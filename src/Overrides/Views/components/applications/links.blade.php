{{-- Corelix Enhanced: full overlay of resources/views/components/applications/links.blade.php --}}
{{-- Adds per-hostname DNS status dots (managed domains) to the Links dropdown. --}}
{{-- Keep in sync with upstream — only the marked blocks differ. --}}
@php
    /* Corelix Enhanced: DNS status badges (managed hostnames) */
    $ceDnsStatuses = [];
    if (config('corelix-platform.enabled', false)
        && config('corelix-platform.dns_provider_management.enabled', false)
        && class_exists(\CorelixIo\Platform\Services\DnsResolutionService::class)) {
        $ceDnsStatuses = \CorelixIo\Platform\Services\DnsResolutionService::hostnameStatusMap($application);
    }
    $ceDnsBadge = function ($url) use ($ceDnsStatuses) {
        if (empty($ceDnsStatuses) || blank($url)) {
            return null;
        }
        $host = \CorelixIo\Platform\Services\DnsResolutionService::hostnameFromUrl((string) $url);

        return $host ? ($ceDnsStatuses[$host] ?? null) : null;
    };
    $ceDnsBadgeClass = [
        'synced' => 'bg-success',
        'pending' => 'bg-warning',
        'drifted' => 'bg-warning',
        'error' => 'bg-error',
    ];
@endphp
<x-dropdown>
    <x-slot:title>
        Links
    </x-slot>
    @if (
        (data_get($application, 'fqdn') ||
            collect(json_decode($this->application->docker_compose_domains))->contains(fn($fqdn) => !empty(data_get($fqdn, 'domain'))) ||
            data_get($application, 'previews', collect([]))->count() > 0 ||
            data_get($application, 'ports_mappings_array')) &&
            data_get($application, 'settings.is_raw_compose_deployment_enabled') !== true)
        <div class="flex flex-col gap-1">
            @if (data_get($application, 'gitBrancLocation'))
                <a target="_blank" class="dropdown-item" href="{{ $application->gitBranchLocation }}">
                    <x-git-icon git="{{ $application->source?->getMorphClass() }}" />
                    Git Repository
                </a>
            @endif
            @if (data_get($application, 'build_pack') === 'dockercompose')
                @foreach (collect(json_decode($this->application->docker_compose_domains)) as $fqdn)
                    @if (data_get($fqdn, 'domain'))
                        @foreach (explode(',', data_get($fqdn, 'domain')) as $domain)
                            <a class="dropdown-item" target="_blank" href="{{ getFqdnWithoutPort($domain) }}">
                                <x-external-link class="size-4" />{{ getFqdnWithoutPort($domain) }}
                                {{-- Corelix Enhanced: DNS status dot (unmanaged/unknown states render nothing) --}}
                                @if (($ceState = $ceDnsBadge($domain)) && isset($ceDnsBadgeClass[$ceState]))
                                    <span title="Corelix DNS: {{ $ceState }}"
                                        class="inline-block w-2 h-2 ml-1 rounded-full {{ $ceDnsBadgeClass[$ceState] }}"></span>
                                @endif
                            </a>
                        @endforeach
                    @endif
                @endforeach
            @endif
            @if (data_get($application, 'fqdn'))
                @foreach (str(data_get($application, 'fqdn'))->explode(',') as $fqdn)
                    <a class="dropdown-item" target="_blank" href="{{ getFqdnWithoutPort($fqdn) }}">
                        <x-external-link class="size-4" />{{ getFqdnWithoutPort($fqdn) }}
                        {{-- Corelix Enhanced: DNS status dot (unmanaged/unknown states render nothing) --}}
                        @if (($ceState = $ceDnsBadge($fqdn)) && isset($ceDnsBadgeClass[$ceState]))
                            <span title="Corelix DNS: {{ $ceState }}"
                                class="inline-block w-2 h-2 ml-1 rounded-full {{ $ceDnsBadgeClass[$ceState] }}"></span>
                        @endif
                    </a>
                @endforeach
            @endif
            @if (data_get($application, 'previews', collect())->count() > 0)
                @if (data_get($application, 'build_pack') === 'dockercompose')
                    @foreach ($application->previews as $preview)
                        @foreach (collect(json_decode($preview->docker_compose_domains)) as $fqdn)
                            @if (data_get($fqdn, 'domain'))
                                @foreach (explode(',', data_get($fqdn, 'domain')) as $domain)
                                    <a class="dropdown-item" target="_blank" href="{{ getFqdnWithoutPort($domain) }}">
                                        <x-external-link class="size-4" />PR{{ data_get($preview, 'pull_request_id') }}
                                        |
                                        {{ getFqdnWithoutPort($domain) }}
                                    </a>
                                @endforeach
                            @endif
                        @endforeach
                    @endforeach
                @else
                    @foreach (data_get($application, 'previews') as $preview)
                        @if (data_get($preview, 'fqdn'))
                            <a class="dropdown-item" target="_blank"
                                href="{{ getFqdnWithoutPort(data_get($preview, 'fqdn')) }}">
                                <x-external-link class="size-4" />
                                PR{{ data_get($preview, 'pull_request_id') }} |
                                {{ data_get($preview, 'fqdn') }}
                            </a>
                        @endif
                    @endforeach
                @endif
            @endif
            @if (data_get($application, 'ports_mappings_array'))
                @foreach ($application->ports_mappings_array as $port)
                    @if ($application->destination->server->id === 0)
                        <a class="dropdown-item" target="_blank" href="http://localhost:{{ explode(':', $port)[0] }}">
                            <x-external-link class="size-4" />
                            Port {{ $port }}
                        </a>
                    @else
                        <a class="dropdown-item" target="_blank"
                            href="http://{{ $application->destination->server->ip }}:{{ explode(':', $port)[0] }}">
                            <x-external-link class="size-4" />
                            {{ $application->destination->server->ip }}:{{ explode(':', $port)[0] }}
                        </a>
                        @if (count($application->additional_servers) > 0)
                            @foreach ($application->additional_servers as $server)
                                <a class="dropdown-item" target="_blank"
                                    href="http://{{ $server->ip }}:{{ explode(':', $port)[0] }}">
                                    <x-external-link class="size-4" />
                                    {{ $server->ip }}:{{ explode(':', $port)[0] }}
                                </a>
                            @endforeach
                        @endif
                    @endif
                @endforeach
            @endif
        </div>
    @else
        <div class="px-2 py-1.5 text-xs">No links available</div>
    @endif
</x-dropdown>
