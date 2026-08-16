<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\IsolatedPostgreSqlEnvironment;
use Tests\TestCase;

class CanonicalUatTestIsolationGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_uat_is_rejected_before_test_boot(): void
    {
        $environment = IsolatedPostgreSqlEnvironment::currentEnvironment();
        $environment['DB_DATABASE'] = IsolatedPostgreSqlEnvironment::CANONICAL_DATABASE;
        $environment['PGDATABASE'] = IsolatedPostgreSqlEnvironment::CANONICAL_DATABASE;

        $this->assertGuardFailure(
            $environment,
            'TEST_DATABASE_GUARD_BLOCKED_CANONICAL_UAT',
        );
    }

    public function test_quarantine_database_is_rejected_before_test_boot(): void
    {
        $database = IsolatedPostgreSqlEnvironment::QUARANTINE_DATABASE_PREFIX.'guard_canary';
        $environment = IsolatedPostgreSqlEnvironment::currentEnvironment();
        $environment['DB_DATABASE'] = $database;
        $environment['PGDATABASE'] = $database;

        $this->assertGuardFailure(
            $environment,
            'TEST_DATABASE_GUARD_BLOCKED_QUARANTINE_DATABASE',
        );
    }

    public function test_runtime_dotenv_cannot_override_disposable_test_database(): void
    {
        $facts = IsolatedPostgreSqlEnvironment::assertProcessEnvironment();

        IsolatedPostgreSqlEnvironment::assertLaravelConfiguration($this->app->make('config'));

        $this->assertSame($facts['database'], DB::connection('pgsql')->getDatabaseName());
        $this->assertNotSame(IsolatedPostgreSqlEnvironment::CANONICAL_DATABASE, $facts['database']);
        $this->assertStringStartsWith(IsolatedPostgreSqlEnvironment::DATABASE_PREFIX, $facts['database']);
    }

    public function test_disposable_test_database_is_cleaned_by_exact_run_owner(): void
    {
        $identity = IsolatedPostgreSqlEnvironment::cleanupIdentityFromEnvironment();

        IsolatedPostgreSqlEnvironment::assertCleanupIdentity($identity, $identity);
        $this->addToAssertionCount(1);

        $mismatch = $identity;
        $mismatch['nonce'] = str_repeat('0', 12);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('rel4g_cleanup_guard:nonce_mismatch');

        IsolatedPostgreSqlEnvironment::assertCleanupIdentity($identity, $mismatch);
    }

    public function test_refresh_database_runs_only_on_disposable_postgresql(): void
    {
        $facts = IsolatedPostgreSqlEnvironment::assertConnectedDatabase(DB::connection('pgsql'));

        $this->assertSame(16, $facts['major']);
        $this->assertSame('pgsql', $facts['driver']);
        $this->assertStringStartsWith(IsolatedPostgreSqlEnvironment::DATABASE_PREFIX, $facts['database']);
        $this->assertTrue(DB::connection('pgsql')->getSchemaBuilder()->hasTable('migrations'));
    }

    /**
     * @param  array<string, string|null>  $environment
     */
    private function assertGuardFailure(array $environment, string $message): void
    {
        try {
            IsolatedPostgreSqlEnvironment::assertProcessEnvironment($environment);
            $this->fail('Protected database profile was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }
}
