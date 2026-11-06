<?php

namespace CorelixIo\Platform\Models;

use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ManagedNetwork extends Model
{
    protected $fillable = [
        'uuid', 'name', 'docker_network_name', 'server_id', 'team_id', 'driver', 'scope',
        'project_id', 'environment_id', 'subnet', 'gateway', 'is_internal',
        'is_attachable', 'is_proxy_network', 'is_encrypted_overlay', 'options', 'labels',
        'docker_id', 'status', 'error_message', 'last_synced_at',
    ];

    protected $casts = [
        'is_internal' => 'boolean',
        'is_attachable' => 'boolean',
        'is_proxy_network' => 'boolean',
        'is_encrypted_overlay' => 'boolean',
        'options' => 'array',
        'labels' => 'array',
        'last_synced_at' => 'datetime',
    ];

    /**
     * Network scope constants.
     */
    public const SCOPE_ENVIRONMENT = 'environment';

    public const SCOPE_PROJECT = 'project';

    public const SCOPE_SHARED = 'shared';

    public const SCOPE_PROXY = 'proxy';

    public const SCOPE_SYSTEM = 'system';

    /**
     * Network status constants.
     */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ERROR = 'error';

    public const STATUS_ORPHANED = 'orphaned';

    /**
     * Get the server this network belongs to.
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * Get the team this network belongs to.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the project this network is scoped to (nullable).
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the environment this network is scoped to (nullable).
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * Get all resource-network pivot records for this network.
     */
    public function resourceNetworks(): HasMany
    {
        return $this->hasMany(ResourceNetwork::class);
    }

    /**
     * Scope to networks on a specific server.
     */
    public function scopeForServer($query, Server $server)
    {
        return $query->where('server_id', $server->id);
    }

    /**
     * Scope to networks for a specific environment.
     */
    public function scopeForEnvironment($query, Environment $environment)
    {
        return $query->where('environment_id', $environment->id);
    }

    /**
     * Scope to networks for a specific project.
     */
    public function scopeForProject($query, Project $project)
    {
        return $query->where('project_id', $project->id);
    }

    /**
     * Scope to shared networks.
     */
    public function scopeShared($query)
    {
        return $query->where('scope', self::SCOPE_SHARED);
    }

    /**
     * Scope to proxy networks.
     */
    public function scopeProxy($query)
    {
        return $query->where('scope', self::SCOPE_PROXY);
    }

    /**
     * Scope to active networks.
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Check if the network is synced with Docker.
     */
    public function getIsDockerSyncedAttribute(): bool
    {
        return $this->docker_id !== null && $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Get the count of currently connected containers.
     * Uses eager-loaded relation when available to avoid N+1 queries.
     */
    public function connectedContainerCount(): int
    {
        if ($this->relationLoaded('resourceNetworks')) {
            return $this->resourceNetworks->where('is_connected', true)->count();
        }

        return $this->resourceNetworks()->where('is_connected', true)->count();
    }

    /**
     * Check if this is a Swarm overlay network.
     */
    public function getIsOverlayAttribute(): bool
    {
        return $this->driver === 'overlay';
    }
}
