<div>
    <div class="flex items-center gap-2">
        <h2>Domains</h2>
        @if ($canManage)
            <x-forms.button wire:click="resync" wire:loading.attr="disabled">Re-sync</x-forms.button>
        @endif
    </div>
    <div class="mt-1 mb-4 text-sm opacity-90">
        Hostnames exposed by this resource and the managed DNS domain that owns them.
        Hostnames outside your managed domains are listed as unmanaged and left untouched.
    </div>

    @if ($entries->isEmpty())
        <div class="text-sm opacity-70">This resource does not expose any hostnames yet.</div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left border-b dark:border-coolgray-200">
                        <th class="py-2 pr-4">Hostname</th>
                        <th class="py-2 pr-4">Managed Domain</th>
                        <th class="py-2 pr-4">Provider</th>
                        <th class="py-2 pr-4">Status</th>
                        <th class="py-2 pr-4">Last Synced</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($entries as $entry)
                        @php
                            $row = $entry['row'];
                            $state = $row?->sync_state;
                            $stateClass = match ($state) {
                                'synced' => 'text-success',
                                'pending' => 'text-warning',
                                'drifted' => 'text-warning',
                                'error' => 'text-error',
                                default => 'opacity-60',
                            };
                        @endphp
                        <tr class="border-b dark:border-coolgray-200">
                            <td class="py-2 pr-4 font-mono">
                                {{ $entry['hostname'] }}
                                @if ($entry['stale'])
                                    <span class="ml-1 text-xs opacity-60">(no longer exposed)</span>
                                @endif
                            </td>
                            <td class="py-2 pr-4">
                                @if ($row?->domain)
                                    {{ $row->domain->base_domain }}
                                    @if ($row->binding_source === 'override')
                                        <span class="ml-1 text-xs opacity-60">(pinned)</span>
                                    @endif
                                @else
                                    <span class="opacity-60">unmanaged</span>
                                @endif
                            </td>
                            <td class="py-2 pr-4">
                                {{ $row?->provider?->name ?? '—' }}
                            </td>
                            <td class="py-2 pr-4">
                                @if ($row)
                                    <span class="{{ $stateClass }}">{{ ucfirst($state) }}</span>
                                    @if ($state === 'error' && filled($row->last_error))
                                        <div class="max-w-md text-xs break-words text-error">{{ $row->last_error }}</div>
                                    @endif
                                @else
                                    <span class="opacity-60">Unmanaged</span>
                                @endif
                            </td>
                            <td class="py-2 pr-4">
                                {{ $row?->last_synced_at?->diffForHumans() ?? '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @feature('DNS_MULTI_DOMAIN')
    @else
        <div class="mt-6">
            @include('corelix-platform::components.upsell-card', ['feature' => 'DNS_MULTI_DOMAIN'])
        </div>
    @endfeature
</div>
