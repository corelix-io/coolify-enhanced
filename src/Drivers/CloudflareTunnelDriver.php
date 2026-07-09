<?php

namespace CorelixIo\Platform\Drivers;

use CorelixIo\Platform\Contracts\DnsProviderInterface;
use CorelixIo\Platform\Models\CloudflareTunnel;
use CorelixIo\Platform\Models\DnsProvider;
use CorelixIo\Platform\Models\Domain;
use CorelixIo\Platform\Models\ManagedHostname;
use CorelixIo\Platform\Services\CloudflareApiClient;
use CorelixIo\Platform\Support\Feature;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use App\Models\Server;

/**
 * Cloudflare Tunnel driver.
 *
 * Implements connectivity (testConnection), capability reporting, Zone DNS record CRUD,
 * tunnel-config desired-state rebuild (full PUT replace, catch-all last, serialized per
 * tunnel), the managed cloudflared daemon lifecycle (deploy/inspect via SSH), hostname
 * reconcile for all routing modes: wildcard (zero provider calls), per-hostname/hybrid
 * (pro — per-host CNAME + explicit ingress), TCP grey-cloud A/AAAA records (pro), and
 * drift detection (pro — desired vs live ingress/DNS state; tunnel-config orphans are
 * reported but NEVER deleted; zone DNS orphans are intentionally not reported because
 * the zone is shared with user-managed records).
 */
class CloudflareTunnelDriver implements DnsProviderInterface
{
    /**
     * Default ingress service when a Domain has no explicit default_ingress_target.
     * cloudflared runs on the coolify network, so the Traefik proxy is reachable by container name.
     */
    public const DEFAULT_INGRESS_TARGET = 'http://coolify-proxy:80';

    protected DnsProvider $provider;

    protected ?CloudflareApiClient $client = null;

    public function setProvider(DnsProvider $provider): self
    {
        $this->provider = $provider;
        $this->client = null;

        return $this;
    }

    protected function client(): CloudflareApiClient
    {
        return $this->client ??= new CloudflareApiClient($this->provider->getApiToken());
    }

    /**
     * Validate token + account-level tunnel access in one round-trip pair.
     *
     * @return array{success: bool, error: ?string, scopes_ok: array<string,bool>}
     */
    public function testConnection(): array
    {
        $scopes = ['token' => false, 'tunnel' => false];

        if ($this->provider->getApiToken() === '') {
            return ['success' => false, 'error' => 'API token is required.', 'scopes_ok' => $scopes];
        }

        $verify = $this->client()->verifyToken();
        $scopes['token'] = $verify['success'];
        if (! $verify['success']) {
            return ['success' => false, 'error' => $verify['error'], 'scopes_ok' => $scopes];
        }

        $tunnels = $this->client()->listTunnels($this->provider->getAccountId());
        $scopes['tunnel'] = $tunnels['success'];
        if (! $tunnels['success']) {
            return ['success' => false, 'error' => $tunnels['error'], 'scopes_ok' => $scopes];
        }

        return ['success' => true, 'error' => null, 'scopes_ok' => $scopes];
    }

    /**
     * @return array{tunnel_ingress: bool, dns_records: bool, tcp_records: bool, access_policies: bool}
     */
    public function capabilities(): array
    {
        $capabilities = [
            'tunnel_ingress' => true,  // HTTP routing via tunnel config
            'dns_records' => true,     // CNAME wildcard
            'tcp_records' => true,     // grey-cloud A/AAAA to server IP (pro-gated at feature level)
            'access_policies' => false, // PRO: DNS_ACCESS_POLICIES (default off)
        ];


        return $capabilities;
    }

    // --- Zone DNS records --------------------------------------------------

