<?php

namespace App\Services\Messaging;

use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServicePartnerJobAction;
use App\Models\TechnicalServiceRequest;
use App\Models\User;

class TechnicalServiceAppointmentMessageDispatchService
{
    public function __construct(
        private readonly TechnicalServiceMessagingSettingsService $settings,
        private readonly TechnicalServiceMessageTemplateService $templates,
        private readonly TechnicalServiceMessageChannelPlanner $channelPlanner,
        private readonly TechnicalServiceMessageDispatchQueue $dispatchQueue,
        private readonly TechnicalServiceMessageIdempotencyService $idempotency,
        private readonly TechnicalServiceTechnicianPortalLinkResolver $technicianPortalLinks,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function dispatchApproval(
        TechnicalServiceRequest $request,
        ?TechnicalServicePartnerJobAction $sourceAction,
        ?User $actor = null,
        array $options = [],
    ): array {
        $eventType = (bool) ($options['appointment_updated'] ?? false)
            ? 'appointment_updated'
            : 'appointment_approved';

        return $this->createDispatches($request, $sourceAction, $actor, $eventType, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function dispatchUpdate(
        TechnicalServiceRequest $request,
        ?TechnicalServicePartnerJobAction $sourceAction,
        ?User $actor = null,
        array $options = [],
    ): array {
        return $this->createDispatches($request, $sourceAction, $actor, 'appointment_updated', $options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function createDispatches(
        TechnicalServiceRequest $request,
        ?TechnicalServicePartnerJobAction $sourceAction,
        ?User $actor,
        string $eventType,
        array $options,
    ): array {
        $settings = $this->settings->payload();
        $global = (array) ($settings['global'] ?? []);
        $summary = $this->emptySummary($eventType);

        if (! (bool) ($global['messaging_enabled'] ?? false)) {
            return $this->blockedSummary($request, $actor, $summary, 'messaging_disabled', 'Mesaj sistemi kapalı.');
        }

        foreach ($this->messageTypesForEvent($eventType) as $messageType => $recipientRole) {
            $typeSettings = $this->messageTypeSettings($settings, $messageType);

            if (! (bool) ($typeSettings['enabled'] ?? false)) {
                $summary = $this->addSuppressed($request, $actor, $summary, $messageType, $recipientRole, 'message_type_disabled', 'Mesaj tipi kapalı.');

                continue;
            }

            if ((bool) ($global['test_mode_enabled'] ?? false) && ! (bool) ($typeSettings['test_send_allowed'] ?? true)) {
                $summary = $this->addSuppressed($request, $actor, $summary, $messageType, $recipientRole, 'test_send_disabled', 'Mesaj tipi test modunda kapalı.');

                continue;
            }

            $policy = (string) ($typeSettings['channel_policy'] ?? 'whatsapp_only');
            $plans = $this->channelPlanner->plan($policy, [
                'message_type' => $messageType,
                'recipient_role' => $recipientRole,
                'whatsapp_provider' => $typeSettings['whatsapp_provider'] ?? 'evo_whatsapp',
                'sms_provider' => $typeSettings['sms_provider'] ?? 'nac_sms',
                'channel_policy' => $policy,
                'metadata' => [
                    'appointment_event_type' => $eventType,
                    'appointment_source' => $options['trigger_source'] ?? 'ops_appointment_approval',
                ],
            ]);

            if ($plans === []) {
                $summary = $this->addSuppressed($request, $actor, $summary, $messageType, $recipientRole, 'channel_policy_disabled', 'Kanal politikası kapalı.');

                continue;
            }

            foreach ($plans as $plan) {
                $summary = $this->createPlannedDispatch($request, $sourceAction, $actor, $settings, $global, $summary, $eventType, $plan, $options);
            }
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $global
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function createPlannedDispatch(
        TechnicalServiceRequest $request,
        ?TechnicalServicePartnerJobAction $sourceAction,
        ?User $actor,
        array $settings,
        array $global,
        array $summary,
        string $eventType,
        array $plan,
        array $options,
    ): array {
        $messageType = (string) $plan['message_type'];
        $recipientRole = (string) $plan['recipient_role'];
        $channel = (string) $plan['channel'];
        $providerKey = (string) ($plan['provider_key'] ?? 'null_local');

        if (! $this->providerReadyForQueue($settings, $providerKey)) {
            return $this->addBlocked($request, $actor, $summary, $messageType, $recipientRole, $channel, $providerKey, 'provider_missing', 'Provider ayarı hazır değil.');
        }

        $recipientPhone = $this->recipientPhone($request, $recipientRole);
        if ($recipientPhone === '') {
            return $this->addBlocked($request, $actor, $summary, $messageType, $recipientRole, $channel, $providerKey, 'recipient_phone_missing', 'Alıcı telefonu eksik.');
        }

        $testMode = (bool) ($global['test_mode_enabled'] ?? false);
        $targetPhone = $recipientPhone;
        $testRedirectApplied = false;
        $controlledRoleTargetPhone = $this->controlledSmokeTargetPhone($options, $recipientRole);
        $controlledRoleTargetApplied = false;

        if ($testMode && $controlledRoleTargetPhone !== null) {
            $targetPhone = $controlledRoleTargetPhone;
            $testRedirectApplied = $targetPhone !== $recipientPhone;
            $controlledRoleTargetApplied = true;
        } elseif ($testMode) {
            $targetPhone = $this->idempotency->normalizePhone((string) ($global['shared_test_phone'] ?? $global['test_phone'] ?? ''));
            $testRedirectApplied = true;

            if ($targetPhone === '') {
                return $this->addBlocked($request, $actor, $summary, $messageType, $recipientRole, $channel, $providerKey, 'shared_test_phone_missing', 'Test modu için ortak test telefonu eksik.');
            }
        }

        $manualE2e = $this->manualE2ePreparation($global, $recipientRole, $targetPhone, $request);
        $manualE2eMetadata = $manualE2e['metadata'];
        if ($manualE2e['blocker'] !== null) {
            return $this->addBlocked(
                $request,
                $actor,
                $summary,
                $messageType,
                $recipientRole,
                $channel,
                $providerKey,
                $manualE2e['blocker']['code'],
                $manualE2e['blocker']['message'],
            );
        }
        $manualE2eAuthorized = filter_var(
            $manualE2eMetadata['manual_e2e'] ?? false,
            FILTER_VALIDATE_BOOL,
        );
        if (! $testMode
            && ! (bool) ($global['real_send_enabled'] ?? false)
            && ! $manualE2eAuthorized) {
            return $this->addBlocked($request, $actor, $summary, $messageType, $recipientRole, $channel, $providerKey, 'real_send_disabled', 'Gerçek gönderim kapalı.');
        }

        $jobCardContext = $recipientRole === 'technician'
            ? $this->technicianPortalLinks->resolveForDispatch(
                $request,
                $settings,
                $recipientRole,
                $targetPhone,
                $manualE2eMetadata,
            )
            : null;
        $context = $this->contextOverrides($request, $sourceAction, $options, $jobCardContext);
        if ($recipientRole === 'technician' && ! (bool) ($context['technician_job_card_ready'] ?? false)) {
            return $this->addBlocked(
                $request,
                $actor,
                $summary,
                $messageType,
                $recipientRole,
                $channel,
                $providerKey,
                (string) ($context['technician_job_card_blocker_code'] ?? 'active_assignment_partner_missing'),
                (string) ($context['technician_job_card_blocker_message'] ?? 'Aktif atamaya bağlı usta iş kartı bulunamadı.'),
            );
        }
        $preview = $this->templates->preview([
            'message_type' => $messageType,
            'channel' => $channel,
            'provider_key' => $providerKey,
            'request_id' => $request->id,
            'sample_context' => false,
            'context' => $context,
        ]);

        $blockers = array_values((array) ($preview['blockers'] ?? []));
        if ($blockers !== [] || ! (bool) ($preview['preview_ready'] ?? false)) {
            return $this->addBlocked(
                $request,
                $actor,
                $summary,
                $messageType,
                $recipientRole,
                $channel,
                $providerKey,
                'template_blocked',
                implode(' ', $blockers) ?: 'Şablon önizleme bloklu.',
            );
        }

        $businessEventId = $this->businessEventId($request, $sourceAction, $eventType, $context);
        $template = (array) ($preview['template'] ?? []);
        $payload = [
            'body' => $preview['rendered_body'] ?? '',
            'message_type' => $messageType,
            'channel' => $channel,
            'provider_key' => $providerKey,
            'business_event_id' => $businessEventId,
            'appointment_event_type' => $eventType,
            'test_redirect_applied' => $testRedirectApplied,
            'context' => $context,
            'sms' => $preview['sms'] ?? null,
        ];
        $dispatch = $this->dispatchQueue->enqueue([
            'event' => "{$eventType}_{$recipientRole}",
            'technical_service_request_id' => $request->id,
            'request_id' => $request->id,
            'related_type' => $sourceAction instanceof TechnicalServicePartnerJobAction ? TechnicalServicePartnerJobAction::class : null,
            'related_id' => $sourceAction?->id,
            'root_mrn' => $request->root_mrn ?: $request->mrn,
            'mrn' => $request->mrn,
            'srv' => $request->service_code,
            'message_type' => $messageType,
            'channel' => $channel,
            'provider_key' => $providerKey,
            'recipient_role' => $recipientRole,
            'target_type' => $recipientRole,
            'recipient_phone' => $recipientPhone,
            'target_phone' => $targetPhone,
            'test_redirect_applied' => $testRedirectApplied,
            'test_mode' => $testMode,
            'template_key' => $template['template_key'] ?? null,
            'template_version' => $template['version'] ?? null,
            'rendered_body' => (string) ($preview['rendered_body'] ?? ''),
            'payload' => $payload,
            'business_event_id' => $businessEventId,
            'channel_policy' => $plan['channel_policy'] ?? null,
            'triggered_by' => $options['trigger_source'] ?? 'ops_appointment_approval',
            'metadata' => [
                ...((array) ($plan['metadata'] ?? [])),
                ...((array) ($options['metadata'] ?? [])),
                ...($manualE2eMetadata ?? []),
                'appointment_event_type' => $eventType,
                'business_event_id' => $businessEventId,
                'source_partner_job_action_id' => $sourceAction?->id,
                'source_action' => $sourceAction?->action,
                'warnings' => array_values((array) ($preview['warnings'] ?? [])),
                'test_redirect_applied' => $testRedirectApplied,
                'controlled_role_target_applied' => $controlledRoleTargetApplied,
                'role_target_phone' => $controlledRoleTargetApplied
                    ? $targetPhone
                    : ($manualE2eMetadata['role_target_phone'] ?? null),
            ],
        ], $actor);

        if ($dispatch->status === TechnicalServiceMessageDispatch::STATUS_DUPLICATE_BLOCKED) {
            $summary['duplicate_blocked']++;
        } elseif ($dispatch->status === TechnicalServiceMessageDispatch::STATUS_SUPPRESSED) {
            $summary['suppressed']++;
        } else {
            $summary['queued']++;
        }

        $summary['dispatches'][] = $this->dispatchPayload($dispatch);

        if ($testRedirectApplied) {
            $this->recordEvent($request, $actor, 'message_test_redirect_applied', 'Test modu: mesaj ortak test telefonuna yönlendirildi.', [
                'dispatch_id' => $dispatch->id,
                'message_type' => $messageType,
                'channel' => $channel,
                'provider' => $providerKey,
                'recipient_role' => $recipientRole,
                'target_masked' => $dispatch->effective_target_phone_mask,
            ]);
        }

        return $summary;
    }

    /**
     * @return array<string, string>
     */
    private function messageTypesForEvent(string $eventType): array
    {
        return $eventType === 'appointment_updated'
            ? [
                'appointment_updated_customer' => 'customer',
                'appointment_updated_technician' => 'technician',
            ]
            : [
                'appointment_approved_customer' => 'customer',
                'appointment_approved_technician' => 'technician',
            ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function messageTypeSettings(array $settings, string $messageType): array
    {
        foreach ((array) ($settings['message_types'] ?? []) as $type) {
            if (($type['key'] ?? null) === $messageType) {
                return (array) $type;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function providerReadyForQueue(array $settings, string $providerKey): bool
    {
        if ($providerKey === 'null_local') {
            return true;
        }

        foreach ((array) ($settings['providers'] ?? []) as $provider) {
            if (($provider['key'] ?? null) !== $providerKey) {
                continue;
            }

            return (bool) ($provider['enabled'] ?? false)
                && (bool) data_get($provider, 'capabilities.supports_text', false)
                && (bool) ($provider['contract_confirmed'] ?? false);
        }

        return false;
    }

    private function recipientPhone(TechnicalServiceRequest $request, string $recipientRole): string
    {
        if ($recipientRole === 'technician') {
            return $this->idempotency->normalizePhone(
                $request->technicianRecord?->phone_e164
                    ?: ($request->technicianRecord?->phone_display ?: $request->technicianRecord?->phone),
            );
        }

        return $this->idempotency->normalizePhone($request->customer_phone);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function controlledSmokeTargetPhone(array $options, string $recipientRole): ?string
    {
        if (! filter_var(data_get($options, 'metadata.test_smoke', false), FILTER_VALIDATE_BOOL)) {
            return null;
        }

        $targets = (array) ($options['controlled_smoke_targets'] ?? $options['role_target_phones'] ?? []);
        $target = $targets[$recipientRole] ?? null;
        if (! is_scalar($target)) {
            return null;
        }

        $normalized = $this->idempotency->normalizePhone((string) $target);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @param  array<string, mixed>  $global
     * @return array{metadata:array<string, mixed>,blocker:array{code:string,message:string}|null}
     */
    private function manualE2ePreparation(array $global, string $recipientRole, string $targetPhone, TechnicalServiceRequest $request): array
    {
        if (! (bool) ($global['manual_e2e_enabled'] ?? false)) {
            return ['metadata' => [], 'blocker' => null];
        }

        $normalizedTarget = $this->idempotency->normalizePhone($targetPhone);
        $runContext = TechnicalServiceManualE2ERunContext::fromSettings($global);
        $blocker = $runContext->dispatchBlockingReason($normalizedTarget);
        if ($blocker !== null) {
            return ['metadata' => [], 'blocker' => $blocker];
        }
        $reference = $request->mrn ?: $request->service_code ?: (string) $request->id;

        return [
            'metadata' => $runContext->dispatchMetadata($reference, $normalizedTarget, $recipientRole),
            'blocker' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function contextOverrides(
        TechnicalServiceRequest $request,
        ?TechnicalServicePartnerJobAction $sourceAction,
        array $options,
        ?array $jobCardContext = null,
    ): array {
        $explicitContext = is_array($options['context'] ?? null) ? $options['context'] : [];
        $slot = is_array($options['slot'] ?? null) ? $options['slot'] : [];
        $start = $this->filledString($slot['start_time'] ?? null) ?: $this->filledString($request->scheduled_time);
        $end = $this->filledString($slot['end_time'] ?? null);
        $range = $start !== null && $end !== null ? "{$start} - {$end}" : $start;
        $customerWindow = $this->customerAppointmentWindow($start);
        $jobCardContext ??= [];
        $jobCardUrl = is_string($jobCardContext['canonical_url'] ?? null)
            ? $jobCardContext['canonical_url']
            : null;

        return [
            ...$explicitContext,
            'appointment_date' => $request->scheduled_date?->toDateString() ?: $request->scheduled_at?->toDateString(),
            'appointment_time' => $range,
            'appointment_time_range' => $range,
            'appointment_start_time' => $start,
            'appointment_end_time' => $end,
            'appointment_exact_time_range' => $start !== null && $end !== null ? "{$start} - {$end}" : null,
            'appointment_customer_window' => $customerWindow,
            'appointment_customer_window_label' => $customerWindow === '09:00 - 13:00 arası' ? 'sabah' : ($customerWindow === null ? null : 'öğleden sonra'),
            'appointment_slot_text' => $slot['label'] ?? $slot['slot'] ?? null,
            'technician_job_card_url' => $jobCardUrl,
            'technician_job_card_short_url' => $jobCardContext['short_url'] ?? null,
            'technician_job_card_origin_source' => $jobCardContext['source'] ?? null,
            'technician_job_card_origin_mode' => $jobCardContext['mode'] ?? null,
            'technician_job_card_ready' => (bool) ($jobCardContext['ready'] ?? false),
            'technician_job_card_blocker_code' => $jobCardContext['blocker_code'] ?? null,
            'technician_job_card_blocker_message' => $jobCardContext['blocker_message'] ?? null,
            'assignment_partner_id' => $jobCardContext['partner_id'] ?? null,
            'assignment_technician_id' => $jobCardContext['technician_id'] ?? null,
        ];
    }

    private function customerAppointmentWindow(?string $start): ?string
    {
        $time = $this->filledString($start);
        if ($time === null || ! preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $matches)) {
            return null;
        }

        $hour = (int) $matches[1];

        return $hour < 13
            ? '09:00 - 13:00 arası'
            : '13:00 - 19:00 arası';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function businessEventId(
        TechnicalServiceRequest $request,
        ?TechnicalServicePartnerJobAction $sourceAction,
        string $eventType,
        array $context,
    ): string {
        return hash('sha256', implode('|', [
            'appointment-message',
            $eventType,
            $request->id,
            $sourceAction?->id ?? 'no-action',
            $request->scheduled_date?->toDateString() ?? '',
            $context['appointment_start_time'] ?? '',
            $context['appointment_end_time'] ?? '',
            $request->technical_service_technician_id ?? '',
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySummary(string $eventType): array
    {
        return [
            'event_type' => $eventType,
            'queued' => 0,
            'blocked' => 0,
            'duplicate_blocked' => 0,
            'suppressed' => 0,
            'dispatches' => [],
            'blockers' => [],
            'warnings' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function blockedSummary(
        TechnicalServiceRequest $request,
        ?User $actor,
        array $summary,
        string $code,
        string $message,
    ): array {
        $summary['blocked']++;
        $summary['blockers'][] = ['code' => $code, 'message' => $message];
        $this->recordEvent($request, $actor, 'message_dispatch_suppressed', 'Mesaj kuyruğu oluşturulmadı.', [
            'code' => $code,
            'reason' => $message,
            'event_type' => $summary['event_type'],
        ]);

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function addSuppressed(
        TechnicalServiceRequest $request,
        ?User $actor,
        array $summary,
        string $messageType,
        string $recipientRole,
        string $code,
        string $message,
    ): array {
        $summary['suppressed']++;
        $summary['blockers'][] = compact('messageType', 'recipientRole', 'code', 'message');
        $this->recordEvent($request, $actor, 'message_dispatch_suppressed', 'Mesaj tipi bastırıldı.', [
            'message_type' => $messageType,
            'recipient_role' => $recipientRole,
            'code' => $code,
            'reason' => $message,
        ]);

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function addBlocked(
        TechnicalServiceRequest $request,
        ?User $actor,
        array $summary,
        string $messageType,
        string $recipientRole,
        string $channel,
        string $providerKey,
        string $code,
        string $message,
    ): array {
        $summary['blocked']++;
        $summary['blockers'][] = compact('messageType', 'recipientRole', 'channel', 'providerKey', 'code', 'message');
        $this->recordEvent($request, $actor, 'message_dispatch_blocked', 'Mesaj oluşturulamadı: eksik bilgi.', [
            'message_type' => $messageType,
            'recipient_role' => $recipientRole,
            'channel' => $channel,
            'provider' => $providerKey,
            'code' => $code,
            'reason' => $message,
        ]);

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatchPayload(TechnicalServiceMessageDispatch $dispatch): array
    {
        return [
            'id' => $dispatch->id,
            'event' => $dispatch->event,
            'message_type' => $dispatch->message_type,
            'channel' => $dispatch->channel,
            'provider_key' => $dispatch->provider_key,
            'recipient_role' => $dispatch->recipient_role,
            'status' => $dispatch->status,
            'target_masked' => $dispatch->effective_target_phone_mask,
            'test_redirect_applied' => (bool) $dispatch->test_redirect_applied,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordEvent(TechnicalServiceRequest $request, ?User $actor, string $eventType, string $title, array $metadata): void
    {
        $request->events()->create([
            'event_type' => $eventType,
            'title' => $title,
            'note' => implode(' | ', array_filter([
                $metadata['message_type'] ?? null,
                $metadata['provider'] ?? null,
                $metadata['target_masked'] ?? null,
                $metadata['reason'] ?? null,
            ])),
            'author_user_id' => $actor?->id,
            'metadata' => $metadata,
        ]);
    }

    private function filledString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
