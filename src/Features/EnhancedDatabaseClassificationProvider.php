<?php

namespace CorelixIo\Platform\Features;

use Illuminate\Foundation\Application;

class EnhancedDatabaseClassificationProvider implements FeatureProviderInterface
{
    public static function featureKey(): string
    {
        return 'ENHANCED_DATABASE_CLASSIFICATION';
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
