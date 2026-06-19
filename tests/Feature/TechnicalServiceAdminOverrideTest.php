<?php

namespace Tests\Feature;

use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerCapability;
use App\Models\B2B\B2BPartnerTechnician;
use App\Models\B2B\B2BPartnerUserAccess;
use App\Models\B2B\B2BPartnerUserProfile;
use App\Models\Role;
use App\Models\RoleResourcePermission;
use App\Models\TechnicalServiceAdminOverride;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use App\Services\TechnicalService\TechnicalServiceUiLabelService;
use Database\Seeders\B2BPartnerPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TechnicalServiceAdminOverrideTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_override_field_override_direct_apply_records_old_new_reason_and_turkish_event_labels(): void
    {
        $admin = $this->adminUser();
        $request = $this->technicalServiceRequest([
            'customer_phone' => '5550000000',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$request->id}/overrides", [
                'field_key' => 'customer_phone',
                'new_value' => '5551112233',
                'reason' => 'Müşteri telefonu yanlış girilmiş.',
            ])
            ->assertOk()
            ->assertJsonPath('status', TechnicalServiceAdminOverride::STATUS_APPLIED)
            ->assertJsonPath('override.field_label', 'Müşteri telefonu')
            ->assertJsonPath('override.old_value.display', '5550000000')
            ->assertJsonPath('override.new_value.display', '5551112233')
            ->assertJsonPath('request.customer_phone', '5551112233')
            ->assertJsonPath('request.admin_overrides.0.status_label', 'Uygulandı');

        $this->assertSame('5551112233', $request->fresh()->customer_phone);
        $this->assertDatabaseHas('technical_service_admin_overrides', [
            'request_id' => $request->id,
            'field_key' => 'customer_phone',
            'status' => TechnicalServiceAdminOverride::STATUS_APPLIED,
        ]);
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $request->id,
            'event_type' => 'field_override_applied',
            'title' => 'Düzeltme uygulandı',
        ]);
        $this->assertDatabaseHas('technical_service_audit_logs', [
            'entity_type' => 'technical_service_request',
            'entity_id' => $request->id,
            'action_type' => 'field_override_applied',
        ]);
    }

    public function test_override_approval_sensitive_serial_override_requires_approval_before_apply(): void
    {
        $admin = $this->adminUser();
        $request = $this->technicalServiceRequest([
            'serial_number' => 'OLD-SERIAL',
        ]);

        $overrideId = $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$request->id}/overrides", [
                'field_key' => 'serial_no',
                'new_value' => 'NEW-SERIAL',
                'reason' => 'Seri etiketi okunarak düzeltildi.',
            ])
            ->assertOk()
            ->assertJsonPath('status', TechnicalServiceAdminOverride::STATUS_PENDING)
            ->assertJsonPath('request.serial_number', 'OLD-SERIAL')
            ->json('override.id');

        $this->assertSame('OLD-SERIAL', $request->fresh()->serial_number);

        $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$request->id}/overrides/{$overrideId}/approve", [
                'note' => 'Etiket fotoğrafı ile doğrulandı.',
            ])
            ->assertOk()
            ->assertJsonPath('status', TechnicalServiceAdminOverride::STATUS_APPLIED)
            ->assertJsonPath('request.serial_number', 'NEW-SERIAL');

        $this->assertSame('NEW-SERIAL', $request->fresh()->serial_number);
    }

    public function test_blocked_mrn_override_is_rejected_without_mutating_request(): void
    {
        $admin = $this->adminUser();
        $request = $this->technicalServiceRequest([
            'mrn' => 'MRN-KEEP-001',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$request->id}/overrides", [
                'field_key' => 'mrn',
                'new_value' => 'MRN-BAD-999',
                'reason' => 'MRN değiştirme denemesi.',
            ])
            ->assertStatus(422);

        $this->assertSame('MRN-KEEP-001', $request->fresh()->mrn);
        $this->assertDatabaseMissing('technical_service_admin_overrides', [
            'request_id' => $request->id,
            'field_key' => 'mrn',
        ]);
    }

    public function test_partner_correction_request_creates_pending_ledger_without_business_mutation(): void
    {
        [$partnerUser, $partner, $technician] = $this->partnerPortalFixture();
        $request = $this->technicalServiceRequest([
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
            'status' => 'Randevulu',
            'workflow_status' => 'Planlı',
            'service_address' => 'Eski adres',
            'scheduled_at' => now()->addDay(),
            'scheduled_date' => now()->addDay()->toDateString(),
            'scheduled_time' => '10:00',
        ]);

        $this->actingAs($partnerUser)
            ->postJson("/api/partner/service-jobs/{$request->id}/correction-request", [
                'field_key' => 'customer_address',
                'new_value' => 'Yeni adres',
                'reason' => 'Müşteri adresi kapı numarasıyla güncelledi.',
            ])
            ->assertOk()
            ->assertJsonPath('status', TechnicalServiceAdminOverride::STATUS_PENDING)
            ->assertJsonPath('override.source', TechnicalServiceAdminOverride::SOURCE_PARTNER_REQUEST)
            ->assertJsonPath('job.correction_requests.0.field_label', 'Servis adresi');

        $this->assertSame('Eski adres', $request->fresh()->service_address);
        $this->assertDatabaseHas('technical_service_admin_overrides', [
            'request_id' => $request->id,
            'field_key' => 'customer_address',
            'source' => TechnicalServiceAdminOverride::SOURCE_PARTNER_REQUEST,
            'status' => TechnicalServiceAdminOverride::STATUS_PENDING,
        ]);
        $this->assertTrue($partner->fresh()->hasCapability(B2BPartner::TYPE_LOCKSMITH));
    }

    public function test_history_labels_are_turkish_and_not_generic_raw_islem_kaydi(): void
    {
        $this->assertSame('Düzeltme talebi oluşturuldu', TechnicalServiceUiLabelService::actionLabel('field_override_requested'));
        $this->assertSame('Düzeltme uygulandı', TechnicalServiceUiLabelService::actionLabel('field_override_applied'));
        $this->assertSame('Operasyon kaydı', TechnicalServiceUiLabelService::actionLabel('unknown_raw_enum'));
    }

    public function test_recompute_flags_route_override_and_earning_override_are_returned(): void
    {
        $admin = $this->adminUser();
        $request = $this->technicalServiceRequest([
            'service_address' => 'Eski adres',
            'travel_fee_amount' => 1000,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$request->id}/overrides", [
                'field_key' => 'customer_address',
                'new_value' => 'Yeni adres',
                'reason' => 'Adres değişikliği rota kontrolü gerektiriyor.',
            ])
            ->assertOk()
            ->assertJsonPath('override.recompute_flags.0', 'route_quote')
            ->assertJsonPath('request.service_address', 'Yeni adres');

        $response = $this->actingAs($admin)
            ->postJson("/api/technical-service/requests/{$request->id}/overrides", [
                'field_key' => 'route_earning',
                'new_value' => 1250,
                'reason' => 'Yol hakedişi operasyon tarafından düzeltildi.',
            ])
            ->assertOk()
            ->assertJsonPath('override.recompute_flags.0', 'earning');

        $this->assertSame(1250.0, (float) $response->json('request.travel_fee_amount'));
    }

    public function test_disabled_action_reason_copy_and_admin_override_controls_exist_in_ops_detail_source(): void
    {
        $source = file_get_contents(resource_path('js/components/technical-service/ServiceRequestDetails.tsx')) ?: '';

        $this->assertStringContainsString('Düzeltme / Denetim', $source);
        $this->assertStringContainsString('Bu işte bekleyen düzeltme talepleri var.', $source);
        $this->assertStringContainsString('disabledTitle', $source);
        $this->assertStringContainsString('Alanı düzelt', $source);
    }

    public function test_override_schema_contract_uses_canonical_columns_without_old_aliases(): void
    {
        $columns = Schema::getColumnListing('technical_service_admin_overrides');

        foreach ([
            'request_id',
            'root_request_id',
            'request_code',
            'root_mrn',
            'field_key',
            'field_label',
            'field_group',
            'old_value',
            'new_value',
            'requested_value',
            'status',
            'source',
            'reason',
            'requested_by',
            'approved_by',
            'applied_by',
            'rejected_by',
            'requested_at',
            'approved_at',
            'applied_at',
            'rejected_at',
            'rejection_reason',
            'recompute_flags',
            'metadata',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertContains($column, $columns);
        }

        foreach ([
            'technical_service_request_id',
            'requested_by_user_id',
            'requested_by_name',
            'approved_by_user_id',
            'approved_by_name',
            'applied_by_user_id',
            'applied_by_name',
            'rejected_by_user_id',
            'rejected_by_name',
            'review_note',
        ] as $oldAlias) {
            $this->assertNotContains($oldAlias, $columns);
        }
    }

    private function adminUser(): User
    {
        Role::query()->updateOrCreate(
            ['code' => 'admin'],
            ['name' => 'Admin', 'is_super_admin' => true]
        );

        return User::factory()->create(['role_code' => 'admin']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function technicalServiceRequest(array $overrides = []): TechnicalServiceRequest
    {
        return TechnicalServiceRequest::query()->create(array_merge([
            'mrn' => 'MRN-TS6-'.uniqid(),
            'customer_name' => 'TS6 Müşteri',
            'customer_phone' => '5550000000',
            'customer_city' => 'Adana',
            'customer_district' => 'Seyhan',
            'service_address' => 'TS6 adres',
            'product_name' => 'Test Ürün',
            'product_model' => 'Model A',
            'serial_number' => 'SN-TS6-'.uniqid(),
            'service_type' => 'Montaj',
            'status' => 'Yeni',
            'workflow_status' => 'Yeni Talep',
            'priority' => 'Orta',
            'risk_level' => 'Orta',
            'source_channel' => 'panel',
        ], $overrides));
    }

    /**
     * @return array{0: User, 1: B2BPartner, 2: TechnicalServiceTechnician}
     */
    private function partnerPortalFixture(): array
    {
        (new B2BPartnerPermissionSeeder)->run();

        Role::query()->updateOrCreate(
            ['code' => 'b2b_locksmith'],
            ['name' => 'Çilingir', 'is_super_admin' => false]
        );
        RoleResourcePermission::query()->updateOrCreate(
            ['role_code' => 'b2b_locksmith', 'resource_code' => 'partner.service_jobs.view'],
            ['can_view' => true, 'can_execute' => true]
        );

        $user = User::factory()->create(['role_code' => 'b2b_locksmith']);
        $partner = B2BPartner::query()->create([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'partner_code' => 'TS6-PARTNER-'.uniqid(),
            'display_name' => 'TS6 Çilingir',
            'active' => true,
        ]);
        B2BPartnerCapability::query()->create([
            'partner_id' => $partner->id,
            'capability' => B2BPartner::TYPE_LOCKSMITH,
            'active' => true,
        ]);
        B2BPartnerUserProfile::query()->create([
            'user_id' => $user->id,
            'partner_id' => $partner->id,
            'title' => 'Portal kullanıcısı',
            'active' => true,
        ]);
        B2BPartnerUserAccess::query()->create([
            'user_id' => $user->id,
            'partner_id' => $partner->id,
            'access_scope' => 'view',
            'can_view' => true,
            'can_create' => false,
            'can_update' => false,
            'can_approve' => false,
        ]);

        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'TS6 Usta',
            'phone' => '+905551112233',
            'city' => 'Adana',
            'district' => 'Seyhan',
            'is_active' => true,
        ]);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'field_technician',
            'is_primary' => true,
            'active' => true,
        ]);

        return [$user, $partner, $technician];
    }
}
