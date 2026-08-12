<?php

namespace App\Services\Messaging;

use App\Models\TechnicalServiceMessageTemplate;

class TechnicalServiceMessageTypeRegistry
{
    public const CHANNELS = [
        TechnicalServiceMessageTemplate::CHANNEL_WHATSAPP => 'WhatsApp',
        TechnicalServiceMessageTemplate::CHANNEL_SMS => 'SMS',
        TechnicalServiceMessageTemplate::CHANNEL_VOICE_SCRIPT => 'Voice Script',
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        $definitions = [];

        foreach (TechnicalServiceMessagingSettingsService::MESSAGE_TYPES as $key => $definition) {
            $definitions[$key] = [
                'key' => $key,
                ...$definition,
                'allowed_channels' => $this->allowedChannels($key),
                'required_variables' => $this->requiredVariables($key),
                'optional_variables' => $this->optionalVariables($key),
                'payer_state_requirements' => $this->payerStateRequirements($key),
            ];
        }

        return $definitions;
    }

    public function knownMessageType(string $messageType): bool
    {
        return array_key_exists($messageType, TechnicalServiceMessagingSettingsService::MESSAGE_TYPES);
    }

    public function knownChannel(string $channel): bool
    {
        return array_key_exists($channel, self::CHANNELS);
    }

    public function knownProvider(?string $providerKey): bool
    {
        return $providerKey === null || array_key_exists($providerKey, TechnicalServiceMessagingSettingsService::PROVIDERS);
    }

