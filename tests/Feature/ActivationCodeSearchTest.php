<?php

namespace Tests\Feature;

use App\Models\ActivationCodeRecord;
use App\Models\User;
use App\Services\ActivationCodeSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ActivationCodeSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_derives_prefix_activation_code_and_prefix_search_fields(): void
    {
        $service = app(ActivationCodeSearchService::class);

        $payload = $service->buildRecordPayload('W720FWS03E250621A01783-123456', 'STK-1', 'Urun 1', 'codes.csv');

        $this->assertSame('123456', $service->activationCodeFromSerial('W720FWS03E250621A01783-123456'));
        $this->assertSame('W720FWS03E250621A01783', $payload['serial_prefix']);
        $this->assertSame('W720FWS03E250621A01783', $payload['serial_prefix_clean']);
        $this->assertSame('A01783', $payload['serial_prefix_tail_6']);
        $this->assertSame('0621A01783', $payload['serial_prefix_tail_10']);
        $this->assertNull($service->activationCodeFromSerial('SNWITHOUTDASH'));
    }

    public function test_page_shows_csv_import_card_after_search_area(): void
    {
        $contents = file_get_contents(resource_path('js/pages/panel/activation-code-search.tsx'));

        $this->assertIsString($contents);
        $this->assertStringContainsString('CSV ile kayıt yükle', $contents);
        $this->assertStringContainsString('STOK_KODU\', \'STOK_ADI\', \'SERI_NO', $contents);
        $this->assertLessThan(
            strpos($contents, 'CSV ile kayıt yükle'),
            strpos($contents, 'Kayıt bulunamadı.'),
        );
    }

    public function test_search_returns_result_for_first_six_prefix_characters(): void
    {
        $user = User::factory()->create(['role_code' => 'technical']);
        $this->createRecord('W720FWS03E250621A01783-123456', 'STK-001', 'Alpha');

        $this->actingAs($user)
            ->getJson('/api/activation-code-search?query=W720FW')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('items.0.activation_code', '123456');
    }

    public function test_search_returns_result_for_middle_prefix_characters(): void
    {
        $user = User::factory()->create(['role_code' => 'technical']);
        $this->createRecord('W720FWS03E250621A01783-123456', 'STK-001', 'Alpha');

        $this->actingAs($user)
            ->getJson('/api/activation-code-search?query=250621')
            ->assertOk()
            ->assertJsonPath('count', 1);
    }

    public function test_search_returns_result_for_last_six_prefix_characters(): void
    {
        $user = User::factory()->create(['role_code' => 'technical']);
        $this->createRecord('W720FWS03E250621A01783-123456', 'STK-001', 'Alpha');

        $this->actingAs($user)
            ->getJson('/api/activation-code-search?query=A01783')
            ->assertOk()
            ->assertJsonPath('count', 1);
    }

    public function test_search_returns_result_for_full_serial_prefix(): void
    {
        $user = User::factory()->create(['role_code' => 'technical']);
        $this->createRecord('W720FWS03E250621A01783-123456', 'STK-001', 'Alpha');

        $this->actingAs($user)
            ->getJson('/api/activation-code-search?query=W720FWS03E250621A01783')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('items.0.stock_code', 'STK-001');
    }

    public function test_search_does_not_return_result_for_activation_code_only(): void
    {
        $user = User::factory()->create(['role_code' => 'technical']);
        $this->createRecord('W720FWS03E250621A01783-123456', 'STK-001', 'Alpha');

        $this->actingAs($user)
            ->getJson('/api/activation-code-search?query=123456')
            ->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonPath('items', []);
    }

    public function test_search_rejects_query_shorter_than_six_characters(): void
    {
        $user = User::factory()->create(['role_code' => 'technical']);

        $this->actingAs($user)
            ->getJson('/api/activation-code-search?query=W720F')
            ->assertStatus(422)
            ->assertJsonValidationErrors('query')
            ->assertJsonPath('errors.query.0', 'En az 6 karakter seri no girin.');
    }

    public function test_search_does_not_match_activation_code_inside_serial_number(): void
    {
        $user = User::factory()->create(['role_code' => 'technical']);
        $this->createRecord('SERINO-654321', 'STK-001', 'Alpha');

        $this->actingAs($user)
            ->getJson('/api/activation-code-search?query=654321')
            ->assertOk()
            ->assertJsonPath('count', 0);
    }

    public function test_search_returns_multiple_matches_for_prefix_query(): void
    {
        $user = User::factory()->create(['role_code' => 'technical']);
        $this->createRecord('W720FWS03E250621A01783-123456', 'STK-001', 'Alpha');
        $this->createRecord('W720FWS03E250621B01784-654321', 'STK-002', 'Beta');

        $this->actingAs($user)
            ->getJson('/api/activation-code-search?query=W720FWS03E250621')
            ->assertOk()
            ->assertJsonPath('count', 2);
    }

    public function test_csv_import_upserts_and_generates_prefix_search_fields(): void
    {
        $user = User::factory()->create(['role_code' => 'technical']);

        $firstImport = UploadedFile::fake()->createWithContent(
            'aktivasyon.csv',
            implode("\n", [
                'STOK_KODU;STOK_ADI;SERI_NO',
                'STK-001;Celik Kilit;D193LGS61E211011B22109-576013',
                'STK-002;Eksik Veri;',
            ]),
        );

        $this->actingAs($user)
            ->post('/api/activation-code-search/import', ['file' => $firstImport])
            ->assertOk()
            ->assertJsonPath('created_count', 1)
            ->assertJsonPath('updated_count', 0)
            ->assertJsonPath('skipped_count', 1)
            ->assertJsonPath('errors.0.row', 3);

        $this->assertDatabaseHas('activation_code_records', [
            'serial_no' => 'D193LGS61E211011B22109-576013',
            'stock_name' => 'Celik Kilit',
            'activation_code' => '576013',
            'serial_prefix_clean' => 'D193LGS61E211011B22109',
            'serial_prefix_tail_6' => 'B22109',
            'serial_prefix_tail_10' => '1011B22109',
            'source_file_name' => 'aktivasyon.csv',
        ]);

        $secondImport = UploadedFile::fake()->createWithContent(
            'aktivasyon-guncel.csv',
            implode("\n", [
                'STOK_KODU,STOK_ADI,SERI_NO',
                'STK-001,Guncel Kilit,D193LGS61E211011B22109-576013',
            ]),
        );

        $this->actingAs($user)
            ->post('/api/activation-code-search/import', ['file' => $secondImport])
            ->assertOk()
            ->assertJsonPath('created_count', 0)
            ->assertJsonPath('updated_count', 1)
            ->assertJsonPath('skipped_count', 0);

        $this->assertDatabaseHas('activation_code_records', [
            'serial_no' => 'D193LGS61E211011B22109-576013',
            'stock_name' => 'Guncel Kilit',
            'source_file_name' => 'aktivasyon-guncel.csv',
        ]);
    }

    private function createRecord(string $serialNo, string $stockCode, string $stockName): ActivationCodeRecord
    {
        $payload = app(ActivationCodeSearchService::class)
            ->buildRecordPayload($serialNo, $stockCode, $stockName, 'seed.csv');

        return ActivationCodeRecord::query()->create($payload);
    }
}
