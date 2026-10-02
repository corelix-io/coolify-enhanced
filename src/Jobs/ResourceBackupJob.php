<?php

namespace CorelixIo\Platform\Jobs;

use CorelixIo\Platform\Models\ScheduledResourceBackup;
use CorelixIo\Platform\Models\ScheduledResourceBackupExecution;
use CorelixIo\Platform\Services\RcloneService;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Visus\Cuid2\Cuid2;

class ResourceBackupJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $maxExceptions = 1;

    public ?Team $team = null;

    public ?Server $server = null;

    public ?S3Storage $s3 = null;

    public string $backup_dir;

    public bool $s3_uploaded = false;

    public ?string $backup_output = null;

    public ?string $error_output = null;

    public $timeout = 3600;

    public function __construct(public ScheduledResourceBackup $backup)
    {
        $this->onQueue('high');
        $this->timeout = $backup->timeout ?? 3600;
    }

    /**
     * Prevent overlapping backup jobs for the same schedule.
     */
    public function middleware(): array
    {
        return [new WithoutOverlapping($this->backup->id)];
    }

    /**
     * Escape a value for safe use in shell commands.
     */
    private function escape(string $value): string
    {
        return escapeshellarg($value);
    }

    public function handle(): void
    {
        // Safety: if the feature was disabled while this job was queued, exit silently
        if (! config('corelix-platform.enabled', false)) {
            Log::info('ResourceBackup: Feature disabled, skipping backup '.$this->backup->uuid);

            return;
        }

        try {
            $this->team = Team::find($this->backup->team_id);
            if (! $this->team) {
                $this->backup->delete();

                return;
            }

            // Load S3 storage from relationship; fall back to direct lookup
            $this->s3 = $this->backup->s3;
            if (is_null($this->s3) && $this->backup->s3_storage_id) {
                $this->s3 = S3Storage::find($this->backup->s3_storage_id);
            }
            $backupType = $this->backup->backup_type;

            // Coolify instance backup doesn't need a resource or server resolved via resource chain
            if ($backupType === ScheduledResourceBackup::TYPE_COOLIFY_INSTANCE) {
                $this->server = $this->resolveCoolifyServer();
                if (! $this->server) {
                    throw new \Exception('Could not resolve the Coolify server for instance backup');
                }
                $this->backup_dir = backup_dir().'/coolify-instance/'.str($this->team->name)->slug().'-'.$this->team->id;
                $this->backupCoolifyInstance();
                $this->removeOldBackups();

                return;
            }

            $this->server = $this->backup->resolveServer();
            if (! $this->server) {
                throw new \Exception('Server not found for resource backup');
            }

            $resource = $this->backup->resource;
            if (! $resource) {
                throw new \Exception('Resource not found for backup');
            }

            $resourceName = str(data_get($resource, 'name', 'unknown'))->slug()->value();
            $resourceUuid = data_get($resource, 'uuid', 'unknown');
            $this->backup_dir = backup_dir().'/resources/'.str($this->team->name)->slug().'-'.$this->team->id.'/'.$resourceName.'-'.$resourceUuid;

            if ($backupType === ScheduledResourceBackup::TYPE_VOLUME) {
                $this->backupVolumes();
            } elseif ($backupType === ScheduledResourceBackup::TYPE_CONFIGURATION) {
                $this->backupConfiguration();
            } elseif ($backupType === ScheduledResourceBackup::TYPE_FULL) {
                $this->backupFull();
            }

            // Clean up old backups
            $this->removeOldBackups();
        } catch (\Throwable $e) {
            Log::channel('scheduled-errors')->error('ResourceBackup failed', [
                'job' => 'ResourceBackupJob',
                'backup_id' => $this->backup->uuid ?? 'unknown',
                'resource' => $this->backup->resource?->name ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Run a full backup (volumes + configuration) as a single execution.
     */
    private function backupFull(): void
    {
        $resource = $this->backup->resource;
        if (! $resource) {
            return;
        }

        $timestamp = Carbon::now()->timestamp;
        $backupFileVolumes = "/full-volumes-{$timestamp}.tar.gz";
        $backupFileConfig = "/full-config-{$timestamp}.json";
        $volumeBackupLocation = $this->backup_dir.$backupFileVolumes;
        $configBackupLocation = $this->backup_dir.$backupFileConfig;

        $logUuid = $this->generateUniqueUuid();

        $execution = ScheduledResourceBackupExecution::create([
            'uuid' => $logUuid,
            'backup_type' => 'full',
            'backup_label' => 'full-backup',
            'filename' => null,
            'scheduled_resource_backup_id' => $this->backup->id,
            'local_storage_deleted' => false,
        ]);

        $logs = [];
        $totalSize = 0;
        $hasError = false;
        $filesToUpload = [];

        try {
            // === Phase 1: Volume backup ===
            $logs[] = '=== Phase 1: Docker Volume Backup ===';

            $containers = $this->backup->getContainerNames();
            if (empty($containers)) {
                $logs[] = 'No containers found for this resource. Skipping volume backup.';
                $logs[] = 'Resource type: '.get_class($resource);
                $logs[] = 'Resource UUID: '.($resource->uuid ?? 'N/A');
            } else {
                $logs[] = 'Containers found: '.implode(', ', $containers);

                $volumeCount = 0;
                $mountIndex = 0;
                $stagingDir = "{$this->backup_dir}/.staging-{$timestamp}";
                $escBackupDir = $this->escape($this->backup_dir);
                $escStagingDir = $this->escape($stagingDir);
                $volumeCommands = ["mkdir -p {$escBackupDir}", "mkdir -p {$escStagingDir}"];

                foreach ($containers as $containerName) {
                    $logs[] = "Inspecting container: {$containerName}";

                    $escContainer = $this->escape($containerName);
                    $volumeJson = instant_remote_process(
                        ["docker inspect {$escContainer} --format '{{json .Mounts}}' 2>&1 || echo '[]'"],
                        $this->server,
                        false,
                        false,
                        null,
                        disableMultiplexing: true
                    );

                    $trimmedJson = trim($volumeJson);
                    $mounts = json_decode($trimmedJson, true);

                    if (! is_array($mounts) || empty($mounts)) {
                        $logs[] = "  No mounts found. Raw output: {$trimmedJson}";

                        continue;
                    }

                    $backupableMounts = collect($mounts)->filter(function ($mount) {
                        return in_array($mount['Type'] ?? '', ['volume', 'bind']);
                    });

                    if ($backupableMounts->isEmpty()) {
                        $logs[] = '  No backupable volumes (volume/bind) found. Mount types: '.collect($mounts)->pluck('Type')->implode(', ');

                        continue;
                    }

                    $logs[] = '  Found '.$backupableMounts->count().' backupable mount(s)';

                    foreach ($backupableMounts as $mount) {
                        $volumeName = $mount['Name'] ?? basename($mount['Source'] ?? 'unknown');
                        $source = $mount['Source'] ?? null;
                        $type = $mount['Type'] ?? 'unknown';

                        if (! $source) {
                            $logs[] = "  Skipping mount without source: {$volumeName}";

                            continue;
                        }

                        $volumeCount++;
                        $mountIndex++;
                        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $volumeName) ?: 'mount';
                        $mountSubdir = "mount_{$mountIndex}_{$safeName}";
                        $mountArchive = "{$stagingDir}/{$mountSubdir}/data.tar.gz";
                        $escMountDir = $this->escape("{$stagingDir}/{$mountSubdir}");
                        $escMountArchive = $this->escape($mountArchive);
                        $logs[] = "  Backing up {$type} '{$volumeName}' ({$mount['Destination']})";

                        $volumeCommands[] = "mkdir -p {$escMountDir}";

                        if ($type === 'volume') {
                            $escVolume = $this->escape($volumeName);
                            $volumeCommands[] = "docker run --rm"
                                ." -v {$escVolume}:/source:ro"
                                ." -v {$escStagingDir}:/staging"
                                ." alpine sh -c ".$this->escape("tar czf /staging/{$mountSubdir}/data.tar.gz -C /source .");
                        } else {
                            $escapedSource = escapeshellarg($source);
                            $escapedDir = escapeshellarg(dirname($source));
                            $escapedBase = escapeshellarg(basename($source));
                            $volumeCommands[] = "if [ -d {$escapedSource} ]; then tar czf {$escMountArchive} -C {$escapedSource} .; else tar czf {$escMountArchive} -C {$escapedDir} {$escapedBase}; fi";
                        }
                    }
                }

                if ($volumeCount > 0) {
                    $escVolumeArchive = $this->escape($volumeBackupLocation);
                    $volumeCommands[] = "tar czf {$escVolumeArchive} -C {$escStagingDir} .";
                    $volumeCommands[] = "rm -rf {$escStagingDir}";

                    $output = instant_remote_process($volumeCommands, $this->server, true, false, $this->timeout, disableMultiplexing: true);
                    if ($output) {
                        $logs[] = '  Command output: '.trim($output);
                    }
                }

                if ($volumeCount > 0) {
                    $volSize = $this->calculateSize($volumeBackupLocation);
                    if ($volSize > 0) {
                        $totalSize += $volSize;
                        $filesToUpload[] = $volumeBackupLocation;
                        $logs[] = "Volume backup complete. Size: {$this->formatBytes($volSize)}";
                    } else {
                        $logs[] = 'Warning: Volume backup file is empty or missing.';
                        $hasError = true;
                    }
                } else {
                    $logs[] = 'No volumes were backed up.';
                }
            }

            // === Phase 2: Configuration backup ===
            $logs[] = '';
            $logs[] = '=== Phase 2: Configuration Export ===';

            $configData = $this->exportResourceConfig($resource);
            $jsonContent = json_encode($configData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $encoded = base64_encode($jsonContent);

            $commands = [
                "mkdir -p {$this->backup_dir}",
                "echo '{$encoded}' | base64 -d > {$configBackupLocation}",
            ];

            instant_remote_process($commands, $this->server, true, false, null, disableMultiplexing: true);
            $configSize = $this->calculateSize($configBackupLocation);

            if ($configSize > 0) {
                $totalSize += $configSize;
                $filesToUpload[] = $configBackupLocation;
                $logs[] = "Configuration export complete. Size: {$this->formatBytes($configSize)}";
            } else {
                $logs[] = 'Warning: Configuration backup file is empty.';
                $hasError = true;
            }

            // === Phase 3: S3 Upload ===
            $isEncrypted = false;
            $localStorageDeleted = false;

            $uploadedFiles = [];
            $allS3Uploaded = false;

            if ($this->backup->save_s3 && ! empty($filesToUpload)) {
                $logs[] = '';
                $logs[] = '=== Phase 3: S3 Upload ===';

                foreach ($filesToUpload as $filePath) {
                    try {
                        $logs[] = 'Uploading: '.basename($filePath);
                        $this->s3_uploaded = false;
                        $this->uploadToS3($filePath, $this->backup_dir.'/', $logUuid);
                        $uploadedFiles[] = $filePath;
                        $isEncrypted = RcloneService::isEncryptionEnabled($this->s3);
                        $logs[] = '  Uploaded successfully'.($isEncrypted ? ' (encrypted)' : '');
                    } catch (\Throwable $e) {
                        $logs[] = '  S3 upload FAILED: '.$e->getMessage();
                        $hasError = true;
                    }
                }

                $allS3Uploaded = count($uploadedFiles) === count($filesToUpload);

                if ($allS3Uploaded && $this->backup->disable_local_backup) {
                    foreach ($uploadedFiles as $filePath) {
                        $this->deleteLocalFile($filePath);
                    }
                    $localStorageDeleted = true;
                    $logs[] = 'Local files deleted after S3 upload.';
                }
            }

            $updateData = [
                'status' => $hasError ? 'failed' : 'success',
                'message' => implode("\n", $logs),
                'size' => $totalSize,
                'filename' => ! empty($filesToUpload) ? json_encode($filesToUpload) : null,
                's3_uploaded' => $this->backup->save_s3 ? ($allS3Uploaded ?: null) : null,
                'local_storage_deleted' => $localStorageDeleted,
            ];

            if ($allS3Uploaded) {
                $updateData['is_encrypted'] = $isEncrypted;
            }

            $execution->update($updateData);
        } catch (\Throwable $e) {
            $logs[] = '';
            $logs[] = 'FATAL ERROR: '.$e->getMessage();
            $execution->update([
                'status' => 'failed',
                'message' => implode("\n", $logs),
                'size' => $totalSize,
                'filename' => null,
                's3_uploaded' => null,
                'finished_at' => Carbon::now(),
            ]);
        } finally {
            $execution->update(['finished_at' => Carbon::now()]);
            $this->s3_uploaded = false;
        }
    }

    /**
     * Backup Docker volumes for the resource's containers.
     */
    private function backupVolumes(): void
    {
        $containers = $this->backup->getContainerNames();
        if (empty($containers)) {
            // Create a failed execution record so the user knows volumes were not found
            $resource = $this->backup->resource;
            $logs = [
                'No containers found for volume backup.',
                'Resource type: '.($resource ? get_class($resource) : 'N/A'),
                'Resource UUID: '.($resource?->uuid ?? 'N/A'),
                '',
                'This usually means the application is not deployed or the container is not running.',
                'Ensure the application is deployed and running before scheduling volume backups.',
            ];

            ScheduledResourceBackupExecution::create([
                'uuid' => $this->generateUniqueUuid(),
                'backup_type' => 'volume',
                'backup_label' => 'no-containers-found',
                'status' => 'failed',
                'message' => implode("\n", $logs),
                'size' => 0,
                'filename' => null,
                'scheduled_resource_backup_id' => $this->backup->id,
                'local_storage_deleted' => false,
                'finished_at' => Carbon::now(),
            ]);

            return;
        }

        foreach ($containers as $containerName) {
            try {
                $this->backupContainerVolumes($containerName);
            } catch (\Throwable $e) {
                $this->addToErrorOutput("Volume backup failed for container {$containerName}: ".$e->getMessage());
            }
        }
    }

    /**
     * Backup all volumes for a specific container.
     */
    private function backupContainerVolumes(string $containerName): void
    {
        // Get volume mounts from container inspection
        $escContainer = $this->escape($containerName);
        $volumeJson = instant_remote_process(
            ["docker inspect {$escContainer} --format '{{json .Mounts}}' 2>&1 || echo '[]'"],
            $this->server,
            false,
            false,
            null,
            disableMultiplexing: true
        );

        $trimmedJson = trim($volumeJson);
        $mounts = json_decode($trimmedJson, true);
        if (! is_array($mounts) || empty($mounts)) {
            // Create an informational record so the user can see what happened
            ScheduledResourceBackupExecution::create([
                'uuid' => $this->generateUniqueUuid(),
                'backup_type' => 'volume',
                'backup_label' => $containerName,
                'status' => 'failed',
                'message' => "No volumes found for container '{$containerName}'.\n\nDocker inspect output:\n{$trimmedJson}\n\nEnsure the container is running and has mounted volumes.",
                'size' => 0,
                'filename' => null,
                'scheduled_resource_backup_id' => $this->backup->id,
                'local_storage_deleted' => false,
                'finished_at' => Carbon::now(),
            ]);

            return;
        }

        // Filter to only named volumes and bind mounts (skip tmpfs, etc.)
        $backupableMounts = collect($mounts)->filter(function ($mount) {
            return in_array($mount['Type'] ?? '', ['volume', 'bind']);
        });

        if ($backupableMounts->isEmpty()) {
            ScheduledResourceBackupExecution::create([
                'uuid' => $this->generateUniqueUuid(),
                'backup_type' => 'volume',
                'backup_label' => $containerName,
                'status' => 'failed',
                'message' => "No backupable volumes (type=volume or bind) found for container '{$containerName}'.\n\nMounts found: ".json_encode($mounts, JSON_PRETTY_PRINT),
                'size' => 0,
                'filename' => null,
                'scheduled_resource_backup_id' => $this->backup->id,
                'local_storage_deleted' => false,
                'finished_at' => Carbon::now(),
            ]);

            return;
        }

        foreach ($backupableMounts as $mount) {
            $this->backupSingleVolume($containerName, $mount);
        }
    }

    /**
     * Backup a single volume/bind mount.
     */
    private function backupSingleVolume(string $containerName, array $mount): void
    {
        $volumeName = $mount['Name'] ?? basename($mount['Source'] ?? 'unknown');
        $source = $mount['Source'] ?? null;
        $destination = $mount['Destination'] ?? null;
        $type = $mount['Type'] ?? 'unknown';

        if (! $source) {
            return;
        }

        // Sanitize volume name for filename
        $safeVolumeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $volumeName);
        $timestamp = Carbon::now()->timestamp;
        $backupFile = "/volume-{$safeVolumeName}-{$timestamp}.tar.gz";
        $backupLocation = $this->backup_dir.$backupFile;

        $logUuid = $this->generateUniqueUuid();

        $execution = ScheduledResourceBackupExecution::create([
            'uuid' => $logUuid,
            'backup_type' => 'volume',
            'backup_label' => "{$containerName}:{$volumeName}",
            'filename' => $backupLocation,
            'scheduled_resource_backup_id' => $this->backup->id,
            'local_storage_deleted' => false,
        ]);

        $logs = [
            "Backing up {$type} volume '{$volumeName}'",
            "Container: {$containerName}",
            "Source: {$source}",
            "Destination: {$destination}",
        ];

        try {
            $commands = [];
            $escBackupDir = $this->escape($this->backup_dir);
            $commands[] = "mkdir -p {$escBackupDir}";

            if ($type === 'volume') {
                // Named Docker volume - use a helper container to read it
                $escVolume = $this->escape($volumeName);
                $commands[] = "docker run --rm"
                    ." -v {$escVolume}:/source:ro"
                    ." -v {$escBackupDir}:/backup"
                    ." alpine tar czf /backup{$backupFile} -C /source .";
            } else {
                // Bind mount - could be a directory or a single file
                $escapedSource = escapeshellarg($source);
                $escapedDir = escapeshellarg(dirname($source));
                $escapedBase = escapeshellarg(basename($source));
                $commands[] = "if [ -d {$escapedSource} ]; then tar czf {$backupLocation} -C {$escapedSource} .; else tar czf {$backupLocation} -C {$escapedDir} {$escapedBase}; fi";
            }

            $output = instant_remote_process($commands, $this->server, true, false, $this->timeout, disableMultiplexing: true);
            if ($output) {
                $logs[] = 'Output: '.trim($output);
            }

            $size = $this->calculateSize($backupLocation);

            if ($size <= 0) {
                throw new \Exception('Backup file is empty or was not created');
            }

            $logs[] = "Backup created: {$backupLocation} ({$this->formatBytes($size)})";

            // Upload to S3 if enabled
            $isEncrypted = false;
            $s3UploadError = null;
            $localStorageDeleted = false;

            if ($this->backup->save_s3) {
                try {
                    $logs[] = 'Uploading to S3...';
                    $this->uploadToS3($backupLocation, $this->backup_dir.'/', $logUuid);
                    $isEncrypted = RcloneService::isEncryptionEnabled($this->s3);
                    $logs[] = 'S3 upload successful'.($isEncrypted ? ' (encrypted)' : '');

                    if ($this->backup->disable_local_backup) {
                        $this->deleteLocalFile($backupLocation);
                        $localStorageDeleted = true;
                        $logs[] = 'Local file deleted after S3 upload';
                    }
                } catch (\Throwable $e) {
                    $s3UploadError = $e->getMessage();
                    $logs[] = 'S3 upload FAILED: '.$s3UploadError;
                }
            }

            $updateData = [
                'status' => 'success',
                'message' => implode("\n", $logs),
                'size' => $size,
                's3_uploaded' => $this->backup->save_s3 ? $this->s3_uploaded : null,
                'local_storage_deleted' => $localStorageDeleted,
            ];

            if ($this->s3_uploaded) {
                $updateData['is_encrypted'] = $isEncrypted;
            }

            $execution->update($updateData);
        } catch (\Throwable $e) {
            $logs[] = 'FAILED: '.$e->getMessage();
            $execution->update([
                'status' => 'failed',
                'message' => implode("\n", $logs),
                'size' => 0,
                'filename' => null,
                's3_uploaded' => null,
                'finished_at' => Carbon::now(),
            ]);
        } finally {
            $execution->update(['finished_at' => Carbon::now()]);
            $this->s3_uploaded = false;
        }
    }

    /**
     * Backup resource configuration as a JSON export.
     */
    private function backupConfiguration(): void
    {
        $resource = $this->backup->resource;
        if (! $resource) {
            return;
        }

        $timestamp = Carbon::now()->timestamp;
        $backupFile = "/config-{$timestamp}.json";
        $backupLocation = $this->backup_dir.$backupFile;

        $logUuid = $this->generateUniqueUuid();

        $execution = ScheduledResourceBackupExecution::create([
            'uuid' => $logUuid,
            'backup_type' => 'configuration',
            'backup_label' => 'configuration',
            'filename' => $backupLocation,
            'scheduled_resource_backup_id' => $this->backup->id,
            'local_storage_deleted' => false,
        ]);

        $logs = ['Exporting resource configuration...'];

        try {
            $configData = $this->exportResourceConfig($resource);
            $jsonContent = json_encode($configData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            // Write JSON to server via base64 to avoid escaping issues
            $encoded = base64_encode($jsonContent);
            $escBackupDir = $this->escape($this->backup_dir);
            $escBackupLoc = $this->escape($backupLocation);
            $commands = [
                "mkdir -p {$escBackupDir}",
                "echo '{$encoded}' | base64 -d > {$escBackupLoc}",
            ];

            instant_remote_process($commands, $this->server, true, false, null, disableMultiplexing: true);
            $size = $this->calculateSize($backupLocation);

            if ($size <= 0) {
                throw new \Exception('Configuration backup file is empty');
            }

            $logs[] = "Configuration exported: {$backupLocation} ({$this->formatBytes($size)})";
            $exportedKeys = array_keys($configData);
            $logs[] = 'Sections: '.implode(', ', $exportedKeys);

            // Upload to S3 if enabled
            $isEncrypted = false;
            $s3UploadError = null;
            $localStorageDeleted = false;

            if ($this->backup->save_s3) {
                try {
                    $logs[] = 'Uploading to S3...';
                    $this->uploadToS3($backupLocation, $this->backup_dir.'/', $logUuid);
                    $isEncrypted = RcloneService::isEncryptionEnabled($this->s3);
                    $logs[] = 'S3 upload successful'.($isEncrypted ? ' (encrypted)' : '');

                    if ($this->backup->disable_local_backup) {
                        $this->deleteLocalFile($backupLocation);
                        $localStorageDeleted = true;
                        $logs[] = 'Local file deleted after S3 upload';
                    }
                } catch (\Throwable $e) {
                    $s3UploadError = $e->getMessage();
                    $logs[] = 'S3 upload FAILED: '.$s3UploadError;
                }
            }

            $updateData = [
                'status' => 'success',
                'message' => implode("\n", $logs),
                'size' => $size,
                's3_uploaded' => $this->backup->save_s3 ? $this->s3_uploaded : null,
                'local_storage_deleted' => $localStorageDeleted,
            ];

            if ($this->s3_uploaded) {
                $updateData['is_encrypted'] = $isEncrypted;
            }

            $execution->update($updateData);
        } catch (\Throwable $e) {
            $logs[] = 'FAILED: '.$e->getMessage();
            $execution->update([
                'status' => 'failed',
                'message' => implode("\n", $logs),
                'size' => 0,
                'filename' => null,
                's3_uploaded' => null,
                'finished_at' => Carbon::now(),
            ]);
        } finally {
            $execution->update(['finished_at' => Carbon::now()]);
            $this->s3_uploaded = false;
        }
    }

    /**
     * Export resource configuration as an associative array.
     */
    private function exportResourceConfig($resource): array
    {
        $config = [
            'backup_meta' => [
                'type' => 'corelix_platform_resource_backup',
                'version' => '1.0',
                'created_at' => Carbon::now()->toIso8601String(),
                'resource_type' => get_class($resource),
                'resource_uuid' => $resource->uuid ?? null,
                'resource_name' => $resource->name ?? null,
            ],
            'resource' => $resource->toArray(),
        ];

        // Export environment variables
        if (method_exists($resource, 'environment_variables')) {
            $config['environment_variables'] = $resource->environment_variables()
                ->get()
                ->map(fn ($var) => [
                    'key' => $var->key,
                    'value' => $var->value,
                    'is_preview' => $var->is_preview ?? false,
                    'is_build_time' => $var->is_build_time ?? false,
                    'is_shared' => $var->is_shared ?? false,
                ])
                ->toArray();
        }

        // Export persistent storages / volume configurations
        if (method_exists($resource, 'persistentStorages')) {
            $config['persistent_storages'] = $resource->persistentStorages()
                ->get()
                ->toArray();
        }

        // Export docker-compose content for applications
        if (property_exists($resource, 'docker_compose_raw') || isset($resource->docker_compose_raw)) {
            $config['docker_compose_raw'] = $resource->docker_compose_raw ?? null;
            $config['docker_compose'] = $resource->docker_compose ?? null;
        }

        // Service-specific: export full compose
        if ($resource instanceof \App\Models\Service) {
            $config['docker_compose_raw'] = $resource->docker_compose_raw ?? null;
            $config['docker_compose'] = $resource->docker_compose ?? null;

            // Export service applications and databases config
            $config['service_applications'] = $resource->applications?->toArray() ?? [];
            $config['service_databases'] = $resource->databases?->toArray() ?? [];
        }

        // Export labels
        if (property_exists($resource, 'custom_labels') || isset($resource->custom_labels)) {
            $config['custom_labels'] = $resource->custom_labels ?? null;
        }

        // Export the environment the resource belongs to
        if (method_exists($resource, 'environment') && $resource->environment) {
            $config['environment'] = [
                'name' => $resource->environment->name ?? null,
                'uuid' => $resource->environment->uuid ?? null,
            ];
        }

        return $config;
    }

    /**
     * Backup the entire Coolify installation (/data/coolify/) as a tar.gz.
     *
     * Excludes the backups directory to avoid duplication, and excludes
     * large ephemeral directories (metrics, tmp).
     */
    private function backupCoolifyInstance(): void
    {
        $baseDir = base_configuration_dir(); // /data/coolify
        $timestamp = Carbon::now()->timestamp;
        $backupFile = "/coolify-instance-{$timestamp}.tar.gz";
        $backupLocation = $this->backup_dir.$backupFile;

        $logUuid = $this->generateUniqueUuid();

        $execution = ScheduledResourceBackupExecution::create([
            'uuid' => $logUuid,
            'backup_type' => 'coolify_instance',
            'backup_label' => 'coolify-instance',
            'filename' => $backupLocation,
            'scheduled_resource_backup_id' => $this->backup->id,
            'local_storage_deleted' => false,
        ]);

        $logs = [
            'Backing up Coolify instance files...',
            "Source: {$baseDir}",
            "Destination: {$backupLocation}",
        ];

        try {
            // Exclude backups (avoids backup-of-backups duplication),
            // metrics (ephemeral monitoring data), and common temp dirs
            $excludes = [
                '--exclude=./backups',
                '--exclude=./metrics',
                '--exclude=./tmp',
                '--exclude=./.cache',
            ];
            $excludeStr = implode(' ', $excludes);

            $escBackupDir = $this->escape($this->backup_dir);
            $escBackupLoc = $this->escape($backupLocation);
            $escBaseDir = $this->escape($baseDir);
            $commands = [
                "mkdir -p {$escBackupDir}",
                "tar czf {$escBackupLoc} {$excludeStr} -C {$escBaseDir} . 2>&1",
            ];

            $output = instant_remote_process($commands, $this->server, true, false, $this->timeout, disableMultiplexing: true);
            if ($output) {
                $logs[] = 'tar output: '.trim($output);
            }

            $size = $this->calculateSize($backupLocation);

            if ($size <= 0) {
                throw new \Exception('Coolify instance backup file is empty or was not created');
            }

            $logs[] = "Backup created: {$this->formatBytes($size)}";
            $logs[] = "Excluded: backups/, metrics/, tmp/, .cache/";

            // Upload to S3 if enabled
            $isEncrypted = false;
            $localStorageDeleted = false;

            if ($this->backup->save_s3) {
                $logs[] = '';
                $logs[] = '=== S3 Upload ===';
                try {
                    if (is_null($this->s3)) {
                        throw new \Exception('S3 storage not configured (s3_storage_id='
                            .var_export($this->backup->s3_storage_id, true)
                            .', save_s3='.var_export($this->backup->save_s3, true)
                            .'). Edit the backup schedule details and select an S3 storage.');
                    }
                    $logs[] = 'S3 storage: '.($this->s3->name ?? 'ID:'.$this->s3->id).' (id='.$this->s3->id.')';
                    $logs[] = 'Endpoint: '.($this->s3->endpoint ?? 'N/A');
                    $logs[] = 'Bucket: '.($this->s3->bucket ?? 'N/A');

                    $logs[] = 'Testing S3 connection...';
                    $this->s3->testConnection(shouldSave: true);
                    $logs[] = 'S3 connection OK';

                    $logs[] = 'Uploading to S3...';
                    $this->doUploadToS3($backupLocation, $this->backup_dir.'/', $logUuid, $logs);
                    $isEncrypted = RcloneService::isEncryptionEnabled($this->s3);
                    $logs[] = 'S3 upload successful'.($isEncrypted ? ' (encrypted)' : '');

                    if ($this->backup->disable_local_backup) {
                        $this->deleteLocalFile($backupLocation);
                        $localStorageDeleted = true;
                        $logs[] = 'Local file deleted after S3 upload';
                    }
                } catch (\Throwable $e) {
                    $logs[] = 'S3 upload FAILED: '.$e->getMessage();
                }
            }

            $updateData = [
                'status' => 'success',
                'message' => implode("\n", $logs),
                'size' => $size,
                's3_uploaded' => $this->backup->save_s3 ? $this->s3_uploaded : null,
                'local_storage_deleted' => $localStorageDeleted,
            ];

            if ($this->s3_uploaded) {
                $updateData['is_encrypted'] = $isEncrypted;
            }

            $execution->update($updateData);
        } catch (\Throwable $e) {
            $logs[] = 'FATAL ERROR: '.$e->getMessage();
            $execution->update([
                'status' => 'failed',
                'message' => implode("\n", $logs),
                'size' => 0,
                'filename' => null,
                's3_uploaded' => null,
                'finished_at' => Carbon::now(),
            ]);
        } finally {
            $execution->update(['finished_at' => Carbon::now()]);
            $this->s3_uploaded = false;
        }
    }

    /**
     * Resolve the Coolify server (the server running this Coolify instance).
     * For instance backups, we always run on server_id=0 (localhost).
     */
    private function resolveCoolifyServer(): ?Server
    {
        return Server::find(0);
    }

    /**
     * Upload a backup file to S3, using encryption if enabled.
     * This is the wrapper used by volume/config backups.
     */
    private function uploadToS3(string $backupLocation, string $backupDir, string $logUuid): void
    {
        if (is_null($this->s3)) {
            throw new \Exception('S3 storage not configured (s3_storage_id='
                .var_export($this->backup->s3_storage_id, true)
                .', save_s3='.var_export($this->backup->save_s3, true)
                .'). Edit the backup schedule details and select an S3 storage.');
        }

        $this->s3->testConnection(shouldSave: true);

        // Determine Docker network from resource
        $network = $this->resolveNetwork();

        if (RcloneService::isEncryptionEnabled($this->s3)) {
            $this->uploadToS3Encrypted($backupLocation, $backupDir, $logUuid, $network);
        } else {
            $this->uploadToS3Unencrypted($backupLocation, $backupDir, $logUuid, $network);
        }

        $this->s3_uploaded = true;
    }

    /**
     * Upload a backup file to S3 with detailed logging (used by instance backups).
     */
    private function doUploadToS3(string $backupLocation, string $backupDir, string $logUuid, array &$logs): void
    {
        $network = $this->resolveNetwork();

        if (RcloneService::isEncryptionEnabled($this->s3)) {
            $logs[] = 'Using rclone (encryption enabled)';
            $this->uploadToS3Encrypted($backupLocation, $backupDir, $logUuid, $network);
        } else {
            $logs[] = 'Using mc (MinIO client)';
            $this->uploadToS3Unencrypted($backupLocation, $backupDir, $logUuid, $network);
        }

        $this->s3_uploaded = true;
    }

    /**
     * Upload via rclone with crypt overlay (encrypted).
     */
    private function uploadToS3Encrypted(string $backupLocation, string $backupDir, string $logUuid, string $network): void
    {
        $containerName = "rclone-resource-backup-{$logUuid}";

        try {
            // Apply S3 path prefix if configured
            $remotePath = $backupDir;
            if (filled($this->s3->path)) {
                $pathPrefix = trim($this->s3->path, '/');
                $remotePath = '/'.$pathPrefix.$backupDir;
            }

            $commands = RcloneService::buildUploadCommands(
                $this->s3,
                $backupLocation,
                $remotePath,
                $containerName,
                $network
            );

            $output = instant_remote_process($commands, $this->server, true, false, null, disableMultiplexing: true);
            if ($output) {
                Log::info('ResourceBackup rclone output: '.trim($output));
            }
        } finally {
            $cleanupCommands = RcloneService::buildCleanupCommands($containerName);
            instant_remote_process($cleanupCommands, $this->server, false, false, null, disableMultiplexing: true);
        }
    }

    /**
     * Upload via MinIO client (unencrypted).
     */
    private function uploadToS3Unencrypted(string $backupLocation, string $backupDir, string $logUuid, string $network): void
    {
        $key = $this->s3->key;
        $secret = $this->s3->secret;
        $bucket = $this->s3->bucket;
        $endpoint = $this->s3->endpoint;

        $helperImage = config('constants.coolify.helper_image');
        $latestVersion = getHelperVersion();
        $fullImageName = "{$helperImage}:{$latestVersion}";

        $containerName = "backup-of-{$logUuid}";

        $escContainer = $this->escape($containerName);
        $escNetwork = $this->escape($network);
        $escBackup = $this->escape($backupLocation);
        $escImage = $this->escape($fullImageName);

        $containerExists = instant_remote_process(["docker ps -a -q -f name={$escContainer}"], $this->server, false, false, null, disableMultiplexing: true);
        if (filled($containerExists)) {
            instant_remote_process(["docker rm -f {$escContainer}"], $this->server, false, false, null, disableMultiplexing: true);
        }

        $commands = [];
        $commands[] = "docker run -d --network {$escNetwork} --name {$escContainer} --rm -v {$escBackup}:{$escBackup}:ro {$escImage}";

        $escapedEndpoint = escapeshellarg($endpoint);
        $escapedKey = escapeshellarg($key);
        $escapedSecret = escapeshellarg($secret);

        $commands[] = "docker exec {$escContainer} mc alias set temporary {$escapedEndpoint} {$escapedKey} {$escapedSecret}";

        // Build S3 path with optional prefix
        $s3Path = $bucket;
        if (filled($this->s3->path)) {
            $pathPrefix = trim($this->s3->path, '/');
            $s3Path .= '/'.$pathPrefix;
        }
        $s3Path .= $backupDir;

        $escapedBackupLocation = escapeshellarg($backupLocation);
        $escapedS3Path = escapeshellarg("temporary/{$s3Path}");

        $commands[] = "docker exec {$escContainer} mc cp {$escapedBackupLocation} {$escapedS3Path}";

        try {
            $output = instant_remote_process($commands, $this->server, true, false, null, disableMultiplexing: true);
            if ($output) {
                Log::info('ResourceBackup mc output: '.trim($output));
            }
        } finally {
            instant_remote_process(["docker rm -f {$escContainer}"], $this->server, false, false, null, disableMultiplexing: true);
        }
    }

    /**
     * Resolve the Docker network for the resource.
     */
    private function resolveNetwork(): string
    {
        // Coolify instance backups have no resource — use the default coolify network
        if ($this->backup->backup_type === ScheduledResourceBackup::TYPE_COOLIFY_INSTANCE) {
            return 'coolify';
        }

        $resource = $this->backup->resource;

        if ($resource instanceof \App\Models\Application && $resource->destination) {
            return $resource->destination->network;
        }

        if ($resource instanceof \App\Models\Service && $resource->destination) {
            return $resource->destination->network;
        }

        // Fallback: try destination relationship
        if ($resource && method_exists($resource, 'destination') && $resource->destination) {
            return $resource->destination->network;
        }

        // Last resort: use coolify network
        return 'coolify';
    }

    private function calculateSize(string $path): int
    {
        $escPath = $this->escape($path);
        $size = instant_remote_process(
            ["du -b {$escPath} | cut -f1"],
            $this->server,
            false,
            false,
            null,
            disableMultiplexing: true
        );

        return (int) trim($size);
    }

    private function deleteLocalFile(string $path): void
    {
        instant_remote_process(
            ["rm -f ".escapeshellarg($path)],
            $this->server,
            throwError: false
        );
    }

    private function generateUniqueUuid(): string
    {
        $attempts = 0;
        do {
            $uuid = (string) new Cuid2;
            $exists = ScheduledResourceBackupExecution::where('uuid', $uuid)->exists();
            $attempts++;
            if ($attempts >= 3 && $exists) {
                throw new \Exception('Unable to generate unique UUID after 3 attempts');
            }
        } while ($exists);

        return $uuid;
    }

    private function addToErrorOutput(string $output): void
    {
        if ($this->error_output) {
            $this->error_output .= "\n".$output;
        } else {
            $this->error_output = $output;
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

    /**
     * Remove old backup executions based on retention settings.
     */
    private function removeOldBackups(): void
    {
        try {
            // Local retention
            if (! $this->backup->disable_local_backup) {
                $this->deleteOldBackupsLocally();
            }

            // S3 retention
            if ($this->backup->save_s3 && $this->s3) {
                $this->deleteOldBackupsFromS3();
            }

            // Delete fully removed executions
            $this->backup->executions()
                ->where('local_storage_deleted', true)
                ->where('s3_storage_deleted', true)
                ->delete();

            $this->backup->executions()
                ->where('local_storage_deleted', true)
                ->whereNull('s3_uploaded')
                ->delete();
        } catch (\Throwable $e) {
            Log::warning('ResourceBackup: Failed to remove old backups', ['error' => $e->getMessage()]);
        }
    }

    private function deleteOldBackupsLocally(): void
    {
        $successful = $this->backup->executions()
            ->where('status', 'success')
            ->where('local_storage_deleted', false)
            ->orderBy('created_at', 'desc')
            ->get();

        if ($successful->isEmpty()) {
            return;
        }

        $toDelete = $this->getBackupsToDelete(
            $successful,
            $this->backup->retention_amount_locally,
            $this->backup->retention_days_locally,
            $this->backup->retention_max_storage_locally
        );

        if ($toDelete->isEmpty()) {
            return;
        }

        $files = $this->collectExecutionFilenames($toDelete);
        if (! empty($files)) {
            deleteBackupsLocally($files, $this->server);
        }

        $this->backup->executions()
            ->whereIn('id', $toDelete->pluck('id'))
            ->update(['local_storage_deleted' => true]);
    }

    private function deleteOldBackupsFromS3(): void
    {
        $successful = $this->backup->executions()
            ->where('status', 'success')
            ->where('s3_storage_deleted', false)
            ->orderBy('created_at', 'desc')
            ->get();

        if ($successful->isEmpty()) {
            return;
        }

        $toDelete = $this->getBackupsToDelete(
            $successful,
            $this->backup->retention_amount_s3,
            $this->backup->retention_days_s3,
            $this->backup->retention_max_storage_s3
        );

        if ($toDelete->isEmpty()) {
            return;
        }

        $files = $this->collectExecutionFilenames($toDelete);
        if (! empty($files)) {
            deleteBackupsS3($files, $this->s3);
        }

        $this->backup->executions()
            ->whereIn('id', $toDelete->pluck('id'))
            ->update(['s3_storage_deleted' => true]);
    }

    /**
     * Flatten execution filename fields (single path or JSON array) for retention deletes.
     *
     * @return list<string>
     */
    private function collectExecutionFilenames($executions): array
    {
        return $executions
            ->flatMap(fn ($execution) => ScheduledResourceBackupExecution::expandFilenames($execution->filename))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Determine which backup executions should be deleted based on retention rules.
     */
    private function getBackupsToDelete($successful, int $amount, int $days, float $maxStorageGB)
    {
        if ($amount === 0 && $days === 0 && $maxStorageGB == 0) {
            return collect();
        }

        $toDelete = collect();

        if ($amount > 0) {
            $toDelete = $toDelete->merge($successful->skip($amount));
        }

        if ($days > 0 && $successful->isNotEmpty()) {
            $oldest = $successful->first()->created_at->clone()->utc()->subDays($days);
            $toDelete = $toDelete->merge(
                $successful->filter(fn ($e) => $e->created_at->utc() < $oldest)
            );
        }

        if ($maxStorageGB > 0) {
            $maxBytes = $maxStorageGB * pow(1024, 3);
            $totalSize = 0;

            foreach ($successful->skip(1) as $exec) {
                $totalSize += (int) $exec->size;
                if ($totalSize > $maxBytes) {
                    $toDelete = $toDelete->merge(
                        $successful->filter(fn ($b) => $b->created_at->utc() <= $exec->created_at->utc())->skip(1)
                    );
                    break;
                }
            }
        }

        return $toDelete->unique('id');
    }

    public function failed(?\Throwable $exception): void
    {
        Log::channel('scheduled-errors')->error('ResourceBackup permanently failed', [
            'job' => 'ResourceBackupJob',
            'backup_id' => $this->backup->uuid ?? 'unknown',
            'error' => $exception?->getMessage(),
        ]);
    }
}
