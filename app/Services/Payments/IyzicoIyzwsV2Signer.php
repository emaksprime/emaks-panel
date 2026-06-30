<?php

namespace App\Services\Payments;

use Illuminate\Support\Str;

class IyzicoIyzwsV2Signer
{
    /**
     * @return array{Authorization:string,x-iyzi-rnd:string}
     */
    public function headers(string $apiKey, string $secretKey, string $uriPath, string $requestBodyString): array
    {
        $randomKey = $this->randomKey();
        $signature = hash_hmac('sha256', $randomKey.$uriPath.$requestBodyString, $secretKey);
        $authorizationPayload = sprintf(
            'apiKey:%s&randomKey:%s&signature:%s',
            $apiKey,
            $randomKey,
            $signature,
        );

        return [
            'Authorization' => 'IYZWSv2 '.base64_encode($authorizationPayload),
            'x-iyzi-rnd' => $randomKey,
        ];
    }

    private function randomKey(): string
    {
        return (string) now()->getTimestampMs().Str::random(16);
    }
}
