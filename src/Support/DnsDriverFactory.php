<?php

namespace CorelixIo\Platform\Support;

use CorelixIo\Platform\Contracts\DnsProviderInterface;
use CorelixIo\Platform\Drivers\CloudflareTunnelDriver;
use CorelixIo\Platform\Drivers\PowerDnsDriver;
use CorelixIo\Platform\Models\DnsProvider;

/**
 * Maps a DnsProvider record to its driver implementation (mirrors Cluster::driver()).
 *
 * Note: TYPE_POWERDNS maps to a conformance scaffold (Wave 7, T7.4) — it is not in
 * DnsProvider::TYPES yet, so UI/API validation prevents creating one.
 */
class DnsDriverFactory
{
    public static function for(DnsProvider $provider): DnsProviderInterface
    {
        $driver = match ($provider->type) {
            DnsProvider::TYPE_CLOUDFLARE_TUNNEL => new CloudflareTunnelDriver,
            DnsProvider::TYPE_POWERDNS => new PowerDnsDriver,
            default => throw new \InvalidArgumentException("Unsupported DNS provider type: {$provider->type}"),
        };

        return $driver->setProvider($provider);
    }
}
