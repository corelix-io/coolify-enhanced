<?php

namespace CorelixIo\Platform\Livewire;

use CorelixIo\Platform\Models\DnsProvider;
use CorelixIo\Platform\Support\DnsDriverFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Settings → DNS Providers. Team-scoped CRUD for DNS/ingress providers with an inline
 * connection test. Owner/admin only (mirrors RegistryManager).
 */
class DnsProviderManager extends Component
{
    public ?int $editingProviderId = null;

    public string $formName = '';

    public string $formType = DnsProvider::TYPE_CLOUDFLARE_TUNNEL;

    public string $formApiToken = '';

    public string $formAccountId = '';

    public bool $showForm = false;

    public ?string $expandedProviderUuid = null;

    public ?string $testResult = null;

    public ?string $testError = null;

    public bool $testing = false;

    protected function rules(): array
    {
        // api_token required on create, optional on edit (empty = keep existing).
        $secretRule = $this->editingProviderId ? 'nullable' : 'required';

        return [
            'formName' => ['required', 'min:2', 'max:100'],
            'formType' => ['required', 'in:'.implode(',', DnsProvider::TYPES)],
            'formApiToken' => [$secretRule, 'max:500'],
            'formAccountId' => ['required', 'max:64'],
        ];
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
        $providers = DnsProvider::ownedByTeam(currentTeam()->id)
            ->orderBy('name')
            ->get();

        return view('corelix-platform::livewire.dns-provider-manager', [
            'providers' => $providers,
        ]);
    }

    public function showAddForm(): void
    {
        $this->ensureAuthorized();
        $this->resetForm();
        $this->editingProviderId = null;
        $this->showForm = true;
    }

    public function editProvider(int $providerId): void
    {
        $this->ensureAuthorized();
        $provider = DnsProvider::ownedByTeam(currentTeam()->id)->findOrFail($providerId);

        $this->editingProviderId = $provider->id;
        $this->formName = $provider->name;
        $this->formType = $provider->type;
        $this->formApiToken = '';
        $this->formAccountId = $provider->getAccountId();
        $this->testResult = null;
        $this->testError = null;
        $this->showForm = true;
    }

    public function cancelForm(): void
    {
        $this->resetForm();
        $this->showForm = false;
        $this->editingProviderId = null;
    }

    public function saveProvider(): void
    {
        $this->ensureAuthorized();
        $this->validate();

        try {
            $credentials = $this->buildCredentials();

            if ($this->editingProviderId) {
                $provider = DnsProvider::ownedByTeam(currentTeam()->id)->findOrFail($this->editingProviderId);

                if ($this->formApiToken === '') {
                    // Keep the existing token; only update the account id.
                    $existing = $provider->credentials ?? [];
                    $credentials['api_token'] = $existing['api_token'] ?? '';
                }

                $provider->update([
                    'name' => $this->formName,
                    'type' => $this->formType,
                    'credentials' => $credentials,
                ]);
            } else {
                // Free tier manages a single provider; more are pro (DNS_MULTI_DOMAIN).
                if (\CorelixIo\Platform\Support\Feature::disabled('DNS_MULTI_DOMAIN')
                    && DnsProvider::ownedByTeam(currentTeam()->id)->count() >= 1) {
                    $this->addError('formName', 'Multiple DNS providers require the Pro edition (DNS Multi-Domain).');

                    return;
                }

                $provider = DnsProvider::create([
                    'team_id' => currentTeam()->id,
                    'name' => $this->formName,
                    'type' => $this->formType,
                    'credentials' => $credentials,
                ]);
            }

            \CorelixIo\Platform\Support\DnsAudit::record(
                $this->editingProviderId ? 'provider.updated' : 'provider.created',
                ['provider_uuid' => $provider->uuid, 'name' => $provider->name, 'type' => $provider->type]
            );

            $this->cancelForm();
            $this->dispatch('success', "DNS provider \"{$provider->name}\" saved.");
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to save provider: '.$e->getMessage());
        }
    }

