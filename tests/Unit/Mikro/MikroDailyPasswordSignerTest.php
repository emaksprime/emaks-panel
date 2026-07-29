<?php

namespace Tests\Unit\Mikro;

use App\Services\Mikro\MikroDailyPasswordSigner;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MikroDailyPasswordSignerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_signer_uses_exact_date_space_password_utf8_and_lowercase_md5(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-29 23:55:00', 'Europe/Istanbul'));
        $plainPassword = 'Sadece-Test-Şifre';

        $signature = app(MikroDailyPasswordSigner::class)->sign($plainPassword, null, 'Europe/Istanbul');

        $this->assertSame(md5('2026-07-29 '.$plainPassword), $signature);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $signature);
        $this->assertStringNotContainsString($plainPassword, $signature);
    }

    public function test_signer_uses_explicit_server_timezone_and_recalculates_after_midnight(): void
    {
        $signer = app(MikroDailyPasswordSigner::class);
        $instant = CarbonImmutable::parse('2026-07-29 21:30:00', 'UTC');

        $istanbul = $signer->sign('test-password', $instant, 'Europe/Istanbul');
        $utc = $signer->sign('test-password', $instant, 'UTC');

        $this->assertSame(md5('2026-07-30 test-password'), $istanbul);
        $this->assertSame(md5('2026-07-29 test-password'), $utc);
        $this->assertNotSame($istanbul, $utc);
    }

    public function test_missing_password_or_invalid_timezone_fails_closed(): void
    {
        $signer = app(MikroDailyPasswordSigner::class);

        foreach ([['', 'Europe/Istanbul', 'MIKRO_PASSWORD_MISSING'], ['secret', 'Not/A-Timezone', 'MIKRO_SERVER_TIMEZONE_INVALID']] as [$password, $timezone, $message]) {
            try {
                $signer->sign($password, null, $timezone);
                $this->fail('Signer should fail closed.');
            } catch (DomainException $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        }
    }
}
