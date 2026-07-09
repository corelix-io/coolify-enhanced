<?php

namespace CorelixIo\Platform\Services;

use CorelixIo\Platform\Models\CloudflareTunnel;
use CorelixIo\Platform\Models\DnsProvider;
use CorelixIo\Platform\Models\Domain;
use CorelixIo\Platform\Models\ManagedHostname;
use CorelixIo\Platform\Support\DnsDriverFactory;
use CorelixIo\Platform\Support\Feature;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Provider-side cleanup before domain/provider deletion (DNS-06, DNS-07).
 */
class DnsTeardownService
{
    /**
     * Remove Access apps, per-host DNS/tunnel state, and stop orphaned daemons before
     * deleting a managed domain.
     */
    public static function teardownDomain(Domain $domain): void
    {
        $domain->load(['provider', 'tunnel']);

        $rows = ManagedHostname::where('domain_id', $domain->id)
            ->with(['domain', 'provider'])
            ->get();

        static::removeAccessApps($rows, $domain);

        if (Feature::enabled('DNS_PER_HOSTNAME')) {
            static::cleanupPerHostHttpRecords($rows, $domain);
        }

        $tunnel = $domain->tunnel;
        if ($tunnel && $domain->provider?->is_active) {
            try {
                $domain->update(['is_active' => false]);
                DnsDriverFactory::for($domain->provider)->rebuildTunnelConfig($tunnel);
            } catch (\Throwable $e) {
                Log::warning('DnsTeardownService: tunnel rebuild before domain delete failed', [
                    'domain_uuid' => $domain->uuid,
                    'error' => $e->getMessage(),
                ]);
            }

            $otherActive = Domain::query()
                ->where('cloudflare_tunnel_id', $tunnel->id)
                ->whereKeyNot($domain->id)
                ->where('is_active', true)
                ->exists();

            if (! $otherActive) {
                static::stopTunnelDaemon($tunnel);
            }
        }
    }

    /**
     * Stop managed cloudflared daemons for all tunnels before provider deletion.
     */
    public static function teardownProvider(DnsProvider $provider): void
    {
        $provider->load('tunnels.daemonServer');

        foreach ($provider->tunnels as $tunnel) {
            static::stopTunnelDaemon($tunnel);
        }
    }

    /**
     * Provider-side cleanup for HTTP hostnames being purged (resource delete).
     *
     * @param  Collection<int, ManagedHostname>  $rows
     */
    public static function cleanupPurgedHttpHostnames(Collection $rows): void
    {
        if (! Feature::enabled('DNS_PER_HOSTNAME') || $rows->isEmpty()) {
            return;
        }

        $rebuilds = [];

        foreach ($rows as $row) {
            $domain = $row->domain;
            if (! $domain || $domain->routing_mode === Domain::ROUTING_WILDCARD) {
                continue;
            }

            $provider = $domain->provider;
            if (! $provider || ! $provider->is_active) {
                continue;
            }

            try {
                $driver = DnsDriverFactory::for($provider);

                if ($domain->routing_mode === Domain::ROUTING_PER_HOSTNAME
                    && filled($row->provider_record_id)) {
                    $driver->removeDnsRecord($domain, $row->provider_record_id);
                }

                if ($domain->tunnel) {
                    $rebuilds[$domain->tunnel->id] = [$driver, $domain->tunnel];
                }
            } catch (\Throwable $e) {
                Log::warning('DnsTeardownService: purged-hostname provider cleanup failed', [
                    'hostname' => $row->hostname,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        foreach ($rebuilds as [$driver, $tunnel]) {
            try {
                $driver->rebuildTunnelConfig($tunnel);
            } catch (\Throwable $e) {
                Log::warning('DnsTeardownService: tunnel rebuild after purge failed', [
                    'tunnel_id' => $tunnel->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, ManagedHostname>  $rows
     */
    protected static function removeAccessApps(Collection $rows, Domain $domain): void
    {
        if (! Feature::enabled('DNS_ACCESS_POLICIES')
            || ! class_exists(DnsAccessPolicyService::class)) {
            return;
        }

        foreach ($rows as $row) {
            if (filled($row->access_app_id ?? null)) {
                DnsAccessPolicyService::removeByAppId($row->access_app_id, $domain);
            }
        }
    }

    /**
     * @param  Collection<int, ManagedHostname>  $rows
     */
    protected static function cleanupPerHostHttpRecords(Collection $rows, Domain $domain): void
    {
        if ($domain->routing_mode === Domain::ROUTING_WILDCARD || ! $domain->provider?->is_active) {
            return;
        }

        try {
            $driver = DnsDriverFactory::for($domain->provider);
            $tunnel = $domain->tunnel;

            if ($domain->routing_mode === Domain::ROUTING_PER_HOSTNAME) {
                foreach ($rows->where('record_kind', ManagedHostname::KIND_HTTP_TUNNEL) as $row) {
                    if (filled($row->provider_record_id)) {
                        $driver->removeDnsRecord($domain, $row->provider_record_id);
                    }
                }
            }

            if ($tunnel) {
                $driver->rebuildTunnelConfig($tunnel);
            }
        } catch (\Throwable $e) {
            Log::warning('DnsTeardownService: per-host DNS cleanup before domain delete failed', [
                'domain_uuid' => $domain->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected static function stopTunnelDaemon(CloudflareTunnel $tunnel): void
    {
        $tunnel->loadMissing('provider', 'daemonServer');
        $server = $tunnel->daemonServer;
        if (! $server || ! $tunnel->provider) {
            return;
        }

        try {
            $driver = DnsDriverFactory::for($tunnel->provider);
            if (method_exists($driver, 'stopDaemon')) {
                $driver->stopDaemon($tunnel, $server);
            }
        } catch (\Throwable $e) {
            Log::warning('DnsTeardownService: failed to stop cloudflared daemon', [
                'tunnel_uuid' => $tunnel->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
