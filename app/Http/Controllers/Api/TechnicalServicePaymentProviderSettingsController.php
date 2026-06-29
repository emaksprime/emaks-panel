<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payments\TechnicalServicePaymentProviderCredentialService;
use App\Services\Payments\TechnicalServicePaymentProviderSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TechnicalServicePaymentProviderSettingsController extends Controller
{
    public function show(TechnicalServicePaymentProviderSettingsService $settings): JsonResponse
    {
        return response()->json([
            'settings' => $settings->payload(),
        ]);
    }

    public function update(Request $request, TechnicalServicePaymentProviderSettingsService $settings): JsonResponse
    {
        $data = $request->validate([
            'real_provider_enabled' => ['sometimes', 'required', 'boolean'],
            'provider_mode' => ['sometimes', 'required', 'string', 'in:sandbox,live'],
        ]);

        return response()->json([
            'settings' => $settings->update($data),
        ]);
    }

    public function healthCheck(TechnicalServicePaymentProviderSettingsService $settings): JsonResponse
    {
        return response()->json($settings->healthCheckPayload());
    }

    public function saveCredentials(
        Request $request,
        TechnicalServicePaymentProviderCredentialService $credentials,
        TechnicalServicePaymentProviderSettingsService $settings,
    ): JsonResponse {
        $data = $request->validate([
            'mode' => ['required', 'string', 'in:sandbox,live'],
            'api_key' => ['required', 'string', 'min:8', 'max:1000'],
            'secret_key' => ['required', 'string', 'min:8', 'max:2000'],
        ]);

        $credentialPayload = $credentials->saveIyzicoCredentials(
            (string) $data['mode'],
            (string) $data['api_key'],
            (string) $data['secret_key'],
            $request->user(),
            $request,
        );

        return response()->json([
            'credentials' => $credentialPayload,
            'settings' => $settings->payload(),
        ]);
    }

    public function clearCredentials(
        Request $request,
        TechnicalServicePaymentProviderCredentialService $credentials,
        TechnicalServicePaymentProviderSettingsService $settings,
    ): JsonResponse {
        $data = $request->validate([
            'mode' => ['required', 'string', 'in:sandbox,live'],
        ]);

        $credentialPayload = $credentials->clearIyzicoCredentials((string) $data['mode'], $request->user(), $request);

        return response()->json([
            'credentials' => $credentialPayload,
            'settings' => $settings->payload(),
        ]);
    }
}