    /**
     * @return array{success: bool, error: ?string, record_id: ?string}
     */
    public function upsertDnsRecord(Domain $domain, string $name, string $type, string $content, bool $proxied): array
    {
        $zoneId = $this->client()->resolveZoneId($domain->base_domain);
        if ($zoneId === null) {
            return ['success' => false, 'error' => "Could not resolve Cloudflare zone for {$domain->base_domain}.", 'record_id' => null];
        }

        $name = rtrim(strtolower($name), '.');
        $payload = ['type' => $type, 'name' => $name, 'content' => $content, 'proxied' => $proxied, 'ttl' => 1];

        // Idempotent upsert: find existing record by name+type, else create (Cloudflare has no native upsert).
        $existingId = null;
        $existingContent = null;
        $list = $this->client()->get("/zones/{$zoneId}/dns_records", ['name' => $name, 'type' => $type, 'per_page' => 1]);
        if ($list->successful()) {
            $existingId = data_get($list->json(), 'result.0.id');
            $existingContent = data_get($list->json(), 'result.0.content');
        }

        if ($existingId && filled($existingContent)) {
            $normalizedExisting = rtrim(strtolower(trim((string) $existingContent)), '.');
            $normalizedTarget = rtrim(strtolower(trim($content)), '.');
            if ($normalizedExisting !== $normalizedTarget && ! $this->isTunnelCnameTarget($normalizedExisting)) {
                return [
                    'success' => false,
                    'error' => "Refusing to overwrite existing {$type} record for {$name} (current target: {$existingContent}). "
                        .'Remove or repoint the record at Cloudflare first, or adopt a tunnel target.',
                    'record_id' => null,
                ];
            }
        }

        $res = $existingId
            ? $this->client()->put('/zones/'.rawurlencode($zoneId).'/dns_records/'.rawurlencode($existingId), $payload)
            : $this->client()->post('/zones/'.rawurlencode($zoneId).'/dns_records', $payload);

        if ($res->successful()) {
            return ['success' => true, 'error' => null, 'record_id' => data_get($res->json(), 'result.id', $existingId)];
        }

        return ['success' => false, 'error' => data_get($res->json(), 'errors.0.message') ?? "DNS upsert failed (HTTP {$res->status()}).", 'record_id' => null];
    }

    /**
     * @return array{success: bool, error: ?string}
     */
    public function removeDnsRecord(Domain $domain, string $recordId): array
    {
        $zoneId = $this->client()->resolveZoneId($domain->base_domain);
        if ($zoneId === null) {
            return ['success' => false, 'error' => "Could not resolve Cloudflare zone for {$domain->base_domain}."];
        }

        $res = $this->client()->delete('/zones/'.rawurlencode($zoneId).'/dns_records/'.rawurlencode($recordId));
        if ($res->successful() || $res->status() === 404) {
            return ['success' => true, 'error' => null];
        }

        return ['success' => false, 'error' => data_get($res->json(), 'errors.0.message') ?? "DNS delete failed (HTTP {$res->status()})."];
    }

    /**
     * @return array{success: bool, records: Collection, error: ?string}
     */
    public function listDnsRecordsResult(Domain $domain): array
    {
        $zoneId = $this->client()->resolveZoneId($domain->base_domain);
        if ($zoneId === null) {
            return [
                'success' => false,
                'records' => collect(),
                'error' => "Could not resolve Cloudflare zone for {$domain->base_domain}.",
            ];
        }

        $records = collect();
        $page = 1;

        do {
            $res = $this->client()->get("/zones/{$zoneId}/dns_records", ['per_page' => 100, 'page' => $page]);
            if (! $res->successful()) {
                return [
                    'success' => false,
                    'records' => collect(),
                    'error' => data_get($res->json(), 'errors.0.message')
                        ?? "DNS list failed (HTTP {$res->status()}).",
                ];
            }

            $batch = collect(data_get($res->json(), 'result', []));
            $records = $records->concat($batch);
            $totalPages = (int) data_get($res->json(), 'result_info.total_pages', 1);
            $page++;
        } while ($page <= $totalPages);

        return ['success' => true, 'records' => $records, 'error' => null];
    }

    public function listDnsRecords(Domain $domain): Collection
    {
        $result = $this->listDnsRecordsResult($domain);

        return $result['success'] ? $result['records'] : collect();
    }

    // --- Tunnel ingress (full-config replace) -------------------------------

