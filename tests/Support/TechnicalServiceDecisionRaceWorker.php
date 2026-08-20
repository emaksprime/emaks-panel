<?php

declare(strict_types=1);

use App\Models\TechnicalServiceMountPayment;
use App\Services\Payments\IyzicoPaymentProvider;
use App\Services\Payments\PaymentProviderManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\IsolatedPostgreSqlEnvironment;

ini_set('display_errors', '0');
error_reporting(E_ALL);

$mode = isset($argv[1]) && is_string($argv[1]) ? $argv[1] : '';
$workerStage = 'bootstrap';

try {
    $projectRoot = dirname(__DIR__, 2);
    $nonce = isset($argv[2]) && is_string($argv[2]) ? $argv[2] : '';

    registerWorkerPid($nonce);

    require $projectRoot.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';

    IsolatedPostgreSqlEnvironment::assertConfigurationCacheAbsent($projectRoot);
    $facts = IsolatedPostgreSqlEnvironment::assertProcessEnvironment();

    if (! hash_equals($facts['nonce'], $nonce)) {
        throw new RuntimeException('worker_nonce_mismatch');
    }

    $app = require $projectRoot.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php';
    $app->make(Kernel::class)->bootstrap();

    IsolatedPostgreSqlEnvironment::assertLaravelConfiguration($app->make('config'));
    installOutboundGuards();

    if (in_array($mode, ['provider_claim_mismatch', 'provider_claim_valid'], true)) {
        $workerStage = 'provider_claim_race';
        $worker = isset($argv[3]) && is_string($argv[3]) ? $argv[3] : '';
        $paymentId = isset($argv[4]) && is_string($argv[4]) && ctype_digit($argv[4])
            ? (int) $argv[4]
            : 0;
        $raceDirectory = isset($argv[5]) && is_string($argv[5]) ? $argv[5] : '';
        $counterPath = isset($argv[6]) && is_string($argv[6]) ? $argv[6] : '';
        $testNow = isset($argv[7]) && is_string($argv[7]) ? $argv[7] : '';

        assertProviderClaimRaceArguments(
            $facts['nonce'],
            $worker,
            $paymentId,
            $raceDirectory,
            $counterPath,
        );
        Carbon::setTestNow(Carbon::parse($testNow));
        $connection = $app->make('db')->connection('pgsql');
        IsolatedPostgreSqlEnvironment::assertConnectedDatabase($connection);
        $payment = TechnicalServiceMountPayment::query()->findOrFail($paymentId);
        $app->instance(IyzicoPaymentProvider::class, new class($counterPath) extends IyzicoPaymentProvider
        {
            public function __construct(private readonly string $counterPath) {}

            public function createPayment(TechnicalServiceMountPayment $payment): array
            {
                incrementProviderCallCounter($this->counterPath);
                usleep(150_000);
                $reference = 'race-session-'.$payment->getKey();
                $payment->forceFill([
                    'provider' => 'iyzico',
                    'provider_reference' => $reference,
                    'payment_url' => 'http://10.0.28.64:8000/payments/'.$reference,
                    'status' => TechnicalServiceMountPayment::STATUS_PENDING,
                ])->save();

                return [
                    'provider_reference' => $reference,
                    'payment_url' => 'http://10.0.28.64:8000/payments/'.$reference,
                    'status' => TechnicalServiceMountPayment::STATUS_PENDING,
                ];
            }
        });

        synchronizeProviderClaimRace($raceDirectory, $worker);

        try {
            $result = $app->make(PaymentProviderManager::class)->createPayment($payment);
            $outcome = (string) ($result['outcome'] ?? 'unknown');
            $rejection = 'none';
        } catch (ConflictHttpException $exception) {
            $outcome = 'rejected';
            $rejection = explode(':', $exception->getMessage(), 2)[0];
        }

        assertNoExternalEffects();
        emitPayload([
            'ok' => true,
            'mode' => $mode,
            'worker' => $worker,
            'outcome' => $outcome,
            'rejection' => $rejection,
            'outbound_guarded' => true,
        ]);
    }

    if ($mode === 'preflight') {
        assertNoExternalEffects();
        emitPayload([
            'ok' => true,
            'mode' => 'preflight',
            'outbound_guarded' => true,
        ]);
    }

    if ($mode === 'connectivity') {
        $workerStage = 'direct_pdo';
        $direct = new PDO(
            sprintf(
                "pgsql:host=127.0.0.1;port=%d;dbname='%s';sslmode=disable",
                $facts['port'],
                $facts['database'],
            ),
            (string) getenv('DB_USERNAME'),
            (string) getenv('DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        if ($direct->query('select current_database()')->fetchColumn() !== $facts['database']) {
            throw new RuntimeException('direct_database_identity_mismatch');
        }

        $direct = null;
        $workerStage = 'laravel_connection';
        $connection = $app->make('db')->connection('pgsql');
        IsolatedPostgreSqlEnvironment::assertConnectedDatabase($connection);

        assertNoExternalEffects();
        emitPayload([
            'ok' => true,
            'mode' => 'connectivity',
            'outbound_guarded' => true,
        ]);
    }

    if ($mode !== 'barrier') {
        throw new RuntimeException('worker_mode_invalid');
    }

    $worker = isset($argv[3]) && is_string($argv[3]) ? $argv[3] : '';

    if (! in_array($worker, ['one', 'two'], true)) {
        throw new RuntimeException('worker_identity_invalid');
    }

    $connection = $app->make('db')->connection('pgsql');
    IsolatedPostgreSqlEnvironment::assertConnectedDatabase($connection);

    $startedAt = hrtime(true);
    $pid = getmypid();

    if (! is_int($pid) || $pid < 1) {
        throw new RuntimeException('worker_pid_invalid');
    }

    $connection->table('rel4g_postgres_barrier_probes')->insert([
        'nonce' => $facts['nonce'],
        'worker' => $worker,
        'pid' => $pid,
        'ready_at' => now(),
    ]);

    $deadline = microtime(true) + 10;
    $readyCount = 0;

    do {
        $readyCount = $connection->table('rel4g_postgres_barrier_probes')
            ->where('nonce', $facts['nonce'])
            ->count();

        if ($readyCount === 2) {
            break;
        }

        usleep(50_000);
    } while (microtime(true) < $deadline);

    if ($readyCount !== 2) {
        throw new RuntimeException('worker_barrier_timeout');
    }

    assertNoExternalEffects();
    emitPayload([
        'ok' => true,
        'mode' => 'barrier',
        'outbound_guarded' => true,
        'worker' => $worker,
        'pid' => $pid,
        'nonce' => $facts['nonce'],
        'ready_count' => $readyCount,
        'started_at_ns' => $startedAt,
        'reached_at_ns' => hrtime(true),
    ]);
} catch (Throwable $exception) {
    $errorCode = strtoupper((string) $exception->getCode());
    $errorDetail = 'other';
    $errorMessage = $exception->getMessage();

    if (preg_match('/(?i)connection refused/', $errorMessage) === 1) {
        $errorDetail = 'refused';
    } elseif (preg_match('/(?i)server closed the connection unexpectedly|connection reset|forcibly closed|connection was closed/', $errorMessage) === 1) {
        $errorDetail = 'unexpected_close';
    } elseif (preg_match('/(?i)SSL SYSCALL|SSL error|certificate|server does not support SSL|send SSL negotiation/', $errorMessage) === 1) {
        $errorDetail = 'ssl';
    } elseif (preg_match('/(?i)invalid response to SSL negotiation|error response during SSL exchange/', $errorMessage) === 1) {
        $errorDetail = 'invalid_ssl_response';
    } elseif (preg_match('/(?i)could not receive data from server/', $errorMessage) === 1) {
        $errorDetail = 'receive_failed';
    } elseif (preg_match('/(?i)password authentication failed/', $errorMessage) === 1) {
        $errorDetail = 'authentication';
    } elseif (preg_match('/(?i)SCRAM authentication|no password supplied/', $errorMessage) === 1) {
        $errorDetail = 'authentication_protocol';
    } elseif (preg_match('/(?i)no pg_hba\.conf entry/', $errorMessage) === 1) {
        $errorDetail = 'hba';
    } elseif (preg_match('/(?i)could not translate host name/', $errorMessage) === 1) {
        $errorDetail = 'hostname';
    } elseif (preg_match('/(?i)timeout expired|connection timed out/', $errorMessage) === 1) {
        $errorDetail = 'timeout';
    }

    if ($exception instanceof PDOException
        && is_array($exception->errorInfo ?? null)
        && is_string($exception->errorInfo[0] ?? null)) {
        $errorCode = strtoupper($exception->errorInfo[0]);
    }

    if (preg_match('/^[0-9A-Z]{5}$/D', $errorCode) !== 1) {
        $errorCode = 'NONE';
    }

    echo json_encode([
        'ok' => false,
        'mode' => in_array($mode, [
            'preflight',
            'connectivity',
            'barrier',
            'provider_claim_mismatch',
            'provider_claim_valid',
        ], true) ? $mode : 'invalid',
        'error' => 'worker_failed',
        'class' => (new ReflectionClass($exception))->getShortName(),
        'code' => $errorCode,
        'detail' => $errorDetail,
        'stage' => $workerStage,
    ], JSON_THROW_ON_ERROR);

    exit(1);
}

/**
 * @param  array<string, bool|int|string>  $payload
 */
function emitPayload(array $payload): never
{
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit(0);
}

function registerWorkerPid(string $nonce): void
{
    $registry = getenv('REL4G_WORKER_PID_REGISTRY');
    $environmentNonce = getenv('REL4G_NONCE');
    $pid = getmypid();

    if (preg_match('/^[a-f0-9]{12}$/D', $nonce) !== 1
        || ! is_string($environmentNonce)
        || ! hash_equals($environmentNonce, $nonce)
        || ! is_string($registry)
        || $registry === ''
        || ! is_file($registry)
        || basename($registry) !== 'worker-pids.txt'
        || basename(dirname($registry)) !== 'emaks-pr92-rel4g-wp0a-'.$nonce
        || ! is_int($pid)
        || $pid < 1) {
        throw new RuntimeException('worker_registry_unavailable');
    }

    $entry = $pid.'|'.$nonce.'|TechnicalServiceDecisionRaceWorker.php'.PHP_EOL;

    if (file_put_contents($registry, $entry, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('worker_registry_write_failed');
    }
}

function assertProviderClaimRaceArguments(
    string $nonce,
    string $worker,
    int $paymentId,
    string $raceDirectory,
    string $counterPath,
): void {
    $expectedDirectory = 'emaks-pr92-provider-preclaim-'.$nonce.'-'.$paymentId;
    if (! in_array($worker, ['one', 'two'], true)
        || $paymentId < 1
        || ! is_dir($raceDirectory)
        || basename($raceDirectory) !== $expectedDirectory
        || dirname($counterPath) !== $raceDirectory
        || basename($counterPath) !== 'provider-calls.txt'
        || ! is_file($counterPath)) {
        throw new RuntimeException('provider_claim_race_arguments_invalid');
    }
}

function synchronizeProviderClaimRace(string $raceDirectory, string $worker): void
{
    $readyPath = $raceDirectory.DIRECTORY_SEPARATOR.$worker.'.ready';
    if (file_put_contents($readyPath, (string) getmypid(), LOCK_EX) === false) {
        throw new RuntimeException('provider_claim_race_ready_write_failed');
    }

    $deadline = microtime(true) + 15;
    do {
        $ready = glob($raceDirectory.DIRECTORY_SEPARATOR.'*.ready');
        if (is_array($ready) && count($ready) === 2) {
            return;
        }
        usleep(25_000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException('provider_claim_race_barrier_timeout');
}

function incrementProviderCallCounter(string $counterPath): void
{
    $handle = fopen($counterPath, 'c+');
    if ($handle === false || ! flock($handle, LOCK_EX)) {
        throw new RuntimeException('provider_claim_counter_lock_failed');
    }

    try {
        rewind($handle);
        $current = trim((string) stream_get_contents($handle));
        $next = (ctype_digit($current) ? (int) $current : 0) + 1;
        rewind($handle);
        if (! ftruncate($handle, 0) || fwrite($handle, (string) $next) === false) {
            throw new RuntimeException('provider_claim_counter_write_failed');
        }
        fflush($handle);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function installOutboundGuards(): void
{
    Http::fake([]);
    Http::preventStrayRequests();
    Queue::fake();
    Bus::fake();
    Mail::fake();
    Notification::fake();
}

function assertNoExternalEffects(): void
{
    Http::assertNothingSent();
    Queue::assertNothingPushed();
    Bus::assertNothingDispatched();
    Mail::assertNothingSent();
    Notification::assertNothingSent();
}
