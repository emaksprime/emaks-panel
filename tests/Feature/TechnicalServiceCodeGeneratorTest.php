<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceRequest;
use App\Services\TechnicalService\TechnicalServiceCodeGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TechnicalServiceCodeGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restartSequence();
    }

    public function test_root_mrn_uses_single_global_sequence(): void
    {
        $generator = app(TechnicalServiceCodeGenerator::class);

        $this->assertSame('MRN-2606MP030001', $generator->nextMrn('Mehmet Burhan Pekguzel', $this->date('2026-06-03')));
        $this->assertSame('MRN-2606AC030002', $generator->nextMrn('Ayse Celik', $this->date('2026-06-03')));
    }

    public function test_root_mrn_sequence_does_not_reset_by_day(): void
    {
        $generator = app(TechnicalServiceCodeGenerator::class);

        $this->assertSame(1, $this->sequence($generator->nextMrn('Burhan Test', $this->date('2026-06-03'))));
        $this->assertSame(2, $this->sequence($generator->nextMrn('Burhan Test', $this->date('2026-06-04'))));
    }

    public function test_root_mrn_sequence_does_not_reset_by_month(): void
    {
        $generator = app(TechnicalServiceCodeGenerator::class);

        $this->assertSame(1, $this->sequence($generator->nextMrn('Burhan Test', $this->date('2026-06-30'))));
        $this->assertSame(2, $this->sequence($generator->nextMrn('Burhan Test', $this->date('2026-07-01'))));
    }

    public function test_root_mrn_sequence_does_not_reset_by_year(): void
    {
        $generator = app(TechnicalServiceCodeGenerator::class);

        $this->assertSame(1, $this->sequence($generator->nextMrn('Burhan Test', $this->date('2026-12-31'))));
        $this->assertSame(2, $this->sequence($generator->nextMrn('Burhan Test', $this->date('2027-01-01'))));
    }

    public function test_root_mrn_preserves_existing_visible_prefix_format(): void
    {
        $mrn = app(TechnicalServiceCodeGenerator::class)
            ->nextMrn('Mehmet Burhan Pekguzel', $this->date('2026-06-03'));

        $this->assertSame('MRN-2606MP030001', $mrn);
    }

    public function test_root_mrn_is_zero_padded_to_minimum_four_digits(): void
    {
        $this->restartSequence(92);

        $this->assertSame(
            'MRN-2606MP030092',
            app(TechnicalServiceCodeGenerator::class)->nextMrn('Mehmet Pekguzel', $this->date('2026-06-03')),
        );
    }

    public function test_root_mrn_continues_from_9999_to_10000(): void
    {
        $this->restartSequence(9999);
        $generator = app(TechnicalServiceCodeGenerator::class);

        $this->assertSame('MRN-2606MP039999', $generator->nextMrn('Mehmet Pekguzel', $this->date('2026-06-03')));
        $this->assertSame('MRN-2606MP0310000', $generator->nextMrn('Mehmet Pekguzel', $this->date('2026-06-03')));
    }

    public function test_root_mrn_never_truncates_five_digit_sequence(): void
    {
        $this->restartSequence(100000);

        $mrn = app(TechnicalServiceCodeGenerator::class)
            ->nextMrn('Mehmet Pekguzel', $this->date('2026-06-03'));

        $this->assertSame('MRN-2606MP03100000', $mrn);
        $this->assertSame(100000, $this->sequence($mrn));
    }

    public function test_concurrent_root_mrn_allocation_has_no_duplicates(): void
    {
        $config = DB::connection()->getConfig();
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            (string) $config['host'],
            (string) $config['port'],
            (string) $config['database'],
        );
        $environment = [
            'REL4H_DSN' => $dsn,
            'REL4H_DB_USER' => (string) $config['username'],
            'REL4H_DB_PASSWORD' => (string) ($config['password'] ?? ''),
        ];
        $script = <<<'PHP'
