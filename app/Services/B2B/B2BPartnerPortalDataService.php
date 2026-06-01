<?php

namespace App\Services\B2B;

use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerOrder;
use App\Models\B2B\B2BPartnerTechnician;
use App\Models\B2B\B2BPartnerUserProfile;
use App\Models\TechnicalServiceEarning;
use App\Models\TechnicalServiceEarningItem;
use App\Models\TechnicalServiceCustomerConfirmation;
use App\Models\TechnicalServicePartnerJobAction;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestUpload;
use App\Models\User;
use App\Services\TechnicalService\TechnicalServiceOperationalStatePresenter;
use App\Support\PartnerPortalPublicUrl;
use Illuminate\Support\Collection;

class B2BPartnerPortalDataService
{
    private const REQUIRED_PORTAL_PHOTO_FIELDS = [
        'before_photo' => 'Öncesi',
        'after_photo' => 'Sonrası',
        'warranty_document_photo' => 'Garanti Belgesi',
    ];

    public function __construct(
        private readonly B2BPartnerAccessService $partnerAccess,
        private readonly B2BPartnerServiceJobScopeService $serviceJobScope,
        private readonly TechnicalServiceOperationalStatePresenter $operationalState,
    ) {}

    /**
     * @return Collection<int, B2BPartner>
     */
    public function visiblePartnersFor(User $user): Collection
    {
        $query = $this->partnerAccess
            ->visiblePartnerQuery($user)
            ->where('active', true)
            ->with([
                'capabilities',
                'profiles.user',
                'activePartnerTechnicians.technician',
            ]);

        if (! (bool) $user->role?->is_super_admin) {
            $query->whereHas('profiles', fn ($query) => $query
                ->where('user_id', $user->id)
                ->where('active', true));
        }

        return $query
            ->orderBy('display_name')
            ->get();
    }

    /**
     * @param  Collection<int, B2BPartner>  $partners
     */
    public function selectedPartner(Collection $partners, ?int $requestedPartnerId): ?B2BPartner
    {
        if ($requestedPartnerId) {
            return $partners->firstWhere('id', $requestedPartnerId);
        }

        return $partners->first();
    }

