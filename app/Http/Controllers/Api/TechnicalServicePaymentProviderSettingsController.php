<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payments\TechnicalServiceMailTransportNotReadyException;
use App\Services\Payments\TechnicalServiceMailTransportSettingsService;
use App\Services\Payments\TechnicalServicePaymentProviderCredentialService;
use App\Services\Payments\TechnicalServicePaymentProviderSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

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
            'payment_notification_enabled' => ['sometimes', 'required', 'boolean'],
            'payment_notification_recipients' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'company_recipient' => ['sometimes', 'array'],
            'company_recipient.company_title' => ['nullable', 'string', 'max:255'],
            'company_recipient.tax_office' => ['nullable', 'string', 'max:120'],
            'company_recipient.tax_number' => ['nullable', 'string', 'max:64'],
            'company_recipient.trade_registry_no' => ['nullable', 'string', 'max:64'],
            'company_recipient.company_address' => ['nullable', 'string', 'max:1000'],
            'company_recipient.company_phone' => ['nullable', 'string', 'max:64'],
            'company_recipient.company_email' => ['nullable', 'email', 'max:255'],
            'company_recipient.iban_try' => ['nullable', 'string', 'max:64'],
            'company_recipient.iban_usd' => ['nullable', 'string', 'max:64'],
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

    public function mailSettings(TechnicalServiceMailTransportSettingsService $mailSettings): JsonResponse
    {
        return response()->json([
            'mail_transport_settings' => $mailSettings->payload(),
        ]);
    }

    public function saveOutgoingMailSettings(Request $request, TechnicalServiceMailTransportSettingsService $mailSettings): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'host' => ['nullable', 'required_if:enabled,true', 'string', 'max:255'],
            'port' => ['nullable', 'required_if:enabled,true', 'integer', 'between:1,65535'],
            'encryption' => ['required', 'string', Rule::in(['tls', 'ssl', 'none'])],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:2000'],
            'from_address' => ['nullable', 'required_if:enabled,true', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json([
            'mail_transport_settings' => $mailSettings->saveOutgoing($data, $request->user(), $request),
        ]);
    }

    public function clearOutgoingMailSettings(Request $request, TechnicalServiceMailTransportSettingsService $mailSettings): JsonResponse
    {
        return response()->json([
            'mail_transport_settings' => $mailSettings->clearOutgoing($request->user(), $request),
        ]);
    }

    public function sendOutgoingTestMail(Request $request, TechnicalServiceMailTransportSettingsService $mailSettings): JsonResponse
    {
        $data = $request->validate([
            'recipient' => ['required', 'email', 'max:255'],
        ]);

        try {
            return response()->json([
                'mail_transport_settings' => $mailSettings->sendTestMail((string) $data['recipient']),
                'message' => 'Test mail gönderildi.',
            ]);
        } catch (TechnicalServiceMailTransportNotReadyException $exception) {
            return response()->json([
                'mail_transport_settings' => $mailSettings->payload(),
                'message' => $exception->getMessage(),
            ], 422);
        } catch (Throwable $exception) {
            return response()->json([
                'mail_transport_settings' => $mailSettings->payload(),
                'message' => 'Test mail gönderilemedi.',
            ], 422);
        }
    }

    public function saveIncomingMailSettings(Request $request, TechnicalServiceMailTransportSettingsService $mailSettings): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'protocol' => ['required', 'string', Rule::in(['imap', 'pop3'])],
            'host' => ['nullable', 'required_if:enabled,true', 'string', 'max:255'],
            'port' => ['nullable', 'required_if:enabled,true', 'integer', 'between:1,65535'],
            'encryption' => ['required', 'string', Rule::in(['tls', 'ssl', 'none'])],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:2000'],
            'mailbox' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json([
            'mail_transport_settings' => $mailSettings->saveIncoming($data, $request->user(), $request),
        ]);
    }

    public function clearIncomingMailSettings(Request $request, TechnicalServiceMailTransportSettingsService $mailSettings): JsonResponse
    {
        return response()->json([
            'mail_transport_settings' => $mailSettings->clearIncoming($request->user(), $request),
        ]);
    }

    public function testIncomingMailSettings(TechnicalServiceMailTransportSettingsService $mailSettings): JsonResponse
    {
        try {
            return response()->json([
                'mail_transport_settings' => $mailSettings->testIncomingConnection(),
                'message' => 'Gelen kutu bağlantı testi tamamlandı.',
            ]);
        } catch (Throwable) {
            return response()->json([
                'mail_transport_settings' => $mailSettings->payload(),
                'message' => 'Gelen kutu bağlantı testi başarısız.',
            ], 422);
        }
    }
}
