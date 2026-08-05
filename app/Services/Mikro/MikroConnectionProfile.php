<?php

namespace App\Services\Mikro;

use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;

final class MikroConnectionProfile implements JsonSerializable
{
    public function __construct(
        public readonly MikroPrivateBaseUrl $baseUrl,
        public readonly string $apiVersion,
        public readonly string $applicationCode,
        public readonly string $applicationName,
        public readonly string $firmCode,
        public readonly string $branchCode,
        public readonly string $terminalCode,
        public readonly int $fiscalYear,
        public readonly string $username,
        public readonly int $timeoutSeconds = 10,
        public readonly string $timezone = 'Europe/Istanbul',
    ) {
        foreach ([
            $apiVersion,
            $applicationCode,
            $applicationName,
            $firmCode,
            $branchCode,
            $terminalCode,
            $username,
        ] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Connection profile fields must not be blank.');
            }
        }

        if ($fiscalYear < 2000 || $fiscalYear > 2200) {
            throw new InvalidArgumentException('Fiscal year is outside the supported range.');
        }

        if ($timeoutSeconds < 1 || $timeoutSeconds > 60) {
            throw new InvalidArgumentException('Timeout must be between 1 and 60 seconds.');
        }

        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('Timezone is invalid.');
        }
    }

    /**
     * @return array<string, int|string>
     */
    public function jsonSerialize(): array
    {
        return [
            'base_url' => $this->baseUrl->value(),
            'api_version' => $this->apiVersion,
            'application_code' => $this->applicationCode,
            'application_name' => $this->applicationName,
            'firm_code' => $this->firmCode,
            'branch_code' => $this->branchCode,
            'terminal_code' => $this->terminalCode,
            'fiscal_year' => $this->fiscalYear,
            'username' => $this->username,
            'timeout_seconds' => $this->timeoutSeconds,
            'timezone' => $this->timezone,
        ];
    }
}
