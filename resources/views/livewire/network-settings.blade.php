<div>
    <x-slot:title>
        Settings | Coolify
    </x-slot>
    <x-settings.navbar />

    <div class="flex flex-col">
        <div class="flex items-center gap-2 pb-2">
            <h2>Networks</h2>
        </div>
        <div class="pb-4">Configure Docker network isolation and management policies.</div>

        <div class="flex flex-col gap-4">
        {{-- Current status --}}
        <div class="p-4 bg-white dark:bg-coolgray-100 rounded border border-neutral-200 dark:border-transparent">
            <h3 class="pb-2">Current Configuration</h3>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <div class="text-xs text-neutral-600 dark:text-neutral-400">Network Management</div>
                    <div class="font-bold {{ $networkManagementEnabled ? 'text-success' : 'text-warning' }}">
                        {{ $networkManagementEnabled ? 'Enabled' : 'Disabled' }}
                    </div>
                </div>
                <div>
                    <div class="text-xs text-neutral-600 dark:text-neutral-400">Isolation Mode</div>
                    <div class="font-bold">{{ ucfirst($isolationMode) }}</div>
                </div>
                <div>
                    <div class="text-xs text-neutral-600 dark:text-neutral-400">Proxy Isolation</div>
                    <div class="font-bold {{ $proxyIsolation ? 'text-success' : 'text-neutral-600 dark:text-neutral-400' }}">
                        {{ $proxyIsolation ? 'Enabled' : 'Disabled' }}
                    </div>
                </div>
                <div>
                    <div class="text-xs text-neutral-600 dark:text-neutral-400">Max Networks per Server</div>
                    <div class="font-bold">{{ $maxNetworksPerServer }}</div>
                </div>
            </div>
        </div>

        {{-- Environment variable configuration guide --}}
        <div class="p-4 bg-white dark:bg-coolgray-100 rounded border border-neutral-200 dark:border-transparent">
            <h3 class="pb-2">Configuration</h3>
            <div class="text-sm text-neutral-600 dark:text-neutral-300">
                Network management is configured via environment variables in your <code class="text-warning">.env</code> file:
            </div>
            <div class="mt-2 p-3 bg-neutral-100 dark:bg-coolgray-200 rounded font-mono text-xs border border-neutral-200 dark:border-transparent">
                <div># Enable network management</div>
                <div>CORELIX_NETWORK_MANAGEMENT=true</div>
                <div class="mt-2"># Isolation mode: none, environment, strict</div>
                <div>CORELIX_NETWORK_ISOLATION=environment</div>
                <div># Backward-compatible alias:</div>
                <div>CORELIX_NETWORK_ISOLATION_MODE=environment</div>
                <div class="mt-2"># Enable dedicated proxy network (opt-in)</div>
                <div>CORELIX_PROXY_ISOLATION=false</div>
                <div class="mt-2"># Maximum networks per server</div>
                <div>CORELIX_MAX_NETWORKS=200</div>
            </div>
        </div>

        {{-- Mode descriptions --}}
        <div class="p-4 bg-white dark:bg-coolgray-100 rounded border border-neutral-200 dark:border-transparent">
            <h3 class="pb-2">Isolation Modes</h3>
            <div class="flex flex-col gap-3 text-sm text-neutral-700 dark:text-neutral-300">
                <div>
                    <span class="font-bold text-warning">none</span> — No auto-provisioning. Networks can only be created and managed manually. Resources stay on Coolify's default network.
                </div>
                <div>
                    <span class="font-bold text-blue-600 dark:text-blue-400">environment</span> — Each environment gets its own Docker network. Resources auto-join their environment network after deployment. Cross-environment communication requires shared networks.
                </div>
                <div>
                    <span class="font-bold text-error">strict</span> — Same as <code>environment</code>, but also disconnects resources from the default <code>coolify</code> network. Maximum isolation, but may break services that rely on the default network.
                </div>
            </div>
        </div>

        {{-- Proxy isolation --}}
        <div class="p-4 bg-white dark:bg-coolgray-100 rounded border border-neutral-200 dark:border-transparent">
            <h3 class="pb-2">Proxy Network Isolation</h3>
            <div class="text-sm text-neutral-600 dark:text-neutral-300 pb-3">
                When enabled, resources with FQDNs join a dedicated proxy network (<code class="text-purple-600 dark:text-purple-400">ce-proxy-{'{server_uuid}'}</code>)
                instead of sharing the default <code>coolify</code> network with all containers.
            </div>
            <div class="flex flex-col gap-3 text-sm">
                <div>
                    <span class="font-bold text-purple-600 dark:text-purple-400">How it works:</span>
                    <ul class="list-disc list-inside text-neutral-600 dark:text-neutral-400 mt-1 space-y-1">
                        <li>A dedicated proxy network is created per server</li>
                        <li>The reverse proxy (Traefik/Caddy) joins this network</li>
                        <li>Resources with FQDNs auto-join the proxy network on deployment</li>
                        <li><code>traefik.docker.network</code> labels ensure consistent routing (no random 502s)</li>
                        <li>Internal services without FQDNs remain invisible to the proxy</li>
                    </ul>
                </div>
                <div>
                    <span class="font-bold text-warning">Migration:</span>
                    <span class="text-neutral-600 dark:text-neutral-400">After enabling, go to Server > Networks and run "Proxy Migration" to connect existing resources. New deployments auto-join.</span>
                </div>
            </div>
        </div>

        {{-- Swarm overlay encryption --}}
        <div class="p-4 bg-white dark:bg-coolgray-100 rounded border border-neutral-200 dark:border-transparent">
            <h3 class="pb-2">Swarm Overlay Encryption</h3>
            <div class="text-sm text-neutral-600 dark:text-neutral-300 pb-3">
                On Docker Swarm servers, networks automatically use the <code class="text-cyan-600 dark:text-cyan-400">overlay</code> driver instead of <code>bridge</code>.
                This enables multi-host communication between Swarm nodes.
            </div>
            <div class="flex flex-col gap-3 text-sm">
                <div>
                    <span class="font-bold text-cyan-600 dark:text-cyan-400">Overlay networks:</span>
                    <span class="text-neutral-600 dark:text-neutral-400">Created automatically for Swarm servers. All managed networks (environment, shared, proxy) use the overlay driver.</span>
                </div>
                <div>
                    <span class="font-bold text-yellow-600 dark:text-yellow-400">Inter-node encryption:</span>
                    <span class="text-neutral-600 dark:text-neutral-400">Optional <code>--opt encrypted</code> flag encrypts traffic between Swarm nodes using IPsec. Adds ~5-10% overhead.</span>
                </div>
                <div>
                    <span class="font-bold text-warning">Service updates:</span>
                    <span class="text-neutral-600 dark:text-neutral-400">Network changes on Swarm trigger a zero-downtime rolling update via <code>docker service update --network-add</code>.</span>
                </div>
            </div>
            <div class="mt-3 p-3 bg-neutral-100 dark:bg-coolgray-200 rounded font-mono text-xs border border-neutral-200 dark:border-transparent">
                <div># Enable default encryption for new overlay networks</div>
                <div>CORELIX_SWARM_OVERLAY_ENCRYPTION=true</div>
            </div>
        </div>
        </div>
    </div>
</div>
