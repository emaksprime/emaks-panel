<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestUpload;
use App\Models\User;
use App\Services\TechnicalService\TechnicalServiceWorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechnicalServiceFieldWorkflowTest extends TestCase
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

    public function test_planned_request_can_start_travel(): void
    {
        $request = $this->technicalServiceRequest([
            'workflow_status' => 'Planlı',
            'status' => 'Randevulu',
        ]);

        $this->actingAs($this->adminUser())
            ->patchJson("/api/technical-service/requests/{$request->id}/field/start-travel", [])
            ->assertOk()
            ->assertJsonPath('request.workflow_status', 'Yolda')
            ->assertJsonPath('request.field_status', 'yolda');
    }

    public function test_request_on_the_way_can_arrive_on_site(): void
    {
        $request = $this->technicalServiceRequest([
            'workflow_status' => 'Yolda',
            'field_status' => 'yolda',
            'technician_started_at' => CarbonImmutable::now()->subMinutes(20),
        ]);

        $this->actingAs($this->adminUser())
            ->patchJson("/api/technical-service/requests/{$request->id}/field/arrive", [])
            ->assertOk()
            ->assertJsonPath('request.workflow_status', 'Sahada')
            ->assertJsonPath('request.field_status', 'sahada');
    }

    public function test_sahada_request_cannot_complete_without_checklist(): void
    {
        $request = $this->technicalServiceRequest([
            'workflow_status' => 'Sahada',
            'field_status' => 'sahada',
            'before_photo_count' => 3,
            'after_photo_count' => 3,
            'general_photo_count' => 1,
            'photo_status' => 'tamamlandı',
            'document_status' => 'tamamlandı',
            'customer_closure_approval_status' => 'onaylandı',
        ]);

        $this->actingAs($this->adminUser())
            ->patchJson("/api/technical-service/requests/{$request->id}/field/complete", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('workflow_status');

        $request->refresh();

        $this->assertSame('Sahada', $request->workflow_status);
        $this->assertStringContainsString('Checklist', (string) $request->completion_block_reason);
    }

    public function test_photo_update_endpoint_computes_photo_status(): void
    {
        $request = $this->technicalServiceRequest([
            'workflow_status' => 'Sahada',
            'field_status' => 'sahada',
        ]);

        $this->actingAs($this->adminUser())
            ->patchJson("/api/technical-service/requests/{$request->id}/field/photos", [
                'before_photo_count' => 3,
                'after_photo_count' => 2,
                'general_photo_count' => 1,
                'document_status' => 'eksik',
            ])
            ->assertOk()
            ->assertJsonPath('request.photo_status', 'eksik');

        $request->refresh();

        $this->assertSame('eksik', $request->photo_status);
    }

    public function test_ops_can_review_canonical_field_completion_documents(): void
    {
        $request = $this->technicalServiceRequest([
            'workflow_status' => 'Son Kontrol',
            'field_status' => 'son_kontrol',
        ]);
        $upload = TechnicalServiceRequestUpload::query()->create([
            'technical_service_request_id' => $request->id,
            'field_code' => 'before_photo',
            'category' => TechnicalServiceRequestUpload::CATEGORY_OPERATION_CONTROL_DOOR_PHOTO,
            'original_name' => 'before.jpg',
            'path' => 'technical-service/test/before.jpg',
            'mime' => 'image/jpeg',
            'size' => 123,
        ]);

        $this->actingAs($this->adminUser())
            ->patchJson("/api/technical-service/requests/{$request->id}/field-documents/{$upload->id}/review", [
                'status' => 'rejected',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('note');

        $payload = $this->actingAs($this->adminUser())
            ->patchJson("/api/technical-service/requests/{$request->id}/field-documents/{$upload->id}/review", [
                'status' => 'accepted',
            ])
            ->assertOk()
            ->json();

        $this->assertSame('accepted', $payload['request']['field_completion_documents'][0]['review_status'] ?? null);
        $this->assertDatabaseHas('technical_service_request_uploads', [
            'id' => $upload->id,
            'review_status' => 'accepted',
        ]);
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $request->id,
            'event_type' => 'field_document_reviewed',
        ]);
    }

    public function test_customer_closure_approval_is_required_for_completion(): void
    {
        $request = $this->technicalServiceRequest([
            'workflow_status' => 'Sahada',
            'field_status' => 'sahada',
            'checklist_status' => 'tamamlandı',
            'checklist_payload' => $this->completedChecklist(),
            'before_photo_count' => 3,
            'after_photo_count' => 3,
            'general_photo_count' => 1,
            'photo_status' => 'tamamlandı',
            'document_status' => 'tamamlandı',
        ]);

        $this->actingAs($this->adminUser())
            ->patchJson("/api/technical-service/requests/{$request->id}/field/complete", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('workflow_status');

        $request->refresh();

        $this->assertSame('Müşteri Kapanış Onayı Bekleyen', $request->workflow_status);
    }

    public function test_customer_closure_approval_endpoint_stores_approval_metadata(): void
    {
        $request = $this->technicalServiceRequest([
            'workflow_status' => 'Sahada',
            'field_status' => 'sahada',
        ]);

        $this->actingAs($this->adminUser())
            ->patchJson("/api/technical-service/requests/{$request->id}/field/customer-closure-approval", [
                'approval_method' => 'otp',
                'approval_code' => '123456',
                'note' => 'OTP doğrulandı',
            ])
            ->assertOk()
            ->assertJsonPath('request.customer_closure_approval_status', 'onaylandı');

        $request->refresh();

        $this->assertSame('otp', $request->customer_closure_approval_method);
    }

    public function test_ops_field_documents_ui_exposes_clear_review_and_final_completion_controls(): void
    {
        $source = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx')) ?: '';

        $this->assertStringContainsString('backendControlComplete', $source);
        $this->assertStringContainsString('finalCheckActionChecklistComplete', $source);
        $this->assertStringContainsString('Atanan Usta', $source);
        $this->assertStringContainsString('!isFinalCheckStage', $source);
        $this->assertStringContainsString('Saha belgeleri uygun', $source);
        $this->assertStringContainsString('Saha belgeleri uygun değil', $source);
        $this->assertStringContainsString('Kararı değiştir', $source);
        $this->assertStringContainsString('Son kontrolü tamamla', $source);
        $this->assertStringContainsString('Saha belgeleri uygunluk kararı bekliyor', $source);
    }

    public function test_request_can_complete_when_checklist_photos_and_customer_closure_are_ready(): void
    {
        $request = $this->technicalServiceRequest([
            'workflow_status' => 'Sahada',
            'field_status' => 'sahada',
            'checklist_status' => 'tamamlandı',
            'checklist_payload' => $this->completedChecklist(),
            'before_photo_count' => 3,
            'after_photo_count' => 3,
            'general_photo_count' => 1,
            'photo_status' => 'tamamlandı',
            'document_status' => 'tamamlandı',
            'customer_closure_approval_status' => 'onaylandı',
            'customer_closure_approved_at' => CarbonImmutable::now()->subMinutes(15),
        ]);

        $this->actingAs($this->adminUser())
            ->patchJson("/api/technical-service/requests/{$request->id}/field/complete", [
                'note' => 'Saha işi tamamlandı.',
            ])
            ->assertOk()
            ->assertJsonPath('request.workflow_status', 'Tamamlandı');

        $request->refresh();

        $this->assertNotNull($request->field_completed_at);
        $this->assertDatabaseHas('technical_service_audit_logs', [
            'entity_type' => 'technical_service_request',
            'entity_id' => $request->id,
            'action_type' => 'field_completed',
        ]);
    }

    public function test_mark_incomplete_requires_reason(): void
    {
        $request = $this->technicalServiceRequest([
            'workflow_status' => 'Sahada',
            'field_status' => 'sahada',
        ]);

        $this->actingAs($this->adminUser())
            ->patchJson("/api/technical-service/requests/{$request->id}/field/mark-incomplete", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('incomplete_reason');
    }

    public function test_parts_pending_action_moves_request_to_parts_pending(): void
    {
        $request = $this->technicalServiceRequest([
            'workflow_status' => 'Sahada',
            'field_status' => 'sahada',
        ]);

        $this->actingAs($this->adminUser())
            ->patchJson("/api/technical-service/requests/{$request->id}/field/mark-incomplete", [
                'workflow_status' => 'Parça Bekleniyor',
                'incomplete_reason' => 'Eksik parça tespit edildi',
                'pending_reason' => 'Parça siparişi açılacak',
            ])
            ->assertOk()
            ->assertJsonPath('request.workflow_status', 'Parça Bekleniyor');
    }

    public function test_second_visit_action_marks_request_for_second_visit(): void
    {
        $request = $this->technicalServiceRequest([
            'workflow_status' => 'Sahada',
            'field_status' => 'sahada',
        ]);

        $this->actingAs($this->adminUser())
            ->patchJson("/api/technical-service/requests/{$request->id}/field/mark-incomplete", [
                'workflow_status' => 'Beklemede',
                'incomplete_reason' => 'Müşteri ek iş istedi',
                'requires_second_visit' => true,
                'second_visit_reason' => 'Ek malzeme ile tekrar gidilecek',
            ])
            ->assertOk()
            ->assertJsonPath('request.requires_second_visit', true);

        $request->refresh();

        $this->assertTrue((bool) $request->requires_second_visit);
    }

    public function test_field_actions_write_audit_logs(): void
    {
        $request = $this->technicalServiceRequest([
            'workflow_status' => 'Planlı',
            'status' => 'Randevulu',
        ]);

        $this->actingAs($this->adminUser())
            ->patchJson("/api/technical-service/requests/{$request->id}/field/start-travel", [])
            ->assertOk();

        $this->assertDatabaseHas('technical_service_audit_logs', [
            'entity_type' => 'technical_service_request',
            'entity_id' => $request->id,
            'action_type' => 'field_travel_started',
        ]);
    }

    public function test_summary_exposes_field_queue_counts(): void
    {
        $this->technicalServiceRequest([
            'workflow_status' => 'Planlı',
            'technician_name' => 'Ada Usta',
        ]);
        $this->technicalServiceRequest([
            'workflow_status' => 'Parça Bekleniyor',
            'field_status' => 'beklemede',
        ]);

        $payload = $this->actingAs($this->adminUser())
            ->getJson('/api/technical-service/summary')
            ->assertOk()
            ->json();

        $this->assertSame(1, $payload['workflow_queue_counts']['travel_pending'] ?? null);
        $this->assertSame(1, $payload['workflow_queue_counts']['parts_pending'] ?? null);
    }

    public function test_sla_becomes_overdue_for_on_site_request_that_is_not_closed_in_time(): void
    {
        $request = $this->technicalServiceRequest([
            'workflow_status' => 'Sahada',
            'field_status' => 'sahada',
            'field_started_at' => CarbonImmutable::now()->subHours(5),
        ]);

        $service = app(TechnicalServiceWorkflowService::class);
        $service->initializeRequest($request);

        $this->assertSame(TechnicalServiceWorkflowService::SLA_OVERDUE, $request->sla_status);
    }

    private function adminUser(): User
    {
        return User::factory()->create(['role_code' => 'admin']);
    }

    /**
     * @return array<string, bool>
     */
    private function completedChecklist(): array
    {
        return [
            'Ürün seri numarası kontrol edildi' => true,
            'Kapı / montaj yeri kontrol edildi' => true,
            'Montaj uygunluğu kontrol edildi' => true,
            'Ürün çalışır durumda test edildi' => true,
            'Müşteriye kullanım bilgisi verildi' => true,
            'Garanti / servis formu bilgisi kontrol edildi' => true,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function technicalServiceRequest(array $overrides = []): TechnicalServiceRequest
    {
        return TechnicalServiceRequest::query()->create(array_merge([
            'mrn' => 'MRN-FIELD-'.uniqid(),
            'customer_name' => 'Saha Test Müşteri',
            'customer_phone' => '5550000000',
            'customer_city' => 'Adana',
            'customer_district' => 'Seyhan',
            'service_address' => 'Saha test adresi',
            'product_name' => 'Test Ürün',
            'serial_number' => 'SN-FIELD-'.uniqid(),
            'service_type' => 'Montaj',
            'status' => 'Randevulu',
            'priority' => 'Orta',
            'risk_level' => 'Orta',
            'source_channel' => 'panel',
        ], $overrides));
    }
}
