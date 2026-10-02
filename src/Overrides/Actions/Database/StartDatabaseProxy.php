<?php

// =============================================================================
// OVERLAY: Modified version of Coolify's StartDatabaseProxy
// =============================================================================
// This file replaces app/Actions/Database/StartDatabaseProxy.php in the
// Coolify container.
// Changes from the original are marked with [DATABASE CLASSIFICATION OVERLAY]
// and [MULTI-PORT PROXY OVERLAY].
//
// Modifications:
//   1. Replaced the hardcoded internal port match with an expanded mapping
//      covering ~50 additional database types (graph, vector, time-series, etc.)
//   2. Added fallback logic to extract port from compose config for unknown types
//   3. Multi-port proxy support: when ServiceDatabase has proxy_ports JSON,
//      generates multiple nginx server blocks and exposes all enabled ports
// =============================================================================

namespace App\Actions\Database;

use App\Models\ServiceDatabase;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Notifications\Container\ContainerRestarted;
use Lorisleiva\Actions\Concerns\AsAction;
use Lorisleiva\Actions\Decorators\JobDecorator;
use Symfony\Component\Yaml\Yaml;

class StartDatabaseProxy
{
    use AsAction;

    public function configureJob(JobDecorator $job): void
    {
        $job->onQueue(deployment_queue());
    }

    // [DATABASE CLASSIFICATION OVERLAY] — Expanded port mapping for database images
    // Maps base image names (after last '/') to their default internal ports.
    // Used as a fallback when the databaseType doesn't match Coolify's built-in types.
    private const DATABASE_PORT_MAP = [
        // --- Standard (Coolify built-in) ---
        'postgres' => 5432,
        'postgresql' => 5432,
        'postgis' => 5432,
        'mysql' => 3306,
        'mysql-server' => 3306,
        'mariadb' => 3306,
        'mongo' => 27017,
        'mongodb' => 27017,
        'redis' => 6379,
        'keydb' => 6379,
        'dragonfly' => 6379,
        'clickhouse-server' => 9000,
        'clickhouse' => 9000,

        // --- Graph databases ---
        'memgraph' => 7687,
        'memgraph-mage' => 7687,
        'memgraph-platform' => 7687,
        'neo4j' => 7687,
        'arangodb' => 8529,
        'orientdb' => 2424,
        'dgraph' => 8080,
        'janusgraph' => 8182,
        'age' => 5432, // PostgreSQL extension

        // --- Vector databases ---
        'milvus' => 19530,
        'qdrant' => 6333,
        'weaviate' => 8080,
        'chroma' => 8000,

        // --- Time-series databases ---
        'questdb' => 8812,
        'tdengine' => 6030,
        'victoria-metrics' => 8428,
        'influxdb' => 8086,

        // --- Document databases ---
        'couchbase' => 11210,
        'couchdb' => 5984,
        'ferretdb' => 27017,
        'surrealdb' => 8000,
        'ravendb' => 38888,
        'rethinkdb' => 28015,

        // --- Search engines ---
        'elasticsearch' => 9200,
        'opensearch' => 9200,
        'meilisearch' => 7700,
        'typesense' => 8108,
        'manticore' => 9306,
        'solr' => 8983,

        // --- Key-value / cache ---
        'valkey' => 6379,
        'memcached' => 11211,

        // --- Column-family databases ---
        'cassandra' => 9042,
        'scylla' => 9042,

        // --- NewSQL / distributed SQL ---
        'cockroach' => 26257,
        'yugabyte' => 5433,
        'tidb' => 4000,
        'lite' => 3306, // vitess/lite

        // --- OLAP / analytical ---
        'druid' => 8888,
        'pinot' => 8099,

        // --- Other databases ---
        'edgedb' => 5656,
        'eventstore' => 2113,
        'immudb' => 3322,
        'percona' => 3306,
        'foundationdb' => 4500,
        'ignite' => 10800,
        'hazelcast' => 5701,
    ];
    // [END DATABASE CLASSIFICATION OVERLAY]

