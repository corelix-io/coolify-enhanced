<?php

namespace CorelixIo\Platform\Livewire;

use App\Models\S3Storage;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Livewire component for managing S3 backup encryption settings.
 *
 * Rendered via view overlay on the storage show page. Uses Coolify's
 * native form components (<x-forms.checkbox>, <x-forms.input>, etc.)
 * to ensure proper styling and Livewire hydration.
 *
 * Note: We do NOT call $this->authorize() in mount() because the
 * storage page itself already authorizes access via StorageShow::mount().
 *
 * Security: existing encryption passwords are never hydrated into public
 * Livewire properties — only empty placeholder fields are shown.
 */
class StorageEncryptionForm extends Component
{
    use AuthorizesRequests;
    public ?int $storageId = null;

    // S3 path prefix (PR #7776)
    public ?string $path = null;

    public bool $encryptionEnabled = false;

    public string $encryptionPassword = '';

    public string $encryptionSalt = '';

    public bool $hasExistingPassword = false;

    public bool $hasExistingSalt = false;

    public string $filenameEncryption = 'off';

    public bool $directoryNameEncryption = false;

    public string $saveMessage = '';

    public string $saveStatus = '';

    protected function rules(): array
    {
        return [
            'path' => ['nullable', 'max:255', 'regex:/^[a-zA-Z0-9\/\-\_\.]*$/', 'not_regex:/\.\./'],
            'encryptionEnabled' => ['boolean'],
            'encryptionPassword' => [
                Rule::requiredIf(fn () => $this->encryptionEnabled && ! $this->hasExistingPassword),
                'nullable',
                'max:255',
            ],
            'encryptionSalt' => ['nullable', 'max:255'],
            'filenameEncryption' => ['in:off,standard,obfuscate'],
            'directoryNameEncryption' => ['boolean'],
        ];
    }

    public function mount(int $storageId): void
    {
        $storage = S3Storage::find($storageId);
        if (! $storage) {
            return;
        }

        $this->storageId = $storage->id;

        // Gracefully handle case where columns don't exist yet
        // (migration may not have run)
        try {
            $this->path = $storage->path ?? null;
            $this->encryptionEnabled = (bool) ($storage->encryption_enabled ?? false);
            $this->hasExistingPassword = $this->encryptionEnabled
                && filled($storage->getRawOriginal('encryption_password'));
            $this->hasExistingSalt = $this->encryptionEnabled
                && filled($storage->getRawOriginal('encryption_salt'));
            $this->filenameEncryption = $storage->filename_encryption ?? 'off';
            $this->directoryNameEncryption = (bool) ($storage->directory_name_encryption ?? false);
        } catch (\Throwable $e) {
            // Columns might not exist yet - use defaults
            $this->path = null;
            $this->encryptionEnabled = false;
            $this->hasExistingPassword = false;
            $this->hasExistingSalt = false;
            $this->filenameEncryption = 'off';
            $this->directoryNameEncryption = false;
        }
    }

    /**
     * Called by the checkbox's instantSave to toggle encryption and re-render.
     * The wire:model binding already flips $encryptionEnabled before this runs.
     */
    public function toggleEncryption(): void
    {
        $this->saveMessage = '';
        $this->saveStatus = '';
    }

    public function save(): void
    {
        try {
            if (! auth()->user()?->isInstanceAdmin()) {
                abort(403, 'Only instance administrators can modify storage encryption settings.');
            }

            $this->validate();

            $storage = S3Storage::findOrFail($this->storageId);

            if ($this->encryptionEnabled && empty($this->encryptionPassword) && ! $this->hasExistingPassword) {
                $this->dispatch('error', 'Encryption password is required when encryption is enabled.');

                return;
            }

            // If filename encryption is off, force directory name encryption off
            if ($this->filenameEncryption === 'off') {
                $this->directoryNameEncryption = false;
            }

            // Save S3 path prefix
            $storage->path = filled($this->path) ? $this->path : null;

            // Save encryption settings
            $storage->encryption_enabled = $this->encryptionEnabled;

            if ($this->encryptionEnabled) {
                if (filled($this->encryptionPassword)) {
                    $storage->encryption_password = $this->encryptionPassword;
                }
                if (filled($this->encryptionSalt)) {
                    $storage->encryption_salt = $this->encryptionSalt;
                } elseif (! $this->hasExistingSalt) {
                    $storage->encryption_salt = null;
                }
                $storage->filename_encryption = $this->filenameEncryption;
                $storage->directory_name_encryption = $this->directoryNameEncryption;
            } else {
                $storage->encryption_password = null;
                $storage->encryption_salt = null;
                $storage->filename_encryption = 'off';
                $storage->directory_name_encryption = false;
            }

            $storage->save();

            $this->hasExistingPassword = $this->encryptionEnabled
                && filled($storage->getRawOriginal('encryption_password'));
            $this->hasExistingSalt = $this->encryptionEnabled
                && filled($storage->getRawOriginal('encryption_salt'));
            $this->encryptionPassword = '';
            $this->encryptionSalt = '';

            $this->saveMessage = 'Settings saved.';
            $this->saveStatus = 'success';
            $this->dispatch('success', 'Storage settings saved successfully.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->saveMessage = 'Failed to save: '.$e->getMessage();
            $this->saveStatus = 'error';
            $this->dispatch('error', 'Failed to save encryption settings.', $e->getMessage());
        }
    }

    public function render()
    {
        return view('corelix-platform::livewire.storage-encryption-form');
    }
}
