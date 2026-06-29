<?php

namespace App\Services\Payments;

use App\Models\PaymentProviderCredential;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class TechnicalServicePaymentProviderCredentialService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @return array<string, mixed>
     */
    public function credentialPayload(string $mode): array
    {
        $credential = $this->credentialForMode($mode);
        $ready = $credential?->isConfigured() === true;

        return [
            'ready' => $ready,
            'source' => 'encrypted_storage',
            'source_label' => 'Encrypted admin storage',
            'api_key_status' => $ready ? 'API bilgileri tanımlı' : 'API bilgileri tanımlı değil',
            'secret_key_status' => $ready ? 'Secret bilgisi tanımlı' : 'Secret bilgisi tanımlı değil',
            'masked_api_key' => $ready ? $credential?->api_key_mask : null,
            'masked_secret_key' => $ready ? $credential?->secret_key_mask : null,
            'entry_supported' => true,
            'entry_status' => $ready ? 'API bilgileri tanımlı' : 'API bilgileri tanımlı değil',
            'entry_message' => 'API bilgileri encrypted saklanır; kayıttan sonra tam değer gösterilmez.',
            'last_updated_at' => $credential?->updated_at?->toIso8601String(),
            'last_verified_at' => $credential?->last_verified_at?->toIso8601String(),
            'last_verification_status' => $credential?->last_verification_status,
            'last_verification_message' => $credential?->last_verification_message,
        ];
    }

    public function credentialsReady(string $mode): bool
    {
        return $this->credentialForMode($mode)?->isConfigured() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function saveIyzicoCredentials(string $mode, string $apiKey, string $secretKey, ?User $actor = null, ?Request $request = null): array
    {
        $mode = $this->normalizeMode($mode);
        $apiKey = trim($apiKey);
        $secretKey = trim($secretKey);

        $credential = PaymentProviderCredential::query()->firstOrNew([
            'scope' => PaymentProviderCredential::SCOPE_TECHNICAL_SERVICE,
            'provider' => PaymentProviderCredential::PROVIDER_IYZICO,
            'mode' => $mode,
        ]);

        if (! $credential->exists && $actor instanceof User) {
            $credential->created_by = $actor->id;
        }

        $credential->forceFill([
            'api_key_encrypted' => $apiKey,
            'secret_key_encrypted' => $secretKey,
            'api_key_mask' => $this->maskApiKey($apiKey),
            'secret_key_mask' => $this->maskSecretKey(),
            'credentials_status' => PaymentProviderCredential::STATUS_CONFIGURED,
            'updated_by' => $actor?->id,
            'metadata' => [
                'source' => 'technical_service_admin',
                'updated_by_name' => $actor?->full_name,
            ],
        ])->save();

        $this->auditLogger->log($actor, 'technical_service.payment_provider.credentials_saved', [
            'scope' => PaymentProviderCredential::SCOPE_TECHNICAL_SERVICE,
            'provider' => PaymentProviderCredential::PROVIDER_IYZICO,
            'mode' => $mode,
            'api_key_mask' => $credential->api_key_mask,
            'secret_key_mask' => $credential->secret_key_mask,
            'credentials_status' => $credential->credentials_status,
        ], $request);

        return $this->credentialPayload($mode);
    }

    /**
     * @return array<string, mixed>
     */
    public function clearIyzicoCredentials(string $mode, ?User $actor = null, ?Request $request = null): array
    {
        $mode = $this->normalizeMode($mode);
        $credential = PaymentProviderCredential::query()->firstOrNew([
            'scope' => PaymentProviderCredential::SCOPE_TECHNICAL_SERVICE,
            'provider' => PaymentProviderCredential::PROVIDER_IYZICO,
            'mode' => $mode,
        ]);

        if (! $credential->exists && $actor instanceof User) {
            $credential->created_by = $actor->id;
        }

        $credential->forceFill([
            'api_key_encrypted' => null,
            'secret_key_encrypted' => null,
            'api_key_mask' => null,
            'secret_key_mask' => null,
            'credentials_status' => PaymentProviderCredential::STATUS_MISSING,
            'last_verified_at' => null,
            'last_verification_status' => null,
            'last_verification_message' => null,
            'updated_by' => $actor?->id,
            'metadata' => [
                'source' => 'technical_service_admin',
                'cleared_by_name' => $actor?->full_name,
            ],
        ])->save();

        $this->auditLogger->log($actor, 'technical_service.payment_provider.credentials_cleared', [
            'scope' => PaymentProviderCredential::SCOPE_TECHNICAL_SERVICE,
            'provider' => PaymentProviderCredential::PROVIDER_IYZICO,
            'mode' => $mode,
            'credentials_status' => PaymentProviderCredential::STATUS_MISSING,
        ], $request);

        return $this->credentialPayload($mode);
    }

    /**
     * @return array{api_key: string, secret_key: string}|null
     */
    public function decryptForInternalUse(string $mode): ?array
    {
        $credential = $this->credentialForMode($mode);

        if (! $credential?->isConfigured()) {
            return null;
        }

        return [
            'api_key' => (string) $credential->api_key_encrypted,
            'secret_key' => (string) $credential->secret_key_encrypted,
        ];
    }

    private function credentialForMode(string $mode): ?PaymentProviderCredential
    {
        return PaymentProviderCredential::query()
            ->where('scope', PaymentProviderCredential::SCOPE_TECHNICAL_SERVICE)
            ->where('provider', PaymentProviderCredential::PROVIDER_IYZICO)
            ->where('mode', $this->normalizeMode($mode))
            ->first();
    }

    private function normalizeMode(string $mode): string
    {
        return strtolower(trim($mode)) === PaymentProviderCredential::MODE_LIVE
            ? PaymentProviderCredential::MODE_LIVE
            : PaymentProviderCredential::MODE_SANDBOX;
    }

    private function maskApiKey(string $apiKey): string
    {
        $length = mb_strlen($apiKey);

        if ($length <= 8) {
            return mb_substr($apiKey, 0, 2).'****'.mb_substr($apiKey, -2);
        }

        return mb_substr($apiKey, 0, 4).'****'.mb_substr($apiKey, -4);
    }

    private function maskSecretKey(): string
    {
        return str_repeat('*', 12);
    }
}
