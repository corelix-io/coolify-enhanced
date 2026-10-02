<?php

namespace CorelixIo\Platform\Livewire;

use CorelixIo\Platform\Jobs\SyncTemplateSourceJob;
use CorelixIo\Platform\Models\CustomTemplateSource;
use CorelixIo\Platform\Services\PermissionService;
use CorelixIo\Platform\Services\TemplateSourceService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Component;

class CustomTemplateSources extends Component
{
    // Form fields for adding/editing a source
    public ?int $editingSourceId = null;

    public string $formName = '';

    public string $formRepositoryUrl = '';

    public string $formBranch = 'main';

    public string $formFolderPath = 'templates/compose';

    public string $formAuthToken = '';

    public bool $showForm = false;

    public ?string $expandedSourceUuid = null;

    public string $templateSearch = '';

    protected function rules(): array
    {
        return [
            'formName' => ['required', 'min:2', 'max:100'],
            'formRepositoryUrl' => ['required', 'max:500', 'regex:/^https?:\/\//', $this->allowedRepositoryHostRule()],
            'formBranch' => ['required', 'max:100', 'regex:/^[a-zA-Z0-9\.\-\_\/]+$/'],
            'formFolderPath' => ['required', 'max:500', 'regex:/^[a-zA-Z0-9\/\-\_\.]+$/', 'not_regex:/\.\./'],
            'formAuthToken' => ['nullable', 'max:500'],
        ];
    }

    protected $messages = [
        'formName.required' => 'A display name is required.',
        'formRepositoryUrl.required' => 'A GitHub repository URL is required.',
        'formRepositoryUrl.regex' => 'Repository URL must start with http:// or https://.',
        'formBranch.regex' => 'Branch name contains invalid characters.',
        'formFolderPath.regex' => 'Folder path contains invalid characters.',
        'formFolderPath.not_regex' => 'Folder path cannot contain "..".',
    ];

    public function mount(): void
    {
        if (! $this->isAuthorized()) {
            abort(403);
        }
    }

    public function render(): View
    {
        $sources = CustomTemplateSource::orderBy('name')->get();
        $hasSyncingSource = $sources->contains('last_sync_status', CustomTemplateSource::STATUS_SYNCING);
        $startupSyncBannerKey = 'corelix-platform:template-sync:startup-active';

        if (! $hasSyncingSource) {
            Cache::forget($startupSyncBannerKey);
        }

        return view('corelix-platform::livewire.custom-template-sources', [
            'sources' => $sources,
            'hasSyncingSource' => $hasSyncingSource,
            'showStartupSyncBanner' => $hasSyncingSource && Cache::get($startupSyncBannerKey, false),
        ]);
    }

    /**
     * Show the add form with default values.
     */
    public function showAddForm(): void
    {
        $this->ensureAuthorized();
        $this->resetForm();
        $this->editingSourceId = null;
        $this->showForm = true;
    }

    /**
     * Show the edit form populated with source data.
     */
    public function editSource(int $sourceId): void
    {
        $this->ensureAuthorized();
        $source = CustomTemplateSource::findOrFail($sourceId);
        $this->editingSourceId = $source->id;
        $this->formName = $source->name;
        $this->formRepositoryUrl = $source->repository_url;
        $this->formBranch = $source->branch;
        $this->formFolderPath = $source->folder_path;
        $this->formAuthToken = ''; // Never expose the token back
        $this->showForm = true;
    }

    /**
     * Cancel form editing.
     */
    public function cancelForm(): void
    {
        $this->resetForm();
        $this->showForm = false;
        $this->editingSourceId = null;
    }

    /**
     * Save and validate the source, then trigger a sync.
     */
    public function saveSource(): void
    {
        $this->ensureAuthorized();
        $this->validate();

        try {
            // Validate the GitHub connection first
            $validation = TemplateSourceService::validateSource(
                $this->formRepositoryUrl,
                $this->formBranch,
                $this->formFolderPath,
                filled($this->formAuthToken) ? $this->formAuthToken : null
            );

            if (! $validation['valid']) {
                $this->dispatch('error', 'Connection failed: '.$validation['error']);

                return;
            }

            if ($this->editingSourceId) {
                $source = CustomTemplateSource::findOrFail($this->editingSourceId);
                $source->name = $this->formName;
                $source->repository_url = $this->formRepositoryUrl;
                $source->branch = $this->formBranch;
                $source->folder_path = $this->formFolderPath;
                if (filled($this->formAuthToken)) {
                    $source->auth_token = $this->formAuthToken;
                }
                $source->save();
            } else {
                $source = CustomTemplateSource::create([
                    'name' => $this->formName,
                    'repository_url' => $this->formRepositoryUrl,
                    'branch' => $this->formBranch,
                    'folder_path' => $this->formFolderPath,
                    'auth_token' => filled($this->formAuthToken) ? $this->formAuthToken : null,
                ]);
            }

            SyncTemplateSourceJob::dispatch($source);

            $this->cancelForm();
            $this->dispatch('success', "Source \"{$source->name}\" saved. Syncing {$validation['file_count']} templates...");
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to save source: '.$e->getMessage());
        }
    }

