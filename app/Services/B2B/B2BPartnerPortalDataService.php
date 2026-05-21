<?php

namespace App\Services\B2B;

use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerOrder;
use App\Models\B2B\B2BPartnerTechnician;
use App\Models\B2B\B2BPartnerUserProfile;
use App\Models\TechnicalServiceEarning;
use App\Models\TechnicalServiceEarningItem;
use App\Models\TechnicalServicePartnerJobAction;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestUpload;
use App\Models\User;
use Illuminate\Support\Collection;

class B2BPartnerPortalDataService
{
    public function __construct(
        private readonly B2BPartnerAccessService $partnerAccess,
        private readonly B2BPartnerServiceJobScopeService $serviceJobScope,
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
            'latestAssignmentOffer.technician',
            'technicianRecord',
        ]);
        $latestAction = $request->partnerJobActions->first();
        $latestAppointmentProposal = $request->partnerJobActions
            ->firstWhere('action', TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED);
        $latestRejection = $request->partnerJobActions
            ->firstWhere('action', TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED);
        $latestCompletionSubmission = $request->partnerJobActions
            ->firstWhere('action', TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED);
        $latestSupportRequest = $request->partnerJobActions
            ->firstWhere('action', TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED);
        $latestOtpRequest = $request->partnerJobActions
            ->firstWhere('action', TechnicalServicePartnerJobAction::ACTION_CUSTOMER_OTP_REQUESTED);
        $stateAction = $this->stateAction($request);
        $assignmentOffer = $request->latestAssignmentOffer;
        $photoEvidenceCount = $this->doorPhotoEvidenceCount($request);
        $customerConfirmationReady = in_array($request->customer_closure_approval_status, ['onaylandı', 'onaylandi', 'onaylandÄ±'], true)
            || $latestOtpRequest instanceof TechnicalServicePartnerJobAction;
        $completionRequirements = [
            'door_photos_required' => 3,
            'door_photos_uploaded' => $photoEvidenceCount,
            'photos_ready' => $photoEvidenceCount >= 3,
            'customer_confirmation_ready' => $customerConfirmationReady,
            'checklist_required' => true,
            'ops_final_check_required' => true,
        ];

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
            'priority' => $request->priority,
            'status' => $request->status,
            'workflow_status' => $request->workflow_status,
            'next_action' => $request->next_action,
            'route_distance_summary' => $request->travel_round_trip_km !== null ? ((float) $request->travel_round_trip_km).' km' : null,
            'payment_status_summary' => $request->mount_payment_label ?? $request->mount_payment_status,
            'maps_link' => $this->mapsLink($request),
            'customer_tel_link' => $this->telLink($request->customer_phone),
            'checklist_status' => $request->checklist_status,
            'checklist_payload' => is_array($request->checklist_payload) ? $request->checklist_payload : [],
            'photo_counts' => [
                'before' => (int) ($request->before_photo_count ?? 0),
                'after' => (int) ($request->after_photo_count ?? 0),
                'general' => (int) ($request->general_photo_count ?? 0),
            ],
            'photos' => $request->uploads
                ->map(fn (TechnicalServiceRequestUpload $upload): array => [
                    'id' => $upload->id,
                    'label' => $upload->original_name,
                    'category' => $upload->category,
                    'field_code' => $upload->field_code,
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
            'portal_actions' => $request->partnerJobActions
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
            'customer_otp_request' => $latestOtpRequest ? [
                'id' => $latestOtpRequest->id,
                'status' => $latestOtpRequest->status,
                'note' => $latestOtpRequest->note,
                'payload' => is_array($latestOtpRequest->payload) ? $latestOtpRequest->payload : [],
                'created_at' => $latestOtpRequest->created_at?->toIso8601String(),
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
                'labor_amount' => $assignmentOffer ? (float) $assignmentOffer->labor_amount : (float) ($request->technician_payment_amount ?? 0),
                'route_fee_amount' => $assignmentOffer ? (float) $assignmentOffer->route_fee_amount : (float) ($request->travel_fee_amount ?? 0),
                'total_amount' => $assignmentOffer
                    ? (float) $assignmentOffer->total_amount
                    : (float) (($request->technician_payment_amount ?? 0) + ($request->travel_fee_amount ?? 0)),
                'status' => $assignmentOffer?->status,
            ],
            'completion_requirements' => $completionRequirements,
            'badges' => $this->serviceJobBadges($request, $stateAction, $completionRequirements),
            'card_priority' => $this->serviceJobPriority($stateAction),
            'card_tone' => $this->serviceJobTone($request, $stateAction),
            'kanban_column' => $this->serviceJobColumn($request, $stateAction),
            'can_accept' => $request->workflow_status === 'Usta Onayı Bekleyen',
            'can_request_revisit' => ! in_array($request->workflow_status, ['Tamamlandı', 'İptal'], true),
            'can_submit_completion' => in_array($request->workflow_status, ['Planlı', 'Yolda', 'Sahada', 'Belge / Fotoğraf Bekleyen', 'Müşteri Kapanış Onayı Bekleyen'], true),
            'can_complete_directly' => false,
            'can_reject' => ! in_array($request->workflow_status, ['Tamamlandı', 'İptal'], true),
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
            ->filter(fn (TechnicalServiceRequest $request): bool => $request->latestAssignmentOffer !== null)
            ->map(function (TechnicalServiceRequest $request): array {
                $offer = $request->latestAssignmentOffer;

                return [
                    'id' => $request->id,
                    'mrn' => $request->mrn,
                    'scheduled_at' => $request->scheduled_at?->toIso8601String() ?? $request->scheduled_date?->toDateString(),
                    'labor_amount' => (float) ($offer?->labor_amount ?? 0),
                    'travel_fee_amount' => (float) ($offer?->route_fee_amount ?? 0),
                    'line_total' => (float) ($offer?->total_amount ?? 0),
                    'status' => $this->pendingEarningStatus($request),
                    'offer_status' => $offer?->status,
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
            'job_count' => $rows->sum('job_count'),
            'labor_total' => $rows->sum('labor_total'),
            'travel_fee_total' => $rows->sum('travel_fee_total'),
            'grand_total' => $rows->sum('grand_total'),
        ];

        return [
            'status' => $rows->isEmpty() && $pendingRows->isEmpty() ? 'empty' : 'ok',
            'rows' => $rows->all(),
            'pending' => [
                'rows' => $pendingRows->all(),
                'summary' => $pendingSummary,
                'note' => 'Bekleyen hakedişler tahmini atama teklifidir; actual hakediş değildir.',
            ],
            'completed' => [
                'rows' => $rows->all(),
                'summary' => $completedSummary,
                'note' => 'Tamamlanan hakedişler Teknik Servis hakediş kaynağından okunur.',
            ],
            'summary' => [
                'job_count' => $rows->sum('job_count'),
                'labor_total' => $rows->sum('labor_total'),
                'travel_fee_total' => $rows->sum('travel_fee_total'),
                'grand_total' => $rows->sum('grand_total'),
            ],
        ];
    }

    private function pendingEarningStatus(TechnicalServiceRequest $request): string
    {
        $request->loadMissing('partnerJobActions');
        $stateAction = $this->stateAction($request);

        if ($stateAction?->action === TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED
            && $stateAction->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
            return 'son_kontrol';
        }

        if (in_array($request->workflow_status, ['Planlı', 'Yolda', 'Sahada', 'Belge / Fotoğraf Bekleyen', 'Müşteri Kapanış Onayı Bekleyen'], true)) {
            return 'planlı';
        }

        return 'beklemede';
    }

    private function stateAction(TechnicalServiceRequest $request): ?TechnicalServicePartnerJobAction
    {
        $opsReview = $request->partnerJobActions
            ->filter(fn (TechnicalServicePartnerJobAction $action): bool => $action->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW);

        foreach ([
            TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED,
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

        return $request->partnerJobActions->first();
    }

    /**
     * @param  array<string, mixed>  $completionRequirements
     * @return array<int, string>
     */
    private function serviceJobBadges(TechnicalServiceRequest $request, ?TechnicalServicePartnerJobAction $action, array $completionRequirements): array
    {
        $badges = [];

        if ($action?->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
            $badges[] = 'Ops onayı bekliyor';
        }

        if ($action?->action === TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED) {
            $badges[] = 'Randevu önerildi';
        }

        if ($action?->action === TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED) {
            $badges[] = 'Reddedildi';
        }

        if ($action?->action === TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED) {
            $badges[] = 'Son kontrol bekliyor';
        }

        if ($action?->action === TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED) {
            $badges[] = 'Yedek parça talebi';
        }

        if (($completionRequirements['photos_ready'] ?? false) !== true && $this->serviceJobColumn($request, $action) === 'appointment_confirmed') {
            $badges[] = 'Fotoğraf bekliyor';
        }

        if (($completionRequirements['customer_confirmation_ready'] ?? false) !== true && $this->serviceJobColumn($request, $action) === 'appointment_confirmed') {
            $badges[] = 'OTP bekliyor';
        }

        return array_values(array_unique($badges));
    }

    private function serviceJobPriority(?TechnicalServicePartnerJobAction $action): int
    {
        if (! $action instanceof TechnicalServicePartnerJobAction || $action->status !== TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
            return 50;
        }

        return match ($action->action) {
            TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED => 1,
            TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED => 2,
            TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED => 3,
            TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED,
            TechnicalServicePartnerJobAction::ACTION_REVISIT_REQUESTED => 4,
            default => 20,
        };
    }

    private function serviceJobTone(TechnicalServiceRequest $request, ?TechnicalServicePartnerJobAction $action): string
    {
        if ($action?->action === TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED && $action->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW) {
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
        $request->loadMissing('uploads');
        $fieldCodes = $request->uploads
            ->filter(fn (TechnicalServiceRequestUpload $upload): bool => $upload->category === TechnicalServiceRequestUpload::CATEGORY_OPERATION_CONTROL_DOOR_PHOTO)
            ->pluck('field_code')
            ->filter()
            ->unique()
            ->count();
        $legacyCount = max(
            0,
            (int) ($request->before_photo_count ?? 0),
            (int) ($request->after_photo_count ?? 0),
            (int) ($request->general_photo_count ?? 0),
        );

        return max($fieldCodes, $legacyCount);
    }

    private function serviceJobColumn(TechnicalServiceRequest $request, ?TechnicalServicePartnerJobAction $latestAction): string
    {
        if (
            $latestAction?->action === TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED
            && $latestAction->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW
        ) {
            return 'final_check';
        }

        if (
            $request->workflow_status === 'Tamamlandı'
            || $request->completed_at !== null
        ) {
            return 'completed';
        }

        if (
            $latestAction?->action === TechnicalServicePartnerJobAction::ACTION_REVISIT_REQUESTED
            || (bool) $request->requires_second_visit
            || in_array($request->workflow_status, ['Beklemede', 'Müşteri Yerinde Yok', 'Montaj Yeri Hazır Değil', 'Parça Bekleniyor', 'Usta Tarih Revize Talebi'], true)
        ) {
            return 'revisit';
        }

        if ($latestAction?->action === TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED) {
            return 'new_jobs';
        }

        if (in_array($request->workflow_status, ['Planlı', 'Yolda', 'Sahada', 'Belge / Fotoğraf Bekleyen', 'Müşteri Kapanış Onayı Bekleyen'], true)) {
            return 'appointment_confirmed';
        }

        return 'new_jobs';
    }

    private function canCompleteDirectly(TechnicalServiceRequest $request): bool
    {
        return in_array($request->workflow_status, ['Sahada', 'Belge / Fotoğraf Bekleyen', 'Müşteri Kapanış Onayı Bekleyen'], true)
            && $request->checklist_status === 'tamamlandı'
            && (int) ($request->before_photo_count ?? 0) >= 3
            && (int) ($request->after_photo_count ?? 0) >= 3
            && (int) ($request->general_photo_count ?? 0) >= 1
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
