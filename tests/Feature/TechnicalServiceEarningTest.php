<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceEarning;
use App\Models\TechnicalServiceEarningsPeriod;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use App\Services\TechnicalService\TechnicalServiceEarningService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TechnicalServiceEarningTest extends TestCase
{
    use RefreshDatabase;

    public function test_calculate_period_includes_only_completed_requests_by_installation_date(): void
    {
        CarbonImmutable::setTestNow('2026-05-10 12:00:00');
        $technician = $this->technician(['name' => 'Usta A', 'city' => 'Adana']);
        $included = $this->request([
            'mrn' => 'MRN-INCLUDED',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
            'status' => 'Tamamlandı',
            'completed_at' => '2026-04-30 10:00:00',
            'installation_completed_at' => '2026-05-02 10:00:00',
            'technician_payment_amount' => 3000,
            'travel_fee_amount' => 120,
            'travel_round_trip_km' => 42,
            'travel_billable_km' => 12,
        ]);
        $this->request([
            'mrn' => 'MRN-NOT-COMPLETED',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
            'status' => 'Randevulu',
            'completed_at' => null,
            'installation_completed_at' => '2026-05-03 10:00:00',
        ]);
        $this->request([
            'mrn' => 'MRN-OTHER-MONTH',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
            'status' => 'Tamamlandı',
            'completed_at' => '2026-05-01 10:00:00',
            'installation_completed_at' => '2026-04-29 10:00:00',
        ]);

        $period = app(TechnicalServiceEarningService::class)->calculatePeriod(2026, 5);

        $this->assertSame(1, $period->earnings()->count());
        $earning = $period->earnings()->firstOrFail();
        $this->assertSame(1, $earning->items()->count());
        $this->assertDatabaseHas('technical_service_earning_items', [
            'technical_service_request_id' => $included->id,
            'mrn' => 'MRN-INCLUDED',
            'labor_amount' => '3000.00',
            'travel_fee_amount' => '120.00',
            'line_total' => '3120.00',
        ]);
        $this->assertSame('3120.00', $earning->fresh()->grand_total);
    }

    public function test_completed_at_is_used_as_fallback_and_empty_labor_amount_adds_note(): void
    {
        $technician = $this->technician(['name' => 'Usta B']);
        $this->request([
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
            'status' => 'Tamamlandı',
            'completed_at' => '2026-05-04 11:00:00',
            'installation_completed_at' => null,
            'technician_payment_amount' => null,
            'travel_fee_amount' => 75,
        ]);

        $period = app(TechnicalServiceEarningService::class)->calculatePeriod(2026, 5);
        $item = $period->earnings()->firstOrFail()->items()->firstOrFail();

        $this->assertSame('2026-05-04', $item->job_date->toDateString());
        $this->assertSame('0.00', $item->labor_amount);
        $this->assertSame('75.00', $item->line_total);
        $this->assertSame('usta hizmet bedeli boş', $item->note);
    }

    public function test_recalculate_draft_period_does_not_duplicate_items(): void
    {
        $this->request([
            'status' => 'Tamamlandı',
            'completed_at' => '2026-05-04 11:00:00',
            'installation_completed_at' => null,
            'technician_payment_amount' => 3000,
        ]);

        $service = app(TechnicalServiceEarningService::class);
        $service->calculatePeriod(2026, 5);
        $service->calculatePeriod(2026, 5);

        $this->assertSame(1, TechnicalServiceEarning::query()->count());
        $this->assertDatabaseCount('technical_service_earning_items', 1);
    }

    public function test_paid_or_locked_period_cannot_be_recalculated(): void
    {
        TechnicalServiceEarningsPeriod::query()->create([
            'year' => 2026,
            'month' => 5,
            'status' => 'paid',
        ]);

        $this->expectException(ValidationException::class);

        app(TechnicalServiceEarningService::class)->calculatePeriod(2026, 5);
    }

    public function test_period_with_paid_earning_cannot_be_recalculated(): void
    {
        $this->request([
            'status' => 'Tamamlandı',
            'completed_at' => '2026-05-04 11:00:00',
            'installation_completed_at' => null,
            'technician_payment_amount' => 3000,
        ]);
        $service = app(TechnicalServiceEarningService::class);
        $period = $service->calculatePeriod(2026, 5);
        $earning = $period->earnings()->firstOrFail();
        $earning->update(['status' => 'Ödendi', 'paid_at' => now()]);

        $this->expectException(ValidationException::class);

        $service->calculatePeriod(2026, 5);
    }

    public function test_mark_paid_and_whatsapp_endpoint_work(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);
        $technician = $this->technician(['name' => 'Usta WhatsApp']);
        $this->request([
            'mrn' => 'MRN-WA',
            'technical_service_technician_id' => $technician->id,
            'technician_name' => $technician->name,
            'status' => 'Tamamlandı',
            'completed_at' => '2026-05-04 11:00:00',
            'installation_completed_at' => '2026-05-04 11:00:00',
            'technician_payment_amount' => 3000,
            'travel_fee_amount' => 100,
        ]);
        $period = app(TechnicalServiceEarningService::class)->calculatePeriod(2026, 5);
        $earning = $period->earnings()->firstOrFail();

        $this->actingAs($user)
            ->postJson("/api/technical-service/earnings/{$earning->id}/mark-paid")
            ->assertOk()
            ->assertJsonPath('earning.status', 'Ödendi');

        $this->assertSame('paid', $period->fresh()->status);

        $this->actingAs($user)
            ->getJson("/api/technical-service/earnings/{$earning->id}/whatsapp-text")
            ->assertOk()
            ->assertJsonPath('text', fn (string $text) => str_contains($text, 'Merhaba Usta WhatsApp,')
                && str_contains($text, 'MRN-WA')
                && str_contains($text, 'Toplam hakediş: 3.100,00 TL'));
    }

    public function test_list_endpoint_returns_summary(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);
        $this->request([
            'status' => 'Tamamlandı',
            'completed_at' => '2026-05-04 11:00:00',
            'installation_completed_at' => null,
            'technician_payment_amount' => 3000,
            'travel_fee_amount' => 100,
        ]);
        app(TechnicalServiceEarningService::class)->calculatePeriod(2026, 5);

        $this->actingAs($user)
            ->getJson('/api/technical-service/earnings?year=2026&month=5')
            ->assertOk()
            ->assertJsonPath('summary.job_count', 1)
            ->assertJsonPath('summary.grand_total', 3100);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function technician(array $overrides = []): TechnicalServiceTechnician
    {
        return TechnicalServiceTechnician::query()->create(array_merge([
            'name' => 'Test Usta',
            'first_name' => 'Test',
            'last_name' => 'Usta',
            'phone' => '905300000000',
            'city' => 'Adana',
            'active' => true,
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function request(array $overrides = []): TechnicalServiceRequest
    {
        return TechnicalServiceRequest::query()->create(array_merge([
            'mrn' => 'MRN-TEST-'.uniqid(),
            'customer_name' => 'Test Müşteri',
            'customer_phone' => '905300000001',
            'customer_city' => 'Adana',
            'customer_district' => 'Seyhan',
            'service_address' => 'Test adres',
            'product_name' => 'Test Ürün',
            'product_model' => 'M1',
            'serial_number' => 'SN-TEST',
            'service_type' => 'Montaj',
            'status' => 'Tamamlandı',
            'priority' => 'Orta',
            'risk_level' => 'Orta',
            'completed_at' => '2026-05-02 10:00:00',
        ], $overrides));
    }
}