    /**
     * Ensure the domain's wildcard routing exists: wildcard CNAME → tunnel CNAME target (proxied),
     * plus the wildcard ingress rule via a full tunnel-config rebuild.
     *
     * @return array{success: bool, error: ?string}
     */
    public function ensureDomainRouting(Domain $domain): array
    {
        $tunnel = $domain->tunnel;
        if (! $tunnel || empty($tunnel->cf_tunnel_id)) {
            return ['success' => false, 'error' => 'Domain has no provisioned tunnel — run ensureTunnel first.'];
        }

        $target = $tunnel->resolveCnameTarget();
        if (! $target) {
            return ['success' => false, 'error' => 'Tunnel has no CNAME target.'];
        }

        // per_hostname domains have NO wildcard CNAME — each host gets its own proxied
        // CNAME via upsertHostname(). Wildcard and hybrid domains get the wildcard pair.
        if ($domain->routing_mode !== Domain::ROUTING_PER_HOSTNAME) {
            // Wildcard CNAME (proxied / orange-cloud) so every subdomain enters the Cloudflare edge.
            $cname = $this->upsertDnsRecord($domain, '*.'.$domain->base_domain, 'CNAME', $target, true);
            if (! $cname['success']) {
                return ['success' => false, 'error' => 'Wildcard CNAME failed: '.$cname['error']];
            }

            // The wildcard does NOT cover the bare base domain itself; resources may use it directly
            // (ownsHostname matches the apex). Cloudflare flattens apex CNAMEs, so this is safe.
            $apex = $this->upsertDnsRecord($domain, $domain->base_domain, 'CNAME', $target, true);
            if (! $apex['success']) {
                return ['success' => false, 'error' => 'Apex CNAME failed: '.$apex['error']];
            }
        }

        return $this->rebuildTunnelConfig($tunnel);
    }

    /**
     * Desired-state rebuild of the tunnel's ingress config from ALL active domains bound to it.
     * PUT is full replacement; the catch-all rule is always re-emitted last (findings §6.5).
     *
     * Serialized per tunnel via an atomic cache lock: concurrent DnsReconcileJob runs for
     * different resources on the same tunnel would otherwise interleave reads of the
     * desired host set with full-replace PUTs and stomp each other (T4.3).
     *
     * @return array{success: bool, error: ?string}
     */
    public function rebuildTunnelConfig(CloudflareTunnel $tunnel): array
    {
        if (empty($tunnel->cf_tunnel_id)) {
            return ['success' => false, 'error' => 'Tunnel has no Cloudflare tunnel id.'];
        }

        $lock = Cache::lock(self::tunnelLockKey($tunnel), 60);

        try {
            return $lock->block(20, fn () => $this->doRebuildTunnelConfig($tunnel));
        } catch (LockTimeoutException) {
            return ['success' => false, 'error' => 'Timed out waiting for the tunnel config lock — another rebuild is in progress.'];
        }
    }

    /**
     * Cache lock key serializing config rebuilds for one tunnel.
     */
    public static function tunnelLockKey(CloudflareTunnel $tunnel): string
    {
        return 'corelix:dns:tunnel-rebuild:'.$tunnel->id;
    }

    /**
     * Desired ingress rules (WITHOUT the trailing catch-all) computed from all active
     * domains bound to the tunnel. Single source of truth shared by the config rebuild
     * and drift detection so the two can never diverge.
     *
     * @return array<int, array{hostname: string, service: string}>
     */
    public function desiredIngressRules(CloudflareTunnel $tunnel): array
    {
        $ingress = [];
        $domains = $tunnel->domains()->where('is_active', true)->orderBy('id')->get();

        foreach ($domains as $domain) {
            $service = $domain->default_ingress_target ?: self::DEFAULT_INGRESS_TARGET;

            // Explicit per-hostname rules first — ingress evaluates top-to-bottom, so they
            // must precede the wildcard rule to win. (Per-hostname mode is pro / Wave 4;
            // in wildcard mode the query below is simply empty.)
            if (in_array($domain->routing_mode, [Domain::ROUTING_PER_HOSTNAME, Domain::ROUTING_HYBRID], true)) {
                $hostRules = $domain->managedHostnames()
                    ->where('record_kind', ManagedHostname::KIND_HTTP_TUNNEL)
                    ->orderBy('hostname')
                    ->pluck('hostname')
                    ->map(fn ($h) => ['hostname' => $h, 'service' => $service])
                    ->all();
                array_push($ingress, ...$hostRules);
            }

            if (in_array($domain->routing_mode, [Domain::ROUTING_WILDCARD, Domain::ROUTING_HYBRID], true)) {
                // Tunnel ingress wildcards do not match the bare base domain — emit both.
                $ingress[] = ['hostname' => $domain->base_domain, 'service' => $service];
                $ingress[] = ['hostname' => '*.'.$domain->base_domain, 'service' => $service];
            }
        }

        return $ingress;
    }