    /**
     * @param  Collection<int, B2BPartner>  $partners
     * @return array<string, mixed>
     */
    public function payload(
        B2BPartner $partner,
        string $view,
        bool $allowed,
        ?string $deniedMessage,
        Collection $partners,
        ?User $user = null,
        bool $preview = false,
    ): array {
        $partner->loadMissing(['capabilities', 'profiles.user', 'activePartnerTechnicians.technician']);

        return [
            'view' => $view,
            'allowed' => $allowed,
            'deniedMessage' => $allowed ? null : $deniedMessage,
            'preview' => $preview,
            'partners' => $partners->map(fn (B2BPartner $item): array => $this->safePartnerSummary($item))->values()->all(),
            'selectedPartner' => $this->safePartnerSummary($partner),
            'navigation' => $this->navigationFor($partner, $user),
            'stats' => $this->statsFor($partner),
            'orders' => $this->ordersFor($partner),
            'products' => $this->safeProductCatalog(),
            'serviceJobs' => $this->serviceJobsFor($partner),
            'serviceJobBoard' => $this->serviceJobBoardFor($partner),
            'earnings' => $this->earningsFor($partner),
            'settings' => $this->settingsFor($partner),
            'messages' => [
                'orders' => 'Sipariş talepleri operasyon onayına düşer; merkez sisteme otomatik yazılmaz.',
                'stock' => 'Stok bilgisi güvenli seviyede gösterilir. Depo, maliyet ve iç stok detayları partner portalında gösterilmez.',
                'service' => 'İşler yalnızca partnerin owner/field usta kapsamından okunur.',
                'settings' => 'Bu bilgileri güncellemek için operasyon ekibiyle iletişime geçin.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function safePartnerSummary(B2BPartner $partner): array
    {
        $metadata = is_array($partner->metadata) ? $partner->metadata : [];

        return [
            'id' => $partner->id,
            'display_name' => $partner->display_name,
            'capabilities' => $partner->capabilityCodes(),
            'phone' => $partner->phone,
            'email' => $partner->email,
            'city' => $partner->city,
            'district' => $partner->district,
            'address' => $partner->address ?? ($metadata['address'] ?? null),
            'child_accounts' => $this->safeChildAccounts($metadata['child_cari_accounts'] ?? []),
            'linked_technicians' => $this->safeTechnicianSummaries($partner),
            'users_count' => $partner->profiles->count(),
            'active_users_count' => $partner->profiles->where('active', true)->count(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function safeProductCatalog(): array
    {
        return [
            [
                'catalog_id' => 'smart_lock_prime',
                'name' => 'Akıllı kapı kilidi',
                'model' => 'Prime seri',
                'category' => 'Kilit sistemleri',
                'stock_status' => 'unknown',
                'stock_label' => 'Stok bilgisi şu an alınamadı',
                'order_note' => 'Operasyon onayıyla talep alınır.',
            ],
            [
                'catalog_id' => 'bas_cek_lock',
                'name' => 'Bas çek kilitleme ürünü',
                'model' => 'Standart seri',
                'category' => 'Kilit sistemleri',
                'stock_status' => 'unknown',
                'stock_label' => 'Stok bilgisi şu an alınamadı',
                'order_note' => 'Depo uygunluğu operasyon tarafından kontrol edilir.',
            ],
            [
                'catalog_id' => 'hotel_lock',
                'name' => 'Otel tipi kilit çözümü',
                'model' => 'Kartlı seri',
                'category' => 'Kurumsal çözümler',
                'stock_status' => 'unknown',
                'stock_label' => 'Stok bilgisi şu an alınamadı',
                'order_note' => 'Proje adedi operasyon onayı gerektirebilir.',
            ],
            [
                'catalog_id' => 'accessory_pack',
                'name' => 'Montaj aksesuar seti',
                'model' => 'Servis paketi',
                'category' => 'Aksesuar',
                'stock_status' => 'unknown',
                'stock_label' => 'Stok bilgisi şu an alınamadı',
                'order_note' => 'Talep operasyon kontrolüne düşer.',
            ],
        ];
    }

    public function productByCatalogId(string $catalogId): ?array
    {
        return collect($this->safeProductCatalog())->firstWhere('catalog_id', $catalogId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function ordersFor(B2BPartner $partner): array
    {
        return B2BPartnerOrder::query()
            ->with('items')
            ->where('partner_id', $partner->id)
            ->orderByDesc('submitted_at')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (B2BPartnerOrder $order): array => $this->safeOrderSummary($order))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function safeOrderSummary(B2BPartnerOrder $order): array
    {
        $items = $order->items;

        return [
            'id' => $order->id,
            'order_no' => $order->order_no,
            'status' => $order->status,
            'status_label' => $this->orderStatusLabel($order->status),
            'note' => $order->note,
            'submitted_at' => $order->submitted_at?->toIso8601String(),
            'items_count' => $items->count(),
            'total_quantity' => $items->sum('requested_quantity'),
            'estimated_amount' => null,
            'shipping_status' => 'Operasyon incelemesinde',
            'delivery_check_status' => 'Henüz başlamadı',
            'items' => $items
                ->map(fn ($item): array => [
                    'product_name' => $item->product_name,
                    'requested_quantity' => $item->requested_quantity,
                    'stock_status' => $item->stock_status,
                    'stock_label' => $this->stockStatusLabel($item->stock_status),
                    'note' => $item->note,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function serviceJobsFor(B2BPartner $partner): array
    {
        return $this->serviceJobScope
            ->serviceJobsQuery($partner)
            ->with([
                'partnerJobActions' => fn ($query) => $query->latest(),
                'uploads',
            ])
            ->latest('updated_at')
            ->limit(50)
            ->get()
            ->map(fn (TechnicalServiceRequest $request): array => $this->safeServiceJobSummary($request))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function serviceJobBoardFor(B2BPartner $partner): array
    {
        $jobs = collect($this->serviceJobsFor($partner));
        $columns = collect([
            ['key' => 'new_jobs', 'label' => 'Yeni işler', 'tone' => 'blue'],
            ['key' => 'appointment_confirmed', 'label' => 'Randevu onaylandı', 'tone' => 'green'],
            ['key' => 'revisit', 'label' => 'Tekrar ziyaret', 'tone' => 'amber'],
            ['key' => 'final_check', 'label' => 'Son kontrol bekliyor', 'tone' => 'violet'],
            ['key' => 'completed', 'label' => 'Tamamlanan işler', 'tone' => 'slate'],
        ])->map(function (array $column) use ($jobs): array {
            $column['jobs'] = $jobs
                ->where('kanban_column', $column['key'])
                ->sortBy([
                    ['card_priority', 'asc'],
                    ['updated_at', 'desc'],
                ])
                ->values()
                ->all();
            $column['count'] = count($column['jobs']);

            return $column;
        });

        return [
            'columns' => $columns->values()->all(),
            'total' => $jobs->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function safeServiceJobSummary(TechnicalServiceRequest $request): array
    {
        $request->loadMissing([
            'partnerJobActions' => fn ($query) => $query->latest(),
            'uploads',
            'customerConfirmations' => fn ($query) => $query->latest(),
            'latestAssignmentOffer.technician',
            'technicianRecord',
        ]);
        $partnerActions = $request->partnerJobActions->sortByDesc('id')->values();
        $latestAction = $this->latestVisiblePartnerAction($request, $partnerActions);
        $latestAppointmentProposal = $partnerActions
            ->firstWhere('action', TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED);
        $latestRejection = $partnerActions
            ->first(fn (TechnicalServicePartnerJobAction $action): bool => $action->action === TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED
                && $action->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW);
        $latestCompletionSubmission = $partnerActions
            ->firstWhere('action', TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED);
        $latestSupportRequest = $partnerActions
            ->firstWhere('action', TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED);
        $latestOtpRequest = $partnerActions
            ->firstWhere('action', TechnicalServicePartnerJobAction::ACTION_CUSTOMER_OTP_REQUESTED);
        $latestPriceRevisionRequest = $partnerActions
            ->firstWhere('action', TechnicalServicePartnerJobAction::ACTION_PRICE_REVISION_REQUESTED);
        $latestCustomerApprovalRejection = $partnerActions
            ->firstWhere('action', TechnicalServicePartnerJobAction::ACTION_CUSTOMER_APPROVAL_REJECTED);
        $stateAction = $this->stateAction($request);
        $canonicalState = $this->operationalState->present($request);
        $assignmentOffer = $request->latestAssignmentOffer;
        $earningSummary = $this->earningSummary($request, $assignmentOffer);
        $photoReadiness = $this->portalPhotoReadiness($request);
        $latestCustomerConfirmation = $request->customerConfirmations->first();
        $approvedCustomerConfirmation = $request->customerConfirmations
            ->firstWhere('status', TechnicalServiceCustomerConfirmation::STATUS_APPROVED);
        $customerConfirmationReady = in_array($request->customer_closure_approval_status, ['onaylandı', 'onaylandi', 'onaylandÄ±'], true)
            || $approvedCustomerConfirmation instanceof TechnicalServiceCustomerConfirmation;
        $completionRequirements = [
            'door_photos_required' => $photoReadiness['required'],
            'door_photos_uploaded' => $photoReadiness['count'],
            'photos_ready' => $photoReadiness['ready'],
            'customer_confirmation_ready' => $customerConfirmationReady,
            'checklist_required' => true,
            'ops_final_check_required' => true,
            'required_photo_labels' => array_values(self::requiredPortalPhotoFields()),
            'missing_photo_labels' => $photoReadiness['missing_labels'],
            'photo_statuses' => collect(self::requiredPortalPhotoFields())
                ->map(fn (string $label, string $field): array => [
                    'field' => $field,
                    'label' => $label,
                    'uploaded' => in_array($field, $photoReadiness['present_fields'], true),
                ])
                ->values()
                ->all(),
        ];
        $hasOpsAppointment = $this->hasOpsAppointment($request);
        $isTerminal = (bool) ($canonicalState['is_completed'] ?? false)
            || ($canonicalState['ops_column'] ?? null) === 'cancelled';
        $isAppointmentConfirmed = (bool) ($canonicalState['is_appointment_confirmed'] ?? false);
        $hasOpsReviewAction = $stateAction instanceof TechnicalServicePartnerJobAction
            && $stateAction->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW;
        $hasAppointmentProposalInReview = $latestAppointmentProposal instanceof TechnicalServicePartnerJobAction
            && $latestAppointmentProposal->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW;
        $isRejectedInReview = $hasOpsReviewAction
            && $stateAction->action === TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED;
        $isFinalCheck = (bool) ($canonicalState['is_pending_final_check'] ?? false);
        $canAcceptAppointment = $this->isTechnicianApprovalStatus($request)
            && $hasOpsAppointment
            && ! $hasOpsReviewAction;
        $canProposeAppointment = ! $isTerminal
            && ! $isAppointmentConfirmed
            && ! $isFinalCheck
            && ! $isRejectedInReview
            && ! $hasAppointmentProposalInReview;
        $canFieldActions = $isAppointmentConfirmed && ! $isTerminal && ! $isFinalCheck && ! $isRejectedInReview;
        $nextActionLabel = $this->serviceJobNextActionLabel($request, $stateAction, $completionRequirements);
        $partnerNextActionLabel = $canonicalState['display_action_label'] ?? $nextActionLabel;
        if ($stateAction?->action === TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED
            && $stateAction->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
            $partnerNextActionLabel = 'Randevu önerildi';
        }
        if ($partnerNextActionLabel === 'Fotoğraf eksik') {
            $partnerNextActionLabel = 'Fotoğraf bekliyor';
        }
        $badges = collect($this->serviceJobBadges($request, $stateAction, $completionRequirements))
            ->reject(fn (string $badge): bool => $badge === $nextActionLabel)
            ->values()
            ->all();
        $displayBadges = collect($canonicalState['display_tags'] ?? [])
            ->pluck('label')
            ->reject(function (?string $badge) use ($partnerNextActionLabel): bool {
                return blank($badge) || $badge === $partnerNextActionLabel || $badge === 'Aksiyon: '.$partnerNextActionLabel;
            })
            ->values()
            ->all();
        if ($displayBadges === []) {
            $displayBadges = $badges;
        }
        if ($stateAction?->action === TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED
            && $stateAction->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
            $displayBadges = ['Operasyon onayı bekleniyor'];
        }
        $appointmentLabel = $this->appointmentLabel($request);
        if ($appointmentLabel === null && $hasAppointmentProposalInReview) {
            $appointmentLabel = 'Randevu önerildi';
        }

        return [
            'id' => $request->id,
            'mrn' => $request->mrn,
            'status_label' => $request->status,
            'service_stage_label' => $request->workflow_status,
            'customer_name' => $request->customer_name,
            'customer_phone' => $request->customer_phone,
            'city' => $request->customer_city,
            'district' => $request->customer_district,
            'address_summary' => $request->service_address,
            'product_name' => $request->product_name,
            'product_model' => $request->product_model,
            'model' => $request->product_model,
            'serial_no' => $request->serial_number,
            'service_type' => $request->service_type,
            'scheduled_at' => $request->scheduled_at?->toIso8601String(),
            'scheduled_date' => $request->scheduled_date?->toDateString(),
            'appointment_at' => $request->scheduled_at?->toIso8601String() ?? $request->scheduled_date?->toDateString(),
            'appointment_label' => $appointmentLabel,
            'priority' => $request->priority,
            'status' => $request->status,
            'workflow_status' => $request->workflow_status,
            'next_action' => $partnerNextActionLabel,
            'route_distance_summary' => $request->travel_round_trip_km !== null ? ((float) $request->travel_round_trip_km).' km' : null,
            'payment_status_summary' => $request->mount_payment_label ?? $request->mount_payment_status,
            'maps_link' => $this->mapsLink($request),
            'customer_tel_link' => $this->telLink($request->customer_phone),
            'checklist_status' => $request->checklist_status,
            'checklist_payload' => [],
            'photo_counts' => [
                'before' => in_array('before_photo', $photoReadiness['present_fields'], true) ? 1 : 0,
                'after' => in_array('after_photo', $photoReadiness['present_fields'], true) ? 1 : 0,
                'general' => in_array('warranty_document_photo', $photoReadiness['present_fields'], true) ? 1 : 0,
            ],
            'photos' => $request->uploads
                ->filter(fn (TechnicalServiceRequestUpload $upload): bool => $this->isPortalFieldDocument($upload))
                ->map(fn (TechnicalServiceRequestUpload $upload): array => [
                    'id' => $upload->id,
                    'label' => $this->portalPhotoLabel($upload) ?? $upload->original_name,
                    'category' => $upload->category,
                    'field_code' => $upload->field_code,
                    'preview_url' => route('api.technical-service.requests.uploads.show', [
                        'technicalServiceRequest' => $request->id,
                        'upload' => $upload->id,
                    ]),
                    'review_status' => $upload->review_status,
                    'review_note' => $upload->review_note,
                ])
                ->values()
                ->all(),
            'latest_partner_action' => $latestAction ? [
                'action' => $latestAction->action,
                'status' => $latestAction->status,
                'note' => $latestAction->note,
                'payload' => is_array($latestAction->payload) ? $latestAction->payload : [],
                'created_at' => $latestAction->created_at?->toIso8601String(),
            ] : null,
            'portal_actions' => $partnerActions
                ->take(8)
                ->map(fn (TechnicalServicePartnerJobAction $action): array => [
                    'id' => $action->id,
                    'action' => $action->action,
                    'status' => $action->status,
                    'note' => $action->note,
                    'payload' => is_array($action->payload) ? $action->payload : [],
                    'created_at' => $action->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'appointment_proposal' => $latestAppointmentProposal ? [
                'id' => $latestAppointmentProposal->id,
                'status' => $latestAppointmentProposal->status,
                'note' => $latestAppointmentProposal->note,
                'payload' => is_array($latestAppointmentProposal->payload) ? $latestAppointmentProposal->payload : [],
                'created_at' => $latestAppointmentProposal->created_at?->toIso8601String(),
            ] : null,
            'rejection' => $latestRejection ? [
                'id' => $latestRejection->id,
                'status' => $latestRejection->status,
                'note' => $latestRejection->note,
                'payload' => is_array($latestRejection->payload) ? $latestRejection->payload : [],
                'created_at' => $latestRejection->created_at?->toIso8601String(),
            ] : null,
            'support_request' => $latestSupportRequest ? [
                'id' => $latestSupportRequest->id,
                'status' => $latestSupportRequest->status,
                'note' => $latestSupportRequest->note,
                'payload' => is_array($latestSupportRequest->payload) ? $latestSupportRequest->payload : [],
                'created_at' => $latestSupportRequest->created_at?->toIso8601String(),
            ] : null,
            'price_revision_request' => $latestPriceRevisionRequest ? [
                'id' => $latestPriceRevisionRequest->id,
                'status' => $latestPriceRevisionRequest->status,
                'note' => $latestPriceRevisionRequest->note,
                'payload' => is_array($latestPriceRevisionRequest->payload) ? $latestPriceRevisionRequest->payload : [],
                'created_at' => $latestPriceRevisionRequest->created_at?->toIso8601String(),
            ] : null,
            'customer_otp_request' => $latestOtpRequest ? [
                'id' => $latestOtpRequest->id,
                'status' => $latestOtpRequest->status,
                'note' => $latestOtpRequest->note,
                'payload' => is_array($latestOtpRequest->payload) ? $latestOtpRequest->payload : [],
                'created_at' => $latestOtpRequest->created_at?->toIso8601String(),
            ] : null,
            'customer_confirmation' => $latestCustomerConfirmation ? [
                'id' => $latestCustomerConfirmation->id,
                'status' => $latestCustomerConfirmation->status,
                'approved_at' => $latestCustomerConfirmation->approved_at?->toIso8601String(),
                'rejected_at' => $latestCustomerConfirmation->rejected_at?->toIso8601String(),
                'customer_note' => $latestCustomerConfirmation->customer_note,
                'approval_url' => $latestCustomerConfirmation->status === TechnicalServiceCustomerConfirmation::STATUS_PENDING
                    ? PartnerPortalPublicUrl::route('service-job-confirmation.show', ['token' => $latestCustomerConfirmation->token])
                    : null,
            ] : null,
            'completion_submission' => $latestCompletionSubmission ? [
                'id' => $latestCompletionSubmission->id,
                'status' => $latestCompletionSubmission->status,
                'note' => $latestCompletionSubmission->note,
                'payload' => is_array($latestCompletionSubmission->payload) ? $latestCompletionSubmission->payload : [],
                'created_at' => $latestCompletionSubmission->created_at?->toIso8601String(),
            ] : null,
            'assignment_offer' => $assignmentOffer ? [
                'id' => $assignmentOffer->id,
                'labor_amount' => (float) $assignmentOffer->labor_amount,
                'route_fee_amount' => (float) $assignmentOffer->route_fee_amount,
                'total_amount' => (float) $assignmentOffer->total_amount,
                'currency' => $assignmentOffer->currency,
                'status' => $assignmentOffer->status,
                'note' => $assignmentOffer->note,
                'sent_at' => $assignmentOffer->sent_at?->toIso8601String(),
                'message_payload' => is_array($assignmentOffer->metadata) ? ($assignmentOffer->metadata['message_payload'] ?? null) : null,
            ] : null,
            'earning_summary' => [
                'labor_amount' => $earningSummary['labor_amount'],
                'route_fee_amount' => $earningSummary['route_fee_amount'],
                'total_amount' => $earningSummary['total_amount'],
                'status' => $earningSummary['status'],
            ],
            'completion_requirements' => $completionRequirements,
            'badges' => $displayBadges,
            'card_priority' => $canonicalState['sort_priority'] ?? $this->serviceJobPriority($stateAction),
            'card_tone' => $this->serviceJobTone($request, $stateAction),
            'kanban_column' => $canonicalState['partner_column'] ?? $this->serviceJobColumn($request, $stateAction),
            'operational_state' => $canonicalState,
            'action_state' => $this->serviceJobActionState($request, $stateAction, $completionRequirements),
            'can_accept' => $canAcceptAppointment,
            'can_propose_appointment' => $canProposeAppointment,
            'can_request_revisit' => $canFieldActions || $this->serviceJobColumn($request, $stateAction) === 'revisit',
            'can_request_support' => $canFieldActions || $this->serviceJobColumn($request, $stateAction) === 'revisit',
            'can_request_customer_otp' => $canFieldActions,
            'can_upload_photos' => $canFieldActions,
            'can_submit_completion' => $canFieldActions,
            'can_request_price_revision' => ! $isTerminal && ! $isRejectedInReview && $assignmentOffer !== null,
            'can_complete_directly' => false,
            'can_reject' => ! $isTerminal && ! $isFinalCheck && ! $isRejectedInReview,
            'updated_at' => $request->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function earningsFor(B2BPartner $partner): array
    {
        $technicianIds = $this->serviceJobScope->activeTechnicianIds($partner);

        $rows = TechnicalServiceEarning::query()
            ->with(['period', 'items'])
            ->whereIn('technical_service_technician_id', $technicianIds)
            ->orderByDesc('updated_at')
            ->limit(24)
            ->get()
            ->map(fn (TechnicalServiceEarning $earning): array => [
                'id' => $earning->id,
                'period' => $earning->period ? $earning->period->year.'-'.$earning->period->month : null,
                'job_count' => $earning->job_count,
                'labor_total' => (float) $earning->labor_total,
                'travel_fee_total' => (float) $earning->travel_fee_total,
                'grand_total' => (float) $earning->grand_total,
                'status' => $earning->status,
                'paid_at' => $earning->paid_at?->toIso8601String(),
                'items' => $earning->items
                    ->map(fn (TechnicalServiceEarningItem $item): array => [
                        'technical_service_request_id' => $item->technical_service_request_id,
                        'job_date' => $item->job_date?->toDateString(),
                        'mrn' => $item->mrn,
                        'city' => $item->customer_city,
                        'district' => $item->customer_district,
                        'labor_amount' => (float) $item->labor_amount,
                        'travel_fee_amount' => (float) $item->travel_fee_amount,
                        'line_total' => (float) $item->line_total,
                        'status' => $earning->status,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values();
        $earningRequestIds = $rows
            ->flatMap(fn (array $row): array => collect($row['items'] ?? [])->pluck('technical_service_request_id')->filter()->all())
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $completedJobRows = $this->serviceJobScope
            ->serviceJobsQuery($partner)
            ->with(['latestAssignmentOffer'])
            ->when($earningRequestIds !== [], fn ($query) => $query->whereNotIn('id', $earningRequestIds))
            ->where(function ($query): void {
                $query
                    ->whereNotNull('completed_at')
                    ->orWhereNotNull('installation_completed_at')
                    ->orWhereIn('workflow_status', ['Tamamlandı', 'Tamamlandi', 'TamamlandÄ±']);
            })
            ->latest('completed_at')
            ->latest('updated_at')
            ->limit(50)
            ->get()
            ->filter(fn (TechnicalServiceRequest $request): bool => $request->latestAssignmentOffer !== null || $this->earningMessagePayload($request) !== null)
            ->map(function (TechnicalServiceRequest $request): array {
                $earningSummary = $this->earningSummary($request, $request->latestAssignmentOffer);

                return [
                    'id' => 'completed-job-'.$request->id,
                    'period' => 'Dönem bekliyor',
                    'job_count' => 1,
                    'labor_total' => $earningSummary['labor_amount'],
                    'travel_fee_total' => $earningSummary['route_fee_amount'],
                    'grand_total' => $earningSummary['total_amount'],
                    'status' => 'Kesinleşti',
                    'paid_at' => null,
                    'items' => [[
                        'technical_service_request_id' => $request->id,
                        'job_date' => $request->completed_at?->toDateString()
                            ?? $request->installation_completed_at?->toDateString()
                            ?? $request->scheduled_date?->toDateString(),
                        'mrn' => $request->mrn,
                        'city' => $request->customer_city,
                        'district' => $request->customer_district,
                        'labor_amount' => $earningSummary['labor_amount'],
                        'travel_fee_amount' => $earningSummary['route_fee_amount'],
                        'line_total' => $earningSummary['total_amount'],
                        'status' => 'Kesinleşti',
                    ]],
                ];
            })
            ->values();
        $completedRows = $rows->concat($completedJobRows)->values();
        $pendingRows = $this->serviceJobScope
            ->serviceJobsQuery($partner)
            ->with(['latestAssignmentOffer'])
            ->where(function ($query): void {
                $query
                    ->whereNull('completed_at')
                    ->orWhere('workflow_status', '<>', 'Tamamlandı');
            })
            ->latest('updated_at')
            ->limit(50)
            ->get()
            ->filter(fn (TechnicalServiceRequest $request): bool => ! $this->isCompletedForPortalEarnings($request)
                && ($request->latestAssignmentOffer !== null || $this->earningMessagePayload($request) !== null))
            ->map(function (TechnicalServiceRequest $request): array {
                $offer = $request->latestAssignmentOffer;
                $earningSummary = $this->earningSummary($request, $offer);

                return [
                    'id' => $request->id,
                    'mrn' => $request->mrn,
                    'scheduled_at' => $request->scheduled_at?->toIso8601String() ?? $request->scheduled_date?->toDateString(),
                    'labor_amount' => $earningSummary['labor_amount'],
                    'travel_fee_amount' => $earningSummary['route_fee_amount'],
                    'line_total' => $earningSummary['total_amount'],
                    'status' => $this->pendingEarningStatus($request),
                    'offer_status' => $earningSummary['status'],
                    'city' => $request->customer_city,
                    'district' => $request->customer_district,
                ];
            })
            ->values();
        $pendingSummary = [
            'job_count' => $pendingRows->count(),
            'labor_total' => $pendingRows->sum('labor_amount'),
            'travel_fee_total' => $pendingRows->sum('travel_fee_amount'),
            'grand_total' => $pendingRows->sum('line_total'),
        ];
        $completedSummary = [
            'job_count' => $completedRows->sum('job_count'),
            'labor_total' => $completedRows->sum('labor_total'),
            'travel_fee_total' => $completedRows->sum('travel_fee_total'),
            'grand_total' => $completedRows->sum('grand_total'),
        ];

        return [
            'status' => $completedRows->isEmpty() && $pendingRows->isEmpty() ? 'empty' : 'ok',
            'rows' => $completedRows->all(),
            'pending' => [
                'rows' => $pendingRows->all(),
                'summary' => $pendingSummary,
                'note' => 'Bekleyen hakedişler tahmini atama teklifidir; actual hakediş değildir.',
            ],
            'completed' => [
                'rows' => $completedRows->all(),
                'summary' => $completedSummary,
                'note' => 'Tamamlanan hakedişler Teknik Servis hakediş kaynağından okunur; dönem bekleyen işler ayrıca gösterilir.',
            ],
            'summary' => [
                'job_count' => $completedRows->sum('job_count'),
                'labor_total' => $completedRows->sum('labor_total'),
                'travel_fee_total' => $completedRows->sum('travel_fee_total'),
                'grand_total' => $completedRows->sum('grand_total'),
            ],
        ];
    }

    private function isCompletedForPortalEarnings(TechnicalServiceRequest $request): bool
    {
        if ($request->completed_at !== null || $request->installation_completed_at !== null) {
            return true;
        }

        return in_array((string) $request->workflow_status, ['Tamamlandı', 'Tamamlandi', 'TamamlandÄ±'], true);
    }

    private function pendingEarningStatus(TechnicalServiceRequest $request): string
    {
        $request->loadMissing('partnerJobActions');
        $stateAction = $this->stateAction($request);

        if ($stateAction?->action === TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED
            && $stateAction->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
            return 'son_kontrol';
        }

        if ($this->isAppointmentConfirmedStatus($request)) {
            return 'planlı';
        }

        return 'beklemede';
    }

    /**
     * @return array{labor_amount:float,route_fee_amount:float,total_amount:float,status:string|null}
     */
    private function earningSummary(TechnicalServiceRequest $request, mixed $assignmentOffer): array
    {
        if ($assignmentOffer !== null) {
            $laborAmount = (float) ($assignmentOffer->labor_amount ?? 0);
            $routeFeeAmount = (float) ($assignmentOffer->route_fee_amount ?? 0);

            return [
                'labor_amount' => $laborAmount,
                'route_fee_amount' => $routeFeeAmount,
                'total_amount' => round($laborAmount + $routeFeeAmount, 2),
                'status' => $assignmentOffer->status ?? null,
            ];
        }

        $earningPayload = $this->earningMessagePayload($request);
        if ($earningPayload !== null) {
            $laborAmount = $this->money($earningPayload['labor_amount'] ?? null)
                ?? $this->money($request->technician_payment_amount)
                ?? 0.0;
            $routeFeeAmount = $this->money($earningPayload['route_fee_amount'] ?? null)
                ?? $this->money($request->travel_fee_amount)
                ?? 0.0;

            return [
                'labor_amount' => $laborAmount,
                'route_fee_amount' => $routeFeeAmount,
                'total_amount' => round($laborAmount + $routeFeeAmount, 2),
                'status' => (string) ($earningPayload['status'] ?? 'sent'),
            ];
        }

        $laborAmount = $this->money($request->technician_payment_amount) ?? 0.0;
        $routeFeeAmount = $this->money($request->travel_fee_amount) ?? 0.0;

        return [
            'labor_amount' => $laborAmount,
            'route_fee_amount' => $routeFeeAmount,
            'total_amount' => round($laborAmount + $routeFeeAmount, 2),
            'status' => null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function earningMessagePayload(TechnicalServiceRequest $request): ?array
    {
        $operationControl = is_array($request->operation_control_payload) ? $request->operation_control_payload : [];
        $payload = $operationControl['technician_earning_message'] ?? null;

        return is_array($payload) ? $payload : null;
    }

    private function money(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private function appointmentLabel(TechnicalServiceRequest $request): ?string
    {
        if ($request->scheduled_at !== null) {
            return $request->scheduled_at->format('d.m.Y H:i');
        }

        if ($request->scheduled_date !== null) {
            return trim($request->scheduled_date->format('d.m.Y').' '.(string) $request->scheduled_time);
        }

        return null;
    }

    private function hasOpsAppointment(TechnicalServiceRequest $request): bool
    {
        return $request->scheduled_at !== null
            || ($request->scheduled_date !== null && filled($request->scheduled_time));
    }

    private function isTerminalStatus(TechnicalServiceRequest $request): bool
    {
        return in_array($request->workflow_status, ['Tamamlandı', 'TamamlandÄ±', 'İptal', 'Ä°ptal'], true)
            || $request->completed_at !== null;
    }

    private function isTechnicianApprovalStatus(TechnicalServiceRequest $request): bool
    {
        return in_array($request->workflow_status, ['Usta Onayı Bekleyen', 'Usta OnayÄ± Bekleyen'], true);
    }

    private function isAppointmentConfirmedStatus(TechnicalServiceRequest $request): bool
    {
        return in_array($request->workflow_status, [
            'Planlı',
            'PlanlÄ±',
            'Yolda',
            'Sahada',
            'Belge / Fotoğraf Bekleyen',
            'Belge / FotoÄŸraf Bekleyen',
            'Müşteri Kapanış Onayı Bekleyen',
            'MÃ¼ÅŸteri KapanÄ±ÅŸ OnayÄ± Bekleyen',
        ], true);
    }

    private function portalPhotoLabel(TechnicalServiceRequestUpload $upload): ?string
    {
        return match ($upload->field_code) {
            'before_photo' => 'Öncesi',
            'after_photo' => 'Sonrası',
            'warranty_document_photo' => 'Garanti Belgesi',
            default => null,
        };
    }

    private function stateAction(TechnicalServiceRequest $request): ?TechnicalServicePartnerJobAction
    {
        $opsReview = $request->partnerJobActions
            ->filter(fn (TechnicalServicePartnerJobAction $action): bool => $action->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW);

        foreach ([
            TechnicalServicePartnerJobAction::ACTION_PRICE_REVISION_REQUESTED,
            TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED,
            TechnicalServicePartnerJobAction::ACTION_CUSTOMER_APPROVAL_REJECTED,
            TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED,
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

    private function latestVisiblePartnerAction(TechnicalServiceRequest $request, \Illuminate\Support\Collection $partnerActions): ?TechnicalServicePartnerJobAction
    {
        return $partnerActions->first(function (TechnicalServicePartnerJobAction $action) use ($request): bool {
            if ($action->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
                return true;
            }

            if ($action->status !== TechnicalServicePartnerJobAction::STATUS_APPLIED) {
                return false;
            }

            if ($action->action === TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED
                || $action->action === TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_ACCEPTED_BY_TECHNICIAN) {
                return $this->isAppointmentConfirmedStatus($request);
            }

            return false;
        });
    }

    /**
     * @param  array<string, mixed>  $completionRequirements
     * @return array<int, string>
     */
    private function serviceJobBadges(TechnicalServiceRequest $request, ?TechnicalServicePartnerJobAction $action, array $completionRequirements): array
    {
        $badges = [];

        if ($action?->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
            $badges[] = 'Operasyon onayı bekleniyor';
        }

        if ($action?->action === TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED
            && $action->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
            $badges[] = 'Randevu önerildi';
        }

        if ($action?->action === TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED
            && $action->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
            $badges[] = 'Reddedildi';
        }

        if ($action?->action === TechnicalServicePartnerJobAction::ACTION_CUSTOMER_APPROVAL_REJECTED
            && $action->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
            $badges[] = 'Müşteri onayı reddedildi';
        }

        if ($action?->action === TechnicalServicePartnerJobAction::ACTION_PRICE_REVISION_REQUESTED
            && $action->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
            $badges[] = 'Hakediş revize talebi';
        }

        if ($action?->action === TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED
            && $action->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
            $badges[] = 'Son kontrol bekliyor';
        }

        if ($action?->action === TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED
            && $action->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
            $badges[] = 'Yedek parça talebi';
        }

        if (($completionRequirements['photos_ready'] ?? false) !== true && $this->serviceJobColumn($request, $action) === 'appointment_confirmed') {
            $badges[] = 'Fotoğraf bekliyor';
        }

        if (($completionRequirements['customer_confirmation_ready'] ?? false) !== true && $this->serviceJobColumn($request, $action) === 'appointment_confirmed') {
            $badges[] = 'OTP bekliyor';
        }

        if (($completionRequirements['photos_ready'] ?? false) === true && $this->serviceJobColumn($request, $action) === 'appointment_confirmed') {
            $badges[] = 'Saha belgeleri yüklendi';
        }

        if (($completionRequirements['customer_confirmation_ready'] ?? false) === true && $this->serviceJobColumn($request, $action) === 'appointment_confirmed') {
            $badges[] = 'Müşteri onayı alındı';
        }

        if (($completionRequirements['photos_ready'] ?? false) === true
            && ($completionRequirements['customer_confirmation_ready'] ?? false) === true
            && $this->serviceJobColumn($request, $action) === 'appointment_confirmed') {
            $badges[] = 'Tamamlamaya gönderilebilir';
        }

        return array_values(array_unique($badges));
    }

    /**
     * @param  array<string, mixed>  $completionRequirements
     */
    private function serviceJobActionState(TechnicalServiceRequest $request, ?TechnicalServicePartnerJobAction $action, array $completionRequirements): string
    {
        if ($action?->action === TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED
            && $action->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
            return 'rejected_ops_review';
        }

        if ($action?->action === TechnicalServicePartnerJobAction::ACTION_CUSTOMER_APPROVAL_REJECTED
            && $action->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
            return 'customer_approval_rejected';
        }

        if ($action?->action === TechnicalServicePartnerJobAction::ACTION_PRICE_REVISION_REQUESTED
            && $action->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
            return 'price_revision_requested';
        }

        if ($action?->action === TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED
            && $action->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
            return 'final_check_waiting';
        }

        if ($action?->action === TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED
            && $action->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
            return 'appointment_proposed_waiting';
        }

        if ($action?->action === TechnicalServicePartnerJobAction::ACTION_REVISIT_REQUESTED
            && $action->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
            return 'revisit_requested';
        }

        if ($action?->action === TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED
            && $action->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
            return 'support_requested';
        }

        if ($this->isAppointmentConfirmedStatus($request)) {
            if (($completionRequirements['photos_ready'] ?? false) !== true) {
                return 'photo_waiting';
            }

            if (($completionRequirements['customer_confirmation_ready'] ?? false) !== true) {
                return 'otp_waiting';
            }

            return 'appointment_confirmed';
        }

        if ($this->isTechnicianApprovalStatus($request) && $this->hasOpsAppointment($request)) {
            return 'appointment_waiting_technician_accept';
        }

        if ($this->isTerminalStatus($request)) {
            return 'completed';
        }

        return 'new';
    }

    /**
     * @param  array<string, mixed>  $completionRequirements
     */
    private function serviceJobNextActionLabel(TechnicalServiceRequest $request, ?TechnicalServicePartnerJobAction $action, array $completionRequirements): string
    {
        if ($action?->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
            return match ($action->action) {
                TechnicalServicePartnerJobAction::ACTION_PRICE_REVISION_REQUESTED => 'Hakediş revize talebi operasyon incelemesinde',
                TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED => 'İş reddi operasyon incelemesinde',
                TechnicalServicePartnerJobAction::ACTION_CUSTOMER_APPROVAL_REJECTED => 'Müşteri onayı reddedildi',
                TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED => 'Son kontrol bekliyor',
                TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED => 'Randevu önerildi',
                TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED => 'Ek talep operasyon incelemesinde',
                TechnicalServicePartnerJobAction::ACTION_REVISIT_REQUESTED => 'Tekrar ziyaret talebi operasyon incelemesinde',
                default => 'Operasyon incelemesinde',
            };
        }

        if ($this->serviceJobColumn($request, $action) === 'appointment_confirmed') {
            if (($completionRequirements['photos_ready'] ?? false) !== true) {
                return 'Fotoğraf bekliyor';
            }

            if (($completionRequirements['customer_confirmation_ready'] ?? false) !== true) {
                return 'Müşteri onayı bekleniyor';
            }

            return 'Tamamlamaya gönderilebilir';
        }

        if ($this->serviceJobColumn($request, $action) === 'final_check') {
            return 'Son kontrol bekliyor';
        }

        if ($this->isTerminalStatus($request)) {
            return 'İş tamamlandı';
        }

        if ($this->isTechnicianApprovalStatus($request)) {
            return $this->hasOpsAppointment($request)
                ? 'Usta randevu onayı bekleniyor'
                : 'Usta onayı bekleniyor';
        }

        return 'Randevu bekleniyor';
    }

    private function serviceJobPriority(?TechnicalServicePartnerJobAction $action): int
    {
        if (! $action instanceof TechnicalServicePartnerJobAction || $action->status !== TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
            return 50;
        }

        return match ($action->action) {
            TechnicalServicePartnerJobAction::ACTION_PRICE_REVISION_REQUESTED => 1,
            TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED => 2,
            TechnicalServicePartnerJobAction::ACTION_CUSTOMER_APPROVAL_REJECTED => 3,
            TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED => 4,
            TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED => 5,
            TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED,
            TechnicalServicePartnerJobAction::ACTION_REVISIT_REQUESTED => 6,
            default => 20,
        };
    }

    private function serviceJobTone(TechnicalServiceRequest $request, ?TechnicalServicePartnerJobAction $action): string
    {
        if ($action?->action === TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED && $action->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
            return 'rose';
        }

        if (in_array($action?->action, [
            TechnicalServicePartnerJobAction::ACTION_PRICE_REVISION_REQUESTED,
            TechnicalServicePartnerJobAction::ACTION_CUSTOMER_APPROVAL_REJECTED,
        ], true) && $action?->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
            return 'rose';
        }

        if ($action?->action === TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED && $action->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
            return 'violet';
        }

        return match ($this->serviceJobColumn($request, $action)) {
            'appointment_confirmed' => 'green',
            'revisit' => 'amber',
            'final_check' => 'violet',
            'completed' => 'slate',
            default => 'blue',
        };
    }

    private function doorPhotoEvidenceCount(TechnicalServiceRequest $request): int
    {
        return $this->portalPhotoReadiness($request)['count'];
    }

    /**
     * @return array{present_fields: array<int, string>, missing_fields: array<int, string>, missing_labels: array<int, string>, count: int, required: int, ready: bool}
     */
    private function portalPhotoReadiness(TechnicalServiceRequest $request): array
    {
        $request->loadMissing('uploads');
        $uploadedFields = $request->uploads
            ->filter(fn (TechnicalServiceRequestUpload $upload): bool => $this->isPortalFieldDocument($upload))
            ->map(fn (TechnicalServiceRequestUpload $upload): ?string => $this->canonicalPortalPhotoField($upload->field_code))
            ->filter(fn (?string $field): bool => $field !== null && array_key_exists($field, self::REQUIRED_PORTAL_PHOTO_FIELDS))
            ->unique()
            ->values();

        $presentFields = $uploadedFields;

        $missingFields = collect(array_keys(self::REQUIRED_PORTAL_PHOTO_FIELDS))
            ->reject(fn (string $field): bool => $presentFields->contains($field))
            ->values();

        return [
            'present_fields' => $presentFields->all(),
            'missing_fields' => $missingFields->all(),
            'missing_labels' => $missingFields
                ->map(fn (string $field): string => self::REQUIRED_PORTAL_PHOTO_FIELDS[$field])
                ->all(),
            'count' => $presentFields->count(),
            'required' => count(self::REQUIRED_PORTAL_PHOTO_FIELDS),
            'ready' => $missingFields->isEmpty(),
        ];
    }

    private function canonicalPortalPhotoField(?string $fieldCode): ?string
    {
        $field = trim((string) $fieldCode);

        if ($field === '') {
            return null;
        }

        return $field;
    }

    private function isPortalFieldDocument(TechnicalServiceRequestUpload $upload): bool
    {
        if ($upload->category === TechnicalServiceRequestUpload::CATEGORY_PARTNER_PORTAL_FIELD_DOCUMENT) {
            return true;
        }

        return $upload->category === TechnicalServiceRequestUpload::CATEGORY_OPERATION_CONTROL_DOOR_PHOTO
            && array_key_exists((string) $upload->field_code, self::REQUIRED_PORTAL_PHOTO_FIELDS);
    }

    /**
     * @return array<string, string>
     */
    private static function requiredPortalPhotoFields(): array
    {
        return self::REQUIRED_PORTAL_PHOTO_FIELDS;
    }

    private function serviceJobColumn(TechnicalServiceRequest $request, ?TechnicalServicePartnerJobAction $latestAction): string
    {
        if (
            $latestAction?->action === TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED
            && $latestAction->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW
        ) {
            return 'final_check';
        }

        if ($this->isTerminalStatus($request)) {
            return 'completed';
        }

        if (
            (
                $latestAction?->action === TechnicalServicePartnerJobAction::ACTION_REVISIT_REQUESTED
                && $latestAction->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW
            )
            || (bool) $request->requires_second_visit
            || in_array($request->workflow_status, ['Beklemede', 'Müşteri Yerinde Yok', 'Montaj Yeri Hazır Değil', 'Parça Bekleniyor', 'Usta Tarih Revize Talebi'], true)
        ) {
            return 'revisit';
        }

        if ($latestAction?->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW
            && in_array($latestAction->action, [
            TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED,
            TechnicalServicePartnerJobAction::ACTION_PRICE_REVISION_REQUESTED,
            TechnicalServicePartnerJobAction::ACTION_CUSTOMER_APPROVAL_REJECTED,
            ], true)) {
            return 'new_jobs';
        }

        if ($this->isAppointmentConfirmedStatus($request)) {
            return 'appointment_confirmed';
        }

        return 'new_jobs';
    }

    private function canCompleteDirectly(TechnicalServiceRequest $request): bool
    {
        return in_array($request->workflow_status, ['Sahada', 'Belge / Fotoğraf Bekleyen', 'Müşteri Kapanış Onayı Bekleyen'], true)
            && $request->checklist_status === 'tamamlandı'
            && $this->portalPhotoReadiness($request)['ready']
            && in_array($request->document_status, ['tamamlandı', 'tamam', 'gerekli_degil'], true)
            && $request->customer_closure_approval_status === 'onaylandı';
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

    private function mapsLink(TechnicalServiceRequest $request): ?string
    {
        if ($request->location_latitude !== null && $request->location_longitude !== null) {
            return 'https://www.google.com/maps/search/?api=1&query='
                .rawurlencode((string) $request->location_latitude.','.(string) $request->location_longitude);
        }

        $address = trim((string) ($request->location_formatted_address ?: $request->service_address));

        return $address !== ''
            ? 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($address)
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function statsFor(B2BPartner $partner): array
    {
        $orders = B2BPartnerOrder::query()->where('partner_id', $partner->id);

        return [
            'linked_technicians_count' => $partner->activePartnerTechnicians->count(),
            'users_count' => B2BPartnerUserProfile::query()->where('partner_id', $partner->id)->count(),
            'active_users_count' => B2BPartnerUserProfile::query()->where('partner_id', $partner->id)->where('active', true)->count(),
            'open_service_jobs_count' => $this->serviceJobScope->serviceJobsQuery($partner)->count(),
            'open_orders_count' => (clone $orders)->whereIn('status', [B2BPartnerOrder::STATUS_SUBMITTED, B2BPartnerOrder::STATUS_OPS_REVIEW])->count(),
            'approval_waiting_orders_count' => (clone $orders)->where('status', B2BPartnerOrder::STATUS_OPS_REVIEW)->count(),
            'submitted_orders_count' => (clone $orders)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function settingsFor(B2BPartner $partner): array
    {
        return [
            'contact_note' => 'Bu bilgileri güncellemek için operasyon ekibiyle iletişime geçin.',
            'users' => $partner->profiles
                ->where('active', true)
                ->map(fn (B2BPartnerUserProfile $profile): array => [
                    'name' => $profile->user?->name,
                    'username' => $profile->user?->username,
                    'title' => $profile->title,
                    'phone' => $profile->phone,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function navigationFor(B2BPartner $partner, ?User $user): array
    {
        $items = [
            ['key' => 'dashboard', 'label' => 'Ana Sayfa', 'href' => '/partner/dashboard'],
        ];

        $hasDealerLike = collect($partner->capabilityCodes())->intersect([
            B2BPartner::TYPE_DEALER,
            B2BPartner::TYPE_MANUFACTURER,
            B2BPartner::TYPE_SELLER,
        ])->isNotEmpty();
        $hasLocksmith = $partner->hasCapability(B2BPartner::TYPE_LOCKSMITH);

        if ($hasDealerLike && (! $user || $this->partnerAccess->canAccessScope($user, $partner, 'orders', 'view'))) {
            $items[] = ['key' => 'orders', 'label' => 'Siparişler', 'href' => '/partner/orders'];
        }

        if ($hasDealerLike && (! $user || $this->partnerAccess->canAccessScope($user, $partner, 'stock', 'view'))) {
            $items[] = ['key' => 'stock', 'label' => 'Ürünler / Stok', 'href' => '/partner/stock'];
        }

        if ($hasLocksmith && (! $user || $this->partnerAccess->canAccessScope($user, $partner, 'technical_service', 'view'))) {
            $items[] = ['key' => 'service-jobs', 'label' => 'İşlerim', 'href' => '/partner/service-jobs'];
            $items[] = ['key' => 'earnings', 'label' => 'Hakedişler', 'href' => '/partner/earnings'];
        }

        $items[] = ['key' => 'settings', 'label' => 'Ayarlar', 'href' => '/partner/settings'];

        return $items;
    }

    /**
     * @param  mixed  $accounts
     * @return array<int, array<string, string|null>>
     */
    private function safeChildAccounts(mixed $accounts): array
    {
        if (! is_array($accounts)) {
            return [];
        }

        return collect($accounts)
            ->map(function (mixed $account): array {
                $usageType = is_array($account)
                    ? (string) ($account['usage_type'] ?? $account['cari_usage_type'] ?? 'other')
                    : 'other';

                return [
                    'usage_type' => $usageType,
                    'label' => $this->childUsageLabel($usageType),
                    'invoice_usage_note' => match ($usageType) {
                        'consignment' => 'Konsinye siparişlerinde operasyon bu alt hesabı kullanır.',
                        'showroom' => 'Teşhir işlemlerinde operasyon bu alt hesabı kullanır.',
                        'project' => 'Proje işlemlerinde operasyon bu alt hesabı kullanır.',
                        default => 'Alt cari kullanımını operasyon ekibi yönetir.',
                    },
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function safeTechnicianSummaries(B2BPartner $partner): array
    {
        return $partner->activePartnerTechnicians
            ->map(fn (B2BPartnerTechnician $link): array => [
                'name' => $link->technician?->name,
                'phone' => $link->technician?->phone,
                'city' => $link->technician?->city,
                'district' => $link->technician?->district,
                'relationship_type' => $link->relationship_type,
                'is_primary' => (bool) $link->is_primary,
            ])
            ->values()
            ->all();
    }

    private function orderStatusLabel(string $status): string
    {
        return match ($status) {
            B2BPartnerOrder::STATUS_DRAFT => 'Taslak',
            B2BPartnerOrder::STATUS_SUBMITTED => 'Gönderildi',
            B2BPartnerOrder::STATUS_OPS_REVIEW => 'Operasyon incelemesinde',
            B2BPartnerOrder::STATUS_APPROVED => 'Onaylandı',
            B2BPartnerOrder::STATUS_REJECTED => 'Reddedildi',
            B2BPartnerOrder::STATUS_CANCELLED => 'İptal edildi',
            default => $status,
        };
    }

    private function stockStatusLabel(string $status): string
    {
        return match ($status) {
            'available' => 'Uygun stok',
            'limited' => 'Sınırlı stok, operasyon onayı gerekebilir',
            'out_of_stock' => 'Stok yok, talep onay bekler',
            default => 'Stok bilgisi şu an alınamadı',
        };
    }

    private function childUsageLabel(string $usageType): string
    {
        return match ($usageType) {
            'consignment' => 'Konsinye cari',
            'showroom' => 'Teşhir cari',
            'project' => 'Proje cari',
            default => 'Alt cari',
        };
    }
}
