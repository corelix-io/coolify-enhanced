<?php

namespace CorelixIo\Platform\Features;

use CorelixIo\Platform\Jobs\SyncTemplateSourceJob;
use CorelixIo\Platform\Models\CustomTemplateSource;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class CustomTemplateSourcesProvider implements FeatureProviderInterface
{
    protected const STARTUP_SYNC_BANNER_CACHE_KEY = 'corelix-platform:template-sync:startup-active';

    public static function featureKey(): string
    {
        return 'CUSTOM_TEMPLATE_SOURCES';
    }

    public function register(Application $app): void {}

    public function boot(Application $app): void
    {
        \Livewire\Livewire::component('enhanced::custom-template-sources', \CorelixIo\Platform\Livewire\CustomTemplateSources::class);
    }

    public function booted(Application $app): void
    {
        $this->dispatchStartupSyncWhenCacheIsMissing();

        $frequency = config('corelix-platform.custom_templates.sync_frequency');
        if (! $frequency) {
            return;
        }

        $schedule = $app->make(Schedule::class);
        $schedule->call(function () {
            $sources = CustomTemplateSource::where('enabled', true)->get();
            foreach ($sources as $source) {
                SyncTemplateSourceJob::dispatch($source);
            }
        })->cron($frequency)->name('corelix-platform:template-sync')->withoutOverlapping();
    }

    /**
     * When template cache files are missing (for example after instance restart),
     * enqueue a one-time sync so Settings > Templates repopulates automatically.
     */
    protected function dispatchStartupSyncWhenCacheIsMissing(): void
    {
        if (! config('corelix-platform.custom_templates.sync_on_startup', true)) {
            return;
        }

        // Avoid repeated database/file scans on every request.
        if (! Cache::add('corelix-platform:template-sync:startup-check', now()->timestamp, 60)) {
            return;
        }

        if (! Schema::hasTable('custom_template_sources')) {
            return;
        }

        $sources = CustomTemplateSource::where('enabled', true)->get();
        $dispatched = false;
        foreach ($sources as $source) {
            // Main restart recovery path: cache dir exists in runtime storage and can be empty on restart.
            if (! $source->hasCachedTemplates()) {
                $source->update([
                    'last_sync_status' => CustomTemplateSource::STATUS_SYNCING,
                    'last_sync_error' => null,
                ]);
                SyncTemplateSourceJob::dispatch($source);
                $dispatched = true;
            }
        }

        if ($dispatched) {
            Cache::put(self::STARTUP_SYNC_BANNER_CACHE_KEY, true, 600);
        }
    }

    public function apiRoutes(): ?string
    {
        return __DIR__ . '/../../routes/features/templates-api.php';
    }

    public function webRoutes(): ?string
    {
        return __DIR__ . '/../../routes/features/templates-web.php';
    }
}
