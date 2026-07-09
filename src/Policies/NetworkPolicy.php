<?php

namespace CorelixIo\Platform\Policies;

use CorelixIo\Platform\Models\ManagedNetwork;
use CorelixIo\Platform\Services\PermissionService;
use App\Models\User;

class NetworkPolicy
{
    /**
     * Determine whether the user can view any networks.
     *
     * All authenticated team members can see the network list.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the network.
     *
     * For environment-scoped networks, check project/env permissions.
     * For shared/proxy/system networks, admin/owner only.
     */
    public function view(User $user, ManagedNetwork $network): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        // Admin/Owner bypass
        if (PermissionService::hasRoleBypass($user)) {
            return true;
        }

        // For environment-scoped networks, check if user has view access
        if ($network->environment_id && $network->environment) {
            return PermissionService::canPerform($user, 'view', $network->environment);
        }

        // Shared/proxy/system networks: admin/owner only
        return false;
    }

    /**
     * Determine whether the user can create shared networks.
     *
     * Admin/Owner only.
     */
    public function create(User $user): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::hasRoleBypass($user);
    }

    /**
     * Determine whether the user can update the network.
     *
     * Admin/Owner only.
     */
    public function update(User $user, ManagedNetwork $network): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::hasRoleBypass($user);
    }

    /**
     * Determine whether the user can delete the network.
     *
     * Admin/Owner only.
     */
    public function delete(User $user, ManagedNetwork $network): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::hasRoleBypass($user);
    }

    /**
     * Determine whether the user can connect a resource to this network.
     *
     * Prefer authorizing update on the target resource (API/Livewire attach paths).
     * Environment-scoped networks require manage on that environment; shared/proxy
     * networks require admin/owner.
     */
    public function connect(User $user, ManagedNetwork $network): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        if (PermissionService::hasRoleBypass($user)) {
            return true;
        }

        if ($network->environment_id && $network->environment) {
            return PermissionService::canPerform($user, 'manage', $network->environment);
        }

        return false;
    }

    /**
     * Determine whether the user can disconnect a resource from this network.
     *
     * Prefer authorizing update on the target resource (API/Livewire detach paths).
     */
    public function disconnect(User $user, ManagedNetwork $network): bool
    {
        return $this->connect($user, $network);
    }
}
