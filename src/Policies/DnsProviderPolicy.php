<?php

namespace CorelixIo\Platform\Policies;

use CorelixIo\Platform\Models\DnsProvider;
use CorelixIo\Platform\Services\PermissionService;
use App\Models\User;

/**
 * Team-scoped authorization for DNS providers (mirrors RegistryPolicy):
 * all team members may VIEW (credentials masked); only owner/admin may mutate or sync.
 */
class DnsProviderPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, DnsProvider $provider): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::hasRoleBypass($user);
    }

    public function update(User $user, DnsProvider $provider): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::hasRoleBypass($user);
    }

    public function delete(User $user, DnsProvider $provider): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::hasRoleBypass($user);
    }

    public function sync(User $user, DnsProvider $provider): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::hasRoleBypass($user);
    }
}
