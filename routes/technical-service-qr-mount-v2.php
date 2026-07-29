<?php

use App\Http\Controllers\Api\ExternalExecutionControlPlaneController;
use App\Http\Controllers\Api\TechnicalServiceController;
use App\Http\Controllers\Api\TechnicalServiceMessageDispatchController;
use App\Http\Controllers\Api\TechnicalServiceMessageTemplateController;
use App\Http\Controllers\Api\TechnicalServiceMessagingSettingsController;
use App\Http\Controllers\Api\TechnicalServicePaymentProviderSettingsController;
use App\Http\Controllers\Api\TechnicalServiceQrFlowSettingsController;
use App\Http\Controllers\Api\TechnicalServiceQrLinkController;
use App\Http\Controllers\PublicMountPaymentController;
use App\Http\Controllers\PublicMountRequestController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('mount-request/{token}', [PublicMountRequestController::class, 'show'])
    ->where('token', '[^/]+')
    ->name('mount-request.show');
Route::post('mount-request/{token}/check', [PublicMountRequestController::class, 'check'])
    ->where('token', '[^/]+')
    ->name('mount-request.check');
Route::get('mount-request/{token}/form', [PublicMountRequestController::class, 'form'])
    ->where('token', '[^/]+')
    ->name('mount-request.form');
