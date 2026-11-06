<?php

namespace CorelixIo\Platform\Services;

use CorelixIo\Platform\Models\CustomTemplateSource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

class TemplateSourceService
{
    /**
     * Sync a template source from its GitHub repository.
     *
     * Fetches YAML template files from the configured repo path,
     * parses them using the same format as Coolify's Generate/Services command,
     * validates the compose content, and caches the result to disk.
     *
     * @throws \RuntimeException on fatal sync errors
     */
    public static function syncSource(CustomTemplateSource $source): void
    {
        $source->update([
            'last_sync_status' => CustomTemplateSource::STATUS_SYNCING,
            'last_sync_error' => null,
        ]);

        try {
            if (! $source->hasAllowedRepositoryHost()) {
                throw new \RuntimeException(
                    'Repository host is not allowed. Allowed hosts: '.implode(', ', CustomTemplateSource::allowedGithubHosts())
                );
            }

            $parsed = $source->parseRepositoryUrl();
            if (! $parsed) {
                throw new \RuntimeException('Invalid repository URL: '.$source->repository_url);
            }

            $files = static::listYamlFiles($source, $parsed);

            if (empty($files)) {
                throw new \RuntimeException(
                    "No YAML files found in {$parsed['owner']}/{$parsed['repo']}/{$source->folder_path} (branch: {$source->branch})"
                );
            }

            $maxTemplates = config('corelix-platform.custom_templates.max_templates_per_source', 500);
            if (count($files) > $maxTemplates) {
                $files = array_slice($files, 0, $maxTemplates);
                Log::warning("TemplateSourceService: Source {$source->name} has more than {$maxTemplates} files, truncating.");
            }

            $templates = static::fetchAndParseTemplates($source, $parsed, $files);

            static::saveCachedTemplates($source, $templates);

            $source->update([
                'last_synced_at' => now(),
                'last_sync_status' => CustomTemplateSource::STATUS_SUCCESS,
                'last_sync_error' => null,
                'template_count' => count($templates),
            ]);

            // Invalidate in-memory cache so the next call picks up fresh data
            static::flushCache();
        } catch (\Throwable $e) {
            Log::error('TemplateSourceService: Sync failed for source '.$source->name, [
                'source_uuid' => $source->uuid,
                'error' => $e->getMessage(),
            ]);

            $source->update([
                'last_sync_status' => CustomTemplateSource::STATUS_FAILED,
                'last_sync_error' => Str::limit($e->getMessage(), 1000),
            ]);

            throw $e;
        }
    }

    /**
     * List YAML files in the configured repository path.
     *
     * Uses the GitHub Contents API. Falls back to the Git Trees API
     * for directories with too many entries.
     *
     * @param  array{host: string, owner: string, repo: string}  $parsed
     * @return array<int, array{name: string, download_url: string|null, path: string}>
     */
    protected static function listYamlFiles(CustomTemplateSource $source, array $parsed): array
    {
        $timeout = config('corelix-platform.custom_templates.github_timeout', 30);
        $apiBase = $source->getApiBaseUrl();
        $path = trim($source->folder_path, '/');

        $http = Http::timeout($timeout)
            ->retry(3, 1000)
            ->withHeaders(static::buildHeaders($source));

        // Try Contents API first
        $url = "{$apiBase}/repos/{$parsed['owner']}/{$parsed['repo']}/contents/{$path}";
        $response = $http->get($url, ['ref' => $source->branch]);

        if ($response->successful()) {
            $items = $response->json();
            if (! is_array($items)) {
                return [];
            }

            return collect($items)
                ->filter(fn ($item) => ($item['type'] ?? '') === 'file'
                    && preg_match('/\.(ya?ml)$/i', $item['name'] ?? '')
                )
                ->map(fn ($item) => [
                    'name' => $item['name'],
                    'path' => $item['path'],
                ])
                ->values()
                ->all();
        }

        // If Contents API fails (e.g., directory too large), try Trees API
        if ($response->status() === 403 || $response->status() === 422) {
            return static::listYamlFilesViaTreesApi($source, $parsed, $path);
        }

        // Handle auth errors clearly
        if ($response->status() === 401) {
            throw new \RuntimeException('Authentication failed. Check your GitHub token.');
        }
        if ($response->status() === 404) {
            throw new \RuntimeException(
                "Repository or path not found: {$parsed['owner']}/{$parsed['repo']}/{$path} (branch: {$source->branch}). "
                .'Check the repository URL, branch, and folder path. Private repos require an auth token.'
            );
        }

        throw new \RuntimeException("GitHub API error ({$response->status()}): ".$response->body());
    }

