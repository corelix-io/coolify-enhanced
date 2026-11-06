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
 * DNS rebinding: isAllowedUrl() alone validates at check time and the HTTP client would
 * resolve again at connect time. Callers that issue the request should use pinnedOptions(),
 * which resolves once (A + AAAA), validates every answer, and returns Guzzle options that
 * pin the connection to those exact IPs via CURLOPT_RESOLVE. The URL (and so Host header,
 * SNI and TLS verification) is left untouched. Redirects are disabled in the same options.
 */
class SsrfGuard
{
    /** @var (callable(string): list<string>)|null test seam replacing real DNS lookups */
    protected static $resolver = null;

    /**
     * Override the resolver (tests). Pass null to restore the system resolver.
     *
     * @param  (callable(string): list<string>)|null  $resolver  host => list of IP strings
     */
    public static function setResolver(?callable $resolver): void
    {
        static::$resolver = $resolver;
    }

    /**
     * Resolve a hostname to all A and AAAA addresses.
     *
     * @return list<string>
     */
    protected static function resolveHost(string $host): array
    {
        if (static::$resolver !== null) {
            return array_values((static::$resolver)($host));
        }

        $ips = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * Resolve once, validate every answer, and return Guzzle request options that pin the
     * connection to the validated IPs (closes the check-then-connect DNS-rebinding gap).
     *
     * @return array<string, mixed>|null  null when the URL is not allowed; otherwise options
     *                                    to pass to Http::withOptions() / Guzzle.
     */
    public static function pinnedOptions(string $url, bool $allowPrivate = false): ?array
    {
        $options = ['verify' => true, 'allow_redirects' => false];

        $ips = static::validatedIps($url, $allowPrivate);
        if ($ips === null) {
            return null;
        }
        if ($ips === []) {
            // IP-literal host: nothing to resolve, nothing to pin.
            return $options;
        }

        $parts = parse_url(trim($url));
        $host = strtolower((string) $parts['host']);
        $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);
        $addresses = array_map(fn (string $ip) => str_contains($ip, ':') ? "[{$ip}]" : $ip, $ips);

        $options['curl'] = [CURLOPT_RESOLVE => ["{$host}:{$port}:".implode(',', $addresses)]];

        return $options;
    }

    /**
     * Whether a URL is safe to fetch server-side.
     *
     * @param  bool  $allowPrivate  permit RFC1918 private ranges. Reserved / link-local /
     *                              loopback are ALWAYS blocked regardless of this flag.
     */
    public static function isAllowedUrl(string $url, bool $allowPrivate = false): bool
    {
        return static::validatedIps($url, $allowPrivate) !== null;
    }

    /**
     * Validate a URL and return the IPs it resolved to.
     *
     * @return list<string>|null  null = rejected; [] = allowed IP-literal (no DNS involved)
     */
    protected static function validatedIps(string $url, bool $allowPrivate): ?array
    {
        $url = trim($url);
        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local')) {
            return null;
        }

        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP)) {
            return static::isAllowedIp($literal, $allowPrivate) ? [] : null;
        }

        $resolved = static::resolveHost($host);
        if ($resolved === []) {
            // Unresolvable host: fail closed.
            return null;
        }

        foreach ($resolved as $ip) {
            if (! static::isAllowedIp($ip, $allowPrivate)) {
                return null;
            }
        }

        return $resolved;
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
