<?php

use CorelixIo\Platform\Http\Controllers\Api\CustomTemplateSourceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', \App\Http\Middleware\ApiAllowed::class, 'throttle:api', 'api.sensitive'])
    ->prefix('v1')
    ->middleware(['feature:CUSTOM_TEMPLATE_SOURCES'])
    ->group(function () {
        Route::get('/template-sources', [CustomTemplateSourceController::class, 'index'])->middleware('api.ability:read');
        Route::post('/template-sources', [CustomTemplateSourceController::class, 'store'])->middleware('api.ability:write');
        Route::get('/template-sources/{uuid}', [CustomTemplateSourceController::class, 'show'])->middleware('api.ability:read');
        Route::patch('/template-sources/{uuid}', [CustomTemplateSourceController::class, 'update'])->middleware('api.ability:write');
        Route::delete('/template-sources/{uuid}', [CustomTemplateSourceController::class, 'destroy'])->middleware('api.ability:write');
        Route::post('/template-sources/{uuid}/sync', [CustomTemplateSourceController::class, 'sync'])->middleware('api.ability:write');
        Route::post('/template-sources/sync-all', [CustomTemplateSourceController::class, 'syncAll'])->middleware('api.ability:write');
    });