    public function handle(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse|ServiceDatabase $database)
    {
        $databaseType = $database->database_type;
        $network = data_get($database, 'destination.network');
        $server = data_get($database, 'destination.server');
        $containerName = data_get($database, 'uuid');
        $proxyContainerName = "{$database->uuid}-proxy";
        $isSSLEnabled = $database->enable_ssl ?? false;

        if ($database->getMorphClass() === ServiceDatabase::class) {
            $databaseType = $database->databaseType();
            $network = $database->service->uuid;
            $server = data_get($database, 'service.destination.server');
            $containerName = "{$database->name}-{$database->service->uuid}";
        }

        // [MULTI-PORT PROXY OVERLAY] — Check for multi-port proxy configuration
        if ($database instanceof ServiceDatabase && ! empty($database->proxy_ports)) {
            $this->handleMultiPort($database, $containerName, $proxyContainerName, $network, $server);

            return;
        }
        // [END MULTI-PORT PROXY OVERLAY]

        // [DATABASE CLASSIFICATION OVERLAY] — Image-specific ports win over wire-compat databaseType()
        // (e.g. YugabyteDB → postgresql type but listens on 5433, TiDB → mysql type but 4000)
        if ($database instanceof ServiceDatabase) {
            $internalPort = $this->resolveServiceDatabaseInternalPort($database, $databaseType);
        } else {
            $internalPort = match ($databaseType) {
                'standalone-mariadb', 'standalone-mysql' => 3306,
                'standalone-postgresql', 'standalone-supabase/postgres' => 5432,
                'standalone-redis', 'standalone-keydb', 'standalone-dragonfly' => 6379,
                'standalone-clickhouse' => 9000,
                'standalone-mongodb' => 27017,
                default => $this->resolveInternalPort($databaseType, $database),
            };
        }
        // [END DATABASE CLASSIFICATION OVERLAY]

        if ($isSSLEnabled) {
            $internalPort = match ($databaseType) {
                'standalone-redis', 'standalone-keydb', 'standalone-dragonfly' => 6380,
                default => $internalPort,
            };
        }

        $configuration_dir = database_proxy_dir($database->uuid);
        $host_configuration_dir = $configuration_dir;
        if (isDev()) {
            $host_configuration_dir = '/var/lib/docker/volumes/coolify_dev_coolify_data/_data/databases/'.$database->uuid.'/proxy';
        }
        $timeoutConfig = $this->buildProxyTimeoutConfig($database->public_port_timeout);
        $nginxconf = <<<EOF
    user  nginx;
    worker_processes  auto;

    error_log  /var/log/nginx/error.log;

    events {
        worker_connections  1024;
    }
    stream {
       server {
            listen $database->public_port;
            proxy_pass $containerName:$internalPort;
            $timeoutConfig
       }
    }
    EOF;
        $docker_compose = [
            'services' => [
                $proxyContainerName => [
                    'image' => 'nginx:stable-alpine',
                    'container_name' => $proxyContainerName,
                    'restart' => RESTART_MODE,
                    'ports' => [
                        "$database->public_port:$database->public_port",
                    ],
                    'networks' => [
                        $network,
                    ],
                    'volumes' => [
                        [
                            'type' => 'bind',
                            'source' => "$host_configuration_dir/nginx.conf",
                            'target' => '/etc/nginx/nginx.conf',
                        ],
                    ],
                    'healthcheck' => [
                        'test' => [
                            'CMD-SHELL',
                            'stat /etc/nginx/nginx.conf || exit 1',
                        ],
                        'interval' => '5s',
                        'timeout' => '5s',
                        'retries' => 3,
                        'start_period' => '1s',
                    ],
                ],
            ],
            'networks' => [
                $network => [
                    'external' => true,
                    'name' => $network,
                    'attachable' => true,
                ],
            ],
        ];
        $dockercompose_base64 = base64_encode(Yaml::dump($docker_compose, 4, 2));
        $nginxconf_base64 = base64_encode($nginxconf);
        instant_remote_process(["docker rm -f $proxyContainerName"], $server, false);

        try {
            instant_remote_process([
                "mkdir -p $configuration_dir",
                "echo '{$nginxconf_base64}' | base64 -d | tee $configuration_dir/nginx.conf > /dev/null",
                "echo '{$dockercompose_base64}' | base64 -d | tee $configuration_dir/docker-compose.yaml > /dev/null",
                "docker compose --project-directory {$configuration_dir} pull",
                "docker compose --project-directory {$configuration_dir} up -d",
            ], $server);
        } catch (\RuntimeException $e) {
            if ($this->isNonTransientError($e->getMessage())) {
                $database->update(['is_public' => false]);

                $team = data_get($database, 'environment.project.team')
                    ?? data_get($database, 'service.environment.project.team');

                $team?->notify(
                    new ContainerRestarted(
                        "TCP Proxy for {$database->name} database has been disabled due to error: {$e->getMessage()}",
                        $server,
                    )
                );

                // [CORELIX ENHANCED: ray() call intentionally omitted — ray is a Coolify dev tool not present in production]

                return;
            }

            throw $e;
        }
    }