    /**
     * @return array{success: bool, error: ?string}
     */
    protected function doRebuildTunnelConfig(CloudflareTunnel $tunnel): array
    {
        $ingress = $this->desiredIngressRules($tunnel);

        // Mandatory final catch-all — PUT is rejected/meaningless without it.
        $ingress[] = ['service' => 'http_status:404'];

        $result = $this->client()->putTunnelConfig(
            $this->provider->getAccountId(),
            $tunnel->cf_tunnel_id,
            ['ingress' => $ingress]
        );

        if ($result['success']) {
            $tunnel->update(['config_synced_at' => now(), 'status' => CloudflareTunnel::STATUS_ACTIVE]);
        } else {
            $tunnel->update(['status' => CloudflareTunnel::STATUS_ERROR]);
        }

        return $result;
    }

    /**
     * Per-hostname/hybrid (pro): ensure the host's DNS coverage + explicit ingress rule.
     *
     * Hybrid domains are DNS-covered by the wildcard CNAME, so only the ingress rule is
     * (re)emitted. per_hostname domains additionally need a per-host proxied CNAME whose
     * record id is persisted for later cleanup.
     *
     * @return array{success: bool, error: ?string, pending?: bool}
     */
    public function upsertHostname(ManagedHostname $hostname): array
    {
        $domain = $hostname->domain;
        $tunnel = $domain?->tunnel;
        if (! $tunnel) {
            return ['success' => false, 'error' => 'Hostname domain has no tunnel.'];
        }
        if (empty($tunnel->cf_tunnel_id)) {
            return ['success' => false, 'pending' => true, 'error' => 'Domain tunnel is not provisioned yet — provisioning pending.'];
        }

        if ($domain->routing_mode === Domain::ROUTING_PER_HOSTNAME) {
            $target = $tunnel->resolveCnameTarget();
            if (! $target) {
                return ['success' => false, 'error' => 'Tunnel has no CNAME target.'];
            }

            $record = $this->upsertDnsRecord($domain, $hostname->hostname, 'CNAME', $target, true);
            if (! $record['success']) {
                return ['success' => false, 'error' => 'Hostname CNAME failed: '.$record['error']];
            }
            if ($record['record_id'] && $record['record_id'] !== $hostname->provider_record_id) {
                $hostname->forceFill(['provider_record_id' => $record['record_id']])->save();
            }
        }

        return $this->rebuildTunnelConfig($tunnel);
    }

    /**
     * Remove a host's per-host DNS record (per_hostname mode) and re-emit the tunnel
     * config without it. Call AFTER deleting the ManagedHostname row — the in-memory
     * model still carries its attributes/relations, and the rebuild (which reads the
     * desired host set from the DB) then no longer includes it.
     *
     * @return array{success: bool, error: ?string}
     */
    public function removeHostname(ManagedHostname $hostname): array
    {
        $domain = $hostname->domain;
        $tunnel = $domain?->tunnel;
        if (! $tunnel) {
            return ['success' => false, 'error' => 'Hostname domain has no tunnel.'];
        }

        if ($domain->routing_mode === Domain::ROUTING_PER_HOSTNAME && filled($hostname->provider_record_id)) {
            $record = $this->removeDnsRecord($domain, $hostname->provider_record_id);
            if (! $record['success']) {
                return ['success' => false, 'error' => 'Hostname CNAME removal failed: '.$record['error']];
            }
        }

        return $this->rebuildTunnelConfig($tunnel);
    }

    public function listHostnames(CloudflareTunnel $tunnel): Collection
    {
        if (empty($tunnel->cf_tunnel_id)) {
            return collect();
        }

        $config = $this->client()->getTunnelConfig($this->provider->getAccountId(), $tunnel->cf_tunnel_id);
        if (! $config['success']) {
            return collect();
        }

        return collect(data_get($config, 'config.ingress', []))
            ->filter(fn ($rule) => ! empty($rule['hostname']))
            ->values();
    }

    // --- managed cloudflared lifecycle --------------------------------------

