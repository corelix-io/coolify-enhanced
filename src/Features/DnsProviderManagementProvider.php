<?php

namespace CorelixIo\Platform\Features;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

class DnsProviderManagementProvider implements FeatureProviderInterface
{
    public static function featureKey(): string
    {
        return 'DNS_PROVIDER_MANAGEMENT';
    }

    public function register(Application $app): void
    {
    }

    public function boot(Application $app): void
    {
        Livewire::component('enhanced::dns-provider-manager', \CorelixIo\Platform\Livewire\DnsProviderManager::class);
        Livewire::component('enhanced::dns-domain-manager', \CorelixIo\Platform\Livewire\DnsDomainManager::class);
        Livewire::component('enhanced::resource-domains', \CorelixIo\Platform\Livewire\ResourceDomains::class);
    }

    public function booted(Application $app): void
    {
        Gate::policy(
            \CorelixIo\Platform\Models\DnsProvider::class,
            \CorelixIo\Platform\Policies\DnsProviderPolicy::class
        );
        Gate::policy(
            \CorelixIo\Platform\Models\Domain::class,
            \CorelixIo\Platform\Policies\DomainPolicy::class
        );

        if (! config('corelix-platform.dns_provider_management.enabled', false)) {
            return;
        }

        // Multi-hook FQDN capture layer — ratified hook set, findings §6.4.
        // There is NO single "deployment finished" event covering Applications,
        // Services and databases; each lifecycle is hooked separately.
        // (Standalone* TCP hooks land in Wave 4 with FEATURE_DNS_TCP_RECORDS.)
        $this->registerDeploymentHook();
        $this->registerFqdnChangeHooks();
        $this->registerDeleteCleanupHooks();
    }

    /**
     * Hook §6.4(4): Applications — reconcile when a deployment reaches `finished`.
     */
    protected function registerDeploymentHook(): void
    {
        if (! class_exists(\App\Models\ApplicationDeploymentQueue::class)) {
            return;
        }

        \App\Models\ApplicationDeploymentQueue::updated(function ($queue) {
            if ($queue->isDirty('status') && $queue->status === 'finished') {
                try {
                    $application = $queue->application;
                    if ($application) {
                        $this->dispatchReconcile($application);
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('DnsProviderManagement: failed to dispatch reconcile for deployment', [
                        'deployment_uuid' => $queue->deployment_uuid ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }

    /**
     * Hooks §6.4(1)+(2): FQDN edits.
     *
     * - Application::created/updated — create/clone/API one-shot saves fire `created` only;
     *   guard on fqdn / docker_compose_domains changes for updates.
     * - ServiceApplication::created/updated — fqdn is auto-populated inside serviceParser()
     *   and editable in the service UI. (ServiceStatusChanged is intentionally NOT used —
     *   its payload is unreliable, findings §6.4(6).)
     */
    protected function registerFqdnChangeHooks(): void
    {
        if (class_exists(\App\Models\Application::class)) {
            \App\Models\Application::created(function ($app) {
                if (filled($app->fqdn) || filled($app->docker_compose_domains)) {
                    $this->dispatchReconcile($app);
                }
            });
            \App\Models\Application::updated(function ($app) {
                if ($app->wasChanged('fqdn') || $app->wasChanged('docker_compose_domains')) {
                    $this->dispatchReconcile($app);
                }
            });
        }

        if (class_exists(\App\Models\ServiceApplication::class)) {
            \App\Models\ServiceApplication::created(function ($sa) {
                if (filled($sa->fqdn)) {
                    $this->dispatchReconcile($sa);
                }
            });
            \App\Models\ServiceApplication::updated(function ($sa) {
                if ($sa->wasChanged('fqdn')) {
                    $this->dispatchReconcile($sa);
                }
            });
        }
    }

    /**
     * Hook §6.4(5): removal reconciliation. `deleted` fires for BOTH soft and force
     * deletes, which covers Coolify's `forceDelete()`-based resource removal paths.
     * Service uses `deleting` (before the delete) so its ServiceApplications are still
     * queryable when DB-level cascades would otherwise bypass Eloquent events.
     */
    protected function registerDeleteCleanupHooks(): void
    {
        $purge = function ($resource) {
            try {
                \CorelixIo\Platform\Services\DnsResolutionService::purgeResource($resource);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('DnsProviderManagement: failed to purge managed hostnames', [
                    'resource' => get_class($resource).'#'.($resource->id ?? '?'),
                    'error' => $e->getMessage(),
                ]);
            }
        };

        if (class_exists(\App\Models\Application::class)) {
            \App\Models\Application::deleted($purge);
        }

        if (class_exists(\App\Models\ServiceApplication::class)) {
            \App\Models\ServiceApplication::deleted($purge);
        }

        if (class_exists(\App\Models\Service::class)) {
            \App\Models\Service::deleting(function ($service) use ($purge) {
                try {
                    foreach ($service->applications()->get() as $serviceApplication) {
                        $purge($serviceApplication);
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('DnsProviderManagement: failed to purge service hostnames', [
                        'service_id' => $service->id ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
        }
    }

    protected function dispatchReconcile($resource): void
    {
        try {
            $delay = (int) config('corelix-platform.dns_provider_management.reconcile_debounce', 5);
            \CorelixIo\Platform\Jobs\DnsReconcileJob::dispatch($resource)
                ->delay(now()->addSeconds($delay));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('DnsProviderManagement: failed to dispatch reconcile', [
                'resource' => get_class($resource).'#'.($resource->id ?? '?'),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function apiRoutes(): ?string
    {
        return __DIR__ . '/../../routes/features/dns-api.php';
    }

    public function webRoutes(): ?string
    {
        return __DIR__ . '/../../routes/features/dns-web.php';
    }
}
