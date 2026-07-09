<?php

namespace CorelixIo\Platform\Livewire;

use CorelixIo\Platform\Jobs\DnsDomainProvisionJob;
use CorelixIo\Platform\Models\DnsProvider;
use CorelixIo\Platform\Models\Domain;
use CorelixIo\Platform\Services\DnsTeardownService;
use CorelixIo\Platform\Support\Feature;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Settings → DNS → Domains. Add a managed wildcard domain, bind it to a provider and a server,
 * and provision it (tunnel + wildcard CNAME + ingress + managed cloudflared) in the background.
 *
 * Free tier: one wildcard domain. Pro: multiple domains (DNS_MULTI_DOMAIN) and
 * per-hostname/hybrid routing modes (DNS_PER_HOSTNAME).
 */
class DnsDomainManager extends Component
{
    public bool $showForm = false;

    public string $formBaseDomain = '';

    public ?int $formProviderId = null;

    public ?int $formServerId = null;

    public bool $formSetServerDefault = true;

    public string $formRoutingMode = Domain::ROUTING_WILDCARD;

    protected function rules(): array
    {
        return [
            'formBaseDomain' => ['required', 'min:3', 'max:253'],
            'formProviderId' => ['required', 'integer'],
            'formServerId' => ['required', 'integer'],
            'formSetServerDefault' => ['boolean'],
            'formRoutingMode' => ['required', 'in:'.implode(',', $this->allowedRoutingModes())],
        ];
    }

    /**
     * Routing modes the current edition may create. per_hostname/hybrid are pro
     * (DNS_PER_HOSTNAME); wildcard is always available.
     *
     * @return array<int, string>
     */
    protected function allowedRoutingModes(): array
    {
        $modes = [Domain::ROUTING_WILDCARD];

        if (Feature::enabled('DNS_PER_HOSTNAME')) {
            $modes[] = Domain::ROUTING_PER_HOSTNAME;
            $modes[] = Domain::ROUTING_HYBRID;
        }

        return $modes;
    }

    public function mount(): void
    {
        if (! config('corelix-platform.dns_provider_management.enabled', false)) {
            abort(404);
        }

        if (! $this->isAuthorized()) {
            abort(403);
        }
    }

    public function render(): View
    {
        $teamId = currentTeam()->id;

        $roleBindings = [];

        return view('corelix-platform::livewire.dns-domain-manager', [
            'domains' => Domain::ownedByTeam($teamId)
                ->with(['provider', 'tunnel', 'servers'])
                ->orderBy('base_domain')
                ->get(),
            'availableProviders' => DnsProvider::ownedByTeam($teamId)->active()->orderBy('name')->get(),
            'availableServers' => \App\Models\Server::ownedByCurrentTeam(['id', 'name'])->get(),
            'canMultiDomain' => Feature::enabled('DNS_MULTI_DOMAIN'),
            'canPerHostname' => Feature::enabled('DNS_PER_HOSTNAME'),
            'canEnvBindings' => Feature::enabled('DNS_ENV_BINDINGS'),
            'canAccessPolicies' => Feature::enabled('DNS_ACCESS_POLICIES'),
            'roleBindings' => $roleBindings,
        ]);
    }

    public function showAddForm(): void
    {
        $this->ensureAuthorized();
        $this->resetForm();
        $this->showForm = true;
    }

