<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\TechnicalServiceRequest;
use App\Models\WarrantyCard;
use App\Services\TechnicalService\MikroSerialNumberService;
use App\Services\TechnicalService\WarrantyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechnicalServiceWarrantyTest extends TestCase
{
    use RefreshDatabase;

    public function test_serial_without_latest_sale_returns_controlled_empty_warranty_result(): void
    {
        $this->mockLatestSale(null);

        $result = app(WarrantyService::class)->statusForSerial('SN-NOT-SOLD');

        $this->assertSame('SN-NOT-SOLD', $result['serial_no']);
        $this->assertSame('Garanti Başlamadı', $result['status']);
        $this->assertNull($result['card']);
        $this->assertNull($result['last_sale']);
        $this->assertContains('Mikro’da son geçerli satış bulunamadı.', $result['warnings']);
    }

    public function test_serial_with_latest_sale_and_no_installation_has_not_started_status(): void
    {
        $this->mockLatestSale($this->salePayload('SN-1', 'fp-1'));

        $result = app(WarrantyService::class)->statusForSerial('SN-1');

        $this->assertSame('Garanti Başlamadı', $result['status']);
        $this->assertSame(24, $result['warranty_period_months']);
        $this->assertNull($result['warranty_started_at']);
        $this->assertNull($result['warranty_ends_at']);
        $this->assertSame('fp-1', $result['last_sale']['fingerprint']);
        $this->assertDatabaseHas('warranty_cards', [
            'serial_no' => 'SN-1',
            'last_sale_mikro_fingerprint' => 'fp-1',
            'status' => 'Garanti Başlamadı',
        ]);
    }

    public function test_installation_completed_date_makes_warranty_active(): void
    {
        $this->mockLatestSale($this->salePayload('SN-2', 'fp-2'));
        $service = app(WarrantyService::class);
        $initial = $service->statusForSerial('SN-2');

        $card = \App\Models\WarrantyCard::query()->findOrFail($initial['card']['id']);
        $card->installation_completed_at = '2026-05-01';
        $card->save();

        $result = $service->statusForSerial('SN-2');

        $this->assertSame('Garanti Aktif', $result['status']);
        $this->assertSame('2026-05-01', $result['warranty_started_at']);
        $this->assertSame('2028-05-01', $result['warranty_ends_at']);
        $this->assertIsInt($result['remaining_days']);
    }

    public function test_expired_installation_date_makes_warranty_expired(): void
    {
        $this->mockLatestSale($this->salePayload('SN-3', 'fp-3'));
        $service = app(WarrantyService::class);
        $service->statusForSerial('SN-3');

        \App\Models\WarrantyCard::query()
            ->where('serial_no', 'SN-3')
            ->update(['installation_completed_at' => '2020-01-01']);

        $result = $service->statusForSerial('SN-3');

        $this->assertSame('Garanti Bitti', $result['status']);
        $this->assertSame('2022-01-01', $result['warranty_ends_at']);
        $this->assertSame(0, $result['remaining_days']);
    }

    public function test_completed_installation_request_starts_warranty(): void
    {
        $this->mockLatestSale($this->salePayload('SN-INSTALL', 'install-fp', '2026-03-01'));
        $request = $this->technicalServiceRequest([
            'mrn' => 'MRN-202605ADA03-001',
            'serial_number' => 'SN-INSTALL',
            'service_type' => 'Montaj',
            'status' => 'Tamamlandı',
            'completed_at' => '2026-05-05 10:00:00',
            'installation_completed_at' => '2026-05-02 10:00:00',
        ]);

        $result = app(WarrantyService::class)->statusForSerial('SN-INSTALL');

        $this->assertSame('Garanti Aktif', $result['status']);
        $this->assertSame('2026-05-02', $result['installation']['completed_at']);
        $this->assertSame('2026-05-02', $result['warranty_started_at']);
        $this->assertSame('2028-05-02', $result['warranty_ends_at']);
        $this->assertSame('panel_completed_installation', $result['source']);
        $this->assertDatabaseHas('warranty_events', [
            'event_type' => 'warranty_started_from_completed_installation',
            'title' => 'Garanti montaj tamamlanma tarihiyle başlatıldı',
        ]);

        $event = WarrantyCard::query()->where('serial_no', 'SN-INSTALL')->firstOrFail()->events()->firstOrFail();
        $this->assertSame($request->mrn, $event->metadata['mrn']);
        $this->assertSame('2026-05-05', $event->metadata['technical_service_completed_at']);
    }

    public function test_old_completed_installation_record_without_actual_date_uses_completed_at_with_warning(): void
    {
        $this->mockLatestSale($this->salePayload('SN-OLD-FALLBACK', 'old-fallback-fp', '2026-03-01'));
        $this->technicalServiceRequest([
            'serial_number' => 'SN-OLD-FALLBACK',
            'service_type' => 'Montaj',
            'status' => 'Tamamlandı',
            'completed_at' => '2026-05-02 10:00:00',
            'installation_completed_at' => null,
        ]);

        $result = app(WarrantyService::class)->statusForSerial('SN-OLD-FALLBACK');

        $this->assertSame('Garanti Aktif', $result['status']);
        $this->assertSame('2026-05-02', $result['warranty_started_at']);
        $this->assertContains('Fiili montaj tarihi bulunamadı; eski kayıt için kapanış tarihi kullanıldı.', $result['warnings']);
    }

    public function test_incomplete_installation_request_does_not_start_warranty(): void
    {
        $this->mockLatestSale($this->salePayload('SN-INCOMPLETE', 'incomplete-fp', '2026-03-01'));
        $this->technicalServiceRequest([
            'serial_number' => 'SN-INCOMPLETE',
            'service_type' => 'Montaj',
            'status' => 'Randevulu',
            'completed_at' => null,
        ]);

        $result = app(WarrantyService::class)->statusForSerial('SN-INCOMPLETE');

        $this->assertSame('Garanti Başlamadı', $result['status']);
        $this->assertNull($result['installation']['completed_at']);
    }

    public function test_completed_installation_for_different_serial_does_not_start_warranty(): void
    {
        $this->mockLatestSale($this->salePayload('SN-TARGET', 'target-fp', '2026-03-01'));
        $this->technicalServiceRequest([
            'serial_number' => 'SN-OTHER',
            'service_type' => 'Montaj',
            'status' => 'Tamamlandı',
            'completed_at' => '2026-05-02 10:00:00',
        ]);

        $result = app(WarrantyService::class)->statusForSerial('SN-TARGET');

        $this->assertSame('Garanti Başlamadı', $result['status']);
        $this->assertNull($result['installation']['completed_at']);
    }

    public function test_installation_before_latest_sale_does_not_start_new_warranty_card(): void
    {
        $this->mockLatestSale($this->salePayload('SN-RESOLD-INSTALL', 'new-fp', '2026-05-01'));
        $this->technicalServiceRequest([
            'serial_number' => 'SN-RESOLD-INSTALL',
            'service_type' => 'Montaj',
            'status' => 'Tamamlandı',
            'completed_at' => '2026-04-15 10:00:00',
        ]);

        $result = app(WarrantyService::class)->statusForSerial('SN-RESOLD-INSTALL');

        $this->assertSame('Garanti Başlamadı', $result['status']);
        $this->assertNull($result['installation']['completed_at']);
    }

    public function test_completed_installation_start_event_is_not_duplicated(): void
    {
        $this->mockLatestSale($this->salePayload('SN-NO-DUP', 'no-dup-fp', '2026-03-01'));
        $this->technicalServiceRequest([
            'serial_number' => 'SN-NO-DUP',
            'service_type' => 'Montaj',
            'status' => 'Tamamlandı',
            'completed_at' => '2026-05-02 10:00:00',
        ]);

        $service = app(WarrantyService::class);
        $service->statusForSerial('SN-NO-DUP');
        $service->statusForSerial('SN-NO-DUP');

        $card = WarrantyCard::query()->where('serial_no', 'SN-NO-DUP')->firstOrFail();
        $this->assertSame(1, $card->events()->where('event_type', 'warranty_started_from_completed_installation')->count());
    }

    public function test_reopening_completed_installation_preserves_completed_at_and_active_warranty(): void
    {
        $this->mockLatestSale($this->salePayload('SN-REOPEN', 'reopen-fp', '2026-03-01'));
        $request = $this->technicalServiceRequest([
            'serial_number' => 'SN-REOPEN',
            'service_type' => 'Montaj',
            'status' => 'Tamamlandı',
            'completed_at' => '2026-05-05 10:00:00',
            'installation_completed_at' => '2026-05-02 10:00:00',
        ]);
        $user = User::factory()->create(['role_code' => 'admin']);
        $service = app(WarrantyService::class);

        $started = $service->statusForSerial('SN-REOPEN');
        $this->assertSame('Garanti Aktif', $started['status']);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/status", [
                'status' => 'Yeni',
                'reopen_reason' => 'Operasyon düzeltmesi',
                'reopen_note' => 'Kontrol için yeniden açıldı.',
            ])
            ->assertOk()
            ->assertJsonPath('request.status', 'Yeni')
            ->assertJsonPath('request.reopen_reason', 'Operasyon düzeltmesi')
            ->assertJsonPath('request.reopen_count', 1);

        $request->refresh();
        $this->assertNotNull($request->completed_at);
        $this->assertSame('2026-05-05', $request->completed_at->toDateString());
        $this->assertNotNull($request->installation_completed_at);
        $this->assertSame('2026-05-02', $request->installation_completed_at->toDateString());

        $afterReopen = $service->statusForSerial('SN-REOPEN');
        $this->assertSame('Garanti Aktif', $afterReopen['status']);
        $this->assertSame('2026-05-02', $afterReopen['installation']['completed_at']);
        $this->assertSame('2026-05-02', $afterReopen['warranty_started_at']);
        $this->assertContains(
            'Montaj daha önce tamamlandığı için garanti başlangıcı korunuyor; talep sonradan yeniden açılmış.',
            $afterReopen['warnings'],
        );

        $card = WarrantyCard::query()->where('serial_no', 'SN-REOPEN')->firstOrFail();
        $this->assertSame(1, $card->events()->where('event_type', 'warranty_started_from_completed_installation')->count());
        $this->assertDatabaseHas('technical_service_request_events', [
            'technical_service_request_id' => $request->id,
            'event_type' => 'technical_service_request_reopened',
            'from_status' => 'Tamamlandı',
            'to_status' => 'Yeni',
        ]);
    }

    public function test_reopen_requires_reason_for_completed_request(): void
    {
        $request = $this->technicalServiceRequest([
            'serial_number' => 'SN-VALIDATION',
            'status' => 'Tamamlandı',
            'completed_at' => '2026-05-02 10:00:00',
        ]);
        $user = User::factory()->create(['role_code' => 'admin']);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/status", [
                'status' => 'Yeni',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reopen_reason');
    }

    public function test_reopen_other_reason_requires_note(): void
    {
        $request = $this->technicalServiceRequest([
            'serial_number' => 'SN-OTHER-REASON',
            'status' => 'Tamamlandı',
            'completed_at' => '2026-05-02 10:00:00',
        ]);
        $user = User::factory()->create(['role_code' => 'admin']);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/status", [
                'status' => 'Yeni',
                'reopen_reason' => 'Diğer',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reopen_note');
    }

    public function test_non_completed_request_status_change_to_new_does_not_use_reopen_flow(): void
    {
        $request = $this->technicalServiceRequest([
            'serial_number' => 'SN-NOT-STARTED-REOPEN',
            'status' => 'Randevulu',
            'completed_at' => null,
        ]);
        $user = User::factory()->create(['role_code' => 'admin']);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/status", [
                'status' => 'Yeni',
            ])
            ->assertOk()
            ->assertJsonPath('request.status', 'Yeni')
            ->assertJsonPath('request.reopen_count', 0);

        $request->refresh();
        $this->assertNull($request->completed_at);
        $this->assertNull($request->reopened_at);
    }

    public function test_completing_installation_request_requires_actual_installation_date(): void
    {
        $request = $this->technicalServiceRequest([
            'serial_number' => 'SN-ACTUAL-REQUIRED',
            'service_type' => 'Montaj',
            'status' => 'Randevulu',
            'completed_at' => null,
        ]);
        $user = User::factory()->create(['role_code' => 'admin']);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/status", [
                'status' => 'Tamamlandı',
                'note' => 'Montaj tamamlandı',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('installation_completed_at');
    }

    public function test_actual_installation_date_is_not_required_for_non_installation_service(): void
    {
        $request = $this->technicalServiceRequest([
            'serial_number' => 'SN-FAULT',
            'service_type' => 'Arıza',
            'status' => 'Randevulu',
            'completed_at' => null,
        ]);
        $user = User::factory()->create(['role_code' => 'admin']);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/status", [
                'status' => 'Tamamlandı',
                'note' => 'Arıza giderildi',
            ])
            ->assertOk()
            ->assertJsonPath('request.status', 'Tamamlandı');
    }

    public function test_completing_installation_rejects_actual_date_before_latest_mikro_sale(): void
    {
        $this->mockLatestSale($this->salePayload('SN-BEFORE-SALE', 'before-sale-fp', '2026-03-01'));
        $request = $this->technicalServiceRequest([
            'serial_number' => 'SN-BEFORE-SALE',
            'service_type' => 'Montaj',
            'status' => 'Randevulu',
            'completed_at' => null,
        ]);
        $user = User::factory()->create(['role_code' => 'admin']);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/status", [
                'status' => 'Tamamlandı',
                'installation_completed_at' => '2026-02-20 10:00:00',
                'installation_completion_note' => 'Eski tarih kontrolü',
                'note' => 'Montaj tamamlandı',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('installation_completed_at');
    }

    public function test_completing_installation_rejects_future_actual_date(): void
    {
        $request = $this->technicalServiceRequest([
            'serial_number' => 'SN-FUTURE',
            'service_type' => 'Montaj',
            'status' => 'Randevulu',
            'completed_at' => null,
        ]);
        $user = User::factory()->create(['role_code' => 'admin']);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/status", [
                'status' => 'Tamamlandı',
                'installation_completed_at' => '2099-01-01 10:00:00',
                'note' => 'Montaj tamamlandı',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('installation_completed_at');
    }

    public function test_completing_installation_requires_note_when_actual_date_differs_from_schedule(): void
    {
        $request = $this->technicalServiceRequest([
            'serial_number' => 'SN-SCHEDULE-DIFF',
            'service_type' => 'Montaj',
            'status' => 'Randevulu',
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'completed_at' => null,
        ]);
        $user = User::factory()->create(['role_code' => 'admin']);

        $this->actingAs($user)
            ->postJson("/api/technical-service/requests/{$request->id}/status", [
                'status' => 'Tamamlandı',
                'installation_completed_at' => now()->subHours(2)->format('Y-m-d H:i:s'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('installation_completion_note');
    }

    public function test_replacement_transfers_remaining_days_and_closes_old_serial(): void
    {
        $this->mockLatestSale($this->salePayload('OLD-SN', 'old-fp'));
        $service = app(WarrantyService::class);
        $service->statusForSerial('OLD-SN');

        $oldCard = \App\Models\WarrantyCard::query()->where('serial_no', 'OLD-SN')->firstOrFail();
        $oldCard->forceFill([
            'installation_completed_at' => '2026-01-01',
            'warranty_started_at' => '2026-01-01',
            'warranty_ends_at' => '2028-01-01',
            'status' => 'Garanti Aktif',
        ])->save();

        $transfer = $service->transferToReplacement($oldCard, 'NEW-SN', '2026-07-01', 'Test değişim');

        $oldCard->refresh();
        $newCard = \App\Models\WarrantyCard::query()->where('serial_no', 'NEW-SN')->firstOrFail();

        $this->assertSame('Değişimle Kapandı', $oldCard->status);
        $this->assertSame('Garanti Aktif', $newCard->status);
        $this->assertEquals($transfer->remaining_warranty_days, $newCard->warranty_started_at->diffInDays($newCard->warranty_ends_at));
        $this->assertSame('2026-07-01', $newCard->warranty_started_at->toDateString());
        $this->assertDatabaseHas('warranty_transfers', [
            'old_serial_no' => 'OLD-SN',
            'new_serial_no' => 'NEW-SN',
        ]);
    }

    public function test_resold_old_serial_gets_new_card_when_fingerprint_changes(): void
    {
        $mikro = $this->mock(MikroSerialNumberService::class);
        $mikro->shouldReceive('latestValidSale')
            ->once()
            ->with('RESALE-SN')
            ->andReturn($this->salePayload('RESALE-SN', 'old-sale-fp'));
        $mikro->shouldReceive('latestValidSale')
            ->once()
            ->with('RESALE-SN')
            ->andReturn($this->salePayload('RESALE-SN', 'new-sale-fp', '2026-04-01', 'C-NEW'));

        $service = app(WarrantyService::class);
        $service->statusForSerial('RESALE-SN');

        $oldCard = \App\Models\WarrantyCard::query()->where('serial_no', 'RESALE-SN')->firstOrFail();
        $oldCard->forceFill(['status' => 'Değişimle Kapandı'])->save();

        $result = $service->statusForSerial('RESALE-SN');

        $this->assertSame('new-sale-fp', $result['last_sale']['fingerprint']);
        $this->assertSame(2, \App\Models\WarrantyCard::query()->where('serial_no', 'RESALE-SN')->count());
        $this->assertDatabaseHas('warranty_cards', [
            'serial_no' => 'RESALE-SN',
            'last_sale_mikro_fingerprint' => 'new-sale-fp',
            'status' => 'Garanti Başlamadı',
        ]);
    }

    public function test_mikro_connection_guard_is_preserved(): void
    {
        config([
            'database.connections.mikro_readonly.host' => '10.0.29.115',
            'database.connections.mikro_readonly.database' => 'MikroDesktop_EMAKS_PRIME',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('10.0.29.120');

        app(MikroSerialNumberService::class)->latestValidSale('SN-GUARD');
    }

    public function test_warranty_serial_endpoint_requires_serial_number(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);

        $this->actingAs($user)
            ->getJson('/api/technical-service/warranty/serial')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('serial_no');
    }

    public function test_warranty_serial_endpoint_returns_warranty_payload(): void
    {
        $this->mockLatestSale($this->salePayload('SN-ENDPOINT', 'endpoint-fp'));
        $user = User::factory()->create(['role_code' => 'admin']);

        $this->actingAs($user)
            ->getJson('/api/technical-service/warranty/serial?serial_no=SN-ENDPOINT')
            ->assertOk()
            ->assertJsonPath('serial_no', 'SN-ENDPOINT')
            ->assertJsonPath('status', 'Garanti Başlamadı')
            ->assertJsonPath('last_sale.fingerprint', 'endpoint-fp');
    }

    /**
     * @param array<string, mixed>|null $sale
     */
    private function mockLatestSale(?array $sale): void
    {
        $this->mock(MikroSerialNumberService::class, function ($mock) use ($sale) {
            $mock->shouldReceive('latestValidSale')->andReturn($sale);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function salePayload(string $serialNo, string $fingerprint, string $date = '2026-03-01', string $customerCode = 'C-1'): array
    {
        return [
            'serial_no' => $serialNo,
            'stock_code' => 'STK-1',
            'stock_name' => 'Test Ürün',
            'date' => $date,
            'customer_code' => $customerCode,
            'customer_name' => 'Test Cari',
            'document_type' => 'İrsaliye',
            'document_no' => 'IRS-1/1',
            'fingerprint' => $fingerprint,
            'installation_signal_date' => null,
            'installation_signal_source' => null,
            'different_customer_installation_warning' => false,
        ];
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
            'serial_number' => 'SN-TEST',
            'service_type' => 'Montaj',
            'status' => 'Tamamlandı',
            'priority' => 'Orta',
            'risk_level' => 'Orta',
            'completed_at' => '2026-05-02 10:00:00',
        ], $overrides));
    }
}
