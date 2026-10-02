<?php

namespace CorelixIo\Platform\Policies;

use App\Models\ServiceApplication;
use App\Models\User;
use CorelixIo\Platform\Services\PermissionService;

/**
 * Delegate ServiceApplication authorization to the parent Service.
 */
class ServiceApplicationPolicy
{
    public function view(User $user, ServiceApplication $serviceApplication): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $this->viaService($user, $serviceApplication, 'view');
    }

    public function create(User $user): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::canCreateInCurrentContext($user);
    }

    public function update(User $user, ServiceApplication $serviceApplication): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $this->viaService($user, $serviceApplication, 'update');
    }

    public function delete(User $user, ServiceApplication $serviceApplication): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $this->viaService($user, $serviceApplication, 'delete');
    }

    public function restore(User $user, ServiceApplication $serviceApplication): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $this->viaService($user, $serviceApplication, 'update');
    }

    public function forceDelete(User $user, ServiceApplication $serviceApplication): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $this->viaService($user, $serviceApplication, 'delete');
    }

    protected function viaService(User $user, ServiceApplication $serviceApplication, string $action): bool
    {
        $service = $serviceApplication->service;
        if (! $service) {
            return false;
        }

        return PermissionService::canPerform($user, $action, $service);
    }
}
