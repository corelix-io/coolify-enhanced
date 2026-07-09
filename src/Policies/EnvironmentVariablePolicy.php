<?php

namespace CorelixIo\Platform\Policies;

use CorelixIo\Platform\Services\PermissionService;
use App\Models\EnvironmentVariable;
use App\Models\User;

/**
 * Override Coolify's EnvironmentVariablePolicy to enforce granular permissions.
 *
 * Coolify's default policy returns true for all operations. This policy
 * traverses the polymorphic parent (Application/Service) to resolve the
 * environment and check the user's permission level.
 */
class EnvironmentVariablePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, EnvironmentVariable $environmentVariable): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $this->checkViaParent($user, $environmentVariable, 'view');
    }

    public function create(User $user): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canCreateInCurrentContext($user);
    }

    public function update(User $user, EnvironmentVariable $environmentVariable): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $this->checkViaParent($user, $environmentVariable, 'manage');
    }

    public function delete(User $user, EnvironmentVariable $environmentVariable): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $this->checkViaParent($user, $environmentVariable, 'manage');
    }

    public function restore(User $user, EnvironmentVariable $environmentVariable): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $this->checkViaParent($user, $environmentVariable, 'manage');
    }

    public function forceDelete(User $user, EnvironmentVariable $environmentVariable): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $this->checkViaParent($user, $environmentVariable, 'delete');
    }

    /**
     * Coolify's Blade templates use @can('manageEnvironment', $resource) to gate
     * environment variable management UI elements.
     */
    public function manageEnvironment(User $user, EnvironmentVariable $environmentVariable): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $this->checkViaParent($user, $environmentVariable, 'manage');
    }

    /**
     * Resolve the parent resource (Application/Service/Database) via the
     * polymorphic resourceable relationship, then check permissions.
     */
    protected function checkViaParent(User $user, EnvironmentVariable $environmentVariable, string $permission): bool
    {
        $parent = $environmentVariable->resourceable;

        return PermissionService::checkResourceablePermission($user, $parent, $permission);
    }
}
