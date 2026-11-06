<?php

use CorelixIo\Platform\Livewire\ResourceBackupPage;
use CorelixIo\Platform\Livewire\RestoreBackup;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', 'feature:RESOURCE_BACKUPS'])->group(function () {
    Route::get('project/{project_uuid}/environment/{environment_uuid}/application/{application_uuid}/resource-backups',
        \App\Livewire\Project\Application\Configuration::class
    )->name('project.application.resource-backups');

    Route::get('project/{project_uuid}/environment/{environment_uuid}/database/{database_uuid}/resource-backups',
        \App\Livewire\Project\Database\Configuration::class
    )->name('project.database.resource-backups');

    Route::get('project/{project_uuid}/environment/{environment_uuid}/service/{service_uuid}/resource-backups',
        \App\Livewire\Project\Service\Configuration::class
    )->name('project.service.resource-backups');

    Route::get('server/{server_uuid}/resource-backups', ResourceBackupPage::class)
        ->name('server.resource-backups');

    Route::get('settings/restore-backup', RestoreBackup::class)
        ->name('settings.restore-backup');
});
