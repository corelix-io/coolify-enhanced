<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Resource Backups | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <x-server.sidebar :server="$server" activeMenu="resource-backups" />
        <div class="w-full">
            @livewire('enhanced::resource-backup-manager', [
                'mode' => 'global',
            ])
        </div>
    </div>
</div>
