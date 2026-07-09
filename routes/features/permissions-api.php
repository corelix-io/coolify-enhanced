<?php

use CorelixIo\Platform\Http\Controllers\Api\PermissionsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', \App\Http\Middleware\ApiAllowed::class, 'throttle:api', 'api.sensitive'])
    ->prefix('v1')
    ->middleware(['feature:GRANULAR_PERMISSIONS'])
    ->group(function () {
        Route::get('/projects/{uuid}/access', [PermissionsController::class, 'listProjectAccess'])->middleware('api.ability:read');
        Route::post('/projects/{uuid}/access', [PermissionsController::class, 'grantProjectAccess'])->middleware('api.ability:write');
        Route::patch('/projects/{uuid}/access/{user_id}', [PermissionsController::class, 'updateProjectAccess'])->middleware('api.ability:write');
        Route::delete('/projects/{uuid}/access/{user_id}', [PermissionsController::class, 'revokeProjectAccess'])->middleware('api.ability:write');
        Route::get('/projects/{uuid}/access/{user_id}/check', [PermissionsController::class, 'checkPermission'])->middleware('api.ability:read');
    });
