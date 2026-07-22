<?php

namespace App\Services\Messaging;

class TechnicalServiceMessageVariableRegistry
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        return [
            'customer_name' => $this->definition('Müşteri adı', 'Mesaj alıcısı müşteri adı.', 'PR88 Test Müşteri', 'technical_service_request.customer_name'),
            'sms_customer_name' => $this->definition('SMS müşteri adı', 'SMS segment güvenliği için ASCII normalize müşteri adı.', 'PR88 Test Musteri', 'context_builder'),
            'customer_phone' => $this->definition('Müşteri telefonu', 'Normalize müşteri telefonu.', '905555555555', 'technical_service_request.customer_phone'),
            'request_code' => $this->definition('Talep kodu', 'MRN/SRV için genel talep kodu.', 'MRN-REL4C-0001', 'technical_service_request.mrn'),
            'mrn' => $this->definition('MRN', 'Ana talep numarası.', 'MRN-REL4C-0001', 'technical_service_request.mrn'),
            'srv' => $this->definition('SRV', 'Servis kodu varsa kullanılır.', 'SRV-REL4C', 'technical_service_request.service_code'),
            'job_type' => $this->definition('İş tipi', 'Müşteri referans cümlesi için montaj/servis/genel ayrımı.', 'montaj', 'context_builder'),
            'customer_job_type_label' => $this->definition('Müşteri iş tipi', 'Müşteriye gösterilecek doğal iş tipi.', 'montaj', 'customer_reference_builder'),
            'customer_reference_code' => $this->definition('Müşteri referans kodu', 'Müşteriye gösterilecek MRN veya SRV kodu.', 'MRN-REL4C-0001', 'customer_reference_builder'),
            'customer_reference_phrase' => $this->definition('Müşteri referans cümlesi', 'MRN/SRV değerini doğal cümle içinde taşıyan ifade.', 'MRN-REL4C-0001 numaralı montaj', 'customer_reference_builder'),
            'customer_appointment_action_phrase' => $this->definition('Müşteri randevu onay cümlesi', 'Teknik alan adı göstermeyen doğal onay cümlesi.', 'MRN-REL4C-0001 numaralı montaj randevunuz onaylanmıştır.', 'customer_reference_builder'),
            'customer_update_action_phrase' => $this->definition('Müşteri randevu güncelleme cümlesi', 'Teknik alan adı göstermeyen doğal güncelleme cümlesi.', 'MRN-REL4C-0001 numaralı montaj randevunuz güncellenmiştir.', 'customer_reference_builder'),
            'customer_record_created_phrase' => $this->definition('Müşteri kayıt cümlesi', 'Teknik alan adı göstermeyen doğal kayıt cümlesi.', 'MRN-REL4C-0001 numaralı montaj randevu kaydınız oluşturulmuştur.', 'customer_reference_builder'),
            'serial_no' => $this->definition('Seri no', 'Ürün seri numarası.', 'PR88-REL4C-SERIAL', 'technical_service_request.serial_number'),
            'product_name' => $this->definition('Ürün', 'Ürün adı.', 'Çelik kapı kilidi', 'technical_service_request.product_name'),
            'product_sms_label' => $this->definition('SMS ürün adı', 'SMS segment güvenliği için ASCII normalize ürün adı.', 'Celik kapi kilidi', 'context_builder'),
            'brand' => $this->definition('Marka', 'Ürün markası.', 'EMAKS', 'technical_service_request.brand'),
            'model' => $this->definition('Model', 'Ürün modeli.', 'Model X', 'technical_service_request.product_model'),
            'appointment_date' => $this->definition('Randevu tarihi', 'Randevu günü.', '03.07.2026', 'technical_service_request.scheduled_date'),
            'appointment_time' => $this->definition('Randevu saati', 'Randevu saat aralığı.', '14:00-16:00', 'technical_service_request.scheduled_time'),
            'appointment_datetime' => $this->definition('Randevu tarih/saat', 'Tek satır randevu zamanı.', '03.07.2026 14:00-16:00', 'technical_service_request.scheduled_at'),
            'appointment_date_formatted' => $this->definition('Formatlı randevu tarihi', 'Müşteri dostu randevu tarihi.', '03.07.2026', 'appointment_window_formatter'),
            'appointment_customer_window' => $this->definition('Müşteri randevu aralığı', 'Müşteriye gösterilecek geniş randevu aralığı.', '13:00 - 19:00 arası', 'appointment_window_formatter'),
            'appointment_customer_window_label' => $this->definition('Müşteri randevu periyodu', 'Müşteri geniş randevu periyodu etiketi.', 'öğleden sonra', 'appointment_window_formatter'),
            'appointment_window_for_customer' => $this->definition('Müşteri randevu penceresi', 'Müşteri randevu aralığı alias değeri.', '13:00 - 19:00 arası', 'appointment_window_formatter'),
            'appointment_window_for_technician' => $this->definition('Usta randevu saati', 'Usta iş kartında kullanılacak net randevu saati.', '14:00 - 16:00', 'appointment_window_formatter'),
            'appointment_exact_time_range' => $this->definition('Net randevu saati', 'Usta/OPS için HH:mm - HH:mm formatlı net randevu saati.', '14:00 - 16:00', 'appointment_window_formatter'),
            'appointment_start_time' => $this->definition('Randevu başlangıç saati', 'Net randevu başlangıç saati.', '14:00', 'appointment_window_formatter'),
            'appointment_end_time' => $this->definition('Randevu bitiş saati', 'Net randevu bitiş saati.', '16:00', 'appointment_window_formatter'),
            'appointment_slot_label' => $this->definition('Randevu periyodu', 'Öğleden önce/sonra veya özel slot etiketi.', 'öğleden sonra', 'appointment_window_formatter'),
            'appointment_time_range' => $this->definition('Randevu saat aralığı', 'HH:mm - HH:mm formatlı saat aralığı.', '14:00 - 16:00', 'appointment_window_formatter'),
            'appointment_assignment_timing_text' => $this->definition('Atama randevu metni', 'Atama mesajında net saat yoksa kullanılacak güvenli metin.', '03.07.2026 14:00 - 16:00', 'appointment_window_formatter'),
            'technician_name' => $this->definition('Usta adı', 'Atanan usta/teknisyen adı.', 'Test Usta', 'technical_service_request.technicianRecord'),
            'technician_phone' => $this->definition('Usta telefonu', 'Atanan usta telefonu.', '905444444444', 'technical_service_request.technicianRecord'),
            'city' => $this->definition('İl', 'Müşteri ili.', 'İstanbul', 'technical_service_request.customer_city'),
            'district' => $this->definition('İlçe', 'Müşteri ilçesi.', 'Kadıköy', 'technical_service_request.customer_district'),
            'address' => $this->definition('Adres', 'Servis adresi.', 'Test Mah. Örnek Sok. No:1', 'technical_service_request.service_address'),
            'sms_service_address' => $this->definition('SMS servis adresi', 'SMS segment güvenliği için ASCII normalize tam servis adresi.', 'Test Mah. Ornek Sok. No:1', 'context_builder'),
            'maps_url' => $this->definition('Harita linki', 'Adres/koordinat harita linki.', 'https://www.google.com/maps/search/?api=1&query=40.987654,29.123456', 'technical_service_request.location'),
            'maps_url_line' => $this->definition('Harita satırı', 'Harita linki varsa gösterilecek satır.', 'Harita: https://www.google.com/maps/search/?api=1&query=40.987654,29.123456', 'context_builder'),
            'company_name' => $this->definition('Firma adı', 'Gönderen firma adı.', 'EMAKS', 'config'),
            'confirmation_link' => $this->definition('Onay linki', 'Müşteri işlem onay linki.', 'https://panel.example.test/onay/PR88', 'link_generator'),
            'confirmation_link_sms' => $this->definition('SMS kısa onay linki', 'SMS için kısa müşteri onay linki.', 'https://e.ms/onay/PR88', 'link_generator'),
            'payment_link' => $this->definition('Ödeme linki', 'Online ödeme linki.', 'https://sandbox.iyzi.link/PR88', 'payment_provider'),
            'payment_link_sms' => $this->definition('SMS kısa ödeme linki', 'SMS için kısa ödeme linki.', 'https://e.ms/pay/PR88', 'payment_provider'),
            'survey_link_sms' => $this->definition('SMS kısa anket linki', 'SMS için kısa memnuniyet anketi linki.', 'https://e.ms/anket/PR88', 'link_generator'),
            'payment_amount_formatted' => $this->definition('Ödeme tutarı', 'SMS/ödeme linki mesajı için formatlı tutar.', '1.250,00 TL', 'payment_provider'),
            'payment_status_label' => $this->definition('Ödeme durumu', 'Trusted reconcile sonrası ödeme durum etiketi.', 'Ödendi', 'payment_provider'),
            'provider_payment_reference' => $this->definition('Provider ödeme no', 'Provider ödeme numarası; sadece provider döndürürse kullanılır.', '25236546', 'payment_provider'),
            'provider_transaction_reference' => $this->definition('Provider işlem no', 'Provider işlem numarası; sadece provider döndürürse kullanılır.', '27225634', 'payment_provider'),
            'provider_receipt_reference' => $this->definition('Dekont/ref no', 'Dekont veya makbuz referansı; sadece provider döndürürse kullanılır.', 'Sağlayıcı tarafından dönmedi', 'payment_provider'),
            'customer_payment_amount' => $this->definition('Müşteri ödeme tutarı', 'Müşteriye bildirilecek ham tutar.', 1250.0, 'payment_ownership'),
            'customer_payment_amount_formatted' => $this->definition('Formatlı ödeme tutarı', 'TRY formatlı müşteri ödeme tutarı.', '1.250,00 TL', 'payment_ownership'),
            'customer_payment_note_text' => $this->definition('Müşteri ödeme notu', 'Customer-pays-technician bağlamında müşteriye gösterilecek nakit/havale notu.', 'Ödemeler nakit ve havale kabul edilmektedir.', 'payment_instruction_builder'),
            'payment_instruction_text' => $this->definition('Ödeme açıklaması', 'Payer-state uyumlu müşteri ödeme metni.', 'Randevu sırasında ustaya ödenecek tutar: 1.250,00 TL.', 'payment_instruction_builder'),
            'payment_instruction_block' => $this->definition('Ödeme bilgi bloğu', 'Payer-state uyumlu müşteri ödeme bölümü.', 'Randevu sırasında ustaya ödenecek tutar: 1.250,00 TL.', 'payment_instruction_builder'),
            'short_payment_instruction' => $this->definition('Kısa ödeme açıklaması', 'SMS için kısa payer-state ödeme metni.', 'Ustaya ödenecek tutar: 1.250,00 TL.', 'payment_instruction_builder'),
            'sms_payment_line' => $this->definition('SMS ödeme satırı', 'SMS için tek satır payer-state ödeme metni.', 'Ustaya ödenecek tutar: 1.250,00 TL.', 'payment_instruction_builder'),
            'sms_short_address' => $this->definition('SMS kısa adres', 'SMS için ilçe/il kısa adresi; tam adres değildir.', 'Kadıköy / İstanbul', 'context_builder'),
            'sms_title' => $this->definition('SMS başlığı', 'NAC SMS customID/title için kısa başlık.', 'EMAKS', 'nac_sms_settings'),
            'sms_custom_id' => $this->definition('SMS custom ID', 'NAC SMS customID/audit değeri.', 'PR88-REL4C', 'nac_sms_adapter'),
            'provider_name' => $this->definition('Provider adı', 'Mesaj/ödeme sağlayıcı adı.', 'Iyzico', 'provider'),
            'ops_note_for_technician' => $this->definition('OPS notu', 'Sadece teknisyene gösterilecek OPS notu.', 'Kapıda güvenlik var.', 'technical_service_internal'),
            'technician_visible_note' => $this->definition('Teknisyen notu', 'Teknisyen için güvenli not.', 'Müşteri randevudan önce aranacak.', 'technical_service_request'),
            'customer_visible_note' => $this->definition('Müşteri notu', 'Müşteriye açık güvenli not.', '', 'technical_service_request'),
            'technician_job_card_url' => $this->definition('Usta iş kartı linki', 'Ustanın işi açacağı güvenli iş kartı linki.', 'https://panel.example.test/partner/jobs/PR88-REL4C', 'job_card_url_builder'),
            'technician_job_card_short_url' => $this->definition('SMS usta iş kartı kısa linki', 'SMS için kısa iş kartı linki.', 'https://e.ms/job/PR88', 'job_card_url_builder'),
            'srv_line' => $this->definition('SRV satırı', 'SRV varsa gösterilecek satır.', 'SRV: SRV-REL4C', 'context_builder'),
            'product_line' => $this->definition('Ürün satırı', 'Ürün varsa gösterilecek satır.', 'Ürün: Çelik kapı kilidi', 'context_builder'),
            'serial_line' => $this->definition('Seri no satırı', 'Seri no varsa gösterilecek satır.', 'Seri No: PR88-REL4C-SERIAL', 'context_builder'),
            'serial_no_line' => $this->definition('Seri no satırı', 'Seri no varsa gösterilecek satır.', 'Seri No: PR88-REL4C-SERIAL', 'context_builder'),
            'customer_visible_note_block' => $this->definition('Müşteri not bloğu', 'Müşteriye güvenli not varsa gösterilecek blok.', '', 'context_builder'),
            'customer_visible_note_line' => $this->definition('Müşteri not satırı', 'Müşteriye güvenli not varsa gösterilecek satır.', '', 'context_builder'),
            'technician_visible_note_block' => $this->definition('Usta not bloğu', 'Ustaya güvenli not varsa gösterilecek blok.', "Not\nMüşteri randevudan önce aranacak.", 'context_builder'),
            'technician_visible_note_line' => $this->definition('Usta not satırı', 'Ustaya güvenli not varsa gösterilecek satır.', 'Not: Müşteri randevudan önce aranacak.', 'context_builder'),
            'labor_amount_formatted' => $this->definition('İşçilik/Montaj tutarı', 'Usta hakediş işçilik/montaj tutarı.', '900,00 TL', 'settlement'),
            'route_fee_formatted' => $this->definition('Yol tutarı', 'Usta hakediş yol tutarı.', '350,00 TL', 'settlement'),
            'technician_earning_total_formatted' => $this->definition('Toplam hakediş', 'Usta toplam hakediş tutarı.', '1.250,00 TL', 'settlement'),
            'technician_earning_summary_text' => $this->definition('Hakediş özeti', 'Usta mesajı için güvenli hakediş özeti.', 'Hakediş bilgisi paneldeki iş kartında görülebilir.', 'settlement'),
            'technician_earning_summary_block' => $this->definition('Hakediş özeti bloğu', 'Usta mesajı için güvenli hakediş bölümü.', "Hakediş Özeti\nİşçilik/Montaj: 900,00 TL\nYol: 350,00 TL\nToplam: 1.250,00 TL", 'settlement'),
            'internal_job_reference' => $this->definition('İç iş referansı', 'OPS/usta mesajlarında MRN/SRV operasyon referansı.', 'SRV: SRV-REL4C / MRN: MRN-REL4C-0001', 'context_builder'),
            'actor_name' => $this->definition('İşlemi yapan', 'OPS/internal event aktörü.', 'Test Usta', 'technical_service_event'),
            'support_subject' => $this->definition('Destek konusu', 'OPS destek talebi başlığı.', 'Parça teyidi gerekiyor', 'technical_service_event'),
            'support_note' => $this->definition('Destek açıklaması', 'OPS destek talebi açıklaması.', 'Müşteri ek parça talep etti; OPS onayı gerekiyor.', 'technical_service_event'),
            'created_at_formatted' => $this->definition('Oluşturma tarihi', 'Event oluşturma zamanı.', '03.07.2026 14:30', 'technical_service_event'),
            'rejection_reason' => $this->definition('Reddetme nedeni', 'Usta iş reddi gerekçesi.', 'Usta belirtilen saat aralığında uygun değil.', 'technical_service_event'),
            'cancellation_reason' => $this->definition('İptal nedeni', 'İş/randevu iptal gerekçesi.', 'Müşteri randevunun iptalini istedi.', 'technical_service_event'),
            'rejected_at_formatted' => $this->definition('Reddetme tarihi', 'İş reddi zamanı.', '03.07.2026 14:35', 'technical_service_event'),
            'old_amount_formatted' => $this->definition('Önceki tutar', 'Revizyon öncesi tutar.', '1.250,00 TL', 'technical_service_event'),
            'requested_amount_formatted' => $this->definition('Talep edilen tutar', 'Revizyonla istenen yeni tutar.', '1.650,00 TL', 'technical_service_event'),
            'revision_reason' => $this->definition('Revizyon açıklaması', 'Fiyat revizyon gerekçesi.', 'Adres uzaklığı ve ek işçilik eklendi.', 'technical_service_event'),
            'completed_at_formatted' => $this->definition('Tamamlama tarihi', 'Ustanın işi tamamladığını bildirdiği zaman.', '03.07.2026 18:10', 'technical_service_event'),
            'next_action_text' => $this->definition('Sonraki aksiyon', 'OPS için net sonraki aksiyon.', 'OPS son kontrol / müşteri onayı', 'technical_service_event'),
            'ops_next_action_text' => $this->definition('OPS aksiyonu', 'OPS WhatsApp mesajında gösterilecek sonraki aksiyon.', 'Randevuyu onaylayın veya değişiklik isteyin.', 'technical_service_event'),
            'proposed_appointment_options' => $this->definition('Önerilen randevu zamanları', 'Ustanın önerdiği randevu tarihi/saatleri.', '08.07.2026 15:00 - 16:00', 'partner_portal_action'),
            'technician_note' => $this->definition('Usta notu', 'Ustanın randevu önerisi veya saha aksiyon notu.', 'Müşteri 15:00 sonrası uygun.', 'partner_portal_action'),
            'activation_code' => $this->definition('Aktivasyon kodu', 'Müşteriye gönderilecek ürün aktivasyon kodu.', 'ACT-REL4E10', 'technical_service_request.activation_code'),
            'warranty_started_at_formatted' => $this->definition('Garanti başlangıç tarihi', 'Müşteriye gösterilecek garanti başlangıç tarihi.', '07.07.2026', 'warranty_service'),
            'warranty_ends_at_formatted' => $this->definition('Garanti bitiş tarihi', 'Varsa müşteriye gösterilecek garanti bitiş tarihi.', '07.07.2028', 'warranty_service'),
            'part_name' => $this->definition('Parça adı', 'Parça talebi/ücreti mesajındaki parça adı.', 'Kilit gövdesi', 'technical_service_part_request'),
            'part_code' => $this->definition('Parça kodu', 'Parça talebi varsa operasyon parça kodu.', 'PRT-001', 'technical_service_part_request'),
            'part_quantity' => $this->definition('Parça adedi', 'Talep edilen parça adedi.', '1', 'technical_service_part_request'),
            'part_reason' => $this->definition('Parça nedeni', 'Parça talebi veya ücret gerekçesi.', 'Parça değişimi gerekiyor.', 'technical_service_part_request'),
            'part_details' => $this->definition('Parça detayı', 'Parça adı, kod ve adet özet satırı.', 'Kilit gövdesi / PRT-001 / 1 adet', 'context_builder'),
            'part_received_at_formatted' => $this->definition('Parça teslim zamanı', 'Ustanın parçayı teslim aldığı tarih/saat.', '08.07.2026 11:20', 'technical_service_part_request'),
            'survey_link' => $this->definition('Anket linki', 'REL-14 müşteri anket linki.', 'https://panel.example.test/anket/PR88', 'future'),
            'support_phone' => $this->definition('Destek telefonu', 'Gelecek destek hattı.', '08500000000', 'future'),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function forbiddenVariables(): array
    {
        return [
            'internal_note',
            'api_key',
            'secret_key',
            'token',
            'authorization',
            'provider_token',
            'provider_response',
            'raw_provider_response',
            'raw_provider_payload',
            'smtp_password',
            'nac_password',
            'mikro_api_key',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function sampleContext(string $messageType, string $channel): array
    {
        $context = [];

        foreach ($this->definitions() as $key => $definition) {
            $context[$key] = $definition['sample'];
        }

        $context['payer_state_key'] = 'customer_pays_technician';
        $context['channel'] = $channel;
        $context['message_type'] = $messageType;

        return $context;
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(string $label, string $description, mixed $sample, string $source): array
    {
        return [
            'key' => null,
            'label' => $label,
            'description' => $description,
            'sample' => $sample,
            'source' => $source,
        ];
    }
}
