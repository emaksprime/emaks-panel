<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Services\AuditLogger;
use App\Services\CariBilgiPageService;
use App\Services\PanelDataSourceManager;
use App\Services\PanelNavigationService;
use App\Services\PrimeCrmIntegrationService;
use App\Services\SalesMainPageService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PanelPageController extends Controller
{
    public function __construct(
        private readonly PanelNavigationService $navigation,
        private readonly PanelDataSourceManager $dataSources,
        private readonly AuditLogger $auditLogger,
        private readonly SalesMainPageService $salesMain,
        private readonly CariBilgiPageService $cariBilgi,
        private readonly PrimeCrmIntegrationService $primeCrm,
    ) {
    }

    public function dashboard(Request $request): Response
    {
        return $this->renderForPath($request, '/dashboard');
    }

    public function __invoke(Request $request, string $panelPath): Response
    {
        return $this->renderForPath($request, '/'.trim($panelPath, '/'));
    }

    private function renderForPath(Request $request, string $path): Response
    {
        $user = $request->user();
        $path = $this->canonicalPanelPath($path);
        $page = $this->navigation->resolveVisiblePage($user, $path);

        if ($page === null) {
            $knownPage = Page::query()
                ->where('route', $path)
                ->where('active', true)
                ->first();

            abort($knownPage ? 403 : 404);
        }

        $payload = $this->navigation->pagePayload($page, $user);
        $navigation = $this->navigation->sharedForUser($user, $path);
        $dataSources = $this->dataSources->summaries();

        $this->auditLogger->log(
            $user,
            'panel.page.view',
            [
                'page' => $page->code,
                'path' => $page->route,
            ],
            $request,
        );

        $sharedProps = [
            'page' => $payload,
            'metrics' => [
                [
                    'label' => 'Aktif Rol',
                    'value' => $navigation['role']['name'] ?? 'Atanmamış',
                    'hint' => $navigation['role']['isSuperAdmin'] ?? false ? 'Tam yetki modeli' : 'Kapsamlı kaynak yetkileri',
                    'tone' => 'accent',
                ],
                [
                    'label' => 'Görünen Sayfalar',
                    'value' => (string) collect($navigation['groups'])->sum(fn (array $group) => count($group['items'])),
                    'hint' => 'Metadata üzerinden yayınlanan sayfalar',
                    'tone' => 'default',
                ],
                [
                    'label' => 'Aksiyon Butonları',
                    'value' => (string) count($payload['buttons']),
                    'hint' => 'Geçerli sayfanın DB kaynaklı aksiyonları',
                    'tone' => 'warning',
                ],
                [
                    'label' => 'Veri Kaynakları',
                    'value' => (string) count($dataSources),
                    'hint' => 'Metadata kayıtlarından yönetilir',
                    'tone' => 'default',
                ],
            ],
            'dataSources' => $dataSources,
            'permissions' => [
                'grantedResources' => $this->navigation->grantedResourceCountFor($user),
                'canExecuteButtons' => count(array_filter(
                    $payload['buttons'],
                    fn (array $button) => ($button['canExecute'] ?? false) === true
                )),
            ],
            'integration' => $this->primeCrm->forPageCode($page->code),
        ];

        if (in_array($page->code, ['sales_main', 'sales_online', 'sales_bayi'], true)) {
            try {
                $sharedProps['salesMainConfig'] = $this->salesMain->config($user, $page->code);
            } catch (Throwable $exception) {
                report($exception);
                $sharedProps['salesMainConfig'] = $this->fallbackSalesMainConfig();
                $sharedProps['salesMainError'] = $exception->getMessage();
            }

            $sharedProps['salesMainData'] = $this->emptySalesMainDataset('Veri güvenli API ile yüklenecek.');
        }

        if ($page->code === 'cari_bilgi') {
            $sharedProps['cariBilgiConfig'] = $this->cariBilgi->config($user);
            $sharedProps['cariBilgiData'] = $this->cariBilgi->dataset($user);
        }

        $component = in_array($page->code, ['sales_main', 'sales_online', 'sales_bayi'], true)
            ? 'panel/sales-main'
            : $page->component;

        return Inertia::render($component, $sharedProps);
    }

    private function canonicalPanelPath(string $path): string
    {
        if ($path === '/crm/customers') {
            return '/cari';
        }

        if ($path === '/crm/customers/balance') {
            return '/cari/balance';
        }

        if (preg_match('#^/proforma/[^/]+/edit$#', $path) === 1) {
            return '/proforma/edit';
        }

        if (preg_match('#^/proforma/[^/]+$#', $path) === 1) {
            return '/proforma/detail';
        }

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function fallbackSalesMainConfig(): array
    {
        return [
            'page' => [
                'title' => 'Genel Satış',
                'description' => 'Ana satış dashboardu ve yönetim kapsamları',
                'routePath' => '/sales/main',
                'component' => 'panel/sales-main',
            ],
            'topNav' => [],
            'grains' => [
                ['key' => 'day', 'label' => 'Günlük'],
                ['key' => 'week', 'label' => 'Haftalık'],
                ['key' => 'month', 'label' => 'Aylık'],
                ['key' => 'year', 'label' => 'Yıllık'],
            ],
            'detailModes' => [
                ['key' => 'cari', 'label' => 'Müşteri Satış Detayı'],
                ['key' => 'urun', 'label' => 'Ürün Satış Detayı'],
            ],
            'managementScopes' => [
                ['key' => 'all', 'label' => 'Tümü', 'note' => 'Tüm satış kapsamı'],
            ],
            'defaults' => [
                'grain' => 'day',
                'detailType' => 'cari',
                'scopeKey' => 'all',
            ],
            'dataSource' => [
                'slug' => 'sales_main_dashboard',
                'status' => 'error',
                'drivers' => [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySalesMainDataset(string $message): array
    {
        $today = now()->format('Y-m-d');

        return [
            'filters' => [
                'dateFrom' => $today,
                'dateTo' => $today,
                'grain' => 'day',
                'detailType' => 'cari',
                'scopeKey' => 'all',
                'periodLabel' => now()->format('d.m.Y').' - '.now()->format('d.m.Y'),
            ],
            'scope' => [
                'key' => 'all',
                'label' => 'Tümü',
                'note' => 'Canlı veri alınamadı.',
                'effectiveRepresentativeCode' => null,
                'canSeeAll' => true,
            ],
            'kpis' => [
                ['label' => 'Toplam Net Ciro', 'value' => '0,00 TL', 'raw' => 0],
                ['label' => 'Seçili Dönem', 'value' => now()->format('d.m.Y'), 'raw' => $today],
                ['label' => 'Konsinye Hariç', 'value' => '0,00 TL', 'raw' => 0],
                ['label' => 'Aktif Kapsam', 'value' => 'Tümü', 'raw' => 'all'],
            ],
            'chart' => [
                'title' => 'Satış Dağılımı',
                'subtitle' => 'Canlı veri alınamadı.',
                'totalNet' => 0,
                'konsinyeAmount' => 0,
                'items' => [],
            ],
            'breakdown' => [
                'mode' => 'cari',
                'title' => 'Satış Detayı',
                'groups' => [],
            ],
            'table' => [
                'columns' => [
                    ['key' => 'label', 'label' => 'Başlık'],
                    ['key' => 'quantity', 'label' => 'Adet'],
                    ['key' => 'amount', 'label' => 'Ciro'],
                ],
                'rows' => [],
            ],
            'queryMeta' => [
                'dataSource' => 'sales_main_dashboard',
                'driver' => 'n8n_json',
                'status' => 'error',
                'mode' => 'n8n_gateway_error',
                'notice' => $message,
                'whitelistedParameters' => [],
                'gatewayMeta' => null,
                'gatewayRequest' => null,
            ],
            'navigation' => [],
        ];
    }
}
