<?php

namespace CorelixIo\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ResourceNetwork extends Model
{
    protected $fillable = [
        'managed_network_id', 'resource_type', 'resource_id',
        'aliases', 'ipv4_address', 'is_auto_attached', 'is_connected', 'connected_at',
    ];

    protected $casts = [
        'aliases' => 'array',
        'is_auto_attached' => 'boolean',
        'is_connected' => 'boolean',
        'connected_at' => 'datetime',
    ];

    /**
     * Get the resource (Application, Service, or Database) via polymorphic relation.
     */
    public function resource(): MorphTo
    {
        return $this->morphTo('resource');
    }

    /**
     * Get the managed network this record belongs to.
     */
    public function managedNetwork(): BelongsTo
    {
        return $this->belongsTo(ManagedNetwork::class);
    }

    /**
     * Scope to connected resources only.
     */
    public function scopeConnected($query)
    {
        return $query->where('is_connected', true);
    }

    /**
     * Scope to auto-attached resources only.
     */
    public function scopeAutoAttached($query)
    {
        return $query->where('is_auto_attached', true);
    }
}
