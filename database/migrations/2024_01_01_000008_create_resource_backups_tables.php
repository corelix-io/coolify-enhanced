<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create tables for resource backups (volumes, configuration, full, coolify instance).
 *
 * This extends Coolify's backup system beyond databases to support
 * backing up Docker volumes, resource configuration, full backups,
 * and the entire Coolify installation.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scheduled_resource_backups')) {
            Schema::create('scheduled_resource_backups', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('description')->nullable();
                $table->boolean('enabled')->default(true);

                // Backup type: 'volume', 'configuration', 'full', 'coolify_instance'
                $table->string('backup_type')->default('volume');

                // Polymorphic: Application, Service, or standalone Database
                // Nullable for coolify_instance backups (no specific resource)
                $table->string('resource_type')->default('');
                $table->unsignedBigInteger('resource_id')->default(0);

                // S3 storage settings
                $table->boolean('save_s3')->default(true);
                $table->boolean('disable_local_backup')->default(false);
                $table->unsignedBigInteger('s3_storage_id')->nullable();

                // Schedule
                $table->string('frequency')->default('0 2 * * *');
                $table->string('timezone')->nullable();
                $table->integer('timeout')->default(3600);

                // Retention settings (local)
                $table->integer('retention_amount_locally')->default(0);
                $table->integer('retention_days_locally')->default(0);
                $table->decimal('retention_max_storage_locally', 17, 7)->default(0);

                // Retention settings (S3)
                $table->integer('retention_amount_s3')->default(0);
                $table->integer('retention_days_s3')->default(0);
                $table->decimal('retention_max_storage_s3', 17, 7)->default(0);

                $table->unsignedBigInteger('team_id');
                $table->timestamps();

                $table->index(['resource_type', 'resource_id']);
                $table->foreign('s3_storage_id')->references('id')->on('s3_storages')->nullOnDelete();
                $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('scheduled_resource_backup_executions')) {
            Schema::create('scheduled_resource_backup_executions', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();

                // Which type this execution covers
                $table->string('backup_type')->default('volume');

                $table->enum('status', ['success', 'failed', 'running'])->default('running');
                $table->longText('message')->nullable();
                $table->text('size')->nullable();
                $table->text('filename')->nullable();

                // Human-readable label (e.g., volume name, 'configuration', 'full')
                $table->string('backup_label')->nullable();

                $table->boolean('is_encrypted')->default(false);
                $table->boolean('s3_uploaded')->nullable();
                $table->boolean('local_storage_deleted')->default(false);
                $table->boolean('s3_storage_deleted')->default(false);

                $table->unsignedBigInteger('scheduled_resource_backup_id');
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->foreign('scheduled_resource_backup_id', 'resource_backup_exec_backup_fk')
                    ->references('id')
                    ->on('scheduled_resource_backups')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_resource_backup_executions');
        Schema::dropIfExists('scheduled_resource_backups');
    }
};
