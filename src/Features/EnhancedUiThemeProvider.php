<?php

namespace CorelixIo\Platform\Features;

use Illuminate\Foundation\Application;

class EnhancedUiThemeProvider implements FeatureProviderInterface
{
    public static function featureKey(): string
    {
        return 'ENHANCED_UI_THEME';
    }

    public function register(Application $app): void {}

    public function boot(Application $app): void
    {
        \Livewire\Livewire::component('enhanced::appearance-settings', \CorelixIo\Platform\Livewire\AppearanceSettings::class);
    }

    public function booted(Application $app): void {}

    public function apiRoutes(): ?string
    {
        return null;
    }

    public function webRoutes(): ?string
    {
        return __DIR__ . '/../../routes/features/theme-web.php';
    }
}
