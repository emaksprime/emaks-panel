<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\TechnicalServiceSyntheticDataFactory;
use Tests\TestCase;
use ZipArchive;

class TechnicalServiceLocksmithImportTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $taskFiles = [];

    /** @var array<int, string> */
    private array $taskDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->taskFiles) as $path) {
            if (is_link($path) || is_file($path)) {
                @unlink($path);
            }
        }

        foreach (array_reverse($this->taskDirectories) as $path) {
            if (is_link($path) || is_dir($path)) {
                @rmdir($path);
            }
        }

        parent::tearDown();
    }

    public function test_import_defaults_to_zero_write_dry_run_and_apply_is_idempotent(): void
    {
        $path = $this->privatePath('synthetic-locksmith-import.xlsx');
        $this->writeXlsx($path, $this->rows([
            TechnicalServiceSyntheticDataFactory::xlsxRow(1),
            TechnicalServiceSyntheticDataFactory::xlsxRow(2),
        ]));

        $dryRunWrites = [];
        DB::listen(function ($query) use (&$dryRunWrites): void {
            if (preg_match('/^\s*(insert|update|delete|truncate|alter|drop|create)\b/i', $query->sql) === 1) {
                $dryRunWrites[] = strtolower($query->sql);
            }
        });
        $beforeFiles = $this->privateFileCount();
        [$dryRun, $dryRunOutput] = $this->callArtisan('technical-service:import-locksmiths', ['--source' => $path]);

        $this->assertSame(0, $dryRun, $dryRunOutput);
        $this->assertSame([], $dryRunWrites);
        $this->assertSame(0, TechnicalServiceTechnician::query()->count());
        $this->assertSame($beforeFiles, $this->privateFileCount());
        $this->assertStringContainsString('insert: 2', $dryRunOutput);
        $this->assertStringContainsString('delete: 0', $dryRunOutput);
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        Http::assertNothingSent();

        [$firstApply, $firstApplyOutput] = $this->callArtisan('technical-service:import-locksmiths', [
            '--source' => $path,
            '--apply' => true,
        ]);

        $this->assertSame(0, $firstApply, $firstApplyOutput);
        $this->assertSame(2, TechnicalServiceTechnician::query()->count());
        $this->assertStringContainsString('insert: 2', $firstApplyOutput);

        [$secondApply, $secondApplyOutput] = $this->callArtisan('technical-service:import-locksmiths', [
            '--source' => $path,
            '--apply' => true,
        ]);

        $this->assertSame(0, $secondApply, $secondApplyOutput);
        $this->assertStringContainsString('insert: 0', $secondApplyOutput);
        $this->assertStringContainsString('update: 0', $secondApplyOutput);
        $this->assertStringContainsString('delete: 0', $secondApplyOutput);
        $this->assertSame(2, TechnicalServiceTechnician::query()->count());
    }

    public function test_apply_preserves_existing_nonblank_values_inactive_state_and_unlisted_rows(): void
    {
        $existing = TechnicalServiceSyntheticDataFactory::locksmith(3, [
            'active' => false,
            'needs_review' => true,
            'note' => 'Synthetic existing note.',
            'cari_address' => 'SYNTHETIC EXISTING ADDRESS',
            'address' => 'SYNTHETIC EXISTING ADDRESS',
        ]);
        unset($existing['synthetic']);
        TechnicalServiceTechnician::query()->create($existing);

        $unlisted = TechnicalServiceSyntheticDataFactory::locksmith(99);
        unset($unlisted['synthetic']);
        $unlistedModel = TechnicalServiceTechnician::query()->create($unlisted);

        $path = $this->privatePath('synthetic-preservation.xlsx');
        $this->writeXlsx($path, $this->rows([
            TechnicalServiceSyntheticDataFactory::xlsxRow(3, [
                'cari_address' => null,
                'cari_title' => null,
                'import_note' => null,
                'needs_review' => false,
            ]),
        ]));

        $exit = Artisan::call('technical-service:import-locksmiths', [
            '--source' => $path,
            '--apply' => true,
        ]);

        $this->assertSame(0, $exit, Artisan::output());
        $fresh = TechnicalServiceTechnician::query()->where('source_key', $existing['source_key'])->firstOrFail();
        $this->assertFalse($fresh->active);
        $this->assertTrue($fresh->needs_review);
        $this->assertSame('Synthetic existing note.', $fresh->note);
        $this->assertSame('SYNTHETIC EXISTING ADDRESS', $fresh->cari_address);
        $this->assertDatabaseHas('technical_service_technicians', ['id' => $unlistedModel->id]);
        $this->assertSame(2, TechnicalServiceTechnician::query()->count());
    }

    public function test_invalid_or_duplicate_identity_rolls_back_the_full_apply(): void
    {
        $path = $this->privatePath('synthetic-conflict.xlsx');
        $duplicate = TechnicalServiceSyntheticDataFactory::xlsxRow(10);
        $invalid = TechnicalServiceSyntheticDataFactory::xlsxRow(11, ['phone_e164' => null, 'phone_display' => null]);
        $this->writeXlsx($path, $this->rows([$duplicate, $duplicate, $invalid]));

        [$exit, $output] = $this->callArtisan('technical-service:import-locksmiths', [
            '--source' => $path,
            '--apply' => true,
        ]);

        $this->assertSame(1, $exit, $output);
        $this->assertSame(0, TechnicalServiceTechnician::query()->count());
        $this->assertStringContainsString('invalid=1', $output);
        $this->assertStringContainsString('conflict=1', $output);
    }

    public function test_ambiguous_existing_source_identity_is_rejected_without_updates(): void
    {
        $fixture = TechnicalServiceSyntheticDataFactory::locksmith(12);
        unset($fixture['synthetic']);
        TechnicalServiceTechnician::query()->create($fixture);
        TechnicalServiceTechnician::query()->create(array_replace($fixture, [
            'name' => 'Synthetic Duplicate Identity',
            'phone' => '+900000099998',
            'phone_e164' => '+900000099998',
        ]));

        $path = $this->privatePath('synthetic-ambiguous.xlsx');
        $this->writeXlsx($path, $this->rows([TechnicalServiceSyntheticDataFactory::xlsxRow(12)]));
        $before = TechnicalServiceTechnician::query()->orderBy('id')->get()->map->getRawOriginal()->all();

        $exit = Artisan::call('technical-service:import-locksmiths', [
            '--source' => $path,
            '--apply' => true,
        ]);

        $this->assertSame(1, $exit, Artisan::output());
        $after = TechnicalServiceTechnician::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $this->assertSame($before, $after);
    }

    public function test_command_requires_source_and_rejects_tracked_or_traversal_paths(): void
    {
        $this->assertSame(1, Artisan::call('technical-service:import-locksmiths'));
        $this->assertSame(1, Artisan::call('technical-service:import-locksmiths', [
            '--source' => base_path('README.md'),
        ]));
        $this->assertSame(1, Artisan::call('technical-service:import-locksmiths', [
            '--source' => storage_path('app/private/technical-service/../../outside.xlsx'),
        ]));
        $this->assertSame(0, TechnicalServiceTechnician::query()->count());
    }

    public function test_private_source_symlink_escape_is_rejected(): void
    {
        $outsideDirectory = storage_path('framework/testing/synthetic-source-target-'.getmypid());
        File::ensureDirectoryExists($outsideDirectory);
        $this->taskDirectories[] = $outsideDirectory;
        $outside = $outsideDirectory.'/source.xlsx';
        $this->writeXlsx($outside, $this->rows([TechnicalServiceSyntheticDataFactory::xlsxRow(20)]));
        $linkDirectory = $this->privatePath('synthetic-source-junction-'.getmypid(), false);
        $created = @symlink($outsideDirectory, $linkDirectory);

        if (! $created && PHP_OS_FAMILY === 'Windows') {
            $command = sprintf(
                'cmd /d /c mklink /J "%s" "%s" >NUL',
                str_replace('"', '""', $linkDirectory),
                str_replace('"', '""', $outsideDirectory),
            );
            exec($command, $ignored, $exitCode);
            $created = $exitCode === 0;
        }

        $this->assertTrue($created, 'A task-local source junction could not be created for the rejection test.');
        $this->taskDirectories[] = $linkDirectory;
        $link = $linkDirectory.'/source.xlsx';

        $this->assertSame(1, Artisan::call('technical-service:import-locksmiths', ['--source' => $link]));
        $this->assertSame(0, TechnicalServiceTechnician::query()->count());
    }

    public function test_export_is_private_atomic_and_refuses_overwrite(): void
    {
        $source = $this->privatePath('synthetic-export-source.xlsx');
        $output = $this->privatePath('synthetic-locksmith-export.json', false);
        $this->writeXlsx($source, $this->rows([TechnicalServiceSyntheticDataFactory::xlsxRow(30)]));

        $first = Artisan::call('technical-service:export-locksmith-seed-data', [
            '--source' => $source,
            '--output' => $output,
        ]);

        $this->assertSame(0, $first, Artisan::output());
        $this->assertFileExists($output);
        $this->taskFiles[] = $output;
        $decoded = json_decode((string) file_get_contents($output), true);
        $this->assertFalse($decoded['synthetic']);
        $this->assertCount(1, $decoded['items']);

        $second = Artisan::call('technical-service:export-locksmith-seed-data', [
            '--source' => $source,
            '--output' => $output,
        ]);
        $this->assertSame(1, $second, Artisan::output());
    }

    public function test_explicit_nonproduction_seeder_is_idempotent_and_has_no_tracked_default(): void
    {
        try {
            Artisan::call('db:seed', ['--class' => 'TechnicalServiceLocksmithSeeder', '--force' => true]);
            $this->fail('Seeder unexpectedly accepted a missing explicit path.');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $path = $this->privatePath('synthetic-locksmith-seed.json');
        $contents = TechnicalServiceSyntheticDataFactory::dataset([
            TechnicalServiceSyntheticDataFactory::locksmith(40),
        ]);
        File::put($path, json_encode($contents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        config(['technical_service.locksmith_seed_data_path' => $path]);

        Artisan::call('db:seed', ['--class' => 'TechnicalServiceLocksmithSeeder', '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'TechnicalServiceLocksmithSeeder', '--force' => true]);

        $this->assertSame(1, TechnicalServiceTechnician::query()->count());
    }

    public function test_apply_writes_only_the_locksmith_table(): void
    {
        $path = $this->privatePath('synthetic-table-allowlist.xlsx');
        $this->writeXlsx($path, $this->rows([TechnicalServiceSyntheticDataFactory::xlsxRow(50)]));
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|truncate|alter|drop)\b/i', $query->sql) === 1) {
                $writes[] = strtolower($query->sql);
            }
        });

        $exit = Artisan::call('technical-service:import-locksmiths', [
            '--source' => $path,
            '--apply' => true,
        ]);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertNotEmpty($writes);
        foreach ($writes as $sql) {
            $this->assertStringContainsString('technical_service_technicians', $sql);
        }
    }

    public function test_technician_list_api_filters_synthetic_locksmiths(): void
    {
        $locksmith = TechnicalServiceSyntheticDataFactory::locksmith(60, ['needs_review' => true]);
        unset($locksmith['synthetic']);
        TechnicalServiceTechnician::query()->create($locksmith);

        $technician = TechnicalServiceSyntheticDataFactory::locksmith(61, ['technician_type' => 'technician']);
        unset($technician['synthetic']);
        TechnicalServiceTechnician::query()->create($technician);

        $user = User::factory()->create(['role_code' => 'admin']);
        $payload = $this->actingAs($user)
            ->getJson('/api/technical-service/technicians?technician_type=locksmith&active=1&needs_review=1')
            ->assertOk()
            ->json('items');

        $this->assertCount(1, $payload);
        $this->assertSame($locksmith['source_key'], $payload[0]['source_key']);
    }

    public function test_technician_page_contains_locksmith_filters_and_fields(): void
    {
        $source = file_get_contents(resource_path('js/pages/panel/technical-service-technicians.tsx'));

        $this->assertIsString($source);
        foreach (['location_code', 'cari_code', 'needs_review', 'technician_type'] as $expected) {
            $this->assertStringContainsString($expected, $source);
        }
    }

    /**
     * @param  array<int, array<int, mixed>>  $records
     * @return array<int, array<int, mixed>>
     */
    private function rows(array $records): array
    {
        return array_merge([
            ['Synthetic Locksmith Master', 'Synthetic fixture'],
            TechnicalServiceSyntheticDataFactory::headers(),
        ], $records);
    }

    private function privatePath(string $name, bool $track = true): string
    {
        $directory = storage_path('app/private/technical-service');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/'.$name;

        if ($track) {
            $this->taskFiles[] = $path;
        }

        return $path;
    }

    private function privateFileCount(): int
    {
        return count(File::files(storage_path('app/private/technical-service')));
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function writeXlsx(string $path, array $rows): void
    {
        File::ensureDirectoryExists(dirname($path));
        if (is_file($path) || is_link($path)) {
            unlink($path);
        }
        if (! in_array($path, $this->taskFiles, true)) {
            $this->taskFiles[] = $path;
        }

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE) === true);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Tam Liste" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->worksheetXml($rows));
        $zip->close();
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
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

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{0:int,1:string}
     */
    private function callArtisan(string $command, array $parameters = []): array
    {
        $output = new BufferedOutput;
        $exitCode = Artisan::call($command, $parameters, $output);

        return [$exitCode, $output->fetch()];
    }
}
