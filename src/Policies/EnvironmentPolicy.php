<?php

namespace CorelixIo\Platform\Policies;

use CorelixIo\Platform\Services\PermissionService;
use App\Models\Environment;
use App\Models\User;

class EnvironmentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Environment $environment): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'view', $environment);
    }

    /**
     * Determine whether the user can create environments.
     *
     * Requires 'manage' permission on the parent project.
     */
    public function create(User $user): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canCreateInCurrentContext($user);
    }

    public function update(User $user, Environment $environment): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'update', $environment);
    }

    public function delete(User $user, Environment $environment): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'delete', $environment);
    }

    public function restore(User $user, Environment $environment): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'update', $environment);
    }

    public function forceDelete(User $user, Environment $environment): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'delete', $environment);
    }
}
