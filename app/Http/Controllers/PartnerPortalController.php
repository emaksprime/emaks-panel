<?php

namespace App\Http\Controllers;

use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerTechnician;
use App\Models\B2B\B2BPartnerUserProfile;
use App\Models\TechnicalServiceRequest;
use App\Models\User;
use App\Services\B2B\B2BPartnerAccessService;
use App\Services\B2B\B2BPartnerServiceJobScopeService;
use App\Services\PanelAccessService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PartnerPortalController extends Controller
{
    public function __construct(
        private readonly B2BPartnerAccessService $partnerAccess,
        private readonly B2BPartnerServiceJobScopeService $serviceJobScope,
        private readonly PanelAccessService $panelAccess,
    ) {}

    public function dashboard(Request $request): Response
    {
        return $this->renderPortal($request, 'dashboard', 'partner.dashboard.view', 'view');
    }

    public function profile(Request $request): Response
    {
        return $this->renderPortal($request, 'profile', 'partner.profile.view', 'view');
    }

    public function orders(Request $request): Response
    {
        return $this->renderPortal($request, 'orders', 'partner.orders.view', 'orders');
    }

    public function stock(Request $request): Response
    {
        return $this->renderPortal($request, 'stock', 'partner.stock.view', 'stock');
    }

    public function serviceJobs(Request $request): Response
    {
        return $this->renderPortal($request, 'service-jobs', 'partner.service_jobs.view', 'technical_service');
    }

    private function renderPortal(Request $request, string $view, string $resourceCode, string $scope): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $partners = $this->visiblePartners($user);
        abort_if($partners->isEmpty(), 403, 'Bu kullanici icin aktif partner erisimi yok.');

        $requestedPartnerId = $request->integer('partner_id') ?: null;
        $partner = $requestedPartnerId
            ? $partners->firstWhere('id', $requestedPartnerId)
            : $partners->first();
        abort_unless($partner instanceof B2BPartner, 403);

        $allowed = $this->panelAccess->userCanAccess($user, $resourceCode)
            && $this->scopeAllowed($user, $partner, $scope);

        $serviceJobs = [];
        if ($view === 'service-jobs' && $allowed) {
            $serviceJobs = $this->serviceJobScope
                ->serviceJobsQuery($partner)
                ->latest('updated_at')
                ->limit(50)
                ->get()
                ->map(fn (TechnicalServiceRequest $request): array => [
                    'id' => $request->id,
                    'mrn' => $request->mrn,
                    'customer_name' => $request->customer_name,
                    'customer_city' => $request->customer_city,
                    'customer_district' => $request->customer_district,
                    'status' => $request->status,
                    'workflow_status' => $request->workflow_status,
                    'updated_at' => optional($request->updated_at)->toIso8601String(),
                ])
                ->values()
                ->all();
        }

        return Inertia::render('partner/'.$view, [
            'page' => [
                'title' => $this->titleFor($view),
                'routePath' => '/partner/'.$view,
                'layoutType' => 'partner',
            ],
            'partnerPortal' => [
                'view' => $view,
                'allowed' => $allowed,
                'deniedMessage' => $allowed ? null : 'Bu ekrana erisiminiz yok.',
                'partners' => $partners->map(fn (B2BPartner $item): array => $this->partnerSummary($item))->values()->all(),
                'selectedPartner' => $this->partnerSummary($partner),
                'stats' => $this->statsFor($partner),
                'serviceJobs' => $serviceJobs,
                'placeholders' => $this->placeholdersFor($partner),
            ],
        ]);
    }

    private function visiblePartners(User $user)
    {
        $query = $this->partnerAccess
            ->visiblePartnerQuery($user)
            ->where('active', true)
            ->with([
                'capabilities',
                'profiles',
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

    private function scopeAllowed(User $user, B2BPartner $partner, string $scope): bool
    {
        if ($scope === 'view') {
            return $this->partnerAccess->canViewPartner($user, $partner);
        }

        return $this->partnerAccess->canAccessScope($user, $partner, $scope, 'view');
    }

    /**
     * @return array<string, mixed>
     */
    private function partnerSummary(B2BPartner $partner): array
    {
        $metadata = is_array($partner->metadata) ? $partner->metadata : [];
        $activeTechnicians = $partner->activePartnerTechnicians
            ->map(fn (B2BPartnerTechnician $link): array => [
                'id' => $link->id,
                'technical_service_technician_id' => $link->technical_service_technician_id,
                'relationship_type' => $link->relationship_type,
                'is_primary' => (bool) $link->is_primary,
                'name' => $link->technician?->name,
                'phone' => $link->technician?->phone,
                'city' => $link->technician?->city,
                'district' => $link->technician?->district,
            ])
            ->values()
            ->all();

        return [
            'id' => $partner->id,
            'display_name' => $partner->display_name,
            'partner_code' => $partner->partner_code,
            'capabilities' => $partner->capabilityCodes(),
            'mikro_cari_kodu' => $partner->mikro_cari_kodu,
            'mikro_cari_unvan' => $partner->mikro_cari_unvan,
            'phone' => $partner->phone,
            'email' => $partner->email,
            'city' => $partner->city,
            'district' => $partner->district,
            'address' => $partner->address ?? ($metadata['address'] ?? null),
            'child_cari_accounts' => $metadata['child_cari_accounts'] ?? [],
            'linked_technicians' => $activeTechnicians,
            'users_count' => $partner->profiles->count(),
            'active_users_count' => $partner->profiles->where('active', true)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function statsFor(B2BPartner $partner): array
    {
        return [
            'linked_technicians_count' => $partner->activePartnerTechnicians->count(),
            'users_count' => B2BPartnerUserProfile::query()->where('partner_id', $partner->id)->count(),
            'active_users_count' => B2BPartnerUserProfile::query()->where('partner_id', $partner->id)->where('active', true)->count(),
            'open_service_jobs_count' => $this->serviceJobScope->serviceJobsQuery($partner)->count(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function placeholdersFor(B2BPartner $partner): array
    {
        return [
            'orders' => 'Bayi siparis entegrasyonu sonraki fazda Mikro read-only datasource ile baglanacak.',
            'stock' => 'Bayi stok entegrasyonu sonraki fazda Mikro read-only datasource ile baglanacak.',
            'finance' => 'Cari ve risk ozeti sonraki fazda read-only cari datasource ile baglanacak.',
            'service' => $partner->hasCapability(B2BPartner::TYPE_LOCKSMITH)
                ? 'Servis isleri bagli owner/field usta kapsamindan okunur.'
                : 'Bu partner icin cilingir portal kimligi aktif degil.',
        ];
    }

    private function titleFor(string $view): string
    {
        return match ($view) {
            'profile' => 'Partner Profil',
            'orders' => 'Partner Siparisleri',
            'stock' => 'Partner Stok',
            'service-jobs' => 'Partner Servis Isleri',
            default => 'Partner Dashboard',
        };
    }
}
