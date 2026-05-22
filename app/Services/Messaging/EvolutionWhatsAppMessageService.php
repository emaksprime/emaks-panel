<?php

namespace App\Services\Messaging;

use App\Models\TechnicalServiceAssignmentOffer;
use App\Models\TechnicalServiceEarning;
use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServicePartnerJobAction;
use App\Models\TechnicalServiceRequest;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Throwable;

class EvolutionWhatsAppMessageService
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function send(
        string $event,
        string $targetType,
        ?string $targetPhone,
        string $messageText,
        array $context = [],
        ?TechnicalServiceRequest $request = null,
        ?User $user = null,
        ?TechnicalServicePartnerJobAction $partnerJobAction = null,
        ?TechnicalServiceAssignmentOffer $assignmentOffer = null,
        ?TechnicalServiceEarning $earning = null,
    ): TechnicalServiceMessageDispatch {
        $testMode = $this->testMode();
        $originalPhone = $this->normalizePhone($targetPhone);
        $resolvedPhone = $testMode
            ? $this->normalizePhone((string) config('services.evolution.test_phone', '905467647428'))
            : $originalPhone;

        $payload = [
            'event' => $event,
            'target_type' => $targetType,
            'target_phone' => $resolvedPhone,
            'original_phone' => $originalPhone,
            'test_mode' => $testMode,
            'mrn' => $request?->mrn,
            'technical_service_request_id' => $request?->id,
            'text' => $messageText,
            'context' => $context,
            'metadata' => [
                'source' => 'emaks_panel',
                'provider_router' => 'n8n_evolution',
            ],
        ];

        $dispatch = TechnicalServiceMessageDispatch::query()->create([
            'event' => $event,
            'technical_service_request_id' => $request?->id,
            'technical_service_partner_job_action_id' => $partnerJobAction?->id,
            'technical_service_assignment_offer_id' => $assignmentOffer?->id,
            'technical_service_earning_id' => $earning?->id,
            'target_type' => $targetType,
            'original_phone' => $originalPhone,
            'target_phone' => $resolvedPhone,
            'test_mode' => $testMode,
            'status' => TechnicalServiceMessageDispatch::STATUS_NOT_CONFIGURED,
            'request_payload' => $payload,
            'sent_by' => $user?->id,
        ]);

        $url = trim((string) config('services.evolution.n8n_webhook_url', ''));
        if ($url === '' || $resolvedPhone === '') {
            return $dispatch;
        }

        try {
            $response = Http::timeout(10)->post($url, $payload);
            $dispatch->forceFill([
                'status' => $response->successful()
                    ? TechnicalServiceMessageDispatch::STATUS_SENT
                    : TechnicalServiceMessageDispatch::STATUS_FAILED,
                'response_payload' => [
                    'status' => $response->status(),
                    'body' => $response->json() ?? $response->body(),
                ],
                'error_message' => $response->successful() ? null : mb_substr($response->body(), 0, 1000),
                'sent_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $dispatch->forceFill([
                'status' => TechnicalServiceMessageDispatch::STATUS_FAILED,
                'error_message' => $exception->getMessage(),
                'sent_at' => now(),
            ])->save();
        }

        return $dispatch;
    }

    public function testMode(): bool
    {
        return filter_var(config('services.evolution.test_mode', true), FILTER_VALIDATE_BOOL);
    }

    public function normalizePhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?: '';

        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '0')) {
            return '90'.substr($digits, 1);
        }

        if (strlen($digits) === 10) {
            return '90'.$digits;
        }

        return $digits;
    }
}
