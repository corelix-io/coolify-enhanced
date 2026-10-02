<?php

namespace CorelixIo\Platform\Livewire;

use CorelixIo\Platform\Jobs\ProxyMigrationJob;
use CorelixIo\Platform\Models\ManagedNetwork;
use CorelixIo\Platform\Services\NetworkService;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * Server-level network management component.
 *
 * Provides a tabbed interface for managing Docker networks on a server:
 * - "Managed" tab: Shows networks tracked by Corelix Platform with create/delete/sync
 * - "Docker" tab: Raw list of Docker networks from the server for reference
 *
 * Rendered on the Server > Networks page via view overlay, similar to
 * ResourceBackupPage for server-level backup management.
 */
class NetworkManager extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public string $activeTab = 'managed'; // 'managed' or 'docker'

    // Create shared network form
    public string $newNetworkName = '';

    public bool $newNetworkInternal = false;

    // Docker networks (raw list from Docker)
    public array $dockerNetworks = [];

    // Swarm server detection
    public bool $isSwarmServer = false;

    public bool $isSwarmManager = false;

    // Create network options
    public bool $newNetworkEncrypted = false;

    public function mount(Server $server): void
    {
        if (! config('corelix-platform.enabled', false) || ! config('corelix-platform.network_management.enabled', false)) {
            abort(404);
        }

        $this->server = $server;
        $this->isSwarmServer = method_exists($server, 'isSwarm') && $server->isSwarm();
        $this->isSwarmManager = method_exists($server, 'isSwarmManager') && $server->isSwarmManager();
    }

    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
        if ($tab === 'docker') {
            $this->refreshDockerNetworks();
        }
    }

    public function createSharedNetwork(): void
    {
        $this->authorize('create', ManagedNetwork::class);

        $this->validate([
            'newNetworkName' => 'required|string|max:255',
        ]);

        try {
            // Check network limit before creating
            if (NetworkService::hasReachedNetworkLimit($this->server)) {
                $this->dispatch('error', 'Network limit reached for this server. Remove unused networks or increase CORELIX_MAX_NETWORKS.');
                return;
            }

            $team = auth()->user()->currentTeam();
            // Create DB record only (defer Docker creation to apply options first)
            $network = NetworkService::ensureSharedNetwork($this->newNetworkName, $this->server, $team, createDocker: false);

            // Apply optional settings BEFORE Docker network creation
            $updates = [];
            if ($this->newNetworkInternal) {
                $updates['is_internal'] = true;
            }
            if ($this->isSwarmServer && $this->newNetworkEncrypted) {
                $updates['is_encrypted_overlay'] = true;
                $updates['options'] = array_merge($network->options ?? [], ['encrypted' => true]);
            }
            if (!empty($updates)) {
                $network->update($updates);
            }

            // Now create the Docker network with all options applied
            NetworkService::createDockerNetwork($this->server, $network->fresh());

            $this->newNetworkName = '';
            $this->newNetworkInternal = false;
            $this->newNetworkEncrypted = false;
            $this->dispatch('success', 'Shared network created.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to create network: '.$e->getMessage());
        }
    }

    public function deleteNetwork(int $networkId): void
    {
        try {
            // Scope to this server to prevent cross-server network deletion
            $network = ManagedNetwork::where('id', $networkId)
                ->where('server_id', $this->server->id)
                ->firstOrFail();

            $this->authorize('delete', $network);

            // Don't allow deleting env/system/proxy networks
            if (in_array($network->scope, ['environment', 'system', 'proxy']) || $network->is_proxy_network) {
                $this->dispatch('error', 'Cannot delete environment, system, or proxy networks.');

                return;
            }

            NetworkService::deleteDockerNetwork($this->server, $network);
            $network->resourceNetworks()->delete();
            $network->delete();
            $this->dispatch('success', 'Network deleted.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to delete network: '.$e->getMessage());
        }
    }

    public function syncNetworks(): void
    {
        $this->authorize('update', $this->server);
        try {
            NetworkService::syncFromDocker($this->server);
            NetworkService::reconcileServer($this->server);
            $this->dispatch('success', 'Network sync complete.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to sync networks: '.$e->getMessage());
        }
    }

    /**
     * Reconcile all existing resources on this server.
     *
     * Useful after enabling network management on servers with pre-existing
     * deployments that need to be adopted into managed networks.
     */
    public function reconcileExistingResources(): void
    {
        $this->authorize('update', $this->server);

        try {
            $results = NetworkService::reconcileExistingServerResources($this->server);
            $this->dispatch(
                'success',
                "Reconciliation complete. Total: {$results['total']}, Reconciled: {$results['reconciled']}, Failed: {$results['failed']}."
            );
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to reconcile existing resources: '.$e->getMessage());
        }
    }

    public function refreshDockerNetworks(): void
    {
        $this->authorize('update', $this->server);
        try {
            $this->dockerNetworks = NetworkService::listDockerNetworks($this->server)->toArray();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to list Docker networks: '.$e->getMessage());
            $this->dockerNetworks = [];
        }
    }

    /**
     * Run proxy isolation migration for this server.
     *
     * Creates the proxy network, connects the proxy container,
     * and connects all FQDN-bearing resources to the proxy network.
     */
    public function migrateProxyIsolation(): void
    {
        $this->authorize('update', $this->server);

        if (! config('corelix-platform.network_management.proxy_isolation', false)) {
            $this->dispatch('error', 'Proxy isolation is not enabled. Set CORELIX_PROXY_ISOLATION=true first.');

            return;
        }

        try {
            ProxyMigrationJob::dispatch($this->server);
            $this->dispatch('success', 'Proxy migration job dispatched. Check server logs for progress.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to dispatch migration: '.$e->getMessage());
        }
    }

    /**
     * Disconnect proxy from non-proxy networks.
     *
     * Only safe to run after all resources have been redeployed
     * with traefik.docker.network labels.
     */
    public function cleanupProxyNetworks(): void
    {
        $this->authorize('update', $this->server);
        try {
            $results = NetworkService::disconnectProxyFromNonProxyNetworks($this->server);
            $count = count(array_filter($results));
            $this->dispatch('success', "Disconnected proxy from {$count} non-proxy network(s).");
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to cleanup proxy networks: '.$e->getMessage());
        }
    }

    public function render()
    {
        $managedNetworks = ManagedNetwork::forServer($this->server)
            ->with('resourceNetworks')
            ->orderBy('scope')
            ->orderBy('name')
            ->get();

        $proxyIsolationEnabled = config('corelix-platform.network_management.proxy_isolation', false);
        $proxyNetwork = $proxyIsolationEnabled
            ? ManagedNetwork::forServer($this->server)->proxy()->active()->with('resourceNetworks')->first()
            : null;

        return view('corelix-platform::livewire.network-manager', [
            'managedNetworks' => $managedNetworks,
            'proxyIsolationEnabled' => $proxyIsolationEnabled,
            'proxyNetwork' => $proxyNetwork,
            'isSwarmServer' => $this->isSwarmServer,
        ]);
    }
}
