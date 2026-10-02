<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create table for custom GitHub template sources.
 *
 * Stores external GitHub repositories that provide additional
 * docker-compose service templates for the one-click service list.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('custom_template_sources')) {
            Schema::create('custom_template_sources', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->string('name');
                $table->string('repository_url');
                $table->string('branch')->default('main');
                $table->string('folder_path')->default('templates/compose');
                $table->text('auth_token')->nullable();
                $table->boolean('enabled')->default(true);
                $table->timestamp('last_synced_at')->nullable();
                $table->string('last_sync_status')->nullable();
                $table->text('last_sync_error')->nullable();
                $table->unsignedInteger('template_count')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_template_sources');
    }
};
