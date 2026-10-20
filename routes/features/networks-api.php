<?php

use CorelixIo\Platform\Http\Controllers\Api\NetworkController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', \App\Http\Middleware\ApiAllowed::class, 'throttle:api', 'api.sensitive'])
    ->prefix('v1')
    ->middleware(['feature:NETWORK_MANAGEMENT'])
    ->group(function () {
        Route::get('/servers/{uuid}/networks', [NetworkController::class, 'index'])->middleware('api.ability:read');
        Route::post('/servers/{uuid}/networks', [NetworkController::class, 'store'])->middleware('api.ability:write');
        Route::get('/servers/{uuid}/networks/{network_uuid}', [NetworkController::class, 'show'])->middleware('api.ability:read');
        Route::delete('/servers/{uuid}/networks/{network_uuid}', [NetworkController::class, 'destroy'])->middleware('api.ability:write');
        Route::post('/servers/{uuid}/networks/sync', [NetworkController::class, 'sync'])->middleware('api.ability:write');
        Route::post('/servers/{uuid}/networks/reconcile-resources', [NetworkController::class, 'reconcileResources'])->middleware('api.ability:write');
        Route::post('/servers/{uuid}/networks/migrate-proxy', [NetworkController::class, 'migrateProxy'])->middleware('api.ability:write');
        Route::post('/servers/{uuid}/networks/cleanup-proxy', [NetworkController::class, 'cleanupProxy'])->middleware('api.ability:write');
        Route::get('/resources/{type}/{uuid}/networks', [NetworkController::class, 'resourceNetworks'])->middleware('api.ability:read');
        Route::post('/resources/{type}/{uuid}/networks', [NetworkController::class, 'attachResource'])->middleware('api.ability:write');
        Route::delete('/resources/{type}/{uuid}/networks/{network_uuid}', [NetworkController::class, 'detachResource'])->middleware('api.ability:write');
    });
