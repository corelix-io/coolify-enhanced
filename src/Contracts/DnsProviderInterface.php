<?php

namespace CorelixIo\Platform\Contracts;

use CorelixIo\Platform\Models\CloudflareTunnel;
use CorelixIo\Platform\Models\DnsProvider;
use CorelixIo\Platform\Models\Domain;
use CorelixIo\Platform\Models\ManagedHostname;
use Illuminate\Support\Collection;
use App\Models\Server;

/**
 * Orchestrator abstraction for DNS / ingress providers (K8s/provider-agnostic, mirrors
 * the cluster driver abstraction). The Cloudflare Tunnel driver is the first implementation.
 *
 * Two distinct provider surfaces are intentionally separated (findings §6.5):
 *   - Zone DNS records (Zone:DNS:Edit)        → wildcard CNAME + TCP A/AAAA
 *   - Tunnel ingress config (Tunnel:Edit)     → HTTP routing, full-config replace + catch-all
 *
 * Every method returns a structured array{success: bool, error: ?string, ...} unless noted.
 */
interface DnsProviderInterface
{
    public function setProvider(DnsProvider $provider): self;

    // --- connectivity / capability ---

    /** @return array{success: bool, error: ?string, scopes_ok: array<string,bool>} */
    public function testConnection(): array;

    /** @return array{tunnel_ingress: bool, dns_records: bool, tcp_records: bool, access_policies: bool} */
    public function capabilities(): array;

    // --- Zone DNS records (CNAME wildcard + TCP A/AAAA) ---

    /** @return array{success: bool, error: ?string, record_id: ?string} */
    public function upsertDnsRecord(Domain $domain, string $name, string $type, string $content, bool $proxied): array;

    /** @return array{success: bool, error: ?string} */
    public function removeDnsRecord(Domain $domain, string $recordId): array;

    public function listDnsRecords(Domain $domain): Collection;

    // --- Tunnel ingress (full-config replace, catch-all last) ---

    /** @return array{success: bool, error: ?string} */
    public function ensureDomainRouting(Domain $domain): array;

    /** @return array{success: bool, error: ?string} */
    public function rebuildTunnelConfig(CloudflareTunnel $tunnel): array;

    /** @return array{success: bool, error: ?string} */
    public function upsertHostname(ManagedHostname $hostname): array;

    /** @return array{success: bool, error: ?string} */
    public function removeHostname(ManagedHostname $hostname): array;

    public function listHostnames(CloudflareTunnel $tunnel): Collection;

    // --- managed cloudflared lifecycle ---

    public function ensureTunnel(Domain $domain): CloudflareTunnel;

    /** @return array{success: bool, error: ?string} */
    public function deployDaemon(CloudflareTunnel $tunnel, Server $server): array;

    public function daemonStatus(CloudflareTunnel $tunnel): string;

    // --- reconcile / drift ---

    /** @return array{success: bool, error: ?string} */
    public function reconcile(ManagedHostname $hostname): array;

    public function detectDrift(CloudflareTunnel $tunnel): Collection;
}
