<?php

// =============================================================================
// OVERLAY: Modified version of Coolify's Service/Index Livewire component
// =============================================================================
// This file replaces app/Livewire/Project/Service/Index.php in the
// Coolify container.
// Changes from the original are marked with [MULTI-PORT PROXY OVERLAY].
//
// Modification:
//   1. Added multi-port proxy support via coolify.proxyPorts Docker label
//   2. When a ServiceDatabase has coolify.proxyPorts in its compose config,
//      the UI shows a port mapping table instead of a single port input
//   3. proxy_ports JSON is stored on the ServiceDatabase model and used by
//      StartDatabaseProxy to generate multi-server-block nginx config
// =============================================================================

namespace App\Livewire\Project\Service;

use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Database\StopDatabaseProxy;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use CorelixIo\Platform\Support\Feature;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Spatie\Url\Url;
use Symfony\Component\Yaml\Yaml;

class Index extends Component
{
    use AuthorizesRequests;

    public ?Service $service = null;

    public ?ServiceApplication $serviceApplication = null;

    public ?ServiceDatabase $serviceDatabase = null;

    public ?string $resourceType = null;

    public ?string $currentRoute = null;

    public array $parameters;

    public array $query;

    public Collection $services;

    public $s3s;

    public ?Server $server = null;

    // Database-specific properties
    public ?string $db_url_public = null;

    public $fileStorages;

    public ?string $humanName = null;

    public ?string $description = null;

    public ?string $image = null;

    public bool $excludeFromStatus = false;

    public mixed $publicPort = null;

    public mixed $publicPortTimeout = 3600;

    public bool $isPublic = false;

    public bool $isLogDrainEnabled = false;

    public bool $isImportSupported = false;

    // [MULTI-PORT PROXY OVERLAY] — Multi-port proxy properties
    public array $proxyPorts = [];

    public array $availableProxyPorts = [];

    public bool $hasMultiPortProxy = false;
    // [END MULTI-PORT PROXY OVERLAY]

    // [CORELIX ENHANCED: Traefik label overrides properties]
    public string $labelOverridesYaml = '';

    public string $labelOverridesError = '';

    public array $generatedLabels = [];
    // [END CORELIX ENHANCED: Traefik label overrides properties]

    // Application-specific properties
    public $docker_cleanup = true;

    public $delete_volumes = true;

    public $domainConflicts = [];

    public $showDomainConflictModal = false;

    public $forceSaveDomains = false;

    public $showPortWarningModal = false;

    public $forceRemovePort = false;

    public $requiredPort = null;

    public ?string $fqdn = null;

    public bool $isGzipEnabled = false;

    public bool $isStripprefixEnabled = false;

    protected $listeners = ['generateDockerCompose', 'refreshScheduledBackups' => '$refresh', 'refreshFileStorages'];

    protected $rules = [
        'humanName' => 'nullable',
        'description' => 'nullable',
        'image' => 'required',
        'excludeFromStatus' => 'required|boolean',
        'publicPort' => 'nullable|integer|min:1|max:65535',
        'publicPortTimeout' => 'nullable|integer|min:1',
        'isPublic' => 'required|boolean',
        'isLogDrainEnabled' => 'required|boolean',
        // Application-specific rules
        'fqdn' => 'nullable',
        'isGzipEnabled' => 'nullable|boolean',
        'isStripprefixEnabled' => 'nullable|boolean',
    ];

