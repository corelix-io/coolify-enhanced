<?php

namespace CorelixIo\Platform\Features;

use Illuminate\Foundation\Application;

class ResourceBackupsProvider implements FeatureProviderInterface
{
    public static function featureKey(): string
    {
        return 'RESOURCE_BACKUPS';
    }

    public function register(Application $app): void {}

    public function boot(Application $app): void
    {
        \Livewire\Livewire::component('enhanced::resource-backup-manager', \CorelixIo\Platform\Livewire\ResourceBackupManager::class);
        \Livewire\Livewire::component('enhanced::resource-backup-page', \CorelixIo\Platform\Livewire\ResourceBackupPage::class);
        \Livewire\Livewire::component('enhanced::restore-backup', \CorelixIo\Platform\Livewire\RestoreBackup::class);
    }

    public function booted(Application $app): void
    {
        $schedule = $app->make(\Illuminate\Console\Scheduling\Schedule::class);
        $schedule->call(function () {
            $backups = \CorelixIo\Platform\Models\ScheduledResourceBackup::where('enabled', true)->get();
            foreach ($backups as $backup) {
                try {
                    $timezone = $backup->timezone ?? config('app.timezone', 'UTC');
                    if (shouldRunCronNow($backup->frequency, $timezone, 'resource-backup-'.$backup->id)) {
                        \CorelixIo\Platform\Jobs\ResourceBackupJob::dispatch($backup);
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('ResourceBackup: Invalid cron for backup '.$backup->uuid, [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        })->everyMinute()->name('corelix-platform:resource-backups')->withoutOverlapping();
    }

    public function apiRoutes(): ?string
    {
        return __DIR__ . '/../../routes/features/backups-api.php';
    }

    public function webRoutes(): ?string
    {
        return __DIR__ . '/../../routes/features/backups-web.php';
    }
}
