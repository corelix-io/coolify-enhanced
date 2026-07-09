<?php

namespace CorelixIo\Platform\Features;

use Illuminate\Foundation\Application;

class NetworkManagementProvider implements FeatureProviderInterface
{
    public static function featureKey(): string
    {
        return 'NETWORK_MANAGEMENT';
    }

    public function register(Application $app): void {}

    public function boot(Application $app): void
    {
        \Livewire\Livewire::component('enhanced::network-manager', \CorelixIo\Platform\Livewire\NetworkManager::class);
        \Livewire\Livewire::component('enhanced::network-manager-page', \CorelixIo\Platform\Livewire\NetworkManagerPage::class);
        \Livewire\Livewire::component('enhanced::resource-networks', \CorelixIo\Platform\Livewire\ResourceNetworks::class);
        \Livewire\Livewire::component('enhanced::network-settings', \CorelixIo\Platform\Livewire\NetworkSettings::class);

        // Deterministic ingress labels (traefik.docker.network / caddy_ingress_network)
        // for compose-based Applications and Services. Upstream parsers.php computes
        // their Traefik/Caddy labels WITHOUT a network hint; once we attach containers
        // to managed networks post-deploy, Traefik would otherwise pick a network IP
        // non-deterministically (intermittent 502s). Same 'updating' hook pattern as
        // the Traefik Label Overrides feature — parsers.php saves the model internally.
        // Persist managed-network membership (ce-env-* / ce-proxy-*) into the
        // freshly parsed docker_compose so it survives container recreation.
        // Runs after the ingress-label injection (independent transforms) via
        // the same 'updating' hook. See ManagedNetworkComposeService.
        if (class_exists(\App\Models\Service::class)) {
            \App\Models\Service::updating(function ($service) {
                \CorelixIo\Platform\Services\IngressNetworkLabelService::applyToService($service);
                \CorelixIo\Platform\Services\ManagedNetworkComposeService::applyToService($service);
            });
        }
        if (class_exists(\App\Models\Application::class)) {
            \App\Models\Application::updating(function ($application) {
                \CorelixIo\Platform\Services\IngressNetworkLabelService::applyToApplication($application);
                \CorelixIo\Platform\Services\ManagedNetworkComposeService::applyToApplication($application);
            });
        }
    }

