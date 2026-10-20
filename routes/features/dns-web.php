<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', 'feature:DNS_PROVIDER_MANAGEMENT'])->group(function () {
    Route::get('settings/dns', \CorelixIo\Platform\Livewire\DnsProviderManager::class)
        ->name('settings.dns');

    // Per-resource Domains sub-pages — rendered by Coolify's Configuration components,
    // whose overlaid views branch on the route name (same pattern as *.networks).
    Route::get('project/{project_uuid}/environment/{environment_uuid}/application/{application_uuid}/domains',
        \App\Livewire\Project\Application\Configuration::class
    )->name('project.application.domains');

    Route::get('project/{project_uuid}/environment/{environment_uuid}/service/{service_uuid}/domains',
        \App\Livewire\Project\Service\Configuration::class
    )->name('project.service.domains');
});
