<?php

namespace CorelixIo\Platform\Policies;

use CorelixIo\Platform\Services\PermissionService;
use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    /**
     * Determine whether the user can view any projects.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the project.
     */
    public function view(User $user, Project $project): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'view', $project);
    }

    /**
     * Determine whether the user can create projects.
     *
     * Project creation is a team-level operation. When granular permissions
     * are enabled, only users with role bypass (owner/admin) can create
     * new projects.
     */
    public function create(User $user): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::hasRoleBypass($user);
    }

    /**
     * Determine whether the user can update the project.
     */
    public function update(User $user, Project $project): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'update', $project);
    }

    /**
     * Determine whether the user can delete the project.
     */
    public function delete(User $user, Project $project): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'delete', $project);
    }

    public function restore(User $user, Project $project): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'update', $project);
    }

    public function forceDelete(User $user, Project $project): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'delete', $project);
    }

    /**
     * Determine whether the user can manage access to the project.
     */
    public function manageAccess(User $user, Project $project): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'update', $project);
    }
}