    public function booted(Application $app): void
    {
        if (class_exists(\CorelixIo\Platform\Models\ManagedNetwork::class)) {
            \Illuminate\Support\Facades\Gate::policy(
                \CorelixIo\Platform\Models\ManagedNetwork::class,
                \CorelixIo\Platform\Policies\NetworkPolicy::class
            );
        }

        // Scheduled membership self-heal. Runtime network attachment is lost on
        // container recreation (reboot / out-of-band `docker compose up`), and
        // no deploy/status event fires in those cases. This periodically
        // re-verifies + reconnects only resources that already have intended
        // memberships and corrects any stale is_connected pivot state.
        $schedule = $app->make(\Illuminate\Console\Scheduling\Schedule::class);
        $membershipInterval = max(1, min(59, (int) config('corelix-platform.network_management.membership_reconcile_interval', 5)));
        $schedule->call(function () {
            if (! config('corelix-platform.network_management.enabled', false)
                || config('corelix-platform.network_management.isolation_mode', 'environment') === 'none') {
                return;
            }

            try {
                $serverIds = \CorelixIo\Platform\Models\ManagedNetwork::query()
                    ->distinct()
                    ->pluck('server_id')
                    ->filter();

                foreach ($serverIds as $serverId) {
                    $server = \App\Models\Server::find($serverId);
                    if ($server && $server->isFunctional()) {
                        \CorelixIo\Platform\Jobs\NetworkReconcileJob::dispatch($server, fullReconcile: false, membershipOnly: true);
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('NetworkManagement: Failed to schedule membership reconcile', [
                    'error' => $e->getMessage(),
                ]);
            }
        })->cron("*/{$membershipInterval} * * * *")->name('corelix-platform:network-membership')->withoutOverlapping();

        $delay = config('corelix-platform.network_management.post_deploy_delay', 3);

        if (class_exists(\App\Models\ApplicationDeploymentQueue::class)) {
            \App\Models\ApplicationDeploymentQueue::updated(function ($queue) use ($delay) {
                if ($queue->isDirty('status') && $queue->status === 'finished') {
                    try {
                        $application = $queue->application;
                        if ($application) {
                            \CorelixIo\Platform\Jobs\NetworkReconcileJob::dispatch($application)
                                ->delay(now()->addSeconds($delay));
                        }
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('NetworkManagement: Failed to dispatch reconcile for deployment', [
                            'deployment_uuid' => $queue->deployment_uuid ?? null,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
        }

        if (class_exists('App\Events\ServiceStatusChanged')) {
            \Illuminate\Support\Facades\Event::listen('App\Events\ServiceStatusChanged', function ($event) use ($delay) {
                $teamId = $event->teamId ?? null;
                if (!$teamId) {
                    return;
                }
                try {
                    foreach (\CorelixIo\Platform\Services\NetworkService::getServerIdsForTeamServices($teamId) as $serverId) {
                        $server = \App\Models\Server::find($serverId);
                        if ($server) {
                            \CorelixIo\Platform\Services\NetworkService::dispatchDebouncedServerReconcile($server, $delay);
                        }
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('NetworkManagement: Failed to dispatch reconcile for services', [
                        'team_id' => $teamId, 'error' => $e->getMessage(),
                    ]);
                }
            });
        }

        if (class_exists('App\Events\DatabaseStatusChanged')) {
            \Illuminate\Support\Facades\Event::listen('App\Events\DatabaseStatusChanged', function ($event) use ($delay) {
                $userId = $event->userId ?? null;
                if (!$userId) {
                    return;
                }
                try {
                    $user = \App\Models\User::find($userId);
                    $team = $user?->currentTeam();
                    if (!$team) {
                        return;
                    }
                    foreach (\CorelixIo\Platform\Services\NetworkService::getServerIdsForTeamDatabases($team->id) as $serverId) {
                        $server = \App\Models\Server::find($serverId);
                        if ($server) {
                            \CorelixIo\Platform\Services\NetworkService::dispatchDebouncedServerReconcile($server, $delay);
                        }
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('NetworkManagement: Failed to dispatch reconcile for databases', [
                        'user_id' => $userId, 'error' => $e->getMessage(),
                    ]);
                }
            });
        }

        if (class_exists(\App\Models\Application::class)) {
            \App\Models\Application::deleting(function ($application) {
                \CorelixIo\Platform\Services\NetworkService::autoDetachResource($application);
            });
        }
        if (class_exists(\App\Models\Service::class)) {
            \App\Models\Service::deleting(function ($service) {
                \CorelixIo\Platform\Services\NetworkService::autoDetachResource($service);
            });
        }
        $databaseModels = [
            \App\Models\StandalonePostgresql::class, \App\Models\StandaloneMysql::class,
            \App\Models\StandaloneMariadb::class, \App\Models\StandaloneMongodb::class,
            \App\Models\StandaloneRedis::class, \App\Models\StandaloneKeydb::class,
            \App\Models\StandaloneDragonfly::class, \App\Models\StandaloneClickhouse::class,
        ];
        foreach ($databaseModels as $modelClass) {
            if (class_exists($modelClass)) {
                $modelClass::deleting(function ($database) {
                    \CorelixIo\Platform\Services\NetworkService::autoDetachResource($database);
                });
            }
        }
    }

    public function apiRoutes(): ?string
    {
        return __DIR__ . '/../../routes/features/networks-api.php';
    }

    public function webRoutes(): ?string
    {
        return __DIR__ . '/../../routes/features/networks-web.php';
    }
}
