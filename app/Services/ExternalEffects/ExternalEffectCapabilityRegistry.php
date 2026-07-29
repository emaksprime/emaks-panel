<?php

namespace App\Services\ExternalEffects;

final class ExternalEffectCapabilityRegistry
{
    public const VERSION = 1;

    public const MESSAGING_EVOLUTION_SEND = 'messaging.evo.send';

    public const MESSAGING_NAC_SEND = 'messaging.nac.send';

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
            $this->definition('mail.smtp.send', 'OUTBOUND_COMMUNICATION', 'REL-8', false, false, true, 'no_send'),
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