    /**
     * Find-or-create the Cloudflare remote tunnel for a domain and persist the local record
     * (cf_tunnel_id, encrypted tunnel token, CNAME target).
     */
    public function ensureTunnel(Domain $domain): CloudflareTunnel
    {
        $accountId = $this->provider->getAccountId();

        $tunnel = $domain->tunnel
            ?? $this->provider->tunnels()->firstOrCreate(
                ['name' => 'corelix-'.$domain->base_domain],
                ['status' => CloudflareTunnel::STATUS_PENDING]
            );

        if (empty($tunnel->cf_tunnel_id)) {
            // Adopt an existing CF tunnel with our name, otherwise create one.
            $found = $this->client()->findTunnelByName($accountId, $tunnel->name);
            $cfTunnel = $found['tunnel'] ?? null;

            if (! $cfTunnel) {
                $created = $this->client()->createTunnel($accountId, $tunnel->name);
                if (! $created['success']) {
                    $tunnel->update(['status' => CloudflareTunnel::STATUS_ERROR]);
                    throw new \RuntimeException('Cloudflare tunnel create failed: '.$created['error']);
                }
                $cfTunnel = $created['tunnel'];
            }

            $token = $cfTunnel['token'] ?? null;
            if (! $token) {
                $tokenResult = $this->client()->getTunnelToken($accountId, $cfTunnel['id']);
                if (! $tokenResult['success']) {
                    $tunnel->update(['status' => CloudflareTunnel::STATUS_ERROR]);
                    throw new \RuntimeException('Cloudflare tunnel token fetch failed: '.$tokenResult['error']);
                }
                $token = $tokenResult['token'];
            }

            $tunnel->update([
                'cf_tunnel_id' => $cfTunnel['id'],
                'cname_target' => $cfTunnel['id'].'.cfargotunnel.com',
                'credentials' => array_merge($tunnel->credentials ?? [], ['tunnel_token' => $token]),
            ]);
        }

        if ($domain->cloudflare_tunnel_id !== $tunnel->id) {
            $domain->update(['cloudflare_tunnel_id' => $tunnel->id]);
        }

        return $tunnel->refresh();
    }

