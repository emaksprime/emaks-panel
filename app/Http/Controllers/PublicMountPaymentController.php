<?php

namespace App\Http\Controllers;

use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceQrLink;
use App\Services\Payments\PaymentProviderManager;
use App\Services\TechnicalService\TechnicalServicePaymentActionPresenter;
use App\Services\TechnicalService\TechnicalServicePaymentSettlementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PublicMountPaymentController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $payment = $this->paymentForToken($token);
        $serviceRequest = $payment->technicalServiceRequest;
        $mountFormUrl = $this->mountFormUrl($request, $payment);
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];

        $fakeApproveUrl = $this->canShowFakeApprove($payment)
            ? route('mount-payment.fake-token.approve', ['token' => $payment->provider_reference], false)
            : null;

        return Inertia::render('public/mount-payment', [
            'payment' => array_merge([
                'id' => $payment->id,
                'status' => $payment->status,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'source' => $payload['source'] ?? null,
                'purpose' => $payload['purpose'] ?? $payload['reason'] ?? 'mount_extra',
                'purpose_label' => $this->purposeLabel((string) ($payload['purpose'] ?? $payload['reason'] ?? 'mount_extra')),
                'service_amount' => (float) ($payload['service_amount'] ?? 0),
                'part_amount' => (float) ($payload['part_amount'] ?? 0),
                'total_amount' => (float) ($payload['total_amount'] ?? $payment->amount),
                'note' => $payload['note'] ?? null,
                'message_template' => $payload['message_template'] ?? null,
                'payment_url' => $payment->payment_url,
                'mount_form_url' => $mountFormUrl,
            ], TechnicalServicePaymentActionPresenter::forPayment($payment, $fakeApproveUrl)),
            'requestSummary' => [
                'mrn' => $serviceRequest?->mrn,
                'service_code' => $serviceRequest?->service_code,
                'customer' => $this->maskName($serviceRequest?->customer_name),
                'phone' => $this->maskPhone($serviceRequest?->customer_phone),
                'product_name' => $serviceRequest?->product_name ?? $payment->session?->qrLink?->product_name,
                'product_model' => $serviceRequest?->product_model ?? $payment->session?->qrLink?->product_model,
                'serial_number' => $serviceRequest?->serial_number ?? $payment->session?->serial_number,
            ],
        ]);
    }

    public function approve(Request $request, string $token, TechnicalServicePaymentSettlementService $settlementService): RedirectResponse
    {
        $payment = $this->paymentForToken($token);
        abort_unless($this->canShowFakeApprove($payment), 404);

        $settlementService->markPaid($payment, [
            'source' => 'fake_public_payment_page',
        ]);

        $freshPayment = $payment->fresh(['session.qrLink']);
        $mountFormPath = $freshPayment instanceof TechnicalServiceMountPayment
            ? $this->mountFormPath($freshPayment)
            : null;
        if ($mountFormPath !== null) {
            return new RedirectResponse($mountFormPath);
        }

        return redirect()->route('mount-payment.show', ['token' => $token]);
    }

    public function providerCallback(Request $request, PaymentProviderManager $paymentProviderManager): RedirectResponse|HttpResponse
    {
        $payment = $this->paymentForProviderCallback($request);
        if (! $payment instanceof TechnicalServiceMountPayment) {
            return $this->providerCallbackStatusResponse(
                title: 'Ödeme dönüş bilgisi eşleştirilemedi',
                message: 'Ödeme sağlayıcı dönüş bilgisi ödeme kaydıyla eşleştirilemedi. Panelden ödeme durumunu kontrol edin.',
                statusCode: 422,
            );
        }

        if ($this->canSyncProviderReturn($payment)) {
            try {
                $paymentProviderManager->syncPayment($payment);
            } catch (Throwable) {
                return $this->providerCallbackStatusResponse(
                    title: 'Ödeme durumu doğrulanamadı',
                    message: 'Ödeme sağlayıcı durumu doğrulanamadı. Ödeme paid olarak işaretlenmedi.',
                    statusCode: 502,
                );
            }

            $payment = $payment->fresh();
        }

        $providerReference = trim((string) ($payment->provider_reference ?? ''));
        if ($providerReference === '') {
            return $this->providerCallbackStatusResponse(
                title: 'Ödeme referansı bulunamadı',
                message: 'Ödeme sağlayıcı referansı bulunamadı. Ödeme paid olarak işaretlenmedi.',
                statusCode: 422,
            );
        }

        return redirect()->route('mount-payment.show', ['token' => $providerReference]);
    }

    private function providerCallbackStatusResponse(string $title, string $message, int $statusCode): HttpResponse
    {
        $safeTitle = e($title);
        $safeMessage = e($message);

        return response(<<<HTML
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$safeTitle}</title>
    <style>
        body { margin: 0; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f8fafc; color: #0f172a; }
        main { min-height: 100vh; display: grid; place-items: center; padding: 24px; }
        section { max-width: 560px; border: 1px solid #e2e8f0; border-radius: 12px; background: white; padding: 24px; box-shadow: 0 18px 45px rgba(15, 23, 42, .08); }
        h1 { margin: 0 0 12px; font-size: 22px; line-height: 1.25; }
        p { margin: 0; line-height: 1.6; color: #475569; }
        strong { color: #9f1239; }
    </style>
</head>
<body>
    <main>
        <section>
            <h1>{$safeTitle}</h1>
            <p>{$safeMessage}</p>
            <p><strong>Bu sayfa ziyareti ödeme kaydını paid yapmaz.</strong></p>
        </section>
    </main>
</body>
</html>
HTML, $statusCode)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    private function paymentForToken(string $token): TechnicalServiceMountPayment
    {
        return TechnicalServiceMountPayment::query()
            ->where('provider_reference', $token)
            ->firstOrFail();
    }

    private function paymentForProviderCallback(Request $request): ?TechnicalServiceMountPayment
    {
        $token = $this->callbackValue($request, ['token', 'provider_token', 'provider_reference']);
        if ($token !== null) {
            $payment = TechnicalServiceMountPayment::query()
                ->where('provider', 'iyzico')
                ->where('provider_reference', $token)
                ->first();
            if ($payment instanceof TechnicalServiceMountPayment) {
                return $payment;
            }
        }

        $conversationId = $this->callbackValue($request, ['conversationId', 'conversation_id']);
        if ($conversationId !== null && str_starts_with($conversationId, 'payment:')) {
            $paymentId = substr($conversationId, strlen('payment:'));
            if (ctype_digit($paymentId)) {
                return TechnicalServiceMountPayment::query()
                    ->whereKey((int) $paymentId)
                    ->where('provider', 'iyzico')
                    ->first();
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function callbackValue(Request $request, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $request->query($key, $request->input($key));
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function canSyncProviderReturn(TechnicalServiceMountPayment $payment): bool
    {
        return $payment->provider === 'iyzico'
            && $payment->status === TechnicalServiceMountPayment::STATUS_PENDING
            && trim((string) ($payment->provider_reference ?? '')) !== '';
    }

    private function canShowFakeApprove(TechnicalServiceMountPayment $payment): bool
    {
        return $payment->provider === 'fake'
            && $payment->status === TechnicalServiceMountPayment::STATUS_PENDING
            && $this->fakeApproveEnabled();
    }

    private function mountFormUrl(Request $request, TechnicalServiceMountPayment $payment): ?string
    {
        $path = $this->mountFormPath($payment);

        return $path === null ? null : $request->getUriForPath($path);
    }

    private function mountFormPath(TechnicalServiceMountPayment $payment): ?string
    {
        if ($payment->technical_service_request_id !== null) {
            return null;
        }

        $session = $payment->session;
        if (! $session instanceof TechnicalServiceMountSession
            || $session->decision_status === TechnicalServiceMountSession::DECISION_SUBMITTED) {
            return null;
        }

        $qrLink = $session->qrLink;
        if (! $qrLink instanceof TechnicalServiceQrLink) {
            return null;
        }

        return route('mount-request.form', ['token' => $qrLink->publicToken()], false);
    }

    private function fakeApproveEnabled(): bool
    {
        return ! app()->environment('production')
            && strtolower((string) config('payments.provider', 'fake')) === 'fake'
            && filter_var(config('payments.enable_fake_approve', false), FILTER_VALIDATE_BOOLEAN);
    }

    private function purposeLabel(string $purpose): string
    {
        return match ($purpose) {
            'service_payment' => 'Servis ücreti',
            'part_payment' => 'Parça ücreti',
            'service_and_part_payment' => 'Servis + parça ücreti',
            'route_fee' => 'Yol ücreti',
            'multi_product', 'multi_product_mount' => 'Çoklu ürün montaj ödemesi',
            'manual_mount_payment', 'mount_extra' => 'Montaj ek ödemesi',
            default => 'Ek ödeme',
        };
    }

    private function maskName(?string $name): string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return '-';
        }

        return mb_substr($name, 0, 1, 'UTF-8').'***';
    }

    private function maskPhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if (strlen($digits) < 4) {
            return '-';
        }

        return '***'.substr($digits, -4);
    }
}
