<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServicePartnerJobAction;
use App\Models\TechnicalServicePartRequest;
use App\Models\TechnicalServiceRequest;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class TechnicalServiceOperationalStatePresenter
{
    private const OPS_COLUMN_NEW = 'new';
    private const OPS_COLUMN_ASSIGNMENT_PENDING = 'assignment_pending';
    private const OPS_COLUMN_ASSIGNED = 'assigned';
    private const OPS_COLUMN_FINAL_CHECK = 'final_check';
    private const OPS_COLUMN_COMPLETED = 'completed';
    private const OPS_COLUMN_REVIEW = 'review';
    private const OPS_COLUMN_CANCELLED = 'cancelled';

    private const PARTNER_COLUMN_NEW = 'new_jobs';
    private const PARTNER_COLUMN_APPOINTMENT_CONFIRMED = 'appointment_confirmed';
    private const PARTNER_COLUMN_OPS_REVIEW = 'ops_review';
    private const PARTNER_COLUMN_REVISIT = 'revisit';
    private const PARTNER_COLUMN_FINAL_CHECK = 'final_check';
    private const PARTNER_COLUMN_COMPLETED = 'completed';

    private const COMPLETED_STATUSES = ['Tamamlandı', 'Tamamlandi'];
    private const CANCELLED_STATUSES = ['İptal', 'Iptal'];
    private const TECHNICIAN_APPROVAL_STATUSES = ['Usta Onayı Bekleyen'];
    private const APPOINTMENT_CONFIRMED_STATUSES = [
        'Planlı',
        'Yolda',
        'Sahada',
        'Belge / Fotoğraf Bekleyen',
        'Müşteri Kapanış Onayı Bekleyen',
    ];
    private const REVISIT_STATUSES = [
        'Beklemede',
        'Müşteri Yerinde Yok',
        'Montaj Yeri Hazır Değil',
        'Parça Bekleniyor',
        'Usta Tarih Revize Talebi',
    ];

    /**
     * @return array<string, mixed>
     */
    public function present(TechnicalServiceRequest $request): array
    {
        $request->loadMissing([
            'partnerJobActions' => fn ($query) => $query->latest(),
            'events' => fn ($query) => $query->latest(),
            'uploads',
            'customerConfirmations' => fn ($query) => $query->latest(),
            'partRequests' => fn ($query) => $query->latest(),
            'childRequests',
        ]);

        $hasDelegatedServiceVisit = $this->hasNonCancelledServiceVisitChild($request);
        $activeAction = $hasDelegatedServiceVisit ? null : $this->activeOpsReviewAction($request);
        $activePartRequest = $hasDelegatedServiceVisit ? null : $this->activePartRequest($request);
        $doorIncompatible = ! $hasDelegatedServiceVisit && $this->doorIncompatible($request);
        $isCancelled = $this->isCancelled($request);
        $isCompleted = $this->isCompleted($request);
        $isPendingFinalCheck = ! $isCompleted && $this->hasCompletionSubmitted($request);
        $isAppointmentConfirmed = ! $isCompleted
            && ! $isPendingFinalCheck
            && $this->isAppointmentConfirmed($request);
        $appointmentAttention = $this->appointmentAttention($request, $isCompleted, $isPendingFinalCheck, $isCancelled, $isAppointmentConfirmed);

        $opsColumn = $this->opsColumn(
            $request,
            $activeAction,
            $isCancelled,
            $isCompleted,
            $isPendingFinalCheck,
            $isAppointmentConfirmed,
            $doorIncompatible,
            $activePartRequest,
        );
        $partnerColumn = $this->partnerColumn(
            $request,
            $activeAction,
            $isCancelled,
            $isCompleted,
            $isPendingFinalCheck,
            $isAppointmentConfirmed,
            $doorIncompatible,
            $activePartRequest,
        );

        $attention = $this->attention($request, $activeAction, $activePartRequest, $appointmentAttention, $opsColumn, $isCompleted, $isCancelled, $doorIncompatible);
        $displayActionLabel = $this->displayActionLabel($request, $activeAction, $activePartRequest, $attention, $opsColumn, $isCompleted);
        $isCustomerApprovalRequired = $this->customerApprovalRequired($request, $opsColumn, $isCompleted);
        $isFieldDocsRequired = $this->fieldDocumentsRequired($request, $opsColumn, $isCompleted);
        $actionMeta = $this->actionMeta(
            $request,
            $activeAction,
            $activePartRequest,
            $attention,
            $opsColumn,
            $displayActionLabel,
            $isCompleted,
            $isCancelled,
            $isPendingFinalCheck,
            $isAppointmentConfirmed,
            $isCustomerApprovalRequired,
            $isFieldDocsRequired,
            $doorIncompatible,
        );

        return [
            'canonical_stage' => $opsColumn,
            'ops_column' => $opsColumn,
            'partner_column' => $partnerColumn,
            'display_status_label' => $this->displayStatusLabel($opsColumn),
            'display_action_label' => $displayActionLabel,
            'display_tags' => $this->displayTags($request, $displayActionLabel, $opsColumn, $attention, $actionMeta, $isCompleted),
            'attention_level' => $attention['attention_level'],
            'attention_reason' => $attention['attention_reason'],
            'sort_priority' => $attention['sort_priority'],
            'last_action_at' => $attention['last_action_at'],
            'active_action_required' => $actionMeta['action_owner'] !== 'none',
            'action_owner' => $actionMeta['action_owner'],
            'action_priority' => $actionMeta['action_priority'],
            'requires_ops_action' => $actionMeta['requires_ops_action'],
            'requires_technician_action' => $actionMeta['requires_technician_action'],
            'requires_customer_action' => $actionMeta['requires_customer_action'],
            'action_label' => $actionMeta['action_label'],
            'action_hint' => $actionMeta['action_hint'],
            'allowed_ops_actions' => [],
            'allowed_technician_actions' => [],
            'is_completed' => $isCompleted,
            'is_pending_final_check' => $isPendingFinalCheck,
            'is_appointment_confirmed' => $isAppointmentConfirmed,
            'is_customer_approval_required' => $isCustomerApprovalRequired,
            'is_field_docs_required' => $isFieldDocsRequired,
            'is_assignment_review_required' => $opsColumn === self::OPS_COLUMN_ASSIGNMENT_PENDING,
            'active_part_request_id' => $activePartRequest?->id,
            'active_part_request_status' => $activePartRequest?->status,
            'attention' => $attention,
        ];
    }

    private function opsColumn(
        TechnicalServiceRequest $request,
        ?TechnicalServicePartnerJobAction $activeAction,
        bool $isCancelled,
        bool $isCompleted,
        bool $isPendingFinalCheck,
        bool $isAppointmentConfirmed,
        bool $doorIncompatible,
        ?TechnicalServicePartRequest $activePartRequest,
    ): string {
        if ($isCancelled) {
            return self::OPS_COLUMN_CANCELLED;
        }

        if ($isCompleted) {
            return self::OPS_COLUMN_COMPLETED;
        }

        if ($isPendingFinalCheck) {
            return self::OPS_COLUMN_FINAL_CHECK;
        }

        if ($activePartRequest instanceof TechnicalServicePartRequest) {
            return self::OPS_COLUMN_REVIEW;
        }

        if ($activeAction instanceof TechnicalServicePartnerJobAction) {
            return match ($activeAction->action) {
                TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED => self::OPS_COLUMN_ASSIGNMENT_PENDING,
                TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED => self::OPS_COLUMN_ASSIGNMENT_PENDING,
                TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_CHANGE_REQUESTED => self::OPS_COLUMN_ASSIGNMENT_PENDING,
                TechnicalServicePartnerJobAction::ACTION_CUSTOMER_APPROVAL_REJECTED,
                TechnicalServicePartnerJobAction::ACTION_PRICE_REVISION_REQUESTED,
                TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED,
                TechnicalServicePartnerJobAction::ACTION_REVISIT_REQUESTED => self::OPS_COLUMN_REVIEW,
                default => self::OPS_COLUMN_REVIEW,
            };
        }

        if ($doorIncompatible) {
            return self::OPS_COLUMN_REVIEW;
        }

        if ($isAppointmentConfirmed || $this->technicianApproved($request)) {
            return self::OPS_COLUMN_ASSIGNED;
        }

        if ($this->hasTechnician($request) || $this->hasAppointment($request)) {
            return self::OPS_COLUMN_ASSIGNMENT_PENDING;
        }

        return self::OPS_COLUMN_NEW;
    }

    private function partnerColumn(
        TechnicalServiceRequest $request,
        ?TechnicalServicePartnerJobAction $activeAction,
        bool $isCancelled,
        bool $isCompleted,
        bool $isPendingFinalCheck,
        bool $isAppointmentConfirmed,
        bool $doorIncompatible,
        ?TechnicalServicePartRequest $activePartRequest,
    ): string {
        if ($isCompleted) {
            return self::PARTNER_COLUMN_COMPLETED;
        }

        if ($isPendingFinalCheck) {
            return self::PARTNER_COLUMN_FINAL_CHECK;
        }

        if ($doorIncompatible) {
            return self::PARTNER_COLUMN_OPS_REVIEW;
        }

        if ($activePartRequest instanceof TechnicalServicePartRequest) {
            return self::PARTNER_COLUMN_OPS_REVIEW;
        }

        if ($activeAction instanceof TechnicalServicePartnerJobAction
            && $activeAction->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW
        ) {
            return match ($activeAction->action) {
                TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED,
                TechnicalServicePartnerJobAction::ACTION_CUSTOMER_APPROVAL_REJECTED,
                TechnicalServicePartnerJobAction::ACTION_PRICE_REVISION_REQUESTED,
                TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_CHANGE_REQUESTED,
                TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED,
                TechnicalServicePartnerJobAction::ACTION_REVISIT_REQUESTED => self::PARTNER_COLUMN_OPS_REVIEW,
                default => self::PARTNER_COLUMN_NEW,
            };
        }

        if (
            ! $this->hasNonCancelledServiceVisitChild($request)
            && (
                (
                    $activeAction?->action === TechnicalServicePartnerJobAction::ACTION_REVISIT_REQUESTED
                    && $activeAction->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW
                )
                || (bool) $request->requires_second_visit
                || $this->statusIn($request->workflow_status, self::REVISIT_STATUSES)
            )
        ) {
            return self::PARTNER_COLUMN_REVISIT;
        }

        if ($isAppointmentConfirmed && ! $isCancelled) {
            return self::PARTNER_COLUMN_APPOINTMENT_CONFIRMED;
        }

        return self::PARTNER_COLUMN_NEW;
    }

    private function activeOpsReviewAction(TechnicalServiceRequest $request): ?TechnicalServicePartnerJobAction
    {
        if ($this->hasNonCancelledServiceVisitChild($request)) {
            return null;
        }

        $opsReview = $request->partnerJobActions
            ->filter(fn (TechnicalServicePartnerJobAction $action): bool => $action->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW
                && ! $this->actionResolvedForNewWork($action)
                && ! $this->actionPredatesActiveReopen($request, $action));

        foreach ([
            TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED,
            TechnicalServicePartnerJobAction::ACTION_CUSTOMER_APPROVAL_REJECTED,
            TechnicalServicePartnerJobAction::ACTION_PRICE_REVISION_REQUESTED,
            TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED,
            TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_CHANGE_REQUESTED,
            TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED,
            TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED,
            TechnicalServicePartnerJobAction::ACTION_REVISIT_REQUESTED,
        ] as $actionType) {
            $action = $opsReview->firstWhere('action', $actionType);

            if ($action instanceof TechnicalServicePartnerJobAction) {
                return $action;
            }
        }

        return null;
    }

    private function activePartRequest(TechnicalServiceRequest $request): ?TechnicalServicePartRequest
    {
        if ($this->hasNonCancelledServiceVisitChild($request)) {
            return null;
        }

        return $request->partRequests
            ->first(fn (TechnicalServicePartRequest $partRequest): bool => $partRequest->isActive());
    }

    private function hasNonCancelledServiceVisitChild(TechnicalServiceRequest $request): bool
    {
        if ($request->parent_request_id !== null) {
            return false;
        }

        $request->loadMissing('childRequests');

        return $request->childRequests
            ->contains(fn (TechnicalServiceRequest $child): bool => ! $this->isCancelled($child));
    }

    private function hasCompletionSubmitted(TechnicalServiceRequest $request): bool
    {
        return $request->partnerJobActions
            ->contains(function (TechnicalServicePartnerJobAction $action) use ($request): bool {
                if ($action->action !== TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED
                    || $this->actionResolvedForNewWork($action)
                    || $this->actionPredatesActiveReopen($request, $action)
                ) {
                    return false;
                }

                if (in_array($action->status, [
                    TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW,
                    TechnicalServicePartnerJobAction::STATUS_SUBMITTED,
                ], true)) {
                    return true;
                }

                if ($action->status !== TechnicalServicePartnerJobAction::STATUS_APPLIED) {
                    return false;
                }

                $payload = is_array($action->payload) ? $action->payload : [];

                return (bool) ($payload['ops_final_check_required'] ?? false)
                    && ! isset($payload['ops_final_check']);
            });
    }

    private function actionResolvedForNewWork(TechnicalServicePartnerJobAction $action): bool
    {
        $payload = is_array($action->payload) ? $action->payload : [];

        return (bool) ($payload['resolved_by_reassignment'] ?? false)
            || isset($payload['service_visit_created']);
    }

    private function actionPredatesActiveReopen(TechnicalServiceRequest $request, TechnicalServicePartnerJobAction $action): bool
    {
        if ($request->reopened_at === null) {
            return false;
        }

        $actionAt = $action->created_at ?? $action->updated_at;

        return $actionAt instanceof CarbonInterface
            && $actionAt->lessThanOrEqualTo($request->reopened_at);
    }

    private function isCompleted(TechnicalServiceRequest $request): bool
    {
        if ($this->isActiveReopenedWork($request)) {
            return false;
        }

        if ($request->completed_at !== null || $request->installation_completed_at !== null) {
            return true;
        }

        if (! $this->statusIn($request->workflow_status, self::COMPLETED_STATUSES)
            && ! $this->statusIn($request->status, self::COMPLETED_STATUSES)) {
            return false;
        }

        return $request->events->contains(function ($event): bool {
            $eventType = $this->normalize((string) ($event->event_type ?? ''));

            return in_array($eventType, [
                'field_completed',
                'partner_completion_approved',
                'product_warranty_start_checked',
            ], true);
        });
    }

    private function isCancelled(TechnicalServiceRequest $request): bool
    {
        if ($this->isActiveReopenedWork($request)) {
            return false;
        }

        return $request->cancelled_at !== null
            || $this->statusIn($request->workflow_status, self::CANCELLED_STATUSES)
            || $this->statusIn($request->status, self::CANCELLED_STATUSES);
    }

    private function isActiveReopenedWork(TechnicalServiceRequest $request): bool
    {
        if ($request->reopened_at === null) {
            return false;
        }

        return ! $this->statusIn($request->workflow_status, self::COMPLETED_STATUSES)
            && ! $this->statusIn($request->status, self::COMPLETED_STATUSES)
            && ! $this->statusIn($request->workflow_status, self::CANCELLED_STATUSES)
            && ! $this->statusIn($request->status, self::CANCELLED_STATUSES);
    }

    private function doorIncompatible(TechnicalServiceRequest $request): bool
    {
        $payload = is_array($request->operation_control_payload) ? $request->operation_control_payload : [];

        return ($payload['door_photos_checked'] ?? null) === 'incompatible';
    }

    private function supportAttentionReason(TechnicalServicePartnerJobAction $action): string
    {
        $payload = is_array($action->payload) ? $action->payload : [];

        return match ((string) ($payload['type'] ?? '')) {
            'technical_support' => 'Servis destek talep etti',
            'spare_part' => 'Parça talebi incelenmeli',
            default => 'Ek talep var',
        };
    }

    private function partRequestAttentionReason(TechnicalServicePartRequest $partRequest): string
    {
        return TechnicalServicePartRequest::labelForStatus((string) $partRequest->status);
    }

    private function isAppointmentConfirmed(TechnicalServiceRequest $request): bool
    {
        return $this->statusIn($request->workflow_status, self::APPOINTMENT_CONFIRMED_STATUSES)
            || ($this->hasAppointment($request) && $this->technicianApproved($request));
    }

    private function hasAppointment(TechnicalServiceRequest $request): bool
    {
        return $request->scheduled_at !== null
            || ($request->scheduled_date !== null && filled($request->scheduled_time));
    }

    private function hasTechnician(TechnicalServiceRequest $request): bool
    {
        return filled($request->technical_service_technician_id) || filled($request->technician_name);
    }

    private function technicianApproved(TechnicalServiceRequest $request): bool
    {
        if ($request->technician_approved_at !== null) {
            return true;
        }

        $approvalStatus = $this->normalize((string) $request->technician_approval_status);
        $confirmationStatus = $this->normalize((string) $request->technician_confirmation_status);

        return str_contains($approvalStatus, 'onay')
            || str_contains($approvalStatus, 'kabul')
            || str_contains($approvalStatus, 'accept')
            || str_contains($confirmationStatus, 'onay')
            || str_contains($confirmationStatus, 'accept');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function appointmentAttention(
        TechnicalServiceRequest $request,
        bool $isCompleted,
        bool $isPendingFinalCheck,
        bool $isCancelled,
        bool $isAppointmentConfirmed,
    ): ?array {
        if ($isCompleted || $isPendingFinalCheck || $isCancelled || ! $isAppointmentConfirmed) {
            return null;
        }

        $startAt = $this->appointmentStartAt($request);
        if (! $startAt instanceof CarbonImmutable || $startAt->isFuture()) {
            return null;
        }

        if (CarbonImmutable::now()->greaterThanOrEqualTo($startAt->addHours(12))) {
            return [
                'sort_priority' => 1,
                'attention_level' => 'critical',
                'attention_reason' => 'İş kapanışı için usta ile iletişime geçin',
                'last_action_at' => $startAt->toDateTimeString(),
                'action' => 'appointment_overdue_for_closure',
            ];
        }

        return [
            'sort_priority' => 8,
            'attention_level' => 'info',
            'attention_reason' => 'Usta müşteride',
            'last_action_at' => $startAt->toDateTimeString(),
            'action' => 'appointment_in_progress',
        ];
    }

    /**
     * @param array<string, mixed>|null $appointmentAttention
     * @return array<string, mixed>
     */
    private function attention(
        TechnicalServiceRequest $request,
        ?TechnicalServicePartnerJobAction $activeAction,
        ?TechnicalServicePartRequest $activePartRequest,
        ?array $appointmentAttention,
        string $opsColumn,
        bool $isCompleted,
        bool $isCancelled,
        bool $doorIncompatible,
    ): array {
        if ($isCompleted || $isCancelled) {
            return $this->normalAttention($request, 100);
        }

        if (($appointmentAttention['sort_priority'] ?? null) === 1) {
            return $appointmentAttention;
        }

        if ($doorIncompatible) {
            return [
                'sort_priority' => 4,
                'attention_level' => 'critical',
                'attention_reason' => 'Kapı uyumsuzluğu incelenmeli',
                'last_action_at' => $request->operation_control_checked_at?->toDateTimeString() ?? $request->updated_at?->toDateTimeString(),
                'action' => 'door_incompatible',
            ];
        }

        if ($activePartRequest instanceof TechnicalServicePartRequest) {
            $payload = match ((string) $activePartRequest->status) {
                TechnicalServicePartRequest::STATUS_REQUESTED,
                TechnicalServicePartRequest::STATUS_OPS_REVIEW => [4, 'warning'],
                TechnicalServicePartRequest::STATUS_SERVICE_VISIT_REQUIRED => [4, 'critical'],
                TechnicalServicePartRequest::STATUS_SENT,
                TechnicalServicePartRequest::STATUS_RECEIVED => [8, 'warning'],
                default => [9, 'warning'],
            };

            return [
                'sort_priority' => $payload[0],
                'attention_level' => $payload[1],
                'attention_reason' => $this->partRequestAttentionReason($activePartRequest),
                'last_action_at' => $activePartRequest->updated_at?->toDateTimeString(),
                'action' => 'part_request_'.$activePartRequest->status,
            ];
        }

        if ($activeAction instanceof TechnicalServicePartnerJobAction) {
            $payload = match ($activeAction->action) {
                TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED => [2, 'critical', 'Usta işi reddetti'],
                TechnicalServicePartnerJobAction::ACTION_CUSTOMER_APPROVAL_REJECTED => [3, 'critical', 'Müşteri onayı reddedildi'],
                TechnicalServicePartnerJobAction::ACTION_PRICE_REVISION_REQUESTED => [4, 'critical', 'Hakediş revize talebi'],
                TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED => [5, 'warning', 'Son kontrol bekliyor'],
                TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED => [6, 'warning', 'Usta randevu önerdi'],
                TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED => [9, 'warning', 'Ek talep var'],
                TechnicalServicePartnerJobAction::ACTION_REVISIT_REQUESTED => [9, 'warning', 'Tekrar ziyaret talebi'],
                default => [20, 'warning', 'Operasyon incelemesi'],
            };

            $payload = match ($activeAction->action) {
                TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED => [2, 'critical', 'Usta işi reddetti - yeni usta atayın'],
                TechnicalServicePartnerJobAction::ACTION_CUSTOMER_APPROVAL_REJECTED => [3, 'critical', 'Müşteri onayı reddedildi'],
                TechnicalServicePartnerJobAction::ACTION_PRICE_REVISION_REQUESTED => [4, 'critical', 'Hakediş revize talebi'],
                TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED => [5, 'warning', 'Son kontrol bekliyor'],
                TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED => [6, 'warning', 'Usta randevu önerdi'],
                TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_CHANGE_REQUESTED => [7, 'warning', 'Usta randevu değişikliği istiyor'],
                TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED => [9, 'warning', $this->supportAttentionReason($activeAction)],
                TechnicalServicePartnerJobAction::ACTION_REVISIT_REQUESTED => [9, 'warning', 'Tekrar ziyaret talebi incelenmeli'],
                default => $payload,
            };

            return [
                'sort_priority' => $payload[0],
                'attention_level' => $payload[1],
                'attention_reason' => $payload[2],
                'last_action_at' => $activeAction->created_at?->toDateTimeString(),
                'action' => $activeAction->action,
            ];
        }

        if ($appointmentAttention !== null) {
            return $appointmentAttention;
        }

        if ($this->fieldDocumentsRequired($request, $opsColumn, false)) {
            return [
                'sort_priority' => 11,
                'attention_level' => 'warning',
                'attention_reason' => 'Fotoğraf eksik',
                'last_action_at' => $request->updated_at?->toDateTimeString(),
                'action' => 'field_docs_required',
            ];
        }

        if ($this->customerApprovalRequired($request, $opsColumn, false)) {
            return [
                'sort_priority' => 10,
                'attention_level' => 'warning',
                'attention_reason' => 'Müşteri onayı bekliyor',
                'last_action_at' => $request->updated_at?->toDateTimeString(),
                'action' => 'customer_approval_required',
            ];
        }

        return $this->normalAttention($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalAttention(TechnicalServiceRequest $request, int $priority = 12): array
    {
        return [
            'sort_priority' => $priority,
            'attention_level' => 'normal',
            'attention_reason' => null,
            'last_action_at' => $request->updated_at?->toDateTimeString(),
            'action' => null,
        ];
    }

    /**
     * @param array<string, mixed> $attention
     */
    private function displayActionLabel(
        TechnicalServiceRequest $request,
        ?TechnicalServicePartnerJobAction $activeAction,
        ?TechnicalServicePartRequest $activePartRequest,
        array $attention,
        string $opsColumn,
        bool $isCompleted,
    ): string {
        if ($isCompleted) {
            return 'Tamamlandı';
        }

        if (($attention['attention_reason'] ?? null) !== null) {
            return (string) $attention['attention_reason'];
        }

        if ($activePartRequest instanceof TechnicalServicePartRequest) {
            return $this->partRequestAttentionReason($activePartRequest);
        }

        if ($activeAction instanceof TechnicalServicePartnerJobAction) {
            return match ($activeAction->action) {
                TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED => 'Usta randevu önerdi',
                TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED => 'Usta işi reddetti',
                TechnicalServicePartnerJobAction::ACTION_CUSTOMER_APPROVAL_REJECTED => 'Müşteri onayı reddedildi',
                TechnicalServicePartnerJobAction::ACTION_PRICE_REVISION_REQUESTED => 'Hakediş revize talebi',
                TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED => 'Son kontrol bekliyor',
                TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED => 'Ek talep var',
                TechnicalServicePartnerJobAction::ACTION_REVISIT_REQUESTED => 'Tekrar ziyaret talebi',
                default => 'Operasyon incelemesi',
            };
        }

        return match ($opsColumn) {
            self::OPS_COLUMN_COMPLETED => 'Tamamlandı',
            self::OPS_COLUMN_FINAL_CHECK => 'Son kontrol bekliyor',
            self::OPS_COLUMN_ASSIGNED => 'Randevu onaylandı',
            self::OPS_COLUMN_ASSIGNMENT_PENDING => $this->hasTechnician($request) ? 'Usta onayı bekliyor' : 'Usta seçilmeli',
            self::OPS_COLUMN_NEW => 'Usta seçilmeli',
            self::OPS_COLUMN_REVIEW => 'Operasyon incelemesi',
            self::OPS_COLUMN_CANCELLED => 'İptal',
            default => 'Operasyon kontrolü',
        };
    }

    /**
     * @param array<string, mixed> $attention
     * @return array<string, mixed>
     */
    private function actionMeta(
        TechnicalServiceRequest $request,
        ?TechnicalServicePartnerJobAction $activeAction,
        ?TechnicalServicePartRequest $activePartRequest,
        array $attention,
        string $opsColumn,
        string $displayActionLabel,
        bool $isCompleted,
        bool $isCancelled,
        bool $isPendingFinalCheck,
        bool $isAppointmentConfirmed,
        bool $isCustomerApprovalRequired,
        bool $isFieldDocsRequired,
        bool $doorIncompatible,
    ): array {
        $action = (string) ($attention['action'] ?? '');

        if ($isCompleted || $isCancelled) {
            return $this->actionMetaPayload('none', 'low', $displayActionLabel, null);
        }

        if ($action === 'appointment_overdue_for_closure') {
            return $this->actionMetaPayload('ops', 'critical', $displayActionLabel, 'Randevu saati geçti. Operasyon usta ile kapanışı netleştirmeli.');
        }

        if ($doorIncompatible || $action === 'door_incompatible') {
            return $this->actionMetaPayload('ops', 'critical', $displayActionLabel, 'Kapı görselleri uyumsuz işaretlendi. Operasyon karar vermeli.');
        }

        if ($activePartRequest instanceof TechnicalServicePartRequest) {
            return match ((string) $activePartRequest->status) {
                TechnicalServicePartRequest::STATUS_REQUESTED,
                TechnicalServicePartRequest::STATUS_OPS_REVIEW => $this->actionMetaPayload('ops', 'high', $displayActionLabel, 'Usta yedek parça talep etti. Operasyon karar vermeli.'),
                TechnicalServicePartRequest::STATUS_APPROVED,
                TechnicalServicePartRequest::STATUS_ORDERED => $this->actionMetaPayload('ops', 'normal', $displayActionLabel, 'Parça tedarik ve gönderim bilgisi operasyon tarafında takip edilmeli.'),
                TechnicalServicePartRequest::STATUS_SERVICE_VISIT_REQUIRED => $this->actionMetaPayload('ops', 'critical', $displayActionLabel, 'Parça sonrası servis kaydı operasyon tarafında oluşturulmalı.'),
                TechnicalServicePartRequest::STATUS_SENT => $this->actionMetaPayload('technician', 'normal', $displayActionLabel, 'Parça gönderildi. Ustanın teslim aldım demesi bekleniyor.'),
                TechnicalServicePartRequest::STATUS_RECEIVED => $this->actionMetaPayload('system', 'low', $displayActionLabel, 'Parça teslim alındı. Operasyon aksiyonu yok.'),
                default => $this->actionMetaPayload('ops', 'normal', $displayActionLabel, 'Parça talebi operasyon takibinde.'),
            };
        }

        if ($activeAction instanceof TechnicalServicePartnerJobAction) {
            return match ($activeAction->action) {
                TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED => $this->actionMetaPayload('ops', 'critical', $displayActionLabel, 'Usta işi reddetti. Operasyon yeniden atama veya iptal kararı vermeli.'),
                TechnicalServicePartnerJobAction::ACTION_CUSTOMER_APPROVAL_REJECTED => $this->actionMetaPayload('ops', 'critical', $displayActionLabel, 'Müşteri onay vermedi. Operasyon inceleyip yeniden aksiyon almalı.'),
                TechnicalServicePartnerJobAction::ACTION_PRICE_REVISION_REQUESTED => $this->actionMetaPayload('ops', 'critical', $displayActionLabel, 'Usta hakediş revize talep etti. Operasyon yanıtlamalı.'),
                TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED => $this->actionMetaPayload('ops', 'high', $displayActionLabel, 'Usta tamamlamaya gönderdi. Operasyon son kontrol yapmalı.'),
                TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED => $this->actionMetaPayload('ops', 'high', $displayActionLabel, 'Usta randevu önerdi. Operasyon randevuyu onaylamalı.'),
                TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_CHANGE_REQUESTED => $this->actionMetaPayload('ops', 'high', $displayActionLabel, 'Usta randevu değişikliği istiyor. Operasyon karar vermeli.'),
                TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED => $this->actionMetaPayload('ops', 'high', $displayActionLabel, 'Usta ek destek talep etti. Operasyon incelemeli.'),
                TechnicalServicePartnerJobAction::ACTION_REVISIT_REQUESTED => $this->actionMetaPayload('ops', 'high', $displayActionLabel, 'Usta tekrar ziyaret istedi. Operasyon karar vermeli.'),
                default => $this->actionMetaPayload('ops', 'normal', $displayActionLabel, 'Operasyon incelemesi bekleniyor.'),
            };
        }

        if ($isPendingFinalCheck || $opsColumn === self::OPS_COLUMN_FINAL_CHECK) {
            return $this->actionMetaPayload('ops', 'high', $displayActionLabel, 'Usta tamamlamaya gönderdi. Operasyon son kontrol yapmalı.');
        }

        if ($opsColumn === self::OPS_COLUMN_NEW) {
            return $this->actionMetaPayload('ops', 'normal', $displayActionLabel, 'Yeni talep operasyon ataması bekliyor.');
        }

        if ($opsColumn === self::OPS_COLUMN_ASSIGNMENT_PENDING) {
            if ($this->hasTechnician($request)) {
                return $this->actionMetaPayload('technician', 'normal', $displayActionLabel, 'Ustanın işi kabul etmesi bekleniyor.');
            }

            return $this->actionMetaPayload('ops', 'normal', $displayActionLabel, 'Talebe usta seçilmeli.');
        }

        if ($action === 'appointment_in_progress') {
            return $this->actionMetaPayload('technician', 'normal', $displayActionLabel, 'Randevu zamanı geldi. Saha aksiyonu ustada.');
        }

        if ($isFieldDocsRequired) {
            return $this->actionMetaPayload('technician', 'normal', 'Fotoğraf bekleniyor', 'Usta saha fotoğraflarını yüklemeli.');
        }

        if ($isCustomerApprovalRequired) {
            return $this->actionMetaPayload('customer', 'normal', 'Müşteri onayı bekliyor', 'Müşteri onayı bekleniyor.');
        }

        if ($isAppointmentConfirmed || $opsColumn === self::OPS_COLUMN_ASSIGNED) {
            return $this->actionMetaPayload('technician', 'normal', $displayActionLabel, 'Usta fotoğrafları ve müşteri onayını tamamlayacak.');
        }

        return $this->actionMetaPayload('none', 'low', $displayActionLabel, null);
    }

    /**
     * @return array<string, mixed>
     */
    private function actionMetaPayload(string $owner, string $priority, string $label, ?string $hint): array
    {
        return [
            'action_owner' => $owner,
            'action_priority' => $priority,
            'requires_ops_action' => $owner === 'ops',
            'requires_technician_action' => $owner === 'technician',
            'requires_customer_action' => $owner === 'customer',
            'action_label' => $label,
            'action_hint' => $hint,
        ];
    }

    /**
     * @param array<string, mixed> $attention
     * @param array<string, mixed> $actionMeta
     * @return array<int, array<string, mixed>>
     */
    private function displayTags(
        TechnicalServiceRequest $request,
        string $displayActionLabel,
        string $opsColumn,
        array $attention,
        array $actionMeta,
        bool $isCompleted,
    ): array {
        $tags = [];

        $add = function (string $label, string $tone = 'blue', bool $important = false) use (&$tags): void {
            if (! collect($tags)->contains(fn (array $tag): bool => $tag['label'] === $label)) {
                $tags[] = [
                    'label' => $label,
                    'tone' => $tone,
                    'icon' => $important ? 'warning' : null,
                    'important' => $important,
                ];
            }
        };

        if ($isCompleted) {
            $add('Tamamlandı', 'green');

            return $tags;
        }

        if (($attention['attention_level'] ?? 'normal') !== 'normal') {
            $level = (string) $attention['attention_level'];
            $isOpsAction = ($actionMeta['action_owner'] ?? null) === 'ops';
            if (($attention['action'] ?? null) === TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED) {
                if ($isOpsAction) {
                    $add('OPS aksiyonu: Usta randevu önerdi', 'amber', true);
                    $add('Randevuyu onaylayın', 'blue');

                    return $tags;
                }

                $add('Usta randevu önerdi', 'amber', true);
                $add('Randevuyu onaylayın', 'blue');

                return $tags;
            }
            if ($isOpsAction) {
                $add('OPS aksiyonu: '.$displayActionLabel, $level === 'critical' ? 'rose' : 'amber', true);
            } else {
                $add($displayActionLabel, $level === 'info' ? 'blue' : 'neutral', false);
            }

            return $tags;
        }

        if ($opsColumn === self::OPS_COLUMN_FINAL_CHECK) {
            $add('OPS aksiyonu: Son kontrol bekliyor', 'purple', true);

            return $tags;
        }

        if ($opsColumn === self::OPS_COLUMN_ASSIGNED) {
            $add('Randevu onaylandı', 'blue');

            return $tags;
        }

        if ($opsColumn === self::OPS_COLUMN_ASSIGNMENT_PENDING) {
            if ($this->hasTechnician($request)) {
                $add('Usta onayı bekliyor', 'neutral');
            } else {
                $add('OPS aksiyonu: Usta seçilmeli', 'amber', true);
            }

            return $tags;
        }

        if ($opsColumn === self::OPS_COLUMN_NEW) {
            $add('OPS aksiyonu: Usta seçilmeli', 'amber', true);
        }

        return $tags;
    }

    private function displayStatusLabel(string $opsColumn): string
    {
        return match ($opsColumn) {
            self::OPS_COLUMN_NEW => 'Yeni',
            self::OPS_COLUMN_ASSIGNMENT_PENDING => 'Onay Bekleniyor',
            self::OPS_COLUMN_ASSIGNED => 'Servis Atandı',
            self::OPS_COLUMN_FINAL_CHECK => 'Son Kontrol',
            self::OPS_COLUMN_COMPLETED => 'Tamamlandı',
            self::OPS_COLUMN_REVIEW => 'İnceleniyor',
            self::OPS_COLUMN_CANCELLED => 'İptal',
            default => 'Operasyon',
        };
    }

    private function customerApprovalRequired(TechnicalServiceRequest $request, string $opsColumn, bool $isCompleted): bool
    {
        if ($isCompleted || ! in_array($opsColumn, [self::OPS_COLUMN_ASSIGNED, self::OPS_COLUMN_FINAL_CHECK], true)) {
            return false;
        }

        $approved = in_array($this->normalize((string) $request->customer_closure_approval_status), ['onaylandi', 'approved'], true)
            || $request->customerConfirmations->contains(fn ($confirmation): bool => (string) $confirmation->status === 'approved');

        return ! $approved;
    }

    private function fieldDocumentsRequired(TechnicalServiceRequest $request, string $opsColumn, bool $isCompleted): bool
    {
        if ($isCompleted || ! in_array($opsColumn, [self::OPS_COLUMN_ASSIGNED, self::OPS_COLUMN_FINAL_CHECK], true)) {
            return false;
        }

        $presentTypes = $request->uploads
            ->map(fn ($upload): string => (string) $upload->field_code)
            ->filter(fn (string $field): bool => in_array($field, ['before_photo', 'after_photo', 'warranty_document_photo'], true))
            ->unique();

        return $presentTypes->count() < 3;
    }

    private function appointmentStartAt(TechnicalServiceRequest $request): ?CarbonImmutable
    {
        if ($request->scheduled_at instanceof CarbonInterface) {
            return CarbonImmutable::instance($request->scheduled_at);
        }

        if ($request->scheduled_at !== null && $request->scheduled_at !== '') {
            return CarbonImmutable::parse($request->scheduled_at);
        }

        if ($request->scheduled_date instanceof CarbonInterface && filled($request->scheduled_time)) {
            return CarbonImmutable::parse($request->scheduled_date->toDateString().' '.$request->scheduled_time);
        }

        return null;
    }

    /**
     * @param array<int, string> $needles
     */
    private function statusIn(?string $value, array $needles): bool
    {
        $normalized = $this->normalize((string) $value);

        foreach ($needles as $needle) {
            if ($normalized === $this->normalize($needle)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        $map = [
            'ı' => 'i',
            'İ' => 'i',
            'ğ' => 'g',
            'Ğ' => 'g',
            'ü' => 'u',
            'Ü' => 'u',
            'ş' => 's',
            'Ş' => 's',
            'ö' => 'o',
            'Ö' => 'o',
            'ç' => 'c',
            'Ç' => 'c',
        ];

        return strtolower(strtr(trim($value), $map));
    }
}