    /**
     * @return array<int, string>
     */
    public function allowedChannels(string $messageType): array
    {
        if (str_contains($messageType, 'ops')) {
            return [TechnicalServiceMessageTemplate::CHANNEL_WHATSAPP, TechnicalServiceMessageTemplate::CHANNEL_SMS];
        }

        return [
            TechnicalServiceMessageTemplate::CHANNEL_WHATSAPP,
            TechnicalServiceMessageTemplate::CHANNEL_SMS,
            TechnicalServiceMessageTemplate::CHANNEL_VOICE_SCRIPT,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function requiredVariables(string $messageType): array
    {
        return match ($messageType) {
            'mount_request_created_customer' => [
                'customer_name',
                'customer_reference_phrase',
                'product_name',
            ],
            'new_request_created_ops' => [
                'internal_job_reference',
                'actor_name',
                'customer_name',
                'customer_phone',
                'product_name',
                'address',
                'next_action_text',
            ],
            'appointment_approved_customer',
            'appointment_updated_customer' => [
                'customer_name',
                $messageType === 'appointment_updated_customer'
                    ? 'customer_update_action_phrase'
                    : 'customer_appointment_action_phrase',
                'appointment_date_formatted',
                'appointment_customer_window',
            ],
            'appointment_approved_technician',
            'appointment_updated_technician' => [
                'mrn',
                'customer_name',
                'customer_phone',
                'address',
                'appointment_date_formatted',
                'appointment_exact_time_range',
                'technician_job_card_url',
            ],
            'appointment_cancelled_customer' => [
                'customer_name',
                'customer_reference_phrase',
                'cancellation_reason',
            ],
            'appointment_cancelled_technician' => [
                'mrn',
                'customer_name',
                'appointment_date_formatted',
                'appointment_exact_time_range',
                'cancellation_reason',
                'technician_job_card_url',
            ],
            'customer_approval_request' => [
                'customer_name',
                'customer_reference_phrase',
                'confirmation_link',
            ],
            'payment_link_customer' => [
                'customer_name',
                'customer_reference_phrase',
                'payment_link',
                'payment_amount_formatted',
            ],
            'payment_received_ops' => [
                'internal_job_reference',
                'customer_name',
                'customer_phone',
                'payment_amount_formatted',
                'payment_status_label',
            ],
            'customer_pays_technician_notice' => [
                'customer_name',
                'customer_reference_phrase',
                'customer_payment_amount_formatted',
            ],
            'assignment_offer_technician' => [
                'technician_name',
                'request_code',
                'customer_name',
                'customer_phone',
                'product_name',
                'serial_no',
                'address',
                'maps_url',
                'labor_amount_formatted',
                'route_fee_formatted',
                'technician_earning_total_formatted',
                'technician_earning_summary_text',
                'technician_job_card_url',
            ],
            'appointment_proposed_ops' => [
                'technician_name',
                'customer_name',
                'mrn',
                'proposed_appointment_options',
                'ops_next_action_text',
            ],
            'earnings_message_technician' => [
                'technician_name',
                'mrn',
                'technician_job_card_url',
                'labor_amount_formatted',
                'route_fee_formatted',
                'technician_earning_total_formatted',
                'technician_earning_summary_text',
                'technician_payment_model_label',
                'technician_payment_source_label',
                'technician_payment_status_label',
            ],
            'price_revision_response_technician' => [
                'mrn',
                'technician_job_card_url',
                'labor_amount_formatted',
                'route_fee_formatted',
                'technician_earning_total_formatted',
            ],
            'final_control_completed_customer' => [
                'customer_name',
                'customer_reference_phrase',
            ],
            'activation_code_customer' => [
                'customer_name',
                'customer_reference_phrase',
                'activation_code',
            ],
            'warranty_started_customer' => [
                'customer_name',
                'customer_reference_phrase',
                'warranty_started_at_formatted',
            ],
            'activation_warranty_customer' => [
                'customer_name',
                'customer_reference_phrase',
                'product_name',
                'serial_no',
                'activation_code',
                'warranty_started_at_formatted',
                'warranty_ends_at_formatted',
            ],
            'completion_submitted_ops' => [
                'internal_job_reference',
                'technician_name',
                'completed_at_formatted',
            ],
            'part_request_ops' => [
                'internal_job_reference',
                'actor_name',
                'part_name',
                'part_reason',
                'created_at_formatted',
                'next_action_text',
            ],
            'part_received_ops' => [
                'internal_job_reference',
                'technician_name',
                'part_name',
                'part_received_at_formatted',
                'next_action_text',
            ],
            'part_fee_payment_link_customer' => [
                'customer_name',
                'customer_reference_phrase',
                'payment_link',
                'payment_amount_formatted',
            ],
            'support_request_ops' => [
                'internal_job_reference',
                'actor_name',
                'support_subject',
                'support_note',
                'created_at_formatted',
            ],
            'job_rejected_ops' => [
                'internal_job_reference',
                'technician_name',
                'technician_phone',
                'rejection_reason',
                'rejected_at_formatted',
            ],
            'price_revision_requested_ops' => [
                'internal_job_reference',
                'actor_name',
                'old_amount_formatted',
                'requested_amount_formatted',
                'revision_reason',
            ],
            'future_survey_customer' => [
                'customer_name',
                'customer_reference_phrase',
                'survey_link',
            ],
            default => [],
        };
    }

    /**
     * @return array<int, string>
     */
    public function optionalVariables(string $messageType): array
    {
        $common = [
            'request_code',
            'mrn',
            'srv',
            'serial_no',
            'product_name',
            'brand',
            'model',
            'city',
            'district',
            'company_name',
        ];

        return array_values(array_unique([
            ...$common,
            ...match ($messageType) {
                'appointment_approved_customer',
                'appointment_updated_customer' => [
                    'appointment_slot_label',
                    'appointment_time_range',
                    'customer_job_type_label',
                    'customer_reference_code',
                    'customer_reference_phrase',
                    'customer_appointment_action_phrase',
                    'customer_update_action_phrase',
                    'customer_visible_note',
                    'customer_visible_note_block',
                    'customer_visible_note_line',
                ],
                'appointment_approved_technician',
                'appointment_updated_technician',
                'assignment_offer_technician' => [
                    'technician_phone',
                    'maps_url',
                    'maps_url_line',
                    'technician_visible_note',
                    'technician_visible_note_line',
                    'customer_payment_amount_formatted',
                    'appointment_slot_label',
                    'appointment_time_range',
                    'appointment_exact_time_range',
                    'appointment_window_for_technician',
                    'appointment_assignment_timing_text',
                    'technician_earning_summary_text',
                    'technician_earning_summary_block',
                    'technician_earning_sms_summary',
                    'company_payment_amount_formatted',
                    'labor_amount_formatted',
                    'route_fee_formatted',
                    'technician_earning_total_formatted',
                    'srv_line',
                    'technician_visible_note_block',
                ],
                'appointment_proposed_ops' => [
                    'technician_name',
                    'customer_name',
                    'proposed_appointment_options',
                    'technician_note',
                    'ops_next_action_text',
                    'internal_job_reference',
                    'created_at_formatted',
                ],
                'appointment_cancelled_customer',
                'final_control_completed_customer',
                'activation_code_customer',
                'warranty_started_customer',
                'activation_warranty_customer',
                'part_fee_payment_link_customer' => [
                    'customer_reference_code',
                    'customer_job_type_label',
                    'customer_payment_amount_formatted',
                    'payment_amount_formatted',
                    'payment_link',
                    'payment_link_sms',
                    'activation_code',
                    'warranty_started_at_formatted',
                    'warranty_ends_at_formatted',
                    'survey_link',
                    'customer_visible_note',
                    'customer_visible_note_block',
                ],
                'appointment_cancelled_technician',
                'price_revision_response_technician' => [
                    'technician_name',
                    'customer_phone',
                    'address',
                    'maps_url',
                    'maps_url_line',
                    'technician_job_card_short_url',
                    'labor_amount_formatted',
                    'route_fee_formatted',
                    'technician_earning_total_formatted',
                    'technician_earning_summary_block',
                    'srv_line',
                    'technician_visible_note_block',
                ],
                'earnings_message_technician' => [
                    'technician_name',
                    'technician_phone',
                    'technician_job_card_short_url',
                    'labor_amount_formatted',
                    'route_fee_formatted',
                    'company_payment_amount_formatted',
                    'technician_earning_total_formatted',
                    'technician_paid_amount_formatted',
                    'technician_remaining_amount_formatted',
                    'customer_collection_amount_formatted',
                    'customer_collection_source_label',
                    'technician_payment_model_label',
                    'technician_payment_source_label',
                    'technician_payment_status_label',
                    'technician_earning_summary_text',
                    'technician_earning_summary_block',
                    'technician_earning_sms_summary',
                    'srv_line',
                ],
                'customer_approval_request' => [
                    'technician_name',
                    'confirmation_link',
                    'confirmation_link_sms',
                    'customer_reference_phrase',
                ],
                'payment_link_customer' => [
                    'payment_link',
                    'payment_link_sms',
                    'payment_amount_formatted',
                    'customer_reference_phrase',
                    'customer_reference_code',
                    'customer_job_type_label',
                    'provider_name',
                ],
                'customer_pays_technician_notice' => [
                    'technician_name',
                    'technician_phone',
                    'customer_payment_amount',
                    'customer_reference_phrase',
                ],
                'new_request_created_ops',
                'completion_submitted_ops',
                'part_request_ops',
                'part_received_ops',
                'payment_received_ops',
                'support_request_ops',
                'job_rejected_ops',
                'price_revision_requested_ops' => [
                    'internal_job_reference',
                    'actor_name',
                    'technician_name',
                    'customer_name',
                    'customer_phone',
                    'serial_no',
                    'payment_link',
                    'payment_amount_formatted',
                    'payment_status_label',
                    'provider_payment_reference',
                    'provider_transaction_reference',
                    'provider_receipt_reference',
                    'support_subject',
                    'support_note',
                    'created_at_formatted',
                    'rejection_reason',
                    'rejected_at_formatted',
                    'old_amount_formatted',
                    'requested_amount_formatted',
                    'revision_reason',
                    'completed_at_formatted',
                    'next_action_text',
                    'part_name',
                    'part_code',
                    'part_quantity',
                    'part_reason',
                    'part_details',
                    'part_received_at_formatted',
                ],
                default => [],
            },
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function payerStateRequirements(string $messageType): array
    {
        return match ($messageType) {
            'payment_link_customer',
            'part_fee_payment_link_customer' => [
                'requires_payment_link' => true,
                'requires_selected_payment_id' => true,
                'requires_selected_payment_status' => 'pending',
            ],
            'customer_pays_technician_notice' => [
                'requires_customer_pays_technician' => true,
                'requires_amount' => true,
            ],
            'appointment_approved_customer',
            'appointment_updated_customer' => [
                'requires_appointment_window' => true,
            ],
            'appointment_approved_technician',
            'appointment_updated_technician',
            'assignment_offer_technician' => [
                'requires_job_card_url' => true,
                'requires_exact_appointment_time' => $messageType !== 'assignment_offer_technician',
            ],
            default => [],
        };
    }

    public function defaultTemplateKey(string $messageType, string $channel): string
    {
        return "{$messageType}.{$channel}.default";
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultTemplate(string $messageType, string $channel, ?string $providerKey = null): array
    {
        $definition = $this->definitions()[$messageType] ?? null;
        $requiredVariables = $this->requiredVariables($messageType);
        $optionalVariables = $this->optionalVariables($messageType);

        if ($channel === TechnicalServiceMessageTemplate::CHANNEL_SMS) {
            $requiredVariables = $this->smsRequiredVariables($messageType, $requiredVariables);
            $optionalVariables = array_values(array_unique([
                ...$optionalVariables,
                ...$this->smsOptionalVariables(),
            ]));
        }

        return [
            'template_key' => $this->defaultTemplateKey($messageType, $channel),
            'message_type' => $messageType,
            'channel' => $channel,
            'provider_key' => $providerKey,
            'title' => $this->defaultTitle($messageType, $channel, $definition['label'] ?? $messageType),
            'body' => $this->defaultBody($messageType, $channel),
            'active' => true,
            'locale' => 'tr',
            'version' => 1,
            'required_variables' => $requiredVariables,
            'optional_variables' => $optionalVariables,
            'validation_rules' => $this->payerStateRequirements($messageType),
            'metadata' => [
                'source' => 'code_default',
                'send_enabled' => false,
            ],
        ];
    }

    private function defaultBody(string $messageType, string $channel): string
    {
        if ($channel === TechnicalServiceMessageTemplate::CHANNEL_VOICE_SCRIPT) {
            return match ($messageType) {
                'mount_request_created_customer' => 'Merhaba {customer_name}. {customer_reference_phrase} montaj talebiniz alınmıştır. Ürün {product_name}. Operasyon ekibimiz süreci takip edecektir. Bu sadece sesli arama script önizlemesidir.',
                'appointment_approved_customer' => 'Merhaba {customer_name}. EMAKS Prime Teknik Servis’ten arıyoruz. {customer_appointment_action_phrase} Randevunuz {appointment_date_formatted} tarihinde {appointment_customer_window}. Bu sadece sesli arama script önizlemesidir.',
                'appointment_updated_customer' => 'Merhaba {customer_name}. EMAKS Prime Teknik Servis’ten arıyoruz. {customer_update_action_phrase} Yeni randevunuz {appointment_date_formatted} tarihinde {appointment_customer_window}. Bu sadece sesli arama script önizlemesidir.',
                'appointment_approved_technician' => 'Merhaba {technician_name}. Yeni iş kartı hazır. İş {internal_job_reference}. Müşteri {customer_name}. Randevu {appointment_date_formatted} {appointment_exact_time_range}. İş kartı bağlantısı {technician_job_card_url}. Bu sadece sesli arama script önizlemesidir.',
                'appointment_updated_technician' => 'Merhaba {technician_name}. İş kartı randevusu güncellendi. İş {internal_job_reference}. Müşteri {customer_name}. Yeni randevu {appointment_date_formatted} {appointment_exact_time_range}. İş kartı bağlantısı {technician_job_card_url}. Bu sadece sesli arama script önizlemesidir.',
                'customer_approval_request' => 'Merhaba {customer_name}. {customer_reference_phrase} işleminiz için servis tamamlandı bilgisi alınmıştır. Onay bağlantısı mesaj olarak paylaşılır. Bu sadece sesli arama script önizlemesidir.',
                'payment_link_customer' => 'Merhaba {customer_name}. {customer_reference_phrase} işleminiz için ödeme bağlantısı mesaj olarak paylaşılır. Tutar {payment_amount_formatted}. Bu sadece sesli arama script önizlemesidir.',
                'payment_received_ops' => 'Ödeme alındı. İş {internal_job_reference}. Müşteri {customer_name}. Tutar {payment_amount_formatted}. Bu sadece OPS script önizlemesidir.',
                'customer_pays_technician_notice' => 'Merhaba {customer_name}. {customer_reference_phrase} işleminizde randevu sırasında ustaya ödenecek tutar {customer_payment_amount_formatted}. Bu sadece sesli arama script önizlemesidir.',
                'appointment_cancelled_customer' => 'Merhaba {customer_name}. EMAKS Prime Teknik Servis’ten arıyoruz. {customer_reference_phrase} randevunuz iptal edilmiştir. Detay için operasyon ekibimiz sizinle iletişime geçecektir. Bu sadece sesli arama script önizlemesidir.',
                'final_control_completed_customer' => 'Merhaba {customer_name}. {customer_reference_phrase} işleminizin son kontrolü tamamlanmıştır. Bu sadece sesli arama script önizlemesidir.',
                'activation_code_customer' => 'Merhaba {customer_name}. {customer_reference_phrase} işleminiz için aktivasyon kodunuz mesaj olarak paylaşılır. Bu sadece sesli arama script önizlemesidir.',
                'warranty_started_customer' => 'Merhaba {customer_name}. {customer_reference_phrase} işleminizin garanti başlangıç bilgisi mesaj olarak paylaşılır. Bu sadece sesli arama script önizlemesidir.',
                'activation_warranty_customer' => 'Merhaba {customer_name}. {customer_reference_phrase} işleminizin aktivasyon ve garanti bilgileri tek mesajda paylaşılır. Bu sadece sesli arama script önizlemesidir.',
                'part_fee_payment_link_customer' => 'Merhaba {customer_name}. {customer_reference_phrase} işleminiz için parça ücreti ödeme bağlantısı mesaj olarak paylaşılır. Tutar {payment_amount_formatted}. Bu sadece sesli arama script önizlemesidir.',
                'part_received_ops' => 'Parça teslim alındı. İş {internal_job_reference}. Usta {technician_name}. Parça {part_name}. Bu sadece OPS script önizlemesidir.',
                'future_survey_customer' => 'Merhaba {customer_name}. {customer_reference_phrase} işleminiz sonrası memnuniyet anketi bağlantısı paylaşılır. Bu sadece gelecek Voibot script önizlemesidir.',
                default => 'Merhaba. EMAKS Prime Teknik Servis operasyon bilgilendirme script önizlemesi. İş referansı {internal_job_reference}. Gerçek Voibot çağrısı yapılmaz.',
            };
        }

        return match ($messageType) {
            'mount_request_created_customer' => $channel === TechnicalServiceMessageTemplate::CHANNEL_SMS
                ? "EMAKS Prime\nMontaj talebiniz alindi.\nMRN: {mrn}\nUrun: {product_sms_label}\nEkibimiz sizinle iletisime gececek."
                : "EMAKS Prime Teknik Servis\n\nSayın {customer_name},\n{customer_reference_phrase} montaj talebiniz alınmıştır.\n\nÜrün: {product_name}\n\nOperasyon ekibimiz talebinizi inceleyerek sonraki adım için sizinle iletişime geçecektir.",
            'new_request_created_ops' => $channel === TechnicalServiceMessageTemplate::CHANNEL_SMS
                ? "EMAKS OPS\nYeni teknik servis talebi.\nİş: {internal_job_reference}\nMüşteri: {customer_name}\nÜrün: {product_name}\nAksiyon: {next_action_text}"
                : "EMAKS Prime Teknik Servis\n\nYeni teknik servis talebi oluşturuldu.\n\nİş: {internal_job_reference}\nTalebi Açan: {actor_name}\nMüşteri: {customer_name}\nTelefon: {customer_phone}\nÜrün: {product_name}\nAdres / Bölge: {address}\n\nSonraki Aksiyon: {next_action_text}",
            'appointment_approved_customer' => $channel === TechnicalServiceMessageTemplate::CHANNEL_SMS
                ? "EMAKS Prime\n{customer_appointment_action_phrase}\nTarih: {appointment_date_formatted}\nAralık: {appointment_customer_window}"
                : "EMAKS Prime Teknik Servis\n\nSayın {customer_name},\n{customer_appointment_action_phrase}\n\nRandevu Bilgileri\nTarih: {appointment_date_formatted}\nSaat Aralığı: {appointment_customer_window}\n\nRandevu aralığında adreste olunmasını rica ederiz.\n{customer_visible_note_block}",
            'appointment_approved_technician' => $channel === TechnicalServiceMessageTemplate::CHANNEL_SMS
                ? "EMAKS\nİş {mrn}\nMüşteri {customer_name}\nTel {customer_phone}\nRandevu {appointment_date_formatted} {appointment_exact_time_range}\nKart {technician_job_card_short_url}"
                : "EMAKS Prime Teknik Servis\n\nYeni iş kartı hazır.\n\nServis Kaydı\nMRN: {mrn}\n{srv_line}\n\nMüşteri Bilgileri\nMüşteri: {customer_name}\nTelefon: {customer_phone}\nAdres: {address}\n{maps_url_line}\n\nRandevu\n{appointment_date_formatted} {appointment_exact_time_range}\n\nİş Kartı\n{technician_job_card_url}\n\n{technician_earning_summary_block}\n{technician_visible_note_block}",
            'appointment_updated_customer' => $channel === TechnicalServiceMessageTemplate::CHANNEL_SMS
                ? "EMAKS Prime\n{customer_update_action_phrase}\nTarih: {appointment_date_formatted}\nAralık: {appointment_customer_window}"
                : "EMAKS Prime Teknik Servis\n\nSayın {customer_name},\n{customer_update_action_phrase}\n\nYeni Randevu Bilgileri\nTarih: {appointment_date_formatted}\nSaat Aralığı: {appointment_customer_window}\n\nRandevu aralığında adreste olunmasını rica ederiz.\n{customer_visible_note_block}",
            'appointment_updated_technician' => $channel === TechnicalServiceMessageTemplate::CHANNEL_SMS
                ? "EMAKS\nRandevu güncellendi\n{mrn}\nMüşteri {customer_name}\n{appointment_date_formatted} {appointment_exact_time_range}\nKart {technician_job_card_short_url}"
                : "EMAKS Prime Teknik Servis\n\nServis randevusu güncellendi.\n\nServis Kaydı\nMRN: {mrn}\n{srv_line}\n\nMüşteri Bilgileri\nMüşteri: {customer_name}\nTelefon: {customer_phone}\nAdres: {address}\n{maps_url_line}\n\nRandevu\n{appointment_date_formatted} {appointment_exact_time_range}\n\nİş Kartı\n{technician_job_card_url}\n\n{technician_visible_note_block}",
            'appointment_cancelled_customer' => $channel === TechnicalServiceMessageTemplate::CHANNEL_SMS
                ? "EMAKS Prime\n{customer_reference_phrase} işiniz iptal edilmiştir.\nNeden: {cancellation_reason}"
                : "EMAKS Prime Teknik Servis\n\nSayın {customer_name},\n{customer_reference_phrase} işiniz iptal edilmiştir.\nRandevu: {appointment_date_formatted} {appointment_exact_time_range}\nNeden: {cancellation_reason}\n\nDetay için operasyon ekibimiz sizinle iletişime geçecektir.",
            'appointment_cancelled_technician' => $channel === TechnicalServiceMessageTemplate::CHANNEL_SMS
                ? "EMAKS Prime\nRandevu iptal edildi.\nMRN: {mrn}\n{srv_line}\nKart {technician_job_card_short_url}"
                : "EMAKS Prime Teknik Servis\n\nİş/randevu iptal edildi.\n\nMRN: {mrn}\n{srv_line}\nMüşteri: {customer_name}\nRandevu: {appointment_date_formatted} {appointment_exact_time_range}\nNeden: {cancellation_reason}\n\nİş Kartı\n{technician_job_card_url}",
            'customer_approval_request' => $channel === TechnicalServiceMessageTemplate::CHANNEL_SMS
                ? "EMAKS Prime\n{customer_reference_phrase} işleminizi onaylamak için:\n{confirmation_link_sms}"
                : "EMAKS Prime Teknik Servis\n\nSayın {customer_name},\n{customer_reference_phrase} işleminiz için servis tamamlandı bilgisi alınmıştır.\n\nİşlemi kontrol edip onaylamak için:\n{confirmation_link}",
            'payment_link_customer' => $channel === TechnicalServiceMessageTemplate::CHANNEL_SMS
                ? "EMAKS Prime\n{customer_reference_phrase} için ödeme bağlantınız:\n{payment_link_sms}\nTutar: {payment_amount_formatted}"
                : "EMAKS Prime Teknik Servis\n\nSayın {customer_name},\n{customer_reference_phrase} işleminiz için ödeme bağlantınız aşağıdadır.\n\nTutar: {payment_amount_formatted}\nÖdeme Bağlantısı:\n{payment_link}",
            'payment_received_ops' => $channel === TechnicalServiceMessageTemplate::CHANNEL_SMS
                ? "EMAKS OPS\nÖdeme alındı.\nİş: {internal_job_reference}\nTutar: {payment_amount_formatted}\nDurum: {payment_status_label}"
                : "EMAKS Prime Teknik Servis\n\nÖdeme alındı.\n\nİş: {internal_job_reference}\nMüşteri: {customer_name}\nTelefon: {customer_phone}\nSeri No: {serial_no}\nTutar: {payment_amount_formatted}\nÖdeme Linki:\n{payment_link}\nProvider Ödeme No: {provider_payment_reference}\nProvider İşlem No: {provider_transaction_reference}\nDekont/Referans: {provider_receipt_reference}\nDurum: {payment_status_label}",
            'part_fee_payment_link_customer' => $channel === TechnicalServiceMessageTemplate::CHANNEL_SMS
                ? "EMAKS Prime\n{customer_reference_phrase} parça ücreti ödeme linki:\n{payment_link_sms}\nTutar: {payment_amount_formatted}"
                : "EMAKS Prime Teknik Servis\n\nSayın {customer_name},\n{customer_reference_phrase} işleminiz için parça ücreti ödeme bağlantınız aşağıdadır.\n\nTutar: {payment_amount_formatted}\nÖdeme Bağlantısı:\n{payment_link}",
            'customer_pays_technician_notice' => $channel === TechnicalServiceMessageTemplate::CHANNEL_SMS
                ? "EMAKS Prime\n{customer_reference_phrase}\nUstaya ödenecek tutar: {customer_payment_amount_formatted}"
                : "EMAKS Prime Teknik Servis\n\nSayın {customer_name},\n{customer_reference_phrase} işleminizde randevu sırasında ustaya ödenecek tutar:\n\n{customer_payment_amount_formatted}\n\nRandevu aralığında adreste olunmasını rica ederiz.",
            'assignment_offer_technician' => $channel === TechnicalServiceMessageTemplate::CHANNEL_SMS
                ? "EMAKS Prime\n{request_code} işi size atandı.\nMüşteri: {sms_customer_name}\nÜrün: {product_sms_label}\n{technician_earning_sms_summary}\nİş kartı: {technician_job_card_short_url}"
                : "Merhaba {technician_name},\n\n{request_code} numaralı servis işi size atanmıştır.\n\nMüşteri: {customer_name}\nTelefon: {customer_phone}\nAdres: {address}\nHarita:\n{maps_url}\nÜrün: {product_name}\nSeri No: {serial_no}\n\n{technician_earning_summary_block}\n\nİş kartınız:\n{technician_job_card_url}\n\nLütfen randevu saati öneriniz.",
            'appointment_proposed_ops' => $channel === TechnicalServiceMessageTemplate::CHANNEL_SMS
                ? "EMAKS OPS\nUsta randevu önerdi.\nMRN: {mrn}\nUsta: {technician_name}\nZaman: {proposed_appointment_options}"
                : "EMAKS Prime Teknik Servis\n\nUsta randevu önerdi.\n\nUsta: {technician_name}\nMüşteri: {customer_name}\nMRN: {mrn}\nÖnerilen zaman: {proposed_appointment_options}\nNot: {technician_note}\n\nOPS aksiyonu: {ops_next_action_text}",
            'earnings_message_technician' => $channel === TechnicalServiceMessageTemplate::CHANNEL_SMS
                ? "EMAKS Prime\n\n{mrn} numaralı iş için hakedişiniz güncellendi.\n{technician_earning_sms_summary}\nİş kartı: {technician_job_card_short_url} B028"
                : "Merhaba {technician_name},\n\n{mrn} numaralı iş için hakedişiniz güncellendi.\n\n{technician_earning_summary_block}\n\nİş kartınız:\n{technician_job_card_url}",
            'price_revision_response_technician' => $channel === TechnicalServiceMessageTemplate::CHANNEL_SMS
                ? "EMAKS Prime\nHakediş revize edildi.\nMRN: {mrn}\nToplam: {technician_earning_total_formatted}\nKart: {technician_job_card_short_url}"
                : "EMAKS Prime Teknik Servis\n\nHakediş revizyon cevabı hazır.\n\nMRN: {mrn}\n{srv_line}\nİş Kartı: {technician_job_card_url}\n\nHakediş Özeti\nİşçilik/Montaj: {labor_amount_formatted}\nYol: {route_fee_formatted}\nToplam: {technician_earning_total_formatted}",
            'final_control_completed_customer' => $channel === TechnicalServiceMessageTemplate::CHANNEL_SMS
                ? "EMAKS Prime\n{customer_reference_phrase} işleminizin son kontrolü tamamlandı."
                : "EMAKS Prime Teknik Servis\n\nSayın {customer_name},\n{customer_reference_phrase} işleminizin son kontrolü tamamlanmıştır.\n\nBizi tercih ettiğiniz için teşekkür ederiz.",
            'activation_code_customer' => $channel === TechnicalServiceMessageTemplate::CHANNEL_SMS
                ? "EMAKS Prime\n{customer_reference_phrase} aktivasyon kodu: {activation_code}"
                : "EMAKS Prime Teknik Servis\n\nSayın {customer_name},\n{customer_reference_phrase} işleminiz için aktivasyon kodunuz:\n\n{activation_code}",
            'warranty_started_customer' => $channel === TechnicalServiceMessageTemplate::CHANNEL_SMS
                ? "EMAKS Prime\n{customer_reference_phrase} garanti başlangıcı: {warranty_started_at_formatted}"
                : "EMAKS Prime Teknik Servis\n\nSayın {customer_name},\n{customer_reference_phrase} için garanti başlangıç tarihi:\n\n{warranty_started_at_formatted}\n{warranty_ends_at_formatted}",
            'activation_warranty_customer' => $channel === TechnicalServiceMessageTemplate::CHANNEL_SMS
                ? "EMAKS Prime\n{customer_reference_phrase}\nAktivasyon: {activation_code}\nGaranti: {warranty_started_at_formatted} - {warranty_ends_at_formatted}"
                : "EMAKS Prime Teknik Servis\n\nSayın {customer_name},\n{customer_reference_phrase} işleminiz tamamlanmıştır.\n\nÜrün Bilgileri\nÜrün: {product_name}\nSeri No: {serial_no}\nAktivasyon Kodu: {activation_code}\n\nGaranti Bilgileri\nBaşlangıç: {warranty_started_at_formatted}\nBitiş: {warranty_ends_at_formatted}",
            'completion_submitted_ops' => $channel === TechnicalServiceMessageTemplate::CHANNEL_SMS
                ? "EMAKS OPS\nUsta işi tamamladı.\nİş: {internal_job_reference}\nUsta: {technician_name}\nTarih: {completed_at_formatted}"
                : "EMAKS Prime Teknik Servis\n\nUsta işi tamamladığını bildirdi.\n\nİş: {internal_job_reference}\nUsta: {technician_name}\nTamamlama Tarihi: {completed_at_formatted}\nSonraki Aksiyon: OPS son kontrol / müşteri onayı",
            'part_request_ops' => $channel === TechnicalServiceMessageTemplate::CHANNEL_SMS
                ? "EMAKS OPS\nParça talebi.\nİş: {internal_job_reference}\nParça: {part_name}\nAksiyon: {next_action_text}"
                : "EMAKS Prime Teknik Servis\n\nParça talebi oluştu.\n\nİş: {internal_job_reference}\nTalep Eden: {actor_name}\nParça: {part_name}\nAdet: {part_quantity}\nNeden: {part_reason}\nTarih: {created_at_formatted}\nSonraki Aksiyon: {next_action_text}",
            'part_received_ops' => $channel === TechnicalServiceMessageTemplate::CHANNEL_SMS
                ? "EMAKS OPS\nParça teslim alındı.\nİş: {internal_job_reference}\nParça: {part_name}\nAksiyon: {next_action_text}"
                : "EMAKS Prime Teknik Servis\n\nUsta parçayı teslim aldı.\n\nİş: {internal_job_reference}\nUsta: {technician_name}\nParça: {part_name}\nDetay: {part_details}\nTeslim Alma: {part_received_at_formatted}\nSonraki Aksiyon: {next_action_text}",
            'support_request_ops' => $channel === TechnicalServiceMessageTemplate::CHANNEL_SMS
                ? "EMAKS OPS\nDestek talebi.\nİş: {internal_job_reference}\nTalep Eden: {actor_name}\nKonu: {support_subject}"
                : "EMAKS Prime Teknik Servis\n\nDestek talebi oluştu.\n\nİş: {internal_job_reference}\nTalep Eden: {actor_name}\nKonu: {support_subject}\nAçıklama: {support_note}\nTarih: {created_at_formatted}",
            'job_rejected_ops' => $channel === TechnicalServiceMessageTemplate::CHANNEL_SMS
                ? "EMAKS OPS\nUsta işi reddetti.\nİş: {internal_job_reference}\nUsta: {technician_name}\nNeden: {rejection_reason}\nTarih: {rejected_at_formatted}\nAksiyon: Yeniden atama."
                : "EMAKS Prime Teknik Servis\n\nUsta işi reddetti.\n\nİş: {internal_job_reference}\nUsta: {technician_name}\nTelefon: {technician_phone}\nReddetme Nedeni: {rejection_reason}\nReddetme Tarihi: {rejected_at_formatted}\n\nSonraki Aksiyon:\nYeniden atama yapılmalı.",
            'price_revision_requested_ops' => $channel === TechnicalServiceMessageTemplate::CHANNEL_SMS
                ? "EMAKS OPS\nFiyat revizyonu.\nİş: {internal_job_reference}\nTalep Eden: {actor_name}\nYeni: {requested_amount_formatted}"
                : "EMAKS Prime Teknik Servis\n\nFiyat revizyonu istendi.\n\nİş: {internal_job_reference}\nTalep Eden: {actor_name}\nÖnceki Tutar: {old_amount_formatted}\nYeni Talep: {requested_amount_formatted}\nAçıklama: {revision_reason}",
            'future_survey_customer' => $channel === TechnicalServiceMessageTemplate::CHANNEL_SMS
                ? "EMAKS Prime\n{customer_reference_phrase} işleminiz için memnuniyet anketi:\n{survey_link}"
                : "EMAKS Prime Teknik Servis\n\nSayın {customer_name},\n{customer_reference_phrase} işleminiz için memnuniyet anketi bağlantısı aşağıdadır.\n\n{survey_link}",
            default => 'Teknik servis bilgilendirme: {request_code}.',
        };
    }

    private function defaultTitle(string $messageType, string $channel, string $fallback): string
    {
        $channelLabel = self::CHANNELS[$channel] ?? $channel;

        return match ($messageType) {
            'mount_request_created_customer' => "Müşteri montaj talebi alındı - {$channelLabel}",
            'new_request_created_ops' => "OPS yeni teknik servis talebi - {$channelLabel}",
            'appointment_approved_customer' => "Müşteri randevu onayı - {$channelLabel}",
            'appointment_updated_customer' => "Müşteri randevu güncelleme - {$channelLabel}",
            'appointment_approved_technician' => "Usta randevu bildirimi - {$channelLabel}",
            'appointment_updated_technician' => "Usta randevu güncelleme - {$channelLabel}",
            'assignment_offer_technician' => "Usta iş ataması - {$channelLabel}",
            'appointment_proposed_ops' => "OPS randevu önerisi - {$channelLabel}",
            'payment_received_ops' => "Ödeme alındı / OPS bildirimi - {$channelLabel}",
            'earnings_message_technician' => "Usta hakediş bilgilendirme - {$channelLabel}",
            'price_revision_response_technician' => "Usta hakediş revizyon cevabı - {$channelLabel}",
            'final_control_completed_customer' => "Müşteri son kontrol tamamlandı - {$channelLabel}",
            'activation_code_customer' => "Müşteri aktivasyon kodu - {$channelLabel}",
            'warranty_started_customer' => "Müşteri garanti başlangıcı - {$channelLabel}",
            'activation_warranty_customer' => "Aktivasyon ve garanti bilgilendirmesi - {$channelLabel}",
            'part_request_ops' => "OPS parça talebi - {$channelLabel}",
            'part_received_ops' => "Parça teslim alındı / OPS bildirimi - {$channelLabel}",
            'part_fee_payment_link_customer' => "Parça ücreti ödeme bağlantısı - {$channelLabel}",
            default => "{$fallback} - {$channelLabel}",
        };
    }

    /**
     * @param  array<int, string>  $requiredVariables
     * @return array<int, string>
     */
    private function smsRequiredVariables(string $messageType, array $requiredVariables): array
    {
        return match ($messageType) {
            'mount_request_created_customer' => [
                'mrn',
                'product_sms_label',
            ],
            'appointment_approved_technician',
            'appointment_updated_technician' => [
                'mrn',
                'customer_name',
                'customer_phone',
                'appointment_date_formatted',
                'appointment_exact_time_range',
                'technician_job_card_short_url',
            ],
            'appointment_cancelled_technician' => [
                'mrn',
                'technician_job_card_short_url',
            ],
            'assignment_offer_technician' => [
                'mrn',
                'sms_customer_name',
                'product_sms_label',
                'technician_earning_total_formatted',
                'technician_job_card_short_url',
            ],
            'appointment_proposed_ops' => [
                'technician_name',
                'mrn',
                'proposed_appointment_options',
            ],
            'payment_link_customer',
            'part_fee_payment_link_customer' => [
                'customer_reference_phrase',
                'payment_link_sms',
                'payment_amount_formatted',
            ],
            'customer_approval_request' => [
                'customer_reference_phrase',
                'confirmation_link_sms',
            ],
            'earnings_message_technician' => [
                'mrn',
                'labor_amount_formatted',
                'route_fee_formatted',
                'technician_earning_total_formatted',
                'technician_earning_sms_summary',
                'technician_payment_source_label',
                'technician_payment_status_label',
                'technician_job_card_short_url',
            ],
            'price_revision_response_technician' => [
                'mrn',
                'technician_earning_total_formatted',
                'technician_job_card_short_url',
            ],
            'activation_code_customer' => [
                'customer_reference_phrase',
                'activation_code',
            ],
            'warranty_started_customer' => [
                'customer_reference_phrase',
                'warranty_started_at_formatted',
            ],
            'activation_warranty_customer' => [
                'customer_reference_phrase',
                'activation_code',
                'warranty_started_at_formatted',
                'warranty_ends_at_formatted',
            ],
            default => $requiredVariables,
        };
    }

    /**
     * @return array<int, string>
     */
    private function smsOptionalVariables(): array
    {
        return [
            'payment_link_sms',
            'confirmation_link_sms',
            'survey_link_sms',
            'technician_job_card_short_url',
            'sms_payment_line',
            'sms_short_address',
            'sms_customer_name',
            'sms_service_address',
            'product_sms_label',
            'sms_title',
            'sms_custom_id',
            'payment_amount_formatted',
        ];
    }
}
