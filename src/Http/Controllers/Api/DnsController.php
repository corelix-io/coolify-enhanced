<?php

namespace CorelixIo\Platform\Http\Controllers\Api;

use CorelixIo\Platform\Jobs\DnsDomainProvisionJob;
use CorelixIo\Platform\Jobs\DnsReconcileJob;
use CorelixIo\Platform\Models\DnsProvider;
use CorelixIo\Platform\Models\Domain;
use CorelixIo\Platform\Models\ManagedHostname;
use CorelixIo\Platform\Services\CloudflareApiClient;
use CorelixIo\Platform\Services\DnsResolutionService;
use CorelixIo\Platform\Services\DnsTeardownService;
use CorelixIo\Platform\Support\DnsAudit;
use CorelixIo\Platform\Support\DnsDriverFactory;
use CorelixIo\Platform\Support\Feature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * DNS Provider Management REST API (mirrors RegistryController / NetworkController).
 *
 * Reads are team-scoped and return MASKED credentials; mutations are owner/admin only.
 * Pro endpoints (assign-domain, environment bindings) sit behind `feature:` middleware
 * (HTTP 402 when the pro feature is disabled) plus defensive runtime guards.
 */
class DnsController extends Controller
{
    public function __construct()
    {
        if (! config('corelix-platform.enabled', false)
            || ! config('corelix-platform.dns_provider_management.enabled', false)) {
            abort(404);
        }
    }

    // ------------------------------------------------------------------
    // DNS providers
    // ------------------------------------------------------------------

