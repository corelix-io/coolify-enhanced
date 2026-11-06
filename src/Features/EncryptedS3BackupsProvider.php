<?php

namespace CorelixIo\Platform\Features;

use Illuminate\Foundation\Application;

class EncryptedS3BackupsProvider implements FeatureProviderInterface
{
    public static function featureKey(): string
    {
        return 'ENCRYPTED_S3_BACKUPS';
    }

    public function register(Application $app): void {}

    public function boot(Application $app): void
    {
        \Livewire\Livewire::component('enhanced::storage-encryption-form', \CorelixIo\Platform\Livewire\StorageEncryptionForm::class);
    }

    public function booted(Application $app): void
    {
        if (!class_exists(\App\Models\S3Storage::class)) {
            return;
        }
        $encryptionCasts = [
            'encryption_enabled' => 'boolean',
            'encryption_password' => 'encrypted',
            'encryption_salt' => 'encrypted',
            'directory_name_encryption' => 'boolean',
        ];
        \App\Models\S3Storage::retrieved(function (\App\Models\S3Storage $storage) use ($encryptionCasts) {
            $storage->mergeCasts($encryptionCasts);
        });
        \App\Models\S3Storage::saving(function (\App\Models\S3Storage $storage) use ($encryptionCasts) {
            $storage->mergeCasts($encryptionCasts);
            if ($storage->encryption_password !== null) {
                $storage->encryption_password = trim($storage->encryption_password);
            }
            if ($storage->encryption_salt !== null) {
                $storage->encryption_salt = trim($storage->encryption_salt);
            }
        });

        // Expose HasS3Encryption helpers on upstream S3Storage (trait cannot be mixed at runtime)
        if (! \App\Models\S3Storage::hasMacro('hasEncryption')) {
            \App\Models\S3Storage::macro('hasEncryption', function (): bool {
                /** @var \App\Models\S3Storage $this */
                return (bool) $this->encryption_enabled && filled($this->encryption_password);
            });
            \App\Models\S3Storage::macro('hasFilenameEncryption', function (): bool {
                /** @var \App\Models\S3Storage $this */
                return $this->hasEncryption() && $this->filename_encryption !== 'off';
            });
            \App\Models\S3Storage::macro('getEncryptionConfig', function (): array {
                /** @var \App\Models\S3Storage $this */
                return [
                    'enabled' => $this->hasEncryption(),
                    'filename_encryption' => $this->filename_encryption ?? 'off',
                    'directory_name_encryption' => (bool) ($this->directory_name_encryption ?? false),
                    'has_salt' => filled($this->encryption_salt),
                ];
            });
        }
    }

    public function apiRoutes(): ?string
    {
        return null;
    }

    public function webRoutes(): ?string
    {
        return null;
    }
}
