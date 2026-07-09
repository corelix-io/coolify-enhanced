<?php

namespace CorelixIo\Platform\Services;

use CorelixIo\Platform\Models\ManagedNetwork;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Yaml;

/**
 * Injects deterministic ingress-network labels (traefik.docker.network /
 * caddy_ingress_network) into compose definitions computed by Coolify's
 * parsers.php — without overlaying parsers.php.
 *
 * Why: upstream applicationParser()/serviceParser() generate Traefik/Caddy
 * labels WITHOUT a network hint. When network management later attaches the
 * container to additional managed networks (ce-env-*, ce-proxy-*), the
 * container becomes multi-homed and Traefik picks a network IP
 * non-deterministically — often one the proxy has no interface on,
 * causing 502s/timeouts.
 *
 * How: same architectural hook as LabelOverrideService (Traefik Label
 * Overrides, pro) — parsers.php calls $resource->save() internally, which
 * fires the Eloquent 'updating' event. We post-process the freshly computed
 * docker_compose before it is persisted. Zero overlay required.
 *
 * NOTE: this class is part of the FREE NETWORK_MANAGEMENT feature and must
 * stay self-contained — do NOT depend on LabelOverrideService (pro-only,
 * stripped from free builds).
 */
class IngressNetworkLabelService
{
    /**
     * Observer entry point for Service::updating.
     *
     * Fires inside serviceParser()'s internal $resource->save() — the freshly
     * computed compose is dirty at this point.
     */
    public static function applyToService($service): void
    {
        try {
            if (! $service->isDirty('docker_compose')) {
                return;
            }

            $server = $service->server ?? null;
            if (! $server) {
                return;
            }

            $ingress = static::resolveIngressForServer($server);
            if (! $ingress) {
                return;
            }

            $compose = Yaml::parse($service->docker_compose ?? '');
            if (! is_array($compose)) {
                return;
            }

            $modified = static::injectIngressLabels(
                $compose,
                $ingress['network'],
                $ingress['is_proxy'],
                static::replaceableCaddyValuesForService($service),
            );

            if ($modified !== null) {
                $service->docker_compose = Yaml::dump($modified, 10, 2);
            }
        } catch (\Throwable $e) {
            // Never break serviceParser's save flow
            Log::warning('IngressNetworkLabelService: Failed to inject ingress labels into service compose', [
                'service_id' => $service->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Observer entry point for Application::updating.
     *
     * Only docker-compose build pack applications are processed — all other
     * build packs receive the label via the generateLabelsApplication()
     * overlay in src/Overrides/Helpers/docker.php.
     */
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
            if (! $server) {
                return;
            }

            $ingress = static::resolveIngressForServer($server);
            if (! $ingress) {
                return;
            }

            $compose = Yaml::parse($application->docker_compose ?? '');
            if (! is_array($compose)) {
                return;
            }

            $modified = static::injectIngressLabels(
                $compose,
                $ingress['network'],
                $ingress['is_proxy'],
                static::replaceableCaddyValuesForApplication($application),
            );

            if ($modified !== null) {
                $application->docker_compose = Yaml::dump($modified, 10, 2);
            }
        } catch (\Throwable $e) {
            Log::warning('IngressNetworkLabelService: Failed to inject ingress labels into application compose', [
                'application_id' => $application->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolve the effective ingress network for a server.
     *
     * - Proxy isolation enabled + ACTIVE ce-proxy network → that network
     * - Otherwise → default ingress ('coolify' / 'coolify-overlay')
     *
     * Returns null when network management is disabled (no injection needed —
     * containers are never multi-homed by us in that case).
     *
     * @return array{network: string, is_proxy: bool}|null
     */
    public static function resolveIngressForServer($server): ?array
    {
        if (! config('corelix-platform.enabled', false)
            || ! config('corelix-platform.network_management.enabled', false)) {
            return null;
        }

        if (config('corelix-platform.network_management.proxy_isolation', false)) {
            $proxyNetwork = ManagedNetwork::where('server_id', $server->id)
                ->where('is_proxy_network', true)
                ->where('status', ManagedNetwork::STATUS_ACTIVE)
                ->first();

            if ($proxyNetwork) {
                return [
                    'network' => $proxyNetwork->docker_network_name,
                    'is_proxy' => true,
                ];
            }
        }

        $isSwarm = method_exists($server, 'isSwarm') && $server->isSwarm();

        return [
            'network' => $isSwarm ? 'coolify-overlay' : 'coolify',
            'is_proxy' => false,
        ];
    }

    /**
     * Inject ingress network labels into a parsed compose array. Pure function.
     *
     * Rules per compose service:
     * - Any traefik.* label present and traefik.docker.network absent → add it.
     *   An existing traefik.docker.network (user override or raw compose) always wins.
     * - Any caddy* label present and caddy_ingress_network absent → add it.
     * - caddy_ingress_network present but pointing at a known auto-generated
     *   value (resource uuid / destination network) while proxy isolation is
     *   active → replace with the proxy network. User-customised values are
     *   left untouched.
     *
     * @param  array  $compose  Parsed docker compose array
     * @param  string  $ingressNetwork  Effective ingress network name
     * @param  bool  $isProxyNetwork  True when $ingressNetwork is a managed ce-proxy network
     * @param  string[]  $replaceableCaddyValues  Auto-generated caddy_ingress_network values that may be replaced
     * @return array|null  Modified compose array, or null when nothing changed
     */
    public static function injectIngressLabels(
        array $compose,
        string $ingressNetwork,
        bool $isProxyNetwork = false,
        array $replaceableCaddyValues = [],
    ): ?array {
        if (! isset($compose['services']) || ! is_array($compose['services'])) {
            return null;
        }

        $modified = false;

        foreach ($compose['services'] as $name => &$svcConfig) {
            if (! is_array($svcConfig)) {
                continue;
            }

            $labels = $svcConfig['labels'] ?? [];
            if (! is_array($labels) || empty($labels)) {
                continue;
            }

            $map = static::labelsToMap($labels);

            $hasTraefik = false;
            $hasCaddy = false;
            foreach ($map as $key => $value) {
                if (str_starts_with($key, 'traefik.')) {
                    $hasTraefik = true;
                }
                if (str_starts_with($key, 'caddy')) {
                    $hasCaddy = true;
                }
            }

            $inject = [];

            if ($hasTraefik && ! array_key_exists('traefik.docker.network', $map)) {
                $inject['traefik.docker.network'] = $ingressNetwork;
            }

            if ($hasCaddy) {
                $current = $map['caddy_ingress_network'] ?? null;
                if ($current === null) {
                    $inject['caddy_ingress_network'] = $ingressNetwork;
                } elseif ($isProxyNetwork
                    && $current !== $ingressNetwork
                    && in_array($current, $replaceableCaddyValues, true)) {
                    // Auto-generated value pointing at a network the isolated
                    // proxy is not connected to — redirect to the proxy network
                    $inject['caddy_ingress_network'] = $ingressNetwork;
                }
            }

            if (! empty($inject)) {
                $svcConfig['labels'] = static::mapToList(array_merge($map, $inject));
                $modified = true;
            }
        }
        unset($svcConfig);

        return $modified ? $compose : null;
    }

    /**
     * Auto-generated caddy_ingress_network values for a Service that are safe
     * to replace under proxy isolation (see fqdnLabelsForCaddy()).
     *
     * @return string[]
     */
    protected static function replaceableCaddyValuesForService($service): array
    {
        return array_values(array_filter([
            $service->uuid ?? null,
            data_get($service, 'destination.network'),
        ]));
    }

    /**
     * Auto-generated caddy_ingress_network values for a compose Application.
     *
     * @return string[]
     */
    protected static function replaceableCaddyValuesForApplication($application): array
    {
        return array_values(array_filter([
            $application->uuid ?? null,
            data_get($application, 'destination.network'),
        ]));
    }

    /**
     * Convert compose labels (list "key=value" and/or map format) to an
     * associative map. Mirrors Coolify's tolerant label parsing.
     */
    public static function labelsToMap(array $labels): array
    {
        $map = [];
        foreach ($labels as $keyOrIndex => $item) {
            if (is_array($item)) {
                foreach ($item as $k => $v) {
                    $map[(string) $k] = (string) $v;
                }
            } elseif (is_string($item) && str_contains($item, '=') && is_int($keyOrIndex)) {
                [$key, $val] = explode('=', $item, 2);
                $map[trim($key)] = trim($val);
            } elseif (is_string($keyOrIndex) && ! is_numeric($keyOrIndex)) {
                $map[$keyOrIndex] = is_scalar($item) || $item === null ? (string) $item : '';
            }
        }

        return $map;
    }

    /**
     * Convert an associative label map back to canonical "key=value" list
     * format (the format Coolify's parsers dump).
     *
     * @return string[]
     */
    public static function mapToList(array $map): array
    {
        $result = [];
        foreach ($map as $key => $value) {
            $result[] = "{$key}={$value}";
        }

        return $result;
    }
}
