<?php

namespace CorelixIo\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A managed base domain (suffix), e.g. "apps.example.com".
 *
 * Ownership of a concrete FQDN is resolved by longest-suffix match against active team domains
 * (with an explicit per-resource override winning first) — see findings §6.2.
 */
class Domain extends Model
{
    protected $table = 'domains';

    protected $fillable = [
        'team_id', 'dns_provider_id', 'cloudflare_tunnel_id',
        'base_domain', 'routing_mode', 'tls_mode', 'default_ingress_target',
        'is_default', 'is_active',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public const ROUTING_WILDCARD = 'wildcard';

    public const ROUTING_PER_HOSTNAME = 'per_hostname';

    public const ROUTING_HYBRID = 'hybrid';

    protected static function booted(): void
    {
        static::creating(function (self $domain) {
            if (empty($domain->uuid)) {
                $domain->uuid = (string) Str::uuid();
            }
            $domain->base_domain = self::normalizeBaseDomain($domain->base_domain);
        });

        static::updating(function (self $domain) {
            if ($domain->isDirty('base_domain')) {
                $domain->base_domain = self::normalizeBaseDomain($domain->base_domain);
            }
        });
    }

    public function provider()
    {
        return $this->belongsTo(DnsProvider::class, 'dns_provider_id');
    }

    public function tunnel()
    {
        return $this->belongsTo(CloudflareTunnel::class, 'cloudflare_tunnel_id');
    }

    public function managedHostnames(): HasMany
    {
        return $this->hasMany(ManagedHostname::class);
    }

    public function servers(): BelongsToMany
    {
        return $this->belongsToMany(\App\Models\Server::class, 'domain_server')
            ->withPivot(['is_default_wildcard', 'last_synced_wildcard'])
            ->withTimestamps();
    }

    public function scopeOwnedByTeam($query, int $teamId)
    {
        return $query->where('team_id', $teamId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Normalize a base domain to a bare suffix: lowercase, trimmed, no scheme, no leading '*.',
     * no trailing dot. Storage form used by longest-suffix matching.
     */
    /** RFC 1123 hostname pattern for stored base domains (labels + TLD). */
    public const BASE_DOMAIN_PATTERN = '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/';

    public static function normalizeBaseDomain(?string $value): string
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $value); // strip scheme
        if (($slash = strpos($value, '/')) !== false) {
            $value = substr($value, 0, $slash);
        }
        if (($colon = strpos($value, ':')) !== false) {
            $value = substr($value, 0, $colon);
        }
        $value = ltrim($value, '.');
        if (str_starts_with($value, '*.')) {
            $value = substr($value, 2);
        }
        $value = rtrim($value, '.');

        return $value;
    }

    public static function isValidBaseDomain(string $normalized): bool
    {
        return $normalized !== ''
            && str_contains($normalized, '.')
            && (bool) preg_match(self::BASE_DOMAIN_PATTERN, $normalized);
    }

    /**
     * True if the given hostname belongs to this domain (exact apex or dot-boundary subdomain).
     */
    public function ownsHostname(string $hostname): bool
    {
        $hostname = rtrim(strtolower(trim($hostname)), '.');
        $base = $this->base_domain;

        return $hostname === $base || str_ends_with($hostname, '.'.$base);
    }
}
