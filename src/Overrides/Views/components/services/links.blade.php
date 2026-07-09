{{-- Corelix Enhanced: full overlay of resources/views/components/services/links.blade.php --}}
{{-- Adds per-hostname DNS status dots (managed domains). $service and $links come from --}}
{{-- App\View\Components\Services\Links (PHP class component, not overlaid). --}}
@php
    /* Corelix Enhanced: DNS status badges (managed hostnames across the service's apps) */
    $ceDnsStatuses = [];
    if (config('corelix-platform.enabled', false)
        && config('corelix-platform.dns_provider_management.enabled', false)
        && class_exists(\CorelixIo\Platform\Services\DnsResolutionService::class)) {
        $ceDnsStatuses = \CorelixIo\Platform\Services\DnsResolutionService::hostnameStatusMapForService($service);
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
@if ($links->count() > 0)
    <x-dropdown>
        <x-slot:title>
            Links
        </x-slot>
        @foreach ($links as $link)
            <a class="dropdown-item" target="_blank" href="{{ $link }}">
                <x-external-link class="size-3.5" />{{ $link }}
                {{-- Corelix Enhanced: DNS status dot (unmanaged/unknown states render nothing) --}}
                @if (($ceState = $ceDnsBadge($link)) && isset($ceDnsBadgeClass[$ceState]))
                    <span title="Corelix DNS: {{ $ceState }}"
                        class="inline-block w-2 h-2 ml-1 rounded-full {{ $ceDnsBadgeClass[$ceState] }}"></span>
                @endif
            </a>
        @endforeach
    </x-dropdown>
@endif
