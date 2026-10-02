<div>
    <h2 class="pb-4">Networks</h2>
    <div class="subtitle pb-4">Manage network connections for {{ $resourceName }}.</div>

    {{-- Current network memberships --}}
    <div class="flex flex-col gap-2 pb-4">
        <h3>Connected Networks</h3>
        @forelse ($currentNetworks as $rn)
            <div class="p-4 bg-white dark:bg-coolgray-100 rounded border border-neutral-200 dark:border-transparent">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <span class="font-bold">{{ $rn->managedNetwork->name }}</span>
                        <span class="text-xs text-neutral-600 dark:text-neutral-400">{{ $rn->managedNetwork->docker_network_name }}</span>
                        {{-- Scope badge --}}
                        <span class="px-2 py-0.5 text-xs rounded
                            @if($rn->managedNetwork->scope === 'environment') bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-400
                            @elseif($rn->managedNetwork->scope === 'shared') bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-400
                            @elseif($rn->managedNetwork->scope === 'proxy') bg-purple-100 text-purple-700 dark:bg-purple-500/20 dark:text-purple-400
                            @endif">
                            {{ ucfirst($rn->managedNetwork->scope) }}
                        </span>
                        @if ($rn->is_auto_attached)
                            <span class="px-2 py-0.5 text-xs rounded bg-neutral-100 text-neutral-700 dark:bg-neutral-500/20 dark:text-neutral-400">Auto</span>
                        @endif
                        {{-- Connection status --}}
                        @if ($rn->is_connected)
                            <span class="w-2 h-2 rounded-full bg-success inline-block" title="Connected"></span>
                        @else
                            <span class="w-2 h-2 rounded-full bg-error inline-block" title="Disconnected"></span>
                        @endif
                    </div>
                    <div class="flex items-center gap-2">
                        @if ($rn->ipv4_address)
                            <span class="text-xs text-neutral-600 dark:text-neutral-400 font-mono">{{ $rn->ipv4_address }}</span>
                        @endif
                        @if (!$rn->is_connected)
                            <x-forms.button wire:click="reconnect({{ $rn->id }})">Reconnect</x-forms.button>
                        @endif
                        @if (!($rn->is_auto_attached && $rn->managedNetwork->scope === 'environment'))
                            <x-forms.button isError wire:click="removeFromNetwork({{ $rn->id }})"
                                wire:confirm="Disconnect from this network?">
                                Disconnect
                            </x-forms.button>
                        @endif
                    </div>
                </div>
                @if ($rn->aliases && count($rn->aliases) > 0)
                    <div class="text-xs text-neutral-500 mt-1">Aliases: {{ implode(', ', $rn->aliases) }}</div>
                @endif
                @if ($rn->connected_at)
                    <div class="text-xs text-neutral-500 mt-1">Connected: {{ $rn->connected_at->diffForHumans() }}</div>
                @endif
            </div>
        @empty
            <div class="text-neutral-600 dark:text-neutral-400">Not connected to any managed networks.</div>
        @endforelse
        {{-- Corelix Enhanced: status semantics + Swarm caveat --}}
        @if ($currentNetworks->isNotEmpty())
            <div class="text-xs text-neutral-500 mt-1">
                Status reflects live-verified Docker membership, refreshed on deploy and on a periodic reconcile.
                On Docker Swarm, <span class="font-mono">docker network inspect</span> only lists tasks on the queried node, so a service running elsewhere may still be connected.
            </div>
        @endif
    </div>

    {{-- Add to shared network --}}
    @if ($availableNetworks->isNotEmpty())
        <div class="pt-4 border-t border-neutral-200 dark:border-coolgray-200">
            <h3 class="pb-2">Add to Shared Network</h3>
            <form wire:submit="addToNetwork" class="flex gap-2 items-end">
                <x-forms.select id="selectedNetworkId" label="Select Network">
                    <option value="">Select a network...</option>
                    @foreach ($availableNetworks as $net)
                        <option value="{{ $net->id }}">{{ $net->name }} ({{ $net->docker_network_name }})</option>
                    @endforeach
                </x-forms.select>
                <x-forms.button type="submit">Connect</x-forms.button>
            </form>
        </div>
    @endif
</div>
