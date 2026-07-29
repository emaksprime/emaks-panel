<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class TechnicalServiceLocksmithImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_locksmith_import_command_is_idempotent_and_skips_invalid_rows(): void
    {
        $path = $this->xlsxPath('locksmith-import.xlsx');
        $this->writeXlsx($path, [
            ['Çilingir / Servis Konsolide Liste', 'Açıklama satırı'],
            $this->headers(),
            ['34', 'İstanbul', '1', 'Ali Usta', '905371111111', '0537 111 11 11', '8G7H+22', '120.01.001', 'Ali Servis', 'Adres 1', 'Kadıköy İstanbul Türkiye', 'Ali Servis', 'Cari eşleşti', null],
            ['06', 'Ankara', '2', 'Ayşe Çilingir', '05372222222', '0537 222 22 22', '9G7H+22', '120.01.002', 'Ayşe Servis', 'Adres 2', 'Çankaya Ankara Türkiye', 'Ayşe Servis', 'Kontrol gerekli', 'Adres kontrol'],
            ['35', 'İzmir', '3', 'Servis Yok', '905373333333', '0537 333 33 33', null, null, null, null, null, null, 'Servis bilgisi yok', null],
            ['16', 'Bursa', '4', 'Telefonsuz Usta', null, null, null, null, null, null, null, null, 'Cari eşleşti', null],
        ]);

        $exitCode = Artisan::call('technical-service:import-locksmiths', ['path' => $path]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame(2, TechnicalServiceTechnician::query()->where('technician_type', 'locksmith')->count());
        $this->assertDatabaseHas('technical_service_technicians', [
            'name' => 'Ali Usta',
            'phone' => '+905371111111',
            'phone_e164' => '+905371111111',
            'technician_type' => 'locksmith',
            'city' => 'İstanbul',
            'priority' => 1,
            'location_code' => '8G7H+22',
            'cari_code' => '120.01.001',
            'needs_review' => false,
            'source_key' => 'locksmith:+905371111111:ISTANBUL',
        ]);
        $this->assertDatabaseHas('technical_service_technicians', [
            'name' => 'Ayşe Çilingir',
            'phone' => '+905372222222',
            'technician_type' => 'locksmith',
            'needs_review' => true,
        ]);

        $exitCode = Artisan::call('technical-service:import-locksmiths', ['path' => $path]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame(2, TechnicalServiceTechnician::query()->where('technician_type', 'locksmith')->count());
    }

    public function test_same_phone_in_different_city_marks_locksmith_for_review(): void
    {
        TechnicalServiceTechnician::query()->create([
            'name' => 'Eski Usta',
            'first_name' => 'Eski',
            'last_name' => 'Usta',
            'technician_type' => 'locksmith',
            'phone' => '+905555555555',
            'phone_e164' => '+905555555555',
            'city' => 'İstanbul',
            'active' => true,
        ]);

        $path = $this->xlsxPath('locksmith-review.xlsx');
        $this->writeXlsx($path, [
            ['Çilingir / Servis Konsolide Liste', 'Açıklama satırı'],
            $this->headers(),
            ['06', 'Ankara', '1', 'Eski Usta', '905555555555', '0555 555 55 55', null, null, null, null, null, null, 'Cari eşleşti', null],
        ]);

        $exitCode = Artisan::call('technical-service:import-locksmiths', ['path' => $path]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame(2, TechnicalServiceTechnician::query()->where('phone_e164', '+905555555555')->count());
        $technician = TechnicalServiceTechnician::query()
            ->where('phone_e164', '+905555555555')
            ->where('city', 'Ankara')
            ->firstOrFail();
        $this->assertTrue($technician->needs_review);
        $this->assertStringContainsString('farklı şehir', (string) $technician->note);
    }

    public function test_locksmith_seed_data_export_and_seeder_are_idempotent(): void
    {
        $sourcePath = $this->xlsxPath('locksmith-seed-source.xlsx');
        $outputPath = $this->xlsxPath('technical_service_locksmiths.json');
        $this->writeXlsx($sourcePath, [
            ['Çilingir / Servis Konsolide Liste', 'Açıklama satırı'],
            $this->headers(),
            ['34', 'İstanbul', '1', 'Ali Usta', '905371111111', '0537 111 11 11', '8G7H+22', '120.01.001', 'Ali Servis', 'Adres 1', 'Kadıköy İstanbul Türkiye', 'Ali Servis', 'Cari eşleşti', null],
            ['06', 'Ankara', '2', 'Ayşe Çilingir', '05372222222', '0537 222 22 22', '9G7H+22', '120.01.002', 'Ayşe Servis', 'Adres 2', 'Çankaya Ankara Türkiye', 'Ayşe Servis', 'Kontrol gerekli', 'Adres kontrol'],
            ['35', 'İzmir', '3', 'Servis Yok', '905373333333', '0537 333 33 33', null, null, null, null, null, null, 'Servis bilgisi yok', null],
        ]);

        $exitCode = Artisan::call('technical-service:export-locksmith-seed-data', [
            'path' => $sourcePath,
            '--output' => $outputPath,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertFileExists($outputPath);
        $data = json_decode((string) file_get_contents($outputPath), true);
        $this->assertIsArray($data);
        $this->assertCount(2, $data['items']);
        $this->assertSame('+905371111111', $data['items'][0]['phone_e164']);
        $this->assertSame('120.01.001', $data['items'][0]['cari_code']);

        config(['technical_service.locksmith_seed_data_path' => $outputPath]);
        Artisan::call('db:seed', ['--class' => 'TechnicalServiceLocksmithSeeder', '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'TechnicalServiceLocksmithSeeder', '--force' => true]);

        $this->assertSame(2, TechnicalServiceTechnician::query()->where('technician_type', 'locksmith')->count());
        $this->assertDatabaseHas('technical_service_technicians', [
            'name' => 'Ali Usta',
            'phone_e164' => '+905371111111',
            'priority' => 1,
            'cari_code' => '120.01.001',
            'location_code' => '8G7H+22',
        ]);
    }

    public function test_technician_list_api_filters_locksmiths(): void
    {
        TechnicalServiceTechnician::query()->create([
            'name' => 'Ankara Çilingir',
            'first_name' => 'Ankara',
            'last_name' => 'Çilingir',
            'technician_type' => 'locksmith',
            'phone' => '+905371111111',
            'phone_e164' => '+905371111111',
            'city' => 'Ankara',
            'priority' => 1,
            'location_code' => '9G7H+22',
            'cari_code' => '120.01.009',
            'needs_review' => true,
            'active' => true,
        ]);
        TechnicalServiceTechnician::query()->create([
            'name' => 'Normal Teknisyen',
            'first_name' => 'Normal',
            'last_name' => 'Teknisyen',
            'technician_type' => 'technician',
            'phone' => '+905372222222',
            'city' => 'Ankara',
            'needs_review' => false,
            'active' => true,
        ]);

        $user = User::factory()->create(['role_code' => 'admin']);

        $payload = $this->actingAs($user)
            ->getJson('/api/technical-service/technicians?technician_type=locksmith&city=Ankara&active=1&needs_review=1')
            ->assertOk()
            ->json('items');

        $this->assertCount(1, $payload);
        $this->assertSame('Ankara Çilingir', $payload[0]['name']);
        $this->assertSame('locksmith', $payload[0]['technician_type']);
        $this->assertSame(1, $payload[0]['priority']);
        $this->assertSame('+905371111111', $payload[0]['phone_e164']);
        $this->assertSame('9G7H+22', $payload[0]['location_code']);
        $this->assertSame('120.01.009', $payload[0]['cari_code']);
        $this->assertTrue($payload[0]['needs_review']);
    }

    public function test_technician_page_contains_locksmith_filters_and_fields(): void
    {
        $source = file_get_contents(resource_path('js/pages/panel/technical-service-technicians.tsx'));

        $this->assertIsString($source);
        foreach ([
            'Çilingir',
            'Kontrol gerekli',
            'Öncelik',
            'Cari Kodu',
            'Konum / Adres Kodu',
            'location_code',
            'cari_code',
            'line-clamp',
            'break-words',
            'xl:hidden',
            'needs_review',
            'technician_type',
        ] as $expected) {
            $this->assertStringContainsString($expected, $source);
        }
    }

    /**
     * @return array<int, string>
     */
    private function headers(): array
    {
        return [
            'Plaka Kodu',
            'Şehir',
            'Öncelik',
            'İsim Soyisim',
            'Telefon (90 format)',
            'Telefon (okunur)',
            'Konum / Adres Kodu',
            'Cari Kodu',
            'Cari Ünvan',
            'Cari Adres',
            'Cari İlçe İl Ülke',
            'Cari ADI',
            'Durum',
            'Kontrol Notu',
        ];
    }

    private function xlsxPath(string $name): string
    {
        $directory = storage_path('framework/testing/locksmith-import');
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
