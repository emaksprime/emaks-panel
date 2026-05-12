<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Services\AuditLogger;
use App\Services\PanelAccessService;
use App\Services\PanelNavigationService;
use App\Services\SupportActivationCodeService;
use App\Services\SupportGuideService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SupportController extends Controller
{
    public function __construct(
        private readonly PanelAccessService $access,
        private readonly PanelNavigationService $navigation,
        private readonly SupportGuideService $guides,
        private readonly SupportActivationCodeService $activationCodes,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->render($request, 'support', $this->defaultTab($request));
    }

    public function keypadGuide(Request $request): Response
    {
        return $this->render($request, 'support_keypad_guide', 'guide');
    }

    public function activation(Request $request): Response
    {
        return $this->render($request, 'support_activation_query', 'activation');
    }

    private function render(Request $request, string $pageCode, ?string $activeTab): Response
    {
        $user = $request->user();
        $page = Page::query()
            ->where('code', $pageCode)
            ->where('active', true)
            ->firstOrFail();

        $permissions = [
            'support' => $this->access->userCanAccess($user, 'support'),
            'keypadGuide' => $this->access->userCanAccess($user, 'support_keypad_guide'),
            'activationQuery' => $this->access->userCanAccess($user, 'support_activation_query'),
        ];

        $this->auditLogger->log(
            $user,
            'panel.page.view',
            [
                'page' => $page->code,
                'path' => $page->route,
            ],
            $request,
        );

        return Inertia::render('panel/support', [
            'page' => $this->navigation->pagePayload($page, $user),
            'supportActiveTab' => $activeTab,
            'supportPermissions' => $permissions,
            'supportGuideData' => $permissions['keypadGuide']
                ? $this->guides->activeGuideData()
                : ['sourceSheet' => 'Yahya Düzenleme', 'total' => 0, 'entries' => []],
            'supportActivationSummary' => [
                'total' => $permissions['activationQuery'] ? $this->activationCodes->activeCount() : 0,
            ],
        ]);
    }

    private function defaultTab(Request $request): ?string
    {
        $user = $request->user();

        if ($this->access->userCanAccess($user, 'support_keypad_guide')) {
            return 'guide';
        }

        if ($this->access->userCanAccess($user, 'support_activation_query')) {
            return 'activation';
        }

        return null;
    }
}
