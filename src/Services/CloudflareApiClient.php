<?php

namespace CorelixIo\Platform\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP client for the Cloudflare API v4. Runs from the PHP host (like RegistryService's
 * connection test), NOT via SSH. All calls use a Bearer API token.
 *
 * Rate limit awareness (findings §6.5): Cloudflare enforces 1200 req / 5 min per user,
 * account-wide; a 429 returns Retry-After. Callers (DnsReconcileJob) must debounce per tunnel
 * and honour Retry-After — this client surfaces the raw Response so callers can react.
 */
class CloudflareApiClient
{
    public const BASE_URL = 'https://api.cloudflare.com/client/v4';

    public function __construct(
        protected string $apiToken,
        protected int $timeout = 15,
    ) {}

    protected function request(): PendingRequest
    {
        return Http::withToken($this->apiToken)
            ->acceptJson()
            ->timeout($this->timeout)
            ->retry(2, 200, throw: false);
    }

    public function get(string $path, array $query = []): Response
    {
        return $this->request()->get(self::BASE_URL.$path, $query);
    }

    public function post(string $path, array $body = []): Response
    {
        return $this->request()->post(self::BASE_URL.$path, $body);
    }

    public function put(string $path, array $body = []): Response
    {
        return $this->request()->put(self::BASE_URL.$path, $body);
    }

    public function patch(string $path, array $body = []): Response
    {
        return $this->request()->patch(self::BASE_URL.$path, $body);
    }

    public function delete(string $path): Response
    {
        return $this->request()->delete(self::BASE_URL.$path);
    }

    /**
     * Verify the API token is active (GET /user/tokens/verify).
     *
     * @return array{success: bool, error: ?string}
     */
    public function verifyToken(): array
    {
        try {
            $res = $this->get('/user/tokens/verify');
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Network error: '.$e->getMessage()];
        }

        if ($res->status() === 401 || $res->status() === 403) {
            return ['success' => false, 'error' => 'Invalid or unauthorized API token.'];
        }

        $status = data_get($res->json(), 'result.status');
        if ($res->successful() && $status === 'active') {
            return ['success' => true, 'error' => null];
        }

        return ['success' => false, 'error' => $this->firstError($res) ?? 'Token is not active.'];
    }

    /**
     * List Cloudflare Tunnels for an account — confirms account_id + tunnel scope.
     *
     * @return array{success: bool, error: ?string}
     */
    public static function validateAccountId(string $accountId): bool
    {
        return (bool) preg_match('/^[0-9a-f]{32}$/i', $accountId);
    }

    public function listTunnels(string $accountId): array
    {
        if ($accountId === '') {
            return ['success' => false, 'error' => 'Account ID is required.'];
        }

        if (! self::validateAccountId($accountId)) {
            return ['success' => false, 'error' => 'Invalid Cloudflare Account ID format.'];
        }

        try {
            $res = $this->get('/accounts/'.rawurlencode($accountId).'/cfd_tunnel', ['per_page' => 1]);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Network error: '.$e->getMessage()];
        }

        if ($res->successful()) {
            return ['success' => true, 'error' => null];
        }

        if (in_array($res->status(), [401, 403], true)) {
            return ['success' => false, 'error' => 'Token lacks Account · Cloudflare Tunnel access (or wrong Account ID).'];
        }

        return ['success' => false, 'error' => $this->firstError($res) ?? "Tunnel list failed (HTTP {$res->status()})."];
    }

    /**
     * Find a remotely-managed tunnel by name (excluding deleted tunnels).
     *
     * @return array{success: bool, error: ?string, tunnel: ?array}
     */
    public function findTunnelByName(string $accountId, string $name): array
    {
        try {
            $res = $this->get('/accounts/'.rawurlencode($accountId).'/cfd_tunnel', [
                'name' => $name,
                'is_deleted' => 'false',
                'per_page' => 1,
            ]);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Network error: '.$e->getMessage(), 'tunnel' => null];
        }

        if (! $res->successful()) {
            return ['success' => false, 'error' => $this->firstError($res) ?? "Tunnel lookup failed (HTTP {$res->status()}).", 'tunnel' => null];
        }

        return ['success' => true, 'error' => null, 'tunnel' => data_get($res->json(), 'result.0')];
    }

