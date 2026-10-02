<div {{ $hasSyncingSource ? 'wire:poll.3000ms' : '' }}>
    <x-slot:title>
        Settings | Coolify
    </x-slot>
    <x-settings.navbar />

    <div class="flex flex-col gap-2">
        <div class="flex items-center justify-between">
            <div>
                <h2>Custom Template Sources</h2>
                <div class="subtitle">
                    Add GitHub repositories containing docker-compose templates.
                    Templates will appear in the one-click service list when creating new resources.
                </div>
            </div>
        </div>

        @if($showStartupSyncBanner ?? false)
            <div class="mt-2 p-3 rounded border border-blue-200 dark:border-blue-500/30 bg-blue-50 dark:bg-blue-500/10 text-sm text-blue-800 dark:text-blue-300">
                Startup recovery sync is in progress. Template cache files were missing, so enabled sources are being synced automatically.
            </div>
        @endif

        <div class="flex gap-2 mt-2">
            @if(!$showForm)
                <x-forms.button wire:click="showAddForm">+ Add Source</x-forms.button>
            @endif
            @if($sources->where('enabled', true)->count() > 0)
                <x-forms.button wire:click="syncAll" isHighlighted>Sync All</x-forms.button>
            @endif
        </div>
    </div>

    {{-- Add/Edit Form --}}
    @if($showForm)
        <div class="flex flex-col gap-2 p-4 mt-4 rounded border border-neutral-200 dark:border-coolgray-300 bg-white dark:bg-coolgray-100">
            <h3>{{ $editingSourceId ? 'Edit Source' : 'Add New Source' }}</h3>

            <x-forms.input
                id="formName"
                label="Name"
                required
                placeholder="e.g., My Company Templates"
                helper="A display name to identify this template source."
            />

            <x-forms.input
                id="formRepositoryUrl"
                label="Repository URL"
                required
                placeholder="https://github.com/owner/repo"
                helper="GitHub repository URL. Supports github.com and GitHub Enterprise."
            />

            <div class="grid grid-cols-1 gap-2 lg:grid-cols-2">
                <x-forms.input
                    id="formBranch"
                    label="Branch"
                    required
                    placeholder="main"
                    helper="Git branch to fetch templates from."
                />

                <x-forms.input
                    id="formFolderPath"
                    label="Folder Path"
                    required
                    placeholder="templates/compose"
                    helper="Path within the repository where YAML template files are located."
                />
            </div>

            <x-forms.input
                type="password"
                id="formAuthToken"
                label="Auth Token (optional)"
                placeholder="{{ $editingSourceId ? 'Leave empty to keep existing token' : 'GitHub Personal Access Token' }}"
                helper="Required for private repositories. Use a fine-grained PAT with 'Contents: Read' permission."
            />

            <div class="flex gap-2 mt-2">
                <x-forms.button wire:click="saveSource">
                    {{ $editingSourceId ? 'Save Changes' : 'Save & Sync' }}
                </x-forms.button>
                <x-forms.button wire:click="cancelForm" isError>Cancel</x-forms.button>
            </div>
        </div>
    @endif

    {{-- Sources List --}}
    @if($sources->count() > 0)
        <div class="flex flex-col gap-4 mt-4">
            @foreach($sources as $source)
                <div class="rounded border border-neutral-200 dark:border-coolgray-300 bg-white dark:bg-coolgray-100 {{ !$source->enabled ? 'opacity-60' : '' }}">
                    {{-- Source Header --}}
                    <div class="flex items-start justify-between gap-4 p-4">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <h4 class="truncate">{{ $source->name }}</h4>

                                @if($source->last_sync_status === 'success')
                                    <span class="inline-flex items-center gap-1 text-xs px-1.5 py-0.5 rounded bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-400">
                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                        synced
                                    </span>
                                @elseif($source->last_sync_status === 'failed')
                                    <span class="text-xs px-1.5 py-0.5 rounded bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-400">sync failed</span>
                                @elseif($source->last_sync_status === 'syncing')
                                    <span class="inline-flex items-center gap-1 text-xs px-1.5 py-0.5 rounded bg-yellow-100 text-yellow-700 dark:bg-yellow-500/20 dark:text-yellow-400">
                                        <svg class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                        syncing…
                                    </span>
                                @else
                                    <span class="text-xs px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-600 dark:bg-neutral-500/20 dark:text-neutral-400">never synced</span>
                                @endif

                                @if(!$source->enabled)
                                    <span class="text-xs px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-700 dark:bg-neutral-500/20 dark:text-neutral-400">disabled</span>
                                @endif
                            </div>

                            <div class="text-sm text-neutral-600 dark:text-neutral-500 truncate mt-0.5">
                                {{ $source->repository_url }}
                            </div>

                            <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-neutral-500 dark:text-neutral-500 mt-1.5">
                                <span class="flex items-center gap-1">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z" /><path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6z" /></svg>
                                    {{ $source->branch }}
                                </span>
                                <span class="flex items-center gap-1">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z" /></svg>
                                    {{ $source->folder_path }}
                                </span>
                                @if($source->template_count > 0)
                                    <span class="flex items-center gap-1">
                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 010 3.75H5.625a1.875 1.875 0 010-3.75z" /></svg>
                                        {{ $source->template_count }} {{ Str::plural('template', $source->template_count) }}
                                    </span>
                                @endif
                                @if($source->last_synced_at)
                                    <span>Synced {{ $source->last_synced_at->diffForHumans() }}</span>
                                @endif
                                @if($source->auth_token)
                                    <span class="flex items-center gap-1">
                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" /></svg>
                                        authenticated
                                    </span>
                                @endif
                            </div>

                            @if($source->last_sync_status === 'failed' && $source->last_sync_error)
                                <div class="text-xs text-red-700 dark:text-red-400 mt-2 p-2 rounded bg-red-100 dark:bg-red-500/10 break-words">
                                    <strong>Sync error:</strong> {{ Str::limit($source->last_sync_error, 300) }}
                                </div>
                            @endif
                        </div>

                        {{-- Action Buttons --}}
                        <div class="flex gap-2 shrink-0 flex-wrap justify-end">
                            <x-forms.button
                                wire:click="syncSource({{ $source->id }})"
                                :disabled="$source->last_sync_status === 'syncing'"
                            >
                                @if($source->last_sync_status === 'syncing')
                                    Syncing…
                                @else
                                    Sync
                                @endif
                            </x-forms.button>
                            <x-forms.button wire:click="editSource({{ $source->id }})">Edit</x-forms.button>
                            <x-forms.button
                                wire:click="toggleEnabled({{ $source->id }})"
                            >{{ $source->enabled ? 'Disable' : 'Enable' }}</x-forms.button>
                            <x-forms.button
                                wire:click="deleteSource({{ $source->id }})"
                                wire:confirm="Are you sure you want to delete '{{ $source->name }}'? Deployed services from these templates will not be affected."
                                isError
                            >Delete</x-forms.button>
                        </div>
                    </div>

                    {{-- Expandable Templates List --}}
                    @if($source->template_count > 0 || $source->hasCachedTemplates())
                        <div class="border-t border-neutral-100 dark:border-coolgray-200 px-4 py-2">
                            <button
                                wire:click="toggleExpanded('{{ $source->uuid }}')"
                                class="flex items-center gap-1.5 text-xs text-neutral-600 hover:text-neutral-800 dark:text-neutral-400 dark:hover:text-neutral-200 transition-colors"
                            >
                                @if($expandedSourceUuid === $source->uuid)
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5" /></svg>
                                    Hide Templates
                                @else
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                                    Show Templates
                                    @php $count = $source->template_count ?: count($source->loadCachedTemplates()); @endphp
                                    @if($count > 0)
                                        <span class="px-1 rounded bg-neutral-200 dark:bg-coolgray-300">{{ $count }}</span>
                                    @endif
                                @endif
                            </button>

                            @if($expandedSourceUuid === $source->uuid)
                                <div class="mt-3">
                                    {{-- Search bar --}}
                                    @if($source->template_count > 6 || count($source->loadCachedTemplates()) > 6)
                                        <div class="mb-3">
                                            <input
                                                type="text"
                                                wire:model.live.debounce.300ms="templateSearch"
                                                placeholder="Search templates…"
                                                class="w-full text-xs rounded border border-neutral-200 dark:border-coolgray-300 bg-transparent px-2 py-1.5 dark:text-white placeholder-neutral-400 focus:outline-none focus:ring-1 focus:ring-coollabs"
                                            />
                                        </div>
                                    @endif

                                    {{-- Template Grid --}}
                                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                        @forelse($this->expandedTemplates as $key => $template)
                                            @php
                                                $logo = data_get($template, 'logo', '');
                                                $isUrl = str_starts_with($logo, 'http');
                                                $slogan = data_get($template, 'slogan', '');
                                                $tags = data_get($template, 'tags', []);
                                                $category = data_get($template, 'category', '');
                                                $documentation = data_get($template, 'documentation', '');
                                                $isIgnored = data_get($template, '_ignored', false);
                                                $displayName = str($key)->headline()->toString();
                                            @endphp
                                            <div class="flex items-start gap-2.5 p-2.5 rounded border border-neutral-100 dark:border-coolgray-200 bg-white dark:bg-coolgray-200/30 hover:border-neutral-300 dark:hover:border-coolgray-300 transition-colors {{ $isIgnored ? 'opacity-50' : '' }}">
                                                {{-- Logo --}}
                                                <div class="shrink-0 mt-0.5">
                                                    @if($isUrl)
                                                        <img src="{{ $logo }}" class="w-7 h-7 object-contain rounded" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'" alt="{{ $displayName }}" />
                                                        <div class="w-7 h-7 rounded bg-neutral-200 dark:bg-neutral-700 items-center justify-center text-xs font-semibold text-neutral-600 dark:text-neutral-300" style="display:none">
                                                            {{ strtoupper(substr($key, 0, 1)) }}
                                                        </div>
                                                    @else
                                                        <div class="w-7 h-7 rounded bg-neutral-200 dark:bg-neutral-700 flex items-center justify-center text-xs font-semibold text-neutral-600 dark:text-neutral-300">
                                                            {{ strtoupper(substr($key, 0, 1)) }}
                                                        </div>
                                                    @endif
                                                </div>

                                                {{-- Details --}}
                                                <div class="flex-1 min-w-0">
                                                    <div class="flex items-center gap-1.5 flex-wrap">
                                                        <span class="text-xs font-medium text-neutral-800 dark:text-neutral-200 truncate">{{ $displayName }}</span>
                                                        @if($isIgnored)
                                                            <span class="text-xs px-1 py-0 rounded bg-yellow-100 text-yellow-700 dark:bg-yellow-500/20 dark:text-yellow-400">ignored</span>
                                                        @endif
                                                    </div>
                                                    @if($slogan)
                                                        <div class="text-xs text-neutral-500 dark:text-neutral-500 truncate mt-0.5">{{ $slogan }}</div>
                                                    @endif
                                                    <div class="flex items-center gap-1.5 mt-1 flex-wrap">
                                                        @if($category)
                                                            <span class="text-xs px-1 py-0 rounded bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-400">{{ $category }}</span>
                                                        @endif
                                                        @if(is_array($tags))
                                                            @foreach(array_slice($tags, 0, 3) as $tag)
                                                                <span class="text-xs px-1 py-0 rounded bg-neutral-100 text-neutral-600 dark:bg-coolgray-300 dark:text-neutral-400">{{ $tag }}</span>
                                                            @endforeach
                                                            @if(count($tags) > 3)
                                                                <span class="text-xs text-neutral-400">+{{ count($tags) - 3 }}</span>
                                                            @endif
                                                        @endif
                                                        @if($documentation)
                                                            <a href="{{ $documentation }}" target="_blank" rel="noopener" class="text-xs text-coollabs hover:underline ml-auto">docs</a>
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                        @empty
                                            <div class="col-span-full py-4 text-center text-sm text-neutral-500 dark:text-neutral-500">
                                                @if(filled($templateSearch))
                                                    No templates match "<span class="font-medium">{{ $templateSearch }}</span>".
                                                    <button wire:click="$set('templateSearch', '')" class="ml-1 text-coollabs hover:underline">Clear search</button>
                                                @else
                                                    No templates cached. Try clicking <strong>Sync</strong>.
                                                @endif
                                            </div>
                                        @endforelse
                                    </div>

                                    @php $total = $source->template_count ?: count($source->loadCachedTemplates()); @endphp
                                    @if(filled($templateSearch) && count($this->expandedTemplates) < $total)
                                        <div class="mt-2 text-xs text-neutral-500 dark:text-neutral-500 text-center">
                                            Showing {{ count($this->expandedTemplates) }} of {{ $total }} templates
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @elseif($source->last_sync_status !== 'syncing')
                        <div class="border-t border-neutral-100 dark:border-coolgray-200 px-4 py-2">
                            <span class="text-xs text-neutral-500 dark:text-neutral-500">
                                No templates synced yet.
                                @if($source->enabled)
                                    Click <button wire:click="syncSource({{ $source->id }})" class="text-coollabs hover:underline">Sync</button> to fetch templates.
                                @endif
                            </span>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @elseif(!$showForm)
        <div class="mt-4 p-8 text-center rounded border border-dashed border-neutral-200 dark:border-coolgray-300">
            <div class="text-neutral-600 dark:text-neutral-400 mb-2">No custom template sources configured.</div>
            <div class="text-sm text-neutral-600 dark:text-neutral-500">
                Add a GitHub repository containing docker-compose YAML files to extend the one-click service list.
            </div>
        </div>
    @endif
    </div>
</div>
