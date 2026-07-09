<div>
    @php
        $isGlobal = $mode === 'global';
        $description = $isGlobal
            ? new \Illuminate\Support\HtmlString('Schedule backups of the <code>/data/coolify</code> directory (configuration files, docker compose files, SSH keys, etc). The database backup above covers the Coolify database &mdash; this covers everything else.')
            : new \Illuminate\Support\HtmlString('Back up Docker volumes, resource configuration, or everything for <strong>' . e($resourceName ?? 'this resource') . '</strong>.');
    @endphp

    {{-- ============================================================ --}}
    {{-- SCHEDULED BACKUPS (one entry per schedule, with Details)      --}}
    {{-- ============================================================ --}}
    @if(count($backups) > 0)
        @foreach($backups as $backup)
            @php
                $typeLabel = \CorelixIo\Platform\Livewire\ResourceBackupManager::backupTypeLabel($backup['backup_type']);
                $typeColors = match($backup['backup_type']) {
                    'full' => 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-200',
                    'volume' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-200',
                    'coolify_instance' => 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-200',
                    default => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-200',
                };
            @endphp
            <div class="flex flex-col gap-0 mb-4">
                {{-- Schedule summary row --}}
                <div class="flex items-center justify-between p-3 rounded-t border border-neutral-200 dark:border-coolgray-300 dark:bg-coolgray-100 bg-white
                    {{ $expandedBackupId === $backup['id'] ? 'border-b-0' : 'rounded-b' }}">
                    <div class="flex items-center gap-3">
                        <span class="px-2 py-0.5 text-xs font-medium rounded {{ $typeColors }}">
                            {{ $typeLabel }}
                        </span>
                        <span class="text-sm">{{ $backup['frequency'] }}</span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Last: {{ $backup['latest_at'] }}</span>
                        @if($backup['latest_status'] === 'success')
                            <span class="text-xs text-success font-medium">OK</span>
                        @elseif($backup['latest_status'] === 'failed')
                            <span class="text-xs text-error font-medium">Failed</span>
                        @elseif($backup['latest_status'] === 'running')
                            <span class="text-xs text-warning font-medium">Running</span>
                        @endif
                        @if(!$backup['enabled'])
                            <span class="px-2 py-0.5 text-xs rounded bg-gray-100 text-gray-500 dark:bg-gray-800/50 dark:text-gray-400">Disabled</span>
                        @endif
                    </div>
                    <div class="flex items-center gap-2">
                        <x-forms.button isSmall wire:click.stop="runBackupNow({{ $backup['id'] }})">Backup Now</x-forms.button>
                        <x-forms.button isSmall wire:click.stop="toggleDetails({{ $backup['id'] }})">
                            {{ $expandedBackupId === $backup['id'] ? 'Close' : 'Details' }}
                        </x-forms.button>
                        <x-forms.button isSmall wire:click.stop="toggleBackup({{ $backup['id'] }})">
                            {{ $backup['enabled'] ? 'Disable' : 'Enable' }}
                        </x-forms.button>
                        <x-forms.button isSmall isError
                            wire:click.stop="deleteBackup({{ $backup['id'] }})"
                            wire:confirm="Are you sure you want to delete this backup schedule and all its executions?">
                            Delete
                        </x-forms.button>
                    </div>
                </div>

                {{-- Expanded details panel (settings only, no executions) --}}
                @if($expandedBackupId === $backup['id'])
                    <div class="border border-t-0 border-neutral-200 dark:border-coolgray-300 rounded-b p-4 dark:bg-coolgray-100 bg-white">
                        <form wire:submit="saveBackupSettings">
                            <div class="flex gap-2 pb-3">
                                <h3>Scheduled Backup</h3>
                                <x-forms.button type="submit">Save</x-forms.button>
                            </div>

                            <div class="w-64 pb-2">
                                <x-forms.checkbox instantSave="editInstantSave" label="Backup Enabled" id="editBackupEnabled" />
                                @if (count($availableS3Storages) > 0)
                                    <x-forms.checkbox instantSave="editInstantSave" label="S3 Enabled" id="editSaveS3" />
                                @else
                                    <x-forms.checkbox instantSave="editInstantSave" helper="No validated S3 storage available." label="S3 Enabled" id="editSaveS3" disabled />
                                @endif
                                @if ($editSaveS3)
                                    <x-forms.checkbox instantSave="editInstantSave" label="Disable Local Backup" id="editDisableLocalBackup"
                                        helper="When enabled, backup files will be deleted from local storage immediately after uploading to S3. This requires S3 backup to be enabled." />
                                @else
                                    <x-forms.checkbox disabled label="Disable Local Backup" id="editDisableLocalBackup"
                                        helper="When enabled, backup files will be deleted from local storage immediately after uploading to S3. This requires S3 backup to be enabled." />
                                @endif
                            </div>

                            @if ($editSaveS3)
                                <div class="pb-6">
                                    <x-forms.select id="editS3StorageId" label="S3 Storage" required>
                                        <option value="" disabled>Select a S3 storage</option>
                                        @foreach ($availableS3Storages as $storage)
                                            <option value="{{ $storage['id'] }}">{{ $storage['name'] }}</option>
                                        @endforeach
                                    </x-forms.select>
                                </div>
                            @endif

                            <div class="flex flex-col gap-2">
                                <h3>Settings</h3>
                                <div class="flex gap-2">
                                    <x-forms.input label="Frequency" id="editFrequency"
                                        helper="Cron expression for backup schedule. Examples: <code>0 2 * * *</code> (daily at 2AM), <code>0 */6 * * *</code> (every 6 hours), <code>0 0 * * 0</code> (weekly on Sunday)." />
                                    <x-forms.input label="Timezone" id="editTimezone" disabled
                                        helper="The timezone of the server where the backup is scheduled to run (if not set, the instance timezone will be used)." />
                                    <x-forms.input label="Timeout" id="editTimeout" type="number"
                                        helper="The timeout of the backup job in seconds. Default: 3600 (1 hour)." />
                                </div>

                                <h3 class="mt-6 mb-2 text-lg font-medium">Backup Retention Settings</h3>
                                <div class="mb-4">
                                    <ul class="list-disc pl-6 space-y-2">
                                        <li>Setting a value to <strong>0</strong> means <strong>unlimited retention</strong> (no automatic cleanup).</li>
                                        <li>The retention rules work independently — whichever limit is reached first will trigger cleanup.</li>
                                    </ul>
                                </div>

                                <div class="flex gap-6 flex-col">
                                    <div>
                                        <h4 class="mb-3 font-medium">Local Backup Retention</h4>
                                        <div class="flex gap-2">
                                            <x-forms.input label="Number of backups to keep" id="editRetentionAmountLocally" type="number" min="0"
                                                helper="Keeps only the specified number of most recent backups on the server. Set to 0 for unlimited backups." />
                                            <x-forms.input label="Days to keep backups" id="editRetentionDaysLocally" type="number" min="0"
                                                helper="Automatically removes backups older than the specified number of days. Set to 0 for no time limit." />
                                            <x-forms.input label="Maximum storage (GB)" id="editRetentionMaxStorageLocally" type="number" min="0"
                                                helper="When total size of all backups exceeds this limit in GB, the oldest backups will be removed. Decimal values are supported (e.g. 0.001 for 1MB). Set to 0 for unlimited storage." />
                                        </div>
                                    </div>

                                    @if ($editSaveS3)
                                        <div>
                                            <h4 class="mb-3 font-medium">S3 Storage Retention</h4>
                                            <div class="flex gap-2">
                                                <x-forms.input label="Number of backups to keep" id="editRetentionAmountS3" type="number" min="0"
                                                    helper="Keeps only the specified number of most recent backups on S3 storage. Set to 0 for unlimited backups." />
                                                <x-forms.input label="Days to keep backups" id="editRetentionDaysS3" type="number" min="0"
                                                    helper="Automatically removes S3 backups older than the specified number of days. Set to 0 for no time limit." />
                                                <x-forms.input label="Maximum storage (GB)" id="editRetentionMaxStorageS3" type="number" min="0"
                                                    helper="When total size of all backups exceeds this limit in GB on S3, the oldest backups will be removed. Decimal values are supported (e.g. 0.5 for 500MB). Set to 0 for unlimited storage." />
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </form>
                    </div>
                @endif
            </div>
        @endforeach
    @endif

    {{-- ============================================================ --}}
    {{-- EXECUTIONS (standalone section, below all schedules)          --}}
    {{-- ============================================================ --}}
    @if($selectedBackupId)
        <div class="py-4">
            <div class="flex items-center gap-2">
                <h3 class="py-2">Executions <span class="text-xs">({{ $executionCount }})</span></h3>
                @if ($executionCount > 0)
                    <div class="flex items-center gap-2">
                        <x-forms.button disabled="{{ $executionSkip <= 0 }}" wire:click="previousExecutionPage">
                            <svg class="w-4 h-4" viewBox="0 0 24 24">
                                <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m14 6l-6 6l6 6z" />
                            </svg>
                        </x-forms.button>
                        <span class="text-sm text-gray-600 dark:text-gray-400 px-2">
                            Page {{ floor($executionSkip / $executionTake) + 1 }} of {{ max(1, ceil($executionCount / $executionTake)) }}
                        </span>
                        <x-forms.button disabled="{{ ($executionSkip + $executionTake) >= $executionCount }}" wire:click="nextExecutionPage">
                            <svg class="w-4 h-4" viewBox="0 0 24 24">
                                <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m10 18l6-6l-6-6z" />
                            </svg>
                        </x-forms.button>
                    </div>
                @endif
                <x-forms.button wire:click='cleanupFailed'>Cleanup Failed Backups</x-forms.button>
            </div>
            <div @if ($executionSkip === 0) wire:poll.5000ms="refreshExecutions" @endif class="flex flex-col gap-4 mt-2">
                @forelse($executions as $execution)
                    <div wire:key="exec-{{ $execution['id'] }}" @class([
                        'flex flex-col border-l-2 transition-colors p-4 bg-white dark:bg-coolgray-100 text-black dark:text-white',
                        'border-blue-500/50 border-dashed' => $execution['status'] === 'running',
                        'border-error' => $execution['status'] === 'failed',
                        'border-success' => $execution['status'] === 'success',
                    ])>
                        {{-- Status badge --}}
                        <div class="flex items-center gap-2 mb-2">
                            <span @class([
                                'px-3 py-1 rounded-md text-xs font-medium tracking-wide shadow-xs',
                                'bg-blue-100/80 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300' => $execution['status'] === 'running',
                                'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200' => $execution['status'] === 'failed',
                                'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200' => $execution['status'] === 'success' && $execution['s3_uploaded'] === false,
                                'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-200' => $execution['status'] === 'success' && $execution['s3_uploaded'] !== false,
                            ])>
                                @php
                                    $statusText = match ($execution['status']) {
                                        'success' => $execution['s3_uploaded'] === false ? 'Success (S3 Warning)' : 'Success',
                                        'running' => 'In Progress',
                                        'failed' => 'Failed',
                                        default => ucfirst($execution['status']),
                                    };
                                @endphp
                                {{ $statusText }}
                            </span>
                            @if($execution['backup_label'])
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $execution['backup_label'] }}</span>
                            @endif
                        </div>

                        {{-- Metadata --}}
                        <div class="text-gray-600 dark:text-gray-400 text-sm">
                            @if ($execution['status'] === 'running')
                                Running...
                            @else
                                {{ $execution['finished_at_human'] ?? $execution['created_at_human'] }}
                                @if($execution['duration'])
                                    ({{ $execution['duration'] }})
                                @endif
                                @if($execution['finished_at_formatted'])
                                    &bull; {{ $execution['finished_at_formatted'] }}
                                @endif
                            @endif
                            @if($execution['size_formatted'])
                                &bull; Size: {{ $execution['size_formatted'] }}
                            @endif
                        </div>

                        {{-- Location --}}
                        @if($execution['filename'])
                            <div class="text-gray-600 dark:text-gray-400 text-sm">
                                Location: {{ $execution['filename'] }}
                            </div>
                        @endif

                        {{-- Backup Availability badges --}}
                        <div class="flex items-center gap-3 mt-2">
                            <div class="text-gray-600 dark:text-gray-400 text-sm">Backup Availability:</div>

                            {{-- Local Storage badge --}}
                            <span @class([
                                'px-2 py-1 rounded-sm text-xs font-medium',
                                'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-200' => !$execution['local_storage_deleted'],
                                'bg-gray-100 text-gray-600 dark:bg-gray-800/50 dark:text-gray-400' => $execution['local_storage_deleted'],
                            ])>
                                <span class="flex items-center gap-1">
                                    @if (!$execution['local_storage_deleted'])
                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                                    @else
                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path></svg>
                                    @endif
                                    Local Storage
                                </span>
                            </span>

                            {{-- S3 Storage badge --}}
                            @if ($execution['s3_uploaded'] !== null)
                                <span @class([
                                    'px-2 py-1 rounded-sm text-xs font-medium',
                                    'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200' => $execution['s3_uploaded'] === false && !$execution['s3_storage_deleted'],
                                    'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-200' => $execution['s3_uploaded'] === true && !$execution['s3_storage_deleted'],
                                    'bg-gray-100 text-gray-600 dark:bg-gray-800/50 dark:text-gray-400' => $execution['s3_storage_deleted'],
                                ])>
                                    <span class="flex items-center gap-1">
                                        @if ($execution['s3_uploaded'] === true && !$execution['s3_storage_deleted'])
                                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                                        @else
                                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path></svg>
                                        @endif
                                        S3 Storage
                                    </span>
                                </span>
                            @endif

                            {{-- Encrypted badge --}}
                            @if ($execution['is_encrypted'])
                                <span class="px-2 py-1 rounded-sm text-xs font-medium bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-200">
                                    <span class="flex items-center gap-1">
                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"></path></svg>
                                        Encrypted
                                    </span>
                                </span>
                            @endif
                        </div>

                        {{-- Error/log message --}}
                        @if ($execution['message'])
                            <div class="mt-2 p-2 rounded-sm border border-neutral-200 dark:border-transparent bg-neutral-100 dark:bg-coolgray-200">
                                <pre class="whitespace-pre-wrap text-sm">{{ $execution['message'] }}</pre>
                            </div>
                        @endif

                        {{-- Action buttons --}}
                        <div class="flex gap-2 mt-4">
                            <x-forms.button isSmall isError
                                wire:click="deleteExecution({{ $execution['id'] }})"
                                wire:confirm="Are you sure you want to delete this backup execution?">
                                Delete
                            </x-forms.button>
                        </div>
                    </div>
                @empty
                    <div class="p-4 rounded-sm border border-neutral-200 dark:border-transparent bg-neutral-100 dark:bg-coolgray-100">No executions found.</div>
                @endforelse
            </div>
        </div>
    @endif

    {{-- ============================================================ --}}
    {{-- NEW BACKUP SCHEDULE form                                     --}}
    {{-- ============================================================ --}}
    <div class="flex flex-col gap-2 p-4 rounded border border-neutral-200 dark:border-coolgray-300 dark:bg-coolgray-100 bg-white mt-4">
        <h3>New Backup Schedule</h3>
        <div class="pb-2 text-sm text-gray-600 dark:text-gray-400">{{ $description }}</div>

        @if(in_array($backupType, ['configuration', 'full'], true))
            <div class="flex items-start gap-2 p-3 rounded-md text-warning">
                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.168 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 6a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 6zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/>
                </svg>
                <div class="text-sm">
                    <strong>Security notice:</strong> Configuration and full backups export environment variables,
                    database passwords, and API tokens in <strong>plaintext JSON</strong>. Restrict S3 access,
                    enable S3 encryption at rest, and treat backup files as highly sensitive credentials.
                </div>
            </div>
        @endif

        <div class="grid grid-cols-2 gap-2">
            <x-forms.select id="backupType" label="Backup Type">
                @foreach($this->backupTypeOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-forms.select>

            <x-forms.input
                id="frequency"
                label="Schedule (Cron)"
                placeholder="0 2 * * *"
                helper="Cron expression for backup schedule. Examples: <code>0 2 * * *</code> (daily at 2AM), <code>0 */6 * * *</code> (every 6 hours), <code>0 0 * * 0</code> (weekly on Sunday)."
            />
        </div>

        @if($backupType === 'coolify_instance')
            <div class="flex items-start gap-2 p-3 rounded-md text-sm opacity-80">
                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a.75.75 0 000 1.5h.253a.25.25 0 01.244.304l-.459 2.066A1.75 1.75 0 0010.747 15H11a.75.75 0 000-1.5h-.253a.25.25 0 01-.244-.304l.459-2.066A1.75 1.75 0 009.253 9H9z" clip-rule="evenodd"/>
                </svg>
                <div>
                    Backs up <code>/data/coolify</code> (source, SSH keys, application/service/database configs).
                    <strong>Excludes</strong> <code>/data/coolify/backups</code> and <code>/data/coolify/metrics</code>
                    to avoid duplicating existing backups. Database data should be backed up separately using Coolify's built-in database backup feature.
                </div>
            </div>
        @endif

        <div class="grid grid-cols-2 gap-2">
            <x-forms.input
                id="timezone"
                label="Timezone"
                placeholder="Server timezone if empty"
                helper="The timezone of the server where the backup is scheduled to run (if not set, the instance timezone will be used)."
            />
            <x-forms.input
                type="number"
                id="timeout"
                label="Timeout (seconds)"
                placeholder="3600"
                helper="The timeout of the backup job in seconds. Default: 3600 (1 hour)."
            />
        </div>

        <div class="w-64 pt-2">
            <x-forms.checkbox id="saveS3" label="Upload to S3" />
            @if($saveS3)
                <x-forms.checkbox id="disableLocalBackup" label="Delete local after S3 upload"
                    helper="When enabled, backup files will be deleted from local storage immediately after uploading to S3." />
            @endif
        </div>

        @if($saveS3)
            <x-forms.select id="s3StorageId" label="S3 Storage">
                <option value="">Select S3 Storage...</option>
                @foreach($availableS3Storages as $storage)
                    <option value="{{ $storage['id'] }}">{{ $storage['name'] }}</option>
                @endforeach
            </x-forms.select>
        @endif

        <h4 class="mt-4 mb-2 text-lg font-medium">Backup Retention Settings</h4>
        <div class="mb-4">
            <ul class="list-disc pl-6 space-y-2 text-sm">
                <li>Setting a value to <strong>0</strong> means <strong>unlimited retention</strong> (no automatic cleanup).</li>
                <li>The retention rules work independently — whichever limit is reached first will trigger cleanup.</li>
            </ul>
        </div>

        <div class="flex gap-6 flex-col">
            <div>
                <h4 class="mb-3 font-medium">Local Backup Retention</h4>
                <div class="flex gap-2">
                    <x-forms.input type="number" id="retentionAmountLocally" label="Number of backups to keep" min="0"
                        helper="Keeps only the specified number of most recent backups on the server. Set to 0 for unlimited backups." />
                    <x-forms.input type="number" id="retentionDaysLocally" label="Days to keep backups" min="0"
                        helper="Automatically removes backups older than the specified number of days. Set to 0 for no time limit." />
                    <x-forms.input type="number" id="retentionMaxStorageLocally" label="Maximum storage (GB)" min="0"
                        helper="When total size exceeds this limit in GB, the oldest backups will be removed. Decimal values are supported (e.g. 0.001 for 1MB). Set to 0 for unlimited storage." />
                </div>
            </div>

            @if($saveS3)
                <div>
                    <h4 class="mb-3 font-medium">S3 Storage Retention</h4>
                    <div class="flex gap-2">
                        <x-forms.input type="number" id="retentionAmountS3" label="Number of backups to keep" min="0"
                            helper="Keeps only the specified number of most recent backups on S3. Set to 0 for unlimited backups." />
                        <x-forms.input type="number" id="retentionDaysS3" label="Days to keep backups" min="0"
                            helper="Automatically removes S3 backups older than the specified number of days. Set to 0 for no time limit." />
                        <x-forms.input type="number" id="retentionMaxStorageS3" label="Maximum storage (GB)" min="0"
                            helper="When total size exceeds this limit in GB on S3, the oldest backups will be removed. Decimal values are supported (e.g. 0.5 for 500MB). Set to 0 for unlimited storage." />
                    </div>
                </div>
            @endif
        </div>

        <div class="flex items-center gap-3 pt-4">
            <x-forms.button wire:click="createBackup">Create Backup Schedule</x-forms.button>
            @if($saveMessage)
                <span class="text-sm {{ $saveStatus === 'success' ? 'text-success' : 'text-error' }}">
                    {{ $saveMessage }}
                </span>
            @endif
        </div>
    </div>
</div>
