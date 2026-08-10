<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignTechnicalServiceRequest;
use App\Http\Requests\StoreTechnicalServiceContactLogRequest;
use App\Http\Requests\StoreTechnicalServiceRequest;
use App\Http\Requests\UpdateTechnicalServiceFieldActionRequest;
use App\Http\Requests\UpdateTechnicalServiceRequest;
use App\Http\Requests\UpdateTechnicalServiceRequestStatus;
use App\Http\Requests\UpdateTechnicalServiceScheduleRequest;
use App\Http\Requests\UpdateTechnicalServiceTechnicianWorkflowRequest;
use App\Http\Requests\UpdateTechnicalServiceWorkflowRequest;
use App\Models\B2B\B2BPartnerTechnician;
use App\Models\TechnicalServiceAssignmentArchive;
use App\Models\TechnicalServiceAssignmentOffer;
use App\Models\TechnicalServiceCustomerConfirmation;
use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServicePartnerJobAction;
use App\Models\TechnicalServicePartRequest;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestSerial;
use App\Models\TechnicalServiceRequestUpload;
use App\Models\TechnicalServiceRouteQuote;
use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use App\Services\B2B\B2BPartnerServiceJobScopeService;
use App\Services\Messaging\TechnicalServiceAppointmentMessageDispatchService;
use App\Services\Messaging\TechnicalServiceMessageIdempotencyService;
use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use App\Services\Messaging\TechnicalServiceWorkflowMessageDispatchService;
use App\Services\Payments\PaymentProviderManager;
use App\Services\Payments\TechnicalServicePaymentProviderReconciliationService;
use App\Services\Payments\TechnicalServicePaymentProviderSettingsService;
use App\Services\TechnicalService\MikroInvoiceSerialsService;
use App\Services\TechnicalService\MikroSerialNumberService;
use App\Services\TechnicalService\MountRequestSubmitService;
use App\Services\TechnicalService\TechnicalServiceAssignmentSettlementService;
use App\Services\TechnicalService\TechnicalServiceCancelContextService;
use App\Services\TechnicalService\TechnicalServiceCodeGenerator;
use App\Services\TechnicalService\TechnicalServiceOperationalStatePresenter;
use App\Services\TechnicalService\TechnicalServicePaymentActionPresenter;
use App\Services\TechnicalService\TechnicalServicePaymentSettlementService;
use App\Services\TechnicalService\TechnicalServiceRouteCostService;
use App\Services\TechnicalService\TechnicalServiceServiceVisitService;
use App\Services\TechnicalService\TechnicalServiceUiLabelService;
use App\Services\TechnicalService\TechnicalServiceWorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class TechnicalServiceController extends Controller
{
    public function __construct(
        private readonly TechnicalServiceWorkflowService $workflowService,
        private readonly TechnicalServiceWorkflowMessageDispatchService $workflowMessages,
        private readonly TechnicalServiceAppointmentMessageDispatchService $appointmentMessages,
        private readonly TechnicalServiceMessagingSettingsService $messagingSettings,
        private readonly TechnicalServiceMessageIdempotencyService $messageIdempotency,
        private readonly TechnicalServiceCodeGenerator $codeGenerator,
        private readonly TechnicalServiceServiceVisitService $serviceVisitService,
        private readonly TechnicalServiceAssignmentSettlementService $assignmentSettlementService,
        private readonly B2BPartnerServiceJobScopeService $partnerJobScope,
    ) {}

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

        $query = TechnicalServiceRequest::query()
            ->whereDoesntHave('childRequests', fn ($query) => $this->nonCancelledChildServiceVisitQuery($query));

        if (! empty($filters['search'])) {
            $needle = '%'.mb_strtolower(trim((string) $filters['search'])).'%';

            $query->where(function ($query) use ($needle) {
                foreach (['mrn', 'customer_name', 'product_name', 'serial_number', 'technician_name'] as $column) {
                    $query->orWhereRaw("LOWER({$column}) LIKE ?", [$needle]);
                }
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

        $limit = $filters['limit'] ?? 200;

        $paginator = $query
            ->orderByRaw('scheduled_at IS NULL')
            ->orderBy('scheduled_at')
            ->orderByDesc('created_at')
            ->paginate($limit);

        $items = collect($paginator->items());
        $this->workflowService->preloadSerializationContext($items);

        return response()->json([
            'items' => $items
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

    private function nonCancelledChildServiceVisitQuery($query)
    {
        return $query
            ->whereNull('cancelled_at')
            ->whereNotIn('status', ['İptal', 'Iptal', 'Ä°ptal'])
            ->whereNotIn('workflow_status', ['İptal', 'Iptal', 'Ä°ptal']);
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
            $payload['mrn'] = $this->codeGenerator->nextMrn((string) ($payload['customer_name'] ?? ''));

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
                    'actor_user_id' => $user?->id,
                    'actor_role' => $user?->role_code ?: 'authenticated_user',
                    'source' => 'technical_service_admin',
                    'occurred_at_istanbul' => now('Europe/Istanbul')->toIso8601String(),
                    'request_id' => $requestModel->id,
                    'mrn' => $requestModel->mrn,
                    'srv' => $requestModel->service_code,
                ],
            ]);

            return $requestModel;
        });

        $messageDispatches = $this->workflowMessages->queueWorkflowDispatches(
            $requestModel->refresh(),
            'new_request_created_ops',
            'ops',
            [
                'actor_name' => $user?->name ?: 'Teknik servis formu',
                'customer_name' => $requestModel->customer_name,
                'customer_phone' => $requestModel->customer_phone,
                'product_name' => $requestModel->product_name,
                'address' => $requestModel->location_formatted_address ?: $requestModel->service_address,
                'next_action_text' => 'Talebi inceleyin ve uygun ustayı atayın.',
            ],
            $user,
            null,
            [
                'triggered_by' => 'technical_service_request_created',
                'event_version' => 'new-request:'.$requestModel->id,
                'metadata' => ['workflow_event' => 'new_request_created_ops'],
            ],
        );

        return response()->json([
            'request' => $this->workflowService->serialize($requestModel, true),
            'message_dispatches' => $messageDispatches,
        ], 201);
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
        $requestedStatusToken = $this->statusToken($payload['status'] ?? null);
        $isNewStatus = $requestedStatusToken === 'yeni';
        $isCancellationReview = $this->workflowService->isCancellationReview($technicalServiceRequest);
        $isCancelRequest = $this->isCancelledStatusValue($payload['status'] ?? null);
        $isCompletedReopen = $isNewStatus
            && $this->isCompletedRequestForCleanServiceVisit($technicalServiceRequest, $previousLegacyStatus);
        $isAccidentalCompletionReopen = $isCompletedReopen
            && $this->isAccidentalCompletionReopenReason($payload['reopen_reason'] ?? null);
        $isReopen = $isNewStatus
            && ($isCancellationReview || $this->isCompletedStatusValue($previousLegacyStatus) || $this->isCancelledStatusValue($previousLegacyStatus));
        $this->validateInstallationAfterLatestSale($technicalServiceRequest, $payload);

        if ($isAccidentalCompletionReopen) {
            $technicalServiceRequest = $this->serviceVisitService->reopenAccidentalCompletionInPlace(
                $technicalServiceRequest,
                $request->user(),
                (string) ($payload['reopen_reason'] ?? 'Yanlışlıkla tamamlandı'),
                $payload['reopen_note'] ?? ($payload['note'] ?? null),
            );

            return response()->json([
                'request' => $this->workflowService->serialize($technicalServiceRequest, true),
                'reopened_in_place' => true,
            ]);
        }

        if ($isCompletedReopen) {
            $child = $this->serviceVisitService->createCleanServiceVisitFromCompletedRequest(
                $technicalServiceRequest,
                $request->user(),
                (string) ($payload['reopen_reason'] ?? 'Operasyon düzeltmesi'),
                $payload['reopen_note'] ?? ($payload['note'] ?? null),
                (string) ($payload['reopen_type'] ?? 'service_request'),
            );
            $parent = $technicalServiceRequest->fresh();

            return response()->json([
                'request' => $this->workflowService->serialize($child, true),
                'child_request' => $this->workflowService->serialize($child, true),
                'parent_request' => $parent ? $this->workflowService->serialize($parent, true) : null,
                'reopened_as_service_visit' => true,
            ]);
        }

        if ($isReopen && ! filled($payload['reopen_reason'] ?? null)) {
            throw ValidationException::withMessages([
                'reopen_reason' => 'Yeniden açma nedeni zorunludur.',
            ]);
        }

        if ($isCancelRequest && ! $isCancellationReview && $this->isCompletedServiceVisitRequest($technicalServiceRequest, $previousLegacyStatus)) {
            throw ValidationException::withMessages([
                'status' => 'Tamamlanmış SRV iptali için admin düzeltme akışı kullanılmalı.',
            ]);
        }

        if ($isCancelRequest && $this->isCancelledStatusValue($previousLegacyStatus)) {
            return response()->json([
                'request' => $this->workflowService->serialize($technicalServiceRequest->refresh(), true),
                'duplicate_noop' => true,
                'message_dispatches' => [],
            ]);
        }

        if ($isReopen) {
            $technicalServiceRequest->reopened_at = now();
            $technicalServiceRequest->reopened_by_user_id = $request->user()?->id;
            $technicalServiceRequest->reopen_reason = $payload['reopen_reason'] ?? null;
            $technicalServiceRequest->reopen_note = $payload['reopen_note'] ?? ($payload['note'] ?? null);
            $technicalServiceRequest->reopen_count = ((int) $technicalServiceRequest->reopen_count) + 1;
        } elseif ($isNewStatus) {
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
        if ($this->isCompletedStatusValue($payload['status'])) {
            $targetWorkflowStatus = $this->workflowService->normalizeWorkflowStatus($payload['status'], $payload['status']);
        } elseif ($this->isCancelledStatusValue($payload['status'])) {
            $targetWorkflowStatus = $this->workflowService->normalizeWorkflowStatus($payload['status'], $payload['status']);
        }
        $targetIsCompleted = $this->isCompletedStatusValue($targetWorkflowStatus);
        $targetIsCancelled = $this->isCancelledStatusValue($targetWorkflowStatus);

        $workflowPayload = [
            'note' => $payload['reopen_note'] ?? $payload['note'] ?? ($payload['resolution_notes'] ?? null),
            'resolution_notes' => $payload['resolution_notes'] ?? null,
            'installation_completed_at' => $payload['installation_completed_at'] ?? null,
            'customer_closure_approval_status' => $targetWorkflowStatus === 'Tamamlandı' ? 'onaylandı' : null,
            'customer_closure_approved_at' => $targetWorkflowStatus === 'Tamamlandı' ? now() : null,
            'cancellation_reason' => $targetWorkflowStatus === 'İptal' ? ($payload['note'] ?? null) : null,
        ];

        $technicalServiceRequest = DB::transaction(fn (): TechnicalServiceRequest => $this->workflowService->transition(
            $technicalServiceRequest,
            $targetWorkflowStatus,
            $workflowPayload,
            $request->user(),
            $isReopen
                ? 'technical_service_request_reopened'
                : ($targetIsCancelled ? 'cancellation_confirmed' : 'legacy_status_update')
        ));

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

        $messageDispatches = [];
        if ($targetIsCancelled) {
            $cancelContext = [
                'cancellation_reason' => $payload['note'],
                'customer_visible_note' => $payload['note'],
                'technician_visible_note' => $payload['note'],
                'appointment_date_formatted' => $technicalServiceRequest->scheduled_date?->format('d.m.Y'),
                'appointment_exact_time_range' => $technicalServiceRequest->scheduled_time,
            ];
            $messageDispatches['customer'] = $this->workflowMessages->queueWorkflowDispatches(
                $technicalServiceRequest->refresh(),
                'appointment_cancelled_customer',
                'customer',
                $cancelContext,
                $request->user(),
                null,
                [
                    'triggered_by' => 'ops_request_cancelled',
                    'event_version' => 'cancel:'.$technicalServiceRequest->id.':'.$technicalServiceRequest->cancelled_at?->timestamp,
                    'metadata' => ['workflow_event' => 'cancellation_confirmed'],
                ],
            );
            if ($technicalServiceRequest->technical_service_technician_id !== null || filled($technicalServiceRequest->technician_name)) {
                $messageDispatches['technician'] = $this->workflowMessages->queueWorkflowDispatches(
                    $technicalServiceRequest->refresh(),
                    'appointment_cancelled_technician',
                    'technician',
                    $cancelContext,
                    $request->user(),
                    null,
                    [
                        'triggered_by' => 'ops_request_cancelled',
                        'event_version' => 'cancel:'.$technicalServiceRequest->id.':'.$technicalServiceRequest->cancelled_at?->timestamp,
                        'metadata' => ['workflow_event' => 'cancellation_confirmed'],
                    ],
                );
            }
        }

        return response()->json([
            'request' => $this->workflowService->serialize($technicalServiceRequest, true),
            'message_dispatches' => $messageDispatches,
        ]);
    }

    private function isCompletedRequestForCleanServiceVisit(TechnicalServiceRequest $request, ?string $legacyStatus = null): bool
    {
        $completedStatuses = ['TamamlandÄ±', 'Tamamlandı', 'Tamamlandi', 'TamamlandÃ„Â±'];

        return $request->completed_at !== null
            || $request->installation_completed_at !== null
            || in_array((string) $legacyStatus, $completedStatuses, true)
            || in_array((string) $request->status, $completedStatuses, true)
            || in_array((string) $request->workflow_status, $completedStatuses, true);
    }

    private function isTerminalOperationRequest(TechnicalServiceRequest $request): bool
    {
        return $request->cancelled_at !== null
            || $request->completed_at !== null
            || $this->isCancelledStatusValue($request->status)
            || $this->isCancelledStatusValue($request->workflow_status)
            || $this->isCompletedStatusValue($request->status)
            || $this->isCompletedStatusValue($request->workflow_status);
    }

    private function isCompletedServiceVisitRequest(TechnicalServiceRequest $request, ?string $previousLegacyStatus = null): bool
    {
        $isServiceVisit = $request->parent_request_id !== null || filled($request->service_code);

        return $isServiceVisit
            && (
                $request->completed_at !== null
                || $this->isCompletedStatusValue($previousLegacyStatus)
                || $this->isCompletedStatusValue($request->status)
                || $this->isCompletedStatusValue($request->workflow_status)
            );
    }

    private function isCompletedStatusValue(?string $status): bool
    {
        return in_array($this->statusToken($status), ['tamamlandi', 'tamamlanda', 'tamamland'], true);
    }

    private function isAccidentalCompletionReopenReason(?string $reason): bool
    {
        $token = $this->statusToken($reason);

        return str_starts_with($token, 'yanlislikla')
            || str_starts_with($token, 'yanllkla')
            || str_contains($token, 'yanlis')
            || str_contains($token, 'yanls');
    }

    private function isCancelledStatusValue(?string $status): bool
    {
        return str_ends_with($this->statusToken($status), 'ptal');
    }

    private function statusToken(?string $status): string
    {
        return Str::of((string) $status)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->value();
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
        $payload = $request->validated();
        $previousDate = $technicalServiceRequest->scheduled_date?->toDateString();
        $previousTime = $technicalServiceRequest->scheduled_time;
        $technicalServiceRequest = $this->workflowService->updateSchedule(
            $technicalServiceRequest,
            $payload,
            $request->user()
        );

        $scheduleChanged = $previousDate !== $technicalServiceRequest->scheduled_date?->toDateString()
            || $previousTime !== $technicalServiceRequest->scheduled_time;
        $messageDispatches = null;
        if ($scheduleChanged) {
            $start = (string) $technicalServiceRequest->scheduled_time;
            $end = trim((string) ($payload['scheduled_time_end'] ?? '')) ?: null;
            $messageDispatches = $this->appointmentMessages->dispatchUpdate(
                $technicalServiceRequest->refresh(),
                null,
                $request->user(),
                [
                    'trigger_source' => 'ops_schedule_update',
                    'slot' => [
                        'start_time' => $start,
                        'end_time' => $end,
                        'label' => $end === null ? $start : "{$start} - {$end}",
                    ],
                    'metadata' => [
                        'previous_scheduled_date' => $previousDate,
                        'previous_scheduled_time' => $previousTime,
                        'updated_by_user_id' => $request->user()?->id,
                    ],
                ],
            );
        }

        return response()->json([
            'request' => $this->workflowService->serialize($technicalServiceRequest, true),
            'schedule_changed' => $scheduleChanged,
            'message_dispatches' => $messageDispatches,
        ]);
    }

    public function updateTechnician(UpdateTechnicalServiceTechnicianWorkflowRequest $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        $payload = $request->validated();
        $sourceWorkflowStatus = $this->workflowService->currentWorkflowStatus($technicalServiceRequest);
        $isReviewReassignment = $this->workflowService->canReopenForAssignment($technicalServiceRequest);
        $oldTechnicianId = $technicalServiceRequest->technical_service_technician_id;
        $oldPartnerId = $this->partnerJobScope->activeAssignmentLink($technicalServiceRequest)?->partner_id;

        if (! empty($payload['technical_service_technician_id'])) {
            $technician = TechnicalServiceTechnician::query()->find($payload['technical_service_technician_id']);
            $payload['technician_name'] = $technician?->name ?? $payload['technician_name'] ?? null;
        } else {
            $technician = null;
        }

        $assignmentLink = $this->assignmentLinkForTechnician($technician, $payload['b2b_partner_id'] ?? null);

        $payload['reassign_after_review'] = $isReviewReassignment;
        $technicalServiceRequest = $this->workflowService->updateTechnician($technicalServiceRequest, $payload, $request->user());

        $newPartnerId = $assignmentLink?->partner_id;

        if ((int) ($oldTechnicianId ?? 0) !== (int) ($technician?->id ?? 0)
            || (int) ($oldPartnerId ?? 0) !== (int) ($newPartnerId ?? 0)
            || $isReviewReassignment) {
            TechnicalServiceAssignmentArchive::query()->create([
                'technical_service_request_id' => $technicalServiceRequest->id,
                'old_technician_id' => $oldTechnicianId,
                'new_technician_id' => $technician?->id,
                'old_partner_id' => $oldPartnerId,
                'new_partner_id' => $newPartnerId,
                'reason' => $payload['note'] ?? ($isReviewReassignment ? 'reassign_after_review' : 'reassignment'),
                'archived_by' => $request->user()?->id,
                'archived_at' => now(),
                'metadata' => [
                    'source' => 'technical_service_update_technician',
                    'reassign_after_review' => $isReviewReassignment,
                    'source_workflow_status' => $sourceWorkflowStatus,
                    'target_workflow_status' => $technicalServiceRequest->workflow_status,
                ],
            ]);

            $this->resolveReviewReassignmentState(
                $technicalServiceRequest,
                $payload,
                $request->user(),
                $technician,
                $sourceWorkflowStatus,
                $isReviewReassignment,
            );
        }

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

        $technicalServiceRequest->refresh();
        $responsePayload = [
            'operation_control_update' => $this->workflowService->operationControlUpdatePayload($technicalServiceRequest),
        ];

        if ($this->operationControlChangeAffectsAssignmentReadiness(array_keys($validated))) {
            $responsePayload['request'] = $this->workflowService->serialize($technicalServiceRequest, true);
        }

        return response()->json($responsePayload);
    }

    /**
     * @param  array<int, string>  $changedKeys
     */
    private function operationControlChangeAffectsAssignmentReadiness(array $changedKeys): bool
    {
        return collect($changedKeys)->intersect([
            'payment_checked',
            'address_checked',
            'door_photos_checked',
            'missing_info',
            'customer_call_required',
            'schedule_update_required',
        ])->isNotEmpty();
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
            'total_amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
            'message_text' => ['nullable', 'string', 'max:5000'],
            'manual_override' => ['nullable', 'boolean'],
            'earning_revision' => ['required', 'string', 'size:64'],
        ]);

        if (blank($technician->phone_e164) && blank($technician->phone_display) && blank($technician->phone)) {
            throw ValidationException::withMessages([
                'technician' => 'Usta telefonu olmadan hakediş bilgisi gönderilemez.',
            ]);
        }

        $result = DB::transaction(function () use ($technicalServiceRequest, $technician, $validated, $request): array {
            $job = TechnicalServiceRequest::query()
                ->whereKey($technicalServiceRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) ($job->technical_service_technician_id ?? 0) !== (int) $technician->id) {
                throw ValidationException::withMessages([
                    'technician' => 'Hakediş bilgisi yalnız aktif atamadaki ustaya gönderilebilir. Önce Servise Ata işlemini tamamlayın.',
                ]);
            }

            $offer = TechnicalServiceAssignmentOffer::query()
                ->where('technical_service_request_id', $job->id)
                ->where('technical_service_technician_id', $technician->id)
                ->whereIn('status', [
                    TechnicalServiceAssignmentOffer::STATUS_SENT,
                    TechnicalServiceAssignmentOffer::STATUS_ACCEPTED,
                    TechnicalServiceAssignmentOffer::STATUS_REVISED,
                ])
                ->latest('id')
                ->lockForUpdate()
                ->first();
            if (! $offer instanceof TechnicalServiceAssignmentOffer) {
                throw ValidationException::withMessages([
                    'assignment_offer' => 'Gönderilebilir canonical hakediş kaydı bulunamadı. Önce Servise Ata işlemini tamamlayın.',
                ]);
            }

            $presentation = $this->workflowService->technicianEarningPresentation($job, $technician, $offer);
            $earningSnapshot = $presentation['earning_snapshot'];
            $earningSnapshotRevision = (string) $earningSnapshot['revision'];
            if (! hash_equals($earningSnapshotRevision, (string) $validated['earning_revision'])) {
                throw ValidationException::withMessages([
                    'earning_revision' => 'Hakediş bilgisi değişti. Güncel kaydı yenileyip tekrar deneyin.',
                ]);
            }

            $jobCardContext = $this->partnerJobScope->technicianJobCardContext($job);
            if (($jobCardContext['ready'] ?? false) !== true) {
                throw ValidationException::withMessages([
                    'assignment_offer' => (string) ($jobCardContext['blocker_message'] ?? 'Aktif atamaya ait usta iş kartı hazır değil.'),
                ]);
            }

            $earningSnapshotFingerprint = $earningSnapshotRevision;
            $existingDispatch = TechnicalServiceMessageDispatch::query()
                ->where('technical_service_request_id', $job->id)
                ->where('message_type', 'earnings_message_technician')
                ->where('recipient_role', 'technician')
                ->latest('id')
                ->get()
                ->first(fn (TechnicalServiceMessageDispatch $dispatch): bool => hash_equals(
                    $earningSnapshotFingerprint,
                    (string) data_get($dispatch->metadata, 'earning_snapshot_fingerprint', ''),
                ));
            if ($existingDispatch instanceof TechnicalServiceMessageDispatch) {
                $operationControl = is_array($job->operation_control_payload) ? $job->operation_control_payload : [];
                $earningPayload = is_array($operationControl['technician_earning_message'] ?? null)
                    ? $operationControl['technician_earning_message']
                    : [];
                $messageText = trim((string) ($earningPayload['message_text'] ?? $existingDispatch->bodyForProvider()));
                $whatsappPhone = $this->normalizedMessagePhone(
                    $technician->phone_e164 ?: ($technician->phone_display ?: $technician->phone),
                );

                return [
                    'request' => $job,
                    'assignment_offer' => $offer,
                    'earning_snapshot' => $earningSnapshot,
                    'message_preview' => $presentation['message_preview'],
                    'message_text' => $messageText,
                    'copy_text' => $messageText,
                    'whatsapp_url' => $whatsappPhone !== ''
                        ? 'https://wa.me/'.$whatsappPhone.'?text='.rawurlencode($messageText)
                        : '',
                    'dispatch' => $existingDispatch,
                    'duplicate_noop' => true,
                ];
            }

            $result = $this->workflowService->recordTechnicianEarningsMessage(
                $job,
                $technician,
                $offer,
                $validated,
                $request->user(),
            );

            $operationControl = is_array($result['request']->operation_control_payload) ? $result['request']->operation_control_payload : [];
            $earningPayload = is_array($operationControl['technician_earning_message'] ?? null)
                ? $operationControl['technician_earning_message']
                : [];
            $dispatch = $this->workflowMessages->queueSystemMessage(
                $job,
                'earnings_message_technician',
                'technician',
                $result['message_text'],
                [
                    'copy_text' => $result['copy_text'],
                    'whatsapp_url' => $result['whatsapp_url'],
                    'labor_amount' => $earningPayload['labor_amount'] ?? null,
                    'route_fee_amount' => $earningPayload['route_fee_amount'] ?? null,
                    'total_amount' => $earningPayload['total_amount'] ?? null,
                    'earning_snapshot' => $earningSnapshot,
                    'earning_revision' => $earningSnapshotRevision,
                    'submitted_total_amount' => $earningPayload['submitted_total_amount'] ?? null,
                    'total_amount_corrected' => $earningPayload['total_amount_corrected'] ?? false,
                ],
                $request->user(),
                null,
                [
                    'recipient_phone' => $technician->phone_e164 ?: ($technician->phone_display ?: $technician->phone),
                    'triggered_by' => 'technical_service_earnings_message',
                    'event_version' => 'assignment-offer:'.$offer->id.':earnings-summary:'.$earningSnapshotFingerprint,
                    'requires_public_url' => $jobCardContext['canonical_url'],
                    'metadata' => [
                        'assignment_offer_id' => $offer->id,
                        'earning_snapshot_fingerprint' => $earningSnapshotFingerprint,
                        'earning_snapshot_revision' => $earningSnapshotRevision,
                        'earning_snapshot' => $earningSnapshot,
                        'manual_ui_send' => true,
                    ],
                ],
            );

            $operationControl['technician_earning_message'] = [
                ...$earningPayload,
                'status' => $dispatch->status,
                'dispatch_id' => $dispatch->id,
                'queued_at' => $dispatch->queued_at?->toISOString(),
                'sent_at' => in_array($dispatch->status, TechnicalServiceMessageDispatch::SUCCESS_STATUSES, true)
                    ? $dispatch->sent_at?->toISOString()
                    : null,
                'last_error_code' => $dispatch->last_error_code,
                'last_error_message_redacted' => $dispatch->last_error_message_redacted,
            ];
            $job->forceFill(['operation_control_payload' => $operationControl])->save();

            return [
                ...$result,
                'request' => $job->refresh(),
                'dispatch' => $dispatch,
                'duplicate_noop' => false,
            ];
        });

        return response()->json([
            'ok' => true,
            'earning_snapshot' => $result['earning_snapshot'],
            'message_preview' => $result['message_preview'],
            'message_text' => $result['message_text'],
            'copy_text' => $result['copy_text'],
            'whatsapp_url' => $result['whatsapp_url'],
            'dispatch' => [
                'id' => $result['dispatch']->id,
                'status' => $result['dispatch']->status,
                'channel' => $result['dispatch']->channel,
                'provider_key' => $result['dispatch']->provider_key,
            ],
            'duplicate_noop' => (bool) ($result['duplicate_noop'] ?? false),
            'request' => $this->workflowService->serialize($result['request'], true),
        ]);
    }

    private function paymentCreateFailureMessage(Throwable $exception): string
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'TERMINAL_PAYMENT_NOT_REUSABLE')) {
            return 'Önceki ödeme bağlantısı iptal edildi. Yeni bir ödeme bağlantısı oluşturmak için yeniden deneme nedeni girin.';
        }
        if (str_contains($message, 'PAYMENT_CREATE_IN_PROGRESS')) {
            return 'Ödeme bağlantısı oluşturuluyor. Lütfen kısa süre sonra tekrar deneyin.';
        }
        if (str_contains($message, 'PENDING_WITHOUT_SUCCESSFUL_SESSION_NOT_REUSABLE')) {
            return 'Mevcut ödeme bağlantısı güvenli biçimde kullanılamıyor. Ödeme geçmişini kontrol edip açıklamalı yeni bağlantı oluşturun.';
        }
        if (str_contains($message, 'PUBLIC_ORIGIN_')) {
            return 'Ödeme bağlantısı için public/LAN adresi hazır değil. Teknik Servis Admin > Entegrasyonlar ayarını kontrol edin.';
        }

        report($exception);

        return 'Ödeme bağlantısı güvenli biçimde oluşturulamadı. Ödeme sağlayıcısı ayarlarını kontrol edip tekrar deneyin.';
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

    public function createExtraMountFeePayment(
        Request $request,
        TechnicalServiceRequest $technicalServiceRequest,
        TechnicalServicePaymentProviderSettingsService $paymentProviderSettings,
        PaymentProviderManager $paymentProviderManager
    ): JsonResponse {
        $validated = $request->validate([
            'route_quote_id' => ['nullable', 'integer', 'exists:technical_service_route_quotes,id'],
            'technician_id' => ['nullable', 'integer', 'exists:technical_service_technicians,id'],
            'selected_serial_ids' => ['nullable', 'array'],
            'selected_serial_ids.*' => ['integer'],
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'service_amount' => ['nullable', 'numeric', 'min:0'],
            'part_amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'reason' => ['nullable', 'string', 'in:route_fee,montage_difference,multi_product,manual_extra,service_payment,part_payment,service_and_part_payment'],
            'purpose' => ['required', 'string', 'in:mount_extra,multi_product_mount,manual_mount_payment,service_payment,part_payment,service_and_part_payment,route_fee,montage_difference,multi_product,manual_extra'],
            'charge_type' => ['nullable', 'string', 'in:mount_extra,multi_product_mount,manual_mount_payment,service_payment,part_payment,service_and_part_payment,route_fee,montage_difference,multi_product,manual_extra'],
            'part_request_id' => ['nullable', 'integer', 'exists:technical_service_part_requests,id'],
            'note' => ['nullable', 'string', 'max:2000'],
            'message_template' => ['nullable', 'string', 'max:4000'],
            'terminal_retry_reason' => ['nullable', 'string', 'min:3', 'max:500'],
        ]);
        $this->assertStrictPaymentDecimalInputs($request, ['amount', 'service_amount', 'part_amount']);
        if (isset($validated['purpose'], $validated['charge_type'])
            && $this->canonicalExtraPaymentPurpose($validated['purpose']) !== $this->canonicalExtraPaymentPurpose($validated['charge_type'])) {
            throw ValidationException::withMessages([
                'charge_type' => 'purpose ve charge_type aynı ödeme yükümlülüğünü göstermelidir.',
            ]);
        }

        $session = $this->paymentMountSessionForRequest($technicalServiceRequest);
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
        if ($serialIds->unique()->count() !== $validSerialIds->unique()->count()) {
            throw ValidationException::withMessages([
                'selected_serial_ids' => 'Seçilen seri kimliklerinin tamamı mevcut teknik servis talebine ait olmalı.',
            ]);
        }
        $selectedSerialSnapshots = TechnicalServiceRequestSerial::query()
            ->where('technical_service_request_id', $technicalServiceRequest->id)
            ->whereIn('id', $validSerialIds)
            ->orderBy('id')
            ->get(['id', 'mrn', 'linked_mrn', 'serial_number', 'customer_phone'])
            ->map(fn (TechnicalServiceRequestSerial $serial): array => [
                'id' => $serial->id,
                'mrn' => $serial->mrn,
                'linked_mrn' => $serial->linked_mrn,
                'serial_number' => $serial->serial_number,
                'customer_phone' => $serial->customer_phone,
            ])
            ->values()
            ->all();
        $currency = strtoupper($validated['currency'] ?? 'TRY');
        $purpose = (string) $validated['purpose'];
        $chargeType = (string) ($validated['charge_type'] ?? $purpose);
        $isCustomerCharge = in_array($purpose, ['service_payment', 'part_payment', 'service_and_part_payment'], true);
        $partRequestId = isset($validated['part_request_id']) ? (int) $validated['part_request_id'] : null;
        if (in_array($purpose, ['part_payment', 'service_and_part_payment'], true)) {
            $partRequestOwned = $partRequestId !== null && TechnicalServicePartRequest::query()
                ->whereKey($partRequestId)
                ->where('technical_service_request_id', $technicalServiceRequest->id)
                ->exists();
            if (! $partRequestOwned) {
                throw ValidationException::withMessages([
                    'part_request_id' => 'Parça ödemesi mevcut teknik servis talebine ait part_request_id gerektirir.',
                ]);
            }
        }
        $technicianRequired = ! $isCustomerCharge && ! in_array($purpose, ['mount_extra', 'manual_mount_payment'], true);
        if ($technicianRequired && ! isset($validated['technician_id'])) {
            throw ValidationException::withMessages([
                'technician_id' => 'Ek montaj ödeme linki için usta seçimi zorunludur.',
            ]);
        }

        $serviceAmountMinor = isset($validated['service_amount'])
            ? $this->strictPaymentMinorUnits((string) $validated['service_amount'])
            : 0;
        $partAmountMinor = isset($validated['part_amount'])
            ? $this->strictPaymentMinorUnits((string) $validated['part_amount'])
            : 0;
        $totalAmountMinor = $isCustomerCharge
            ? $serviceAmountMinor + $partAmountMinor
            : (isset($validated['amount']) ? $this->strictPaymentMinorUnits((string) $validated['amount']) : 0);
        $serviceAmount = $this->minorUnitsToDecimal($serviceAmountMinor);
        $partAmount = $this->minorUnitsToDecimal($partAmountMinor);
        $totalAmount = $this->minorUnitsToDecimal($totalAmountMinor);

        if ($totalAmountMinor <= 0 || $totalAmountMinor > 999999999999) {
            throw ValidationException::withMessages([
                'amount' => $totalAmountMinor <= 0
                    ? 'Ödeme tutarı 0 TL üzerinde olmalı.'
                    : 'Ödeme tutarı desteklenen üst sınırı aşmamalıdır.',
            ]);
        }

        if ($paymentProviderManager->providerName() !== 'fake') {
            $paymentProviderSettings->assertCompanyRecipientAddressReady();
        }
        $companyRecipient = $paymentProviderSettings->companyRecipientForPayment();
        $customerServiceAddress = trim((string) ($technicalServiceRequest->location_formatted_address ?: $technicalServiceRequest->service_address));

        $source = $isCustomerCharge ? 'operation_customer_charge' : 'operation_extra_mount_fee';
        $selectedSerialIds = $validSerialIds->sort()->values()->all();
        $paymentPayload = [
            'source' => $source,
            'provider_environment' => $paymentProviderManager->environment(),
            'technical_service_request_id' => $technicalServiceRequest->id,
            'root_request_id' => $technicalServiceRequest->parent_request_id ?: $technicalServiceRequest->id,
            'mrn' => $technicalServiceRequest->mrn,
            'request_code' => $technicalServiceRequest->mrn,
            'root_mrn' => $technicalServiceRequest->root_mrn ?: $technicalServiceRequest->mrn,
            'service_code' => $technicalServiceRequest->service_code,
            'serial_number' => $technicalServiceRequest->serial_number,
            'customer_name' => $technicalServiceRequest->customer_name,
            'customer_phone' => $technicalServiceRequest->customer_phone,
            'payment_recipient' => $companyRecipient,
            'payment_recipient_address' => $companyRecipient['company_address'] ?? null,
            'payment_recipient_address_source' => 'technical_service_payment_provider_settings',
            'customer_address' => $customerServiceAddress !== '' ? $customerServiceAddress : null,
            'customer_service_address' => $customerServiceAddress !== '' ? $customerServiceAddress : null,
            'customer_address_role' => 'service_address',
            'customer_city' => $technicalServiceRequest->customer_city,
            'customer_district' => $technicalServiceRequest->customer_district,
            'route_quote_id' => $validated['route_quote_id'] ?? null,
            'technician_id' => isset($validated['technician_id']) ? (int) $validated['technician_id'] : null,
            'selected_serial_ids' => $selectedSerialIds,
            'selected_serials' => $selectedSerialSnapshots,
            'reason' => $validated['reason'] ?? $purpose,
            'purpose' => $purpose,
            'charge_type' => $chargeType,
            'part_request_id' => $partRequestId,
            'amount_source' => $isCustomerCharge ? 'manual_customer_charge' : 'manual_ops_amount',
            'service_amount' => $serviceAmount,
            'part_amount' => $partAmount,
            'total_amount' => $totalAmount,
            'message_template' => $validated['message_template'] ?? null,
            'note' => $validated['note'] ?? null,
        ];
        if (filled($validated['terminal_retry_reason'] ?? null)) {
            $paymentPayload['canonical_payment_terminal_retry'] = [
                'schema_version' => 1,
                'source' => 'ops_explicit_terminal_retry',
                'reason' => trim((string) $validated['terminal_retry_reason']),
                'requested_by_user_id' => $request->user()?->id,
                'requested_at' => now()->toIso8601String(),
            ];
        }

        $payment = TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'technical_service_request_id' => $technicalServiceRequest->id,
            'provider' => $paymentProviderManager->providerName(),
            'provider_reference' => null,
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'amount' => $totalAmount,
            'currency' => $currency,
            'raw_payload' => $paymentPayload,
        ]);
        $createOutcome = PaymentProviderManager::CREATE_OUTCOME_NEW_PENDING;
        try {
            $createResult = $paymentProviderManager->createPayment($payment);
            $createOutcome = $paymentProviderManager->createOutcome($createResult);
            $payment = $paymentProviderManager->canonicalPaymentFromCreateResult($createResult);
        } catch (Throwable $exception) {
            $paymentProviderManager->discardFailedCreatePaymentUnlessAudited($payment);

            throw ValidationException::withMessages([
                'payment' => $this->paymentCreateFailureMessage($exception),
            ]);
        }
        if ($createOutcome === PaymentProviderManager::CREATE_OUTCOME_ALREADY_PAID) {
            return response()->json([
                'ok' => true,
                'message' => 'Bu ödeme yükümlülüğü daha önce tamamlandı.',
                'payment' => $this->mountPaymentResponse($payment, [
                    'amount_source' => $paymentPayload['amount_source'],
                    'reused' => true,
                    'create_outcome' => $createOutcome,
                ]),
                'request' => $this->workflowService->serialize($technicalServiceRequest->refresh(), true),
            ]);
        }
        if ($createOutcome === PaymentProviderManager::CREATE_OUTCOME_REUSED_PENDING) {
            return response()->json([
                'ok' => true,
                'message' => 'Ödeme linki zaten var.',
                'payment' => $this->mountPaymentResponse($payment, [
                    'amount_source' => $paymentPayload['amount_source'],
                    'reused' => true,
                    'create_outcome' => $createOutcome,
                ]),
                'request' => $this->workflowService->serialize($technicalServiceRequest->refresh(), true),
            ]);
        }
        if ($payment->status !== TechnicalServiceMountPayment::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'payment' => 'Önceki ödeme bağlantısı iptal edildi. Yeni bir ödeme bağlantısı oluşturmak için yeniden deneme nedeni girin.',
            ]);
        }

        if (! $isCustomerCharge) {
            $technicalServiceRequest->forceFill([
                'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PENDING,
                'mount_payment_label' => 'Ek ödeme bekleniyor',
                'mount_payment_provider' => $payment->provider,
                'mount_payment_reference' => $payment->provider_reference,
            ])->save();
        }

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

        $technicalServiceRequest->events()->create([
            'event_type' => 'mount_payment_link_created',
            'title' => 'Ödeme linki oluşturuldu',
            'note' => $isCustomerCharge
                ? 'Müşteri servis/parça ödemesi için ödeme linki oluşturuldu.'
                : 'Ek montaj ödemesi için ödeme linki oluşturuldu.',
            'from_status' => $technicalServiceRequest->workflow_status,
            'to_status' => $technicalServiceRequest->workflow_status,
            'author_user_id' => $request->user()?->id,
            'metadata' => [
                'payment_id' => $payment->id,
                'provider' => $payment->provider,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'source' => $source,
                'selected_serial_ids' => $selectedSerialIds,
            ],
        ]);

        $requestPayload = $this->workflowService->serialize($technicalServiceRequest->refresh(), true);

        return response()->json([
            'ok' => true,
            'payment' => $this->mountPaymentResponse($payment, [
                'amount_source' => $paymentPayload['amount_source'],
                'reused' => $createOutcome === PaymentProviderManager::CREATE_OUTCOME_REUSED_PENDING,
                'create_outcome' => $createOutcome,
            ]),
            'request' => $requestPayload,
        ], 201);
    }

    public function mountPaymentStatus(
        Request $request,
        TechnicalServiceRequest $technicalServiceRequest,
        TechnicalServiceMountPayment $payment,
        PaymentProviderManager $paymentProviderManager,
        TechnicalServicePaymentProviderReconciliationService $reconciliationService
    ): JsonResponse {
        abort_unless((int) $payment->technical_service_request_id === (int) $technicalServiceRequest->id, 404);

        if ($request->boolean('sync_provider')) {
            try {
                $paymentProviderManager->syncPayment($payment);
            } catch (Throwable $exception) {
                $reconciliationService->recordSyncFailure($payment->refresh(), $exception, 'admin_sync');

                throw ValidationException::withMessages([
                    'payment' => $exception->getMessage(),
                ]);
            }

            $payment = $payment->refresh();
        }

        return response()->json([
            'ok' => true,
            'payment' => $this->mountPaymentResponse($payment->refresh()),
            'request' => $this->workflowService->serialize($technicalServiceRequest->refresh(), true),
        ]);
    }

    public function confirmManualPartPayment(
        Request $request,
        TechnicalServiceRequest $technicalServiceRequest,
        TechnicalServicePartRequest $partRequest,
        TechnicalServicePaymentSettlementService $paymentSettlementService,
    ): JsonResponse {
        abort_unless((int) $partRequest->technical_service_request_id === (int) $technicalServiceRequest->id, 404);

        $validated = $request->validate([
            'explanation' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $session = $this->paymentMountSessionForRequest($technicalServiceRequest);
        $payment = $paymentSettlementService->recordManualPartPayment(
            $technicalServiceRequest,
            $partRequest,
            $session,
            $actor,
            (string) $validated['explanation'],
        );

        return response()->json([
            'ok' => true,
            'message' => 'Manuel tahsilat kaydedildi.',
            'payment' => $this->mountPaymentResponse($payment->refresh()),
            'request' => $this->workflowService->serialize($technicalServiceRequest->refresh(), true),
        ]);
    }

    public function sendMountPaymentLink(
        Request $request,
        TechnicalServiceRequest $technicalServiceRequest,
        TechnicalServiceMountPayment $payment,
    ): JsonResponse {
        abort_unless((int) $payment->technical_service_request_id === (int) $technicalServiceRequest->id, 404);
        $validated = $request->validate([
            'payment_id' => ['required', 'integer', 'min:1'],
            'send_request_id' => ['required', 'uuid'],
            'resend_reason' => ['nullable', 'string', 'min:3', 'max:500'],
        ]);
        if ((int) $validated['payment_id'] !== (int) $payment->id) {
            throw ValidationException::withMessages([
                'payment_id' => 'Gönderilecek ödeme bağlantısı belirlenemedi. Lütfen aktif ödeme kaydını seçin.',
            ]);
        }

        $result = DB::transaction(function () use ($technicalServiceRequest, $payment, $validated, $request): array {
            $lockedPayment = TechnicalServiceMountPayment::query()
                ->whereKey($payment->id)
                ->where('technical_service_request_id', $technicalServiceRequest->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($lockedPayment->status !== TechnicalServiceMountPayment::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'payment' => $this->paymentLinkSendStatusBlocker($lockedPayment->status),
                ]);
            }

            $presentedPayment = TechnicalServicePaymentActionPresenter::forPayment($lockedPayment);
            $paymentUrl = is_string($presentedPayment['copy_url'] ?? null)
                ? trim($presentedPayment['copy_url'])
                : '';
            if ($paymentUrl === '') {
                throw ValidationException::withMessages([
                    'payment' => 'Ödeme linki güvenli public URL sözleşmesi doğrulanmadan müşteriye mesaj kuyruğu oluşturulamaz.',
                ]);
            }

            $amount = round((float) $lockedPayment->amount, 2);
            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Ödeme tutarı 0 TL üzerinde olmalı.',
                ]);
            }

            $rawPayload = is_array($lockedPayment->raw_payload) ? $lockedPayment->raw_payload : [];
            $history = is_array($rawPayload['message_send_history'] ?? null) ? $rawPayload['message_send_history'] : [];
            $sendRequestId = strtolower((string) $validated['send_request_id']);
            $existingRequest = collect($history)->first(
                fn (mixed $entry): bool => is_array($entry)
                    && hash_equals(strtolower((string) ($entry['send_request_id'] ?? '')), $sendRequestId),
            );
            if (is_array($existingRequest)) {
                return [
                    'summary' => [
                        'queued' => 0,
                        'blocked' => 0,
                        'suppressed' => 0,
                        'duplicate_blocked' => 0,
                        'idempotent_replay' => true,
                        'dispatches' => [],
                    ],
                    'payment' => $lockedPayment,
                    'idempotent_replay' => true,
                ];
            }

            $messageState = $this->workflowService->paymentLinkMessageState($lockedPayment);
            $sendCount = max((int) ($rawPayload['message_send_count'] ?? 0), $messageState['send_count']);
            $parentDispatch = $this->latestPaymentLinkDispatch($technicalServiceRequest, $lockedPayment);
            $isResend = $sendCount > 0 || $parentDispatch instanceof TechnicalServiceMessageDispatch;
            if ($isResend && ! filled($validated['resend_reason'] ?? null)) {
                throw ValidationException::withMessages([
                    'resend_reason' => 'Ödeme linkini yeniden göndermek için neden zorunludur.',
                ]);
            }

            $amountLabel = number_format($amount, 2, ',', '.').' TL';
            $purpose = (string) ($rawPayload['purpose'] ?? $rawPayload['charge_type'] ?? '');
            $purposeLabel = $this->paymentPurposeLabel($purpose);
            $partRequestId = $rawPayload['part_request_id'] ?? null;
            $isPartFeePayment = $partRequestId !== null || in_array($purpose, ['part_payment', 'service_and_part_payment'], true);
            $messageType = $isPartFeePayment ? 'part_fee_payment_link_customer' : 'payment_link_customer';
            $nextSendCount = $sendCount + 1;
            $summary = $this->workflowMessages->queueWorkflowDispatches(
                $technicalServiceRequest->refresh(),
                $messageType,
                'customer',
                [
                    'payment_link' => $paymentUrl,
                    'payment_link_sms' => $paymentUrl,
                    'payment_amount_formatted' => $amountLabel,
                    'customer_payment_amount' => $amount,
                    'customer_payment_amount_formatted' => $amountLabel,
                    'selected_payment_id' => $lockedPayment->id,
                    'selected_payment_status' => $lockedPayment->status,
                    'payment_purpose' => $purpose,
                    'payment_purpose_label' => $purposeLabel,
                ],
                $request->user(),
                null,
                [
                    'recipient_phone' => $technicalServiceRequest->customer_phone,
                    'triggered_by' => $isResend ? 'ops_payment_link_resend' : 'ops_payment_link_send',
                    'event_version' => 'payment-link:'.$lockedPayment->id.':send:'.$nextSendCount,
                    'requires_public_url' => $paymentUrl,
                    'parent_dispatch_id' => $parentDispatch?->id,
                    'force_resend' => $isResend,
                    'force_resend_reason' => $validated['resend_reason'] ?? null,
                    'metadata' => [
                        'payment_id' => $lockedPayment->id,
                        'send_request_id' => $sendRequestId,
                        'part_request_id' => $partRequestId,
                        'payment_status' => $lockedPayment->status,
                        'payment_purpose' => $purpose,
                        'payment_purpose_label' => $purposeLabel,
                        'payment_amount' => $amount,
                        'payment_provider' => $lockedPayment->provider,
                        'manual_ui_send' => true,
                        'resend' => $isResend,
                        'resend_reason' => $validated['resend_reason'] ?? null,
                        'parent_dispatch_id' => $parentDispatch?->id,
                        'message_send_count' => $nextSendCount,
                        'workflow_event' => $isPartFeePayment ? 'part_fee_payment_link' : 'payment_link',
                    ],
                ],
            );

            if (($summary['queued'] ?? 0) > 0) {
                $history[] = [
                    'send_request_id' => $sendRequestId,
                    'send_count' => $nextSendCount,
                    'requested_at' => now()->toISOString(),
                    'requested_by_user_id' => $request->user()?->id,
                    'resend_reason' => $validated['resend_reason'] ?? null,
                    'parent_dispatch_id' => $parentDispatch?->id,
                    'dispatch_ids' => collect($summary['dispatches'] ?? [])->pluck('id')->values()->all(),
                ];
                $rawPayload['message_send_count'] = $nextSendCount;
                $rawPayload['message_send_history'] = $history;
                $lockedPayment->forceFill(['raw_payload' => $rawPayload])->save();
            }

            $technicalServiceRequest->events()->create([
                'event_type' => $isResend ? 'mount_payment_link_resend_requested' : 'mount_payment_link_send_requested',
                'title' => ($summary['queued'] ?? 0) > 0
                    ? ($isResend ? 'Ödeme linki yeniden mesaj kuyruğuna alındı' : 'Ödeme linki mesaj kuyruğuna alındı')
                    : 'Ödeme linki mesaj kuyruğu isteği bloklandı',
                'note' => $validated['resend_reason'] ?? null,
                'from_status' => $technicalServiceRequest->workflow_status,
                'to_status' => $technicalServiceRequest->workflow_status,
                'author_user_id' => $request->user()?->id,
                'metadata' => [
                    'payment_id' => $lockedPayment->id,
                    'send_request_id' => $sendRequestId,
                    'payment_status' => $lockedPayment->status,
                    'payment_purpose' => $purpose,
                    'payment_purpose_label' => $purposeLabel,
                    'payment_amount' => $amount,
                    'message_send_count' => ($summary['queued'] ?? 0) > 0 ? $nextSendCount : $sendCount,
                    'parent_dispatch_id' => $parentDispatch?->id,
                    'resend_reason' => $validated['resend_reason'] ?? null,
                    'message_dispatches' => $summary,
                ],
            ]);

            return [
                'summary' => $summary,
                'payment' => $lockedPayment->refresh(),
                'idempotent_replay' => false,
            ];
        });
        $summary = $result['summary'];
        $payment = $result['payment'];
        $paymentPayload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $purposeLabel = $this->paymentPurposeLabel((string) ($paymentPayload['purpose'] ?? $paymentPayload['charge_type'] ?? ''));
        $amountLabel = number_format((float) $payment->amount, 2, ',', '.').' TL';
        $idempotentReplay = (bool) ($result['idempotent_replay'] ?? false);

        return response()->json([
            'ok' => true,
            'message' => ($summary['queued'] > 0 || $idempotentReplay)
                ? "{$amountLabel} tutarındaki {$purposeLabel} ödeme bağlantısı müşteriye gönderim kuyruğuna alındı."
                : 'Ödeme linki mesajı oluşturulamadı; kuyruk/log detayını kontrol edin.',
            'dispatches' => $summary,
            'idempotent_replay' => $idempotentReplay,
            'payment' => $this->mountPaymentResponse($payment->refresh()),
            'request' => $this->workflowService->serialize($technicalServiceRequest->refresh(), true),
        ]);
    }

    public function cancelMountPayment(
        Request $request,
        TechnicalServiceRequest $technicalServiceRequest,
        TechnicalServiceMountPayment $payment,
        PaymentProviderManager $paymentProviderManager
    ): JsonResponse {
        abort_unless((int) $payment->technical_service_request_id === (int) $technicalServiceRequest->id, 404);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($payment->status === TechnicalServiceMountPayment::STATUS_PAID) {
            throw ValidationException::withMessages([
                'payment' => 'Ödenmiş ödeme kaydı bu aşamada iptal edilemez.',
            ]);
        }

        if ($payment->status === TechnicalServiceMountPayment::STATUS_CANCELLED) {
            return response()->json([
                'ok' => true,
                'message' => 'Ödeme linki zaten iptal edilmiş.',
                'payment' => $this->mountPaymentResponse($payment->refresh()),
                'request' => $this->workflowService->serialize($technicalServiceRequest->refresh(), true),
            ]);
        }

        if ($payment->status !== TechnicalServiceMountPayment::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'payment' => 'Yalnızca bekleyen ödeme linkleri iptal edilebilir.',
            ]);
        }

        $cancellationAudit = [
            'cancelled_at' => now()->toISOString(),
            'cancelled_by_user_id' => $request->user()?->id,
            'cancelled_by_name' => $request->user()?->name,
            'cancellation_reason' => $validated['reason'] ?? 'OPS tarafından iptal edildi',
            'cancel_source' => 'ops_payment_modal',
        ];

        try {
            $canonicalResult = $paymentProviderManager->cancelPayment($payment, $cancellationAudit);
            $payment = $paymentProviderManager->canonicalPaymentFromMutationResult($canonicalResult);
        } catch (Throwable $exception) {
            throw ValidationException::withMessages([
                'payment' => $exception->getMessage(),
            ]);
        }
        if ($payment->status !== TechnicalServiceMountPayment::STATUS_CANCELLED) {
            throw ValidationException::withMessages([
                'payment' => 'PAYMENT_CANCEL_STATE_CONFLICT: Ödeme provider işlemi sırasında terminal duruma geçti; iptal uygulanmadı.',
            ]);
        }
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];

        $remainingMountPayments = TechnicalServiceMountPayment::query()
            ->where('technical_service_request_id', $technicalServiceRequest->id)
            ->whereIn('status', [
                TechnicalServiceMountPayment::STATUS_PENDING,
                TechnicalServiceMountPayment::STATUS_PAID,
            ])
            ->get()
            ->reject(function (TechnicalServiceMountPayment $candidate): bool {
                $candidatePayload = is_array($candidate->raw_payload) ? $candidate->raw_payload : [];

                return ($candidatePayload['source'] ?? null) === 'operation_customer_charge';
            });
        $hasPendingMountPayment = $remainingMountPayments
            ->contains(fn (TechnicalServiceMountPayment $candidate): bool => $candidate->status === TechnicalServiceMountPayment::STATUS_PENDING);
        $hasPaidMountPayment = $remainingMountPayments
            ->contains(fn (TechnicalServiceMountPayment $candidate): bool => $candidate->status === TechnicalServiceMountPayment::STATUS_PAID);

        if (! $hasPendingMountPayment) {
            $technicalServiceRequest->forceFill([
                'mount_payment_status' => $hasPaidMountPayment
                    ? TechnicalServiceMountSession::PAYMENT_PAID
                    : TechnicalServiceMountSession::PAYMENT_CANCELLED,
                'mount_payment_label' => $hasPaidMountPayment
                    ? 'Ödeme alındı'
                    : 'Ödeme linki iptal edildi',
            ])->save();
        }

        $technicalServiceRequest->events()->create([
            'event_type' => 'mount_payment_link_cancelled',
            'title' => 'Ödeme linki iptal edildi',
            'note' => $payload['cancellation_reason'],
            'from_status' => $technicalServiceRequest->workflow_status,
            'to_status' => $technicalServiceRequest->workflow_status,
            'author_user_id' => $request->user()?->id,
            'metadata' => [
                'payment_id' => $payment->id,
                'provider' => $payment->provider,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'source' => $payload['source'] ?? null,
            ],
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Bekleyen ödeme linki iptal edildi.',
            'payment' => $this->mountPaymentResponse($payment->refresh()),
            'request' => $this->workflowService->serialize($technicalServiceRequest->refresh(), true),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function latestPaymentLinkDispatch(
        TechnicalServiceRequest $technicalServiceRequest,
        TechnicalServiceMountPayment $payment,
    ): ?TechnicalServiceMessageDispatch {
        $messageState = $this->workflowService->paymentLinkMessageState($payment);
        $dispatchId = $messageState['latest_dispatch_id'];
        if ($dispatchId === null) {
            return null;
        }

        $dispatch = TechnicalServiceMessageDispatch::query()
            ->whereKey($dispatchId)
            ->where('technical_service_request_id', $technicalServiceRequest->id)
            ->first();
        if (! $dispatch instanceof TechnicalServiceMessageDispatch) {
            return null;
        }

        $metadata = is_array($dispatch->metadata) ? $dispatch->metadata : [];

        return (int) ($metadata['payment_id'] ?? 0) === (int) $payment->id ? $dispatch : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function mountPaymentResponse(TechnicalServiceMountPayment $payment, array $overrides = []): array
    {
        $payment->loadMissing('technicalServiceRequest');
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $providerDecision = is_array($payload['provider_decision'] ?? null) ? $payload['provider_decision'] : [];
        $providerGateway = is_array($payload['provider_gateway'] ?? null) ? $payload['provider_gateway'] : [];
        $providerGatewaySync = is_array($payload['provider_gateway_sync'] ?? null) ? $payload['provider_gateway_sync'] : [];
        $paymentUrl = trim((string) ($payment->payment_url ?? ''));
        $paymentUrlPath = is_string(parse_url($paymentUrl, PHP_URL_PATH))
            ? trim((string) parse_url($paymentUrl, PHP_URL_PATH), '/')
            : '';
        $linkToken = $paymentUrlPath !== '' ? basename($paymentUrlPath) : null;
        $providerMode = $payload['provider_mode']
            ?? $providerDecision['provider_mode']
            ?? ($payment->provider === 'fake' ? 'local' : ($payload['provider_environment'] ?? null));
        $providerTransport = $payload['provider_transport']
            ?? $providerDecision['provider_transport']
            ?? ($payment->provider === 'fake' ? 'fake_local' : null);
        $providerStatus = $providerGatewaySync['provider_status']
            ?? $providerGateway['provider_status']
            ?? $providerGatewaySync['raw_status']
            ?? $providerGateway['raw_status']
            ?? $payment->status;
        $messageState = $this->workflowService->paymentLinkMessageState($payment);
        $messagingGlobal = (array) data_get($this->messagingSettings->workflowDispatchSnapshot(), 'global', []);
        $testMode = (bool) ($messagingGlobal['test_mode_enabled'] ?? false);
        $messageTargetMasked = $testMode
            ? ($messagingGlobal['customer_test_phone_masked'] ?? null)
            : $this->messageIdempotency->maskPhone($payload['customer_phone'] ?? $payment->technicalServiceRequest?->customer_phone);
        $purpose = (string) ($payload['purpose'] ?? $payload['charge_type'] ?? '');

        return array_merge([
            'id' => $payment->id,
            'request_id' => $payment->technical_service_request_id,
            'request_code' => $payload['request_code'] ?? $payload['mrn'] ?? $payment->technicalServiceRequest?->mrn,
            'root_mrn' => $payload['root_mrn'] ?? $payment->technicalServiceRequest?->root_mrn,
            'serial_no' => $payload['serial_number'] ?? $payment->technicalServiceRequest?->serial_number,
            'serial_number' => $payload['serial_number'] ?? $payment->technicalServiceRequest?->serial_number,
            'customer_name' => TechnicalServiceUiLabelService::cleanDisplayText($payload['customer_name'] ?? $payment->technicalServiceRequest?->customer_name),
            'customer_phone' => $payload['customer_phone'] ?? $payment->technicalServiceRequest?->customer_phone,
            'customer_email' => $payload['customer_email'] ?? null,
            'status' => $payment->status,
            'status_label' => $this->paymentStatusLabel($payment->status),
            'amount' => (float) $payment->amount,
            'amount_label' => number_format((float) $payment->amount, 2, ',', '.').' '.($payment->currency === 'TRY' ? 'TL' : $payment->currency),
            'currency' => $payment->currency,
            'payment_url' => $paymentUrl !== '' ? $paymentUrl : null,
            'copy_url' => $paymentUrl !== '' ? $paymentUrl : null,
            'link_token' => $linkToken,
            'message_target_phone_masked' => $messageTargetMasked,
            'message_target_mode' => $testMode ? 'test' : 'actual',
            'message_channels' => ['whatsapp', 'sms'],
            'provider' => $payment->provider,
            'provider_mode' => $providerMode,
            'provider_transport' => $providerTransport,
            'provider_token' => $payment->provider_reference,
            'provider_reference' => $payment->provider_reference,
            'provider_status' => $providerStatus,
            'provider_last_synced_at' => $payment->provider_last_synced_at?->toISOString(),
            'provider_sync_attempts' => (int) ($payment->provider_sync_attempts ?? 0),
            'provider_last_sync_status' => $payment->provider_last_sync_status,
            'provider_last_sync_error' => $payment->provider_last_sync_error,
            'provider_sync_locked_at' => $payment->provider_sync_locked_at?->toISOString(),
            'provider_paid_confirmed_at' => $payment->provider_paid_confirmed_at?->toISOString(),
            'amount_source' => $payload['amount_source'] ?? null,
            'source' => $payload['source'] ?? null,
            'purpose' => $purpose !== '' ? $purpose : null,
            'purpose_label' => $this->paymentPurposeLabel($purpose),
            'reason' => $payload['reason'] ?? null,
            'note' => TechnicalServiceUiLabelService::cleanDisplayText($payload['note'] ?? null),
            'paid_at' => $payment->paid_at?->toISOString(),
            'cancelled_at' => $payload['cancelled_at'] ?? null,
            'cancelled_by_name' => TechnicalServiceUiLabelService::cleanDisplayText($payload['cancelled_by_name'] ?? null),
            'cancellation_reason' => $payload['cancellation_reason'] ?? null,
            'message_send_count' => max((int) ($payload['message_send_count'] ?? 0), $messageState['send_count']),
            'last_message_sent_at' => data_get($payload, 'message_send_history.'.(max(0, count((array) ($payload['message_send_history'] ?? [])) - 1).'.requested_at'))
                ?? $messageState['last_message_sent_at'],
            'created_at' => $payment->created_at?->toISOString(),
            'updated_at' => $payment->updated_at?->toISOString(),
        ], TechnicalServicePaymentActionPresenter::forPayment($payment), $overrides);
    }

    private function paymentLinkSendStatusBlocker(string $status): string
    {
        return match ($status) {
            TechnicalServiceMountPayment::STATUS_PAID => 'Bu ödeme zaten tahsil edildi; bağlantı yeniden gönderilemez.',
            TechnicalServiceMountPayment::STATUS_CANCELLED => 'Bu ödeme bağlantısı iptal edildi; yeniden gönderilemez.',
            TechnicalServiceMountPayment::STATUS_EXPIRED => 'Bu ödeme bağlantısının süresi doldu; yeniden gönderilemez.',
            default => 'Seçilen ödeme bağlantısı aktif bekleyen durumda değil; gönderilemez.',
        };
    }

    private function paymentPurposeLabel(string $purpose): string
    {
        return match ($purpose) {
            'service_payment' => 'Ek servis',
            'part_payment' => 'Parça ücreti',
            'service_and_part_payment' => 'Servis + parça ücreti',
            'route_fee' => 'Yol ücreti',
            'multi_product', 'multi_product_mount' => 'Çoklu ürün montaj ödemesi',
            'manual_mount_payment', 'mount_extra', 'manual_extra' => 'Genel ek tahsilat',
            default => 'Ek ödeme',
        };
    }

    private function paymentStatusLabel(string $status): string
    {
        return match ($status) {
            TechnicalServiceMountPayment::STATUS_PENDING => 'Bekliyor',
            TechnicalServiceMountPayment::STATUS_PAID => 'Ödendi',
            TechnicalServiceMountPayment::STATUS_CANCELLED => 'İptal edildi',
            TechnicalServiceMountPayment::STATUS_EXPIRED => 'Süresi doldu',
            TechnicalServiceMountPayment::STATUS_FAILED => 'Başarısız',
            default => 'Bilinmiyor',
        };
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

    public function reviewFieldDocument(
        Request $request,
        TechnicalServiceRequest $technicalServiceRequest,
        mixed $upload,
    ): JsonResponse {
        if (! $upload instanceof TechnicalServiceRequestUpload) {
            $upload = TechnicalServiceRequestUpload::query()->findOrFail($upload);
        }

        abort_unless((int) $upload->technical_service_request_id === (int) $technicalServiceRequest->id, 404);
        abort_unless($this->isReviewableFieldCompletionDocument($upload), 404);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:accepted,rejected'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validated['status'] === 'rejected' && trim((string) ($validated['note'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'note' => 'Uygun değil işaretlemek için açıklama zorunludur.',
            ]);
        }

        $upload->forceFill([
            'review_status' => $validated['status'],
            'review_note' => $validated['note'] ?? null,
            'reviewed_by' => $request->user()?->id,
            'reviewed_at' => now(),
            'review_payload' => ['source' => 'technical_service_ops'],
        ])->save();

        $technicalServiceRequest->events()->create([
            'event_type' => 'field_document_reviewed',
            'title' => $validated['status'] === 'accepted' ? 'Saha belgesi uygun işaretlendi' : 'Saha belgesi uygun değil işaretlendi',
            'note' => $validated['note'] ?? null,
            'from_status' => $technicalServiceRequest->workflow_status,
            'to_status' => $technicalServiceRequest->workflow_status,
            'author_user_id' => $request->user()?->id,
            'metadata' => [
                'upload_id' => $upload->id,
                'field_code' => $upload->field_code,
                'review_status' => $validated['status'],
            ],
        ]);

        return response()->json([
            'status' => 'ok',
            'request' => $this->workflowService->serialize($technicalServiceRequest->refresh(), true),
        ]);
    }

    public function uploadOpsExtraDocuments(Request $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        $validated = $request->validate([
            'ops_extra_documents' => ['required', 'array', 'min:1', 'max:6'],
            'ops_extra_documents.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif,heic,heif', 'max:10240'],
            'document_type' => ['nullable', 'string', Rule::in([
                'ops_extra_photo',
                'ops_door_front_photo',
                'ops_door_side_photo',
                'ops_door_back_photo',
                'ops_door_photo',
                'ops_additional_document',
            ])],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'ops_extra_documents.required' => 'Yüklenecek OPS görseli seçilmelidir.',
            'ops_extra_documents.array' => 'OPS görselleri dosya listesi olarak gönderilmelidir.',
            'ops_extra_documents.min' => 'En az bir OPS görseli seçilmelidir.',
            'ops_extra_documents.max' => 'Tek seferde en fazla 6 OPS görseli yüklenebilir.',
            'ops_extra_documents.*.required' => 'OPS görsel dosyası eksik.',
            'ops_extra_documents.*.file' => 'OPS görseli geçerli bir dosya olmalıdır.',
            'ops_extra_documents.*.mimes' => 'OPS görselleri jpg, jpeg, png, webp, gif, heic veya heif formatında olmalıdır.',
            'ops_extra_documents.*.max' => 'OPS görseli en fazla 10 MB olabilir.',
            'document_type.in' => 'OPS görsel türü geçersiz.',
            'note.max' => 'OPS görsel notu en fazla 1000 karakter olabilir.',
        ], [
            'ops_extra_documents' => 'OPS görselleri',
            'ops_extra_documents.*' => 'OPS görseli',
            'document_type' => 'OPS görsel türü',
            'note' => 'OPS görsel notu',
        ]);

        $files = $request->file('ops_extra_documents', []);
        $note = trim((string) ($validated['note'] ?? ''));
        $fieldCode = (string) ($validated['document_type'] ?? 'ops_extra_photo');
        $title = match ($fieldCode) {
            'ops_door_front_photo' => 'OPS kapı ön yüz görseli yüklendi',
            'ops_door_side_photo' => 'OPS kapı yan yüz görseli yüklendi',
            'ops_door_back_photo' => 'OPS kapı arka yüz görseli yüklendi',
            'ops_door_photo' => 'OPS ek kapı görseli yüklendi',
            'ops_additional_document' => 'OPS ek belge yüklendi',
            default => 'OPS ek görsel yüklendi',
        };

        $uploads = DB::transaction(function () use ($technicalServiceRequest, $request, $files, $note, $fieldCode, $title) {
            $created = [];

            foreach ((array) $files as $file) {
                $extension = $file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'jpg';
                $path = $file->storeAs(
                    'technical-service/requests/'.$technicalServiceRequest->id.'/ops-extra-documents',
                    (string) Str::uuid().'.'.$extension,
                    'public',
                );

                $created[] = TechnicalServiceRequestUpload::query()->create([
                    'technical_service_request_id' => $technicalServiceRequest->id,
                    'field_code' => $fieldCode,
                    'category' => TechnicalServiceRequestUpload::CATEGORY_OPS_EXTRA_DOCUMENT,
                    'original_name' => $file->getClientOriginalName() ?: 'OPS ek görsel',
                    'path' => $path,
                    'mime' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'review_status' => 'accepted',
                    'reviewed_by' => $request->user()?->id,
                    'reviewed_at' => now(),
                    'review_payload' => [
                        'source' => 'technical_service_ops',
                        'note' => $note !== '' ? $note : null,
                        'uploaded_by_user_id' => $request->user()?->id,
                    ],
                ]);
            }

            $technicalServiceRequest->events()->create([
                'event_type' => 'ops_extra_document_uploaded',
                'title' => $title,
                'note' => $note !== '' ? $note : null,
                'from_status' => $technicalServiceRequest->workflow_status,
                'to_status' => $technicalServiceRequest->workflow_status,
                'author_user_id' => $request->user()?->id,
                'metadata' => [
                    'upload_ids' => array_map(fn (TechnicalServiceRequestUpload $upload): int => (int) $upload->id, $created),
                    'field_code' => $fieldCode,
                    'category' => TechnicalServiceRequestUpload::CATEGORY_OPS_EXTRA_DOCUMENT,
                ],
            ]);

            return $created;
        });

        return response()->json([
            'status' => 'ok',
            'uploads' => array_map(fn (TechnicalServiceRequestUpload $upload): array => [
                'id' => $upload->id,
                'field_code' => $upload->field_code,
                'category' => $upload->category,
                'original_name' => $upload->original_name,
            ], $uploads),
            'request' => $this->workflowService->serialize($technicalServiceRequest->refresh(), true),
        ]);
    }

    private function isReviewableFieldCompletionDocument(TechnicalServiceRequestUpload $upload): bool
    {
        if (! in_array($upload->category, [
            TechnicalServiceRequestUpload::CATEGORY_PARTNER_PORTAL_FIELD_DOCUMENT,
            TechnicalServiceRequestUpload::CATEGORY_OPERATION_CONTROL_DOOR_PHOTO,
        ], true)) {
            return false;
        }

        return in_array((string) $upload->field_code, [
            'before_photo',
            'after_photo',
            'warranty_document_photo',
        ], true);
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
        if ($this->isTerminalOperationRequest($technicalServiceRequest)) {
            throw ValidationException::withMessages([
                'request' => 'Tamamlanmış veya iptal edilmiş iş yeniden servise atanamaz.',
            ]);
        }

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

        if ($routeQuote instanceof TechnicalServiceRouteQuote) {
            $routeQuotePayload = app(TechnicalServiceRouteCostService::class)->payload($routeQuote);
            if (($routeQuotePayload['ok'] ?? false) !== true || ! is_numeric($routeQuotePayload['fee_amount'] ?? null)) {
                throw ValidationException::withMessages([
                    'route_quote_id' => (string) ($routeQuotePayload['message'] ?? 'Seçili yol hakedişi hesabı tamamlanmadı. Manuel yol hakedişi kaydedin.'),
                ]);
            }
        } elseif ($technician instanceof TechnicalServiceTechnician) {
            $latestRouteAttempt = TechnicalServiceRouteQuote::query()
                ->where('technical_service_request_id', $technicalServiceRequest->id)
                ->where('technician_id', $technician->id)
                ->latest('id')
                ->first();
            if ($latestRouteAttempt instanceof TechnicalServiceRouteQuote
                && $latestRouteAttempt->status !== TechnicalServiceRouteQuote::STATUS_CALCULATED
                && (float) ($payload['travel_round_trip_km'] ?? 0) <= 0.0) {
                throw ValidationException::withMessages([
                    'route_quote_id' => 'Otomatik rota hesaplanamadı. Atamadan önce nedenini kontrol edip manuel yol hakedişi kaydedin.',
                ]);
            }
        }

        $assignmentOfferPayload = is_array($payload['assignment_offer'] ?? null) ? $payload['assignment_offer'] : [];

        if (array_key_exists('labor_amount', $payload)) {
            $assignmentOfferPayload['labor_amount'] = $payload['labor_amount'];
        }

        if (array_key_exists('travel_amount', $payload)) {
            $assignmentOfferPayload['route_fee_amount'] = $payload['travel_amount'];
        }

        if (array_key_exists('customer_direct_to_technician_amount', $payload)) {
            $assignmentOfferPayload['customer_direct_to_technician_amount'] = $payload['customer_direct_to_technician_amount'];
        }

        if (array_key_exists('earning_note', $payload)) {
            $assignmentOfferPayload['note'] = $payload['earning_note'];
        }

        if (array_key_exists('confirm_assignment', $payload)) {
            $assignmentOfferPayload['confirmed_by_ops'] = (bool) $payload['confirm_assignment'];
        }

        if ($technician instanceof TechnicalServiceTechnician) {
            $assignmentOfferPayload['route_fee_amount'] = $this->canonicalAssignmentRouteFeeAmount(
                $technicalServiceRequest,
                $routeQuote,
                $assignmentOfferPayload,
                $payload,
            );
        }

        $isServiceVisit = $technicalServiceRequest->parent_request_id !== null
            || filled($technicalServiceRequest->service_code)
            || mb_strtolower(trim((string) $technicalServiceRequest->service_type)) === 'servis';
        $submittedLaborAmount = $this->nullableMoney($assignmentOfferPayload['labor_amount'] ?? null);
        if ($isServiceVisit && ($submittedLaborAmount === null || $submittedLaborAmount <= 0)) {
            throw ValidationException::withMessages([
                'assignment_offer.labor_amount' => 'SRV ataması için işçilik hakedişi 0 TL üzerinde açıkça girilmelidir.',
            ]);
        }

        $mountExclusionNote = trim((string) ($payload['mount_exclusion_note'] ?? ''));
        $mountExclusionAcknowledged = (bool) ($payload['mount_exclusion_acknowledged'] ?? false);
        if ($mountExclusionAcknowledged || $mountExclusionNote !== '') {
            $errors = [];

            if (! $mountExclusionAcknowledged) {
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

        $sourceWorkflowStatus = $this->workflowService->currentWorkflowStatus($technicalServiceRequest);
        $isReviewReassignment = $this->workflowService->canReopenForAssignment($technicalServiceRequest);
        $oldTechnicianId = $technicalServiceRequest->technical_service_technician_id;
        $oldPartnerId = $this->partnerJobScope->activeAssignmentLink($technicalServiceRequest)?->partner_id;
        $assignmentLink = $this->assignmentLinkForTechnician($technician, $payload['b2b_partner_id'] ?? null);

        $technicianPayload = [
            'technical_service_technician_id' => $technician?->id,
            'technician_name' => $technician?->name ?? ($payload['technician_name'] ?? null),
            'technician_approval_status' => 'bekliyor',
            'route_quote_id' => $payload['route_quote_id'] ?? null,
            'note' => $payload['note'] ?? null,
            'reassign_after_review' => $isReviewReassignment,
        ];

        $technicalServiceRequest = $this->workflowService->updateTechnician(
            $technicalServiceRequest,
            $technicianPayload,
            $request->user()
        );

        $technicalServiceRequest->events()->create([
            'event_type' => 'assignment_created',
            'title' => 'Usta atandı',
            'note' => $payload['note'] ?? null,
            'from_status' => $sourceWorkflowStatus,
            'to_status' => $technicalServiceRequest->workflow_status,
            'author_user_id' => $request->user()?->id,
            'metadata' => [
                'technician_id' => $technician?->id,
                'technician_name' => $technician?->name ?? ($payload['technician_name'] ?? null),
                'assignment_partner_id' => $assignmentLink?->partner_id,
                'assignment_partner_technician_link_id' => $assignmentLink?->id,
                'source' => 'technical_service_assign',
            ],
        ]);

        $newPartnerId = $assignmentLink?->partner_id;

        if ((int) ($oldTechnicianId ?? 0) !== (int) ($technician?->id ?? 0)
            || (int) ($oldPartnerId ?? 0) !== (int) ($newPartnerId ?? 0)
            || $isReviewReassignment) {
            TechnicalServiceAssignmentArchive::query()->create([
                'technical_service_request_id' => $technicalServiceRequest->id,
                'old_technician_id' => $oldTechnicianId,
                'new_technician_id' => $technician?->id,
                'old_partner_id' => $oldPartnerId,
                'new_partner_id' => $newPartnerId,
                'reason' => $payload['note'] ?? ($isReviewReassignment ? 'reassign_after_review' : 'reassignment'),
                'archived_by' => $request->user()?->id,
                'archived_at' => now(),
                'metadata' => [
                    'source' => 'technical_service_assign',
                    'reassign_after_review' => $isReviewReassignment,
                    'source_workflow_status' => $sourceWorkflowStatus,
                    'target_workflow_status' => $technicalServiceRequest->workflow_status,
                ],
            ]);

            $this->resolveReviewReassignmentState(
                $technicalServiceRequest,
                $payload,
                $request->user(),
                $technician,
                $sourceWorkflowStatus,
                $isReviewReassignment,
            );

            $technicalServiceRequest->events()->create([
                'event_type' => 'assignment_archived',
                'title' => 'Önceki usta ataması arşivlendi',
                'note' => $payload['note'] ?? null,
                'from_status' => $technicalServiceRequest->workflow_status,
                'to_status' => $technicalServiceRequest->workflow_status,
                'author_user_id' => $request->user()?->id,
                'metadata' => [
                    'old_technician_id' => $oldTechnicianId,
                    'new_technician_id' => $technician?->id,
                    'old_partner_id' => $oldPartnerId,
                    'new_partner_id' => $newPartnerId,
                ],
            ]);
        }

        if (! $routeQuote instanceof TechnicalServiceRouteQuote && isset($payload['travel_round_trip_km'])) {
            $technicalServiceRequest->fill($this->calculateTravelCosts((float) $payload['travel_round_trip_km']));
            $technicalServiceRequest->save();
        }

        if ($technician instanceof TechnicalServiceTechnician) {
            $this->createAssignmentOfferFromAssignPayload(
                $technicalServiceRequest->refresh(),
                $technician,
                $routeQuote,
                $assignmentOfferPayload,
                $request->user(),
                $assignmentLink,
            );
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
            ->filter(fn (TechnicalServiceRequest $request) => ($request->scheduled_at?->isToday() ?? false)
                && ! $this->isTerminalOperationRequest($request))
            ->values();
        $overdue = $requests
            ->filter(fn (TechnicalServiceRequest $request) => $this->isOverdueRequest($request))
            ->values();
        $warrantyStarted = $requests
            ->filter(fn (TechnicalServiceRequest $request) => $request->service_type === 'Montaj'
                && $request->installation_completed_at !== null
                && ($request->completed_at !== null
                    || $this->isCompletedStatusValue($request->status)
                    || $this->isCompletedStatusValue($request->workflow_status)))
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
                ->groupBy(fn (TechnicalServiceRequest $request) => trim((string) $request->technician_name) !== '' ? TechnicalServiceUiLabelService::displayName($request->technician_name) : 'Atanmadı')
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
                ->groupBy(fn (TechnicalServiceRequest $request) => trim((string) $request->customer_city) !== '' ? TechnicalServiceUiLabelService::cityLabel($request->customer_city) : 'Belirtilmedi')
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
     * @param  array<string, mixed>  $filters
     */
    private function operationsDashboardQuery(array $filters)
    {
        return TechnicalServiceRequest::query()
            ->whereDoesntHave('childRequests', fn ($query) => $this->nonCancelledChildServiceVisitQuery($query))
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
        $displayCity = TechnicalServiceUiLabelService::cityLabel($request->customer_city);
        $displayDistrict = TechnicalServiceUiLabelService::districtLabel($request->customer_district, $displayCity);
        $operationalState = app(TechnicalServiceOperationalStatePresenter::class)->present($request);
        $cancelContext = app(TechnicalServiceCancelContextService::class)->present($request, $operationalState);

        return [
            'id' => $request->id,
            'mrn' => $request->mrn,
            'customer_name' => TechnicalServiceUiLabelService::cleanDisplayText($request->customer_name),
            'customer_phone' => $request->customer_phone,
            'customer_city' => $displayCity,
            'customer_district' => $displayDistrict,
            'service_address' => TechnicalServiceUiLabelService::addressLabel($request->service_address),
            'product_name' => TechnicalServiceUiLabelService::cleanDisplayText($request->product_name),
            'product_model' => TechnicalServiceUiLabelService::cleanDisplayText($request->product_model),
            'serial_number' => $request->serial_number,
            'service_type' => $request->service_type,
            'technician_name' => TechnicalServiceUiLabelService::displayName($request->technician_name),
            'scheduled_at' => $request->scheduled_at?->toISOString(),
            'scheduled_time' => $request->scheduled_time,
            'status' => TechnicalServiceUiLabelService::cleanDisplayText($request->status),
            'workflow_status' => TechnicalServiceUiLabelService::cleanDisplayText($request->workflow_status),
            'next_action' => TechnicalServiceUiLabelService::cleanDisplayText($request->next_action),
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
            'operational_state' => $operationalState,
            'kanban_column' => $operationalState['ops_column'],
            'display_action_label' => $operationalState['display_action_label'],
            'display_tags' => $operationalState['display_tags'],
            'attention' => $operationalState['attention'],
            'cancel_context' => $cancelContext,
            'current_stage_summary' => app(TechnicalServiceCancelContextService::class)->currentStageSummary($request, $operationalState),
            'action_owner' => $operationalState['dashboard_action_owner'] ?? $operationalState['action_owner'],
            'action_owner_label' => $operationalState['action_owner_label'] ?? null,
            'action_priority' => $operationalState['action_priority_score'] ?? $operationalState['sort_priority'] ?? null,
            'action_bucket' => $operationalState['action_bucket'] ?? null,
            'card_tone' => $operationalState['card_tone'] ?? null,
            'action_title' => $operationalState['action_title'] ?? null,
            'action_reason' => $operationalState['action_reason'] ?? null,
            'action_filter_keys' => $operationalState['action_filter_keys'] ?? [],
        ];
    }

    private function isOverdueRequest(TechnicalServiceRequest $request): bool
    {
        return $request->scheduled_at !== null
            && $request->scheduled_at->isPast()
            && ! $this->isTerminalOperationRequest($request);
    }

    private function isPastScheduledNotCompleted(TechnicalServiceRequest $request): bool
    {
        return $request->scheduled_at !== null
            && $request->scheduled_at->isPast()
            && $request->installation_completed_at === null
            && ! $this->isTerminalOperationRequest($request);
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
        if ($this->fieldCompletionDocumentsComplete($request)) {
            return true;
        }

        return (int) ($request->before_photo_count ?? 0) >= 3
            && (int) ($request->after_photo_count ?? 0) >= 3
            && (int) ($request->general_photo_count ?? 0) >= 1;
    }

    private function fieldCompletionDocumentsComplete(TechnicalServiceRequest $request): bool
    {
        $request->loadMissing('uploads');

        $required = [
            'before_photo',
            'after_photo',
            'warranty_document_photo',
        ];

        $presentTypes = $request->uploads
            ->filter(fn (TechnicalServiceRequestUpload $upload): bool => $upload->category === TechnicalServiceRequestUpload::CATEGORY_PARTNER_PORTAL_FIELD_DOCUMENT
                || (
                    $upload->category === TechnicalServiceRequestUpload::CATEGORY_OPERATION_CONTROL_DOOR_PHOTO
                    && in_array((string) $upload->field_code, $required, true)
                ))
            ->map(fn (TechnicalServiceRequestUpload $upload): string => (string) $upload->field_code)
            ->unique();

        return collect($required)->every(fn (string $field): bool => $presentTypes->contains($field));
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
     * @param  array<string, mixed>  $offerPayload
     * @param  array<string, mixed>  $requestPayload
     */
    private function canonicalAssignmentRouteFeeAmount(
        TechnicalServiceRequest $request,
        ?TechnicalServiceRouteQuote $routeQuote,
        array $offerPayload,
        array $requestPayload,
    ): float {
        $routeFeeAmount = $routeQuote instanceof TechnicalServiceRouteQuote
            ? $this->nullableMoney($routeQuote->fee_amount)
            : null;

        if ($routeFeeAmount === null && array_key_exists('route_fee_amount', $offerPayload)) {
            $routeFeeAmount = $this->nullableMoney($offerPayload['route_fee_amount']);
        }
        if ($routeFeeAmount === null && $request->travel_fee_amount !== null) {
            $routeFeeAmount = $this->nullableMoney($request->travel_fee_amount);
        }
        if ($routeFeeAmount === null && array_key_exists('travel_round_trip_km', $requestPayload)) {
            $calculated = $this->calculateTravelCosts((float) $requestPayload['travel_round_trip_km']);
            $routeFeeAmount = $this->nullableMoney($calculated['travel_fee_amount'] ?? null);
        }

        if ($routeFeeAmount === null) {
            throw ValidationException::withMessages([
                'route_quote_id' => 'Usta yol hakedişi hesaplanmadan atama tamamlanamaz.',
            ]);
        }

        return round($routeFeeAmount, 2);
    }

    /**
     * @param  array<string, mixed>  $offerPayload
     */
    private function createAssignmentOfferFromAssignPayload(
        TechnicalServiceRequest $request,
        TechnicalServiceTechnician $technician,
        ?TechnicalServiceRouteQuote $routeQuote,
        array $offerPayload,
        mixed $user,
        ?B2BPartnerTechnician $assignmentLink,
    ): TechnicalServiceAssignmentOffer {
        $submittedLaborAmount = $this->nullableMoney($offerPayload['labor_amount'] ?? null);
        $laborAmount = $submittedLaborAmount ?? $this->nullableMoney($request->technician_payment_amount);
        $isServiceVisit = $request->parent_request_id !== null
            || filled($request->service_code)
            || mb_strtolower(trim((string) $request->service_type)) === 'servis';
        if ($isServiceVisit && ($submittedLaborAmount === null || $submittedLaborAmount <= 0)) {
            throw ValidationException::withMessages([
                'assignment_offer.labor_amount' => 'SRV ataması için işçilik hakedişi 0 TL üzerinde açıkça girilmelidir.',
            ]);
        }
        $laborAmount ??= 0.0;
        $routeFeeAmount = $this->nullableMoney($offerPayload['route_fee_amount'] ?? null);
        if ($routeFeeAmount === null) {
            throw ValidationException::withMessages([
                'route_quote_id' => 'Usta yol hakedişi hesaplanmadan atama tamamlanamaz.',
            ]);
        }
        $totalAmount = round($laborAmount + $routeFeeAmount, 2);
        $note = trim((string) ($offerPayload['note'] ?? ''));
        $currency = strtoupper(substr((string) ($offerPayload['currency'] ?? 'TRY'), 0, 8)) ?: 'TRY';
        $customerDirectAmount = array_key_exists('customer_direct_to_technician_amount', $offerPayload)
            ? $this->nullableSubmittedMoney($offerPayload['customer_direct_to_technician_amount'])
            : null;
        $messagePayload = $this->technicianAssignmentMessagePayload($request, $technician, [
            'labor_amount' => $laborAmount,
            'route_fee_amount' => $routeFeeAmount,
            'total_amount' => $totalAmount,
            'currency' => $currency,
            'note' => $note !== '' ? $note : null,
        ], $assignmentLink);
        $messageText = $this->technicianAssignmentMessageText($messagePayload);
        $messagePayload['message_text'] = $messageText;

        TechnicalServiceAssignmentOffer::query()
            ->where('technical_service_request_id', $request->id)
            ->whereIn('status', [
                TechnicalServiceAssignmentOffer::STATUS_SENT,
                TechnicalServiceAssignmentOffer::STATUS_REVISED,
            ])
            ->update([
                'status' => TechnicalServiceAssignmentOffer::STATUS_CANCELLED,
                'updated_at' => now(),
            ]);

        $offer = TechnicalServiceAssignmentOffer::query()->create([
            'technical_service_request_id' => $request->id,
            'technical_service_technician_id' => $technician->id,
            'route_quote_id' => $routeQuote?->id,
            'labor_amount' => $laborAmount,
            'route_fee_amount' => $routeFeeAmount,
            'total_amount' => $totalAmount,
            'currency' => $currency,
            'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
            'note' => $note !== '' ? $note : null,
            'sent_by' => $user?->id,
            'sent_at' => now(),
            'metadata' => [
                'source' => 'technical_service_assignment',
                'confirmed_by_ops' => (bool) ($offerPayload['confirmed_by_ops'] ?? false),
                'message_payload' => $messagePayload,
                'route_quote_id' => $routeQuote?->id,
                'assignment_partner_id' => $assignmentLink?->partner_id,
                'assignment_partner_technician_link_id' => $assignmentLink?->id,
            ],
        ]);

        $request->forceFill([
            'technician_payment_amount' => round($laborAmount, 2),
            'travel_fee_amount' => round($routeFeeAmount, 2),
        ])->save();

        $request->events()->create([
            'event_type' => 'assignment_offer_sent',
            'title' => 'Hakediş bilgisi hazırlandı',
            'note' => $note !== '' ? $note : null,
            'from_status' => $request->workflow_status,
            'to_status' => $request->workflow_status,
            'author_user_id' => $user?->id,
            'metadata' => [
                'assignment_offer_id' => $offer->id,
                'technician_id' => $technician->id,
                'labor_amount' => $laborAmount,
                'route_fee_amount' => $routeFeeAmount,
                'total_amount' => $totalAmount,
                'currency' => $currency,
                'assignment_partner_id' => $assignmentLink?->partner_id,
                'assignment_partner_technician_link_id' => $assignmentLink?->id,
                'message_payload' => $messagePayload,
            ],
        ]);

        $dispatchSummary = $this->workflowMessages->queueWorkflowDispatches(
            $request,
            'assignment_offer_technician',
            'technician',
            [...$messagePayload, 'prepare_only' => true],
            $user,
            null,
            [
                'recipient_phone' => $technician->phone_e164 ?: ($technician->phone_display ?: $technician->phone),
                'triggered_by' => 'technical_service_assignment_offer',
                'fallback_body' => $messageText,
                'event_version' => 'assignment-offer:'.$offer->id,
                'requires_public_url' => $messagePayload['technician_job_card_url'],
                'metadata' => [
                    'assignment_offer_id' => $offer->id,
                    'manual_ui_send' => false,
                ],
            ],
        );
        if (($dispatchSummary['dispatches'] ?? []) === []) {
            $fallbackDispatch = $this->workflowMessages->queueSystemMessage(
                $request,
                'assignment_offer_technician',
                'technician',
                $messageText,
                [...$messagePayload, 'prepare_only' => true],
                $user,
                null,
                [
                    'recipient_phone' => $technician->phone_e164 ?: ($technician->phone_display ?: $technician->phone),
                    'triggered_by' => 'technical_service_assignment_offer',
                    'metadata' => [
                        'assignment_offer_id' => $offer->id,
                        'manual_ui_send' => false,
                    ],
                ],
            );
            $dispatchSummary['dispatches'][] = [
                'id' => $fallbackDispatch->id,
                'status' => $fallbackDispatch->status,
                'message_type' => $fallbackDispatch->message_type,
                'channel' => $fallbackDispatch->channel,
                'provider_key' => $fallbackDispatch->provider_key,
                'recipient_role' => $fallbackDispatch->recipient_role,
                'target_masked' => $fallbackDispatch->effective_target_phone_mask,
                'last_error_code' => $fallbackDispatch->last_error_code,
                'last_error_message_redacted' => $fallbackDispatch->last_error_message_redacted,
            ];
        }
        $firstDispatch = $dispatchSummary['dispatches'][0] ?? null;
        $metadata = is_array($offer->metadata) ? $offer->metadata : [];
        $metadata['message_dispatch_summary'] = $dispatchSummary;
        $metadata['message_dispatches'] = array_values((array) ($dispatchSummary['dispatches'] ?? []));
        $metadata['message_dispatch'] = is_array($firstDispatch) ? [
            'id' => $firstDispatch['id'] ?? null,
            'status' => $firstDispatch['status'] ?? null,
        ] : null;
        $offer->forceFill(['metadata' => $metadata])->save();

        $this->assignmentSettlementService->persistForAssignment(
            $request->refresh(),
            $technician,
            $offer,
            $routeQuote,
            $laborAmount,
            $routeFeeAmount,
            $customerDirectAmount,
            $user,
        );

        return $offer;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveReviewReassignmentState(
        TechnicalServiceRequest $request,
        array $payload,
        mixed $user,
        ?TechnicalServiceTechnician $technician,
        string $sourceWorkflowStatus,
        bool $isReviewReassignment,
    ): void {
        $resolvedAt = now();
        $resolvedActions = [];

        TechnicalServicePartnerJobAction::query()
            ->where('technical_service_request_id', $request->id)
            ->where('status', TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW)
            ->whereIn('action', $this->workflowService->reviewReassignmentActionTypes())
            ->get()
            ->each(function (TechnicalServicePartnerJobAction $action) use ($user, $technician, $resolvedAt, &$resolvedActions): void {
                $actionPayload = is_array($action->payload) ? $action->payload : [];
                $resolvedActions[] = $action->action;

                $action->forceFill([
                    'status' => TechnicalServicePartnerJobAction::STATUS_APPLIED,
                    'payload' => [
                        ...$actionPayload,
                        'resolved_by_reassignment' => true,
                        'new_technician_id' => $technician?->id,
                        'resolved_by_user_id' => $user?->id,
                        'resolved_at' => $resolvedAt->toISOString(),
                    ],
                ])->save();
            });

        $cancelledConfirmations = 0;
        TechnicalServiceCustomerConfirmation::query()
            ->where('technical_service_request_id', $request->id)
            ->where('status', TechnicalServiceCustomerConfirmation::STATUS_PENDING)
            ->get()
            ->each(function (TechnicalServiceCustomerConfirmation $confirmation) use ($user, $technician, $resolvedAt, &$cancelledConfirmations): void {
                $confirmationPayload = is_array($confirmation->payload) ? $confirmation->payload : [];
                $cancelledConfirmations++;

                $confirmation->forceFill([
                    'status' => TechnicalServiceCustomerConfirmation::STATUS_CANCELLED,
                    'payload' => [
                        ...$confirmationPayload,
                        'cancelled_by_reassignment' => true,
                        'new_technician_id' => $technician?->id,
                        'cancelled_by_user_id' => $user?->id,
                        'cancelled_at' => $resolvedAt->toISOString(),
                    ],
                ])->save();
            });

        $cancelledAssignmentOffers = TechnicalServiceAssignmentOffer::query()
            ->where('technical_service_request_id', $request->id)
            ->whereIn('status', [
                TechnicalServiceAssignmentOffer::STATUS_SENT,
                TechnicalServiceAssignmentOffer::STATUS_REVISED,
            ])
            ->update([
                'status' => TechnicalServiceAssignmentOffer::STATUS_CANCELLED,
                'updated_at' => $resolvedAt,
            ]);

        $request->events()->create([
            'event_type' => $isReviewReassignment ? 'reassign_after_review_resolved' : 'assignment_reassigned',
            'title' => $isReviewReassignment ? 'İş incelemeden yeniden atama akışına alındı' : 'Usta ataması güncellendi',
            'note' => $payload['note'] ?? null,
            'from_status' => $sourceWorkflowStatus,
            'to_status' => $request->workflow_status,
            'author_user_id' => $user?->id,
            'metadata' => [
                'new_technician_id' => $technician?->id,
                'resolved_actions' => array_values(array_unique($resolvedActions)),
                'cancelled_pending_customer_confirmations' => $cancelledConfirmations,
                'cancelled_assignment_offers' => $cancelledAssignmentOffers,
                'reassign_after_review' => $isReviewReassignment,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $amounts
     * @return array<string, mixed>
     */
    private function technicianAssignmentMessagePayload(
        TechnicalServiceRequest $request,
        TechnicalServiceTechnician $technician,
        array $amounts,
        ?B2BPartnerTechnician $assignmentLink,
    ): array {
        $phone = $technician->phone_e164 ?: ($technician->phone_display ?: $technician->phone);
        $address = $request->location_formatted_address ?: $request->service_address;
        $mapsLink = $this->mapsLink($request, $address);
        $jobCardContext = $this->partnerJobScope->technicianJobCardContext($request, $assignmentLink);
        $jobLink = is_string($jobCardContext['canonical_url'] ?? null)
            ? $jobCardContext['canonical_url']
            : null;

        $laborAmount = round((float) ($amounts['labor_amount'] ?? 0), 2);
        $routeFeeAmount = round((float) ($amounts['route_fee_amount'] ?? 0), 2);
        $totalAmount = round((float) ($amounts['total_amount'] ?? 0), 2);
        $operationNote = $amounts['note'] ?? null;

        return [
            'channel' => 'system_payload',
            'recipient' => 'technician',
            'technician_id' => $technician->id,
            'technician_name' => $technician->name,
            'technician_phone' => $this->normalizedMessagePhone($phone) ?: $phone,
            'technician_tel_link' => $this->telLink($phone),
            'mrn' => $request->mrn,
            'mrn_or_srv' => $request->service_code ?: $request->mrn,
            'customer_name' => $request->customer_name,
            'customer_phone' => $this->normalizedMessagePhone($request->customer_phone) ?: $request->customer_phone,
            'customer_tel_link' => $this->telLink($request->customer_phone),
            'address' => $address,
            'service_address' => $address,
            'sms_short_address' => Str::limit((string) $address, 72, '…'),
            'maps_link' => $mapsLink,
            'maps_url' => $mapsLink,
            'product_name' => $request->product_name,
            'model' => $request->product_model,
            'product_model' => $request->product_model,
            'brand' => $request->brand,
            'serial_no' => $request->serial_number,
            'activation_code' => $request->activation_code,
            'job_link' => $jobLink,
            'technician_job_card_url' => $jobLink,
            'technician_job_card_short_url' => $jobLink,
            'technician_job_card_ready' => (bool) ($jobCardContext['ready'] ?? false),
            'technician_job_card_blocker_code' => $jobCardContext['blocker_code'] ?? null,
            'technician_job_card_blocker_message' => $jobCardContext['blocker_message'] ?? null,
            'assignment_partner_id' => $jobCardContext['partner_id'] ?? null,
            'assignment_partner_technician_link_id' => $jobCardContext['partner_technician_link_id'] ?? null,
            'appointment_date' => $request->scheduled_date?->toDateString(),
            'appointment_time' => $request->scheduled_time,
            'labor_amount' => $laborAmount,
            'route_fee_amount' => $routeFeeAmount,
            'total_amount' => $totalAmount,
            'labor_amount_formatted' => $this->messageMoney($laborAmount),
            'route_fee_formatted' => $this->messageMoney($routeFeeAmount),
            'total_amount_formatted' => $this->messageMoney($totalAmount),
            'technician_earning_total_formatted' => $this->messageMoney($totalAmount),
            'currency' => $amounts['currency'] ?? 'TRY',
            'note' => $operationNote,
            'operation_note' => $operationNote,
            'payment_message_trigger' => 'appointment_approval',
            'payment_instruction_included' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function technicianAssignmentMessageText(array $payload): string
    {
        $appointment = trim((string) ($payload['appointment_date'] ?? '').' '.(string) ($payload['appointment_time'] ?? ''));
        $product = trim(implode(' / ', array_filter([
            trim((string) ($payload['product_name'] ?? '')),
            trim((string) ($payload['model'] ?? $payload['product_model'] ?? '')),
        ], fn (string $value): bool => $value !== '')));
        $serialNo = trim((string) ($payload['serial_no'] ?? ''));
        $activationCode = trim((string) ($payload['activation_code'] ?? ''));

        return trim(implode("\n", array_filter([
            'EMAKS Prime Teknik Servis',
            '',
            'Yeni iş teklifi.',
            '',
            'MRN: '.($payload['mrn'] ?? '-'),
            'Müşteri: '.($payload['customer_name'] ?? '-'),
            'Telefon: '.($payload['customer_phone'] ?? '-'),
            'Adres: '.($payload['address'] ?? '-'),
            'Harita: '.($payload['maps_link'] ?? '-'),
            $product !== '' ? 'Ürün: '.$product : null,
            $serialNo !== '' ? 'Seri: '.$serialNo : null,
            $activationCode !== '' ? 'Aktivasyon: '.$activationCode : null,
            $appointment !== '' ? 'Randevu: '.$appointment : null,
            '',
            'Hakediş:',
            'İşçilik: '.$this->messageMoney($payload['labor_amount'] ?? 0),
            'Yol: '.$this->messageMoney($payload['route_fee_amount'] ?? 0),
            'Toplam: '.$this->messageMoney($payload['total_amount'] ?? 0),
            '',
            'İş kartı:',
            $payload['job_link'] ?? '-',
            'Lütfen randevu saati öneriniz.',
            $payload['note'] ?? null,
        ], fn ($line) => is_string($line) && trim($line) !== '')));
    }

    private function assignmentLinkForTechnician(
        ?TechnicalServiceTechnician $technician,
        mixed $preferredPartnerId,
    ): ?B2BPartnerTechnician {
        if (! $technician instanceof TechnicalServiceTechnician) {
            return null;
        }

        $preferredPartnerId = is_numeric($preferredPartnerId) ? (int) $preferredPartnerId : null;
        $links = $this->partnerJobScope->activeAssignmentLinksForTechnician((int) $technician->id);

        if ($links->isEmpty() && $preferredPartnerId === null) {
            return null;
        }

        return $this->partnerJobScope->resolveAssignmentPartnerLink((int) $technician->id, $preferredPartnerId);
    }

    private function messageMoney(mixed $amount): string
    {
        $value = is_numeric($amount) ? (float) $amount : 0.0;
        $decimals = floor($value) === $value ? 0 : 2;

        return number_format($value, $decimals, ',', '.').' TL';
    }

    private function nullableMoney(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return round(max((float) $value, 0), 2);
    }

    private function nullableSubmittedMoney(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    /** @param array<int, string> $fields */
    private function assertStrictPaymentDecimalInputs(Request $request, array $fields): void
    {
        $errors = [];

        foreach ($fields as $field) {
            if (! $request->exists($field)) {
                continue;
            }

            $value = $request->input($field);
            $valid = is_string($value)
                && preg_match('/^(0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/', trim($value)) === 1;

            if (! $valid) {
                $errors[$field] = 'Ödeme tutarı en fazla iki ondalıklı normal decimal biçiminde olmalıdır.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function strictPaymentMinorUnits(string $value): int
    {
        $value = trim($value);
        if (! preg_match('/^(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?$/', $value, $matches)) {
            throw ValidationException::withMessages([
                'amount' => 'Ödeme tutarı normal decimal string biçiminde olmalıdır.',
            ]);
        }
        $fraction = str_pad((string) ($matches[2] ?? ''), 2, '0');

        return ((int) $matches[1] * 100) + (int) $fraction;
    }

    private function minorUnitsToDecimal(int $minorUnits): string
    {
        return intdiv($minorUnits, 100).'.'.str_pad((string) ($minorUnits % 100), 2, '0', STR_PAD_LEFT);
    }

    private function paymentMountSessionForRequest(TechnicalServiceRequest $request): TechnicalServiceMountSession
    {
        $current = $request;
        $visited = [];

        while ($current instanceof TechnicalServiceRequest && ! isset($visited[$current->id])) {
            $visited[$current->id] = true;
            if ($current->mount_session_id !== null) {
                $session = TechnicalServiceMountSession::query()->find((int) $current->mount_session_id);
                if ($session instanceof TechnicalServiceMountSession) {
                    return $session;
                }
            }

            $current = $current->parent_request_id === null
                ? null
                : TechnicalServiceRequest::query()->find((int) $current->parent_request_id);
        }

        $rootMrn = trim((string) ($request->root_mrn ?: ''));
        if ($rootMrn !== '') {
            $rootSessionId = TechnicalServiceRequest::query()
                ->whereNull('parent_request_id')
                ->where('mrn', $rootMrn)
                ->value('mount_session_id');
            if (is_numeric($rootSessionId)) {
                $session = TechnicalServiceMountSession::query()->find((int) $rootSessionId);
                if ($session instanceof TechnicalServiceMountSession) {
                    return $session;
                }
            }
        }

        throw ValidationException::withMessages([
            'payment' => 'Bu kaydın bağlı MRN ödeme altyapısı bulunamadı.',
        ]);
    }

    private function canonicalExtraPaymentPurpose(string $value): string
    {
        return match (strtolower(trim($value))) {
            'multi_product', 'multi_product_mount' => 'multi_product_mount',
            'manual_extra', 'mount_extra', 'manual_mount_payment' => 'extra_mount_fee',
            'route_fee' => 'route_fee',
            'montage_difference' => 'montage_difference',
            'service_payment' => 'service_payment',
            'part_payment' => 'part_payment',
            'service_and_part_payment' => 'service_and_part_payment',
            default => throw ValidationException::withMessages([
                'purpose' => 'Desteklenmeyen ödeme amacı.',
            ]),
        };
    }

    private function telLink(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '0')) {
            $digits = '90'.substr($digits, 1);
        }

        return 'tel:+'.$digits;
    }

    private function normalizedMessagePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?: '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '0')) {
            $digits = '90'.substr($digits, 1);
        }

        return $digits;
    }

    private function mapsLink(TechnicalServiceRequest $request, ?string $address): ?string
    {
        if ($request->location_latitude !== null && $request->location_longitude !== null) {
            return 'https://www.google.com/maps/search/?api=1&query='
                .rawurlencode((string) $request->location_latitude.','.(string) $request->location_longitude);
        }

        $query = trim((string) $address);

        return $query !== ''
            ? 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($query)
            : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateInstallationAfterLatestSale(TechnicalServiceRequest $request, array $payload): void
    {
        if (
            ! $this->isCompletedStatusValue($payload['status'] ?? null)
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
