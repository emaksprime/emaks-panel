<?php

namespace Tests\Unit\Mikro;

use App\Services\Mikro\MikroRuntimeState;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MikroRuntimeStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.default', 'array');
        Cache::flush();
        Carbon::setTestNow('2026-07-29 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();
        parent::tearDown();
    }

    public function test_circuit_opens_after_three_failures_and_isolated_operation_remains_closed(): void
    {
        $state = app(MikroRuntimeState::class);
        $origin = 'https://mikro-api.example.test';

        $state->recordTransientFailure($origin, 'customer.list');
        $state->recordTransientFailure($origin, 'customer.list');
        $this->assertSame('CLOSED', $state->circuit($origin, 'customer.list')['circuit_state']);

        $state->recordTransientFailure($origin, 'customer.list');

        $this->assertSame('OPEN', $state->circuit($origin, 'customer.list')['circuit_state']);
        $this->assertFalse($state->beforeRequest($origin, 'customer.list')['allowed']);
        $this->assertTrue($state->beforeRequest($origin, 'stock.list')['allowed']);
    }

    public function test_half_open_allows_one_probe_and_success_resets_the_circuit(): void
    {
        $state = app(MikroRuntimeState::class);
        $origin = 'https://mikro-api.example.test';
        foreach (range(1, 3) as $_) {
            $state->recordTransientFailure($origin, 'customer.list');
        }

        Carbon::setTestNow(now()->addSeconds(31));

        $this->assertSame(['allowed' => true, 'circuit_state' => 'HALF_OPEN'], $state->beforeRequest($origin, 'customer.list'));
        $this->assertSame(['allowed' => false, 'circuit_state' => 'HALF_OPEN'], $state->beforeRequest($origin, 'customer.list'));

        $state->recordSuccess($origin, 'customer.list');
        $this->assertSame('CLOSED', $state->circuit($origin, 'customer.list')['circuit_state']);
        $this->assertTrue($state->beforeRequest($origin, 'customer.list')['allowed']);
    }

    public function test_last_good_snapshot_is_filter_scoped_and_explicitly_sanitized(): void
    {
        $state = app(MikroRuntimeState::class);
        $filters = ['customer_code' => 'TEST-001', 'limit' => 10];
        $data = [['customer_code' => 'TEST-001', 'balance' => 42.50]];

        $state->storeLastGood('customer.balance', $filters, $data, 'mikro', '2026-07-29T12:00:00+03:00');
        $snapshot = $state->lastGood('customer.balance', ['limit' => 10, 'customer_code' => 'TEST-001']);

        $this->assertSame($data, $snapshot['data']);
        $this->assertSame('mikro', $snapshot['source']);
        $this->assertSame($state->filterFingerprint($filters), $snapshot['filter_fingerprint']);
        $this->assertNull($state->lastGood('customer.balance', ['customer_code' => 'OTHER', 'limit' => 10]));
        $this->assertArrayNotHasKey('request', $snapshot);
        $this->assertArrayNotHasKey('response', $snapshot);
        $this->assertArrayNotHasKey('credentials', $snapshot);
    }
}