    public function mount()
    {
        try {
            $this->services = collect([]);
            $this->parameters = get_route_parameters();
            $this->query = request()->query();
            $this->currentRoute = request()->route()->getName();
            $project = currentTeam()
                ->projects()
                ->select('id', 'uuid', 'team_id')
                ->where('uuid', $this->parameters['project_uuid'])
                ->firstOrFail();
            $environment = $project->environments()
                ->select('id', 'uuid', 'name', 'project_id')
                ->where('uuid', $this->parameters['environment_uuid'])
                ->firstOrFail();
            $this->service = $environment->services()->whereUuid($this->parameters['service_uuid'])->firstOrFail();
            $this->authorize('view', $this->service);
            $service = $this->service->applications()->whereUuid($this->parameters['stack_service_uuid'])->first();
            if ($service) {
                $this->serviceApplication = $service;
                $this->resourceType = 'application';
                $this->serviceApplication->getFilesFromServer();
                $this->initializeApplicationProperties();
            } else {
                $this->serviceDatabase = $this->service->databases()->whereUuid($this->parameters['stack_service_uuid'])->first();
                if (! $this->serviceDatabase) {
                    return redirect()->route('project.service.configuration', [
                        'project_uuid' => $this->parameters['project_uuid'],
                        'environment_uuid' => $this->parameters['environment_uuid'],
                        'service_uuid' => $this->parameters['service_uuid'],
                    ]);
                }
                $this->resourceType = 'database';
                $this->serviceDatabase->getFilesFromServer();
                $this->initializeDatabaseProperties();
            }
            $this->s3s = currentTeam()->s3s;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function initializeDatabaseProperties(): void
    {
        $this->server = $this->serviceDatabase->service->destination->server;
        if ($this->serviceDatabase->is_public) {
            $this->db_url_public = $this->serviceDatabase->getServiceDatabaseUrl();
        }
        $this->refreshFileStorages();
        $this->syncDatabaseData(false);

        // Check if import is supported for this database type
        $dbType = $this->serviceDatabase->databaseType();
        $supportedTypes = ['mysql', 'mariadb', 'postgres', 'mongo'];
        $this->isImportSupported = collect($supportedTypes)->contains(fn ($type) => str_contains($dbType, $type));

        // [MULTI-PORT PROXY OVERLAY] — Parse coolify.proxyPorts from compose
        $this->availableProxyPorts = $this->parseAvailableProxyPorts();
        $this->hasMultiPortProxy = count($this->availableProxyPorts) > 0;

        if ($this->hasMultiPortProxy) {
            $this->proxyPorts = $this->serviceDatabase->proxy_ports ?? [];
            // Initialize missing ports from available list with defaults
            foreach ($this->availableProxyPorts as $port => $info) {
                if (! isset($this->proxyPorts[$port])) {
                    $this->proxyPorts[$port] = [
                        'public_port' => null,
                        'label' => $info['label'],
                        'enabled' => false,
                    ];
                }
            }
        }
        // [END MULTI-PORT PROXY OVERLAY]

        // [CORELIX ENHANCED: Traefik label overrides]
        if (Feature::enabled('TRAEFIK_LABEL_OVERRIDES')) {
            $this->initializeLabelOverrides(
                model: $this->serviceDatabase,
                containerName: $this->serviceDatabase->name
            );
        }
        // [END CORELIX ENHANCED: Traefik label overrides]
    }

    // [MULTI-PORT PROXY OVERLAY] — Parse coolify.proxyPorts label from compose YAML
    private function parseAvailableProxyPorts(): array
    {
        $service = $this->serviceDatabase->service;
        if (! $service || ! $service->docker_compose_raw) {
            return [];
        }

        try {
            $compose = Yaml::parse($service->docker_compose_raw);
        } catch (\Throwable $e) {
            return [];
        }

        $serviceConfig = data_get($compose, "services.{$this->serviceDatabase->name}");
        if (! $serviceConfig) {
            return [];
        }

        $labels = data_get($serviceConfig, 'labels', []);
        $proxyPortsValue = null;

        if (is_array($labels)) {
            foreach ($labels as $key => $value) {
                // Map format: coolify.proxyPorts: "7687:bolt,7444:log"
                if (is_string($key) && strtolower($key) === 'coolify.proxyports') {
                    $proxyPortsValue = $value;
                    break;
                }
                // Array format: - coolify.proxyPorts=7687:bolt,7444:log
                if (is_string($value)) {
                    $parts = explode('=', $value, 2);
                    if (count($parts) === 2 && strtolower(trim($parts[0])) === 'coolify.proxyports') {
                        $proxyPortsValue = trim($parts[1]);
                        break;
                    }
                }
            }
        }

        if (! $proxyPortsValue) {
            return [];
        }

        return ServiceDatabase::parseProxyPortsLabel($proxyPortsValue);
    }
    // [END MULTI-PORT PROXY OVERLAY]

    private function syncDatabaseData(bool $toModel = false): void
    {
        if ($toModel) {
            $this->serviceDatabase->human_name = $this->humanName;
            $this->serviceDatabase->description = $this->description;
            $this->serviceDatabase->image = $this->image;
            $this->serviceDatabase->exclude_from_status = $this->excludeFromStatus;
            $this->serviceDatabase->public_port = $this->publicPort ?: null;
            $this->serviceDatabase->public_port_timeout = $this->publicPortTimeout ?: null;
            $this->serviceDatabase->is_public = $this->isPublic;
            $this->serviceDatabase->is_log_drain_enabled = $this->isLogDrainEnabled;
        } else {
            $this->humanName = $this->serviceDatabase->human_name;
            $this->description = $this->serviceDatabase->description;
            $this->image = $this->serviceDatabase->image;
            $this->excludeFromStatus = $this->serviceDatabase->exclude_from_status ?? false;
            $this->publicPort = $this->serviceDatabase->public_port;
            $this->publicPortTimeout = $this->serviceDatabase->public_port_timeout;
            $this->isPublic = $this->serviceDatabase->is_public ?? false;
            $this->isLogDrainEnabled = $this->serviceDatabase->is_log_drain_enabled ?? false;
        }
    }

    public function generateDockerCompose()
    {
        try {
            $this->authorize('update', $this->service);
            $this->service->parse();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    // Database-specific methods
    public function refreshFileStorages()
    {
        if ($this->serviceDatabase) {
            $this->fileStorages = $this->serviceDatabase->fileStorages()->get();
        }
    }

    public function deleteDatabase($password, $selectedActions = [])
    {
        try {
            $this->authorize('delete', $this->serviceDatabase);

            if (! verifyPasswordConfirmation($password, $this)) {
                return 'The provided password is incorrect.';
            }

            $this->serviceDatabase->delete();
            $this->dispatch('success', 'Database deleted.');

            return redirectRoute($this, 'project.service.configuration', $this->parameters);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSaveExclude()
    {
        try {
            $this->authorize('update', $this->serviceDatabase);
            $this->submitDatabase();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSaveLogDrain()
    {
        try {
            $this->authorize('update', $this->serviceDatabase);
            if (! $this->serviceDatabase->service->destination->server->isLogDrainEnabled()) {
                $this->isLogDrainEnabled = false;
                $this->dispatch('error', 'Log drain is not enabled on the server. Please enable it first.');

                return;
            }
            $this->submitDatabase();
            $this->dispatch('success', 'You need to restart the service for the changes to take effect.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function convertToApplication()
    {
        try {
            $this->authorize('update', $this->serviceDatabase);
            $service = $this->serviceDatabase->service;
            $serviceDatabase = $this->serviceDatabase;

            // Check if application with same name already exists
            if ($service->applications()->where('name', $serviceDatabase->name)->exists()) {
                throw new \Exception('An application with this name already exists.');
            }

            // Create new parameters removing database_uuid
            $redirectParams = collect($this->parameters)
                ->except('database_uuid')
                ->all();

            DB::transaction(function () use ($service, $serviceDatabase) {
                $service->applications()->create([
                    'name' => $serviceDatabase->name,
                    'human_name' => $serviceDatabase->human_name,
                    'description' => $serviceDatabase->description,
                    'exclude_from_status' => $serviceDatabase->exclude_from_status,
                    'is_log_drain_enabled' => $serviceDatabase->is_log_drain_enabled,
                    'image' => $serviceDatabase->image,
                    'service_id' => $service->id,
                    'is_migrated' => true,
                ]);
                $serviceDatabase->delete();
            });

            return redirectRoute($this, 'project.service.configuration', $redirectParams);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSave()
    {
        try {
            $this->authorize('update', $this->serviceDatabase);

            // [MULTI-PORT PROXY OVERLAY] — Use multi-port logic when available
            if ($this->hasMultiPortProxy) {
                return $this->instantSaveMultiPort();
            }
            // [END MULTI-PORT PROXY OVERLAY]

            if ($this->isPublic && ! $this->publicPort) {
                $this->dispatch('error', 'Public port is required.');
                $this->isPublic = false;

                return;
            }
            $this->syncDatabaseData(true);
            if ($this->serviceDatabase->is_public) {
                if (! str($this->serviceDatabase->status)->startsWith('running')) {
                    $this->dispatch('error', 'Database must be started to be publicly accessible.');
                    $this->isPublic = false;
                    $this->serviceDatabase->is_public = false;

                    return;
                }
                StartDatabaseProxy::run($this->serviceDatabase);
                $this->db_url_public = $this->serviceDatabase->getServiceDatabaseUrl();
                $this->dispatch('success', 'Database is now publicly accessible.');
            } else {
                StopDatabaseProxy::run($this->serviceDatabase);
                $this->db_url_public = null;
                $this->dispatch('success', 'Database is no longer publicly accessible.');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    // [MULTI-PORT PROXY OVERLAY] — Handle multi-port proxy toggle
    private function instantSaveMultiPort()
    {
        try {
            if ($this->isPublic) {
                // Validate: at least one port must be enabled with a public port set
                $hasAnyEnabled = false;
                foreach ($this->proxyPorts as $port => $config) {
                    if ($config['enabled'] ?? false) {
                        if (empty($config['public_port'])) {
                            $label = $config['label'] ?? $port;
                            $this->dispatch('error', "Public port is required for {$label} ({$port}).");
                            $this->isPublic = false;

                            return;
                        }
                        $hasAnyEnabled = true;
                    }
                }
                if (! $hasAnyEnabled) {
                    $this->dispatch('error', 'Enable at least one port mapping before making publicly available.');
                    $this->isPublic = false;

                    return;
                }
            }

            $this->serviceDatabase->proxy_ports = $this->proxyPorts;
            $this->serviceDatabase->is_public = $this->isPublic;
            $this->serviceDatabase->save();

            if ($this->isPublic) {
                if (! str($this->serviceDatabase->status)->startsWith('running')) {
                    $this->dispatch('error', 'Database must be started to be publicly accessible.');
                    $this->isPublic = false;
                    $this->serviceDatabase->is_public = false;
                    $this->serviceDatabase->save();

                    return;
                }
                StartDatabaseProxy::run($this->serviceDatabase);
                $this->dispatch('success', 'Database is now publicly accessible.');
            } else {
                StopDatabaseProxy::run($this->serviceDatabase);
                $this->dispatch('success', 'Database is no longer publicly accessible.');
            }
        } catch (\Throwable $e) {
            // On failure, revert the toggle
            $this->isPublic = ! $this->isPublic;
            $this->serviceDatabase->is_public = $this->isPublic;
            $this->serviceDatabase->save();

            return handleError($e, $this);
        }
    }

    public function updateProxyPorts()
    {
        try {
            $this->authorize('update', $this->serviceDatabase);
            $this->serviceDatabase->proxy_ports = $this->proxyPorts;
            $this->serviceDatabase->save();

            // If proxy is running, restart it with new port config
            if ($this->serviceDatabase->is_public) {
                StartDatabaseProxy::run($this->serviceDatabase);
                $this->dispatch('success', 'Proxy port configuration updated.');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
    // [END MULTI-PORT PROXY OVERLAY]

    public function submitDatabase()
    {
        try {
            $this->authorize('update', $this->serviceDatabase);
            $this->validate();
            $this->syncDatabaseData(true);
            $this->serviceDatabase->save();
            $this->serviceDatabase->refresh();
            $this->syncDatabaseData(false);
            updateCompose($this->serviceDatabase);
            $this->dispatch('success', 'Database saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->dispatch('generateDockerCompose');
        }
    }

    // Application-specific methods
    private function initializeApplicationProperties(): void
    {
        $this->requiredPort = $this->serviceApplication->getRequiredPort();
        $this->syncApplicationData(false);

        // [CORELIX ENHANCED: Traefik label overrides]
        if (Feature::enabled('TRAEFIK_LABEL_OVERRIDES')) {
            $this->initializeLabelOverrides(
                model: $this->serviceApplication,
                containerName: $this->serviceApplication->name
            );
        }
        // [END CORELIX ENHANCED: Traefik label overrides]
    }

    private function syncApplicationData(bool $toModel = false): void
    {
        if ($toModel) {
            $this->serviceApplication->human_name = $this->humanName;
            $this->serviceApplication->description = $this->description;
            $this->serviceApplication->fqdn = $this->fqdn;
            $this->serviceApplication->image = $this->image;
            $this->serviceApplication->exclude_from_status = $this->excludeFromStatus;
            $this->serviceApplication->is_log_drain_enabled = $this->isLogDrainEnabled;
            $this->serviceApplication->is_gzip_enabled = $this->isGzipEnabled;
            $this->serviceApplication->is_stripprefix_enabled = $this->isStripprefixEnabled;
        } else {
            $this->humanName = $this->serviceApplication->human_name;
            $this->description = $this->serviceApplication->description;
            $this->fqdn = $this->serviceApplication->fqdn;
            $this->image = $this->serviceApplication->image;
            $this->excludeFromStatus = data_get($this->serviceApplication, 'exclude_from_status', false);
            $this->isLogDrainEnabled = data_get($this->serviceApplication, 'is_log_drain_enabled', false);
            $this->isGzipEnabled = data_get($this->serviceApplication, 'is_gzip_enabled', true);
            $this->isStripprefixEnabled = data_get($this->serviceApplication, 'is_stripprefix_enabled', true);
        }
    }

    public function instantSaveApplication()
    {
        try {
            $this->authorize('update', $this->serviceApplication);
            $this->submitApplication();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSaveApplicationSettings()
    {
        try {
            $this->authorize('update', $this->serviceApplication);
            $this->serviceApplication->is_gzip_enabled = $this->isGzipEnabled;
            $this->serviceApplication->is_stripprefix_enabled = $this->isStripprefixEnabled;
            $this->serviceApplication->exclude_from_status = $this->excludeFromStatus;
            $this->serviceApplication->save();
            $this->dispatch('success', 'Settings saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSaveApplicationAdvanced()
    {
        try {
            $this->authorize('update', $this->serviceApplication);
            if (! $this->serviceApplication->service->destination->server->isLogDrainEnabled()) {
                $this->isLogDrainEnabled = false;
                $this->dispatch('error', 'Log drain is not enabled on the server. Please enable it first.');

                return;
            }
            $this->syncApplicationData(true);
            $this->serviceApplication->save();
            $this->dispatch('success', 'You need to restart the service for the changes to take effect.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function deleteApplication($password, $selectedActions = [])
    {
        try {
            $this->authorize('delete', $this->serviceApplication);

            if (! verifyPasswordConfirmation($password, $this)) {
                return 'The provided password is incorrect.';
            }

            $this->serviceApplication->delete();
            $this->dispatch('success', 'Application deleted.');

            return redirect()->route('project.service.configuration', $this->parameters);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function convertToDatabase()
    {
        try {
            $this->authorize('update', $this->serviceApplication);
            $service = $this->serviceApplication->service;
            $serviceApplication = $this->serviceApplication;

            if ($service->databases()->where('name', $serviceApplication->name)->exists()) {
                throw new \Exception('A database with this name already exists.');
            }

            $redirectParams = collect($this->parameters)
                ->except('database_uuid')
                ->all();
            DB::transaction(function () use ($service, $serviceApplication) {
                $service->databases()->create([
                    'name' => $serviceApplication->name,
                    'human_name' => $serviceApplication->human_name,
                    'description' => $serviceApplication->description,
                    'exclude_from_status' => $serviceApplication->exclude_from_status,
                    'is_log_drain_enabled' => $serviceApplication->is_log_drain_enabled,
                    'image' => $serviceApplication->image,
                    'service_id' => $service->id,
                    'is_migrated' => true,
                ]);
                $serviceApplication->delete();
            });

            return redirect()->route('project.service.configuration', $redirectParams);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function confirmDomainUsage()
    {
        $this->forceSaveDomains = true;
        $this->showDomainConflictModal = false;
        $this->submitApplication();
    }

    public function confirmRemovePort()
    {
        $this->forceRemovePort = true;
        $this->showPortWarningModal = false;
        $this->submitApplication();
    }

    public function cancelRemovePort()
    {
        $this->showPortWarningModal = false;
        $this->syncApplicationData(false);
    }

    public function submitApplication()
    {
        try {
            $this->authorize('update', $this->serviceApplication);
            $this->fqdn = str($this->fqdn)->replaceEnd(',', '')->trim()->toString();
            $this->fqdn = str($this->fqdn)->replaceStart(',', '')->trim()->toString();
            $domains = str($this->fqdn)->trim()->explode(',')->map(function ($domain) {
                $domain = trim($domain);
                Url::fromString($domain, ['http', 'https']);

                return str($domain)->lower();
            });
            $this->fqdn = $domains->unique()->implode(',');
            $warning = sslipDomainWarning($this->fqdn);
            if ($warning) {
                $this->dispatch('warning', __('warning.sslipdomain'));
            }

            $this->syncApplicationData(true);

            if (! $this->forceSaveDomains) {
                $result = checkDomainUsage(resource: $this->serviceApplication);
                if ($result['hasConflicts']) {
                    $this->domainConflicts = $result['conflicts'];
                    $this->showDomainConflictModal = true;

                    return;
                }
            } else {
                $this->forceSaveDomains = false;
            }

            if (! $this->forceRemovePort) {
                $requiredPort = $this->serviceApplication->getRequiredPort();

                if ($requiredPort !== null) {
                    $fqdns = str($this->fqdn)->trim()->explode(',');
                    $missingPort = false;

                    foreach ($fqdns as $fqdn) {
                        $fqdn = trim($fqdn);
                        if (empty($fqdn)) {
                            continue;
                        }

                        $port = ServiceApplication::extractPortFromUrl($fqdn);
                        if ($port === null) {
                            $missingPort = true;
                            break;
                        }
                    }

                    if ($missingPort) {
                        $this->requiredPort = $requiredPort;
                        $this->showPortWarningModal = true;

                        return;
                    }
                }
            } else {
                $this->forceRemovePort = false;
            }

            $this->validate();
            $this->serviceApplication->save();
            $this->serviceApplication->refresh();
            $this->syncApplicationData(false);
            updateCompose($this->serviceApplication);
            if (str($this->serviceApplication->fqdn)->contains(',')) {
                $this->dispatch('warning', 'Some services do not support multiple domains, which can lead to problems and is NOT RECOMMENDED.<br><br>Only use multiple domains if you know what you are doing.');
            } else {
                ! $warning && $this->dispatch('success', 'Service saved.');
            }
            $this->dispatch('generateDockerCompose');
        } catch (\Throwable $e) {
            $originalFqdn = $this->serviceApplication->getOriginal('fqdn');
            if ($originalFqdn !== $this->serviceApplication->fqdn) {
                $this->serviceApplication->fqdn = $originalFqdn;
                $this->syncApplicationData(false);
            }

            return handleError($e, $this);
        }
    }

    // [CORELIX ENHANCED: Traefik label overrides methods]

    /**
     * Initialise label override state from the given model (ServiceApplication or ServiceDatabase).
     *
     * @param  \App\Models\ServiceApplication|\App\Models\ServiceDatabase  $model
     * @param  string  $containerName  Name of the compose service for this container
     */
    private function initializeLabelOverrides($model, string $containerName): void
    {
        if (! class_exists(\CorelixIo\Platform\Services\LabelOverrideService::class)) {
            return;
        }

        $overrides = $model->label_overrides ?? [];
        $this->labelOverridesYaml = \CorelixIo\Platform\Services\LabelOverrideService::toYamlString($overrides);
        $this->generatedLabels = \CorelixIo\Platform\Services\LabelOverrideService::getGeneratedLabelsForContainer(
            $this->service,
            $containerName
        );
    }

    /**
     * Save label overrides for a ServiceApplication container and trigger a re-parse.
     */
    public function saveLabelOverridesApplication()
    {
        if (! class_exists(\CorelixIo\Platform\Services\LabelOverrideService::class)) {
            return;
        }

        try {
            $this->authorize('update', $this->service);
            $this->labelOverridesError = '';

            $overrides = blank($this->labelOverridesYaml)
                ? null
                : \CorelixIo\Platform\Services\LabelOverrideService::parseYamlInput($this->labelOverridesYaml);

            $this->serviceApplication->update(['label_overrides' => $overrides]);
            $this->service->parse();

            $this->generatedLabels = \CorelixIo\Platform\Services\LabelOverrideService::getGeneratedLabelsForContainer(
                $this->service->fresh(),
                $this->serviceApplication->name
            );

            $this->dispatch('success', 'Label overrides saved and applied.');
        } catch (\InvalidArgumentException $e) {
            $this->labelOverridesError = $e->getMessage();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    /**
     * Clear all label overrides for a ServiceApplication container.
     */
    public function clearLabelOverridesApplication()
    {
        if (! class_exists(\CorelixIo\Platform\Services\LabelOverrideService::class)) {
            return;
        }

        try {
            $this->authorize('update', $this->service);
            $this->serviceApplication->update(['label_overrides' => null]);
            $this->labelOverridesYaml = '';
            $this->labelOverridesError = '';
            $this->service->parse();

            $this->generatedLabels = \CorelixIo\Platform\Services\LabelOverrideService::getGeneratedLabelsForContainer(
                $this->service->fresh(),
                $this->serviceApplication->name
            );

            $this->dispatch('success', 'Label overrides cleared.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    /**
     * Save label overrides for a ServiceDatabase container and trigger a re-parse.
     */
    public function saveLabelOverridesDatabase()
    {
        if (! class_exists(\CorelixIo\Platform\Services\LabelOverrideService::class)) {
            return;
        }

        try {
            $this->authorize('update', $this->service);
            $this->labelOverridesError = '';

            $overrides = blank($this->labelOverridesYaml)
                ? null
                : \CorelixIo\Platform\Services\LabelOverrideService::parseYamlInput($this->labelOverridesYaml);

            $this->serviceDatabase->update(['label_overrides' => $overrides]);
            $this->service->parse();

            $this->generatedLabels = \CorelixIo\Platform\Services\LabelOverrideService::getGeneratedLabelsForContainer(
                $this->service->fresh(),
                $this->serviceDatabase->name
            );

            $this->dispatch('success', 'Label overrides saved and applied.');
        } catch (\InvalidArgumentException $e) {
            $this->labelOverridesError = $e->getMessage();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    /**
     * Clear all label overrides for a ServiceDatabase container.
     */
    public function clearLabelOverridesDatabase()
    {
        if (! class_exists(\CorelixIo\Platform\Services\LabelOverrideService::class)) {
            return;
        }

        try {
            $this->authorize('update', $this->service);
            $this->serviceDatabase->update(['label_overrides' => null]);
            $this->labelOverridesYaml = '';
            $this->labelOverridesError = '';
            $this->service->parse();

            $this->generatedLabels = \CorelixIo\Platform\Services\LabelOverrideService::getGeneratedLabelsForContainer(
                $this->service->fresh(),
                $this->serviceDatabase->name
            );

            $this->dispatch('success', 'Label overrides cleared.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    // [END CORELIX ENHANCED: Traefik label overrides methods]

    public function render()
    {
        return view('livewire.project.service.index');
    }
}
