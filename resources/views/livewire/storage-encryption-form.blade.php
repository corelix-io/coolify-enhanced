<div>
    {{-- S3 Path Prefix --}}
    <div class="flex flex-col gap-2 pt-6">
        <div>
            <h2>Path Prefix</h2>
            <div class="subtitle">
                Optional prefix added before the standard backup path. Useful for separating multiple Coolify instances in a single bucket.
            </div>
        </div>

        <x-forms.input
            id="path"
            label="Path Prefix"
            placeholder="e.g., production or instance-1"
            helper="Backups will be stored under this prefix in the bucket. Leave empty for no prefix."
        />
    </div>

    {{-- Backup Encryption --}}
    <div class="flex flex-col gap-2 pt-6">
        <div>
            <h2>Backup Encryption</h2>
            <div class="subtitle">
                Encrypt backups at rest using rclone's crypt backend (NaCl SecretBox).
                When enabled, all backups to this S3 destination are encrypted before upload.
            </div>
        </div>

        <x-forms.checkbox
            id="encryptionEnabled"
            label="Enable backup encryption"
            instantSave="toggleEncryption"
        />

        @if($encryptionEnabled)
            <div class="flex flex-col gap-2 p-4 rounded border border-neutral-200 dark:border-coolgray-300 bg-white dark:bg-coolgray-100">
                <div class="flex gap-2">
                    <x-forms.input
                        type="password"
                        id="encryptionPassword"
                        label="Encryption Password"
                        :required="$encryptionEnabled && !$hasExistingPassword"
                        placeholder="{{ $hasExistingPassword ? 'Leave blank to keep current password' : 'Main encryption password' }}"
                        helper="Used to encrypt/decrypt backup content. Store securely — without it, encrypted backups cannot be restored.{{ $hasExistingPassword ? ' Leave blank to keep the current password.' : '' }}"
                    />
                    <x-forms.input
                        type="password"
                        id="encryptionSalt"
                        label="Salt Password (password2)"
                        placeholder="{{ $hasExistingSalt ? 'Leave blank to keep current salt' : 'Optional salt for additional security' }}"
                        helper="Optional. Adds extra security to filename encryption.{{ $hasExistingSalt ? ' Leave blank to keep the current salt.' : '' }}"
                    />
                </div>

                <x-forms.select id="filenameEncryption" label="Filename Encryption"
                    helper="'Off' is recommended for best compatibility. Content is always encrypted regardless.">
                    <option value="off">Off (recommended) — Filenames remain readable on S3</option>
                    <option value="standard">Standard — Filenames are encrypted on S3</option>
                    <option value="obfuscate">Obfuscate — Filenames are lightly obscured</option>
                </x-forms.select>

                <x-forms.checkbox
                    id="directoryNameEncryption"
                    label="Encrypt directory names"
                    helper="Only applies when filename encryption is set to Standard or Obfuscate."
                />

                <div class="flex items-start gap-2 p-3 rounded-md text-warning">
                    <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.168 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 6a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 6zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/>
                    </svg>
                    <div class="text-sm">
                        <strong>Important:</strong> Store your encryption password(s) securely. If lost,
                        encrypted backups <strong>cannot be recovered</strong>. Existing unencrypted backups
                        are not affected.
                    </div>
                </div>
            </div>
        @endif

        <div class="flex items-center gap-3 pt-2">
            <x-forms.button wire:click="save">Save Enhanced Settings</x-forms.button>

            @if($saveMessage)
                <span class="text-sm {{ $saveStatus === 'success' ? 'text-success' : 'text-error' }}">
                    {{ $saveMessage }}
                </span>
            @endif
        </div>
    </div>
</div>
