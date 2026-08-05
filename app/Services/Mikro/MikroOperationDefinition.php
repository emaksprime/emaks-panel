<?php

namespace App\Services\Mikro;

use InvalidArgumentException;

final class MikroOperationDefinition
{
    public const CLASSIFICATION_READ = 'READ';

    public const CLASSIFICATION_WRITE = 'WRITE';

    public const VERIFICATION_DECLARED = 'DECLARED';

    public const VERIFICATION_CONTRACT_VERIFIED = 'CONTRACT_VERIFIED';

    public const VERIFICATION_RUNTIME_VERIFIED = 'RUNTIME_VERIFIED';

    public const VERIFICATION_BLOCKED = 'BLOCKED';

    public const VERIFICATION_LEGACY_DISABLED = 'LEGACY_DISABLED';

    public function __construct(
        public readonly string $code,
        public readonly string $title,
        public readonly string $classification,
        public readonly string $verification,
        public readonly ?string $method = null,
        public readonly ?string $endpoint = null,
        public readonly ?int $maxRows = null,
        public readonly bool $safeForCanary = false,
        public readonly bool $requiresWriteGate = false,
    ) {
        if (trim($code) === '' || trim($title) === '') {
            throw new InvalidArgumentException('Operation code and title must not be blank.');
        }

        if (! in_array($classification, [self::CLASSIFICATION_READ, self::CLASSIFICATION_WRITE], true)) {
            throw new InvalidArgumentException('Unsupported operation classification.');
        }

        if (! in_array($verification, [
            self::VERIFICATION_DECLARED,
            self::VERIFICATION_CONTRACT_VERIFIED,
            self::VERIFICATION_RUNTIME_VERIFIED,
            self::VERIFICATION_BLOCKED,
            self::VERIFICATION_LEGACY_DISABLED,
        ], true)) {
            throw new InvalidArgumentException('Unsupported operation verification state.');
        }

        if ($method !== null && ! in_array($method, ['GET', 'POST'], true)) {
            throw new InvalidArgumentException('Unsupported operation method.');
        }

        if ($endpoint !== null && (! str_starts_with($endpoint, '/') || str_contains($endpoint, '..'))) {
            throw new InvalidArgumentException('Operation path must be an absolute provider path.');
        }

        if ($maxRows !== null && $maxRows < 1) {
            throw new InvalidArgumentException('Operation row limit must be positive.');
        }

        if ($classification === self::CLASSIFICATION_WRITE && ! $requiresWriteGate) {
            throw new InvalidArgumentException('Every write operation must require the write gate.');
        }
    }

    public function isRead(): bool
    {
        return $this->classification === self::CLASSIFICATION_READ;
    }

    public function isWrite(): bool
    {
        return $this->classification === self::CLASSIFICATION_WRITE;
    }

    public function isContractVerified(): bool
    {
        return $this->verification === self::VERIFICATION_CONTRACT_VERIFIED;
    }

    public function isRuntimeVerified(): bool
    {
        return $this->verification === self::VERIFICATION_RUNTIME_VERIFIED;
    }
}
