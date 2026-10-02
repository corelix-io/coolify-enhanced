<?php

namespace CorelixIo\Platform\Livewire;

use Livewire\Component;

/**
 * Settings page for network management configuration.
 *
 * Displays the current network management configuration values
 * loaded from the corelix-platform config. These settings are
 * environment-variable based, so the UI serves as a status display
 * and documentation page.
 *
 * In the future, these could be stored in the database
 * (InstanceSettings model) to allow runtime changes.
 */
class NetworkSettings extends Component
{
    public bool $networkManagementEnabled;

    public string $isolationMode;

    public bool $proxyIsolation;

    public int $maxNetworksPerServer;

    public bool $swarmOverlayEncryption;

    public function mount(): void
    {
        if (! config('corelix-platform.enabled', false)) {
            abort(404);
        }

        if (! isInstanceAdmin()) {
            abort(403);
        }

        $this->networkManagementEnabled = config('corelix-platform.network_management.enabled', false);
        $this->isolationMode = config('corelix-platform.network_management.isolation_mode', 'environment');
        $this->proxyIsolation = config('corelix-platform.network_management.proxy_isolation', false);
        $this->maxNetworksPerServer = config('corelix-platform.network_management.max_networks_per_server', 200);
        $this->swarmOverlayEncryption = config('corelix-platform.network_management.swarm_overlay_encryption', false);
    }

    // Note: These settings are env-based, so we show current values but can't persist changes
    // to .env from the UI. They serve as a status display + documentation page.
    // In the future, these could be stored in the database (InstanceSettings model).

    public function render()
    {
        return view('corelix-platform::livewire.network-settings');
    }
}
