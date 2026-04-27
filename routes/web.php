<?php

use App\Http\Controllers\Api\NavigationController;
use App\Http\Controllers\Api\PageConfigController;
use App\Http\Controllers\Api\SalesMainConfigController;
use App\Http\Controllers\Api\SalesMainDataController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PanelPageController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/settings.php';

Route::get('/', HomeController::class)->name('home');

Route::middleware(['auth', 'panel.session'])->group(function () {
    Route::prefix('api')->group(function () {
        Route::get('navigation', NavigationController::class)->name('api.navigation');
        Route::get('pages/sales-main/config', SalesMainConfigController::class)->name('api.pages.sales-main.config');
        Route::get('pages/{code}/config', PageConfigController::class)->name('api.pages.config');
        Route::post('data/sales-main', SalesMainDataController::class)->name('api.data.sales-main');

        Route::middleware('panel.access:admin_panel')->prefix('admin')->group(function () {
            Route::get('overview', [\App\Http\Controllers\Api\AdminController::class, 'overview']);
            Route::get('users', [\App\Http\Controllers\Api\AdminController::class, 'users'])->middleware('panel.access:admin_users');
            Route::post('users', [\App\Http\Controllers\Api\AdminController::class, 'saveUser'])->middleware('panel.access:admin_users');
            Route::get('pages', [\App\Http\Controllers\Api\AdminController::class, 'pages'])->middleware('panel.access:admin_pages');
            Route::post('pages', [\App\Http\Controllers\Api\AdminController::class, 'savePage'])->middleware('panel.access:admin_pages');
            Route::post('buttons', [\App\Http\Controllers\Api\AdminController::class, 'saveButton'])->middleware('panel.access:admin_pages');
            Route::delete('pages/{page}', [\App\Http\Controllers\Api\AdminController::class, 'deletePage'])->middleware('panel.access:admin_pages');
            Route::get('datasources', [\App\Http\Controllers\Api\AdminController::class, 'dataSources'])->middleware('panel.access:admin_datasources');
            Route::post('datasources', [\App\Http\Controllers\Api\AdminController::class, 'saveDataSource'])->middleware('panel.access:admin_datasources');
            Route::get('logs', [\App\Http\Controllers\Api\AdminController::class, 'logs'])->middleware('panel.access:admin_logs');
        });
    });

    Route::get('dashboard', [PanelPageController::class, 'dashboard'])->name('dashboard');

    Route::get('{panelPath}', PanelPageController::class)
        ->where('panelPath', '.*')
        ->name('panel.page');
});
