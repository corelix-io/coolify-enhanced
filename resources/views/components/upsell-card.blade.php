@props(['feature'])
@php
    $meta = \CorelixIo\Platform\Support\Feature::meta($feature);
    if (!$meta) return;
    $upgradeUrl = \CorelixIo\Platform\Support\Feature::upgradeUrl();
@endphp

<div {{ $attributes->merge(['class' => 'border border-coolgray-200 bg-coolgray-100 rounded-lg p-6']) }}>
    <div class="flex items-center justify-between">
        <h3 class="text-lg font-semibold text-white">{{ $meta['name'] }}</h3>
        <span class="bg-purple-500/20 text-purple-400 text-xs font-medium px-2.5 py-0.5 rounded-full">
            Pro
        </span>
    </div>

    @if (!empty($meta['description']))
        <p class="mt-2 text-sm text-neutral-400">{{ $meta['description'] }}</p>
    @endif

    <a href="{{ $upgradeUrl }}"
       target="_blank"
       rel="noopener"
       class="inline-flex items-center gap-2 mt-4 px-4 py-2 text-sm font-medium text-white bg-coolgray-200 hover:bg-coolgray-300 rounded-lg transition-colors">
        Learn More →
    </a>
</div>
