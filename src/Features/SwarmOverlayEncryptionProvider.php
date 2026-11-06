<?php

namespace CorelixIo\Platform\Features;

use CorelixIo\Platform\Support\Feature;
use Illuminate\Foundation\Application;

class SwarmOverlayEncryptionProvider implements FeatureProviderInterface
{
    public static function featureKey(): string
    {
        return 'SWARM_OVERLAY_ENCRYPTION';
    }

    public function register(Application $app): void {}

    public function boot(Application $app): void
    {
        if (! Feature::enabled('SWARM_OVERLAY_ENCRYPTION')) {
            config(['corelix-platform.network_management.swarm_overlay_encryption' => false]);
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
