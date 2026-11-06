<?php

namespace CorelixIo\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CustomTemplateSource extends Model
{
    protected $fillable = [
        'name', 'repository_url', 'branch', 'folder_path', 'auth_token', 'enabled',
        'last_synced_at', 'last_sync_status', 'last_sync_error', 'template_count',
    ];

    protected $hidden = ['auth_token'];

    protected $casts = [
        'auth_token' => 'encrypted',
        'enabled' => 'boolean',
        'template_count' => 'integer',
        'last_synced_at' => 'datetime',
    ];

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SYNCING = 'syncing';

    protected static function booted(): void
    {
        static::creating(function (self $source) {
            if (empty($source->uuid)) {
                $source->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Get the slug used for disambiguating template names.
     */
    public function getSlugAttribute(): string
    {
        return Str::slug($this->name);
    }

    /**
     * Get the directory where cached templates are stored.
     */
    public function getCacheDirectory(): string
    {
        $baseDir = config('corelix-platform.custom_templates.cache_dir', storage_path('app/custom-templates'));

        return $baseDir.'/'.$this->uuid;
    }

    /**
     * Get the path to the cached templates JSON file.
     */
    public function getCacheFilePath(): string
    {
        return $this->getCacheDirectory().'/templates.json';
    }

    /**
     * Parse the repository URL to extract owner and repo name.
     *
     * Supports formats:
     *   https://github.com/owner/repo
     *   https://github.com/owner/repo.git
     *   github.com/owner/repo
     *   https://github.example.com/owner/repo (Enterprise)
     *
     * @return array{host: string, owner: string, repo: string}|null
     */
    /**
     * @return string[]
     */
    public static function allowedGithubHosts(): array
    {
        $hosts = config('corelix-platform.custom_templates.allowed_github_hosts', ['github.com']);

        return array_map('strtolower', (array) $hosts);
    }

    public function repositoryHost(): ?string
    {
        $parsed = $this->parseRepositoryUrl(skipHostCheck: true);

        return $parsed['host'] ?? null;
    }

    public function hasAllowedRepositoryHost(): bool
    {
        $host = $this->repositoryHost();
        if ($host === null) {
            return false;
        }

        return in_array(strtolower($host), self::allowedGithubHosts(), true);
    }

    /**
     * @return array{host: string, owner: string, repo: string}|null
     */
    public function parseRepositoryUrl(bool $skipHostCheck = false): ?array
    {
        $url = $this->repository_url;

        // Normalize: add scheme if missing
        if (! preg_match('#^https?://#', $url)) {
            $url = 'https://'.$url;
        }

        $parsed = parse_url($url);
        if (! $parsed || empty($parsed['host']) || empty($parsed['path'])) {
            return null;
        }

        if (! $skipHostCheck && ! in_array(strtolower($parsed['host']), self::allowedGithubHosts(), true)) {
            return null;
        }

        $path = trim($parsed['path'], '/');
        $path = preg_replace('/\.git$/', '', $path);
        $segments = explode('/', $path);

        if (count($segments) < 2) {
            return null;
        }

        return [
            'host' => $parsed['host'],
            'owner' => $segments[0],
            'repo' => $segments[1],
        ];
    }

    /**
     * Get the GitHub API base URL for this source.
     */
    public function getApiBaseUrl(): string
    {
        $parsed = $this->parseRepositoryUrl();
        if (! $parsed) {
            return 'https://api.github.com';
        }

        if ($parsed['host'] === 'github.com') {
            return 'https://api.github.com';
        }

        // GitHub Enterprise
        return 'https://'.$parsed['host'].'/api/v3';
    }

    /**
     * Get the raw content base URL for downloading files.
     */
    public function getRawContentBaseUrl(): string
    {
        $parsed = $this->parseRepositoryUrl();
        if (! $parsed) {
            return 'https://raw.githubusercontent.com';
        }

        if ($parsed['host'] === 'github.com') {
            return "https://raw.githubusercontent.com/{$parsed['owner']}/{$parsed['repo']}/{$this->branch}";
        }

        // GitHub Enterprise raw URL pattern
        return "https://{$parsed['host']}/{$parsed['owner']}/{$parsed['repo']}/raw/{$this->branch}";
    }

    /**
     * Check if this source has been synced at least once.
     */
    public function hasBeenSynced(): bool
    {
        return $this->last_synced_at !== null;
    }

    /**
     * Check if a cached templates file exists.
     */
    public function hasCachedTemplates(): bool
    {
        return file_exists($this->getCacheFilePath());
    }

    /**
     * Load cached templates from disk.
     *
     * @return array<string, mixed>
     */
    public function loadCachedTemplates(): array
    {
        if (! $this->hasCachedTemplates()) {
            return [];
        }

        $contents = file_get_contents($this->getCacheFilePath());
        if ($contents === false) {
            return [];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }
}
