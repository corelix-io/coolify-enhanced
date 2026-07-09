<div class="pb-5">
    <h1>Settings</h1>
    <div class="subtitle">Instance wide settings for {{ config('corelix-platform.whitelabel.brand_name', 'Coolify') }}.</div>
    <div class="navbar-main">
        <nav class="flex items-center gap-6 min-h-10 whitespace-nowrap">
            <a class="{{ request()->routeIs('settings.index') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                href="{{ route('settings.index') }}">
                Configuration
            </a>
            <a class="{{ request()->routeIs('settings.backup') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                href="{{ route('settings.backup') }}">
                Backup
            </a>
            <a class="{{ request()->routeIs('settings.email') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                href="{{ route('settings.email') }}">
                Transactional Email
            </a>
            <a class="{{ request()->routeIs('settings.oauth') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                href="{{ route('settings.oauth') }}">
                OAuth
            </a>
            <a class="{{ request()->routeIs('settings.scheduled-jobs') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                href="{{ route('settings.scheduled-jobs') }}">
                Scheduled Jobs
            </a>
            {{-- Corelix Enhanced: Restore/Import Backups tab --}}
            @if (config('corelix-platform.enabled', false))
                <a class="{{ request()->routeIs('settings.restore-backup') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                    href="{{ route('settings.restore-backup') }}">
                    Restore
                </a>
                {{-- Templates tab: only visible to admins/owners --}}
                @if (auth()->user()?->isAdmin() || auth()->user()?->isOwner())
                    <a class="{{ request()->routeIs('settings.custom-templates') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                        href="{{ route('settings.custom-templates') }}">
                        Templates
                    </a>
                @endif
                {{-- Networks tab: only visible to admins/owners --}}
                @if (auth()->user()?->isAdmin() || auth()->user()?->isOwner())
                    <a class="{{ request()->routeIs('settings.networks') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                        href="{{ route('settings.networks') }}">
                        Networks
                    </a>
                @endif
                {{-- Appearance tab: only visible to admins/owners --}}
                @if (auth()->user()?->isAdmin() || auth()->user()?->isOwner())
                    <a class="{{ request()->routeIs('settings.appearance') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                        href="{{ route('settings.appearance') }}">
                        Appearance
                    </a>
                @endif
                @feature('DOCKER_REGISTRY_MANAGEMENT')
                @else
                    @if (auth()->user()?->isAdmin() || auth()->user()?->isOwner())
                        <span class="opacity-50 text-sm" title="Available in Pro">Registries (Pro)</span>
                    @endif
                @endfeature
                {{-- Corelix Enhanced: DNS Provider Management (free) --}}
                @if (\Route::has('settings.dns') && (auth()->user()?->isAdmin() || auth()->user()?->isOwner()))
                    <a class="{{ request()->routeIs('settings.dns') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                        href="{{ route('settings.dns') }}">
                        DNS Providers
                    </a>
                @endif
                @feature('WHITELABELING')
                @else
                    @if (auth()->user()?->isAdmin() || auth()->user()?->isOwner())
                        <span class="opacity-50 text-sm" title="Available in Pro">Branding (Pro)</span>
                    @endif
                @endfeature
            @endif
            <div class="flex-1"></div>
        </nav>
    </div>
</div>
