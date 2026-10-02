<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        try {
            $oldRow = DB::table('enhanced_ui_settings')
                ->where('key', 'enhanced_theme_enabled')
                ->first();

            if ($oldRow && $oldRow->value === '1') {
                DB::table('enhanced_ui_settings')->updateOrInsert(
                    ['key' => 'active_theme'],
                    ['value' => 'enhanced', 'updated_at' => now()]
                );
            }

            DB::table('enhanced_ui_settings')
                ->where('key', 'enhanced_theme_enabled')
                ->delete();
        } catch (\Throwable $e) {
            // Table may not exist yet — safe to skip.
        }
    }

    public function down(): void
    {
        try {
            $activeRow = DB::table('enhanced_ui_settings')
                ->where('key', 'active_theme')
                ->first();

            $themeEnabled = $activeRow
                && $activeRow->value !== null
                && $activeRow->value !== '';

            DB::table('enhanced_ui_settings')->updateOrInsert(
                ['key' => 'enhanced_theme_enabled'],
                ['value' => $themeEnabled ? '1' : '0', 'updated_at' => now()]
            );

            DB::table('enhanced_ui_settings')
                ->where('key', 'active_theme')
                ->delete();
        } catch (\Throwable $e) {
            // Table may not exist yet — safe to skip.
        }
    }
};
