<?php

use CorelixIo\Platform\Livewire\AppearanceSettings;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', 'feature:ENHANCED_UI_THEME'])->group(function () {
    Route::get('settings/appearance', AppearanceSettings::class)
        ->name('settings.appearance');
});
