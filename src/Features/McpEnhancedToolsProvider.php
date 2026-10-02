<?php

namespace CorelixIo\Platform\Features;

use Illuminate\Foundation\Application;

class McpEnhancedToolsProvider implements FeatureProviderInterface
{
    public static function featureKey(): string
    {
        return 'MCP_ENHANCED_TOOLS';
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
