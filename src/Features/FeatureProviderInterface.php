<?php

namespace CorelixIo\Platform\Features;

use Illuminate\Foundation\Application;

interface FeatureProviderInterface
{
    /**
     * The feature registry key this provider manages (e.g. 'CLUSTER_MANAGEMENT').
     */
    public static function featureKey(): string;

    /**
     * Register services, config merging, early route loading.
     * Called during ServiceProvider::register() if the feature is enabled.
     * Web routes MUST be loaded here (not boot) to precede Coolify's catch-all.
     */
    public function register(Application $app): void;

    /**
     * Boot services: Livewire components, Eloquent observers, asset publishing.
     * Called during ServiceProvider::boot() if the feature is enabled.
     */
    public function boot(Application $app): void;

    /**
     * Deferred boot: policies, model extensions, schedulers.
     * Called inside $app->booted() — runs AFTER Coolify's AuthServiceProvider.
     */
    public function booted(Application $app): void;

    /**
     * Path to per-feature API route file, or null if none.
     * Loaded during boot() phase.
     */
    public function apiRoutes(): ?string;

    /**
     * Path to per-feature web route file, or null if none.
     * Loaded during register() phase to precede Coolify's catch-all.
     */
    public function webRoutes(): ?string;
}
