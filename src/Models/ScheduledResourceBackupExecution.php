<?php

namespace CorelixIo\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledResourceBackupExecution extends Model
{
    protected $fillable = [
        'uuid', 'backup_type', 'status', 'message', 'size', 'filename', 'backup_label',
        'is_encrypted', 's3_uploaded', 'local_storage_deleted', 's3_storage_deleted',
        'scheduled_resource_backup_id', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'is_encrypted' => 'boolean',
            's3_uploaded' => 'boolean',
            'local_storage_deleted' => 'boolean',
            's3_storage_deleted' => 'boolean',
        ];
    }

    public function scheduledResourceBackup(): BelongsTo
    {
        return $this->belongsTo(ScheduledResourceBackup::class);
    }

    /**
     * Expand execution filename into one or more local/S3 paths.
     *
     * Full backups store a JSON array of artifact paths; other types use a single path string.
     *
     * @return list<string>
     */
    public static function expandFilenames(?string $filename): array
    {
        if ($filename === null || $filename === '') {
            return [];
        }

        $decoded = json_decode($filename, true);
        if (is_array($decoded)) {
            return array_values(array_filter($decoded, fn ($path) => is_string($path) && $path !== ''));
        }

        return [$filename];
    }
}
