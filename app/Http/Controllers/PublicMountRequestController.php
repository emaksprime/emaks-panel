<?php

namespace App\Http\Controllers;

use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceQrLink;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestSerial;
use App\Services\TechnicalService\MountFlowDecisionService;
use App\Services\TechnicalService\MountRequestSubmitService;
use App\Services\TechnicalService\MountSessionEnrichmentService;
use App\Services\TechnicalService\MikroInvoiceSerialsService;
use App\Services\TechnicalService\SerialProductContextResolver;
use App\Services\TechnicalService\TechnicalServicePaymentActionPresenter;
use App\Services\TechnicalService\TechnicalServicePaymentSettlementService;
use App\Services\Payments\PaymentProviderManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PublicMountRequestController extends Controller
{
    public function show(
        Request $request,
        string $token,
    ) {
        $link = TechnicalServiceQrLink::findActiveByToken($token);

        if (! $link instanceof TechnicalServiceQrLink) {
            return Inertia::render('public/mount-request-v2', [
                'viewState' => 'invalid_link',
                'message' => 'Montaj talep linki geçersiz veya süresi dolmuş.',
                'csrfToken' => csrf_token(),
            ])->toResponse($request)->setStatusCode(404);
        }

        $link->markScanned();

        return Inertia::render('public/mount-request-v2', [
            'viewState' => 'checking',
            'message' => 'Cihaz bilgileriniz kontrol ediliyor.',
            'product' => [
                'product_name' => $link->product_name,
                'product_model' => $link->product_model,
                'serial_number' => $link->serial_number,
                'brand' => $link->brand,
            ],
            'actions' => [
                'check_url' => route('mount-request.check', ['token' => $token], false),
                'form_url' => route('mount-request.form', ['token' => $token], false),
            ],
            'csrfToken' => csrf_token(),
        ]);
    }

    public function check(
        Request $request,
        string $token,
        SerialProductContextResolver $contextResolver,
        MountSessionEnrichmentService $enrichmentService,
        MountFlowDecisionService $decisionService,
    ): JsonResponse {
        $link = TechnicalServiceQrLink::findActiveByToken($token);

        if (! $link instanceof TechnicalServiceQrLink) {
            return response()->json([
                'ok' => false,
                'view_state' => 'invalid_link',
                'target_url' => route('mount-request.show', ['token' => $token], false),
                'message' => 'Montaj talep linki geçersiz veya süresi dolmuş.',
            ], 404);
        }

        try {
            $session = $this->prepareSession($link, $contextResolver, $enrichmentService);
            $decision = $decisionService->decide($session->fresh(['qrLink', 'payments']));

            return response()->json([
                'ok' => true,
                'view_state' => $this->viewState($decision['decision']),
                'target_url' => $this->targetUrlForDecision($decision['decision'], $token),
                'message' => $this->message($decision['decision'], $session->fresh(['qrLink', 'payments'])),
            ]);
        } catch (\Throwable) {
            $session = $this->applyFallbackContext($link, $enrichmentService);
            $decisionService->decide($session->fresh(['qrLink', 'payments']));

            return response()->json([
                'ok' => true,
                'view_state' => 'check_pending',
                'target_url' => route('mount-request.form', ['token' => $token], false),
                'message' => 'Cihaz bilgileri tam doğrulanamadı; operasyon ekibi kontrol edecektir.',
            ]);
        }
    }

    public function form(
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
                'csrfToken' => csrf_token(),
            ])->toResponse($request)->setStatusCode(404);
        }

        $link->markScanned();
        $session = $this->prepareSession($link, $contextResolver, $enrichmentService);

        $decision = $decisionService->decide($session->fresh(['qrLink', 'payments']));
        $session = $session->fresh(['qrLink', 'payments']);

        return $this->renderFormPage($token, $link, $session, $decision['decision']);
    }

    public function paymentStep(
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
                'csrfToken' => csrf_token(),
            ])->toResponse($request)->setStatusCode(404);
        }

        $link->markScanned();
        $session = $this->prepareSession($link, $contextResolver, $enrichmentService);
        $decision = $decisionService->decide($session->fresh(['qrLink', 'payments']));

        if ($decision['decision'] !== MountFlowDecisionService::DECISION_SHOW_PAYMENT) {
            return $this->redirectToCurrentHost($request, 'mount-request.form', ['token' => $token]);
        }

        return $this->renderFormPage($token, $link, $session->fresh(['qrLink', 'payments']), $decision['decision']);
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

    public function createFakePayment(Request $request, string $token, PaymentProviderManager $paymentProviderManager): RedirectResponse
    {
        abort_if(app()->environment('production') && ! config('payments.real_provider_enabled', false), 404);

        $link = $this->linkOrFail($token);
        $session = $this->sessionForLink($link);
        $payment = $this->latestPayment($session);

        if (! $payment instanceof TechnicalServiceMountPayment || $payment->status !== TechnicalServiceMountPayment::STATUS_PENDING) {
            $payment = null;

            try {
                $payment = TechnicalServiceMountPayment::query()->create([
                    'technical_service_mount_session_id' => $session->id,
                    'provider' => $paymentProviderManager->providerName(),
                    'status' => TechnicalServiceMountPayment::STATUS_PENDING,
                    'amount' => 3500,
                    'currency' => 'TRY',
                    'raw_payload' => $this->publicMountPaymentPayload($link, $session, $paymentProviderManager),
                ]);

                $paymentProviderManager->createPayment($payment);
            } catch (\Throwable $exception) {
                $payment?->delete();

                return redirect()->to(route('mount-request.payment.step', ['token' => $token], false))
                    ->withErrors(['payment' => $exception->getMessage()]);
            }
        }

        $session->forceFill([
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PENDING,
            'customer_entry_mode' => TechnicalServiceMountSession::ENTRY_SINGLE_PRODUCT,
            'decision_status' => TechnicalServiceMountSession::DECISION_READY,
        ])->save();

        return $this->redirectToCurrentHost($request, 'mount-payment.show', ['token' => $payment->provider_reference]);
    }

    /**
     * @return array<string, mixed>
     */
    private function publicMountPaymentPayload(
        TechnicalServiceQrLink $link,
        TechnicalServiceMountSession $session,
        PaymentProviderManager $paymentProviderManager,
    ): array {
        $context = is_array($session->context_payload) ? $session->context_payload : [];

        return [
            'source' => 'public_mount_payment',
            'provider_environment' => $paymentProviderManager->environment(),
            'technical_service_request_id' => null,
            'root_request_id' => null,
            'request_code' => null,
            'root_mrn' => null,
            'qr_link_id' => $link->id,
            'mount_session_id' => $session->id,
            'serial_number' => $session->serial_number ?: $link->serial_number,
            'customer_name' => null,
            'customer_phone' => null,
            'customer_email' => null,
            'product_name' => $context['product_name'] ?? $link->product_name,
            'product_model' => $context['product_model'] ?? $link->product_model,
            'brand' => $context['brand'] ?? $link->brand,
            'purpose' => 'service_payment',
            'charge_type' => 'service_payment',
            'amount_source' => 'public_mount_payment_fixed_fee',
            'total_amount' => 3500.0,
        ];
    }

    public function chooseMultiProduct(Request $request, string $token): RedirectResponse
    {
        $link = $this->linkOrFail($token);
        $session = $this->sessionForLink($link);

        $session->forceFill([
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_SKIPPED_MULTI_PRODUCT,
            'customer_entry_mode' => TechnicalServiceMountSession::ENTRY_MULTI_PRODUCT_WITHOUT_PAYMENT,
            'decision_status' => TechnicalServiceMountSession::DECISION_FORM_OPEN,
        ])->save();

        return $this->redirectToCurrentHost($request, 'mount-request.form', ['token' => $token]);
    }

    public function multiProductOptions(
        Request $request,
        string $token,
        SerialProductContextResolver $contextResolver,
        MountSessionEnrichmentService $enrichmentService,
        MikroInvoiceSerialsService $invoiceSerialsService,
    ) {
        $filters = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);
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
        $selectableTotal = count($publicRows);
        $search = $this->nullableString($filters['search'] ?? null);

        if ($search !== null) {
            $needle = mb_strtolower($search, 'UTF-8');
            $publicRows = array_values(array_filter($publicRows, function (array $row) use ($needle): bool {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    $row['serial_number'] ?? null,
                    $row['product_name'] ?? null,
                    $row['product_model'] ?? null,
                ], fn (mixed $value): bool => $value !== null && $value !== '')), 'UTF-8');

                return str_contains($haystack, $needle);
            }));
        }

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($filters['per_page'] ?? 20)));
        $filteredTotal = count($publicRows);
        $lastPage = max(1, (int) ceil($filteredTotal / $perPage));
        $page = min($page, $lastPage);
        $publicRows = array_slice($publicRows, ($page - 1) * $perPage, $perPage);
        $hasSelectableSerials = $selectableTotal > 0;
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
            'operation_only_count' => max(0, $operationRowsCount - $selectableTotal),
            'returned_count' => $returnedCount,
            'blocked_count' => $blockedCount,
            'total_count' => $operationRowsCount,
            'selectable_total' => $selectableTotal,
            'meta' => [
                'total' => $filteredTotal,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => $lastPage,
            ],
            'message' => $hasSelectableSerials ? null : 'Bu fatura için ek montaj seçilebilir ürün bulunamadı. Ek ürün talebiniz operasyon ekibine iletilecek.',
        ]);
    }

    public function approveFakePayment(
        Request $request,
        TechnicalServiceMountPayment $payment,
        TechnicalServicePaymentSettlementService $settlementService
    ): RedirectResponse
    {
        abort_unless($this->fakePaymentEnabled(), 404);
        abort_unless($payment->provider === 'fake', 404);
        abort_unless($payment->status === TechnicalServiceMountPayment::STATUS_PENDING, 404);

        $settlementService->markPaid($payment, [
            'source' => 'fake_approve_route',
        ]);

        $token = $request->query('token');

        if (is_string($token) && $token !== '') {
            return $this->redirectToCurrentHost($request, 'mount-request.form', ['token' => $token]);
        }

        return redirect('/');
    }

    private function applyExtraMountPaymentApproval(TechnicalServiceMountPayment $payment): void
    {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];

        if (($payload['source'] ?? null) !== 'operation_extra_mount_fee') {
            return;
        }

        $requestId = $payment->technical_service_request_id ?? ($payload['technical_service_request_id'] ?? null);

        if (! is_numeric($requestId)) {
            return;
        }

        $technicalServiceRequest = TechnicalServiceRequest::query()->find((int) $requestId);

        if (! $technicalServiceRequest instanceof TechnicalServiceRequest) {
            return;
        }

        $technicalServiceRequest->forceFill([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_SONRADAN_DAHIL,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PAID,
            'mount_payment_label' => 'Montaj ödemesi alındı',
            'mount_payment_provider' => $payment->provider,
            'mount_payment_reference' => $payment->provider_reference,
            'mount_payment_paid_at' => $payment->paid_at ?? now(),
        ])->save();

        $serialIds = collect($payload['selected_serial_ids'] ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values();

        $serialQuery = TechnicalServiceRequestSerial::query()
            ->where('technical_service_request_id', $technicalServiceRequest->id);

        if ($serialIds->isNotEmpty()) {
            $serialQuery->whereIn('id', $serialIds);
        } else {
            $serialQuery->where(function ($query): void {
                $query->where('customer_selected', true)
                    ->orWhere('operation_added', true)
                    ->orWhere('is_primary', true);
            });
        }

        $serialQuery->get()->each(function (TechnicalServiceRequestSerial $serial) use ($payment): void {
            $sourcePayload = is_array($serial->source_payload) ? $serial->source_payload : [];
            $sourcePayload['extra_mount_payment_status'] = TechnicalServiceMountPayment::STATUS_PAID;
            $sourcePayload['extra_mount_payment_id'] = $payment->id;
            $sourcePayload['sale_mount_status'] = TechnicalServiceMountSession::SALE_MONTAJ_SONRADAN_DAHIL;
            $sourcePayload['mount_payment_status'] = TechnicalServiceMountSession::PAYMENT_PAID;
            $sourcePayload['mount_status_label'] = 'Montaj Dahil';

            $serial->forceFill([
                'operation_added' => true,
                'operation_added_at' => $serial->operation_added_at ?? now(),
                'source_payload' => $sourcePayload,
                'color_status' => 'green',
                'operation_note' => trim((string) $serial->operation_note) !== ''
                    ? $serial->operation_note.' | Ek ödeme onaylandı - Montaj Dahil'
                    : 'Ek ödeme onaylandı - Montaj Dahil',
            ])->save();
        });
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

    private function applyFallbackContext(
        TechnicalServiceQrLink $link,
        MountSessionEnrichmentService $enrichmentService,
    ): TechnicalServiceMountSession {
        $session = $this->sessionForLink($link);

        if ((int) $session->check_attempt_count !== 0) {
            return $session;
        }

        return $enrichmentService->applyContext($session, [
            'sale_mount_status' => TechnicalServiceMountSession::SALE_CHECK_FAILED,
            'product_name' => $link->product_name,
            'product_model' => $link->product_model,
            'brand' => $link->brand,
            'activation_code' => null,
            'invoice_customer_type' => 'unknown',
            'current_serial_state' => 'unknown',
            'has_current_sale' => false,
            'latest_event_type' => null,
            'latest_valid_sale_exists' => false,
            'stock_code' => null,
            'resolver_payload' => [
                'source' => 'public_qr_check_failed',
            ],
        ]);
    }

    private function assertCanSubmit(TechnicalServiceMountSession $session, string $decision): void
    {
        $allowedDecision = in_array($decision, [
            MountFlowDecisionService::DECISION_SHOW_FORM,
            MountFlowDecisionService::DECISION_SHOW_MULTI_PRODUCT_FORM_WITHOUT_PAYMENT,
            MountFlowDecisionService::DECISION_SHOW_CHECK_FAILED_BUT_ALLOW_SUBMIT,
        ], true);

        if (! $allowedDecision) {
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
            $existingRows = is_array($existing['all_invoice_serials'] ?? null)
                ? $existing['all_invoice_serials']
                : [];

            if ($existingRows !== []) {
                return $session;
            }
        }

        try {
            $result = $invoiceSerialsService->forSerial($session->serial_number);
            $context['invoice_serials'] = [
                'all_invoice_serials' => $result['all_invoice_serials'],
                'selectable_customer_serials' => $result['selectable_customer_serials'],
                'returned_serials' => $result['returned_serials'],
                'checked_at' => now()->toISOString(),
                'check_status' => $result['meta']['status'] ?? null,
                'check_error' => null,
            ];
        } catch (\Throwable $exception) {
            $context['invoice_serials'] = [
                'all_invoice_serials' => [],
                'selectable_customer_serials' => [],
                'returned_serials' => [],
                'checked_at' => now()->toISOString(),
                'check_status' => 'failed',
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
        return $this->fakePaymentProviderEnabled()
            && filter_var(config('payments.enable_fake_approve', false), FILTER_VALIDATE_BOOLEAN);
    }

    private function fakePaymentProviderEnabled(): bool
    {
        return ! app()->environment('production')
            && strtolower((string) config('payments.provider', 'fake')) === 'fake';
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

    private function targetUrlForDecision(string $decision, string $token): string
    {
        if ($decision === MountFlowDecisionService::DECISION_SHOW_PAYMENT) {
            return route('mount-request.payment.step', ['token' => $token], false);
        }

        return route('mount-request.form', ['token' => $token], false);
    }

    private function renderFormPage(
        string $token,
        TechnicalServiceQrLink $link,
        TechnicalServiceMountSession $session,
        string $decision,
    ) {
        $payment = $this->latestPayment($session);
        $paymentPayload = null;
        if ($payment instanceof TechnicalServiceMountPayment) {
            $fakeApproveUrl = $this->fakePaymentEnabled()
                ? route('mount-payment.fake.approve', ['payment' => $payment, 'token' => $token], false)
                : null;
            $paymentPayload = array_merge([
                'amount' => number_format((float) $payment->amount, 2, '.', ''),
                'currency' => $payment->currency,
                'payment_url' => $payment->payment_url,
            ], TechnicalServicePaymentActionPresenter::forPayment($payment, $fakeApproveUrl));
        }

        return Inertia::render('public/mount-request-v2', [
            'viewState' => $this->viewState($decision),
            'message' => $this->message($decision, $session),
            'product' => [
                'product_name' => $session->context_payload['product_name'] ?? $link->product_name,
                'product_model' => $session->context_payload['product_model'] ?? $link->product_model,
                'serial_number' => $link->serial_number,
                'brand' => $session->context_payload['brand'] ?? $link->brand,
            ],
            'statusLabel' => $this->statusLabel($session),
            'actions' => [
                'payment_label' => 'Montaj ödemesi yap',
                'multi_product_label' => 'Birden fazla ürünüm var',
                'continue_label' => 'Forma Devam Et',
                'create_payment_url' => route('mount-request.payment.create', ['token' => $token], false),
                'multi_product_url' => route('mount-request.multi-product', ['token' => $token], false),
                'multi_product_lookup_url' => route('mount-request.invoice-serials.check', ['token' => $token], false),
                'submit_url' => route('mount-request.submit', ['token' => $token], false),
            ],
            'payment' => $paymentPayload,
            'allowMultiProductRequest' => $this->allowMultiProductRequest($session),
            'csrfToken' => csrf_token(),
        ]);
    }

    private function message(string $decision, ?TechnicalServiceMountSession $session = null): string
    {
        $currentState = $this->nullableString($session?->context_payload['current_serial_state'] ?? null);
        $resolverSource = $this->nullableString($session?->context_payload['resolver_payload']['source'] ?? null);

        if ($resolverSource === 'public_qr_check_failed') {
            return 'Cihaz bilgileri tam doğrulanamadı; operasyon ekibi kontrol edecektir.';
        }

        if ($decision !== MountFlowDecisionService::DECISION_SHOW_PAYMENT && $currentState === 'in_stock_or_center') {
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

        if ($this->requiresPaymentBeforeForm($session)) {
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
        return $session->customer_entry_mode !== TechnicalServiceMountSession::ENTRY_PAID_SINGLE_PRODUCT;
    }

    private function requiresPaymentBeforeForm(TechnicalServiceMountSession $session): bool
    {
        $context = is_array($session->context_payload) ? $session->context_payload : [];
        $currentSerialState = $this->nullableString($context['current_serial_state'] ?? null);
        $resolverSource = $this->nullableString($context['source'] ?? ($context['resolver_payload']['source'] ?? null));

        if ($session->sale_mount_status === TechnicalServiceMountSession::SALE_MONTAJ_HARIC) {
            return true;
        }

        if ($session->sale_mount_status === TechnicalServiceMountSession::SALE_NOT_FOUND) {
            return true;
        }

        if ($resolverSource === 'mikro_serial_check_failed') {
            return false;
        }

        return in_array($currentSerialState, ['in_stock_or_center', 'returned'], true);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function redirectToCurrentHost(Request $request, string $routeName, array $parameters): RedirectResponse
    {
        return new RedirectResponse(route($routeName, $parameters, false));
    }
}
