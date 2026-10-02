<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('environment_user')) {
            Schema::create('environment_user', function (Blueprint $table) {
                $table->id();
                $table->foreignId('environment_id')->constrained()->onDelete('cascade');
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->json('permissions')->default('{"view":false,"deploy":false,"manage":false,"delete":false}');
                $table->timestamps();

                $table->unique(['environment_id', 'user_id']);
                $table->index('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('environment_user');
    }
};
