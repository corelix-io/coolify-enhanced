<?php

namespace CorelixIo\Platform\Scopes;

use CorelixIo\Platform\Models\ProjectUser;
use CorelixIo\Platform\Services\PermissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class ProjectPermissionScope implements Scope
{
    /**
     * Apply the scope to the given Eloquent query builder.
     *
     * Filters projects to only those where the authenticated user has been
     * explicitly granted view access. Skipped for guests and users with
     * role bypass (owner/admin).
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

        $allowedIds = ProjectUser::where('user_id', $user->id)
            ->where('permissions->view', true)
            ->pluck('project_id')
            ->all();

        if (empty($allowedIds)) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->whereIn($model->getTable().'.id', $allowedIds);
    }
}