    /**
     * Sync a single source.
     */
    public function syncSource(int $sourceId): void
    {
        $this->ensureAuthorized();

        try {
            $source = CustomTemplateSource::findOrFail($sourceId);

            // Immediately mark as syncing so the UI updates
            $source->update(['last_sync_status' => CustomTemplateSource::STATUS_SYNCING]);

            SyncTemplateSourceJob::dispatch($source);
            $this->dispatch('success', "Syncing \"{$source->name}\"...");
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to start sync: '.$e->getMessage());
        }
    }

    /**
     * Sync all enabled sources.
     */
    public function syncAll(): void
    {
        $this->ensureAuthorized();

        try {
            $sources = CustomTemplateSource::where('enabled', true)->get();
            foreach ($sources as $source) {
                $source->update(['last_sync_status' => CustomTemplateSource::STATUS_SYNCING]);
                SyncTemplateSourceJob::dispatch($source);
            }
            $this->dispatch('success', "Syncing {$sources->count()} sources...");
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to start sync: '.$e->getMessage());
        }
    }

    /**
     * Toggle a source's enabled status.
     */
    public function toggleEnabled(int $sourceId): void
    {
        $this->ensureAuthorized();

        try {
            $source = CustomTemplateSource::findOrFail($sourceId);
            $source->enabled = ! $source->enabled;
            $source->save();

            $status = $source->enabled ? 'enabled' : 'disabled';
            $this->dispatch('success', "Source \"{$source->name}\" {$status}.");
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to toggle source: '.$e->getMessage());
        }
    }

    /**
     * Delete a source and its cached templates.
     */
    public function deleteSource(int $sourceId): void
    {
        $this->ensureAuthorized();

        try {
            $source = CustomTemplateSource::findOrFail($sourceId);
            $name = $source->name;

            TemplateSourceService::deleteCachedTemplates($source);
            $source->delete();

            if ($this->expandedSourceUuid === $source->uuid) {
                $this->expandedSourceUuid = null;
            }

            $this->dispatch('success', "Source \"{$name}\" deleted.");
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to delete source: '.$e->getMessage());
        }
    }

    /**
     * Toggle the expanded templates list for a source.
     */
    public function toggleExpanded(string $uuid): void
    {
        $this->expandedSourceUuid = $this->expandedSourceUuid === $uuid ? null : $uuid;
        $this->templateSearch = '';
    }

    /**
     * Get the template list for an expanded source, optionally filtered by search.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function expandedTemplates(): array
    {
        if (! $this->expandedSourceUuid) {
            return [];
        }

        $source = CustomTemplateSource::where('uuid', $this->expandedSourceUuid)->first();
        if (! $source) {
            return [];
        }

        $templates = $source->loadCachedTemplates();

        if (filled($this->templateSearch)) {
            $search = strtolower($this->templateSearch);
            $templates = array_filter($templates, function ($template) use ($search) {
                $name = strtolower($template['_key'] ?? '');
                $slogan = strtolower($template['slogan'] ?? '');
                $tags = strtolower(implode(' ', $template['tags'] ?? []));
                $category = strtolower($template['category'] ?? '');

                return str_contains($name, $search)
                    || str_contains($slogan, $search)
                    || str_contains($tags, $search)
                    || str_contains($category, $search);
            });
        }

        return $templates;
    }

    /**
     * Check authorization and abort if not allowed.
     * Named ensureAuthorized() to avoid conflict with Livewire\Component::authorize().
     */
    protected function ensureAuthorized(): void
    {
        if (! $this->isAuthorized()) {
            abort(403);
        }
    }

    /**
     * Check if the current user is authorized to manage template sources.
     */
    protected function isAuthorized(): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        // NOTE: never read $user->currentTeam()->pivot->role — Coolify's cached
        // currentTeam() returns Team::find() with no team_user pivot loaded, so it
        // is always null (would 403 every user, including owners). PermissionService
        // resolves the role via roleInTeam()/isAdminOfTeam() instead.
        return PermissionService::isTeamAdmin($user);
    }

    /**
     * Reset the form to defaults.
     */
    protected function resetForm(): void
    {
        $this->formName = '';
        $this->formRepositoryUrl = '';
        $this->formBranch = 'main';
        $this->formFolderPath = 'templates/compose';
        $this->formAuthToken = '';
        $this->resetValidation();
    }

    /**
     * @return \Closure(string, mixed, \Closure): void
     */
    protected function allowedRepositoryHostRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $probe = new CustomTemplateSource(['repository_url' => (string) $value]);
            if (! $probe->hasAllowedRepositoryHost()) {
                $allowed = implode(', ', CustomTemplateSource::allowedGithubHosts());
                $fail("Repository host must be one of: {$allowed}.");
            }
        };
    }
}
