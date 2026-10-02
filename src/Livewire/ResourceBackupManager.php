<?php

namespace CorelixIo\Platform\Livewire;

use CorelixIo\Platform\Jobs\ResourceBackupJob;
use CorelixIo\Platform\Models\ScheduledResourceBackup;
use CorelixIo\Platform\Models\ScheduledResourceBackupExecution;
use App\Models\S3Storage;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

/**
 * Livewire component for managing resource backups.
 *
 * Supports two modes:
 * - 'resource': Per-resource backups (volumes, configuration, full) on config pages
 * - 'global': Coolify instance backups on the Settings > Backup page
 */
class ResourceBackupManager extends Component
{
    use AuthorizesRequests;

    public ?int $resourceId = null;

    public ?string $resourceType = null;

    public ?string $resourceName = null;

    // Mode: 'resource' for per-resource, 'global' for settings page (coolify_instance only)
    public string $mode = 'resource';

    // New Backup Schedule form
    public string $backupType = 'volume';

    public string $frequency = '0 2 * * *';

    public ?string $timezone = null;

    public int $timeout = 3600;

    public bool $saveS3 = true;

    public bool $disableLocalBackup = false;

    public ?int $s3StorageId = null;

    // Retention
    public int $retentionAmountLocally = 0;

    public int $retentionDaysLocally = 0;

    public float $retentionMaxStorageLocally = 0;

    public int $retentionAmountS3 = 0;

    public int $retentionDaysS3 = 0;

    public float $retentionMaxStorageS3 = 0;

    // UI state
    public array $availableS3Storages = [];

    public array $backups = [];

    public array $executions = [];

    public ?int $selectedBackupId = null;

    public ?int $expandedBackupId = null;

    // Execution pagination
    public int $executionSkip = 0;

    public int $executionTake = 10;

    public int $executionCount = 0;

    // Edit form (for expanded backup details)
    public string $editFrequency = '';

    public ?string $editTimezone = null;

    public int $editTimeout = 3600;

    public bool $editSaveS3 = false;

    public bool $editDisableLocalBackup = false;

    public ?int $editS3StorageId = null;

    public int $editRetentionAmountLocally = 0;

    public int $editRetentionDaysLocally = 0;

    public float $editRetentionMaxStorageLocally = 0;

    public int $editRetentionAmountS3 = 0;

    public int $editRetentionDaysS3 = 0;

    public float $editRetentionMaxStorageS3 = 0;

    public bool $editBackupEnabled = true;

    public string $saveMessage = '';

    public string $saveStatus = '';

    public function mount(
        ?int $resourceId = null,
        ?string $resourceType = null,
        ?string $resourceName = null,
        string $mode = 'resource'
    ): void {
        $this->resourceId = $resourceId;
        $this->resourceType = $resourceType;
        $this->resourceName = $resourceName;
        $this->mode = $mode;

        // Default backup type based on mode
        if ($this->mode === 'global') {
            $this->backupType = 'coolify_instance';
        }

        $this->loadBackups();
        $this->loadS3Storages();

        // Auto-select the first S3 storage so it's pre-filled in the form
        if (! empty($this->availableS3Storages) && is_null($this->s3StorageId)) {
            $this->s3StorageId = $this->availableS3Storages[0]['id'];
        }

        // Auto-select the first backup so executions are visible immediately
        if (! empty($this->backups)) {
            $this->selectedBackupId = $this->backups[0]['id'];
            $this->loadExecutions();
        }
    }

    public function loadBackups(): void
    {
        $query = ScheduledResourceBackup::with('latest_log');

        if ($this->mode === 'global') {
            $query->where('backup_type', 'coolify_instance');
        } else {
            $query->where('resource_id', $this->resourceId)
                ->where('resource_type', $this->resourceType);
        }

        try {
            $teamId = auth()->user()->currentTeam()->id;
            $query->where('team_id', $teamId);
        } catch (\Throwable $e) {
            // Fallback: don't scope
        }

        $this->backups = $query->get()
            ->map(fn ($b) => [
                'id' => $b->id,
                'uuid' => $b->uuid,
                'backup_type' => $b->backup_type,
                'frequency' => $b->frequency,
                'timezone' => $b->timezone,
                'timeout' => $b->timeout,
                'enabled' => $b->enabled,
                'save_s3' => $b->save_s3,
                'disable_local_backup' => $b->disable_local_backup,
                's3_storage_id' => $b->s3_storage_id,
                'retention_amount_locally' => $b->retention_amount_locally,
                'retention_days_locally' => $b->retention_days_locally,
                'retention_max_storage_locally' => (float) $b->retention_max_storage_locally,
                'retention_amount_s3' => $b->retention_amount_s3,
                'retention_days_s3' => $b->retention_days_s3,
                'retention_max_storage_s3' => (float) $b->retention_max_storage_s3,
                'latest_status' => $b->latest_log?->status ?? 'never',
                'latest_at' => $b->latest_log?->created_at?->diffForHumans() ?? 'Never',
            ])
            ->toArray();
    }

