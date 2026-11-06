<?php

namespace CorelixIo\Platform\Livewire;

use CorelixIo\Platform\Models\EnvironmentUser;
use CorelixIo\Platform\Models\ProjectUser;
use CorelixIo\Platform\Services\PermissionService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class AccessMatrix extends Component
{
    public array $users = [];
    public array $projects = [];
    public array $permissions = [];
    public array $originalPermissions = [];
    public bool $hasPendingChanges = false;
    public string $search = '';
    public string $bulkLevel = 'full_access';
    public string $saveMessage = '';
    public string $saveStatus = ''; // 'success' or 'error'

    protected $listeners = ['refreshAccessMatrix' => 'loadMatrix'];

    public function mount(): void
    {
        $this->authorizeAdmin();
        $this->loadMatrix();
    }

    public function loadMatrix(): void
    {
        $this->authorizeAdmin();

        $team = auth()->user()->currentTeam();
        if (! $team) {
            return;
        }

        $bypassRoles = config('corelix-platform.bypass_roles', ['owner', 'admin']);

        // Load team members via relationship (includes pivot with role)
        $this->users = $team->members->map(function ($user) use ($bypassRoles) {
            $role = $user->pivot->role ?? 'member';

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $role,
                'bypass' => in_array($role, $bypassRoles),
            ];
        })->sortBy('name')->values()->toArray();

        // Load projects with environments (admin bypasses global scopes)
        $this->projects = $team->projects()->with('environments')->get()->map(function ($project) {
            return [
                'id' => $project->id,
                'uuid' => $project->uuid,
                'name' => $project->name,
                'environments' => $project->environments->map(function ($env) {
                    return [
                        'id' => $env->id,
                        'name' => $env->name,
                    ];
                })->sortBy('name')->values()->toArray(),
            ];
        })->sortBy('name')->values()->toArray();

        // Load all project permissions in bulk
        $projectIds = collect($this->projects)->pluck('id')->toArray();
        $projectPerms = collect();
        if (! empty($projectIds)) {
            $projectPerms = DB::table('project_user')
                ->whereIn('project_id', $projectIds)
                ->get()
                ->groupBy('user_id');
        }

        // Load all environment permissions in bulk
        $envIds = collect($this->projects)->flatMap(function ($p) {
            return collect($p['environments'])->pluck('id');
        })->toArray();
        $envPerms = collect();
        if (! empty($envIds)) {
            $envPerms = DB::table('environment_user')
                ->whereIn('environment_id', $envIds)
                ->get()
                ->groupBy('user_id');
        }

        // Build permissions matrix
        $this->permissions = [];
        foreach ($this->users as $user) {
            $userId = $user['id'];
            $this->permissions[$userId] = [];

            foreach ($this->projects as $project) {
                $projectId = $project['id'];
                $perm = $projectPerms->get($userId)?->firstWhere('project_id', $projectId);
                $this->permissions[$userId]["p_{$projectId}"] = $perm
                    ? $this->resolveLevel(json_decode($perm->permissions, true))
                    : 'none';

                foreach ($project['environments'] as $env) {
                    $envId = $env['id'];
                    $ePerm = $envPerms->get($userId)?->firstWhere('environment_id', $envId);
                    // null means inherited from project
                    $this->permissions[$userId]["e_{$envId}"] = $ePerm
                        ? $this->resolveLevel(json_decode($ePerm->permissions, true))
                        : 'inherited';
                }
            }
        }

        $this->originalPermissions = $this->permissions;
        $this->hasPendingChanges = false;
        $this->saveMessage = '';
        $this->saveStatus = '';
    }

    /**
     * Resolve a permission level string from a permissions array.
     */
    protected function resolveLevel(array $perms): string
    {
        $view = $perms['view'] ?? false;
        $deploy = $perms['deploy'] ?? false;
        $manage = $perms['manage'] ?? false;
        $delete = $perms['delete'] ?? false;

        if ($view && $deploy && $manage && $delete) {
            return 'full_access';
        }
        if ($view && $deploy) {
            return 'deploy';
        }
        if ($view) {
            return 'view_only';
        }

        return 'none';
    }

    /**
     * Update a project-level permission locally (no DB write until save).
     */
    public function updateProjectPermission(int $userId, int $projectId, string $level): void
    {
        $this->authorizeAdmin();
        $this->validatePermissionLevel($level);
        $this->permissions[$userId]["p_{$projectId}"] = $level;
        $this->checkForChanges();
    }

    /**
     * Update an environment-level permission locally (no DB write until save).
     *
     * "inherited" = cascade from project level.
     * "none" = explicit block (user can't see the environment).
     */
    public function updateEnvironmentPermission(int $userId, int $envId, string $level): void
    {
        $this->authorizeAdmin();
        $this->validatePermissionLevel($level, ['inherited', 'none', 'view_only', 'deploy', 'full_access']);
        $this->permissions[$userId]["e_{$envId}"] = $level;
        $this->checkForChanges();
    }

    /**
     * Set all project+environment permissions for a single user (local only).
     */
    public function setAllForUser(int $userId, string $level): void
    {
        $this->authorizeAdmin();
        $this->validatePermissionLevel($level);

        foreach ($this->projects as $project) {
            $this->permissions[$userId]["p_{$project['id']}"] = $level;
            // Reset all environment overrides to inherited
            foreach ($project['environments'] as $env) {
                $this->permissions[$userId]["e_{$env['id']}"] = 'inherited';
            }
        }

        $this->checkForChanges();
    }

    /**
     * Set a permission level for all users on a specific project (local only).
     */
    public function setAllForProject(int $projectId, string $level): void
    {
        $this->authorizeAdmin();
        $this->validatePermissionLevel($level);

        foreach ($this->users as $user) {
            if ($user['bypass']) {
                continue;
            }
            $this->permissions[$user['id']]["p_{$projectId}"] = $level;
        }

        $this->checkForChanges();
    }

    /**
     * Set a permission level for all users on a specific environment (local only).
     */
    public function setAllForEnvironment(int $envId, string $level): void
    {
        $this->authorizeAdmin();
        $this->validatePermissionLevel($level, ['inherited', 'none', 'view_only', 'deploy', 'full_access']);

        foreach ($this->users as $user) {
            if ($user['bypass']) {
                continue;
            }
            $this->permissions[$user['id']]["e_{$envId}"] = $level;
        }

        $this->checkForChanges();
    }

    /**
     * Persist all pending permission changes to the database.
     */
    public function saveChanges(): void
    {
        $this->authorizeAdmin();
        $this->saveMessage = '';
        $this->saveStatus = '';

        $changeCount = 0;

        try {
            DB::beginTransaction();

            foreach ($this->users as $user) {
                if ($user['bypass']) {
                    continue;
                }

                $userId = $user['id'];
                $userModel = null;

                foreach ($this->projects as $project) {
                    $pKey = "p_{$project['id']}";
                    $newLevel = $this->permissions[$userId][$pKey] ?? 'none';
                    $oldLevel = $this->originalPermissions[$userId][$pKey] ?? 'none';

                    if ($newLevel !== $oldLevel) {
                        $userModel = $userModel ?? User::find($userId);
                        $proj = \App\Models\Project::withoutGlobalScopes()->find($project['id']);
                        if ($userModel && $proj) {
                            if ($newLevel === 'none') {
                                PermissionService::revokeProjectAccess($userModel, $proj);
                            } else {
                                PermissionService::grantProjectAccess($userModel, $proj, $newLevel);
                            }
                            $changeCount++;
                        }
                    }

                    foreach ($project['environments'] as $env) {
                        $eKey = "e_{$env['id']}";
                        $newEnvLevel = $this->permissions[$userId][$eKey] ?? 'inherited';
                        $oldEnvLevel = $this->originalPermissions[$userId][$eKey] ?? 'inherited';

                        if ($newEnvLevel !== $oldEnvLevel) {
                            $userModel = $userModel ?? User::find($userId);
                            $environment = \App\Models\Environment::withoutGlobalScopes()->find($env['id']);
                            if ($userModel && $environment) {
                                if ($newEnvLevel === 'inherited') {
                                    PermissionService::revokeEnvironmentAccess($userModel, $environment);
                                } else {
                                    PermissionService::grantEnvironmentAccess($userModel, $environment, $newEnvLevel);
                                }
                                $changeCount++;
                            }
                        }
                    }
                }
            }

            DB::commit();

            $this->originalPermissions = $this->permissions;
            $this->hasPendingChanges = false;
            $this->saveMessage = "Saved {$changeCount} permission change(s) successfully.";
            $this->saveStatus = 'success';
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            $this->saveMessage = 'Failed to save permissions: '.$e->getMessage();
            $this->saveStatus = 'error';
        }
    }

    /**
     * Discard all pending changes and revert to last saved state.
     */
    public function discardChanges(): void
    {
        $this->permissions = $this->originalPermissions;
        $this->hasPendingChanges = false;
        $this->saveMessage = '';
        $this->saveStatus = '';
    }

    /**
     * Compare current permissions with original to detect pending changes.
     */
    protected function checkForChanges(): void
    {
        $this->hasPendingChanges = $this->permissions !== $this->originalPermissions;
        // Clear any previous save message when user makes new changes
        if ($this->hasPendingChanges) {
            $this->saveMessage = '';
            $this->saveStatus = '';
        }
    }

    /**
     * Get filtered users based on search.
     */
    public function getFilteredUsersProperty(): array
    {
        if (empty($this->search)) {
            return $this->users;
        }

        $search = strtolower($this->search);

        return array_values(array_filter($this->users, function ($user) use ($search) {
            return str_contains(strtolower($user['name']), $search)
                || str_contains(strtolower($user['email']), $search)
                || str_contains(strtolower($user['role']), $search);
        }));
    }

    /**
     * Validate that a permission level is one of the allowed values.
     *
     * @param string $level The permission level to validate.
     * @param array $allowed Allowed values (defaults to project-level values).
     */
    protected function validatePermissionLevel(string $level, array $allowed = ['none', 'view_only', 'deploy', 'full_access']): void
    {
        if (! in_array($level, $allowed, true)) {
            throw ValidationException::withMessages([
                'level' => "Invalid permission level '{$level}'. Allowed: ".implode(', ', $allowed),
            ]);
        }
    }

    /**
     * Verify the current user can manage permissions.
     */
    protected function authorizeAdmin(): void
    {
        $currentUser = auth()->user();
        if (! PermissionService::hasRoleBypass($currentUser)) {
            abort(403, 'Only team owners and admins can manage permissions.');
        }
    }

    public function render()
    {
        return view('corelix-platform::livewire.access-matrix', [
            'filteredUsers' => $this->filteredUsers,
        ]);
    }
}
