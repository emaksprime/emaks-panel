<?php

namespace App\Http\Controllers;

use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceQrLink;
use App\Services\TechnicalService\TechnicalServicePaymentSettlementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PublicMountPaymentController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $payment = $this->paymentForToken($token);
        $serviceRequest = $payment->technicalServiceRequest;
        $mountFormUrl = $this->mountFormUrl($payment);
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];

        return Inertia::render('public/mount-payment', [
            'payment' => [
                'id' => $payment->id,
                'status' => $payment->status,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'purpose' => $payload['purpose'] ?? $payload['reason'] ?? 'mount_extra',
                'note' => $payload['note'] ?? null,
                'payment_url' => $payment->payment_url,
                'mount_form_url' => $mountFormUrl,
                'fake_approve_url' => $this->canShowFakeApprove($payment)
                    ? route('mount-payment.fake-token.approve', ['token' => $payment->provider_reference])
                    : null,
            ],
            'requestSummary' => [
                'mrn' => $serviceRequest?->mrn,
                'customer' => $this->maskName($serviceRequest?->customer_name),
                'phone' => $this->maskPhone($serviceRequest?->customer_phone),
                'product_name' => $serviceRequest?->product_name ?? $payment->session?->qrLink?->product_name,
                'product_model' => $serviceRequest?->product_model ?? $payment->session?->qrLink?->product_model,
                'serial_number' => $serviceRequest?->serial_number ?? $payment->session?->serial_number,
            ],
        ]);
    }

    public function approve(string $token, TechnicalServicePaymentSettlementService $settlementService): RedirectResponse
    {
        $payment = $this->paymentForToken($token);
        abort_unless($this->canShowFakeApprove($payment), 404);

        $settlementService->markPaid($payment, [
            'source' => 'fake_public_payment_page',
        ]);

        $freshPayment = $payment->fresh(['session.qrLink']);
        $mountFormUrl = $freshPayment instanceof TechnicalServiceMountPayment
            ? $this->mountFormUrl($freshPayment)
            : null;
        if ($mountFormUrl !== null) {
            return redirect($mountFormUrl);
        }

        return redirect()->route('mount-payment.show', ['token' => $token]);
    }

    private function paymentForToken(string $token): TechnicalServiceMountPayment
    {
        return TechnicalServiceMountPayment::query()
            ->where('provider_reference', $token)
            ->firstOrFail();
    }

    private function canShowFakeApprove(TechnicalServiceMountPayment $payment): bool
    {
        return $payment->provider === 'fake'
            && $payment->status === TechnicalServiceMountPayment::STATUS_PENDING
            && $this->fakeApproveEnabled();
    }

    private function mountFormUrl(TechnicalServiceMountPayment $payment): ?string
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

        return route('mount-request.show', ['token' => $qrLink->publicToken()]);
    }

    private function fakeApproveEnabled(): bool
    {
        return ! app()->environment('production')
            && strtolower((string) config('payments.provider', 'fake')) === 'fake'
            && filter_var(config('payments.enable_fake_approve', false), FILTER_VALIDATE_BOOLEAN);
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
