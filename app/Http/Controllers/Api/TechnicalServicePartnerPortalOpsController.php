<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\B2B\B2BPartnerTechnician;
use App\Models\TechnicalServiceAssignmentOffer;
use App\Models\TechnicalServiceCustomerConfirmation;
use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServicePartnerJobAction;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestUpload;
use App\Models\TechnicalServiceTechnician;
use App\Services\Messaging\EvolutionWhatsAppMessageService;
use App\Services\TechnicalService\TechnicalServiceWorkflowService;
use App\Services\TechnicalService\TechnicalServiceServiceVisitService;
use App\Services\TechnicalService\WarrantyService;
use App\Support\PartnerPortalPublicUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        private readonly TechnicalServiceServiceVisitService $serviceVisits,
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
            $payload = is_array($partnerJobAction->payload) ? $partnerJobAction->payload : [];
            $slot = $this->selectedAppointmentSlot($payload, (int) ($validated['selected_slot_index'] ?? 0));
            $scheduledDate = $validated['scheduled_date'] ?? ($slot['date'] ?? null);
            $scheduledTime = $validated['scheduled_time'] ?? ($slot['start_time'] ?? null);

            if (! is_string($scheduledDate) || $scheduledDate === '' || ! is_string($scheduledTime) || $scheduledTime === '') {
                throw ValidationException::withMessages([
                    'scheduled_date' => 'Onay iÃ§in randevu tarihi ve saati gereklidir.',
                ]);
            }

            $from = $technicalServiceRequest->workflow_status;
            $hadAppointment = $technicalServiceRequest->scheduled_at !== null
                || ($technicalServiceRequest->scheduled_date !== null && filled($technicalServiceRequest->scheduled_time));
            $job = $this->workflow->updateSchedule($technicalServiceRequest, [
                'scheduled_date' => $scheduledDate,
                'scheduled_time' => $scheduledTime,
                'approve_technician' => true,
                'note' => $validated['note'] ?? 'Partner portal randevu önerisi onaylandı.',
            ], $request->user());

            $messages = $this->appointmentApprovalMessages($job->refresh(), $slot, $hadAppointment);
            $customerDispatch = $this->messages->send(
                $hadAppointment ? 'appointment_updated_customer' : 'appointment_approved_customer',
                'customer',
                $job->customer_phone,
                (string) ($messages['customer']['message_text'] ?? ''),
                [...($messages['customer'] ?? []), 'manual_ui_send' => true],
                $job,
                $request->user(),
                $partnerJobAction,
            );
            $technicianPhone = $job->technicianRecord?->phone_e164
                ?: ($job->technicianRecord?->phone_display ?: $job->technicianRecord?->phone);
            $technicianDispatch = $this->messages->send(
                $hadAppointment ? 'appointment_updated_technician' : 'appointment_approved_technician',
                'technician',
                $technicianPhone,
                $this->technicianAppointmentMessageText($messages['technician'] ?? []),
                [...($messages['technician'] ?? []), 'manual_ui_send' => true],
                $job,
                $request->user(),
                $partnerJobAction,
            );
            $payload['approval'] = [
                'approved_at' => now()->toISOString(),
                'approved_by_user_id' => $request->user()?->id,
                'scheduled_date' => $scheduledDate,
                'scheduled_time' => $scheduledTime,
                'selected_slot' => $slot,
                'technician_confirmation_required' => false,
                'note' => $validated['note'] ?? null,
                'messages' => $messages,
                'message_dispatches' => [
                    'customer' => ['id' => $customerDispatch->id, 'status' => $customerDispatch->status],
                    'technician' => ['id' => $technicianDispatch->id, 'status' => $technicianDispatch->status],
                ],
            ];
            $partnerJobAction->forceFill([
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
                    'partner_job_action_id' => $partnerJobAction->id,
                    'proposal' => $slot,
                    'messages' => $messages,
                ],
            ]);

            return [
                'status' => 'applied',
                'message_payloads' => $messages,
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
                ? 'Partner portal randevu Ã¶nerisi reddedildi'
                : 'Partner portal randevu Ã¶nerisi revize istendi',
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
                'partner_job_action' => 'Bu kayÃ„Â±t tamamlama gÃƒÂ¶nderimi deÃ„Å¸ildir.',
            ]);
        }

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $blockers = $this->completionApprovalBlockers($technicalServiceRequest->refresh(), $partnerJobAction);
        if ($blockers !== []) {
            throw ValidationException::withMessages([
                'completion' => $blockers,
            ]);
        }

        $result = DB::transaction(function () use ($technicalServiceRequest, $partnerJobAction, $validated, $request): array {
            $from = $technicalServiceRequest->workflow_status;
            $technicalServiceRequest->forceFill([
                'photo_status' => 'tamamlandı',
                'document_status' => 'tamamlandı',
                'checklist_status' => 'tamamlandı',
                'checklist_completed_at' => now(),
            ])->save();

            $readyRequest = $technicalServiceRequest->refresh();
            if ($readyRequest->workflow_status === 'Planlı') {
                $readyRequest = $this->workflow->updateFieldWorkflow($readyRequest, 'arrive', [
                    'technician_arrived_at' => $readyRequest->technician_arrived_at ?? now(),
                    'note' => 'Partner portal son kontrol onayı için saha aşaması doğrulandı.',
                ], $request->user());
            }

            $job = $this->workflow->updateFieldWorkflow($readyRequest->refresh(), 'complete', [
                'note' => $validated['note'] ?? 'Partner portal tamamlama gÃƒÂ¶nderimi operasyon tarafÃ„Â±ndan onaylandÃ„Â±.',
            ], $request->user());
            $closedParent = $this->serviceVisits->closeParentIfChildCompleted($job->refresh(), $request->user());
            $payload = is_array($partnerJobAction->payload) ? $partnerJobAction->payload : [];
            $payload['ops_final_check'] = [
                'approved_at' => now()->toISOString(),
                'approved_by_user_id' => $request->user()?->id,
                'note' => $validated['note'] ?? null,
                'closed_parent_request_id' => $closedParent?->id,
            ];
            $partnerJobAction->forceFill([
                'status' => TechnicalServicePartnerJobAction::STATUS_APPLIED,
                'payload' => $payload,
            ])->save();

            $job->events()->create([
                'event_type' => 'partner_completion_approved',
                'title' => 'Partner portal tamamlama gÃƒÂ¶nderimi onaylandÃ„Â±',
                'note' => $validated['note'] ?? null,
                'from_status' => $from,
                'to_status' => $job->workflow_status,
                'author_user_id' => $request->user()?->id,
                'metadata' => [
                    'partner_job_action_id' => $partnerJobAction->id,
                ],
            ]);

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

            return [
                'status' => 'applied',
                'request' => $this->workflow->serialize($job->refresh(), true),
            ];
        });

        return response()->json($result);
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

        $laborAmount = round((float) $validated['labor_amount'], 2);
        $routeFeeAmount = round((float) $validated['route_fee_amount'], 2);
        $totalAmount = isset($validated['total_amount'])
            ? round((float) $validated['total_amount'], 2)
            : round($laborAmount + $routeFeeAmount, 2);
        $metadata = is_array($assignmentOffer->metadata) ? $assignmentOffer->metadata : [];
        $metadata['message_payload'] = $this->assignmentOfferMessagePayload($technicalServiceRequest, $assignmentOffer->technician, [
            'labor_amount' => $laborAmount,
            'route_fee_amount' => $routeFeeAmount,
            'total_amount' => $totalAmount,
            'currency' => $assignmentOffer->currency,
            'note' => $validated['note'] ?? null,
        ]);
        $metadata['revised_at'] = now()->toISOString();
        $metadata['revised_by_user_id'] = $request->user()?->id;
        $technicianPhone = $assignmentOffer->technician?->phone_e164
            ?: ($assignmentOffer->technician?->phone_display ?: $assignmentOffer->technician?->phone);
        $dispatch = $this->messages->send(
            'price_revision_response_technician',
            'technician',
            $technicianPhone,
            $this->assignmentOfferMessageText($metadata['message_payload'] ?? []),
            [
                ...(is_array($metadata['message_payload'] ?? null) ? $metadata['message_payload'] : []),
                'manual_ui_send' => true,
            ],
            $technicalServiceRequest,
            $request->user(),
            null,
            $assignmentOffer,
        );
        $metadata['message_dispatch'] = [
            'id' => $dispatch->id,
            'status' => $dispatch->status,
        ];

        $assignmentOffer->forceFill([
            'labor_amount' => $laborAmount,
            'route_fee_amount' => $routeFeeAmount,
            'total_amount' => $totalAmount,
            'status' => TechnicalServiceAssignmentOffer::STATUS_REVISED,
            'note' => $validated['note'] ?? null,
            'metadata' => $metadata,
        ])->save();

        $technicalServiceRequest->events()->create([
            'event_type' => 'assignment_offer_revised',
            'title' => 'Usta hakediÅŸ bilgisi revize edildi',
            'note' => $validated['note'] ?? null,
            'from_status' => $technicalServiceRequest->workflow_status,
            'to_status' => $technicalServiceRequest->workflow_status,
            'author_user_id' => $request->user()?->id,
            'metadata' => [
                'assignment_offer_id' => $assignmentOffer->id,
                'labor_amount' => $laborAmount,
                'route_fee_amount' => $routeFeeAmount,
                'total_amount' => $totalAmount,
                'message_payload' => $metadata['message_payload'],
            ],
        ]);

        return response()->json([
            'status' => 'revised',
            'request' => $this->workflow->serialize($technicalServiceRequest->refresh(), true),
        ]);
    }

    public function resendCustomerApprovalRequest(Request $request, TechnicalServiceRequest $technicalServiceRequest): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = DB::transaction(function () use ($technicalServiceRequest, $request, $validated): array {
            $job = $technicalServiceRequest->refresh();
            $partnerId = $this->partnerIdForRequest($job);

            if ($partnerId === null) {
                throw ValidationException::withMessages([
                    'partner' => 'Bu iş için aktif çilingir partner bağlantısı bulunamadı.',
                ]);
            }

            TechnicalServiceCustomerConfirmation::query()
                ->where('technical_service_request_id', $job->id)
                ->where('status', TechnicalServiceCustomerConfirmation::STATUS_PENDING)
                ->update(['status' => TechnicalServiceCustomerConfirmation::STATUS_CANCELLED]);

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

            $approvalUrl = PartnerPortalPublicUrl::route('service-job-confirmation.show', ['token' => $confirmation->token]);
            $publicUrlWarning = $this->messages->testMode() && PartnerPortalPublicUrl::isLocalUrl($approvalUrl)
                ? 'Onay linki lokal URL içeriyor; telefondan açılamaz. PARTNER_PORTAL_PUBLIC_URL ayarlanmalı.'
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
                    'provider' => 'evolution_n8n',
                    'confirmation_id' => $confirmation->id,
                    'approval_url' => $approvalUrl,
                    'confirmation_url' => $approvalUrl,
                    'requested_at' => now()->toISOString(),
                    'message_payload' => [
                        'recipient' => 'customer',
                        'channel' => 'evolution_n8n',
                        'mrn' => $job->mrn,
                        'customer_phone' => $job->customer_phone,
                        'message_text' => $messageText,
                        'approval_url' => $approvalUrl,
                        'confirmation_url' => $approvalUrl,
                        'public_url_warning' => $publicUrlWarning,
                    ],
                ],
            ]);

            $dispatch = $this->messages->send(
                'customer_approval_request',
                'customer',
                $job->customer_phone,
                $messageText,
                [
                    'confirmation_url' => $approvalUrl,
                    'approval_url' => $approvalUrl,
                    'confirmation_id' => $confirmation->id,
                    'public_url_warning' => $publicUrlWarning,
                    'force_resend' => true,
                    'message_type' => 'customer_approval_request',
                    'manual_ui_send' => true,
                ],
                $job,
                $request->user(),
                $action,
            );
            $dispatchSummary = $this->dispatchSummary($dispatch);
            $actionPayload = is_array($action->payload) ? $action->payload : [];
            $messagePayload = [
                ...($actionPayload['message_payload'] ?? []),
                ...$dispatchSummary,
                'dispatch_id' => $dispatch->id,
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
        $documents = $request->uploads()
            ->whereIn('category', [
                TechnicalServiceRequestUpload::CATEGORY_PARTNER_PORTAL_FIELD_DOCUMENT,
                TechnicalServiceRequestUpload::CATEGORY_OPERATION_CONTROL_DOOR_PHOTO,
            ])
            ->whereIn('field_code', ['before_photo', 'after_photo', 'warranty_document_photo'])
            ->get()
            ->groupBy('field_code');
        $labels = [
            'before_photo' => 'Öncesi fotoğrafı',
            'after_photo' => 'Sonrası fotoğrafı',
            'warranty_document_photo' => 'Garanti Belgesi',
        ];
        $blockers = [];

        foreach ($labels as $field => $label) {
            $fieldDocuments = $documents->get($field, collect());
            if ($fieldDocuments->isEmpty()) {
                $blockers[] = $label.' eksik.';

                continue;
            }

            if ($fieldDocuments->contains(fn (TechnicalServiceRequestUpload $upload): bool => $upload->review_status === 'rejected')) {
                $blockers[] = $label.' uygun değil.';

                continue;
            }

            if (! $fieldDocuments->contains(fn (TechnicalServiceRequestUpload $upload): bool => $upload->review_status === 'accepted')) {
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
                'partner_job_action' => 'Bu kayÄ±t randevu Ã¶nerisi deÄŸildir.',
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
                'selected_slot_index' => 'Onaylanacak randevu saati bulunamadÃ„Â±.',
            ]);
        }

        return $slots[$index];
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

        return [
            'customer' => [
                'channel' => 'system_payload',
                'recipient' => 'customer',
                'mrn' => $request->mrn,
                'product_model' => collect([$request->product_name, $request->product_model])->filter()->implode(' / '),
                'appointment_date' => $request->scheduled_date?->toDateString(),
                'appointment_time_range' => $timeRange !== '-' ? $timeRange : null,
                'slot_text' => $slotText,
                'message_text' => trim("{$customerPrefix} {$request->scheduled_date?->format('d.m.Y')} tarihinde {$slotText} için planlandı. Emaks Prime operasyon ekibi."),
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
            ],
        ];
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
            'morning' => 'Ã¶ÄŸleden Ã¶nce',
            'afternoon' => 'Ã¶ÄŸleden sonra',
            'full_day' => 'gÃ¼n iÃ§inde',
            'custom' => trim(($proposal['proposed_time_start'] ?? '').' - '.($proposal['proposed_time_end'] ?? '')) ?: 'Ã¶zel saat',
            default => 'gÃ¼n iÃ§inde',
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
            'labor_amount' => round((float) ($amounts['labor_amount'] ?? 0), 2),
            'route_fee_amount' => round((float) ($amounts['route_fee_amount'] ?? 0), 2),
            'total_amount' => round((float) ($amounts['total_amount'] ?? 0), 2),
            'currency' => $amounts['currency'] ?? 'TRY',
            'note' => $amounts['note'] ?? null,
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
        if ($request->technical_service_technician_id !== null) {
            $link = B2BPartnerTechnician::query()
                ->active()
                ->where('technical_service_technician_id', $request->technical_service_technician_id)
                ->whereIn('relationship_type', ['owner', 'field_technician'])
                ->orderByDesc('is_primary')
                ->latest('id')
                ->first();

            if ($link instanceof B2BPartnerTechnician) {
                return (int) $link->partner_id;
            }
        }

        $action = $request->partnerJobActions()
            ->whereNotNull('partner_id')
            ->latest('id')
            ->first();

        return $action instanceof TechnicalServicePartnerJobAction
            ? (int) $action->partner_id
            : null;
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
            'dispatch_provider' => 'evolution_n8n',
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

        if (($summary['dispatch_status'] ?? null) === TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_DUPLICATE) {
            return 'Bu mesaj daha önce gönderildi; tekrar WhatsApp gönderilmedi.';
        }

        if (($summary['dispatch_status'] ?? null) === TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_RATE_LIMITED) {
            return 'WhatsApp gönderimi güvenlik limiti nedeniyle bastırıldı. Biraz sonra tekrar deneyin.';
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
