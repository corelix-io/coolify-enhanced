<?php

namespace CorelixIo\Platform\Traits;

/**
 * Adds encryption-related accessors and helpers to the S3Storage model.
 *
 * Applied via the service provider's booted callback on \App\Models\S3Storage.
 * This trait expects the model to have these database columns:
 *   - encryption_enabled (boolean)
 *   - encryption_password (longText, encrypted)
 *   - encryption_salt (longText, encrypted)
 *   - filename_encryption (string: 'off', 'standard', 'obfuscate')
 *   - directory_name_encryption (boolean)
 */
trait HasS3Encryption
{
    /**
     * Check if backup encryption is enabled and properly configured.
     */
    public function hasEncryption(): bool
    {
        return (bool) $this->encryption_enabled
            && filled($this->encryption_password);
    }

    /**
     * Check if filename encryption is enabled (not 'off').
     */
    public function hasFilenameEncryption(): bool
    {
        return $this->hasEncryption()
            && $this->filename_encryption !== 'off';
    }

    /**
     * Get the encryption configuration as an array.
     */
    public function getEncryptionConfig(): array
    {
        return [
            'enabled' => $this->hasEncryption(),
            'filename_encryption' => $this->filename_encryption ?? 'off',
            'directory_name_encryption' => (bool) ($this->directory_name_encryption ?? false),
            'has_salt' => filled($this->encryption_salt),
        ];
    }
}
