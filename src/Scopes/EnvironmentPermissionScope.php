<?php

namespace CorelixIo\Platform\Scopes;

use CorelixIo\Platform\Models\EnvironmentUser;
use CorelixIo\Platform\Services\PermissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class EnvironmentPermissionScope implements Scope
{
    /**
     * Apply the scope to the given Eloquent query builder.
     *
     * Filters out environments where the authenticated user has an explicit
     * "none" override (view permission = false). Skipped for guests and
     * users with role bypass (owner/admin).
     */
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();

        if (! $user) {
            return;
        }

        if (! PermissionService::isEnabled()) {
            return;
        }

        if (PermissionService::hasRoleBypass($user)) {
            return;
        }

        $blockedIds = EnvironmentUser::where('user_id', $user->id)
            ->where('permissions->view', false)
            ->pluck('environment_id')
            ->all();

        if (! empty($blockedIds)) {
            $builder->whereNotIn($model->getTable().'.id', $blockedIds);
        }
    }
}
