<?php

namespace CorelixIo\Platform\Features;

use Illuminate\Foundation\Application;

class AdditionalBuildTypesProvider implements FeatureProviderInterface
{
    public static function featureKey(): string
    {
        return 'ADDITIONAL_BUILD_TYPES';
    }

    public function register(Application $app): void {}

    public function boot(Application $app): void {}

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
