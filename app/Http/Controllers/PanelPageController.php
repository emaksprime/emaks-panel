<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\PanelDataSourceManager;
use App\Services\PanelNavigationService;
use App\Services\SalesMainPageService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PanelPageController extends Controller
{
    public function __construct(
        private readonly PanelNavigationService $navigation,
        private readonly PanelDataSourceManager $dataSources,
        private readonly AuditLogger $auditLogger,
        private readonly SalesMainPageService $salesMain,
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
        $page = $this->navigation->resolveVisiblePage($user, $path);

        abort_if($page === null, 404);

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
        ];

        if ($page->code === 'sales_main') {
            $sharedProps['salesMainConfig'] = $this->salesMain->config($user);
            $sharedProps['salesMainData'] = $this->salesMain->dataset($user);
        }

        return Inertia::render($page->component, $sharedProps);
    }
}
