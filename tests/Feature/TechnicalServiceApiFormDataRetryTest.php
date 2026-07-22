<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class TechnicalServiceApiFormDataRetryTest extends TestCase
{
    public function test_formdata_419_refresh_retry_once(): void
    {
        $facts = $this->runHarness('formdata_419_refresh_retry_once');

        $this->assertSame(2, $facts['upload_attempts']);
        $this->assertSame(1, $facts['refresh_attempts']);
        $this->assertSame(1, $facts['failed_refresh_upload_attempts']);
        $this->assertSame(1, $facts['failed_refresh_attempts']);
    }

    public function test_upload_retry_does_not_duplicate_row(): void
    {
        $facts = $this->runHarness('upload_retry_does_not_duplicate_row');

        $this->assertSame(2, $facts['upload_attempts']);
        $this->assertSame(1, $facts['refresh_attempts']);
        $this->assertSame(1, $facts['created_rows']);
    }

    public function test_non_419_upload_error_not_retried(): void
    {
        $facts = $this->runHarness('non_419_upload_error_not_retried');

        $this->assertSame(1, $facts['upload_attempts']);
        $this->assertSame(0, $facts['refresh_attempts']);
        $this->assertSame(422, $facts['error_status']);
    }

    public function test_retry_failure_returns_real_error(): void
    {
        $facts = $this->runHarness('retry_failure_returns_real_error');

        $this->assertSame(2, $facts['upload_attempts']);
        $this->assertSame(1, $facts['refresh_attempts']);
        $this->assertSame(422, $facts['error_status']);
    }

    /**
     * @return array<string, int>
     */
    private function runHarness(string $scenario): array
    {
        $projectRoot = dirname(__DIR__, 2);
        $harness = $projectRoot.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'api-formdata-retry-harness.mjs';
        $process = new Process([
            getenv('NODE_BINARY') ?: 'node',
            $harness,
            $scenario,
        ], $projectRoot);
        $process->setTimeout(30);
        $process->run();

        $diagnostic = trim($process->getErrorOutput().PHP_EOL.$process->getOutput());

        $this->assertSame(
            0,
            $process->getExitCode(),
            "Node FormData retry harness failed for {$scenario}:{$diagnostic}",
        );

        $payload = json_decode(trim($process->getOutput()), true);

        $this->assertIsArray($payload, "Harness returned invalid JSON for {$scenario}: {$diagnostic}");
        $this->assertTrue($payload['ok'] ?? false, "Harness did not pass for {$scenario}: {$diagnostic}");
        $this->assertSame($scenario, $payload['scenario'] ?? null);
        $this->assertIsArray($payload['facts'] ?? null);

        return $payload['facts'];
    }
}
