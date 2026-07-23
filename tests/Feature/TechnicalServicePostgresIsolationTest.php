<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\IsolatedPostgreSqlEnvironment;

class TechnicalServicePostgresIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        $projectRoot = dirname(__DIR__, 2);

        IsolatedPostgreSqlEnvironment::assertConfigurationCacheAbsent($projectRoot);
        IsolatedPostgreSqlEnvironment::assertProcessEnvironment();

        parent::setUp();

        IsolatedPostgreSqlEnvironment::assertLaravelConfiguration($this->app->make('config'));

        Http::fake([]);
        Http::preventStrayRequests();
        Queue::fake();
        Bus::fake();
        Mail::fake();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
        Mail::assertNothingSent();
        Notification::assertNothingSent();

        parent::tearDown();
    }

    public function test_profile_rejects_canonical_port_and_non_test_database_name(): void
    {
        $canonicalPort = IsolatedPostgreSqlEnvironment::currentEnvironment();
        $canonicalPort['DB_PORT'] = (string) IsolatedPostgreSqlEnvironment::CANONICAL_PORT;
        $canonicalPort['PGPORT'] = (string) IsolatedPostgreSqlEnvironment::CANONICAL_PORT;

        $this->assertGuardFailure(
            $canonicalPort,
            'rel4g_guard:canonical_or_invalid_port',
        );

        $nonTestDatabase = IsolatedPostgreSqlEnvironment::currentEnvironment();
        $nonTestDatabase['DB_DATABASE'] = 'application';
        $nonTestDatabase['PGDATABASE'] = 'application';

        $this->assertGuardFailure(
            $nonTestDatabase,
            'rel4g_guard:database_name_mismatch',
        );
    }

    public function test_profile_uses_postgresql_16_on_dynamic_loopback_port(): void
    {
        $environment = IsolatedPostgreSqlEnvironment::assertProcessEnvironment();
        $database = IsolatedPostgreSqlEnvironment::assertConnectedDatabase(DB::connection('pgsql'));

        $this->assertSame('127.0.0.1', $environment['host']);
        $this->assertNotSame(IsolatedPostgreSqlEnvironment::CANONICAL_PORT, $environment['port']);
        $this->assertSame(16, $database['major']);
        $this->assertSame('pgsql', $database['driver']);
        $this->assertSame($environment['database'], $database['database']);
    }

    public function test_ephemeral_database_has_no_persistent_volume_or_shared_network(): void
    {
        $isolation = IsolatedPostgreSqlEnvironment::assertDockerIsolation();

        $this->assertSame('tmpfs', $isolation['mount_type']);
        $this->assertSame(1, $isolation['mount_count']);
        $this->assertSame(1, $isolation['network_container_count']);
        $this->assertSame('127.0.0.1', $isolation['host_ip']);
        $this->assertNotSame(IsolatedPostgreSqlEnvironment::CANONICAL_PORT, $isolation['host_port']);
    }

    public function test_two_independent_php_processes_reach_the_same_controlled_barrier(): void
    {
        $environment = IsolatedPostgreSqlEnvironment::assertProcessEnvironment();
        $projectRoot = dirname(__DIR__, 2);
        $workerScript = $projectRoot.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'TechnicalServiceDecisionRaceWorker.php';

        DB::connection('pgsql')->statement(<<<'SQL'
            create table if not exists rel4g_postgres_barrier_probes (
                nonce varchar(32) not null,
                worker varchar(16) not null,
                pid integer not null,
                ready_at timestamptz not null,
                primary key (nonce, worker)
            )
            SQL);

        DB::connection('pgsql')->table('rel4g_postgres_barrier_probes')
            ->where('nonce', $environment['nonce'])
            ->delete();

        $one = $this->barrierProcess($workerScript, $environment['nonce'], 'one', $projectRoot);
        $two = $this->barrierProcess($workerScript, $environment['nonce'], 'two', $projectRoot);

        try {
            $one->start();
            $two->start();
            $one->wait();
            $two->wait();

            $this->assertSame(0, $one->getExitCode(), 'First isolated barrier worker failed.');
            $this->assertSame(0, $two->getExitCode(), 'Second isolated barrier worker failed.');

            $first = $this->decodeWorkerPayload($one);
            $second = $this->decodeWorkerPayload($two);

            $this->assertTrue($first['ok'] ?? false);
            $this->assertTrue($second['ok'] ?? false);
            $this->assertSame('one', $first['worker'] ?? null);
            $this->assertSame('two', $second['worker'] ?? null);
            $this->assertTrue($first['outbound_guarded'] ?? false);
            $this->assertTrue($second['outbound_guarded'] ?? false);
            $this->assertSame($environment['nonce'], $first['nonce'] ?? null);
            $this->assertSame($environment['nonce'], $second['nonce'] ?? null);
            $this->assertSame(2, $first['ready_count'] ?? null);
            $this->assertSame(2, $second['ready_count'] ?? null);
            $this->assertNotSame($first['pid'] ?? null, $second['pid'] ?? null);

            $latestStart = max((int) ($first['started_at_ns'] ?? 0), (int) ($second['started_at_ns'] ?? 0));
            $earliestRelease = min((int) ($first['reached_at_ns'] ?? 0), (int) ($second['reached_at_ns'] ?? 0));

            $this->assertGreaterThan(0, $latestStart);
            $this->assertGreaterThanOrEqual($latestStart, $earliestRelease);
        } finally {
            $this->stopExactProcess($one);
            $this->stopExactProcess($two);

            DB::connection('pgsql')->table('rel4g_postgres_barrier_probes')
                ->where('nonce', $environment['nonce'])
                ->delete();
            DB::connection('pgsql')->statement('drop table if exists rel4g_postgres_barrier_probes');
        }
    }

    public function test_cleanup_guard_rejects_wrong_container_id_name_or_label(): void
    {
        $expected = IsolatedPostgreSqlEnvironment::cleanupIdentityFromEnvironment();

        foreach ([
            ['key' => 'id', 'value' => str_repeat('0', 64), 'error' => 'rel4g_cleanup_guard:id_mismatch'],
            ['key' => 'name', 'value' => 'wrong-container', 'error' => 'rel4g_cleanup_guard:name_mismatch'],
            ['key' => 'scope', 'value' => 'wrong-scope', 'error' => 'rel4g_cleanup_guard:scope_mismatch'],
            ['key' => 'nonce', 'value' => '000000000000', 'error' => 'rel4g_cleanup_guard:nonce_mismatch'],
        ] as $case) {
            $observed = $expected;
            $observed[$case['key']] = $case['value'];

            try {
                IsolatedPostgreSqlEnvironment::assertCleanupIdentity($expected, $observed);
                $this->fail('Cleanup identity mismatch was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertSame($case['error'], $exception->getMessage());
            }
        }
    }

    /**
     * @param  array<string, string|null>  $environment
     */
    private function assertGuardFailure(array $environment, string $expectedMessage): void
    {
        try {
            IsolatedPostgreSqlEnvironment::assertProcessEnvironment($environment);
            $this->fail('Unsafe PostgreSQL profile was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame($expectedMessage, $exception->getMessage());
        }
    }

    private function barrierProcess(string $workerScript, string $nonce, string $worker, string $projectRoot): Process
    {
        $process = new Process([PHP_BINARY, $workerScript, 'barrier', $nonce, $worker], $projectRoot);
        $process->setTimeout(20);
        $process->setIdleTimeout(15);

        return $process;
    }

    /**
     * @return array<string, bool|int|string>
     */
    private function decodeWorkerPayload(Process $process): array
    {
        try {
            $payload = json_decode(trim($process->getOutput()), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->fail('Isolated barrier worker returned invalid JSON.');
        }

        $this->assertIsArray($payload);

        return $payload;
    }

    private function stopExactProcess(Process $process): void
    {
        if ($process->isRunning()) {
            $process->stop(1);
        }
    }
}
