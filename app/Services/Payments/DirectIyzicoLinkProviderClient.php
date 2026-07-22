<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

class DirectIyzicoLinkProviderClient implements PaymentProviderGatewayClient
{
    public const SANDBOX_BASE_URL = 'https://sandbox-api.iyzipay.com';
    public const LIVE_BASE_URL = 'https://api.iyzipay.com';

    public function __construct(
        private readonly TechnicalServicePaymentProviderCredentialService $credentials,
        private readonly IyzicoIyzwsV2Signer $signer,
        private readonly IyzicoLinkRequestFactory $requestFactory,
        private readonly IyzicoLinkResponseNormalizer $normalizer,
    ) {}

    public function send(PaymentProviderGatewayRequest $request): PaymentProviderGatewayResponse
    {
        $payload = $request->toArray();
        $operation = $request->operation();

        if ((bool) ($payload['dry_run'] ?? false) || (bool) ($payload['no_send'] ?? false)) {
            return $this->normalizer->noSend($payload, $operation);
        }

        $mode = $this->mode($payload['mode'] ?? 'sandbox');
        if ($mode === 'live' && ! (bool) config('payments.iyzico.live_send_approved', false)) {
            throw new TechnicalServicePaymentProviderDisabledException(
                TechnicalServicePaymentProviderSettingsService::LIVE_SEND_APPROVAL_MESSAGE
            );
        }

        $credential = $this->credentials->decryptForInternalUse($mode);
        if ($credential === null) {
            throw new TechnicalServicePaymentProviderDisabledException(
                TechnicalServicePaymentProviderModeResolver::NOT_READY_MESSAGE
            );
        }

        [$method, $path, $body] = $this->operationRequest($operation, $payload);
        $bodyString = $body === null
            ? ''
            : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $headers = $this->signer->headers(
            $credential['api_key'],
            $credential['secret_key'],
            $path,
            $bodyString,
        );

        try {
            $response = Http::baseUrl($this->baseUrl($mode))
                ->timeout((int) config('payments.iyzico.timeout', 10))
                ->connectTimeout((int) config('payments.iyzico.connect_timeout', 3))
                ->acceptJson()
                ->withHeaders($headers)
                ->withBody($bodyString, 'application/json')
                ->send($method, $path);
        } catch (ConnectionException $exception) {
            throw new TechnicalServicePaymentProviderClientException('Iyzico bağlantısı kurulamadı. Lütfen daha sonra tekrar deneyin.', previous: $exception);
        } catch (Throwable $exception) {
            throw new TechnicalServicePaymentProviderClientException('Iyzico ödeme sağlayıcısı yanıtı alınamadı.', previous: $exception);
        }

        $providerPayload = $response->json();
        if (! is_array($providerPayload)) {
            $providerPayload = [
                'status' => $response->successful() ? 'success' : 'failure',
                'errorMessage' => $response->successful()
                    ? null
                    : 'Iyzico ödeme sağlayıcısı geçerli JSON yanıtı döndürmedi.',
            ];
        }

        if ($response->failed()) {
            try {
                $response->throw();
            } catch (RequestException) {
                // Normalize the provider payload below instead of leaking raw HTTP exception context.
            }
        }

        return $this->normalizer->normalize(
            $payload,
            $providerPayload,
            $operation,
            $response->status(),
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0:string,1:string,2:array<string,mixed>|null}
     */
    private function operationRequest(string $operation, array $payload): array
    {
        $token = $this->requestFactory->providerToken($payload);

        return match ($operation) {
            PaymentProviderGatewayRequest::OPERATION_CREATE_LINK => [
                'POST',
                '/v2/iyzilink/products',
                $this->requestFactory->linkBody($payload),
            ],
            PaymentProviderGatewayRequest::OPERATION_UPDATE_LINK => [
                'PUT',
                '/v2/iyzilink/products/'.$this->requiredToken($token),
                $this->requestFactory->linkBody($payload),
            ],
            PaymentProviderGatewayRequest::OPERATION_GET_LINK,
            PaymentProviderGatewayRequest::OPERATION_SYNC_STATUS => [
                'GET',
                '/v2/iyzilink/products/'.$this->requiredToken($token),
                null,
            ],
            PaymentProviderGatewayRequest::OPERATION_CANCEL_LINK => [
                'PATCH',
                '/v2/iyzilink/products/'.$this->requiredToken($token).'/status/PASSIVE',
                null,
            ],
            PaymentProviderGatewayRequest::OPERATION_LIST_LINKS => [
                'GET',
                '/v2/iyzilink/products',
                null,
            ],
            default => throw new IyzicoProviderException('Desteklenmeyen Iyzico link operasyonu.'),
        };
    }

    private function requiredToken(?string $token): string
    {
        if ($token === null) {
            throw new TechnicalServicePaymentProviderClientException('Iyzico link referansı bulunamadı.');
        }

        return rawurlencode($token);
    }

    private function mode(mixed $mode): string
    {
        return strtolower(trim((string) $mode)) === 'live' ? 'live' : 'sandbox';
    }

    private function baseUrl(string $mode): string
    {
        return $mode === 'live'
            ? self::LIVE_BASE_URL
            : self::SANDBOX_BASE_URL;
    }
}
