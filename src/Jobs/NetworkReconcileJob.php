<?php

namespace CorelixIo\Platform\Jobs;

use CorelixIo\Platform\Services\NetworkService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NetworkReconcileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [5, 15, 30];

    public $timeout = 120;

    public function __construct(
        public $resource,
        public bool $fullReconcile = false,
        public bool $membershipOnly = false
    ) {}

    /**
     * Prevent overlapping reconciliation for the same resource.
     */
    public function middleware(): array
    {
        $prefix = match (true) {
            $this->membershipOnly => 'network-reconcile-membership-',
            $this->fullReconcile => 'network-reconcile-server-',
            default => 'network-reconcile-',
        };

        $key = $prefix.get_class($this->resource).'-'.$this->resource->id;

        // releaseAfter is the lock's fail-safe TTL. It MUST exceed the job
        // timeout, otherwise a run that legitimately takes up to $timeout
        // releases its lock early and the next scheduled dispatch can start a
        // second concurrent run against the same server (compounding remote-exec
        // load). Keep it comfortably above $this->timeout.
        return [
            (new WithoutOverlapping($key))->releaseAfter($this->timeout + 60),
        ];
    }

    public function handle(): void
    {
        // Safety: exit if feature disabled
        if (! config('corelix-platform.enabled', false) || ! config('corelix-platform.network_management.enabled', false)) {
            return;
        }

        try {
            if ($this->resource instanceof \App\Models\Server) {
                if ($this->membershipOnly) {
                    // Lightweight scheduled self-heal: only re-verify + reconnect
                    // resources that already have intended memberships.
                    if (config('corelix-platform.network_management.isolation_mode', 'none') !== 'none') {
                        NetworkService::reconcileServerMembership($this->resource);
                    }

                    return;
                }

                $this->reconcileServerWide($this->resource);

                return;
            }

            if ($this->fullReconcile) {
                $this->reconcileServer();
            } else {
                $this->reconcileResource();
            }
        } catch (\Throwable $e) {
            Log::error('NetworkReconcileJob: Failed', [
                'resource' => get_class($this->resource).'#'.$this->resource->id,
                'error' => $e->getMessage(),
            ]);
            throw $e; // Let the queue retry
        }
    }

    protected function reconcileResource(): void
    {
        $server = NetworkService::getServerForResource($this->resource);
        if (! $server || ! $server->isFunctional()) {
            Log::warning('NetworkReconcileJob: Server not functional, skipping');

            return;
        }

        $environment = NetworkService::getEnvironmentForResource($this->resource);
        if (! $environment) {
            Log::warning('NetworkReconcileJob: No environment found for resource');

            return;
        }

        if (config('corelix-platform.network_management.isolation_mode', 'none') === 'none') {
            return;
        }

        // [CORELIX ENHANCED: delegate to NetworkService::reconcileResource() — the
        // previous duplicated standalone/Swarm implementations here had drifted
        // (proxy pivot rows were written is_connected=true without verifying the
        // connect, and the proxy container was never attached to the proxy network).]
        NetworkService::reconcileResource($this->resource);

        Log::info('NetworkReconcileJob: Reconciled resource', [
            'resource' => get_class($this->resource).'#'.$this->resource->id,
            'swarm' => NetworkService::isSwarmServer($server),
        ]);
    }

    protected function reconcileServer(): void
    {
        $server = NetworkService::getServerForResource($this->resource);
        if (! $server) {
            return;
        }

        $this->reconcileServerWide($server);
    }

    /**
     * Full server reconciliation: sync network records, adopt existing resources,
     * and connect the proxy container when proxy isolation is enabled.
     */
    protected function reconcileServerWide(\App\Models\Server $server): void
    {
        if (! $server->isFunctional()) {
            Log::warning('NetworkReconcileJob: Server not functional, skipping server-wide reconcile');

            return;
        }

        NetworkService::reconcileServer($server);

        if (config('corelix-platform.network_management.isolation_mode', 'none') !== 'none') {
            NetworkService::reconcileExistingServerResources($server);
        }

        if (config('corelix-platform.network_management.proxy_isolation', false)) {
            NetworkService::connectProxyContainer($server);
        }

        Log::info('NetworkReconcileJob: Full server reconciliation complete', [
            'server' => $server->name,
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        Log::channel('scheduled-errors')->error('NetworkReconcileJob permanently failed', [
            'job' => 'NetworkReconcileJob',
            'resource' => get_class($this->resource).'#'.$this->resource->id,
            'error' => $exception?->getMessage(),
        ]);
    }
}
