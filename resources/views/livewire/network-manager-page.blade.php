<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Networks | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <x-server.sidebar :server="$server" activeMenu="networks" />
        <div class="w-full">
            @livewire('enhanced::network-manager', [
                'server' => $server,
            ])
        </div>
    </div>
</div>
