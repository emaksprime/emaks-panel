<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceRequest;
use App\Services\TechnicalService\TechnicalServiceCodeGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class GlobalMrnSequenceMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_initializes_from_verified_historical_high_water_without_rewriting_history(): void
    {
        $migration = $this->migration();
        $migration->down();
        $first = $this->request('MRN-2606MP039998');
        $highest = $this->request('MRN-2607AC049999');
        $synthetic = $this->request('F57D722-UAT-20260803T102154Z', [
            'source_channel' => 'synthetic_local_uat',
        ]);
        $before = $this->identityHash();

        $migration->up();

        $this->assertSame(
            'MRN-2608BT1810000',
            app(TechnicalServiceCodeGenerator::class)->nextMrn(
                'Burhan Test',
                CarbonImmutable::parse('2026-08-18 10:00:00', 'Europe/Istanbul'),
            ),
        );
        $this->assertSame($before, $this->identityHash());
        $this->assertSame('MRN-2606MP039998', $first->fresh()->mrn);
        $this->assertSame('MRN-2607AC049999', $highest->fresh()->mrn);
        $this->assertSame('F57D722-UAT-20260803T102154Z', $synthetic->fresh()->mrn);
    }

    public function test_migration_passes_up_down_up_on_postgresql_16(): void
    {
        $migration = $this->migration();

        $migration->down();
        $this->assertNull($this->sequenceName());
        $migration->up();
        $this->assertSame(TechnicalServiceCodeGenerator::ROOT_MRN_SEQUENCE, $this->sequenceName());
        $migration->down();
        $this->assertNull($this->sequenceName());
        $migration->up();
        $this->assertSame(TechnicalServiceCodeGenerator::ROOT_MRN_SEQUENCE, $this->sequenceName());
    }

    public function test_migration_never_lowers_an_existing_sequence_position(): void
    {
        $migration = $this->migration();
        $migration->down();
        DB::statement('create sequence technical_service_root_mrn_sequence start with 50 cache 1');
        $this->request('MRN-2606MP030007');

        $migration->up();

        $this->assertSame(
            50,
            (int) DB::selectOne(
                "select nextval('technical_service_root_mrn_sequence'::regclass) as sequence_value"
            )->sequence_value,
        );
    }

    public function test_migration_fails_closed_for_unclassified_historical_root_format(): void
    {
        $migration = $this->migration();
        $migration->down();
        $unknown = $this->request('UNKNOWN-ROOT-FORMAT');

        try {
            $migration->up();
            $this->fail('The migration accepted an unclassified historical root format.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Unclassified historical root MRN', $exception->getMessage());
            $this->assertNull($this->sequenceName());
        } finally {
            $unknown->forceDelete();
            $migration->up();
        }
    }

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_08_18_120000_create_technical_service_root_mrn_sequence.php');

        return $migration;
    }

    /** @param array<string, mixed> $overrides */
    private function request(string $mrn, array $overrides = []): TechnicalServiceRequest
    {
        return TechnicalServiceRequest::query()->create([
            'mrn' => $mrn,
            'customer_name' => 'Migration Test',
            'customer_phone' => '+905551112233',
            'customer_city' => 'Istanbul',
            'customer_district' => 'Kadikoy',
            'service_address' => 'Test address',
            'product_name' => 'Test Product',
            'serial_number' => 'MIG-'.hash('sha256', $mrn),
            'service_type' => 'Montaj',
            'status' => TechnicalServiceRequest::STATUS_NEW,
            'workflow_status' => TechnicalServiceRequest::WORKFLOW_NEW_REQUEST,
            'priority' => TechnicalServiceRequest::PRIORITY_MEDIUM,
            'risk_level' => TechnicalServiceRequest::RISK_MEDIUM,
            ...$overrides,
        ]);
    }

    private function identityHash(): string
    {
        return hash('sha256', TechnicalServiceRequest::query()
            ->orderBy('id')
            ->get(['id', 'mrn', 'root_mrn', 'service_code', 'service_sequence'])
            ->toJson());
    }

    private function sequenceName(): ?string
    {
        $row = DB::selectOne(
            "select to_regclass('technical_service_root_mrn_sequence')::text as sequence_name"
        );

        return $row?->sequence_name;
    }
}
