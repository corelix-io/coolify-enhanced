<div>
    <h2 class="pb-4">Networks</h2>
    <div class="subtitle">Manage Docker networks on this server.</div>

    @if ($isSwarmServer)
        <div class="flex items-center gap-2 pb-2">
            <span class="px-2 py-0.5 text-xs rounded bg-cyan-100 text-cyan-700 dark:bg-cyan-500/20 dark:text-cyan-400">Swarm {{ $isSwarmManager ? 'Manager' : 'Worker' }}</span>
            <span class="text-xs text-neutral-600 dark:text-neutral-400">Networks use overlay driver for multi-host communication.</span>
        </div>
    @endif

    {{-- Tab navigation --}}
    <div class="flex gap-2 pb-4">
        <x-forms.button wire:click="switchTab('managed')"
            class="{{ $activeTab === 'managed' ? '' : 'bg-neutral-200 dark:bg-coolgray-200' }}">
            Managed Networks
        </x-forms.button>
        <x-forms.button wire:click="switchTab('docker')"
            class="{{ $activeTab === 'docker' ? '' : 'bg-neutral-200 dark:bg-coolgray-200' }}">
            Docker Networks
        </x-forms.button>
        <x-forms.button wire:click="syncNetworks">
            Sync from Docker
        </x-forms.button>
        <x-forms.button wire:click="reconcileExistingResources">
            Reconcile Existing Resources
        </x-forms.button>
    </div>

    {{-- Proxy Isolation Panel --}}
    @if ($proxyIsolationEnabled)
        <div class="p-4 mb-4 bg-white dark:bg-coolgray-100 rounded border border-purple-200 dark:border-purple-500/30">
            <div class="flex items-center justify-between pb-2">
                <div>
                    <h3 class="font-bold text-purple-600 dark:text-purple-400">Proxy Network Isolation</h3>
                    <div class="text-xs text-neutral-600 dark:text-neutral-400 mt-1">
                        Dedicated proxy network ensures the reverse proxy only accesses resources with FQDNs.
                    </div>
                </div>
                @if ($proxyNetwork)
                    <span class="px-2 py-0.5 text-xs rounded bg-success/20 text-success">Active</span>
                @else
                    <span class="px-2 py-0.5 text-xs rounded bg-warning/20 text-warning">Not Migrated</span>
                @endif
            </div>

            @if ($proxyNetwork)
                <div class="text-sm text-neutral-600 dark:text-neutral-300 mt-2">
                    <span class="font-mono text-xs">{{ $proxyNetwork->docker_network_name }}</span>
                    <span class="text-neutral-500 ml-2">{{ $proxyNetwork->connectedContainerCount() }} connected</span>
                </div>
                <div class="flex gap-2 mt-3">
                    <x-forms.button wire:click="migrateProxyIsolation">
                        Re-run Migration
                    </x-forms.button>
                    <x-forms.button isWarning
                        wire:click="cleanupProxyNetworks"
                        wire:confirm="This will disconnect the proxy from all non-proxy networks. Only proceed if all resources have been redeployed. Continue?">
                        Cleanup Old Networks
                    </x-forms.button>
                </div>
            @else
                <div class="mt-3">
                    <x-forms.button wire:click="migrateProxyIsolation">
                        Run Proxy Migration
                    </x-forms.button>
                    <div class="text-xs text-neutral-500 mt-1">
                        Creates the proxy network, connects the proxy container, and connects all FQDN resources.
                    </div>
                </div>
            @endif
        </div>
    @endif

    @if ($activeTab === 'managed')
        {{-- Create shared network form --}}
        <div class="pb-4">
            <h3 class="pb-2">Create Shared Network</h3>
            <form wire:submit="createSharedNetwork" class="flex gap-2 items-end">
                <x-forms.input id="newNetworkName" label="Network Name" placeholder="e.g., shared-backend" required />
                <x-forms.checkbox id="newNetworkInternal" label="Internal Only" />
                @if ($isSwarmServer)
                    <x-forms.checkbox id="newNetworkEncrypted" label="Encrypted Overlay" />
                @endif
                <x-forms.button type="submit">Create</x-forms.button>
            </form>
        </div>

        {{-- List of managed networks --}}
        <div class="flex flex-col gap-2">
            @forelse ($managedNetworks as $network)
                <div class="p-4 bg-white dark:bg-coolgray-100 rounded border border-neutral-200 dark:border-transparent">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <div>
                                <span class="font-bold">{{ $network->name }}</span>
                                <span class="text-xs text-neutral-600 dark:text-neutral-400 ml-2">{{ $network->docker_network_name }}</span>
                            </div>
                            {{-- Scope badge --}}
                            <span class="px-2 py-0.5 text-xs rounded
                                @if($network->scope === 'environment') bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-400
                                @elseif($network->scope === 'shared') bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-400
                                @elseif($network->scope === 'proxy') bg-purple-100 text-purple-700 dark:bg-purple-500/20 dark:text-purple-400
                                @elseif($network->scope === 'system') bg-neutral-100 text-neutral-700 dark:bg-neutral-500/20 dark:text-neutral-400
                                @endif">
                                {{ ucfirst($network->scope) }}
                            </span>
                            {{-- Driver badge --}}
                            <span class="px-2 py-0.5 text-xs rounded
                                @if($network->driver === 'overlay') bg-cyan-100 text-cyan-700 dark:bg-cyan-500/20 dark:text-cyan-400
                                @else bg-neutral-100 text-neutral-700 dark:bg-neutral-500/20 dark:text-neutral-400
                                @endif">
                                {{ $network->driver }}
                            </span>
                            @if($network->is_encrypted_overlay)
                                <span class="px-2 py-0.5 text-xs rounded bg-yellow-100 text-yellow-700 dark:bg-yellow-500/20 dark:text-yellow-400">Encrypted</span>
                            @endif
                            {{-- Status badge --}}
                            <span class="px-2 py-0.5 text-xs rounded
                                @if($network->status === 'active') bg-success/20 text-success
                                @elseif($network->status === 'pending') bg-warning/20 text-warning
                                @elseif($network->status === 'error') bg-error/20 text-error
                                @else bg-neutral-100 text-neutral-700 dark:bg-neutral-500/20 dark:text-neutral-400
                                @endif">
                                {{ ucfirst($network->status) }}
                            </span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-xs text-neutral-600 dark:text-neutral-400">
                                {{ $network->connectedContainerCount() }} connected
                            </span>
                            @if (!in_array($network->scope, ['environment', 'system']))
                                <x-forms.button isError wire:click="deleteNetwork({{ $network->id }})"
                                    wire:confirm="Are you sure you want to delete this network?">
                                    Delete
                                </x-forms.button>
                            @endif
                        </div>
                    </div>
                    @if ($network->error_message)
                        <div class="text-xs text-error mt-2">{{ $network->error_message }}</div>
                    @endif
                    @if ($network->last_synced_at)
                        <div class="text-xs text-neutral-500 mt-1">Last synced: {{ $network->last_synced_at->diffForHumans() }}</div>
                    @endif
                </div>
            @empty
                <div class="text-neutral-600 dark:text-neutral-400">No managed networks found. Enable network management in Settings to get started.</div>
            @endforelse
        </div>
    @else
        {{-- Docker Networks tab --}}
        <div class="flex flex-col gap-2">
            @forelse ($dockerNetworks as $dn)
                <div class="p-4 bg-white dark:bg-coolgray-100 rounded border border-neutral-200 dark:border-transparent">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="font-bold">{{ $dn['Name'] ?? 'Unknown' }}</span>
                            <span class="text-xs text-neutral-600 dark:text-neutral-400 ml-2">{{ $dn['Driver'] ?? '' }} / {{ $dn['Scope'] ?? '' }}</span>
                        </div>
                        <span class="text-xs text-neutral-500 font-mono">{{ Str::limit($dn['ID'] ?? '', 12) }}</span>
                    </div>
                </div>
            @empty
                <div class="text-neutral-600 dark:text-neutral-400">Click "Sync from Docker" to load Docker networks.</div>
            @endforelse
        </div>
    @endif
</div>
