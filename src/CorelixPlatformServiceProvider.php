<?php

namespace CorelixIo\Platform;

use CorelixIo\Platform\Features\FeatureProviderInterface;
use CorelixIo\Platform\Support\Feature;
use Illuminate\Support\ServiceProvider;

class CorelixPlatformServiceProvider extends ServiceProvider
{
    /** @var FeatureProviderInterface[] */
    protected array $providers = [];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/features.php', 'features');
        $this->mergeConfigFrom(__DIR__.'/../config/corelix-platform.php', 'corelix-platform');

        if (! config('corelix-platform.enabled', false)) {
            return;
        }

        $this->providers = $this->resolveFeatureProviders();

        foreach ($this->providers as $provider) {
            $provider->register($this->app);

            // Web routes MUST be loaded during register() to precede
            // Coolify's catch-all Route::any('/{any}', ...) registered in
            // RouteServiceProvider::boot(). Routes loaded later would match
            // the catch-all and cause redirect loops.
            $webRoutes = $provider->webRoutes();
            if ($webRoutes && file_exists($webRoutes)) {
                $this->loadRoutesFrom($webRoutes);
            }
        }
    }

    public function boot(): void
    {
        $this->registerBladeDirectives();
        $this->app['router']->aliasMiddleware('feature', \CorelixIo\Platform\Http\Middleware\FeatureMiddleware::class);

        if (! config('corelix-platform.enabled', false)) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'corelix-platform');

        $this->publishes([
            __DIR__.'/../config/corelix-platform.php' => config_path('corelix-platform.php'),
        ], 'corelix-platform-config');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/corelix-platform'),
        ], 'corelix-platform-views');

        $this->publishes([
            __DIR__.'/../resources/assets/themes' => public_path('vendor/corelix-platform/themes'),
        ], 'corelix-platform-theme');

        foreach ($this->providers as $provider) {
            $provider->boot($this->app);

            $apiRoutes = $provider->apiRoutes();
            if ($apiRoutes && file_exists($apiRoutes)) {
                $this->loadRoutesFrom($apiRoutes);
            }
        }

        $this->app->booted(function () {
            foreach ($this->providers as $provider) {
                $provider->booted($this->app);
            }
        });
    }

    protected function registerBladeDirectives(): void
    {
        \Illuminate\Support\Facades\Blade::if('feature', function (string $key) {
            return Feature::enabled($key);
        });
    }

    /**
     * Discover and instantiate all enabled feature providers.
     * Uses class_exists() to gracefully skip providers whose files
     * have been excluded from a free build.
     *
     * @return FeatureProviderInterface[]
     */
    protected function resolveFeatureProviders(): array
    {
        $providers = [];
        $seen = [];

        foreach (config('features.registry', []) as $feature) {
            $class = $feature['provider'] ?? null;

            if (! is_string($class) || $class === '' || isset($seen[$class])) {
                continue;
            }

            $seen[$class] = true;

            if (! class_exists($class)) {
                continue;
            }

            $key = $feature['key'] ?? $class::featureKey();

            if (Feature::enabled($key)) {
                $providers[] = new $class;
            }
        }

        return $providers;
    }
}
