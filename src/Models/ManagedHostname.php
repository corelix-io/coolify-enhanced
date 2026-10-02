<?php

namespace CorelixIo\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * Per-resource hostname reconciliation state (polymorphic — mirrors ResourceNetwork).
 *
 * One row per (resource, concrete hostname). Tracks how the hostname was bound to a domain,
 * what kind of record backs it (HTTP tunnel ingress vs TCP DNS A/AAAA), and the live sync state.
 */
class ManagedHostname extends Model
{
    protected $table = 'managed_hostnames';

    protected $fillable = [
        'resource_type', 'resource_id',
        'domain_id', 'dns_provider_id',
        'hostname', 'binding_source', 'record_kind', 'sync_state',
        'provider_record_id', 'provider_ingress_ref',
        'last_synced_at', 'last_error',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
    ];

    public const SOURCE_OVERRIDE = 'override';

    public const SOURCE_ENV_BINDING = 'env_binding';

    public const SOURCE_SUFFIX_MATCH = 'suffix_match';

    public const KIND_HTTP_TUNNEL = 'http_tunnel';

    public const KIND_TCP_DNS = 'tcp_dns';

    public const STATE_PENDING = 'pending';

    public const STATE_SYNCED = 'synced';

    public const STATE_ERROR = 'error';

    public const STATE_DRIFTED = 'drifted';

    public const STATE_UNMANAGED = 'unmanaged';

    protected static function booted(): void
    {
        static::creating(function (self $hostname) {
            if (empty($hostname->uuid)) {
                $hostname->uuid = (string) Str::uuid();
            }
            if (! empty($hostname->hostname)) {
                $hostname->hostname = rtrim(strtolower(trim($hostname->hostname)), '.');
            }
        });
    }

    public function resource(): MorphTo
    {
        return $this->morphTo();
    }

    public function domain()
    {
        return $this->belongsTo(Domain::class);
    }

    public function provider()
    {
        return $this->belongsTo(DnsProvider::class, 'dns_provider_id');
    }

    public function scopeInState($query, string $state)
    {
        return $query->where('sync_state', $state);
    }
}
