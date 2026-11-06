<?php

namespace CorelixIo\Platform\Support;

class Feature
{
    protected static ?array $resolved = null;

    public static function enabled(string $key): bool
    {
        return static::all()[$key] ?? false;
    }

    public static function disabled(string $key): bool
    {
        return ! static::enabled($key);
    }

    public static function tier(string $key): ?string
    {
        return static::meta($key)['tier'] ?? null;
    }

    public static function meta(string $key): ?array
    {
        foreach (static::registry() as $feature) {
            if ($feature['key'] === $key) {
                return $feature;
            }
        }

        return null;
    }

    public static function all(): array
    {
        if (static::$resolved === null) {
            static::$resolved = static::resolveAll();
        }

        return static::$resolved;
    }

    public static function allEnabled(): array
    {
        return array_filter(static::all());
    }

    public static function edition(): string
    {
        return config('features.edition', 'pro');
    }

    public static function upgradeUrl(): string
    {
        return config('features.upgrade_url', 'https://corelix.io/pricing');
    }

    public static function registry(): array
    {
        return config('features.registry', []);
    }

    public static function frontendFlags(): array
    {
        return static::all();
    }

    public static function flush(): void
    {
        static::$resolved = null;
    }

    protected static function resolveAll(): array
    {
        $registry = static::registry();
        $flags = [];
        $edition = static::edition();

        foreach ($registry as $feature) {
            $flags[$feature['key']] = static::resolveFeatureFlag($feature);
        }

        if ($edition === 'free') {
            foreach ($registry as $feature) {
                if (($feature['tier'] ?? '') === 'pro') {
                    $flags[$feature['key']] = false;
                }
            }
        }

        foreach ($registry as $feature) {
            if ($feature['parent'] !== null && ! ($flags[$feature['parent']] ?? false)) {
                $flags[$feature['key']] = false;
            }
        }

        return $flags;
    }

    /**
     * Resolve a single feature flag: runtime env (getenv) overrides config `enabled`,
     * which overrides manifest `default`. getenv keeps tests (putenv) and deploy env working
     * without calling env() here (config:cache safe — baked values live in `enabled`).
     */
    protected static function resolveFeatureFlag(array $feature): bool
    {
        $envVar = $feature['env_var'] ?? null;
        if ($envVar !== null) {
            $raw = getenv($envVar);
            if ($raw !== false) {
                return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
            }
        }

        if (array_key_exists('enabled', $feature)) {
            return filter_var($feature['enabled'], FILTER_VALIDATE_BOOLEAN);
        }

        return filter_var($feature['default'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
