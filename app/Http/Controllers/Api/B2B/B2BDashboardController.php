<?php

namespace App\Http\Controllers\Api\B2B;

use App\Http\Controllers\Controller;
use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerAuditLog;
use App\Models\B2B\B2BPartnerTechnician;
use App\Models\B2B\B2BPartnerUserProfile;
use App\Models\TechnicalServiceEarning;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use App\Services\PanelAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class B2BDashboardController extends Controller
{
    public function __construct(private readonly PanelAccessService $panelAccess) {}

    public function summary(Request $request): JsonResponse
    {
        $this->authorizeDashboard($request->user());

        $filters = $this->dashboardFilters($request);
        $partnersQuery = $this->filteredPartnerQuery($filters);
        $partners = $this->partnerStatusItems($partnersQuery);

        return response()->json([
            'partner_counts' => $this->partnerCounts(),
            'missing_data_counts' => $this->missingDataCounts(),
            'service_counts' => $this->serviceCounts(),
            'user_counts' => $this->userCounts(),
            'stock_order_placeholders' => $this->placeholderContracts(),
            'recent_activity' => $this->recentActivity(),
            'partner_status' => $partners,
            'filters' => $filters,
        ]);
    }

    public function orders(Request $request): JsonResponse
    {
        $this->authorizeDashboard($request->user());

        return response()->json([
            'status' => 'not_configured',
            'reason' => 'datasource_required',
            'message' => 'Sipariş datasource sonraki fazda bağlanacak.',
            'rows' => [],
            'summary' => [
                'pending' => 0,
                'approval_pending' => 0,
                'preparing' => 0,
                'in_transit' => 0,
                'delivered' => 0,
                'discrepancy' => 0,
            ],
        ]);
    }

    public function stock(Request $request): JsonResponse
    {
        $this->authorizeDashboard($request->user());

        $partnersWithChildCari = B2BPartner::query()
            ->whereNotNull('metadata')
            ->get()
            ->filter(fn (B2BPartner $partner): bool => count($this->childCariAccounts($partner)) > 0)
            ->map(fn (B2BPartner $partner): array => [
                'partner_id' => $partner->id,
                'partner_name' => $partner->display_name,
                'mikro_cari_kodu' => $partner->mikro_cari_kodu,
                'child_cari_accounts' => $this->childCariAccounts($partner),
            ])
            ->values()
            ->all();

        return response()->json([
            'status' => 'not_configured',
            'reason' => 'datasource_required',
            'message' => 'Konsinye/teşhir stok datasource sonraki fazda bağlanacak.',
            'rows' => [],
            'snapshot_contract' => $partnersWithChildCari,
        ]);
    }

    public function locksmiths(Request $request): JsonResponse
    {
        $this->authorizeDashboard($request->user());

        $today = Carbon::today();
        $technicians = TechnicalServiceTechnician::query()
            ->where('active', true)
            ->with(['b2bPartnerLinks.partner.capabilities'])
            ->orderBy('city')
            ->orderBy('name')
            ->limit(80)
            ->get()
            ->map(function (TechnicalServiceTechnician $technician) use ($today): array {
                $requestQuery = TechnicalServiceRequest::query()
                    ->where('technical_service_technician_id', $technician->id);

                return [
                    'id' => $technician->id,
                    'name' => $technician->name,
                    'phone' => $technician->phone,
                    'city' => $technician->city,
                    'district' => $technician->district,
                    'linked_partners' => $technician->b2bPartnerLinks
                        ->where('active', true)
                        ->map(fn (B2BPartnerTechnician $link): array => [
                            'partner_id' => $link->partner_id,
                            'partner_name' => $link->partner?->display_name,
                            'relationship_type' => $link->relationship_type,
                            'is_primary' => (bool) $link->is_primary,
                        ])
                        ->values()
                        ->all(),
                    'open_jobs' => (clone $requestQuery)->whereNull('completed_at')->whereNull('cancelled_at')->count(),
                    'today_jobs' => (clone $requestQuery)
                        ->where(function (Builder $query) use ($today): void {
                            $query->whereDate('scheduled_at', $today)
                                ->orWhereDate('scheduled_date', $today);
                        })
                        ->count(),
                    'completed_jobs' => (clone $requestQuery)->whereNotNull('completed_at')->count(),
                    'pending_earnings' => TechnicalServiceEarning::query()
                        ->where('technical_service_technician_id', $technician->id)
                        ->whereNull('paid_at')
                        ->count(),
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'items' => $technicians,
            'status' => 'ok',
        ]);
    }

    public function earnings(Request $request): JsonResponse
    {
        $this->authorizeDashboard($request->user());

        $earnings = TechnicalServiceEarning::query()
            ->with(['technician', 'period'])
            ->whereNull('paid_at')
            ->orderByDesc('updated_at')
            ->limit(80)
            ->get()
            ->map(fn (TechnicalServiceEarning $earning): array => [
                'id' => $earning->id,
                'technician_id' => $earning->technical_service_technician_id,
                'technician_name' => $earning->technician_name_snapshot,
                'partner_names' => $this->partnerNamesForTechnician($earning),
                'period' => $earning->period ? $earning->period->year.'-'.$earning->period->month : null,
                'job_count' => $earning->job_count,
                'labor_total' => (float) $earning->labor_total,
                'travel_fee_total' => (float) $earning->travel_fee_total,
                'grand_total' => (float) $earning->grand_total,
                'status' => $earning->status,
                'paid_at' => $earning->paid_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return response()->json([
            'status' => count($earnings) > 0 ? 'ok' : 'not_configured',
            'reason' => count($earnings) > 0 ? null : 'no_earnings_period',
            'message' => count($earnings) > 0 ? null : 'Hakediş verisi oluştuğunda burada listelenecek.',
            'rows' => $earnings,
        ]);
    }

    private function authorizeDashboard(?User $user): void
    {
        abort_unless($user, 403);

        $allowed = $this->panelAccess->userCanAccess($user, 'b2b.dashboard.view')
            || $this->panelAccess->userCanAccess($user, 'b2b.view')
            || $this->panelAccess->userCanAccess($user, 'b2b.manage');

        abort_unless($allowed, 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboardFilters(Request $request): array
    {
        return $request->validate([
            'capability' => ['nullable', 'string', 'in:dealer,locksmith,manufacturer,seller,multi_role'],
            'active' => ['nullable', 'boolean'],
            'city' => ['nullable', 'string', 'max:128'],
            'mikro_cari_kodu' => ['nullable', 'string', 'max:128'],
            'search' => ['nullable', 'string', 'max:255'],
            'user_state' => ['nullable', 'string', 'in:with_users,without_users'],
            'technician_state' => ['nullable', 'string', 'in:with_technicians,without_technicians'],
            'data_state' => ['nullable', 'string', 'in:missing_invoice,complete_invoice'],
            'child_cari_state' => ['nullable', 'string', 'in:with_child_cari,without_child_cari'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filteredPartnerQuery(array $filters): Builder
    {
        $query = B2BPartner::query()
            ->with(['capabilities', 'activePartnerTechnicians.technician'])
            ->withCount([
                'profiles as users_count',
                'profiles as active_users_count' => fn (Builder $query): Builder => $query->where('active', true),
                'activePartnerTechnicians as linked_technicians_count',
            ]);

        if (($filters['capability'] ?? null) === 'multi_role') {
            $query->whereHas('activeCapabilities')
                ->withCount('activeCapabilities')
                ->having('active_capabilities_count', '>', 1);
        } elseif (! empty($filters['capability'])) {
            $query->whereHas('activeCapabilities', fn (Builder $query): Builder => $query->where('capability', $filters['capability']));
        }

        if (array_key_exists('active', $filters)) {
            $query->where('active', (bool) $filters['active']);
        }

        if (! empty($filters['city'])) {
            $query->where('city', $this->likeOperator(), '%'.$filters['city'].'%');
        }

        if (! empty($filters['mikro_cari_kodu'])) {
            $query->where('mikro_cari_kodu', $this->likeOperator(), '%'.$filters['mikro_cari_kodu'].'%');
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function (Builder $query) use ($search): void {
                $query->where('partner_code', $this->likeOperator(), '%'.$search.'%')
                    ->orWhere('display_name', $this->likeOperator(), '%'.$search.'%')
                    ->orWhere('mikro_cari_kodu', $this->likeOperator(), '%'.$search.'%')
                    ->orWhere('mikro_cari_unvan', $this->likeOperator(), '%'.$search.'%')
                    ->orWhere('phone', $this->likeOperator(), '%'.$search.'%')
                    ->orWhere('city', $this->likeOperator(), '%'.$search.'%');
            });
        }

        if (($filters['user_state'] ?? null) === 'with_users') {
            $query->whereHas('profiles', fn (Builder $query): Builder => $query->where('active', true));
        } elseif (($filters['user_state'] ?? null) === 'without_users') {
            $query->whereDoesntHave('profiles', fn (Builder $query): Builder => $query->where('active', true));
        }

        if (($filters['technician_state'] ?? null) === 'with_technicians') {
            $query->whereHas('activePartnerTechnicians');
        } elseif (($filters['technician_state'] ?? null) === 'without_technicians') {
            $query->whereDoesntHave('activePartnerTechnicians');
        }

        if (($filters['data_state'] ?? null) === 'missing_invoice') {
            $this->whereMissingInvoiceData($query);
        } elseif (($filters['data_state'] ?? null) === 'complete_invoice') {
            $query->whereNotNull('mikro_cari_kodu')->where('mikro_cari_kodu', '<>', '')
                ->whereNotNull('address')->where('address', '<>', '');
        }

        if (($filters['child_cari_state'] ?? null) === 'with_child_cari') {
            $query->whereNotNull('metadata');
        } elseif (($filters['child_cari_state'] ?? null) === 'without_child_cari') {
            $query->where(function (Builder $query): void {
                $query->whereNull('metadata');
            });
        }

        return $query;
    }

    private function partnerCounts(): array
    {
        return [
            'total' => B2BPartner::query()->count(),
            'active_total' => B2BPartner::query()->where('active', true)->count(),
            'active_dealers' => $this->activeCapabilityCount(B2BPartner::TYPE_DEALER),
            'active_locksmiths' => $this->activeCapabilityCount(B2BPartner::TYPE_LOCKSMITH),
            'active_dealer_locksmith' => B2BPartner::query()
                ->where('active', true)
                ->whereHas('activeCapabilities', fn (Builder $query): Builder => $query->where('capability', B2BPartner::TYPE_DEALER))
                ->whereHas('activeCapabilities', fn (Builder $query): Builder => $query->where('capability', B2BPartner::TYPE_LOCKSMITH))
                ->count(),
            'active_manufacturers' => $this->activeCapabilityCount(B2BPartner::TYPE_MANUFACTURER),
            'active_sellers' => $this->activeCapabilityCount(B2BPartner::TYPE_SELLER),
        ];
    }

    private function activeCapabilityCount(string $capability): int
    {
        return B2BPartner::query()
            ->where('active', true)
            ->whereHas('activeCapabilities', fn (Builder $query): Builder => $query->where('capability', $capability))
            ->count();
    }

    private function missingDataCounts(): array
    {
        return [
            'partners_without_users' => B2BPartner::query()
                ->where('active', true)
                ->whereDoesntHave('profiles', fn (Builder $query): Builder => $query->where('active', true))
                ->count(),
            'locksmiths_without_technicians' => B2BPartner::query()
                ->where('active', true)
                ->whereHas('activeCapabilities', fn (Builder $query): Builder => $query->where('capability', B2BPartner::TYPE_LOCKSMITH))
                ->whereDoesntHave('activePartnerTechnicians')
                ->count(),
            'partners_missing_cari_info' => tap(B2BPartner::query()->where('active', true), fn (Builder $query) => $this->whereMissingInvoiceData($query))->count(),
            'partners_with_child_cari' => B2BPartner::query()
                ->where('active', true)
                ->whereNotNull('metadata')
                ->get()
                ->filter(fn (B2BPartner $partner): bool => count($this->childCariAccounts($partner)) > 0)
                ->count(),
        ];
    }

    private function serviceCounts(): array
    {
        return [
            'open_service_jobs' => TechnicalServiceRequest::query()->whereNull('completed_at')->whereNull('cancelled_at')->count(),
            'today_jobs' => TechnicalServiceRequest::query()
                ->whereDate('scheduled_at', Carbon::today())
                ->orWhereDate('scheduled_date', Carbon::today())
                ->count(),
            'completed_jobs' => TechnicalServiceRequest::query()->whereNotNull('completed_at')->count(),
        ];
    }

    private function userCounts(): array
    {
        return [
            'partner_users' => B2BPartnerUserProfile::query()->count(),
            'active_partner_users' => B2BPartnerUserProfile::query()->where('active', true)->count(),
        ];
    }

    private function placeholderContracts(): array
    {
        return [
            'orders' => [
                'status' => 'not_configured',
                'reason' => 'datasource_required',
                'pending_orders' => 0,
                'approval_pending' => 0,
            ],
            'stock' => [
                'status' => 'not_configured',
                'reason' => 'datasource_required',
                'consignment_alerts' => 0,
                'showroom_alerts' => 0,
            ],
            'deliveries' => [
                'status' => 'contract_ready',
                'reason' => 'local_model_deferred',
                'discrepancies' => 0,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function partnerStatusItems(Builder $query): array
    {
        $partners = $query
            ->orderByDesc('active')
            ->orderBy('display_name')
            ->limit(100)
            ->get();

        $latestActivity = B2BPartnerAuditLog::query()
            ->whereIn('partner_id', $partners->pluck('id'))
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('partner_id')
            ->map(fn ($logs) => $logs->first());

        return $partners
            ->map(function (B2BPartner $partner) use ($latestActivity): array {
                $metadata = is_array($partner->metadata) ? $partner->metadata : [];
                $activity = $latestActivity->get($partner->id);

                return [
                    'id' => $partner->id,
                    'display_name' => $partner->display_name,
                    'partner_code' => $partner->partner_code,
                    'capabilities' => $partner->capabilityCodes(),
                    'mikro_cari_kodu' => $partner->mikro_cari_kodu,
                    'phone' => $partner->phone,
                    'email' => $partner->email,
                    'city' => $partner->city,
                    'district' => $partner->district,
                    'address_missing' => empty($partner->address),
                    'users_count' => $partner->users_count ?? 0,
                    'active_users_count' => $partner->active_users_count ?? 0,
                    'linked_technicians_count' => $partner->linked_technicians_count ?? 0,
                    'child_cari_count' => count($metadata['child_cari_accounts'] ?? []),
                    'active' => (bool) $partner->active,
                    'last_activity' => $activity ? [
                        'action' => $activity->action,
                        'created_at' => $this->dateString($activity->created_at),
                    ] : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentActivity(): array
    {
        return B2BPartnerAuditLog::query()
            ->with('partner')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn (B2BPartnerAuditLog $log): array => [
                'id' => $log->id,
                'partner_id' => $log->partner_id,
                'partner_name' => $log->partner?->display_name,
                'action' => $log->action,
                'created_at' => $this->dateString($log->created_at),
            ])
            ->values()
            ->all();
    }

    private function whereMissingInvoiceData(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query->whereNull('mikro_cari_kodu')
                ->orWhere('mikro_cari_kodu', '')
                ->orWhereNull('address')
                ->orWhere('address', '');
        });
    }

    /**
     * @return array<int, mixed>
     */
    private function childCariAccounts(B2BPartner $partner): array
    {
        $metadata = is_array($partner->metadata) ? $partner->metadata : [];
        $accounts = $metadata['child_cari_accounts'] ?? [];

        return is_array($accounts) ? array_values($accounts) : [];
    }

    private function likeOperator(): string
    {
        return config('database.default') === 'pgsql' ? 'ilike' : 'like';
    }

    /**
     * @return array<int, string>
     */
    private function partnerNamesForTechnician(TechnicalServiceEarning $earning): array
    {
        if (! $earning->technician) {
            return [];
        }

        return $earning->technician
            ->b2bPartnerLinks()
            ->where('active', true)
            ->with('partner')
            ->get()
            ->map(fn (B2BPartnerTechnician $link): ?string => $link->partner?->display_name)
            ->filter()
            ->values()
            ->all();
    }

    private function dateString(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        if (empty($value)) {
            return null;
        }

        return Carbon::parse($value)->toIso8601String();
    }
}
