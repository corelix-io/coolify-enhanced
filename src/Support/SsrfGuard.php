<?php

namespace CorelixIo\Platform\Support;

/**
 * Centralized SSRF guard for server-side requests to user/credential-controlled URLs.
 *
 * Single source of truth reused by:
 *  - PowerDnsDriver (DNS provider api_url)
 *  - RegistryService (Docker registry URLs + Bearer-token auth realms)
 *
 * Rejects non-http(s) schemes, localhost / *.local, and — after DNS resolution — any
 * loopback / link-local / reserved IP (e.g. cloud metadata 169.254.169.254). RFC1918
 * private ranges are rejected by default but may be permitted for legitimate internal
 * targets (e.g. a self-hosted Docker registry) via the $allowPrivate flag.
 *
 * Note: this validates at check time; the subsequent HTTP client re-resolves DNS, so a
 * determined DNS-rebinding attacker could still differ at connect time. Callers should
 * additionally disable redirect following so a 30x cannot pivot to an internal host.
 */
class SsrfGuard
{
    /**
     * Whether a URL is safe to fetch server-side.
     *
     * @param  bool  $allowPrivate  permit RFC1918 private ranges. Reserved / link-local /
     *                              loopback are ALWAYS blocked regardless of this flag.
     */
    public static function isAllowedUrl(string $url, bool $allowPrivate = false): bool
    {
        $url = trim($url);
        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return static::isAllowedIp($host, $allowPrivate);
        }

        $resolved = gethostbynamel($host) ?: [];
        if ($resolved === []) {
            // Unresolvable host: fail closed.
            return false;
        }

        foreach ($resolved as $ip) {
            if (! static::isAllowedIp($ip, $allowPrivate)) {
                return false;
            }
        }

        return true;
    }

    /**
     * An IP is allowed unless it is reserved/link-local/loopback, or private when
     * $allowPrivate is false.
     */
    protected static function isAllowedIp(string $ip, bool $allowPrivate): bool
    {
        // Reserved ranges (loopback 127/8, link-local + cloud metadata 169.254/16,
        // 0.0.0.0/8, multicast, IPv6 reserved, …) are never permitted.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // RFC1918 / fc00::/7 private ranges are gated by the caller.
        if (! $allowPrivate && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false) {
            return false;
        }

        return true;
    }

    /**
     * True when an IP is NOT publicly routable (private OR reserved).
     */
    public static function isPrivateOrReservedIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
