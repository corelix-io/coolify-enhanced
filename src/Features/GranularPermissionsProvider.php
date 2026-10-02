<?php

namespace CorelixIo\Platform\Features;

use Illuminate\Foundation\Application;

class GranularPermissionsProvider implements FeatureProviderInterface
{
    public static function featureKey(): string
    {
        return 'GRANULAR_PERMISSIONS';
    }

    public function register(Application $app): void {}

    public function boot(Application $app): void
    {
        \Livewire\Livewire::component('enhanced::access-matrix', \CorelixIo\Platform\Livewire\AccessMatrix::class);

        $kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
        $kernel->pushMiddleware(\CorelixIo\Platform\Http\Middleware\InjectPermissionsUI::class);

        if (class_exists(\App\Models\Project::class)) {
            \App\Models\Project::addGlobalScope(new \CorelixIo\Platform\Scopes\ProjectPermissionScope);
        }
        if (class_exists(\App\Models\Environment::class)) {
            \App\Models\Environment::addGlobalScope(new \CorelixIo\Platform\Scopes\EnvironmentPermissionScope);
        }
    }

    public function booted(Application $app): void
    {
        $policies = [
            \App\Models\Application::class => \CorelixIo\Platform\Policies\ApplicationPolicy::class,
            \App\Models\Project::class => \CorelixIo\Platform\Policies\ProjectPolicy::class,
            \App\Models\Environment::class => \CorelixIo\Platform\Policies\EnvironmentPolicy::class,
            \App\Models\Server::class => \CorelixIo\Platform\Policies\ServerPolicy::class,
            \App\Models\Service::class => \CorelixIo\Platform\Policies\ServicePolicy::class,
            \App\Models\StandalonePostgresql::class => \CorelixIo\Platform\Policies\DatabasePolicy::class,
            \App\Models\StandaloneMysql::class => \CorelixIo\Platform\Policies\DatabasePolicy::class,
            \App\Models\StandaloneMariadb::class => \CorelixIo\Platform\Policies\DatabasePolicy::class,
            \App\Models\StandaloneMongodb::class => \CorelixIo\Platform\Policies\DatabasePolicy::class,
            \App\Models\StandaloneRedis::class => \CorelixIo\Platform\Policies\DatabasePolicy::class,
            \App\Models\StandaloneKeydb::class => \CorelixIo\Platform\Policies\DatabasePolicy::class,
            \App\Models\StandaloneDragonfly::class => \CorelixIo\Platform\Policies\DatabasePolicy::class,
            \App\Models\StandaloneClickhouse::class => \CorelixIo\Platform\Policies\DatabasePolicy::class,
            \App\Models\EnvironmentVariable::class => \CorelixIo\Platform\Policies\EnvironmentVariablePolicy::class,
            \App\Models\ServiceApplication::class => \CorelixIo\Platform\Policies\ServiceApplicationPolicy::class,
            \App\Models\ServiceDatabase::class => \CorelixIo\Platform\Policies\ServiceDatabasePolicy::class,
            \App\Models\SharedEnvironmentVariable::class => \CorelixIo\Platform\Policies\SharedEnvironmentVariablePolicy::class,
        ];
        foreach ($policies as $model => $policy) {
            if (class_exists($model)) {
                \Illuminate\Support\Facades\Gate::policy($model, $policy);
            }
        }

        \Illuminate\Support\Facades\Gate::define('createAnyResource', function (\App\Models\User $user) {
            if (! \CorelixIo\Platform\Services\PermissionService::isEnabled()) {
                return true;
            }

            return \CorelixIo\Platform\Services\PermissionService::hasRoleBypass($user)
                || \CorelixIo\Platform\Services\PermissionService::canCreateInCurrentContext($user);
        });

        if (class_exists(\App\Models\User::class)) {
            $userClass = \App\Models\User::class;
            if (method_exists($userClass, 'macro') && !method_exists($userClass, 'canPerform')) {
                $userClass::macro('canPerform', function (string $action, $resource): bool {
                    return \CorelixIo\Platform\Services\PermissionService::canPerform($this, $action, $resource);
                });
            }
        }
    }

    public function apiRoutes(): ?string
    {
        return __DIR__ . '/../../routes/features/permissions-api.php';
    }

    public function webRoutes(): ?string
    {
        return null;
    }
}
