<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DNS Provider Management — foundation tables (free tier).
 *
 * dns_providers       : team-level DNS/ingress provider configs (encrypted credentials).
 * cloudflare_tunnels  : a remotely-managed Cloudflare Tunnel + its managed cloudflared daemon.
 * domains             : a managed base domain (suffix) owned by a provider/tunnel.
 * domain_server       : which domain drives a server's ServerSetting.wildcard_domain.
 * managed_hostnames   : polymorphic per-resource hostname reconciliation state.
 *
 * The Pro env-binding table (domain_environment_bindings) ships in a later, file-excluded migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dns_providers')) {
            Schema::create('dns_providers', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->unsignedBigInteger('team_id');

                $table->string('name');
                $table->string('type', 50); // cloudflare_tunnel (more drivers later)
                $table->text('credentials'); // encrypted JSON: api_token, account_id, ...

                $table->boolean('is_active')->default(true);

                $table->timestamp('last_tested_at')->nullable();
                $table->string('last_test_status', 50)->nullable(); // success|failed
                $table->text('last_test_error')->nullable();

                $table->timestamps();

                $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
                $table->index('team_id');
                $table->index('type');
            });
        }

        if (! Schema::hasTable('cloudflare_tunnels')) {
            Schema::create('cloudflare_tunnels', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->foreignId('dns_provider_id')->constrained('dns_providers')->cascadeOnDelete();

                $table->string('name');
                $table->string('cf_tunnel_id')->nullable();      // Cloudflare tunnel UUID
                $table->text('credentials')->nullable();          // encrypted JSON: tunnel token
                $table->string('cname_target')->nullable();       // <cf_tunnel_id>.cfargotunnel.com

                // Managed cloudflared daemon (D2 — Corelix-managed from v1)
                $table->boolean('managed_daemon')->default(true);
                $table->unsignedBigInteger('daemon_server_id')->nullable();
                $table->string('daemon_status', 50)->default('pending'); // pending|running|error|stopped
                $table->text('daemon_error')->nullable();

                $table->string('status', 50)->default('pending'); // pending|active|error
                $table->timestamp('config_synced_at')->nullable();

                $table->timestamps();

                $table->foreign('daemon_server_id')->references('id')->on('servers')->nullOnDelete();
                // Protects ensureTunnel()'s firstOrCreate against concurrent provisioning races.
                $table->unique(['dns_provider_id', 'name']);
                $table->index('cf_tunnel_id');
            });
        }

        if (! Schema::hasTable('domains')) {
            Schema::create('domains', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->unsignedBigInteger('team_id');
                $table->foreignId('dns_provider_id')->constrained('dns_providers')->cascadeOnDelete();
                $table->foreignId('cloudflare_tunnel_id')->nullable()
                    ->constrained('cloudflare_tunnels')->nullOnDelete();

                $table->string('base_domain'); // BARE suffix, no scheme / no '*.' (apps.example.com)
                $table->string('routing_mode', 20)->default('wildcard'); // wildcard|per_hostname|hybrid (pro modes)
                $table->string('tls_mode', 20)->default('edge');
                $table->string('default_ingress_target')->nullable(); // e.g. http://traefik:80
                $table->boolean('is_default')->default(false); // team fallback when no env binding
                $table->boolean('is_active')->default(true);

                $table->timestamps();

                $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
                $table->unique(['team_id', 'base_domain']);
                $table->index('dns_provider_id');
            });
        }

        if (! Schema::hasTable('domain_server')) {
            Schema::create('domain_server', function (Blueprint $table) {
                $table->id();
                $table->foreignId('domain_id')->constrained('domains')->cascadeOnDelete();
                $table->foreignId('server_id')->constrained()->cascadeOnDelete();

                // This domain syncs ServerSetting.wildcard_domain for the server (T0.1 / D3).
                $table->boolean('is_default_wildcard')->default(false);
                $table->string('last_synced_wildcard')->nullable(); // last value WE wrote (safe-revert guard)

                $table->timestamps();

                $table->unique(['domain_id', 'server_id']);
                $table->index('server_id');
            });
        }

        if (! Schema::hasTable('managed_hostnames')) {
            Schema::create('managed_hostnames', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();

                $table->string('resource_type'); // morphTo: Application | ServiceApplication | Standalone*
                $table->unsignedBigInteger('resource_id');

                $table->foreignId('domain_id')->constrained('domains')->cascadeOnDelete();
                $table->foreignId('dns_provider_id')->constrained('dns_providers')->cascadeOnDelete();

                $table->string('hostname'); // concrete host, lowercased, no scheme/port
                $table->string('binding_source', 20)->default('suffix_match'); // override|env_binding|suffix_match
                $table->string('record_kind', 20)->default('http_tunnel');     // http_tunnel|tcp_dns
                $table->string('sync_state', 20)->default('pending');          // synced|pending|error|drifted|unmanaged

                $table->string('provider_record_id')->nullable();   // Zone DNS record id
                $table->string('provider_ingress_ref')->nullable(); // tunnel ingress identifier/index

                $table->timestamp('last_synced_at')->nullable();
                $table->text('last_error')->nullable();

                $table->timestamps();

                // record_kind included: the same hostname may exist as http_tunnel AND tcp_dns rows.
                $table->unique(['resource_type', 'resource_id', 'hostname', 'record_kind'], 'managed_hostnames_resource_host_kind_unique');
                $table->index(['resource_type', 'resource_id']);
                $table->index('domain_id');
                $table->index('sync_state');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_hostnames');
        Schema::dropIfExists('domain_server');
        Schema::dropIfExists('domains');
        Schema::dropIfExists('cloudflare_tunnels');
        Schema::dropIfExists('dns_providers');
    }
};
