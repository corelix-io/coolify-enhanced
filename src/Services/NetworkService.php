<?php

namespace CorelixIo\Platform\Services;

use CorelixIo\Platform\Models\ManagedNetwork;
use CorelixIo\Platform\Models\ResourceNetwork;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Visus\Cuid2\Cuid2;

class NetworkService
{
    /**
     * Network name prefix for all managed Docker networks.
     */
    const PREFIX = 'ce';

    // ============================================================
    // Docker Operations (via instant_remote_process)
    // ============================================================

    /**
     * Create a Docker network on the server.
     *
     * Idempotent: uses 2>/dev/null || true pattern so repeated calls
     * do not fail if the network already exists.
     *
     * Adds labels for reconciliation:
     *   coolify.managed=true, coolify.scope={scope}, coolify.environment={uuid}
     */
    public static function createDockerNetwork(Server $server, ManagedNetwork $network): bool
    {
        try {
            // Overlay networks can only be created on Swarm manager nodes
            if ($network->driver === 'overlay' && !static::isSwarmManager($server)) {
                Log::warning("NetworkService: Cannot create overlay network on non-manager node", [
                    'server' => $server->name,
                    'network' => $network->docker_network_name,
                ]);

                $network->update([
                    'status' => ManagedNetwork::STATUS_ERROR,
                    'error_message' => 'Overlay networks can only be created on Swarm manager nodes.',
                ]);

                return false;
            }

            $parts = ['docker network create'];
            $parts[] = '--driver '.escapeshellarg($network->driver);

            if ($network->is_attachable) {
                $parts[] = '--attachable';
            }

            if ($network->is_internal) {
                $parts[] = '--internal';
            }

            if ($network->subnet) {
                $parts[] = '--subnet '.escapeshellarg($network->subnet);
            }

            if ($network->gateway) {
                $parts[] = '--gateway '.escapeshellarg($network->gateway);
            }

            // Add encrypted option for overlay networks if configured
            if ($network->driver === 'overlay' && ($network->options['encrypted'] ?? false)) {
                $parts[] = '--opt encrypted';
            }

            // Add labels for reconciliation
            $parts[] = '--label coolify.managed=true';
            $parts[] = '--label '.escapeshellarg("coolify.scope={$network->scope}");

            if ($network->environment_id) {
                $envUuid = $network->environment?->uuid;
                if ($envUuid) {
                    $parts[] = '--label '.escapeshellarg("coolify.environment={$envUuid}");
                }
            }

            if ($network->project_id) {
                $projectUuid = $network->project?->uuid;
                if ($projectUuid) {
                    $parts[] = '--label '.escapeshellarg("coolify.project={$projectUuid}");
                }
            }

            $parts[] = escapeshellarg($network->docker_network_name);

            $createCommand = implode(' ', $parts).' 2>/dev/null || true';

            instant_remote_process([$createCommand], $server);

            Log::info("NetworkService: Created Docker network {$network->docker_network_name} on server {$server->name}");

            // Inspect to verify the network actually exists and get the docker_id
            $inspection = static::inspectNetwork($server, $network->docker_network_name);

            if ($inspection && !empty($inspection['Id'])) {
                $network->update([
                    'docker_id' => $inspection['Id'],
                    'status' => ManagedNetwork::STATUS_ACTIVE,
                    'last_synced_at' => now(),
                    'error_message' => null,
                ]);

                return true;
            }

            // Network creation silently failed (|| true swallowed the error)
            Log::warning("NetworkService: Docker network {$network->docker_network_name} creation may have failed — inspection returned empty", [
                'server' => $server->name,
            ]);

            $network->update([
                'status' => ManagedNetwork::STATUS_ERROR,
                'error_message' => 'Network creation succeeded but inspection returned empty. Network may not exist on the Docker host.',
                'last_synced_at' => now(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::warning("NetworkService: Failed to create Docker network {$network->docker_network_name}", [
                'server' => $server->name,
                'error' => $e->getMessage(),
            ]);

            $network->update([
                'status' => ManagedNetwork::STATUS_ERROR,
                'error_message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Delete a Docker network.
     *
     * Disconnects all containers first via force-remove, then removes the network.
     */
    public static function deleteDockerNetwork(Server $server, ManagedNetwork $network): bool
    {
        try {
            $command = 'docker network rm '.escapeshellarg($network->docker_network_name).' 2>/dev/null || true';

            instant_remote_process([$command], $server);

            $stillExists = static::inspectNetwork($server, $network->docker_network_name);
            if ($stillExists !== null) {
                Log::warning("NetworkService: Docker network {$network->docker_network_name} still exists after delete attempt", [
                    'server' => $server->name,
                ]);

                return false;
            }

            Log::info("NetworkService: Deleted Docker network {$network->docker_network_name} on server {$server->name}");

            $network->update([
                'status' => ManagedNetwork::STATUS_PENDING,
                'docker_id' => null,
                'last_synced_at' => now(),
                'error_message' => null,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning("NetworkService: Failed to delete Docker network {$network->docker_network_name}", [
                'server' => $server->name,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Connect a container to a network.
     *
     * Idempotent: ignores "already connected" errors via 2>/dev/null || true.
     */
    public static function connectContainer(Server $server, string $networkName, string $containerName, ?array $aliases = null): bool
    {
        try {
            $parts = ['docker network connect'];

            if ($aliases) {
                foreach ($aliases as $alias) {
                    $parts[] = '--alias '.escapeshellarg($alias);
                }
            }

            $parts[] = escapeshellarg($networkName);
            $parts[] = escapeshellarg($containerName);

            $command = implode(' ', $parts).' 2>/dev/null || true';

            instant_remote_process([$command], $server);

            $connected = static::isContainerConnectedToNetwork($server, $containerName, $networkName);
            if ($connected !== true) {
                Log::warning("NetworkService: Container {$containerName} is not connected to network {$networkName} after connect attempt", [
                    'server' => $server->name,
                ]);

                return false;
            }

            Log::info("NetworkService: Connected container {$containerName} to network {$networkName}");

            return true;
        } catch (\Throwable $e) {
            Log::warning("NetworkService: Failed to connect container {$containerName} to network {$networkName}", [
                'server' => $server->name,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Disconnect a container from a network.
     */
    public static function disconnectContainer(Server $server, string $networkName, string $containerName, bool $force = false): bool
    {
        try {
            $forceFlag = $force ? '--force ' : '';
            $command = "docker network disconnect {$forceFlag}".escapeshellarg($networkName).' '.escapeshellarg($containerName).' 2>/dev/null || true';

            instant_remote_process([$command], $server);

            $connected = static::isContainerConnectedToNetwork($server, $containerName, $networkName);
            if ($connected !== false) {
                Log::warning("NetworkService: Container {$containerName} is still connected to network {$networkName} after disconnect attempt", [
                    'server' => $server->name,
                ]);

                return false;
            }

            Log::info("NetworkService: Disconnected container {$containerName} from network {$networkName}");

            return true;
        } catch (\Throwable $e) {
            Log::warning("NetworkService: Failed to disconnect container {$containerName} from network {$networkName}", [
                'server' => $server->name,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Inspect a Docker network and return parsed JSON.
     *
     * Returns null if the network does not exist or inspection fails.
     */
    public static function inspectNetwork(Server $server, string $networkName): ?array
    {
        try {
            $command = 'docker network inspect '.escapeshellarg($networkName)." --format '{{json .}}' 2>/dev/null";

            $output = instant_remote_process([$command], $server);

            if (empty($output)) {
                return null;
            }

            $parsed = json_decode($output, true);

            return is_array($parsed) ? $parsed : null;
        } catch (\Throwable $e) {
            Log::warning("NetworkService: Failed to inspect network {$networkName}", [
                'server' => $server->name,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * List all Docker networks on a server.
     *
     * Returns a collection of arrays with: Name, ID, Driver, Scope, Labels.
     */
    public static function listDockerNetworks(Server $server): Collection
    {
        try {
            $command = "docker network ls --format '{{json .}}'";

            $output = instant_remote_process([$command], $server);

            if (empty($output)) {
                return collect();
            }

            return collect(explode("\n", trim($output)))
                ->filter(fn ($line) => ! empty(trim($line)))
                ->map(function ($line) {
                    $parsed = json_decode($line, true);

                    return is_array($parsed) ? $parsed : null;
                })
                ->filter()
                ->values();
        } catch (\Throwable $e) {
            Log::warning('NetworkService: Failed to list Docker networks', [
                'server' => $server->name,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    // ============================================================
    // High-Level Operations
    // ============================================================

    /**
     * Ensure the environment network exists on the server.
     *
     * Creates both the DB record and the Docker network if needed.
     * Uses firstOrCreate with exception handling for race conditions.
     */
    public static function ensureEnvironmentNetwork(Environment $environment, Server $server): ManagedNetwork
    {
        return static::ensureScopedNetwork(
            server: $server,
            dockerName: static::generateNetworkName('env', $environment->uuid),
            humanName: "{$environment->name} ({$environment->project->name})",
            scope: ManagedNetwork::SCOPE_ENVIRONMENT,
            teamId: $environment->project->team_id,
            extraAttributes: [
                'project_id' => $environment->project_id,
                'environment_id' => $environment->id,
                'is_proxy_network' => false,
            ],
            limitErrorContext: "environment network for {$environment->name}",
        );
    }

    /**
     * Ensure the proxy network exists on a server.
     *
     * The proxy network connects resources that have an FQDN to the reverse proxy,
     * enabling HTTP routing without being on the default 'coolify' network.
     */
    public static function ensureProxyNetwork(Server $server): ManagedNetwork
    {
        return static::ensureScopedNetwork(
            server: $server,
            dockerName: static::generateNetworkName('proxy', $server->uuid),
            humanName: "Proxy ({$server->name})",
            scope: ManagedNetwork::SCOPE_PROXY,
            teamId: $server->team_id,
            extraAttributes: [
                'is_proxy_network' => true,
            ],
            limitErrorContext: 'proxy network',
        );
    }

    /**
     * Create/ensure a shared network on a server.
     *
     * Shared networks are manually created and can be joined by any resource
     * on the same server, enabling cross-environment communication.
     */
    public static function ensureSharedNetwork(string $name, Server $server, Team $team, bool $createDocker = true): ManagedNetwork
    {
        // Look up by name + server + scope (not by docker_network_name, which includes a random CUID)
        $existing = ManagedNetwork::where('name', $name)
            ->where('server_id', $server->id)
            ->where('scope', ManagedNetwork::SCOPE_SHARED)
            ->first();

        if ($existing) {
            return $existing;
        }

        $identifier = (string) new Cuid2;
        $dockerName = static::generateNetworkName('shared', $identifier);

        try {
            $network = ManagedNetwork::create([
                'uuid' => $identifier,
                'name' => $name,
                'docker_network_name' => $dockerName,
                'server_id' => $server->id,
                'driver' => static::resolveNetworkDriver($server),
                'scope' => ManagedNetwork::SCOPE_SHARED,
                'team_id' => $team->id,
                'is_attachable' => true,
                'is_internal' => false,
                'is_proxy_network' => false,
                'status' => ManagedNetwork::STATUS_PENDING,
            ]);
        } catch (\Throwable $e) {
            // Race condition: another process created it between our check and create
            $network = ManagedNetwork::where('name', $name)
                ->where('server_id', $server->id)
                ->where('scope', ManagedNetwork::SCOPE_SHARED)
                ->firstOrFail();
        }

        if ($createDocker && $network->status === ManagedNetwork::STATUS_PENDING) {
            static::createDockerNetwork($server, $network);
        }

        return $network;
    }

    /**
     * Get all managed networks a resource is connected to.
     */
    public static function getResourceNetworks($resource): Collection
    {
        return ResourceNetwork::where('resource_type', get_class($resource))
            ->where('resource_id', $resource->id)
            ->with('managedNetwork')
            ->get();
    }

    /**
     * Get networks available for a resource to join.
     *
     * Returns shared networks on the same server that the resource is not already on.
     */
    public static function getAvailableNetworks($resource, Server $server): Collection
    {
        $existingIds = ResourceNetwork::where('resource_type', get_class($resource))
            ->where('resource_id', $resource->id)
            ->pluck('managed_network_id');

        return ManagedNetwork::forServer($server)
            ->shared()
            ->active()
            ->whereNotIn('id', $existingIds)
            ->get();
    }

    // ============================================================
    // Reconciliation
    // ============================================================

    /**
     * Reconcile a single resource: ensure Docker state matches DB.
     *
     * 1. Get the resource's server
     * 2. Ensure environment network exists
     * 3. Get resource containers
     * 4. For each container: connect to environment network
     * 5. If resource has FQDN and proxy isolation enabled: connect to proxy network
     * 6. If strict isolation: disconnect from 'coolify' network
     * 7. Update resource_networks.is_connected status
     */
    public static function reconcileResource($resource): void
    {
        $resolved = static::resolveResourceContext($resource, 'resource reconciliation');
        if (! $resolved) {
            return;
        }

        [$server, $environment] = $resolved;

        if (static::isSwarmServer($server)) {
            static::attachSwarmResourceToManagedNetworks($resource, $server, $environment);

            return;
        }

        $containerNames = static::getContainerNames($resource);

        if (! empty($containerNames)) {
            static::attachContainersToManagedNetworks($resource, $server, $environment, $containerNames);
        }

        // [CORELIX ENHANCED: heal + truthfully record membership. Runtime network
        // attachment is lost on container recreation (redeploy/reboot); this
        // re-verifies every intended membership against live Docker, reconnects
        // drifted containers, and flips is_connected so the UI never shows a
        // stale "connected" state that `docker network inspect` contradicts.]
        static::reconcileMembershipState($resource, $server, $containerNames);
    }

    /**
     * Re-verify every intended membership (resource_networks pivot) for a
     * resource against live Docker state, reconnect drifted containers, and set
     * is_connected to the verified truth.
     *
     * This is the drift-healing counterpart to compose-level persistence: it
     * both fixes the reported "UI says connected, docker network inspect says
     * otherwise" divergence and re-attaches containers that lost their runtime
     * network membership after a recreation.
     *
     * @param  array|null  $containerNames  Pre-resolved running container names (re-resolved when null)
     */
    public static function reconcileMembershipState($resource, Server $server, ?array $containerNames = null): void
    {
        // Swarm membership is verified inline by attachSwarmResourceToManagedNetworks().
        if (static::isSwarmServer($server)) {
            return;
        }

        $pivots = ResourceNetwork::where('resource_type', get_class($resource))
            ->where('resource_id', $resource->id)
            ->with('managedNetwork')
            ->get();

        if ($pivots->isEmpty()) {
            return;
        }

        if ($containerNames === null) {
            $containerNames = static::getContainerNames($resource);
        }

        // Inspect each container ONCE for its full live network set, rather than
        // re-inspecting per (pivot × network). A container on N networks was
        // previously inspected N times every cycle — this is O(containers), not
        // O(pivots × containers). null = inspect failed / unknown for that container.
        $liveNetworks = [];
        foreach ($containerNames as $containerName) {
            $liveNetworks[$containerName] = static::getContainerNetworkNames($server, $containerName);
        }

        $defaultNetwork = static::getDefaultNetworkName($server);

        foreach ($pivots as $pivot) {
            $network = $pivot->managedNetwork;

            // Orphaned pivot (network deleted) — never connected.
            if (! $network) {
                if ($pivot->is_connected) {
                    $pivot->update(['is_connected' => false, 'connected_at' => null]);
                }

                continue;
            }

            // Never manage the default ingress network via a pivot — reconnecting
            // it here would fight the strict-mode disconnect in
            // attachContainersToManagedNetworks() and flap the container's IP
            // every cycle. Pivots for the default network are never created, but
            // guard defensively in case one is ever adopted.
            if ($network->docker_network_name === $defaultNetwork) {
                continue;
            }

            // No running containers → cannot be connected.
            if (empty($containerNames)) {
                if ($pivot->is_connected) {
                    $pivot->update(['is_connected' => false, 'connected_at' => null]);
                }

                continue;
            }

            $allConnected = true;
            foreach ($containerNames as $containerName) {
                $live = $liveNetworks[$containerName] ?? null;
                $isOn = is_array($live)
                    ? in_array($network->docker_network_name, $live, true)
                    : null; // null = unknown (inspect failed)

                if ($isOn === true) {
                    continue;
                }

                // Drifted (or unknown) — attempt to reconnect this container.
                $reconnected = static::connectContainer($server, $network->docker_network_name, $containerName);
                if (! $reconnected) {
                    $allConnected = false;
                }
            }

            if ($pivot->is_connected !== $allConnected) {
                $pivot->update([
                    'is_connected' => $allConnected,
                    'connected_at' => $allConnected ? now() : null,
                ]);
            }
        }
    }

    /**
     * Return the full list of Docker network names a container is currently
     * attached to (keys of .NetworkSettings.Networks), or null when inspection
     * fails / the container is absent. One remote inspect per container.
     *
     * @return array<int,string>|null
     */
    protected static function getContainerNetworkNames(Server $server, string $containerName): ?array
    {
        try {
            $command = 'docker inspect --format=\'{{json .NetworkSettings.Networks}}\' '.escapeshellarg($containerName).' 2>/dev/null';
            $output = instant_remote_process([$command], $server);

            if (empty(trim((string) $output))) {
                return null;
            }

            $networks = json_decode($output, true);
            if (! is_array($networks)) {
                return null;
            }

            return array_keys($networks);
        } catch (\Throwable $e) {
            Log::warning('NetworkService: Failed to read container network set', [
                'server' => $server->name,
                'container' => $containerName,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Lightweight per-server membership reconcile: re-verify + heal only the
     * resources that already have intended memberships (pivot records) on this
     * server. Bounded by managed resources rather than every resource, so it is
     * cheap enough to run on a schedule (self-heals reboots / out-of-band
     * recreations without a manual "Reconcile Existing Resources" click).
     */
    public static function reconcileServerMembership(Server $server): void
    {
        if (! $server->isFunctional()) {
            return;
        }

        $refs = ResourceNetwork::whereHas('managedNetwork', function ($query) use ($server) {
            $query->where('server_id', $server->id);
        })
            ->get(['resource_type', 'resource_id'])
            ->unique(fn ($r) => $r->resource_type.'#'.$r->resource_id);

        foreach ($refs as $ref) {
            $resource = static::resolveResourceModel($ref->resource_type, $ref->resource_id);
            if (! $resource) {
                continue;
            }

            try {
                // Verify-first drift heal (reconnects only on actual drift and
                // corrects stale is_connected) — lighter than a full re-attach
                // for the steady-state scheduled cadence.
                static::reconcileMembershipState($resource, $server);
            } catch (\Throwable $e) {
                Log::warning('NetworkService: Failed membership reconcile for resource', [
                    'resource' => $ref->resource_type.'#'.$ref->resource_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Resolve a resource model from a stored pivot (resource_type, resource_id).
     * Guards against arbitrary class instantiation via an allowlist.
     */
    protected static function resolveResourceModel(string $type, $id)
    {
        $allowed = [
            \App\Models\Application::class,
            \App\Models\Service::class,
            \App\Models\StandalonePostgresql::class,
            \App\Models\StandaloneMysql::class,
            \App\Models\StandaloneMariadb::class,
            \App\Models\StandaloneMongodb::class,
            \App\Models\StandaloneRedis::class,
            \App\Models\StandaloneKeydb::class,
            \App\Models\StandaloneDragonfly::class,
            \App\Models\StandaloneClickhouse::class,
        ];

        if (! in_array($type, $allowed, true) || ! class_exists($type)) {
            return null;
        }

        return $type::find($id);
    }

    /**
     * Reconcile all managed networks on a server.
     *
     * Verifies Docker state matches DB state for all networks.
     * Recreates missing networks and updates status for existing ones.
     */
    public static function reconcileServer(Server $server): void
    {
        $networks = ManagedNetwork::forServer($server)->get();

        foreach ($networks as $network) {
            try {
                $inspection = static::inspectNetwork($server, $network->docker_network_name);

                if ($inspection === null) {
                    // Network doesn't exist in Docker
                    if ($network->status === ManagedNetwork::STATUS_ACTIVE) {
                        // Was active, now missing — recreate
                        Log::info("NetworkService: Recreating missing network {$network->docker_network_name} on server {$server->name}");
                        static::createDockerNetwork($server, $network);
                    }
                } else {
                    // Network exists — update docker_id and status
                    $network->update([
                        'docker_id' => $inspection['Id'] ?? null,
                        'status' => ManagedNetwork::STATUS_ACTIVE,
                        'last_synced_at' => now(),
                        'error_message' => null,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning("NetworkService: Failed to reconcile network {$network->docker_network_name}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Reconcile all existing resources on a server.
     *
     * Useful when enabling network management for servers that already have
     * deployed resources and need one-time adoption into managed networks.
     *
     * @return array{total:int,reconciled:int,failed:int,errors:array<int,string>}
     */
    public static function reconcileExistingServerResources(Server $server): array
    {
        $results = [
            'total' => 0,
            'reconciled' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        $resources = collect();

        $resources = $resources->merge(
            Application::whereHas('destination', function ($query) use ($server) {
                $query->where('server_id', $server->id);
            })->get()
        );

        $resources = $resources->merge(
            Service::where('server_id', $server->id)->get()
        );

        $databaseClasses = [
            \App\Models\StandalonePostgresql::class,
            \App\Models\StandaloneMysql::class,
            \App\Models\StandaloneMariadb::class,
            \App\Models\StandaloneMongodb::class,
            \App\Models\StandaloneRedis::class,
            \App\Models\StandaloneKeydb::class,
            \App\Models\StandaloneDragonfly::class,
            \App\Models\StandaloneClickhouse::class,
        ];

        foreach ($databaseClasses as $dbClass) {
            if (! class_exists($dbClass)) {
                continue;
            }

            $resources = $resources->merge(
                $dbClass::whereHas('destination', function ($query) use ($server) {
                    $query->where('server_id', $server->id);
                })->get()
            );
        }

        $results['total'] = $resources->count();

        foreach ($resources as $resource) {
            try {
                static::reconcileResource($resource);
                $results['reconciled']++;
            } catch (\Throwable $e) {
                $results['failed']++;
                $results['errors'][] = get_class($resource).'#'.($resource->id ?? 'unknown').': '.$e->getMessage();

                Log::warning('NetworkService: Failed to reconcile existing resource', [
                    'server' => $server->name,
                    'resource_type' => get_class($resource),
                    'resource_id' => $resource->id ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Sync Docker networks into the managed_networks table.
     *
     * Discovers existing Docker networks with coolify.managed labels
     * and creates/updates DB records accordingly.
     *
     * @return Collection Collection of all discovered managed networks
     */
    public static function syncFromDocker(Server $server): Collection
    {
        $dockerNetworks = static::listDockerNetworks($server);
        $discovered = collect();

        foreach ($dockerNetworks as $dockerNetwork) {
            $name = $dockerNetwork['Name'] ?? null;
            if (! $name) {
                continue;
            }

            // Inspect the network to get labels and full details
            $inspection = static::inspectNetwork($server, $name);
            if (! $inspection) {
                continue;
            }

            $labels = $inspection['Labels'] ?? [];

            // Only process networks with the coolify.managed label
            if (! isset($labels['coolify.managed']) || $labels['coolify.managed'] !== 'true') {
                continue;
            }

            $scope = $labels['coolify.scope'] ?? ManagedNetwork::SCOPE_SYSTEM;

            // Find or create the DB record
            $network = ManagedNetwork::where('docker_network_name', $name)
                ->where('server_id', $server->id)
                ->first();

            if ($network) {
                $network->update([
                    'docker_id' => $inspection['Id'] ?? null,
                    'status' => ManagedNetwork::STATUS_ACTIVE,
                    'last_synced_at' => now(),
                    'error_message' => null,
                ]);
            } else {
                Log::info("NetworkService: Discovered untracked managed network {$name} on server {$server->name}");

                $network = ManagedNetwork::create([
                    'uuid' => (string) new Cuid2,
                    'name' => $name,
                    'docker_network_name' => $name,
                    'server_id' => $server->id,
                    'team_id' => $server->team_id,
                    'driver' => $inspection['Driver'] ?? 'bridge',
                    'scope' => $scope,
                    'docker_id' => $inspection['Id'] ?? null,
                    'status' => ManagedNetwork::STATUS_ACTIVE,
                    'last_synced_at' => now(),
                    'is_attachable' => (bool) ($inspection['Attachable'] ?? false),
                    'is_internal' => (bool) ($inspection['Internal'] ?? false),
                ]);
            }

            $discovered->push($network);
        }

        return $discovered;
    }

    // ============================================================
    // Auto-Provisioning
    // ============================================================

    /**
     * Auto-attach a resource to its environment network (and proxy if applicable).
     *
     * Called after deployment completes. Checks feature flags before proceeding.
     */
    public static function autoAttachResource($resource): void
    {
        if (! config('corelix-platform.network_management.enabled', false)) {
            return;
        }

        $isolationMode = config('corelix-platform.network_management.isolation_mode', 'environment');
        if ($isolationMode === 'none') {
            return;
        }

        $resolved = static::resolveResourceContext($resource, 'auto-attach');
        if (! $resolved) {
            return;
        }

        [$server, $environment] = $resolved;

        if (static::isSwarmServer($server)) {
            static::attachSwarmResourceToManagedNetworks($resource, $server, $environment);

            Log::info('NetworkService: Auto-attached Swarm resource to managed networks', [
                'resource_type' => get_class($resource),
                'resource_id' => $resource->id ?? null,
                'environment' => $environment->name,
            ]);

            return;
        }

        $containerNames = static::getContainerNames($resource);
        if (empty($containerNames)) {
            return;
        }

        static::attachContainersToManagedNetworks($resource, $server, $environment, $containerNames);

        Log::info('NetworkService: Auto-attached resource to managed networks', [
            'resource_type' => get_class($resource),
            'resource_id' => $resource->id ?? null,
            'environment' => $environment->name,
        ]);
    }

    /**
     * Auto-detach a resource from all managed networks.
     *
     * Called when a resource is deleted. Disconnects containers from all
     * managed networks and removes the pivot records.
     */
    public static function autoDetachResource($resource): void
    {
        $server = static::getServerForResource($resource);
        $resourceNetworks = ResourceNetwork::where('resource_type', get_class($resource))
            ->where('resource_id', $resource->id)
            ->with('managedNetwork')
            ->get();

        if ($resourceNetworks->isEmpty()) {
            return;
        }

        $containerNames = static::getContainerNames($resource);

        foreach ($resourceNetworks as $resourceNetwork) {
            $managedNetwork = $resourceNetwork->managedNetwork;
            if (! $managedNetwork) {
                $resourceNetwork->delete();

                continue;
            }

            // Disconnect containers from Docker network
            if ($server) {
                foreach ($containerNames as $containerName) {
                    static::disconnectContainer($server, $managedNetwork->docker_network_name, $containerName, true);
                }
            }

            $resourceNetwork->delete();
        }

        Log::info('NetworkService: Auto-detached resource from all managed networks', [
            'resource_type' => get_class($resource),
            'resource_id' => $resource->id ?? null,
            'networks_removed' => $resourceNetworks->count(),
        ]);
    }

    // ============================================================
    // Swarm Support
    // ============================================================

    /**
     * Check if a server is running Docker Swarm.
     */
    public static function isSwarmServer(Server $server): bool
    {
        return method_exists($server, 'isSwarm') && $server->isSwarm();
    }

    /**
     * Check if a server is a Swarm manager node.
     *
     * Network creation and service updates must be executed on manager nodes.
     */
    public static function isSwarmManager(Server $server): bool
    {
        return method_exists($server, 'isSwarmManager') && $server->isSwarmManager();
    }

    /**
     * Resolve the appropriate Docker network driver for a server.
     *
     * Returns 'overlay' for Swarm servers (multi-host networking)
     * and 'bridge' for standalone Docker servers (single-host).
     */
    public static function resolveNetworkDriver(Server $server): string
    {
        return static::isSwarmServer($server) ? 'overlay' : 'bridge';
    }

    /**
     * Get the Swarm service name(s) for a resource.
     *
     * In Docker Swarm, services are named {stack}_{service} when deployed
     * via docker stack deploy. Coolify uses the application UUID as the stack name.
     *
     * For Applications: docker stack deploy creates {app_uuid}_{container_name}
     * For Services: each sub-container is {service_uuid}_{sub_name}
     */
    public static function getSwarmServiceNames($resource, Server $server): array
    {
        $serviceNames = [];

        try {
            if ($resource instanceof Application) {
                // Coolify deploys Applications as a stack named by UUID
                // The service name follows the pattern: {uuid}_{container_name}
                // List actual services to get precise names
                $filterLabel = escapeshellarg("label=coolify.applicationId={$resource->id}");
                $output = instant_remote_process(
                    ["docker service ls --filter {$filterLabel} --format '{{{{.Name}}}}' 2>/dev/null || true"],
                    $server
                );

                if (! empty(trim($output))) {
                    $serviceNames = array_filter(explode("\n", trim($output)));
                }

                // Fallback: try the UUID as stack name
                if (empty($serviceNames)) {
                    $filterName = escapeshellarg("name={$resource->uuid}");
                    $output = instant_remote_process(
                        ["docker service ls --filter {$filterName} --format '{{{{.Name}}}}' 2>/dev/null || true"],
                        $server
                    );

                    if (! empty(trim($output))) {
                        $serviceNames = array_filter(explode("\n", trim($output)));
                    }
                }
            } elseif ($resource instanceof Service) {
                // Services have multiple sub-containers
                foreach ($resource->applications as $app) {
                    $filterName = escapeshellarg("name={$resource->uuid}_{$app->name}");
                    $output = instant_remote_process(
                        ["docker service ls --filter {$filterName} --format '{{{{.Name}}}}' 2>/dev/null || true"],
                        $server
                    );

                    if (! empty(trim($output))) {
                        $serviceNames = array_merge($serviceNames, array_filter(explode("\n", trim($output))));
                    }
                }
                foreach ($resource->databases as $db) {
                    $filterName = escapeshellarg("name={$resource->uuid}_{$db->name}");
                    $output = instant_remote_process(
                        ["docker service ls --filter {$filterName} --format '{{{{.Name}}}}' 2>/dev/null || true"],
                        $server
                    );

                    if (! empty(trim($output))) {
                        $serviceNames = array_merge($serviceNames, array_filter(explode("\n", trim($output))));
                    }
                }
            } else {
                // Standalone databases
                if (isset($resource->uuid)) {
                    $filterName = escapeshellarg("name={$resource->uuid}");
                    $output = instant_remote_process(
                        ["docker service ls --filter {$filterName} --format '{{{{.Name}}}}' 2>/dev/null || true"],
                        $server
                    );

                    if (! empty(trim($output))) {
                        $serviceNames = array_filter(explode("\n", trim($output)));
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('NetworkService: Failed to get Swarm service names', [
                'resource' => get_class($resource).'#'.($resource->id ?? '?'),
                'error' => $e->getMessage(),
            ]);
        }

        return array_values(array_unique($serviceNames));
    }

    /**
     * Update a Swarm service's network membership.
     *
     * Uses `docker service update --network-add/--network-rm` to modify
     * the service spec. This triggers a rolling update of the service tasks.
     *
     * Multiple networks can be added/removed in a single command to minimize
     * the number of rolling updates (one update per service, not per network).
     *
     * @param  Server  $server  The Swarm server (must be a manager node)
     * @param  string  $serviceName  The Docker service name
     * @param  array  $networksToAdd  Network names to add
     * @param  array  $networksToRemove  Network names to remove
     * @return bool True if the update succeeded
     */
    public static function updateSwarmServiceNetworks(
        Server $server,
        string $serviceName,
        array $networksToAdd = [],
        array $networksToRemove = []
    ): bool {
        if (empty($networksToAdd) && empty($networksToRemove)) {
            return true;
        }

        try {
            $parts = ['docker service update'];

            foreach ($networksToAdd as $network) {
                $parts[] = '--network-add '.escapeshellarg($network);
            }

            foreach ($networksToRemove as $network) {
                $parts[] = '--network-rm '.escapeshellarg($network);
            }

            // --detach to avoid blocking on convergence
            $parts[] = '--detach';
            $parts[] = escapeshellarg($serviceName);

            $command = implode(' ', $parts).' 2>/dev/null || true';

            instant_remote_process([$command], $server);

            $verified = static::verifySwarmServiceNetworks($server, $serviceName, $networksToAdd, $networksToRemove);
            if (! $verified) {
                Log::warning("NetworkService: Swarm service {$serviceName} network update could not be verified", [
                    'added' => $networksToAdd,
                    'removed' => $networksToRemove,
                ]);

                return false;
            }

            Log::info("NetworkService: Updated Swarm service {$serviceName} networks", [
                'added' => $networksToAdd,
                'removed' => $networksToRemove,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning("NetworkService: Failed to update Swarm service {$serviceName} networks", [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get the default Docker network name based on server type.
     *
     * Swarm uses 'coolify-overlay', standalone uses 'coolify'.
     */
    public static function getDefaultNetworkName(Server $server): string
    {
        return static::isSwarmServer($server) ? 'coolify-overlay' : 'coolify';
    }

    // ============================================================
    // Internal Helpers (shared logic extracted from public methods)
    // [CORELIX ENHANCED: refactored to eliminate duplication across reconciliation/attach methods]
    // ============================================================

    /**
     * Resolve the server and environment for a resource, logging warnings on failure.
     *
     * @return array{0: Server, 1: Environment}|null  Returns [server, environment] or null on failure.
     */
    protected static function resolveResourceContext($resource, string $operationLabel): ?array
    {
        $server = static::getServerForResource($resource);
        if (! $server) {
            Log::warning("NetworkService: Could not resolve server for {$operationLabel}", [
                'resource_type' => get_class($resource),
                'resource_id' => $resource->id ?? null,
            ]);

            return null;
        }

        $environment = static::getEnvironmentForResource($resource);
        if (! $environment) {
            Log::warning("NetworkService: Could not resolve environment for {$operationLabel}", [
                'resource_type' => get_class($resource),
                'resource_id' => $resource->id ?? null,
            ]);

            return null;
        }

        return [$server, $environment];
    }

    /**
     * Ensure a scoped network (environment or proxy) exists on a server.
     *
     * Shared implementation for ensureEnvironmentNetwork() and ensureProxyNetwork().
     * Handles network limit checks, driver resolution, firstOrCreate with race
     * condition handling, and Docker network creation for pending records.
     *
     * @param  Server  $server  Target server
     * @param  string  $dockerName  Docker network name (e.g. ce-env-xxx or ce-proxy-xxx)
     * @param  string  $humanName  Human-readable display name
     * @param  string  $scope  ManagedNetwork scope constant
     * @param  int  $teamId  Owning team ID
     * @param  array  $extraAttributes  Additional attributes for the ManagedNetwork record
     * @param  string  $limitErrorContext  Context string for network limit error messages
     */
    protected static function ensureScopedNetwork(
        Server $server,
        string $dockerName,
        string $humanName,
        string $scope,
        int $teamId,
        array $extraAttributes = [],
        string $limitErrorContext = 'network',
    ): ManagedNetwork {
        // Check network limit before creating new networks
        if (static::hasReachedNetworkLimit($server)) {
            // If this specific network already exists, return it regardless of limit
            $existing = ManagedNetwork::where('docker_network_name', $dockerName)
                ->where('server_id', $server->id)
                ->first();
            if ($existing) {
                return $existing;
            }

            Log::error("NetworkService: Network limit reached on server {$server->name}, cannot create {$limitErrorContext}");
            throw new \RuntimeException("Network limit reached on server {$server->name}. Increase CORELIX_MAX_NETWORKS or remove unused networks.");
        }

        $driver = static::resolveNetworkDriver($server);
        $options = [];
        if ($driver === 'overlay' && config('corelix-platform.network_management.swarm_overlay_encryption', false)) {
            $options['encrypted'] = true;
        }

        $attributes = array_merge([
            'uuid' => (string) new Cuid2,
            'name' => $humanName,
            'driver' => $driver,
            'scope' => $scope,
            'team_id' => $teamId,
            'is_attachable' => true,
            'is_internal' => false,
            'is_encrypted_overlay' => !empty($options['encrypted']),
            'options' => $options ?: null,
            'status' => ManagedNetwork::STATUS_PENDING,
        ], $extraAttributes);

        try {
            $network = ManagedNetwork::firstOrCreate(
                [
                    'docker_network_name' => $dockerName,
                    'server_id' => $server->id,
                ],
                $attributes
            );
        } catch (\Throwable $e) {
            // Race condition: another process created the record — fetch it
            $network = ManagedNetwork::where('docker_network_name', $dockerName)
                ->where('server_id', $server->id)
                ->firstOrFail();
        }

        // Create the Docker network if the record is still pending
        if ($network->status === ManagedNetwork::STATUS_PENDING) {
            static::createDockerNetwork($server, $network);
        }

        return $network;
    }

    /**
     * Create or update a resource-network pivot record.
     *
     * Centralizes the repeated ResourceNetwork::updateOrCreate() pattern
     * used throughout reconciliation and auto-attach methods.
     */
    protected static function upsertResourceNetworkPivot(
        $resource,
        int $managedNetworkId,
        bool $isConnected,
        bool $isAutoAttached = true,
        ?array $aliases = null,
    ): void {
        $attributes = [
            'is_connected' => $isConnected,
            'is_auto_attached' => $isAutoAttached,
            'connected_at' => $isConnected ? now() : null,
        ];

        if ($aliases !== null) {
            $attributes['aliases'] = $aliases;
        }

        ResourceNetwork::updateOrCreate(
            [
                'resource_type' => get_class($resource),
                'resource_id' => $resource->id,
                'managed_network_id' => $managedNetworkId,
            ],
            $attributes
        );
    }

    /**
     * Connect containers to a managed network and update pivot records.
     *
     * @param  array  $containerNames  Container names to connect
     * @return array<string,bool>  Map of container name => verified connect result
     */
    protected static function connectContainersAndRecord(
        $resource,
        Server $server,
        ManagedNetwork $network,
        array $containerNames,
        bool $isAutoAttached = true,
    ): array {
        $results = [];
        foreach ($containerNames as $containerName) {
            $results[$containerName] = static::connectContainer($server, $network->docker_network_name, $containerName);
        }

        $allConnected = ! empty($results) && ! in_array(false, $results, true);
        static::upsertResourceNetworkPivot($resource, $network->id, $allConnected, $isAutoAttached, $containerNames);

        return $results;
    }

    /**
     * Manually connect a resource to a managed network (API / Livewire attach paths).
     *
     * @return bool True only when every container verified connected
     */
    public static function connectResourceToNetwork($resource, ManagedNetwork $network, bool $autoAttached = false): bool
    {
        $server = static::getServerForResource($resource);
        if (! $server || $network->server_id !== $server->id) {
            return false;
        }

        $containerNames = static::getContainerNames($resource);
        if (empty($containerNames)) {
            return false;
        }

        $results = static::connectContainersAndRecord($resource, $server, $network, $containerNames, $autoAttached);

        return ! in_array(false, $results, true);
    }

    /**
     * Attach containers to environment network, proxy network (if applicable),
     * and handle strict isolation disconnect.
     *
     * Shared implementation for reconcileResource() and autoAttachResource()
     * on standalone Docker (non-Swarm) servers.
     */
    protected static function attachContainersToManagedNetworks(
        $resource,
        Server $server,
        Environment $environment,
        array $containerNames,
    ): void {
        // Ensure the environment network and connect containers
        $envNetwork = static::ensureEnvironmentNetwork($environment, $server);
        static::connectContainersAndRecord($resource, $server, $envNetwork, $containerNames);

        // Handle proxy network if the resource has an FQDN
        $proxyIsolation = config('corelix-platform.network_management.proxy_isolation', false);
        $hasFqdn = static::resourceHasFqdn($resource);
        $proxyNetwork = null;
        $proxyConnectResults = [];
        if ($proxyIsolation && $hasFqdn) {
            $proxyNetwork = static::ensureProxyNetwork($server);

            // [CORELIX ENHANCED: connect the reverse proxy to the proxy network here too.
            // Previously only the (never-dispatched) full server reconcile and the manual
            // ProxyMigrationJob did this, so the first FQDN resource on a server was
            // routed to a network the proxy had no interface on.]
            static::connectContainer($server, $proxyNetwork->docker_network_name, 'coolify-proxy');

            $proxyConnectResults = static::connectContainersAndRecord($resource, $server, $proxyNetwork, $containerNames);
        } elseif ($hasFqdn) {
            // [CORELIX ENHANCED: without proxy isolation an FQDN backend is
            // multi-homed on [coolify, ce-env-*]. Attach the proxy to the env
            // network immediately so it can reach the backend even if Traefik
            // selects the ce-env IP (adopted/custom-labelled containers with no
            // traefik.docker.network hint). This closes the window where the
            // stack hangs until a manual proxy restart; the proxy stays on
            // `coolify`, so this is purely additive reachability. The proxy-
            // config path (collectDockerNetworksByServer) keeps it attached
            // across proxy restarts / ServerCheckJob reconnects.]
            static::connectContainer($server, $envNetwork->docker_network_name, 'coolify-proxy');
        }

        // If strict isolation mode: disconnect from the default 'coolify' network
        if (static::shouldDisconnectFromDefaultNetwork($resource)) {
            $defaultNetwork = static::getDefaultNetworkName($server);
            foreach ($containerNames as $containerName) {
                if ($hasFqdn && ! static::isSafeToCutDefaultIngress(
                    $server,
                    $containerName,
                    $defaultNetwork,
                    $proxyNetwork,
                    $proxyConnectResults[$containerName] ?? false,
                )) {
                    Log::info('NetworkService: Keeping container on default network — ingress would break', [
                        'container' => $containerName,
                        'network' => $defaultNetwork,
                    ]);

                    continue;
                }

                static::disconnectContainer($server, $defaultNetwork, $containerName, true);
            }
        }
    }

    /**
     * Determine whether an FQDN-bearing container can safely be removed from
     * the default ingress network in strict mode.
     *
     * Safe only when:
     * 1. The container is verified connected to the managed proxy network, AND
     * 2. The container's baked ingress label (traefik.docker.network /
     *    caddy_ingress_network) does not point at the default network.
     *
     * Labels are baked at deploy time; on the first deploy after enabling proxy
     * isolation the label may still point at 'coolify' (the proxy network did
     * not exist when labels were generated). Cutting the default network in
     * that state breaks ingress until the next redeploy.
     */
    protected static function isSafeToCutDefaultIngress(
        Server $server,
        string $containerName,
        string $defaultNetwork,
        ?ManagedNetwork $proxyNetwork,
        bool $proxyConnected,
    ): bool {
        if (! $proxyNetwork || ! $proxyConnected) {
            return false;
        }

        $ingressLabel = static::getContainerIngressLabel($server, $containerName);

        // Unknown label state (inspect failed or no label baked) — be conservative
        if ($ingressLabel === null) {
            return false;
        }

        return $ingressLabel !== $defaultNetwork;
    }

    /**
     * Read the runtime ingress network label from a container.
     *
     * Returns the value of traefik.docker.network (preferred) or
     * caddy_ingress_network, or null when neither is set / inspect fails.
     */
    protected static function getContainerIngressLabel(Server $server, string $containerName): ?string
    {
        try {
            $command = 'docker inspect --format \'{{json .Config.Labels}}\' '.escapeshellarg($containerName).' 2>/dev/null';
            $output = instant_remote_process([$command], $server);

            if (empty(trim((string) $output))) {
                return null;
            }

            $labels = json_decode($output, true);
            if (! is_array($labels)) {
                return null;
            }

            $value = $labels['traefik.docker.network'] ?? $labels['caddy_ingress_network'] ?? null;

            return is_string($value) && $value !== '' ? $value : null;
        } catch (\Throwable $e) {
            Log::warning('NetworkService: Failed to read ingress label from container', [
                'server' => $server->name,
                'container' => $containerName,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Attach a Swarm resource to managed networks via service update.
     *
     * Shared implementation for Swarm reconciliation and auto-attach.
     * Builds the networks-to-add/remove lists, updates each Swarm service,
     * and creates pivot records on success.
     */
    protected static function attachSwarmResourceToManagedNetworks(
        $resource,
        Server $server,
        Environment $environment,
    ): void {
        $envNetwork = static::ensureEnvironmentNetwork($environment, $server);

        $baseNetworksToAdd = [$envNetwork->docker_network_name];

        // Proxy network
        $proxyIsolation = config('corelix-platform.network_management.proxy_isolation', false);
        $proxyNetwork = null;
        $hasFqdn = static::resourceHasFqdn($resource);
        if ($proxyIsolation && $hasFqdn) {
            $proxyNetwork = static::ensureProxyNetwork($server);
            $baseNetworksToAdd[] = $proxyNetwork->docker_network_name;

            // [CORELIX ENHANCED: ensure reverse proxy joins proxy overlay on Swarm reconcile]
            static::connectProxyContainer($server);
        }

        $defaultNetwork = static::getDefaultNetworkName($server);
        $strictMode = static::shouldDisconnectFromDefaultNetwork($resource);

        $serviceNames = static::getSwarmServiceNames($resource, $server);
        foreach ($serviceNames as $serviceName) {
            $networksToAdd = $baseNetworksToAdd;
            $networksToRemove = [];

            if ($strictMode) {
                $onProxy = $proxyNetwork
                    && static::isSwarmServiceOnNetwork($server, $serviceName, $proxyNetwork->docker_network_name);
                if (static::isSafeToCutDefaultIngressSwarm(
                    $server,
                    $serviceName,
                    $defaultNetwork,
                    $proxyNetwork,
                    $onProxy,
                )) {
                    $networksToRemove[] = $defaultNetwork;
                }
            }

            $success = static::updateSwarmServiceNetworks($server, $serviceName, $networksToAdd, $networksToRemove);

            if ($success) {
                static::upsertResourceNetworkPivot($resource, $envNetwork->id, true, true, $serviceNames);

                if ($proxyNetwork) {
                    static::upsertResourceNetworkPivot($resource, $proxyNetwork->id, true, true, $serviceNames);
                }
            }
        }
    }

    /**
     * Connect a single resource to a proxy network during migration.
     *
     * Handles both Swarm and standalone Docker servers.
     * Updates the results array in-place with migration/failure counts.
     */
    protected static function migrateResourceToProxyNetwork(
        $resource,
        Server $server,
        ManagedNetwork $proxyNetwork,
        array &$results,
    ): void {
        $typeLabel = $resource instanceof Application ? 'Application' : 'Service';

        try {
            if (static::isSwarmServer($server)) {
                $serviceNames = static::getSwarmServiceNames($resource, $server);
                $connectResults = [];
                foreach ($serviceNames as $serviceName) {
                    $connectResults[] = static::updateSwarmServiceNetworks($server, $serviceName, [$proxyNetwork->docker_network_name]);
                }
                $allConnected = ! empty($connectResults) && ! in_array(false, $connectResults, true);

                // Record the verified connection state — never assume success.
                static::upsertResourceNetworkPivot($resource, $proxyNetwork->id, $allConnected);
            } else {
                // connectContainersAndRecord() connects each container, verifies via
                // docker inspect, and upserts the pivot with the computed allConnected
                // (pitfall #36 — a failed/unverified connect must not record is_connected=true).
                $containerNames = static::getContainerNames($resource);
                $connectResults = static::connectContainersAndRecord($resource, $server, $proxyNetwork, $containerNames);
                $allConnected = ! empty($connectResults) && ! in_array(false, $connectResults, true);
            }

            if ($allConnected) {
                $results['resources_migrated']++;
            } else {
                $results['resources_failed']++;
                $results['errors'][] = "{$typeLabel} {$resource->uuid}: not all containers connected to proxy network (left pivot is_connected=false)";
            }
        } catch (\Throwable $e) {
            $results['resources_failed']++;
            $results['errors'][] = "{$typeLabel} {$resource->uuid}: {$e->getMessage()}";
        }
    }

    // ============================================================
    // Helpers
    // ============================================================

    /**
     * Get the server for a resource.
     *
     * Supports Application, Service, and standalone database types.
     * Uses the same resolution pattern as ScheduledResourceBackup::server().
     */
    public static function getServerForResource($resource): ?Server
    {
        if (! $resource) {
            return null;
        }

        if ($resource instanceof Server) {
            return $resource;
        }

        // Application — uses destination->server
        if ($resource instanceof Application) {
            return $resource->destination->server ?? null;
        }

        // Service — has a direct server relationship
        if ($resource instanceof Service) {
            return $resource->server ?? null;
        }

        // Standalone databases — use destination->server
        if (method_exists($resource, 'destination') && $resource->destination) {
            return $resource->destination->server ?? null;
        }

        // Direct server relationship fallback
        if (method_exists($resource, 'server') && $resource->server) {
            return $resource->server;
        }

        return null;
    }

    /**
     * Get the environment for a resource.
     */
    public static function getEnvironmentForResource($resource): ?Environment
    {
        if (! $resource) {
            return null;
        }

        // Service sub-resources (ServiceDatabase, ServiceApplication) don't have direct environment()
        if ($resource instanceof \App\Models\ServiceDatabase || $resource instanceof \App\Models\ServiceApplication) {
            return $resource->service?->environment;
        }

        // Application, Service, and standalone databases all have an environment relationship
        if (method_exists($resource, 'environment')) {
            return $resource->environment ?? null;
        }

        return null;
    }

    /**
     * Get container name(s) for a resource.
     *
     * Applications and standalone databases use their UUID.
     * Services list all sub-containers (applications + databases) using
     * the {name}-{service_uuid} convention.
     */
    public static function getContainerNames($resource): array
    {
        $server = static::getServerForResource($resource);
        if ($server && ! static::isSwarmServer($server)) {
            $runtimeContainerNames = static::getContainerNamesFromDockerLabels($resource, $server);
            if (! empty($runtimeContainerNames)) {
                return $runtimeContainerNames;
            }
        }

        if ($resource instanceof Application) {
            return [$resource->uuid];
        }

        if ($resource instanceof Service) {
            $containers = [];
            foreach ($resource->applications as $app) {
                $containers[] = "{$app->name}-{$resource->uuid}";
            }
            foreach ($resource->databases as $db) {
                $containers[] = "{$db->name}-{$resource->uuid}";
            }

            return $containers;
        }

        // Standalone databases use uuid
        if (isset($resource->uuid)) {
            return [$resource->uuid];
        }

        return [];
    }

    /**
     * Find runtime container names using Coolify labels.
     *
     * This avoids relying solely on naming conventions, which can drift for
     * existing resources and break post-deploy network assignments.
     */
    protected static function getContainerNamesFromDockerLabels($resource, Server $server): array
    {
        $labelFilter = static::getResourceDockerLabelFilter($resource);
        if (! $labelFilter) {
            return [];
        }

        try {
            // Running containers only (no -a). A stopped/old container still
            // reports the network in its own `.NetworkSettings.Networks` (so
            // per-container verification would pass) yet never appears in
            // `docker network inspect` — the exact false-positive that made the
            // UI show "connected" while the network listed nothing. Membership
            // for stopped resources is restored from compose on next start.
            $escapedFilter = escapeshellarg("label={$labelFilter}");
            $command = "docker ps --filter {$escapedFilter} --format '{{.Names}}' 2>/dev/null";
            $output = instant_remote_process([$command], $server);

            if (empty(trim((string) $output))) {
                return [];
            }

            return collect(explode("\n", trim((string) $output)))
                ->map(fn ($name) => trim($name))
                ->filter(fn ($name) => $name !== '')
                ->unique()
                ->values()
                ->all();
        } catch (\Throwable $e) {
            Log::warning('NetworkService: Failed to discover runtime container names from labels', [
                'resource_type' => get_class($resource),
                'resource_id' => $resource->id ?? null,
                'server' => $server->name,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Resolve the Docker label filter used by Coolify for a resource type.
     */
    protected static function getResourceDockerLabelFilter($resource): ?string
    {
        if (! isset($resource->id)) {
            return null;
        }

        if ($resource instanceof Application) {
            return "coolify.applicationId={$resource->id}";
        }

        if ($resource instanceof Service) {
            return "coolify.serviceId={$resource->id}";
        }

        if (str_starts_with(get_class($resource), 'App\\Models\\Standalone')) {
            return "coolify.databaseId={$resource->id}";
        }

        return null;
    }

    /**
     * Check if a resource has an FQDN (needs proxy network).
     */
    public static function resourceHasFqdn($resource): bool
    {
        if (! empty($resource->fqdn ?? null)) {
            return true;
        }

        // For Services, check if any sub-container (ServiceApplication) has an FQDN
        if ($resource instanceof Service) {
            return $resource->applications->contains(fn ($app) => ! empty($app->fqdn));
        }

        return false;
    }

    /**
     * Determine if strict mode should disconnect a resource from the default network.
     *
     * FQDN-bearing resources must stay on the default network when proxy isolation
     * is disabled, otherwise ingress is cut off.
     */
    public static function shouldDisconnectFromDefaultNetwork($resource): bool
    {
        $isolationModeDefault = env('CORELIX_NETWORK_ISOLATION', env('CORELIX_NETWORK_ISOLATION_MODE', 'environment'));
        $isolationMode = static::getConfigValue('corelix-platform.network_management.isolation_mode', $isolationModeDefault);
        if ($isolationMode !== 'strict') {
            return false;
        }

        $proxyIsolationDefault = filter_var(env('CORELIX_PROXY_ISOLATION', false), FILTER_VALIDATE_BOOLEAN);
        $proxyIsolation = static::getConfigValue('corelix-platform.network_management.proxy_isolation', $proxyIsolationDefault);
        if (! $proxyIsolation && static::resourceHasFqdn($resource)) {
            return false;
        }

        return true;
    }

    /**
     * Read config safely in contexts where the Laravel container may not be booted.
     */
    protected static function getConfigValue(string $key, $default = null)
    {
        try {
            return config($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * Check whether a container is currently connected to a network.
     */
    /**
     * Debounce server-wide reconciliation to avoid thundering herds from
     * team-wide status events (ServiceStatusChanged / DatabaseStatusChanged).
     */
    public static function dispatchDebouncedServerReconcile(Server $server, int $delaySeconds = 3): void
    {
        $debounceSeconds = max(60, $delaySeconds + 45);
        $cacheKey = "network-reconcile-debounce-server-{$server->id}";

        if (! \Illuminate\Support\Facades\Cache::add($cacheKey, true, now()->addSeconds($debounceSeconds))) {
            return;
        }

        \CorelixIo\Platform\Jobs\NetworkReconcileJob::dispatch($server, fullReconcile: true)
            ->delay(now()->addSeconds($delaySeconds));
    }

    /**
     * Collect unique server IDs for all services in a team.
     *
     * @return array<int,int>
     */
    public static function getServerIdsForTeamServices(int $teamId): array
    {
        return Service::whereHas('environment.project.team', function ($q) use ($teamId) {
            $q->where('id', $teamId);
        })
            ->whereNotNull('server_id')
            ->distinct()
            ->pluck('server_id')
            ->all();
    }

    /**
     * Collect unique server IDs for all standalone databases in a team.
     *
     * @return array<int,int>
     */
    public static function getServerIdsForTeamDatabases(int $teamId): array
    {
        $serverIds = [];
        $databaseClasses = [
            \App\Models\StandalonePostgresql::class,
            \App\Models\StandaloneMysql::class,
            \App\Models\StandaloneMariadb::class,
            \App\Models\StandaloneMongodb::class,
            \App\Models\StandaloneRedis::class,
            \App\Models\StandaloneKeydb::class,
            \App\Models\StandaloneDragonfly::class,
            \App\Models\StandaloneClickhouse::class,
        ];

        foreach ($databaseClasses as $dbClass) {
            if (! class_exists($dbClass)) {
                continue;
            }

            $ids = $dbClass::whereHas('environment.project.team', function ($q) use ($teamId) {
                $q->where('id', $teamId);
            })
                ->whereHas('destination')
                ->with('destination:id,server_id')
                ->get()
                ->pluck('destination.server_id')
                ->filter()
                ->unique()
                ->all();

            $serverIds = array_merge($serverIds, $ids);
        }

        return array_values(array_unique($serverIds));
    }

    /**
     * Verify a Swarm service has the expected network membership after update.
     */
    protected static function verifySwarmServiceNetworks(
        Server $server,
        string $serviceName,
        array $networksToAdd,
        array $networksToRemove,
    ): bool {
        for ($attempt = 0; $attempt < 4; $attempt++) {
            if ($attempt > 0) {
                sleep(2);
            }

            $attached = static::getSwarmServiceNetworkNames($server, $serviceName);
            if ($attached === null) {
                continue;
            }

            $addOk = empty($networksToAdd) || count(array_intersect($networksToAdd, $attached)) === count($networksToAdd);
            $removeOk = empty(array_intersect($networksToRemove, $attached));

            if ($addOk && $removeOk) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int,string>|null
     */
    protected static function getSwarmServiceNetworkNames(Server $server, string $serviceName): ?array
    {
        try {
            $escapedService = escapeshellarg($serviceName);
            $command = "for id in \$(docker service inspect {$escapedService} --format '{{range .Spec.TaskTemplate.Networks}}{{.Target}} {{end}}' 2>/dev/null); do docker network inspect \"\$id\" --format '{{.Name}}' 2>/dev/null; done";

            $output = instant_remote_process([$command], $server);
            if (empty(trim((string) $output))) {
                return null;
            }

            return array_values(array_filter(array_map('trim', explode("\n", trim($output)))));
        } catch (\Throwable $e) {
            Log::warning('NetworkService: Failed to list Swarm service networks', [
                'service' => $serviceName,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected static function isSwarmServiceOnNetwork(Server $server, string $serviceName, string $networkName): bool
    {
        $names = static::getSwarmServiceNetworkNames($server, $serviceName);

        return $names !== null && in_array($networkName, $names, true);
    }

    /**
     * Swarm equivalent of isSafeToCutDefaultIngress — inspects service task labels.
     */
    protected static function isSafeToCutDefaultIngressSwarm(
        Server $server,
        string $serviceName,
        string $defaultNetwork,
        ?ManagedNetwork $proxyNetwork,
        bool $onProxyNetwork,
    ): bool {
        if (! $proxyNetwork || ! $onProxyNetwork) {
            return false;
        }

        $ingressLabel = static::getSwarmServiceIngressLabel($server, $serviceName);
        if ($ingressLabel === null) {
            return false;
        }

        return $ingressLabel !== $defaultNetwork;
    }

    protected static function getSwarmServiceIngressLabel(Server $server, string $serviceName): ?string
    {
        try {
            $command = 'docker service inspect --format \'{{json .Spec.TaskTemplate.ContainerSpec.Labels}}\' '
                .escapeshellarg($serviceName).' 2>/dev/null';
            $output = instant_remote_process([$command], $server);

            if (empty(trim((string) $output))) {
                return null;
            }

            $labels = json_decode($output, true);
            if (! is_array($labels)) {
                return null;
            }

            $value = $labels['traefik.docker.network'] ?? $labels['caddy_ingress_network'] ?? null;

            return is_string($value) && $value !== '' ? $value : null;
        } catch (\Throwable $e) {
            Log::warning('NetworkService: Failed to read ingress label from Swarm service', [
                'service' => $serviceName,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected static function isContainerConnectedToNetwork(Server $server, string $containerName, string $networkName): ?bool
    {
        try {
            $command = 'docker inspect --format=\'{{json .NetworkSettings.Networks}}\' '.escapeshellarg($containerName).' 2>/dev/null';
            $output = instant_remote_process([$command], $server);

            if (empty(trim((string) $output))) {
                return null;
            }

            $networks = json_decode($output, true);
            if (! is_array($networks)) {
                return null;
            }

            return array_key_exists($networkName, $networks);
        } catch (\Throwable $e) {
            Log::warning('NetworkService: Failed to verify container network membership', [
                'server' => $server->name,
                'container' => $containerName,
                'network' => $networkName,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get the proxy network name for a server.
     *
     * Returns the Docker network name of the active proxy network,
     * or null if proxy isolation is disabled or no proxy network exists.
     */
    public static function getProxyNetworkName(Server $server): ?string
    {
        if (! config('corelix-platform.network_management.proxy_isolation', false)
            || ! config('corelix-platform.network_management.enabled', false)) {
            return null;
        }

        $proxyNetwork = ManagedNetwork::where('server_id', $server->id)
            ->where('is_proxy_network', true)
            ->where('status', ManagedNetwork::STATUS_ACTIVE)
            ->first();

        return $proxyNetwork?->docker_network_name;
    }

    /**
     * Connect the proxy container (coolify-proxy) to the proxy network.
     *
     * Called during proxy isolation migration and reconciliation to ensure
     * the reverse proxy can reach resources on the proxy network.
     */
    public static function connectProxyContainer(Server $server): bool
    {
        $proxyNetwork = static::ensureProxyNetwork($server);

        return static::connectContainer($server, $proxyNetwork->docker_network_name, 'coolify-proxy');
    }

    /**
     * Disconnect proxy from networks that are NOT proxy networks.
     *
     * Used after proxy isolation migration is complete and all resources
     * have been redeployed with traefik.docker.network labels.
     * Keeps the default coolify/coolify-overlay network for safety.
     */
    public static function disconnectProxyFromNonProxyNetworks(Server $server): array
    {
        $results = [];
        $defaultNetwork = static::isSwarmServer($server) ? 'coolify-overlay' : 'coolify';

        // Inspect the proxy container to get its connected networks
        try {
            $output = instant_remote_process(
                ['docker inspect --format=\'{{json .NetworkSettings.Networks}}\' coolify-proxy 2>/dev/null'],
                $server
            );

            $connectedNetworks = array_keys(json_decode($output, true) ?? []);
        } catch (\Throwable $e) {
            Log::warning('NetworkService: Failed to inspect proxy container networks', [
                'error' => $e->getMessage(),
            ]);

            return $results;
        }

        // Get proxy network names
        $proxyNetworkNames = ManagedNetwork::where('server_id', $server->id)
            ->where('is_proxy_network', true)
            ->pluck('docker_network_name')
            ->toArray();

        foreach ($connectedNetworks as $networkName) {
            // Keep default network and proxy networks
            if ($networkName === $defaultNetwork || in_array($networkName, $proxyNetworkNames)) {
                continue;
            }

            // Skip Docker predefined networks
            if (in_array($networkName, ['bridge', 'host', 'none'])) {
                continue;
            }

            $disconnected = static::disconnectContainer($server, $networkName, 'coolify-proxy');
            $results[$networkName] = $disconnected;
        }

        Log::info('NetworkService: Disconnected proxy from non-proxy networks', [
            'server' => $server->name,
            'disconnected' => array_keys(array_filter($results)),
        ]);

        return $results;
    }

    /**
     * Migrate a server to proxy isolation.
     *
     * Steps:
     * 1. Create/ensure the proxy network exists
     * 2. Connect the proxy container to it
     * 3. Connect all FQDN-bearing resource containers to the proxy network
     * 4. Return migration status
     *
     * Does NOT disconnect from old networks — that's a separate step
     * after all resources have been redeployed with traefik.docker.network labels.
     */
    public static function migrateToProxyIsolation(Server $server): array
    {
        $results = [
            'proxy_network' => null,
            'proxy_connected' => false,
            'resources_migrated' => 0,
            'resources_failed' => 0,
            'errors' => [],
        ];

        try {
            // 1. Ensure proxy network exists
            $proxyNetwork = static::ensureProxyNetwork($server);
            $results['proxy_network'] = $proxyNetwork->docker_network_name;

            // 2. Connect proxy container
            $results['proxy_connected'] = static::connectProxyContainer($server);

            // 3. Find all FQDN-bearing resources on this server and connect them
            $applications = Application::whereHas('destination', function ($q) use ($server) {
                $q->where('server_id', $server->id);
            })->whereNotNull('fqdn')->where('fqdn', '!=', '')->get();

            foreach ($applications as $app) {
                static::migrateResourceToProxyNetwork($app, $server, $proxyNetwork, $results);
            }

            // Also handle Services with FQDNs
            $services = Service::where('server_id', $server->id)->get();
            foreach ($services as $service) {
                if (! static::resourceHasFqdn($service)) {
                    continue;
                }

                static::migrateResourceToProxyNetwork($service, $server, $proxyNetwork, $results);
            }

            Log::info('NetworkService: Proxy isolation migration complete', $results);
        } catch (\Throwable $e) {
            $results['errors'][] = $e->getMessage();
            Log::error('NetworkService: Proxy isolation migration failed', [
                'server' => $server->name,
                'error' => $e->getMessage(),
            ]);
        }

        return $results;
    }

    /**
     * Generate the Docker network name for a given scope and identifier.
     *
     * Format: {prefix}-{scope}-{identifier}
     * Example: ce-env-clxy1234abcd
     */
    public static function generateNetworkName(string $scope, string $identifier): string
    {
        $prefix = config('corelix-platform.network_management.prefix', self::PREFIX);

        return "{$prefix}-{$scope}-{$identifier}";
    }

    /**
     * Check if the maximum network limit has been reached for a server.
     *
     * Prevents unbounded network creation which could exhaust Docker resources.
     */
    public static function hasReachedNetworkLimit(Server $server): bool
    {
        $limit = config('corelix-platform.network_management.max_networks_per_server', 200);

        return ManagedNetwork::forServer($server)->count() >= $limit;
    }
}
