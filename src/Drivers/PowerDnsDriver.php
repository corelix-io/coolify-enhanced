<?php

namespace CorelixIo\Platform\Drivers;

use App\Models\Server;
use CorelixIo\Platform\Contracts\DnsProviderInterface;
use CorelixIo\Platform\Models\CloudflareTunnel;
use CorelixIo\Platform\Models\DnsProvider;
use CorelixIo\Platform\Models\Domain;
use CorelixIo\Platform\Models\ManagedHostname;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * PowerDNS driver SCAFFOLD (Wave 7, T7.4) — the second DnsProviderInterface
 * implementation, proving the abstraction holds beyond Cloudflare without any
 * UI or business-logic change.
 *
 * Status: conformance scaffold, NOT yet selectable. DnsProvider::TYPES intentionally
 * does NOT include 'powerdns', so neither the UI nor the REST API can create one yet;
 * only the factory mapping + this class exist. Promoting it to a real sovereign
 * driver (PRD §extended scope) means: implement reconcile() for wildcard A records,
 * add the type to DnsProvider::TYPES, and ship a feature flag if pro-gated.
 *
 * Surface mapping vs the interface's two provider surfaces:
 *   - Zone DNS records  → PowerDNS Authoritative API v1 RRsets (implemented)
 *   - Tunnel ingress    → no equivalent; PowerDNS is DNS-only. All tunnel/daemon
 *                         methods return structured not-supported results so callers
 *                         can branch on capabilities() instead of method existence.
 *
 * Credentials: {api_url, api_key, server_id (default "localhost")}.
 */
class PowerDnsDriver implements DnsProviderInterface
{
    protected DnsProvider $provider;

