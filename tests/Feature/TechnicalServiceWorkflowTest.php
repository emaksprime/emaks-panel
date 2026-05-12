<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceRequest;
use App\Models\User;
use App\Services\TechnicalService\TechnicalServiceWorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TechnicalServiceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-05-09 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_workflow_service_initializes_legacy_status_and_computes_sla_and_next_action(): void
    {
        $request = $this->technicalServiceRequest([
            'status' => 'Yeni',
        ], [
            'created_at' => CarbonImmutable::now()->subHours(30),
            'updated_at' => CarbonImmutable::now()->subHours(30),
        ]);

        $service = app(TechnicalServiceWorkflowService::class);
        $service->initializeRequest($request);

        $this->assertSame('Yeni Talep', $service->currentWorkflowStatus($request));
        $this->assertSame('Yeni', $request->status);
        $this->assertSame(TechnicalServiceWorkflowService::SLA_OVERDUE, $request->sla_status);
        $this->assertNotNull($request->sla_due_at);
        $this->assertNotEmpty($request->next_action);
    }

    public function test_workflow_service_rejects_invalid_transition(): void
    {
        $service = app(TechnicalServiceWorkflowService::class);

        $this->expectException(ValidationException::class);

        $service->assertTransitionAllowed('Yeni Talep', 'Tamamlandı');
    }

    public function test_schedule_endpoint_validates_time_and_updates_workflow_with_audit_log(): void
    {
        $user = $this->adminUser();
        $request = $this->technicalServiceRequest([
            'status' => 'Yeni',
            'workflow_status' => 'Müşteri Onayladı',
        ]);

        $this->actingAs($user)
            ->patchJson("/api/technical-service/requests/{$request->id}/schedule", [
                'scheduled_date' => '2026-05-10',
                'scheduled_time' => '25:99',
            ])
            ->assertStatus(422);

        $this->actingAs($user)
            ->patchJson("/api/technical-service/requests/{$request->id}/schedule", [
                'scheduled_date' => '2026-05-10',
                'scheduled_time' => '14:30',
                'note' => 'Müşteriyle teyit edildi',
            ])
            ->assertOk()
            ->assertJsonPath('request.scheduled_time', '14:30');

        $request->refresh();

        $this->assertNotNull($request->scheduled_at);
        $this->assertSame('2026-05-10', $request->scheduled_date?->toDateString());
        $this->assertDatabaseHas('technical_service_audit_logs', [
            'entity_type' => 'technical_service_request',
            'entity_id' => $request->id,
            'action_type' => 'schedule_updated',
            'user_id' => $user->id,
        ]);
    }

    public function test_workflow_endpoint_rejects_invalid_action_for_current_status(): void
    {
        $user = $this->adminUser();
        $request = $this->technicalServiceRequest([
            'status' => 'Yeni',
            'workflow_status' => 'Yeni Talep',
        ]);

        $this->actingAs($user)
            ->patchJson("/api/technical-service/requests/{$request->id}/workflow", [
                'action' => 'complete',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('workflow_status');
    }

    public function test_assign_endpoint_allows_new_request_to_wait_for_technician_approval(): void
    {
        $user = $this->adminUser();
        $request = $this->technicalServiceRequest([
            'status' => 'Yeni',
            'workflow_status' => 'Yeni Talep',
        ]);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/assign", [
                'technician_name' => 'Test Usta',
                'travel_round_trip_km' => 12,
            ])
            ->assertOk()
            ->assertJsonPath('request.workflow_status', 'Usta Onayı Bekleyen');

        $request->refresh();

        $this->assertSame('Usta Onayı Bekleyen', $request->workflow_status);
        $this->assertSame('bekliyor', $request->technician_approval_status);
    }

    public function test_contact_log_endpoint_advances_customer_contact_workflow(): void
    {
        $user = $this->adminUser();
        $request = $this->technicalServiceRequest([
            'status' => 'Yeni',
            'workflow_status' => 'Müşteri Aranacak',
        ]);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/contact-log", [
                'action' => 'customer_called',
                'contact_method' => 'telefon',
                'note' => 'Müşteri ile ilk görüşme yapıldı',
            ])
            ->assertOk();

        $request->refresh();

        $this->assertNotNull($request->customer_contacted_at);
        $this->assertNotNull($request->customer_contact_status);
        $this->assertDatabaseHas('technical_service_audit_logs', [
            'entity_type' => 'technical_service_request',
            'entity_id' => $request->id,
            'action_type' => 'customer_called',
        ]);
    }

    public function test_audit_logs_endpoint_returns_workflow_history(): void
    {
        $user = $this->adminUser();
        $request = $this->technicalServiceRequest([
            'status' => 'Atandı',
            'workflow_status' => 'Usta Onayı Bekleyen',
        ]);

        $this->actingAs($user)
            ->patchJson("/api/technical-service/requests/{$request->id}/workflow", [
                'action' => 'technician_revision_requested',
                'note' => 'Usta yeni tarih talep etti',
                'technician_revision_note' => 'Öğleden sonra uygun',
            ])
            ->assertOk();

        $response = $this->actingAs($user)
            ->getJson("/api/technical-service/requests/{$request->id}/audit-logs")
            ->assertOk()
            ->json('items');

        $this->assertIsArray($response);
        $this->assertNotEmpty($response);
        $this->assertSame('technician_revision_requested', $response[0]['action_type'] ?? null);
    }

    public function test_summary_includes_workflow_status_counts(): void
    {
        $this->technicalServiceRequest([
            'status' => 'Devam Ediyor',
            'workflow_status' => 'Parça Bekleniyor',
        ]);
        $this->technicalServiceRequest([
            'status' => 'Devam Ediyor',
            'workflow_status' => 'Belge / Fotoğraf Bekleyen',
        ]);

        $payload = $this->actingAs($this->adminUser())
            ->getJson('/api/technical-service/summary')
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('workflow_status_counts', $payload);
        $this->assertIsArray($payload['workflow_status_counts']);
        $this->assertSame(2, array_sum($payload['workflow_status_counts']));
    }

    public function test_show_endpoint_returns_detail_payload_and_tolerates_missing_audit_log_table(): void
    {
        $user = $this->adminUser();
        $request = $this->technicalServiceRequest([
            'status' => 'Atandı',
            'workflow_status' => 'Usta Onayı Bekleyen',
            'travel_round_trip_km' => 42,
            'travel_billable_km' => 12,
            'travel_fee_amount' => 120,
            'technician_payment_amount' => 3000,
        ]);

        Schema::dropIfExists('technical_service_audit_logs');

        $this->actingAs($user)
            ->getJson("/api/technical-service/requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('request.id', $request->id)
            ->assertJsonPath('request.workflow_status', 'Usta Onayı Bekleyen')
            ->assertJsonPath('request.customer_fee', 3000)
            ->assertJsonPath('request.technician_fee', 3000)
            ->assertJsonPath('request.travel_fee', 120)
            ->assertJsonPath('request.total_technician_cost', 3120)
            ->assertJsonPath('request.cost_delta', -120)
            ->assertJsonPath('request.audit_logs_unavailable', true);
    }

    private function adminUser(): User
    {
        return User::factory()->create(['role_code' => 'admin']);
    }

    /**
     * @param array<string, mixed> $overrides
     * @param array<string, mixed> $timestamps
     */
    private function technicalServiceRequest(array $overrides = [], array $timestamps = []): TechnicalServiceRequest
    {
        $request = TechnicalServiceRequest::query()->create(array_merge([
            'mrn' => 'MRN-TEST-'.uniqid(),
            'customer_name' => 'Test Müşteri',
            'customer_phone' => '5550000000',
            'customer_city' => 'Adana',
            'customer_district' => 'Seyhan',
            'service_address' => 'Test adres',
            'product_name' => 'Test Ürün',
            'serial_number' => 'SN-TEST-'.uniqid(),
            'service_type' => 'Montaj',
            'status' => 'Yeni',
            'priority' => 'Orta',
            'risk_level' => 'Orta',
            'source_channel' => 'panel',
        ], $overrides));

        if ($timestamps !== []) {
            foreach ($timestamps as $column => $value) {
                $request->{$column} = $value;
            }

            $request->saveQuietly();
        }

        return $request;
    }
}
