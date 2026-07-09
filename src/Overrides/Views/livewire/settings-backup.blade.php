<div>
    <x-slot:title>
        Settings | Coolify
    </x-slot>
    <x-settings.navbar />
    <div class="flex flex-col">
        <div class="flex items-center gap-2 pb-2">
            <h2>Backup</h2>
            @if (isset($database) && $server->isFunctional())
                <x-forms.button type="submit" wire:click="submit">
                    Save
                </x-forms.button>
            @endif
        </div>
        <div class="pb-4">Backup configuration for Coolify instance.</div>
        <div>
            @if ($server->isFunctional())
                @if (isset($database) && isset($backup))
                    <div class="flex flex-col gap-3 pb-4">
                        <div class="flex gap-2">
                            <x-forms.input label="UUID" readonly id="uuid" />
                            <x-forms.input label="Name" readonly id="name" />
                            <x-forms.input label="Description" id="description" />
                        </div>
                        <div class="flex gap-2">
                            <x-forms.input label="User" readonly id="postgres_user" />
                            <x-forms.input type="password" label="Password" readonly id="postgres_password" />
                        </div>
                    </div>
                    <livewire:project.database.backup-edit :backup="$backup" :s3s="$s3s" :status="data_get($database, 'status')" />

                    {{-- Corelix Enhanced: Instance File Backup --}}
                    @if (config('corelix-platform.enabled', false))
                        <div class="mt-8 pt-8 border-t dark:border-coolgray-200 border-gray-200">
                            <div class="flex items-center gap-2 pb-2">
                                <h2>Instance File Backup</h2>
                            </div>
                            <div class="pb-4 text-sm text-gray-600 dark:text-gray-400">
                                Schedule backups of the <code>/data/coolify</code> directory (configuration files, docker compose files, SSH keys, etc).
                                The <strong>database backup above</strong> covers the Coolify PostgreSQL database.
                                This section covers <strong>everything else</strong> — the files on disk that make up your Coolify installation.
                            </div>
                            @livewire('enhanced::resource-backup-manager', ['mode' => 'global'])
                        </div>
                    @endif

                    <div class="py-4">
                        <livewire:project.database.backup-executions :backup="$backup" />
                    </div>
                @else
                    To configure automatic backup for your Coolify instance, you first need to add a database resource
                    into Coolify.
                    <x-forms.button class="mt-2" wire:click="addCoolifyDatabase">Configure Backup</x-forms.button>

                    {{-- Corelix Enhanced: Instance File Backup (no DB configured yet) --}}
                    @if (config('corelix-platform.enabled', false))
                        <div class="mt-8 pt-8 border-t dark:border-coolgray-200 border-gray-200">
                            <div class="flex items-center gap-2 pb-2">
                                <h2>Instance File Backup</h2>
                            </div>
                            <div class="pb-4 text-sm text-gray-600 dark:text-gray-400">
                                Schedule backups of the <code>/data/coolify</code> directory (configuration files, docker compose files, SSH keys, etc).
                                You can configure file backups independently of the database backup above.
                            </div>
                            @livewire('enhanced::resource-backup-manager', ['mode' => 'global'])
                        </div>
                    @endif
                @endif
            @else
                <div class="p-6 rounded-lg border border-red-200 dark:border-red-500/20 bg-red-100 dark:bg-red-500/10">
                    <div class="text-red-700 dark:text-red-500 font-medium mb-4">
                        Instance Backup is currently disabled because the localhost server is not properly validated.
                        Please validate your server to enable Instance Backup.
                    </div>
                    <a href="{{ route('server.show', [$server->uuid]) }}"
                        class="text-black hover:text-gray-700 dark:text-white dark:hover:text-gray-200 underline" {{ wireNavigate() }}>
                        Go to Server Settings to Validate
                    </a>
                </div>
            @endif
        </div>
    </div>
</div>
