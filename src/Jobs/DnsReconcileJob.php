<?php

namespace CorelixIo\Platform\Jobs;

use CorelixIo\Platform\Models\Domain;
use CorelixIo\Platform\Models\ManagedHostname;
use CorelixIo\Platform\Services\DnsResolutionService;
use CorelixIo\Platform\Support\DnsDriverFactory;
use CorelixIo\Platform\Support\Feature;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Reconcile a single resource's hostnames against its DNS provider(s).
 *
 * Idempotent desired-state pass:
 *   1. extract hostnames → longest-suffix ownership → sync ManagedHostname rows
 *   2. per hostname, drive the provider reconcile (wildcard happy path = ZERO provider calls)
 *
 * Wildcard-mode hostnames go straight to `synced` once their domain's tunnel is active.
 * Config-rewriting modes (per_hostname/hybrid, PRO) are serialized per tunnel by the
 * driver's atomic cache lock (CloudflareTunnelDriver::tunnelLockKey); the wildcard path
 * performs no tunnel writes so the per-resource WithoutOverlapping lock suffices.
 * Standalone databases take the TCP record path (PRO: grey-cloud A/AAAA → server IP).
 */
class DnsReconcileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [5, 15, 30];

    public $timeout = 120;

    public function __construct(
        public $resource
    ) {}

    public function middleware(): array
    {
        $key = 'dns-reconcile-'.get_class($this->resource).'-'.$this->resource->id;

        return [
            (new WithoutOverlapping($key))->releaseAfter(60),
        ];
    }

    public function handle(): void
    {
        if (! config('corelix-platform.enabled', false)
            || ! config('corelix-platform.dns_provider_management.enabled', false)) {
            return;
        }

        if ($this->isStandaloneDatabase()) {

            return; // databases have no HTTP fqdn flow
        }

        try {
            $result = DnsResolutionService::syncManagedHostnames($this->resource);
        } catch (\Throwable $e) {
            Log::error('DnsReconcileJob: hostname sync failed', [
                'resource' => get_class($this->resource).'#'.$this->resource->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        foreach ($result['current'] as $hostname) {
            $this->reconcileHostname($hostname);
        }

        if ($result['removed']->isNotEmpty()) {
            Log::info('DnsReconcileJob: removed stale managed hostnames', [
                'resource' => get_class($this->resource).'#'.$this->resource->id,
                'hostnames' => $result['removed']->pluck('hostname')->all(),
            ]);


        }
    }

    /**
     * Standalone database models (StandalonePostgresql, StandaloneMysql, ...) take the
     * TCP record path; everything else takes the HTTP fqdn path.
     */
    protected function isStandaloneDatabase(): bool
    {
        return str_starts_with(class_basename($this->resource), 'Standalone');
    }

    protected function reconcileHostname(ManagedHostname $hostname): void
    {
        if ($hostname->sync_state === ManagedHostname::STATE_SYNCED) {
            return; // already converged; wildcard coverage does not expire
        }

        $provider = $hostname->provider;
        if (! $provider || ! $provider->is_active) {
            $hostname->update([
                'sync_state' => ManagedHostname::STATE_ERROR,
                'last_error' => 'DNS provider missing or inactive.',
            ]);

            return;
        }

        try {
            $driver = \CorelixIo\Platform\Support\DnsDriverFactory::for($provider);
            $result = $driver->reconcile($hostname);
        } catch (\Throwable $e) {
            $result = ['success' => false, 'error' => $e->getMessage()];
        }

        if ($result['success']) {
            $hostname->update([
                'sync_state' => ManagedHostname::STATE_SYNCED,
                'last_synced_at' => now(),
                'last_error' => null,
            ]);

        } elseif (! empty($result['pending'])) {
            // Domain not provisioned yet — keep waiting, do not surface as error.
            $hostname->update([
                'sync_state' => ManagedHostname::STATE_PENDING,
                'last_error' => $result['error'],
            ]);
        } else {
            $hostname->update([
                'sync_state' => ManagedHostname::STATE_ERROR,
                'last_error' => $result['error'],
            ]);
            Log::warning('DnsReconcileJob: hostname reconcile failed', [
                'hostname' => $hostname->hostname,
                'error' => $result['error'],
            ]);
        }
    }


}