    public function setProvider(DnsProvider $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    // --- connectivity / capability ------------------------------------------

    /**
     * @return array{success: bool, error: ?string, scopes_ok: array<string,bool>}
     */
    public function testConnection(): array
    {
        $scopes = ['token' => false, 'tunnel' => false];

        if ($this->apiUrl() === '' || $this->apiKey() === '') {
            return ['success' => false, 'error' => 'PowerDNS api_url and api_key are required.', 'scopes_ok' => $scopes];
        }

        if (! self::isAllowedApiUrl($this->apiUrl())) {
            return ['success' => false, 'error' => 'PowerDNS api_url must be a public http(s) endpoint (private/reserved hosts are blocked).', 'scopes_ok' => $scopes];
        }

        try {
            $res = $this->request()->get($this->endpoint(''));
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Network error: '.$e->getMessage(), 'scopes_ok' => $scopes];
        }

        if (in_array($res->status(), [401, 403], true)) {
            return ['success' => false, 'error' => 'Invalid or unauthorized PowerDNS API key.', 'scopes_ok' => $scopes];
        }

        if (! $res->successful()) {
            return ['success' => false, 'error' => "PowerDNS API error (HTTP {$res->status()}).", 'scopes_ok' => $scopes];
        }

        $scopes['token'] = true; // 'tunnel' scope does not exist for a DNS-only provider

        return ['success' => true, 'error' => null, 'scopes_ok' => $scopes];
    }

    /**
     * @return array{tunnel_ingress: bool, dns_records: bool, tcp_records: bool, access_policies: bool}
     */
    public function capabilities(): array
    {
        return [
            'tunnel_ingress' => false,  // DNS-only — no managed ingress surface
            'dns_records' => true,      // RRset CRUD via the Authoritative API
            'tcp_records' => true,      // A/AAAA records are native here
            'access_policies' => false, // no Zero Trust equivalent
        ];
    }

    // --- Zone DNS records (PowerDNS RRsets) ----------------------------------

    /**
     * @return array{success: bool, error: ?string, record_id: ?string}
     */
    public function upsertDnsRecord(Domain $domain, string $name, string $type, string $content, bool $proxied): array
    {
        // $proxied has no PowerDNS meaning (no proxy layer) — accepted and ignored.
        $zone = $this->resolveZone($domain->base_domain);
        if ($zone === null) {
            return ['success' => false, 'error' => "Could not resolve PowerDNS zone for {$domain->base_domain}.", 'record_id' => null];
        }

        $fqdn = rtrim(strtolower($name), '.').'.';
        $payload = [
            'rrsets' => [[
                'name' => $fqdn,
                'type' => strtoupper($type),
                'ttl' => 300,
                'changetype' => 'REPLACE',
                'records' => [[
                    'content' => strtoupper($type) === 'CNAME' ? rtrim($content, '.').'.' : $content,
                    'disabled' => false,
                ]],
            ]],
        ];

        try {
            $res = $this->request()->patch($this->endpoint("/zones/{$zone}"), $payload);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Network error: '.$e->getMessage(), 'record_id' => null];
        }

        if ($res->successful()) {
            // PowerDNS RRsets have no per-record id — the (name, type) pair IS the identity.
            return ['success' => true, 'error' => null, 'record_id' => $fqdn.'|'.strtoupper($type)];
        }

        return ['success' => false, 'error' => $this->firstError($res) ?? "RRset replace failed (HTTP {$res->status()}).", 'record_id' => null];
    }

    /**
     * @return array{success: bool, error: ?string}
     */
    public function removeDnsRecord(Domain $domain, string $recordId): array
    {
        $zone = $this->resolveZone($domain->base_domain);
        if ($zone === null) {
            return ['success' => false, 'error' => "Could not resolve PowerDNS zone for {$domain->base_domain}."];
        }

        // record_id format produced by upsertDnsRecord: "<fqdn>.|<TYPE>"
        [$name, $type] = array_pad(explode('|', $recordId, 2), 2, '');
        if ($name === '' || $type === '') {
            return ['success' => false, 'error' => "Unrecognized PowerDNS record id \"{$recordId}\" (expected \"fqdn|TYPE\")."];
        }

        try {
            $res = $this->request()->patch($this->endpoint("/zones/{$zone}"), [
                'rrsets' => [[
                    'name' => $name,
                    'type' => strtoupper($type),
                    'changetype' => 'DELETE',
                    'records' => [],
                ]],
            ]);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Network error: '.$e->getMessage()];
        }

        if ($res->successful() || $res->status() === 404) {
            return ['success' => true, 'error' => null];
        }

        return ['success' => false, 'error' => $this->firstError($res) ?? "RRset delete failed (HTTP {$res->status()})."];
    }

    public function listDnsRecords(Domain $domain): Collection
    {
        $zone = $this->resolveZone($domain->base_domain);
        if ($zone === null) {
            return collect();
        }

        try {
            $res = $this->request()->get($this->endpoint("/zones/{$zone}"));
        } catch (\Throwable) {
            return collect();
        }

        if (! $res->successful()) {
            return collect();
        }

        // Normalize RRsets to the {name, type, content} shape the CF driver returns.
        return collect(data_get($res->json(), 'rrsets', []))
            ->flatMap(fn ($rrset) => collect($rrset['records'] ?? [])->map(fn ($record) => [
                'name' => rtrim((string) ($rrset['name'] ?? ''), '.'),
                'type' => $rrset['type'] ?? '',
                'content' => $record['content'] ?? '',
            ]))
            ->values();
    }

    // --- Tunnel ingress: no PowerDNS equivalent ------------------------------

    /** @return array{success: bool, error: ?string} */
    public function ensureDomainRouting(Domain $domain): array
    {
        return $this->unsupported('Domain routing (tunnel ingress)');
    }

    /** @return array{success: bool, error: ?string} */
    public function rebuildTunnelConfig(CloudflareTunnel $tunnel): array
    {
        return $this->unsupported('Tunnel config rebuild');
    }

    /** @return array{success: bool, error: ?string} */
    public function upsertHostname(ManagedHostname $hostname): array
    {
        return $this->unsupported('Per-hostname ingress');
    }

    /** @return array{success: bool, error: ?string} */
    public function removeHostname(ManagedHostname $hostname): array
    {
        return $this->unsupported('Per-hostname ingress');
    }

    public function listHostnames(CloudflareTunnel $tunnel): Collection
    {
        return collect();
    }

    // --- managed daemon lifecycle: no PowerDNS equivalent ---------------------

    public function ensureTunnel(Domain $domain): CloudflareTunnel
    {
        throw new \RuntimeException('PowerDNS has no tunnel concept — check capabilities()[tunnel_ingress] before calling.');
    }

    /** @return array{success: bool, error: ?string} */
    public function deployDaemon(CloudflareTunnel $tunnel, Server $server): array
    {
        return $this->unsupported('Managed daemon');
    }

    public function daemonStatus(CloudflareTunnel $tunnel): string
    {
        return 'unsupported';
    }

    // --- reconcile / drift ----------------------------------------------------

    /**
     * Scaffold: hostname reconcile is not wired yet. A full PowerDNS driver would
     * upsert wildcard/host A-AAAA records pointing at the resource's server here.
     *
     * @return array{success: bool, error: ?string}
     */
    public function reconcile(ManagedHostname $hostname): array
    {
        return $this->unsupported('Hostname reconcile (scaffold — record CRUD is implemented, reconcile wiring is not)');
    }

    public function detectDrift(CloudflareTunnel $tunnel): Collection
    {
        return collect(); // no tunnel surface to drift against (zone drift: future work)
    }

    // --- internals -------------------------------------------------------------

    protected function unsupported(string $operation): array
    {
        return ['success' => false, 'error' => "{$operation} is not supported by the PowerDNS driver (DNS-only provider)."];
    }

    protected function request(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders(['X-API-Key' => $this->apiKey()])
            ->acceptJson()
            ->timeout(15)
            ->retry(2, 200, throw: false);
    }

    /** Base endpoint for the configured PowerDNS server, e.g. ".../api/v1/servers/localhost". */
    protected function endpoint(string $path): string
    {
        return rtrim($this->apiUrl(), '/').'/api/v1/servers/'.$this->serverId().$path;
    }

    /**
     * PowerDNS zone ids are the canonical zone name with trailing dot. Try the base
     * domain, then progressively shorter suffixes (apps.example.com → example.com).
     */
    protected function resolveZone(string $baseDomain): ?string
    {
        $labels = explode('.', strtolower(trim($baseDomain, '.')));

        for ($i = 0; $i < count($labels) - 1; $i++) {
            $candidate = implode('.', array_slice($labels, $i)).'.';
            try {
                $res = $this->request()->get($this->endpoint('/zones/'.$candidate));
            } catch (\Throwable) {
                return null;
            }
            if ($res->successful()) {
                return $candidate;
            }
        }

        return null;
    }

    protected function apiUrl(): string
    {
        return rtrim(trim((string) (($this->provider->credentials ?? [])['api_url'] ?? '')), '/');
    }

    /**
     * SSRF guard for credential-controlled api_url (required before TYPE_POWERDNS is promoted).
     */
    public static function isAllowedApiUrl(string $url): bool
    {
        // Delegate to the centralized SSRF guard (single source of truth). DNS provider
        // APIs are public services, so private and reserved ranges are all rejected.
        return \CorelixIo\Platform\Support\SsrfGuard::isAllowedUrl($url);
    }

    protected function apiKey(): string
    {
        return (string) (($this->provider->credentials ?? [])['api_key'] ?? '');
    }

    protected function serverId(): string
    {
        $id = trim((string) (($this->provider->credentials ?? [])['server_id'] ?? ''));

        return $id !== '' ? $id : 'localhost';
    }

    protected function firstError(\Illuminate\Http\Client\Response $res): ?string
    {
        return data_get($res->json(), 'error');
    }
}
