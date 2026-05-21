<?php

namespace App\Services\B2B;

use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerOrder;
use App\Models\B2B\B2BPartnerTechnician;
use App\Models\B2B\B2BPartnerUserProfile;
use App\Models\TechnicalServiceEarning;
use App\Models\TechnicalServiceEarningItem;
use App\Models\TechnicalServiceRequest;
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
            ->latest('updated_at')
            ->limit(50)
            ->get()
            ->map(fn (TechnicalServiceRequest $request): array => [
                'id' => $request->id,
                'mrn' => $request->mrn,
                'customer_name' => $request->customer_name,
                'customer_phone' => $request->customer_phone,
                'customer_city' => $request->customer_city,
                'customer_district' => $request->customer_district,
                'address_summary' => $request->service_address,
                'product_name' => $request->product_name,
                'product_model' => $request->product_model,
                'service_type' => $request->service_type,
                'scheduled_at' => $request->scheduled_at?->toIso8601String(),
                'scheduled_date' => $request->scheduled_date?->toDateString(),
                'status' => $request->status,
                'workflow_status' => $request->workflow_status,
                'next_action' => $request->next_action,
                'updated_at' => $request->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();
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

        return [
            'status' => $rows->isEmpty() ? 'empty' : 'ok',
            'rows' => $rows->all(),
            'summary' => [
                'job_count' => $rows->sum('job_count'),
                'labor_total' => $rows->sum('labor_total'),
                'travel_fee_total' => $rows->sum('travel_fee_total'),
                'grand_total' => $rows->sum('grand_total'),
            ],
        ];
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