    public function index(): JsonResponse
    {
        $providers = DnsProvider::ownedByTeam($this->teamId())
            ->orderBy('name')
            ->get()
            ->map(fn (DnsProvider $provider) => $this->formatProvider($provider));

        return response()->json($providers);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'type' => ['required', 'string', 'in:'.implode(',', DnsProvider::TYPES)],
            'credentials' => ['required', 'array'],
        ]);

        $this->validateCredentials($validated['type'], $validated['credentials']);

        // Free tier manages a single provider; more are pro (DNS_MULTI_DOMAIN).
        if (Feature::disabled('DNS_MULTI_DOMAIN')
            && DnsProvider::ownedByTeam($this->teamId())->count() >= 1) {
            return response()->json([
                'message' => 'Multiple DNS providers require the Pro edition (DNS Multi-Domain).',
            ], 402);
        }

        $provider = DnsProvider::create([
            'team_id' => $this->teamId(),
            'name' => $validated['name'],
            'type' => $validated['type'],
            'credentials' => $validated['credentials'],
        ]);

        DnsAudit::record('provider.created', [
            'provider_uuid' => $provider->uuid, 'name' => $provider->name, 'type' => $provider->type,
        ]);

        return response()->json($this->formatProvider($provider), 201);
    }

    public function show(string $uuid): JsonResponse
    {
        return response()->json($this->formatProvider($this->findProvider($uuid)));
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $this->requireAdmin($request);
        $provider = $this->findProvider($uuid);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:100'],
            'type' => ['sometimes', 'string', 'in:'.implode(',', DnsProvider::TYPES)],
            'credentials' => ['sometimes', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['credentials'])) {
            // Empty/missing fields keep their stored value (token-preserving edit).
            $existing = $provider->credentials ?? [];
            $validated['credentials'] = array_merge(
                $existing,
                array_filter($validated['credentials'], fn ($v) => $v !== '' && $v !== null)
            );
            $this->validateCredentials($validated['type'] ?? $provider->type, $validated['credentials']);
        }

        $provider->update($validated);

        DnsAudit::record('provider.updated', [
            'provider_uuid' => $provider->uuid, 'changed' => array_keys($validated),
        ]);

        return response()->json($this->formatProvider($provider->fresh()));
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->requireAdmin($request);
        $provider = $this->findProvider($uuid);

        if ($provider->domains()->exists()) {
            return response()->json([
                'message' => 'This provider still has managed domains. Delete the domains first.',
            ], 422);
        }

        DnsTeardownService::teardownProvider($provider);
        $provider->delete();

        DnsAudit::record('provider.deleted', [
            'provider_uuid' => $provider->uuid, 'name' => $provider->name,
        ]);

        return response()->json(['message' => 'DNS provider deleted.']);
    }

    public function test(Request $request, string $uuid): JsonResponse
    {
        $this->requireAdmin($request);
        $provider = $this->findProvider($uuid);

        $result = DnsDriverFactory::for($provider)->testConnection();

        $provider->update([
            'last_tested_at' => now(),
            'last_test_status' => $result['success'] ? DnsProvider::TEST_SUCCESS : DnsProvider::TEST_FAILED,
            'last_test_error' => $result['error'] ?? null,
        ]);

        DnsAudit::record('provider.tested', [
            'provider_uuid' => $provider->uuid, 'success' => (bool) $result['success'],
        ]);

        return response()->json([
            'success' => (bool) $result['success'],
            'error' => $result['error'] ?? null,
            'scopes_ok' => $result['scopes_ok'] ?? [],
        ]);
    }

    // ------------------------------------------------------------------
    // Managed domains
    // ------------------------------------------------------------------

    public function domains(): JsonResponse
    {
        $domains = Domain::ownedByTeam($this->teamId())
            ->with(['provider', 'tunnel', 'servers'])
            ->orderBy('base_domain')
            ->get()
            ->map(fn (Domain $domain) => $this->formatDomain($domain));

        return response()->json($domains);
    }

    public function storeDomain(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $teamId = $this->teamId();

        $validated = $request->validate([
            'base_domain' => ['required', 'string', 'min:3', 'max:253'],
            'dns_provider_uuid' => ['required', 'string'],
            'server_uuid' => ['required', 'string'],
            'routing_mode' => ['sometimes', 'string', 'in:'.implode(',', $this->allowedRoutingModes())],
            'set_server_default' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $provider = DnsProvider::ownedByTeam($teamId)->active()
            ->where('uuid', $validated['dns_provider_uuid'])->firstOrFail();
        // Stateless Sanctum routes have no session, so ownedByCurrentTeam() (which
        // reads session('currentTeam')) would 500 or mis-scope. Scope by the token team.
        $server = \App\Models\Server::whereTeamId($teamId)
            ->where('uuid', $validated['server_uuid'])->firstOrFail();

        $normalized = Domain::normalizeBaseDomain($validated['base_domain']);
        if (! Domain::isValidBaseDomain($normalized)) {
            return response()->json(['message' => 'Enter a valid hostname, e.g. apps.example.com.'], 422);
        }

        if (Domain::ownedByTeam($teamId)->where('base_domain', $normalized)->exists()) {
            return response()->json(['message' => 'This domain is already managed.'], 422);
        }

        // Free tier manages a single domain; additional domains are pro (DNS_MULTI_DOMAIN).
        if (Feature::disabled('DNS_MULTI_DOMAIN') && Domain::ownedByTeam($teamId)->count() >= 1) {
            return response()->json([
                'message' => 'Multiple managed domains require the Pro edition (DNS Multi-Domain).',
            ], 402);
        }

        $isDefault = $validated['is_default']
            ?? ! Domain::ownedByTeam($teamId)->where('is_default', true)->exists();

        if ($isDefault) {
            Domain::ownedByTeam($teamId)->update(['is_default' => false]);
        }

        $domain = Domain::create([
            'team_id' => $teamId,
            'dns_provider_id' => $provider->id,
            'base_domain' => $normalized,
            'routing_mode' => $validated['routing_mode'] ?? Domain::ROUTING_WILDCARD,
            'is_default' => $isDefault,
        ]);

        $domain->servers()->attach($server->id, [
            'is_default_wildcard' => $validated['set_server_default'] ?? true,
        ]);

        DnsDomainProvisionJob::dispatch($domain, $server->id);

        DnsAudit::record('domain.created', [
            'domain_uuid' => $domain->uuid, 'base_domain' => $domain->base_domain,
            'routing_mode' => $domain->routing_mode, 'provider_uuid' => $provider->uuid,
        ]);

        return response()->json($this->formatDomain($domain->fresh(['provider', 'tunnel', 'servers'])), 201);
    }

    public function showDomain(string $uuid): JsonResponse
    {
        return response()->json(
            $this->formatDomain($this->findDomain($uuid)->load(['provider', 'tunnel', 'servers']))
        );
    }

    public function updateDomain(Request $request, string $uuid): JsonResponse
    {
        $this->requireAdmin($request);
        $domain = $this->findDomain($uuid);

        $validated = $request->validate([
            'routing_mode' => ['sometimes', 'string', 'in:'.implode(',', $this->allowedRoutingModes())],
            'default_ingress_target' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'access_policy' => ['sometimes', 'nullable', 'array'],
            'access_policy.enabled' => ['sometimes', 'boolean'],
            'access_policy.allowed_email_domains' => ['sometimes', 'array'],
            'access_policy.allowed_emails' => ['sometimes', 'array'],
            'access_policy.session_duration' => ['sometimes', 'string', 'max:10'],
        ]);

        if ($request->has('access_policy')) {
            // PRO: DNS_ACCESS_POLICIES — explicit 402 when the flag is off (default off).
            if (Feature::disabled('DNS_ACCESS_POLICIES')) {
                return response()->json([
                    'message' => 'Access policies require the Pro edition (DNS Access Policies).',
                ], 402);
            }

        }

        if (($validated['is_default'] ?? false) === true) {
            Domain::ownedByTeam($this->teamId())
                ->whereKeyNot($domain->id)
                ->update(['is_default' => false]);
        }

        $domain->update($validated);


        DnsAudit::record('domain.updated', [
            'domain_uuid' => $domain->uuid, 'base_domain' => $domain->base_domain,
            'changed' => array_keys($validated),
        ]);

        return response()->json($this->formatDomain($domain->fresh(['provider', 'tunnel', 'servers'])));
    }

    public function destroyDomain(Request $request, string $uuid): JsonResponse
    {
        $this->requireAdmin($request);
        $domain = $this->findDomain($uuid);
        $name = $domain->base_domain;

        DnsTeardownService::teardownDomain($domain);
        $domain->delete();

        DnsAudit::record('domain.deleted', ['domain_uuid' => $uuid, 'base_domain' => $name]);

        return response()->json([
            'message' => "Domain \"{$name}\" deleted. Access apps and managed daemons were cleaned up; zone DNS records were left untouched.",
        ]);
    }

    /**
     * Re-provision a domain: tunnel + wildcard/apex records + managed cloudflared daemon.
     */
    public function syncDomain(Request $request, string $uuid): JsonResponse
    {
        $this->requireAdmin($request);
        $domain = $this->findDomain($uuid);

        DnsDomainProvisionJob::dispatch($domain);

        DnsAudit::record('domain.provision_queued', [
            'domain_uuid' => $domain->uuid, 'base_domain' => $domain->base_domain,
        ]);

        return response()->json(['message' => "Re-provisioning \"{$domain->base_domain}\" in the background."]);
    }

    public function domainHostnames(string $uuid): JsonResponse
    {
        $domain = $this->findDomain($uuid);

        $hostnames = ManagedHostname::where('domain_id', $domain->id)
            ->orderBy('hostname')
            ->get()
            ->map(fn (ManagedHostname $hostname) => $this->formatHostname($hostname));

        return response()->json($hostnames);
    }

    // ------------------------------------------------------------------
    // Per-resource operations
    // ------------------------------------------------------------------

    /**
     * Resolved hostnames + reconcile state for a resource (Application, Service, database).
     */
    public function resourceStatus(string $type, string $uuid): JsonResponse
    {
        $resource = $this->resolveResource($type, $uuid);

        $entries = collect();
        foreach ($this->fqdnResources($resource) as $fqdnResource) {
            $rows = ManagedHostname::where('resource_type', get_class($fqdnResource))
                ->where('resource_id', $fqdnResource->id)
                ->with(['domain', 'provider'])
                ->get()
                ->keyBy('hostname');

            foreach (DnsResolutionService::extractHostnames($fqdnResource) as $hostname) {
                $row = $rows->pull($hostname);
                $entries->push($row
                    ? $this->formatHostname($row)
                    : ['hostname' => $hostname, 'sync_state' => ManagedHostname::STATE_UNMANAGED, 'domain' => null]);
            }

            // Stale rows (hostname no longer exposed) + TCP rows (no HTTP fqdn source).
            foreach ($rows as $row) {
                $entries->push($this->formatHostname($row));
            }
        }

        return response()->json($entries->values());
    }

    /**
     * Queue a DNS reconcile for the resource (idempotent).
     */
    public function resourceResync(Request $request, string $type, string $uuid): JsonResponse
    {
        $this->requireAdmin($request);
        $resource = $this->resolveResource($type, $uuid);

        foreach ($this->fqdnResources($resource) as $fqdnResource) {
            DnsReconcileJob::dispatch($fqdnResource);
        }

        DnsAudit::record('resource.resync_queued', ['resource_type' => $type, 'resource_uuid' => $uuid]);

        return response()->json(['message' => 'DNS re-sync queued.']);
    }

    /**
     * Pin a hostname to an explicit managed domain (binding_source=override), or remove the
     * pin when domain_uuid is null. PRO: DNS_MULTI_DOMAIN (route carries feature middleware).
     */
    public function assignDomain(Request $request, string $type, string $uuid): JsonResponse
    {
        $this->requireAdmin($request);

        if (Feature::disabled('DNS_MULTI_DOMAIN')) {
            return response()->json([
                'message' => 'Pinning hostnames requires the Pro edition (DNS Multi-Domain).',
            ], 402);
        }

        $resource = $this->resolveResource($type, $uuid);

        $validated = $request->validate([
            'hostname' => ['required', 'string', 'min:3', 'max:253'],
            'domain_uuid' => ['present', 'nullable', 'string'],
        ]);

        $hostname = rtrim(strtolower(trim($validated['hostname'])), '.');

        $target = null;
        foreach ($this->fqdnResources($resource) as $fqdnResource) {
            if (DnsResolutionService::extractHostnames($fqdnResource)->contains($hostname)) {
                $target = $fqdnResource;
                break;
            }
        }

        if (! $target) {
            return response()->json(['message' => 'This hostname is not exposed by the resource.'], 422);
        }

        if ($validated['domain_uuid'] === null) {
            // Unpin → falls back to longest-suffix resolution on the next reconcile.
            ManagedHostname::where('resource_type', get_class($target))
                ->where('resource_id', $target->id)
                ->where('hostname', $hostname)
                ->where('binding_source', ManagedHostname::SOURCE_OVERRIDE)
                ->update([
                    'binding_source' => ManagedHostname::SOURCE_SUFFIX_MATCH,
                    'sync_state' => ManagedHostname::STATE_PENDING,
                    'last_error' => null,
                ]);

            DnsReconcileJob::dispatch($target);

            DnsAudit::record('hostname.unpinned', ['hostname' => $hostname, 'resource_type' => $type, 'resource_uuid' => $uuid]);

            return response()->json(['message' => 'Pin removed — the hostname will re-resolve on the next sync.']);
        }

        $domain = Domain::ownedByTeam($this->teamId())->active()
            ->where('uuid', $validated['domain_uuid'])->firstOrFail();

        ManagedHostname::updateOrCreate(
            [
                'resource_type' => get_class($target),
                'resource_id' => $target->id,
                'hostname' => $hostname,
                'record_kind' => ManagedHostname::KIND_HTTP_TUNNEL,
            ],
            [
                'domain_id' => $domain->id,
                'dns_provider_id' => $domain->dns_provider_id,
                'binding_source' => ManagedHostname::SOURCE_OVERRIDE,
                'sync_state' => ManagedHostname::STATE_PENDING,
                'last_error' => null,
            ]
        );

        DnsReconcileJob::dispatch($target);

        DnsAudit::record('hostname.pinned', [
            'hostname' => $hostname, 'domain_uuid' => $domain->uuid,
            'resource_type' => $type, 'resource_uuid' => $uuid,
        ]);

        return response()->json(['message' => "Hostname pinned to \"{$domain->base_domain}\" — re-sync queued."]);
    }

    // ------------------------------------------------------------------
    // Environment bindings — PRO: DNS_ENV_BINDINGS (routes carry feature middleware;
    // the model file is excluded from free builds, hence the class_exists guards).
    // ------------------------------------------------------------------

    public function bindings(string $uuid): JsonResponse
    {
        $domain = $this->findDomain($uuid);

        if (! $this->envBindingsAvailable()) {
            return response()->json(['message' => 'Environment bindings require the Pro edition.'], 402);
        }

        $bindingClass = \CorelixIo\Platform\Models\DomainEnvironmentBinding::class;
        $bindings = $bindingClass::where('domain_id', $domain->id)
            ->orderBy('priority')
            ->get()
            ->map(fn ($binding) => $this->formatBinding($binding));

        return response()->json($bindings);
    }

    public function storeBinding(Request $request, string $uuid): JsonResponse
    {
        $this->requireAdmin($request);
        $domain = $this->findDomain($uuid);

        if (! $this->envBindingsAvailable()) {
            return response()->json(['message' => 'Environment bindings require the Pro edition.'], 402);
        }

        $bindingClass = \CorelixIo\Platform\Models\DomainEnvironmentBinding::class;

        $validated = $request->validate([
            'environment_id' => ['nullable', 'integer'],
            'environment_role' => ['nullable', 'string', 'in:'.implode(',', $bindingClass::ROLES)],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ]);

        $hasEnv = ! empty($validated['environment_id']);
        $hasRole = ! empty($validated['environment_role']);
        if ($hasEnv === $hasRole) {
            return response()->json([
                'message' => 'Provide exactly one of environment_id or environment_role.',
            ], 422);
        }

        if (! empty($validated['environment_id'])) {
            // The environment must belong to one of the team's projects.
            $environment = \App\Models\Environment::query()
                ->whereKey($validated['environment_id'])
                ->whereHas('project', fn ($q) => $q->where('team_id', $this->teamId()))
                ->first();
            if (! $environment) {
                return response()->json(['message' => 'Environment not found in this team.'], 404);
            }
        }

        try {
            $binding = $bindingClass::updateOrCreate(
                array_filter([
                    'team_id' => $this->teamId(),
                    'environment_id' => $validated['environment_id'] ?? null,
                    'environment_role' => $validated['environment_role'] ?? null,
                ], fn ($v) => $v !== null),
                [
                    'domain_id' => $domain->id,
                    'priority' => $validated['priority'] ?? 0,
                ]
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        DnsAudit::record('binding.upserted', [
            'domain_uuid' => $domain->uuid,
            'environment_id' => $validated['environment_id'] ?? null,
            'environment_role' => $validated['environment_role'] ?? null,
        ]);

        return response()->json($this->formatBinding($binding), 201);
    }

    public function destroyBinding(Request $request, string $uuid, int $bindingId): JsonResponse
    {
        $this->requireAdmin($request);
        $domain = $this->findDomain($uuid);

        if (! $this->envBindingsAvailable()) {
            return response()->json(['message' => 'Environment bindings require the Pro edition.'], 402);
        }

        $bindingClass = \CorelixIo\Platform\Models\DomainEnvironmentBinding::class;
        $deleted = $bindingClass::where('domain_id', $domain->id)
            ->whereKey($bindingId)
            ->delete();

        if (! $deleted) {
            return response()->json(['message' => 'Binding not found.'], 404);
        }

        DnsAudit::record('binding.deleted', ['domain_uuid' => $domain->uuid, 'binding_id' => $bindingId]);

        return response()->json(['message' => 'Environment binding removed.']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function teamId(): int
    {
        $teamId = getTeamIdFromToken();
        if ($teamId === null) {
            $teamId = currentTeam()?->id;
        }

        if ($teamId === null) {
            abort(400, 'Invalid token.');
        }

        return (int) $teamId;
    }

    protected function requireAdmin(Request $request): void
    {
        $user = $request->user();
        if (! $user?->isAdminOfTeam($this->teamId())) {
            abort(403, 'Only team owners and admins can manage DNS.');
        }
    }

    private function validateCredentials(string $type, array $credentials): void
    {
        foreach (DnsProvider::getCredentialFields($type) as $field) {
            if (empty($credentials[$field])) {
                abort(422, "The credential field '{$field}' is required for {$type} providers.");
            }
        }

        if ($type === DnsProvider::TYPE_CLOUDFLARE_TUNNEL
            && ! CloudflareApiClient::validateAccountId((string) ($credentials['account_id'] ?? ''))) {
            abort(422, 'Cloudflare Account ID must be a 32-character hexadecimal string.');
        }
    }

    protected function envBindingsAvailable(): bool
    {
        return Feature::enabled('DNS_ENV_BINDINGS')
            && class_exists(\CorelixIo\Platform\Models\DomainEnvironmentBinding::class);
    }

    protected function findProvider(string $uuid): DnsProvider
    {
        return DnsProvider::ownedByTeam($this->teamId())->where('uuid', $uuid)->firstOrFail();
    }

    protected function findDomain(string $uuid): Domain
    {
        return Domain::ownedByTeam($this->teamId())->where('uuid', $uuid)->firstOrFail();
    }

    /**
     * @return array<int, string>
     */
    protected function allowedRoutingModes(): array
    {
        $modes = [Domain::ROUTING_WILDCARD];

        if (Feature::enabled('DNS_PER_HOSTNAME')) {
            $modes[] = Domain::ROUTING_PER_HOSTNAME;
            $modes[] = Domain::ROUTING_HYBRID;
        }

        return $modes;
    }

    /**
     * Resolve a resource by type slug + uuid, team-scoped (mirrors NetworkController).
     */
    protected function resolveResource(string $type, string $uuid)
    {
        // Token-scoped (stateless Sanctum routes have no session for ownedByCurrentTeam()).
        $teamId = $this->teamId();
        $scoped = fn (string $class) => $class::whereRelation('environment.project.team', 'id', $teamId)
            ->where('uuid', $uuid)->firstOrFail();

        return match ($type) {
            'application' => $scoped(\App\Models\Application::class),
            'service' => $scoped(\App\Models\Service::class),
            'postgresql' => $scoped(\App\Models\StandalonePostgresql::class),
            'mysql' => $scoped(\App\Models\StandaloneMysql::class),
            'mariadb' => $scoped(\App\Models\StandaloneMariadb::class),
            'mongodb' => $scoped(\App\Models\StandaloneMongodb::class),
            'redis' => $scoped(\App\Models\StandaloneRedis::class),
            'keydb' => $scoped(\App\Models\StandaloneKeydb::class),
            'dragonfly' => $scoped(\App\Models\StandaloneDragonfly::class),
            'clickhouse' => $scoped(\App\Models\StandaloneClickhouse::class),
            default => abort(404, "Unknown resource type: {$type}"),
        };
    }

    /**
     * FQDN-bearing resources behind an API resource: the resource itself, or a
     * Service's ServiceApplications.
     *
     * @return array<int, object>
     */
    protected function fqdnResources($resource): array
    {
        if ($resource instanceof \App\Models\Service) {
            return $resource->applications()->get()->all();
        }

        return [$resource];
    }

    protected function formatProvider(DnsProvider $provider): array
    {
        return [
            'uuid' => $provider->uuid,
            'name' => $provider->name,
            'type' => $provider->type,
            'type_label' => $provider->getTypeLabel(),
            'credentials' => $provider->getMaskedCredentials(),
            'is_active' => $provider->is_active,
            'last_tested_at' => $provider->last_tested_at?->toISOString(),
            'last_test_status' => $provider->last_test_status,
            'last_test_error' => $provider->last_test_error,
            'created_at' => $provider->created_at?->toISOString(),
            'updated_at' => $provider->updated_at?->toISOString(),
        ];
    }

    protected function formatDomain(Domain $domain): array
    {
        return [
            'uuid' => $domain->uuid,
            'base_domain' => $domain->base_domain,
            'routing_mode' => $domain->routing_mode,
            'tls_mode' => $domain->tls_mode,
            'default_ingress_target' => $domain->default_ingress_target,
            'is_default' => $domain->is_default,
            'is_active' => $domain->is_active,
            'access_policy' => Feature::enabled('DNS_ACCESS_POLICIES') ? ($domain->access_policy ?? null) : null,
            'provider' => $domain->provider ? [
                'uuid' => $domain->provider->uuid,
                'name' => $domain->provider->name,
                'type' => $domain->provider->type,
            ] : null,
            'tunnel' => $domain->tunnel ? [
                'tunnel_id' => $domain->tunnel->cf_tunnel_id,
                'status' => $domain->tunnel->status,
            ] : null,
            'servers' => $domain->relationLoaded('servers')
                ? $domain->servers->map(fn ($server) => [
                    'uuid' => $server->uuid,
                    'name' => $server->name,
                    'is_default_wildcard' => (bool) $server->pivot->is_default_wildcard,
                ])->values()
                : [],
            'created_at' => $domain->created_at?->toISOString(),
            'updated_at' => $domain->updated_at?->toISOString(),
        ];
    }

    protected function formatHostname(ManagedHostname $hostname): array
    {
        return [
            'uuid' => $hostname->uuid,
            'hostname' => $hostname->hostname,
            'sync_state' => $hostname->sync_state,
            'binding_source' => $hostname->binding_source,
            'record_kind' => $hostname->record_kind,
            'domain' => $hostname->domain?->base_domain,
            'provider' => $hostname->provider?->name,
            'resource_type' => class_basename((string) $hostname->resource_type),
            'resource_id' => $hostname->resource_id,
            'last_synced_at' => $hostname->last_synced_at?->toISOString(),
            'last_error' => $hostname->last_error,
        ];
    }

    protected function formatBinding($binding): array
    {
        return [
            'id' => $binding->id,
            'domain_id' => $binding->domain_id,
            'environment_id' => $binding->environment_id,
            'environment_role' => $binding->environment_role,
            'priority' => $binding->priority,
            'created_at' => $binding->created_at?->toISOString(),
        ];
    }
}
