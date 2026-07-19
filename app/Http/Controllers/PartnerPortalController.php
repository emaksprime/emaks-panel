<?php

namespace App\Http\Controllers;

use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerOrder;
use App\Models\B2B\B2BPartnerUserProfile;
use App\Models\TechnicalServiceRequest;
use App\Models\User;
use App\Services\B2B\B2BPartnerAccessService;
use App\Services\B2B\B2BPartnerPortalDataService;
use App\Services\B2B\B2BPartnerServiceJobScopeService;
use App\Services\PanelAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PartnerPortalController extends Controller
{
    public function __construct(
        private readonly B2BPartnerAccessService $partnerAccess,
        private readonly B2BPartnerPortalDataService $portalData,
        private readonly PanelAccessService $panelAccess,
        private readonly B2BPartnerServiceJobScopeService $serviceJobScope,
    ) {}

    public function dashboard(Request $request): Response
    {
        return $this->renderPortal($request, 'dashboard', 'partner.dashboard.view', 'view');
    }

    public function profile(Request $request): Response
    {
        return $this->renderPortal($request, 'settings', 'partner.profile.view', 'view');
    }

    public function settings(Request $request): Response
    {
        return $this->renderPortal($request, 'settings', 'partner.settings.view', 'view');
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

    public function shortServiceJob(Request $request, TechnicalServiceRequest $technicalServiceRequest): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $partner = $this->serviceJobScope->assertCanViewServiceJob($user, $technicalServiceRequest);
        abort_unless(
            $this->panelAccess->userCanAccess($user, 'partner.service_jobs.view')
                && $this->partnerAccess->canAccessScope($user, $partner, 'technical_service', 'view'),
            403,
        );

        return redirect()->route('partner.service-jobs', [
            'partner_id' => (int) $partner->id,
            'job_id' => (int) $technicalServiceRequest->id,
        ]);
    }

    public function opsSupportServiceJobs(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $this->panelAccess->userCanAccess($user, 'technical_service_manage'), 403);

        $jobId = $request->integer('job_id');
        $job = $jobId > 0 ? TechnicalServiceRequest::query()->findOrFail($jobId) : null;
        $selection = $this->serviceJobScope->assertOpsSupportSelection(
            $request->integer('partner_id') ?: null,
            $request->integer('technician_id') ?: null,
            $job,
        );
        $partner = $selection['partner'];
        $technicianId = (int) $selection['technician_id'];
        $query = http_build_query([
            'partner_id' => (int) $partner->id,
            'technician_id' => $technicianId,
            ...($job instanceof TechnicalServiceRequest ? ['job_id' => (int) $job->id] : []),
        ]);

        return Inertia::render('partner/service-jobs', [
            'requestedJobId' => $job?->id,
            'page' => [
                'title' => 'Usta İş Kartı OPS Destek Modu',
                'routePath' => '/technical-service/ops-support/service-jobs',
                'layoutType' => 'module',
            ],
            'partnerPortal' => $this->portalData->payload(
                $partner,
                'service-jobs',
                true,
                null,
                collect([$partner]),
                $user,
                false,
                [
                    'enabled' => true,
                    'partner_id' => (int) $partner->id,
                    'technician_id' => $technicianId,
                    'technician_options' => $this->serviceJobScope->opsSupportTechnicianOptions(),
                    'api_base' => '/api/technical-service/ops-support/service-jobs',
                    'route_path' => '/technical-service/ops-support/service-jobs?'.$query,
                ],
            ),
        ]);
    }

    public function earnings(Request $request): Response
    {
        return $this->renderPortal($request, 'earnings', 'partner.earnings.view', 'technical_service');
    }

    public function products(Request $request): JsonResponse
    {
        [$user, $partner] = $this->resolvePartnerFromRequest($request);
        abort_unless(
            $this->panelAccess->userCanAccess($user, 'partner.stock.view')
                && $this->partnerAccess->canAccessScope($user, $partner, 'stock', 'view'),
            403,
        );

        return response()->json([
            'status' => 'ok',
            'source' => 'local_safe_catalog',
            'products' => $this->portalData->safeProductCatalog(),
        ]);
    }

    public function orderIndex(Request $request): JsonResponse
    {
        [$user, $partner] = $this->resolvePartnerFromRequest($request);
        abort_unless(
            $this->panelAccess->userCanAccess($user, 'partner.orders.view')
                && $this->partnerAccess->canAccessScope($user, $partner, 'orders', 'view'),
            403,
        );

        return response()->json([
            'status' => 'ok',
            'orders' => $this->portalData->ordersFor($partner),
        ]);
    }

    public function storeOrder(Request $request): JsonResponse
    {
        [$user, $partner] = $this->resolvePartnerFromRequest($request);
        abort_unless(
            $this->panelAccess->userCanAccess($user, 'partner.orders.view')
                && $this->partnerAccess->canAccessScope($user, $partner, 'orders', 'create'),
            403,
        );

        $catalogIds = collect($this->portalData->safeProductCatalog())
            ->pluck('catalog_id')
            ->all();

        $payload = $request->validate([
            'partner_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.catalog_id' => ['required', 'string', Rule::in($catalogIds)],
            'items.*.requested_quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'items.*.note' => ['nullable', 'string', 'max:500'],
        ]);

        $order = DB::transaction(function () use ($payload, $partner, $user): B2BPartnerOrder {
            $order = B2BPartnerOrder::query()->create([
                'partner_id' => $partner->id,
                'user_id' => $user->id,
                'order_no' => $this->uniqueOrderNo(),
                'status' => B2BPartnerOrder::STATUS_OPS_REVIEW,
                'note' => $payload['note'] ?? null,
                'metadata' => [
                    'source' => 'partner_portal',
                    'integration_status' => 'mikro_not_written',
                ],
                'submitted_at' => now(),
            ]);

            foreach ($payload['items'] as $item) {
                $product = $this->portalData->productByCatalogId((string) $item['catalog_id']);
                $order->items()->create([
                    'product_code' => (string) $item['catalog_id'],
                    'product_name' => (string) ($product['name'] ?? 'Ürün talebi'),
                    'requested_quantity' => (int) $item['requested_quantity'],
                    'stock_status' => (string) ($product['stock_status'] ?? 'unknown'),
                    'note' => $item['note'] ?? null,
                    'metadata' => [
                        'catalog_model' => $product['model'] ?? null,
                        'catalog_category' => $product['category'] ?? null,
                        'stock_label' => $product['stock_label'] ?? null,
                    ],
                ]);
            }

            return $order->load('items');
        });

        return response()->json([
            'status' => 'created',
            'message' => 'Sipariş talebiniz operasyon incelemesine gönderildi.',
            'order' => $this->portalData->safeOrderSummary($order),
        ], 201);
    }

    private function renderPortal(Request $request, string $view, string $resourceCode, string $scope): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $partners = $this->portalData->visiblePartnersFor($user);
        abort_if($partners->isEmpty(), 403, 'Bu kullanıcı için aktif partner erişimi yok.');

        $partner = $this->portalData->selectedPartner($partners, $request->integer('partner_id') ?: null);
        abort_unless($partner instanceof B2BPartner, 403);

        $allowed = $this->panelAccess->userCanAccess($user, $resourceCode)
            && $this->scopeAllowed($user, $partner, $scope);
        $requestedJobId = null;
        $serviceJobTechnicianId = $view === 'service-jobs'
            ? $this->serviceJobScope->portalTechnicianId($user, $partner)
            : null;
        if ($view === 'service-jobs' && $serviceJobTechnicianId === null) {
            $allowed = false;
        }
        if ($view === 'service-jobs' && $request->integer('job_id') > 0 && ! $allowed) {
            abort(403, 'Bu iş kartı için doğrulanmış portal usta kapsamınız yok.');
        }
        if ($view === 'service-jobs' && $allowed) {
            $requestedTechnicianId = $request->integer('technician_id') ?: null;
            if ($requestedTechnicianId !== null && $requestedTechnicianId !== $serviceJobTechnicianId) {
                abort(403, 'URL usta kapsamı authenticated portal kullanıcısıyla eşleşmiyor.');
            }

            $jobId = $request->integer('job_id');
            if ($jobId > 0) {
                $job = TechnicalServiceRequest::query()->findOrFail($jobId);
                $this->serviceJobScope->assertCanViewServiceJob(
                    $user,
                    $job,
                    (int) $partner->id,
                    $requestedTechnicianId ?? $serviceJobTechnicianId,
                );
                $requestedJobId = (int) $job->id;
            }
        }
        $preview = ! B2BPartnerUserProfile::query()
            ->where('user_id', $user->id)
            ->where('partner_id', $partner->id)
            ->where('active', true)
            ->exists();

        return Inertia::render('partner/'.$view, [
            'requestedJobId' => $requestedJobId,
            'page' => [
                'title' => $this->titleFor($view),
                'routePath' => '/partner/'.$view,
                'layoutType' => 'partner',
            ],
            'partnerPortal' => $this->portalData->payload(
                $partner,
                $view,
                $allowed,
                'Bu ekrana erişiminiz yok.',
                $partners,
                $user,
                $preview,
                null,
                $serviceJobTechnicianId,
            ),
        ]);
    }

    /**
     * @return array{0: User, 1: B2BPartner}
     */
    private function resolvePartnerFromRequest(Request $request): array
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $partners = $this->portalData->visiblePartnersFor($user);
        abort_if($partners->isEmpty(), 403, 'Bu kullanıcı için aktif partner erişimi yok.');

        $partner = $this->portalData->selectedPartner($partners, $request->integer('partner_id') ?: null);
        abort_unless($partner instanceof B2BPartner, 403);

        return [$user, $partner];
    }

    private function scopeAllowed(User $user, B2BPartner $partner, string $scope): bool
    {
        if ($scope === 'view') {
            return $this->partnerAccess->canViewPartner($user, $partner);
        }

        return $this->partnerAccess->canAccessScope($user, $partner, $scope, 'view');
    }

    private function uniqueOrderNo(): string
    {
        do {
            $orderNo = 'B2B-'.now()->format('ymd').'-'.Str::upper(Str::random(6));
        } while (B2BPartnerOrder::query()->where('order_no', $orderNo)->exists());

        return $orderNo;
    }

    private function titleFor(string $view): string
    {
        return match ($view) {
            'settings' => 'Partner Ayarları',
            'orders' => 'Siparişlerim',
            'stock' => 'Ürünler',
            'service-jobs' => 'İşlerim',
            'earnings' => 'Hakedişlerim',
            default => 'Partner Ana Sayfa',
        };
    }
}
