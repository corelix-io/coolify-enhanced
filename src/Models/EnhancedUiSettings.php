<?php

namespace CorelixIo\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class EnhancedUiSettings extends Model
{
    private const CACHE_TTL_SECONDS = 60;

    private const BOOLEAN_TRUE_VALUES = ['1', 'true', 'yes', 'on'];

    private const BOOLEAN_FALSE_VALUES = ['0', 'false', 'no', 'off'];

    protected $table = 'enhanced_ui_settings';

    protected $fillable = ['key', 'value'];

    /**
     * Get a setting value by key. Falls back to direct DB query if cache is unavailable.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $resolver = function () use ($key, $default) {
            $row = static::query()->where('key', $key)->first();

            if (! $row) {
                return $default;
            }

            $value = $row->value;
            if ($value === null) {
                return $default;
            }

            $normalized = strtolower(trim((string) $value));
            if (in_array($normalized, self::BOOLEAN_TRUE_VALUES, true)) {
                return true;
            }
            if (in_array($normalized, self::BOOLEAN_FALSE_VALUES, true)) {
                return false;
            }

            return $value;
        };

        try {
            return Cache::remember(self::cacheKey($key), self::CACHE_TTL_SECONDS, $resolver);
        } catch (\Throwable $e) {
            return $resolver();
        }
    }

    /**
     * Set a setting value by key.
     */
    public static function set(string $key, mixed $value): void
    {
        $stringValue = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $stringValue]
        );

        try {
            Cache::forget(self::cacheKey($key));
        } catch (\Throwable $e) {
            // Cache unavailable — the DB is the source of truth
        }
    }

    public static function getActiveTheme(): ?string
    {
        if (! config('corelix-platform.enabled', false)) {
            return null;
        }

        try {
            $theme = static::get('active_theme', null);
        } catch (\Throwable $e) {
            $theme = null;
        }

        if (! $theme || ! is_string($theme) || trim($theme) === '') {
            $theme = config('corelix-platform.ui_theme.default');
        }

        if (! $theme || ! is_string($theme) || trim($theme) === '') {
            return null;
        }

        $themes = config('corelix-platform.ui_theme.themes', []);

        if (! array_key_exists($theme, $themes)) {
            return null;
        }

        return $theme;
    }

    public static function setActiveTheme(?string $theme): void
    {
        static::set('active_theme', ($theme && trim($theme) !== '') ? $theme : '');
    }

    public static function isThemeActive(): bool
    {
        return static::getActiveTheme() !== null;
    }

    public static function getAvailableThemes(): array
    {
        return config('corelix-platform.ui_theme.themes', []);
    }

    /**
     * Backward-compatible check — returns true when any theme is active.
     */
    public static function isThemeEnabled(): bool
    {
        return static::isThemeActive();
    }

    protected static function cacheKey(string $key): string
    {
        return 'enhanced_ui_settings:'.$key;
    }
}