    /**
     * Deploy (or redeploy) the managed cloudflared container on a server via SSH.
     * The token is passed via env var; all interpolated values are escapeshellarg()ed.
     *
     * @return array{success: bool, error: ?string}
     */
    public function deployDaemon(CloudflareTunnel $tunnel, Server $server): array
    {
        if (! config('corelix-platform.dns_provider_management.manage_cloudflared', true)) {
            return ['success' => false, 'error' => 'Managed cloudflared is disabled (CORELIX_DNS_MANAGE_CLOUDFLARED=false).'];
        }

        $token = $tunnel->getTunnelToken();
        if ($token === '') {
            return ['success' => false, 'error' => 'Tunnel has no token — run ensureTunnel first.'];
        }

        $image = config('corelix-platform.dns_provider_management.cloudflared_image', 'cloudflare/cloudflared:latest');
        $containerName = $this->daemonContainerName($tunnel);
        $network = $server->isSwarm() ? 'coolify-overlay' : 'coolify';

        $envFilePath = '/tmp/corelix-tunnel-'.$tunnel->uuid.'.env';
        $envBase64 = base64_encode('TUNNEL_TOKEN='.$token."\n");

        $run = implode(' ', [
            'docker run -d',
            '--name '.escapeshellarg($containerName),
            '--restart unless-stopped',
            '--network '.escapeshellarg($network),
            '--env-file '.escapeshellarg($envFilePath),
            '--label corelix.managed=true',
            '--label '.escapeshellarg('corelix.tunnelId='.$tunnel->uuid),
            escapeshellarg($image),
            'tunnel --no-autoupdate run',
        ]);

        try {
            // Token via root-only temp env file — never on the command line or docker inspect env.
            instant_remote_process([
                'docker rm -f '.escapeshellarg($containerName).' 2>/dev/null || true',
                'echo '.escapeshellarg($envBase64).' | base64 -d > '.escapeshellarg($envFilePath),
                'chmod 600 '.escapeshellarg($envFilePath),
                $run,
                'rm -f '.escapeshellarg($envFilePath),
            ], $server);

            $status = $this->inspectDaemonContainer($tunnel, $server);
            $running = $status === 'running';

            $tunnel->update([
                'daemon_server_id' => $server->id,
                'daemon_status' => $running ? CloudflareTunnel::DAEMON_RUNNING : CloudflareTunnel::DAEMON_ERROR,
                'daemon_error' => $running ? null : "Container state after start: {$status}",
            ]);

            return $running
                ? ['success' => true, 'error' => null]
                : ['success' => false, 'error' => "cloudflared container is not running (state: {$status})."];
        } catch (\Throwable $e) {
            $tunnel->update([
                'daemon_status' => CloudflareTunnel::DAEMON_ERROR,
                'daemon_error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => 'Daemon deploy failed: '.$e->getMessage()];
        }
    }

    /**
     * Live daemon status: inspect the container on the daemon server, update the record.
     */
    public function daemonStatus(CloudflareTunnel $tunnel): string
    {
        $server = $tunnel->daemonServer;
        if (! $server) {
            return $tunnel->daemon_status ?? CloudflareTunnel::DAEMON_PENDING;
        }

        try {
            $state = $this->inspectDaemonContainer($tunnel, $server);
            $status = match ($state) {
                'running' => CloudflareTunnel::DAEMON_RUNNING,
                'exited', 'dead', 'created' => CloudflareTunnel::DAEMON_STOPPED,
                null => CloudflareTunnel::DAEMON_STOPPED,
                default => CloudflareTunnel::DAEMON_ERROR,
            };
            if ($status !== $tunnel->daemon_status) {
                $tunnel->update(['daemon_status' => $status]);
            }

            return $status;
        } catch (\Throwable) {
            return $tunnel->daemon_status ?? CloudflareTunnel::DAEMON_PENDING;
        }
    }

    protected function daemonContainerName(CloudflareTunnel $tunnel): string
    {
        return 'corelix-cloudflared-'.$tunnel->uuid;
    }

    protected function inspectDaemonContainer(CloudflareTunnel $tunnel, Server $server): ?string
    {
        $name = $this->daemonContainerName($tunnel);
        $output = instant_remote_process([
            'docker inspect --format \'{{.State.Status}}\' '.escapeshellarg($name).' 2>/dev/null || true',
        ], $server);

        $state = trim((string) $output);

        return $state !== '' ? $state : null;
    }

    /**
     * Stop and remove the managed cloudflared container on the daemon server.
     *
     * @return array{success: bool, error: ?string}
     */
    public function stopDaemon(CloudflareTunnel $tunnel, Server $server): array
    {
        $containerName = $this->daemonContainerName($tunnel);

        try {
            instant_remote_process([
                'docker rm -f '.escapeshellarg($containerName).' 2>/dev/null || true',
            ], $server);

            $tunnel->update([
                'daemon_status' => CloudflareTunnel::DAEMON_STOPPED,
                'daemon_error' => null,
            ]);

            return ['success' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Daemon stop failed: '.$e->getMessage()];
        }
    }

    protected function isTunnelCnameTarget(string $content): bool
    {
        return str_ends_with($content, '.cfargotunnel.com');
    }

    /**
     * Reconcile a single managed hostname against the provider.
     *
     * Wildcard happy path: the hostname is already covered by the domain's wildcard CNAME +
     * wildcard ingress rule — ZERO provider calls needed (PRD goal). Per-hostname/hybrid
     * domains delegate to upsertHostname (config rebuild).
     *
     * @return array{success: bool, error: ?string}
     */
    public function reconcile(ManagedHostname $hostname): array
    {
        $domain = $hostname->domain;
        if (! $domain) {
            return ['success' => false, 'error' => 'Hostname has no owning domain.'];
        }

        if (! $domain->is_active) {
            return [
                'success' => false,
                'pending' => true,
                'error' => 'Domain is deactivated — hostname pending until the domain is reactivated or unpinned.',
            ];
        }

        if ($hostname->record_kind === ManagedHostname::KIND_HTTP_TUNNEL
            && $domain->routing_mode === Domain::ROUTING_WILDCARD) {
            $tunnel = $domain->tunnel;
            if (! $tunnel || $tunnel->status !== CloudflareTunnel::STATUS_ACTIVE) {
                // Not an error — the domain simply isn't provisioned yet. Stay pending;
                // DnsDomainProvisionJob re-dispatches reconciles once routing is live.
                return ['success' => false, 'pending' => true, 'error' => 'Domain tunnel is not active yet — provisioning pending.'];
            }

            return ['success' => true, 'error' => null];
        }



        return [
            'success' => false,
            'error' => "Routing for record_kind={$hostname->record_kind} on a "
                ."{$domain->routing_mode} domain requires a Pro feature "
                .'(per-hostname routing / TCP records) that is not enabled.',
        ];
    }


    public function detectDrift(CloudflareTunnel $tunnel): Collection
    {

        return collect();
    }

}