Route::get('mount-request/{token}/payment', [PublicMountRequestController::class, 'paymentStep'])
    ->where('token', '[^/]+')
    ->name('mount-request.payment.step');
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
Route::match(['GET', 'POST'], 'mount-payment/iyzico/callback', [PublicMountPaymentController::class, 'providerCallback'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('mount-payment.callback');
Route::get('mount-payment/{token}', [PublicMountPaymentController::class, 'show'])
    ->where('token', '[^/]+')
    ->name('mount-payment.show');
Route::post('mount-payment/{token}/fake-approve', [PublicMountPaymentController::class, 'approve'])
    ->where('token', '[^/]+')
    ->name('mount-payment.fake-token.approve');

Route::middleware(['auth', 'panel.session'])
    ->prefix('api/technical-service')
    ->group(function (): void {
        Route::get('execution-control', [ExternalExecutionControlPlaneController::class, 'show'])
            ->middleware('panel.access:technical_service,technical_service_manage,technical_service_admin,admin_panel')
            ->name('api.technical-service.execution-control.show');
        Route::post('execution-control', [ExternalExecutionControlPlaneController::class, 'update'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.execution-control.update');
        Route::get('qr-products', [TechnicalServiceQrLinkController::class, 'index'])
            ->middleware('panel.access:technical_service_manage')
            ->name('api.technical-service.qr-products.index');
        Route::get('qr-products/serial-context', [TechnicalServiceQrLinkController::class, 'serialContext'])
            ->middleware('panel.access:technical_service_manage')
            ->name('api.technical-service.qr-products.serial-context');
        Route::post('qr-products', [TechnicalServiceQrLinkController::class, 'store'])
            ->middleware('panel.access:technical_service_manage')
            ->name('api.technical-service.qr-products.store');
        Route::post('qr-products/bulk', [TechnicalServiceQrLinkController::class, 'bulk'])
            ->middleware('panel.access:technical_service_manage')
            ->name('api.technical-service.qr-products.bulk');
        Route::post('qr-products/{link}/printed', [TechnicalServiceQrLinkController::class, 'markPrinted'])
            ->middleware('panel.access:technical_service_manage')
            ->name('api.technical-service.qr-products.printed');
        Route::get('qr-products/{link}/svg', [TechnicalServiceQrLinkController::class, 'svg'])
            ->middleware('panel.access:technical_service_manage')
            ->name('api.technical-service.qr-products.svg');
        Route::get('qr-flow-settings', [TechnicalServiceQrFlowSettingsController::class, 'show'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.qr-flow-settings.show');
        Route::patch('qr-flow-settings', [TechnicalServiceQrFlowSettingsController::class, 'update'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.qr-flow-settings.update');
        Route::get('messaging-settings', [TechnicalServiceMessagingSettingsController::class, 'show'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.messaging-settings.show');
        Route::patch('messaging-settings', [TechnicalServiceMessagingSettingsController::class, 'update'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.messaging-settings.update');
        Route::get('messaging-settings/execution-mode/readiness', [TechnicalServiceMessagingSettingsController::class, 'executionModeReadiness'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.messaging-settings.execution-mode.readiness');
        Route::post('messaging-settings/execution-mode', [TechnicalServiceMessagingSettingsController::class, 'updateExecutionMode'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.messaging-settings.execution-mode.update');
        Route::post('messaging-settings/reset', [TechnicalServiceMessagingSettingsController::class, 'reset'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.messaging-settings.reset');
        Route::get('messaging-settings/manual-e2e/readiness', [TechnicalServiceMessagingSettingsController::class, 'manualE2EReadiness'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.messaging-settings.manual-e2e.readiness');
        Route::post('messaging-settings/manual-e2e/enable', [TechnicalServiceMessagingSettingsController::class, 'enableManualE2E'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.messaging-settings.manual-e2e.enable');
        Route::post('messaging-settings/manual-e2e/freeze', [TechnicalServiceMessagingSettingsController::class, 'freezeManualE2E'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.messaging-settings.manual-e2e.freeze');
        Route::post('messaging-settings/validate-phone', [TechnicalServiceMessagingSettingsController::class, 'validatePhone'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.messaging-settings.validate-phone');
        Route::post('messaging-settings/nac-sms/credentials', [TechnicalServiceMessagingSettingsController::class, 'saveNacSmsCredentials'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.messaging-settings.nac-sms.credentials.save');
        Route::post('messaging-settings/nac-sms/credentials/clear', [TechnicalServiceMessagingSettingsController::class, 'clearNacSmsCredentials'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.messaging-settings.nac-sms.credentials.clear');
        Route::post('messaging-settings/nac-sms/test-send', [TechnicalServiceMessagingSettingsController::class, 'testNacSms'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.messaging-settings.nac-sms.test-send');
        Route::post('messaging-settings/evo-whatsapp/credentials', [TechnicalServiceMessagingSettingsController::class, 'saveEvoWhatsappCredentials'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.messaging-settings.evo-whatsapp.credentials.save');
        Route::post('messaging-settings/evo-whatsapp/credentials/clear', [TechnicalServiceMessagingSettingsController::class, 'clearEvoWhatsappCredentials'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.messaging-settings.evo-whatsapp.credentials.clear');
        Route::post('messaging-settings/mikro-api/credentials', [TechnicalServiceMessagingSettingsController::class, 'saveMikroApiCredentials'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.messaging-settings.mikro-api.credentials.save');
        Route::post('messaging-settings/mikro-api/credentials/clear', [TechnicalServiceMessagingSettingsController::class, 'clearMikroApiCredentials'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.messaging-settings.mikro-api.credentials.clear');
        Route::post('messaging-settings/mikro-api/connection-test', [TechnicalServiceMessagingSettingsController::class, 'testMikroApiConnection'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.messaging-settings.mikro-api.connection-test');
        Route::get('message-templates', [TechnicalServiceMessageTemplateController::class, 'index'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.message-templates.index');
        Route::get('message-templates/variables', [TechnicalServiceMessageTemplateController::class, 'variables'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.message-templates.variables');
        Route::post('message-templates/preview', [TechnicalServiceMessageTemplateController::class, 'preview'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.message-templates.preview');
        Route::post('message-templates', [TechnicalServiceMessageTemplateController::class, 'store'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.message-templates.store');
        Route::post('message-templates/restore-default', [TechnicalServiceMessageTemplateController::class, 'restoreDefault'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.message-templates.restore-default');
        Route::post('message-templates/test-send', [TechnicalServiceMessageTemplateController::class, 'testSend'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.message-templates.test-send');
        Route::get('message-dispatches', [TechnicalServiceMessageDispatchController::class, 'index'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.message-dispatches.index');
        Route::get('message-dispatches/{dispatch}', [TechnicalServiceMessageDispatchController::class, 'show'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.message-dispatches.show');
        Route::post('message-dispatches/{dispatch}/cancel', [TechnicalServiceMessageDispatchController::class, 'cancel'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.message-dispatches.cancel');
        Route::post('message-dispatches/{dispatch}/retry', [TechnicalServiceMessageDispatchController::class, 'retry'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.message-dispatches.retry');
        Route::post('message-dispatches/{dispatch}/force-resend', [TechnicalServiceMessageDispatchController::class, 'forceResend'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.message-dispatches.force-resend');
        Route::get('payment-provider-settings', [TechnicalServicePaymentProviderSettingsController::class, 'show'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.payment-provider-settings.show');
        Route::patch('payment-provider-settings', [TechnicalServicePaymentProviderSettingsController::class, 'update'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.payment-provider-settings.update');
        Route::post('payment-provider-settings/credentials', [TechnicalServicePaymentProviderSettingsController::class, 'saveCredentials'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.payment-provider-settings.credentials.save');
        Route::post('payment-provider-settings/credentials/clear', [TechnicalServicePaymentProviderSettingsController::class, 'clearCredentials'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.payment-provider-settings.credentials.clear');
        Route::post('payment-provider-settings/health-check', [TechnicalServicePaymentProviderSettingsController::class, 'healthCheck'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.payment-provider-settings.health-check');
        Route::get('mail-transport-settings', [TechnicalServicePaymentProviderSettingsController::class, 'mailSettings'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.mail-transport-settings.show');
        Route::post('mail-transport-settings/outgoing', [TechnicalServicePaymentProviderSettingsController::class, 'saveOutgoingMailSettings'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.mail-transport-settings.outgoing.save');
        Route::post('mail-transport-settings/outgoing/clear', [TechnicalServicePaymentProviderSettingsController::class, 'clearOutgoingMailSettings'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.mail-transport-settings.outgoing.clear');
        Route::post('mail-transport-settings/outgoing/test', [TechnicalServicePaymentProviderSettingsController::class, 'sendOutgoingTestMail'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.mail-transport-settings.outgoing.test');
        Route::post('mail-transport-settings/incoming', [TechnicalServicePaymentProviderSettingsController::class, 'saveIncomingMailSettings'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.mail-transport-settings.incoming.save');
        Route::post('mail-transport-settings/incoming/clear', [TechnicalServicePaymentProviderSettingsController::class, 'clearIncomingMailSettings'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.mail-transport-settings.incoming.clear');
        Route::post('mail-transport-settings/incoming/test', [TechnicalServicePaymentProviderSettingsController::class, 'testIncomingMailSettings'])
            ->middleware('panel.access:technical_service_admin')
            ->name('api.technical-service.mail-transport-settings.incoming.test');
        Route::post('requests/{technicalServiceRequest}/technicians/{technician}/route-quote', [TechnicalServiceController::class, 'routeQuote'])
            ->middleware('panel.access:technical_service_manage')
            ->name('api.technical-service.requests.technicians.route-quote');
        Route::post('requests/{technicalServiceRequest}/technicians/{technician}/earnings-message', [TechnicalServiceController::class, 'technicianEarningsMessage'])
            ->middleware('panel.access:technical_service_manage')
            ->name('api.technical-service.requests.technicians.earnings-message');
        Route::patch('requests/{technicalServiceRequest}/route-quote/manual', [TechnicalServiceController::class, 'manualRouteQuote'])
            ->middleware('panel.access:technical_service_manage')
            ->name('api.technical-service.requests.route-quote.manual');
        Route::post('requests/{technicalServiceRequest}/payments/extra-mount-fee', [TechnicalServiceController::class, 'createExtraMountFeePayment'])
            ->middleware('panel.access:technical_service_manage')
            ->name('api.technical-service.requests.payments.extra-mount-fee');
        Route::post('requests/{technicalServiceRequest}/payments/mount-extra-payment', [TechnicalServiceController::class, 'createExtraMountFeePayment'])
            ->middleware('panel.access:technical_service_manage')
            ->name('api.technical-service.requests.payments.mount-extra-payment');
        Route::get('requests/{technicalServiceRequest}/payments/{payment}/status', [TechnicalServiceController::class, 'mountPaymentStatus'])
            ->middleware('panel.access:technical_service_manage')
            ->name('api.technical-service.requests.payments.status');
        Route::post('requests/{technicalServiceRequest}/payments/{payment}/send-link', [TechnicalServiceController::class, 'sendMountPaymentLink'])
            ->middleware('panel.access:technical_service_manage')
            ->name('api.technical-service.requests.payments.send-link');
        Route::post('requests/{technicalServiceRequest}/payments/{payment}/cancel', [TechnicalServiceController::class, 'cancelMountPayment'])
            ->middleware('panel.access:technical_service_manage')
            ->name('api.technical-service.requests.payments.cancel');
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
