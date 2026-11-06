<?php

namespace CorelixIo\Platform\Services;

use CorelixIo\Platform\Models\Domain;
use CorelixIo\Platform\Models\ManagedHostname;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Resolves which managed Domain owns a resource's hostnames and keeps the
 * ManagedHostname desired state in sync.
 *
 * Ownership resolution (findings §6.2):
 *   1. explicit per-resource override (existing row with binding_source=override) wins
 *   2. longest-suffix match across the team's active domains
 *   3. tie-break: is_default first, then lowest id
 *
 * Hostnames that match no managed domain are simply not tracked (unmanaged).
 */
class DnsResolutionService
{
    // ------------------------------------------------------------------
    // Hostname extraction
    // ------------------------------------------------------------------

    /**
     * Extract concrete hostnames a resource exposes over HTTP.
     *
     * Applications: `fqdn` (comma-separated URLs) + `docker_compose_domains` JSON.
     * ServiceApplications: `fqdn` (comma-separated URLs).
     *
     * @return Collection<int, string> lowercase bare hostnames, deduplicated
     */
    public static function extractHostnames($resource): Collection
    {
        $raw = collect();

        if (isset($resource->fqdn) && filled($resource->fqdn)) {
            $raw = $raw->merge(explode(',', $resource->fqdn));
        }

        // Docker Compose applications keep per-service domains in JSON:
        // {"service": {"domain": "https://a.example.com,https://b.example.com"}}
        if (isset($resource->docker_compose_domains) && filled($resource->docker_compose_domains)) {
            $decoded = json_decode($resource->docker_compose_domains, true);
            foreach (is_array($decoded) ? $decoded : [] as $entry) {
                $domain = is_array($entry) ? ($entry['domain'] ?? null) : null;
                if (filled($domain)) {
                    $raw = $raw->merge(explode(',', $domain));
                }
            }
        }

        return $raw
            ->map(fn ($url) => static::hostnameFromUrl((string) $url))
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * Bare hostname from a Coolify FQDN entry (URL with optional scheme/port/path).
     */
    public static function hostnameFromUrl(string $url): ?string
    {
        $url = strtolower(trim($url));
        if ($url === '') {
            return null;
        }

        if (! preg_match('#^[a-z][a-z0-9+.-]*://#', $url)) {
            $url = 'http://'.$url;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return filled($host) ? rtrim($host, '.') : null;
    }

    // ------------------------------------------------------------------
    // Ownership resolution
    // ------------------------------------------------------------------

    /**
     * Longest-suffix match: the team's active domain with the longest base_domain
     * that owns the hostname. Tie-break: is_default desc, then id asc.
     */
    public static function resolveDomain(string $hostname, int $teamId): ?Domain
    {
        $hostname = rtrim(strtolower(trim($hostname)), '.');
        if ($hostname === '') {
            return null;
        }

        return Domain::ownedByTeam($teamId)
            ->active()
            ->get()
            ->filter(fn (Domain $domain) => $domain->ownsHostname($hostname))
            ->sortBy([
                fn (Domain $a, Domain $b) => strlen($b->base_domain) <=> strlen($a->base_domain),
                fn (Domain $a, Domain $b) => ($b->is_default <=> $a->is_default),
                fn (Domain $a, Domain $b) => ($a->id <=> $b->id),
            ])
            ->first();
    }

    /**
     * Resolve which managed Domain should host FQDNs generated for an environment.
     *
     * Precedence (plan.md / findings §6.3):
     *   environment_id binding → environment_role binding → domains.is_default → first active.
     *
     * The binding lookups are PRO (DNS_ENV_BINDINGS); free builds fall straight
     * through to the team default domain.
     */
    public static function resolveDomainForEnvironment($environment, ?int $teamId = null): ?Domain
    {
        $teamId ??= $environment?->project?->team_id;
        if ($teamId === null) {
            return null;
        }


        return Domain::ownedByTeam($teamId)->active()->where('is_default', true)->orderBy('id')->first()
            ?? Domain::ownedByTeam($teamId)->active()->orderBy('id')->first();
    }

    /**
     * Resolve the owning team id for a supported resource (Application, ServiceApplication,
     * Service, standalone databases) by walking environment → project → team.
     */
    public static function teamIdForResource($resource): ?int
    {
        try {
            // ServiceApplication / ServiceDatabase hang off a Service.
            $base = method_exists($resource, 'service') && isset($resource->service)
                ? $resource->service
                : $resource;

            return $base->environment?->project?->team_id;
        } catch (\Throwable $e) {
            Log::warning('DnsResolutionService: could not resolve team for resource', [
                'resource' => get_class($resource).'#'.($resource->id ?? '?'),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    // ------------------------------------------------------------------
    // Desired-state sync of ManagedHostname rows
    // ------------------------------------------------------------------

    /**
     * Sync ManagedHostname rows for a resource against its currently exposed hostnames.
     *
     * - new managed hostnames are created in `pending`
     * - rows whose hostname disappeared are deleted (returned for provider cleanup)
     * - rows with binding_source=override keep their pinned domain
     * - ownership changes (domain re-resolution) reset the row to `pending`
     *
     * @param  string  $recordKind  ManagedHostname::KIND_HTTP_TUNNEL or KIND_TCP_DNS
     * @return array{current: Collection<int, ManagedHostname>, removed: Collection<int, array>}
     */
    public static function syncManagedHostnames($resource, string $recordKind = ManagedHostname::KIND_HTTP_TUNNEL): array
    {
        $teamId = static::teamIdForResource($resource);
        if ($teamId === null) {
            return ['current' => collect(), 'removed' => collect()];
        }

        $desired = static::extractHostnames($resource);

        $existing = ManagedHostname::where('resource_type', get_class($resource))
            ->where('resource_id', $resource->id)
            ->where('record_kind', $recordKind)
            ->get()
            ->keyBy('hostname');

        $current = collect();
        $removed = collect();

        foreach ($desired as $hostname) {
            $row = $existing->get($hostname);

            if ($row && $row->binding_source === ManagedHostname::SOURCE_OVERRIDE) {
                // Pinned by explicit override — never re-resolve.
                $current->push($row);
                continue;
            }

            $domain = static::resolveDomain($hostname, $teamId);
            if (! $domain) {
                // Unmanaged hostname (domain deactivated/deleted): drop the stale row but
                // report it for provider cleanup — it may still carry a per-host DNS record
                // or Access app from when it WAS managed.
                if ($row) {
                    $removed->push(static::removedEntryFor($row));
                    $row->delete();
                }
                continue;
            }

            if ($row) {
                if ((int) $row->domain_id !== (int) $domain->id) {
                    $row->update([
                        'domain_id' => $domain->id,
                        'dns_provider_id' => $domain->dns_provider_id,
                        'sync_state' => ManagedHostname::STATE_PENDING,
                        'last_error' => null,
                    ]);
                }
                $current->push($row);
            } else {
                $current->push(ManagedHostname::create([
                    'resource_type' => get_class($resource),
                    'resource_id' => $resource->id,
                    'domain_id' => $domain->id,
                    'dns_provider_id' => $domain->dns_provider_id,
                    'hostname' => $hostname,
                    'binding_source' => ManagedHostname::SOURCE_SUFFIX_MATCH,
                    'record_kind' => $recordKind,
                    'sync_state' => ManagedHostname::STATE_PENDING,
                ]));
            }
        }

        // Hostnames the resource no longer exposes → delete rows, report for provider cleanup.
        foreach ($existing as $hostname => $row) {
            if (! $desired->contains($hostname)) {
                $removed->push(static::removedEntryFor($row));
                $row->delete();
            }
        }

        return ['current' => $current, 'removed' => $removed];
    }

    /**
     * Provider-cleanup payload for a ManagedHostname row that is being dropped.
     *
     * @return array<string, mixed>
     */
    protected static function removedEntryFor(ManagedHostname $row): array
    {
        return [
            'hostname' => $row->hostname,
            'domain_id' => $row->domain_id,
            'dns_provider_id' => $row->dns_provider_id,
            'record_kind' => $row->record_kind,
            'provider_record_id' => $row->provider_record_id,
        ];
    }

    // ------------------------------------------------------------------
    // Removal reconciliation + UI status helpers (Wave 3)
    // ------------------------------------------------------------------

    /**
     * Drop all managed-hostname state for a deleted resource.
     *
     * Wildcard domains need no provider-side cleanup (no per-host record exists).
     * Per-hostname/hybrid rows are logged so the Pro drift sync (Wave 4+) can
     * remove the corresponding tunnel ingress rules / DNS records.
     */
    public static function purgeResource($resource): int
    {
        $rows = ManagedHostname::where('resource_type', get_class($resource))
            ->where('resource_id', $resource->id)
            ->with('domain')
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }


        $httpRows = $rows->filter(
            fn (ManagedHostname $row) => $row->record_kind === ManagedHostname::KIND_HTTP_TUNNEL
                && $row->domain
                && $row->domain->routing_mode !== Domain::ROUTING_WILDCARD
        );
        if ($httpRows->isNotEmpty()) {
            \CorelixIo\Platform\Services\DnsTeardownService::cleanupPurgedHttpHostnames($httpRows);
        }

        return ManagedHostname::whereIn('id', $rows->pluck('id'))->delete();
    }

    /**
     * UI helper: [hostname => sync_state] for a single resource (Links badges, Domains panel).
     *
     * @return array<string, string>
     */
    public static function hostnameStatusMap($resource): array
    {
        try {
            return ManagedHostname::where('resource_type', get_class($resource))
                ->where('resource_id', $resource->id)
                ->pluck('sync_state', 'hostname')
                ->all();
        } catch (\Throwable) {
            // Never let a status badge break page rendering (e.g. migrations not run yet).
            return [];
        }
    }


    /**
     * UI helper: merged [hostname => sync_state] across all of a Service's applications.
     *
     * @return array<string, string>
     */
    public static function hostnameStatusMapForService($service): array
    {
        try {
            $ids = $service->applications()->pluck('id');
            if ($ids->isEmpty()) {
                return [];
            }

            return ManagedHostname::where('resource_type', 'App\\Models\\ServiceApplication')
                ->whereIn('resource_id', $ids)
                ->pluck('sync_state', 'hostname')
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
