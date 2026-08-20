<?php

namespace App\Services\Messaging;

use App\Models\TechnicalServiceMessageDispatch;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class TechnicalServiceTemplateTestSendService
{
    public function __construct(
        private readonly TechnicalServiceMessageTemplateService $templates,
        private readonly TechnicalServiceMessagingSettingsService $settings,
        private readonly EvolutionWhatsAppMessageService $evolution,
        private readonly TechnicalServiceNacSmsTestClient $nacSms,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function send(array $input, ?User $actor = null): array
    {
        if (! (bool) ($input['confirmed'] ?? false)) {
            throw ValidationException::withMessages([
                'confirmed' => 'Test mesajı için manuel onay zorunlu.',
            ]);
        }

        $preview = $this->templates->preview([
            ...$input,
            'sample_context' => true,
        ]);

        if (($preview['blockers'] ?? []) !== [] || (bool) ($preview['preview_ready'] ?? false) === false) {
            throw ValidationException::withMessages([
                'template' => 'Bloklu şablon test mesajı olarak gönderilemez.',
            ]);
        }

        $channel = (string) ($input['channel'] ?? 'whatsapp');
        $phone = $this->settings->testPhone();

        if (! $this->validPhone($phone)) {
            throw ValidationException::withMessages([
                'test_phone' => 'Shared test telefonu geçerli olmadan test mesajı gönderilemez.',
            ]);
        }

        if ($channel === 'voice_script') {
            throw ValidationException::withMessages([
                'channel' => 'Voibot voice test gönderimi sözleşme kesinleşene kadar kapalı.',
            ]);
        }

        if ($channel === 'sms') {
            $dispatch = $this->nacSms->send($preview, $phone, $input, $actor);

            return $this->response($preview, $dispatch, match ($dispatch->status) {
                TechnicalServiceMessageDispatch::STATUS_SENT => 'NAC SMS şablon testi shared test telefonuna gönderildi.',
                TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_REAL_SEND_DISABLED => 'NAC SMS şablonu no-send audit kaydı olarak oluşturuldu; provider çağrısı yapılmadı.',
                default => 'NAC SMS provider yanıtı başarısız: '.($dispatch->error_message ?: 'Güvenli hata detayı yok.'),
            });
        }

        if ($channel !== 'whatsapp') {
            throw ValidationException::withMessages([
                'channel' => 'Bu kanal için test gönderimi desteklenmiyor.',
            ]);
        }

        $dispatch = $this->evolution->send(
            event: 'template_test_whatsapp',
            targetType: 'shared_test_phone',
            targetPhone: $phone,
            messageText: (string) $preview['rendered_body'],
            context: [
                ...(array) ($preview['context'] ?? []),
                'message_type' => $input['message_type'] ?? 'template_test_whatsapp',
                'template_key' => $input['template_key'] ?? null,
                'manual_ui_send' => true,
                'allow_unit_test_http_fake' => app()->runningUnitTests(),
                'provider_test' => true,
            ],
            user: $actor,
        );

        return $this->response($preview, $dispatch, match ($dispatch->status) {
            TechnicalServiceMessageDispatch::STATUS_SENT => 'Evo WhatsApp test mesajı shared test telefonuna gönderildi.',
            TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_REAL_SEND_DISABLED => 'Evo WhatsApp şablonu no-send audit kaydı olarak oluşturuldu; provider çağrısı yapılmadı.',
            default => 'Evo WhatsApp provider yanıtı doğrulanamadı; telefon üzerinden teslim kontrolü yapın.',
        });
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>
     */
    private function response(array $preview, TechnicalServiceMessageDispatch $dispatch, string $message): array
    {
        return [
            'message' => $message,
            'preview' => $preview,
            'dispatch' => [
                'id' => $dispatch->id,
                'event' => $dispatch->event,
                'status' => $dispatch->status,
                'target_type' => $dispatch->target_type,
                'target_phone_masked' => $this->maskPhone($dispatch->target_phone),
                'response_status' => $dispatch->response_payload['status'] ?? null,
                'provider_reference' => $dispatch->response_payload['pkgID'] ?? null,
                'error_message' => $dispatch->error_message,
                'test_type' => $dispatch->response_payload['test_type']
                    ?? $dispatch->request_payload['test_type']
                    ?? $dispatch->event,
                'content_preview' => $dispatch->request_payload['content_preview'] ?? null,
                'encoding' => $dispatch->response_payload['encoding']
                    ?? $dispatch->request_payload['encoding']
                    ?? null,
                'test_code' => $dispatch->response_payload['test_code']
                    ?? $dispatch->request_payload['test_code']
                    ?? null,
                'custom_id' => $dispatch->response_payload['customID']
                    ?? $dispatch->request_payload['custom_id']
                    ?? null,
                'payload_hash' => $dispatch->response_payload['payload_hash']
                    ?? $dispatch->request_payload['payload_hash']
                    ?? null,
                'previous_payload_hash' => $dispatch->response_payload['previous_payload_hash']
                    ?? $dispatch->request_payload['previous_payload_hash']
                    ?? null,
                'duplicate' => (bool) ($dispatch->response_payload['duplicate'] ?? false),
            ],
        ];
    }

    private function validPhone(string $phone): bool
    {
        return preg_match('/^90\d{10}$/', $phone) === 1;
    }

    private function maskPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?: '';

        if ($digits === '') {
            return null;
        }

        return substr($digits, 0, 4).'***'.substr($digits, -3);
    }
}
