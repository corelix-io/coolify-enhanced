<?php

namespace CorelixIo\Platform\Support;

use Illuminate\Support\Facades\Log;

/**
 * Audit-trail hook for mutating DNS operations (Wave 7, T7.3).
 *
 * Ships in the FREE tier as inert plumbing: every mutating DNS op (provider/domain CRUD,
 * provisioning, reconcile-affecting actions, orphan adoption, access policies) calls
 * DnsAudit::record(). Until the Audit Trail pro feature ships an AuditTrailService,
 * the call is a guaranteed no-op — it never throws, never logs above debug, and adds
 * no measurable overhead.
 *
 * Contract for the future sink (AUDIT_TRAIL feature):
 *   \CorelixIo\Platform\Services\AuditTrailService::record(string $event, array $context): void
 *
 * Event names are namespaced "dns.<entity>.<verb>", e.g. "dns.provider.created",
 * "dns.domain.deleted", "dns.hostname.adopted". Context is sanitized: credential-like
 * keys are masked before they ever reach a sink.
 */
class DnsAudit
{
    /** Context keys that must never reach an audit sink in clear text. */
    protected const SENSITIVE_KEYS = [
        'credentials', 'api_token', 'api_key', 'token', 'password', 'secret',
    ];

    /**
     * Record a mutating DNS operation. Inert (guaranteed no-throw no-op) until the
     * Audit Trail feature ships its service class AND is enabled at runtime.
     */
    public static function record(string $action, array $context = []): void
    {
        try {
            $sink = '\\CorelixIo\\Platform\\Services\\AuditTrailService';

            if (! class_exists($sink) || ! Feature::enabled('AUDIT_TRAIL')) {
                return; // Audit Trail not shipped/enabled — hook stays inert.
            }

            $sink::record('dns.'.$action, static::sanitize($context) + static::actor());
        } catch (\Throwable $e) {
            // An audit failure must never break the operation being audited.
            Log::debug('DnsAudit: sink failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Mask credential-like values (recursively) so secrets never reach a sink.
     */
    public static function sanitize(array $context): array
    {
        $clean = [];

        foreach ($context as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $clean[$key] = '***';
            } elseif (is_array($value)) {
                $clean[$key] = static::sanitize($value);
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /**
     * Current actor (user) when the mutation happens in an authenticated context;
     * background jobs record as system.
     *
     * @return array{actor_id: int|null, actor_type: string}
     */
    protected static function actor(): array
    {
        try {
            $userId = auth()->id();
        } catch (\Throwable) {
            $userId = null;
        }

        return [
            'actor_id' => $userId,
            'actor_type' => $userId ? 'user' : 'system',
        ];
    }
}
