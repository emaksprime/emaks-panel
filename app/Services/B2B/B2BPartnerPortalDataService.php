<?php

namespace App\Services\B2B;

use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerOrder;
use App\Models\B2B\B2BPartnerTechnician;
use App\Models\B2B\B2BPartnerUserProfile;
use App\Models\TechnicalServiceAssignmentOffer;
use App\Models\TechnicalServiceEarning;
use App\Models\TechnicalServiceEarningItem;
use App\Models\TechnicalServiceCustomerConfirmation;
use App\Models\TechnicalServicePartRequest;
use App\Models\TechnicalServicePartnerJobAction;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestUpload;
use App\Models\User;
use App\Services\TechnicalService\TechnicalServiceCancelContextService;
use App\Services\TechnicalService\TechnicalServiceAdminOverrideService;
use App\Services\TechnicalService\TechnicalServiceOperationalStatePresenter;
use App\Services\TechnicalService\TechnicalServicePartRequestService;
use App\Services\TechnicalService\TechnicalServiceUiLabelService;
use App\Services\TechnicalService\TechnicalServiceWorkflowService;
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
            'display_name' => TechnicalServiceUiLabelService::displayName($partner->display_name),
            'capabilities' => $partner->capabilityCodes(),
            'phone' => $partner->phone,
            'email' => $partner->email,
            'city' => TechnicalServiceUiLabelService::cityLabel($partner->city),
            'district' => TechnicalServiceUiLabelService::districtLabel($partner->district, $partner->city),
            'address' => TechnicalServiceUiLabelService::addressLabel($partner->address ?? ($metadata['address'] ?? null)),
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
        $activeJobs = $this->serviceJobScope
            ->serviceJobsQuery($partner)
            ->with([
                'partnerJobActions' => fn ($query) => $query->latest(),
                'partRequests' => fn ($query) => $query->latest(),
                'uploads',
            ])
            ->latest('updated_at')
            ->limit(50)
            ->get()
            ->reject(fn (TechnicalServiceRequest $request): bool => $this->serviceJobScope->shouldHideActiveParentWithChild($request))
            ->values();
        $activeJobIds = $activeJobs->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $completedHistoryJobs = $this->serviceJobScope
            ->completedHistoryJobsQuery($partner)
            ->with([
                'partnerJobActions' => fn ($query) => $query->latest(),
                'partRequests' => fn ($query) => $query->latest(),
                'uploads',
            ])
            ->when($activeJobIds !== [], fn ($query) => $query->whereNotIn('id', $activeJobIds))
            ->latest('completed_at')
            ->latest('updated_at')
            ->limit(50)
            ->get()
            ->filter(fn (TechnicalServiceRequest $request): bool => $this->serviceJobScope->isCompletedHistoryJob($request))
            ->values();

        return $activeJobs
            ->concat($completedHistoryJobs)
            ->unique('id')
            ->map(fn (TechnicalServiceRequest $request): array => $this->safeServiceJobSummary($request, $partner))
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
            ['key' => 'ops_review', 'label' => 'Operasyon incelemede', 'tone' => 'violet'],
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
    public function safeServiceJobSummary(TechnicalServiceRequest $request, ?B2BPartner $viewerPartner = null): array
    {
        $request->load('uploads');
        $request->loadMissing([
            'partnerJobActions' => fn ($query) => $query->latest(),
            'customerConfirmations' => fn ($query) => $query->latest(),
            'latestAssignmentOffer.technician',
            'technicianRecord',
            'parentRequest',
            'sourcePartRequest',
            'partRequests' => fn ($query) => $query->latest(),
        ]);
        $partnerActions = $this
            ->visibleActionsForPartner($request->partnerJobActions, $viewerPartner)
            ->sortByDesc('id')
            ->values();
        if ($viewerPartner instanceof B2BPartner) {
            $request->setRelation('partnerJobActions', $partnerActions);
        }
        $currentPartnerActions = $partnerActions
            ->reject(fn (TechnicalServicePartnerJobAction $action): bool => $this->actionResolvedForCurrentWork($request, $action))
            ->values();
        $latestAction = $this->latestVisiblePartnerAction($request, $partnerActions);
        $latestAppointmentProposal = $currentPartnerActions
            ->firstWhere('action', TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED);
        $latestRejection = $currentPartnerActions
            ->first(fn (TechnicalServicePartnerJobAction $action): bool => $action->action === TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED
                && $action->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW);
        $latestCompletionSubmission = $currentPartnerActions
            ->firstWhere('action', TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED);
        $latestSupportRequest = $currentPartnerActions
            ->firstWhere('action', TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED);
        $latestOtpRequest = $currentPartnerActions
            ->firstWhere('action', TechnicalServicePartnerJobAction::ACTION_CUSTOMER_OTP_REQUESTED);
        $latestPriceRevisionRequest = $partnerActions
            ->first(fn (TechnicalServicePartnerJobAction $action): bool => $action->action === TechnicalServicePartnerJobAction::ACTION_PRICE_REVISION_REQUESTED
                && ! $this->actionResolvedForNewWork($action)
                && ! $this->actionPredatesActiveReopen($request, $action));
        $latestCustomerApprovalRejection = $currentPartnerActions
            ->firstWhere('action', TechnicalServicePartnerJobAction::ACTION_CUSTOMER_APPROVAL_REJECTED);
        $stateAction = $this->stateAction($request, $partnerActions);
        $canonicalState = $this->operationalState->present($request);
        $cancelContext = app(TechnicalServiceCancelContextService::class)->present($request, $canonicalState);
        $partnerColumn = (string) ($canonicalState['partner_column'] ?? $this->serviceJobColumn($request, $stateAction));
        $partRequestRows = $request->partRequests
            ->map(fn (TechnicalServicePartRequest $partRequest): array => app(TechnicalServicePartRequestService::class)->serialize($partRequest, forPartner: true))
            ->values();
        $activePartRequest = $partRequestRows
            ->first(fn (array $partRequest): bool => in_array((string) ($partRequest['status'] ?? ''), TechnicalServicePartRequest::ACTIVE_STATUSES, true));
        $hasOpenPartRequest = is_array($activePartRequest);
        $assignmentOffer = $request->latestAssignmentOffer;
        $earningSummary = $this->earningSummary($request, $assignmentOffer);
        $earningBreakdown = $this->earningBreakdown($request, $assignmentOffer);
        $photoReadiness = $this->portalPhotoReadiness($request);
        $latestCustomerConfirmation = $request->customerConfirmations
            ->first(fn (TechnicalServiceCustomerConfirmation $confirmation): bool => ! $this->recordPredatesActiveReopen($request, $confirmation->created_at ?? $confirmation->updated_at));
        $customerClosureStatus = TechnicalServiceUiLabelService::cleanDisplayText((string) $request->customer_closure_approval_status);
        $customerClosureApprovalIsCurrent = $request->reopened_at === null
            || ($request->customer_closure_approved_at instanceof \Carbon\CarbonInterface
                && $request->customer_closure_approved_at->greaterThan($request->reopened_at));
        $customerConfirmationReady = $latestCustomerConfirmation instanceof TechnicalServiceCustomerConfirmation
            ? $latestCustomerConfirmation->status === TechnicalServiceCustomerConfirmation::STATUS_APPROVED
            : ($customerClosureApprovalIsCurrent && in_array($customerClosureStatus, ['onaylandı', 'onaylandi', 'onaylandÄ±'], true));
        $completionRequirements = [
            'door_photos_required' => $photoReadiness['required'],
            'door_photos_uploaded' => $photoReadiness['count'],
            'photos_ready' => $photoReadiness['ready'],
            'customer_confirmation_ready' => $customerConfirmationReady,
            'part_request_clear' => ! $hasOpenPartRequest,
            'part_request_status_label' => is_array($activePartRequest) ? ($activePartRequest['status_label'] ?? null) : null,
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
        $portalPhotos = $request->uploads
            ->filter(fn (TechnicalServiceRequestUpload $upload): bool => $this->isPortalFieldDocument($upload))
            ->values();
        $currentPortalPhotoMap = $this->currentPortalFieldDocuments($request);
        $currentPortalPhotos = $currentPortalPhotoMap->values();
        $previousPortalPhotos = $portalPhotos
            ->filter(fn (TechnicalServiceRequestUpload $upload): bool => $this->portalDocumentPredatesActiveReopen($request, $upload->created_at ?? $upload->updated_at))
            ->values();
        $isCancellationReview = (bool) ($canonicalState['is_cancellation_review'] ?? false);
        $isTerminal = (bool) ($canonicalState['is_completed'] ?? false)
            || $isCancellationReview
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
        $canRequestAppointmentChange = $isAppointmentConfirmed && ! $isTerminal && ! $isFinalCheck && ! $hasOpsReviewAction;
        $canFieldActions = $isAppointmentConfirmed && $hasOpsAppointment && ! $isTerminal && ! $isFinalCheck && ! $hasOpsReviewAction && ! $hasOpenPartRequest;
        $completionReadyForSubmit = $partnerColumn === 'appointment_confirmed'
            && $canFieldActions
            && ($completionRequirements['photos_ready'] ?? false) === true
            && ($completionRequirements['customer_confirmation_ready'] ?? false) === true;
        $nextActionLabel = $this->serviceJobNextActionLabel($request, $stateAction, $completionRequirements);
        $partnerNextActionLabel = $canonicalState['display_action_label'] ?? $nextActionLabel;
        if ($completionReadyForSubmit) {
            $nextActionLabel = 'Tamamlamaya gönderilebilir';
            $partnerNextActionLabel = $nextActionLabel;
        } elseif ($isFinalCheck || $partnerColumn === 'final_check') {
            $nextActionLabel = 'Son kontrol bekliyor';
            $partnerNextActionLabel = 'Son kontrol bekliyor';
        } elseif ($nextActionLabel === 'Tamamlamaya gönderilebilir') {
            $nextActionLabel = (string) ($canonicalState['display_action_label'] ?? 'Randevu onaylandı');
        }
        if ($stateAction?->action === TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED
            && $stateAction->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
            $partnerNextActionLabel = 'Randevu önerildi';
        }
        if ($partnerNextActionLabel === 'Fotoğraf eksik') {
            $partnerNextActionLabel = 'Fotoğraf bekliyor';
        }
        if (is_array($activePartRequest) && filled($activePartRequest['status_label'] ?? null)) {
            $partnerNextActionLabel = (string) $activePartRequest['status_label'];
        }
        if ($isCancellationReview) {
            $partnerNextActionLabel = 'İptal incelemede';
        } elseif (($canonicalState['ops_column'] ?? null) === 'cancelled') {
            $partnerNextActionLabel = 'İptal edildi';
        }
        $activePartRequestOpsLabel = is_array($activePartRequest)
            ? TechnicalServicePartRequest::labelForStatus((string) ($activePartRequest['status'] ?? ''))
            : null;
        $badges = collect($this->serviceJobBadges($request, $stateAction, $completionRequirements))
            ->reject(fn (string $badge): bool => $badge === $nextActionLabel)
            ->values()
            ->all();
        $displayBadges = collect($canonicalState['display_tags'] ?? [])
            ->pluck('label')
            ->reject(function (?string $badge) use ($partnerNextActionLabel, $nextActionLabel, $activePartRequestOpsLabel): bool {
                return blank($badge)
                    || $badge === $partnerNextActionLabel
                    || $badge === 'Aksiyon: '.$partnerNextActionLabel
                    || $badge === 'Aksiyon: '.$nextActionLabel
                    || ($activePartRequestOpsLabel !== null && $badge === $activePartRequestOpsLabel)
                    || ($activePartRequestOpsLabel !== null && $badge === 'Aksiyon: '.$activePartRequestOpsLabel)
                    || ($nextActionLabel === 'Tamamlamaya gönderilebilir' && str_starts_with((string) $badge, 'Aksiyon: '));
            })
            ->values()
            ->all();
        if ($displayBadges === []) {
            $displayBadges = $badges;
        }
        if ($partnerColumn === 'appointment_confirmed') {
            $displayBadges = $this->appointmentConfirmedPartnerBadges($completionRequirements);
        }
        if ($completionReadyForSubmit && ! in_array('Tamamlamaya gönderilebilir', $displayBadges, true)) {
            array_unshift($displayBadges, 'Tamamlamaya gönderilebilir');
        }
        if (! $completionReadyForSubmit) {
            $displayBadges = array_values(array_filter(
                $displayBadges,
                fn (string $badge): bool => $badge !== 'Tamamlamaya gönderilebilir'
            ));
        }
        if ($stateAction?->action === TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED
            && $stateAction->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
            $displayBadges = ['Operasyon onayı bekleniyor'];
        }
        if ($isCancellationReview) {
            $displayBadges = ['İptal incelemede', 'Hakedişe dahil değil'];
        } elseif (($canonicalState['ops_column'] ?? null) === 'cancelled') {
            $displayBadges = ['İptal edildi', 'Hakedişe dahil değil'];
        }
        $actionState = $this->serviceJobActionState($request, $stateAction, $completionRequirements);
        if ($isFinalCheck || $partnerColumn === 'final_check') {
            $actionState = 'final_check_waiting';
        } elseif ($isTerminal || $partnerColumn === 'completed') {
            $actionState = 'completed';
        } elseif ($completionReadyForSubmit) {
            $actionState = 'completion_ready';
        } elseif ($actionState === 'completion_ready') {
            $actionState = $partnerColumn === 'appointment_confirmed' ? 'otp_waiting' : 'new';
        }
        $appointmentLabel = $this->appointmentLabel($request);
        if ($appointmentLabel === null && $hasAppointmentProposalInReview) {
            $appointmentLabel = 'Randevu önerildi';
        }
        $displayCity = TechnicalServiceUiLabelService::cityLabel($request->customer_city);
        $displayDistrict = TechnicalServiceUiLabelService::districtLabel($request->customer_district, $displayCity);
        $serialContext = $this->serviceJobSerialContext($request);
        $productName = $this->firstFilled($request->product_name, $serialContext['product_name'] ?? null);
        $productModel = $this->firstFilled($request->product_model, $serialContext['product_model'] ?? null);
        $productBrand = $this->firstFilled($request->brand, $serialContext['brand'] ?? null);
        $viewContext = $this->partnerServiceJobViewContext($request, $partnerColumn);
        $isCompletedHistoryView = in_array($viewContext, ['completed_history', 'completed_parent'], true);
        $shouldShowCompletionRequirements = ! $isCompletedHistoryView;
        $shouldShowCurrentActions = ! $isCompletedHistoryView && ! $isTerminal;
        $effectiveCompletionRequirements = $isCompletedHistoryView
            ? $this->completedHistoryCompletionRequirements()
            : $completionRequirements;
        $effectiveDisplayBadges = $isCompletedHistoryView
            ? $this->completedHistoryBadges($displayBadges)
            : $displayBadges;

        return [
            'id' => $request->id,
            'mrn' => $request->mrn,
            'parent_request_id' => $request->parent_request_id,
            'root_mrn' => $request->root_mrn,
            'service_code' => $request->service_code,
            'view_context' => $viewContext,
            'is_current_active_assignment' => ! $isCompletedHistoryView && ! $isTerminal,
            'is_completed_history_view' => $isCompletedHistoryView,
            'should_show_completion_requirements' => $shouldShowCompletionRequirements,
            'should_show_current_actions' => $shouldShowCurrentActions,
            'status_label' => TechnicalServiceUiLabelService::cleanDisplayText($request->status),
            'service_stage_label' => TechnicalServiceUiLabelService::cleanDisplayText($request->workflow_status),
            'customer_name' => TechnicalServiceUiLabelService::cleanDisplayText($request->customer_name),
            'customer_phone' => $request->customer_phone,
            'city' => $displayCity,
            'district' => $displayDistrict,
            'address_summary' => TechnicalServiceUiLabelService::addressLabel($request->service_address),
            'product_name' => TechnicalServiceUiLabelService::cleanDisplayText($productName),
            'product_model' => TechnicalServiceUiLabelService::cleanDisplayText($productModel),
            'model' => TechnicalServiceUiLabelService::cleanDisplayText($productModel),
            'brand' => TechnicalServiceUiLabelService::cleanDisplayText($productBrand),
            'stock_code' => $this->firstFilled($request->stock_code, $serialContext['stock_code'] ?? null),
            'activation_code' => $this->firstFilled($request->activation_code, $serialContext['activation_code'] ?? null),
            'serial_context' => $serialContext,
            'serial_no' => $request->serial_number,
            'service_type' => $this->displayServiceType($request),
            'scheduled_at' => $request->scheduled_at?->toIso8601String(),
            'scheduled_date' => $request->scheduled_date?->toDateString(),
            'appointment_at' => $request->scheduled_at?->toIso8601String() ?? $request->scheduled_date?->toDateString(),
            'appointment_label' => TechnicalServiceUiLabelService::cleanDisplayText($appointmentLabel),
            'priority' => $request->priority,
            'status' => TechnicalServiceUiLabelService::cleanDisplayText($request->status),
            'workflow_status' => TechnicalServiceUiLabelService::cleanDisplayText($request->workflow_status),
            'next_action' => TechnicalServiceUiLabelService::cleanDisplayText($partnerNextActionLabel),
            'field_action_hint' => $shouldShowCompletionRequirements && $partnerColumn === 'appointment_confirmed'
                ? $this->appointmentConfirmedPartnerHint($completionRequirements)
                : null,
            'route_distance_summary' => $request->travel_round_trip_km !== null ? ((float) $request->travel_round_trip_km).' km' : null,
            'payment_status_summary' => TechnicalServiceUiLabelService::cleanDisplayText($request->mount_payment_label ?? $request->mount_payment_status),
            'maps_link' => $this->mapsLink($request),
            'customer_tel_link' => $this->telLink($request->customer_phone),
            'checklist_status' => $request->checklist_status,
            'checklist_payload' => [],
            'photo_counts' => $isCompletedHistoryView ? [
                'before' => 0,
                'after' => 0,
                'general' => 0,
            ] : [
                'before' => in_array('before_photo', $photoReadiness['present_fields'], true) ? 1 : 0,
                'after' => in_array('after_photo', $photoReadiness['present_fields'], true) ? 1 : 0,
                'general' => in_array('warranty_document_photo', $photoReadiness['present_fields'], true) ? 1 : 0,
            ],
            'photos' => $currentPortalPhotos
                ->map(fn (TechnicalServiceRequestUpload $upload): array => $this->portalPhotoPayload($request, $upload))
                ->values()
                ->all(),
            'current_field_documents' => $isCompletedHistoryView ? $this->emptyPortalFieldDocuments() : collect(self::requiredPortalPhotoFields())
                ->mapWithKeys(fn (string $label, string $field): array => [
                    $field => $currentPortalPhotoMap->has($field)
                        ? $this->portalPhotoPayload($request, $currentPortalPhotoMap->get($field))
                        : null,
                ])
                ->all(),
            'previous_photos' => $previousPortalPhotos
                ->map(fn (TechnicalServiceRequestUpload $upload): array => $this->portalPhotoPayload($request, $upload))
                ->values()
                ->all(),
            'latest_partner_action' => $latestAction ? [
                'action' => $latestAction->action,
                'action_label' => TechnicalServiceUiLabelService::actionLabel($latestAction->action),
                'status' => $latestAction->status,
                'status_label' => TechnicalServiceUiLabelService::statusLabel($latestAction->status),
                'note' => TechnicalServiceUiLabelService::cleanDisplayText($latestAction->note),
                'payload' => is_array($latestAction->payload) ? $latestAction->payload : [],
                'created_at' => $latestAction->created_at?->toIso8601String(),
            ] : null,
            'portal_actions' => $partnerActions
                ->take(8)
                ->map(fn (TechnicalServicePartnerJobAction $action): array => [
                    'id' => $action->id,
                    'action' => $action->action,
                    'action_label' => TechnicalServiceUiLabelService::actionLabel($action->action),
                    'status' => $action->status,
                    'status_label' => TechnicalServiceUiLabelService::statusLabel($action->status),
                    'note' => TechnicalServiceUiLabelService::cleanDisplayText($action->note),
                    'payload' => is_array($action->payload) ? $action->payload : [],
                    'created_at' => $action->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'appointment_proposal' => $latestAppointmentProposal ? [
                'id' => $latestAppointmentProposal->id,
                'status' => $latestAppointmentProposal->status,
                'status_label' => TechnicalServiceUiLabelService::statusLabel($latestAppointmentProposal->status),
                'note' => TechnicalServiceUiLabelService::cleanDisplayText($latestAppointmentProposal->note),
                'payload' => is_array($latestAppointmentProposal->payload) ? $latestAppointmentProposal->payload : [],
                'created_at' => $latestAppointmentProposal->created_at?->toIso8601String(),
            ] : null,
            'rejection' => $latestRejection ? [
                'id' => $latestRejection->id,
                'status' => $latestRejection->status,
                'status_label' => TechnicalServiceUiLabelService::statusLabel($latestRejection->status),
                'note' => TechnicalServiceUiLabelService::cleanDisplayText($latestRejection->note),
                'payload' => is_array($latestRejection->payload) ? $latestRejection->payload : [],
                'created_at' => $latestRejection->created_at?->toIso8601String(),
            ] : null,
            'support_request' => $latestSupportRequest ? [
                'id' => $latestSupportRequest->id,
                'status' => $latestSupportRequest->status,
                'status_label' => TechnicalServiceUiLabelService::statusLabel($latestSupportRequest->status),
                'note' => TechnicalServiceUiLabelService::cleanDisplayText($latestSupportRequest->note),
                'payload' => is_array($latestSupportRequest->payload) ? $latestSupportRequest->payload : [],
                'created_at' => $latestSupportRequest->created_at?->toIso8601String(),
            ] : null,
            'part_requests' => $partRequestRows->all(),
            'active_part_request' => is_array($activePartRequest) ? $activePartRequest : null,
            'can_receive_part' => $shouldShowCurrentActions
                && is_array($activePartRequest)
                && ($activePartRequest['status'] ?? null) === TechnicalServicePartRequest::STATUS_SENT,
            'price_revision_request' => $latestPriceRevisionRequest ? [
                'id' => $latestPriceRevisionRequest->id,
                'status' => $latestPriceRevisionRequest->status,
                'status_label' => TechnicalServiceUiLabelService::statusLabel($latestPriceRevisionRequest->status),
                'note' => TechnicalServiceUiLabelService::cleanDisplayText($latestPriceRevisionRequest->note),
                'payload' => is_array($latestPriceRevisionRequest->payload) ? $latestPriceRevisionRequest->payload : [],
                'created_at' => $latestPriceRevisionRequest->created_at?->toIso8601String(),
            ] : null,
            'customer_otp_request' => $latestOtpRequest ? [
                'id' => $latestOtpRequest->id,
                'status' => $latestOtpRequest->status,
                'status_label' => TechnicalServiceUiLabelService::statusLabel($latestOtpRequest->status),
                'note' => TechnicalServiceUiLabelService::cleanDisplayText($latestOtpRequest->note),
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
                'status_label' => TechnicalServiceUiLabelService::statusLabel($latestCompletionSubmission->status),
                'note' => TechnicalServiceUiLabelService::cleanDisplayText($latestCompletionSubmission->note),
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
                'note' => TechnicalServiceUiLabelService::cleanDisplayText($assignmentOffer->note),
                'sent_at' => $assignmentOffer->sent_at?->toIso8601String(),
                'message_payload' => is_array($assignmentOffer->metadata) ? ($assignmentOffer->metadata['message_payload'] ?? null) : null,
            ] : null,
            'earning_summary' => [
                'labor_amount' => $earningSummary['labor_amount'],
                'route_fee_amount' => $earningSummary['route_fee_amount'],
                'total_amount' => $earningSummary['total_amount'],
                'status' => $earningSummary['status'],
                'job_count' => $earningSummary['job_count'],
                'related_mrns' => $earningSummary['related_mrns'],
                'excluded_from_payable' => (bool) ($earningSummary['excluded_from_payable'] ?? false),
                'exclusion_label' => $earningSummary['exclusion_label'] ?? null,
            ],
            'earning_breakdown' => $earningBreakdown,
            'completion_requirements' => $effectiveCompletionRequirements,
            'badges' => $effectiveDisplayBadges,
            'card_priority' => $completionReadyForSubmit
                ? 4
                : ($canonicalState['sort_priority'] ?? $this->serviceJobPriority($stateAction)),
            'card_tone' => $isTerminal
                ? (string) ($canonicalState['card_tone'] ?? 'muted')
                : ($hasOpenPartRequest ? 'violet' : $this->serviceJobTone($request, $stateAction)),
            'kanban_column' => $partnerColumn,
            'operational_state' => $canonicalState,
            'cancel_context' => $cancelContext,
            'current_stage_summary' => app(TechnicalServiceCancelContextService::class)->currentStageSummary($request, $canonicalState),
            'service_visit_context' => $this->serviceVisitContext($request),
            'action_state' => $actionState,
            'can_accept' => $shouldShowCurrentActions && $canAcceptAppointment,
            'can_propose_appointment' => $shouldShowCurrentActions && $canProposeAppointment,
            'can_request_appointment_change' => $shouldShowCurrentActions && $canRequestAppointmentChange,
            'can_request_revisit' => $shouldShowCurrentActions && ($canFieldActions || $this->serviceJobColumn($request, $stateAction) === 'revisit'),
            'can_request_support' => $shouldShowCurrentActions && ($canFieldActions || $this->serviceJobColumn($request, $stateAction) === 'revisit'),
            'can_request_customer_otp' => $shouldShowCurrentActions && $canFieldActions,
            'can_upload_photos' => $shouldShowCurrentActions && $canFieldActions,
            'can_submit_completion' => $shouldShowCurrentActions && $completionReadyForSubmit,
            'can_request_price_revision' => $shouldShowCurrentActions && ! $isTerminal && ! $isRejectedInReview && $assignmentOffer !== null,
            'can_request_correction' => $shouldShowCurrentActions && ! $isTerminal,
            'correction_requests' => collect(app(TechnicalServiceAdminOverrideService::class)->serializeForRequest($request))
                ->filter(fn (array $override): bool => ($override['source'] ?? null) === \App\Models\TechnicalServiceAdminOverride::SOURCE_PARTNER_REQUEST)
                ->take(6)
                ->values()
                ->all(),
            'can_complete_directly' => false,
            'can_reject' => $shouldShowCurrentActions && ! $isTerminal && ! $isFinalCheck && ! $isRejectedInReview,
            'updated_at' => $request->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, TechnicalServicePartnerJobAction>  $actions
     * @return Collection<int, TechnicalServicePartnerJobAction>
     */
    private function visibleActionsForPartner(Collection $actions, ?B2BPartner $viewerPartner): Collection
    {
        if (! $viewerPartner instanceof B2BPartner) {
            return $actions;
        }

        return $actions
            ->filter(function (TechnicalServicePartnerJobAction $action) use ($viewerPartner): bool {
                if ((int) $action->partner_id !== (int) $viewerPartner->id) {
                    return false;
                }

                $payload = is_array($action->payload) ? $action->payload : [];
                $visibility = (string) ($payload['visibility'] ?? '');

                return ! in_array($visibility, ['ops_internal', 'internal_partner'], true);
            })
            ->values();
    }

    private function partnerServiceJobViewContext(TechnicalServiceRequest $request, string $partnerColumn): string
    {
        if (
            $partnerColumn === 'completed'
            || ($this->serviceJobScope->isCompletedHistoryJob($request) && ! $this->isActiveReopenedWork($request))
        ) {
            return $this->hasNonCancelledChildServiceVisit($request)
                ? 'completed_parent'
                : 'completed_history';
        }

        return $request->parent_request_id !== null || filled($request->service_code)
            ? 'child_active'
            : 'active_current';
    }

    private function hasNonCancelledChildServiceVisit(TechnicalServiceRequest $request): bool
    {
        if ($request->parent_request_id !== null) {
            return false;
        }

        return $request->childRequests()
            ->whereNull('cancelled_at')
            ->whereNotIn('status', ['İptal', 'Iptal', 'Ä°ptal'])
            ->whereNotIn('workflow_status', ['İptal', 'Iptal', 'Ä°ptal'])
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function completedHistoryCompletionRequirements(): array
    {
        return [
            'door_photos_required' => 0,
            'door_photos_uploaded' => 0,
            'photos_ready' => true,
            'customer_confirmation_ready' => true,
            'part_request_clear' => true,
            'part_request_status_label' => null,
            'checklist_required' => false,
            'ops_final_check_required' => false,
            'required_photo_labels' => [],
            'missing_photo_labels' => [],
            'photo_statuses' => [],
        ];
    }

    /**
     * @return array<string, null>
     */
    private function emptyPortalFieldDocuments(): array
    {
        return collect(self::requiredPortalPhotoFields())
            ->mapWithKeys(fn (string $label, string $field): array => [$field => null])
            ->all();
    }

    /**
     * @param  array<int, string>  $badges
     * @return array<int, string>
     */
    private function completedHistoryBadges(array $badges): array
    {
        $filtered = collect($badges)
            ->reject(fn (string $badge): bool => in_array($badge, [
                'Fotoğraf bekliyor',
                'Fotoğraflar yüklendi',
                'Müşteri onayı bekleniyor',
                'Müşteri onayı alındı',
                'Tamamlamaya gönderilebilir',
            ], true))
            ->values()
            ->all();

        return $filtered === [] ? ['İş tamamlandı'] : $filtered;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serviceVisitContext(TechnicalServiceRequest $request): ?array
    {
        $isServiceVisit = filled($request->service_code) || $request->parent_request_id !== null;
        $rootMrn = (string) ($request->root_mrn ?: $request->parentRequest?->mrn);

        if (! $isServiceVisit && $rootMrn === '') {
            return null;
        }

        $root = $rootMrn !== ''
            ? TechnicalServiceRequest::query()
                ->where('mrn', $rootMrn)
                ->orderByRaw('case when parent_request_id is null then 0 else 1 end')
                ->oldest('id')
                ->first()
            : null;
        $siblings = collect();
        if ($rootMrn !== '') {
            $siblings = TechnicalServiceRequest::query()
                ->where('root_mrn', $rootMrn)
                ->where('id', '!=', $request->id)
                ->orderBy('service_sequence')
                ->limit(8)
                ->get()
                ->map(fn (TechnicalServiceRequest $sibling): array => [
                    'id' => $sibling->id,
                    'mrn' => $sibling->mrn,
                    'service_code' => $sibling->service_code,
                    'status_label' => TechnicalServiceUiLabelService::cleanDisplayText($sibling->workflow_status ?: $sibling->status),
                ]);
        }
        $historyRecords = collect([$root])
            ->filter(fn ($record): bool => $record instanceof TechnicalServiceRequest)
            ->merge($siblings)
            ->merge([$request])
            ->unique(fn (TechnicalServiceRequest|array $record): int => (int) (is_array($record) ? $record['id'] : $record->id))
            ->map(function (TechnicalServiceRequest|array $record) use ($root, $request): array {
                if (is_array($record)) {
                    $code = (string) ($record['service_code'] ?: $record['mrn']);

                    return [
                        ...$record,
                        'code' => $code,
                        'type' => 'srv',
                        'label' => 'Servis ziyareti',
                        'is_current' => (int) $record['id'] === (int) $request->id,
                    ];
                }

                $isRoot = $root instanceof TechnicalServiceRequest && (int) $record->id === (int) $root->id;

                return [
                    'id' => $record->id,
                    'mrn' => $record->mrn,
                    'service_code' => $record->service_code,
                    'code' => $record->service_code ?: $record->mrn,
                    'status_label' => TechnicalServiceUiLabelService::cleanDisplayText($record->workflow_status ?: $record->status),
                    'type' => $isRoot ? 'root_mrn' : 'srv',
                    'label' => $isRoot ? 'Ana talep' : 'Servis ziyareti',
                    'is_current' => (int) $record->id === (int) $request->id,
                ];
            })
            ->values();

        return [
            'parent_request_id' => $request->parent_request_id,
            'root_mrn' => $rootMrn !== '' ? $rootMrn : null,
            'parent_mrn' => $request->parentRequest?->mrn,
            'root_request' => $root instanceof TechnicalServiceRequest ? [
                'id' => $root->id,
                'mrn' => $root->mrn,
                'service_code' => $root->service_code,
                'status_label' => TechnicalServiceUiLabelService::cleanDisplayText($root->workflow_status ?: $root->status),
            ] : null,
            'service_code' => $request->service_code,
            'reason' => $request->service_visit_reason,
            'reason_label' => TechnicalServiceUiLabelService::serviceVisitReasonLabel($request->service_visit_reason),
            'source_part_request_id' => $request->source_part_request_id,
            'summary' => $request->service_code
                ? 'Ana talep '.$rootMrn.' altında '.$request->service_code
                : ($rootMrn !== '' ? 'Ana talep '.$rootMrn : null),
            'sibling_service_visits' => $siblings->values()->all(),
            'history_records' => $historyRecords->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function earningsFor(B2BPartner $partner): array
    {
        $technicianIds = $this->serviceJobScope->activeTechnicianIds($partner);

        $rows = TechnicalServiceEarning::query()
            ->with([
                'period',
                'items.request.latestAssignmentOffer',
                'items.request.parentRequest.latestAssignmentOffer',
                'items.request.childRequests.latestAssignmentOffer',
            ])
            ->whereIn('technical_service_technician_id', $technicianIds)
            ->orderByDesc('updated_at')
            ->limit(24)
            ->get()
            ->map(fn (TechnicalServiceEarning $earning): array => $this->persistedEarningRow($earning))
            ->values();
        $earningRequestIds = $rows
            ->flatMap(fn (array $row): array => collect($row['items'] ?? [])->pluck('technical_service_request_id')->filter()->all())
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $canonicalRows = $this->partnerCanonicalEarningRows($partner, $earningRequestIds);
        $completedJobRows = $canonicalRows
            ->filter(fn (array $row): bool => ($row['bucket'] ?? null) === 'completed_payable')
            ->map(fn (array $row): array => $this->completedEarningRowFromCanonical($row))
            ->values();
        $completedRows = $rows->concat($completedJobRows)->values();
        $pendingRows = $canonicalRows
            ->filter(fn (array $row): bool => ($row['bucket'] ?? null) === 'pending_estimated')
            ->values();
        $excludedRows = $canonicalRows
            ->filter(fn (array $row): bool => ($row['bucket'] ?? null) === 'excluded')
            ->values();
        $pendingSummary = [
            'job_count' => $pendingRows->sum('job_count'),
            'labor_total' => $pendingRows->sum('labor_amount'),
            'travel_fee_total' => $pendingRows->sum('travel_fee_amount'),
            'grand_total' => $pendingRows->sum('line_total'),
        ];
        $excludedSummary = [
            'job_count' => $excludedRows->sum('job_count'),
            'labor_total' => $excludedRows->sum('labor_amount'),
            'travel_fee_total' => $excludedRows->sum('travel_fee_amount'),
            'grand_total' => $excludedRows->sum('line_total'),
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
                'label' => 'Hakediş onayı bekleyen tamamlanan işler',
                'note' => 'Bu işler tamamlandı; hakediş operasyon onayı veya gönderimi sonrası kesinleşir.',
            ],
            'completed' => [
                'rows' => $completedRows->all(),
                'summary' => $completedSummary,
                'label' => 'Kesinleşen / gönderilen hakedişler',
                'note' => 'Operasyon tarafından gönderilen, onaylanan veya kesinleşen hakediş kayıtlarıdır.',
            ],
            'excluded' => [
                'rows' => $excludedRows->all(),
                'summary' => $excludedSummary,
                'note' => 'İptal veya inceleme nedeniyle hakedişe dahil edilmeyen işler aktif toplamları etkilemez.',
            ],
            'totals' => [
                'pending_count' => $pendingSummary['job_count'],
                'pending_total' => $pendingSummary['grand_total'],
                'completed_count' => $completedSummary['job_count'],
                'completed_total' => $completedSummary['grand_total'],
            ],
            'summary' => [
                'job_count' => $completedRows->sum('job_count'),
                'labor_total' => $completedRows->sum('labor_total'),
                'travel_fee_total' => $completedRows->sum('travel_fee_total'),
                'grand_total' => $completedRows->sum('grand_total'),
            ],
        ];
    }

    /**
     * @param array<int, int> $excludedRequestIds
     * @return Collection<int, array<string, mixed>>
     */
    private function partnerCanonicalEarningRows(B2BPartner $partner, array $excludedRequestIds = []): Collection
    {
        $activeRows = $this->serviceJobScope
            ->serviceJobsQuery($partner)
            ->with(['latestAssignmentOffer.technician', 'technicianRecord'])
            ->latest('updated_at')
            ->limit(100)
            ->get();

        $completedRows = $this->serviceJobScope
            ->completedHistoryJobsQuery($partner)
            ->with(['latestAssignmentOffer.technician', 'technicianRecord'])
            ->where(function ($query): void {
                $query
                    ->whereNotNull('completed_at')
                    ->orWhereNotNull('installation_completed_at')
                    ->orWhereIn('workflow_status', ['Tamamlandı', 'Tamamlandi', 'TamamlandÄ±']);
            })
            ->latest('completed_at')
            ->latest('updated_at')
            ->limit(100)
            ->get()
            ->filter(fn (TechnicalServiceRequest $request): bool => $this->serviceJobScope->isCompletedHistoryJob($request));

        return $activeRows
            ->concat($completedRows)
            ->unique('id')
            ->reject(fn (TechnicalServiceRequest $request): bool => in_array((int) $request->id, $excludedRequestIds, true))
            ->map(fn (TechnicalServiceRequest $request): array => $this->partnerCanonicalEarningRow($request))
            ->filter(fn (array $row): bool => (float) ($row['line_total'] ?? 0) > 0 || (bool) ($row['excluded_from_payable'] ?? false))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function partnerCanonicalEarningRow(TechnicalServiceRequest $request): array
    {
        $request->loadMissing(['latestAssignmentOffer.technician', 'technicianRecord']);
        $summary = $this->singleEarningSummary($request, $request->latestAssignmentOffer);
        $bucket = $this->partnerEarningBucket($request, $summary);
        $earningStatus = $this->partnerEarningStatus(
            $summary['status'] ?? null,
            $bucket,
            $this->hasOpsFinalPayoutApproval($request),
        );
        $statusLabel = $this->partnerEarningDisplayStatus($earningStatus, $bucket);
        $paymentStatus = $this->payoutPaymentStatusPayload($request);
        $scheduledAt = $request->scheduled_at?->toIso8601String() ?? $request->scheduled_date?->toDateString();
        $jobStatus = $this->partnerEarningJobStatus($request);

        return [
            'id' => $request->id,
            'request_id' => $request->id,
            'mrn' => $request->mrn,
            'code' => $request->mrn,
            'root_mrn' => $request->root_mrn,
            'customer_name' => TechnicalServiceUiLabelService::cleanDisplayText($request->customer_name),
            'scheduled_at' => $scheduledAt,
            'appointment_at' => $scheduledAt,
            'job_date' => $request->completed_at?->toDateString()
                ?? $request->installation_completed_at?->toDateString()
                ?? $request->scheduled_date?->toDateString(),
            'job_count' => 1,
            'labor_amount' => round((float) $summary['labor_amount'], 2),
            'travel_fee_amount' => round((float) $summary['route_fee_amount'], 2),
            'line_total' => round((float) $summary['total_amount'], 2),
            'related_mrns' => array_values(array_filter([(string) $request->mrn])),
            'technician_id' => $summary['technician_id'] ?? null,
            'technician_name' => $summary['technician_name'] ?? null,
            'status' => $statusLabel,
            'status_label' => $statusLabel,
            'job_status' => $jobStatus,
            'job_status_label' => $this->partnerEarningJobStatusLabel($jobStatus),
            'earning_status' => $earningStatus,
            'earning_status_label' => $statusLabel,
            'offer_status' => $earningStatus,
            'bucket' => $bucket,
            'earning_bucket' => $bucket,
            'earning_bucket_label' => $this->partnerEarningBucketLabel($bucket),
            'actual' => $bucket === 'completed_payable',
            'excluded_from_payable' => $bucket === 'excluded',
            'exclusion_label' => $bucket === 'excluded' ? 'Hakedişe dahil değil' : null,
            'amount' => round((float) $summary['total_amount'], 2),
            'payment_record_status' => $paymentStatus['status'],
            'payment_record_status_label' => $paymentStatus['status_label'],
            'paid_at' => $paymentStatus['paid_at'],
            'explanation' => $bucket === 'pending_estimated'
                ? 'İş tamamlandı; hakediş operasyon onayı veya gönderimi sonrası kesinleşir.'
                : null,
            'note' => $bucket === 'pending_estimated'
                ? 'Kesin hakediş değildir.'
                : null,
            'city' => TechnicalServiceUiLabelService::cityLabel($request->customer_city),
            'district' => TechnicalServiceUiLabelService::districtLabel($request->customer_district, $request->customer_city),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function completedEarningRowFromCanonical(array $row): array
    {
        return [
            'id' => 'completed-job-'.$row['request_id'],
            'period' => 'Dönem bekliyor',
            'job_count' => $row['job_count'],
            'labor_total' => $row['labor_amount'],
            'travel_fee_total' => $row['travel_fee_amount'],
            'grand_total' => $row['line_total'],
            'status' => $row['status_label'],
            'payment_record_status_label' => $row['payment_record_status_label'],
            'paid_at' => $row['paid_at'],
            'items' => [[
                'technical_service_request_id' => $row['request_id'],
                'job_date' => $row['job_date'],
                'mrn' => $row['mrn'],
                'root_mrn' => $row['root_mrn'],
                'customer_name' => $row['customer_name'],
                'city' => $row['city'],
                'district' => $row['district'],
                'labor_amount' => $row['labor_amount'],
                'travel_fee_amount' => $row['travel_fee_amount'],
                'line_total' => $row['line_total'],
                'technician_id' => $row['technician_id'],
                'technician_name' => $row['technician_name'],
                'status' => $row['status_label'],
                'job_status' => $row['job_status'],
                'job_status_label' => $row['job_status_label'],
                'earning_status' => $row['earning_status'],
                'earning_status_label' => $row['earning_status_label'],
                'earning_bucket' => $row['earning_bucket'],
                'earning_bucket_label' => $row['earning_bucket_label'],
                'payment_record_status_label' => $row['payment_record_status_label'],
                'related_mrns' => $row['related_mrns'],
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function partnerEarningBucket(TechnicalServiceRequest $request, array $summary): string
    {
        $status = mb_strtolower(trim((string) ($summary['status'] ?? '')));

        if ($this->isEarningExcludedServiceJob($request)
            || in_array($status, ['cancelled', 'canceled', 'excluded', 'excluded_from_payable', 'cancel_review'], true)) {
            return 'excluded';
        }

        return $this->isCompletedForPortalEarnings($request)
            && (in_array($status, ['sent', 'submitted', 'accepted', 'approved', 'confirmed', 'final', 'finalized', 'payable', 'paid'], true)
                || $this->hasOpsFinalPayoutApproval($request))
            ? 'completed_payable'
            : 'pending_estimated';
    }

    private function partnerEarningDisplayStatus(?string $status, string $bucket): string
    {
        if ($bucket === 'excluded') {
            return 'Hakedişe dahil değil';
        }

        $label = $this->earningStatusLabel($status);

        if ($label !== 'Hakediş yok') {
            return $label;
        }

        return $bucket === 'pending_estimated' ? 'Taslak' : 'Kesinleşti';
    }

    private function partnerEarningStatus(?string $status, string $bucket, bool $opsFinalApproved = false): string
    {
        $normalized = mb_strtolower(trim((string) $status));

        if ($bucket === 'excluded') {
            return 'excluded_from_payable';
        }

        if ($bucket === 'completed_payable'
            && $opsFinalApproved
            && in_array($normalized, ['', 'draft', 'pending', 'prepared_not_sent', 'proposed', 'estimate'], true)) {
            return 'finalized';
        }

        if ($normalized === '' || $this->earningStatusLabel($normalized) === 'Hakediş yok') {
            return $bucket === 'pending_estimated' ? 'draft' : 'finalized';
        }

        return $normalized;
    }

    private function partnerEarningBucketLabel(string $bucket): string
    {
        return match ($bucket) {
            'pending_estimated' => 'Hakediş onayı bekliyor',
            'completed_payable' => 'Kesinleşen/gönderilen hakediş',
            'excluded' => 'Hakedişe dahil değil',
            default => 'Hakediş durumu',
        };
    }

    private function partnerEarningJobStatus(TechnicalServiceRequest $request): string
    {
        if ($this->isEarningExcludedServiceJob($request)) {
            return 'cancelled';
        }

        return $this->isCompletedForPortalEarnings($request) ? 'completed' : 'active';
    }

    private function partnerEarningJobStatusLabel(string $status): string
    {
        return match ($status) {
            'completed' => 'İş tamamlandı',
            'cancelled' => 'İş iptal edildi',
            default => 'İş devam ediyor',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function persistedEarningRow(TechnicalServiceEarning $earning): array
    {
        $items = $this->persistedEarningItems($earning);

        return [
            'id' => $earning->id,
            'period' => $earning->period ? $earning->period->year.'-'.$earning->period->month : null,
            'job_count' => $items->sum(fn (array $item): int => (int) ($item['job_count'] ?? 1)),
            'labor_total' => round((float) $items->sum('labor_amount'), 2),
            'travel_fee_total' => round((float) $items->sum('travel_fee_amount'), 2),
            'grand_total' => round((float) $items->sum('line_total'), 2),
            'status' => $earning->status,
            'paid_at' => $earning->paid_at?->toIso8601String(),
            'items' => $items
                ->map(function (array $item): array {
                    unset($item['job_count']);

                    return $item;
                })
                ->values()
                ->all(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function persistedEarningItems(TechnicalServiceEarning $earning): Collection
    {
        $seenGroups = [];
        $items = collect();

        foreach ($earning->items as $item) {
            $groupKey = $this->persistedEarningGroupKey($item);

            if ($groupKey !== null && isset($seenGroups[$groupKey])) {
                continue;
            }

            if ($groupKey !== null) {
                $seenGroups[$groupKey] = true;
            }

            $items->push($this->persistedEarningItemRow($earning, $item));
        }

        return $items->values();
    }

    private function persistedEarningGroupKey(TechnicalServiceEarningItem $item): ?string
    {
        $request = $item->request;

        if ($request instanceof TechnicalServiceRequest) {
            return 'service-request-id:'.$request->id;
        }

        return $item->id !== null ? 'earning-item:'.$item->id : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function persistedEarningItemRow(TechnicalServiceEarning $earning, TechnicalServiceEarningItem $item): array
    {
        $request = $item->request;

        if ($request instanceof TechnicalServiceRequest) {
            $request->loadMissing(['latestAssignmentOffer.technician', 'technicianRecord']);
            $earningSummary = $this->singleEarningSummary($request, $request->latestAssignmentOffer);
            $status = $this->partnerEarningDisplayStatus($earningSummary['status'] ?? $earning->status, 'completed_payable');

            return [
                'technical_service_request_id' => $request->id,
                'job_date' => $item->job_date?->toDateString()
                    ?? $request->completed_at?->toDateString()
                    ?? $request->installation_completed_at?->toDateString()
                    ?? $request->scheduled_date?->toDateString(),
                'mrn' => $request->mrn ?: $item->mrn,
                'city' => TechnicalServiceUiLabelService::cityLabel($item->customer_city ?: $request->customer_city),
                'district' => TechnicalServiceUiLabelService::districtLabel($item->customer_district ?: $request->customer_district, $item->customer_city ?: $request->customer_city),
                'labor_amount' => $earningSummary['labor_amount'],
                'travel_fee_amount' => $earningSummary['route_fee_amount'],
                'line_total' => $earningSummary['total_amount'],
                'technician_id' => $earningSummary['technician_id'],
                'technician_name' => $earningSummary['technician_name'],
                'status' => $status,
                'related_mrns' => array_values(array_filter([(string) $request->mrn])),
                'job_count' => 1,
            ];
        }

        return [
            'technical_service_request_id' => $item->technical_service_request_id,
            'job_date' => $item->job_date?->toDateString(),
            'mrn' => $item->mrn,
            'city' => TechnicalServiceUiLabelService::cityLabel($item->customer_city),
            'district' => TechnicalServiceUiLabelService::districtLabel($item->customer_district, $item->customer_city),
            'labor_amount' => (float) $item->labor_amount,
            'travel_fee_amount' => (float) $item->travel_fee_amount,
            'line_total' => (float) $item->line_total,
            'technician_id' => $earning->technical_service_technician_id,
            'technician_name' => TechnicalServiceUiLabelService::displayName($earning->technician_name_snapshot),
            'status' => $earning->status,
            'job_count' => 1,
        ];
    }

    private function isCompletedForPortalEarnings(TechnicalServiceRequest $request): bool
    {
        if ($request->completed_at !== null || $request->installation_completed_at !== null) {
            return true;
        }

        return in_array((string) $request->workflow_status, ['Tamamlandı', 'Tamamlandi', 'TamamlandÄ±'], true)
            || in_array((string) $request->status, ['Tamamlandı', 'Tamamlandi', 'TamamlandÄ±'], true);
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
     * @return array{labor_amount:float,route_fee_amount:float,total_amount:float,status:string|null,job_count:int,related_mrns:array<int, string>}
     */
    private function earningSummary(TechnicalServiceRequest $request, mixed $assignmentOffer): array
    {
        $requests = $this->earningGroupRequests($request);

        if ($requests->isEmpty()) {
            if ($this->isEarningExcludedServiceJob($request)) {
                $excluded = $this->singleEarningSummary($request, $assignmentOffer);

                return [
                    'labor_amount' => 0.0,
                    'route_fee_amount' => 0.0,
                    'total_amount' => 0.0,
                    'status' => $excluded['status'],
                    'job_count' => 1,
                    'technician_id' => $excluded['technician_id'],
                    'technician_name' => $excluded['technician_name'],
                    'technician_names' => array_values(array_filter([(string) ($excluded['technician_name'] ?? '')])),
                    'technician_count' => filled($excluded['technician_name'] ?? null) ? 1 : 0,
                    'is_multi_technician' => false,
                    'related_mrns' => array_values(array_filter([(string) $request->mrn])),
                    'excluded_from_payable' => true,
                    'exclusion_label' => 'İptal nedeniyle hakedişe dahil değil',
                ];
            }

            $requests = collect([$request]);
        }

        $summaries = $requests
            ->map(fn (TechnicalServiceRequest $related): array => $this->singleEarningSummary(
                $related,
                $related->id === $request->id ? $assignmentOffer : $related->latestAssignmentOffer,
            ));
        $technicianNames = $summaries
            ->pluck('technician_name')
            ->filter(fn (mixed $name): bool => filled($name))
            ->map(fn (mixed $name): string => (string) $name)
            ->unique()
            ->values();

        return [
            'labor_amount' => round((float) $summaries->sum('labor_amount'), 2),
            'route_fee_amount' => round((float) $summaries->sum('route_fee_amount'), 2),
            'total_amount' => round((float) $summaries->sum('total_amount'), 2),
            'status' => $summaries->pluck('status')->filter()->last(),
            'job_count' => $requests->count(),
            'technician_id' => $summaries->pluck('technician_id')->filter()->unique()->count() === 1
                ? $summaries->pluck('technician_id')->filter()->first()
                : null,
            'technician_name' => $technicianNames->count() === 1 ? $technicianNames->first() : null,
            'technician_names' => $technicianNames->all(),
            'technician_count' => $technicianNames->count(),
            'is_multi_technician' => $technicianNames->count() > 1,
            'related_mrns' => $requests
                ->pluck('mrn')
                ->filter()
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function earningBreakdown(TechnicalServiceRequest $request, mixed $assignmentOffer): array
    {
        $requests = $this->earningGroupRequests($request);

        if ($requests->isEmpty()) {
            if ($this->isEarningExcludedServiceJob($request)) {
                $summary = $this->singleEarningSummary($request, $assignmentOffer);
                $row = [
                    'id' => $request->id,
                    'mrn' => $request->mrn,
                    'display_mrn' => $request->service_code
                        ? trim((string) ($request->root_mrn ?: $request->mrn)).' / '.$request->service_code
                        : $request->mrn,
                    'service_code' => $request->service_code,
                    'kind' => $request->parent_request_id !== null || filled($request->service_code) ? 'service' : 'mount',
                    'kind_label' => $request->parent_request_id !== null || filled($request->service_code) ? 'Servis' : 'Montaj',
                    'is_current' => true,
                    'technician_id' => $summary['technician_id'],
                    'technician_name' => $summary['technician_name'],
                    'technician_source' => $summary['technician_source'],
                    'labor_amount' => 0.0,
                    'route_fee_amount' => 0.0,
                    'total_amount' => 0.0,
                    'status' => $summary['status'],
                    'status_label' => $this->earningStatusLabel($summary['status']),
                    'excluded_from_payable' => true,
                    'exclusion_label' => 'İptal nedeniyle hakedişe dahil değil',
                ];

                return [
                    'current_visit' => $row,
                    'rows' => [$row],
                    'root_total' => [
                        'labor_amount' => 0.0,
                        'route_fee_amount' => 0.0,
                        'total_amount' => 0.0,
                        'job_count' => 1,
                        'technician_count' => filled($summary['technician_name'] ?? null) ? 1 : 0,
                        'technician_names' => array_values(array_filter([(string) ($summary['technician_name'] ?? '')])),
                        'is_multi_technician' => false,
                    ],
                ];
            }

            $requests = collect([$request]);
        }

        $rows = $requests
            ->map(function (TechnicalServiceRequest $related) use ($request, $assignmentOffer): array {
                $summary = $this->singleEarningSummary(
                    $related,
                    $related->id === $request->id ? $assignmentOffer : $related->latestAssignmentOffer,
                );
                $kindLabel = $related->parent_request_id !== null || filled($related->service_code) ? 'Servis' : 'Montaj';

                return [
                    'id' => $related->id,
                    'mrn' => $related->mrn,
                    'display_mrn' => $related->service_code
                        ? trim((string) ($related->root_mrn ?: $related->mrn)).' / '.$related->service_code
                        : $related->mrn,
                    'service_code' => $related->service_code,
                    'kind' => $kindLabel === 'Servis' ? 'service' : 'mount',
                    'kind_label' => $kindLabel,
                    'is_current' => (int) $related->id === (int) $request->id,
                    'technician_id' => $summary['technician_id'],
                    'technician_name' => $summary['technician_name'],
                    'technician_source' => $summary['technician_source'],
                    'labor_amount' => $summary['labor_amount'],
                    'route_fee_amount' => $summary['route_fee_amount'],
                    'total_amount' => $summary['total_amount'],
                    'status' => $summary['status'],
                    'status_label' => $this->earningStatusLabel($summary['status']),
                ];
            })
            ->values();
        $current = $rows->firstWhere('id', $request->id);

        return [
            'current_visit' => $current,
            'rows' => $rows->all(),
            'root_total' => [
                'labor_amount' => round((float) $rows->sum('labor_amount'), 2),
                'route_fee_amount' => round((float) $rows->sum('route_fee_amount'), 2),
                'total_amount' => round((float) $rows->sum('total_amount'), 2),
                'job_count' => $rows->count(),
                'technician_count' => $rows
                    ->pluck('technician_name')
                    ->filter(fn (mixed $name): bool => filled($name))
                    ->unique()
                    ->count(),
                'technician_names' => $rows
                    ->pluck('technician_name')
                    ->filter(fn (mixed $name): bool => filled($name))
                    ->map(fn (mixed $name): string => (string) $name)
                    ->unique()
                    ->values()
                    ->all(),
                'is_multi_technician' => $rows
                    ->pluck('technician_name')
                    ->filter(fn (mixed $name): bool => filled($name))
                    ->unique()
                    ->count() > 1,
            ],
        ];
    }

    /**
     * @return Collection<int, TechnicalServiceRequest>
     */
    private function earningGroupRequests(TechnicalServiceRequest $request): Collection
    {
        $request->loadMissing(['latestAssignmentOffer', 'parentRequest.latestAssignmentOffer', 'childRequests.latestAssignmentOffer']);
        $technicianId = $request->technical_service_technician_id;

        $requests = collect([$request]);

        if ($request->parent_request_id !== null && $request->parentRequest instanceof TechnicalServiceRequest) {
            $requests->push($request->parentRequest);
        } elseif ($request->parent_request_id === null && $request->relationLoaded('childRequests')) {
            $requests = $requests->concat($request->childRequests);
        }

        return $requests
            ->filter(fn (TechnicalServiceRequest $related): bool => (int) $related->technical_service_technician_id === (int) $technicianId)
            ->reject(fn (TechnicalServiceRequest $related): bool => $this->isEarningExcludedServiceJob($related))
            ->unique('id')
            ->values();
    }

    /**
     * @return array{labor_amount:float,route_fee_amount:float,total_amount:float,status:string|null}
     */
    private function singleEarningSummary(TechnicalServiceRequest $request, mixed $assignmentOffer): array
    {
        if ($this->isEarningExcludedServiceJob($request)) {
            $technician = $this->earningTechnicianPayload($request, $assignmentOffer);

            return [
                'labor_amount' => 0.0,
                'route_fee_amount' => 0.0,
                'total_amount' => 0.0,
                'status' => 'cancelled',
                'technician_id' => $technician['technician_id'],
                'technician_name' => $technician['technician_name'],
                'technician_source' => $technician['source'],
            ];
        }

        $completedSnapshot = $this->completedEarningSnapshot($request);
        if ($completedSnapshot !== null) {
            $technician = $this->earningTechnicianPayload($request, $assignmentOffer, $completedSnapshot);
            $laborAmount = $this->money($completedSnapshot['labor_amount'] ?? null) ?? 0.0;
            $routeFeeAmount = $this->money($completedSnapshot['route_fee_amount'] ?? null) ?? 0.0;
            $totalAmount = $this->money($completedSnapshot['total_amount'] ?? null) ?? round($laborAmount + $routeFeeAmount, 2);
            $status = (string) ($completedSnapshot['status'] ?? $completedSnapshot['payout_status'] ?? 'sent');
            if ($this->hasOpsFinalPayoutApproval($request)
                && in_array(mb_strtolower(trim($status)), ['', 'draft', 'pending', 'prepared_not_sent', 'proposed', 'estimate'], true)) {
                $status = 'finalized';
            }

            return [
                'labor_amount' => $laborAmount,
                'route_fee_amount' => $routeFeeAmount,
                'total_amount' => round($totalAmount, 2),
                'status' => $status,
                'technician_id' => $technician['technician_id'],
                'technician_name' => $technician['technician_name'],
                'technician_source' => $technician['source'],
            ];
        }

        if ($assignmentOffer !== null) {
            $technician = $this->earningTechnicianPayload($request, $assignmentOffer);
            $laborAmount = (float) ($assignmentOffer->labor_amount ?? 0);
            $routeFeeAmount = (float) ($assignmentOffer->route_fee_amount ?? 0);

            return [
                'labor_amount' => $laborAmount,
                'route_fee_amount' => $routeFeeAmount,
                'total_amount' => round($laborAmount + $routeFeeAmount, 2),
                'status' => $assignmentOffer->status ?? null,
                'technician_id' => $technician['technician_id'],
                'technician_name' => $technician['technician_name'],
                'technician_source' => $technician['source'],
            ];
        }

        $earningPayload = $this->earningMessagePayload($request);
        if ($earningPayload !== null) {
            $technician = $this->earningTechnicianPayload($request, $assignmentOffer, null, $earningPayload);
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
                'technician_id' => $technician['technician_id'],
                'technician_name' => $technician['technician_name'],
                'technician_source' => $technician['source'],
            ];
        }

        $technician = $this->earningTechnicianPayload($request, $assignmentOffer);
        $laborAmount = $this->money($request->technician_payment_amount) ?? 0.0;
        $routeFeeAmount = $this->money($request->travel_fee_amount) ?? 0.0;

        return [
            'labor_amount' => $laborAmount,
            'route_fee_amount' => $routeFeeAmount,
            'total_amount' => round($laborAmount + $routeFeeAmount, 2),
            'status' => null,
            'technician_id' => $technician['technician_id'],
            'technician_name' => $technician['technician_name'],
            'technician_source' => $technician['source'],
        ];
    }

    /**
     * @param array<string, mixed>|null $completedSnapshot
     * @param array<string, mixed>|null $earningPayload
     * @return array{technician_id:mixed,technician_name:string|null,source:string}
     */
    private function earningTechnicianPayload(
        TechnicalServiceRequest $request,
        mixed $assignmentOffer = null,
        ?array $completedSnapshot = null,
        ?array $earningPayload = null,
    ): array {
        $request->loadMissing('technicianRecord');
        $source = 'request_assignment';
        $technicianId = $request->technical_service_technician_id;
        $technicianName = $request->technician_name ?? $request->technicianRecord?->name;

        if ($assignmentOffer instanceof TechnicalServiceAssignmentOffer) {
            $assignmentOffer->loadMissing('technician');
            $technicianId ??= $assignmentOffer->technical_service_technician_id;
            $technicianName = $this->firstFilled($technicianName, $assignmentOffer->technician?->name);
            $source = 'assignment_offer';
        }

        if ($earningPayload !== null) {
            $technicianId = $earningPayload['technical_service_technician_id']
                ?? $earningPayload['technician_id']
                ?? $technicianId;
            $technicianName = $this->firstFilled($earningPayload['technician_name'] ?? null, $technicianName);
            $source = 'earning_message';
        }

        if ($completedSnapshot !== null) {
            $technicianId = $completedSnapshot['technical_service_technician_id']
                ?? $completedSnapshot['technician_id']
                ?? $technicianId;
            $technicianName = $this->firstFilled($completedSnapshot['technician_name'] ?? null, $technicianName);
            $source = 'completed_earning_snapshot';
        }

        return [
            'technician_id' => $technicianId,
            'technician_name' => TechnicalServiceUiLabelService::displayName($technicianName),
            'source' => $source,
        ];
    }

    private function firstFilled(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (filled($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @return array<string, string|null>|null
     */
    private function serviceJobSerialContext(TechnicalServiceRequest $request): ?array
    {
        $qrPayload = is_array($request->qr_context_payload) ? $request->qr_context_payload : [];
        $serialPayload = is_array($qrPayload['serial_context'] ?? null) ? $qrPayload['serial_context'] : [];

        $context = [
            'serial_number' => $this->firstFilled($request->serial_number, $serialPayload['serial_number'] ?? null),
            'activation_code' => $this->firstFilled($request->activation_code, $serialPayload['activation_code'] ?? null, $qrPayload['activation_code'] ?? null),
            'product_name' => TechnicalServiceUiLabelService::cleanDisplayText(
                $this->firstFilled($request->product_name, $serialPayload['product_name'] ?? null, $qrPayload['product_name'] ?? null)
            ),
            'product_model' => TechnicalServiceUiLabelService::cleanDisplayText(
                $this->firstFilled($request->product_model, $serialPayload['product_model'] ?? null, $qrPayload['product_model'] ?? null)
            ),
            'brand' => TechnicalServiceUiLabelService::cleanDisplayText(
                $this->firstFilled($request->brand, $serialPayload['brand'] ?? null, $qrPayload['brand'] ?? null)
            ),
            'stock_code' => $this->firstFilled($request->stock_code, $serialPayload['stock_code'] ?? null, $qrPayload['stock_code'] ?? null),
        ];

        return collect($context)->filter(fn (?string $value): bool => filled($value))->isEmpty()
            ? null
            : $context;
    }

    private function earningStatusLabel(?string $status): string
    {
        return match (mb_strtolower(trim((string) $status))) {
            'sent', 'submitted' => 'Gönderildi',
            'accepted', 'approved' => 'Onaylandı',
            'revised', 'revision_requested' => 'Revize edildi',
            'confirmed', 'final', 'finalized', 'payable' => 'Kesinleşti',
            'paid' => 'Ödendi',
            'cancelled', 'canceled', 'excluded', 'excluded_from_payable' => 'Hakedişe dahil değil',
            'draft', 'pending', 'prepared_not_sent', 'proposed', 'estimate' => 'Taslak',
            default => 'Hakediş yok',
        };
    }

    private function isCancelledServiceJob(TechnicalServiceRequest $request): bool
    {
        return $request->cancelled_at !== null
            || $this->statusIncludes($request->status, 'ptal')
            || $this->statusIncludes($request->workflow_status, 'ptal');
    }

    private function isEarningExcludedServiceJob(TechnicalServiceRequest $request): bool
    {
        return $this->isCancelledServiceJob($request) || $this->isCancellationReview($request);
    }

    private function isCancellationReview(TechnicalServiceRequest $request): bool
    {
        if ($this->isCancelledServiceJob($request)) {
            return false;
        }

        $operationControl = is_array($request->operation_control_payload) ? $request->operation_control_payload : [];
        $review = $operationControl[TechnicalServiceWorkflowService::CANCELLATION_REVIEW_KEY] ?? $operationControl['cancellation_review'] ?? null;
        $reviewStatus = is_array($review) ? (string) ($review['status'] ?? '') : '';

        return in_array($reviewStatus, ['pending', 'review'], true)
            || (string) $request->pending_reason === TechnicalServiceWorkflowService::CANCELLATION_REVIEW_PENDING_REASON;
    }

    private function statusIncludes(?string $value, string $needle): bool
    {
        return str_contains(mb_strtolower((string) $value), $needle);
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

    /**
     * @return array<string, mixed>|null
     */
    private function completedEarningSnapshot(TechnicalServiceRequest $request): ?array
    {
        if ($request->completed_at === null && $request->installation_completed_at === null) {
            return null;
        }

        $operationControl = is_array($request->operation_control_payload) ? $request->operation_control_payload : [];
        $snapshot = $operationControl['completed_earning_snapshot'] ?? null;

        return is_array($snapshot) ? $snapshot : null;
    }

    private function hasOpsFinalPayoutApproval(TechnicalServiceRequest $request): bool
    {
        $operationControl = is_array($request->operation_control_payload) ? $request->operation_control_payload : [];
        $approval = $operationControl['ops_final_payout_approval'] ?? null;
        if (! is_array($approval)) {
            return false;
        }

        $requestId = (int) $request->id;
        $approvedIds = collect($approval['approved_request_ids'] ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique();

        return $requestId > 0 && $approvedIds->contains($requestId);
    }

    /**
     * @return array{status:string,status_label:string,paid_at:string|null,earning_id:int|null}
     */
    private function payoutPaymentStatusPayload(TechnicalServiceRequest $request): array
    {
        $item = TechnicalServiceEarningItem::query()
            ->with('earning')
            ->where('technical_service_request_id', $request->id)
            ->latest('id')
            ->first();

        if ($item === null || $item->earning === null) {
            return [
                'status' => 'not_recorded',
                'status_label' => 'Hakediş ödeme kaydı yok',
                'paid_at' => null,
                'earning_id' => null,
            ];
        }

        $paidAt = $item->earning->paid_at?->toIso8601String();

        return [
            'status' => $paidAt !== null ? 'paid' : 'pending',
            'status_label' => $paidAt !== null ? 'Ödendi' : 'Ödeme bekliyor',
            'paid_at' => $paidAt,
            'earning_id' => $item->earning->id,
        ];
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
        if ($this->isActiveReopenedWork($request)) {
            return false;
        }

        return $this->isCancellationReview($request)
            || in_array($request->workflow_status, ['Tamamlandı', 'TamamlandÄ±', 'İptal', 'Ä°ptal'], true)
            || $request->completed_at !== null;
    }

    private function isCompletedForPartnerHistory(TechnicalServiceRequest $request): bool
    {
        return $request->completed_at !== null
            || $request->installation_completed_at !== null
            || in_array((string) $request->workflow_status, ['Tamamlandı', 'Tamamlandi', 'TamamlandÄ±'], true)
            || in_array((string) $request->status, ['Tamamlandı', 'Tamamlandi', 'TamamlandÄ±'], true);
    }

    private function isActiveReopenedWork(TechnicalServiceRequest $request): bool
    {
        if ($request->reopened_at === null) {
            return false;
        }

        $terminalStatuses = ['Tamamlandı', 'TamamlandÄ±', 'Tamamlandi', 'İptal', 'Ä°ptal', 'Iptal'];
        $workflowStatus = TechnicalServiceUiLabelService::cleanDisplayText((string) $request->workflow_status);
        $status = TechnicalServiceUiLabelService::cleanDisplayText((string) $request->status);

        return ! in_array((string) $workflowStatus, $terminalStatuses, true)
            && ! in_array((string) $status, $terminalStatuses, true);
    }

    private function isTechnicianApprovalStatus(TechnicalServiceRequest $request): bool
    {
        return TechnicalServiceUiLabelService::cleanDisplayText($request->workflow_status) === 'Usta Onayı Bekleyen';
    }

    private function isAppointmentConfirmedStatus(TechnicalServiceRequest $request): bool
    {
        return in_array(TechnicalServiceUiLabelService::cleanDisplayText($request->workflow_status), [
            'Planlı',
            'Yolda',
            'Sahada',
            'Belge / Fotoğraf Bekleyen',
            'Müşteri Kapanış Onayı Bekleyen',
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

    /**
     * @param  Collection<int, TechnicalServicePartnerJobAction>|null  $actions
     */
    private function stateAction(TechnicalServiceRequest $request, ?Collection $actions = null): ?TechnicalServicePartnerJobAction
    {
        $opsReview = ($actions ?? $request->partnerJobActions)
            ->filter(fn (TechnicalServicePartnerJobAction $action): bool => $action->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW
                && ! $this->actionResolvedForCurrentWork($request, $action));

        foreach ([
            TechnicalServicePartnerJobAction::ACTION_PRICE_REVISION_REQUESTED,
            TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED,
            TechnicalServicePartnerJobAction::ACTION_CUSTOMER_APPROVAL_REJECTED,
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

    private function actionResolvedForNewWork(TechnicalServicePartnerJobAction $action): bool
    {
        $payload = is_array($action->payload) ? $action->payload : [];

        return (bool) ($payload['resolved_by_reassignment'] ?? false)
            || isset($payload['service_visit_created']);
    }

    private function actionResolvedForCurrentWork(TechnicalServiceRequest $request, TechnicalServicePartnerJobAction $action): bool
    {
        return $this->actionResolvedForNewWork($action)
            || $this->actionPredatesActiveReopen($request, $action)
            || $this->priceRevisionResolvedByAssignmentOffer($request, $action);
    }

    private function priceRevisionResolvedByAssignmentOffer(TechnicalServiceRequest $request, TechnicalServicePartnerJobAction $action): bool
    {
        if ($action->action !== TechnicalServicePartnerJobAction::ACTION_PRICE_REVISION_REQUESTED) {
            return false;
        }

        if ($action->status !== TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
            return true;
        }

        $payload = is_array($action->payload) ? $action->payload : [];
        if (($payload['revision_status'] ?? null) === 'resolved' || isset($payload['resolved_assignment_offer_id'])) {
            return true;
        }

        $offer = $request->latestAssignmentOffer;
        if (! $offer instanceof TechnicalServiceAssignmentOffer) {
            return false;
        }

        $actionTechnicianId = (int) ($action->technical_service_technician_id ?? 0);
        if ($actionTechnicianId > 0 && $actionTechnicianId !== (int) $offer->technical_service_technician_id) {
            return false;
        }

        $metadata = is_array($offer->metadata) ? $offer->metadata : [];
        $resolvedIds = collect($metadata['resolved_price_revision_action_ids'] ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values();
        if ($resolvedIds->contains((int) $action->id)) {
            return true;
        }

        $revisedAt = $offer->updated_at;
        if (isset($metadata['revised_at'])) {
            try {
                $revisedAt = \Carbon\CarbonImmutable::parse((string) $metadata['revised_at']);
            } catch (\Throwable) {
                $revisedAt = $offer->updated_at;
            }
        }

        $actionAt = $action->created_at ?? $action->updated_at;

        return $offer->status === TechnicalServiceAssignmentOffer::STATUS_REVISED
            && $revisedAt instanceof \Carbon\CarbonInterface
            && $actionAt instanceof \Carbon\CarbonInterface
            && $revisedAt->greaterThanOrEqualTo($actionAt);
    }

    private function actionPredatesActiveReopen(TechnicalServiceRequest $request, TechnicalServicePartnerJobAction $action): bool
    {
        if ($request->reopened_at === null) {
            return false;
        }

        $actionAt = $action->created_at ?? $action->updated_at;

        return $actionAt instanceof \Carbon\CarbonInterface
            && $actionAt->lessThan($request->reopened_at);
    }

    private function recordPredatesActiveReopen(TechnicalServiceRequest $request, mixed $recordAt): bool
    {
        return $request->reopened_at instanceof \Carbon\CarbonInterface
            && $recordAt instanceof \Carbon\CarbonInterface
            && $recordAt->lessThan($request->reopened_at);
    }

    private function portalDocumentPredatesActiveReopen(TechnicalServiceRequest $request, mixed $recordAt): bool
    {
        return $request->reopened_at instanceof \Carbon\CarbonInterface
            && $recordAt instanceof \Carbon\CarbonInterface
            && $recordAt->lessThanOrEqualTo($request->reopened_at);
    }

    private function latestVisiblePartnerAction(TechnicalServiceRequest $request, \Illuminate\Support\Collection $partnerActions): ?TechnicalServicePartnerJobAction
    {
        return $partnerActions->first(function (TechnicalServicePartnerJobAction $action) use ($request): bool {
            if ($this->actionResolvedForCurrentWork($request, $action)) {
                return false;
            }

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

        if ($action?->action === TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_CHANGE_REQUESTED
            && $action->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
            $badges[] = 'Randevu değişikliği istendi';
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
            $badges[] = 'Müşteri onayı bekleniyor';
        }

        if (($completionRequirements['photos_ready'] ?? false) === true && $this->serviceJobColumn($request, $action) === 'appointment_confirmed') {
            $badges[] = 'Fotoğraflar yüklendi';
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
     * @return array<int, string>
     */
    private function appointmentConfirmedPartnerBadges(array $completionRequirements): array
    {
        $photosReady = ($completionRequirements['photos_ready'] ?? false) === true;
        $customerReady = ($completionRequirements['customer_confirmation_ready'] ?? false) === true;

        if ($photosReady && $customerReady) {
            return ['Tamamlamaya gönderilebilir', 'Fotoğraflar yüklendi', 'Müşteri onayı alındı'];
        }

        return array_values(array_filter([
            'Randevu onaylandı',
            $photosReady ? 'Fotoğraflar yüklendi' : 'Fotoğraf bekleniyor',
            $customerReady ? 'Müşteri onayı alındı' : 'Müşteri onayı bekleniyor',
        ]));
    }

    /**
     * @param  array<string, mixed>  $completionRequirements
     */
    private function appointmentConfirmedPartnerHint(array $completionRequirements): string
    {
        $photosReady = ($completionRequirements['photos_ready'] ?? false) === true;
        $customerReady = ($completionRequirements['customer_confirmation_ready'] ?? false) === true;

        if ($photosReady && $customerReady) {
            return 'Fotoğraflar ve müşteri onayı tamam. İşi tamamlamaya gönderebilirsiniz.';
        }

        if ($photosReady) {
            return 'Fotoğraflar tamam. Şimdi müşteri onayı alın.';
        }

        return 'İş sonrası 3 fotoğrafı yükleyin, ardından müşteri onayı alın.';
    }

    private function displayServiceType(TechnicalServiceRequest $request): ?string
    {
        if ($request->parent_request_id !== null || $request->service_code !== null) {
            return 'Servis';
        }

        return $request->service_type;
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

        if ($action?->action === TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_CHANGE_REQUESTED
            && $action->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
            return 'appointment_change_requested';
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

            return 'completion_ready';
        }

        if ($this->isTechnicianApprovalStatus($request) && $this->hasOpsAppointment($request)) {
            return 'appointment_waiting_technician_accept';
        }

        if ($this->isCompletedForPartnerHistory($request) || $this->isTerminalStatus($request)) {
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
                TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_CHANGE_REQUESTED => 'Randevu değişikliği operasyon incelemesinde',
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
            return 12;
        }

        return match ($action->action) {
            TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED => 2,
            TechnicalServicePartnerJobAction::ACTION_CUSTOMER_APPROVAL_REJECTED => 3,
            TechnicalServicePartnerJobAction::ACTION_PRICE_REVISION_REQUESTED => 4,
            TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED => 5,
            TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED => 6,
            TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_CHANGE_REQUESTED => 7,
            TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED,
            TechnicalServicePartnerJobAction::ACTION_REVISIT_REQUESTED => 9,
            default => 12,
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

        if ($action?->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
            return in_array($action->action, [
                TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_CHANGE_REQUESTED,
                TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED,
                TechnicalServicePartnerJobAction::ACTION_REVISIT_REQUESTED,
            ], true) ? 'violet' : 'blue';
        }

        return match ($this->serviceJobColumn($request, $action)) {
            'appointment_confirmed' => 'green',
            'ops_review' => 'violet',
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
        $uploadedFields = $this->currentPortalFieldDocuments($request)
            ->keys()
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

    /**
     * @return Collection<string, TechnicalServiceRequestUpload>
     */
    private function currentPortalFieldDocuments(TechnicalServiceRequest $request): Collection
    {
        $request->load('uploads');

        return $request->uploads
            ->filter(fn (TechnicalServiceRequestUpload $upload): bool => $this->isPortalFieldDocument($upload)
                && ! $this->portalDocumentPredatesActiveReopen($request, $upload->created_at ?? $upload->updated_at))
            ->filter(fn (TechnicalServiceRequestUpload $upload): bool => array_key_exists(
                (string) $this->canonicalPortalPhotoField($upload->field_code),
                self::REQUIRED_PORTAL_PHOTO_FIELDS
            ))
            ->sort(function (TechnicalServiceRequestUpload $left, TechnicalServiceRequestUpload $right): int {
                $createdAtCompare = ($right->created_at?->getTimestamp() ?? 0) <=> ($left->created_at?->getTimestamp() ?? 0);

                if ($createdAtCompare !== 0) {
                    return $createdAtCompare;
                }

                return ((int) $right->id) <=> ((int) $left->id);
            })
            ->unique(fn (TechnicalServiceRequestUpload $upload): string => (string) $this->canonicalPortalPhotoField($upload->field_code))
            ->mapWithKeys(fn (TechnicalServiceRequestUpload $upload): array => [
                (string) $this->canonicalPortalPhotoField($upload->field_code) => $upload,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function portalPhotoPayload(TechnicalServiceRequest $request, TechnicalServiceRequestUpload $upload): array
    {
        return [
            'id' => $upload->id,
            'label' => $this->portalPhotoLabel($upload) ?? $upload->original_name,
            'category' => $upload->category,
            'field_code' => $upload->field_code,
            'preview_url' => route('api.technical-service.requests.uploads.show', [
                'technicalServiceRequest' => $request->id,
                'upload' => $upload->id,
            ], false),
            'review_status' => $upload->review_status,
            'review_note' => $upload->review_note,
            'created_at' => $upload->created_at?->toIso8601String(),
        ];
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
            TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_CHANGE_REQUESTED,
            TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED,
            TechnicalServicePartnerJobAction::ACTION_REVISIT_REQUESTED,
            ], true)) {
            return 'ops_review';
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
                'name' => TechnicalServiceUiLabelService::displayName($link->technician?->name),
                'phone' => $link->technician?->phone,
                'city' => TechnicalServiceUiLabelService::cityLabel($link->technician?->city),
                'district' => TechnicalServiceUiLabelService::districtLabel($link->technician?->district, $link->technician?->city),
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
