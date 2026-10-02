<div>
    <div class="flex flex-col gap-2">
        <div class="flex items-center justify-between">
            <div>
                <h2>DNS Providers</h2>
                <div class="subtitle">
                    Connect a DNS / ingress provider so deployed resources automatically get reachable URLs.
                    Credentials are stored encrypted and never leave your instance.
                </div>
            </div>
        </div>

        <div class="flex gap-2 mt-2">
            @if(!$showForm)
                <x-forms.button wire:click="showAddForm">+ Add DNS Provider</x-forms.button>
            @endif
        </div>
    </div>

    {{-- Add/Edit Form --}}
    @if($showForm)
        <div class="flex flex-col gap-2 p-4 mt-4 rounded border border-neutral-200 dark:border-coolgray-300 bg-white dark:bg-coolgray-100">
            <h3>{{ $editingProviderId ? 'Edit DNS Provider' : 'Add DNS Provider' }}</h3>

            <x-forms.input
                id="formName"
                label="Name"
                required
                placeholder="e.g., Cloudflare (production)"
                helper="A display name for this provider."
            />

            {{-- Provider Type Selector --}}
            <div>
                <label class="flex items-center gap-2 mb-1 text-sm font-medium">Provider Type</label>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    @foreach([
                        'cloudflare_tunnel' => 'Cloudflare Tunnel',
                    ] as $type => $label)
                        <button
                            type="button"
                            wire:click="$set('formType', '{{ $type }}')"
                            class="px-3 py-2 text-sm rounded border transition-colors
                                {{ $formType === $type
                                    ? 'border-blue-500 bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-500'
                                    : 'border-neutral-300 dark:border-coolgray-400 hover:border-neutral-400 dark:hover:border-coolgray-300' }}"
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Cloudflare credentials --}}
            <div class="grid grid-cols-1 gap-2 lg:grid-cols-2">
                <x-forms.input
                    type="password"
                    id="formApiToken"
                    label="API Token"
                    required
                    placeholder="{{ $editingProviderId ? 'Leave empty to keep existing' : '' }}"
                    helper="Scopes: Account · Cloudflare Tunnel · Edit, and Zone · DNS · Edit (+ Zone · Read)."
                />
                <x-forms.input
                    id="formAccountId"
                    label="Account ID"
                    required
                    placeholder="Cloudflare account ID"
                    helper="Found in the Cloudflare dashboard URL / account overview."
                />
            </div>

            {{-- Test Connection --}}
            <div class="flex items-center gap-3 mt-2">
                <x-forms.button wire:click="testConnection" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="testConnection">Test Connection</span>
                    <span wire:loading wire:target="testConnection">Testing...</span>
                </x-forms.button>

                @if($testResult === 'success')
                    <span class="text-sm text-green-600 dark:text-green-400">Connection successful</span>
                @elseif($testResult === 'failed')
                    <span class="text-sm text-red-600 dark:text-red-400">Failed: {{ $testError }}</span>
                @endif
            </div>

            <div class="flex gap-2 mt-2">
                <x-forms.button wire:click="saveProvider">
                    {{ $editingProviderId ? 'Save Changes' : 'Save' }}
                </x-forms.button>
                <x-forms.button wire:click="cancelForm" isError>Cancel</x-forms.button>
            </div>
        </div>
    @endif

    {{-- Providers List --}}
    @if($providers->count() > 0)
        <div class="flex flex-col gap-2 mt-4">
            @foreach($providers as $provider)
                <div class="flex flex-col gap-2 p-4 rounded border border-neutral-200 dark:border-coolgray-300 bg-white dark:bg-coolgray-100">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="font-bold">{{ $provider->name }}</span>
                                    <span class="px-2 py-0.5 text-xs rounded-full
                                        {{ $provider->is_active
                                            ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                                            : 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400' }}">
                                        {{ $provider->is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </div>
                                <div class="text-sm text-neutral-500 dark:text-neutral-400">
                                    {{ $provider->getTypeLabel() }}
                                    @if($provider->getAccountId())
                                        &middot; <code class="text-xs">{{ $provider->getAccountId() }}</code>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            @if($provider->last_test_status === 'success')
                                <span class="text-xs text-green-600 dark:text-green-400" title="Last tested: {{ $provider->last_tested_at?->diffForHumans() }}">Verified</span>
                            @elseif($provider->last_test_status === 'failed')
                                <span class="text-xs text-red-600 dark:text-red-400" title="{{ $provider->last_test_error }}">Test Failed</span>
                            @endif

                            <x-forms.button wire:click="testProvider({{ $provider->id }})" wire:loading.attr="disabled" class="text-xs">Test</x-forms.button>
                            <x-forms.button wire:click="editProvider({{ $provider->id }})" class="text-xs">Edit</x-forms.button>
                            <x-forms.button wire:click="toggleActive({{ $provider->id }})" class="text-xs">
                                {{ $provider->is_active ? 'Disable' : 'Enable' }}
                            </x-forms.button>
                            <x-forms.button
                                wire:click="deleteProvider({{ $provider->id }})"
                                wire:confirm="Delete this provider? Managed domains and hostnames using it will be removed."
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
            No DNS providers configured. Add a Cloudflare Tunnel provider to enable automatic URLs for your deployments.
        </div>
    @endif

    {{-- Managed Domains (wildcard happy path) --}}
    <livewire:enhanced::dns-domain-manager />

    {{-- DNS Health (PRO: drift sync) — upsell stays OUTSIDE markers so it survives free builds --}}
    @feature('DNS_DRIFT_SYNC')
    @else
        <div class="mt-8">
            @include('corelix-platform::components.upsell-card', ['feature' => 'DNS_DRIFT_SYNC'])
        </div>
    @endfeature
</div>
