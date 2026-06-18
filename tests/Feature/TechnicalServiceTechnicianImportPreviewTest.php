<?php

namespace Tests\Feature;

use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerTechnician;
use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class TechnicalServiceTechnicianImportPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_preview_parses_utf8_turkish_headers_and_semicolon_delimiter(): void
    {
        $csv = $this->csvUpload('turkish.csv', [
            ['Ad Soyad', 'Telefon', 'Şehir', 'İlçe', 'Adres', 'Mikro Cari Kodu'],
            ['Çağrı Çilingir', '0532 111 22 33', 'İzmir', 'Konak', 'Alsancak Mahallesi 1', '320.CLG.TEST.001'],
        ]);

        $response = $this->actingAs($this->admin())
            ->post('/api/technical-service/technicians/import-preview', [
                'file' => $csv,
                'dry_run' => '1',
            ]);

        $response->assertOk()
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('writes_performed', false)
            ->assertJsonPath('summary.total_rows', 1)
            ->assertJsonPath('summary.create_count', 1)
            ->assertJsonPath('summary.geocode_ready_count', 1)
            ->assertJsonPath('rows.0.normalized.full_name', 'Çağrı Çilingir')
            ->assertJsonPath('rows.0.normalized.phone_e164', '+905321112233')
            ->assertJsonPath('rows.0.geocode_plan.status', 'ready_address');
    }

    public function test_xlsx_preview_uses_tam_liste_sheet_and_detects_header_row(): void
    {
        $path = $this->xlsxPath('preview.xlsx');
        $this->writeXlsx($path, [
            ['Açıklama', 'Satırı'],
            ['Başka', 'Satır'],
            ['Ad Soyad', 'Telefon', 'Şehir', 'Adres', 'Plus Code', 'Mikro Cari Kodu'],
            ['Ayşe Usta', '905331112233', 'Ankara', 'Kızılay', '9G7H+22', '320.CLG.TEST.002'],
        ]);

        $response = $this->actingAs($this->admin())
            ->post('/api/technical-service/technicians/import-preview', [
                'file' => new UploadedFile($path, 'preview.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            ]);

        $response->assertOk()
            ->assertJsonPath('file.sheet_name', 'Tam Liste')
            ->assertJsonPath('file.detected_header_row', 3)
            ->assertJsonPath('summary.total_rows', 1)
            ->assertJsonPath('rows.0.geocode_plan.status', 'ready_plus_code');
    }

    public function test_import_preview_rejects_unsupported_file_type(): void
    {
        $response = $this->actingAs($this->admin())
            ->post('/api/technical-service/technicians/import-preview', [
                'file' => UploadedFile::fake()->create('legacy.xls', 4, 'application/vnd.ms-excel'),
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('writes_performed', false)
            ->assertJsonPath('message', 'XLS dosyası bu fazda desteklenmiyor. Lütfen XLSX veya CSV yükleyin.');
    }

    public function test_import_preview_requires_name_and_errors_invalid_coordinates(): void
    {
        $response = $this->actingAs($this->admin())
            ->post('/api/technical-service/technicians/import-preview', [
                'file' => $this->csvUpload('invalid.csv', [
                    ['Ad Soyad', 'Telefon', 'Şehir', 'Latitude', 'Longitude', 'Aktif'],
                    ['', '0532 111 22 33', 'İzmir', '120', '35', 'belki'],
                ]),
            ]);

        $response->assertOk()
            ->assertJsonPath('summary.error_count', 1)
            ->assertJsonPath('rows.0.action', 'error');

        $errors = $response->json('rows.0.errors');
        $this->assertContains('Ad soyad veya ad alanı zorunlu.', $errors);
        $this->assertContains('Koordinat geçersiz. Latitude -90..90, longitude -180..180 aralığında ve 0/0 olmamalı.', $errors);
        $this->assertContains('Aktif alanı sadece 1/0, true/false, aktif/pasif veya evet/hayır olabilir.', $errors);
    }

    public function test_import_preview_warns_missing_phone_address_and_detects_duplicate_phone(): void
    {
        $response = $this->actingAs($this->admin())
            ->post('/api/technical-service/technicians/import-preview', [
                'file' => $this->csvUpload('warnings.csv', [
                    ['Ad Soyad', 'Telefon', 'Şehir', 'Adres'],
                    ['Telefonsuz Usta', '', 'Bursa', ''],
                    ['Aynı Telefon 1', '0532 111 22 33', 'İzmir', 'Adres 1'],
                    ['Aynı Telefon 2', '0532 111 22 33', 'İzmir', 'Adres 2'],
                ]),
            ]);

        $response->assertOk()
            ->assertJsonPath('summary.warning_count', 3)
            ->assertJsonPath('summary.duplicate_count', 1);

        $this->assertContains('Telefon eksik; otomatik eşleşme zayıf olur.', $response->json('rows.0.warnings'));
        $this->assertContains('Adres veya Plus Code eksik; geocode uyarısı oluşur.', $response->json('rows.0.warnings'));
        $this->assertSame(['phone'], $response->json('rows.2.duplicates'));
    }

    public function test_import_preview_matches_existing_technician_and_preserves_coordinates(): void
    {
        TechnicalServiceTechnician::query()->create([
            'name' => 'Berkay Atlas',
            'first_name' => 'Berkay',
            'last_name' => 'Atlas',
            'phone' => '+905321112233',
            'phone_e164' => '+905321112233',
            'city' => 'İzmir',
            'latitude' => 38.4237,
            'longitude' => 27.1428,
            'active' => true,
        ]);

        $response = $this->actingAs($this->admin())
            ->post('/api/technical-service/technicians/import-preview', [
                'file' => $this->csvUpload('match.csv', [
                    ['Ad Soyad', 'Telefon', 'Şehir', 'Adres'],
                    ['Berkay Atlas', '0532 111 22 33', 'İzmir', 'Yeni adres'],
                ]),
            ]);

        $response->assertOk()
            ->assertJsonPath('summary.update_count', 1)
            ->assertJsonPath('summary.geocode_preserve_existing_count', 1)
            ->assertJsonPath('rows.0.existing_match.match_type', 'phone_e164')
            ->assertJsonPath('rows.0.geocode_plan.status', 'preserve_existing');
    }

    public function test_import_preview_uses_primary_address_as_default_start_contract(): void
    {
        $response = $this->actingAs($this->admin())
            ->post('/api/technical-service/technicians/import-preview', [
                'file' => $this->csvUpload('start-contract.csv', [
                    ['Ad Soyad', 'Telefon', 'Şehir', 'Adres', 'Başlangıç Adresi', 'Plus Code', 'Başlangıç Plus Code'],
                    ['Ana Adres Usta', '0532 111 22 33', 'İzmir', 'Ana adres', 'Başlangıç adresi', 'C5XJ+3P', 'XXXX+99'],
                    ['Fallback Usta', '0532 111 22 44', 'İzmir', '', 'Fallback başlangıç adresi', '', 'C5XJ+3Q'],
                ]),
            ]);

        $response->assertOk()
            ->assertJsonPath('rows.0.normalized.address', 'Ana adres')
            ->assertJsonPath('rows.0.normalized.google_plus_code', 'C5XJ+3P')
            ->assertJsonPath('rows.0.normalized.start_location_contract', 'primary_location')
            ->assertJsonPath('rows.1.normalized.address', 'Fallback başlangıç adresi')
            ->assertJsonPath('rows.1.normalized.google_plus_code', 'C5XJ+3Q');
    }

    public function test_import_preview_links_existing_partner_and_warns_when_partner_missing(): void
    {
        B2BPartner::query()->create([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'partner_code' => '320.CLG.PARTNER',
            'display_name' => 'Partner Çilingir',
            'mikro_cari_kodu' => '320.CLG.PARTNER',
            'active' => true,
        ]);

        $response = $this->actingAs($this->admin())
            ->post('/api/technical-service/technicians/import-preview', [
                'file' => $this->csvUpload('partners.csv', [
                    ['Ad Soyad', 'Telefon', 'Şehir', 'Adres', 'Mikro Cari Kodu'],
                    ['Partner Ustası', '0532 111 22 33', 'İzmir', 'Adres 1', '320.CLG.PARTNER'],
                    ['Eksik Partner', '0532 444 55 66', 'Ankara', 'Adres 2', '320.CLG.MISSING'],
                ]),
            ]);

        $response->assertOk()
            ->assertJsonPath('summary.partner_link_create_count', 1)
            ->assertJsonPath('summary.partner_missing_count', 1)
            ->assertJsonPath('rows.0.partner_match.name', 'Partner Çilingir')
            ->assertJsonPath('rows.1.link_plan.action', 'partner_missing');
    }

    public function test_import_preview_does_not_overwrite_different_person_same_partner(): void
    {
        $partner = B2BPartner::query()->create([
            'partner_type' => B2BPartner::TYPE_DEALER,
            'partner_code' => '320.CLG.BAHATTIN',
            'display_name' => 'Bahattin Özbek',
            'mikro_cari_kodu' => '320.CLG.BAHATTIN',
            'active' => true,
        ]);
        $berkay = TechnicalServiceTechnician::query()->create([
            'name' => 'Berkay Atlas',
            'first_name' => 'Berkay',
            'last_name' => 'Atlas',
            'phone' => '+905551112233',
            'phone_e164' => '+905551112233',
            'city' => 'İzmir',
            'active' => true,
        ]);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $berkay->id,
            'active' => true,
        ]);

        $response = $this->actingAs($this->admin())
            ->post('/api/technical-service/technicians/import-preview', [
                'file' => $this->csvUpload('bahattin.csv', [
                    ['Ad Soyad', 'Telefon', 'Şehir', 'Adres', 'Mikro Cari Kodu'],
                    ['Bahattin Özbek', '0532 999 88 77', 'Ankara', 'Bahattin adres', '320.CLG.BAHATTIN'],
                ]),
            ]);

        $response->assertOk()
            ->assertJsonPath('rows.0.action', 'create')
            ->assertJsonPath('rows.0.existing_match', null)
            ->assertJsonPath('rows.0.partner_match.name', 'Bahattin Özbek')
            ->assertJsonPath('summary.partner_link_create_count', 1);

        $this->assertSame('Berkay Atlas', $berkay->fresh()->name);
        $this->assertSame('İzmir', $berkay->fresh()->city);
    }

    public function test_import_preview_does_not_write_business_data(): void
    {
        $technicianCount = TechnicalServiceTechnician::query()->count();
        $partnerCount = B2BPartner::query()->count();
        $linkCount = B2BPartnerTechnician::query()->count();

        $response = $this->actingAs($this->admin())
            ->post('/api/technical-service/technicians/import-preview', [
                'file' => $this->csvUpload('no-write.csv', [
                    ['Ad Soyad', 'Telefon', 'Şehir', 'Adres'],
                    ['Yeni Usta', '0532 111 22 33', 'İzmir', 'Adres'],
                ]),
            ]);

        $response->assertOk()
            ->assertJsonPath('writes_performed', false)
            ->assertJsonPath('summary.create_count', 1);

        $this->assertSame($technicianCount, TechnicalServiceTechnician::query()->count());
        $this->assertSame($partnerCount, B2BPartner::query()->count());
        $this->assertSame($linkCount, B2BPartnerTechnician::query()->count());
    }

    public function test_technician_page_contains_import_preview_controls_and_disabled_apply(): void
    {
        $source = file_get_contents(resource_path('js/pages/panel/technical-service-technicians.tsx'));
        $importColumnBlock = Str::between($source, 'const importColumns = [', 'const emptyForm');

        $this->assertIsString($source);
        foreach ([
            'CSV / Excel ile toplu içe aktarma',
            'Önizle / Dry-run',
            'Faz 2B’de aktif olacak',
            '/api/technical-service/technicians/import-preview',
            'writes_performed',
            'Geocode hazır',
            'Partner eksik',
            'Başlangıç adresi ana adresle aynı kabul edilir',
        ] as $expected) {
            $this->assertStringContainsString($expected, $source);
        }

        $this->assertStringNotContainsString('default_start_address', $importColumnBlock);
        $this->assertStringNotContainsString('default_start_plus_code', $importColumnBlock);
        $this->assertStringNotContainsString('start_latitude', $importColumnBlock);
        $this->assertStringNotContainsString('start_longitude', $importColumnBlock);
    }

    private function admin(): User
    {
        return User::factory()->create(['role_code' => 'admin']);
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

    private function xlsxPath(string $name): string
    {
        $directory = storage_path('framework/testing/technician-import-preview');
        File::ensureDirectoryExists($directory);

        return $directory.'/'.$name;
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     */
    private function writeXlsx(string $path, array $rows): void
    {
        if (is_file($path)) {
            unlink($path);
        }

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE) === true);
        $zip->addFromString('[Content_Types].xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>
XML);
        $zip->addFromString('_rels/.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML);
        $zip->addFromString('xl/workbook.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Tam Liste" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>
XML);
        $zip->addFromString('xl/_rels/workbook.xml.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>
XML);
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->worksheetXml($rows));
        $zip->close();
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     */
    private function worksheetXml(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        foreach ($rows as $rowIndex => $row) {
            $xml .= '<row r="'.($rowIndex + 1).'">';
            foreach ($row as $columnIndex => $value) {
                if ($value === null || $value === '') {
                    continue;
                }

                $xml .= '<c r="'.$this->cellReference($columnIndex, $rowIndex).'" t="inlineStr"><is><t>'
                    .htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8')
                    .'</t></is></c>';
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    private function cellReference(int $columnIndex, int $rowIndex): string
    {
        $letters = '';
        $column = $columnIndex + 1;

        while ($column > 0) {
            $remainder = ($column - 1) % 26;
            $letters = chr(65 + $remainder).$letters;
            $column = intdiv($column - $remainder - 1, 26);
        }

        return $letters.($rowIndex + 1);
    }
}
