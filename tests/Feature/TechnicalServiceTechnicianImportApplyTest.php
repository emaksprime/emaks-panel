<?php

namespace Tests\Feature;

use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerTechnician;
use App\Models\TechnicalServiceTechnician;
use App\Models\TechnicalServiceTechnicianImportBatch;
use App\Models\TechnicalServiceTechnicianImportRow;
use App\Models\User;
use App\Services\TechnicalService\TechnicianImportApplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class TechnicalServiceTechnicianImportApplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_apply_requires_preview_hash(): void
    {
        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->actingAs($this->admin())
            ->post('/api/technical-service/technicians/import-apply', [
                'file' => $this->csvUpload('missing-hash.csv', $this->validRows()),
                'confirmation_text' => TechnicianImportApplyService::CONFIRMATION_TEXT,
                'selected_row_numbers' => [2],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['preview_hash']);
    }

    public function test_import_apply_returns_turkish_message_for_invalid_selected_rows_shape(): void
    {
        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->actingAs($this->admin())
            ->post('/api/technical-service/technicians/import-apply', [
                'file' => $this->csvUpload('invalid-selection.csv', $this->validRows()),
                'preview_hash' => str_repeat('a', 64),
                'confirmation_text' => TechnicianImportApplyService::CONFIRMATION_TEXT,
                'selected_row_numbers' => '2',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.selected_row_numbers.0', 'İçe aktarılacak satır seçimi geçersiz.')
            ->assertJsonMissing(['validation.array']);
    }

    public function test_import_apply_rejects_stale_preview_hash_and_wrong_confirmation(): void
    {
        $preview = $this->preview('stale.csv', $this->validRows());

        $this->actingAs($this->admin())
            ->post('/api/technical-service/technicians/import-apply', [
                'file' => $this->csvUpload('stale.csv', $this->validRows()),
                'preview_hash' => $preview['preview_hash'],
                'confirmation_text' => 'YANLIŞ',
                'selected_row_numbers' => [2],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'İçe aktarma için onay metni tam olarak IMPORT APPLY ONAY olmalı.');

        $this->actingAs($this->admin())
            ->post('/api/technical-service/technicians/import-apply', [
                'file' => $this->csvUpload('stale.csv', [
                    ['Ad Soyad', 'Telefon', 'Şehir', 'Adres'],
                    ['Değişen Usta', '0532 111 22 33', 'İzmir', 'Adres'],
                ]),
                'preview_hash' => $preview['preview_hash'],
                'confirmation_text' => TechnicianImportApplyService::CONFIRMATION_TEXT,
                'selected_row_numbers' => [2],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Dry-run önizlemesi güncel değil. Dosyayı veya seçenekleri değiştirdiyseniz önce yeniden önizleme alın.');
    }

    public function test_import_apply_rejects_more_than_50_rows(): void
    {
        $rows = [['Ad Soyad', 'Telefon', 'Şehir', 'Adres']];
        for ($i = 1; $i <= 51; $i++) {
            $rows[] = ["PR88 Import {$i}", '0532 111 '.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'İzmir', 'Adres'];
        }
        $preview = $this->preview('too-many.csv', $rows);

        $this->actingAs($this->admin())
            ->post('/api/technical-service/technicians/import-apply', [
                'file' => $this->csvUpload('too-many.csv', $rows),
                'preview_hash' => $preview['preview_hash'],
                'confirmation_text' => TechnicianImportApplyService::CONFIRMATION_TEXT,
                'selected_row_numbers' => range(2, 52),
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tek seferde en fazla 50 satır içe aktarılabilir. Filtreyi daraltın veya parça parça ilerleyin.');
    }

    public function test_import_apply_creates_new_technician_and_logs_batch_rows(): void
    {
        $rows = $this->validRows();
        $preview = $this->preview('create.csv', $rows);

        $response = $this->actingAs($this->admin())
            ->post('/api/technical-service/technicians/import-apply', [
                'file' => $this->csvUpload('create.csv', $rows),
                'preview_hash' => $preview['preview_hash'],
                'confirmation_text' => TechnicianImportApplyService::CONFIRMATION_TEXT,
                'selected_row_numbers' => [2],
            ]);

        $response->assertOk()
            ->assertJsonPath('writes_performed', true)
            ->assertJsonPath('summary.create_count', 1)
            ->assertJsonPath('summary.error_count', 0);

        $this->assertDatabaseHas('technical_service_technicians', [
            'name' => 'PR88-IMPORT-FIXTURE Yeni Usta',
            'phone_e164' => '+905321112233',
            'city' => 'İzmir',
            'import_source' => 'technician_import_apply',
        ]);
        $this->assertSame(1, TechnicalServiceTechnicianImportBatch::query()->count());
        $this->assertSame(1, TechnicalServiceTechnicianImportRow::query()->count());
    }

    public function test_import_apply_updates_existing_by_phone_and_preserves_coordinates_by_default(): void
    {
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'PR88 Existing',
            'first_name' => 'PR88',
            'last_name' => 'Existing',
            'phone' => '+905321112233',
            'phone_e164' => '+905321112233',
            'city' => 'İzmir',
            'address' => 'Eski adres',
            'latitude' => 38.4237000,
            'longitude' => 27.1428000,
            'active' => true,
        ]);
        $rows = [
            ['Ad Soyad', 'Telefon', 'Şehir', 'Adres', 'Latitude', 'Longitude'],
            ['PR88 Existing', '0532 111 22 33', 'Manisa', 'Yeni adres', '39.0000000', '28.0000000'],
        ];
        $preview = $this->preview('update.csv', $rows, ['update_existing' => true]);

        $response = $this->actingAs($this->admin())
            ->post('/api/technical-service/technicians/import-apply', [
                'file' => $this->csvUpload('update.csv', $rows),
                'preview_hash' => $preview['preview_hash'],
                'confirmation_text' => TechnicianImportApplyService::CONFIRMATION_TEXT,
                'selected_row_numbers' => [2],
                'update_existing' => '1',
                'override_existing_coordinates' => '0',
            ]);

        $response->assertOk()
            ->assertJsonPath('summary.update_count', 1)
            ->assertJsonPath('summary.preserved_coordinate_count', 1);

        $fresh = $technician->fresh();
        $this->assertSame('Manisa', $fresh->city);
        $this->assertSame('Yeni adres', $fresh->address);
        $this->assertSame(38.4237, (float) $fresh->latitude);
        $this->assertSame(27.1428, (float) $fresh->longitude);
    }

    public function test_import_apply_links_existing_partner_by_mikro_cari_and_does_not_create_missing_partner(): void
    {
        $partner = B2BPartner::query()->create([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'partner_code' => '320.CLG.PARTNER',
            'display_name' => 'PR88 Partner',
            'mikro_cari_kodu' => '320.CLG.PARTNER',
            'active' => true,
        ]);
        $rows = [
            ['Ad Soyad', 'Telefon', 'Şehir', 'Adres', 'Mikro Cari Kodu'],
            ['PR88 Link Usta', '0532 111 22 33', 'İzmir', 'Adres', '320.CLG.PARTNER'],
            ['PR88 Missing Partner', '0532 111 22 44', 'İzmir', 'Adres', '320.CLG.MISSING'],
        ];
        $preview = $this->preview('link.csv', $rows);

        $response = $this->actingAs($this->admin())
            ->post('/api/technical-service/technicians/import-apply', [
                'file' => $this->csvUpload('link.csv', $rows),
                'preview_hash' => $preview['preview_hash'],
                'confirmation_text' => TechnicianImportApplyService::CONFIRMATION_TEXT,
                'selected_row_numbers' => [2, 3],
            ]);

        $response->assertOk()
            ->assertJsonPath('summary.create_count', 2)
            ->assertJsonPath('summary.partner_link_create_count', 1);

        $this->assertSame(1, B2BPartner::query()->count());
        $this->assertDatabaseHas('b2b_partner_technicians', [
            'partner_id' => $partner->id,
            'active' => true,
            'source' => 'technician_import_apply',
        ]);
    }

    public function test_import_apply_keeps_bahattin_and_berkay_separate(): void
    {
        $partner = B2BPartner::query()->create([
            'partner_type' => B2BPartner::TYPE_DEALER,
            'partner_code' => '320.CLG.BAHATTIN',
            'display_name' => 'BAHATTİN ÖZBEK',
            'mikro_cari_kodu' => '320.CLG.BAHATTIN',
            'city' => 'Ankara',
            'active' => true,
        ]);
        $berkay = TechnicalServiceTechnician::query()->create([
            'name' => 'BERKAY ATLAS',
            'first_name' => 'BERKAY',
            'last_name' => 'ATLAS',
            'phone' => '+905071838038',
            'phone_e164' => '+905071838038',
            'city' => 'İzmir',
            'address' => 'Bornova',
            'active' => true,
        ]);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $berkay->id,
            'active' => true,
        ]);
        $rows = [
            ['Ad Soyad', 'Telefon', 'Şehir', 'Adres', 'Mikro Cari Kodu'],
            ['BAHATTİN ÖZBEK', '0532 222 33 44', 'Ankara', 'Bahattin adres', '320.CLG.BAHATTIN'],
        ];
        $preview = $this->preview('bahattin.csv', $rows);

        $this->actingAs($this->admin())
            ->post('/api/technical-service/technicians/import-apply', [
                'file' => $this->csvUpload('bahattin.csv', $rows),
                'preview_hash' => $preview['preview_hash'],
                'confirmation_text' => TechnicianImportApplyService::CONFIRMATION_TEXT,
                'selected_row_numbers' => [2],
            ])
            ->assertOk()
            ->assertJsonPath('summary.create_count', 1);

        $this->assertSame('BERKAY ATLAS', $berkay->fresh()->name);
        $this->assertSame('İzmir', $berkay->fresh()->city);
        $this->assertSame(1, B2BPartner::query()->where('mikro_cari_kodu', '320.CLG.BAHATTIN')->count());
        $this->assertSame(2, TechnicalServiceTechnician::query()->count());
    }

    public function test_import_apply_ui_contains_confirmation_selection_and_result_panel(): void
    {
        $source = file_get_contents(resource_path('js/pages/panel/technical-service-technicians.tsx'));

        $this->assertIsString($source);
        foreach ([
            '/api/technical-service/technicians/import-apply',
            'Geçerli satırları seç',
            'IMPORT APPLY ONAY',
            'Seçili geçerli satırları içe aktar',
            'Apply tamamlandı. Batch #',
            'Tek seferde en fazla 50 satır içe aktarılabilir.',
            'Mikro’ya yazılmaz',
        ] as $expected) {
            $this->assertStringContainsString($expected, $source);
        }
    }

    private function admin(): User
    {
        return User::factory()->create(['role_code' => 'admin']);
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function preview(string $name, array $rows, array $options = []): array
    {
        $response = $this->actingAs($this->admin())
            ->post('/api/technical-service/technicians/import-preview', array_merge([
                'file' => $this->csvUpload($name, $rows),
                'dry_run' => '1',
            ], $options));

        $response->assertOk();

        return $response->json();
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function validRows(): array
    {
        return [
            ['Ad Soyad', 'Telefon', 'Şehir', 'Adres', 'Latitude', 'Longitude'],
            ['PR88-IMPORT-FIXTURE Yeni Usta', '0532 111 22 33', 'İzmir', 'Fixture adres', '38.4237000', '27.1428000'],
        ];
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     */
    private function csvUpload(string $name, array $rows): UploadedFile
    {
        $lines = array_map(
            fn (array $row): string => implode(';', array_map(fn (mixed $value): string => str_replace(';', ',', (string) $value), $row)),
            $rows,
        );

        return UploadedFile::fake()->createWithContent($name, implode("\n", $lines));
    }
}
