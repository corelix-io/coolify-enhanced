<div>
    <x-slot:title>
        Restore Backup | Coolify
    </x-slot>
    <x-settings.navbar />

    <div class="flex flex-col">
        <div class="flex items-center gap-2 pb-2">
            <h2>Restore / Import Configuration Backup</h2>
        </div>
        <div class="pb-4 text-sm text-gray-600 dark:text-gray-400">
            Import a configuration backup JSON file exported by Corelix Platform's Resource Backups feature.
            This allows you to review backup contents and import environment variables into existing resources.
        </div>
        <div class="flex items-start gap-2 p-3 mb-4 rounded-md text-warning">
            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.168 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 6a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 6zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/>
            </svg>
            <div class="text-sm">
                <strong>Sensitive data:</strong> Configuration backups contain decrypted environment variables,
                database passwords, and API tokens. Only open files from trusted sources and avoid sharing backup
                JSON in tickets or chat.
            </div>
        </div>

        {{-- ============================================================ --}}
        {{-- STEP 1: Upload or Paste                                      --}}
        {{-- ============================================================ --}}
        @if(!$parsedBackup)
            <div class="flex flex-col gap-6">
                {{-- File Upload --}}
                <div class="p-6 rounded border dark:border-coolgray-300 dark:bg-coolgray-100 bg-white">
                    <h3 class="pb-3">Option A: Upload JSON File</h3>
                    <div class="pb-2 text-sm text-gray-600 dark:text-gray-400">
                        Upload a <code>config-*.json</code> or <code>full-config-*.json</code> file from your backup.
                    </div>
                    <div class="flex items-center gap-4">
                        <x-forms.input type="file" wire:model="backupFile" accept=".json,application/json" />
                        <div wire:loading wire:target="backupFile">
                            <span class="text-sm text-warning">Uploading...</span>
                        </div>
                    </div>
                </div>

                {{-- Paste JSON --}}
                <div class="p-6 rounded border dark:border-coolgray-300 dark:bg-coolgray-100 bg-white">
                    <h3 class="pb-3">Option B: Paste JSON Content</h3>
                    <div class="pb-2 text-sm text-gray-600 dark:text-gray-400">
                        Paste the contents of your configuration backup JSON file.
                    </div>
                    <x-forms.textarea
                        wire:model="pastedJson"
                        rows="8"
                        placeholder='{"backup_meta": {...}, "resource": {...}, ...}'
                    />
                    <div class="pt-3">
                        <x-forms.button wire:click="parsePastedJson">Parse JSON</x-forms.button>
                    </div>
                </div>

                {{-- Error message --}}
                @if($parseError)
                    <div class="p-4 rounded border border-red-500/30 bg-red-500/10 text-red-600 dark:text-red-400">
                        {{ $parseError }}
                    </div>
                @endif

                {{-- Help Section --}}
                <div class="p-6 rounded border dark:border-coolgray-300 dark:bg-coolgray-100 bg-white">
                    <h3 class="pb-3">How Configuration Backups Work</h3>
                    <div class="flex flex-col gap-3 text-sm text-gray-600 dark:text-gray-400">
                        <p>
                            Corelix Platform's <strong>Resource Backups</strong> feature exports resource configurations
                            as JSON files. These contain everything needed to recreate a resource:
                        </p>
                        <ul class="list-disc pl-6 space-y-1">
                            <li><strong>Resource settings</strong> — name, git repository, build pack, ports, domains, health checks, and all other configuration</li>
                            <li><strong>Environment variables</strong> — all key/value pairs (production and build-time)</li>
                            <li><strong>Persistent storages</strong> — volume mount configurations</li>
                            <li><strong>Docker Compose</strong> — raw and processed compose files (for services and compose-based apps)</li>
                            <li><strong>Custom labels</strong> — Traefik/Docker labels</li>
                        </ul>
                        <div class="mt-2 p-3 rounded bg-gray-50 dark:bg-coolgray-200">
                            <strong>Where to find backup files:</strong>
                            <ul class="list-disc pl-6 mt-1 space-y-1">
                                <li>Local server: <code>/data/coolify/backups/resources/</code></li>
                                <li>S3 storage: same path structure in your bucket</li>
                                <li>Configuration backups are named <code>config-*.json</code></li>
                                <li>Full backups include a <code>full-config-*.json</code> alongside the volume archive</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        @else
            {{-- ============================================================ --}}
            {{-- STEP 2: Backup Preview                                       --}}
            {{-- ============================================================ --}}
            <div class="flex flex-col gap-4">
                {{-- Clear button --}}
                <div class="flex items-center gap-3">
                    <x-forms.button wire:click="clearBackup">Load Different Backup</x-forms.button>
                </div>

                {{-- Summary Card --}}
                <div class="p-6 rounded border dark:border-coolgray-300 dark:bg-coolgray-100 bg-white">
                    <div class="flex items-center justify-between pb-3">
                        <h3>Backup Summary</h3>
                        <span class="px-3 py-1 rounded text-xs font-medium bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-400">
                            {{ $parsedBackup['meta']['resource_type_short'] }}
                        </span>
                    </div>
                    <div class="grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <span class="text-gray-500 dark:text-gray-400">Resource Name:</span>
                            <span class="font-medium">{{ $parsedBackup['meta']['resource_name'] }}</span>
                        </div>
                        <div>
                            <span class="text-gray-500 dark:text-gray-400">Resource Type:</span>
                            <span class="font-medium">{{ $parsedBackup['meta']['resource_type_short'] }}</span>
                        </div>
                        <div>
                            <span class="text-gray-500 dark:text-gray-400">Backup Date:</span>
                            <span class="font-medium">{{ $parsedBackup['meta']['created_at'] }}</span>
                        </div>
                        <div>
                            <span class="text-gray-500 dark:text-gray-400">UUID:</span>
                            <span class="font-mono text-xs">{{ $parsedBackup['meta']['resource_uuid'] ?? 'N/A' }}</span>
                        </div>
                        @if($parsedBackup['environment'])
                            <div>
                                <span class="text-gray-500 dark:text-gray-400">Environment:</span>
                                <span class="font-medium">{{ $parsedBackup['environment']['name'] ?? 'N/A' }}</span>
                            </div>
                        @endif
                    </div>

                    {{-- Sections overview --}}
                    <div class="flex flex-wrap gap-2 mt-4 pt-4 border-t border-neutral-200 dark:border-coolgray-300">
                        @if(!empty($parsedBackup['environment_variables']))
                            <span class="px-2 py-1 rounded text-xs bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-400">
                                {{ count($parsedBackup['environment_variables']) }} Environment Variables
                            </span>
                        @endif
                        @if(!empty($parsedBackup['persistent_storages']))
                            <span class="px-2 py-1 rounded text-xs bg-purple-100 text-purple-700 dark:bg-purple-500/20 dark:text-purple-400">
                                {{ count($parsedBackup['persistent_storages']) }} Persistent Storages
                            </span>
                        @endif
                        @if($parsedBackup['docker_compose_raw'])
                            <span class="px-2 py-1 rounded text-xs bg-orange-100 text-orange-700 dark:bg-orange-500/20 dark:text-orange-400">
                                Docker Compose
                            </span>
                        @endif
                        @if($parsedBackup['custom_labels'])
                            <span class="px-2 py-1 rounded text-xs bg-gray-100 text-gray-700 dark:bg-gray-500/20 dark:text-gray-400">
                                Custom Labels
                            </span>
                        @endif
                        @if(!empty($parsedBackup['service_applications']))
                            <span class="px-2 py-1 rounded text-xs bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-400">
                                {{ count($parsedBackup['service_applications']) }} Service Apps
                            </span>
                        @endif
                        @if(!empty($parsedBackup['service_databases']))
                            <span class="px-2 py-1 rounded text-xs bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-400">
                                {{ count($parsedBackup['service_databases']) }} Service Databases
                            </span>
                        @endif
                    </div>
                </div>

                {{-- ============================================================ --}}
                {{-- Environment Variables Section                                 --}}
                {{-- ============================================================ --}}
                @if(!empty($parsedBackup['environment_variables']))
                    <div class="rounded border dark:border-coolgray-300 dark:bg-coolgray-100 bg-white">
                        <button wire:click="toggleSection('env_vars')" class="flex items-center justify-between w-full p-4 text-left">
                            <h3>Environment Variables ({{ count($parsedBackup['environment_variables']) }})</h3>
                            <svg class="w-5 h-5 transition-transform {{ in_array('env_vars', $expandedSections) ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>

                        @if(in_array('env_vars', $expandedSections))
                            <div class="px-4 pb-4 border-t border-neutral-200 dark:border-coolgray-300">
                                {{-- Import into existing resource --}}
                                <div class="my-4 p-4 rounded bg-gray-50 dark:bg-coolgray-200">
                                    <h4 class="pb-2 font-medium">Import into Existing Resource</h4>
                                    <div class="pb-2 text-sm text-gray-600 dark:text-gray-400">
                                        Select a resource to import these environment variables into.
                                        Variables that already exist on the target will be skipped (not overwritten).
                                    </div>
                                    <div class="flex items-end gap-3">
                                        <div class="flex-1">
                                            <x-forms.select id="importTargetId" label="Target Resource">
                                                <option value="">Select a resource...</option>
                                                @foreach($availableTargets as $target)
                                                    <option value="{{ $target['type'] }}:{{ $target['id'] }}">{{ $target['label'] }}</option>
                                                @endforeach
                                            </x-forms.select>
                                        </div>
                                        <x-forms.button wire:click="importEnvVars"
                                            wire:confirm="This will import {{ count($parsedBackup['environment_variables']) }} environment variables into the selected resource. Existing variables will not be overwritten. Continue?">
                                            Import Variables
                                        </x-forms.button>
                                    </div>
                                    @if($importMessage)
                                        <div class="mt-3 text-sm {{ $importStatus === 'success' ? 'text-success' : 'text-error' }}">
                                            {!! nl2br(e($importMessage)) !!}
                                        </div>
                                    @endif
                                </div>

                                {{-- Variable list --}}
                                <div class="overflow-x-auto">
                                    <table class="w-full text-sm">
                                        <thead>
                                            <tr class="text-left text-gray-500 dark:text-gray-400">
                                                <th class="py-2 pr-4 font-medium">Key</th>
                                                <th class="py-2 pr-4 font-medium">Value</th>
                                                <th class="py-2 font-medium">Flags</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($parsedBackup['environment_variables'] as $var)
                                                <tr class="border-t border-neutral-200 dark:border-coolgray-300">
                                                    <td class="py-2 pr-4 font-mono text-xs">{{ $var['key'] ?? '' }}</td>
                                                    <td class="py-2 pr-4 font-mono text-xs max-w-md truncate">{{ $var['value'] ?? '' }}</td>
                                                    <td class="py-2">
                                                        @if($var['is_build_time'] ?? false)
                                                            <span class="px-1.5 py-0.5 rounded text-xs bg-yellow-100 text-yellow-700 dark:bg-yellow-500/20 dark:text-yellow-400">build</span>
                                                        @endif
                                                        @if($var['is_preview'] ?? false)
                                                            <span class="px-1.5 py-0.5 rounded text-xs bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-400">preview</span>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>

                                {{-- Copy as .env format --}}
                                <div class="mt-4 pt-4 border-t border-neutral-200 dark:border-coolgray-300">
                                    <h4 class="pb-2 font-medium text-sm">Copy as .env format</h4>
                                    <div class="relative">
                                        <pre class="p-3 rounded bg-gray-100 dark:bg-coolgray-200 text-xs font-mono overflow-x-auto max-h-48">@foreach($parsedBackup['environment_variables'] as $var){{ $var['key'] ?? '' }}={{ $var['value'] ?? '' }}
