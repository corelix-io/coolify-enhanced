<?php

namespace CorelixIo\Platform\Services;

use CorelixIo\Platform\Models\ManagedNetwork;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Yaml;

/**
 * Persists managed-network membership INTO the docker-compose definition so it
 * survives container recreation (redeploy, `docker compose up`, server reboot).
 *
 * Why this exists
 * ---------------
 * NetworkService attaches containers to managed networks (ce-env-*, ce-proxy-*)
 * at runtime via `docker network connect`. That attachment is ephemeral: Docker
 * drops it whenever the container is recreated. The resource_networks pivot,
 * however, keeps is_connected=true — so the UI reports "connected" while
 * `docker network inspect` shows nothing. By declaring the managed network as an
 * `external` network in the compose file and adding it to each service's
 * `networks:` block, Docker re-establishes membership on every recreation and
 * the runtime connect becomes a fallback rather than the source of truth.
 *
 * Safety
 * ------
 * We only ever inject networks that ALREADY EXIST on the host (verified via
 * `docker network inspect`). Referencing a non-existent `external` network would
 * make `docker compose up` fail hard, so a stale/missing network is skipped and
 * the runtime-connect + drift-reconcile path handles it instead. On the very
 * first deploy the network does not exist yet, so nothing is injected; the
 * post-deploy reconcile creates it and connects the container, and every
 * subsequent deploy then persists the membership. Combined with the scheduled
 * membership reconcile this self-heals without ever risking a broken deploy.
 *
 * This class is part of the FREE NETWORK_MANAGEMENT feature and must stay
 * self-contained — do NOT depend on any pro-only service.
 */
