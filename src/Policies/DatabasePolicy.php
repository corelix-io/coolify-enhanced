<?php

namespace CorelixIo\Platform\Policies;

use CorelixIo\Platform\Services\PermissionService;
use App\Models\User;

/**
 * Generic policy for all database types.
 * Works with: StandalonePostgresql, StandaloneMysql, StandaloneMariadb,
 * StandaloneMongodb, StandaloneRedis, StandaloneKeydb, StandaloneDragonfly, StandaloneClickhouse
 */
class DatabasePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, $database): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'view', $database);
    }

    /**
     * Determine whether the user can create databases.
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

    public function update(User $user, $database): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'update', $database);
    }

    public function delete(User $user, $database): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'delete', $database);
    }

    public function restore(User $user, $database): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'update', $database);
    }

    public function forceDelete(User $user, $database): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'delete', $database);
    }

    public function deploy(User $user, $database): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'deploy', $database);
    }

    /**
     * Determine whether the user can start/stop the database.
     */
    public function manage(User $user, $database): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'update', $database);
    }

    /**
     * Determine whether the user can manage database backups.
     *
     * Used by BackupEdit, BackupExecution Livewire components.
     */
    public function manageBackups(User $user, $database): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'update', $database);
    }

    /**
     * Determine whether the user can manage environment variables and settings.
     *
     * Coolify's Blade templates use @can('manageEnvironment', $resource) to gate
     * environment variable management, settings checkboxes, and developer views.
     */
    public function manageEnvironment(User $user, $database): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canPerform($user, 'manage', $database);
    }
}