@endforeach</pre>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                @endif

                {{-- ============================================================ --}}
                {{-- Resource Settings Section                                     --}}
                {{-- ============================================================ --}}
                <div class="rounded border dark:border-coolgray-300 dark:bg-coolgray-100 bg-white">
                    <button wire:click="toggleSection('resource')" class="flex items-center justify-between w-full p-4 text-left">
                        <h3>Resource Settings</h3>
                        <svg class="w-5 h-5 transition-transform {{ in_array('resource', $expandedSections) ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>

                    @if(in_array('resource', $expandedSections))
                        <div class="px-4 pb-4 border-t border-neutral-200 dark:border-coolgray-300">
                            <div class="mt-3 text-sm text-gray-600 dark:text-gray-400 pb-3">
                                These are the full resource settings at the time of backup.
                                Use these values as reference when recreating the resource on a new Coolify instance.
                            </div>

                            @php
                                $resource = $parsedBackup['resource'];
                                $importantKeys = ['name', 'fqdn', 'git_repository', 'git_branch', 'build_pack', 'base_directory',
                                    'install_command', 'build_command', 'start_command', 'ports_exposes', 'ports_mappings',
                                    'health_check_path', 'health_check_port', 'dockerfile', 'dockerfile_location',
                                    'docker_registry_image_name', 'docker_registry_image_tag', 'static_image'];
                                $shown = [];
                            @endphp

                            {{-- Key settings first --}}
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="text-left text-gray-500 dark:text-gray-400">
                                            <th class="py-2 pr-4 font-medium w-1/3">Setting</th>
                                            <th class="py-2 font-medium">Value</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($importantKeys as $key)
                                            @if(isset($resource[$key]) && filled($resource[$key]))
                                                @php $shown[] = $key; @endphp
                                                <tr class="border-t border-neutral-200 dark:border-coolgray-300">
                                                    <td class="py-2 pr-4 font-mono text-xs text-gray-600 dark:text-gray-400">{{ $key }}</td>
                                                    <td class="py-2 font-mono text-xs">{{ is_array($resource[$key]) ? json_encode($resource[$key]) : $resource[$key] }}</td>
                                                </tr>
                                            @endif
                                        @endforeach
                                        @foreach($resource as $key => $value)
                                            @if(!in_array($key, $shown) && filled($value) && !is_array($value) && !in_array($key, ['id', 'created_at', 'updated_at', 'deleted_at']))
                                                <tr class="border-t border-neutral-200 dark:border-coolgray-300">
                                                    <td class="py-2 pr-4 font-mono text-xs text-gray-600 dark:text-gray-400">{{ $key }}</td>
                                                    <td class="py-2 font-mono text-xs break-all">{{ Str::limit((string) $value, 200) }}</td>
                                                </tr>
                                            @endif
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif
                </div>

                {{-- ============================================================ --}}
                {{-- Persistent Storages Section                                   --}}
                {{-- ============================================================ --}}
                @if(!empty($parsedBackup['persistent_storages']))
                    <div class="rounded border dark:border-coolgray-300 dark:bg-coolgray-100 bg-white">
                        <button wire:click="toggleSection('storages')" class="flex items-center justify-between w-full p-4 text-left">
                            <h3>Persistent Storages ({{ count($parsedBackup['persistent_storages']) }})</h3>
                            <svg class="w-5 h-5 transition-transform {{ in_array('storages', $expandedSections) ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>

                        @if(in_array('storages', $expandedSections))
                            <div class="px-4 pb-4 border-t border-neutral-200 dark:border-coolgray-300">
                                <div class="mt-3 text-sm text-gray-600 dark:text-gray-400 pb-3">
                                    These volume mounts were configured on the resource. Recreate them in the
                                    <strong>Persistent Storages</strong> section of your new resource.
                                </div>
                                <div class="overflow-x-auto">
                                    <table class="w-full text-sm">
                                        <thead>
                                            <tr class="text-left text-gray-500 dark:text-gray-400">
                                                <th class="py-2 pr-4 font-medium">Name</th>
                                                <th class="py-2 pr-4 font-medium">Mount Path</th>
                                                <th class="py-2 font-medium">Host Path</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($parsedBackup['persistent_storages'] as $storage)
                                                <tr class="border-t border-neutral-200 dark:border-coolgray-300">
                                                    <td class="py-2 pr-4 font-mono text-xs">{{ $storage['name'] ?? 'N/A' }}</td>
                                                    <td class="py-2 pr-4 font-mono text-xs">{{ $storage['mount_path'] ?? 'N/A' }}</td>
                                                    <td class="py-2 font-mono text-xs">{{ $storage['host_path'] ?? 'auto' }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endif
                    </div>
                @endif

                {{-- ============================================================ --}}
                {{-- Docker Compose Section                                        --}}
                {{-- ============================================================ --}}
                @if($parsedBackup['docker_compose_raw'])
                    <div class="rounded border dark:border-coolgray-300 dark:bg-coolgray-100 bg-white">
                        <button wire:click="toggleSection('compose')" class="flex items-center justify-between w-full p-4 text-left">
                            <h3>Docker Compose</h3>
                            <svg class="w-5 h-5 transition-transform {{ in_array('compose', $expandedSections) ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>

                        @if(in_array('compose', $expandedSections))
                            <div class="px-4 pb-4 border-t border-neutral-200 dark:border-coolgray-300">
                                <div class="mt-3 text-sm text-gray-600 dark:text-gray-400 pb-3">
                                    The raw Docker Compose file for this resource. Copy and paste this when creating a new
                                    Docker Compose-based application or service.
                                </div>
                                <pre class="p-3 rounded bg-gray-100 dark:bg-coolgray-200 text-xs font-mono overflow-x-auto max-h-96">{{ $parsedBackup['docker_compose_raw'] }}</pre>

                                @if($parsedBackup['docker_compose'] && $parsedBackup['docker_compose'] !== $parsedBackup['docker_compose_raw'])
                                    <h4 class="mt-4 pb-2 font-medium text-sm">Processed Compose (with Coolify modifications)</h4>
                                    <pre class="p-3 rounded bg-gray-100 dark:bg-coolgray-200 text-xs font-mono overflow-x-auto max-h-96">{{ $parsedBackup['docker_compose'] }}</pre>
                                @endif
                            </div>
                        @endif
                    </div>
                @endif

                {{-- ============================================================ --}}
                {{-- Custom Labels Section                                         --}}
                {{-- ============================================================ --}}
                @if($parsedBackup['custom_labels'])
                    <div class="rounded border dark:border-coolgray-300 dark:bg-coolgray-100 bg-white">
                        <button wire:click="toggleSection('labels')" class="flex items-center justify-between w-full p-4 text-left">
                            <h3>Custom Labels</h3>
                            <svg class="w-5 h-5 transition-transform {{ in_array('labels', $expandedSections) ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>

                        @if(in_array('labels', $expandedSections))
                            <div class="px-4 pb-4 border-t border-neutral-200 dark:border-coolgray-300">
                                <div class="mt-3 text-sm text-gray-600 dark:text-gray-400 pb-3">
                                    Custom Docker/Traefik labels. These can be pasted into the <strong>Custom Labels</strong>
                                    section in your resource's advanced settings.
                                </div>
                                <pre class="p-3 rounded bg-gray-100 dark:bg-coolgray-200 text-xs font-mono overflow-x-auto max-h-64">{{ $parsedBackup['custom_labels'] }}</pre>
                            </div>
                        @endif
                    </div>
                @endif

                {{-- ============================================================ --}}
                {{-- Service Sub-Resources                                         --}}
                {{-- ============================================================ --}}
                @if(!empty($parsedBackup['service_applications']) || !empty($parsedBackup['service_databases']))
                    <div class="rounded border dark:border-coolgray-300 dark:bg-coolgray-100 bg-white">
                        <button wire:click="toggleSection('service_sub')" class="flex items-center justify-between w-full p-4 text-left">
                            <h3>Service Components</h3>
                            <svg class="w-5 h-5 transition-transform {{ in_array('service_sub', $expandedSections) ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>

                        @if(in_array('service_sub', $expandedSections))
                            <div class="px-4 pb-4 border-t border-neutral-200 dark:border-coolgray-300">
                                @if(!empty($parsedBackup['service_applications']))
                                    <h4 class="mt-3 pb-2 font-medium">Service Applications ({{ count($parsedBackup['service_applications']) }})</h4>
                                    @foreach($parsedBackup['service_applications'] as $app)
                                        <div class="p-3 mb-2 rounded bg-gray-50 dark:bg-coolgray-200 text-sm">
                                            <span class="font-medium">{{ $app['name'] ?? 'Unknown' }}</span>
                                            @if(isset($app['fqdn']))
                                                <span class="text-gray-500 dark:text-gray-400 ml-2">{{ $app['fqdn'] }}</span>
                                            @endif
                                            @if(isset($app['image']))
                                                <span class="text-gray-500 dark:text-gray-400 ml-2 font-mono text-xs">{{ $app['image'] }}</span>
                                            @endif
                                        </div>
                                    @endforeach
                                @endif

                                @if(!empty($parsedBackup['service_databases']))
                                    <h4 class="mt-3 pb-2 font-medium">Service Databases ({{ count($parsedBackup['service_databases']) }})</h4>
                                    @foreach($parsedBackup['service_databases'] as $db)
                                        <div class="p-3 mb-2 rounded bg-gray-50 dark:bg-coolgray-200 text-sm">
                                            <span class="font-medium">{{ $db['name'] ?? 'Unknown' }}</span>
                                            @if(isset($db['image']))
                                                <span class="text-gray-500 dark:text-gray-400 ml-2 font-mono text-xs">{{ $db['image'] }}</span>
                                            @endif
                                        </div>
                                    @endforeach
                                @endif
                            </div>
                        @endif
                    </div>
                @endif

                {{-- ============================================================ --}}
                {{-- Restoration Guide                                             --}}
                {{-- ============================================================ --}}
                <div class="rounded border dark:border-coolgray-300 dark:bg-coolgray-100 bg-white">
                    <button wire:click="toggleSection('guide')" class="flex items-center justify-between w-full p-4 text-left">
                        <h3>Step-by-Step Restoration Guide</h3>
                        <svg class="w-5 h-5 transition-transform {{ in_array('guide', $expandedSections) ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>

                    @if(in_array('guide', $expandedSections))
                        <div class="px-4 pb-4 border-t border-neutral-200 dark:border-coolgray-300">
                            <div class="mt-3 flex flex-col gap-4 text-sm">
                                <div class="flex gap-3">
                                    <span class="flex-shrink-0 w-7 h-7 rounded-full bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-400 flex items-center justify-center text-xs font-bold">1</span>
                                    <div>
                                        <strong>Create the resource</strong>
                                        <p class="text-gray-600 dark:text-gray-400 mt-1">
                                            In your new Coolify instance, create a new
                                            <strong>{{ $parsedBackup['meta']['resource_type_short'] }}</strong>
                                            in your desired project and environment.
                                            @if($parsedBackup['docker_compose_raw'])
                                                Use the Docker Compose content from the backup (see "Docker Compose" section above).
                                            @elseif(isset($parsedBackup['resource']['git_repository']))
                                                Point it to the same git repository: <code>{{ $parsedBackup['resource']['git_repository'] }}</code>
                                                (branch: <code>{{ $parsedBackup['resource']['git_branch'] ?? 'main' }}</code>).
                                            @endif
                                        </p>
                                    </div>
                                </div>

                                <div class="flex gap-3">
                                    <span class="flex-shrink-0 w-7 h-7 rounded-full bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-400 flex items-center justify-center text-xs font-bold">2</span>
                                    <div>
                                        <strong>Configure resource settings</strong>
                                        <p class="text-gray-600 dark:text-gray-400 mt-1">
                                            Open the "Resource Settings" section above and apply the relevant settings
                                            (domains, ports, build commands, health checks, etc.) to match the original configuration.
                                        </p>
                                    </div>
                                </div>

                                @if(!empty($parsedBackup['environment_variables']))
                                    <div class="flex gap-3">
                                        <span class="flex-shrink-0 w-7 h-7 rounded-full bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-400 flex items-center justify-center text-xs font-bold">3</span>
                                        <div>
                                            <strong>Import environment variables</strong>
                                            <p class="text-gray-600 dark:text-gray-400 mt-1">
                                                Use the <strong>"Import into Existing Resource"</strong> feature above to bulk-import
                                                all {{ count($parsedBackup['environment_variables']) }} environment variables.
                                                Select your newly created resource from the dropdown and click "Import Variables".
                                            </p>
                                        </div>
                                    </div>
                                @endif

                                @if(!empty($parsedBackup['persistent_storages']))
                                    <div class="flex gap-3">
                                        <span class="flex-shrink-0 w-7 h-7 rounded-full bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-400 flex items-center justify-center text-xs font-bold">4</span>
                                        <div>
                                            <strong>Recreate persistent storages</strong>
                                            <p class="text-gray-600 dark:text-gray-400 mt-1">
                                                Go to the <strong>Persistent Storages</strong> section of your new resource and add
                                                the mount paths listed above. If you also have volume backup archives (<code>.tar.gz</code>),
                                                restore them to the server after the volumes are created.
                                            </p>
                                        </div>
                                    </div>
                                @endif

                                @if($parsedBackup['custom_labels'])
                                    <div class="flex gap-3">
                                        <span class="flex-shrink-0 w-7 h-7 rounded-full bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-400 flex items-center justify-center text-xs font-bold">5</span>
                                        <div>
                                            <strong>Apply custom labels</strong>
                                            <p class="text-gray-600 dark:text-gray-400 mt-1">
                                                Copy the labels from the "Custom Labels" section above and paste them into your
                                                resource's custom labels field (under advanced settings).
                                            </p>
                                        </div>
                                    </div>
                                @endif

                                <div class="flex gap-3">
                                    <span class="flex-shrink-0 w-7 h-7 rounded-full bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-400 flex items-center justify-center text-xs font-bold">&#10003;</span>
                                    <div>
                                        <strong>Deploy</strong>
                                        <p class="text-gray-600 dark:text-gray-400 mt-1">
                                            Once everything is configured, deploy the resource. Verify that the application
                                            is running correctly and all settings are applied.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>