    public function cancelForm(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    public function saveDomain(): void
    {
        $this->ensureAuthorized();
        $this->validate();

        $teamId = currentTeam()->id;

        try {
            $provider = DnsProvider::ownedByTeam($teamId)->active()->findOrFail($this->formProviderId);
            $server = \App\Models\Server::ownedByCurrentTeam(['id', 'name'])->findOrFail($this->formServerId);

            $normalized = Domain::normalizeBaseDomain($this->formBaseDomain);
            if (! Domain::isValidBaseDomain($normalized)) {
                $this->addError('formBaseDomain', 'Enter a valid hostname, e.g. apps.example.com.');

                return;
            }

            if (Domain::ownedByTeam($teamId)->where('base_domain', $normalized)->exists()) {
                $this->addError('formBaseDomain', 'This domain is already managed.');

                return;
            }

            // Free tier manages a single domain; additional domains are pro (DNS_MULTI_DOMAIN).
            if (Feature::disabled('DNS_MULTI_DOMAIN') && Domain::ownedByTeam($teamId)->count() >= 1) {
                $this->addError('formBaseDomain', 'Multiple managed domains require the Pro edition (DNS Multi-Domain).');

                return;
            }

            $domain = Domain::create([
                'team_id' => $teamId,
                'dns_provider_id' => $provider->id,
                'base_domain' => $normalized,
                'routing_mode' => $this->formRoutingMode,
                'is_default' => ! Domain::ownedByTeam($teamId)->where('is_default', true)->exists(),
            ]);

            $domain->servers()->attach($server->id, [
                'is_default_wildcard' => $this->formSetServerDefault,
            ]);

            DnsDomainProvisionJob::dispatch($domain, $server->id);

            \CorelixIo\Platform\Support\DnsAudit::record('domain.created', [
                'domain_uuid' => $domain->uuid, 'base_domain' => $normalized,
                'routing_mode' => $domain->routing_mode,
            ]);

            $this->cancelForm();
            $this->dispatch('success', "Domain \"{$normalized}\" added — provisioning tunnel, wildcard DNS and cloudflared in the background.");
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to add domain: '.$e->getMessage());
        }
    }

    public function reprovision(int $domainId): void
    {
        $this->ensureAuthorized();

        try {
            $domain = Domain::ownedByTeam(currentTeam()->id)->findOrFail($domainId);
            DnsDomainProvisionJob::dispatch($domain);
            \CorelixIo\Platform\Support\DnsAudit::record('domain.provision_queued', [
                'domain_uuid' => $domain->uuid, 'base_domain' => $domain->base_domain,
            ]);
            $this->dispatch('success', "Re-provisioning \"{$domain->base_domain}\" in the background.");
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to re-provision: '.$e->getMessage());
        }
    }

    public function toggleActive(int $domainId): void
    {
        $this->ensureAuthorized();

        try {
            $domain = Domain::ownedByTeam(currentTeam()->id)->findOrFail($domainId);
            $domain->is_active = ! $domain->is_active;
            $domain->save();
            \CorelixIo\Platform\Support\DnsAudit::record('domain.toggled', [
                'domain_uuid' => $domain->uuid, 'is_active' => $domain->is_active,
            ]);
            $this->dispatch('success', "Domain \"{$domain->base_domain}\" ".($domain->is_active ? 'activated.' : 'deactivated.'));
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to toggle domain: '.$e->getMessage());
        }
    }

    public function deleteDomain(int $domainId): void
    {
        $this->ensureAuthorized();

        try {
            $domain = Domain::ownedByTeam(currentTeam()->id)->findOrFail($domainId);
            $name = $domain->base_domain;
            $uuid = $domain->uuid;
            DnsTeardownService::teardownDomain($domain);
            $domain->delete();
            \CorelixIo\Platform\Support\DnsAudit::record('domain.deleted', [
                'domain_uuid' => $uuid, 'base_domain' => $name,
            ]);
            $this->dispatch('success', "Domain \"{$name}\" deleted. Access apps and managed daemons were cleaned up; zone DNS records were left untouched.");
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to delete domain: '.$e->getMessage());
        }
    }



    protected function ensureAuthorized(): void
    {
        if (! $this->isAuthorized()) {
            abort(403);
        }
    }

    protected function isAuthorized(): bool
    {
        // isAdmin() covers owner+admin and is CURRENT-team aware (unlike teams->first()).
        return (bool) auth()->user()?->isAdmin();
    }

    protected function resetForm(): void
    {
        $this->formBaseDomain = '';
        $this->formProviderId = null;
        $this->formServerId = null;
        $this->formSetServerDefault = true;
        $this->formRoutingMode = Domain::ROUTING_WILDCARD;
        $this->resetValidation();
    }
}
