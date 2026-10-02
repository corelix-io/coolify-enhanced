<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('service_databases', 'proxy_ports')) {
            Schema::table('service_databases', function (Blueprint $table) {
                $table->json('proxy_ports')->nullable()->after('public_port');
            });
        }
    }

    public function down(): void
    {
        Schema::table('service_databases', function (Blueprint $table) {
            $table->dropColumn('proxy_ports');
        });
    }
};
