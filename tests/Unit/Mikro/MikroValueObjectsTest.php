<?php

namespace Tests\Unit\Mikro;

use App\Services\Mikro\MikroCredentialEnvelope;
use App\Services\Mikro\MikroOperationCatalog;
use App\Services\Mikro\MikroOperationDefinition;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class MikroValueObjectsTest extends TestCase
{
    #[Test]
    public function mikro_secret_is_not_serialized(): void
    {
        $credential = new MikroCredentialEnvelope(
            password: 'fixture-password-not-real',
            apiKey: 'fixture-api-key-not-real',
            token: 'fixture-token-not-real',
        );

        $serialized = json_encode($credential, JSON_THROW_ON_ERROR);
        $debug = print_r($credential, true);

        foreach (['fixture-password-not-real', 'fixture-api-key-not-real', 'fixture-token-not-real'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
            $this->assertStringNotContainsString($secret, $debug);
        }

        $this->assertStringContainsString('configured', $serialized);
    }

    #[Test]
    public function mikro_secret_is_not_logged(): void
    {
        $credential = new MikroCredentialEnvelope(
            password: 'logged-password-must-not-leak',
            apiKey: 'logged-api-key-must-not-leak',
            token: 'logged-token-must-not-leak',
        );
        $handler = new TestHandler;
        $handler->setFormatter(new JsonFormatter);
        $logger = new Logger('mikro-contract-test', [$handler]);

        $logger->info('credential envelope', ['credential' => $credential]);

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $formatted = (string) $records[0]->formatted;

        foreach (['logged-password-must-not-leak', 'logged-api-key-must-not-leak', 'logged-token-must-not-leak'] as $secret) {
            $this->assertStringNotContainsString($secret, $formatted);
        }

        $this->assertStringContainsString('configured', $formatted);
    }

    #[Test]
    public function mikro_operation_catalog_distinguishes_declared_and_verified(): void
    {
        $catalog = new MikroOperationCatalog;
        $summary = $catalog->summary();

        $this->assertSame(32, $summary['declared_read_count']);
        $this->assertSame(11, $summary['declared_write_count']);
        $this->assertSame(0, $summary['contract_verified_count']);
        $this->assertSame(0, $summary['runtime_verified_count']);

        $declaredWrite = $catalog->find('customer.save');
        $blockedDirectRead = $catalog->find(MikroOperationCatalog::STOCK_LIST);

        $this->assertNotNull($declaredWrite);
        $this->assertNotNull($blockedDirectRead);
        $this->assertSame(MikroOperationDefinition::VERIFICATION_DECLARED, $declaredWrite->verification);
        $this->assertSame(MikroOperationDefinition::VERIFICATION_BLOCKED, $blockedDirectRead->verification);
        $this->assertNotSame('', trim($declaredWrite->title));
        $this->assertNotSame('', trim($blockedDirectRead->title));
    }

    #[Test]
    public function mikro_write_capabilities_remain_locked(): void
    {
        $catalog = new MikroOperationCatalog;

        $this->assertCount(11, $catalog->writeOperations());

        foreach ($catalog->writeOperations() as $operation) {
            $this->assertTrue($operation->isWrite());
            $this->assertSame(MikroOperationDefinition::VERIFICATION_DECLARED, $operation->verification);
            $this->assertTrue($operation->requiresWriteGate);
            $this->assertFalse($operation->safeForCanary);
            $this->assertFalse($operation->isRuntimeVerified());
        }
    }

    #[Test]
    public function mikro_real_canary_is_disabled_by_default(): void
    {
        $source = file_get_contents($this->repositoryRoot().'/config/mikro.php');

        $this->assertIsString($source);
        $this->assertMatchesRegularExpression(
            "/'real_canary_enabled'\\s*=>\\s*env\\('MIKRO_REAL_CANARY_ENABLED',\\s*false\\)/",
            $source,
        );
    }

    #[Test]
    public function mikro_client_does_not_reference_n8n(): void
    {
        $this->assertStringNotContainsString('n8n', strtolower($this->productionSource()));
    }

    #[Test]
    public function mikro_client_does_not_reference_mssql(): void
    {
        $this->assertStringNotContainsString('mssql', strtolower($this->productionSource()));
        $this->assertStringNotContainsString('microsoftsql', strtolower($this->productionSource()));
    }

    #[Test]
    public function mikro_client_does_not_reference_query_template(): void
    {
        $source = strtolower($this->productionSource());

        foreach (['query_template', 'allowed_params', 'connection_meta'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }

    #[Test]
    public function mikro_client_does_not_reference_sql_fallback(): void
    {
        $source = $this->productionSource();

        foreach ([
            'SqlVeriOkuV2',
            'SQLSorgu',
            'FIXED_QUERY',
            'panel-data-source-run',
            'hook.emaksprime.com.tr',
            'executeQuery',
        ] as $forbidden) {
            $this->assertStringNotContainsString(strtolower($forbidden), strtolower($source));
        }

        $this->assertDoesNotMatchRegularExpression('/\b(?:INSERT|UPDATE|DELETE|MERGE|TRUNCATE)\b/i', $source);
    }

    private function productionSource(): string
    {
        $root = $this->repositoryRoot();
        $directory = $root.'/app/Services/Mikro';
        $files = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        $files[] = $root.'/config/mikro.php';
        sort($files);

        $source = '';

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            $this->assertIsString($contents, "Unable to read production source [{$file}].");
            $source .= "\n{$contents}";
        }

        return $source;
    }

    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
