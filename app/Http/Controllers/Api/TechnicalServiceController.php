<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignTechnicalServiceRequest;
use App\Http\Requests\StoreTechnicalServiceContactLogRequest;
use App\Http\Requests\StoreTechnicalServiceRequest;
use App\Http\Requests\UpdateTechnicalServiceRequest;
use App\Http\Requests\UpdateTechnicalServiceRequestStatus;
use App\Http\Requests\UpdateTechnicalServiceScheduleRequest;
use App\Http\Requests\UpdateTechnicalServiceTechnicianWorkflowRequest;
use App\Http\Requests\UpdateTechnicalServiceWorkflowRequest;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceTechnician;
use App\Services\TechnicalService\MikroSerialNumberService;
use App\Services\TechnicalService\TechnicalServiceWorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        $this->validateInstallationAfterLatestSale($technicalServiceRequest, $payload);

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
            'legacy_status_update'
        );

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

    public function auditLogs(TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
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

        $technicianPayload = [
            'technical_service_technician_id' => $technician?->id,
            'technician_name' => $technician?->name ?? ($payload['technician_name'] ?? null),
            'technician_approval_status' => 'bekliyor',
            'note' => $payload['note'] ?? null,
        ];

        $technicalServiceRequest = $this->workflowService->updateTechnician(
            $technicalServiceRequest,
            $technicianPayload,
            $request->user()
        );

        if (isset($payload['travel_round_trip_km'])) {
            $technicalServiceRequest->fill($this->calculateTravelCosts((float) $payload['travel_round_trip_km']));
            $technicalServiceRequest->save();
        }

        return response()->json(['request' => $this->workflowService->serialize($technicalServiceRequest, true)]);
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
            'customer_city' => $request->customer_city,
            'customer_district' => $request->customer_district,
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
        ];
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
        $travelFee = round($billableKm * 10, 2);

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
