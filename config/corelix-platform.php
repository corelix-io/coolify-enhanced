<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Enable Granular Permissions
    |--------------------------------------------------------------------------
    |
    | When enabled, the granular permission system will be active, requiring
    | explicit project access for members and viewers. When disabled, all
    | team members have access to all projects (default Coolify behavior).
    |
    */
    'enabled' => env('CORELIX_PLATFORM', env('CORELIX_GRANULAR_PERMISSIONS', false)),

    /*
    |--------------------------------------------------------------------------
    | Permission Levels
    |--------------------------------------------------------------------------
    |
    | Define the available permission levels and their capabilities.
    | These can be customized but the keys should remain unchanged.
    |
    */
    'levels' => [
        'view_only' => [
            'view' => true,
            'deploy' => false,
            'manage' => false,
            'delete' => false,
        ],
        'deploy' => [
            'view' => true,
            'deploy' => true,
            'manage' => false,
            'delete' => false,
        ],
        'full_access' => [
            'view' => true,
            'deploy' => true,
            'manage' => true,
            'delete' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Role Bypass
    |--------------------------------------------------------------------------
    |
    | Roles that bypass granular permission checks entirely.
    | These users have full access to all resources in their team.
    |
    */
    'bypass_roles' => ['owner', 'admin'],

    /*
    |--------------------------------------------------------------------------
    | Permission Cascade
    |--------------------------------------------------------------------------
    |
    | When enabled, project permissions cascade to all environments within.
    | Environment-level overrides can still be set for fine-tuning.
    |
    */
    'cascade_permissions' => true,

    /*
    |--------------------------------------------------------------------------
    | Auto-Grant Access
    |--------------------------------------------------------------------------
    |
    | When a new project is created, automatically grant access to these roles.
    | Set to empty array to require explicit access grants for all users.
    |
    */
    'auto_grant_roles' => ['owner', 'admin'],

    /*
    |--------------------------------------------------------------------------
    | Default Permission Level
    |--------------------------------------------------------------------------
    |
    | The default permission level when granting access without specifying one.
    |
    */
    'default_level' => 'view_only',

    /*
    |--------------------------------------------------------------------------
    | Backup Encryption
    |--------------------------------------------------------------------------
    |
    | Configuration for the S3 backup encryption feature.
    | Uses rclone's crypt backend (NaCl SecretBox) for at-rest encryption.
    |
    */
    'backup_encryption' => [
        // The rclone Docker image used for encrypted backup operations
        'rclone_image' => env('CORELIX_RCLONE_IMAGE', 'rclone/rclone:latest'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Network Management
    |--------------------------------------------------------------------------
    |
    | Configuration for Docker network isolation and management.
    | Provides per-environment network isolation, shared networks,
    | dedicated proxy networks, and server-level network management.
    |
    */
    'network_management' => [
        // Enable network management feature
        'enabled' => env('CORELIX_NETWORK_MANAGEMENT', false),

        // Isolation mode: 'none', 'environment', 'strict'
        // - none: no auto-provisioning, manual network management only
        // - environment: auto-create per-environment networks, resources auto-join
        // - strict: same as environment + disconnect from default coolify network
        'isolation_mode' => env('CORELIX_NETWORK_ISOLATION', env('CORELIX_NETWORK_ISOLATION_MODE', 'environment')),

        // Whether to use a dedicated proxy network (opt-in)
        // When enabled, only resources with FQDNs join the proxy network
        'proxy_isolation' => env('CORELIX_PROXY_ISOLATION', false),

        // Maximum managed networks per server (safety limit)
        'max_networks_per_server' => (int) env('CORELIX_MAX_NETWORKS', 200),

        // Network name prefix (avoid collisions with Coolify's naming)
        'prefix' => env('CORELIX_NETWORK_PREFIX', 'ce'),

        // Delay before post-deployment network assignment (seconds)
        'post_deploy_delay' => (int) env('CORELIX_NETWORK_POST_DEPLOY_DELAY', 3),

        // Persist managed-network membership into the compose definition so it
        // survives container recreation (redeploy/reboot). When false, only the
        // ephemeral runtime `docker network connect` + scheduled reconcile are
        // used. Only networks verified to exist are ever injected, so this can
        // never break a deployment.
        'persist_in_compose' => filter_var(env('CORELIX_NETWORK_PERSIST_IN_COMPOSE', true), FILTER_VALIDATE_BOOLEAN),

        // How often (minutes, 1–59) the scheduled membership self-heal runs.
        // Re-verifies live Docker membership for resources with intended
        // memberships, reconnects drift, and corrects stale is_connected state.
        'membership_reconcile_interval' => (int) env('CORELIX_NETWORK_MEMBERSHIP_INTERVAL', 5),

        // Enable inter-node encryption for Swarm overlay networks
        // Uses Docker's --opt encrypted flag (IPsec between Swarm nodes)
        // Only applies to Swarm servers; ignored for standalone Docker
        'swarm_overlay_encryption' => env('CORELIX_SWARM_OVERLAY_ENCRYPTION', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Template Sources
    |--------------------------------------------------------------------------
    |
    | Configuration for custom GitHub template sources.
    | Allows adding external repositories with docker-compose templates
    | that appear in the one-click service list.
    |
    */
    'custom_templates' => [
        // Auto-sync interval (cron expression). Set to null to disable auto-sync.
        'sync_frequency' => env('CORELIX_TEMPLATE_SYNC_FREQUENCY', '0 */6 * * *'),

        // Auto-sync enabled sources when cache is missing (for example after restart).
        'sync_on_startup' => env('CORELIX_TEMPLATE_SYNC_ON_STARTUP', true),

        // Cache directory for fetched templates
        // Follows Coolify's pattern: host /data/coolify/custom-templates is mounted
        // to container /var/www/html/storage/app/custom-templates via docker-compose.custom.yml
        'cache_dir' => env('CORELIX_TEMPLATE_CACHE_DIR', storage_path('app/custom-templates')),

        // Maximum templates per source (safety limit)
        'max_templates_per_source' => 500,

        // GitHub API timeout in seconds
        'github_timeout' => 30,

        // Allowed GitHub hostnames for template sources (comma-separated env override).
        // Prevents SSRF via repository_url pointing at internal or arbitrary hosts.
        'allowed_github_hosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', env('CORELIX_TEMPLATE_GITHUB_HOSTS', 'github.com'))
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | DNS Provider Management
    |--------------------------------------------------------------------------
    |
    | Automatic DNS records and ingress for deployed resources via a DNS provider
    | (Cloudflare Tunnel in v1), including a Corelix-managed cloudflared daemon.
    | The free tier covers a single wildcard domain with reconcile-on-deploy; pro
    | capabilities (multi-domain, per-hostname routing, env bindings, TCP records,
    | scheduled drift sync) are gated by their own feature flags.
    |
    */
    'dns_provider_management' => [
        // Master switch (compile-time flag also gates routes/UI).
        'enabled' => env('CORELIX_DNS_PROVIDER_MANAGEMENT', false),

        // Corelix runs the managed cloudflared daemon container. Set false to self-run.
        'manage_cloudflared' => env('CORELIX_DNS_MANAGE_CLOUDFLARED', true),

        // Pinnable cloudflared image (recommend a digest in production).
        'cloudflared_image' => env('CORELIX_DNS_CLOUDFLARED_IMAGE', 'cloudflare/cloudflared:latest'),

        // Scheduled drift-sync cadence in minutes (pro: FEATURE_DNS_DRIFT_SYNC).
        'sync_interval' => (int) env('CORELIX_DNS_SYNC_INTERVAL', 15),

        // On detected drift: 'reconcile' (silently re-apply) or 'alert_only'. Never auto-deletes.
        'drift_policy' => env('CORELIX_DNS_DRIFT_POLICY', 'alert_only'),

        // Debounce window (seconds) for coalescing co-deploying resources into one per-tunnel rebuild.
        'reconcile_debounce' => (int) env('CORELIX_DNS_RECONCILE_DEBOUNCE', 5),
    ],




    /*
    |--------------------------------------------------------------------------
    | Enhanced UI Theme
    |--------------------------------------------------------------------------
    |
    | Optional corporate-grade modern UI theme (CSS + minimal JS only).
    | When enabled in Settings > Appearance, applies a refined color palette
    | and light/dark modes. Disabled by default; runtime value from DB.
    |
    */
    'ui_theme' => [
        'default' => env('CORELIX_PLATFORM_UI_THEME', null),
        'themes' => [
            'enhanced' => [
                'label' => 'Enhanced (Linear)',
                'description' => 'Deep neutrals, crisp borders, restrained accent usage. Inspired by Linear.',
                'css' => 'themes/enhanced.css',
                'js' => null,
                'font_label' => null,
            ],
            'tailadmin' => [
                'label' => 'TailAdmin',
                'description' => 'Clean enterprise dashboard with brand blues, warm grays, and polished form controls. Inspired by TailAdmin.',
                'css' => 'themes/tailadmin.css',
                'js' => 'themes/tailadmin.js',
                'font_label' => 'Outfit',
            ],
        ],
    ],
];
