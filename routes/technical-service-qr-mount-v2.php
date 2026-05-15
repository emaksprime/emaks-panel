<?php

use App\Http\Controllers\Api\TechnicalServiceController;
use App\Http\Controllers\Api\TechnicalServiceQrLinkController;
use App\Http\Controllers\PublicMountRequestController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('mount-request/{token}', [PublicMountRequestController::class, 'show'])
    ->where('token', '[^/]+')
    ->name('mount-request.show');
Route::post('mount-request/{token}/payment', [PublicMountRequestController::class, 'createFakePayment'])
    ->where('token', '[^/]+')
    ->name('mount-request.payment.create');
Route::post('mount-request/{token}/multi-product', [PublicMountRequestController::class, 'chooseMultiProduct'])
    ->where('token', '[^/]+')
    ->name('mount-request.multi-product');
Route::get('mount-request/{token}/multi-products', [PublicMountRequestController::class, 'multiProductOptions'])
    ->where('token', '[^/]+')
    ->name('mount-request.multi-products');
Route::post('mount-request/{token}/invoice-serials/check', [PublicMountRequestController::class, 'multiProductOptions'])
    ->where('token', '[^/]+')
    ->name('mount-request.invoice-serials.check');
Route::post('mount-request/{token}/submit', [PublicMountRequestController::class, 'submit'])
    ->where('token', '[^/]+')
    ->name('mount-request.submit');
Route::get('mount-payment/fake/{payment}/approve', [PublicMountRequestController::class, 'approveFakePayment'])
    ->name('mount-payment.fake.approve');

Route::middleware(['auth', 'panel.session'])
    ->prefix('api/technical-service')
    ->group(function (): void {
        Route::post('requests/{technicalServiceRequest}/technicians/{technician}/route-quote', [TechnicalServiceController::class, 'routeQuote'])
            ->middleware('panel.access:technical_service_manage')
            ->name('api.technical-service.requests.technicians.route-quote');
        Route::patch('requests/{technicalServiceRequest}/operation-control', [TechnicalServiceController::class, 'updateOperationControl'])
            ->middleware('panel.access:technical_service_manage')
            ->name('api.technical-service.requests.operation-control');
        Route::post('requests/{technicalServiceRequest}/invoice-serials/recheck', [TechnicalServiceController::class, 'recheckInvoiceSerials'])
            ->middleware('panel.access:technical_service_manage')
            ->name('api.technical-service.requests.invoice-serials.recheck');
        Route::post('requests/{technicalServiceRequest}/invoice-serials/{serial}/add', [TechnicalServiceController::class, 'addInvoiceSerial'])
            ->middleware('panel.access:technical_service_manage')
            ->name('api.technical-service.requests.invoice-serials.add');
        Route::delete('requests/{technicalServiceRequest}/invoice-serials/{serial}/remove', [TechnicalServiceController::class, 'removeInvoiceSerial'])
            ->middleware('panel.access:technical_service_manage')
            ->name('api.technical-service.requests.invoice-serials.remove');
        Route::post('requests/{technicalServiceRequest}/invoice-serials/add-all', [TechnicalServiceController::class, 'addAllInvoiceSerials'])
            ->middleware('panel.access:technical_service_manage')
            ->name('api.technical-service.requests.invoice-serials.add-all');
        Route::get('requests/{technicalServiceRequest}/uploads/{upload}', [TechnicalServiceController::class, 'showUpload'])
            ->middleware('panel.access:technical_service')
            ->name('api.technical-service.requests.uploads.show');
    });

Route::middleware(['auth', 'panel.session', 'panel.access:admin_panel'])
    ->prefix('api/admin/technical-service')
    ->group(function (): void {
        Route::get('serial-context', [TechnicalServiceQrLinkController::class, 'serialContext']);
        Route::post('qr-links', [TechnicalServiceQrLinkController::class, 'store']);
    });

Route::middleware(['auth', 'panel.session'])
    ->get('admin/forms', fn () => Inertia::render('panel/admin/technical-service-qr-links', [
        'page' => [
            'title' => 'Teknik Servis QR Linkleri',
            'slug' => 'admin_technical_service_qr_links',
            'routePath' => '/admin/forms',
            'component' => 'panel/admin/technical-service-qr-links',
            'layoutType' => 'admin',
            'description' => 'Teknik servis QR/link üretimi ve müşteri test akışı',
            'buttons' => [],
        ],
    ]))
    ->middleware('panel.access:admin_panel')
    ->name('admin.forms');
