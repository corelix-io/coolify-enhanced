{{-- Corelix Enhanced: full overlay of resources/views/components/services/links.blade.php --}}
{{-- Adds per-hostname DNS status dots. $service and $links injected by the PHP component class. --}}
@php
    $linkItemClasses = 'listbox-option justify-start! gap-2.5!';

    // [CORELIX ENHANCED: DNS status badges (managed hostnames across the service's apps)]
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
        'synced'  => 'bg-success',
        'pending' => 'bg-warning',
        'drifted' => 'bg-warning',
        'error'   => 'bg-error',
    ];
    // End Corelix Enhanced
@endphp

<div @class([
    'relative' => !$compact,
    'static' => $compact,
    'w-full' => $fullWidth,
]) x-data="{ open: false }"
    x-effect="$dispatch('resource-actions-toggled', { open })" @keydown.escape.window="open = false">
    <button type="button" @click="open = !open" @click.outside="open = false" title="Open service links"
        @class([
            'app-tab shrink-0 gap-1' => !$fullWidth && !$compact,
            'button w-full justify-between' => $fullWidth,
            'inline-flex h-6 shrink-0 items-center gap-1.5 rounded-full border border-neutral-200 bg-neutral-100 px-2 text-xs font-medium leading-none text-neutral-700 dark:border-white/[0.12] dark:bg-white/[0.07] dark:text-white' => $compact,
        ])>
        <span class="inline-flex items-center gap-2">
            @unless ($compact)
                <x-reicon name="external-link" class="size-3.5 shrink-0 opacity-70" />
            @endunless
            Links
        </span>
        <span class="inline-flex transition-transform" :class="open && 'rotate-180'">
            <x-reicon name="chevron-down" class="size-3 opacity-55" />
        </span>
    </button>
    <div x-show="open" x-cloak x-transition.origin.top.right role="menu"
        @class([
            'listbox-panel top-full! mt-1! max-h-80! overflow-y-auto!',
            'left-0! right-0! w-full! min-w-0! max-w-none!' => $fullWidth,
            'left-1/2! right-auto! w-[calc(100vw-2rem)]! max-w-md! min-w-0! -translate-x-1/2' => $compact,
            'right-0! left-auto! min-w-60! max-w-96!' => !$fullWidth && !$compact,
        ])>
        @forelse ($links as $link)
            <a class="{{ $linkItemClasses }}" target="_blank" href="{{ $link }}">
                <span class="min-w-0 truncate">{{ $link }}</span>
                {{-- Corelix Enhanced: DNS status dot --}}
                @if (($ceState = $ceDnsBadge($link)) && isset($ceDnsBadgeClass[$ceState]))
                    <span title="Corelix DNS: {{ $ceState }}"
                        class="ml-auto shrink-0 size-2 rounded-full {{ $ceDnsBadgeClass[$ceState] }}"></span>
                @endif
            </a>
        @empty
            <div class="listbox-option justify-start! cursor-default!">No links available</div>
        @endforelse
    </div>
</div>
