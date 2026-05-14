<?php

namespace App\Http\Controllers;

use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceQrLink;
use App\Services\TechnicalService\MountFlowDecisionService;
use App\Services\TechnicalService\MountRequestSubmitService;
use App\Services\TechnicalService\MountSessionEnrichmentService;
use App\Services\TechnicalService\SerialProductContextResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PublicMountRequestController extends Controller
{
    public function show(
        Request $request,
        string $token,
        SerialProductContextResolver $contextResolver,
        MountSessionEnrichmentService $enrichmentService,
        MountFlowDecisionService $decisionService,
    ) {
        $link = TechnicalServiceQrLink::findActiveByToken($token);

        if (! $link instanceof TechnicalServiceQrLink) {
            return Inertia::render('public/mount-request-v2', [
                'viewState' => 'invalid_link',
                'message' => 'Montaj talep linki geçersiz veya süresi dolmuş.',
            ])->toResponse($request)->setStatusCode(404);
        }

        $session = $this->prepareSession($link, $contextResolver, $enrichmentService);

        $decision = $decisionService->decide($session->fresh(['qrLink', 'payments']));
        $session = $session->fresh(['qrLink', 'payments']);
        $payment = $this->latestPayment($session);

        return Inertia::render('public/mount-request-v2', [
            'viewState' => $this->viewState($decision['decision']),
            'message' => $this->message($decision['decision']),
            'product' => [
                'product_name' => $session->context_payload['product_name'] ?? $link->product_name,
                'product_model' => $session->context_payload['product_model'] ?? $link->product_model,
                'serial_number' => $link->serial_number,
                'brand' => $session->context_payload['brand'] ?? $link->brand,
            ],
            'statusLabel' => $this->statusLabel($session),
            'actions' => [
                'payment_label' => 'Montaj ödemesi yap',
                'multi_product_label' => 'Birden fazla ürün için montaj talebim var',
                'continue_label' => 'Forma Devam Et',
                'create_payment_url' => route('mount-request.payment.create', ['token' => $token]),
                'multi_product_url' => route('mount-request.multi-product', ['token' => $token]),
                'submit_url' => route('mount-request.submit', ['token' => $token]),
            ],
            'payment' => $payment instanceof TechnicalServiceMountPayment ? [
                'amount' => number_format((float) $payment->amount, 2, '.', ''),
                'currency' => $payment->currency,
                'fake_approve_url' => $this->fakePaymentEnabled()
                    ? route('mount-payment.fake.approve', ['payment' => $payment, 'token' => $token])
                    : null,
            ] : null,
        ]);
    }

    public function submit(
        Request $request,
        string $token,
        SerialProductContextResolver $contextResolver,
        MountSessionEnrichmentService $enrichmentService,
        MountFlowDecisionService $decisionService,
        MountRequestSubmitService $submitService,
    ) {
        $link = TechnicalServiceQrLink::findActiveByToken($token);

        if (! $link instanceof TechnicalServiceQrLink) {
            return Inertia::render('public/mount-request-v2', [
                'viewState' => 'invalid_link',
                'message' => 'Montaj talep linki geçersiz veya süresi dolmuş.',
            ])->toResponse($request)->setStatusCode(404);
        }

        $session = $this->prepareSession($link, $contextResolver, $enrichmentService);
        $decision = $decisionService->decide($session->fresh(['qrLink', 'payments']));
        $session = $session->fresh(['qrLink', 'payments']);

        $this->assertCanSubmit($session, $decision['decision']);

        $payload = $this->validatedSubmitPayload($request);
        $technicalServiceRequest = $submitService->submit($session, [
            'customer_name' => trim($payload['first_name'].' '.$payload['last_name']),
            'customer_phone' => $payload['customer_phone'],
            'customer_city' => $payload['city'],
            'customer_district' => $payload['district'],
            'service_address' => $payload['address'],
        ]);

        return Inertia::render('public/mount-request-v2', [
            'viewState' => 'submitted',
            'message' => 'Montaj talebiniz alınmıştır.',
            'submitted' => [
                'mrn' => $technicalServiceRequest->mrn,
            ],
            'product' => [
                'product_name' => $technicalServiceRequest->product_name,
                'product_model' => $technicalServiceRequest->product_model,
                'serial_number' => $technicalServiceRequest->serial_number,
                'brand' => $session->context_payload['brand'] ?? $link->brand,
            ],
            'statusLabel' => $this->statusLabel($session),
        ]);
    }

    public function createFakePayment(string $token): RedirectResponse
    {
        abort_unless($this->fakePaymentEnabled(), 404);

        $link = $this->linkOrFail($token);
        $session = $this->sessionForLink($link);
        $payment = $this->latestPayment($session);

        if (! $payment instanceof TechnicalServiceMountPayment || $payment->status !== TechnicalServiceMountPayment::STATUS_PENDING) {
            TechnicalServiceMountPayment::query()->create([
                'technical_service_mount_session_id' => $session->id,
                'provider' => 'fake',
                'provider_reference' => 'fake-'.hash('sha256', $session->id.'|'.microtime(true)),
                'status' => TechnicalServiceMountPayment::STATUS_PENDING,
                'amount' => 3500,
                'currency' => 'TRY',
            ]);
        }

        $session->forceFill([
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PENDING,
            'customer_entry_mode' => TechnicalServiceMountSession::ENTRY_SINGLE_PRODUCT,
            'decision_status' => TechnicalServiceMountSession::DECISION_READY,
        ])->save();

        return redirect()->route('mount-request.show', ['token' => $token]);
    }

    public function chooseMultiProduct(string $token): RedirectResponse
    {
        $link = $this->linkOrFail($token);
        $session = $this->sessionForLink($link);

        $session->forceFill([
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_SKIPPED_MULTI_PRODUCT,
            'customer_entry_mode' => TechnicalServiceMountSession::ENTRY_MULTI_PRODUCT_WITHOUT_PAYMENT,
            'decision_status' => TechnicalServiceMountSession::DECISION_FORM_OPEN,
        ])->save();

        return redirect()->route('mount-request.show', ['token' => $token]);
    }

    public function approveFakePayment(Request $request, TechnicalServiceMountPayment $payment): RedirectResponse
    {
        abort_unless($this->fakePaymentEnabled(), 404);
        abort_unless($payment->provider === 'fake', 404);

        $payment->forceFill([
            'status' => TechnicalServiceMountPayment::STATUS_PAID,
            'paid_at' => $payment->paid_at ?? now(),
        ])->save();

        $session = $payment->session;
        $session->forceFill([
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PAID,
            'customer_entry_mode' => TechnicalServiceMountSession::ENTRY_PAID_SINGLE_PRODUCT,
            'decision_status' => TechnicalServiceMountSession::DECISION_FORM_OPEN,
        ])->save();

        $token = $request->query('token');

        if (is_string($token) && $token !== '') {
            return redirect()->route('mount-request.show', ['token' => $token]);
        }

        return redirect('/');
    }

    private function sessionForLink(TechnicalServiceQrLink $link): TechnicalServiceMountSession
    {
        $session = $link->sessions()
            ->latest('id')
            ->first();

        if ($session instanceof TechnicalServiceMountSession) {
            return $session;
        }

        return TechnicalServiceMountSession::startForLink($link)['session']->fresh();
    }

    private function prepareSession(
        TechnicalServiceQrLink $link,
        SerialProductContextResolver $contextResolver,
        MountSessionEnrichmentService $enrichmentService,
    ): TechnicalServiceMountSession {
        $session = $this->sessionForLink($link);

        if ((int) $session->check_attempt_count !== 0) {
            return $session;
        }

        $context = $contextResolver->resolve($link->serial_number, [
            'product_name' => $link->product_name,
            'product_model' => $link->product_model,
            'brand' => $link->brand,
            'link_type' => $link->link_type,
        ]);

        return $enrichmentService->applyContext($session, [
            'sale_mount_status' => $context['sale_mount_status'],
            'product_name' => $context['product_name'] ?? $link->product_name,
            'product_model' => $context['product_model'] ?? $link->product_model,
            'brand' => $context['brand'] ?? $link->brand,
            'activation_code' => $context['activation_code'],
            'invoice_customer_type' => $context['invoice_customer_type'],
            'resolver_payload' => $context['context_payload'],
        ]);
    }

    private function assertCanSubmit(TechnicalServiceMountSession $session, string $decision): void
    {
        $allowedDecision = in_array($decision, [
            MountFlowDecisionService::DECISION_SHOW_FORM,
            MountFlowDecisionService::DECISION_SHOW_MULTI_PRODUCT_FORM_WITHOUT_PAYMENT,
            MountFlowDecisionService::DECISION_SHOW_CHECK_FAILED_BUT_ALLOW_SUBMIT,
        ], true);

        $unpaidSingleProduct = $session->sale_mount_status === TechnicalServiceMountSession::SALE_MONTAJ_HARIC
            && $session->mount_payment_status !== TechnicalServiceMountSession::PAYMENT_PAID
            && $session->customer_entry_mode !== TechnicalServiceMountSession::ENTRY_MULTI_PRODUCT_WITHOUT_PAYMENT;

        if (! $allowedDecision || $unpaidSingleProduct) {
            throw ValidationException::withMessages([
                'form' => 'Montaj ödemesi tamamlanmadan form gönderilemez.',
            ]);
        }
    }

    /**
     * @return array{first_name:string,last_name:string,customer_phone:string,city:string,district:string,address:string}
     */
    private function validatedSubmitPayload(Request $request): array
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:40'],
            'city' => ['required', 'string', 'max:100'],
            'district' => ['required', 'string', 'max:100'],
            'address' => ['required', 'string', 'max:1000'],
            'installation_consent' => ['accepted'],
            'kvkk_consent' => ['accepted'],
        ], [
            'first_name.required' => 'İsim zorunludur.',
            'last_name.required' => 'Soyisim zorunludur.',
            'phone.required' => 'Telefon numarası zorunludur.',
            'city.required' => 'İl zorunludur.',
            'district.required' => 'İlçe zorunludur.',
            'address.required' => 'Adres zorunludur.',
            'installation_consent.accepted' => 'Montaj şartlarını kabul etmelisiniz.',
            'kvkk_consent.accepted' => 'KVKK / Aydınlatma ve Açık Rıza Onayı zorunludur.',
        ]);

        $phone = $this->normalizeTurkishPhone($validated['phone']);

        if ($phone === null) {
            throw ValidationException::withMessages([
                'phone' => 'Telefon numarası +90 sonrası 10 hane olmalıdır.',
            ]);
        }

        return [
            'first_name' => trim((string) $validated['first_name']),
            'last_name' => trim((string) $validated['last_name']),
            'customer_phone' => $phone,
            'city' => trim((string) $validated['city']),
            'district' => trim((string) $validated['district']),
            'address' => trim((string) $validated['address']),
        ];
    }

    private function normalizeTurkishPhone(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        if (str_starts_with($digits, '90') && strlen($digits) === 12) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $digits = substr($digits, 1);
        }

        if (! preg_match('/^\d{10}$/', $digits)) {
            return null;
        }

        return '+90'.$digits;
    }

    private function linkOrFail(string $token): TechnicalServiceQrLink
    {
        $link = TechnicalServiceQrLink::findActiveByToken($token);

        abort_unless($link instanceof TechnicalServiceQrLink, 404);

        return $link;
    }

    private function latestPayment(TechnicalServiceMountSession $session): ?TechnicalServiceMountPayment
    {
        return $session->payments()
            ->latest('id')
            ->first();
    }

    private function fakePaymentEnabled(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    private function viewState(string $decision): string
    {
        return match ($decision) {
            MountFlowDecisionService::DECISION_SHOW_FORM => 'form_ready',
            MountFlowDecisionService::DECISION_SHOW_PAYMENT => 'payment_required',
            MountFlowDecisionService::DECISION_SHOW_MULTI_PRODUCT_FORM_WITHOUT_PAYMENT => 'multi_product_ready',
            MountFlowDecisionService::DECISION_SHOW_CHECK_FAILED_BUT_ALLOW_SUBMIT => 'check_pending',
            MountFlowDecisionService::DECISION_SHOW_INVALID_LINK => 'invalid_link',
            default => 'unknown_error',
        };
    }

    private function message(string $decision): string
    {
        return match ($decision) {
            MountFlowDecisionService::DECISION_SHOW_FORM => 'Montaj talep formunuz açılmaya hazır.',
            MountFlowDecisionService::DECISION_SHOW_PAYMENT => 'Bu ürün için montaj ödemesi gereklidir.',
            MountFlowDecisionService::DECISION_SHOW_MULTI_PRODUCT_FORM_WITHOUT_PAYMENT => 'Birden fazla ürün için montaj talebiniz alınmaya hazır. Operasyon ekibi sizinle iletişime geçecektir.',
            MountFlowDecisionService::DECISION_SHOW_CHECK_FAILED_BUT_ALLOW_SUBMIT => 'Seri / montaj kontrolü şu anda tamamlanamadı. Formu doldurabilirsiniz; operasyon ekibi kontrolü tamamlayacaktır.',
            MountFlowDecisionService::DECISION_SHOW_INVALID_LINK => 'Montaj talep linki geçersiz veya süresi dolmuş.',
            default => 'Montaj talep akışı şu anda başlatılamadı.',
        };
    }

    private function statusLabel(TechnicalServiceMountSession $session): string
    {
        if (in_array($session->sale_mount_status, [
            TechnicalServiceMountSession::SALE_MONTAJ_DAHIL,
            TechnicalServiceMountSession::SALE_MONTAJ_SONRADAN_DAHIL,
        ], true)) {
            return 'Montaj dahil';
        }

        if ($session->sale_mount_status === TechnicalServiceMountSession::SALE_MONTAJ_HARIC) {
            return $session->mount_payment_status === TechnicalServiceMountSession::PAYMENT_PAID
                ? 'Montaj ödemesi alındı'
                : 'Montaj ödemesi gerekli';
        }

        if ($session->sale_mount_status === TechnicalServiceMountSession::SALE_CHECK_FAILED) {
            return 'Kontrol bekliyor';
        }

        return 'Kontrol bekliyor';
    }
}
