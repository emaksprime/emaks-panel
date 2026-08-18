<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceRequest;
use App\Services\TechnicalService\TechnicalServiceServiceVisitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalMrnSrvCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_srv_uses_full_five_digit_root_mrn_body(): void
    {
        $root = $this->rootRequest();
        $child = app(TechnicalServiceServiceVisitService::class)
            ->createServiceVisitFromRequest($root, null, 'revisit');

        $this->assertSame('SRV-2606MP0310000-001', $child->service_code);
        $this->assertSame('SRV-2606MP0310000-001', $child->mrn);
        $this->assertSame('MRN-2606MP0310000', $child->root_mrn);
    }

    public function test_srv_first_visit_is_001(): void
    {
        $child = app(TechnicalServiceServiceVisitService::class)
            ->createServiceVisitFromRequest($this->rootRequest(), null, 'revisit');

        $this->assertSame(1, $child->service_sequence);
        $this->assertStringEndsWith('-001', (string) $child->service_code);
    }

    public function test_srv_second_visit_is_002(): void
    {
        $root = $this->rootRequest();
        $service = app(TechnicalServiceServiceVisitService::class);
        $service->createServiceVisitFromRequest($root, null, 'revisit');
        $second = $service->createServiceVisitFromRequest($root->fresh(), null, 'revisit');

        $this->assertSame(2, $second->service_sequence);
        $this->assertSame('SRV-2606MP0310000-002', $second->service_code);
    }

    public function test_srv_parent_relation_does_not_depend_on_four_digit_suffix(): void
    {
        $root = $this->rootRequest();
        $child = app(TechnicalServiceServiceVisitService::class)
            ->createServiceVisitFromRequest($root, null, 'revisit');

        $this->assertSame($root->id, $child->parent_request_id);
        $this->assertTrue($child->parentRequest->is($root));
        $this->assertSame($root->mrn, $child->root_mrn);
    }

    private function rootRequest(): TechnicalServiceRequest
    {
        return TechnicalServiceRequest::query()->create([
            'mrn' => 'MRN-2606MP0310000',
            'customer_name' => 'Long MRN Customer',
            'customer_phone' => '+905551112233',
            'customer_city' => 'Istanbul',
            'customer_district' => 'Kadikoy',
            'service_address' => 'Test address',
            'product_name' => 'Test Product',
            'product_model' => 'TST',
            'serial_number' => 'LONG-MRN-SERIAL',
            'service_type' => 'Montaj',
            'status' => TechnicalServiceRequest::STATUS_NEW,
            'workflow_status' => TechnicalServiceRequest::WORKFLOW_NEW_REQUEST,
            'priority' => TechnicalServiceRequest::PRIORITY_MEDIUM,
            'risk_level' => TechnicalServiceRequest::RISK_MEDIUM,
            'source_channel' => 'panel',
        ]);
    }
}
