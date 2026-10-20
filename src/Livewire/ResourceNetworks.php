<?php

namespace CorelixIo\Platform\Livewire;

use CorelixIo\Platform\Models\ManagedNetwork;
use CorelixIo\Platform\Models\ResourceNetwork;
use CorelixIo\Platform\Services\NetworkService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * Per-resource network assignment component.
 *
 * Rendered on resource configuration pages (Application, Service, Database)
 * to manage which Docker networks a resource is connected to. Allows adding
 * shared networks, removing non-auto-attached networks, and reconnecting
 * disconnected containers.
 *
 * Similar to ResourceBackupManager but for network management instead of backups.
 * Uses the same resource identification pattern (resourceId + resourceType).
 */
class ResourceNetworks extends Component
{
    use AuthorizesRequests;
    /**
     * Allowlist of valid resource model classes.
     * Prevents arbitrary class instantiation via Livewire public property tampering.
     */
    private const ALLOWED_RESOURCE_TYPES = [
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

    public $resourceId;

    public $resourceType;

    public string $resourceName = '';

    // Selected network to add
    public string $selectedNetworkId = '';

    public function mount($resourceId, $resourceType, $resourceName = ''): void
    {
        if (! config('corelix-platform.enabled', false) || ! config('corelix-platform.network_management.enabled', false)) {
            abort(404);
        }

        if (! in_array($resourceType, self::ALLOWED_RESOURCE_TYPES, true)) {
            abort(403, 'Invalid resource type.');
        }

        $this->resourceId = $resourceId;
        $this->resourceType = $resourceType;
        $this->resourceName = $resourceName;
    }

    /**
     * Resolve the resource model safely using the allowlisted type.
     * Scoped to the current team to prevent cross-team IDOR via Livewire properties.
     */
    private function resolveResource()
    {
        if (! in_array($this->resourceType, self::ALLOWED_RESOURCE_TYPES, true)) {
            throw new \RuntimeException('Invalid resource type.');
        }

        $resource = $this->resourceType::ownedByCurrentTeam()->findOrFail($this->resourceId);
        $this->authorize('view', $resource);

        return $resource;
    }

    public function addToNetwork(): void
    {
        $resource = $this->resolveResource();
        $this->authorize('update', $resource);

        if (empty($this->selectedNetworkId)) {
            return;
        }

        try {
            $resource = $this->resolveResource();
            $network = ManagedNetwork::findOrFail($this->selectedNetworkId);
            $server = NetworkService::getServerForResource($resource);

            if (! $server || $network->server_id !== $server->id) {
                $this->dispatch('error', 'Network is not on the same server.');

                return;
            }

            $connected = NetworkService::connectResourceToNetwork($resource, $network, autoAttached: false);
            if (! $connected) {
                $this->dispatch('error', 'One or more containers failed to connect — check Docker state.');

                return;
            }

            $this->selectedNetworkId = '';
            $this->dispatch('success', 'Connected to network.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to connect to network: '.$e->getMessage());
        }
    }

    public function removeFromNetwork(int $pivotId): void
    {
        try {
            $resource = $this->resolveResource();
            $this->authorize('update', $resource);

            // Verify pivot belongs to this resource (prevents cross-resource manipulation)
            $pivot = ResourceNetwork::where('id', $pivotId)
                ->where('resource_type', $this->resourceType)
                ->where('resource_id', $this->resourceId)
                ->firstOrFail();

            // Don't allow removing auto-attached environment networks
            if ($pivot->is_auto_attached && $pivot->managedNetwork->scope === 'environment') {
                $this->dispatch('error', 'Cannot remove auto-attached environment network.');

                return;
            }

            $resource = $this->resolveResource();
            $server = NetworkService::getServerForResource($resource);
            $network = $pivot->managedNetwork;

            // Disconnect containers. disconnectContainer() verifies the result,
            // so a false return means the container is still attached (not a
            // benign "already gone"). The pivot is removed regardless — the
            // user's intent is to drop the managed membership — but we surface
            // the partial failure instead of reporting a false success.
            $containerNames = NetworkService::getContainerNames($resource);
            $allDisconnected = true;
            foreach ($containerNames as $containerName) {
                if (! NetworkService::disconnectContainer($server, $network->docker_network_name, $containerName)) {
                    $allDisconnected = false;
                }
            }

            $pivot->delete();

            if ($allDisconnected) {
                $this->dispatch('success', 'Disconnected from network.');
            } else {
                $this->dispatch('error', 'Membership removed, but one or more containers could not be disconnected — check Docker state.');
            }
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to disconnect from network: '.$e->getMessage());
        }
    }

    public function reconnect(int $pivotId): void
    {
        try {
            $resource = $this->resolveResource();
            $this->authorize('update', $resource);

            // Verify pivot belongs to this resource (prevents cross-resource manipulation)
            $pivot = ResourceNetwork::where('id', $pivotId)
                ->where('resource_type', $this->resourceType)
                ->where('resource_id', $this->resourceId)
                ->firstOrFail();
            $resource = $this->resolveResource();
            $server = NetworkService::getServerForResource($resource);
            $network = $pivot->managedNetwork;

            $connected = NetworkService::connectResourceToNetwork($resource, $network, autoAttached: $pivot->is_auto_attached);
            if (! $connected) {
                $this->dispatch('error', 'One or more containers failed to reconnect — check Docker state.');

                return;
            }

            $this->dispatch('success', 'Reconnected to network.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to reconnect: '.$e->getMessage());
        }
    }

    public function render()
    {
        $resource = null;
        $server = null;
        $currentNetworks = collect();

        try {
            $resource = $this->resolveResource();
            $server = NetworkService::getServerForResource($resource);
            $currentNetworks = ResourceNetwork::where('resource_type', $this->resourceType)
                ->where('resource_id', $this->resourceId)
                ->with('managedNetwork')
                ->get();
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Throwable) {
            abort(403);
        }

        $availableNetworks = $server
            ? NetworkService::getAvailableNetworks($resource, $server)
            : collect();

        return view('corelix-platform::livewire.resource-networks', [
            'currentNetworks' => $currentNetworks,
            'availableNetworks' => $availableNetworks,
        ]);
    }
}
