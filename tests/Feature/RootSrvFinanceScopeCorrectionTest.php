<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceAssignmentOffer;
use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServicePartRequest;
use App\Models\TechnicalServiceQrLink;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestUpload;
use App\Models\TechnicalServiceSettlement;
use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use App\Services\TechnicalService\TechnicalServiceAssignmentSettlementService;
use App\Services\TechnicalService\TechnicalServicePartRequestService;
use App\Services\TechnicalService\TechnicalServicePaymentOrderContextService;
use App\Services\TechnicalService\TechnicalServiceWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RootSrvFinanceScopeCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'services.partner_portal.public_url' => 'https://payments.example.test',
            'services.technical_service.payment_order_context_test_stock' => true,
        ]);
    }

    public function test_mount_collection_created_from_srv_resolves_to_root(): void
    {
        [$actor, , $root] = $this->assignedRoot([
            'customer_city' => 'Denizli',
            'customer_district' => 'Pamukkale',
        ]);
        $this->mountSession($root);
        $child = $this->request([
            'mrn' => 'SRV-MOUNT-SCOPE-001',
            'parent_request_id' => $root->id,
            'root_mrn' => $root->mrn,
            'service_sequence' => 1,
            'service_code' => 'SRV-MOUNT-SCOPE-001',
            'service_visit_reason' => 'spare_part',
            'service_type' => 'Servis',
        ]);
        $service = app(TechnicalServiceAssignmentSettlementService::class);

        $this->assertSame($root->id, $service->paymentRequestForPurpose($child, 'mount_collection')->id);
        $this->assertSame($child->id, $service->paymentRequestForPurpose($child, 'service_and_part_payment')->id);

        $orderContexts = app(TechnicalServicePaymentOrderContextService::class);
        $input = ['billing_source' => 'mrn_customer'];
        $preview = $orderContexts->preview($root, 'mount_collection', $input, 3500, 'TRY', 'fake', 'local');
        $response = $this->actingAs($actor)->postJson(
            "/api/technical-service/requests/{$child->id}/payments/extra-mount-fee",
            [
                'amount' => '3500.00',
                'currency' => 'TRY',
                'purpose' => 'mount_collection',
                'reason' => 'mount_collection',
                'order_context' => [
                    ...$input,
                    'expected_context_hash' => $preview['context_hash'],
                    'expected_revision' => $preview['revision'],
                ],
            ],
        )->assertCreated();

        $payment = TechnicalServiceMountPayment::query()->findOrFail((int) $response->json('payment.id'));
        $context = DB::table(TechnicalServicePaymentOrderContextService::TABLE)
            ->where('technical_service_mount_payment_id', $payment->id)
            ->sole();

        $this->assertSame($root->id, (int) $payment->technical_service_request_id);
        $this->assertSame($root->id, (int) $context->technical_service_request_id);
        $this->assertSame($child->id, (int) data_get($payment->raw_payload, 'initiated_from_request_id'));
        $this->assertSame($child->service_code, data_get($payment->raw_payload, 'initiated_from_request_code'));
    }

    public function test_part_received_child_srv_materializes_ops_amount_offer_and_settlement_exactly_once(): void
    {
        [$actor, $technician, $root] = $this->assignedRoot();
        $partRequest = $this->partRequest($root, [
            'service_amount' => 1000,
            'service_visit_route_fee_amount' => 500,
            'service_visit_route_fee_source' => 'ops_manual',
        ]);

        $service = app(TechnicalServicePartRequestService::class);
        $first = $service->createServiceVisit($partRequest, $actor, 'spare_part');
        $second = $service->createServiceVisit($partRequest->fresh(), $actor, 'spare_part');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, TechnicalServiceRequest::query()->where('source_part_request_id', $partRequest->id)->count());
        $this->assertSame(1, TechnicalServiceAssignmentOffer::query()->where('technical_service_request_id', $first->id)->count());
        $this->assertSame(1, TechnicalServiceSettlement::query()->where('technical_service_request_id', $first->id)->count());
        $this->assertSame(1, $first->events()->where('event_type', 'part_received_service_visit_assigned')->count());

        $offer = TechnicalServiceAssignmentOffer::query()->where('technical_service_request_id', $first->id)->sole();
        $settlement = TechnicalServiceSettlement::query()->where('technical_service_request_id', $first->id)->sole();

        $this->assertSame(1000.0, (float) $offer->labor_amount);
        $this->assertSame(500.0, (float) $offer->route_fee_amount);
        $this->assertSame(1500.0, (float) $offer->total_amount);
        $this->assertSame('ops_manual', data_get($offer->metadata, 'route_source'));
        $this->assertTrue((bool) data_get($offer->metadata, 'confirmed_by_ops'));
        $this->assertSame(1000.0, (float) $settlement->labor_earning_amount);
        $this->assertSame(500.0, (float) $settlement->route_earning_amount);
        $this->assertSame(1500.0, (float) $settlement->technician_earning_total);
        $this->assertSame(TechnicalServiceAssignmentSettlementService::EARNING_PAYMENT_SOURCE_COMPANY, data_get($settlement->metadata, 'earning_payment_source'));
        $this->assertSame($technician->id, $first->fresh()->technical_service_technician_id);
    }

    public function test_child_srv_preserves_explicit_zero_ops_service_amount(): void
    {
        [$actor, , $root] = $this->assignedRoot();
        $partRequest = $this->partRequest($root, [
            'service_amount' => 0,
            'service_visit_route_fee_amount' => 0,
            'service_visit_route_fee_source' => 'ops_manual',
        ]);

        $child = app(TechnicalServicePartRequestService::class)->createServiceVisit($partRequest, $actor, 'spare_part');
        $offer = TechnicalServiceAssignmentOffer::query()->where('technical_service_request_id', $child->id)->sole();
        $settlement = TechnicalServiceSettlement::query()->where('technical_service_request_id', $child->id)->sole();

        $this->assertSame(0.0, (float) $offer->labor_amount);
        $this->assertSame(0.0, (float) $offer->route_fee_amount);
        $this->assertSame(0.0, (float) $settlement->labor_earning_amount);
        $this->assertSame(0.0, (float) $settlement->route_earning_amount);
    }

    public function test_missing_ops_service_amount_remains_unresolved_without_1800_fallback(): void
    {
        [$actor, $technician, $root] = $this->assignedRoot();
        $partRequest = $this->partRequest($root, [
            'service_amount' => null,
            'service_visit_route_fee_amount' => 500,
            'service_visit_route_fee_source' => 'ops_manual',
        ]);

        $child = app(TechnicalServicePartRequestService::class)->createServiceVisit($partRequest, $actor, 'spare_part');

        $this->assertSame(0, TechnicalServiceAssignmentOffer::query()->where('technical_service_request_id', $child->id)->count());
        $this->assertSame(0, TechnicalServiceSettlement::query()->where('technical_service_request_id', $child->id)->count());
        $this->assertNull($child->fresh()->technician_payment_amount);
        $this->assertSame($technician->id, $child->fresh()->technical_service_technician_id);
        $this->assertSame('OPS servis bedeli belirlenmedi.', data_get($child->fresh()->operation_control_payload, 'part_received_service_visit_assignment.settlement_blocker'));

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($child->fresh(), true);
        $this->assertSame(0.0, $payload['finance_summary']['current_visit']['locksmith_payout']['labor_amount']);
        $this->assertSame('none', $payload['finance_summary']['current_visit']['locksmith_payout']['payout_status']);
    }

    public function test_paid_payment_scope_correction_is_append_only_idempotent_and_projects_root_srv_totals(): void
    {
        [$actor, $technician, $root] = $this->assignedRoot([
            'mrn' => 'MRN-2608DD180009',
        ]);
        $child = $this->request([
            'mrn' => 'SRV-2608DD180009-001',
            'parent_request_id' => $root->id,
            'root_mrn' => $root->mrn,
            'service_sequence' => 1,
            'service_code' => 'SRV-2608DD180009-001',
            'service_visit_reason' => 'spare_part',
            'service_type' => 'Servis',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
        ]);
        $partRequest = $this->partRequest($root, [
            'service_amount' => 1000,
            'service_visit_route_fee_amount' => 500,
            'service_visit_route_fee_source' => 'ops_manual',
            'part_amount' => 500,
        ], $child);

        $mountSession = $this->mountSession($child);
        $mountPayment = TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $mountSession->id,
            'technical_service_request_id' => $child->id,
            'provider' => 'fake',
            'provider_reference' => 'scope-mount-208',
            'status' => TechnicalServiceMountPayment::STATUS_PAID,
            'amount' => 3500,
            'currency' => 'TRY',
            'paid_at' => now(),
            'raw_payload' => [
                'source' => 'mount_collection',
                'purpose' => 'mount_collection',
            ],
        ]);
        $servicePartPayment = TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $mountSession->id,
            'technical_service_request_id' => $root->id,
            'provider' => 'fake',
            'provider_reference' => 'scope-service-part-207',
            'status' => TechnicalServiceMountPayment::STATUS_PAID,
            'amount' => 1500,
            'currency' => 'TRY',
            'paid_at' => now(),
            'raw_payload' => [
                'source' => 'operation_customer_charge',
                'purpose' => 'service_and_part_payment',
                'charge_type' => 'service_and_part_payment',
                'service_amount' => 1000,
                'part_amount' => 500,
                'total_amount' => 1500,
                'part_request_id' => $partRequest->id,
                'srv_request_id' => $child->id,
            ],
        ]);
        [$rootOffer, $rootSettlement] = $this->offerAndSettlement($root, $technician, 3000, 500, $actor, 'ops_manual');
        [$childOffer, $childSettlement] = $this->offerAndSettlement($child, $technician, 1000, 500, $actor, 'ops_manual');

        $paymentSnapshot = TechnicalServiceMountPayment::query()
            ->whereIn('id', [$mountPayment->id, $servicePartPayment->id])
            ->orderBy('id')
            ->get(['id', 'status', 'amount', 'currency', 'provider_reference', 'raw_payload', 'updated_at'])
            ->map(fn (TechnicalServiceMountPayment $payment): array => $payment->getRawOriginal())
            ->all();
        $service = app(TechnicalServiceAssignmentSettlementService::class);
        $first = $service->applyRootSrvFinanceScopeCorrection(
            $root,
            $child,
            $partRequest,
            $mountPayment,
            $servicePartPayment,
            $actor,
            'Failed Iyzico create scope inversion repair.',
        );
        $second = $service->applyRootSrvFinanceScopeCorrection(
            $root,
            $child,
            $partRequest,
            $mountPayment,
            $servicePartPayment,
            $actor,
            'Failed Iyzico create scope inversion repair.',
        );

        $this->assertSame(2, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(2, DB::table('technical_service_payment_settlement_allocations')->where('decision', 'scope_correction')->count());
        $this->assertSame(1, $root->events()->where('event_type', 'root_srv_finance_scope_corrected')->count());
        $this->assertSame($paymentSnapshot, TechnicalServiceMountPayment::query()
            ->whereIn('id', [$mountPayment->id, $servicePartPayment->id])
            ->orderBy('id')
            ->get(['id', 'status', 'amount', 'currency', 'provider_reference', 'raw_payload', 'updated_at'])
            ->map(fn (TechnicalServiceMountPayment $payment): array => $payment->getRawOriginal())
            ->all());
        $this->assertSame($rootOffer->id, $rootSettlement->technical_service_assignment_offer_id);
        $this->assertSame($childOffer->id, $childSettlement->technical_service_assignment_offer_id);

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($child->fresh(), true);
        $currentCollection = $payload['finance_summary']['current_visit']['customer_collection'];
        $currentPayout = $payload['finance_summary']['current_visit']['locksmith_payout'];
        $rootCollection = $payload['finance_summary']['root_total']['customer_collection'];
        $rootPayout = $payload['finance_summary']['root_total']['locksmith_payout'];

        $this->assertSame(1000.0, $currentCollection['service_amount']);
        $this->assertSame(0.0, $currentCollection['part_amount']);
        $this->assertSame(1000.0, $currentCollection['total_amount']);
        $this->assertSame(1000.0, $currentPayout['labor_amount']);
        $this->assertSame(500.0, $currentPayout['route_fee_amount']);
        $this->assertSame(1500.0, $currentPayout['total_amount']);
        $this->assertSame('ops_manual', $currentPayout['route_source']);
        $this->assertSame('OPS tarafından manuel belirlendi', $currentPayout['route_source_label']);
        $this->assertSame(-500.0, $payload['finance_summary']['current_visit']['net_margin']['amount']);
        $this->assertSame(3500.0, $rootCollection['mount_amount']);
        $this->assertSame(1000.0, $rootCollection['service_amount']);
        $this->assertSame(500.0, $rootCollection['part_amount']);
        $this->assertSame(4500.0, $rootCollection['service_total_amount']);
        $this->assertSame(5000.0, $rootCollection['total_amount']);
        $this->assertSame(4000.0, $rootPayout['labor_amount']);
        $this->assertSame(1000.0, $rootPayout['route_fee_amount']);
        $this->assertSame(5000.0, $rootPayout['total_amount']);
        $this->assertSame('EMAKS Prime', $rootPayout['technician_payment_source_label']);
        $this->assertSame(-500.0, $payload['finance_summary']['root_total']['net_margin']['amount']);

        $currentRows = collect($payload['finance_summary']['payment_records']['current_scope_rows']);
        $this->assertFalse($currentRows->contains(fn (array $row): bool => (int) $row['id'] === (int) $mountPayment->id));
        $servicePartRow = $currentRows->firstWhere('id', $servicePartPayment->id);
        $this->assertSame(1000.0, $servicePartRow['selected_scope_service_amount']);
        $this->assertSame(500.0, $servicePartRow['part_information_amount']);
    }

    public function test_child_history_projects_root_previous_and_current_documents_without_copying_rows(): void
    {
        [, , $root] = $this->assignedRoot(['mrn' => 'MRN-DOCUMENT-SCOPE']);
        $previous = $this->request([
            'mrn' => 'SRV-DOCUMENT-SCOPE-001',
            'parent_request_id' => $root->id,
            'root_mrn' => $root->mrn,
            'service_sequence' => 1,
            'service_code' => 'SRV-DOCUMENT-SCOPE-001',
            'service_visit_reason' => 'revisit',
        ]);
        $current = $this->request([
            'mrn' => 'SRV-DOCUMENT-SCOPE-002',
            'parent_request_id' => $previous->id,
            'root_mrn' => $root->mrn,
            'service_sequence' => 2,
            'service_code' => 'SRV-DOCUMENT-SCOPE-002',
            'service_visit_reason' => 'spare_part',
        ]);
        $this->upload($root, TechnicalServiceRequestUpload::CATEGORY_OPERATION_CONTROL_DOOR_PHOTO, 'door_front_photo', 'root-front.jpg');
        $this->upload($previous, TechnicalServiceRequestUpload::CATEGORY_PARTNER_PORTAL_FIELD_DOCUMENT, 'before_photo', 'previous-before.jpg');
        $this->upload($current, TechnicalServiceRequestUpload::CATEGORY_PARTNER_PORTAL_FIELD_DOCUMENT, 'after_photo', 'current-after.jpg');
        $uploadCount = TechnicalServiceRequestUpload::query()->count();

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($current->fresh(), true);
        $record = collect($payload['service_visit_history']['history_records'])->firstWhere('id', $current->id);

        $this->assertNotNull($record);
        $this->assertSame(['Kaynak: Kök MRN'], collect($record['root_door_photos'])->pluck('source_label')->unique()->values()->all());
        $this->assertSame(['Kaynak: Önceki ziyaret'], collect($record['previous_visit_documents'])->pluck('source_label')->unique()->values()->all());
        $this->assertSame(['Kaynak: Bu SRV'], collect($record['current_documents'])->pluck('source_label')->unique()->values()->all());
        $this->assertSame($root->mrn, $record['root_door_photos'][0]['source_request_code']);
        $this->assertSame($previous->service_code, $record['previous_visit_documents'][0]['source_request_code']);
        $this->assertSame($current->service_code, $record['current_documents'][0]['source_request_code']);
        $this->assertSame($uploadCount, TechnicalServiceRequestUpload::query()->count());
    }

    public function test_mixed_payer_sources_project_as_mixed(): void
    {
        [$actor, $technician, $root] = $this->assignedRoot();
        $child = $this->request([
            'mrn' => 'SRV-MIXED-PAYER-001',
            'parent_request_id' => $root->id,
            'root_mrn' => $root->mrn,
            'service_sequence' => 1,
            'service_code' => 'SRV-MIXED-PAYER-001',
            'service_visit_reason' => 'revisit',
            'service_type' => 'Servis',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
        ]);
        $this->offerAndSettlement($root, $technician, 3000, 500, $actor, 'ops_manual');
        $offer = TechnicalServiceAssignmentOffer::query()->create([
            'technical_service_request_id' => $child->id,
            'technical_service_technician_id' => $technician->id,
            'labor_amount' => 1000,
            'route_fee_amount' => 500,
            'total_amount' => 1500,
            'currency' => 'TRY',
            'status' => TechnicalServiceAssignmentOffer::STATUS_ACCEPTED,
            'sent_by' => $actor->id,
            'sent_at' => now(),
            'metadata' => [
                'route_source' => 'ops_manual',
                'confirmed_by_ops' => true,
                'earning_payment_source' => TechnicalServiceAssignmentSettlementService::EARNING_PAYMENT_SOURCE_CUSTOMER_DIRECT,
            ],
        ]);
        app(TechnicalServiceAssignmentSettlementService::class)->persistForAssignment(
            $child,
            $technician,
            $offer,
            null,
            1000,
            500,
            1500,
            $actor,
            TechnicalServiceAssignmentSettlementService::EARNING_PAYMENT_SOURCE_CUSTOMER_DIRECT,
        );

        $payload = app(TechnicalServiceWorkflowService::class)->serialize($child->fresh(), true);

        $this->assertSame('Karma ödeme kaynağı', $payload['finance_summary']['root_total']['locksmith_payout']['technician_payment_source_label']);
        $this->assertSame('mixed', $payload['finance_summary']['root_total']['locksmith_payout']['technician_payment_source_key']);
    }

    /** @return array{User,TechnicalServiceTechnician,TechnicalServiceRequest} */
    private function assignedRoot(array $overrides = []): array
    {
        $actor = User::factory()->create(['role_code' => 'admin']);
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Root SRV Finans Ustası',
            'phone' => '+905551110001',
            'city' => 'Sentetik Sehir 001',
            'active' => true,
        ]);
        $root = $this->request(array_merge([
            'mrn' => 'MRN-ROOT-SRV-FINANCE',
            'service_type' => 'Montaj',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
        ], $overrides));

        return [$actor, $technician, $root];
    }

    private function request(array $overrides = []): TechnicalServiceRequest
    {
        return TechnicalServiceRequest::query()->create(array_merge([
            'mrn' => 'MRN-ROOT-SRV-'.uniqid(),
            'customer_name' => 'Sentetik Müşteri',
            'customer_phone' => '5550000000',
            'customer_city' => 'Sentetik Sehir 001',
            'customer_district' => 'Seyhan',
            'service_address' => 'Sentetik adres',
            'product_name' => 'Sentetik Ürün',
            'serial_number' => 'SN-'.uniqid(),
            'service_type' => 'Montaj',
            'status' => 'Yeni',
            'priority' => 'Orta',
            'risk_level' => 'Orta',
            'source_channel' => 'panel',
        ], $overrides));
    }

    private function partRequest(
        TechnicalServiceRequest $root,
        array $metadata,
        ?TechnicalServiceRequest $child = null,
    ): TechnicalServicePartRequest {
        return TechnicalServicePartRequest::query()->create([
            'technical_service_request_id' => $root->id,
            'root_request_id' => $root->id,
            'service_visit_request_id' => $child?->id,
            'status' => $child === null
                ? TechnicalServicePartRequest::STATUS_SERVICE_VISIT_REQUIRED
                : TechnicalServicePartRequest::STATUS_SERVICE_VISIT_CREATED,
            'part_name' => 'Sentetik servis parçası',
            'quantity' => 1,
            'requires_service_visit' => true,
            'metadata' => [
                'charge_decision' => 'chargeable',
                ...$metadata,
            ],
        ]);
    }

    /** @return array{TechnicalServiceAssignmentOffer,TechnicalServiceSettlement} */
    private function offerAndSettlement(
        TechnicalServiceRequest $request,
        TechnicalServiceTechnician $technician,
        float $labor,
        float $route,
        User $actor,
        string $routeSource,
    ): array {
        $offer = TechnicalServiceAssignmentOffer::query()->create([
            'technical_service_request_id' => $request->id,
            'technical_service_technician_id' => $technician->id,
            'labor_amount' => $labor,
            'route_fee_amount' => $route,
            'total_amount' => $labor + $route,
            'currency' => 'TRY',
            'status' => TechnicalServiceAssignmentOffer::STATUS_ACCEPTED,
            'sent_by' => $actor->id,
            'sent_at' => now(),
            'metadata' => [
                'route_source' => $routeSource,
                'confirmed_by_ops' => true,
                'earning_payment_source' => TechnicalServiceAssignmentSettlementService::EARNING_PAYMENT_SOURCE_COMPANY,
            ],
        ]);
        $settlement = app(TechnicalServiceAssignmentSettlementService::class)->persistForAssignment(
            $request,
            $technician,
            $offer,
            null,
            $labor,
            $route,
            0,
            $actor,
            TechnicalServiceAssignmentSettlementService::EARNING_PAYMENT_SOURCE_COMPANY,
        );

        return [$offer->refresh(), $settlement->refresh()];
    }

    private function mountSession(TechnicalServiceRequest $request): TechnicalServiceMountSession
    {
        ['link' => $link] = TechnicalServiceQrLink::createPreSaleProductLink([
            'serial_number' => $request->serial_number,
            'product_name' => $request->product_name,
        ]);
        $session = TechnicalServiceMountSession::query()->create([
            'technical_service_qr_link_id' => $link->id,
            'session_token_hash' => TechnicalServiceMountSession::hashSessionToken('scope-session-'.uniqid()),
            'serial_number' => $request->serial_number,
            'sale_mount_status' => TechnicalServiceMountSession::SALE_UNKNOWN,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PAID,
            'decision_status' => TechnicalServiceMountSession::DECISION_SUBMITTED,
            'context_payload' => [],
        ]);
        $request->forceFill([
            'qr_link_id' => $link->id,
            'mount_session_id' => $session->id,
        ])->save();

        return $session;
    }

    private function upload(
        TechnicalServiceRequest $request,
        string $category,
        string $fieldCode,
        string $name,
    ): TechnicalServiceRequestUpload {
        return TechnicalServiceRequestUpload::query()->create([
            'technical_service_request_id' => $request->id,
            'category' => $category,
            'field_code' => $fieldCode,
            'original_name' => $name,
            'path' => 'technical-service/synthetic/'.$name,
            'mime' => 'image/jpeg',
            'size' => 128,
            'review_status' => 'accepted',
        ]);
    }
}
