<?php

namespace CorelixIo\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A remotely-managed Cloudflare Tunnel plus its Corelix-managed cloudflared daemon (D2).
 *
 * The tunnel token (used to run cloudflared) is stored encrypted in `credentials`.
 */
class CloudflareTunnel extends Model
{
    protected $table = 'cloudflare_tunnels';

    protected $fillable = [
        'dns_provider_id', 'name', 'cf_tunnel_id', 'credentials', 'cname_target',
        'managed_daemon', 'daemon_server_id', 'daemon_status', 'daemon_error',
        'status', 'config_synced_at',
    ];

    protected $hidden = ['credentials'];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'managed_daemon' => 'boolean',
        'config_synced_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ERROR = 'error';

    public const DAEMON_PENDING = 'pending';

    public const DAEMON_RUNNING = 'running';

    public const DAEMON_ERROR = 'error';

    public const DAEMON_STOPPED = 'stopped';

    protected static function booted(): void
    {
        static::creating(function (self $tunnel) {
            if (empty($tunnel->uuid)) {
                $tunnel->uuid = (string) Str::uuid();
            }
        });
    }

    public function provider()
    {
        return $this->belongsTo(DnsProvider::class, 'dns_provider_id');
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    public function daemonServer()
    {
        return $this->belongsTo(\App\Models\Server::class, 'daemon_server_id');
    }

    public function getTunnelToken(): string
    {
        return ($this->credentials ?? [])['tunnel_token'] ?? '';
    }

    /**
     * The CNAME target hostnames should point at: <cf_tunnel_id>.cfargotunnel.com.
     */
    public function resolveCnameTarget(): ?string
    {
        if (! empty($this->cname_target)) {
            return $this->cname_target;
        }

        return $this->cf_tunnel_id ? "{$this->cf_tunnel_id}.cfargotunnel.com" : null;
    }
}
