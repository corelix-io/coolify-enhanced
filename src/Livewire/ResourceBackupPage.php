<?php

namespace CorelixIo\Platform\Livewire;

use Livewire\Component;

/**
 * Full-page Livewire component for the Server > Resource Backups page.
 *
 * Renders the server navbar and sidebar alongside the ResourceBackupManager.
 * Used as the target for the server.resource-backups route.
 */
class ResourceBackupPage extends Component
{
    public $server;

    public function mount()
    {
        try {
            $this->server = \App\Models\Server::ownedByCurrentTeam()
                ->where('uuid', request()->route('server_uuid'))
                ->firstOrFail();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('corelix-platform::livewire.resource-backup-page');
    }
}
