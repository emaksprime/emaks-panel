<?php

use App\Http\Controllers\Api\ActivationCodeImportController;
use App\Http\Controllers\Api\ActivationCodeSearchController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\CariBilgiDataController;
use App\Http\Controllers\Api\NavigationController;
use App\Http\Controllers\Api\PageConfigController;
use App\Http\Controllers\Api\PageDataController;
use App\Http\Controllers\Api\SalesMainConfigController;
use App\Http\Controllers\Api\SalesMainDataController;
use App\Http\Controllers\Api\StockCriticalSettingController;
use App\Http\Controllers\Api\TechnicalServiceController;
use App\Http\Controllers\Api\TechnicalServiceEarningController;
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
        Route::get('activation-code-search', ActivationCodeSearchController::class)
            ->middleware('panel.access:activation_code_search')
            ->name('api.activation-code-search');
        Route::post('activation-code-search/import', ActivationCodeImportController::class)
            ->middleware('panel.access:activation_code_search')
            ->name('api.activation-code-search.import');
        Route::get('pages/sales-main/config', SalesMainConfigController::class)->name('api.pages.sales-main.config');
        Route::get('pages/{code}/config', PageConfigController::class)->name('api.pages.config');
        Route::post('data/sales-main', SalesMainDataController::class)->name('api.data.sales-main');
        Route::post('data/cari-bilgi', CariBilgiDataController::class)->name('api.data.cari-bilgi');
        Route::post('data/{code}', PageDataController::class)
            ->where('code', '[A-Za-z0-9_-]+')
            ->name('api.data.page');
        Route::get('stock/critical-settings', [StockCriticalSettingController::class, 'index'])
            ->middleware('panel.access:stock,stock_critical')
            ->name('api.stock.critical-settings.index');
        Route::post('stock/critical-settings', [StockCriticalSettingController::class, 'store'])
            ->middleware('panel.access:stock,stock_critical')
            ->name('api.stock.critical-settings.store');
        Route::delete('stock/critical-settings/{stockCode}', [StockCriticalSettingController::class, 'destroy'])
            ->where('stockCode', '.*')
            ->middleware('panel.access:stock,stock_critical')
            ->name('api.stock.critical-settings.destroy');

        Route::prefix('technical-service')->group(function () {
            Route::get('technicians', [TechnicalServiceTechnicianController::class, 'index'])
                ->middleware('panel.access:technical_service,technical_service_technicians')
                ->name('api.technical-service.technicians.index');
            Route::post('technicians', [TechnicalServiceTechnicianController::class, 'store'])
                ->middleware('panel.access:technical_service_technicians')
                ->name('api.technical-service.technicians.store');
            Route::patch('technicians/{technician}', [TechnicalServiceTechnicianController::class, 'update'])
                ->middleware('panel.access:technical_service_technicians')
                ->name('api.technical-service.technicians.update');
            Route::delete('technicians/{technician}', [TechnicalServiceTechnicianController::class, 'destroy'])
                ->middleware('panel.access:technical_service_technicians')
                ->name('api.technical-service.technicians.destroy');
            Route::post('technicians/import', [TechnicalServiceTechnicianController::class, 'importCsv'])
                ->middleware('panel.access:technical_service_technicians')
                ->name('api.technical-service.technicians.import');
            Route::get('requests', [TechnicalServiceController::class, 'index'])
                ->middleware('panel.access:technical_service')
                ->name('api.technical-service.requests.index');
            Route::get('requests/{technicalServiceRequest}', [TechnicalServiceController::class, 'show'])
                ->middleware('panel.access:technical_service')
                ->name('api.technical-service.requests.show');
            Route::post('requests', [TechnicalServiceController::class, 'store'])
                ->middleware('panel.access:technical_service_manage')
                ->name('api.technical-service.requests.store');
            Route::patch('requests/{technicalServiceRequest}', [TechnicalServiceController::class, 'update'])
                ->middleware('panel.access:technical_service_manage')
                ->name('api.technical-service.requests.update');
            Route::post('requests/{technicalServiceRequest}/status', [TechnicalServiceController::class, 'updateStatus'])
                ->middleware('panel.access:technical_service_manage')
                ->name('api.technical-service.requests.status');
            Route::post('requests/{technicalServiceRequest}/assign', [TechnicalServiceController::class, 'assign'])
                ->middleware('panel.access:technical_service_manage')
                ->name('api.technical-service.requests.assign');
            Route::get('summary', [TechnicalServiceController::class, 'summary'])
                ->middleware('panel.access:technical_service')
                ->name('api.technical-service.summary');
            Route::get('operations-dashboard', [TechnicalServiceController::class, 'operationsDashboard'])
                ->middleware('panel.access:technical_service_dashboard')
                ->name('api.technical-service.operations-dashboard');
            Route::post('earnings/periods/calculate', [TechnicalServiceEarningController::class, 'calculate'])
                ->middleware('panel.access:technical_service_earnings')
                ->name('api.technical-service.earnings.periods.calculate');
            Route::get('earnings', [TechnicalServiceEarningController::class, 'index'])
                ->middleware('panel.access:technical_service_earnings')
                ->name('api.technical-service.earnings.index');
            Route::get('earnings/{earning}', [TechnicalServiceEarningController::class, 'show'])
                ->middleware('panel.access:technical_service_earnings')
                ->name('api.technical-service.earnings.show');
            Route::patch('earnings/{earning}', [TechnicalServiceEarningController::class, 'update'])
                ->middleware('panel.access:technical_service_earnings')
                ->name('api.technical-service.earnings.update');
            Route::post('earnings/{earning}/mark-paid', [TechnicalServiceEarningController::class, 'markPaid'])
                ->middleware('panel.access:technical_service_earnings_pay')
                ->name('api.technical-service.earnings.mark-paid');
            Route::get('earnings/{earning}/whatsapp-text', [TechnicalServiceEarningController::class, 'whatsappText'])
                ->middleware('panel.access:technical_service_earnings')
                ->name('api.technical-service.earnings.whatsapp-text');
            Route::get('mikro/serial-check', [TechnicalServiceMikroController::class, 'check'])
                ->middleware('panel.access:technical_service_serial_query')
                ->name('api.technical-service.mikro.serial-check');
            Route::get('mikro/serial-history', [TechnicalServiceMikroController::class, 'history'])
                ->middleware('panel.access:technical_service_serial_query')
                ->name('api.technical-service.mikro.serial-history');
            Route::get('warranty/serial', [TechnicalServiceWarrantyController::class, 'serial'])
                ->middleware('panel.access:technical_service_serial_query')
                ->name('api.technical-service.warranty.serial');
        });

        Route::middleware('panel.access:admin_panel')->prefix('admin')->group(function () {
            Route::get('overview', [AdminController::class, 'overview']);
            Route::get('users', [AdminController::class, 'users'])->middleware('panel.access:user_admin');
            Route::post('users', [AdminController::class, 'saveUser'])->middleware('panel.access:user_admin');
            Route::get('pages', [AdminController::class, 'pages'])->middleware('panel.access:admin_pages');
            Route::post('pages', [AdminController::class, 'savePage'])->middleware('panel.access:admin_pages');
            Route::post('buttons', [AdminController::class, 'saveButton'])->middleware('panel.access:admin_pages');
            Route::delete('pages/{page}', [AdminController::class, 'deletePage'])->middleware('panel.access:admin_pages');
            Route::get('datasources', [AdminController::class, 'dataSources'])->middleware('panel.access:data_sources');
            Route::post('datasources', [AdminController::class, 'saveDataSource'])->middleware('panel.access:data_sources');
            Route::post('datasources/test', [AdminController::class, 'testDataSource'])->middleware('panel.access:data_sources');
            Route::get('logs', [AdminController::class, 'logs'])->middleware('panel.access:admin_logs');
        });
    });

    Route::get('dashboard', [PanelPageController::class, 'dashboard'])->name('dashboard');
    Route::get('orders', [PanelPageController::class, 'orders'])->name('orders.redirect');

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
    ]))->middleware('panel.access:technical_service_serial_query')->name('technical-service.serial-query');

    Route::get('technical-service/dashboard', fn () => Inertia::render('panel/technical-service-dashboard', [
        'page' => [
            'title' => 'Teknik Servis İç Operasyon Pilot Dashboard',
            'slug' => 'technical_service_dashboard',
            'routePath' => '/technical-service/dashboard',
            'component' => 'panel/technical-service-dashboard',
            'layoutType' => 'module',
            'description' => 'İç operasyon teknik servis takip ekranı',
            'buttons' => [],
        ],
    ]))->middleware('panel.access:technical_service_dashboard')->name('technical-service.operations-dashboard');

    Route::get('technical-service/technicians', fn () => Inertia::render('panel/technical-service-technicians', [
        'page' => [
            'title' => 'Teknisyen Yönetimi',
            'slug' => 'technical_service_technicians',
            'routePath' => '/technical-service/technicians',
            'component' => 'panel/technical-service-technicians',
            'layoutType' => 'module',
            'description' => 'Teknik servis usta ve çilingir kayıt yönetimi',
            'buttons' => [],
        ],
    ]))->middleware('panel.access:technical_service_technicians')->name('technical-service.technicians');

    Route::get('technical-service/earnings', fn () => Inertia::render('panel/technical-service-earnings', [
        'page' => [
            'title' => 'Servis Hakedişleri',
            'slug' => 'technical_service_earnings',
            'routePath' => '/technical-service/earnings',
            'component' => 'panel/technical-service-earnings',
            'layoutType' => 'module',
            'description' => 'Servis ve çilingir aylık hakediş takip ekranı',
            'buttons' => [],
        ],
    ]))->middleware('panel.access:technical_service_earnings')->name('technical-service.earnings');

    Route::get('technical-service/admin', fn () => Inertia::render('panel/technical-service-admin', [
        'page' => [
            'title' => 'Teknik Servis Admin',
            'slug' => 'technical_service_admin',
            'routePath' => '/technical-service/admin',
            'component' => 'panel/technical-service-admin',
            'layoutType' => 'module',
            'description' => 'Teknik servis modül ayarları ve yetki merkezi',
            'buttons' => [],
        ],
    ]))->middleware('panel.access:technical_service_admin')->name('technical-service.admin');

    Route::get('{panelPath}', PanelPageController::class)
        ->where('panelPath', '.*')
        ->name('panel.page');
});
