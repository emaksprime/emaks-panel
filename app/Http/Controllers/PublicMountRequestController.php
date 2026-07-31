<?php

namespace App\Http\Controllers;

use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceQrLink;
use App\Services\TechnicalService\MountFlowDecisionService;
use App\Services\TechnicalService\MountRequestSubmitService;
use App\Services\TechnicalService\MountSessionEnrichmentService;
use App\Services\TechnicalService\MikroInvoiceSerialsService;
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
            'message' => $this->message($decision['decision'], $session),
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
                'multi_product_lookup_url' => route('mount-request.invoice-serials.check', ['token' => $token]),
                'submit_url' => route('mount-request.submit', ['token' => $token]),
            ],
            'payment' => $payment instanceof TechnicalServiceMountPayment ? [
                'amount' => number_format((float) $payment->amount, 2, '.', ''),
                'currency' => $payment->currency,
                'fake_approve_url' => $this->fakePaymentEnabled()
                    ? route('mount-payment.fake.approve', ['payment' => $payment, 'token' => $token])
                    : null,
            ] : null,
            'allowMultiProductRequest' => $this->allowMultiProductRequest($session),
        ]);
    }

    public function submit(
        Request $request,
        string $token,
        SerialProductContextResolver $contextResolver,
        MountSessionEnrichmentService $enrichmentService,
        MountFlowDecisionService $decisionService,
        MountRequestSubmitService $submitService,
        MikroInvoiceSerialsService $invoiceSerialsService,
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

        if (($payload['multiple_products'] ?? false) && $this->allowMultiProductRequest($session)) {
            $session->forceFill([
                'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_SKIPPED_MULTI_PRODUCT,
                'customer_entry_mode' => TechnicalServiceMountSession::ENTRY_MULTI_PRODUCT_WITHOUT_PAYMENT,
            ])->save();
            $session = $session->fresh(['qrLink', 'payments']);
        }

        if (($payload['multiple_products'] ?? false) || $session->customer_entry_mode === TechnicalServiceMountSession::ENTRY_MULTI_PRODUCT_WITHOUT_PAYMENT) {
            $session = $this->ensureInvoiceSerialContext($session, $invoiceSerialsService);
        }

        $technicalServiceRequest = $submitService->submit($session, [
            'customer_name' => trim($payload['first_name'].' '.$payload['last_name']),
            'customer_phone' => $payload['customer_phone'],
            'customer_city' => $payload['city'],
            'customer_district' => $payload['district'],
            'service_address' => $payload['address'],
            'selected_invoice_serials' => $payload['selected_invoice_serials'],
            'location_latitude' => $payload['location_latitude'],
            'location_longitude' => $payload['location_longitude'],
            'location_place_id' => $payload['location_place_id'],
            'location_formatted_address' => $payload['location_formatted_address'],
            'location_map_url' => $payload['location_map_url'],
            'building_no' => $payload['building_no'],
            'apartment_no' => $payload['apartment_no'],
            'door_no' => $payload['door_no'],
            'floor_no' => $payload['floor_no'],
            'site_name' => $payload['site_name'],
            'door_photos' => $payload['door_photos'],
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
            'allowMultiProductRequest' => false,
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

    public function multiProductOptions(
        Request $request,
        string $token,
        SerialProductContextResolver $contextResolver,
        MountSessionEnrichmentService $enrichmentService,
        MikroInvoiceSerialsService $invoiceSerialsService,
    ) {
        $link = TechnicalServiceQrLink::findActiveByToken($token);

        if (! $link instanceof TechnicalServiceQrLink) {
            return response()->json([
                'ok' => false,
                'message' => 'Montaj talep linki geçersiz veya süresi dolmuş.',
                'selectable_serials' => [],
                'items' => [],
                'has_selectable_serials' => false,
                'operation_only_count' => 0,
                'returned_count' => 0,
                'blocked_count' => 0,
                'total_count' => 0,
            ], 404);
        }

        $session = $this->prepareSession($link, $contextResolver, $enrichmentService);

        $session = $this->ensureInvoiceSerialContext($session, $invoiceSerialsService);
        $context = $session->context_payload ?? [];
        $invoiceSerials = is_array($context['invoice_serials'] ?? null) ? $context['invoice_serials'] : [];
        $allRows = is_array($invoiceSerials['all_invoice_serials'] ?? null)
            ? $invoiceSerials['all_invoice_serials']
            : [];
        $primarySerial = mb_strtoupper($session->serial_number, 'UTF-8');
        $selectableRows = array_values(array_filter($allRows, fn (mixed $row): bool => is_array($row)
            && mb_strtoupper((string) ($row['serial_number'] ?? ''), 'UTF-8') !== $primarySerial));
        $publicRows = array_values(array_map(
            function (array $row): array {
                return [
                    'serial_number' => $this->nullableString($row['serial_number'] ?? null),
                    'product_name' => $this->nullableString($row['product_name'] ?? null),
                    'product_model' => $this->nullableString($row['product_model'] ?? null),
                ];
            },
            array_filter($selectableRows, fn (array $row): bool => (bool) ($row['customer_selectable'] ?? false)),
        ));
        $hasSelectableSerials = count($publicRows) > 0;
        $returnedCount = count(array_filter($allRows, fn (mixed $row): bool => is_array($row)
            && mb_strtoupper((string) ($row['serial_number'] ?? ''), 'UTF-8') !== $primarySerial
            && (bool) ($row['is_returned'] ?? false)
        ));
        $operationRowsCount = count(array_filter($allRows, fn (mixed $row): bool => is_array($row)
            && mb_strtoupper((string) ($row['serial_number'] ?? ''), 'UTF-8') !== $primarySerial));
        $blockedCount = count(array_filter($allRows, fn (mixed $row): bool => is_array($row)
            && mb_strtoupper((string) ($row['serial_number'] ?? ''), 'UTF-8') !== $primarySerial
            && ! (bool) ($row['is_returned'] ?? false)
            && (bool) ($row['is_responsibility_blocked'] ?? false)
        ));

        return response()->json([
            'ok' => true,
            'selectable_serials' => $publicRows,
            'items' => $publicRows,
            'has_selectable_serials' => $hasSelectableSerials,
            'operation_only_count' => max(0, $operationRowsCount - count($publicRows)),
            'returned_count' => $returnedCount,
            'blocked_count' => $blockedCount,
            'total_count' => $operationRowsCount,
            'message' => $hasSelectableSerials ? null : 'Ek ürün talebiniz operasyon ekibine iletilecek.',
        ]);
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
            'current_serial_state' => $context['current_serial_state'] ?? null,
            'has_current_sale' => $context['has_current_sale'] ?? null,
            'latest_event_type' => $context['latest_event_type'] ?? null,
            'latest_valid_sale_exists' => $context['latest_valid_sale_exists'] ?? null,
            'stock_code' => $context['stock_code'] ?? null,
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
     * @return array{first_name:string,last_name:string,customer_phone:string,city:string,district:string,address:string,multiple_products:bool,selected_invoice_serials:array<int,string>,location_latitude:?float,location_longitude:?float,location_place_id:?string,location_formatted_address:?string,location_map_url:?string,building_no:?string,apartment_no:?string,door_no:?string,floor_no:?string,site_name:?string,door_photos:array<string,\Illuminate\Http\UploadedFile|null>}
     */
    private function validatedSubmitPayload(Request $request): array
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:40'],
            'city' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:1000'],
            'location_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'location_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'location_place_id' => ['nullable', 'string', 'max:255'],
            'location_formatted_address' => ['nullable', 'string', 'max:2000'],
            'location_map_url' => ['nullable', 'url', 'max:1024'],
            'building_no' => ['nullable', 'string', 'max:80'],
            'apartment_no' => ['nullable', 'string', 'max:80'],
            'door_no' => ['nullable', 'string', 'max:80'],
            'floor_no' => ['nullable', 'string', 'max:80'],
            'site_name' => ['nullable', 'string', 'max:255'],
            'door_front_photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'door_side_photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'door_back_photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'installation_consent' => ['accepted'],
            'kvkk_consent' => ['accepted'],
            'multiple_products' => ['nullable', 'boolean'],
            'selected_invoice_serials' => ['nullable', 'array'],
            'selected_invoice_serials.*' => ['string', 'max:120'],
        ], [
            'first_name.required' => 'İsim zorunludur.',
            'last_name.required' => 'Soyisim zorunludur.',
            'phone.required' => 'Telefon numarası zorunludur.',
            'installation_consent.accepted' => 'Montaj şartlarını kabul etmelisiniz.',
            'kvkk_consent.accepted' => 'KVKK / Aydınlatma ve Açık Rıza Onayı zorunludur.',
            'door_front_photo.required' => 'Kapı ön, yan ve arka yüz fotoğrafları zorunludur.',
            'door_side_photo.required' => 'Kapı ön, yan ve arka yüz fotoğrafları zorunludur.',
            'door_back_photo.required' => 'Kapı ön, yan ve arka yüz fotoğrafları zorunludur.',
            'door_front_photo.image' => 'Kapı ön yüzü için geçerli bir görsel seçin.',
            'door_side_photo.image' => 'Kapı yan yüzü için geçerli bir görsel seçin.',
            'door_back_photo.image' => 'Kapı arka yüzü için geçerli bir görsel seçin.',
            'door_front_photo.max' => 'Fotoğraf çok büyük. Lütfen daha küçük bir görsel seçin.',
            'door_side_photo.max' => 'Fotoğraf çok büyük. Lütfen daha küçük bir görsel seçin.',
            'door_back_photo.max' => 'Fotoğraf çok büyük. Lütfen daha küçük bir görsel seçin.',
        ]);

        $phone = $this->normalizeTurkishPhone($validated['phone']);

        if ($phone === null) {
            throw ValidationException::withMessages([
                'phone' => 'Telefon numarası +90 sonrası 10 hane olmalıdır.',
            ]);
        }

        $address = $this->composeServiceAddress($validated);
        $hasLocationAddress = $this->nullableString($validated['location_formatted_address'] ?? null) !== null
            && isset($validated['location_latitude'], $validated['location_longitude']);

        if ($address === null && ! $hasLocationAddress) {
            throw ValidationException::withMessages([
                'address' => 'Adres veya konum bilgisi zorunludur.',
            ]);
        }

        return [
            'first_name' => trim((string) $validated['first_name']),
            'last_name' => trim((string) $validated['last_name']),
            'customer_phone' => $phone,
            'city' => $this->nullableString($validated['city'] ?? null) ?? '-',
            'district' => $this->nullableString($validated['district'] ?? null) ?? '-',
            'address' => $address ?? $this->nullableString($validated['location_formatted_address'] ?? null) ?? '-',
            'multiple_products' => (bool) ($validated['multiple_products'] ?? false),
            'selected_invoice_serials' => array_values(array_filter(
                array_map(fn (mixed $value): string => trim((string) $value), $validated['selected_invoice_serials'] ?? []),
                fn (string $value): bool => $value !== '',
            )),
            'location_latitude' => isset($validated['location_latitude']) ? (float) $validated['location_latitude'] : null,
            'location_longitude' => isset($validated['location_longitude']) ? (float) $validated['location_longitude'] : null,
            'location_place_id' => $this->nullableString($validated['location_place_id'] ?? null),
            'location_formatted_address' => $this->nullableString($validated['location_formatted_address'] ?? null),
            'location_map_url' => $this->nullableString($validated['location_map_url'] ?? null),
            'building_no' => $this->nullableString($validated['building_no'] ?? null),
            'apartment_no' => $this->nullableString($validated['apartment_no'] ?? null),
            'door_no' => $this->nullableString($validated['door_no'] ?? null),
            'floor_no' => $this->nullableString($validated['floor_no'] ?? null),
            'site_name' => $this->nullableString($validated['site_name'] ?? null),
            'door_photos' => [
                'door_front_photo' => $request->file('door_front_photo'),
                'door_side_photo' => $request->file('door_side_photo'),
                'door_back_photo' => $request->file('door_back_photo'),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $validated
     */
    private function composeServiceAddress(array $validated): ?string
    {
        $base = $this->nullableString($validated['address'] ?? null)
            ?? $this->nullableString($validated['location_formatted_address'] ?? null);

        if ($base === null) {
            return null;
        }

        $parts = [];

        foreach ([
            'site_name' => 'Site/Apartman',
            'building_no' => 'Bina No',
            'apartment_no' => 'Daire No',
            'door_no' => 'Kapı No',
            'floor_no' => 'Kat',
        ] as $key => $label) {
            $value = $this->nullableString($validated[$key] ?? null);

            if ($value !== null) {
                $parts[] = "{$label}: {$value}";
            }
        }

        return $parts === [] ? $base : $base.' | '.implode(', ', $parts);
    }

    private function ensureInvoiceSerialContext(
        TechnicalServiceMountSession $session,
        MikroInvoiceSerialsService $invoiceSerialsService,
    ): TechnicalServiceMountSession {
        $context = is_array($session->context_payload) ? $session->context_payload : [];
        $existing = $context['invoice_serials'] ?? null;

        if (is_array($existing) && isset($existing['all_invoice_serials'])) {
            return $session;
        }

        try {
            $result = $invoiceSerialsService->forSerial($session->serial_number);
            $context['invoice_serials'] = [
                'all_invoice_serials' => $result['all_invoice_serials'],
                'selectable_customer_serials' => $result['selectable_customer_serials'],
                'returned_serials' => $result['returned_serials'],
                'checked_at' => now()->toISOString(),
                'check_error' => null,
            ];
        } catch (\Throwable $exception) {
            $context['invoice_serials'] = [
                'all_invoice_serials' => [],
                'selectable_customer_serials' => [],
                'returned_serials' => [],
                'checked_at' => now()->toISOString(),
                'check_error' => $exception->getMessage(),
            ];
        }

        $session->forceFill(['context_payload' => $context])->save();

        return $session->fresh(['qrLink', 'payments']);
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

    private function message(string $decision, ?TechnicalServiceMountSession $session = null): string
    {
        $currentState = $this->nullableString($session?->context_payload['current_serial_state'] ?? null);

        if ($currentState === 'in_stock_or_center') {
            return 'Bu ürünün satış bilgisi henüz doğrulanamadı. Talebiniz operasyon ekibi tarafından kontrol edilecektir.';
        }

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

    private function allowMultiProductRequest(TechnicalServiceMountSession $session): bool
    {
        return in_array($session->sale_mount_status, [
            TechnicalServiceMountSession::SALE_MONTAJ_DAHIL,
            TechnicalServiceMountSession::SALE_MONTAJ_SONRADAN_DAHIL,
        ], true)
            && $session->customer_entry_mode !== TechnicalServiceMountSession::ENTRY_PAID_SINGLE_PRODUCT;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
