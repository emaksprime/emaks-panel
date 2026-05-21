<?php

use App\Http\Controllers\Api\B2B\B2BPartnerController;
use App\Http\Controllers\Api\B2B\B2BPartnerUserController;
use App\Http\Controllers\Api\CariBilgiDataController;
use App\Http\Controllers\Api\NavigationController;
use App\Http\Controllers\Api\PageConfigController;
use App\Http\Controllers\Api\PageDataController;
use App\Http\Controllers\Api\SalesMainConfigController;
use App\Http\Controllers\Api\SalesMainDataController;
use App\Http\Controllers\Api\StockCriticalSettingController;
use App\Http\Controllers\Api\SupportActivationCodeSearchController;
use App\Http\Controllers\Api\TechnicalServiceController;
use App\Http\Controllers\Api\TechnicalServiceEarningController;
use App\Http\Controllers\Api\TechnicalServiceMikroController;
use App\Http\Controllers\Api\TechnicalServiceTechnicianController;
use App\Http\Controllers\Api\TechnicalServiceWarrantyController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PanelPageController;
use App\Http\Controllers\SupportController;
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

        Route::prefix('support')->group(function () {
            Route::get('activation/search', SupportActivationCodeSearchController::class)
                ->middleware(['panel.access:support', 'panel.access:support_activation_query'])
                ->name('api.support.activation.search');
        });

        Route::prefix('b2b')
            ->middleware('panel.access:b2b.view,b2b.manage,b2b.dealers.view,b2b.locksmiths.view,b2b.manufacturers.view,b2b.sellers.view,b2b.partner_users.manage')
            ->group(function () {
                Route::get('partners', [B2BPartnerController::class, 'index'])
                    ->name('api.b2b.partners.index');
                Route::post('partners', [B2BPartnerController::class, 'store'])
                    ->middleware('panel.access:b2b.manage,b2b.dealers.manage,b2b.locksmiths.manage,b2b.manufacturers.manage,b2b.sellers.manage')
                    ->name('api.b2b.partners.store');
                Route::get('cari-control', [B2BPartnerController::class, 'cariControl'])
                    ->middleware('panel.access:b2b.manage,b2b.dealers.manage,b2b.locksmiths.manage,b2b.manufacturers.manage,b2b.sellers.manage')
                    ->name('api.b2b.cari-control.index');
                Route::post('cari-control/apply', [B2BPartnerController::class, 'applyCariControl'])
                    ->middleware('panel.access:b2b.manage,b2b.dealers.manage,b2b.locksmiths.manage,b2b.manufacturers.manage,b2b.sellers.manage')
                    ->name('api.b2b.cari-control.apply');
                Route::post('cari-control/import', [B2BPartnerController::class, 'importCariControl'])
                    ->middleware('panel.access:b2b.manage,b2b.dealers.manage,b2b.locksmiths.manage,b2b.manufacturers.manage,b2b.sellers.manage')
                    ->name('api.b2b.cari-control.import');
                Route::get('users/search', [B2BPartnerUserController::class, 'searchUsers'])
                    ->middleware('panel.access:b2b.manage,b2b.partner_users.manage')
                    ->name('api.b2b.users.search');
                Route::get('locksmith-technicians', [B2BPartnerController::class, 'locksmithTechnicians'])
                    ->name('api.b2b.locksmith-technicians.index');
                Route::post('locksmith-technicians/sync', [B2BPartnerController::class, 'syncLocksmithTechnicians'])
                    ->middleware('panel.access:b2b.manage,b2b.locksmiths.manage')
                    ->name('api.b2b.locksmith-technicians.sync');
                Route::get('partners/{partner}/technicians', [B2BPartnerController::class, 'partnerTechnicians'])
                    ->name('api.b2b.partner-technicians.index');
                Route::post('partners/{partner}/technicians', [B2BPartnerController::class, 'storePartnerTechnician'])
                    ->middleware('panel.access:b2b.manage,b2b.locksmiths.manage')
                    ->name('api.b2b.partner-technicians.store');
                Route::patch('partners/{partner}/technicians/{link}', [B2BPartnerController::class, 'updatePartnerTechnician'])
                    ->middleware('panel.access:b2b.manage,b2b.locksmiths.manage')
                    ->name('api.b2b.partner-technicians.update');
                Route::delete('partners/{partner}/technicians/{link}', [B2BPartnerController::class, 'destroyPartnerTechnician'])
                    ->middleware('panel.access:b2b.manage,b2b.locksmiths.manage')
                    ->name('api.b2b.partner-technicians.destroy');
                Route::get('partners/{partner}/users', [B2BPartnerUserController::class, 'index'])
                    ->name('api.b2b.partner-users.index');
                Route::post('partners/{partner}/users', [B2BPartnerUserController::class, 'store'])
                    ->name('api.b2b.partner-users.store');
                Route::patch('partners/{partner}/users/{user}', [B2BPartnerUserController::class, 'update'])
                    ->name('api.b2b.partner-users.update');
                Route::delete('partners/{partner}/users/{user}', [B2BPartnerUserController::class, 'destroy'])
                    ->name('api.b2b.partner-users.destroy');
                Route::get('partners/{partner}', [B2BPartnerController::class, 'show'])
                    ->name('api.b2b.partners.show');
                Route::patch('partners/{partner}/active', [B2BPartnerController::class, 'updateActive'])
                    ->middleware('panel.access:b2b.manage,b2b.dealers.manage,b2b.locksmiths.manage,b2b.manufacturers.manage,b2b.sellers.manage')
                    ->name('api.b2b.partners.active');
                Route::patch('partners/{partner}/capabilities', [B2BPartnerController::class, 'updateCapabilities'])
                    ->middleware('panel.access:b2b.manage,b2b.dealers.manage,b2b.locksmiths.manage,b2b.manufacturers.manage,b2b.sellers.manage')
                    ->name('api.b2b.partners.capabilities');
                Route::patch('partners/{partner}', [B2BPartnerController::class, 'update'])
                    ->middleware('panel.access:b2b.manage,b2b.dealers.manage,b2b.locksmiths.manage,b2b.manufacturers.manage,b2b.sellers.manage')
                    ->name('api.b2b.partners.update');
            });

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
            Route::post('technicians/{technician}/geocode', [TechnicalServiceTechnicianController::class, 'geocode'])
                ->middleware('panel.access:technical_service_technicians')
                ->name('api.technical-service.technicians.geocode');
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
            Route::patch('requests/{technicalServiceRequest}/workflow', [TechnicalServiceController::class, 'updateWorkflow'])
                ->middleware('panel.access:technical_service_manage')
                ->name('api.technical-service.requests.workflow');
            Route::patch('requests/{technicalServiceRequest}/schedule', [TechnicalServiceController::class, 'updateSchedule'])
                ->middleware('panel.access:technical_service_manage')
                ->name('api.technical-service.requests.schedule');
            Route::patch('requests/{technicalServiceRequest}/technician', [TechnicalServiceController::class, 'updateTechnician'])
                ->middleware('panel.access:technical_service_manage')
                ->name('api.technical-service.requests.technician');
            Route::post('requests/{technicalServiceRequest}/contact-log', [TechnicalServiceController::class, 'storeContactLog'])
                ->middleware('panel.access:technical_service_manage')
                ->name('api.technical-service.requests.contact-log');
            Route::patch('requests/{technicalServiceRequest}/field/{fieldAction}', [TechnicalServiceController::class, 'updateFieldAction'])
                ->where('fieldAction', 'start-travel|arrive|start-work|mark-incomplete|checklist|photos|customer-closure-approval|complete')
                ->middleware('panel.access:technical_service_manage')
                ->name('api.technical-service.requests.field-action');
            Route::get('requests/{technicalServiceRequest}/audit-logs', [TechnicalServiceController::class, 'auditLogs'])
                ->middleware('panel.access:technical_service')
                ->name('api.technical-service.requests.audit-logs');
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
            Route::get('overview', [\App\Http\Controllers\Api\AdminController::class, 'overview']);
            Route::get('users', [\App\Http\Controllers\Api\AdminController::class, 'users'])->middleware('panel.access:user_admin');
            Route::post('users', [\App\Http\Controllers\Api\AdminController::class, 'saveUser'])->middleware('panel.access:user_admin');
            Route::post('users/{user}/clone', [\App\Http\Controllers\Api\AdminController::class, 'cloneUser'])->middleware('panel.access:user_admin');
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
    Route::get('orders', [PanelPageController::class, 'orders'])->name('orders.redirect');
    Route::get('support', [SupportController::class, 'index'])
        ->middleware('panel.access:support')
        ->name('support.index');
    Route::get('support/keypad-guide', [SupportController::class, 'keypadGuide'])
        ->middleware(['panel.access:support', 'panel.access:support_keypad_guide'])
        ->name('support.keypad-guide');
    Route::get('support/activation', [SupportController::class, 'activation'])
        ->middleware(['panel.access:support', 'panel.access:support_activation_query'])
        ->name('support.activation');

    Route::get('panel/b2b/partners', fn () => Inertia::render('panel/b2b/partners', [
        'page' => [
            'title' => 'B2B Partner Yönetimi',
            'slug' => 'b2b_partners',
            'routePath' => '/panel/b2b/partners',
            'component' => 'panel/b2b/partners',
            'layoutType' => 'module',
            'description' => 'Bayi ve çilingir partner kayıtları',
            'buttons' => [],
        ],
    ]))->middleware('panel.access:b2b.view,b2b.manage,b2b.dealers.view,b2b.locksmiths.view,b2b.manufacturers.view,b2b.sellers.view')->name('b2b.partners');

    Route::get('panel/b2b/users', fn () => Inertia::render('panel/b2b/users', [
        'page' => [
            'title' => 'B2B Partner Kullanıcıları',
            'slug' => 'b2b_partner_users',
            'routePath' => '/panel/b2b/users',
            'component' => 'panel/b2b/users',
            'layoutType' => 'module',
            'description' => 'Mevcut panel kullanıcılarını B2B partner kayıtlarına bağlama ve yetkilendirme',
            'buttons' => [],
        ],
    ]))->middleware('panel.access:b2b.manage,b2b.partner_users.manage')->name('b2b.users');

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

    Route::get('technical-service/field', fn () => Inertia::render('panel/technical-service-field', [
        'page' => [
            'title' => 'Usta Saha İşleri',
            'slug' => 'technical_service_field',
            'routePath' => '/technical-service/field',
            'component' => 'panel/technical-service-field',
            'layoutType' => 'module',
            'description' => 'Atanmış saha işleri ve mobil iş akışı',
            'buttons' => [],
        ],
    ]))->middleware('panel.access:technical_service_dashboard')->name('technical-service.field');

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
