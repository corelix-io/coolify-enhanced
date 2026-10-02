<?php

namespace CorelixIo\Platform\Services;

use CorelixIo\Platform\Models\EnvironmentUser;
use CorelixIo\Platform\Models\ProjectUser;
use App\Models\User;

class PermissionService
{
    /**
     * Cached role bypass results keyed by user ID.
     * Prevents N+1 queries when multiple permission checks occur in a single request.
     */
    protected static array $roleBypassCache = [];

    /**
     * Action to permission mapping.
     */
    protected static array $actionMap = [
        // View actions
        'view' => 'view',
        'viewAny' => 'view',

        // Deploy actions
        'deploy' => 'deploy',
        'start' => 'deploy',
        'stop' => 'deploy',
        'restart' => 'deploy',

        // Manage actions
        'update' => 'manage',
        'create' => 'manage',
        'manage' => 'manage',

        // Delete actions
        'delete' => 'delete',
        'forceDelete' => 'delete',
        'restore' => 'delete',
    ];

    /**
     * Check if granular permissions are enabled.
     */
    public static function isEnabled(): bool
    {
        return config('corelix-platform.enabled', false);
    }

    /**
     * Check if user has a specific permission on a project.
     */
    public static function hasProjectPermission(User $user, $project, string $permission): bool
    {
        // Bypass for privileged roles
        if (static::hasRoleBypass($user)) {
            return true;
        }

        $projectAccess = ProjectUser::where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $projectAccess) {
            return false;
        }