class ManagedNetworkComposeService
{
    /**
     * Resolve the docker network names a resource should PERSISTENTLY join,
     * verified to exist on the host.
     *
     * @return string[]
     */
    public static function resolveNetworkNames($resource, $server, $environment, bool $verify = true): array
    {
        if (! config('corelix-platform.enabled', false)
            || ! config('corelix-platform.network_management.enabled', false)) {
            return [];
        }

        if (config('corelix-platform.network_management.isolation_mode', 'environment') === 'none') {
            return [];
        }

        if (! config('corelix-platform.network_management.persist_in_compose', true)) {
            return [];
        }

        if (! $server) {
            return [];
        }

        // Swarm services declare networks differently (docker service update).
        // We only persist via compose for standalone Docker.
        if (NetworkService::isSwarmServer($server)) {
            return [];
        }

        $names = [];

        // Environment network
        if ($environment) {
            $env = ManagedNetwork::where('server_id', $server->id)
                ->where('environment_id', $environment->id)
                ->where('scope', ManagedNetwork::SCOPE_ENVIRONMENT)
                ->where('status', ManagedNetwork::STATUS_ACTIVE)
                ->first();

            if ($env && static::networkUsable($server, $env->docker_network_name, $verify)) {
                $names[] = $env->docker_network_name;
            }
        }

        // Proxy network — only for FQDN-bearing resources under proxy isolation
        if (config('corelix-platform.network_management.proxy_isolation', false)
            && NetworkService::resourceHasFqdn($resource)) {
            $proxy = ManagedNetwork::where('server_id', $server->id)
                ->where('is_proxy_network', true)
                ->where('status', ManagedNetwork::STATUS_ACTIVE)
                ->first();

            if ($proxy && static::networkUsable($server, $proxy->docker_network_name, $verify)) {
                $names[] = $proxy->docker_network_name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Verify a network exists on the host before we reference it as external.
     */
    protected static function networkUsable($server, string $networkName, bool $verify): bool
    {
        if (! $verify) {
            return true;
        }

        try {
            return NetworkService::inspectNetwork($server, $networkName) !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Inject external managed networks into a parsed compose array. Pure function.
     *
     * Only services that ALREADY declare a `networks` block are touched, so we
     * never accidentally detach a service from Coolify's default network by
     * introducing an explicit (and therefore exclusive) network list.
     *
     * @param  string[]  $networkNames
     * @return array|null  Modified compose array, or null when nothing changed.
     */
    public static function injectNetworks(array $compose, array $networkNames): ?array
    {
        if (empty($networkNames) || ! isset($compose['services']) || ! is_array($compose['services'])) {
            return null;
        }

        $modified = false;
        $injected = [];

        foreach ($compose['services'] as $svcName => &$svc) {
            if (! is_array($svc) || ! array_key_exists('networks', $svc)) {
                continue;
            }

            foreach ($networkNames as $net) {
                if (static::serviceHasNetwork($svc['networks'], $net)) {
                    continue;
                }

                $svc['networks'] = static::addServiceNetwork($svc['networks'], $net, (string) $svcName);
                $injected[$net] = true;
                $modified = true;
            }
        }
        unset($svc);

        // Only declare the top-level external network when a service actually
        // joined it — never leave a dangling external reference no service uses.
        foreach (array_keys($injected) as $net) {
            if (! isset($compose['networks'][$net])) {
                // External networks may only carry `external` and `name`; other
                // keys (driver/attachable/...) trigger compose validation errors.
                $compose['networks'][$net] = [
                    'external' => true,
                    'name' => $net,
                ];
            }
        }

        return $modified ? $compose : null;
    }

    /**
     * Whether a compose service `networks` value already includes $net
     * (supports both list and map forms).
     */
    protected static function serviceHasNetwork($networks, string $net): bool
    {
        if (! is_array($networks)) {
            return false;
        }

        // map form: key present
        if (array_key_exists($net, $networks)) {
            return true;
        }

        // list form: value present
        return in_array($net, $networks, true);
    }

    /**
     * Add $net to a compose service `networks` value, preserving its form.
     */
    protected static function addServiceNetwork($networks, string $net, string $alias)
    {
        if (! is_array($networks)) {
            return $networks;
        }

        // Preserve list form (sequential integer keys) or empty.
        $isList = $networks === [] || array_keys($networks) === range(0, count($networks) - 1);

        if ($isList) {
            $networks[] = $net;

            return $networks;
        }

        // Map form — attach an alias so intra-network DNS resolves the service.
        $networks[$net] = ['aliases' => [$alias]];

        return $networks;
    }

    // ============================================================
    // Observer entry points (services + compose-based applications)
    //
    // Same architectural hook as IngressNetworkLabelService: parsers.php calls
    // $resource->save() internally, firing the Eloquent 'updating' event with a
    // freshly computed docker_compose. We post-process it before persistence.
    // ============================================================

    public static function applyToService($service): void
    {
        try {
            if (! $service->isDirty('docker_compose')) {
                return;
            }

            $server = $service->server ?? null;
            $environment = $service->environment ?? null;
            if (! $server || ! $environment) {
                return;
            }

            $names = static::resolveNetworkNames($service, $server, $environment);
            if (empty($names)) {
                return;
            }

            $compose = Yaml::parse($service->docker_compose ?? '');
            if (! is_array($compose)) {
                return;
            }

            $modified = static::injectNetworks($compose, $names);
            if ($modified !== null) {
                $service->docker_compose = Yaml::dump($modified, 10, 2);
            }
        } catch (\Throwable $e) {
            Log::warning('ManagedNetworkComposeService: Failed to inject managed networks into service compose', [
                'service_id' => $service->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function applyToApplication($application): void
    {
        try {
            if (! $application->isDirty('docker_compose')) {
                return;
            }

            if (($application->build_pack ?? '') !== 'dockercompose') {
                return;
            }

            $server = $application->destination->server ?? null;
            $environment = $application->environment ?? null;
            if (! $server || ! $environment) {
                return;
            }

            $names = static::resolveNetworkNames($application, $server, $environment);
            if (empty($names)) {
                return;
            }

            $compose = Yaml::parse($application->docker_compose ?? '');
            if (! is_array($compose)) {
                return;
            }

            $modified = static::injectNetworks($compose, $names);
            if ($modified !== null) {
                $application->docker_compose = Yaml::dump($modified, 10, 2);
            }
        } catch (\Throwable $e) {
            Log::warning('ManagedNetworkComposeService: Failed to inject managed networks into application compose', [
                'application_id' => $application->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
