<?php

namespace App\Services\Messaging;

use App\Models\TechnicalServiceMessageTemplate;
use App\Services\TechnicalService\TechnicalServicePaymentOwnershipService;

class TechnicalServiceMessageTemplateRenderer
{
    public function __construct(
        private readonly TechnicalServiceMessageTypeRegistry $types,
        private readonly TechnicalServiceMessageVariableRegistry $variables,
    ) {}

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function render(array $template, array $context): array
    {
        $body = (string) ($template['body'] ?? '');
        $messageType = (string) ($template['message_type'] ?? '');
        $channel = (string) ($template['channel'] ?? TechnicalServiceMessageTemplate::CHANNEL_WHATSAPP);
        $placeholders = $this->placeholders($body);
        $templateRequired = $this->requiredFromTemplate($template);
        $required = $templateRequired === []
            ? $this->types->requiredVariables($messageType)
            : $templateRequired;
        $missing = $this->missingVariables($required, $context);
        $forbidden = array_values(array_intersect($placeholders, $this->variables->forbiddenVariables()));
        $unknown = array_values(array_diff($placeholders, array_keys($this->variables->definitions()), $this->variables->forbiddenVariables()));
        $unresolved = array_values(array_unique([...$missing, ...$unknown, ...$forbidden]));
        $rendered = $this->replacePlaceholders($body, $context);
        if ($channel === TechnicalServiceMessageTemplate::CHANNEL_SMS) {
            $rendered = $this->normalizeSmsText($rendered);
        }
        $warnings = [];
        $blockers = [];

        foreach ($missing as $variable) {
            $blockers[] = "Zorunlu değişken eksik: {$variable}.";
        }

        foreach ($unknown as $variable) {
            $blockers[] = "Bilinmeyen değişken: {$variable}.";
        }

        foreach ($forbidden as $variable) {
            $blockers[] = "Yasak değişken kullanılamaz: {$variable}.";
        }

        $this->appendRenderedTextBlockers($rendered, $blockers);
        $this->appendMessageTypeBlockers($messageType, $rendered, $context, $blockers, $warnings);
        $this->appendChannelBlockers($messageType, $channel, $placeholders, $context, $blockers);

        $sms = $channel === TechnicalServiceMessageTemplate::CHANNEL_SMS
            ? $this->smsMetadata($rendered, $warnings, $blockers)
            : null;

        if ($channel === TechnicalServiceMessageTemplate::CHANNEL_VOICE_SCRIPT) {
            $warnings[] = 'Voibot contract pending; bu sadece voice script önizlemesidir, çağrı yapılmaz.';
        }

        return [
            'rendered_body' => $rendered,
            'placeholders' => $placeholders,
            'required_variables' => $required,
            'missing_variables' => $missing,
            'unresolved_variables' => $unresolved,
            'warnings' => array_values(array_unique($warnings)),
            'blockers' => array_values(array_unique($blockers)),
            'sms' => $sms,
            'preview_ready' => $blockers === [],
            'send_ready' => false,
            'payer_state_key' => $context['payer_state_key'] ?? null,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function placeholders(string $body): array
    {
        preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $body, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * @param  array<string, mixed>  $template
     * @return array<int, string>
     */
    private function requiredFromTemplate(array $template): array
    {
        return collect($template['required_variables'] ?? [])
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => trim($value))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $required
     * @param  array<string, mixed>  $context
     * @return array<int, string>
     */
    private function missingVariables(array $required, array $context): array
    {
        return collect($required)
            ->filter(fn (string $variable): bool => ! array_key_exists($variable, $context) || $this->blank($context[$variable]))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function replacePlaceholders(string $body, array $context): string
    {
        $rendered = preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', function (array $matches) use ($context): string {
            $key = (string) $matches[1];
            $value = $context[$key] ?? null;

            if (! array_key_exists($key, $context)) {
                return $matches[0];
            }

            if ($this->blank($value)) {
                return '';
            }

            if (is_bool($value)) {
                return $value ? 'Evet' : 'Hayır';
            }

            if (is_scalar($value)) {
                return (string) $value;
            }

            return $matches[0];
        }, $body) ?? $body;

        $rendered = preg_replace('/[ \t]+/', ' ', trim($rendered)) ?? trim($rendered);

        return preg_replace("/(\r?\n){3,}/", "\n\n", $rendered) ?? $rendered;
    }

    private function normalizeSmsText(string $rendered): string
    {
        $lines = preg_split('/\r?\n/', trim($rendered)) ?: [];
        $lines = collect($lines)
            ->map(fn (string $line): string => preg_replace('/[ \t]{2,}/', ' ', trim($line)) ?? trim($line))
            ->filter(fn (string $line): bool => $line !== '')
            ->values()
            ->all();

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, string>  $blockers
     */
    private function appendRenderedTextBlockers(string $rendered, array &$blockers): void
    {
        if (str_contains($rendered, '{') || str_contains($rendered, '}')) {
            $blockers[] = 'Render çıktısında çözülmemiş değişken kaldı.';
        }

        if (preg_match('/\b(undefined|null|nan)\b/i', $rendered) === 1) {
            $blockers[] = 'Render çıktısında undefined/null/NaN ifadesi var.';
        }

        if (preg_match('/https?:\/\/\s*($|\.)/i', $rendered) === 1) {
            $blockers[] = 'Render çıktısında boş link var.';
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<int, string>  $blockers
     * @param  array<int, string>  $warnings
     */
    private function appendMessageTypeBlockers(string $messageType, string $rendered, array $context, array &$blockers, array &$warnings): void
    {
        $payerState = (string) ($context['payer_state_key'] ?? '');

        if (in_array($messageType, [
            'appointment_approved_customer',
            'appointment_updated_customer',
            'customer_approval_request',
            'future_survey_customer',
        ], true)) {
            $this->appendCustomerFacingLabelBlockers($rendered, $context, $blockers);
        }

        if (in_array($messageType, [
            'appointment_approved_customer',
            'appointment_updated_customer',
        ], true)) {
            if ($this->blank($context['appointment_customer_window'] ?? null)) {
                $blockers[] = 'Müşteri randevu aralığı belirlenmeli.';
            }

            if ($payerState === TechnicalServicePaymentOwnershipService::STATE_CUSTOMER_PAYS_TECHNICIAN
                && ($this->money($context['customer_payment_amount'] ?? null) <= 0.0
                    || $this->blank($context['customer_payment_amount_formatted'] ?? null))) {
                $blockers[] = 'Müşteri ustaya ödeme yapacaksa pozitif müşteri ödeme tutarı zorunlu.';
            }
        }

        if (in_array($messageType, [
            'appointment_approved_technician',
            'appointment_updated_technician',
            'assignment_offer_technician',
        ], true)) {
            if ($messageType !== 'assignment_offer_technician' && $this->blank($context['appointment_exact_time_range'] ?? null)) {
                $blockers[] = 'Usta mesajı için tam randevu saati gerekli.';
            }

            if ($this->blank($context['technician_job_card_url'] ?? null)) {
                $blockers[] = 'Usta mesajı için iş kartı linki zorunlu.';
            }

            if ($this->blank($context['maps_url'] ?? null)) {
                $warnings[] = 'Harita linki yok; harita satırı boş bırakıldı.';
            }
        }

        if ($messageType === 'payment_link_customer') {
            $this->appendCustomerFacingLabelBlockers($rendered, $context, $blockers);

            if ($this->blank($context['payment_link'] ?? null) && $this->blank($context['payment_link_sms'] ?? null)) {
                $blockers[] = 'Ödeme linki mesajı için payment_link zorunlu.';
            }

            if (in_array($payerState, [
                TechnicalServicePaymentOwnershipService::STATE_COMPANY_COLLECTED_ONLINE,
                TechnicalServicePaymentOwnershipService::STATE_COMPANY_COLLECTED_EXTERNAL,
            ], true)) {
                $blockers[] = 'Şirket tahsil etmişken müşteriye ödeme linki mesajı gönderilemez.';
            }
        }

        if ($messageType === 'customer_approval_request'
            && $this->blank($context['confirmation_link'] ?? null)
            && $this->blank($context['confirmation_link_sms'] ?? null)) {
            $blockers[] = 'Müşteri onayı mesajı için confirmation_link zorunlu.';
        }

        if ($messageType === 'customer_pays_technician_notice') {
            $this->appendCustomerFacingLabelBlockers($rendered, $context, $blockers);

            if ($payerState !== TechnicalServicePaymentOwnershipService::STATE_CUSTOMER_PAYS_TECHNICIAN) {
                $blockers[] = 'Ustaya ödeme bilgilendirmesi sadece customer_pays_technician durumunda hazır olur.';
            }

            if ($this->money($context['customer_payment_amount'] ?? null) <= 0.0
                || $this->blank($context['customer_payment_amount_formatted'] ?? null)) {
                $blockers[] = 'Ustaya ödeme mesajı için pozitif müşteri ödeme tutarı zorunlu.';
            }
        }

        if (in_array($payerState, [
            TechnicalServicePaymentOwnershipService::STATE_COMPANY_COLLECTED_ONLINE,
            TechnicalServicePaymentOwnershipService::STATE_COMPANY_COLLECTED_EXTERNAL,
        ], true) && $this->containsPayTechnicianPhrase($rendered)) {
            $blockers[] = 'Şirket tahsil etmişken müşteri mesajı ustaya ödeme talimatı içeremez.';
        }

        if (str_ends_with($messageType, '_ops') && (string) ($context['recipient_role'] ?? 'ops') === 'customer') {
            $blockers[] = 'OPS/internal şablonları müşteri alıcısına hazır sayılamaz.';
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<int, string>  $blockers
     */
    private function appendCustomerFacingLabelBlockers(string $rendered, array $context, array &$blockers): void
    {
        if (preg_match('/\b(MRN|SRV|Talep|Ödeme|Request|Job):/u', $rendered) === 1) {
            $blockers[] = 'Müşteri mesajı teknik alan etiketi içeremez: MRN:, SRV:, Talep:, Ödeme:, Request:, Job:.';
        }

        $hiddenMrn = $context['customer_hidden_internal_references']['mrn'] ?? null;
        if (is_string($hiddenMrn) && $hiddenMrn !== '' && str_contains($rendered, $hiddenMrn)) {
            $blockers[] = 'Servis/SRV müşteri mesajında iç MRN gösterilemez.';
        }

        $lower = mb_strtolower($rendered, 'UTF-8');
        foreach ([
            'hakediş' => 'Müşteri mesajı hakediş bilgisi içeremez.',
            'admin review' => 'Müşteri mesajı admin review/internal süreç metni içeremez.',
            'provider token' => 'Müşteri mesajı provider token içeremez.',
            'provider_reference' => 'Müşteri mesajı provider reference içeremez.',
            'raw_provider' => 'Müşteri mesajı raw provider alanı içeremez.',
            'raw json' => 'Müşteri mesajı raw JSON/error metni içeremez.',
            'internal_note' => 'Müşteri mesajı internal note içeremez.',
        ] as $needle => $message) {
            if (str_contains($lower, $needle)) {
                $blockers[] = $message;
            }
        }
    }

    /**
     * @param  array<int, string>  $placeholders
     * @param  array<int, string>  $blockers
     */
    private function appendChannelBlockers(string $messageType, string $channel, array $placeholders, array $context, array &$blockers): void
    {
        if ($channel !== TechnicalServiceMessageTemplate::CHANNEL_SMS) {
            return;
        }

        foreach (['address', 'maps_url', 'maps_url_line', 'technician_job_card_url', 'technician_earning_summary_block', 'technician_visible_note_block', 'payment_link', 'confirmation_link'] as $variable) {
            if (in_array($variable, $placeholders, true)) {
                $blockers[] = "SMS şablonunda uzun/ham değişken kullanılamaz: {$variable}.";
            }
        }

        if ($messageType === 'payment_link_customer' && $this->blank($context['payment_link_sms'] ?? null)) {
            $blockers[] = 'SMS ödeme mesajı için kısa ödeme linki zorunlu.';
        }

        if ($messageType === 'customer_approval_request' && $this->blank($context['confirmation_link_sms'] ?? null)) {
            $blockers[] = 'SMS müşteri onayı için kısa onay linki zorunlu.';
        }

        if (in_array($messageType, [
            'appointment_approved_technician',
            'appointment_updated_technician',
            'assignment_offer_technician',
            'earnings_message_technician',
        ], true) && $this->blank($context['technician_job_card_short_url'] ?? null)) {
            $blockers[] = 'Usta SMS mesajı için kısa iş kartı linki zorunlu.';
        }
    }

    /**
     * @param  array<int, string>  $warnings
     * @param  array<int, string>  $blockers
     * @return array<string, mixed>
     */
    private function smsMetadata(string $rendered, array &$warnings, array &$blockers): array
    {
        $characters = mb_strlen($rendered);
        $unicode = preg_match('/[^\x{0000}-\x{007F}]/u', $rendered) === 1;
        $singleLimit = $unicode ? 70 : 160;
        $multiLimit = $unicode ? 67 : 153;
        $segments = $characters <= $singleLimit ? 1 : (int) ceil($characters / $multiLimit);

        if ($unicode) {
            $warnings[] = 'SMS Türkçe/Unicode karakter içeriyor; segment sayısı artabilir.';
        }

        if ($segments > 1) {
            $warnings[] = "SMS {$segments} segment olarak hesaplandı.";
        }

        if ($segments >= 3) {
            $warnings[] = 'SMS 3+ segment; metin kısaltılmalı.';
        }

        if ($segments >= 4) {
            $blockers[] = 'SMS 4 veya daha fazla segment; admin override olmadan gönderilemez.';
        }

        if (str_contains($rendered, 'http')) {
            $warnings[] = 'SMS link içeriyor; link uzunluğu segment sayısını etkiler.';
        }

        return [
            'characters' => $characters,
            'segments' => $segments,
            'encoding' => $unicode ? 'unicode' : 'gsm',
            'contains_link' => str_contains($rendered, 'http'),
            'line_count' => substr_count($rendered, "\n") + 1,
        ];
    }

    private function containsPayTechnicianPhrase(string $rendered): bool
    {
        $text = mb_strtolower($rendered, 'UTF-8');
        $text = str_replace([
            'ustaya ayrıca ödeme yapmanız gerekmez',
            'ustaya ödeme yapmanız gerekmez',
        ], '', $text);

        foreach ([
            'ustaya ödeme',
            'ustaya öde',
            'ustaya ödemeniz',
            'çilingire ödeme',
        ] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function blank(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        return false;
    }

    private function money(mixed $value): float
    {
        return round((float) ($value ?? 0), 2);
    }
}
