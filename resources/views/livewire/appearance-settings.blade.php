<div>
    <x-slot:title>
        Appearance | {{ config('corelix-platform.whitelabel.brand_name', 'Coolify') }}
    </x-slot>
    <x-settings.navbar />

    <h2>Appearance</h2>
    <div class="subtitle">
        Choose a visual theme for the dashboard. Themes change colors, typography, and visual styling without altering layout or behavior.
    </div>

    <div class="flex flex-col gap-2 pt-4 max-w-md">
        <x-forms.select id="activeTheme" wire:model.live="activeTheme" label="Dashboard Theme">
            <option value="">Default ({{ config('corelix-platform.whitelabel.brand_name', 'Coolify') }})</option>
            @foreach ($availableThemes as $slug => $theme)
                <option value="{{ $slug }}">{{ $theme['label'] }}{{ $theme['font_label'] ? ' — ' . $theme['font_label'] . ' font' : '' }}</option>
            @endforeach
        </x-forms.select>
        @if ($activeTheme && isset($availableThemes[$activeTheme]))
            <p class="text-sm pt-1">{{ $availableThemes[$activeTheme]['description'] }}</p>
        @endif
        <p class="text-xs pt-2">Reload the page after changing themes to see the new styling applied.</p>
    </div>
</div>
