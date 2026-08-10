<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TechnicalServiceAssignmentOffer;
use App\Models\TechnicalServiceCustomerConfirmation;
use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServicePartnerJobAction;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestUpload;
use App\Models\TechnicalServiceRouteQuote;
use App\Models\TechnicalServiceSettlement;
use App\Models\TechnicalServiceTechnician;
use App\Services\B2B\B2BPartnerServiceJobScopeService;
use App\Services\Messaging\EvolutionWhatsAppMessageService;
use App\Services\Messaging\TechnicalServiceAppointmentMessageDispatchService;
use App\Services\Messaging\TechnicalServiceWorkflowMessageDispatchService;
use App\Services\TechnicalService\TechnicalServiceAssignmentSettlementService;
use App\Services\TechnicalService\TechnicalServicePaymentOwnershipService;
use App\Services\TechnicalService\TechnicalServiceServiceVisitService;
use App\Services\TechnicalService\TechnicalServiceWorkflowService;
use App\Services\TechnicalService\WarrantyService;
use App\Support\PartnerPortalPublicUrl;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class TechnicalServicePartnerPortalOpsController extends Controller
{
    public function __construct(
        private readonly TechnicalServiceWorkflowService $workflow,
        private readonly EvolutionWhatsAppMessageService $messages,
        private readonly TechnicalServiceAppointmentMessageDispatchService $appointmentMessages,
        private readonly TechnicalServiceWorkflowMessageDispatchService $workflowMessages,
        private readonly TechnicalServiceServiceVisitService $serviceVisits,
        private readonly TechnicalServiceAssignmentSettlementService $assignmentSettlements,
        private readonly B2BPartnerServiceJobScopeService $partnerJobScope,
    ) {}

    public function approveAppointmentProposal(
        Request $request,
        TechnicalServiceRequest $technicalServiceRequest,
        TechnicalServicePartnerJobAction $partnerJobAction,
    ): JsonResponse {
        $this->assertProposalBelongsToRequest($technicalServiceRequest, $partnerJobAction);

        $validated = $request->validate([
            'scheduled_date' => ['nullable', 'date'],
            'scheduled_time' => ['nullable', 'date_format:H:i'],
            'selected_slot_index' => ['nullable', 'integer', 'min:0', 'max:2'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $result = DB::transaction(function () use ($technicalServiceRequest, $partnerJobAction, $validated, $request): array {
            $action = TechnicalServicePartnerJobAction::query()
                ->whereKey($partnerJobAction->id)
                ->lockForUpdate()
                ->firstOrFail();
            $job = TechnicalServiceRequest::query()
                ->whereKey($technicalServiceRequest->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertProposalBelongsToRequest($job, $action);
            $payload = is_array($action->payload) ? $action->payload : [];
            if ($action->status === TechnicalServicePartnerJobAction::STATUS_APPLIED
                && is_array($payload['approval'] ?? null)) {
                $storedSummary = is_array($payload['approval']['message_dispatch_summary'] ?? null)
                    ? $payload['approval']['message_dispatch_summary']
                    : [];

                return [
                    'status' => 'duplicate_noop',
                    'message_payloads' => $storedSummary,
                    'message_dispatch_summary' => $storedSummary,
                    'request' => $this->workflow->serialize($job->refresh(), true),
                ];
            }
            if ($action->status !== TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
                throw ValidationException::withMessages([
                    'partner_job_action' => 'Randevu önerisi operasyon incelemesinde değil.',
                ]);
            }
            $slot = $this->selectedAppointmentSlot($payload, (int) ($validated['selected_slot_index'] ?? 0));
            $scheduledDate = $validated['scheduled_date'] ?? ($slot['date'] ?? null);
            $scheduledTime = $validated['scheduled_time'] ?? ($slot['start_time'] ?? null);

            if (! is_string($scheduledDate) || $scheduledDate === '' || ! is_string($scheduledTime) || $scheduledTime === '') {
                throw ValidationException::withMessages([
                    'scheduled_date' => 'Onay için randevu tarihi ve saati gereklidir.',
                ]);
            }

            $from = $job->workflow_status;
            $hadAppointment = $job->scheduled_at !== null
                || ($job->scheduled_date !== null && filled($job->scheduled_time));
            $job = $this->workflow->updateSchedule($job, [
                'scheduled_date' => $scheduledDate,
                'scheduled_time' => $scheduledTime,
                'approve_technician' => true,
                'note' => $validated['note'] ?? 'Partner portal randevu önerisi onaylandı.',
            ], $request->user());

            $messageDispatchSummary = $this->appointmentMessages->dispatchApproval(
                $job->refresh(),
                $action,
                $request->user(),
                [
                    'appointment_updated' => $hadAppointment,
                    'slot' => $slot,
                    'trigger_source' => 'ops_appointment_approval',
                ],
            );
            $payload['approval'] = [
                'approved_at' => now()->toISOString(),
                'approved_by_user_id' => $request->user()?->id,
                'scheduled_date' => $scheduledDate,
                'scheduled_time' => $scheduledTime,
                'selected_slot' => $slot,
                'technician_confirmation_required' => false,
                'note' => $validated['note'] ?? null,
                'message_dispatch_summary' => $messageDispatchSummary,
                'message_dispatches' => $messageDispatchSummary['dispatches'] ?? [],
            ];
            $action->forceFill([
                'status' => TechnicalServicePartnerJobAction::STATUS_APPLIED,
                'payload' => $payload,
            ])->save();

            $job->events()->create([
                'event_type' => 'partner_appointment_approved',
                'title' => 'Partner portal randevu önerisi onaylandı',
                'note' => $validated['note'] ?? null,
                'from_status' => $from,
                'to_status' => $job->workflow_status,
                'author_user_id' => $request->user()?->id,
                'metadata' => [
                    'partner_job_action_id' => $action->id,
                    'proposal' => $slot,
                    'message_dispatch_summary' => $messageDispatchSummary,
                ],
            ]);

            return [
                'status' => 'applied',
                'message_payloads' => $messageDispatchSummary,
                'message_dispatch_summary' => $messageDispatchSummary,
                'request' => $this->workflow->serialize($job->refresh(), true),
            ];
        });

        return response()->json($result);
    }

    public function rejectAppointmentProposal(
        Request $request,
        TechnicalServiceRequest $technicalServiceRequest,
        TechnicalServicePartnerJobAction $partnerJobAction,
    ): JsonResponse {
        $this->assertProposalBelongsToRequest($technicalServiceRequest, $partnerJobAction);

        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in([
                TechnicalServicePartnerJobAction::STATUS_REJECTED,
                TechnicalServicePartnerJobAction::STATUS_REVISION_REQUESTED,
            ])],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        $status = $validated['status'] ?? TechnicalServicePartnerJobAction::STATUS_REVISION_REQUESTED;
        $payload = is_array($partnerJobAction->payload) ? $partnerJobAction->payload : [];
        $payload['ops_review'] = [
            'status' => $status,
            'reviewed_at' => now()->toISOString(),
            'reviewed_by_user_id' => $request->user()?->id,
            'note' => $validated['note'],
        ];
        $partnerJobAction->forceFill([
            'status' => $status,
            'payload' => $payload,
            'note' => $partnerJobAction->note ?: $validated['note'],
        ])->save();

        $technicalServiceRequest->events()->create([
            'event_type' => $status === TechnicalServicePartnerJobAction::STATUS_REJECTED
                ? 'partner_appointment_rejected'
                : 'partner_appointment_revision_requested',
            'title' => $status === TechnicalServicePartnerJobAction::STATUS_REJECTED
                ? 'Partner portal randevu önerisi reddedildi'
                : 'Partner portal randevu önerisi revize istendi',
            'note' => $validated['note'],
            'from_status' => $technicalServiceRequest->workflow_status,
            'to_status' => $technicalServiceRequest->workflow_status,
            'author_user_id' => $request->user()?->id,
            'metadata' => [
                'partner_job_action_id' => $partnerJobAction->id,
                'status' => $status,
            ],
        ]);

        return response()->json([
            'status' => $status,
            'request' => $this->workflow->serialize($technicalServiceRequest->refresh(), true),
        ]);
    }

    public function createServiceVisitFromRevisit(
        Request $request,
        TechnicalServiceRequest $technicalServiceRequest,
        TechnicalServicePartnerJobAction $partnerJobAction,
    ): JsonResponse {
        abort_unless((int) $partnerJobAction->technical_service_request_id === (int) $technicalServiceRequest->id, 404);

        if ($partnerJobAction->action !== TechnicalServicePartnerJobAction::ACTION_REVISIT_REQUESTED) {
            throw ValidationException::withMessages([
                'partner_job_action' => 'Bu kayıt tekrar ziyaret talebi değildir.',
            ]);
        }

        if ($partnerJobAction->status !== TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
            throw ValidationException::withMessages([
                'partner_job_action' => 'SRV oluşturmak için tekrar ziyaret talebi operasyon incelemesinde olmalıdır.',
            ]);
        }

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $result = DB::transaction(function () use ($technicalServiceRequest, $partnerJobAction, $request, $validated): array {
            $payload = is_array($partnerJobAction->payload) ? $partnerJobAction->payload : [];
            $reason = trim((string) ($payload['reason'] ?? $partnerJobAction->note ?? 'Tekrar ziyaret'));
            $child = $this->serviceVisits->createServiceVisitFromRequest(
                $technicalServiceRequest,
                $request->user(),
                'revisit',
                [
                    'source_partner_action' => $partnerJobAction,
                    'description' => 'Tekrar ziyaret servisi: '.$reason,
                    'copy_operation_control' => false,
                    'parent_event_type' => 'revisit_srv_created',
                    'parent_event_title' => 'Tekrar ziyaret SRV kaydı oluşturuldu',
                ],
            );

            $partnerJobAction->forceFill([
                'status' => TechnicalServicePartnerJobAction::STATUS_APPLIED,
                'payload' => [
                    ...$payload,
                    'service_visit_created' => [
                        'request_id' => $child->id,
                        'mrn' => $child->mrn,
                        'service_code' => $child->service_code,
                        'created_at' => now()->toISOString(),
                        'created_by_user_id' => $request->user()?->id,
                        'note' => $validated['note'] ?? null,
                    ],
                ],
            ])->save();

            return [
                'status' => 'created',
                'child_request' => $this->workflow->serialize($child->refresh(), true),
                'request' => $this->workflow->serialize($technicalServiceRequest->refresh(), true),
            ];
        });

        return response()->json($result, 201);
    }

    public function approveCompletionSubmission(
        Request $request,
        TechnicalServiceRequest $technicalServiceRequest,
        TechnicalServicePartnerJobAction $partnerJobAction,
    ): JsonResponse {
        abort_unless((int) $partnerJobAction->technical_service_request_id === (int) $technicalServiceRequest->id, 404);

        if ($partnerJobAction->action !== TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED) {
            throw ValidationException::withMessages([
                'partner_job_action' => 'Bu kayıt tamamlama gönderimi değildir.',
            ]);
        }

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
            'approved_visit_ids' => ['nullable', 'array'],
            'approved_visit_ids.*' => ['integer'],
        ]);

        $result = DB::transaction(function () use ($technicalServiceRequest, $partnerJobAction, $validated, $request): array {
            $action = TechnicalServicePartnerJobAction::query()
                ->whereKey($partnerJobAction->id)
                ->lockForUpdate()
                ->firstOrFail();
            $job = TechnicalServiceRequest::query()
                ->whereKey($technicalServiceRequest->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless((int) $action->technical_service_request_id === (int) $job->id, 404);
            if ($action->action !== TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED) {
                throw ValidationException::withMessages([
                    'partner_job_action' => 'Bu kayıt tamamlama gönderimi değildir.',
                ]);
            }
            $actionPayload = is_array($action->payload) ? $action->payload : [];
            if ($action->status === TechnicalServicePartnerJobAction::STATUS_APPLIED
                && is_array($actionPayload['ops_final_check'] ?? null)) {
                return [
                    'status' => 'duplicate_noop',
                    'message_dispatches' => [],
                    'request' => $this->workflow->serialize($job->refresh(), true),
                ];
            }
            $customerApprovedFinalCheckPending = $action->status === TechnicalServicePartnerJobAction::STATUS_APPLIED
                && ($actionPayload['ops_final_check_required'] ?? false) === true
                && (! array_key_exists('ops_final_check', $actionPayload) || $actionPayload['ops_final_check'] === null);
            if ($action->status !== TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW
                && ! $customerApprovedFinalCheckPending) {
                throw ValidationException::withMessages([
                    'partner_job_action' => 'Tamamlama gönderimi operasyon incelemesinde değil.',
                ]);
            }

            $blockers = $this->completionApprovalBlockers($job, $action);
            if ($blockers !== []) {
                throw ValidationException::withMessages([
                    'completion' => $blockers,
                ]);
            }
            $payoutApproval = $this->completionPayoutApprovalContext(
                $job,
                $validated['approved_visit_ids'] ?? null,
            );
            $from = $job->workflow_status;
            $job->forceFill([
                'photo_status' => 'tamamlandı',
                'document_status' => 'tamamlandı',
                'checklist_status' => 'tamamlandı',
                'checklist_completed_at' => now(),
            ])->save();

            $readyRequest = $job->refresh();
            if ($readyRequest->workflow_status === 'Planlı') {
                $readyRequest = $this->workflow->updateFieldWorkflow($readyRequest, 'arrive', [
                    'technician_arrived_at' => $readyRequest->technician_arrived_at ?? now(),
                    'note' => 'Partner portal son kontrol onayı için saha aşaması doğrulandı.',
                ], $request->user());
            }

            $job = $this->workflow->updateFieldWorkflow($readyRequest->refresh(), 'complete', [
                'note' => $validated['note'] ?? 'Partner portal tamamlama gönderimi operasyon tarafından onaylandı.',
            ], $request->user());
            $job->forceFill([
                'status' => 'Tamamlandı',
                'workflow_status' => 'Tamamlandı',
                'field_status' => 'tamamlandı',
                'completed_at' => $job->completed_at ?? now(),
                'field_completed_at' => $job->field_completed_at ?? now(),
                'technician_completed_at' => $job->technician_completed_at ?? now(),
                'photo_status' => 'tamamlandı',
                'document_status' => 'tamamlandı',
                'customer_closure_approval_status' => 'onaylandı',
                'customer_closure_approved_at' => $job->customer_closure_approved_at ?? now(),
                'completion_block_reason' => null,
            ])->save();
            $closedParent = $this->serviceVisits->closeParentIfChildCompleted($job->refresh(), $request->user());
            if (($payoutApproval['required'] ?? false) === true) {
                $this->persistOpsFinalPayoutApproval($job->refresh(), $payoutApproval, $request);
            }
            $storedPayoutApproval = $payoutApproval;
            unset($storedPayoutApproval['rows']);

            $payload = is_array($action->payload) ? $action->payload : [];
            $payload['ops_final_check'] = [
                'approved_at' => now()->toISOString(),
                'approved_by_user_id' => $request->user()?->id,
                'note' => $validated['note'] ?? null,
                'closed_parent_request_id' => $closedParent?->id,
                'payout_approval' => ($payoutApproval['required'] ?? false) === true ? $storedPayoutApproval : null,
            ];
            $action->forceFill([
                'status' => TechnicalServicePartnerJobAction::STATUS_APPLIED,
                'payload' => $payload,
            ])->save();

            $job->events()->create([
                'event_type' => 'partner_completion_approved',
                'title' => 'Partner portal tamamlama gönderimi onaylandı',
                'note' => $validated['note'] ?? null,
                'from_status' => $from,
                'to_status' => $job->workflow_status,
                'author_user_id' => $request->user()?->id,
                'metadata' => [
                    'partner_job_action_id' => $action->id,
                    'payout_approval' => ($payoutApproval['required'] ?? false) === true ? $storedPayoutApproval : null,
                ],
            ]);

            $warranty = null;
            if ($job->serial_number && $job->service_type === 'Montaj') {
                try {
                    $warranty = app(WarrantyService::class)->statusForSerial((string) $job->serial_number);
                    $job->events()->create([
                        'event_type' => 'product_warranty_start_checked',
                        'title' => 'Garanti başlangıcı kontrol edildi',
                        'note' => null,
                        'from_status' => $job->workflow_status,
                        'to_status' => $job->workflow_status,
                        'author_user_id' => $request->user()?->id,
                        'metadata' => [
                            'serial_no' => $job->serial_number,
                            'warranty' => $warranty,
                            'source' => 'partner_completion_approved',
                        ],
                    ]);
                } catch (Throwable $exception) {
                    $job->events()->create([
                        'event_type' => 'product_warranty_start_failed',
                        'title' => 'Garanti başlangıcı kontrol edilemedi',
                        'note' => $exception->getMessage(),
                        'from_status' => $job->workflow_status,
                        'to_status' => $job->workflow_status,
                        'author_user_id' => $request->user()?->id,
                        'metadata' => [
                            'serial_no' => $job->serial_number,
                            'source' => 'partner_completion_approved',
                        ],
                    ]);
                }
            }
            $completionVersion = $job->updated_at?->timestamp ?? 'missing';
            $warrantyStartedAt = $job->installation_completed_at ?: $job->completed_at ?: $job->field_completed_at;
            $warrantyVersion = $warrantyStartedAt?->timestamp ?? 'missing';
            $warrantyStartedAtFormatted = $this->formatWarrantyDate($warranty['warranty_started_at'] ?? $warrantyStartedAt?->toDateString());
            $warrantyEndsAtFormatted = $this->formatWarrantyDate($warranty['warranty_ends_at'] ?? null);
            $surveyLink = null;

            $messageSummaries = [
                'activation_warranty_customer' => $this->workflowMessages->queueWorkflowDispatches(
                    $job->refresh(),
                    'activation_warranty_customer',
                    'customer',
                    [
                        'completed_at_formatted' => now('Europe/Istanbul')->format('d.m.Y H:i'),
                        'activation_code' => $job->activation_code,
                        'warranty_started_at_formatted' => $warrantyStartedAtFormatted,
                        'warranty_ends_at_formatted' => $warrantyEndsAtFormatted,
                        'survey_link' => $surveyLink,
                    ],
                    $request->user(),
                    $action,
                    [
                        'recipient_phone' => $job->customer_phone,
                        'triggered_by' => 'ops_activation_warranty_ready',
                        'event_version' => 'activation-warranty:'.$action->id.':'.$completionVersion.':'.($job->activation_code ?: 'missing').':'.$warrantyVersion,
                        'metadata' => [
                            'partner_job_action_id' => $action->id,
                            'workflow_event' => 'activation_warranty_customer',
                            'survey_link_logged' => $surveyLink !== null,
                        ],
                    ],
                ),
            ];

            return [
                'status' => 'applied',
                'message_dispatches' => $messageSummaries,
                'request' => $this->workflow->serialize($job->refresh(), true),
            ];
        });

        return response()->json($result);
    }

    private function formatWarrantyDate(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->timezone('Europe/Istanbul')->format('d.m.Y');
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($text)->timezone('Europe/Istanbul')->format('d.m.Y');
        } catch (Throwable) {
            return $text;
        }
    }

    /**
     * @param  array<int, mixed>|null  $approvedVisitIds
     * @return array<string, mixed>
     */
    private function completionPayoutApprovalContext(TechnicalServiceRequest $request, ?array $approvedVisitIds): array
    {
        $payload = $this->workflow->serialize($request, true);
        $breakdown = is_array($payload['earning_breakdown'] ?? null) ? $payload['earning_breakdown'] : [];
        $rows = collect($breakdown['rows'] ?? [])
            ->filter(fn (mixed $row): bool => is_array($row) && isset($row['id']))
            ->values();
        $serviceRows = $rows
            ->filter(fn (array $row): bool => ($row['kind'] ?? null) === 'service')
            ->values();
        $required = $serviceRows->isNotEmpty();

        if (! $required) {
            return [
                'required' => false,
                'approved_request_ids' => [],
                'excluded_request_ids' => [],
                'rows' => $rows->all(),
            ];
        }

        $validIds = $rows
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
        $approved = collect($approvedVisitIds ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($approved->isEmpty()) {
            throw ValidationException::withMessages([
                'approved_visit_ids' => 'Mevcut SRV kapanmadan önce hakedişe dahil edilecek iş açıkça onaylanmalıdır.',
            ]);
        }

        $invalid = $approved->reject(fn (int $id): bool => $validIds->contains($id))->values();
        if ($invalid->isNotEmpty()) {
            throw ValidationException::withMessages([
                'approved_visit_ids' => 'Hakediş onayı bu MRN/SRV grubuna ait olmayan iş içeriyor.',
            ]);
        }

        $currentVisitRequiresApproval = $serviceRows
            ->contains(fn (array $row): bool => (int) ($row['id'] ?? 0) === (int) $request->id);
        if ($currentVisitRequiresApproval && ! $approved->contains((int) $request->id)) {
            throw ValidationException::withMessages([
                'approved_visit_ids' => 'Mevcut SRV hakedişi açıkça onaylanmadan iş kapatılamaz.',
            ]);
        }

        $excluded = $validIds
            ->reject(fn (int $id): bool => $approved->contains($id))
            ->values();

        return [
            'required' => true,
            'root_request_id' => $breakdown['root_request_id'] ?? $request->id,
            'root_mrn' => $breakdown['root_mrn'] ?? $request->root_mrn ?? $request->mrn,
            'approved_request_ids' => $approved->all(),
            'excluded_request_ids' => $excluded->all(),
            'approved_at' => now()->toISOString(),
            'approved_by_user_id' => request()->user()?->id,
            'rows' => $rows->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $approval
     */
    private function persistOpsFinalPayoutApproval(TechnicalServiceRequest $job, array $approval, Request $request): void
    {
        $targetIds = collect([$approval['root_request_id'] ?? null, $job->id])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        TechnicalServiceRequest::query()
            ->whereIn('id', $targetIds->all())
            ->lockForUpdate()
            ->get()
            ->each(function (TechnicalServiceRequest $target) use ($approval, $request): void {
                $operationPayload = is_array($target->operation_control_payload) ? $target->operation_control_payload : [];
                $operationPayload['ops_final_payout_approval'] = [
                    'approved_request_ids' => $approval['approved_request_ids'] ?? [],
                    'excluded_request_ids' => $approval['excluded_request_ids'] ?? [],
                    'approved_at' => $approval['approved_at'] ?? now()->toISOString(),
                    'approved_by_user_id' => $approval['approved_by_user_id'] ?? $request->user()?->id,
                ];
                $target->forceFill(['operation_control_payload' => $operationPayload])->save();
            });

        $approvedIds = collect($approval['approved_request_ids'] ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($approvedIds->isEmpty()) {
            return;
        }

        TechnicalServiceRequest::query()
            ->whereIn('id', $approvedIds->all())
            ->lockForUpdate()
            ->get()
            ->each(function (TechnicalServiceRequest $approved) use ($request): void {
                $this->workflow->finalizeCompletedEarningSnapshotForOpsPayoutApproval($approved, $request->user());
            });
    }

    public function updateAssignmentOffer(
        Request $request,
        TechnicalServiceRequest $technicalServiceRequest,
        TechnicalServiceAssignmentOffer $assignmentOffer,
    ): JsonResponse {
        abort_unless((int) $assignmentOffer->technical_service_request_id === (int) $technicalServiceRequest->id, 404);

        $validated = $request->validate([
            'labor_amount' => ['required', 'numeric', 'min:0'],
            'route_fee_amount' => ['required', 'numeric', 'min:0'],
            'total_amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $result = DB::transaction(function () use ($technicalServiceRequest, $assignmentOffer, $validated, $request): array {
            $offer = TechnicalServiceAssignmentOffer::query()
                ->whereKey($assignmentOffer->id)
                ->lockForUpdate()
                ->firstOrFail();
            $job = TechnicalServiceRequest::query()
                ->whereKey($technicalServiceRequest->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless((int) $offer->technical_service_request_id === (int) $job->id, 404);
            $offer->loadMissing('technician');
            if (! $offer->technician instanceof TechnicalServiceTechnician) {
                throw ValidationException::withMessages([
                    'assignment_offer' => 'Hakediş teklifi geçerli bir ustaya bağlı değil.',
                ]);
            }

            $laborAmount = round((float) $validated['labor_amount'], 2);
            $routeFeeAmount = round((float) $validated['route_fee_amount'], 2);
            $totalAmount = round($laborAmount + $routeFeeAmount, 2);
            $note = isset($validated['note']) ? trim((string) $validated['note']) : null;
            $note = $note !== '' ? $note : null;
            $hasPendingRevision = TechnicalServicePartnerJobAction::query()
                ->where('technical_service_request_id', $job->id)
                ->where('action', TechnicalServicePartnerJobAction::ACTION_PRICE_REVISION_REQUESTED)
                ->where('status', TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW)
                ->where(function ($query) use ($offer): void {
                    $query->whereNull('technical_service_technician_id')
                        ->orWhere('technical_service_technician_id', $offer->technical_service_technician_id);
                })
                ->lockForUpdate()
                ->exists();

            if (! $hasPendingRevision
                && abs((float) $offer->labor_amount - $laborAmount) < 0.005
                && abs((float) $offer->route_fee_amount - $routeFeeAmount) < 0.005
                && abs((float) $offer->total_amount - $totalAmount) < 0.005
                && (($offer->note !== null ? trim((string) $offer->note) : null) === $note)) {
                $presentation = $this->workflow->technicianEarningPresentation($job, $offer->technician, $offer);

                return [
                    'status' => 'duplicate_noop',
                    ...$presentation,
                    'request' => $this->workflow->serialize($job->refresh(), true),
                ];
            }

            $routeQuote = null;
            if (is_numeric($offer->route_quote_id)) {
                $routeQuote = TechnicalServiceRouteQuote::query()
                    ->whereKey((int) $offer->route_quote_id)
                    ->where('technical_service_request_id', $job->id)
                    ->where('technician_id', $offer->technical_service_technician_id)
                    ->first();
                if (! $routeQuote instanceof TechnicalServiceRouteQuote) {
                    throw ValidationException::withMessages([
                        'assignment_offer' => 'Hakediş teklifinin yol hesabı bu iş ve ustayla eşleşmiyor.',
                    ]);
                }
            }

            $existingSettlement = TechnicalServiceSettlement::query()
                ->where('technical_service_request_id', $job->id)
                ->lockForUpdate()
                ->first();
            $oldTotalAmount = round((float) $offer->total_amount, 2);
            $customerDirectAmount = null;
            if ($existingSettlement instanceof TechnicalServiceSettlement) {
                $storedDirectAmount = round((float) $existingSettlement->customer_direct_to_technician_amount, 2);
                $storedCollectionAmount = round((float) $existingSettlement->customer_collection_amount, 2);
                $customerDirectAmount = $storedCollectionAmount > 0
                    ? 0.0
                    : (abs($storedDirectAmount - $oldTotalAmount) < 0.005 ? null : $storedDirectAmount);
            }

            $metadata = is_array($offer->metadata) ? $offer->metadata : [];
            $metadata['message_payload'] = $this->assignmentOfferMessagePayload($job, $offer->technician, [
                'labor_amount' => $laborAmount,
                'route_fee_amount' => $routeFeeAmount,
                'total_amount' => $totalAmount,
                'currency' => $offer->currency,
                'note' => $note,
            ]);
            $metadata['revised_at'] = now()->toISOString();
            $metadata['revised_by_user_id'] = $request->user()?->id;

            $offer->forceFill([
                'labor_amount' => $laborAmount,
                'route_fee_amount' => $routeFeeAmount,
                'total_amount' => $totalAmount,
                'status' => TechnicalServiceAssignmentOffer::STATUS_REVISED,
                'note' => $note,
                'metadata' => $metadata,
            ])->save();
            $job->forceFill([
                'technician_payment_amount' => $laborAmount,
                'travel_fee_amount' => $routeFeeAmount,
            ])->save();
            $this->assignmentSettlements->persistForAssignment(
                $job->refresh(),
                $offer->technician,
                $offer,
                $routeQuote,
                $laborAmount,
                $routeFeeAmount,
                $customerDirectAmount,
                $request->user(),
            );
            $resolvedPriceRevisionActionIds = $this->resolvePendingPriceRevisionActions(
                $job,
                $offer,
                $request,
                [
                    'labor_amount' => $laborAmount,
                    'route_fee_amount' => $routeFeeAmount,
                    'total_amount' => $totalAmount,
                    'note' => $note,
                ],
            );
            if ($resolvedPriceRevisionActionIds !== []) {
                $metadata['resolved_price_revision_action_ids'] = $resolvedPriceRevisionActionIds;
                $metadata['revision_response_status'] = 'resolved';
                $offer->forceFill(['metadata' => $metadata])->save();
            }

            $presentation = $this->workflow->technicianEarningPresentation(
                $job->refresh(),
                $offer->technician,
                $offer->refresh(),
            );

            $job->events()->create([
                'event_type' => 'assignment_offer_revised',
                'title' => $resolvedPriceRevisionActionIds === []
                    ? 'Usta hakediş bilgisi revize edildi'
                    : 'Hakediş revize talebi yanıtlandı',
                'note' => $note,
                'from_status' => $job->workflow_status,
                'to_status' => $job->workflow_status,
                'author_user_id' => $request->user()?->id,
                'metadata' => [
                    'assignment_offer_id' => $offer->id,
                    'labor_amount' => $laborAmount,
                    'route_fee_amount' => $routeFeeAmount,
                    'total_amount' => $totalAmount,
                    'message_payload' => $metadata['message_payload'],
                    'earning_snapshot' => $presentation['earning_snapshot'],
                    'earning_snapshot_revision' => $presentation['earning_snapshot']['revision'],
                    'resolved_price_revision_action_ids' => $resolvedPriceRevisionActionIds,
                    'actor_user_id' => $request->user()?->id,
                    'actor_role' => $request->user()?->role_code ?: 'authenticated_user',
                    'source' => 'technical_service_admin',
                    'occurred_at_istanbul' => now('Europe/Istanbul')->toIso8601String(),
                    'request_id' => $job->id,
                    'mrn' => $job->mrn,
                    'srv' => $job->service_code,
                ],
            ]);

            return [
                'status' => 'revised',
                ...$presentation,
                'request' => $this->workflow->serialize($job->refresh(), true),
            ];
        });

        return response()->json($result);
    }

    public function reviewPartnerAction(
        Request $request,
        TechnicalServiceRequest $technicalServiceRequest,
        TechnicalServicePartnerJobAction $partnerJobAction,
    ): JsonResponse {
        abort_unless((int) $partnerJobAction->technical_service_request_id === (int) $technicalServiceRequest->id, 404);
        abort_unless(in_array($partnerJobAction->action, [
            TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED,
            TechnicalServicePartnerJobAction::ACTION_PRICE_REVISION_REQUESTED,
        ], true), 404);

        $allowedDecisions = $partnerJobAction->action === TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED
            ? ['reviewed', 'resolved', 'more_info']
            : ['rejected', 'revision_requested'];
        $validated = $request->validate([
            'decision' => ['required', 'string', Rule::in($allowedDecisions)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        if (in_array($validated['decision'], ['more_info', 'rejected', 'revision_requested'], true) && ! filled($validated['note'] ?? null)) {
            throw ValidationException::withMessages([
                'note' => 'Bu karar için açıklama zorunludur.',
            ]);
        }

        $result = DB::transaction(function () use ($technicalServiceRequest, $partnerJobAction, $validated, $request): array {
            $action = TechnicalServicePartnerJobAction::query()
                ->whereKey($partnerJobAction->id)
                ->lockForUpdate()
                ->firstOrFail();
            $payload = is_array($action->payload) ? $action->payload : [];
            $existingDecision = data_get($payload, 'ops_review.decision');

            if ($existingDecision === $validated['decision']) {
                return [
                    'status' => 'duplicate_noop',
                    'action' => $action,
                    'request' => $this->workflow->serialize($technicalServiceRequest->refresh(), true),
                ];
            }

            if ($action->status !== TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
                throw ValidationException::withMessages([
                    'decision' => 'Bu talep için daha önce karar verilmiş. Yeni karar uygulanmadı.',
                ]);
            }

            $targetStatus = match ($validated['decision']) {
                'reviewed' => TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW,
                'resolved' => TechnicalServicePartnerJobAction::STATUS_APPLIED,
                'more_info', 'revision_requested' => TechnicalServicePartnerJobAction::STATUS_REVISION_REQUESTED,
                'rejected' => TechnicalServicePartnerJobAction::STATUS_REJECTED,
            };
            $review = [
                'decision' => $validated['decision'],
                'decision_label' => $this->partnerActionDecisionLabel($validated['decision']),
                'note' => $validated['note'] ?? null,
                'reviewed_at' => now()->toISOString(),
                'reviewed_by_user_id' => $request->user()?->id,
                'reviewed_by_name' => $request->user()?->name,
                'actor_role' => $request->user()?->role_code ?: 'authenticated_user',
                'source' => 'technical_service_admin',
            ];
            $payload['ops_review'] = $review;
            $payload['ops_review_required'] = $targetStatus === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW;
            $action->forceFill([
                'status' => $targetStatus,
                'payload' => $payload,
            ])->save();

            $job = $technicalServiceRequest->refresh();
            $job->events()->create([
                'event_type' => $action->action === TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED
                    ? 'support_request_reviewed'
                    : 'price_revision_reviewed',
                'title' => $action->action === TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED
                    ? 'Destek talebi karara bağlandı'
                    : 'Hakediş revizyon talebi karara bağlandı',
                'note' => $validated['note'] ?? null,
                'from_status' => $job->workflow_status,
                'to_status' => $job->workflow_status,
                'author_user_id' => $request->user()?->id,
                'metadata' => [
                    'partner_job_action_id' => $action->id,
                    'decision' => $validated['decision'],
                    'actor_user_id' => $request->user()?->id,
                    'actor_role' => $request->user()?->role_code ?: 'authenticated_user',
                    'source' => 'technical_service_admin',
                    'occurred_at_istanbul' => now('Europe/Istanbul')->toIso8601String(),
                    'request_id' => $job->id,
                    'mrn' => $job->mrn,
                    'srv' => $job->service_code,
                ],
            ]);

            return [
                'status' => $targetStatus,
                'action' => $action->refresh(),
                'request' => $this->workflow->serialize($job->refresh(), true),
            ];
        });

        return response()->json($result);
    }

    /**
     * @param  array<string, mixed>  $amounts
     * @return list<int>
     */
    private function resolvePendingPriceRevisionActions(
        TechnicalServiceRequest $job,
        TechnicalServiceAssignmentOffer $assignmentOffer,
        Request $request,
        array $amounts,
    ): array {
        $technicianId = (int) $assignmentOffer->technical_service_technician_id;
        $actions = TechnicalServicePartnerJobAction::query()
            ->where('technical_service_request_id', $job->id)
            ->where('action', TechnicalServicePartnerJobAction::ACTION_PRICE_REVISION_REQUESTED)
            ->where('status', TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW)
            ->where(function ($query) use ($technicianId): void {
                $query->whereNull('technical_service_technician_id');
                if ($technicianId > 0) {
                    $query->orWhere('technical_service_technician_id', $technicianId);
                }
            })
            ->lockForUpdate()
            ->get();

        return $actions
            ->map(function (TechnicalServicePartnerJobAction $action) use ($assignmentOffer, $request, $amounts): int {
                $payload = is_array($action->payload) ? $action->payload : [];
                $payload['revision_status'] = 'resolved';
                $payload['resolved_at'] = now()->toISOString();
                $payload['resolved_by_user_id'] = $request->user()?->id;
                $payload['resolved_assignment_offer_id'] = $assignmentOffer->id;
                $payload['resolved_labor_amount'] = round((float) ($amounts['labor_amount'] ?? 0), 2);
                $payload['resolved_route_fee_amount'] = round((float) ($amounts['route_fee_amount'] ?? 0), 2);
                $payload['resolved_total_amount'] = round((float) ($amounts['total_amount'] ?? 0), 2);
                $payload['resolved_note'] = $amounts['note'] ?? null;
                $payload['ops_review_required'] = false;

                $action->forceFill([
                    'status' => TechnicalServicePartnerJobAction::STATUS_APPLIED,
                    'payload' => $payload,
                ])->save();

                return (int) $action->id;
            })
            ->values()
            ->all();
    }

    private function partnerActionDecisionLabel(string $decision): string
    {
        return match ($decision) {
            'reviewed' => 'İncelendi',
            'resolved' => 'Çözüldü',
            'more_info' => 'Ek bilgi istendi',
            'rejected' => 'Reddedildi',
            'revision_requested' => 'Düzenleme istendi',
            default => $decision,
        };
    }

    public function resendCustomerApprovalRequest(Request $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = DB::transaction(function () use ($technicalServiceRequest, $request, $validated): array {
            $job = TechnicalServiceRequest::query()->whereKey($technicalServiceRequest->id)->lockForUpdate()->firstOrFail();
            $partnerId = $this->partnerIdForRequest($job);

            if ($partnerId === null) {
                throw ValidationException::withMessages([
                    'partner' => 'Bu iş için aktif çilingir partner bağlantısı bulunamadı.',
                ]);
            }

            $confirmation = TechnicalServiceCustomerConfirmation::query()
                ->where('technical_service_request_id', $job->id)
                ->where(function ($query): void {
                    $query->where('status', TechnicalServiceCustomerConfirmation::STATUS_APPROVED)
                        ->orWhere(function ($pending): void {
                            $pending->where('status', TechnicalServiceCustomerConfirmation::STATUS_PENDING)
                                ->where(function ($valid): void {
                                    $valid->whereNull('expires_at')->orWhere('expires_at', '>', now());
                                });
                        });
                })
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $confirmation instanceof TechnicalServiceCustomerConfirmation) {
                $confirmation = TechnicalServiceCustomerConfirmation::query()->create([
                    'technical_service_request_id' => $job->id,
                    'token' => Str::random(64),
                    'status' => TechnicalServiceCustomerConfirmation::STATUS_PENDING,
                    'payload' => [
                        'partner_id' => $partnerId,
                        'requested_by_user_id' => $request->user()?->id,
                        'technical_service_technician_id' => $job->technical_service_technician_id,
                        'source' => 'ops_resend',
                    ],
                ]);
            }

            $approvalUrl = PartnerPortalPublicUrl::route('service-job-confirmation.show', ['token' => $confirmation->token]);
            $publicUrlWarning = ! $this->workflowMessages->publicUrlReadyForDispatch($approvalUrl)
                ? 'Müşteri onay linki telefondan açılabilir public URL gerektirir. PARTNER_PORTAL_PUBLIC_URL / public portal URL ayarlanmalı.'
                : null;
            $messageText = $this->customerApprovalMessageText($job, $approvalUrl);
            $action = TechnicalServicePartnerJobAction::query()->create([
                'technical_service_request_id' => $job->id,
                'partner_id' => $partnerId,
                'user_id' => $request->user()?->id,
                'technical_service_technician_id' => $job->technical_service_technician_id,
                'action' => TechnicalServicePartnerJobAction::ACTION_CUSTOMER_OTP_REQUESTED,
                'status' => TechnicalServicePartnerJobAction::STATUS_SUBMITTED,
                'note' => $validated['note'] ?? 'Müşteri onay linki operasyon tarafından tekrar gönderildi.',
                'payload' => [
                    'confirmation_method' => 'customer_link',
                    'provider' => 'dispatch_queue',
                    'confirmation_id' => $confirmation->id,
                    'approval_url' => $approvalUrl,
                    'confirmation_url' => $approvalUrl,
                    'requested_at' => now()->toISOString(),
                    'message_payload' => [
                        'recipient' => 'customer',
                        'channel' => 'dispatch_queue',
                        'mrn' => $job->mrn,
                        'customer_phone' => $job->customer_phone,
                        'message_text' => $messageText,
                        'approval_url' => $approvalUrl,
                        'confirmation_url' => $approvalUrl,
                        'public_url_warning' => $publicUrlWarning,
                    ],
                ],
            ]);

            $dispatch = $this->workflowMessages->queueSystemMessage(
                $job,
                'customer_approval_request',
                'customer',
                $messageText,
                [
                    'confirmation_link' => $approvalUrl,
                    'confirmation_link_sms' => $approvalUrl,
                    'confirmation_url' => $approvalUrl,
                    'approval_url' => $approvalUrl,
                    'confirmation_id' => $confirmation->id,
                    'public_url_warning' => $publicUrlWarning,
                    'force_resend' => true,
                    'message_type' => 'customer_approval_request',
                    'manual_ui_send' => true,
                ],
                $request->user(),
                $action,
                [
                    'recipient_phone' => $job->customer_phone,
                    'triggered_by' => 'ops_customer_approval_request_resend',
                    'requires_public_url' => $approvalUrl,
                    'metadata' => [
                        'force_resend' => true,
                        'manual_ui_send' => true,
                    ],
                ],
            );
            $dispatchSummary = $this->dispatchSummary($dispatch);
            $canonicalMessageText = $dispatch->bodyForProvider();
            $actionPayload = is_array($action->payload) ? $action->payload : [];
            $messagePayload = [
                ...($actionPayload['message_payload'] ?? []),
                ...$dispatchSummary,
                'dispatch_id' => $dispatch->id,
                'message_text' => $canonicalMessageText,
                'approval_url' => $approvalUrl,
                'confirmation_url' => $approvalUrl,
            ];
            $action->forceFill([
                'payload' => [
                    ...$actionPayload,
                    ...$dispatchSummary,
                    'message_payload' => $messagePayload,
                ],
            ])->save();
            $confirmation->forceFill([
                'payload' => [
                    ...(is_array($confirmation->payload) ? $confirmation->payload : []),
                    'partner_action_id' => $action->id,
                    'message_payload' => $messagePayload,
                ],
            ])->save();

            $job->events()->create([
                'event_type' => 'customer_approval_request_resent',
                'title' => 'Müşteri onay linki tekrar gönderildi',
                'note' => $validated['note'] ?? null,
                'from_status' => $job->workflow_status,
                'to_status' => $job->workflow_status,
                'author_user_id' => $request->user()?->id,
                'metadata' => [
                    'partner_job_action_id' => $action->id,
                    'customer_confirmation_id' => $confirmation->id,
                    'message_dispatch' => $dispatchSummary,
                ],
            ]);

            return [
                'status' => $action->status,
                'action' => $action->action,
                'dispatch' => $dispatchSummary,
                'message' => $this->dispatchUserMessage($dispatchSummary),
                'request' => $this->workflow->serialize($job->refresh(), true),
            ];
        });

        return response()->json($result);
    }

    /**
     * @return array<int, string>
     */
    private function completionApprovalBlockers(TechnicalServiceRequest $request, ?TechnicalServicePartnerJobAction $completionAction = null): array
    {
        $documents = $this->currentFieldCompletionDocuments($request);
        $labels = [
            'before_photo' => 'Öncesi fotoğrafı',
            'after_photo' => 'Sonrası fotoğrafı',
            'warranty_document_photo' => 'Garanti Belgesi',
        ];
        $blockers = [];

        foreach ($labels as $field => $label) {
            $fieldDocument = $documents->get($field);
            if (! $fieldDocument instanceof TechnicalServiceRequestUpload) {
                $blockers[] = $label.' eksik.';

                continue;
            }

            if ($fieldDocument->review_status === 'rejected') {
                $blockers[] = $label.' uygun değil.';

                continue;
            }

            if ($fieldDocument->review_status !== 'accepted') {
                $blockers[] = $label.' uygunluk kararı bekliyor.';
            }
        }

        if ($request->customer_closure_approval_status !== 'onaylandı') {
            $blockers[] = 'Müşteri onayı bekliyor.';
        }

        if (! $this->hasBackendCompletionChecklist($request, $completionAction)) {
            $blockers[] = 'Backend kontrol eksik.';
        }

        return $blockers;
    }

    /**
     * @return Collection<string, TechnicalServiceRequestUpload>
     */
    private function currentFieldCompletionDocuments(TechnicalServiceRequest $request): Collection
    {
        return $request->uploads()
            ->whereIn('category', [
                TechnicalServiceRequestUpload::CATEGORY_PARTNER_PORTAL_FIELD_DOCUMENT,
                TechnicalServiceRequestUpload::CATEGORY_OPERATION_CONTROL_DOOR_PHOTO,
            ])
            ->whereIn('field_code', ['before_photo', 'after_photo', 'warranty_document_photo'])
            ->get()
            ->filter(fn (TechnicalServiceRequestUpload $upload): bool => ! $this->recordPredatesActiveReopen($request, $upload->created_at ?? $upload->updated_at))
            ->sort(function (TechnicalServiceRequestUpload $left, TechnicalServiceRequestUpload $right): int {
                $createdAtCompare = ($right->created_at?->getTimestamp() ?? 0) <=> ($left->created_at?->getTimestamp() ?? 0);

                if ($createdAtCompare !== 0) {
                    return $createdAtCompare;
                }

                return ((int) $right->id) <=> ((int) $left->id);
            })
            ->unique(fn (TechnicalServiceRequestUpload $upload): string => (string) $upload->field_code)
            ->mapWithKeys(fn (TechnicalServiceRequestUpload $upload): array => [
                (string) $upload->field_code => $upload,
            ]);
    }

    private function recordPredatesActiveReopen(TechnicalServiceRequest $request, mixed $recordAt): bool
    {
        return $request->reopened_at instanceof CarbonInterface
            && $recordAt instanceof CarbonInterface
            && $recordAt->lessThanOrEqualTo($request->reopened_at);
    }

    private function hasBackendCompletionChecklist(TechnicalServiceRequest $request, ?TechnicalServicePartnerJobAction $completionAction): bool
    {
        if ($request->checklist_status === 'tamamlandı') {
            return true;
        }

        $payload = is_array($completionAction?->payload) ? $completionAction->payload : [];
        $checklist = $payload['checklist'] ?? null;

        if (($payload['checklist_gate'] ?? null) !== 'server_checked' || ! is_array($checklist) || $checklist === []) {
            return false;
        }

        foreach ($checklist as $value) {
            if (filter_var($value, FILTER_VALIDATE_BOOL) !== true) {
                return false;
            }
        }

        return true;
    }

    private function assertProposalBelongsToRequest(TechnicalServiceRequest $request, TechnicalServicePartnerJobAction $action): void
    {
        abort_unless((int) $action->technical_service_request_id === (int) $request->id, 404);
        if (! in_array($action->action, [
            TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED,
            TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_CHANGE_REQUESTED,
        ], true)) {
            throw ValidationException::withMessages([
                'partner_job_action' => 'Bu kayıt randevu önerisi değildir.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function selectedAppointmentSlot(array $payload, int $index): array
    {
        $slots = is_array($payload['slots'] ?? null) ? array_values($payload['slots']) : [];

        if ($slots === [] && is_array($payload['proposal'] ?? null)) {
            $proposal = $payload['proposal'];
            $slots[] = [
                'date' => $proposal['proposed_date'] ?? null,
                'start_time' => $proposal['proposed_time_start'] ?? $this->legacySlotStartTime((string) ($proposal['proposed_slot'] ?? '')),
                'end_time' => $proposal['proposed_time_end'] ?? $this->legacySlotEndTime((string) ($proposal['proposed_slot'] ?? '')),
                'label' => $proposal['slot_label'] ?? null,
            ];
        }

        if (! isset($slots[$index]) || ! is_array($slots[$index])) {
            throw ValidationException::withMessages([
                'selected_slot_index' => 'Onaylanacak randevu saati bulunamadı.',
            ]);
        }

        return $this->normalizeAppointmentSlot($slots[$index]);
    }

    /**
     * @param  array<string, mixed>  $slot
     * @return array<string, mixed>
     */
    private function normalizeAppointmentSlot(array $slot): array
    {
        $slotCode = (string) ($slot['slot'] ?? $slot['proposed_slot'] ?? '');
        $range = $this->timeRangeFromSlotCode($slotCode);

        $slot['date'] = $slot['date'] ?? $slot['proposed_date'] ?? null;
        $slot['start_time'] = $slot['start_time'] ?? $slot['proposed_time_start'] ?? ($range['start_time'] ?? $this->legacySlotStartTime($slotCode));
        $slot['end_time'] = $slot['end_time'] ?? $slot['proposed_time_end'] ?? ($range['end_time'] ?? $this->legacySlotEndTime($slotCode));
        $slot['label'] = $slot['label'] ?? $slot['slot_label'] ?? $slot['slot'] ?? null;

        return $slot;
    }

    /**
     * @return array{start_time:string,end_time:string}|null
     */
    private function timeRangeFromSlotCode(string $slot): ?array
    {
        $compact = str_replace([' ', '–', '—'], ['', '-', '-'], trim($slot));

        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)-([01]\d|2[0-3]):([0-5]\d)$/', $compact, $matches) !== 1) {
            return null;
        }

        return [
            'start_time' => "{$matches[1]}:{$matches[2]}",
            'end_time' => "{$matches[3]}:{$matches[4]}",
        ];
    }

    private function legacySlotStartTime(string $slot): string
    {
        return match ($slot) {
            'afternoon' => '14:00',
            default => '10:00',
        };
    }

    private function legacySlotEndTime(string $slot): string
    {
        return match ($slot) {
            'morning' => '12:00',
            'afternoon' => '16:00',
            default => '18:00',
        };
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return array<string, array<string, mixed>>
     */
    private function appointmentApprovalMessages(TechnicalServiceRequest $request, array $proposal, bool $appointmentUpdated = false): array
    {
        $slotText = $this->slotTextFromRange((string) ($proposal['start_time'] ?? ''), (string) ($proposal['end_time'] ?? ''));
        $timeRange = trim((string) ($proposal['start_time'] ?? '').' - '.(string) ($proposal['end_time'] ?? ''));
        $assignmentOffer = $request->latestAssignmentOffer;
        $technician = $request->technicianRecord;
        $customerPrefix = $appointmentUpdated ? 'Randevunuz güncellenmiştir.' : "{$request->mrn} numaralı servisiniz";
        $paymentContext = app(TechnicalServicePaymentOwnershipService::class)->summary($request, $request->settlement);
        $customerPaymentLine = $this->appointmentCustomerPaymentLine($paymentContext);

        return [
            'customer' => [
                'channel' => 'system_payload',
                'recipient' => 'customer',
                'mrn' => $request->mrn,
                'product_model' => collect([$request->product_name, $request->product_model])->filter()->implode(' / '),
                'appointment_date' => $request->scheduled_date?->toDateString(),
                'appointment_time_range' => $timeRange !== '-' ? $timeRange : null,
                'slot_text' => $slotText,
                'payment_context' => $paymentContext,
                'payer_state_key' => $paymentContext['payer_state_key'] ?? null,
                'customer_should_pay_technician' => $paymentContext['customer_should_pay_technician'] ?? false,
                'customer_direct_to_technician_amount' => $paymentContext['active_customer_direct_to_technician_amount'] ?? 0,
                'message_text' => trim(implode(' ', array_filter([
                    "{$customerPrefix} {$request->scheduled_date?->format('d.m.Y')} tarihinde {$slotText} için planlandı.",
                    $customerPaymentLine,
                    'Emaks Prime operasyon ekibi.',
                ], fn (?string $line): bool => is_string($line) && trim($line) !== ''))),
            ],
            'technician' => [
                'channel' => 'system_payload',
                'recipient' => 'technician',
                'mrn' => $request->mrn,
                'customer_name' => $request->customer_name,
                'customer_phone' => $request->customer_phone,
                'customer_tel_link' => $this->telLink($request->customer_phone),
                'address' => $request->location_formatted_address ?: $request->service_address,
                'maps_link' => $this->mapsLink($request, $request->location_formatted_address ?: $request->service_address),
                'appointment_date' => $request->scheduled_date?->toDateString(),
                'appointment_time_range' => $timeRange !== '-' ? $timeRange : null,
                'slot_text' => $slotText,
                'technician_id' => $technician?->id,
                'technician_name' => $technician?->name,
                'labor_amount' => $assignmentOffer ? (float) $assignmentOffer->labor_amount : null,
                'route_fee_amount' => $assignmentOffer ? (float) $assignmentOffer->route_fee_amount : null,
                'total_amount' => $assignmentOffer ? (float) $assignmentOffer->total_amount : null,
                'payment_context' => $paymentContext,
                'payer_state_key' => $paymentContext['payer_state_key'] ?? null,
                'customer_should_pay_technician' => $paymentContext['customer_should_pay_technician'] ?? false,
                'customer_direct_to_technician_amount' => $paymentContext['active_customer_direct_to_technician_amount'] ?? 0,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $paymentContext
     */
    private function appointmentCustomerPaymentLine(array $paymentContext): ?string
    {
        if (($paymentContext['payer_state_key'] ?? null) !== TechnicalServicePaymentOwnershipService::STATE_CUSTOMER_PAYS_TECHNICIAN) {
            return null;
        }

        $amount = (float) ($paymentContext['active_customer_direct_to_technician_amount'] ?? 0);
        if ($amount <= 0) {
            return null;
        }

        return 'Ödeme tutarı ustaya ödenecek: '.number_format($amount, 0, ',', '.').' TL.';
    }

    private function slotTextFromRange(string $start, string $end): string
    {
        if ($start >= '06:00' && $end <= '12:00') {
            return 'öğleden önce';
        }

        if ($start >= '12:00' && $end <= '18:00') {
            return 'öğleden sonra';
        }

        return 'belirlenen saat aralığında';
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function slotText(string $slot, array $proposal): string
    {
        return match ($slot) {
            'morning' => 'öğleden önce',
            'afternoon' => 'öğleden sonra',
            'full_day' => 'gün içinde',
            'custom' => trim(($proposal['proposed_time_start'] ?? '').' - '.($proposal['proposed_time_end'] ?? '')) ?: 'özel saat',
            default => 'gün içinde',
        };
    }

    /**
     * @param  array<string, mixed>  $amounts
     * @return array<string, mixed>|null
     */
    private function assignmentOfferMessagePayload(TechnicalServiceRequest $request, ?TechnicalServiceTechnician $technician, array $amounts): ?array
    {
        if (! $technician instanceof TechnicalServiceTechnician) {
            return null;
        }

        $jobCardContext = $this->partnerJobScope->technicianJobCardContext($request);
        $jobLink = is_string($jobCardContext['canonical_url'] ?? null)
            ? $jobCardContext['canonical_url']
            : null;

        return [
            'channel' => 'system_payload',
            'recipient' => 'technician',
            'mrn' => $request->mrn,
            'technician_id' => $technician->id,
            'technician_name' => $technician->name,
            'technician_phone' => $technician->phone_e164 ?: ($technician->phone_display ?: $technician->phone),
            'customer_name' => $request->customer_name,
            'customer_phone' => $request->customer_phone,
            'customer_tel_link' => $this->telLink($request->customer_phone),
            'address' => $request->location_formatted_address ?: $request->service_address,
            'maps_link' => $this->mapsLink($request, $request->location_formatted_address ?: $request->service_address),
            'job_link' => $jobLink,
            'technician_job_card_url' => $jobLink,
            'technician_job_card_short_url' => $jobLink,
            'technician_job_card_ready' => (bool) ($jobCardContext['ready'] ?? false),
            'technician_job_card_blocker_code' => $jobCardContext['blocker_code'] ?? null,
            'technician_job_card_blocker_message' => $jobCardContext['blocker_message'] ?? null,
            'labor_amount' => round((float) ($amounts['labor_amount'] ?? 0), 2),
            'route_fee_amount' => round((float) ($amounts['route_fee_amount'] ?? 0), 2),
            'total_amount' => round((float) ($amounts['total_amount'] ?? 0), 2),
            'currency' => $amounts['currency'] ?? 'TRY',
            'note' => $amounts['note'] ?? null,
            'payment_message_trigger' => 'appointment_approval',
            'payment_instruction_included' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assignmentOfferMessageText(?array $payload): string
    {
        if (! is_array($payload)) {
            return 'Usta hakediş bilgisi güncellendi.';
        }

        return trim(implode("\n", array_filter([
            'Emaks Prime teknik servis işi',
            'MRN: '.($payload['mrn'] ?? '-'),
            'Müşteri: '.($payload['customer_name'] ?? '-'),
            'Telefon: '.($payload['customer_tel_link'] ?? ($payload['customer_phone'] ?? '-')),
            'Adres: '.($payload['address'] ?? '-'),
            'Harita: '.($payload['maps_link'] ?? '-'),
            'İşçilik / montaj: '.($payload['labor_amount'] ?? 0).' '.($payload['currency'] ?? 'TRY'),
            'Usta yol hakedişi: '.($payload['route_fee_amount'] ?? 0).' '.($payload['currency'] ?? 'TRY'),
            'Toplam: '.($payload['total_amount'] ?? 0).' '.($payload['currency'] ?? 'TRY'),
            'İş kartı:',
            $payload['job_link'] ?? null,
            $payload['note'] ?? null,
        ], fn ($line) => is_string($line) && trim($line) !== '')));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function technicianAppointmentMessageText(array $payload): string
    {
        return trim(implode("\n", array_filter([
            'Emaks Prime randevu bilgisi',
            'MRN: '.($payload['mrn'] ?? '-'),
            'Müşteri: '.($payload['customer_name'] ?? '-'),
            'Telefon: '.($payload['customer_tel_link'] ?? ($payload['customer_phone'] ?? '-')),
            'Adres: '.($payload['address'] ?? '-'),
            'Harita: '.($payload['maps_link'] ?? '-'),
            'Randevu: '.trim((string) ($payload['appointment_date'] ?? '').' '.(string) ($payload['appointment_time_range'] ?? '')),
            'İşçilik / montaj: '.($payload['labor_amount'] ?? 0).' TRY',
            'Usta yol hakedişi: '.($payload['route_fee_amount'] ?? 0).' TRY',
            'Toplam: '.($payload['total_amount'] ?? 0).' TRY',
        ], fn ($line) => is_string($line) && trim($line) !== '')));
    }

    private function partnerIdForRequest(TechnicalServiceRequest $request): ?int
    {
        return $this->partnerJobScope->activeAssignmentLink($request)?->partner_id;
    }

    private function customerApprovalMessageText(TechnicalServiceRequest $job, string $approvalUrl): string
    {
        $product = trim(implode(' / ', array_filter([
            (string) $job->product_name,
            (string) $job->product_model,
        ])));

        return implode("\n", [
            'Emaks Prime Teknik Servis',
            '',
            'Sayın '.($job->customer_name ?: 'müşterimiz').',',
            ($product !== '' ? $product.' montaj işleminiz için onayınız gerekmektedir.' : 'Montaj işleminiz için onayınız gerekmektedir.'),
            '',
            'Talep No: '.$job->mrn,
            '',
            'Montajın tamamlandığını ve üründe görünür hasar/kusur olmadığını kontrol ettiyseniz aşağıdaki bağlantıdan onay verebilirsiniz:',
            '',
            $approvalUrl,
            '',
            'Bu işlemi siz yapmadıysanız operasyon ekibimizle iletişime geçiniz.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatchSummary(TechnicalServiceMessageDispatch $dispatch): array
    {
        $responsePayload = is_array($dispatch->response_payload) ? $dispatch->response_payload : [];
        $requestPayload = is_array($dispatch->request_payload) ? $dispatch->request_payload : [];
        $responseStatusCode = $responsePayload['status'] ?? null;
        $responseBody = $responsePayload['body'] ?? null;
        $errorMessage = $dispatch->error_message;

        if ($dispatch->status === TechnicalServiceMessageDispatch::STATUS_NOT_CONFIGURED && ! filled($errorMessage)) {
            $errorMessage = 'WhatsApp webhook ayarı eksik.';
        }

        return [
            'dispatch_status' => $dispatch->status,
            'dispatch_provider' => $dispatch->provider_key ?: data_get($requestPayload, 'provider') ?: 'null_local',
            'target_phone' => $dispatch->target_phone,
            'test_mode' => (bool) $dispatch->test_mode,
            'response_status_code' => is_numeric($responseStatusCode) ? (int) $responseStatusCode : null,
            'response_body_summary' => $this->summarizeDispatchBody($responseBody),
            'error_message' => filled($errorMessage) ? $errorMessage : null,
            'public_url_warning' => $requestPayload['public_url_warning']
                ?? ($requestPayload['context']['public_url_warning'] ?? null),
        ];
    }

    /**
     * @param  mixed  $body
     */
    private function summarizeDispatchBody($body): ?string
    {
        if ($body === null || $body === '') {
            return null;
        }

        $summary = is_string($body)
            ? $body
            : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return mb_substr((string) $summary, 0, 500);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function dispatchUserMessage(array $summary): string
    {
        if (($summary['dispatch_status'] ?? null) === TechnicalServiceMessageDispatch::STATUS_SENT) {
            $warning = trim((string) ($summary['public_url_warning'] ?? ''));

            return 'WhatsApp onay mesajı gönderildi.'.($warning !== '' ? ' '.$warning : '');
        }

        if (($summary['dispatch_status'] ?? null) === TechnicalServiceMessageDispatch::STATUS_QUEUED) {
            return 'Müşteri onay mesajı kuyruğa alındı.';
        }

        if (($summary['dispatch_status'] ?? null) === TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_DUPLICATE) {
            return 'Bu mesaj daha önce gönderildi; tekrar WhatsApp gönderilmedi.';
        }

        if (($summary['dispatch_status'] ?? null) === TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_RATE_LIMITED) {
            return 'WhatsApp gönderimi güvenlik limiti nedeniyle bastırıldı. Biraz sonra tekrar deneyin.';
        }

        if (
            ($summary['dispatch_status'] ?? null) === TechnicalServiceMessageDispatch::STATUS_SUPPRESSED
            && in_array(($summary['dispatch_provider'] ?? null), ['null_local', 'system'], true)
        ) {
            return 'Müşteri onay mesajı sistem kaydı olarak tutuldu.';
        }

        if (in_array(($summary['dispatch_status'] ?? null), [
            TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_TEST_FIXTURE,
            TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_TESTING_ENVIRONMENT,
            TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_REAL_SEND_DISABLED,
        ], true)) {
            return 'Test mesajı gerçek WhatsApp’a gönderilmedi.';
        }

        $reason = trim((string) ($summary['error_message'] ?? ''));

        return 'WhatsApp mesajı gönderilemedi'.($reason !== '' ? ': '.$reason : '.');
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
}
