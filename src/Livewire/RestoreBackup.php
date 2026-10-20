<?php

namespace CorelixIo\Platform\Livewire;

use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\Service;
use Livewire\Component;
use Livewire\WithFileUploads;

class RestoreBackup extends Component
{
    use WithFileUploads;

    // File upload
    public $backupFile = null;

    // Paste JSON
    public string $pastedJson = '';

    // Parsed backup data
    public ?array $parsedBackup = null;

    public string $parseError = '';

    // Import env vars state
    public string $importTargetType = '';

    public string $importTargetId = '';

    public array $availableTargets = [];

    public string $importMessage = '';

    public string $importStatus = '';

    // Which sections are expanded
    public array $expandedSections = [];

    public function mount(): void
    {
        // Must abort, not redirect-without-return: an unreturned redirect() lets execution
        // continue and the component mounts anyway (HTTP 200). Match sibling components.
        if (! isInstanceAdmin()) {
            abort(403);
        }

        $this->loadAvailableTargets();
    }

    /**
     * Load available resources the user can import env vars into.
     */
    public function loadAvailableTargets(): void
    {
        $targets = [];

        try {
            $teamId = auth()->user()->currentTeam()->id;

            // Applications
            $apps = Application::whereHas('environment', function ($q) use ($teamId) {
                $q->whereHas('project', function ($q2) use ($teamId) {
                    $q2->where('team_id', $teamId);
                });
            })->get();

            foreach ($apps as $app) {
                $envName = $app->environment?->name ?? 'unknown';
                $projName = $app->environment?->project?->name ?? 'unknown';
                $targets[] = [
                    'id' => $app->id,
                    'type' => Application::class,
                    'label' => "[App] {$projName} / {$envName} / {$app->name}",
                ];
            }

            // Services
            $services = Service::whereHas('environment', function ($q) use ($teamId) {
                $q->whereHas('project', function ($q2) use ($teamId) {
                    $q2->where('team_id', $teamId);
                });
            })->get();

            foreach ($services as $svc) {
                $envName = $svc->environment?->name ?? 'unknown';
                $projName = $svc->environment?->project?->name ?? 'unknown';
                $targets[] = [
                    'id' => $svc->id,
                    'type' => Service::class,
                    'label' => "[Service] {$projName} / {$envName} / {$svc->name}",
                ];
            }

            // Standalone databases (all types)
            $dbClasses = [
                \App\Models\StandalonePostgresql::class,
                \App\Models\StandaloneMysql::class,
                \App\Models\StandaloneMariadb::class,
                \App\Models\StandaloneMongodb::class,
                \App\Models\StandaloneRedis::class,
                \App\Models\StandaloneKeydb::class,
                \App\Models\StandaloneDragonfly::class,
                \App\Models\StandaloneClickhouse::class,
            ];

            foreach ($dbClasses as $dbClass) {
                if (! class_exists($dbClass)) {
                    continue;
                }
                $dbs = $dbClass::whereHas('environment', function ($q) use ($teamId) {
                    $q->whereHas('project', function ($q2) use ($teamId) {
                        $q2->where('team_id', $teamId);
                    });
                })->get();

                foreach ($dbs as $db) {
                    $envName = $db->environment?->name ?? 'unknown';
                    $projName = $db->environment?->project?->name ?? 'unknown';
                    $shortType = class_basename($dbClass);
                    $targets[] = [
                        'id' => $db->id,
                        'type' => $dbClass,
                        'label' => "[{$shortType}] {$projName} / {$envName} / {$db->name}",
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Silently ignore — targets will be empty
        }

        $this->availableTargets = $targets;
    }

    /**
     * Parse an uploaded backup file.
     */
    public function updatedBackupFile(): void
    {
        $this->parseError = '';
        $this->parsedBackup = null;

        if (! $this->backupFile) {
            return;
        }

        try {
            $content = $this->backupFile->get();
            $this->parseJsonContent($content);
        } catch (\Throwable $e) {
            $this->parseError = 'Failed to read file: '.$e->getMessage();
        }
    }

    /**
     * Parse pasted JSON content.
     */
    public function parsePastedJson(): void
    {
        $this->parseError = '';
        $this->parsedBackup = null;

        if (empty(trim($this->pastedJson))) {
            $this->parseError = 'Please paste JSON content first.';

            return;
        }

        $this->parseJsonContent($this->pastedJson);
    }

    /**
     * Parse and validate JSON backup content.
     */
    private function parseJsonContent(string $content): void
    {
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->parseError = 'Invalid JSON: '.json_last_error_msg();

            return;
        }

        if (! isset($data['backup_meta'])) {
            $this->parseError = 'This does not appear to be a Corelix Platform configuration backup. Missing "backup_meta" section.';

            return;
        }

        if (($data['backup_meta']['type'] ?? '') !== 'corelix_platform_resource_backup') {
            $this->parseError = 'Unrecognized backup type: '.($data['backup_meta']['type'] ?? 'unknown');

            return;
        }

        // Build a structured summary
        $meta = $data['backup_meta'];
        $resource = $data['resource'] ?? [];
        $envVars = $data['environment_variables'] ?? [];
        $storages = $data['persistent_storages'] ?? [];
        $composeRaw = $data['docker_compose_raw'] ?? null;
        $compose = $data['docker_compose'] ?? null;
        $labels = $data['custom_labels'] ?? null;
        $environment = $data['environment'] ?? null;
        $serviceApps = $data['service_applications'] ?? [];
        $serviceDbs = $data['service_databases'] ?? [];

        $this->parsedBackup = [
            'meta' => [
                'version' => $meta['version'] ?? '1.0',
                'created_at' => $meta['created_at'] ?? 'Unknown',
                'resource_type' => $meta['resource_type'] ?? 'Unknown',
                'resource_type_short' => class_basename($meta['resource_type'] ?? ''),
                'resource_name' => $meta['resource_name'] ?? 'Unknown',
                'resource_uuid' => $meta['resource_uuid'] ?? null,
            ],
            'resource' => $resource,
            'environment_variables' => $envVars,
            'persistent_storages' => $storages,
            'docker_compose_raw' => $composeRaw,
            'docker_compose' => $compose,
            'custom_labels' => $labels,
            'environment' => $environment,
            'service_applications' => $serviceApps,
            'service_databases' => $serviceDbs,
            'raw_json' => $content,
        ];

        // Auto-expand the summary section
        $this->expandedSections = ['summary'];
    }

    /**
     * Toggle a section's expanded state.
     */
    public function toggleSection(string $section): void
    {
        if (in_array($section, $this->expandedSections)) {
            $this->expandedSections = array_values(array_diff($this->expandedSections, [$section]));
        } else {
            $this->expandedSections[] = $section;
        }
    }

    /**
     * Import environment variables from the backup into an existing resource.
     */
    public function importEnvVars(): void
    {
        // Re-check authorization on the action: Livewire does NOT re-run mount() on
        // subsequent (hydrated) action requests, so the mount() guard alone is bypassable.
        if (! isInstanceAdmin()) {
            abort(403);
        }

        $this->importMessage = '';
        $this->importStatus = '';

        if (! $this->parsedBackup || empty($this->parsedBackup['environment_variables'])) {
            $this->importMessage = 'No environment variables to import.';
            $this->importStatus = 'error';

            return;
        }

        if (empty($this->importTargetId)) {
            $this->importMessage = 'Please select a target resource.';
            $this->importStatus = 'error';

            return;
        }

        // Find the selected target
        $target = collect($this->availableTargets)->firstWhere('id', (int) $this->importTargetId);
        if (! $target) {
            // Try matching by combined type:id
            foreach ($this->availableTargets as $t) {
                if ($t['type'].':'.$t['id'] === $this->importTargetId) {
                    $target = $t;
                    break;
                }
            }
        }

        if (! $target) {
            $this->importMessage = 'Selected resource not found.';
            $this->importStatus = 'error';

            return;
        }

        try {
            $resourceClass = $target['type'];
            $resource = $resourceClass::findOrFail($target['id']);

            $imported = 0;
            $skipped = 0;
            $errors = [];

            foreach ($this->parsedBackup['environment_variables'] as $var) {
                $key = $var['key'] ?? null;
                $value = $var['value'] ?? '';

                if (empty($key)) {
                    continue;
                }

                // Check if this key already exists on the resource
                $existing = EnvironmentVariable::where('resourceable_type', $resourceClass)
                    ->where('resourceable_id', $resource->id)
                    ->where('key', $key)
                    ->where('is_preview', false)
                    ->first();

                if ($existing) {
                    $skipped++;

                    continue;
                }

                try {
                    EnvironmentVariable::create([
                        'key' => $key,
                        'value' => $value,
                        'resourceable_type' => $resourceClass,
                        'resourceable_id' => $resource->id,
                        'is_preview' => false,
                        'is_build_time' => $var['is_build_time'] ?? false,
                        'is_multiline' => false,
                        'is_literal' => false,
                    ]);
                    $imported++;
                } catch (\Throwable $e) {
                    $errors[] = "{$key}: {$e->getMessage()}";
                }
            }

            $parts = [];
            if ($imported > 0) {
                $parts[] = "{$imported} imported";
            }
            if ($skipped > 0) {
                $parts[] = "{$skipped} skipped (already exist)";
            }
            if (! empty($errors)) {
                $parts[] = count($errors).' failed';
            }

            $this->importMessage = 'Environment variables: '.implode(', ', $parts).'.';
            $this->importStatus = $imported > 0 ? 'success' : ($skipped > 0 ? 'success' : 'error');

            if (! empty($errors)) {
                $this->importMessage .= "\nErrors: ".implode('; ', array_slice($errors, 0, 5));
            }

            if ($imported > 0) {
                $this->dispatch('success', "Imported {$imported} environment variables.");
            }
        } catch (\Throwable $e) {
            $this->importMessage = 'Import failed: '.$e->getMessage();
            $this->importStatus = 'error';
        }
    }

    /**
     * Clear the parsed backup and reset state.
     */
    public function clearBackup(): void
    {
        $this->parsedBackup = null;
        $this->parseError = '';
        $this->pastedJson = '';
        $this->backupFile = null;
        $this->importMessage = '';
        $this->importStatus = '';
        $this->expandedSections = [];
    }

    public function render()
    {
        return view('corelix-platform::livewire.restore-backup');
    }
}
