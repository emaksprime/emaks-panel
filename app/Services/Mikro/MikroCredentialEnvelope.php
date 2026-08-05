<?php

namespace App\Services\Mikro;

use JsonSerializable;
use SensitiveParameter;

final class MikroCredentialEnvelope implements JsonSerializable
{
    private readonly ?string $password;

    private readonly ?string $apiKey;

    private readonly ?string $token;

    public function __construct(
        #[SensitiveParameter] ?string $password = null,
        #[SensitiveParameter] ?string $apiKey = null,
        #[SensitiveParameter] ?string $token = null,
    ) {
        $this->password = $this->normalize($password);
        $this->apiKey = $this->normalize($apiKey);
        $this->token = $this->normalize($token);
    }

    public function configured(): bool
    {
        return $this->password !== null || $this->apiKey !== null || $this->token !== null;
    }

    public function password(): ?string
    {
        return $this->password;
    }

    public function apiKey(): ?string
    {
        return $this->apiKey;
    }

    public function token(): ?string
    {
        return $this->token;
    }

    /**
     * @return array{configured: bool, password_configured: bool, api_key_configured: bool, token_configured: bool}
     */
    public function jsonSerialize(): array
    {
        return [
            'configured' => $this->configured(),
            'password_configured' => $this->password !== null,
            'api_key_configured' => $this->apiKey !== null,
            'token_configured' => $this->token !== null,
        ];
    }

    /**
     * @return array{configured: bool, password: string, api_key: string, token: string}
     */
    public function __debugInfo(): array
    {
        return [
            'configured' => $this->configured(),
            'password' => '[REDACTED]',
            'api_key' => '[REDACTED]',
            'token' => '[REDACTED]',
        ];
    }

    private function normalize(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
