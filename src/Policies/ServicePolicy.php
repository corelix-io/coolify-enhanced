<?php

namespace CorelixIo\Platform\Policies;

use CorelixIo\Platform\Services\PermissionService;
use App\Models\Service;
use App\Models\User;

class ServicePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Service $service): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'view', $service);
    }

    /**
     * Determine whether the user can create services.
     *
     * Requires 'manage' permission on the current project context.
     */
    public function create(User $user): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canCreateInCurrentContext($user);
    }

    public function update(User $user, Service $service): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'update', $service);
    }

    public function delete(User $user, Service $service): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'delete', $service);
    }

    public function restore(User $user, Service $service): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'update', $service);
    }

    public function forceDelete(User $user, Service $service): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'delete', $service);
    }

    /**
     * Determine whether the user can stop the service.
     */
    public function stop(User $user, Service $service): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'deploy', $service);
    }

    /**
     * Determine whether the user can access the terminal.
     */
    public function accessTerminal(User $user, Service $service): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'manage', $service);
    }

    public function deploy(User $user, Service $service): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'deploy', $service);
    }

    /**
     * Determine whether the user can manage environment variables and settings.
     *
     * Coolify's Blade templates use @can('manageEnvironment', $resource) to gate
     * environment variable management, settings checkboxes, and developer views.
     */
    public function manageEnvironment(User $user, Service $service): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'manage', $service);
    }
}
