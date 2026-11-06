<?php

namespace CorelixIo\Platform\Jobs;

use CorelixIo\Platform\Services\NetworkService;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Migrates a server to proxy network isolation.
 *
 * Performs the following steps:
 * 1. Creates the proxy network (ce-proxy-{server_uuid})
 * 2. Connects the proxy container (coolify-proxy) to it
 * 3. Connects all FQDN-bearing resource containers to the proxy network
 * 4. Creates ResourceNetwork pivot records
 *
 * Does NOT disconnect the proxy from old networks — that should be done
 * manually after all resources have been redeployed with traefik.docker.network labels.
 */
class ProxyMigrationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [30, 60, 120];

    public $timeout = 300;

    public function __construct(
        public Server $server
    ) {}

    public function handle(): void
    {
        if (! config('corelix-platform.enabled', false)
            || ! config('corelix-platform.network_management.enabled', false)
            || ! config('corelix-platform.network_management.proxy_isolation', false)) {
            Log::info('ProxyMigrationJob: Proxy isolation not enabled, skipping');

            return;
        }

        if (! $this->server->isFunctional()) {
            Log::warning('ProxyMigrationJob: Server not functional', [
                'server' => $this->server->name,
            ]);

            return;
        }

        Log::info('ProxyMigrationJob: Starting proxy isolation migration', [
            'server' => $this->server->name,
        ]);

        $results = NetworkService::migrateToProxyIsolation($this->server);

        Log::info('ProxyMigrationJob: Migration complete', [
            'server' => $this->server->name,
            'proxy_network' => $results['proxy_network'],
            'proxy_connected' => $results['proxy_connected'],
            'resources_migrated' => $results['resources_migrated'],
            'resources_failed' => $results['resources_failed'],
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        Log::channel('scheduled-errors')->error('ProxyMigrationJob permanently failed', [
            'server' => $this->server->name,
            'error' => $exception?->getMessage(),
        ]);
    }
}
