<?php

namespace App\Services\Messaging;

use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServicePartnerJobAction;
use App\Models\TechnicalServiceRequest;
use App\Models\User;

class TechnicalServiceWorkflowMessageDispatchService
{
    public function __construct(
        private readonly TechnicalServiceMessageDispatchQueue $dispatchQueue,
        private readonly TechnicalServiceMessageIdempotencyService $idempotency,
        private readonly TechnicalServiceMessagingSettingsService $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $options
     */
    public function queueSystemMessage(
        TechnicalServiceRequest $request,
        string $messageType,
        string $recipientRole,
        string $body,
        array $context = [],
        ?User $actor = null,
        ?TechnicalServicePartnerJobAction $sourceAction = null,
        array $options = [],
    ): TechnicalServiceMessageDispatch {
        $settings = $this->settings->payload();
        $global = (array) ($settings['global'] ?? []);
        $testMode = (bool) ($global['test_mode_enabled'] ?? false);
        $recipientPhone = $this->recipientPhone($request, $recipientRole, $options);
        $targetPhone = $testMode
            ? $this->idempotency->normalizePhone((string) ($global['shared_test_phone'] ?? $global['test_phone'] ?? ''))
            : $recipientPhone;
        $businessEventId = $this->businessEventId($request, $messageType, $sourceAction, $body, $options);

        return $this->dispatchQueue->enqueue([
            'event' => $messageType,
            'technical_service_request_id' => $request->id,
            'request_id' => $request->id,
            'related_type' => $sourceAction instanceof TechnicalServicePartnerJobAction ? TechnicalServicePartnerJobAction::class : null,
            'related_id' => $sourceAction?->id,
            'root_mrn' => $request->root_mrn ?: $request->mrn,
            'mrn' => $request->mrn,
            'srv' => $request->service_code,
            'message_type' => $messageType,
            'channel' => 'system',
            'provider_key' => 'null_local',
            'recipient_role' => $recipientRole,
            'target_type' => $recipientRole,
            'recipient_phone' => $recipientPhone,
            'target_phone' => $targetPhone,
            'test_redirect_applied' => $testMode && $targetPhone !== '' && $targetPhone !== $recipientPhone,
            'test_mode' => $testMode,
            'rendered_body' => $body,
            'business_event_id' => $businessEventId,
            'channel_policy' => 'system_queue_only',
            'triggered_by' => (string) ($options['triggered_by'] ?? 'workflow_action'),
            'payload' => [
                'body' => $body,
                'message_text' => $body,
                'message_type' => $messageType,
                'recipient_role' => $recipientRole,
                'business_event_id' => $businessEventId,
                'queue_only' => true,
                'external_provider_call' => false,
                'context' => $context,
            ],
            'metadata' => [
                'workflow_message_queue_only' => true,
                'external_provider_call' => false,
                'source_partner_job_action_id' => $sourceAction?->id,
                'source_action' => $sourceAction?->action,
                'business_event_id' => $businessEventId,
                'context_keys' => array_keys($context),
                ...((array) ($options['metadata'] ?? [])),
            ],
        ], $actor);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function recipientPhone(TechnicalServiceRequest $request, string $recipientRole, array $options): string
    {
        if (isset($options['recipient_phone'])) {
            return $this->idempotency->normalizePhone((string) $options['recipient_phone']);
        }

        if ($recipientRole === 'customer') {
            return $this->idempotency->normalizePhone($request->customer_phone);
        }

        if ($recipientRole === 'technician') {
            return $this->idempotency->normalizePhone(
                $request->technicianRecord?->phone_e164
                    ?: ($request->technicianRecord?->phone_display ?: $request->technicianRecord?->phone),
            );
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function businessEventId(
        TechnicalServiceRequest $request,
        string $messageType,
        ?TechnicalServicePartnerJobAction $sourceAction,
        string $body,
        array $options,
    ): string {
        return hash('sha256', implode('|', [
            'workflow-message',
            $messageType,
            $request->id,
            $sourceAction?->id ?? 'no-action',
            $options['event_version'] ?? '',
            hash('sha256', $body),
        ]));
    }
}
