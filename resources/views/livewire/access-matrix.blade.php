<div
    x-data="{ hasPending: @entangle('hasPendingChanges') }"
    x-on:beforeunload.window="if (hasPending) { $event.preventDefault(); $event.returnValue = ''; }"
    x-init="
        document.addEventListener('livewire:navigate', function(e) {
            if (hasPending && !confirm('You have unsaved permission changes. Leave without saving?')) {
                e.preventDefault();
            }
        });
    "
>
    {{-- Granular Permissions - Access Matrix --}}
    <div class="mt-10 pt-8 border-t border-neutral-200 dark:border-coolgray-300">
        {{-- Header row: title + status + save button --}}
        <div class="flex items-center justify-between mb-2 gap-4">
            <div class="flex items-center gap-3 flex-1 min-w-0">
                <h2 class="truncate">Granular Access Management</h2>
                @if(! config('corelix-platform.enabled', false))
                    <span class="inline-flex items-center px-3 py-1 text-xs font-semibold rounded border border-orange-300 dark:border-yellow-600 bg-orange-50 dark:bg-yellow-900/30 text-orange-700 dark:text-warning shrink-0">
                        Disabled
                    </span>
                @else
                    <span class="inline-flex items-center px-3 py-1 text-xs font-semibold rounded border border-green-300 dark:border-green-700 bg-green-50 dark:bg-green-900/30 text-green-700 dark:text-success shrink-0">
                        Active
                    </span>
                @endif
            </div>

            {{-- Save / Discard buttons --}}
            <div class="flex items-center gap-2 shrink-0">
                @if($hasPendingChanges)
                    <button
                        wire:click="discardChanges"
                        class="button px-3 py-1.5 text-xs !h-auto"
                    >Discard</button>
                    <button
                        wire:click="saveChanges"
                        class="button px-3 py-1.5 text-xs !h-auto"
                        isHighlighted
                    >Save Changes</button>
                @endif
            </div>
        </div>

        {{-- Notification banner --}}
        @if($saveMessage)
            <div class="rounded border p-3 mb-4 text-sm flex items-center justify-between
                {{ $saveStatus === 'success'
                    ? 'border-green-300 dark:border-green-700 bg-green-50 dark:bg-green-900/20 text-green-800 dark:text-green-200'
                    : 'border-red-300 dark:border-red-700 bg-red-50 dark:bg-red-900/20 text-red-800 dark:text-red-200' }}">
                <span>{{ $saveMessage }}</span>
                <button wire:click="$set('saveMessage', '')" class="ml-4 text-xs opacity-60 hover:opacity-100">&times;</button>
            </div>
        @endif

        {{-- Pending changes indicator --}}
        @if($hasPendingChanges)
            <div class="rounded border border-amber-300 dark:border-yellow-700 bg-amber-50 dark:bg-yellow-900/20 p-3 mb-4 text-sm text-amber-800 dark:text-yellow-200 flex items-center gap-2">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L4.268 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                You have unsaved changes. Click <strong class="mx-1">Save Changes</strong> to apply.
            </div>
        @endif

        <div class="subtitle">
            Manage per-user access to projects and environments. Owners and Admins bypass all checks.
        </div>

        @if(! config('corelix-platform.enabled', false))
            <div class="rounded border border-orange-300 dark:border-yellow-700 bg-orange-50 dark:bg-yellow-900/20 p-4 mb-6">
                <p class="text-sm text-orange-800 dark:text-yellow-200">
                    Granular permissions are currently disabled. Set <code class="px-1.5 py-0.5 rounded text-xs bg-neutral-200 dark:bg-coolgray-300 font-mono">CORELIX_GRANULAR_PERMISSIONS=true</code> in your environment to enable.
                </p>
            </div>
        @endif

        {{-- Search and Bulk Level --}}
        <div class="flex flex-col gap-2 lg:flex-row mb-6">
            <div class="flex-1">
                <x-forms.input
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search users by name, email, or role..."
                />
            </div>
            <div class="flex items-center gap-2">
                <x-forms.select wire:model="bulkLevel" label="Bulk level:">
                    <option value="full_access">Full Access</option>
                    <option value="deploy">Deploy</option>
                    <option value="view_only">View Only</option>
                    <option value="none">No Access</option>
                </x-forms.select>
            </div>
        </div>

        @if(count($projects) === 0)
            <div class="rounded border border-neutral-200 dark:border-coolgray-300 bg-white dark:bg-coolgray-100 p-8 text-center">
                <p class="text-neutral-500 dark:text-neutral-400">No projects found in this team.</p>
            </div>
        @elseif(count($filteredUsers) === 0)
            <div class="rounded border border-neutral-200 dark:border-coolgray-300 bg-white dark:bg-coolgray-100 p-8 text-center">
                <p class="text-neutral-500 dark:text-neutral-400">No users match your search.</p>
            </div>
        @else
            {{-- Matrix Table --}}
            <div class="rounded border border-neutral-200 dark:border-coolgray-300 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full border-collapse">
                        {{-- Header Row 1: Project groups --}}
                        <thead>
                            <tr class="bg-neutral-50 dark:bg-coolgray-200 border-b border-neutral-200 dark:border-coolgray-300">
                                <th class="sticky left-0 z-20 bg-neutral-50 dark:bg-coolgray-200 px-3 py-3 text-left text-xs font-medium uppercase tracking-wider min-w-[200px] border-r border-neutral-200 dark:border-coolgray-300" rowspan="2">
                                    User
                                </th>
                                <th class="sticky left-[200px] z-20 bg-neutral-50 dark:bg-coolgray-200 px-3 py-3 text-center text-xs font-medium uppercase tracking-wider min-w-[90px] border-r border-neutral-200 dark:border-coolgray-300" rowspan="2">
                                    Team Role
                                </th>
                                <th class="px-3 py-3 text-center text-xs font-medium uppercase tracking-wider min-w-[70px] border-r border-neutral-200 dark:border-coolgray-300" rowspan="2">
                                    Actions
                                </th>
                                @foreach($projects as $project)
                                    <th
                                        class="px-2 py-2.5 text-center text-xs font-bold tracking-wider border-r border-neutral-200 dark:border-coolgray-300 bg-neutral-100 dark:bg-coolgray-300"
                                        colspan="{{ 1 + count($project['environments']) }}"
                                    >
                                        <span class="truncate max-w-[200px] inline-block text-black dark:text-white" title="{{ $project['name'] }}">
                                            Project: {{ $project['name'] }}
                                        </span>
                                    </th>
                                @endforeach
                            </tr>

                            {{-- Header Row 2: Project-level + Environment sub-columns --}}
                            <tr class="bg-neutral-50 dark:bg-coolgray-200 border-b border-neutral-200 dark:border-coolgray-300">
                                @foreach($projects as $project)
                                    {{-- Project-level column --}}
                                    <th class="px-2 py-2 text-center border-r border-neutral-200 dark:border-coolgray-300 min-w-[120px]">
                                        <div class="flex flex-col items-center gap-1">
                                            <span class="text-xs font-semibold text-coollabs dark:text-warning">Project Level</span>
                                            <div class="flex gap-1">
                                                <button
                                                    wire:click="setAllForProject({{ $project['id'] }}, bulkLevel)"
                                                    class="button px-1.5 py-0.5 text-[10px] !h-auto !min-w-0"
                                                    title="Set all users to selected level"
                                                >All</button>
                                                <button
                                                    wire:click="setAllForProject({{ $project['id'] }}, 'none')"
                                                    class="button px-1.5 py-0.5 text-[10px] !h-auto !min-w-0"
                                                    isError
                                                    title="Revoke all users"
                                                >None</button>
                                            </div>
                                        </div>
                                    </th>
                                    {{-- Environment columns --}}
                                    @foreach($project['environments'] as $env)
                                        <th class="px-2 py-2 text-center border-r border-neutral-200 dark:border-coolgray-300 min-w-[150px]">
                                            <div class="flex flex-col items-center gap-1">
                                                <span class="text-xs font-medium text-neutral-600 dark:text-neutral-400 truncate max-w-[130px]" title="{{ $env['name'] }}">Env: {{ $env['name'] }}</span>
                                                <div class="flex gap-1">
                                                    <button
                                                        wire:click="setAllForEnvironment({{ $env['id'] }}, bulkLevel)"
                                                        class="button px-1.5 py-0.5 text-[10px] !h-auto !min-w-0"
                                                        title="Set all users to selected level"
                                                    >All</button>
                                                    <button
                                                        wire:click="setAllForEnvironment({{ $env['id'] }}, 'inherited')"
                                                        class="button px-1.5 py-0.5 text-[10px] !h-auto !min-w-0"
                                                        title="Reset all to inherited"
                                                    >Reset</button>
                                                </div>
                                            </div>
                                        </th>
                                    @endforeach
                                @endforeach
                            </tr>
                        </thead>

                        {{-- Data Rows --}}
                        <tbody>
                            @foreach($filteredUsers as $user)
                                <tr class="{{ $user['bypass'] ? 'opacity-50' : '' }}">
                                    {{-- User cell (sticky) --}}
                                    <td class="sticky left-0 z-10 bg-white dark:bg-coolgray-100 px-3 py-2.5 border-r border-neutral-200 dark:border-coolgray-300">
                                        <div class="flex flex-col">
                                            <span class="font-medium text-sm text-black dark:text-white truncate max-w-[180px]" title="{{ $user['name'] }}">
                                                {{ $user['name'] }}
                                            </span>
                                            <span class="text-xs text-neutral-600 dark:text-neutral-400 truncate max-w-[180px]" title="{{ $user['email'] }}">
                                                {{ $user['email'] }}
                                            </span>
                                        </div>
                                    </td>

                                    {{-- Team Role cell (sticky) --}}
                                    <td class="sticky left-[200px] z-10 bg-white dark:bg-coolgray-100 px-3 py-2.5 text-center border-r border-neutral-200 dark:border-coolgray-300">
                                        @php
                                            $roleBadge = match($user['role']) {
                                                'owner' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-400 border-amber-300 dark:border-amber-700',
                                                'admin' => 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-400 border-red-300 dark:border-red-700',
                                                'member' => 'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-400 border-blue-300 dark:border-blue-700',
                                                default => 'bg-neutral-100 text-neutral-700 dark:bg-neutral-500/20 dark:text-neutral-400 border-neutral-300 dark:border-neutral-600',
                                            };
                                        @endphp
                                        <span class="inline-flex items-center px-2 py-0.5 text-xs font-semibold rounded border {{ $roleBadge }}">
                                            {{ ucfirst($user['role']) }}
                                        </span>
                                        @if($user['bypass'])
                                            <div class="text-[10px] text-neutral-600 dark:text-neutral-400 mt-0.5 italic">bypass</div>
                                        @endif
                                    </td>

                                    {{-- Row-level actions --}}
                                    <td class="px-2 py-2.5 text-center border-r border-neutral-200 dark:border-coolgray-300">
                                        @if(! $user['bypass'])
                                            <div class="flex flex-col gap-1">
                                                <button
                                                    wire:click="setAllForUser({{ $user['id'] }}, bulkLevel)"
                                                    class="button px-1.5 py-0.5 text-[10px] !h-auto !min-w-0"
                                                    title="Grant selected level to all projects"
                                                >All</button>
                                                <button
                                                    wire:click="setAllForUser({{ $user['id'] }}, 'none')"
                                                    class="button px-1.5 py-0.5 text-[10px] !h-auto !min-w-0"
                                                    isError
                                                    title="Revoke all project access"
                                                >None</button>
                                            </div>
                                        @else
                                            <span class="text-xs text-neutral-600 dark:text-neutral-400">—</span>
                                        @endif
                                    </td>

                                    {{-- Permission cells --}}
                                    @foreach($projects as $project)
                                        {{-- Project cell --}}
                                        <td class="px-1.5 py-1.5 text-center border-r border-neutral-200 dark:border-coolgray-300">
                                            @if($user['bypass'])
