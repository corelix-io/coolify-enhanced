<div>
    <x-slot:title>
        Instance Backup | Coolify
    </x-slot>

    <x-settings.layout>
    <div class="application-settings-form mx-auto flex w-full max-w-none min-w-0 flex-col gap-6">
        @if ($server->isFunctional())
            @if (isset($database) && isset($backup))
                <form wire:submit="submit">
                    <x-unsaved-bar action="submit" />

                    <x-application.settings-section title="Instance database">
                        <div class="grid gap-4 lg:grid-cols-2">
                            <x-forms.input label="Name" readonly id="name" />
                            <x-forms.input label="Description" id="description" />
                            <div class="lg:col-span-2">
                                <x-forms.input label="UUID" readonly id="uuid" />
                            </div>
                            <x-forms.input label="User" readonly id="postgres_user" />
                            <x-forms.input type="password" label="Password" readonly id="postgres_password" />
                        </div>
                    </x-application.settings-section>
                </form>

                <livewire:project.database.backup-edit :backup="$backup" :available-s3-storages="$s3s"
                    :status="data_get($database, 'status')" />

                {{-- Corelix Enhanced: Instance File Backup --}}
                @if (config('corelix-platform.enabled', false))
                <div class="mt-8 pt-8 border-t dark:border-coolgray-200 border-gray-200">
                    <div class="flex items-center gap-2 pb-2">
                        <h2>Instance File Backup</h2>
                    </div>
                    <div class="pb-4 text-sm text-gray-600 dark:text-gray-400">
                        Schedule backups of the <code>/data/coolify</code> directory (configuration files, docker compose files, SSH keys, etc).
                        The <strong>database backup above</strong> covers Coolify's PostgreSQL database. This
                        section covers <strong>everything else</strong> — files on disk that make up your Coolify installation.
                    </div>
                    @livewire('enhanced::resource-backup-manager', ['mode' => 'global'])
                </div>
                @endif
                {{-- End Corelix Enhanced --}}

                <livewire:project.database.backup-executions :backup="$backup" />
            @else
                <x-application.settings-section title="Instance backup">
                    <x-empty title="Backup is not configured"
                        description="Coolify needs an internal database resource to create automatic backups."
                        icon-name="database" size="sm">
                        <x-slot:actions>
                            <x-forms.button wire:click="addCoolifyDatabase" isHighlighted>
                                Configure backup
                            </x-forms.button>
                        </x-slot:actions>
                    </x-empty>
                </x-application.settings-section>

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
                {{-- End Corelix Enhanced --}}
            @endif
        @else
            <x-application.settings-section title="Instance backup">
                <x-callout type="danger" title="Localhost is not ready">
                    Validate the localhost connection before configuring instance backups.
                    <a href="{{ route('server.show', [$server->uuid]) }}" class="font-medium underline"
                        {{ wireNavigate() }}>Open server settings</a>
                </x-callout>
            </x-application.settings-section>
        @endif
    </div>
    </x-settings.layout>
</div>
