<?php

namespace CorelixIo\Platform\Policies;

use App\Models\ServiceDatabase;
use App\Models\User;
use CorelixIo\Platform\Services\PermissionService;

/**
 * Delegate ServiceDatabase authorization to the parent Service.
 */
class ServiceDatabasePolicy
{
    public function view(User $user, ServiceDatabase $serviceDatabase): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $this->viaService($user, $serviceDatabase, 'view');
    }

    public function create(User $user): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canCreateInCurrentContext($user);
    }

    public function update(User $user, ServiceDatabase $serviceDatabase): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $this->viaService($user, $serviceDatabase, 'update');
    }

    public function delete(User $user, ServiceDatabase $serviceDatabase): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $this->viaService($user, $serviceDatabase, 'delete');
    }

    public function restore(User $user, ServiceDatabase $serviceDatabase): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $this->viaService($user, $serviceDatabase, 'update');
    }

    public function forceDelete(User $user, ServiceDatabase $serviceDatabase): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $this->viaService($user, $serviceDatabase, 'delete');
    }

    public function manageBackups(User $user, ServiceDatabase $serviceDatabase): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $this->viaService($user, $serviceDatabase, 'manage');
    }

    protected function viaService(User $user, ServiceDatabase $serviceDatabase, string $action): bool
    {
        $service = $serviceDatabase->service;
        if (! $service) {
            return false;
        }

        return PermissionService::canPerform($user, $action, $service);
    }
}
