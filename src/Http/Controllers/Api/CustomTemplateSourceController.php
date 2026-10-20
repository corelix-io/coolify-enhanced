<?php

namespace CorelixIo\Platform\Http\Controllers\Api;

use CorelixIo\Platform\Jobs\SyncTemplateSourceJob;
use CorelixIo\Platform\Models\CustomTemplateSource;
use CorelixIo\Platform\Services\PermissionService;
use CorelixIo\Platform\Services\TemplateSourceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class CustomTemplateSourceController extends Controller
{
    public function __construct()
    {
        if (! config('corelix-platform.enabled', false)) {
            abort(404);
        }

        $this->middleware(function ($request, $next) {
            // Sanctum API routes have no session, so resolve the team from the token
            // (falling back to session for web-guard callers). currentTeam()->pivot is
            // never populated by Coolify's cached helper — use PermissionService.
            $teamId = getTeamIdFromToken() ?? currentTeam()?->id;
            if (! PermissionService::isTeamAdmin($request->user(), $teamId)) {
                abort(403, 'Only admins and owners can manage template sources.');
            }

            return $next($request);
        });
    }

    /**
     * List all custom template sources.
     */
    public function index(): JsonResponse
    {
        $sources = CustomTemplateSource::orderBy('name')->get();

        return response()->json($sources);
    }

    /**
     * Create a new template source.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'repository_url' => ['required', 'string', 'max:500', 'regex:/^https?:\/\//', $this->allowedRepositoryHostRule()],
            'branch' => ['string', 'max:100', 'regex:/^[a-zA-Z0-9\.\-\_\/]+$/'],
            'folder_path' => ['string', 'max:500', 'regex:/^[a-zA-Z0-9\/\-\_\.]+$/', 'not_regex:/\.\./'],
            'auth_token' => ['nullable', 'string', 'max:500'],
            'enabled' => ['boolean'],
        ]);

        $validation = TemplateSourceService::validateSource(
            $validated['repository_url'],
            $validated['branch'] ?? 'main',
            $validated['folder_path'] ?? 'templates/compose',
            $validated['auth_token'] ?? null
        );

        if (! $validation['valid']) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['repository_url' => [$validation['error']]],
            ], 422);
        }

        $source = CustomTemplateSource::create([
            'uuid' => (string) Str::uuid(),
            'name' => $validated['name'],
            'repository_url' => $validated['repository_url'],
            'branch' => $validated['branch'] ?? 'main',
            'folder_path' => $validated['folder_path'] ?? 'templates/compose',
            'auth_token' => $validated['auth_token'] ?? null,
            'enabled' => $validated['enabled'] ?? true,
        ]);

        SyncTemplateSourceJob::dispatch($source);

        return response()->json($source, 201);
    }

    /**
     * Show a single template source.
     */
    public function show(string $uuid): JsonResponse
    {
        $source = CustomTemplateSource::where('uuid', $uuid)->firstOrFail();

        return response()->json($source);
    }

    /**
     * Update a template source.
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $source = CustomTemplateSource::where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'name' => ['string', 'min:2', 'max:100'],
            'repository_url' => ['string', 'max:500', 'regex:/^https?:\/\//', $this->allowedRepositoryHostRule()],
            'branch' => ['string', 'max:100', 'regex:/^[a-zA-Z0-9\.\-\_\/]+$/'],
            'folder_path' => ['string', 'max:500', 'regex:/^[a-zA-Z0-9\/\-\_\.]+$/', 'not_regex:/\.\./'],
            'auth_token' => ['nullable', 'string', 'max:500'],
            'enabled' => ['boolean'],
        ]);

        $syncFields = ['repository_url', 'branch', 'folder_path', 'auth_token'];
        $syncRelevantChange = collect($syncFields)->contains(fn (string $field) => array_key_exists($field, $validated));

        if ($syncRelevantChange) {
            $nextUrl = $validated['repository_url'] ?? $source->repository_url;
            $nextBranch = $validated['branch'] ?? $source->branch;
            $nextFolder = $validated['folder_path'] ?? $source->folder_path;
            $nextToken = array_key_exists('auth_token', $validated)
                ? $validated['auth_token']
                : $source->auth_token;

            $validation = TemplateSourceService::validateSource(
                $nextUrl,
                $nextBranch,
                $nextFolder,
                $nextToken
            );

            if (! $validation['valid']) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => ['repository_url' => [$validation['error']]],
                ], 422);
            }
        }

        $source->update($validated);

        if ($syncRelevantChange) {
            SyncTemplateSourceJob::dispatch($source->fresh());
        }

        return response()->json($source->fresh());
    }

    /**
     * Delete a template source and its cached templates.
     */
    public function destroy(string $uuid): JsonResponse
    {
        $source = CustomTemplateSource::where('uuid', $uuid)->firstOrFail();

        TemplateSourceService::deleteCachedTemplates($source);
        $source->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    /**
     * Sync a single template source.
     */
    public function sync(string $uuid): JsonResponse
    {
        $source = CustomTemplateSource::where('uuid', $uuid)->firstOrFail();

        SyncTemplateSourceJob::dispatch($source);

        return response()->json(['message' => 'Sync started.']);
    }

    /**
     * Sync all enabled template sources.
     */
    public function syncAll(): JsonResponse
    {
        $sources = CustomTemplateSource::where('enabled', true)->get();

        foreach ($sources as $source) {
            SyncTemplateSourceJob::dispatch($source);
        }

        return response()->json([
            'message' => "Syncing {$sources->count()} sources.",
        ]);
    }

    /**
     * @return \Closure(string, mixed, \Closure): void
     */
    private function allowedRepositoryHostRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $probe = new CustomTemplateSource(['repository_url' => (string) $value]);
            if (! $probe->hasAllowedRepositoryHost()) {
                $allowed = implode(', ', CustomTemplateSource::allowedGithubHosts());
                $fail("Repository host must be one of: {$allowed}.");
            }
        };
    }
}
