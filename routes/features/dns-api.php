<?php

use CorelixIo\Platform\Http\Controllers\Api\DnsController;
use Illuminate\Support\Facades\Route;

/*
 * DNS Provider Management — REST API (Wave 5).
 *
 * All routes are Sanctum-authenticated and team-scoped; mutations are owner/admin only
 * (enforced in the controller). Pro endpoints additionally carry their child feature
 * middleware and return HTTP 402 when the pro feature is disabled (free edition).
 */
Route::middleware(['auth:sanctum', \App\Http\Middleware\ApiAllowed::class, 'throttle:api', 'api.sensitive'])
    ->prefix('v1')
    ->middleware(['feature:DNS_PROVIDER_MANAGEMENT'])
    ->group(function () {
        // --- DNS providers ---
        Route::get('/dns-providers', [DnsController::class, 'index'])->middleware('api.ability:read');
        Route::post('/dns-providers', [DnsController::class, 'store'])->middleware('api.ability:write');
        Route::get('/dns-providers/{uuid}', [DnsController::class, 'show'])->middleware('api.ability:read');
        Route::patch('/dns-providers/{uuid}', [DnsController::class, 'update'])->middleware('api.ability:write');
        Route::delete('/dns-providers/{uuid}', [DnsController::class, 'destroy'])->middleware('api.ability:write');
        Route::post('/dns-providers/{uuid}/test', [DnsController::class, 'test'])->middleware('api.ability:write');

        // --- Managed domains ---
        Route::get('/domains', [DnsController::class, 'domains'])->middleware('api.ability:read');
        Route::post('/domains', [DnsController::class, 'storeDomain'])->middleware('api.ability:write');
        Route::get('/domains/{uuid}', [DnsController::class, 'showDomain'])->middleware('api.ability:read');
        Route::patch('/domains/{uuid}', [DnsController::class, 'updateDomain'])->middleware('api.ability:write');
        Route::delete('/domains/{uuid}', [DnsController::class, 'destroyDomain'])->middleware('api.ability:write');
        Route::post('/domains/{uuid}/sync', [DnsController::class, 'syncDomain'])->middleware('api.ability:write');
        Route::get('/domains/{uuid}/hostnames', [DnsController::class, 'domainHostnames'])->middleware('api.ability:read');

        // --- Per-resource DNS operations ---
        Route::get('/dns/resource/{type}/{uuid}', [DnsController::class, 'resourceStatus'])->middleware('api.ability:read');
        Route::post('/dns/resource/{type}/{uuid}/resync', [DnsController::class, 'resourceResync'])->middleware('api.ability:write');

        // Pro: hostname pinning (distinct/custom apex) — 402 when DNS_MULTI_DOMAIN is disabled.
        Route::post('/dns/resource/{type}/{uuid}/assign-domain', [DnsController::class, 'assignDomain'])
            ->middleware(['api.ability:write', 'feature:DNS_MULTI_DOMAIN']);

        // Pro: environment bindings — 402 when DNS_ENV_BINDINGS is disabled.
        Route::get('/domains/{uuid}/environment-bindings', [DnsController::class, 'bindings'])
            ->middleware(['api.ability:read', 'feature:DNS_ENV_BINDINGS']);
        Route::post('/domains/{uuid}/environment-bindings', [DnsController::class, 'storeBinding'])
            ->middleware(['api.ability:write', 'feature:DNS_ENV_BINDINGS']);
        Route::delete('/domains/{uuid}/environment-bindings/{bindingId}', [DnsController::class, 'destroyBinding'])
            ->middleware(['api.ability:write', 'feature:DNS_ENV_BINDINGS']);
    });
