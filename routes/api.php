<?php

use CorelixIo\Platform\Support\Feature;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Corelix Platform API Routes
|--------------------------------------------------------------------------
|
| Feature-specific routes are loaded from routes/features/ by each
| feature's provider class. Only the shared /v1/features endpoint
| remains here.
|
*/

Route::middleware(['auth:sanctum', \App\Http\Middleware\ApiAllowed::class, 'throttle:api', 'api.sensitive'])->prefix('v1')->group(function () {
    Route::get('/features', function () {
        return response()->json([
            'edition' => Feature::edition(),
            'features' => Feature::all(),
            'upgrade_url' => Feature::upgradeUrl(),
        ]);
    })->middleware('api.ability:read');
});
