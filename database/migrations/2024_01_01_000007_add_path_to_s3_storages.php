<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add S3 path prefix support (from Coolify PR #7776).
 *
 * Allows configuring a path prefix on S3 storage destinations,
 * useful for separating multiple Coolify instances in a single bucket.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('s3_storages', 'path')) {
            return;
        }

        Schema::table('s3_storages', function (Blueprint $table) {
            $table->string('path')->nullable()->after('endpoint');
        });
    }

    public function down(): void
    {
        Schema::table('s3_storages', function (Blueprint $table) {
            $table->dropColumn('path');
        });
    }
};
