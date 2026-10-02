<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', 'feature:NETWORK_MANAGEMENT'])->group(function () {
    Route::get('server/{server_uuid}/networks', \CorelixIo\Platform\Livewire\NetworkManagerPage::class)
        ->name('server.networks');

    Route::get('project/{project_uuid}/environment/{environment_uuid}/application/{application_uuid}/networks',
        \App\Livewire\Project\Application\Configuration::class
    )->name('project.application.networks');

    Route::get('project/{project_uuid}/environment/{environment_uuid}/database/{database_uuid}/networks',
        \App\Livewire\Project\Database\Configuration::class
    )->name('project.database.networks');

    Route::get('project/{project_uuid}/environment/{environment_uuid}/service/{service_uuid}/networks',
        \App\Livewire\Project\Service\Configuration::class
    )->name('project.service.networks');

    Route::get('settings/networks', \CorelixIo\Platform\Livewire\NetworkSettings::class)
        ->name('settings.networks');
});
