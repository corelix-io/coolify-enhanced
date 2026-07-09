<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add foreign key constraints to managed_networks.project_id and environment_id.
 *
 * These columns existed since 000010 but lacked FK constraints,
 * allowing orphaned references when projects/environments are deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('managed_networks')) {
            return;
        }

        Schema::table('managed_networks', function (Blueprint $table) {
            // Nullify the reference when the project/environment is deleted
            // rather than cascade-deleting the network (which could disrupt running containers)
            if (Schema::hasColumn('managed_networks', 'project_id')) {
                $table->foreign('project_id')
                    ->references('id')
                    ->on('projects')
                    ->nullOnDelete();
            }

            if (Schema::hasColumn('managed_networks', 'environment_id')) {
                $table->foreign('environment_id')
                    ->references('id')
                    ->on('environments')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('managed_networks')) {
            return;
        }

        Schema::table('managed_networks', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropForeign(['environment_id']);
        });
    }
};
