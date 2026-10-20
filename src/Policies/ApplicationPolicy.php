<?php

namespace CorelixIo\Platform\Policies;

use CorelixIo\Platform\Services\PermissionService;
use App\Models\Application;
use App\Models\User;

class ApplicationPolicy
{
    /**
     * Determine whether the user can view any applications.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the application.
     */
    public function view(User $user, Application $application): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'view', $application);
    }

    /**
     * Determine whether the user can create applications.
     *
     * Requires 'manage' permission on the current project context.
     * Mirrors Coolify's PrivateKeyPolicy pattern where create() checks isAdmin().
     */
    public function create(User $user): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canCreateInCurrentContext($user);
    }

    /**
     * Determine whether the user can update the application.
     */
    public function update(User $user, Application $application): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'update', $application);
    }

    /**
     * Determine whether the user can delete the application.
     */
    public function delete(User $user, Application $application): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'delete', $application);
    }

    public function restore(User $user, Application $application): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'update', $application);
    }

    public function forceDelete(User $user, Application $application): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'delete', $application);
    }

    /**
     * Determine whether the user can deploy the application.
     */
    public function deploy(User $user, Application $application): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'deploy', $application);
    }

    /**
     * Determine whether the user can manage deployments (view logs, cancel).
     */
    public function manageDeployments(User $user, Application $application): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'deploy', $application);
    }

    /**
     * Determine whether the user can cleanup the deployment queue.
     */
    public function cleanupDeploymentQueue(User $user): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::hasRoleBypass($user);
    }

    /**
     * Determine whether the user can manage environment variables and settings.
     *
     * Coolify's Blade templates use @can('manageEnvironment', $resource) to gate
     * environment variable management, settings checkboxes, and developer views.
     */
    public function manageEnvironment(User $user, Application $application): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'manage', $application);
    }
}
