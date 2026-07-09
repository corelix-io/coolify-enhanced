<div class="mt-8">
    <div class="flex flex-col gap-2">
        <div class="flex items-center justify-between">
            <div>
                <h2>Managed Domains</h2>
                <div class="subtitle">
                    A managed domain gives every deployed resource an instant URL under it — one wildcard DNS
                    record and one tunnel cover all current and future apps, with zero per-app DNS changes.
                </div>
            </div>
        </div>

        <div class="flex gap-2 mt-2">
            @if(!$showForm)
                <x-forms.button wire:click="showAddForm" :disabled="$availableProviders->isEmpty()">+ Add Domain</x-forms.button>
            @endif
            @if($availableProviders->isEmpty())
                <span class="text-sm text-neutral-500 dark:text-neutral-400 self-center">Add an active DNS provider first.</span>
            @endif
        </div>
    </div>

    {{-- Add Form --}}
    @if($showForm)
        <div class="flex flex-col gap-2 p-4 mt-4 rounded border border-neutral-200 dark:border-coolgray-300 bg-white dark:bg-coolgray-100">
            <h3>Add Managed Domain</h3>

            <x-forms.input
                id="formBaseDomain"
                label="Base Domain"
                required
                placeholder="apps.example.com"
                helper="Resources will live under *.this-domain (wildcard routing). The zone must exist in your provider account."
            />

            <div class="grid grid-cols-1 gap-2 lg:grid-cols-2">
                <x-forms.select id="formProviderId" label="DNS Provider" required>
                    <option value="">Select a provider…</option>
                    @foreach($availableProviders as $provider)
                        <option value="{{ $provider->id }}">{{ $provider->name }} ({{ $provider->getTypeLabel() }})</option>
                    @endforeach
                </x-forms.select>

                <x-forms.select id="formServerId" label="Server" required
                    helper="The managed cloudflared daemon runs on this server, next to its proxy.">
                    <option value="">Select a server…</option>
                    @foreach($availableServers as $server)
                        <option value="{{ $server->id }}">{{ $server->name }}</option>
                    @endforeach
                </x-forms.select>
            </div>

            <x-forms.select id="formRoutingMode" label="Routing Mode"
                helper="Wildcard: one CNAME + one ingress rule cover every app — zero per-app DNS changes. Per-hostname: an explicit CNAME + ingress rule per host. Hybrid: wildcard DNS with explicit per-host ingress rules.">
                <option value="wildcard">Wildcard (recommended)</option>
                @feature('DNS_PER_HOSTNAME')
                @endfeature
            </x-forms.select>

            <x-forms.checkbox
                id="formSetServerDefault"
                label="Use as the server's default wildcard domain"
                helper="Auto-generated app URLs on this server will land under this domain instead of *.sslip.io. Existing user-set wildcard values are never overwritten."
            />

            <div class="text-xs text-neutral-500 dark:text-neutral-400">
                Wildcard routing keeps DNS untouched per app: one CNAME (*.domain) plus one tunnel ingress rule
                serve everything.
                @if(! $canPerHostname)
                    Per-hostname and hybrid routing are available in the Pro tier.
                @endif
                @if(! $canMultiDomain && $domains->count() >= 1)
                    Managing more than one domain requires the Pro tier (DNS Multi-Domain).
                @endif
            </div>

            <div class="flex gap-2 mt-2">
                <x-forms.button wire:click="saveDomain">Add &amp; Provision</x-forms.button>
                <x-forms.button wire:click="cancelForm" isError>Cancel</x-forms.button>
            </div>
        </div>
    @endif

    {{-- Domains List --}}
    @if($domains->count() > 0)
        <div class="flex flex-col gap-2 mt-4">
            @foreach($domains as $domain)
                <div class="flex flex-col gap-2 p-4 rounded border border-neutral-200 dark:border-coolgray-300 bg-white dark:bg-coolgray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="font-bold">*.{{ $domain->base_domain }}</span>
                                <span class="px-2 py-0.5 text-xs rounded-full
                                    {{ $domain->is_active
                                        ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                                        : 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400' }}">
                                    {{ $domain->is_active ? 'Active' : 'Inactive' }}
                                </span>
                                @if($domain->is_default)
                                    <span class="px-2 py-0.5 text-xs rounded-full bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">Default</span>
                                @endif
                            </div>
                            <div class="text-sm text-neutral-500 dark:text-neutral-400">
                                {{ $domain->provider?->name ?? 'No provider' }}
                                &middot; {{ ucfirst(str_replace('_', ' ', $domain->routing_mode)) }} routing
                                @if($domain->servers->isNotEmpty())
                                    &middot; {{ $domain->servers->pluck('name')->join(', ') }}
                                @endif
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            @php $tunnel = $domain->tunnel; @endphp
                            @if($tunnel)
                                <span class="text-xs px-2 py-0.5 rounded-full
                                    {{ $tunnel->status === 'active'
                                        ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                                        : ($tunnel->status === 'error'
                                            ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
                                            : 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400') }}"
                                    title="Tunnel status">
                                    Tunnel: {{ $tunnel->status }}
                                </span>
                                <span class="text-xs px-2 py-0.5 rounded-full
                                    {{ $tunnel->daemon_status === 'running'
                                        ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                                        : ($tunnel->daemon_status === 'error'
                                            ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
                                            : 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400') }}"
                                    title="{{ $tunnel->daemon_error ?? 'cloudflared daemon status' }}">
                                    Daemon: {{ $tunnel->daemon_status }}
                                </span>
                            @else
                                <span class="text-xs px-2 py-0.5 rounded-full bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400">
                                    Provisioning…
                                </span>
                            @endif

                            <x-forms.button wire:click="reprovision({{ $domain->id }})" class="text-xs">Re-provision</x-forms.button>
                            <x-forms.button wire:click="toggleActive({{ $domain->id }})" class="text-xs">
                                {{ $domain->is_active ? 'Disable' : 'Enable' }}
                            </x-forms.button>
                            <x-forms.button
                                wire:click="deleteDomain({{ $domain->id }})"
                                wire:confirm="Delete this managed domain? Hostname tracking will be removed; DNS records and the tunnel are left untouched at the provider."
                                isError
                                class="text-xs"
                            >Delete</x-forms.button>
                        </div>
                    </div>

                </div>
            @endforeach
        </div>
    @elseif(!$showForm)
        <div class="mt-4 text-sm text-neutral-500 dark:text-neutral-400">
            No managed domains yet. Add one to give your deployments automatic URLs.
        </div>
    @endif

    @feature('DNS_ENV_BINDINGS')
    @else
        <div class="mt-6">
            @include('corelix-platform::components.upsell-card', ['feature' => 'DNS_ENV_BINDINGS'])
        </div>
    @endfeature
</div>
