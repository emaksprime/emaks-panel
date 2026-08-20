<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TechnicalServiceSyntheticDataFactory;

class RepositoryOperationalPiiGuardTest extends TestCase
{
    public function test_operational_datasets_and_private_payloads_are_not_publishable_files(): void
    {
        $paths = $this->prospectiveRepositoryPaths();
        $removedDatasets = [
            'database/data/'.'technical_service_locksmiths'.'.json',
            'database/data/'.'technical_service_technician_coordinates'.'.json',
        ];

        foreach ($removedDatasets as $path) {
            $this->assertNotContains($path, $paths);
            $this->assertFileDoesNotExist($this->repositoryPath($path));
        }

        $privateFiles = array_values(array_filter(
            $paths,
            fn (string $path): bool => str_starts_with($path, 'storage/app/private/technical-service/'),
        ));

        $this->assertSame([], $privateFiles);
    }

    public function test_remediated_identifier_tests_use_the_deterministic_synthetic_factory(): void
    {
        foreach ([
            'tests/Feature/B2BPartnerPanelAccessTest.php',
            'tests/Feature/TechnicalServiceTechnicianImportApplyTest.php',
            'tests/Feature/TechnicalServiceWorkflowTest.php',
        ] as $path) {
            $contents = (string) file_get_contents($this->repositoryPath($path));

            $this->assertStringContainsString('TechnicalServiceSyntheticDataFactory', $contents, $path);
        }
    }

    public function test_private_data_entrypoints_do_not_reference_removed_tracked_defaults(): void
    {
        $removedBasenames = [
            'technical_service_locksmiths'.'.json',
            'technical_service_technician_coordinates'.'.json',
        ];
        $entrypoints = [
            'app/Console/Commands/ExportTechnicalServiceLocksmithSeedData.php',
            'app/Console/Commands/ExportTechnicalServiceTechnicianCoordinates.php',
            'app/Console/Commands/ImportTechnicalServiceLocksmiths.php',
            'app/Console/Commands/ImportTechnicalServiceTechnicianCoordinates.php',
            'app/Services/TechnicalService/LocksmithImportService.php',
            'app/Services/TechnicalService/TechnicianCoordinateDataService.php',
            'database/seeders/TechnicalServiceLocksmithSeeder.php',
            'database/seeders/TechnicalServiceTechnicianCoordinateSeeder.php',
        ];

        foreach ($entrypoints as $path) {
            $contents = (string) file_get_contents($this->repositoryPath($path));

            foreach ($removedBasenames as $basename) {
                $this->assertStringNotContainsString($basename, $contents, $path);
            }
        }
    }

    public function test_synthetic_factory_is_marked_deterministic_and_non_routable(): void
    {
        $first = TechnicalServiceSyntheticDataFactory::locksmith(17);
        $second = TechnicalServiceSyntheticDataFactory::locksmith(17);

        $this->assertSame($first, $second);
        $this->assertTrue($first['synthetic']);
        $this->assertSame(TechnicalServiceSyntheticDataFactory::MARKER, 'synthetic');
        $this->assertMatchesRegularExpression('/^\+900{5,}\d{5}$/', $first['phone_e164']);
        $this->assertStringStartsWith('SYNTH-CARI-', $first['cari_code']);
        $this->assertStringStartsWith('SENTETİK TEST ADRESİ ', $first['address']);
        $this->assertSame('synthetic_fixture', TechnicalServiceSyntheticDataFactory::coordinate(17)['location_source']);
        $this->assertSame(2, count(TechnicalServiceSyntheticDataFactory::duplicateIdentity(17)));
        $this->assertNull(TechnicalServiceSyntheticDataFactory::missingCoordinate(17)['latitude']);
        $this->assertSame(0.0, TechnicalServiceSyntheticDataFactory::invalidCoordinate(17)['latitude']);
        $this->assertSame('', TechnicalServiceSyntheticDataFactory::blankFields(17)['cari_title']);
    }

    /**
     * @return array<int, string>
     */
    private function prospectiveRepositoryPaths(): array
    {
        $command = sprintf(
            'git -C %s -c core.quotepath=false ls-files --cached --others --exclude-standard -z',
            escapeshellarg($this->repositoryRoot()),
        );
        $output = shell_exec($command);

        if (! is_string($output)) {
            throw new RuntimeException('Prospective repository path listesi okunamadi.');
        }

        return array_values(array_filter(array_map(
            fn (string $path): string => str_replace('\\', '/', $path),
            explode("\0", $output),
        )));
    }

    private function repositoryPath(string $path): string
    {
        return $this->repositoryRoot().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
