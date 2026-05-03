<?php

use App\Http\Controllers\Api\NavigationController;
use App\Http\Controllers\Api\PageConfigController;
use App\Http\Controllers\Api\CariBilgiDataController;
use App\Http\Controllers\Api\PageDataController;
use App\Http\Controllers\Api\SalesMainConfigController;
use App\Http\Controllers\Api\SalesMainDataController;
use App\Http\Controllers\Api\TechnicalServiceController;
use App\Http\Controllers\Api\TechnicalServiceMikroController;
use App\Http\Controllers\Api\TechnicalServiceTechnicianController;
use App\Http\Controllers\Api\TechnicalServiceWarrantyController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PanelPageController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

require __DIR__.'/settings.php';

Route::get('/', HomeController::class)->name('home');

Route::middleware(['auth', 'panel.session'])->group(function () {
    Route::prefix('api')->group(function () {
        Route::get('navigation', NavigationController::class)->name('api.navigation');
        Route::get('pages/sales-main/config', SalesMainConfigController::class)->name('api.pages.sales-main.config');
        Route::get('pages/{code}/config', PageConfigController::class)->name('api.pages.config');
        Route::post('data/sales-main', SalesMainDataController::class)->name('api.data.sales-main');
        Route::post('data/cari-bilgi', CariBilgiDataController::class)->name('api.data.cari-bilgi');
        Route::post('data/{code}', PageDataController::class)
            ->where('code', '[A-Za-z0-9_-]+')
            ->name('api.data.page');

        Route::prefix('technical-service')->middleware('panel.access:technical_service')->group(function () {
            Route::get('technicians', [TechnicalServiceTechnicianController::class, 'index'])->name('api.technical-service.technicians.index');
            Route::post('technicians', [TechnicalServiceTechnicianController::class, 'store'])->name('api.technical-service.technicians.store');
            Route::patch('technicians/{technician}', [TechnicalServiceTechnicianController::class, 'update'])->name('api.technical-service.technicians.update');
            Route::delete('technicians/{technician}', [TechnicalServiceTechnicianController::class, 'destroy'])->name('api.technical-service.technicians.destroy');
            Route::post('technicians/import', [TechnicalServiceTechnicianController::class, 'importCsv'])->name('api.technical-service.technicians.import');
            Route::get('requests', [TechnicalServiceController::class, 'index'])->name('api.technical-service.requests.index');
            Route::get('requests/{technicalServiceRequest}', [TechnicalServiceController::class, 'show'])->name('api.technical-service.requests.show');
            Route::post('requests', [TechnicalServiceController::class, 'store'])->name('api.technical-service.requests.store');
            Route::patch('requests/{technicalServiceRequest}', [TechnicalServiceController::class, 'update'])->name('api.technical-service.requests.update');
            Route::post('requests/{technicalServiceRequest}/status', [TechnicalServiceController::class, 'updateStatus'])->name('api.technical-service.requests.status');
            Route::post('requests/{technicalServiceRequest}/assign', [TechnicalServiceController::class, 'assign'])->name('api.technical-service.requests.assign');
            Route::get('summary', [TechnicalServiceController::class, 'summary'])->name('api.technical-service.summary');
            Route::get('operations-dashboard', [TechnicalServiceController::class, 'operationsDashboard'])->name('api.technical-service.operations-dashboard');
            Route::get('mikro/serial-check', [TechnicalServiceMikroController::class, 'check'])->name('api.technical-service.mikro.serial-check');
            Route::get('mikro/serial-history', [TechnicalServiceMikroController::class, 'history'])->name('api.technical-service.mikro.serial-history');
            Route::get('warranty/serial', [TechnicalServiceWarrantyController::class, 'serial'])->name('api.technical-service.warranty.serial');
        });

        Route::middleware('panel.access:admin_panel')->prefix('admin')->group(function () {
            Route::get('overview', [\App\Http\Controllers\Api\AdminController::class, 'overview']);
            Route::get('users', [\App\Http\Controllers\Api\AdminController::class, 'users'])->middleware('panel.access:user_admin');
            Route::post('users', [\App\Http\Controllers\Api\AdminController::class, 'saveUser'])->middleware('panel.access:user_admin');
            Route::get('pages', [\App\Http\Controllers\Api\AdminController::class, 'pages'])->middleware('panel.access:admin_pages');
            Route::post('pages', [\App\Http\Controllers\Api\AdminController::class, 'savePage'])->middleware('panel.access:admin_pages');
            Route::post('buttons', [\App\Http\Controllers\Api\AdminController::class, 'saveButton'])->middleware('panel.access:admin_pages');
            Route::delete('pages/{page}', [\App\Http\Controllers\Api\AdminController::class, 'deletePage'])->middleware('panel.access:admin_pages');
            Route::get('datasources', [\App\Http\Controllers\Api\AdminController::class, 'dataSources'])->middleware('panel.access:data_sources');
            Route::post('datasources', [\App\Http\Controllers\Api\AdminController::class, 'saveDataSource'])->middleware('panel.access:data_sources');
            Route::post('datasources/test', [\App\Http\Controllers\Api\AdminController::class, 'testDataSource'])->middleware('panel.access:data_sources');
            Route::get('logs', [\App\Http\Controllers\Api\AdminController::class, 'logs'])->middleware('panel.access:admin_logs');
        });
    });

    Route::get('dashboard', [PanelPageController::class, 'dashboard'])->name('dashboard');

    Route::get('technical-service/serial-query', fn () => Inertia::render('panel/technical-service-serial-query', [
        'page' => [
            'title' => 'Seri No Sorgu',
            'slug' => 'technical_service_serial_query',
            'routePath' => '/technical-service/serial-query',
            'component' => 'panel/technical-service-serial-query',
            'layoutType' => 'module',
            'description' => 'Mikro seri no geçmişi ve montaj karar kontrolü',
            'buttons' => [],
        ],
    ]))->middleware('panel.access:technical_service')->name('technical-service.serial-query');

    Route::get('technical-service/dashboard', fn () => Inertia::render('panel/technical-service-dashboard', [
        'page' => [
            'title' => 'Teknik Servis İç Operasyon Pilot Dashboard',
            'slug' => 'technical_service_operations_dashboard',
            'routePath' => '/technical-service/dashboard',
            'component' => 'panel/technical-service-dashboard',
            'layoutType' => 'module',
            'description' => 'İç operasyon teknik servis takip ekranı',
            'buttons' => [],
        ],
    ]))->middleware('panel.access:technical_service')->name('technical-service.operations-dashboard');

    Route::get('{panelPath}', PanelPageController::class)
        ->where('panelPath', '.*')
        ->name('panel.page');
});
