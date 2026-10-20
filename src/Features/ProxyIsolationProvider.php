<?php

namespace CorelixIo\Platform\Features;

use CorelixIo\Platform\Support\Feature;
use Illuminate\Foundation\Application;

class ProxyIsolationProvider implements FeatureProviderInterface
{
    public static function featureKey(): string
    {
        return 'PROXY_ISOLATION';
    }

    public function register(Application $app): void {}

    public function boot(Application $app): void
    {
        if (! Feature::enabled('PROXY_ISOLATION')) {
            config(['corelix-platform.network_management.proxy_isolation' => false]);
        }
    }

    public function booted(Application $app): void {}

    public function apiRoutes(): ?string
    {
        return null;
    }

    public function webRoutes(): ?string
    {
        return null;
    }
}
