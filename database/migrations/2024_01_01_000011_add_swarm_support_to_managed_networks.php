<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add Swarm support columns to managed_networks table.
 *
 * Phase 3: Extends the network management system with Docker Swarm
 * overlay network support, including inter-node encryption.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('managed_networks') && ! Schema::hasColumn('managed_networks', 'is_encrypted_overlay')) {
            Schema::table('managed_networks', function (Blueprint $table) {
                // Whether to enable inter-node encryption for overlay networks
                // Uses Docker's --opt encrypted flag (IPsec encryption)
                $table->boolean('is_encrypted_overlay')->default(false)->after('is_proxy_network');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('managed_networks') && Schema::hasColumn('managed_networks', 'is_encrypted_overlay')) {
            Schema::table('managed_networks', function (Blueprint $table) {
                $table->dropColumn('is_encrypted_overlay');
            });
        }
    }
};
