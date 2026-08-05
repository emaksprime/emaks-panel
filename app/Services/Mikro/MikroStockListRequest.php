<?php

namespace App\Services\Mikro;

use InvalidArgumentException;

final class MikroStockListRequest
{
    public const MAX_CANARY_ROWS = 5;

    /**
     * Internal bounded-read intent only. This is not a provider wire payload.
     */
    public function __construct(
        public readonly int $size,
        public readonly ?string $stockCode = null,
        public readonly ?int $dateType = null,
        public readonly ?string $firstDate = null,
        public readonly ?string $lastDate = null,
        public readonly ?string $sort = null,
        public readonly ?int $index = null,
    ) {
        if ($dateType !== null && $dateType < 0) {
            throw new InvalidArgumentException('Date type must not be negative.');
        }

        if ($size < 1 || $size > self::MAX_CANARY_ROWS) {
            throw new InvalidArgumentException('Canary row limit must be between 1 and 5.');
        }

        if (($index !== null && $index < 0) || ($sort !== null && trim($sort) === '')) {
            throw new InvalidArgumentException('Pagination fields are invalid.');
        }
    }

}
