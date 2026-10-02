<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create tables for the network management system.
 *
 * managed_networks: Tracks Docker networks managed by the addon,
 * with support for environment, project, shared, proxy, and system scopes.
 *
 * resource_networks: Polymorphic pivot linking resources (Application, Service,
 * Database) to managed networks, with DNS aliases and static IP assignments.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('managed_networks')) {
            Schema::create('managed_networks', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->string('name');
                $table->string('docker_network_name');

                $table->foreignId('server_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('team_id');

                // Docker driver: bridge, overlay, macvlan
                $table->string('driver', 50)->default('bridge');

                // Scope: environment, project, shared, proxy, system
                $table->string('scope');

                // Set for project-scoped networks
                $table->unsignedBigInteger('project_id')->nullable();

                // Set for environment-scoped networks
                $table->unsignedBigInteger('environment_id')->nullable();

                // Network addressing
                $table->string('subnet', 50)->nullable();
                $table->string('gateway', 50)->nullable();

                // Docker network flags
                $table->boolean('is_internal')->default(false);
                $table->boolean('is_attachable')->default(true);
                $table->boolean('is_proxy_network')->default(false);

                // Driver-specific options and Docker labels
                $table->json('options')->nullable();
                $table->json('labels')->nullable();

                // Docker network ID (sha256)
                $table->string('docker_id')->nullable();

                // Status: active, pending, error, orphaned
                $table->string('status', 50)->default('pending');
                $table->text('error_message')->nullable();
                $table->timestamp('last_synced_at')->nullable();

                $table->timestamps();

                $table->unique(['docker_network_name', 'server_id']);
                $table->index('environment_id');
                $table->index('project_id');
                $table->index('scope');

                $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('resource_networks')) {
            Schema::create('resource_networks', function (Blueprint $table) {
                $table->id();

                // Polymorphic: Application, Service, standalone Database, etc.
                $table->string('resource_type');
                $table->unsignedBigInteger('resource_id');

                $table->foreignId('managed_network_id')->constrained()->cascadeOnDelete();

                // DNS aliases for this resource on this network
                $table->json('aliases')->nullable();

                // Static IP assignment (optional)
                $table->string('ipv4_address', 50)->nullable();

                // Auto-attached vs manually assigned
                $table->boolean('is_auto_attached')->default(false);

                // Actual Docker connection state
                $table->boolean('is_connected')->default(false);
                $table->timestamp('connected_at')->nullable();

                $table->timestamps();

                $table->unique(['resource_type', 'resource_id', 'managed_network_id'], 'resource_networks_unique');
                $table->index(['resource_type', 'resource_id']);
                $table->index('managed_network_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_networks');
        Schema::dropIfExists('managed_networks');
    }
};
