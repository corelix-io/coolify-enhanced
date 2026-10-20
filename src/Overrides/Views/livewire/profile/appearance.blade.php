<div>
    <x-slot:title>Appearance | Coolify</x-slot>
    <x-theme-controls variant="full" />
    {{-- Corelix Enhanced: discoverable link to the instance-wide UI Theme settings (owner/admin only).
         Per-user preference (light/dark/custom) is what the upstream component manages. The
         instance-wide setting applies to all users, so it lives on its own page and is only surfaced to admins here. --}}
    @if (config('corelix-platform.enabled', false) && (auth()->user()?->isAdmin() || auth()->user()?->isOwner()))
        <div class="mt-6 pt-6 border-t dark:border-coolgray-200 border-gray-200">
            <div>Choose an instance-wide corporate UI theme (applies to all users). This is an admin-level setting.</div>
            <div class="mt-3">
                <a href="{{ route('settings.appearance') }}" {{ wireNavigate() }}
                    class="text-sm font-medium text-coollabs dark:text-warning hover:underline">
                    Open Instance Theme Settings →
                </a>
            </div>
        </div>
    @endif
    {{-- End Corelix Enhanced --}}
</div>
