<?php

namespace CorelixIo\Platform\Http\Middleware;

use CorelixIo\Platform\Support\Feature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FeatureMiddleware
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (Feature::enabled($feature)) {
            return $next($request);
        }

        $meta = Feature::meta($feature);
        $name = $meta['name'] ?? $feature;
        $tier = $meta['tier'] ?? 'pro';
        $upgradeUrl = Feature::upgradeUrl();

        if ($request->expectsJson() || str_starts_with($request->path(), 'api/')) {
            return response()->json([
                'error' => 'premium_feature',
                'feature' => $feature,
                'name' => $name,
                'tier' => $tier,
                'upgrade_url' => $upgradeUrl,
                'message' => "{$name} requires Pro edition. Upgrade at {$upgradeUrl}",
            ], 402);
        }

        return redirect()->back()->with('error', 'This feature requires Pro edition.');
    }
}
