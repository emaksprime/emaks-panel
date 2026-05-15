<?php

namespace Tests\Unit\TechnicalService;

use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceQrLink;
use App\Services\TechnicalService\MountFlowDecisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MountFlowDecisionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_revoked_link_returns_invalid_link_decision(): void
    {
        ['link' => $link] = TechnicalServiceQrLink::createPreSaleProductLink([
            'serial_number' => 'REVOKED-SERIAL',
            'product_name' => 'Revoked Product',
        ]);
        $link->forceFill(['status' => TechnicalServiceQrLink::STATUS_REVOKED])->save();
        ['session' => $session] = TechnicalServiceMountSession::startForLink($link);

        $decision = app(MountFlowDecisionService::class)->decide($session);

        $this->assertSame(MountFlowDecisionService::DECISION_SHOW_INVALID_LINK, $decision['decision']);
    }

    public function test_unknown_sale_mount_status_returns_unknown_error_decision(): void
    {
        ['link' => $link] = TechnicalServiceQrLink::createPreSaleProductLink([
            'serial_number' => 'UNKNOWN-SERIAL',
            'product_name' => 'Unknown Product',
        ]);
        ['session' => $session] = TechnicalServiceMountSession::startForLink($link);

        $decision = app(MountFlowDecisionService::class)->decide($session);

        $this->assertSame(MountFlowDecisionService::DECISION_SHOW_UNKNOWN_ERROR, $decision['decision']);
        $this->assertSame(TechnicalServiceMountSession::SALE_UNKNOWN, $decision['sale_mount_status']);
    }
}
