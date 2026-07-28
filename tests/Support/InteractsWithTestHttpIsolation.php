<?php

namespace Tests\Support;

use App\Models\DataSource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Assert;

trait InteractsWithTestHttpIsolation
{
    protected const TEST_PANEL_DATA_SOURCE_GATEWAY_URL = 'https://n8n-gateway.example.test/webhook/panel-data-source-run-v1';

    protected const TEST_GOOGLE_GEOCODING_PATTERN = 'https://maps.googleapis.com/maps/api/geocode/json*';

    protected const TEST_GOOGLE_ROUTES_URL = 'https://routes.googleapis.com/directions/v2:computeRoutes';

    /** @var array{patterns: list<string>, count: int}|null */
    private ?array $testHttpExpectation = null;

    private mixed $previousPanelGatewayUrl = null;

    private bool $panelGatewayUrlOverridden = false;

    protected function setUpInteractsWithTestHttpIsolation(): void
    {
        $this->testHttpExpectation = null;
        Http::preventStrayRequests();
    }

    protected function tearDownInteractsWithTestHttpIsolation(): void
    {
        $this->assertCurrentTestHttpExpectation();

        if ($this->panelGatewayUrlOverridden) {
            config()->set('panel.n8n_gateway_url', $this->previousPanelGatewayUrl);
            $this->panelGatewayUrlOverridden = false;
        }
    }

    protected function useTestPanelDataSourceGateway(): void
    {
        if (! $this->panelGatewayUrlOverridden) {
            $this->previousPanelGatewayUrl = config('panel.n8n_gateway_url');
            $this->panelGatewayUrlOverridden = true;
        }

        config()->set('panel.n8n_gateway_url', self::TEST_PANEL_DATA_SOURCE_GATEWAY_URL);

        DataSource::query()->get()->each(function (DataSource $source): void {
            $connectionMeta = is_array($source->connection_meta) ? $source->connection_meta : [];
            $connectionMeta['endpoint_url'] = self::TEST_PANEL_DATA_SOURCE_GATEWAY_URL;
            $source->forceFill(['connection_meta' => $connectionMeta])->save();
        });
    }

    /**
     * @param  array<string, mixed>  $responses
     */
    protected function fakeIsolatedHttp(array $responses, int $expectedRequests = 1): void
    {
        if ($expectedRequests < 0) {
            throw new LogicException('Expected HTTP request count cannot be negative.');
        }

        $this->assertCurrentTestHttpExpectation();

        $patterns = array_keys($responses);

        foreach ($patterns as $pattern) {
            if (! in_array($pattern, $this->allowedTestHttpPatterns(), true)) {
                throw new LogicException("Test HTTP fake pattern is not allowlisted: {$pattern}");
            }
        }

        Http::fake($responses);
        $this->testHttpExpectation = [
            'patterns' => array_values($patterns),
            'count' => $expectedRequests,
        ];
    }

    private function assertCurrentTestHttpExpectation(): void
    {
        if ($this->testHttpExpectation === null) {
            return;
        }

        $expectation = $this->testHttpExpectation;
        $this->testHttpExpectation = null;
        $recorded = Http::recorded();

        Assert::assertCount($expectation['count'], $recorded, 'Unexpected test HTTP request count.');

        foreach ($recorded as [$request]) {
            $pattern = collect($expectation['patterns'])
                ->first(fn (string $candidate): bool => Str::is($candidate, $request->url()));

            Assert::assertNotNull($pattern, 'Recorded HTTP request did not match an exact registered fake.');
            $this->assertRegisteredRequestContract($request, (string) $pattern);
        }
    }

    /** @return list<string> */
    private function allowedTestHttpPatterns(): array
    {
        return [
            self::TEST_PANEL_DATA_SOURCE_GATEWAY_URL,
            self::TEST_GOOGLE_GEOCODING_PATTERN,
            self::TEST_GOOGLE_ROUTES_URL,
        ];
    }

    private function assertRegisteredRequestContract(Request $request, string $pattern): void
    {
        if ($pattern === self::TEST_PANEL_DATA_SOURCE_GATEWAY_URL) {
            Assert::assertSame('POST', $request->method());
            Assert::assertTrue($request->hasHeader('Accept', 'application/json'));
            Assert::assertTrue($request->hasHeader('Content-Type', 'application/json'));
            Assert::assertIsString($request['source_code'] ?? null);
            Assert::assertIsArray($request['params'] ?? null);

            return;
        }

        if ($pattern === self::TEST_GOOGLE_GEOCODING_PATTERN) {
            Assert::assertSame('GET', $request->method());
            Assert::assertNotSame('', trim((string) ($request['address'] ?? '')));
            Assert::assertNotSame('', trim((string) ($request['key'] ?? '')));
            Assert::assertSame('tr', $request['language'] ?? null);
            Assert::assertSame('tr', $request['region'] ?? null);

            return;
        }

        Assert::assertSame(self::TEST_GOOGLE_ROUTES_URL, $request->url());
        Assert::assertSame('POST', $request->method());
        Assert::assertTrue($request->hasHeader('X-Goog-Api-Key'));
        Assert::assertTrue($request->hasHeader('X-Goog-FieldMask', 'routes.distanceMeters,routes.duration'));
        Assert::assertTrue($request->hasHeader('Content-Type', 'application/json'));
        Assert::assertIsArray($request['origin'] ?? null);
        Assert::assertIsArray($request['destination'] ?? null);
        Assert::assertSame('DRIVE', $request['travelMode'] ?? null);
        Assert::assertSame('TRAFFIC_UNAWARE', $request['routingPreference'] ?? null);
        Assert::assertSame('tr-TR', $request['languageCode'] ?? null);
        Assert::assertSame('METRIC', $request['units'] ?? null);
    }
}
