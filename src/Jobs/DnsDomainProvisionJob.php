<?php

namespace CorelixIo\Platform\Jobs;

use CorelixIo\Platform\Models\Domain;
use CorelixIo\Platform\Support\DnsDriverFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Provision a managed Domain end-to-end (wildcard happy path, T2.1/T2.2/T2.5/T2.6):
 *
 *   1. ensureTunnel        — find-or-create the Cloudflare remote tunnel, persist token
 *   2. ensureDomainRouting — wildcard CNAME + wildcard ingress rule + catch-all (full rebuild)
 *   3. deployDaemon        — run the managed cloudflared container on the bound server
 *   4. wildcard sync (D3)  — conservatively point ServerSetting.wildcard_domain at the domain
 *
 * Serialized per domain; safe to re-run (every step is idempotent desired-state).
 */
class DnsDomainProvisionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $backoff = [10, 30];

    public $timeout = 300;

    public function __construct(
        public Domain $domain,
        public ?int $serverId = null
    ) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('dns-provision-domain-'.$this->domain->id))->releaseAfter(120)->dontRelease(),
        ];
    }

    public function handle(): void
    {
        if (! config('corelix-platform.enabled', false)
            || ! config('corelix-platform.dns_provider_management.enabled', false)) {
            return;
        }

        $domain = $this->domain->refresh();
        $provider = $domain->provider;
        if (! $provider || ! $provider->is_active) {
            Log::warning('DnsDomainProvisionJob: provider missing or inactive', ['domain' => $domain->base_domain]);

            return;
        }

        $driver = DnsDriverFactory::for($provider);

        // 1. Tunnel
        $tunnel = $driver->ensureTunnel($domain);

        // 2. Wildcard CNAME + ingress config (full desired-state rebuild, catch-all last)
        $routing = $driver->ensureDomainRouting($domain->refresh());
        if (! $routing['success']) {
            Log::error('DnsDomainProvisionJob: routing setup failed', [
                'domain' => $domain->base_domain,
                'error' => $routing['error'],
            ]);
            throw new \RuntimeException('Domain routing failed: '.$routing['error']);
        }

        // 3. Managed cloudflared daemon on the bound server
        $server = $this->resolveServer($domain);
        if ($server && $tunnel->managed_daemon) {
            $daemon = $driver->deployDaemon($tunnel, $server);
            if (! $daemon['success']) {
                // Routing is in place; daemon failure is recoverable via redeploy. Log, don't fail the job.
                Log::error('DnsDomainProvisionJob: daemon deploy failed', [
                    'domain' => $domain->base_domain,
                    'server' => $server->name,
                    'error' => $daemon['error'],
                ]);
            }
        }

        // 4. Conservative ServerSetting.wildcard_domain sync (findings §6.1)
        if ($server) {
            $this->syncServerWildcard($domain, $server);
        }

        // 5. Routing is live now — re-drive hostnames that were captured while the
        //    domain was still provisioning (they sat in pending/error).
        $this->redispatchWaitingHostnames($domain);

        Log::info('DnsDomainProvisionJob: domain provisioned', [
            'domain' => $domain->base_domain,
            'tunnel' => $tunnel->cf_tunnel_id,
        ]);

        \CorelixIo\Platform\Support\DnsAudit::record('domain.provisioned', [
            'domain_uuid' => $domain->uuid, 'base_domain' => $domain->base_domain,
        ]);
    }

    protected function redispatchWaitingHostnames(Domain $domain): void
    {
        $waiting = $domain->managedHostnames()
            ->whereIn('sync_state', [
                \CorelixIo\Platform\Models\ManagedHostname::STATE_PENDING,
                \CorelixIo\Platform\Models\ManagedHostname::STATE_ERROR,
            ])
            ->get()
            ->unique(fn ($h) => $h->resource_type.'#'.$h->resource_id);

        foreach ($waiting as $hostnameRow) {
            try {
                $resource = $hostnameRow->resource;
                if ($resource) {
                    DnsReconcileJob::dispatch($resource);
                }
            } catch (\Throwable $e) {
                Log::warning('DnsDomainProvisionJob: failed to re-dispatch reconcile', [
                    'hostname' => $hostnameRow->hostname,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function resolveServer(Domain $domain): ?\App\Models\Server
    {
        if ($this->serverId) {
            return \App\Models\Server::find($this->serverId);
        }

        return $domain->servers()->wherePivot('is_default_wildcard', true)->first()
            ?? $domain->servers()->first();
    }

    /**
     * D3 safe sync rule: set wildcard_domain only when it is (a) empty, (b) an sslip.io
     * placeholder, or (c) the value we last synced. Never overwrite a user-set domain.
     */
    protected function syncServerWildcard(Domain $domain, \App\Models\Server $server): void
    {
        // wherePivot lets the DB do the boolean comparison (driver-agnostic; a raw
        // pivot attribute could be "0"/"1" strings, and "0" is truthy in PHP).
        $pivot = $domain->servers()
            ->wherePivot('is_default_wildcard', true)
            ->where('servers.id', $server->id)
            ->first()?->pivot;
        if (! $pivot) {
            return;
        }

        $settings = $server->settings;
        if (! $settings) {
            return;
        }

        $current = (string) ($settings->wildcard_domain ?? '');
        $desired = 'https://'.$domain->base_domain;

        $isEmpty = trim($current) === '';
        $isSslip = str_contains(strtolower($current), 'sslip.io');
        $isOurs = $current === ($pivot->last_synced_wildcard ?? null);

        if (! ($isEmpty || $isSslip || $isOurs)) {
            Log::info('DnsDomainProvisionJob: skipping wildcard sync (user-set value present)', [
                'server' => $server->name,
                'current' => $current,
            ]);

            return;
        }

        if ($current !== $desired) {
            $settings->wildcard_domain = $desired;
            $settings->save();
        }

        $domain->servers()->updateExistingPivot($server->id, ['last_synced_wildcard' => $desired]);

        Log::info('DnsDomainProvisionJob: synced server wildcard_domain', [
            'server' => $server->name,
            'wildcard' => $desired,
        ]);
    }
}