    private function isNonTransientError(string $message): bool
    {
        $nonTransientPatterns = [
            'port is already allocated',
            'address already in use',
            'Bind for',
        ];

        foreach ($nonTransientPatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    // [MULTI-PORT PROXY OVERLAY] — Handle multi-port proxy for ServiceDatabase with proxy_ports
    private function handleMultiPort(ServiceDatabase $database, string $containerName, string $proxyContainerName, string $network, $server): void
    {
        $proxyPorts = is_array($database->proxy_ports)
            ? $database->proxy_ports
            : json_decode($database->proxy_ports, true);

        // Build nginx server blocks and docker port mappings for all enabled ports
        $serverBlocks = '';
        $dockerPorts = [];
        $timeoutConfig = $this->buildProxyTimeoutConfig($database->public_port_timeout);
        foreach ($proxyPorts as $internalPort => $config) {
            if (! ($config['enabled'] ?? false)) {
                continue;
            }
            $publicPort = (int) $config['public_port'];
            if ($publicPort <= 0) {
                continue;
            }
            $serverBlocks .= "   server {\n";
            $serverBlocks .= "        listen {$publicPort};\n";
            $serverBlocks .= "        proxy_pass {$containerName}:{$internalPort};\n";
            $serverBlocks .= "        {$timeoutConfig}\n";
            $serverBlocks .= "   }\n";
            $dockerPorts[] = "{$publicPort}:{$publicPort}";
        }

        if (empty($dockerPorts)) {
            // No enabled ports — fall through to stop proxy if it exists
            instant_remote_process(["docker rm -f $proxyContainerName"], $server, false);

            return;
        }

        $configuration_dir = database_proxy_dir($database->uuid);
        $host_configuration_dir = $configuration_dir;
        if (isDev()) {
            $host_configuration_dir = '/var/lib/docker/volumes/coolify_dev_coolify_data/_data/databases/'.$database->uuid.'/proxy';
        }

        $nginxconf = "user  nginx;\nworker_processes  auto;\n\nerror_log  /var/log/nginx/error.log;\n\nevents {\n    worker_connections  1024;\n}\nstream {\n{$serverBlocks}}";

        $docker_compose = [
            'services' => [
                $proxyContainerName => [
                    'image' => 'nginx:stable-alpine',
                    'container_name' => $proxyContainerName,
                    'restart' => RESTART_MODE,
                    'ports' => $dockerPorts,
                    'networks' => [
                        $network,
                    ],
                    'volumes' => [
                        [
                            'type' => 'bind',
                            'source' => "$host_configuration_dir/nginx.conf",
                            'target' => '/etc/nginx/nginx.conf',
                        ],
                    ],
                    'healthcheck' => [
                        'test' => [
                            'CMD-SHELL',
                            'stat /etc/nginx/nginx.conf || exit 1',
                        ],
                        'interval' => '5s',
                        'timeout' => '5s',
                        'retries' => 3,
                        'start_period' => '1s',
                    ],
                ],
            ],
            'networks' => [
                $network => [
                    'external' => true,
                    'name' => $network,
                    'attachable' => true,
                ],
            ],
        ];

        $dockercompose_base64 = base64_encode(Yaml::dump($docker_compose, 4, 2));
        $nginxconf_base64 = base64_encode($nginxconf);
        instant_remote_process(["docker rm -f $proxyContainerName"], $server, false);
        instant_remote_process([
            "mkdir -p $configuration_dir",
            "echo '{$nginxconf_base64}' | base64 -d | tee $configuration_dir/nginx.conf > /dev/null",
            "echo '{$dockercompose_base64}' | base64 -d | tee $configuration_dir/docker-compose.yaml > /dev/null",
            "docker compose --project-directory {$configuration_dir} pull",
            "docker compose --project-directory {$configuration_dir} up -d",
        ], $server);
    }
    // [END MULTI-PORT PROXY OVERLAY]

    // [DATABASE CLASSIFICATION OVERLAY] — Resolve ServiceDatabase port from image before wire-compat type
    private function resolveServiceDatabaseInternalPort(ServiceDatabase $database, string $databaseType): int
    {
        $imagePort = $this->resolvePortFromImageString($database->image ?? '');
        if ($imagePort !== null) {
            return $imagePort;
        }

        return match ($databaseType) {
            'standalone-mariadb', 'standalone-mysql' => 3306,
            'standalone-postgresql', 'standalone-supabase/postgres' => 5432,
            'standalone-redis', 'standalone-keydb', 'standalone-dragonfly' => 6379,
            'standalone-clickhouse' => 9000,
            'standalone-mongodb' => 27017,
            default => $this->resolveInternalPort($databaseType, $database),
        };
    }

    private function resolvePortFromImageString(?string $image): ?int
    {
        if (blank($image)) {
            return null;
        }

        $baseImage = str($image)->before(':')->afterLast('/')->lower()->value();

        if (isset(self::DATABASE_PORT_MAP[$baseImage])) {
            return self::DATABASE_PORT_MAP[$baseImage];
        }

        foreach (self::DATABASE_PORT_MAP as $knownImage => $port) {
            if (str($baseImage)->contains($knownImage)) {
                return $port;
            }
        }

        return null;
    }

    // [DATABASE CLASSIFICATION OVERLAY] — Resolve internal port for unknown database types
    private function resolveInternalPort(string $databaseType, $database): int
    {
        // Extract the image-based identifier from databaseType (strip 'standalone-' prefix)
        $imageIdentifier = str($databaseType)->after('standalone-')->value();

        // Try matching by base image name (after last '/')
        $baseImage = str($imageIdentifier)->contains('/')
            ? str($imageIdentifier)->afterLast('/')->value()
            : $imageIdentifier;

        if (isset(self::DATABASE_PORT_MAP[$baseImage])) {
            return self::DATABASE_PORT_MAP[$baseImage];
        }

        // Try matching by full image identifier (for namespaced images like 'supabase/postgres')
        if (isset(self::DATABASE_PORT_MAP[$imageIdentifier])) {
            return self::DATABASE_PORT_MAP[$imageIdentifier];
        }

        // Try partial match against known base names (handles variants like 'timescaledb-ha')
        foreach (self::DATABASE_PORT_MAP as $knownImage => $port) {
            if (str($baseImage)->contains($knownImage) || str($knownImage)->contains($baseImage)) {
                return $port;
            }
        }

        // For ServiceDatabase, try extracting port from the service's compose config
        if ($database instanceof ServiceDatabase) {
            $port = $this->extractPortFromCompose($database);
            if ($port !== null) {
                return $port;
            }
        }

        throw new \Exception(
            "Unable to determine internal port for database type: {$databaseType}. ".
            'Set a custom_type on the service database (e.g., postgresql, mysql, redis) '.
            'to use a known port mapping, or check that the service compose defines ports.'
        );
    }

    // Extract the first exposed port from a ServiceDatabase's compose configuration
    private function extractPortFromCompose(ServiceDatabase $database): ?int
    {
        try {
            $service = $database->service;
            if (! $service || ! $service->docker_compose_raw) {
                return null;
            }

            $compose = Yaml::parse($service->docker_compose_raw);
            $services = data_get($compose, 'services', []);
            $serviceName = $database->name;

            $serviceConfig = data_get($services, $serviceName);
            if (! $serviceConfig) {
                return null;
            }

            // Check 'ports' section for the first mapped port
            $ports = data_get($serviceConfig, 'ports', []);
            foreach ($ports as $port) {
                $portStr = is_string($port) ? $port : (string) $port;
                // Parse port mappings like "7687:7687", "0.0.0.0:7687:7687", or just "7687"
                $parts = explode(':', $portStr);
                $containerPort = (int) end($parts);
                if ($containerPort > 0) {
                    return $containerPort;
                }
            }

            // Check 'expose' section
            $expose = data_get($serviceConfig, 'expose', []);
            foreach ($expose as $port) {
                $p = (int) $port;
                if ($p > 0) {
                    return $p;
                }
            }
        } catch (\Throwable $e) {
            // Silently fall through to the exception in the caller
        }

        return null;
    }
    // [END DATABASE CLASSIFICATION OVERLAY]

    private function buildProxyTimeoutConfig(?int $timeout): string
    {
        if ($timeout === null || $timeout < 1) {
            $timeout = 3600;
        }

        return "proxy_timeout {$timeout}s;";
    }
}
