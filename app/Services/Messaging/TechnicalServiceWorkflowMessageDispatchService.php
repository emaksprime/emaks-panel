<?php

namespace App\Services\Messaging;

use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServicePartnerJobAction;
use App\Models\TechnicalServiceRequest;
use App\Models\User;
use App\Support\PartnerPortalPublicUrl;

class TechnicalServiceWorkflowMessageDispatchService
{
    public function __construct(
        private readonly TechnicalServiceMessageDispatchQueue $dispatchQueue,
        private readonly TechnicalServiceMessageIdempotencyService $idempotency,
        private readonly TechnicalServiceMessagingSettingsService $settings,
        private readonly TechnicalServiceMessageTemplateService $templates,
        private readonly TechnicalServiceMessageChannelPlanner $channelPlanner,
        private readonly TechnicalServiceTechnicianPortalLinkResolver $technicianPortalLinks,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function queueWorkflowDispatches(
        TechnicalServiceRequest $request,
        string $messageType,
        string $recipientRole,
        array $context = [],
        ?User $actor = null,
        ?TechnicalServicePartnerJobAction $sourceAction = null,
        array $options = [],
    ): array {
        $settings = $this->settings->workflowDispatchSnapshot();
        $global = (array) ($settings['global'] ?? []);
        $summary = $this->emptySummary($messageType, $recipientRole);
        $jobCardContext = null;

        if (! (bool) ($global['messaging_enabled'] ?? false)) {
            return $this->blockedSummary($summary, 'messaging_disabled', 'Mesaj sistemi kapalı.');
        }

        $typeSettings = $this->messageTypeSettings($settings, $messageType);
        if (! (bool) ($typeSettings['enabled'] ?? false)) {
            return $this->suppressedSummary($summary, 'message_type_disabled', 'Mesaj tipi kapalı.');
        }

        $policy = $this->effectiveChannelPolicy($global, $typeSettings, $recipientRole);
        if ($policy === 'disabled') {
            return $this->suppressedSummary($summary, 'channel_policy_disabled', 'Kanal politikası kapalı.');
        }

        $plans = $this->channelPlanner->plan($policy, [
            'message_type' => $messageType,
            'recipient_role' => $recipientRole,
            'whatsapp_provider' => $typeSettings['whatsapp_provider'] ?? 'evo_whatsapp',
            'sms_provider' => $typeSettings['sms_provider'] ?? 'nac_sms',
            'channel_policy' => $policy,
            'metadata' => [
                'workflow_message_type' => $messageType,
                'workflow_trigger' => $options['triggered_by'] ?? 'workflow_action',
            ],
        ]);

        if ($plans === []) {
            return $this->suppressedSummary($summary, 'channel_policy_disabled', 'Kanal politikası kapalı.');
        }

        $recipientPhone = $this->recipientPhone($request, $recipientRole, $options, $global);
        $testMode = (bool) ($global['test_mode_enabled'] ?? false);
        $targetPhone = $testMode
            ? $this->idempotency->normalizePhone((string) ($global['shared_test_phone'] ?? $global['test_phone'] ?? ''))
            : $recipientPhone;
        $testRedirectApplied = $testMode && $targetPhone !== '' && $targetPhone !== $recipientPhone;
        $manualE2e = $this->manualE2ePreparation($global, $recipientRole, $targetPhone, $request);
        $manualE2eMetadata = $manualE2e['metadata'];
        if ($recipientRole === 'technician') {
            $jobCardContext = $this->technicianPortalLinks->resolveForDispatch(
                $request,
                $settings,
                $recipientRole,
                $targetPhone,
                $manualE2eMetadata,
            );
            if ((bool) ($jobCardContext['ready'] ?? false)) {
                $context = [
                    ...$context,
                    'job_link' => $jobCardContext['canonical_url'],
                    'technician_job_card_url' => $jobCardContext['canonical_url'],
                    'technician_job_card_short_url' => $jobCardContext['short_url'],
                    'technician_job_card_origin_source' => $jobCardContext['source'],
                    'technician_job_card_origin_mode' => $jobCardContext['mode'],
                    'assignment_partner_id' => $jobCardContext['partner_id'] ?? null,
                    'assignment_technician_id' => $jobCardContext['technician_id'] ?? null,
                ];
                $options['requires_public_url'] = $jobCardContext['canonical_url'];
            }
        }

        foreach ($plans as $plan) {
            $channel = (string) ($plan['channel'] ?? 'system');
            $providerKey = (string) ($plan['provider_key'] ?? 'null_local');
            $baseInput = $this->dispatchInput(
                $request,
                $sourceAction,
                $messageType,
                $recipientRole,
                $channel,
                $providerKey,
                $recipientPhone,
                $targetPhone,
                $testMode,
                $testRedirectApplied,
                $policy,
                $context,
                $options,
                (array) ($plan['metadata'] ?? []),
                $manualE2eMetadata ?? [],
                (bool) ($global['real_send_enabled'] ?? false),
            );

            if ($recipientPhone === '') {
                $summary = $this->addBlockedDispatch($summary, $baseInput, $actor, 'recipient_phone_missing', 'Alıcı telefonu eksik.');

                continue;
            }

            if ($testMode && $targetPhone === '') {
                $summary = $this->addBlockedDispatch($summary, $baseInput, $actor, 'shared_test_phone_missing', 'Test modu için ortak test telefonu eksik.');

                continue;
            }

            if (is_array($jobCardContext) && ! (bool) ($jobCardContext['ready'] ?? false)) {
                $summary = $this->addBlockedDispatch(
                    $summary,
                    $baseInput,
                    $actor,
                    (string) ($jobCardContext['blocker_code'] ?? 'active_assignment_partner_missing'),
                    (string) ($jobCardContext['blocker_message'] ?? 'Aktif atamaya bağlı usta iş kartı bulunamadı.'),
                );

                continue;
            }

            if ($manualE2e['blocker'] !== null) {
                $summary = $this->addBlockedDispatch(
                    $summary,
                    $baseInput,
                    $actor,
                    $manualE2e['blocker']['code'],
                    $manualE2e['blocker']['message'],
                );

                continue;
            }

            $publicUrl = $this->requiredPublicUrl($options, $context);
            $guardedManualTechnicianUrl = $recipientRole === 'technician'
                && ($jobCardContext['mode'] ?? null) === 'manual_e2e_local';
            if (! $testMode
                && $publicUrl !== null
                && ! $guardedManualTechnicianUrl
                && ! $this->publicUrlReadyForDispatch($publicUrl)
            ) {
                $summary = $this->addBlockedDispatch($summary, $baseInput, $actor, 'public_url_missing', 'Mesajdaki bağlantı gerçek gönderim için public URL olmalıdır. PARTNER_PORTAL_PUBLIC_URL / public portal URL ayarlanmalı.');

                continue;
            }

            if (! $this->providerReadyForQueue($settings, $providerKey)) {
                $summary = $this->addBlockedDispatch($summary, $baseInput, $actor, 'provider_missing', 'Provider ayarı hazır değil.');

                continue;
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
            $fallbackBody = trim((string) ($options['fallback_body'] ?? ''));
            if ($blockers !== [] || ! (bool) ($preview['preview_ready'] ?? false)) {
                if ($fallbackBody === '') {
                    $summary = $this->addBlockedDispatch(
                        $summary,
                        [
                            ...$baseInput,
                            'payload' => [
                                ...((array) ($baseInput['payload'] ?? [])),
                                'template_blockers' => $blockers,
                            ],
                        ],
                        $actor,
                        'template_blocked',
                        implode(' ', $blockers) ?: 'Şablon önizleme bloklu.',
                    );

                    continue;
                }
            }

            $template = (array) ($preview['template'] ?? []);
            $usingFallbackBody = ($blockers !== [] || ! (bool) ($preview['preview_ready'] ?? false)) && $fallbackBody !== '';
            $body = $usingFallbackBody ? $fallbackBody : (string) ($preview['rendered_body'] ?? '');
            $businessEventId = $this->businessEventId($request, $messageType, $sourceAction, $body, $options + [
                'channel' => $channel,
                'event_version' => $options['event_version'] ?? null,
            ]);
            $dispatch = $this->dispatchQueue->enqueue([
                ...$baseInput,
                'template_key' => $template['template_key'] ?? null,
                'template_version' => $template['version'] ?? null,
                'rendered_body' => $body,
                'business_event_id' => $businessEventId,
                'payload' => [
                    ...((array) ($baseInput['payload'] ?? [])),
                    'body' => $body,
                    'rendered_body' => $body,
                    'message_text' => $body,
                    'business_event_id' => $businessEventId,
                    'template_source' => $usingFallbackBody
                        ? 'explicit_queue_body'
                        : ((bool) ($template['is_default'] ?? false) ? 'default_registry' : 'db_template'),
                    'template_blockers' => $usingFallbackBody ? $blockers : [],
                    'sms' => $preview['sms'] ?? null,
                ],
                'metadata' => [
                    ...((array) ($baseInput['metadata'] ?? [])),
                    'business_event_id' => $businessEventId,
                    'warnings' => [
                        ...array_values((array) ($preview['warnings'] ?? [])),
                        ...($usingFallbackBody ? ['Şablon context eksik olduğu için hazır kuyruk metni kullanıldı.'] : []),
                    ],
                    'template_source' => $usingFallbackBody
                        ? 'explicit_queue_body'
                        : ((bool) ($template['is_default'] ?? false) ? 'default_registry' : 'db_template'),
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
        }

        return $summary;
    }

    public function publicUrlReadyForDispatch(?string $publicUrl): bool
    {
        if (PartnerPortalPublicUrl::isPublicHttpsUrl($publicUrl)) {
            return true;
        }

        $normalizedUrl = PartnerPortalPublicUrl::normalizeBaseUrl($publicUrl);
        if ($normalizedUrl === null) {
            return false;
        }
        $scheme = parse_url($normalizedUrl, PHP_URL_SCHEME);
        $host = parse_url($normalizedUrl, PHP_URL_HOST);
        $port = parse_url($normalizedUrl, PHP_URL_PORT);
        if (! is_string($scheme) || ! is_string($host) || $scheme === '' || $host === '') {
            return false;
        }
        $urlOrigin = PartnerPortalPublicUrl::normalizeOrigin(
            strtolower($scheme).'://'.strtolower($host).(is_int($port) ? ':'.$port : ''),
        );
        $profile = PartnerPortalPublicUrl::profile();
        $profileOrigin = PartnerPortalPublicUrl::normalizeOrigin(
            is_string($profile['origin'] ?? null) ? $profile['origin'] : null,
        );

        return ($profile['profile'] ?? null) === PartnerPortalPublicUrl::PROFILE_LOCAL
            && (bool) ($profile['ready'] ?? false)
            && (bool) ($profile['private_lan'] ?? false)
            && ! (bool) ($profile['loopback'] ?? true)
            && (bool) ($profile['phone_reachable'] ?? false)
            && $urlOrigin !== null
            && $profileOrigin !== null
            && hash_equals($profileOrigin, $urlOrigin);
    }

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
        $configured = $this->queueWorkflowDispatches(
            $request,
            $messageType,
            $recipientRole,
            $context,
            $actor,
            $sourceAction,
            [
                ...$options,
                'fallback_body' => $body,
            ],
        );

        if (($configured['provider_policy_attempted'] ?? false) === true && ($configured['dispatches'] ?? []) !== []) {
            $first = TechnicalServiceMessageDispatch::query()->find((int) $configured['dispatches'][0]['id']);
            if ($first instanceof TechnicalServiceMessageDispatch) {
                return $first;
            }
        }

        $settings = $this->settings->payload();
        $global = (array) ($settings['global'] ?? []);
        $testMode = (bool) ($global['test_mode_enabled'] ?? false);
        $recipientPhone = $this->recipientPhone($request, $recipientRole, $options, $global);
        $targetPhone = $testMode
            ? $this->idempotency->normalizePhone((string) ($global['shared_test_phone'] ?? $global['test_phone'] ?? ''))
            : $recipientPhone;
        $businessEventId = $this->businessEventId($request, $messageType, $sourceAction, $body, $options);
        $opsWhatsappEnabled = $this->opsWhatsappEnabled($settings, $messageType, $recipientRole);
        $manualE2e = $this->manualE2ePreparation($global, $recipientRole, $targetPhone, $request);
        $manualE2eMetadata = $manualE2e['metadata'];
        $channel = $opsWhatsappEnabled && $manualE2e['blocker'] === null ? 'whatsapp' : 'system';
        $providerKey = $opsWhatsappEnabled && $manualE2e['blocker'] === null ? 'evo_whatsapp' : 'null_local';

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
            'channel' => $channel,
            'provider_key' => $providerKey,
            'recipient_role' => $recipientRole,
            'target_type' => $recipientRole,
            'recipient_phone' => $recipientPhone,
            'target_phone' => $targetPhone,
            'test_redirect_applied' => $testMode && $targetPhone !== '' && $targetPhone !== $recipientPhone,
            'test_mode' => $testMode,
            'rendered_body' => $body,
            'business_event_id' => $businessEventId,
            'channel_policy' => $channel === 'whatsapp' ? 'whatsapp_only' : 'system_queue_only',
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
                ...($manualE2eMetadata ?? []),
                'ops_whatsapp_enabled' => $opsWhatsappEnabled,
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
     * @param  array<string, mixed>  $global
     */
    private function recipientPhone(TechnicalServiceRequest $request, string $recipientRole, array $options, array $global): string
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

        if ($recipientRole === 'ops') {
            return $this->idempotency->normalizePhone((string) ($global['ops_whatsapp_phone'] ?? ''));
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function opsWhatsappEnabled(array $settings, string $messageType, string $recipientRole): bool
    {
        if ($recipientRole !== 'ops' || ! (bool) data_get($settings, 'global.ops_whatsapp_enabled', false)) {
            return false;
        }

        foreach ((array) ($settings['message_types'] ?? []) as $type) {
            if (($type['key'] ?? null) !== $messageType) {
                continue;
            }

            return (bool) ($type['enabled'] ?? false)
                && ($type['channel_policy'] ?? 'disabled') !== 'disabled';
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function addBlockedDispatch(array $summary, array $input, ?User $actor, string $code, string $message): array
    {
        $dispatch = $this->dispatchQueue->blocked($input, $actor, $code, $message);
        $summary['blocked']++;
        $summary['blockers'][] = [
            'message_type' => $dispatch->message_type,
            'recipient_role' => $dispatch->recipient_role,
            'channel' => $dispatch->channel,
            'provider_key' => $dispatch->provider_key,
            'code' => $code,
            'message' => $message,
        ];
        $summary['dispatches'][] = $this->dispatchPayload($dispatch);

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatchInput(
        TechnicalServiceRequest $request,
        ?TechnicalServicePartnerJobAction $sourceAction,
        string $messageType,
        string $recipientRole,
        string $channel,
        string $providerKey,
        string $recipientPhone,
        string $targetPhone,
        bool $testMode,
        bool $testRedirectApplied,
        string $channelPolicy,
        array $context,
        array $options,
        array $planMetadata,
        array $manualE2eMetadata,
        bool $realSendEnabledAtEnqueue,
    ): array {
        return [
            'event' => $messageType,
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
            'channel_policy' => $channelPolicy,
            'triggered_by' => (string) ($options['triggered_by'] ?? 'workflow_action'),
            'parent_dispatch_id' => $options['parent_dispatch_id'] ?? null,
            'force_resend' => (bool) ($options['force_resend'] ?? false),
            'force_resend_reason' => $options['force_resend_reason'] ?? null,
            'payload' => [
                'message_type' => $messageType,
                'recipient_role' => $recipientRole,
                'queue_only' => true,
                'external_provider_call' => false,
                'context' => $context,
            ],
            'metadata' => [
                ...$planMetadata,
                ...$manualE2eMetadata,
                ...((array) ($options['metadata'] ?? [])),
                'workflow_message_queue_only' => true,
                'external_provider_call' => false,
                'source_partner_job_action_id' => $sourceAction?->id,
                'source_action' => $sourceAction?->action,
                'context_keys' => array_keys($context),
                'real_send_enabled_at_enqueue' => $realSendEnabledAtEnqueue,
                'test_redirect_applied' => $testRedirectApplied,
            ],
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
     * @param  array<string, mixed>  $global
     * @param  array<string, mixed>  $typeSettings
     */
    private function effectiveChannelPolicy(array $global, array $typeSettings, string $recipientRole): string
    {
        if ($recipientRole === 'ops') {
            if (! (bool) ($global['ops_whatsapp_enabled'] ?? false)) {
                return 'disabled';
            }

            return 'whatsapp_only';
        }

        return (string) ($typeSettings['channel_policy'] ?? 'disabled');
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function providerReadyForQueue(array $settings, string $providerKey): bool
    {
        if ($providerKey === 'null_local' || $providerKey === 'system') {
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

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $context
     */
    private function requiredPublicUrl(array $options, array $context): ?string
    {
        foreach ([
            $options['requires_public_url'] ?? null,
            $context['confirmation_link'] ?? null,
            $context['approval_url'] ?? null,
            $context['payment_link'] ?? null,
            $context['technician_job_card_url'] ?? null,
            $context['job_link'] ?? null,
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySummary(string $messageType, string $recipientRole): array
    {
        return [
            'message_type' => $messageType,
            'recipient_role' => $recipientRole,
            'provider_policy_attempted' => true,
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
    private function blockedSummary(array $summary, string $code, string $message): array
    {
        $summary['blocked']++;
        $summary['blockers'][] = compact('code', 'message');

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function suppressedSummary(array $summary, string $code, string $message): array
    {
        $summary['provider_policy_attempted'] = false;
        $summary['suppressed']++;
        $summary['blockers'][] = compact('code', 'message');

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatchPayload(TechnicalServiceMessageDispatch $dispatch): array
    {
        return [
            'id' => $dispatch->id,
            'status' => $dispatch->status,
            'message_type' => $dispatch->message_type,
            'channel' => $dispatch->channel,
            'provider_key' => $dispatch->provider_key,
            'recipient_role' => $dispatch->recipient_role,
            'target_masked' => $dispatch->effective_target_phone_mask,
            'last_error_code' => $dispatch->last_error_code,
            'last_error_message_redacted' => $dispatch->last_error_message_redacted,
        ];
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

        $target = $this->idempotency->normalizePhone($targetPhone);
        $runContext = TechnicalServiceManualE2ERunContext::fromSettings($global);
        $blocker = $runContext->dispatchBlockingReason($target);
        if ($blocker !== null) {
            return ['metadata' => [], 'blocker' => $blocker];
        }
        $reference = $request->mrn ?: $request->service_code ?: (string) $request->id;

        return [
            'metadata' => $runContext->dispatchMetadata($reference, $target, $recipientRole),
            'blocker' => null,
        ];
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