    public function loadS3Storages(): void
    {
        try {
            $this->availableS3Storages = S3Storage::ownedByCurrentTeam(['id', 'name'])
                ->where('is_usable', true)
                ->get()
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])
                ->toArray();
        } catch (\Throwable $e) {
            $this->availableS3Storages = [];
        }
    }

    /**
     * Get backup type options based on mode.
     */
    public function getBackupTypeOptionsProperty(): array
    {
        if ($this->mode === 'global') {
            return [
                'coolify_instance' => 'Coolify Instance — Backs up the entire /data/coolify directory',
            ];
        }

        return [
            'volume' => 'Docker Volumes — Snapshot all Docker volumes as tar.gz archives',
            'configuration' => 'Configuration Export — Settings, environment variables, and compose files (JSON)',
            'full' => 'Full Backup — Docker volumes + configuration export combined',
        ];
    }

    private function currentTeamId(): int
    {
        return auth()->user()->currentTeam()->id;
    }

    private function findTeamBackup(int $backupId): ScheduledResourceBackup
    {
        return ScheduledResourceBackup::where('team_id', $this->currentTeamId())
            ->findOrFail($backupId);
    }

    private function findTeamExecution(int $executionId): ScheduledResourceBackupExecution
    {
        return ScheduledResourceBackupExecution::query()
            ->whereHas('scheduledResourceBackup', fn ($query) => $query->where('team_id', $this->currentTeamId()))
            ->findOrFail($executionId);
    }

    private function assertS3StorageOwnedByTeam(?int $s3StorageId): void
    {
        if (empty($s3StorageId)) {
            return;
        }

        $owned = S3Storage::ownedByCurrentTeam()
            ->where('id', $s3StorageId)
            ->exists();

        if (! $owned) {
            abort(403, 'S3 storage does not belong to your team.');
        }
    }

    /**
     * Authorize the current user for backup mutations.
     * Global mode requires instance admin; resource mode requires update permission.
     */
    private function authorizeBackupAction(): void
    {
        if ($this->mode === 'global') {
            if (! isInstanceAdmin()) {
                abort(403, 'Unauthorized.');
            }
        } elseif ($this->resourceType && $this->resourceId) {
            $resource = app($this->resourceType)::find($this->resourceId);
            if ($resource) {
                $this->authorize('update', $resource);
            }
        }
    }

    public function createBackup(): void
    {
        try {
            $this->authorizeBackupAction();

            // Validate S3 storage is selected when S3 is enabled
            if ($this->saveS3 && empty($this->s3StorageId)) {
                $this->saveMessage = 'Please select an S3 storage destination.';
                $this->saveStatus = 'error';
                $this->dispatch('error', 'Please select an S3 storage destination.');

                return;
            }

            if ($this->saveS3) {
                $this->assertS3StorageOwnedByTeam((int) $this->s3StorageId);
            }

            $teamId = $this->currentTeamId();

            $data = [
                'uuid' => (string) new Cuid2,
                'backup_type' => $this->backupType,
                'frequency' => $this->frequency,
                'timezone' => $this->timezone,
                'timeout' => $this->timeout,
                'save_s3' => $this->saveS3,
                'disable_local_backup' => $this->disableLocalBackup,
                's3_storage_id' => $this->saveS3 ? (int) $this->s3StorageId : null,
                'retention_amount_locally' => $this->retentionAmountLocally,
                'retention_days_locally' => $this->retentionDaysLocally,
                'retention_max_storage_locally' => $this->retentionMaxStorageLocally,
                'retention_amount_s3' => $this->retentionAmountS3,
                'retention_days_s3' => $this->retentionDaysS3,
                'retention_max_storage_s3' => $this->retentionMaxStorageS3,
                'team_id' => $teamId,
                'enabled' => true,
            ];

            if ($this->backupType === 'coolify_instance' || $this->mode === 'global') {
                $data['backup_type'] = 'coolify_instance';
                $data['resource_type'] = 'coolify_instance';
                $data['resource_id'] = 0;
            } else {
                $data['resource_type'] = $this->resourceType;
                $data['resource_id'] = $this->resourceId;
            }

            ScheduledResourceBackup::create($data);

            $this->loadBackups();
            $this->saveMessage = 'Backup schedule created.';
            $this->saveStatus = 'success';
            $this->dispatch('success', 'Backup schedule created successfully.');
        } catch (\Throwable $e) {
            $this->saveMessage = 'Failed: '.$e->getMessage();
            $this->saveStatus = 'error';
            $this->dispatch('error', 'Failed to create backup schedule.', $e->getMessage());
        }
    }

    public function runBackupNow(int $backupId): void
    {
        try {
            $this->authorizeBackupAction();
            $backup = $this->findTeamBackup($backupId);
            ResourceBackupJob::dispatch($backup);

            // Select this backup so executions section shows its progress
            $this->selectedBackupId = $backupId;
            $this->executionSkip = 0;
            $this->loadExecutions();

            $this->dispatch('success', 'Backup job dispatched. Check executions for progress.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to dispatch backup.', $e->getMessage());
        }
    }

    public function toggleBackup(int $backupId): void
    {
        try {
            $this->authorizeBackupAction();
            $backup = $this->findTeamBackup($backupId);
            $backup->enabled = ! $backup->enabled;
            $backup->save();
            $this->loadBackups();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to toggle backup.', $e->getMessage());
        }
    }

    public function deleteBackup(int $backupId): void
    {
        try {
            $this->authorizeBackupAction();
            $backup = $this->findTeamBackup($backupId);
            $backup->delete();
            $this->loadBackups();
            if ($this->expandedBackupId === $backupId) {
                $this->expandedBackupId = null;
            }
            if ($this->selectedBackupId === $backupId) {
                $this->selectedBackupId = null;
                $this->executions = [];
            }
            $this->dispatch('success', 'Backup schedule deleted.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to delete backup.', $e->getMessage());
        }
    }

    /**
     * Toggle the expanded details panel for a backup schedule.
     */
    public function toggleDetails(int $backupId): void
    {
        if ($this->expandedBackupId === $backupId) {
            $this->expandedBackupId = null;

            return;
        }

        $this->expandedBackupId = $backupId;

        // Load edit form from backup data
        $backup = collect($this->backups)->firstWhere('id', $backupId);
        if ($backup) {
            $this->editFrequency = $backup['frequency'];
            $this->editTimezone = $backup['timezone'];
            $this->editTimeout = $backup['timeout'];
            $this->editBackupEnabled = $backup['enabled'];
            $this->editSaveS3 = $backup['save_s3'];
            $this->editDisableLocalBackup = $backup['disable_local_backup'];
            $this->editS3StorageId = $backup['s3_storage_id'];
            $this->editRetentionAmountLocally = $backup['retention_amount_locally'];
            $this->editRetentionDaysLocally = $backup['retention_days_locally'];
            $this->editRetentionMaxStorageLocally = $backup['retention_max_storage_locally'];
            $this->editRetentionAmountS3 = $backup['retention_amount_s3'];
            $this->editRetentionDaysS3 = $backup['retention_days_s3'];
            $this->editRetentionMaxStorageS3 = $backup['retention_max_storage_s3'];
        }

        // Also select this backup and load executions
        $this->selectedBackupId = $backupId;
        $this->executionSkip = 0;
        $this->loadExecutions();
    }

    /**
     * Save changes to an expanded backup schedule.
     */
    public function saveBackupSettings(): void
    {
        $this->authorizeBackupAction();

        if (! $this->expandedBackupId) {
            return;
        }

        try {
            // Validate S3 storage is selected when S3 is enabled
            if ($this->editSaveS3 && empty($this->editS3StorageId)) {
                $this->dispatch('error', 'Please select an S3 storage destination.');

                return;
            }

            if ($this->editSaveS3) {
                $this->assertS3StorageOwnedByTeam((int) $this->editS3StorageId);
            }

            $backup = $this->findTeamBackup($this->expandedBackupId);
            $backup->update([
                'frequency' => $this->editFrequency,
                'timezone' => $this->editTimezone,
                'timeout' => $this->editTimeout,
                'enabled' => $this->editBackupEnabled,
                'save_s3' => $this->editSaveS3,
                'disable_local_backup' => $this->editDisableLocalBackup,
                's3_storage_id' => $this->editSaveS3 ? (int) $this->editS3StorageId : null,
                'retention_amount_locally' => $this->editRetentionAmountLocally,
                'retention_days_locally' => $this->editRetentionDaysLocally,
                'retention_max_storage_locally' => $this->editRetentionMaxStorageLocally,
                'retention_amount_s3' => $this->editRetentionAmountS3,
                'retention_days_s3' => $this->editRetentionDaysS3,
                'retention_max_storage_s3' => $this->editRetentionMaxStorageS3,
            ]);

            $this->loadBackups();
            $this->dispatch('success', 'Backup settings saved.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to save backup settings.', $e->getMessage());
        }
    }

    /**
     * Instant toggle for checkboxes in the edit form.
     */
    public function editInstantSave(): void
    {
        $this->saveBackupSettings();
    }

    public function loadExecutions(): void
    {
        if (! $this->selectedBackupId) {
            $this->executions = [];
            $this->executionCount = 0;

            return;
        }

        try {
            $this->findTeamBackup($this->selectedBackupId);
        } catch (\Throwable $e) {
            $this->executions = [];
            $this->executionCount = 0;
            $this->selectedBackupId = null;

            return;
        }

        $query = ScheduledResourceBackupExecution::where('scheduled_resource_backup_id', $this->selectedBackupId);
        $this->executionCount = $query->count();

        $this->executions = $query
            ->orderBy('created_at', 'desc')
            ->skip($this->executionSkip)
            ->take($this->executionTake)
            ->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'uuid' => $e->uuid,
                'backup_type' => $e->backup_type,
                'backup_label' => $e->backup_label,
                'status' => $e->status,
                'size' => $e->size,
                'size_formatted' => $e->size ? $this->formatBytes((int) $e->size) : null,
                'is_encrypted' => $e->is_encrypted,
                's3_uploaded' => $e->s3_uploaded,
                's3_storage_deleted' => $e->s3_storage_deleted,
                'local_storage_deleted' => $e->local_storage_deleted,
                'filename' => $e->filename,
                'message' => $e->message,
                'created_at' => $e->created_at?->toIso8601String(),
                'created_at_human' => $e->created_at?->diffForHumans(),
                'created_at_formatted' => $e->created_at?->format('M j, H:i'),
                'finished_at' => $e->finished_at,
                'finished_at_human' => $e->finished_at ? \Carbon\Carbon::parse($e->finished_at)->diffForHumans() : null,
                'finished_at_formatted' => $e->finished_at ? \Carbon\Carbon::parse($e->finished_at)->format('M j, H:i') : null,
                'duration' => $e->finished_at ? $this->calculateDuration($e->created_at, $e->finished_at) : null,
            ])
            ->toArray();
    }

    public function nextExecutionPage(): void
    {
        if ($this->executionSkip + $this->executionTake < $this->executionCount) {
            $this->executionSkip += $this->executionTake;
            $this->loadExecutions();
        }
    }

    public function previousExecutionPage(): void
    {
        if ($this->executionSkip > 0) {
            $this->executionSkip = max(0, $this->executionSkip - $this->executionTake);
            $this->loadExecutions();
        }
    }

    public function refreshExecutions(): void
    {
        $this->loadExecutions();
    }

    public function cleanupFailed(): void
    {
        if (! $this->selectedBackupId) {
            return;
        }

        try {
            $this->authorizeBackupAction();
            $this->findTeamBackup($this->selectedBackupId);
            ScheduledResourceBackupExecution::where('scheduled_resource_backup_id', $this->selectedBackupId)
                ->where('status', 'failed')
                ->delete();
            $this->loadExecutions();
            $this->dispatch('success', 'Failed backup executions cleaned up.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to cleanup.', $e->getMessage());
        }
    }

    public function deleteExecution(int $executionId): void
    {
        try {
            $this->authorizeBackupAction();
            $execution = $this->findTeamExecution($executionId);

            // Delete local file if exists
            if ($execution->filename && ! $execution->local_storage_deleted) {
                $backup = $execution->scheduledResourceBackup;
                $server = $backup?->resolveServer();
                if ($server) {
                    deleteBackupsLocally(
                        ScheduledResourceBackupExecution::expandFilenames($execution->filename),
                        $server
                    );
                }
            }

            $execution->delete();
            $this->loadExecutions();
            $this->dispatch('success', 'Execution deleted.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to delete execution.', $e->getMessage());
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 1024));

        return round($bytes / pow(1024, $i), 2).' '.$units[$i];
    }

    private function calculateDuration($start, $end): string
    {
        try {
            $startCarbon = \Carbon\Carbon::parse($start);
            $endCarbon = \Carbon\Carbon::parse($end);
            $diff = $startCarbon->diff($endCarbon);

            $parts = [];
            if ($diff->d > 0) {
                $parts[] = $diff->d.'d';
            }
            if ($diff->h > 0) {
                $parts[] = $diff->h.'h';
            }
            if ($diff->i > 0) {
                $parts[] = $diff->i.'m';
            }
            $parts[] = sprintf('%02ds', $diff->s);

            return implode(' ', $parts);
        } catch (\Throwable $e) {
            return '-';
        }
    }

    /**
     * Get a human-readable label for a backup type.
     */
    public static function backupTypeLabel(string $type): string
    {
        return match ($type) {
            'volume' => 'Docker Volumes',
            'configuration' => 'Configuration',
            'full' => 'Full Backup',
            'coolify_instance' => 'Coolify Instance',
            default => ucfirst($type),
        };
    }

    public function render()
    {
        return view('corelix-platform::livewire.resource-backup-manager');
    }
}
