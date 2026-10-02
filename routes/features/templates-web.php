<?php

use CorelixIo\Platform\Livewire\CustomTemplateSources;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', 'feature:CUSTOM_TEMPLATE_SOURCES'])->group(function () {
    Route::get('settings/custom-templates', CustomTemplateSources::class)
        ->name('settings.custom-templates');
});
