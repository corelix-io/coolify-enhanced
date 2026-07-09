<?php

namespace CorelixIo\Platform\Http\Controllers\Api;

use App\Models\S3Storage;
use CorelixIo\Platform\Jobs\ResourceBackupJob;
use CorelixIo\Platform\Models\ScheduledResourceBackup;
use CorelixIo\Platform\Services\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Visus\Cuid2\Cuid2;

class ResourceBackupController extends Controller
{
    public function __construct()
    {
        // Safety: abort if the feature has been disabled
        if (! config('corelix-platform.enabled', false)) {
            abort(404);
        }
    }

    /**
     * List resource backups for the current team.
     */
    public function index(Request $request)
    {
        $teamId = PermissionService::resolveActiveTeamId();
        if ($teamId === null) {
            abort(403, 'No active team.');
        }

        $backups = ScheduledResourceBackup::where('team_id', $teamId)
            ->with('latest_log')
            ->get();

        return response()->json($backups);
    }

    /**
     * Create a new resource backup schedule.
     */
    public function store(Request $request)
    {
        PermissionService::requireTeamAdmin($request->user());

        $validated = $request->validate([
            'backup_type' => 'required|in:volume,configuration,full,coolify_instance',
            'resource_type' => 'required_unless:backup_type,coolify_instance|string',
            'resource_id' => 'required_unless:backup_type,coolify_instance|integer',
            'frequency' => 'nullable|string|max:100',
            'timezone' => 'nullable|string|max:100',
            'timeout' => 'nullable|integer|min:60',
            'save_s3' => 'nullable|boolean',
            'disable_local_backup' => 'nullable|boolean',
            's3_storage_id' => 'nullable|integer|exists:s3_storages,id',
            'retention_amount_locally' => 'nullable|integer|min:0',
            'retention_days_locally' => 'nullable|integer|min:0',
            'retention_amount_s3' => 'nullable|integer|min:0',
            'retention_days_s3' => 'nullable|integer|min:0',
        ]);

        $teamId = PermissionService::resolveActiveTeamId();
        if ($teamId === null) {
            abort(403, 'No active team.');
        }

        if (! empty($validated['s3_storage_id'])) {
            $ownsStorage = S3Storage::query()
                ->where('id', $validated['s3_storage_id'])
                ->where('team_id', $teamId)
                ->exists();

            if (! $ownsStorage) {
                abort(422, 'S3 storage does not belong to your team.');
            }
        }

        // coolify_instance doesn't need a resource
        if ($validated['backup_type'] === 'coolify_instance') {
            $validated['resource_type'] = 'coolify_instance';
            $validated['resource_id'] = 0;
        } else {
            // Verify resource exists and belongs to the user's team
            $allowedTypes = [
                'App\\Models\\Application',
                'App\\Models\\Service',
                'App\\Models\\StandalonePostgresql',
                'App\\Models\\StandaloneMysql',
                'App\\Models\\StandaloneMariadb',
                'App\\Models\\StandaloneMongodb',
                'App\\Models\\StandaloneRedis',
                'App\\Models\\StandaloneKeydb',
                'App\\Models\\StandaloneDragonfly',
                'App\\Models\\StandaloneClickhouse',
            ];

            if (! in_array($validated['resource_type'], $allowedTypes, true)) {
                abort(422, 'Invalid resource_type.');
            }

            $resourceClass = $validated['resource_type'];
            $resource = $resourceClass::find($validated['resource_id']);

            if (! $resource) {
                abort(404, 'Resource not found.');
            }

            // Verify team ownership via the resource's environment chain
            $resourceTeamId = null;
            if (method_exists($resource, 'environment')) {
                $resourceTeamId = $resource->environment?->project?->team_id;
            } elseif (method_exists($resource, 'team')) {
                $resourceTeamId = $resource->team?->id;
            }

            if ($resourceTeamId !== $teamId) {
                abort(403, 'Resource does not belong to your team.');
            }
        }

        $backup = ScheduledResourceBackup::create(array_merge($validated, [
            'uuid' => (string) new Cuid2,
            'team_id' => $teamId,
            'enabled' => true,
            'frequency' => $validated['frequency'] ?? '0 2 * * *',
            'timeout' => $validated['timeout'] ?? 3600,
        ]));

        return response()->json($backup, 201);
    }

    /**
     * Show a specific resource backup schedule.
     */
    public function show(Request $request, string $uuid)
    {
        $teamId = PermissionService::resolveActiveTeamId();
        if ($teamId === null) {
            abort(403, 'No active team.');
        }

        $backup = ScheduledResourceBackup::where('uuid', $uuid)
            ->where('team_id', $teamId)
            ->with('executions')
            ->firstOrFail();

        return response()->json($backup);
    }

    /**
     * Trigger a backup immediately.
     */
    public function trigger(Request $request, string $uuid)
    {
        PermissionService::requireTeamAdmin($request->user());

        $teamId = PermissionService::resolveActiveTeamId();
        if ($teamId === null) {
            abort(403, 'No active team.');
        }

        $backup = ScheduledResourceBackup::where('uuid', $uuid)
            ->where('team_id', $teamId)
            ->firstOrFail();
        ResourceBackupJob::dispatch($backup);

        return response()->json(['message' => 'Backup job dispatched']);
    }

    /**
     * Delete a resource backup schedule.
     */
    public function destroy(Request $request, string $uuid)
    {
        PermissionService::requireTeamAdmin($request->user());

        $teamId = PermissionService::resolveActiveTeamId();
        if ($teamId === null) {
            abort(403, 'No active team.');
        }

        $backup = ScheduledResourceBackup::where('uuid', $uuid)
            ->where('team_id', $teamId)
            ->firstOrFail();
        $backup->delete();

        return response()->json(['message' => 'Backup schedule deleted']);
    }
}