<span class="text-xs text-neutral-600 dark:text-neutral-400 italic">bypass</span>
                                                @else
                                                @php
                                                    $level = $permissions[$user['id']]['p_' . $project['id']] ?? 'none';
                                                    $origLevel = $originalPermissions[$user['id']]['p_' . $project['id']] ?? 'none';
                                                    $isChanged = $level !== $origLevel;
                                                    $selectColor = match($level) {
                                                        'full_access' => 'perm-select-full',
                                                        'deploy' => 'perm-select-deploy',
                                                        'view_only' => 'perm-select-view',
                                                        default => 'perm-select-none',
                                                    };
                                                @endphp
                                                <select
                                                    wire:change="updateProjectPermission({{ $user['id'] }}, {{ $project['id'] }}, $event.target.value)"
                                                    class="perm-select {{ $selectColor }} {{ $isChanged ? 'perm-select-dirty' : '' }}"
                                                >
                                                    <option value="none" {{ $level === 'none' ? 'selected' : '' }}>No Access</option>
                                                    <option value="view_only" {{ $level === 'view_only' ? 'selected' : '' }}>View Only</option>
                                                    <option value="deploy" {{ $level === 'deploy' ? 'selected' : '' }}>Deploy</option>
                                                    <option value="full_access" {{ $level === 'full_access' ? 'selected' : '' }}>Full Access</option>
                                                </select>
                                            @endif
                                        </td>

                                        {{-- Environment cells --}}
                                        @foreach($project['environments'] as $env)
                                            <td class="px-1.5 py-1.5 text-center border-r border-neutral-200 dark:border-coolgray-300">
                                                @if($user['bypass'])
                                                    <span class="text-xs text-neutral-600 dark:text-neutral-400 italic">bypass</span>
                                                @else
                                                    @php
                                                        $envLevel = $permissions[$user['id']]['e_' . $env['id']] ?? 'inherited';
                                                        $origEnvLevel = $originalPermissions[$user['id']]['e_' . $env['id']] ?? 'inherited';
                                                        $isEnvChanged = $envLevel !== $origEnvLevel;
                                                        $projectLevel = $permissions[$user['id']]['p_' . $project['id']] ?? 'none';
                                                        $effectiveLevel = $envLevel !== 'inherited' ? $envLevel : $projectLevel;
                                                        $effectiveLabel = ucwords(str_replace('_', ' ', $effectiveLevel === 'none' ? 'No Access' : $effectiveLevel));
                                                        $envSelectColor = match($envLevel) {
                                                            'full_access' => 'perm-select-full',
                                                            'deploy' => 'perm-select-deploy',
                                                            'view_only' => 'perm-select-view',
                                                            'none' => 'perm-select-none',
                                                            default => 'perm-select-inherited',
                                                        };
                                                    @endphp
                                                    <select
                                                        wire:change="updateEnvironmentPermission({{ $user['id'] }}, {{ $env['id'] }}, $event.target.value)"
                                                        class="perm-select {{ $envSelectColor }} {{ $isEnvChanged ? 'perm-select-dirty' : '' }}"
                                                        title="{{ $envLevel === 'inherited' ? 'Inherited from project level: ' . $effectiveLabel : '' }}"
                                                    >
                                                        <option value="inherited" {{ $envLevel === 'inherited' ? 'selected' : '' }}>↳ Inherit ({{ $effectiveLabel }})</option>
                                                        <option value="none" {{ $envLevel === 'none' ? 'selected' : '' }}>No Access</option>
                                                        <option value="view_only" {{ $envLevel === 'view_only' ? 'selected' : '' }}>View Only</option>
                                                        <option value="deploy" {{ $envLevel === 'deploy' ? 'selected' : '' }}>Deploy</option>
                                                        <option value="full_access" {{ $envLevel === 'full_access' ? 'selected' : '' }}>Full Access</option>
                                                    </select>
                                                @endif
                                            </td>
                                        @endforeach
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Legend --}}
            <div class="mt-4 flex flex-wrap gap-x-5 gap-y-2 text-xs">
                <div class="flex items-center gap-1.5">
                    <span class="w-3 h-3 rounded-sm border border-green-400 dark:border-green-500 bg-green-100 dark:bg-green-900/50"></span>
                    <span class="text-neutral-600 dark:text-neutral-400">Full Access</span>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="w-3 h-3 rounded-sm border border-amber-400 dark:border-amber-500 bg-amber-100 dark:bg-amber-900/50"></span>
                    <span class="text-neutral-600 dark:text-neutral-400">Deploy</span>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="w-3 h-3 rounded-sm border border-blue-400 dark:border-blue-500 bg-blue-100 dark:bg-blue-900/50"></span>
                    <span class="text-neutral-600 dark:text-neutral-400">View Only</span>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="w-3 h-3 rounded-sm border border-neutral-300 dark:border-neutral-600 bg-neutral-100 dark:bg-neutral-800"></span>
                    <span class="text-neutral-600 dark:text-neutral-400">No Access</span>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="w-3 h-3 rounded-sm border border-purple-400 dark:border-purple-500 bg-purple-100 dark:bg-purple-900/50 border-dashed"></span>
                    <span class="text-neutral-600 dark:text-neutral-400">Inherited</span>
                </div>
            </div>
        @endif
    </div>

    {{-- Scoped styles for permission select dropdowns --}}
    <style data-navigate-once>
        /* --- Permission matrix select dropdowns --- */
        #corelix-platform-inject .perm-select {
            display: block;
            width: 100%;
            padding: 0.25rem 1.5rem 0.25rem 0.5rem;
            font-size: 0.75rem;
            line-height: 1.25rem;
            font-weight: 500;
            border-radius: 0.25rem;
            border-width: 2px;
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 0.25rem center;
            background-repeat: no-repeat;
            background-size: 1.25em 1.25em;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        #corelix-platform-inject .perm-select:focus {
            outline: none;
            box-shadow: 0 0 0 2px rgba(107, 22, 237, 0.3);
        }
        .dark #corelix-platform-inject .perm-select:focus {
            box-shadow: 0 0 0 2px rgba(252, 212, 82, 0.3);
        }

        /* Dirty (unsaved) indicator — left accent bar */
        #corelix-platform-inject .perm-select-dirty {
            box-shadow: inset 4px 0 0 #6b16ed;
        }
        .dark #corelix-platform-inject .perm-select-dirty {
            box-shadow: inset 4px 0 0 #fcd452;
        }

        /* Full Access - Green */
        #corelix-platform-inject .perm-select-full {
            background-color: #dcfce7;
            border-color: #4ade80;
            color: #166534;
        }
        .dark #corelix-platform-inject .perm-select-full {
            background-color: rgba(22, 101, 52, 0.35);
            border-color: #22c55e;
            color: #86efac;
        }

        /* Deploy - Amber */
        #corelix-platform-inject .perm-select-deploy {
            background-color: #fef3c7;
            border-color: #fbbf24;
            color: #92400e;
        }
        .dark #corelix-platform-inject .perm-select-deploy {
            background-color: rgba(146, 64, 14, 0.35);
            border-color: #f59e0b;
            color: #fcd34d;
        }

        /* View Only - Blue */
        #corelix-platform-inject .perm-select-view {
            background-color: #dbeafe;
            border-color: #60a5fa;
            color: #1e40af;
        }
        .dark #corelix-platform-inject .perm-select-view {
            background-color: rgba(30, 64, 175, 0.35);
            border-color: #3b82f6;
            color: #93c5fd;
        }

        /* No Access - Neutral */
        #corelix-platform-inject .perm-select-none {
            background-color: #f5f5f5;
            border-color: #d4d4d4;
            color: #737373;
        }
        .dark #corelix-platform-inject .perm-select-none {
            background-color: rgba(64, 64, 64, 0.3);
            border-color: #525252;
            color: #a3a3a3;
        }

        /* Inherited - Purple/Dashed */
        #corelix-platform-inject .perm-select-inherited {
            background-color: #f5f3ff;
            border-color: #c4b5fd;
            color: #6d28d9;
            border-style: dashed;
        }
        .dark #corelix-platform-inject .perm-select-inherited {
            background-color: rgba(109, 40, 217, 0.15);
            border-color: #7c3aed;
            color: #c4b5fd;
        }
    </style>
</div>
