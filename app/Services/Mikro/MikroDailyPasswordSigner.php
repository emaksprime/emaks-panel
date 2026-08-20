<?php

namespace App\Services\Mikro;

use Carbon\CarbonImmutable;
use DateTimeZone;
use DomainException;

class MikroDailyPasswordSigner
{
    public function sign(string $plainPassword, ?CarbonImmutable $now = null, ?string $timezone = null): string
    {
        if ($plainPassword === '') {
            throw new DomainException('MIKRO_PASSWORD_MISSING');
        }

        $timezone = trim((string) ($timezone ?: config('services.mikro_api.server_timezone')));
        if ($timezone === '') {
            throw new DomainException('MIKRO_SERVER_TIMEZONE_MISSING');
        }

        try {
            $zone = new DateTimeZone($timezone);
        } catch (\Throwable) {
            throw new DomainException('MIKRO_SERVER_TIMEZONE_INVALID');
        }

        $date = ($now ?? CarbonImmutable::now($zone))->setTimezone($zone)->format('Y-m-d');

        return md5($date.' '.$plainPassword);
    }
}
