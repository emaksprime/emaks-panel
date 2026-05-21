<?php

namespace App\Http\Controllers;

use App\Models\B2B\B2BPartner;
use App\Models\User;
use App\Services\B2B\B2BPartnerPortalDataService;
use App\Services\PanelAccessService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class B2BPortalPreviewController extends Controller
{
    public function __construct(
        private readonly PanelAccessService $panelAccess,
        private readonly B2BPartnerPortalDataService $portalData,
    ) {}

    public function __invoke(Request $request, B2BPartner $partner): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $this->panelAccess->userCanAccess($user, 'b2b.portal_preview.view'), 403);
        $view = (string) $request->string('view', 'dashboard');
        $view = in_array($view, ['dashboard', 'orders', 'stock', 'service-jobs', 'earnings', 'settings'], true)
            ? $view
            : 'dashboard';

        $partner->loadMissing([
            'capabilities',
            'profiles.user',
            'activePartnerTechnicians.technician',
        ]);

        return Inertia::render('panel/b2b/portal-preview', [
            'page' => [
                'title' => 'Partner Portal Önizleme',
                'routePath' => "/panel/b2b/partners/{$partner->id}/portal-preview",
                'layoutType' => 'module',
            ],
            'preview' => [
                'read_only' => true,
                'warning' => 'Önizleme modu: Bu ekran partner kullanıcısının portal görünümünü simüle eder. İşlem yapılamaz.',
                'back_url' => '/panel/b2b/partners',
                'portal_url' => "/panel/b2b/partners/{$partner->id}/portal-preview",
            ],
            'partnerPortal' => $this->portalData->payload(
                $partner,
                $view,
                true,
                null,
                collect([$partner]),
                null,
                true,
            ),
        ]);
    }
}
