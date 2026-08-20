<?php

namespace Tests\Unit\TechnicalService;

use App\Models\SupportActivationCode;
use App\Services\TechnicalService\SerialActivationCodeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SerialActivationCodeResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_activation_code_from_clean_serial(): void
    {
        SupportActivationCode::query()->updateOrCreate(
            ['code' => 'UNIT-W720FWS03E250621A01809-275023'],
            [
                'stock_code' => 'UNIT-STOCK',
                'stock_name' => 'Unit Test Kilit',
                'serial_number' => 'W720FWS03E250621A01809-275023',
                'serial_number_clean' => 'W720FWS03E250621A01809',
                'search_code' => 'A01809',
                'activation_code' => '275023',
                'metadata' => [],
                'search_text' => 'W720FWS03E250621A01809 275023 A01809',
                'is_active' => true,
            ],
        );

        $this->assertSame(
            '275023',
            app(SerialActivationCodeResolver::class)->resolve('W720FWS03E250621A01809'),
        );
    }

    public function test_search_code_is_not_used_as_activation_code(): void
    {
        SupportActivationCode::query()->create([
            'code' => 'UNIT-NO-ACTIVATION',
            'stock_code' => 'UNIT-STOCK',
            'stock_name' => 'Unit Test Kilit',
            'serial_number' => 'UNITSERIALNOACTIVATION',
            'serial_number_clean' => 'UNITSERIALNOACTIVATION',
            'search_code' => 'A00001',
            'activation_code' => null,
            'metadata' => [],
            'search_text' => 'UNITSERIALNOACTIVATION A00001',
            'is_active' => true,
        ]);

        $this->assertNull(app(SerialActivationCodeResolver::class)->resolve('UNITSERIALNOACTIVATION'));
    }

    public function test_missing_activation_returns_null(): void
    {
        $this->assertNull(app(SerialActivationCodeResolver::class)->resolve('MISSING-SERIAL-0001'));
    }
}