    /**
     * List YAML files using the Git Trees API (for large directories).
     *
     * @param  array{host: string, owner: string, repo: string}  $parsed
     * @return array<int, array{name: string, download_url: string|null, path: string}>
     */
    protected static function listYamlFilesViaTreesApi(CustomTemplateSource $source, array $parsed, string $path): array
    {
        $timeout = config('corelix-platform.custom_templates.github_timeout', 30);
        $apiBase = $source->getApiBaseUrl();

        $http = Http::timeout($timeout)
            ->retry(3, 1000)
            ->withHeaders(static::buildHeaders($source));

        $url = "{$apiBase}/repos/{$parsed['owner']}/{$parsed['repo']}/git/trees/{$source->branch}";
        $response = $http->get($url, ['recursive' => '1']);

        if (! $response->successful()) {
            throw new \RuntimeException("GitHub Trees API error ({$response->status()}): ".$response->body());
        }

        $tree = $response->json('tree', []);
        $prefix = $path ? $path.'/' : '';

        return collect($tree)
            ->filter(function ($item) use ($prefix) {
                if (($item['type'] ?? '') !== 'blob') {
                    return false;
                }
                $itemPath = $item['path'] ?? '';
                if ($prefix && ! str_starts_with($itemPath, $prefix)) {
                    return false;
                }
                // Only direct children (no subdirectories)
                $relativePath = $prefix ? substr($itemPath, strlen($prefix)) : $itemPath;
                if (str_contains($relativePath, '/')) {
                    return false;
                }

                return preg_match('/\.(ya?ml)$/i', $relativePath);
            })
            ->map(function ($item) use ($source, $parsed) {
                $name = basename($item['path']);
                $rawBase = $source->getRawContentBaseUrl();

                return [
                    'name' => $name,
                    'path' => $item['path'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Fetch and parse all template YAML files from the repository.
     *
     * Uses the same parsing logic as Coolify's `Generate/Services.php::processFile()`.
     *
     * @param  array{host: string, owner: string, repo: string}  $parsed
     * @param  array<int, array{name: string, download_url: string|null, path: string}>  $files
     * @return array<string, array<string, mixed>>
     */
    protected static function fetchAndParseTemplates(CustomTemplateSource $source, array $parsed, array $files): array
    {
        $timeout = config('corelix-platform.custom_templates.github_timeout', 30);
        $templates = [];
        $rawBase = $source->getRawContentBaseUrl();
        $folderPath = trim($source->folder_path, '/');

        foreach ($files as $file) {
            try {
                $content = static::downloadFileContent($source, $file, $timeout);
                if ($content === null) {
                    continue;
                }

                $template = static::parseTemplateContent($content, $file['name'], $source, $rawBase, $folderPath);
                if ($template === null) {
                    continue;
                }

                $templateName = $template['_key'];
                unset($template['_key']);
                $templates[$templateName] = $template;
            } catch (\Throwable $e) {
                Log::warning("TemplateSourceService: Failed to parse template {$file['name']} from {$source->name}", [
                    'error' => $e->getMessage(),
                ]);
                // Skip individual file failures — don't abort the entire sync
            }
        }

        return $templates;
    }

    /**
     * Download a single file's content from GitHub.
     *
     * Always constructs the download URL from known-good components
     * to prevent SSRF via untrusted download_url from the API.
     */
    protected static function downloadFileContent(CustomTemplateSource $source, array $file, int $timeout): ?string
    {
        // Always build URL from trusted components to prevent SSRF.
        // Never use download_url from the GitHub API response directly.
        $rawBase = $source->getRawContentBaseUrl();
        $url = "{$rawBase}/{$file['path']}";

        $response = Http::timeout($timeout)
            ->withHeaders(static::buildHeaders($source))
            ->get($url);

        if (! $response->successful()) {
            Log::warning("TemplateSourceService: Failed to download {$file['name']}: HTTP {$response->status()}");

            return null;
        }

        // Limit file size to 1MB to prevent memory exhaustion
        $body = $response->body();
        if (strlen($body) > 1_000_000) {
            Log::warning("TemplateSourceService: File {$file['name']} exceeds 1MB size limit, skipping.");

            return null;
        }

        return $body;
    }

    /**
     * Parse a single template YAML file into the service-templates format.
     *
     * Mirrors Coolify's `Generate/Services.php::processFile()` logic:
     * - Extracts metadata from comment headers (# key: value)
     * - Parses YAML and base64-encodes the compose content
     * - Validates the compose has a `services` section
     * - Resolves logo paths to raw GitHub URLs
     *
     * @return array<string, mixed>|null Returns null if the template should be skipped
     */
    protected static function parseTemplateContent(
        string $content,
        string $filename,
        CustomTemplateSource $source,
        string $rawBase,
        string $folderPath
    ): ?array {
        // Extract metadata headers (same regex as Coolify's Generate/Services.php)
        $data = collect(explode("\n", $content))->mapWithKeys(function ($line): array {
            preg_match('/^#(?<key>.*):(?<value>.*)$/U', $line, $m);

            return $m ? [trim($m['key']) => trim($m['value'])] : [];
        });

        // Track ignored status instead of skipping
        $isIgnored = str($data->get('ignore'))->toBoolean();

        // Parse and validate YAML
        $yaml = Yaml::parse($content);
        if (! is_array($yaml) || ! isset($yaml['services'])) {
            Log::warning("TemplateSourceService: Template {$filename} missing 'services' section, skipping.");

            return null;
        }

        // Inject 'coolify.database' label when '# type: database' or '# type: application'
        // comment header is present. This allows template authors to explicitly control
        // how Coolify classifies each service container (ServiceDatabase vs ServiceApplication).
        $typeOverride = strtolower(trim($data->get('type', '')));
        if (in_array($typeOverride, ['database', 'application'], true)) {
            $labelValue = $typeOverride === 'database' ? 'true' : 'false';
            foreach ($yaml['services'] as $svcName => &$svcConfig) {
                // Only inject if the service doesn't already have the label
                $existingLabels = $svcConfig['labels'] ?? [];
                $hasExplicitLabel = false;

                if (is_array($existingLabels)) {
                    foreach ($existingLabels as $lk => $lv) {
                        if ((is_string($lk) && strtolower($lk) === 'coolify.database') ||
                            (is_string($lv) && str_starts_with(strtolower($lv), 'coolify.database='))) {
                            $hasExplicitLabel = true;
                            break;
                        }
                    }
                }

                if (! $hasExplicitLabel) {
                    // Use map format for labels (key: value)
                    if (! isset($svcConfig['labels']) || ! is_array($svcConfig['labels'])) {
                        $svcConfig['labels'] = [];
                    }
                    $svcConfig['labels']['coolify.database'] = $labelValue;
                }
            }
            unset($svcConfig); // break reference
        }

        // Run injection validation if the function exists (it's in Coolify's parsers.php)
        if (function_exists('validateDockerComposeForInjection')) {
            try {
                validateDockerComposeForInjection(Yaml::dump($yaml, 10, 2));
            } catch (\Throwable $e) {
                Log::warning("TemplateSourceService: Template {$filename} failed injection validation: {$e->getMessage()}");

                return null;
            }
        }

        // Build the template payload
        $compose = base64_encode(Yaml::dump($yaml, 10, 2));

        $documentation = $data->get('documentation');
        $documentation = $documentation ? $documentation.'?utm_source=coolify.io' : null;

        $tags = str($data->get('tags', ''))->lower()->explode(',')->map(fn ($tag) => trim($tag))->filter();
        $tags = $tags->isEmpty() ? null : $tags->values()->all();

        // Resolve logo: absolute URLs are used directly, relative paths are from repo root (raw GitHub URLs)
        $logo = $data->get('logo', 'svgs/default.webp');
        if ($logo && ! preg_match('#^https?://#', $logo)) {
            // Relative paths are resolved from the repository root so icons at repo root (e.g. svgs/*.svg) load correctly
            $logoPath = ltrim($logo, '/');
            $logoPath = static::resolveRelativePath($logoPath);
            $logo = "{$rawBase}/{$logoPath}";
        }

        $templateName = pathinfo($filename, PATHINFO_FILENAME);

        $payload = [
            '_key' => $templateName,
            'documentation' => $documentation,
            'slogan' => $data->get('slogan', str($filename)->headline()),
            'compose' => $compose,
            'tags' => $tags,
            'category' => $data->get('category'),
            'logo' => $logo,
            'minversion' => $data->get('minversion', '0.0.0'),
            // Mark as custom template for UI differentiation
            '_source' => $source->name,
            '_source_uuid' => $source->uuid,
        ];

        // Mark ignored templates for UI warning instead of skipping them
        if ($isIgnored) {
            $payload['_ignored'] = true;
        }

        if ($port = $data->get('port')) {
            $payload['port'] = $port;
        }

        // Handle env_file if specified (relative to the compose directory)
        if ($envFile = $data->get('env_file')) {
            try {
                $envUrl = "{$rawBase}/".($folderPath ? "{$folderPath}/" : '').$envFile;
                $envResponse = Http::timeout(15)
                    ->withHeaders(static::buildHeaders($source))
                    ->get($envUrl);

                if ($envResponse->successful()) {
                    $payload['envs'] = base64_encode($envResponse->body());
                }
            } catch (\Throwable $e) {
                Log::warning("TemplateSourceService: Failed to fetch env_file {$envFile} for {$filename}");
            }
        }

        return $payload;
    }

    /**
     * Resolve relative path segments like `../` in a file path.
     */
    protected static function resolveRelativePath(string $path): string
    {
        $parts = explode('/', $path);
        $resolved = [];
        foreach ($parts as $part) {
            if ($part === '..') {
                array_pop($resolved);
            } elseif ($part !== '.' && $part !== '') {
                $resolved[] = $part;
            }
        }

        return implode('/', $resolved);
    }

    /**
     * Save parsed templates to the source's cache file.
     *
     * @param  array<string, array<string, mixed>>  $templates
     */
    protected static function saveCachedTemplates(CustomTemplateSource $source, array $templates): void
    {
        $baseDir = config('corelix-platform.custom_templates.cache_dir', storage_path('app/custom-templates'));
        if (! File::isDirectory($baseDir)) {
            File::makeDirectory($baseDir, 0755, true);
        }

        $dir = $source->getCacheDirectory();
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $json = json_encode($templates, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        File::put($source->getCacheFilePath(), $json);
    }

    /**
     * Load and merge all cached custom templates from enabled sources.
     *
     * Called by the overridden `get_service_templates()` function.
     * Handles name collisions with built-in templates by appending the source name.
     *
     * @param  Collection|null  $builtInTemplates  Optional built-in templates for collision detection
     * @return Collection<string, mixed>
     */
    private static ?Collection $cachedTemplates = null;

    /**
     * Flush the in-memory cache. Call this when templates change
     * or from Octane/queue worker request boundaries.
     */
    public static function flushCache(): void
    {
        static::$cachedTemplates = null;
    }

    public static function getCachedCustomTemplates(?Collection $builtInTemplates = null): Collection
    {
        // Cache within the request to avoid repeated DB queries + file reads
        if (static::$cachedTemplates !== null) {
            return static::$cachedTemplates;
        }

        $sources = CustomTemplateSource::where('enabled', true)->get();
        $merged = collect();

        foreach ($sources as $source) {
            if (! $source->hasCachedTemplates()) {
                continue;
            }

            $templates = $source->loadCachedTemplates();
            foreach ($templates as $key => $template) {
                $finalKey = $key;

                // Resolve name collision with built-in templates
                if ($builtInTemplates && $builtInTemplates->has($key)) {
                    $finalKey = $key.'-'.Str::slug($source->name);
                }

                // Resolve collision with another custom source
                if ($merged->has($finalKey)) {
                    $finalKey = $key.'-'.Str::slug($source->name);
                }

                $merged->put($finalKey, (object) $template);
            }
        }

        static::$cachedTemplates = $merged;

        return $merged;
    }

    /**
     * Validate a repository URL and optional auth token by making a test API call.
     *
     * @return array{valid: bool, error: string|null, file_count: int}
     */
    public static function validateSource(string $repositoryUrl, string $branch, string $folderPath, ?string $authToken = null): array
    {
        $source = new CustomTemplateSource([
            'uuid' => (string) Str::uuid(),
            'name' => 'validation-test',
            'repository_url' => $repositoryUrl,
            'branch' => $branch,
            'folder_path' => $folderPath,
            'auth_token' => $authToken,
        ]);

        if (! $source->hasAllowedRepositoryHost()) {
            return [
                'valid' => false,
                'error' => 'Repository host is not allowed. Allowed hosts: '.implode(', ', CustomTemplateSource::allowedGithubHosts()),
                'file_count' => 0,
            ];
        }

        $parsed = $source->parseRepositoryUrl();
        if (! $parsed) {
            return ['valid' => false, 'error' => 'Invalid repository URL format.', 'file_count' => 0];
        }

        try {
            $files = static::listYamlFiles($source, $parsed);

            return [
                'valid' => true,
                'error' => null,
                'file_count' => count($files),
            ];
        } catch (\Throwable $e) {
            return ['valid' => false, 'error' => $e->getMessage(), 'file_count' => 0];
        }
    }

    /**
     * Delete cached templates for a source.
     */
    public static function deleteCachedTemplates(CustomTemplateSource $source): void
    {
        $dir = $source->getCacheDirectory();
        if (File::isDirectory($dir)) {
            File::deleteDirectory($dir);
        }
    }

    /**
     * Build HTTP headers for GitHub API requests.
     *
     * @return array<string, string>
     */
    protected static function buildHeaders(CustomTemplateSource $source): array
    {
        $headers = [
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'Corelix-Platform',
        ];

        if ($source->auth_token) {
            $headers['Authorization'] = 'Bearer '.$source->auth_token;
        }

        return $headers;
    }
}
