<?php

namespace CorelixIo\Platform\Policies;

use App\Models\Environment;
use App\Models\Project;
use App\Models\SharedEnvironmentVariable;
use App\Models\User;
use CorelixIo\Platform\Services\PermissionService;

/**
 * Override Coolify's permissive SharedEnvironmentVariablePolicy.
 *
 * Team/server scoped variables require owner/admin. Project/environment scoped
 * variables inherit granular permissions from their scope.
 */
class SharedEnvironmentVariablePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SharedEnvironmentVariable $sharedEnvironmentVariable): bool
    {
        if (! PermissionService::isEnabled()) {
            return $user->teams->contains('id', $sharedEnvironmentVariable->team_id);
        }

        if (PermissionService::hasRoleBypass($user)) {
            return true;
        }

        return $user->teams->contains('id', $sharedEnvironmentVariable->team_id)
            && $this->hasScopedPermission($user, $sharedEnvironmentVariable, 'view');
    }

    public function create(User $user): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::hasRoleBypass($user)
            || PermissionService::canCreateInCurrentContext($user);
    }

    public function update(User $user, SharedEnvironmentVariable $sharedEnvironmentVariable): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $this->checkMutation($user, $sharedEnvironmentVariable, 'manage');
    }

    public function delete(User $user, SharedEnvironmentVariable $sharedEnvironmentVariable): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $this->checkMutation($user, $sharedEnvironmentVariable, 'manage');
    }

    public function restore(User $user, SharedEnvironmentVariable $sharedEnvironmentVariable): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $this->checkMutation($user, $sharedEnvironmentVariable, 'manage');
    }

    public function forceDelete(User $user, SharedEnvironmentVariable $sharedEnvironmentVariable): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $this->checkMutation($user, $sharedEnvironmentVariable, 'delete');
    }

    public function manageEnvironment(User $user, SharedEnvironmentVariable $sharedEnvironmentVariable): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $this->checkMutation($user, $sharedEnvironmentVariable, 'manage');
    }

    protected function checkMutation(User $user, SharedEnvironmentVariable $variable, string $permission): bool
    {
        if (PermissionService::hasRoleBypass($user)) {
            return true;
        }

        if (! $user->teams->contains('id', $variable->team_id)) {
            return false;
        }

        return $this->hasScopedPermission($user, $variable, $permission);
    }

    protected function hasScopedPermission(User $user, SharedEnvironmentVariable $variable, string $permission): bool
    {
        return match ($variable->type) {
            'environment' => ($environment = Environment::withoutGlobalScopes()->find($variable->environment_id))
                && PermissionService::hasEnvironmentPermission($user, $environment, $permission),
            'project' => ($project = Project::withoutGlobalScopes()->find($variable->project_id))
                && PermissionService::hasProjectPermission($user, $project, $permission),
            'team', 'server' => PermissionService::hasRoleBypass($user),
            default => false,
        };
    }
}
