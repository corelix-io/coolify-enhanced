<?php

// =============================================================================
// OVERLAY: Modified version of Coolify's app/Livewire/Project/Database/ImportForm.php
// =============================================================================
// [ENCRYPTION OVERLAY]:
//   1. Added import for RcloneService
//   2. Added $s3EncryptionEnabled property + encryption status in S3 storage list
//   3. checkS3File() skips pre-check when filename encryption hides the object name
//   4. restoreFromS3() branches to rclone crypt download when encryption is enabled
//   5. restoreFromS3Encrypted() new method
// [SECURITY FIX — COR-42]:
//   6. restoreFromS3Encrypted: fixed double-escapeshellarg in $restoreAndCleanupCommand
//      (inner $escapedScriptPath/$escapedContainerTmpPath re-escaped by outer call —
//      sh -c argument was malformed, restore never executed, cleanup failed)
//   7. restoreFromS3Encrypted: added rm -f of host-side restore script after docker cp
//      (script may contain DB credentials; was not purged from remote server /tmp)
// =============================================================================

namespace App\Livewire\Project\Database;

use App\Actions\Database\StartDatabaseImport;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceDatabase;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneRedis;
use App\Models\StandaloneSqlite;
use App\Rules\SafeWebhookUrl;
use App\Support\DatabaseImport\DatabaseImportCommandBuilder;
use App\Support\DatabaseImport\DatabaseImportException;
use App\Support\DatabaseImport\DatabaseImportSource;
use App\Support\ValidationPatterns;
use CorelixIo\Platform\Services\RcloneService; // [ENCRYPTION OVERLAY]
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ImportForm extends Component
{
    use AuthorizesRequests;

    /**
     * Validate that a string is safe for use as an S3 bucket name.
     */
    private function validateBucketName(string $bucket): bool
    {
        return ValidationPatterns::isValidS3BucketName($bucket);
    }

    /**
     * Validate that a string is safe for use as an S3 path.
     * Allows alphanumerics, dots, dashes, underscores, slashes, and common file characters.
     */
    private function validateS3Path(string $path): bool
    {
        // Must not be empty
        if (empty($path)) {
            return false;
        }

        // Must not contain dangerous shell metacharacters or command injection patterns
        $dangerousPatterns = [
            '..', // Directory traversal
            '$(', // Command substitution
            '`',  // Backtick command substitution
            '|',  // Pipe
            ';',  // Command separator
            '&',  // Background/AND
            '>',  // Redirect
            '<',  // Redirect
            "\n", // Newline
            "\r", // Carriage return
            "\0", // Null byte
            "'",  // Single quote
            '"',  // Double quote
            '\\', // Backslash
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (str_contains($path, $pattern)) {
                return false;
            }
        }

        // Allow alphanumerics, dots, dashes, underscores, slashes, spaces, plus, equals, at
        return preg_match('/^[a-zA-Z0-9.\-_\/\s+@=]+$/', $path) === 1;
    }

    /**
     * Validate that a string is safe for use as a file path on the server.
     */
    private function validateServerPath(string $path): bool
    {
        // Must be an absolute path
        if (! str_starts_with($path, '/')) {
            return false;
        }

        // Must not contain dangerous shell metacharacters or command injection patterns
        $dangerousPatterns = [
            '..', // Directory traversal
            '$(', // Command substitution
            '`',  // Backtick command substitution
            '|',  // Pipe
            ';',  // Command separator
            '&',  // Background/AND
            '>',  // Redirect
            '<',  // Redirect
            "\n", // Newline
            "\r", // Carriage return
            "\0", // Null byte
            "'",  // Single quote
            '"',  // Double quote
            '\\', // Backslash
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (str_contains($path, $pattern)) {
                return false;
            }
        }

        // Allow alphanumerics, dots, dashes, underscores, slashes, and spaces
        return preg_match('/^[a-zA-Z0-9.\-_\/\s]+$/', $path) === 1;
    }

    public bool $unsupported = false;

    // Store IDs instead of models for proper Livewire serialization
    #[Locked]
    public ?int $resourceId = null;

    #[Locked]
    public ?string $resourceType = null;

    #[Locked]
    public ?int $serverId = null;

    // View-friendly properties to avoid computed property access in Blade
    #[Locked]
    public string $resourceUuid = '';

    public string $resourceStatus = '';

    #[Locked]
    public string $resourceDbType = '';

    public array $parameters = [];

    public array $containers = [];

    public bool $scpInProgress = false;

    public bool $importRunning = false;

    public ?string $filename = null;

    public ?string $filesize = null;

    public bool $isUploading = false;

    public int $progress = 0;

    public bool $error = false;

    #[Locked]
    public string $container;

    public array $importCommands = [];

    public bool $dumpAll = false;

    public bool $replaceExisting = false;

    /**
     * PostgreSQL archives: restore owners and privileges instead of skipping them.
     */
    public bool $keepOwners = false;

    /**
     * MySQL and MariaDB all-databases backups: also restore the system databases (users, passwords and privileges).
     */
    public bool $restoreMysqlUsers = false;

    /**
     * SQLite: the database file to restore into. Always one of the database's own files.
     */
    public ?string $sqliteDatabase = null;

    public string $restoreCommandText = '';

    public string $customLocation = '';

    /**
     * The file source the user selected: 'upload' after a completed upload, 'server' after a checked server path.
     * Only the server sets it, so an import never falls back to an older upload.
     */
    #[Locked]
    public ?string $fileSource = null;

    public ?int $activityId = null;

    // S3 Restore properties
    public array $availableS3Storages = [];

    // [ENCRYPTION OVERLAY] Track whether selected S3 storage has encryption enabled
    public bool $s3EncryptionEnabled = false;

    public ?int $s3StorageId = null;

    public string $s3Path = '';

    public ?int $s3FileSize = null;

    #[Computed]
    public function resource()
    {
        if ($this->resourceId === null || $this->resourceType === null) {
            return null;
        }

        return $this->resourceType::find($this->resourceId);
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function sqliteDatabaseFiles(): array
    {
        return $this->resource instanceof StandaloneSqlite ? $this->resource->databaseFiles() : [];
    }

    #[Computed]
    public function server()
    {
        if ($this->serverId === null) {
            return null;
        }

        return Server::query()->where('team_id', $this->resourceTeamId())->find($this->serverId);
    }

    /**
     * Team of the loaded database. The session team can differ: a user can switch teams in
     * another tab while this form stays open.
     */
    private function resourceTeamId(): ?int
    {
        return $this->resource?->team()?->id;
    }

    protected $listeners = [
        'slideOverClosed' => 'resetActivityId',
    ];

    public function resetActivityId()
    {
        $this->activityId = null;
    }

    public function mount()
    {
        $this->parameters = get_route_parameters();
        $this->getContainers();
        $this->loadAvailableS3Storages();
        $this->initializeRestoreOptions();
    }

    protected function initializeRestoreOptions(): void
    {
        $this->sqliteDatabase = $this->sqliteDatabaseFiles[0] ?? null;
        $this->refreshRestoreCommandText();
    }

    public function updatedDumpAll(): void
    {
        $this->refreshRestoreCommandText();
    }

    public function updatedReplaceExisting(): void
    {
        $this->refreshRestoreCommandText();
    }

    public function updatedKeepOwners(): void
    {
        $this->refreshRestoreCommandText();
    }

    public function updatedRestoreMysqlUsers(): void
    {
        $this->refreshRestoreCommandText();
    }

    public function updatedSqliteDatabase(): void
    {
        if (! in_array($this->sqliteDatabase, $this->sqliteDatabaseFiles, true)) {
            $this->sqliteDatabase = $this->sqliteDatabaseFiles[0] ?? null;
        }
        $this->refreshRestoreCommandText();
    }

    /**
     * Preselects the SQLite file whose name matches the chosen backup, else the first file.
     */
    public function selectSqliteDatabaseFor(?string $backupName = null): void
    {
        if (! $this->resource instanceof StandaloneSqlite) {
            return;
        }
        $this->sqliteDatabase = $this->resource->defaultRestoreFile($backupName);
        $this->refreshRestoreCommandText();
    }

    /**
     * Shows the exact script the import runs, so the confirmation matches the restore.
     */
    private function refreshRestoreCommandText(): void
    {
        $commands = app(DatabaseImportCommandBuilder::class);

        try {
            $this->restoreCommandText = $this->resource && $commands->supports($this->resource)
                ? $commands->buildRestoreCommand($this->resource, '<temp_backup_file>', $this->dumpAll, $this->replaceExisting, $this->keepOwners, $this->sqliteDatabase, $this->restoreMysqlUsers)
                : '';
        } catch (\InvalidArgumentException) {
            $this->restoreCommandText = '';
        }
    }

    public function getContainers()
    {
        $this->containers = [];
        $teamId = data_get(auth()->user()->currentTeam(), 'id');

        // Try to find resource by route parameter
        $databaseUuid = data_get($this->parameters, 'database_uuid');
        $stackServiceUuid = data_get($this->parameters, 'stack_service_uuid');

        $resource = null;
        if ($databaseUuid) {
            // Standalone database route
            $resource = getResourceByUuid($databaseUuid, $teamId);
            if (is_null($resource)) {
                abort(404);
            }
        } elseif ($stackServiceUuid) {
            // ServiceDatabase route - look up the service database
            $serviceUuid = data_get($this->parameters, 'service_uuid');
            $project = currentTeam()
                ->projects()
                ->select('id', 'uuid', 'team_id')
                ->where('uuid', data_get($this->parameters, 'project_uuid'))
                ->firstOrFail();
            $environment = $project->environments()
                ->select('id', 'uuid', 'name', 'project_id')
                ->where('uuid', data_get($this->parameters, 'environment_uuid'))
                ->firstOrFail();
            $service = $environment->services()->whereUuid($serviceUuid)->firstOrFail();
            $resource = $service->databases()->whereUuid($stackServiceUuid)->first();
            if (is_null($resource)) {
                abort(404);
            }
        } else {
            abort(404);
        }

        $this->authorize('view', $resource);

        // Store IDs for Livewire serialization
        $this->resourceId = $resource->id;
        $this->resourceType = get_class($resource);

        // Store view-friendly properties
        $this->resourceStatus = $resource->status ?? '';

        // Handle ServiceDatabase server access differently
        if ($resource->getMorphClass() === ServiceDatabase::class) {
            $server = $resource->service?->server;
            if (! $server) {
                abort(404, 'Server not found for this service database.');
            }
            $this->serverId = $server->id;
            $this->container = $resource->name.'-'.$resource->service->uuid;
            $this->resourceUuid = $resource->uuid; // Use ServiceDatabase's own UUID

            // Determine database type for ServiceDatabase
            $dbType = $resource->databaseType();
            if (str_contains($dbType, 'postgres')) {
                $this->resourceDbType = 'standalone-postgresql';
            } elseif (str_contains($dbType, 'mysql')) {
                $this->resourceDbType = 'standalone-mysql';
            } elseif (str_contains($dbType, 'mariadb')) {
                $this->resourceDbType = 'standalone-mariadb';
            } elseif (str_contains($dbType, 'mongo')) {
                $this->resourceDbType = 'standalone-mongodb';
            } else {
                $this->resourceDbType = $dbType;
            }
        } else {
            $server = $resource->destination?->server;
            if (! $server) {
                abort(404, 'Server not found for this database.');
            }
            $this->serverId = $server->id;
            $this->container = $resource->uuid;
            $this->resourceUuid = $resource->uuid;
            $this->resourceDbType = $resource->type();
        }

        if (str($resource->status)->startsWith('running')) {
            $this->containers[] = $this->container;
        }

        if (
            $resource->getMorphClass() === StandaloneRedis::class ||
            $resource->getMorphClass() === StandaloneKeydb::class ||
            $resource->getMorphClass() === StandaloneDragonfly::class ||
            $resource->getMorphClass() === StandaloneClickhouse::class
        ) {
            $this->unsupported = true;
        }

        // Mark unsupported ServiceDatabase types (Redis, KeyDB, etc.)
        if ($resource->getMorphClass() === ServiceDatabase::class) {
            $dbType = $resource->databaseType();
            if (str_contains($dbType, 'redis') || str_contains($dbType, 'keydb') ||
                str_contains($dbType, 'dragonfly') || str_contains($dbType, 'clickhouse')) {
                $this->unsupported = true;
            }
        }
    }

    public function checkFile()
    {
        $this->authorize('update', $this->resource);

        if (filled($this->customLocation)) {
            // Validate the custom location to prevent command injection
            if (! $this->validateServerPath($this->customLocation)) {
                $this->dispatch('error', 'Invalid file path. Path must be absolute and contain only safe characters (alphanumerics, dots, dashes, underscores, slashes).');

                return;
            }

            if (! $this->server) {
                $this->dispatch('error', 'Server not found. Please refresh the page.');

                return;
            }

            try {
                if (! $this->serverFileExists($this->customLocation)) {
                    $this->dispatch('error', 'The file does not exist or has been deleted.');

                    return;
                }
                $this->filename = $this->customLocation;
                $this->fileSource = 'server';
                $this->discardStagedUpload();
                $this->selectSqliteDatabaseFor($this->customLocation);
                $this->dispatch('success', 'The file exists.');
            } catch (\Throwable $e) {
                return handleError($e, $this);
            }
        }
    }

    public function updatedCustomLocation(): void
    {
        if ($this->fileSource === 'server') {
            $this->fileSource = null;
        }
    }

    /**
     * Called by the upload form after an upload is complete.
     */
    public function selectUploadedFile(string $name): void
    {
        $this->authorize('update', $this->resource);

        if (! Storage::exists($this->stagedUploadPath())) {
            $this->fileSource = null;
            $this->dispatch('error', 'The uploaded file was not found. Please upload it again.');

            return;
        }

        $this->fileSource = 'upload';
        $this->customLocation = '';
        $this->selectSqliteDatabaseFor($name);
    }

    private function stagedUploadPath(): string
    {
        return "upload/{$this->resource->uuid}/restore";
    }

    /**
     * Delete an upload that was not imported when the user selects another source. Skipped while
     * an import of this database runs, because that import can still copy the upload.
     */
    private function discardStagedUpload(): void
    {
        $lock = Cache::lock(StartDatabaseImport::lockKey($this->resource->uuid), 60);
        if (! $lock->get()) {
            return;
        }

        try {
            Storage::delete($this->stagedUploadPath());
        } finally {
            $lock->release();
        }
    }

    protected function serverFileExists(string $path): bool
    {
        $escapedPath = escapeshellarg($path);

        return filled(instant_remote_process(["ls -l {$escapedPath}"], $this->server, throwError: false));
    }

    public function runImport(string $password = ''): bool|string
    {
        if (! verifyPasswordConfirmation($password, $this)) {
            return 'The provided password is incorrect.';
        }

        $this->authorize('update', $this->resource);

        if (! ValidationPatterns::isValidContainerName($this->container)) {
            $this->dispatch('error', 'Invalid container name.');

            return true;
        }

        if (! in_array($this->fileSource, ['upload', 'server'], true)) {
            $this->dispatch('error', 'Please select a file to import.');

            return true;
        }

        if (! $this->server) {
            $this->dispatch('error', 'Server not found. Please refresh the page.');

            return true;
        }

        try {
            $this->importRunning = true;
            if ($this->fileSource === 'server') {
                $this->discardStagedUpload();
            }
            $source = $this->fileSource === 'upload'
                ? new DatabaseImportSource('upload', dumpAll: $this->dumpAll, replaceExisting: $this->replaceExisting, keepOwners: $this->keepOwners, sqliteDatabase: $this->sqliteDatabase, restoreMysqlUsers: $this->restoreMysqlUsers)
                : new DatabaseImportSource('server', path: $this->customLocation, dumpAll: $this->dumpAll, replaceExisting: $this->replaceExisting, keepOwners: $this->keepOwners, sqliteDatabase: $this->sqliteDatabase, restoreMysqlUsers: $this->restoreMysqlUsers);
            $activity = StartDatabaseImport::run($this->resource, $source, (int) $this->resourceTeamId());
            $this->activityId = $activity->id;
            $this->dispatch('activityMonitor', $activity->id);
            $this->dispatch('databaserestore');
            auditLog('ui.database.import_started', [
                'team_id' => $this->resource->team()?->id,
                'database_uuid' => $this->resource->uuid,
                'database_name' => $this->resource->name,
                'source' => 'file',
                'replace_existing' => $this->replaceExisting,
            ]);
        } catch (DatabaseImportException $e) {
            $this->importRunning = false;
            $this->dispatch('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->importRunning = false;
            handleError($e, $this);

            return true;
        } finally {
            $this->filename = null;
            $this->fileSource = null;
            $this->importCommands = [];
        }

        return true;
    }

    public function loadAvailableS3Storages()
    {
        try {
            $this->availableS3Storages = S3Storage::ownedByCurrentTeamAPI((int) $this->resourceTeamId(), ['id', 'name', 'description'])
                ->where('is_usable', true)
                ->get()
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'description' => $s->description,
                    // [ENCRYPTION OVERLAY] Include encryption status
                    'encryption_enabled' => RcloneService::isEncryptionEnabled($s),
                ])
                ->toArray();
        } catch (\Throwable $e) {
            $this->availableS3Storages = [];
        }
    }

    public function updatedS3Path($value)
    {
        // Reset validation state when path changes
        $this->s3FileSize = null;

        // Ensure path starts with a slash
        if ($value !== null && $value !== '') {
            $this->s3Path = str($value)->trim()->start('/')->value();
        }
    }

    public function updatedS3StorageId()
    {
        // Reset validation state when storage changes
        $this->s3FileSize = null;
        // [ENCRYPTION OVERLAY] Update encryption status when storage selection changes
        $this->s3EncryptionEnabled = false;
        $storage = collect($this->availableS3Storages)->firstWhere('id', $this->s3StorageId);
        if ($storage) {
            $this->s3EncryptionEnabled = $storage['encryption_enabled'] ?? false;
        }
    }

    public function checkS3File()
    {
        $this->authorize('update', $this->resource);

        if (! $this->s3StorageId) {
            $this->dispatch('error', 'Please select an S3 storage.');

            return;
        }

        if (blank($this->s3Path)) {
            $this->dispatch('error', 'Please provide an S3 path.');

            return;
        }

        // Clean the path (remove leading slash if present)
        $cleanPath = ltrim($this->s3Path, '/');

        // Validate the S3 path early to prevent command injection in subsequent operations
        if (! $this->validateS3Path($cleanPath)) {
            $this->dispatch('error', 'Invalid S3 path. Path must contain only safe characters (alphanumerics, dots, dashes, underscores, slashes).');

            return;
        }

        try {
            $s3Storage = S3Storage::ownedByCurrentTeamAPI((int) $this->resourceTeamId())->findOrFail($this->s3StorageId);

            // Validate bucket name early
            if (! $this->validateBucketName($s3Storage->bucket)) {
                $this->dispatch('error', 'Invalid S3 bucket name. Bucket name must contain only letters, numbers, dots, and dashes, and must follow S3 bucket naming rules.');

                return;
            }

            // Test connection
            $s3Storage->testConnection();

            // Build S3 disk configuration
            $disk = Storage::build([
                'driver' => 's3',
                'region' => $s3Storage->region,
                'key' => $s3Storage->key,
                'secret' => $s3Storage->secret,
                'bucket' => $s3Storage->bucket,
                'endpoint' => $s3Storage->endpoint,
                'use_path_style_endpoint' => true,
                'http' => SafeWebhookUrl::httpClientOptions($s3Storage->endpoint),
            ]);

            // [ENCRYPTION OVERLAY] For encrypted storage, filename encryption hides the actual
            // object name. We skip the pre-check and trust the user's path.
            if (RcloneService::isEncryptionEnabled($s3Storage)) {
                $this->s3FileSize = 0;
                $this->selectSqliteDatabaseFor($cleanPath);
                $this->dispatch('success', 'S3 connection verified. File will be decrypted during restore (size cannot be determined for encrypted filenames).');

                return;
            }

            // Check if file exists
            if (! $disk->exists($cleanPath)) {
                $this->dispatch('error', 'File not found in S3. Please check the path.');

                return;
            }

            // Get file size
            $this->s3FileSize = $disk->size($cleanPath);
            $this->selectSqliteDatabaseFor($cleanPath);

            $this->dispatch('success', 'File found in S3. Size: '.formatBytes($this->s3FileSize).(($this->s3EncryptionEnabled) ? ' (encrypted - will be decrypted during restore)' : ''));
        } catch (\Throwable $e) {
            $this->s3FileSize = null;

            return handleError($e, $this);
        }
    }

    public function restoreFromS3(string $password = ''): bool|string
    {
        if (! verifyPasswordConfirmation($password, $this)) {
            return 'The provided password is incorrect.';
        }

        $this->authorize('update', $this->resource);

        if (! ValidationPatterns::isValidContainerName($this->container)) {
            $this->dispatch('error', 'Invalid container name.');

            return true;
        }

        if (! $this->s3StorageId || blank($this->s3Path)) {
            $this->dispatch('error', 'Please select S3 storage and provide a path first.');

            return true;
        }

        if (is_null($this->s3FileSize)) {
            $this->dispatch('error', 'Please check the file first by clicking "Check File".');

            return true;
        }

        if (! $this->server) {
            $this->dispatch('error', 'Server not found. Please refresh the page.');

            return true;
        }

        try {
            $this->importRunning = true;
            $this->discardStagedUpload();

            // [ENCRYPTION OVERLAY] When storage is encrypted, download + decrypt via rclone crypt
            $s3Storage = S3Storage::ownedByCurrentTeamAPI((int) $this->resourceTeamId())->findOrFail($this->s3StorageId);
            if (RcloneService::isEncryptionEnabled($s3Storage)) {
                $this->restoreFromS3Encrypted($s3Storage);

                return true;
            }

            $source = new DatabaseImportSource('s3', path: $this->s3Path, s3StorageUuid: (string) $this->s3StorageId, dumpAll: $this->dumpAll, replaceExisting: $this->replaceExisting, keepOwners: $this->keepOwners, sqliteDatabase: $this->sqliteDatabase, restoreMysqlUsers: $this->restoreMysqlUsers);
            $activity = StartDatabaseImport::run($this->resource, $source, (int) $this->resourceTeamId());
            $this->activityId = $activity->id;
            $this->dispatch('activityMonitor', $activity->id);
            $this->dispatch('databaserestore');
            auditLog('ui.database.restore_started', [
                'team_id' => $this->resource->team()?->id,
                'database_uuid' => $this->resource->uuid,
                'database_name' => $this->resource->name,
                'source' => 's3',
                'replace_existing' => $this->replaceExisting,
                'storage_id' => $this->s3StorageId,
            ]);
            $this->dispatch('info', 'Restoring database from S3. Progress will be shown in the activity monitor...');
        } catch (DatabaseImportException $e) {
            $this->importRunning = false;
            $this->dispatch('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->importRunning = false;
            handleError($e, $this);

            return true;
        }

        return true;
    }

    /**
     * [ENCRYPTION OVERLAY] Restore from an encrypted S3 backup using rclone's crypt overlay.
     * Mirrors the unencrypted path's shell-escaping hardening.
     */
    private function restoreFromS3Encrypted(S3Storage $s3Storage): void
    {
        $bucket = $s3Storage->bucket;
        $cleanPath = ltrim($this->s3Path, '/');

        if (! $this->validateBucketName($bucket) || ! $this->validateS3Path($cleanPath)) {
            $this->dispatch('error', 'Invalid S3 bucket name or path.');

            return;
        }

        // Get database destination network
        if ($this->resource->getMorphClass() === ServiceDatabase::class) {
            $destinationNetwork = $this->resource->service->destination->network ?? 'coolify';
        } else {
            $destinationNetwork = $this->resource->destination->network ?? 'coolify';
        }

        $rcloneContainerName = "rclone-restore-{$this->resourceUuid}";
        $localDownloadPath = "/tmp/rclone-download-{$this->resourceUuid}-".basename($cleanPath);
        $containerTmpPath = "/tmp/restore_{$this->resourceUuid}-".basename($cleanPath);
        $scriptPath = "/tmp/restore_{$this->resourceUuid}.sh";

        $escapedLocalDownloadPath = escapeshellarg($localDownloadPath);
        $escapedContainerTmpPath = escapeshellarg($containerTmpPath);
        $escapedScriptPath = escapeshellarg($scriptPath);
        $escapedRcloneContainerPath = escapeshellarg("{$rcloneContainerName}:{$localDownloadPath}");
        $escapedDatabaseContainerTmpPath = escapeshellarg("{$this->container}:{$containerTmpPath}");
        $escapedDatabaseContainerScriptPath = escapeshellarg("{$this->container}:{$scriptPath}");
        // Use raw (unescaped) paths here: they only contain UUID chars and validated S3 basename
        // chars — no single quotes — so one escapeshellarg() for the outer shell is sufficient.
        // Double-escaping (using $escapedScriptPath/$escapedContainerTmpPath inside) corrupts the
        // sh -c argument: the inner single quotes get escaped by the outer call, breaking the
        // command and leaving decrypted temp files in the container.
        $restoreAndCleanupCommand = escapeshellarg("{$scriptPath} && rm -f {$containerTmpPath} {$scriptPath}");

        $commands = [];

        // 1. Clean up any previous run
        $commands[] = "docker rm -f {$rcloneContainerName} 2>/dev/null || true";
        $commands[] = "rm -f {$escapedLocalDownloadPath} 2>/dev/null || true";
        $commands[] = "docker exec {$this->container} rm -f {$escapedContainerTmpPath} {$escapedScriptPath} 2>/dev/null || true";

        // [PATH PREFIX OVERLAY] Apply storage base path prefix for rclone download
        $rclonePath = $cleanPath;
        if (filled($s3Storage->path)) {
            $rclonePath = trim($s3Storage->path, '/').'/'.$cleanPath;
        }

        // 2. Build rclone download commands (creates container, downloads via crypt)
        $downloadCommands = RcloneService::buildDownloadCommands(
            $s3Storage,
            $rclonePath,
            $localDownloadPath,
            $rcloneContainerName,
            $destinationNetwork
        );
        $commands = array_merge($commands, $downloadCommands);

        // 3. Copy from rclone container to server, then to database container
        $commands[] = "docker cp {$escapedRcloneContainerPath} {$escapedLocalDownloadPath}";
        $commands[] = "docker cp {$escapedLocalDownloadPath} {$escapedDatabaseContainerTmpPath}";

        // 4. Cleanup rclone container and temp files
        $cleanupCommands = RcloneService::buildCleanupCommands($rcloneContainerName);
        $commands = array_merge($commands, $cleanupCommands);
        $commands[] = "rm -f {$escapedLocalDownloadPath} 2>/dev/null || true";

        // 5. Build and execute restore command inside database container
        $restoreCommand = $this->buildRestoreCommand($containerTmpPath);
        $restoreCommandBase64 = base64_encode($restoreCommand);
        $commands[] = "echo \"{$restoreCommandBase64}\" | base64 -d > {$escapedScriptPath}";
        $commands[] = "chmod +x {$escapedScriptPath}";
        $commands[] = "docker cp {$escapedScriptPath} {$escapedDatabaseContainerScriptPath}";
        // Remove host-side restore script after it has been copied into the container.
        // The script may contain DB credentials; /tmp on the host is not auto-purged.
        $commands[] = "rm -f {$escapedScriptPath} 2>/dev/null || true";

        // 6. Execute restore and cleanup
        $commands[] = "docker exec {$this->container} sh -c {$restoreAndCleanupCommand}";
        $commands[] = "docker exec {$this->container} sh -c 'echo \"Import finished with exit code $?\"'";

        $activity = remote_process($commands, $this->server, ignore_errors: true, callEventOnFinish: 'S3RestoreJobFinished', callEventData: [
            'containerName' => $rcloneContainerName,
            'serverTmpPath' => $localDownloadPath,
            'scriptPath' => $scriptPath,
            'containerTmpPath' => $containerTmpPath,
            'container' => $this->container,
            'serverId' => $this->server->id,
        ]);

        $this->activityId = $activity->id;
        $this->dispatch('activityMonitor', $activity->id);
        $this->dispatch('databaserestore');
        $this->dispatch('info', 'Restoring encrypted database backup from S3. Progress will be shown in the activity monitor...');
    }

    public function buildRestoreCommand(string $tmpPath): string
    {
        return app(DatabaseImportCommandBuilder::class)->buildRestoreCommand($this->resource, $tmpPath, $this->dumpAll, $this->replaceExisting, $this->keepOwners, $this->sqliteDatabase, $this->restoreMysqlUsers);
    }
}
