<?php

namespace App\Services\ExternalEffects;

final class ExternalEffectCapabilityRegistry
{
    public const VERSION = 1;

    public const MESSAGING_EVOLUTION_SEND = 'messaging.evo.send';

    public const MESSAGING_NAC_SEND = 'messaging.nac.send';

    public const MAIL_SMTP_SEND = 'mail.smtp.send';

    public const PAYMENT_LOCAL_SANDBOX_EXECUTE = 'payment.local_sandbox.execute';

    public const LOCAL_ALLOWLISTED_UAT_PROFILE = 'LOCAL_ALLOWLISTED_MESSAGING_EMAIL_SANDBOX_PAYMENT_UAT';

    /**
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        $definitions = [
            $this->definition('provider.profile.control', 'PROVIDER_OR_CREDENTIAL_CONTROL', 'FOUNDATION-CONTROL-PLANE', true, true, false, 'foundation_controlled'),
            $this->definition(self::MESSAGING_EVOLUTION_SEND, 'OUTBOUND_COMMUNICATION', 'REL-4E', true, true, true, 'no_send'),
            $this->definition(self::MESSAGING_NAC_SEND, 'OUTBOUND_COMMUNICATION', 'REL-4E', true, true, true, 'no_send'),
            $this->definition('serial.exchange.apply', 'INTERNAL_ONLY', 'REL-4G', true, true, false, 'internal_only'),
            $this->definition('identity.root_mrn.allocate', 'INTERNAL_ONLY', 'REL-4H', true, true, false, 'internal_only'),
            $this->definition('bulk.support.apply', 'BULK_APPLY_OR_INVITATION', 'REL-5', true, false, true, 'preview_only'),
            $this->definition('bulk.b2b.apply', 'BULK_APPLY_OR_INVITATION', 'REL-5', true, false, true, 'preview_only'),
            $this->definition('bulk.technician_locksmith.apply', 'BULK_APPLY_OR_INVITATION', 'REL-5', true, false, true, 'preview_only'),
            $this->definition('invitation.send', 'BULK_APPLY_OR_INVITATION', 'REL-5', false, false, true, 'off'),
            $this->definition('maps.google.geocode', 'EXTERNAL_READ', 'REL-5', false, false, true, 'off'),
            $this->definition('otp.send', 'OUTBOUND_COMMUNICATION', 'REL-6', true, false, true, 'no_send'),
            $this->definition('state.sla.tick', 'BACKGROUND_AUTOMATION', 'REL-7', true, false, true, 'off'),
            $this->definition(self::MAIL_SMTP_SEND, 'OUTBOUND_COMMUNICATION', 'REL-8', false, false, true, 'no_send'),
            $this->definition('payment.iyzico.mutate', 'FINANCIAL_MUTATION', 'REL-9', true, false, true, 'off'),
            $this->definition('payment.iyzico.reconcile', 'EXTERNAL_READ', 'REL-9', true, false, true, 'off'),
            $this->definition('payment.iyzico.callback', 'INBOUND_CALLBACK', 'REL-9', true, false, true, 'journal_only'),
            $this->definition('audit.event.append', 'INTERNAL_ONLY', 'REL-10A', true, true, false, 'internal_only'),
            $this->definition('mail.incoming.health', 'EXTERNAL_READ', 'REL-11', false, false, true, 'off'),
            $this->definition('voibot.outbound', 'OUTBOUND_COMMUNICATION', 'REL-11', false, false, true, 'no_send'),
            $this->definition('voibot.inbound', 'INBOUND_CALLBACK', 'REL-11', false, false, true, 'journal_only'),
            $this->definition('parts.movement.intent', 'INTERNAL_ONLY', 'REL-12', true, true, false, 'internal_only'),
            $this->definition('crm.projection.refresh', 'BACKGROUND_AUTOMATION', 'REL-13', true, false, true, 'off'),
            $this->definition('survey.followup.plan', 'BACKGROUND_AUTOMATION', 'REL-14', true, false, true, 'off'),
            $this->definition('release.cutover.execute', 'INTERNAL_ONLY', 'REL-15', true, true, false, 'internal_only'),
            $this->definition('gateway.n8n.execute', 'EXTERNAL_MUTATION', 'INT-MIKRO', false, false, true, 'off'),
            $this->definition('erp.mikro.read', 'EXTERNAL_READ', 'INT-MIKRO', true, false, true, 'off'),
            $this->definition('erp.mikro.write', 'EXTERNAL_MUTATION', 'INT-MIKRO', true, false, true, 'off'),
            $this->definition('maps.google.routes', 'EXTERNAL_READ', 'FIELD-TRACK', false, false, true, 'off'),
        ];

        return collect($definitions)->keyBy('code')->all();
    }

    /**
     * The profile is server-owned. Request payloads may select neither its
     * capabilities nor its provider, event, channel, TTL, or send limits.
     *
     * @return array<string, mixed>
     */
    public function localAllowlistedUatProfile(): array
    {
        $profile = [
            'id' => self::LOCAL_ALLOWLISTED_UAT_PROFILE,
            'version' => 1,
            'production_ready' => false,
            'required_capabilities' => [
                self::MESSAGING_EVOLUTION_SEND,
                self::MESSAGING_NAC_SEND,
                self::MAIL_SMTP_SEND,
                self::PAYMENT_LOCAL_SANDBOX_EXECUTE,
            ],
            'messaging_events' => [
                'assignment_offer_technician' => [
                    'whatsapp' => 'evo_whatsapp',
                    'sms' => 'nac_sms',
                ],
                'appointment_approved_customer' => [
                    'whatsapp' => 'evo_whatsapp',
                    'sms' => 'nac_sms',
                ],
                'appointment_approved_technician' => [
                    'whatsapp' => 'evo_whatsapp',
                    'sms' => 'nac_sms',
                ],
                'customer_approval_request' => [
                    'whatsapp' => 'evo_whatsapp',
                ],
            ],
            'action_events' => [
                'sandbox_payment' => [
                    'channel' => 'sandbox_payment',
                    'providers' => ['fake_payment', 'iyzico_sandbox'],
                ],
                'sandbox_payment_notification' => [
                    'channel' => 'email',
                    'providers' => ['smtp'],
                ],
            ],
            'limits' => [
                'whatsapp' => 4,
                'sms' => 3,
                'email' => 1,
                'total' => 8,
                'max_seconds' => 3600,
            ],
            'max_ttl_seconds' => 3600,
            'ops_sms' => false,
            'sandbox_payment' => true,
            'real_payment' => false,
        ];

        return [
            ...$profile,
            'profile_fingerprint' => hash('sha256', json_encode($profile, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $code): ?array
    {
        return $this->definitions()[trim($code)] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(
        string $code,
        string $classification,
        string $ownerTrack,
        bool $required,
        bool $adapted,
        bool $modeGated,
        string $safeDefault,
    ): array {
        return [
            'code' => $code,
            'classification' => $classification,
            'owner_track' => $ownerTrack,
            'activation_class' => $required ? 'required' : 'optional',
            'required' => $required,
            'adapted' => $adapted,
            'mode_gated' => $modeGated,
            'capability_revision' => 1,
            'safe_default' => $safeDefault,
        ];
    }
}
