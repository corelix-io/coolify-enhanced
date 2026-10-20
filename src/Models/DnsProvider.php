<?php

namespace CorelixIo\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A team-level DNS / ingress provider configuration.
 *
 * Credentials are stored encrypted (mirrors DockerRegistry). For Cloudflare Tunnel the
 * credentials array holds: api_token, account_id.
 */
class DnsProvider extends Model
{
    protected $table = 'dns_providers';

    protected $fillable = [
        'name', 'type', 'credentials', 'is_active', 'team_id',
        'last_tested_at', 'last_test_status', 'last_test_error',
    ];

    protected $hidden = ['credentials'];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'is_active' => 'boolean',
        'last_tested_at' => 'datetime',
    ];

    public const TYPE_CLOUDFLARE_TUNNEL = 'cloudflare_tunnel';

    /**
     * Scaffold type (Wave 7, T7.4): driver + factory mapping exist as an interface-
     * conformance proof, but the type is intentionally NOT in TYPES yet — neither the
     * UI nor the API can create one until the driver's reconcile path is implemented.
     */
    public const TYPE_POWERDNS = 'powerdns';

    public const TYPES = [
        self::TYPE_CLOUDFLARE_TUNNEL,
    ];

    public const TEST_SUCCESS = 'success';

    public const TEST_FAILED = 'failed';

    protected static function booted(): void
    {
        static::creating(function (self $provider) {
            if (empty($provider->uuid)) {
                $provider->uuid = (string) Str::uuid();
            }
        });
    }

    public function team()
    {
        return $this->belongsTo(\App\Models\Team::class);
    }

    public function tunnels(): HasMany
    {
        return $this->hasMany(CloudflareTunnel::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    public function scopeOwnedByTeam($query, int $teamId)
    {
        return $query->where('team_id', $teamId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function getTypeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_CLOUDFLARE_TUNNEL => 'Cloudflare Tunnel',
            self::TYPE_POWERDNS => 'PowerDNS',
            default => $this->type,
        };
    }

    /**
     * Credential field names expected for a provider type.
     */
    public static function getCredentialFields(string $type): array
    {
        return match ($type) {
            self::TYPE_CLOUDFLARE_TUNNEL => ['api_token', 'account_id'],
            self::TYPE_POWERDNS => ['api_url', 'api_key', 'server_id'],
            default => ['api_token'],
        };
    }

    public function getApiToken(): string
    {
        return ($this->credentials ?? [])['api_token'] ?? '';
    }

    public function getAccountId(): string
    {
        return ($this->credentials ?? [])['account_id'] ?? '';
    }

    /**
     * Mask credentials for safe API responses — secrets never leave the server.
     */
    public function getMaskedCredentials(): array
    {
        $creds = $this->credentials ?? [];
        $masked = [];

        foreach ($creds as $key => $value) {
            if (in_array($key, ['account_id'], true)) {
                $masked[$key] = $value;
            } else {
                $masked[$key] = $value ? '***' : null;
            }
        }

        return $masked;
    }
}
