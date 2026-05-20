<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignTechnicalServiceRequest;
use App\Http\Requests\StoreTechnicalServiceContactLogRequest;
use App\Http\Requests\StoreTechnicalServiceRequest;
use App\Http\Requests\UpdateTechnicalServiceRequest;
use App\Http\Requests\UpdateTechnicalServiceFieldActionRequest;
use App\Http\Requests\UpdateTechnicalServiceRequestStatus;
use App\Http\Requests\UpdateTechnicalServiceScheduleRequest;
use App\Http\Requests\UpdateTechnicalServiceTechnicianWorkflowRequest;
use App\Http\Requests\UpdateTechnicalServiceWorkflowRequest;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceRequestSerial;
use App\Models\TechnicalServiceRequestUpload;
use App\Models\TechnicalServiceRouteQuote;
use App\Models\TechnicalServiceTechnician;
use App\Services\TechnicalService\MikroInvoiceSerialsService;
use App\Services\TechnicalService\MikroSerialNumberService;
use App\Services\TechnicalService\MountRequestSubmitService;
use App\Services\TechnicalService\TechnicalServiceRouteCostService;
use App\Services\TechnicalService\TechnicalServiceWorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class TechnicalServiceController extends Controller
{
    public function __construct(
        private readonly TechnicalServiceWorkflowService $workflowService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:64'],
            'workflow_status' => ['nullable', 'string', 'max:128'],
            'sla_status' => ['nullable', 'string', 'max:32'],
            'service_type' => ['nullable', 'string', 'max:128'],
            'priority' => ['nullable', 'string', 'max:64'],
            'risk_level' => ['nullable', 'string', 'max:64'],
            'technician_name' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = TechnicalServiceRequest::query();

        if (! empty($filters['search'])) {
            $query->where(function ($query) use ($filters) {
                $query->where('mrn', 'ilike', "%{$filters['search']}%")
                    ->orWhere('customer_name', 'ilike', "%{$filters['search']}%")
                    ->orWhere('product_name', 'ilike', "%{$filters['search']}%")
                    ->orWhere('serial_number', 'ilike', "%{$filters['search']}%")
                    ->orWhere('technician_name', 'ilike', "%{$filters['search']}%");
            });
        }

        foreach (['status', 'workflow_status', 'service_type', 'priority', 'risk_level', 'technician_name'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (! empty($filters['sla_status'])) {
            $query->where('sla_status', $filters['sla_status']);
        }

        $limit = $filters['limit'] ?? 25;

        $paginator = $query
            ->orderByRaw('scheduled_at IS NULL')
            ->orderBy('scheduled_at')
            ->orderByDesc('created_at')
            ->paginate($limit);

        return response()->json([
            'items' => collect($paginator->items())
                ->map(fn (TechnicalServiceRequest $request) => $this->workflowService->serialize($request))
                ->all(),
            'pagination' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        return response()->json([
            'request' => $this->workflowService->serialize($technicalServiceRequest, true),
        ]);
    }

    public function store(StoreTechnicalServiceRequest $request): JsonResponse
    {
        $user = $request->user();
        $payload = $request->validated();

        $payload['status'] = $payload['status'] ?? 'Yeni';
        $payload['priority'] = $payload['priority'] ?? 'Orta';
        $payload['risk_level'] = $payload['risk_level'] ?? 'Orta';
        $payload['created_by_user_id'] = $user?->id;
        $payload['updated_by_user_id'] = $user?->id;

        $requestModel = DB::transaction(function () use ($payload, $user) {
            $payload['mrn'] = $this->generateMrn();

            /** @var TechnicalServiceRequest $requestModel */
            $requestModel = TechnicalServiceRequest::query()->create($payload);
            $this->workflowService->initializeRequest($requestModel, [
                'workflow_status' => $payload['workflow_status'] ?? $payload['status'] ?? 'Yeni Talep',
            ]);
            $requestModel->save();

            $requestModel->events()->create([
                'event_type' => 'created',
                'title' => 'Talep oluşturuldu',
                'note' => 'Teknik servis talebi oluşturuldu.',
                'from_status' => null,
                'to_status' => $requestModel->workflow_status,
                'author_user_id' => $user?->id,
                'metadata' => [
                    'source_channel' => $payload['source_channel'] ?? null,
                ],
            ]);

            return $requestModel;
        });

        return response()->json(['request' => $this->workflowService->serialize($requestModel, true)], 201);
    }

    public function update(UpdateTechnicalServiceRequest $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        $payload = $request->validated();
        $payload['updated_by_user_id'] = $request->user()?->id;

        $scheduleNote = $payload['schedule_note'] ?? null;
        unset($payload['schedule_note']);

        if (isset($payload['scheduled_at']) && $payload['scheduled_at']) {
            $scheduledAt = CarbonImmutable::parse($payload['scheduled_at']);
            $technicalServiceRequest->scheduled_at = $scheduledAt;
            $technicalServiceRequest->scheduled_date = $scheduledAt->toDateString();
            $technicalServiceRequest->scheduled_time = $scheduledAt->format('H:i');
            unset($payload['scheduled_at']);
        }

        $travelSummary = null;
        if (array_key_exists('travel_round_trip_km', $payload) && $payload['travel_round_trip_km'] !== null) {
            $travelSummary = $this->calculateTravelCosts((float) $payload['travel_round_trip_km']);
            $payload = array_merge($payload, $travelSummary);
        }

        $technicalServiceRequest->fill($payload);
        $this->workflowService->initializeRequest($technicalServiceRequest, $payload);
        $technicalServiceRequest->save();

        if ($scheduleNote !== null) {
            $technicalServiceRequest->events()->create([
                'event_type' => 'schedule_note',
                'title' => 'Randevu notu güncellendi',
                'note' => $scheduleNote,
                'from_status' => null,
                'to_status' => $technicalServiceRequest->workflow_status,
                'author_user_id' => $request->user()?->id,
                'metadata' => $travelSummary ? ['travel' => $travelSummary] : [],
            ]);
        }

        return response()->json(['request' => $this->workflowService->serialize($technicalServiceRequest, true)]);
    }

    public function updateStatus(UpdateTechnicalServiceRequestStatus $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        $payload = $request->validated();
        $previousLegacyStatus = $technicalServiceRequest->status;
        $isReopen = $payload['status'] === 'Yeni' && in_array($previousLegacyStatus, ['Tamamlandı', 'İptal'], true);
        $this->validateInstallationAfterLatestSale($technicalServiceRequest, $payload);

        if ($isReopen) {
            $technicalServiceRequest->reopened_at = now();
            $technicalServiceRequest->reopened_by_user_id = $request->user()?->id;
            $technicalServiceRequest->reopen_reason = $payload['reopen_reason'] ?? null;
            $technicalServiceRequest->reopen_note = $payload['reopen_note'] ?? ($payload['note'] ?? null);
            $technicalServiceRequest->reopen_count = ((int) $technicalServiceRequest->reopen_count) + 1;
        } elseif ($payload['status'] === 'Yeni') {
            $technicalServiceRequest->completed_at = null;
            $technicalServiceRequest->cancelled_at = null;
        }

        $targetWorkflowStatus = match ($this->workflowService->normalizeLegacyStatus($payload['status'])) {
            'Tamamlandı' => 'Tamamlandı',
            'İptal' => 'İptal',
            'Devam Ediyor' => 'Sahada',
            'Randevulu' => $technicalServiceRequest->technical_service_technician_id || $technicalServiceRequest->technician_name
                ? 'Planlı'
                : 'Randevu Planlandı',
            'Atandı' => $technicalServiceRequest->technical_service_technician_id || $technicalServiceRequest->technician_name
                ? 'Usta Onayı Bekleyen'
                : 'Usta Ataması Bekleyen',
            default => 'Yeni Talep',
        };

        $workflowPayload = [
            'note' => $payload['reopen_note'] ?? $payload['note'] ?? ($payload['resolution_notes'] ?? null),
            'resolution_notes' => $payload['resolution_notes'] ?? null,
            'installation_completed_at' => $payload['installation_completed_at'] ?? null,
            'customer_closure_approval_status' => $targetWorkflowStatus === 'Tamamlandı' ? 'onaylandı' : null,
            'customer_closure_approved_at' => $targetWorkflowStatus === 'Tamamlandı' ? now() : null,
            'cancellation_reason' => $targetWorkflowStatus === 'İptal' ? ($payload['note'] ?? null) : null,
        ];

        $technicalServiceRequest = $this->workflowService->transition(
            $technicalServiceRequest,
            $targetWorkflowStatus,
            $workflowPayload,
            $request->user(),
            $isReopen ? 'technical_service_request_reopened' : 'legacy_status_update'
        );

        if ($isReopen) {
            $technicalServiceRequest->events()->create([
                'event_type' => 'technical_service_request_reopened',
                'title' => 'Talep yeniden açıldı',
                'note' => $payload['reopen_note'] ?? $payload['note'] ?? null,
                'from_status' => $previousLegacyStatus,
                'to_status' => $payload['status'],
                'author_user_id' => $request->user()?->id,
                'metadata' => [
                    'reason' => $payload['reopen_reason'] ?? null,
                    'note' => $payload['reopen_note'] ?? ($payload['note'] ?? null),
                    'user_id' => $request->user()?->id,
                ],
            ]);
        }

        return response()->json(['request' => $this->workflowService->serialize($technicalServiceRequest, true)]);
    }

    public function updateWorkflow(UpdateTechnicalServiceWorkflowRequest $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        $payload = $request->validated();
        $targetWorkflowStatus = $payload['workflow_status'] ?? null;

        if (! $targetWorkflowStatus && isset($payload['action'])) {
            $allowedActions = $this->workflowService->allowedActionsFor($technicalServiceRequest);
            $targetWorkflowStatus = $allowedActions[$payload['action']]['target'] ?? null;
        }

        if (! is_string($targetWorkflowStatus) || $targetWorkflowStatus === '') {
            throw ValidationException::withMessages([
                'workflow_status' => 'Workflow statüsü veya geçerli bir aksiyon seçilmelidir.',
            ]);
        }

        $technicalServiceRequest = $this->workflowService->transition(
            $technicalServiceRequest,
            $targetWorkflowStatus,
            $payload,
            $request->user(),
            $payload['action'] ?? 'workflow_transition'
        );

        return response()->json(['request' => $this->workflowService->serialize($technicalServiceRequest, true)]);
    }

    public function updateSchedule(UpdateTechnicalServiceScheduleRequest $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        $technicalServiceRequest = $this->workflowService->updateSchedule(
            $technicalServiceRequest,
            $request->validated(),
            $request->user()
        );

        return response()->json(['request' => $this->workflowService->serialize($technicalServiceRequest, true)]);
    }

    public function updateTechnician(UpdateTechnicalServiceTechnicianWorkflowRequest $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        $payload = $request->validated();
        if (! empty($payload['technical_service_technician_id'])) {
            $technician = TechnicalServiceTechnician::query()->find($payload['technical_service_technician_id']);
            $payload['technician_name'] = $technician?->name ?? $payload['technician_name'] ?? null;
        }

        $technicalServiceRequest = $this->workflowService->updateTechnician($technicalServiceRequest, $payload, $request->user());

        return response()->json(['request' => $this->workflowService->serialize($technicalServiceRequest, true)]);
    }

    public function updateOperationControl(Request $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        $validated = $request->validate([
            'payment_checked' => ['nullable', 'string', 'in:yes,no,unreviewed'],
            'address_checked' => ['nullable', 'string', 'in:yes,no,unreviewed'],
            'door_photos_checked' => ['nullable', 'string', 'in:compatible,incompatible,unreviewed'],
            'missing_info' => ['nullable', 'string', 'in:yes,no,unreviewed'],
            'customer_call_required' => ['nullable', 'string', 'in:yes,no,unreviewed'],
            'schedule_update_required' => ['nullable', 'string', 'in:yes,no,unreviewed'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $payload = array_replace(
            [
                'payment_checked' => 'unreviewed',
                'address_checked' => 'unreviewed',
                'door_photos_checked' => 'unreviewed',
                'missing_info' => 'unreviewed',
                'customer_call_required' => 'unreviewed',
                'schedule_update_required' => 'unreviewed',
                'note' => null,
            ],
            is_array($technicalServiceRequest->operation_control_payload) ? $technicalServiceRequest->operation_control_payload : [],
            $validated,
        );

        $technicalServiceRequest->forceFill([
            'operation_control_payload' => $payload,
            'operation_control_checked_by_user_id' => $request->user()?->id,
            'operation_control_checked_at' => now(),
        ])->save();

        return response()->json([
            'request' => $this->workflowService->serialize($technicalServiceRequest->refresh(), true),
        ]);
    }

    public function routeQuote(
        TechnicalServiceRequest $technicalServiceRequest,
        TechnicalServiceTechnician $technician,
        TechnicalServiceRouteCostService $routeCostService,
    ): JsonResponse {
        $quote = $routeCostService->quote($technicalServiceRequest, $technician);
        $requestPayload = $this->workflowService->serialize($technicalServiceRequest->refresh(), true);
        $requestPayload['route_quote'] = $quote;

        return response()->json(array_merge($quote, [
            'request' => $requestPayload,
        ]));
    }

    public function technicianEarningsMessage(
        Request $request,
        TechnicalServiceRequest $technicalServiceRequest,
        TechnicalServiceTechnician $technician,
    ): JsonResponse {
        $validated = $request->validate([
            'labor_amount' => ['nullable', 'numeric', 'min:0'],
            'route_fee_amount' => ['nullable', 'numeric', 'min:0'],
            'total_amount' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
            'message_text' => ['nullable', 'string', 'max:5000'],
            'manual_override' => ['nullable', 'boolean'],
        ]);

        if (blank($technician->phone_e164) && blank($technician->phone_display) && blank($technician->phone)) {
            throw ValidationException::withMessages([
                'technician' => 'Usta telefonu olmadan hakediş bilgisi gönderilemez.',
            ]);
        }

        $result = $this->workflowService->recordTechnicianEarningsMessage(
            $technicalServiceRequest,
            $technician,
            $validated,
            $request->user(),
        );

        return response()->json([
            'ok' => true,
            'message_text' => $result['message_text'],
            'copy_text' => $result['copy_text'],
            'whatsapp_url' => $result['whatsapp_url'],
            'request' => $this->workflowService->serialize($result['request'], true),
        ]);
    }

    public function manualRouteQuote(
        Request $request,
        TechnicalServiceRequest $technicalServiceRequest,
        TechnicalServiceRouteCostService $routeCostService,
    ): JsonResponse {
        $validated = $request->validate([
            'technical_service_technician_id' => ['required', 'integer', 'exists:technical_service_technicians,id'],
            'one_way_distance_km' => ['nullable', 'numeric', 'min:0'],
            'round_trip_distance_km' => ['nullable', 'numeric', 'min:0'],
            'threshold_km' => ['nullable', 'numeric', 'min:0'],
            'billable_km' => ['nullable', 'numeric', 'min:0'],
            'extra_km' => ['nullable', 'numeric', 'min:0'],
            'fee_per_km' => ['nullable', 'numeric', 'min:0'],
            'fee_amount' => ['nullable', 'numeric', 'min:0'],
            'manual_override' => ['nullable', 'boolean'],
            'manual_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $technician = TechnicalServiceTechnician::query()->findOrFail((int) $validated['technical_service_technician_id']);

        $quote = $routeCostService->manualQuote($technicalServiceRequest, $technician, $validated);

        return response()->json(array_merge($quote, [
            'request' => $this->workflowService->serialize($technicalServiceRequest->refresh(), true),
        ]));
    }

    public function createExtraMountFeePayment(Request $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        if (! app()->environment(['local', 'testing'])) {
            throw ValidationException::withMessages([
                'payment' => 'Ek ödeme linki için ödeme sağlayıcı entegrasyonu tanımlı değil.',
            ]);
        }

        $validated = $request->validate([
            'route_quote_id' => ['nullable', 'integer', 'exists:technical_service_route_quotes,id'],
            'technician_id' => ['required', 'integer', 'exists:technical_service_technicians,id'],
            'selected_serial_ids' => ['nullable', 'array'],
            'selected_serial_ids.*' => ['integer'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'reason' => ['nullable', 'string', 'in:route_fee,montage_difference,multi_product,manual_extra'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($technicalServiceRequest->mount_session_id === null) {
            throw ValidationException::withMessages([
                'payment' => 'Bu talep için ödeme oturumu bulunamadı.',
            ]);
        }

        $session = TechnicalServiceMountSession::query()->findOrFail($technicalServiceRequest->mount_session_id);
        $serialIds = collect($validated['selected_serial_ids'] ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
        $validSerialIds = TechnicalServiceRequestSerial::query()
            ->where('technical_service_request_id', $technicalServiceRequest->id)
            ->whereIn('id', $serialIds)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values();
        $currency = strtoupper($validated['currency'] ?? 'TRY');

        $payment = TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $technicalServiceRequest->id,
            'provider' => 'fake',
            'provider_reference' => 'fake-extra-'.hash('sha256', $technicalServiceRequest->id.'|'.microtime(true)),
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'amount' => round((float) $validated['amount'], 2),
            'currency' => $currency,
            'raw_payload' => [
                'source' => 'operation_extra_mount_fee',
                'technical_service_request_id' => $technicalServiceRequest->id,
                'mrn' => $technicalServiceRequest->mrn,
                'route_quote_id' => $validated['route_quote_id'] ?? null,
                'technician_id' => (int) $validated['technician_id'],
                'selected_serial_ids' => $validSerialIds->all(),
                'reason' => $validated['reason'] ?? 'route_fee',
                'note' => $validated['note'] ?? null,
            ],
        ]);
        $payment->forceFill([
            'payment_url' => route('mount-payment.fake.approve', ['payment' => $payment]),
        ])->save();

        $technicalServiceRequest->forceFill([
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PENDING,
            'mount_payment_label' => 'Ek ödeme bekleniyor',
            'mount_payment_provider' => $payment->provider,
            'mount_payment_reference' => $payment->provider_reference,
        ])->save();

        if ($validSerialIds->isNotEmpty()) {
            TechnicalServiceRequestSerial::query()
                ->where('technical_service_request_id', $technicalServiceRequest->id)
                ->whereIn('id', $validSerialIds)
                ->get()
                ->each(function (TechnicalServiceRequestSerial $serial) use ($payment): void {
                    $sourcePayload = is_array($serial->source_payload) ? $serial->source_payload : [];
                    $sourcePayload['extra_mount_payment_status'] = TechnicalServiceMountPayment::STATUS_PENDING;
                    $sourcePayload['extra_mount_payment_id'] = $payment->id;
                    $sourcePayload['mount_status_label'] = 'Ek ödeme bekleniyor';
                    $serial->forceFill([
                        'source_payload' => $sourcePayload,
                        'operation_note' => trim((string) $serial->operation_note) !== ''
                            ? $serial->operation_note.' | Ek ödeme linki oluşturuldu'
                            : 'Ek ödeme linki oluşturuldu',
                    ])->save();
                });
        }

        $requestPayload = $this->workflowService->serialize($technicalServiceRequest->refresh(), true);

        return response()->json([
            'ok' => true,
            'payment' => [
                'id' => $payment->id,
                'status' => $payment->status,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'payment_url' => $payment->payment_url,
            ],
            'request' => $requestPayload,
        ], 201);
    }

    public function recheckInvoiceSerials(
        Request $request,
        TechnicalServiceRequest $technicalServiceRequest,
        MikroInvoiceSerialsService $invoiceSerialsService,
        MountRequestSubmitService $submitService,
    ): JsonResponse {
        $context = is_array($technicalServiceRequest->qr_context_payload)
            ? $technicalServiceRequest->qr_context_payload
            : [];

        try {
            $result = $invoiceSerialsService->forSerial((string) $technicalServiceRequest->serial_number);
            $selectedSerials = $technicalServiceRequest->requestSerials()
                ->where('customer_selected', true)
                ->pluck('serial_number')
                ->filter()
                ->values()
                ->all();
            $operationAddedSerials = $technicalServiceRequest->requestSerials()
                ->where('operation_added', true)
                ->pluck('serial_number')
                ->filter()
                ->values()
                ->all();

            $submitService->syncRequestSerials(
                $technicalServiceRequest,
                $result['all_invoice_serials'],
                array_map('strval', $selectedSerials),
                array_map('strval', $operationAddedSerials),
                $request->user()?->id,
            );

            $context['invoice_serials'] = [
                'all_invoice_serials' => $result['all_invoice_serials'],
                'selectable_customer_serials' => $result['selectable_customer_serials'],
                'returned_serials' => $result['returned_serials'],
                'checked_at' => now()->toISOString(),
                'check_status' => $result['meta']['status'] ?? null,
                'check_error' => null,
            ];
        } catch (Throwable $exception) {
            $context['invoice_serials'] = [
                'all_invoice_serials' => [],
                'selectable_customer_serials' => [],
                'returned_serials' => [],
                'checked_at' => now()->toISOString(),
                'check_status' => 'failed',
                'check_error' => $exception->getMessage(),
            ];
        }

        $technicalServiceRequest->forceFill([
            'qr_context_payload' => $context,
        ])->save();

        return response()->json([
            'request' => $this->workflowService->serialize($technicalServiceRequest->refresh(), true),
        ]);
    }

    public function addInvoiceSerial(
        Request $request,
        TechnicalServiceRequest $technicalServiceRequest,
        TechnicalServiceRequestSerial $serial,
    ): JsonResponse {
        abort_unless((int) $serial->technical_service_request_id === (int) $technicalServiceRequest->id, 404);

        if ((bool) $serial->is_returned) {
            throw ValidationException::withMessages([
                'serial' => 'İade seri montaja eklenemez.',
            ]);
        }

        $serial->forceFill([
            'operation_added' => true,
            'operation_added_by' => $request->user()?->id,
            'operation_added_at' => now(),
            'customer_phone' => $technicalServiceRequest->customer_phone,
            'linked_mrn' => $technicalServiceRequest->mrn,
            'operation_note' => 'Operasyon tarafından montaja eklendi',
            'color_status' => 'green',
        ])->save();

        return response()->json([
            'request' => $this->workflowService->serialize($technicalServiceRequest->refresh(), true),
        ]);
    }

    public function removeInvoiceSerial(
        Request $request,
        TechnicalServiceRequest $technicalServiceRequest,
        TechnicalServiceRequestSerial $serial,
    ): JsonResponse {
        abort_unless((int) $serial->technical_service_request_id === (int) $technicalServiceRequest->id, 404);

        if ((bool) $serial->is_primary) {
            throw ValidationException::withMessages([
                'serial' => 'Ana seri montaj talebinden çıkarılamaz.',
            ]);
        }

        $serial->forceFill([
            'customer_selected' => false,
            'operation_added' => false,
            'operation_added_by' => $request->user()?->id,
            'operation_added_at' => null,
            'customer_phone' => $technicalServiceRequest->customer_phone,
            'linked_mrn' => $technicalServiceRequest->mrn,
            'operation_note' => 'Operasyon tarafından çıkarıldı',
            'color_status' => (bool) $serial->is_returned ? 'red' : 'orange',
        ])->save();

        return response()->json([
            'request' => $this->workflowService->serialize($technicalServiceRequest->refresh(), true),
        ]);
    }

    public function addAllInvoiceSerials(
        Request $request,
        TechnicalServiceRequest $technicalServiceRequest,
    ): JsonResponse {
        $technicalServiceRequest->requestSerials()
            ->where('is_returned', false)
            ->where('is_primary', false)
            ->where('customer_selected', false)
            ->where('operation_added', false)
            ->get()
            ->each(function (TechnicalServiceRequestSerial $serial) use ($request, $technicalServiceRequest): void {
                $serial->forceFill([
                    'operation_added' => true,
                    'operation_added_by' => $request->user()?->id,
                    'operation_added_at' => now(),
                    'customer_phone' => $technicalServiceRequest->customer_phone,
                    'linked_mrn' => $technicalServiceRequest->mrn,
                    'operation_note' => 'Operasyon tarafından toplu montaja eklendi',
                    'color_status' => 'green',
                ])->save();
            });

        return response()->json([
            'request' => $this->workflowService->serialize($technicalServiceRequest->refresh(), true),
        ]);
    }

    public function showUpload(
        TechnicalServiceRequest $technicalServiceRequest,
        mixed $upload,
    ) {
        if (! $upload instanceof TechnicalServiceRequestUpload) {
            $upload = TechnicalServiceRequestUpload::query()->findOrFail($upload);
        }

        abort_unless($upload->technical_service_request_id === $technicalServiceRequest->id, 404);
        abort_unless(Storage::disk('public')->exists($upload->path), 404);

        return response()->file(Storage::disk('public')->path($upload->path), [
            'Content-Type' => $upload->mime ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.$upload->original_name.'"',
        ]);
    }

    public function storeContactLog(StoreTechnicalServiceContactLogRequest $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        $payload = $request->validated();
        $payload['customer_confirmation_method'] = $payload['customer_confirmation_method'] ?? $payload['contact_method'] ?? null;

        $technicalServiceRequest = $this->workflowService->logCustomerContact(
            $technicalServiceRequest,
            $payload,
            $request->user()
        );

        return response()->json(['request' => $this->workflowService->serialize($technicalServiceRequest, true)]);
    }

    public function updateFieldAction(
        UpdateTechnicalServiceFieldActionRequest $request,
        TechnicalServiceRequest $technicalServiceRequest,
        string $fieldAction
    ): JsonResponse {
        $technicalServiceRequest = $this->workflowService->updateFieldWorkflow(
            $technicalServiceRequest,
            $fieldAction,
            $request->validated(),
            $request->user()
        );

        return response()->json(['request' => $this->workflowService->serialize($technicalServiceRequest, true)]);
    }

    public function auditLogs(TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        if (! Schema::hasTable('technical_service_audit_logs')) {
            return response()->json([
                'items' => [],
                'warning' => 'Audit log tablosu henüz hazır değil.',
            ]);
        }

        $technicalServiceRequest->load(['auditLogs' => fn ($query) => $query->latest()]);

        return response()->json([
            'items' => $technicalServiceRequest->auditLogs->values()->all(),
        ]);
    }

    public function assign(AssignTechnicalServiceRequest $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        $payload = $request->validated();
        $technician = isset($payload['technical_service_technician_id'])
            ? TechnicalServiceTechnician::query()->find($payload['technical_service_technician_id'])
            : null;
        $routeQuote = isset($payload['route_quote_id'])
            ? TechnicalServiceRouteQuote::query()
                ->where('technical_service_request_id', $technicalServiceRequest->id)
                ->whereKey((int) $payload['route_quote_id'])
                ->first()
            : null;

        if (isset($payload['route_quote_id']) && ! $routeQuote instanceof TechnicalServiceRouteQuote) {
            throw ValidationException::withMessages([
                'route_quote_id' => 'Seçili yol ücreti hesabı bu talebe ait değil.',
            ]);
        }

        if ($routeQuote instanceof TechnicalServiceRouteQuote
            && $technician instanceof TechnicalServiceTechnician
            && (int) $routeQuote->technician_id !== (int) $technician->id
        ) {
            throw ValidationException::withMessages([
                'route_quote_id' => 'Seçili yol ücreti hesabı seçilen ustaya ait değil.',
            ]);
        }

        if ($this->workflowService->requiresMountExclusionAcknowledgement($technicalServiceRequest)) {
            $mountExclusionNote = trim((string) ($payload['mount_exclusion_note'] ?? ''));
            $errors = [];

            if (! (bool) ($payload['mount_exclusion_acknowledged'] ?? false)) {
                $errors['mount_exclusion_acknowledged'] = 'Montaj hariç çoklu ürün onayı zorunludur.';
            }

            if (mb_strlen($mountExclusionNote) < 5) {
                $errors['mount_exclusion_note'] = 'Montaj hariç çoklu ürün onayı için açıklama girin.';
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $operationControl = is_array($technicalServiceRequest->operation_control_payload)
                ? $technicalServiceRequest->operation_control_payload
                : [];
            $operationControl['mount_exclusion_acknowledgement'] = [
                'required' => true,
                'payment_received' => false,
                'acknowledged' => true,
                'note' => $mountExclusionNote,
                'acknowledged_at' => now()->toISOString(),
                'acknowledged_by_user_id' => $request->user()?->id,
            ];

            $technicalServiceRequest->forceFill([
                'operation_control_payload' => $operationControl,
                'operation_control_checked_by_user_id' => $request->user()?->id,
                'operation_control_checked_at' => now(),
            ])->save();

            $technicalServiceRequest->events()->create([
                'event_type' => 'mount_exclusion_acknowledged',
                'title' => 'Montaj hariç çoklu ürün operasyon onayı alındı.',
                'note' => $mountExclusionNote,
                'from_status' => $technicalServiceRequest->workflow_status,
                'to_status' => $technicalServiceRequest->workflow_status,
                'author_user_id' => $request->user()?->id,
                'metadata' => [
                    'mount_exclusion_acknowledged' => true,
                ],
            ]);
        }

        $technicianPayload = [
            'technical_service_technician_id' => $technician?->id,
            'technician_name' => $technician?->name ?? ($payload['technician_name'] ?? null),
            'technician_approval_status' => 'bekliyor',
            'route_quote_id' => $payload['route_quote_id'] ?? null,
            'note' => $payload['note'] ?? null,
        ];

        $technicalServiceRequest = $this->workflowService->updateTechnician(
            $technicalServiceRequest,
            $technicianPayload,
            $request->user()
        );

        if (! $routeQuote instanceof TechnicalServiceRouteQuote && isset($payload['travel_round_trip_km'])) {
            $technicalServiceRequest->fill($this->calculateTravelCosts((float) $payload['travel_round_trip_km']));
            $technicalServiceRequest->save();
        }

        $requestPayload = $this->workflowService->serialize($technicalServiceRequest->refresh(), true);

        if ($routeQuote instanceof TechnicalServiceRouteQuote) {
            $requestPayload['route_quote'] = app(TechnicalServiceRouteCostService::class)->payload($routeQuote->refresh());
        }

        return response()->json(['request' => $requestPayload]);
    }

    public function summary(): JsonResponse
    {
        $requests = TechnicalServiceRequest::query()->get()->each(fn (TechnicalServiceRequest $request) => $this->workflowService->initializeRequest($request));

        $statusCounts = $requests
            ->groupBy('status')
            ->map(fn ($items) => $items->count());

        $priorityCounts = $requests
            ->groupBy('priority')
            ->map(fn ($items) => $items->count());

        $riskCounts = $requests
            ->groupBy('risk_level')
            ->map(fn ($items) => $items->count());

        $workflowCounts = $requests
            ->groupBy('workflow_status')
            ->map(fn ($items) => $items->count());

        return response()->json([
            'total_requests' => $requests->count(),
            'ongoing_requests' => $requests->whereNotIn('workflow_status', ['Tamamlandı', 'İptal'])->count(),
            'status_counts' => $statusCounts,
            'priority_counts' => $priorityCounts,
            'risk_level_counts' => $riskCounts,
            'workflow_status_counts' => $workflowCounts,
            'workflow_queue_counts' => $this->workflowQueueCounts($requests),
            'customer_contact_counts' => [
                'aranacak' => $requests->where('customer_contact_status', 'aranacak')->count(),
                'arandı' => $requests->where('customer_contact_status', 'arandı')->count(),
                'ulaşılamadı' => $requests->where('customer_contact_status', 'ulaşılamadı')->count(),
                'tekrar_aranacak' => $requests->where('customer_contact_status', 'tekrar_aranacak')->count(),
                'müşteri_onayı_bekleniyor' => $requests->where('customer_contact_status', 'müşteri_onayı_bekleniyor')->count(),
                'müşteri_onayladı' => $requests->where('customer_contact_status', 'müşteri_onayladı')->count(),
                'müşteri_reddetti' => $requests->where('customer_contact_status', 'müşteri_reddetti')->count(),
                'yanlış_numara' => $requests->where('customer_contact_status', 'yanlış_numara')->count(),
                'iptal_talebi' => $requests->where('customer_contact_status', 'iptal_talebi')->count(),
            ],
            'scheduled_today' => $requests->filter(fn (TechnicalServiceRequest $request) => $request->scheduled_at?->isToday() ?? false)->count(),
        ]);
    }

    public function operationsDashboard(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'max:64'],
            'workflow_status' => ['nullable', 'string', 'max:128'],
            'service_type' => ['nullable', 'string', 'max:128'],
            'city' => ['nullable', 'string', 'max:255'],
            'technician_name' => ['nullable', 'string', 'max:255'],
            'warranty_started' => ['nullable', 'boolean'],
            'overdue' => ['nullable', 'boolean'],
        ]);

        $requests = $this->operationsDashboardQuery($filters)
            ->orderByRaw('scheduled_at IS NULL')
            ->orderBy('scheduled_at')
            ->orderByDesc('created_at')
            ->get()
            ->each(fn (TechnicalServiceRequest $request) => $this->workflowService->initializeRequest($request));

        $todayAppointments = $requests
            ->filter(fn (TechnicalServiceRequest $request) => $request->scheduled_at?->isToday() ?? false)
            ->values();
        $overdue = $requests
            ->filter(fn (TechnicalServiceRequest $request) => $this->isOverdueRequest($request))
            ->values();
        $warrantyStarted = $requests
            ->filter(fn (TechnicalServiceRequest $request) => $request->service_type === 'Montaj' && $request->installation_completed_at !== null)
            ->values();
        $pastScheduledNotCompleted = $requests
            ->filter(fn (TechnicalServiceRequest $request) => $this->isPastScheduledNotCompleted($request))
            ->values();

        return response()->json([
            'summary' => [
                'today_appointments' => $todayAppointments->count(),
                'pending' => $requests->where('status', 'Yeni')->count(),
                'assigned' => $requests->where('status', 'Atandı')->count(),
                'scheduled' => $requests->where('status', 'Randevulu')->count(),
                'in_progress' => $requests->where('status', 'Devam Ediyor')->count(),
                'completed' => $requests->where('status', 'Tamamlandı')->count(),
                'cancelled' => $requests->where('status', 'İptal')->count(),
                'overdue' => $overdue->count(),
                'warranty_started' => $warrantyStarted->count(),
                'past_scheduled_not_completed' => $pastScheduledNotCompleted->count(),
                'sla_overdue' => $requests->where('sla_status', TechnicalServiceWorkflowService::SLA_OVERDUE)->count(),
                'customer_call' => $requests->where('workflow_status', 'Müşteri Aranacak')->count(),
                'customer_unreachable' => $requests->where('workflow_status', 'Müşteriye Ulaşılamadı')->count(),
                'customer_callback' => $requests->where('customer_contact_status', 'tekrar_aranacak')->count(),
                'customer_confirmation' => $requests->where('workflow_status', 'Müşteri Onayı Bekleyen')->count(),
                'schedule_planning' => $requests->where('workflow_status', 'Müşteri Onayladı')->count(),
                'unassigned' => $requests->where('workflow_status', 'Usta Ataması Bekleyen')->count(),
                'technician_approval' => $requests->where('workflow_status', 'Usta Onayı Bekleyen')->count(),
                'travel_pending' => $requests->where('workflow_status', 'Planlı')->count(),
                'on_site_active' => $requests->where('workflow_status', 'Sahada')->count(),
                'checklist_missing' => $requests->filter(fn (TechnicalServiceRequest $request) => $request->workflow_status === 'Sahada' && $request->checklist_status !== 'tamamlandı')->count(),
                'photo_missing' => $requests->filter(fn (TechnicalServiceRequest $request) => in_array($request->workflow_status, ['Sahada', 'Belge / Fotoğraf Bekleyen'], true) && ! $this->photosComplete($request))->count(),
                'closure_pending_field' => $requests->where('workflow_status', 'Müşteri Kapanış Onayı Bekleyen')->count(),
                'incomplete' => $requests->filter(fn (TechnicalServiceRequest $request) => $request->workflow_status === 'Beklemede' && filled($request->incomplete_reason))->count(),
                'parts_pending' => $requests->where('workflow_status', 'Parça Bekleniyor')->count(),
                'second_visit' => $requests->where('requires_second_visit', true)->count(),
            ],
            'today_appointments' => $todayAppointments->map(fn (TechnicalServiceRequest $request) => $this->operationRequestPayload($request))->all(),
            'overdue_requests' => $overdue->map(fn (TechnicalServiceRequest $request) => $this->operationRequestPayload($request, true))->all(),
            'warranty_started_requests' => $warrantyStarted->map(fn (TechnicalServiceRequest $request) => $this->operationRequestPayload($request))->all(),
            'past_scheduled_not_completed' => $pastScheduledNotCompleted->map(fn (TechnicalServiceRequest $request) => $this->operationRequestPayload($request, true))->all(),
            'technician_summary' => $requests
                ->groupBy(fn (TechnicalServiceRequest $request) => trim((string) $request->technician_name) !== '' ? $request->technician_name : 'Atanmadı')
                ->map(fn ($items, string $technicianName) => [
                    'technician_name' => $technicianName,
                    'today_jobs' => $items->filter(fn (TechnicalServiceRequest $request) => $request->scheduled_at?->isToday() ?? false)->count(),
                    'open_jobs' => $items->whereNotIn('workflow_status', ['Tamamlandı', 'İptal'])->count(),
                    'completed_jobs' => $items->where('workflow_status', 'Tamamlandı')->count(),
                    'overdue_jobs' => $items->filter(fn (TechnicalServiceRequest $request) => $this->isOverdueRequest($request))->count(),
                ])
                ->sortByDesc('open_jobs')
                ->values()
                ->all(),
            'city_summary' => $requests
                ->groupBy(fn (TechnicalServiceRequest $request) => trim((string) $request->customer_city) !== '' ? $request->customer_city : 'Belirtilmedi')
                ->map(fn ($items, string $city) => [
                    'city' => $city,
                    'open_requests' => $items->whereNotIn('workflow_status', ['Tamamlandı', 'İptal'])->count(),
                    'today_appointments' => $items->filter(fn (TechnicalServiceRequest $request) => $request->scheduled_at?->isToday() ?? false)->count(),
                    'overdue_requests' => $items->filter(fn (TechnicalServiceRequest $request) => $this->isOverdueRequest($request))->count(),
                ])
                ->sortByDesc('open_requests')
                ->values()
                ->all(),
            'workflow_queue_counts' => $this->workflowQueueCounts($requests),
        ]);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function operationsDashboardQuery(array $filters)
    {
        return TechnicalServiceRequest::query()
            ->when(! empty($filters['date_from']), fn ($query) => $query->whereDate('scheduled_at', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn ($query) => $query->whereDate('scheduled_at', '<=', $filters['date_to']))
            ->when(! empty($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(! empty($filters['workflow_status']), fn ($query) => $query->where('workflow_status', $filters['workflow_status']))
            ->when(! empty($filters['service_type']), fn ($query) => $query->where('service_type', $filters['service_type']))
            ->when(! empty($filters['city']), fn ($query) => $query->where('customer_city', $filters['city']))
            ->when(! empty($filters['technician_name']), fn ($query) => $query->where('technician_name', $filters['technician_name']))
            ->when(array_key_exists('warranty_started', $filters), function ($query) use ($filters) {
                return filter_var($filters['warranty_started'], FILTER_VALIDATE_BOOL)
                    ? $query->whereNotNull('installation_completed_at')
                    : $query->whereNull('installation_completed_at');
            })
            ->when(array_key_exists('overdue', $filters) && filter_var($filters['overdue'], FILTER_VALIDATE_BOOL), function ($query) {
                $query->whereNotNull('scheduled_at')
                    ->where('scheduled_at', '<', now())
                    ->whereNotIn('workflow_status', ['Tamamlandı', 'İptal']);
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function operationRequestPayload(TechnicalServiceRequest $request, bool $includeOverdue = false): array
    {
        return [
            'id' => $request->id,
            'mrn' => $request->mrn,
            'customer_name' => $request->customer_name,
            'customer_phone' => $request->customer_phone,
            'customer_city' => $request->customer_city,
            'customer_district' => $request->customer_district,
            'service_address' => $request->service_address,
            'product_name' => $request->product_name,
            'product_model' => $request->product_model,
            'serial_number' => $request->serial_number,
            'service_type' => $request->service_type,
            'technician_name' => $request->technician_name,
            'scheduled_at' => $request->scheduled_at?->toISOString(),
            'scheduled_time' => $request->scheduled_time,
            'status' => $request->status,
            'workflow_status' => $request->workflow_status,
            'next_action' => $request->next_action,
            'sla_status' => $request->sla_status,
            'customer_contact_status' => $request->customer_contact_status,
            'customer_callback_at' => $request->customer_callback_at?->toISOString(),
            'customer_preferred_date' => $request->customer_preferred_date?->toDateString(),
            'customer_preferred_time_start' => $request->customer_preferred_time_start,
            'customer_preferred_time_end' => $request->customer_preferred_time_end,
            'field_status' => $request->field_status,
            'checklist_status' => $request->checklist_status,
            'photo_status' => $request->photo_status,
            'document_status' => $request->document_status,
            'before_photo_count' => $request->before_photo_count,
            'after_photo_count' => $request->after_photo_count,
            'general_photo_count' => $request->general_photo_count,
            'customer_closure_approval_status' => $request->customer_closure_approval_status,
            'incomplete_reason' => $request->incomplete_reason,
            'requires_second_visit' => $request->requires_second_visit,
            'installation_completed_at' => $request->installation_completed_at?->toISOString(),
            'warranty_started_at' => $request->installation_completed_at?->toDateString(),
            'overdue_label' => $includeOverdue ? $this->overdueLabel($request) : null,
        ];
    }

    private function isOverdueRequest(TechnicalServiceRequest $request): bool
    {
        return $request->scheduled_at !== null
            && $request->scheduled_at->isPast()
            && ! in_array($request->workflow_status, ['Tamamlandı', 'İptal'], true);
    }

    private function isPastScheduledNotCompleted(TechnicalServiceRequest $request): bool
    {
        return $request->scheduled_at !== null
            && $request->scheduled_at->isPast()
            && $request->installation_completed_at === null
            && ! in_array($request->workflow_status, ['Tamamlandı', 'İptal'], true);
    }

    private function overdueLabel(TechnicalServiceRequest $request): ?string
    {
        if (! $request->scheduled_at) {
            return null;
        }

        $minutes = max(0, (int) $request->scheduled_at->diffInMinutes(now()));
        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);

        if ($days > 0) {
            return $hours > 0 ? "{$days} gün {$hours} saat gecikmiş" : "{$days} gün gecikmiş";
        }

        return $hours > 0 ? "{$hours} saat gecikmiş" : "{$minutes} dakika gecikmiş";
    }

    private function workflowQueueCounts($requests): array
    {
        return [
            'customer_call' => $requests->where('workflow_status', 'Müşteri Aranacak')->count(),
            'customer_unreachable' => $requests->where('workflow_status', 'Müşteriye Ulaşılamadı')->count(),
            'customer_callback' => $requests->where('customer_contact_status', 'tekrar_aranacak')->count(),
            'customer_confirmation' => $requests->where('workflow_status', 'Müşteri Onayı Bekleyen')->count(),
            'schedule_planning' => $requests->where('workflow_status', 'Müşteri Onayladı')->count(),
            'unassigned' => $requests->where('workflow_status', 'Usta Ataması Bekleyen')->count(),
            'technician_approval' => $requests->where('workflow_status', 'Usta Onayı Bekleyen')->count(),
            'sla_overdue' => $requests->where('sla_status', TechnicalServiceWorkflowService::SLA_OVERDUE)->count(),
            'travel_pending' => $requests->where('workflow_status', 'Planlı')->count(),
            'on_site_active' => $requests->where('workflow_status', 'Sahada')->count(),
            'checklist_missing' => $requests->filter(fn (TechnicalServiceRequest $request) => $request->workflow_status === 'Sahada' && $request->checklist_status !== 'tamamlandı')->count(),
            'photo_missing' => $requests->filter(fn (TechnicalServiceRequest $request) => in_array($request->workflow_status, ['Sahada', 'Belge / Fotoğraf Bekleyen'], true) && ! $this->photosComplete($request))->count(),
            'closure_pending_field' => $requests->where('workflow_status', 'Müşteri Kapanış Onayı Bekleyen')->count(),
            'incomplete' => $requests->filter(fn (TechnicalServiceRequest $request) => $request->workflow_status === 'Beklemede' && filled($request->incomplete_reason))->count(),
            'parts_pending' => $requests->where('workflow_status', 'Parça Bekleniyor')->count(),
            'second_visit' => $requests->where('requires_second_visit', true)->count(),
        ];
    }

    private function photosComplete(TechnicalServiceRequest $request): bool
    {
        return (int) ($request->before_photo_count ?? 0) >= 3
            && (int) ($request->after_photo_count ?? 0) >= 3
            && (int) ($request->general_photo_count ?? 0) >= 1;
    }

    private function generateMrn(): string
    {
        $today = now()->format('Ymd');
        $last = TechnicalServiceRequest::query()
            ->where('mrn', 'like', "MRN-{$today}-%")
            ->orderByDesc('id')
            ->value('mrn');

        $sequence = 1;

        if ($last !== null && preg_match('/-(\d{4})$/', $last, $matches)) {
            $sequence = (int) $matches[1] + 1;
        }

        return sprintf('MRN-%s-%04d', $today, $sequence);
    }

    /**
     * @return array<string, mixed>
     */
    private function calculateTravelCosts(float $roundTripKm): array
    {
        $roundTripKm = round(max($roundTripKm, 0), 2);
        $billableKm = round(max($roundTripKm - 30, 0), 2);
        $feePerKm = config('services.google.routes_fee_per_km');
        $travelFee = is_numeric($feePerKm) ? round($billableKm * (float) $feePerKm, 2) : null;

        return [
            'travel_round_trip_km' => $roundTripKm,
            'travel_billable_km' => $billableKm,
            'travel_fee_amount' => $travelFee,
            'travel_calculation_source' => 'manual',
            'travel_calculated_at' => now(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function validateInstallationAfterLatestSale(TechnicalServiceRequest $request, array $payload): void
    {
        if (
            ($payload['status'] ?? null) !== 'Tamamlandı'
            || $request->service_type !== 'Montaj'
            || empty($payload['installation_completed_at'])
            || empty($request->serial_number)
        ) {
            return;
        }

        $latestSale = app(MikroSerialNumberService::class)->latestValidSale($request->serial_number);
        $saleDate = $latestSale['date'] ?? null;

        if (! $saleDate) {
            return;
        }

        if (CarbonImmutable::parse($payload['installation_completed_at'])->lessThan(CarbonImmutable::parse($saleDate)->startOfDay())) {
            throw ValidationException::withMessages([
                'installation_completed_at' => 'Fiili montaj tarihi son geçerli Mikro satış tarihinden önce olamaz.',
            ]);
        }
    }
}
