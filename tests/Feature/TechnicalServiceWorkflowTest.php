<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestSerial;
use App\Models\TechnicalServiceRequestUpload;
use App\Models\User;
use App\Services\TechnicalService\MikroInvoiceSerialsService;
use App\Services\TechnicalService\TechnicalServiceWorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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
            'operation_control_payload' => [
                'payment_checked' => 'yes',
                'door_photos_checked' => 'compatible',
            ],
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
            'operation_control_payload' => [
                'payment_checked' => 'yes',
                'door_photos_checked' => 'compatible',
            ],
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

    public function test_operation_control_patch_persists_and_unlocks_assignment(): void
    {
        $user = $this->adminUser();
        $request = $this->technicalServiceRequest([
            'status' => 'Yeni',
            'workflow_status' => 'Yeni Talep',
        ]);

        $this->actingAs($user)
            ->patchJson("/api/technical-service/requests/{$request->id}/operation-control", [
                'payment_checked' => 'yes',
                'address_checked' => 'yes',
                'door_photos_checked' => 'compatible',
                'missing_info' => 'no',
                'customer_call_required' => 'no',
                'schedule_update_required' => 'no',
                'note' => 'Operasyon kontrolü tamamlandı.',
            ])
            ->assertOk()
            ->assertJsonPath('request.operation_control.payment_checked', 'yes')
            ->assertJsonPath('request.operation_control.door_photos_checked', 'compatible')
            ->assertJsonPath('request.assignment_blockers.messages', []);

        $request->refresh();

        $this->assertSame('yes', $request->operation_control_payload['payment_checked'] ?? null);
        $this->assertSame('compatible', $request->operation_control_payload['door_photos_checked'] ?? null);
        $this->assertSame($user->id, $request->operation_control_checked_by_user_id);
        $this->assertNotNull($request->operation_control_checked_at);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/assign", [
                'technician_name' => 'Test Usta',
                'travel_round_trip_km' => 12,
            ])
            ->assertOk()
            ->assertJsonPath('request.workflow_status', 'Usta Onayı Bekleyen');
    }

    public function test_assignment_is_blocked_until_payment_and_door_photo_controls_are_complete(): void
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
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'operation_control.payment_checked',
                'operation_control.door_photos_checked',
            ]);

        $this->actingAs($user)
            ->patchJson("/api/technical-service/requests/{$request->id}/operation-control", [
                'payment_checked' => 'yes',
                'door_photos_checked' => 'unreviewed',
            ])
            ->assertOk();

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/assign", [
                'technician_name' => 'Test Usta',
                'travel_round_trip_km' => 12,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['operation_control.door_photos_checked']);
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
        Storage::fake('public');
        $user = $this->adminUser();
        $request = $this->technicalServiceRequest([
            'status' => 'Atandı',
            'workflow_status' => 'Usta Onayı Bekleyen',
            'travel_round_trip_km' => 42,
            'travel_billable_km' => 12,
            'travel_fee_amount' => 120,
            'technician_payment_amount' => 3000,
            'source_channel' => TechnicalServiceRequest::SOURCE_QR_MOUNT_FORM,
            'qr_link_id' => 10,
            'mount_session_id' => 20,
            'brand' => 'EMAKS PRIME',
            'stock_code' => 'STK-001',
            'activation_code' => '275023',
            'sale_mount_status' => 'montaj_haric',
            'mount_payment_status' => 'paid',
            'mount_payment_label' => 'Montaj ödemesi alındı',
            'mount_payment_reference' => 'fake-reference',
            'invoice_display_no' => 'FAT/123',
            'dispatch_display_no' => 'IRS/456',
            'order_display_no' => 'SIP/789',
            'location_latitude' => 40.9876543,
            'location_longitude' => 29.1234567,
            'location_formatted_address' => 'Caferağa Mahallesi, Kadıköy/İstanbul',
            'location_map_url' => 'https://www.google.com/maps?q=40.9876543,29.1234567',
            'building_no' => '12',
            'apartment_no' => 'A',
            'door_no' => '5',
            'floor_no' => '2',
            'operation_control_payload' => [
                'payment_checked' => 'yes',
                'door_photos_checked' => 'compatible',
            ],
        ]);
        Storage::disk('public')->put('technical-service/requests/test/front.jpg', 'fake image');
        $request->uploads()->create([
            'field_code' => 'door_front_photo',
            'category' => TechnicalServiceRequestUpload::CATEGORY_OPERATION_CONTROL_DOOR_PHOTO,
            'original_name' => 'front.jpg',
            'path' => 'technical-service/requests/test/front.jpg',
            'mime' => 'image/jpeg',
            'size' => 123456,
        ]);
        $request->requestSerials()->create([
            'mrn' => $request->mrn,
            'serial_number' => 'SN-SELECTED',
            'product_name' => 'E10 Kilit',
            'customer_selected' => true,
            'customer_visible' => true,
            'customer_selectable' => true,
            'is_primary' => true,
            'is_returned' => false,
            'is_current_latest_sale' => true,
            'color_status' => 'green',
        ]);
        $request->requestSerials()->create([
            'mrn' => $request->mrn,
            'serial_number' => 'SN-HIDDEN',
            'product_name' => 'DDL720 Kilit',
            'customer_selected' => false,
            'customer_visible' => false,
            'customer_selectable' => false,
            'hidden_reason' => 'dealer_or_partner',
            'is_returned' => false,
            'color_status' => 'orange',
        ]);
        $request->requestSerials()->create([
            'mrn' => $request->mrn,
            'serial_number' => 'SN-RETURNED',
            'product_name' => 'DDL720 Kilit',
            'customer_selected' => false,
            'customer_visible' => false,
            'customer_selectable' => false,
            'hidden_reason' => 'returned',
            'is_returned' => true,
            'return_note' => 'İADE GELMİŞ',
            'return_date' => '2026-05-14',
            'return_document_no' => 'IAD/10',
            'color_status' => 'red',
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
            ->assertJsonPath('request.qr_source.source_channel', TechnicalServiceRequest::SOURCE_QR_MOUNT_FORM)
            ->assertJsonPath('request.product.activation_code', '275023')
            ->assertJsonPath('request.product.stock_code', 'STK-001')
            ->assertJsonPath('request.sale_and_payment.sale_mount_label', 'Montaj Hariç')
            ->assertJsonPath('request.sale_and_payment.mount_payment_label', 'Montaj ödemesi alındı')
            ->assertJsonPath('request.sale_and_payment.payment_reference', 'fake-reference')
            ->assertJsonPath('request.documents.invoice_display_no', 'FAT/123')
            ->assertJsonPath('request.operation_control.payment_checked', 'yes')
            ->assertJsonPath('request.operation_control.door_photos_checked', 'compatible')
            ->assertJsonPath('request.assignment_blockers.messages', [])
            ->assertJsonPath('request.location.shared', true)
            ->assertJsonPath('request.location.map_url', 'https://www.google.com/maps?q=40.9876543,29.1234567')
            ->assertJsonPath('request.door_photos.0.field_code', 'door_front_photo')
            ->assertJsonPath('request.door_photos.0.category', TechnicalServiceRequestUpload::CATEGORY_OPERATION_CONTROL_DOOR_PHOTO)
            ->assertJsonPath('request.door_photos.0.preview_url', route('api.technical-service.requests.uploads.show', [
                'technicalServiceRequest' => $request->id,
                'upload' => $request->uploads()->firstOrFail()->id,
            ]))
            ->assertJsonPath('request.invoice_serials.selected_serials.0.serial_number', 'SN-SELECTED')
            ->assertJsonPath('request.invoice_serials.selected_serials.0.color_status', 'green')
            ->assertJsonPath('request.invoice_serials.hidden_serials.0.serial_number', 'SN-HIDDEN')
            ->assertJsonPath('request.invoice_serials.hidden_serials.0.hidden_reason_label', 'Müşteriye gösterilmedi - sorumluluk kodu: Boş')
            ->assertJsonPath('request.invoice_serials.returned_serials.0.serial_number', 'SN-RETURNED')
            ->assertJsonPath('request.invoice_serials.returned_serials.0.color_status', 'red')
            ->assertJsonPath('request.invoice_serials.has_returned', true)
            ->assertJsonPath('request.audit_logs_unavailable', true);

        $this->actingAs($user)
            ->get(route('api.technical-service.requests.uploads.show', [
                'technicalServiceRequest' => $request->id,
                'upload' => $request->uploads()->firstOrFail()->id,
            ]))
            ->assertOk();
    }

    public function test_invoice_serial_recheck_endpoint_updates_operation_payload(): void
    {
        $user = $this->adminUser();
        $request = $this->technicalServiceRequest([
            'source_channel' => TechnicalServiceRequest::SOURCE_QR_MOUNT_FORM,
            'serial_number' => 'SN-PRIMARY',
        ]);
        $this->app->instance(
            MikroInvoiceSerialsService::class,
            new class extends MikroInvoiceSerialsService {
                public function forSerial(string $serialNo): array
                {
                    $rows = $this->normalizeRows([
                        [
                            'Faturadaki Seri No' => 'SN-PRIMARY',
                            'Stok Adı' => 'E10-AKILLI KAPI KİLİDİ-SİYAH (70LİK KİLİT)',
                            'Bu Fatura Bu Seri İçin Son Satış mı' => 'Evet',
                            'Satış Cari Grup Adı' => 'Perakende Son Müşteri',
                        ],
                        [
                            'Faturadaki Seri No' => 'SN-RETURNED',
                            'Stok Adı' => 'PHILIPS DDL720 Akıllı Kilit',
                            'İade Notu' => 'İADE GELMİŞ',
                            'İade Tarihi' => '14.05.2026',
                            'İade Evrak Seri' => 'IAD',
                            'İade Evrak Sıra' => '12',
                            'Bu Fatura Bu Seri İçin Son Satış mı' => 'Evet',
                            'Satış Cari Grup Adı' => 'Perakende Son Müşteri',
                        ],
                    ], $serialNo);

                    return [
                        'rows' => $rows,
                        'all_invoice_serials' => $rows,
                        'selectable_customer_serials' => array_values(array_filter($rows, fn (array $row): bool => (bool) $row['customer_selectable'])),
                        'returned_serials' => array_values(array_filter($rows, fn (array $row): bool => (bool) $row['is_returned'])),
                        'meta' => [],
                        'request' => [],
                    ];
                }
            },
        );

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/invoice-serials/recheck")
            ->assertOk()
            ->assertJsonPath('request.invoice_serials.selected_serials.0.serial_number', 'SN-PRIMARY')
            ->assertJsonPath('request.invoice_serials.returned_serials.0.serial_number', 'SN-RETURNED')
            ->assertJsonPath('request.invoice_serials.returned_serials.0.color_status', 'red');
    }

    public function test_invoice_serial_operation_add_remove_and_add_all_actions(): void
    {
        $user = $this->adminUser();
        $request = $this->technicalServiceRequest([
            'source_channel' => TechnicalServiceRequest::SOURCE_QR_MOUNT_FORM,
            'customer_phone' => '+905372081655',
            'serial_number' => 'SN-PRIMARY',
        ]);
        $primary = $request->requestSerials()->create([
            'mrn' => $request->mrn,
            'serial_number' => 'SN-PRIMARY',
            'product_name' => 'E10 Kilit',
            'customer_selected' => true,
            'customer_visible' => true,
            'customer_selectable' => true,
            'operation_added' => true,
            'customer_phone' => $request->customer_phone,
            'linked_mrn' => $request->mrn,
            'is_primary' => true,
            'is_returned' => false,
            'color_status' => 'green',
        ]);
        $addable = $request->requestSerials()->create([
            'mrn' => $request->mrn,
            'serial_number' => 'SN-ADDABLE',
            'product_name' => 'E10 Kilit',
            'customer_selected' => false,
            'customer_visible' => true,
            'customer_selectable' => true,
            'is_primary' => false,
            'is_returned' => false,
            'color_status' => 'orange',
        ]);
        $dealer = $request->requestSerials()->create([
            'mrn' => $request->mrn,
            'serial_number' => 'SN-DEALER',
            'product_name' => 'DDL720 Kilit',
            'customer_selected' => false,
            'customer_visible' => false,
            'customer_selectable' => false,
            'hidden_reason' => 'dealer_or_partner',
            'invoice_customer_type' => TechnicalServiceRequestSerial::CUSTOMER_DEALER_OR_PARTNER,
            'is_primary' => false,
            'is_returned' => false,
            'color_status' => 'orange',
            'source_payload' => [
                'responsibility_code' => 'BAYİ SATIŞ',
                'normalized_responsibility_code' => 'BAYI SATIS',
                'is_responsibility_blocked' => true,
            ],
        ]);
        $project = $request->requestSerials()->create([
            'mrn' => $request->mrn,
            'serial_number' => 'SN-PROJE',
            'product_name' => 'E20 Kilit',
            'customer_selected' => false,
            'customer_visible' => false,
            'customer_selectable' => false,
            'hidden_reason' => 'responsibility_code_blocked',
            'is_primary' => false,
            'is_returned' => false,
            'color_status' => 'orange',
            'source_payload' => [
                'responsibility_code' => 'PROJE',
                'normalized_responsibility_code' => 'PROJE',
                'is_responsibility_blocked' => true,
            ],
        ]);
        $gr = $request->requestSerials()->create([
            'mrn' => $request->mrn,
            'serial_number' => 'SN-GR',
            'product_name' => 'E20 Kilit',
            'customer_selected' => false,
            'customer_visible' => false,
            'customer_selectable' => false,
            'hidden_reason' => 'responsibility_code_blocked',
            'is_primary' => false,
            'is_returned' => false,
            'color_status' => 'orange',
            'source_payload' => [
                'responsibility_code' => 'GR',
                'normalized_responsibility_code' => 'GR',
                'is_responsibility_blocked' => true,
            ],
        ]);
        $emptyResponsibility = $request->requestSerials()->create([
            'mrn' => $request->mrn,
            'serial_number' => 'SN-EMPTY-RESP',
            'product_name' => 'E20 Kilit',
            'customer_selected' => false,
            'customer_visible' => false,
            'customer_selectable' => false,
            'hidden_reason' => 'responsibility_code_blocked',
            'is_primary' => false,
            'is_returned' => false,
            'color_status' => 'orange',
            'source_payload' => [
                'responsibility_code' => null,
                'normalized_responsibility_code' => null,
                'is_responsibility_blocked' => true,
            ],
        ]);
        $returned = $request->requestSerials()->create([
            'mrn' => $request->mrn,
            'serial_number' => 'SN-RETURNED',
            'product_name' => 'DDL720 Kilit',
            'customer_selected' => false,
            'customer_visible' => false,
            'customer_selectable' => false,
            'hidden_reason' => 'returned',
            'is_primary' => false,
            'is_returned' => true,
            'color_status' => 'red',
        ]);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/invoice-serials/{$addable->id}/add")
            ->assertOk()
            ->assertJsonPath('request.invoice_serials.added_serial_count', 1)
            ->assertJsonPath('request.invoice_serials.addable_serial_count', 4);

        $addable->refresh();
        $this->assertTrue($addable->operation_added);
        $this->assertSame('green', $addable->color_status);
        $this->assertSame($request->mrn, $addable->linked_mrn);
        $this->assertSame($request->customer_phone, $addable->customer_phone);

        $this->actingAs($user)
            ->deleteJson("/api/technical-service/requests/{$request->id}/invoice-serials/{$addable->id}/remove")
            ->assertOk()
            ->assertJsonPath('request.invoice_serials.added_serial_count', 0)
            ->assertJsonPath('request.invoice_serials.addable_serial_count', 5);

        $this->assertFalse($addable->refresh()->operation_added);
        $this->assertSame('Operasyon tarafından çıkarıldı', $addable->operation_note);

        $this->actingAs($user)
            ->deleteJson("/api/technical-service/requests/{$request->id}/invoice-serials/{$primary->id}/remove")
            ->assertUnprocessable();

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/invoice-serials/{$returned->id}/add")
            ->assertUnprocessable();

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/invoice-serials/{$dealer->id}/add")
            ->assertOk();

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/invoice-serials/{$project->id}/add")
            ->assertOk();

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/invoice-serials/{$gr->id}/add")
            ->assertOk();

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/invoice-serials/{$emptyResponsibility->id}/add")
            ->assertOk();

        $bulkDealer = $request->requestSerials()->create([
            'mrn' => $request->mrn,
            'serial_number' => 'SN-BULK-DEALER',
            'product_name' => 'DDL720 Kilit',
            'customer_selected' => false,
            'customer_visible' => false,
            'customer_selectable' => false,
            'hidden_reason' => 'responsibility_code_blocked',
            'is_primary' => false,
            'is_returned' => false,
            'color_status' => 'orange',
            'source_payload' => [
                'responsibility_code' => 'BAYİ SATIŞ',
                'normalized_responsibility_code' => 'BAYI SATIS',
                'is_responsibility_blocked' => true,
            ],
        ]);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/invoice-serials/add-all")
            ->assertOk()
            ->assertJsonPath('request.invoice_serials.added_serial_count', 6)
            ->assertJsonPath('request.invoice_serials.addable_serial_count', 0)
            ->assertJsonPath('request.invoice_serials.returned_serial_count', 1);

        $this->assertTrue($addable->refresh()->operation_added);
        $this->assertTrue($dealer->refresh()->operation_added);
        $this->assertTrue($project->refresh()->operation_added);
        $this->assertTrue($gr->refresh()->operation_added);
        $this->assertTrue($emptyResponsibility->refresh()->operation_added);
        $this->assertTrue($bulkDealer->refresh()->operation_added);
        $this->assertFalse($returned->refresh()->operation_added);
    }

    public function test_frontend_contains_qr_operation_control_and_assignment_guard_labels(): void
    {
        $detailsSource = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx'));
        $cardSource = file_get_contents(resource_path('js/components/technical-service/TechnicalServiceKanbanCard.tsx'));
        $panelSource = file_get_contents(resource_path('js/pages/panel/technical-service.tsx'));

        $this->assertIsString($detailsSource);
        $this->assertIsString($cardSource);
        $this->assertIsString($panelSource);

        foreach ([
            'Operasyon ve Montaj Kontrolü',
            'Ödeme / Montaj Bloğu',
            'Adres Kontrol Bloğu',
            'Randevu Kontrol Bloğu',
            'Ek Operasyon Kontrolleri',
            'Ödeme kontrol edildi mi?',
            'Kapı görselleri bakıldı mı?',
            'Randevu tarihi güncellenecek mi?',
            'Usta atama engelleri',
            'Henüz kapı fotoğrafı yüklenmedi',
            'Haritada aç',
            'Konum paylaşıldı',
            'Kapı Ön Yüzü',
            'Satış montaj durumu',
            'Montaj ödeme durumu',
            'Ödeme referansı',
            'Faturadaki diğer serileri gör',
            'Talep edilen seriler',
            'Aynı faturadaki diğer seriler',
            'Müşteriye gösterilmeyen seriler',
            'İade gelen seriler',
            'Müşteriye gösterilmedi',
            'Tekrar kontrol et',
            'Tüm uygun serileri montaja ekle',
            'Montaja ekle',
            'Çıkar',
            'İade - eklenemez',
            'Müşteriye gösterilmedi - sorumluluk kodu',
            'Ana seri - çıkarılamaz',
            'Son satış durumu',
            'Bu faturadaki güncel satış',
            'Bu fatura son satış değil',
            'Son satış kontrolü doğrulanamadı',
            'Son satış kontrolü çelişkili',
            'Usta adresi var, koordinat eksik',
            'Usta adres bilgisi eksik',
            'Usta koordinat',
            'Usta koordinatı eksik olduğu için Google Routes hesaplanamadı',
            'assignmentSubmitDisabled',
            'routeFeeEditorMessage',
            'Servis onay durumu',
            'Kabul / red',
            'Hakedi',
            'Maliyet',
            'Farkl',
            'Seçili seriler için farkl',
            'Önce usta seçin',
            'warning_labels',
            'serial.warning_labels?.length',
            'border-amber-300 bg-amber-100',
            'invoiceSerialsOpen',
            'fieldCompletionOpen',
            'preview_url ?? photo.download_url ?? photo.url',
            'Görüntü açılamadı',
        ] as $expectedText) {
            $this->assertStringContainsString($expectedText, $detailsSource);
        }

        $this->assertStringNotContainsString('Montaj / Servis Durumu', $detailsSource);
        $this->assertStringNotContainsString('Operasyon Kontrolü', $detailsSource);
        $this->assertStringNotContainsString('Bayi/Proje - otomatik eklenemez', $detailsSource);
        $this->assertStringNotContainsString('Müşteri tercihi', $detailsSource);

        foreach ([
            'visibleTechnicianAssignmentInsights',
            'submittedTechnicianOption',
            'loadRequests({ silent: true, preserveSelection: true })',
        ] as $expectedText) {
            $this->assertStringContainsString($expectedText, $panelSource);
        }

        foreach ([
            'QR Montaj Formu',
            'Montaj ödemesi alındı',
            'Çoklu ürün talebi',
            'Montaja eklenen',
            'Eklenebilir seri',
            'İade seri var',
            'border-orange-300 bg-orange-100 text-orange-950',
            'border-rose-300 bg-rose-100 text-rose-950',
            'border-emerald-200 bg-emerald-100 text-emerald-900',
            'border-blue-200 bg-blue-100 text-blue-900',
            'BadgeIconMark',
            'important: true',
        ] as $expectedText) {
            $this->assertStringContainsString($expectedText, $cardSource);
        }
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