    /**
     * Create a remotely-managed tunnel (config_src=cloudflare). Response includes id + token.
     *
     * @return array{success: bool, error: ?string, tunnel: ?array}
     */
    public function createTunnel(string $accountId, string $name): array
    {
        try {
            $res = $this->post('/accounts/'.rawurlencode($accountId).'/cfd_tunnel', [
                'name' => $name,
                'config_src' => 'cloudflare',
            ]);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Network error: '.$e->getMessage(), 'tunnel' => null];
        }

        if (! $res->successful()) {
            return ['success' => false, 'error' => $this->firstError($res) ?? "Tunnel create failed (HTTP {$res->status()}).", 'tunnel' => null];
        }

        return ['success' => true, 'error' => null, 'tunnel' => data_get($res->json(), 'result')];
    }

    /**
     * Fetch the tunnel token used to run cloudflared (remotely-managed tunnels).
     *
     * @return array{success: bool, error: ?string, token: ?string}
     */
    public function getTunnelToken(string $accountId, string $tunnelId): array
    {
        try {
            $res = $this->get('/accounts/'.rawurlencode($accountId).'/cfd_tunnel/'.rawurlencode($tunnelId).'/token');
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Network error: '.$e->getMessage(), 'token' => null];
        }

        if (! $res->successful()) {
            return ['success' => false, 'error' => $this->firstError($res) ?? "Token fetch failed (HTTP {$res->status()}).", 'token' => null];
        }

        $token = data_get($res->json(), 'result');
        if (! is_string($token) || $token === '') {
            return ['success' => false, 'error' => 'Cloudflare returned an empty tunnel token.', 'token' => null];
        }

        return ['success' => true, 'error' => null, 'token' => $token];
    }

    /**
     * Get the remotely-managed tunnel configuration (config.ingress array).
     *
     * @return array{success: bool, error: ?string, config: ?array}
     */
    public function getTunnelConfig(string $accountId, string $tunnelId): array
    {
        try {
            $res = $this->get('/accounts/'.rawurlencode($accountId).'/cfd_tunnel/'.rawurlencode($tunnelId).'/configurations');
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Network error: '.$e->getMessage(), 'config' => null];
        }

        if (! $res->successful()) {
            return ['success' => false, 'error' => $this->firstError($res) ?? "Config fetch failed (HTTP {$res->status()}).", 'config' => null];
        }

        return ['success' => true, 'error' => null, 'config' => data_get($res->json(), 'result.config') ?? []];
    }

    /**
     * Replace the remotely-managed tunnel configuration. PUT semantics are FULL REPLACEMENT —
     * callers must pass the complete ingress list with the catch-all rule LAST.
     *
     * @return array{success: bool, error: ?string}
     */
    public function putTunnelConfig(string $accountId, string $tunnelId, array $config): array
    {
        $ingress = $config['ingress'] ?? [];
        $last = end($ingress);
        if (empty($ingress) || isset($last['hostname']) || ($last['service'] ?? null) !== 'http_status:404') {
            return ['success' => false, 'error' => 'Refusing to PUT tunnel config without a final http_status:404 catch-all rule.'];
        }

        try {
            $res = $this->put('/accounts/'.rawurlencode($accountId).'/cfd_tunnel/'.rawurlencode($tunnelId).'/configurations', [
                'config' => $config,
            ]);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Network error: '.$e->getMessage()];
        }

        if (! $res->successful()) {
            return ['success' => false, 'error' => $this->firstError($res) ?? "Config replace failed (HTTP {$res->status()})."];
        }

        return ['success' => true, 'error' => null];
    }

    /** @var array<string, ?string> per-instance zone id memo (avoids re-resolving per record op) */
    protected array $zoneIdCache = [];

    /**
     * Resolve a zone_id for a base domain (registrable zone). Returns null if not found / no access.
     * Memoized per client instance — DNS record operations would otherwise re-resolve every call.
     */
    public function resolveZoneId(string $baseDomain): ?string
    {
        if (array_key_exists($baseDomain, $this->zoneIdCache)) {
            return $this->zoneIdCache[$baseDomain];
        }

        return $this->zoneIdCache[$baseDomain] = $this->lookupZoneId($baseDomain);
    }

    protected function lookupZoneId(string $baseDomain): ?string
    {
        // The zone is the registrable domain; try the full base first, then progressively shorter
        // suffixes (apps.example.com → example.com) so subdomain base_domains still resolve.
        $labels = explode('.', $baseDomain);
        for ($i = 0; $i < count($labels) - 1; $i++) {
            $candidate = implode('.', array_slice($labels, $i));
            try {
                $res = $this->get('/zones', ['name' => $candidate, 'per_page' => 1]);
            } catch (\Throwable) {
                return null;
            }
            if ($res->successful()) {
                $id = data_get($res->json(), 'result.0.id');
                if (! empty($id)) {
                    return $id;
                }
            }
        }

        return null;
    }

    protected function firstError(Response $res): ?string
    {
        return data_get($res->json(), 'errors.0.message');
    }
}