        return $projectAccess->hasPermission($permission);
    }

    /**
     * Check if user has a specific permission on an environment.
     */
    public static function hasEnvironmentPermission(User $user, $environment, string $permission): bool
    {
        // Bypass for privileged roles
        if (static::hasRoleBypass($user)) {
            return true;
        }

        // Check environment-level override first
        $envAccess = EnvironmentUser::where('environment_id', $environment->id)
            ->where('user_id', $user->id)
            ->first();

        if ($envAccess) {
            return $envAccess->hasPermission($permission);
        }

        // Fall back to project-level permission if cascade is enabled
        if (config('corelix-platform.cascade_permissions', true)) {
            return static::hasProjectPermission($user, $environment->project, $permission);
        }

        return false;
    }

    /**
     * Check if user can perform an action on a resource.
     */
    public static function canPerform(User $user, string $action, $resource): bool
    {
        $permission = static::mapActionToPermission($action);

        // Determine resource type and check permission
        return match (true) {
            $resource instanceof \App\Models\Application => static::checkApplicationPermission($user, $resource, $permission),
            $resource instanceof \App\Models\Service => static::checkServicePermission($user, $resource, $permission),
            $resource instanceof \App\Models\Project => static::hasProjectPermission($user, $resource, $permission),
            $resource instanceof \App\Models\Environment => static::hasEnvironmentPermission($user, $resource, $permission),
            static::isDatabase($resource) => static::checkDatabasePermission($user, $resource, $permission),
            default => static::hasRoleBypass($user),
        };
    }

    /**
     * Check permission for an application.
     */
    protected static function checkApplicationPermission(User $user, $application, string $permission): bool
    {
        $environment = $application->environment;
        if (! $environment) {
            return static::hasRoleBypass($user);
        }

        return static::hasEnvironmentPermission($user, $environment, $permission);
    }

    /**
     * Check permission for a service.
     */
    protected static function checkServicePermission(User $user, $service, string $permission): bool
    {
        $environment = $service->environment;
        if (! $environment) {
            return static::hasRoleBypass($user);
        }

        return static::hasEnvironmentPermission($user, $environment, $permission);
    }

    /**
     * Check permission for a database.
     */
    protected static function checkDatabasePermission(User $user, $database, string $permission): bool
    {
        $environment = $database->environment;
        if (! $environment) {
            return static::hasRoleBypass($user);
        }

        return static::hasEnvironmentPermission($user, $environment, $permission);
    }


    /**
     * Check if resource is a database model.
     */
    protected static function isDatabase($resource): bool
    {
        $databaseModels = [
            \App\Models\StandalonePostgresql::class,
            \App\Models\StandaloneMysql::class,
            \App\Models\StandaloneMariadb::class,
            \App\Models\StandaloneMongodb::class,
            \App\Models\StandaloneRedis::class,
            \App\Models\StandaloneKeydb::class,
            \App\Models\StandaloneDragonfly::class,
            \App\Models\StandaloneClickhouse::class,
        ];

        foreach ($databaseModels as $model) {
            if ($resource instanceof $model) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user has a role that bypasses permission checks.
     *
     * Results are cached per user ID + team ID to avoid N+1 queries
     * when multiple permission checks occur in a single request.
     */
    public static function hasRoleBypass(mixed $user): bool
    {
        if (! is_object($user)) {
            return false;
        }

        $teamId = static::resolveActiveTeamId();
        $cacheKey = ($user->id ?? spl_object_id($user)).':'.($teamId ?? 'null');

        if (array_key_exists($cacheKey, static::$roleBypassCache)) {
            return static::$roleBypassCache[$cacheKey];
        }

        $bypassRoles = config('corelix-platform.bypass_roles', ['owner', 'admin']);
        $userRole = $user->teams()->where('teams.id', $teamId)->first()?->pivot?->role;

        return static::$roleBypassCache[$cacheKey] = in_array($userRole, $bypassRoles);
    }

    /**
     * Flush the static role bypass cache.
     *
     * Must be called between tests to prevent state leakage.
     */
    public static function flushCache(): void
    {
        static::$roleBypassCache = [];
    }

    /**
     * Resolve the active team ID for the current request (API token or session).
     */
    public static function resolveActiveTeamId(): ?int
    {
        $teamId = function_exists('getTeamIdFromToken') ? getTeamIdFromToken() : null;

        return $teamId ?? currentTeam()?->id;
    }

    /**
     * Resolve the authenticated user's role on the current team.
     *
     * Uses roleInTeam() when available — never currentTeam()->pivot, which is
     * not populated by Coolify's cached currentTeam() helper.
     */
    public static function roleOnCurrentTeam(User $user): ?string
    {
        $teamId = static::resolveActiveTeamId();

        // NOTE: strict null check — Coolify's root team has id 0, and the instance
        // owner sits on it. `! $teamId` would treat team 0 as "no team resolved"
        // and wrongly deny every root-team admin/owner.
        if ($teamId === null) {
            return null;
        }

        if (method_exists($user, 'roleInTeam')) {
            return $user->roleInTeam($teamId);
        }

        return $user->teams()->where('teams.id', $teamId)->first()?->pivot?->role;
    }

    /**
     * Whether the user is owner or admin on the current (or token) team.
     */
    public static function isTeamAdmin(mixed $user, ?int $teamId = null): bool
    {
        $teamId ??= static::resolveActiveTeamId();

        // NOTE: strict null check — Coolify's root team has id 0, and the instance
        // owner sits on it. `! $teamId` would treat team 0 as "no team resolved"
        // and 403 every root-team admin/owner (the exact custom-templates bug).
        if ($teamId === null) {
            return false;
        }

        if (method_exists($user, 'isAdminOfTeam')) {
            return $user->isAdminOfTeam($teamId);
        }

        $role = method_exists($user, 'roleInTeam')
            ? $user->roleInTeam($teamId)
            : $user->teams()->where('teams.id', $teamId)->first()?->pivot?->role;

        return in_array($role, config('corelix-platform.bypass_roles', ['owner', 'admin']), true);
    }

    /**
     * Abort with 403 unless the user is owner or admin on the current team.
     */
    public static function requireTeamAdmin(mixed $user): void
    {
        if (! is_object($user) || ! static::isTeamAdmin($user)) {
            abort(403, 'Unauthorized. Admin or owner role required.');
        }
    }

    /**
     * Map an action to its corresponding permission.
     */
    protected static function mapActionToPermission(string $action): string
    {
        return static::$actionMap[$action] ?? 'view';
    }

    /**
     * Resolve the current project from the request route/URL.
     *
     * Coolify URLs follow the pattern /project/{project_uuid}/...
     * This allows create() policy methods (which don't receive a model instance)
     * to determine the project context.
     */
    public static function resolveProjectFromRequest(): ?\App\Models\Project
    {
        $request = request();

        // Try route parameter first
        $projectUuid = $request->route('project_uuid');

        // Fallback: extract from URL path
        if (! $projectUuid) {
            $path = $request->path();
            if (preg_match('#^project/([^/]+)#', $path, $matches)) {
                $projectUuid = $matches[1];
            }
        }

        if ($projectUuid) {
            return \App\Models\Project::withoutGlobalScopes()
                ->where('uuid', $projectUuid)
                ->first();
        }

        return null;
    }

    /**
     * Resolve the current environment from the request route/URL.
     *
     * Coolify v4.1+ URLs use /project/{project_uuid}/environment/{environment_uuid}/...
     * Legacy paths may still use /project/{project_uuid}/{environment_name}/...
     */
    public static function resolveEnvironmentFromRequest(): ?\App\Models\Environment
    {
        $project = static::resolveProjectFromRequest();
        if (! $project) {
            return null;
        }

        $request = request();
        $query = fn () => \App\Models\Environment::withoutGlobalScopes()->where('project_id', $project->id);

        // v4.1+ route parameter
        $envUuid = $request->route('environment_uuid');
        if ($envUuid) {
            return $query()->where('uuid', $envUuid)->first();
        }

        // Legacy route parameter (environment name)
        $envName = $request->route('environment_name');

        // Fallback: extract from URL path
        if (! $envName) {
            $path = $request->path();
            if (preg_match('#^project/[^/]+/environment/([^/]+)#', $path, $matches)) {
                $segment = $matches[1];
                $byUuid = $query()->where('uuid', $segment)->first();
                if ($byUuid) {
                    return $byUuid;
                }
                $envName = $segment;
            } elseif (preg_match('#^project/[^/]+/([^/]+)#', $path, $matches)) {
                $envName = $matches[1];
            }
        }

        if ($envName && $envName !== 'environment') {
            return $query()->where('name', $envName)->first();
        }

        return null;
    }

    /**
     * Check if user can create resources in the current request context.
     *
     * Since create() policy methods don't receive a model instance,
     * this resolves the project and environment from the request URL
     * and checks the manage permission at the most specific level available.
     *
     * Environment-level overrides take precedence over project-level,
     * matching the same cascade logic used by hasEnvironmentPermission().
     */
    public static function canCreateInCurrentContext(mixed $user): bool
    {
        if (static::hasRoleBypass($user)) {
            return true;
        }

        // Try environment-level first (most specific)
        $environment = static::resolveEnvironmentFromRequest();
        if ($environment) {
            return static::hasEnvironmentPermission($user, $environment, 'manage');
        }

        // Fall back to project-level
        $project = static::resolveProjectFromRequest();
        if ($project) {
            return static::hasProjectPermission($user, $project, 'manage');
        }

        // No project context - deny creation for non-bypass users
        return false;
    }

    /**
     * Check permission for a resource that has a polymorphic parent
     * (e.g., EnvironmentVariable → Application/Service via resourceable).
     *
     * Traverses the parent chain: resource → environment → permission check.
     */
    public static function checkResourceablePermission(User $user, $resourceable, string $permission): bool
    {
        if (! $resourceable) {
            return static::hasRoleBypass($user);
        }

        // The resourceable is the parent Application/Service/Database
        return static::canPerform($user, $permission, $resourceable);
    }

    /**
     * Grant project access to a user.
     */
    public static function grantProjectAccess(User $user, $project, string $level = 'view_only'): ProjectUser
    {
        return ProjectUser::updateOrCreate(
            [
                'project_id' => $project->id,
                'user_id' => $user->id,
            ],
            [
                'permissions' => ProjectUser::getPermissionsForLevel($level),
            ]
        );
    }

    /**
     * Revoke project access from a user.
     */
    public static function revokeProjectAccess(User $user, $project): bool
    {
        return ProjectUser::where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->delete() > 0;
    }

    /**
     * Grant environment access to a user (override).
     */
    public static function grantEnvironmentAccess(User $user, $environment, string $level = 'view_only'): EnvironmentUser
    {
        return EnvironmentUser::updateOrCreate(
            [
                'environment_id' => $environment->id,
                'user_id' => $user->id,
            ],
            [
                'permissions' => EnvironmentUser::getPermissionsForLevel($level),
            ]
        );
    }

    /**
     * Revoke environment access from a user.
     */
    public static function revokeEnvironmentAccess(User $user, $environment): bool
    {
        return EnvironmentUser::where('environment_id', $environment->id)
            ->where('user_id', $user->id)
            ->delete() > 0;
    }
}