    public function testConnection(): void
    {
        $this->ensureAuthorized();

        try {
            $this->testing = true;
            $this->testResult = null;
            $this->testError = null;

            $credentials = $this->buildCredentials();
            if ($this->editingProviderId && $this->formApiToken === '') {
                $existing = DnsProvider::ownedByTeam(currentTeam()->id)->find($this->editingProviderId);
                if ($existing) {
                    $credentials['api_token'] = ($existing->credentials ?? [])['api_token'] ?? '';
                }
            }

            $temp = new DnsProvider(['type' => $this->formType, 'credentials' => $credentials]);
            $result = DnsDriverFactory::for($temp)->testConnection();

            $this->testResult = $result['success'] ? 'success' : 'failed';
            $this->testError = $result['error'];

            // Persist the outcome on the stored provider so the list badge reflects reality.
            if ($this->editingProviderId) {
                DnsProvider::ownedByTeam(currentTeam()->id)
                    ->whereKey($this->editingProviderId)
                    ->update([
                        'last_tested_at' => now(),
                        'last_test_status' => $result['success'] ? DnsProvider::TEST_SUCCESS : DnsProvider::TEST_FAILED,
                        'last_test_error' => $result['error'],
                    ]);
            }
        } catch (\Throwable $e) {
            $this->testResult = 'failed';
            $this->testError = $e->getMessage();
        } finally {
            $this->testing = false;
        }
    }

    /**
     * Test a SAVED provider from the list row and persist the outcome.
     */
    public function testProvider(int $providerId): void
    {
        $this->ensureAuthorized();

        try {
            $provider = DnsProvider::ownedByTeam(currentTeam()->id)->findOrFail($providerId);
            $result = DnsDriverFactory::for($provider)->testConnection();

            $provider->update([
                'last_tested_at' => now(),
                'last_test_status' => $result['success'] ? DnsProvider::TEST_SUCCESS : DnsProvider::TEST_FAILED,
                'last_test_error' => $result['error'],
            ]);

            if ($result['success']) {
                $this->dispatch('success', "Connection to \"{$provider->name}\" verified.");
            } else {
                $this->dispatch('error', "Connection test failed: {$result['error']}");
            }
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Connection test failed: '.$e->getMessage());
        }
    }

    public function toggleActive(int $providerId): void
    {
        $this->ensureAuthorized();

        try {
            $provider = DnsProvider::ownedByTeam(currentTeam()->id)->findOrFail($providerId);
            $provider->is_active = ! $provider->is_active;
            $provider->save();
            \CorelixIo\Platform\Support\DnsAudit::record('provider.toggled', [
                'provider_uuid' => $provider->uuid, 'is_active' => $provider->is_active,
            ]);
            $this->dispatch('success', "DNS provider \"{$provider->name}\" ".($provider->is_active ? 'activated.' : 'deactivated.'));
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to toggle provider: '.$e->getMessage());
        }
    }

    public function deleteProvider(int $providerId): void
    {
        $this->ensureAuthorized();

        try {
            $provider = DnsProvider::ownedByTeam(currentTeam()->id)->findOrFail($providerId);
            $name = $provider->name;
            $uuid = $provider->uuid;
            \CorelixIo\Platform\Services\DnsTeardownService::teardownProvider($provider);
            $provider->delete();
            \CorelixIo\Platform\Support\DnsAudit::record('provider.deleted', [
                'provider_uuid' => $uuid, 'name' => $name,
            ]);
            $this->dispatch('success', "DNS provider \"{$name}\" deleted.");
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to delete provider: '.$e->getMessage());
        }
    }

    public function toggleExpanded(string $uuid): void
    {
        $this->expandedProviderUuid = $this->expandedProviderUuid === $uuid ? null : $uuid;
    }

    protected function buildCredentials(): array
    {
        $credentials = match ($this->formType) {
            DnsProvider::TYPE_CLOUDFLARE_TUNNEL => [
                'api_token' => $this->formApiToken,
                'account_id' => $this->formAccountId,
            ],
            default => ['api_token' => $this->formApiToken],
        };

        if ($this->formType === DnsProvider::TYPE_CLOUDFLARE_TUNNEL
            && ! \CorelixIo\Platform\Services\CloudflareApiClient::validateAccountId($this->formAccountId)) {
            throw new \InvalidArgumentException('Cloudflare Account ID must be a 32-character hexadecimal string.');
        }

        return $credentials;
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
        $this->formName = '';
        $this->formType = DnsProvider::TYPE_CLOUDFLARE_TUNNEL;
        $this->formApiToken = '';
        $this->formAccountId = '';
        $this->testResult = null;
        $this->testError = null;
        $this->testing = false;
        $this->resetValidation();
    }
}
