<?php

namespace CorelixIo\Platform\Livewire;

use CorelixIo\Platform\Jobs\DnsReconcileJob;
use CorelixIo\Platform\Models\Domain;
use CorelixIo\Platform\Models\ManagedHostname;
use CorelixIo\Platform\Services\DnsResolutionService;
use CorelixIo\Platform\Support\Feature;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Per-resource Domains panel (DNS Provider Management).
 *
 * Rendered as a configuration sub-page for Applications and Services. Shows every
 * hostname the resource exposes, which managed Domain owns it (longest-suffix or
 * override), the provider, and the reconcile state — plus a manual Re-sync action.
 *
 * Mirrors ResourceNetworks' resource identification pattern (resourceId + resourceType
 * allowlist) to prevent arbitrary class instantiation via property tampering.
 */
class ResourceDomains extends Component
{
    private const ALLOWED_RESOURCE_TYPES = [
        \App\Models\Application::class,
        \App\Models\Service::class,
    ];

    public $resourceId;

    public $resourceType;

    public string $resourceName = '';

    public function mount($resourceId, $resourceType, $resourceName = ''): void
    {
        if (! config('corelix-platform.enabled', false)
            || ! config('corelix-platform.dns_provider_management.enabled', false)) {
            abort(404);
        }

        if (! in_array($resourceType, self::ALLOWED_RESOURCE_TYPES, true)) {
            abort(403, 'Invalid resource type.');
        }

        $this->resourceId = $resourceId;
        $this->resourceType = $resourceType;
        $this->resourceName = $resourceName;
    }

    private function resolveResource()
    {
        if (! in_array($this->resourceType, self::ALLOWED_RESOURCE_TYPES, true)) {
            throw new \RuntimeException('Invalid resource type.');
        }

        $resource = $this->resourceType::findOrFail($this->resourceId);

        // Team guard — resourceId is a tamperable Livewire property.
        if (DnsResolutionService::teamIdForResource($resource) !== currentTeam()->id) {
            abort(403);
        }

        return $resource;
    }

    /**
     * Underlying FQDN-bearing resources: the resource itself, or a Service's applications.
     *
     * @return array<int, object>
     */
    private function fqdnResources(): array
    {
        $resource = $this->resolveResource();

        if ($resource instanceof \App\Models\Service) {
            return $resource->applications()->get()->all();
        }

        return [$resource];
    }

    public function resync(): void
    {
        $user = auth()->user();
        if (! $user || ! $user->isAdmin()) {
            $this->dispatch('error', 'Only team owners and admins can trigger a DNS re-sync.');

            return;
        }

        try {
            foreach ($this->fqdnResources() as $fqdnResource) {
                DnsReconcileJob::dispatch($fqdnResource);
            }
            \CorelixIo\Platform\Support\DnsAudit::record('resource.resync_queued', [
                'resource_type' => $this->resourceType, 'resource_id' => $this->resourceId,
            ]);
            $this->dispatch('success', 'DNS re-sync queued.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to queue DNS re-sync: '.$e->getMessage());
        }
    }


    public function render(): View
    {
        $entries = collect();

        try {
            foreach ($this->fqdnResources() as $fqdnResource) {
                $rows = ManagedHostname::where('resource_type', get_class($fqdnResource))
                    ->where('resource_id', $fqdnResource->id)
                    ->with(['domain', 'provider'])
                    ->get()
                    ->keyBy('hostname');

                foreach (DnsResolutionService::extractHostnames($fqdnResource) as $hostname) {
                    $row = $rows->pull($hostname);
                    $entries->push([
                        'hostname' => $hostname,
                        'row' => $row,
                        'stale' => false,
                        'fqdn_resource_type' => get_class($fqdnResource),
                        'fqdn_resource_id' => $fqdnResource->id,
                    ]);
                }

                // Rows whose hostname is no longer exposed (cleaned up on next reconcile).
                foreach ($rows as $row) {
                    $entries->push([
                        'hostname' => $row->hostname,
                        'row' => $row,
                        'stale' => true,
                        'fqdn_resource_type' => get_class($fqdnResource),
                        'fqdn_resource_id' => $fqdnResource->id,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to load domain status: '.$e->getMessage());
        }

        $canManage = (bool) auth()->user()?->isAdmin();

        $availableDomains = collect();
        if ($canManage && Feature::enabled('DNS_MULTI_DOMAIN')) {
            try {
                $availableDomains = Domain::ownedByTeam(currentTeam()->id)
                    ->active()
                    ->orderBy('base_domain')
                    ->get(['id', 'base_domain']);
            } catch (\Throwable) {
                // table may not exist yet — pin UI simply stays hidden
            }
        }

        return view('corelix-platform::livewire.resource-domains', [
            'entries' => $entries,
            'canManage' => $canManage,
            'canPin' => $canManage && Feature::enabled('DNS_MULTI_DOMAIN') && $availableDomains->isNotEmpty(),
            'availableDomains' => $availableDomains,
        ]);
    }
}
