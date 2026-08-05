<?php

namespace App\Services\Mikro;

final class MikroCanaryDecision
{
    public function __construct(
        public readonly bool $allowed,
        public readonly string $errorClass,
        public readonly string $reason,
    ) {}

    public static function allow(): self
    {
        return new self(true, MikroErrorClass::NONE, 'CANARY_ALLOWED');
    }

    public static function deny(string $errorClass, string $reason): self
    {
        return new self(false, $errorClass, $reason);
    }
}