$pdo = new PDO(getenv('REL4H_DSN'), getenv('REL4H_DB_USER'), getenv('REL4H_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$worker = (int) $argv[1];
$sequence = (int) $pdo->query("select nextval('technical_service_root_mrn_sequence'::regclass)")->fetchColumn();
$mrn = 'MRN-2606CW03'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
$statement = $pdo->prepare('insert into technical_service_requests (mrn, customer_name, customer_phone, customer_city, customer_district, service_address, product_name, serial_number, service_type, status, workflow_status, priority, risk_level, source_channel, created_at, updated_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, now(), now())');
$statement->execute([$mrn, 'Concurrent Worker', '+905550000000', 'Istanbul', 'Kadikoy', 'Test address', 'Test Product', 'REL4H-'.$worker, 'Montaj', 'Yeni', 'Yeni Talep', 'Orta', 'Orta', 'rel4h_concurrency_test']);
echo $mrn;
PHP;
        $processes = [];
        $mrns = [];
        $errors = [];

        try {
            foreach (range(1, 20) as $worker) {
                $process = new Process([PHP_BINARY, '-r', $script, (string) $worker], base_path(), $environment);
                $process->setTimeout(30);
                $process->start();
                $processes[] = $process;
            }

            foreach ($processes as $process) {
                $process->wait();

                if (! $process->isSuccessful()) {
                    $errors[] = trim($process->getErrorOutput() ?: $process->getOutput());

                    continue;
                }

                $mrns[] = trim($process->getOutput());
            }
        } finally {
            $cleanup = new PDO(
                $dsn,
                (string) $config['username'],
                (string) ($config['password'] ?? ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            $cleanup->exec("delete from technical_service_requests where source_channel = 'rel4h_concurrency_test'");
        }

        $this->assertSame([], $errors, implode("\n", $errors));
        $this->assertCount(20, $mrns);
        $this->assertCount(20, array_unique($mrns));
        $this->assertSame(0, TechnicalServiceRequest::query()->where('source_channel', 'rel4h_concurrency_test')->count());
    }

    public function test_rolled_back_sequence_is_not_reused(): void
    {
        $generator = app(TechnicalServiceCodeGenerator::class);
        $rolledBackMrn = null;

        try {
            DB::transaction(function () use ($generator, &$rolledBackMrn): void {
                $rolledBackMrn = $generator->nextMrn('Rollback Test', $this->date('2026-06-03'));

                throw new RuntimeException('force rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('force rollback', $exception->getMessage());
        }

        $nextMrn = $generator->nextMrn('Rollback Test', $this->date('2026-06-03'));

        $this->assertSame(1, $this->sequence((string) $rolledBackMrn));
        $this->assertSame(2, $this->sequence($nextMrn));
    }

    public function test_cancelled_request_sequence_is_not_reused(): void
    {
        $generator = app(TechnicalServiceCodeGenerator::class);
        $unusedMrn = $generator->nextMrn('Cancelled Test', $this->date('2026-06-03'));
        $nextMrn = $generator->nextMrn('Next Test', $this->date('2026-06-03'));

        $this->assertSame(1, $this->sequence($unusedMrn));
        $this->assertSame(2, $this->sequence($nextMrn));
    }

    public function test_historical_mrn_values_are_not_rewritten(): void
    {
        $legacy = $this->createRequestWithMrn('MRN-LEGACY-2024-001');

        app(TechnicalServiceCodeGenerator::class)->nextMrn('Mehmet Pekguzel', $this->date('2026-06-03'));

        $this->assertSame('MRN-LEGACY-2024-001', $legacy->fresh()->mrn);
    }

    public function test_historical_srv_values_are_not_rewritten(): void
    {
        $root = $this->createRequestWithMrn('MRN-LEGACY-ROOT');
        $service = $this->createRequestWithMrn('SRV-LEGACY-ROOT-001', [
            'parent_request_id' => $root->id,
            'root_mrn' => $root->mrn,
            'service_code' => 'SRV-LEGACY-ROOT-001',
            'service_sequence' => 1,
        ]);

        app(TechnicalServiceCodeGenerator::class)->nextMrn('Mehmet Pekguzel', $this->date('2026-06-03'));

        $this->assertSame('SRV-LEGACY-ROOT-001', $service->fresh()->mrn);
        $this->assertSame('SRV-LEGACY-ROOT-001', $service->fresh()->service_code);
    }

    /** @param array<string, mixed> $overrides */
    private function createRequestWithMrn(string $mrn, array $overrides = []): TechnicalServiceRequest
    {
        return TechnicalServiceRequest::query()->create([
            'mrn' => $mrn,
            'customer_name' => 'Test Customer',
            'customer_phone' => '+905551112233',
            'customer_city' => 'Istanbul',
            'customer_district' => 'Kadikoy',
            'service_address' => 'Test address',
            'product_name' => 'Test Product',
            'product_model' => 'TST',
            'serial_number' => 'SN-'.hash('sha256', $mrn),
            'service_type' => 'Montaj',
            'status' => TechnicalServiceRequest::STATUS_NEW,
            'workflow_status' => TechnicalServiceRequest::WORKFLOW_NEW_REQUEST,
            'priority' => TechnicalServiceRequest::PRIORITY_MEDIUM,
            'risk_level' => TechnicalServiceRequest::RISK_MEDIUM,
            ...$overrides,
        ]);
    }

    private function restartSequence(int $nextValue = 1): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        DB::selectOne(
            "select setval('technical_service_root_mrn_sequence'::regclass, cast(? as bigint), false)",
            [$nextValue],
        );
    }

    private function sequence(string $mrn): int
    {
        $matched = preg_match('/^MRN-\d{4}[A-Z]{2}\d{2}(\d{4,})$/D', $mrn, $matches);
        $this->assertSame(1, $matched, 'Unexpected MRN format: '.$mrn);

        return (int) $matches[1];
    }

    private function date(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date.' 10:00:00', 'Europe/Istanbul');
    }
}
