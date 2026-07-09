<?php

namespace CorelixIo\Platform\Models;

use App\Models\Environment;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * EnvironmentUser pivot model.
 *
 * Represents environment-level permission overrides.
 * When set, these permissions take precedence over project-level permissions.
 *
 * @property int $id
 * @property int $environment_id
 * @property int $user_id
 * @property array $permissions
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read Environment $environment
 * @property-read User $user
 */
class EnvironmentUser extends Pivot
{
    protected $table = 'environment_user';

    public $incrementing = true;

    protected $fillable = [
        'environment_id',
        'user_id',
        'permissions',
    ];

    protected $casts = [
        'permissions' => 'array',
    ];

    /**
     * Default permissions structure.
     */
    public const DEFAULT_PERMISSIONS = [
        'view' => false,
        'deploy' => false,
        'manage' => false,
        'delete' => false,
    ];

    /**
     * Full access permissions.
     */
    public const FULL_ACCESS_PERMISSIONS = [
        'view' => true,
        'deploy' => true,
        'manage' => true,
        'delete' => true,
    ];

    /**
     * View-only permissions.
     */
    public const VIEW_ONLY_PERMISSIONS = [
        'view' => true,
        'deploy' => false,
        'manage' => false,
        'delete' => false,
    ];

    /**
     * Deploy permissions (view + deploy).
     */
    public const DEPLOY_PERMISSIONS = [
        'view' => true,
        'deploy' => true,
        'manage' => false,
        'delete' => false,
    ];

    /**
     * Get the environment.
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * Get the user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if user has a specific permission.
     */
    public function hasPermission(string $permission): bool
    {
        $permissions = $this->permissions ?? [];

        return $permissions[$permission] ?? false;
    }

    /**
     * Check if user can view the environment.
     */
    public function canView(): bool
    {
        return $this->hasPermission('view');
    }

    /**
     * Check if user can deploy to the environment.
     */
    public function canDeploy(): bool
    {
        return $this->hasPermission('deploy');
    }

    /**
     * Check if user can manage the environment.
     */
    public function canManage(): bool
    {
        return $this->hasPermission('manage');
    }

    /**
     * Check if user can delete resources in the environment.
     */
    public function canDelete(): bool
    {
        return $this->hasPermission('delete');
    }

    /**
     * Set all permissions at once.
     */
    public function setPermissions(array $permissions): self
    {
        $this->permissions = array_merge(self::DEFAULT_PERMISSIONS, $permissions);

        return $this;
    }

    /**
     * Get permissions array from a permission level string.
     *
     * @param  string  $level  One of: view_only, deploy, full_access
     */
    public static function getPermissionsForLevel(string $level): array
    {
        return match ($level) {
            'full_access' => self::FULL_ACCESS_PERMISSIONS,
            'deploy' => self::DEPLOY_PERMISSIONS,
            'none' => self::DEFAULT_PERMISSIONS,
            default => self::VIEW_ONLY_PERMISSIONS,
        };
    }

    /**
     * Get permission level string from permissions array.
     */
    public function getPermissionLevel(): string
    {
        if ($this->canView() && $this->canDeploy() && $this->canManage() && $this->canDelete()) {
            return 'full_access';
        }
        if ($this->canDeploy()) {
            return 'deploy';
        }
        if ($this->canView()) {
            return 'view_only';
        }

        return 'none';
    }
}
