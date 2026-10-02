<?php

namespace CorelixIo\Platform\Policies;

use CorelixIo\Platform\Models\Domain;
use CorelixIo\Platform\Services\PermissionService;
use App\Models\User;

/**
 * Team-scoped authorization for managed domains (mirrors DnsProviderPolicy):
 * all team members may VIEW status; only owner/admin may mutate or trigger reconcile.
 */
class DomainPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Domain $domain): bool
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

    public function update(User $user, Domain $domain): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::hasRoleBypass($user);
    }

    public function delete(User $user, Domain $domain): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::hasRoleBypass($user);
    }

    public function sync(User $user, Domain $domain): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::hasRoleBypass($user);
    }
}
