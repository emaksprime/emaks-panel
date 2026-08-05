<?php

namespace App\Services\Mikro;

use InvalidArgumentException;

final class MikroBusinessWriteMeasurement
{
    public function __construct(
        public readonly mixed $value,
        public readonly int $writeCount,
    ) {
        if ($writeCount < 0) {
            throw new InvalidArgumentException('Write count must not be negative.');
        }
    }
}
