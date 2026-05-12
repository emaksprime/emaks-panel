<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechnicalServiceCustomerContactTest extends TestCase
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

    public function test_customer_called_moves_request_to_customer_confirmation_stage(): void
    {
        $request = $this->technicalServiceRequest(['workflow_status' => 'Müşteri Aranacak']);

        $this->actingAs($this->adminUser())
            ->postJson("/api/technical-service/requests/{$request->id}/contact-log", [
                'action' => 'customer_called',
                'contact_method' => 'telefon',
                'note' => 'İlk görüşme yapıldı.',
            ])
            ->assertOk()
            ->assertJsonPath('request.workflow_status', 'Müşteri Onayı Bekleyen')
            ->assertJsonPath('request.customer_contact_status', 'arandı');
    }

    public function test_customer_callback_action_stores_callback_and_marks_sla_overdue_when_past(): void
    {
        $request = $this->technicalServiceRequest(['workflow_status' => 'Müşteriye Ulaşılamadı']);

        $this->actingAs($this->adminUser())
            ->postJson("/api/technical-service/requests/{$request->id}/contact-log", [
                'action' => 'customer_callback_scheduled',
                'customer_callback_at' => '2026-05-09 09:00:00',
            ])
            ->assertOk()
            ->assertJsonPath('request.customer_contact_status', 'tekrar_aranacak')
            ->assertJsonPath('request.sla_status', 'geciken');

        $request->refresh();

        $this->assertNotNull($request->customer_callback_at);
    }

    public function test_customer_confirmed_stores_preference_without_writing_schedule(): void
    {
        $request = $this->technicalServiceRequest(['workflow_status' => 'Müşteri Onayı Bekleyen']);

        $this->actingAs($this->adminUser())
            ->postJson("/api/technical-service/requests/{$request->id}/contact-log", [
                'action' => 'customer_confirmed',
                'customer_preferred_date' => '2026-05-12',
                'customer_preferred_time_start' => '14:00',
                'customer_preferred_time_end' => '16:00',
                'customer_confirmation_method' => 'telefon',
                'note' => 'Müşteri bu aralığı onayladı.',
            ])
            ->assertOk()
            ->assertJsonPath('request.workflow_status', 'Müşteri Onayladı')
            ->assertJsonPath('request.customer_preferred_time_start', '14:00')
            ->assertJsonPath('request.next_action', 'Randevu planlanmalı');

        $request->refresh();

        $this->assertSame('2026-05-12', $request->customer_preferred_date?->toDateString());
        $this->assertNull($request->scheduled_date);
        $this->assertDatabaseHas('technical_service_audit_logs', [
            'entity_type' => 'technical_service_request',
            'entity_id' => $request->id,
            'action_type' => 'customer_confirmed',
        ]);
    }

    public function test_schedule_endpoint_plans_final_appointment_after_customer_confirmation(): void
    {
        $request = $this->technicalServiceRequest([
            'workflow_status' => 'Müşteri Onayladı',
            'customer_confirmed_at' => CarbonImmutable::now()->toDateTimeString(),
            'customer_preferred_date' => '2026-05-12',
            'customer_preferred_time_start' => '14:00',
            'customer_preferred_time_end' => '16:00',
        ]);

        $this->actingAs($this->adminUser())
            ->patchJson("/api/technical-service/requests/{$request->id}/schedule", [
                'scheduled_date' => '2026-05-13',
                'scheduled_time' => '10:00',
                'note' => 'Operasyon tarafından farklı güne planlandı.',
            ])
            ->assertOk()
            ->assertJsonPath('request.workflow_status', 'Randevu Planlandı');

        $request->refresh();

        $this->assertSame('2026-05-13', $request->scheduled_date?->toDateString());
    }

    public function test_wrong_number_requires_note(): void
    {
        $request = $this->technicalServiceRequest(['workflow_status' => 'Müşteri Aranacak']);

        $this->actingAs($this->adminUser())
            ->postJson("/api/technical-service/requests/{$request->id}/contact-log", [
                'action' => 'wrong_number',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');
    }

    public function test_customer_rejected_requires_reason(): void
    {
        $request = $this->technicalServiceRequest(['workflow_status' => 'Müşteri Onayı Bekleyen']);

        $this->actingAs($this->adminUser())
            ->postJson("/api/technical-service/requests/{$request->id}/contact-log", [
                'action' => 'customer_rejected',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('customer_rejection_reason');
    }

    public function test_invalid_contact_action_returns_validation_error(): void
    {
        $request = $this->technicalServiceRequest(['workflow_status' => 'Müşteri Aranacak']);

        $this->actingAs($this->adminUser())
            ->postJson("/api/technical-service/requests/{$request->id}/contact-log", [
                'action' => 'invalid_action',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('action');
    }

    public function test_summary_exposes_customer_contact_queue_counts(): void
    {
        $this->technicalServiceRequest([
            'workflow_status' => 'Müşteri Aranacak',
            'customer_contact_status' => 'aranacak',
        ]);
        $this->technicalServiceRequest([
            'workflow_status' => 'Müşteriye Ulaşılamadı',
            'customer_contact_status' => 'tekrar_aranacak',
        ]);

        $payload = $this->actingAs($this->adminUser())
            ->getJson('/api/technical-service/summary')
            ->assertOk()
            ->json();

        $this->assertSame(1, $payload['workflow_queue_counts']['customer_call'] ?? null);
        $this->assertSame(1, $payload['workflow_queue_counts']['customer_callback'] ?? null);
    }

    private function adminUser(): User
    {
        return User::factory()->create(['role_code' => 'admin']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function technicalServiceRequest(array $overrides = []): TechnicalServiceRequest
    {
        return TechnicalServiceRequest::query()->create(array_merge([
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
    }
}
