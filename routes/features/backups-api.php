<?php

use CorelixIo\Platform\Http\Controllers\Api\ResourceBackupController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', \App\Http\Middleware\ApiAllowed::class, 'throttle:api', 'api.sensitive'])
    ->prefix('v1')
    ->middleware(['feature:RESOURCE_BACKUPS'])
    ->group(function () {
        Route::get('/resource-backups', [ResourceBackupController::class, 'index'])->middleware('api.ability:read');
        Route::post('/resource-backups', [ResourceBackupController::class, 'store'])->middleware('api.ability:write');
        Route::get('/resource-backups/{uuid}', [ResourceBackupController::class, 'show'])->middleware('api.ability:read');
        Route::post('/resource-backups/{uuid}/trigger', [ResourceBackupController::class, 'trigger'])->middleware('api.ability:write');
        Route::delete('/resource-backups/{uuid}', [ResourceBackupController::class, 'destroy'])->middleware('api.ability:write');
    });
