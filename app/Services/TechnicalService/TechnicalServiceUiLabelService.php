<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServicePartRequest;
use App\Models\TechnicalServicePartnerJobAction;
use App\Support\TechnicalServiceTurkeyLocations;

class TechnicalServiceUiLabelService
{
    public static function actionLabel(?string $action): string
    {
        $code = trim((string) $action);

        return match ($code) {
            TechnicalServicePartnerJobAction::ACTION_ACCEPTED => 'İş kabul edildi',
            'assignment_archived' => 'Önceki usta ataması arşivlendi',
            'assignment_created' => 'Usta atandı',
            'assignment_reassigned',
            'assignment_updated' => 'Servis ataması güncellendi',
            'assignment_offer' => 'Hakediş teklifi oluşturuldu',
            'assignment_offer_sent' => 'Hakediş bilgisi hazırlandı',
            'assignment_offer_cancelled' => 'Eski hakediş teklifi iptal edildi',
            'reassign_after_review_resolved' => 'İş yeniden atamaya alındı',
            TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_ACCEPTED_BY_TECHNICIAN => 'Randevu onaylandı',
            TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED => 'Randevu önerildi',
            'partner_portal_appointment_proposed' => 'Randevu önerildi',
            TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_CHANGE_REQUESTED => 'Randevu değişikliği istendi',
            TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED => 'Usta işi reddetti',
            TechnicalServicePartnerJobAction::ACTION_REVISIT_REQUESTED => 'Tekrar ziyaret istendi',
            TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED => 'Tamamlamaya gönderildi',
            TechnicalServicePartnerJobAction::ACTION_NOTE_ADDED => 'Not eklendi',
            TechnicalServicePartnerJobAction::ACTION_CUSTOMER_OTP_REQUESTED,
            'customer_approval_request',
            'customer_approval_request_resent' => 'Müşteri onayı istendi',
            TechnicalServicePartnerJobAction::ACTION_CUSTOMER_APPROVAL_CONFIRMED,
            'customer_approved' => 'Müşteri onayladı',
            TechnicalServicePartnerJobAction::ACTION_CUSTOMER_APPROVAL_REJECTED,
            'customer_rejected' => 'Müşteri onaylamadı',
            TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED => 'Ek talep oluşturuldu',
            TechnicalServicePartnerJobAction::ACTION_PHOTOS_UPLOADED => 'Fotoğraf yüklendi',
            TechnicalServicePartnerJobAction::ACTION_PRICE_REVISION_REQUESTED => 'Hakediş revize talep edildi',
            'schedule_updated' => 'Randevu güncellendi',
            'appointment_approved' => 'Randevu onaylandı',
            'appointment_updated' => 'Randevu güncellendi',
            'cancel',
            'cancelled' => 'İptal edildi',
            'final_check' => 'Son kontrol bekliyor',
            'submitted' => 'Gönderildi',
            'applied' => 'Uygulandı',
            'revised' => 'Revize edildi',
            'ops_review' => 'Operasyon incelemesinde',
            'route_quote_created' => 'Yol hakedişi hesaplandı',
            'route_quote_updated' => 'Yol hakedişi güncellendi',
            'manual_fee' => 'Manuel ücret girildi',
            'payment_paid',
            'mount_payment_paid' => 'Ödeme alındı',
            'customer_charge_paid' => 'Müşteri servis/parça ödemesi alındı',
            'payment_pending' => 'Ödeme bekleniyor',
            'payment_failed' => 'Ödeme başarısız',
            'part_requested',
            'part_request_created' => 'Parça talebi oluşturuldu',
            'part_request_payment_paid' => 'Parça ödemesi alındı',
            'part_approved' => 'Parça talebi onaylandı',
            'part_ordered' => 'Parça tedarikte',
            'part_request_'.TechnicalServicePartRequest::STATUS_APPROVED => 'Parça talebi onaylandı',
            'part_request_'.TechnicalServicePartRequest::STATUS_ORDERED => 'Parça tedarikte',
            'part_request_'.TechnicalServicePartRequest::STATUS_SENT,
            'part_sent' => 'Parça gönderildi',
            'part_request_'.TechnicalServicePartRequest::STATUS_RECEIVED,
            'part_received' => 'Parça teslim alındı',
            'part_request_'.TechnicalServicePartRequest::STATUS_SERVICE_VISIT_REQUIRED => 'Parça sonrası servis gerekli',
            'part_request_srv_created' => 'Parça sonrası servis oluşturuldu',
            'part_request_'.TechnicalServicePartRequest::STATUS_SERVICE_VISIT_CREATED,
            'service_visit_created',
            'srv_created',
            'srv_child_created' => 'Servis kaydı oluşturuldu',
            'part_request_'.TechnicalServicePartRequest::STATUS_REJECTED => 'Parça talebi reddedildi',
            'technician_earning_message_sent' => 'Hakediş bilgisi gönderildi',
            'customer_called' => 'Müşteri arandı',
            'contact_customer_called' => 'Müşteri arandı',
            'technical_support' => 'Teknik destek istendi',
            'partner_portal_support_requested' => 'Ek talep oluşturuldu',
            'second_visit_required' => 'Tekrar randevu gerekli',
            'technician_updated' => 'Usta bilgisi güncellendi',
            'technician_revision_requested' => 'Usta revize talep etti',
            'field_override_requested' => 'Düzeltme talebi oluşturuldu',
            'field_override_applied' => 'Düzeltme uygulandı',
            'field_override_rejected' => 'Düzeltme talebi reddedildi',
            'admin_recompute_requested' => 'Yeniden hesaplama kontrolü kaydedildi',
            '' => 'Operasyon kaydı',
            default => self::safeFallback($code, 'Operasyon kaydı'),
        };
    }

    public static function statusLabel(?string $status): string
    {
        $code = trim((string) $status);

        return match ($code) {
            TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW,
            'ops_review' => 'Operasyon incelemesinde',
            TechnicalServicePartnerJobAction::STATUS_APPLIED,
            'applied' => 'Uygulandı',
            TechnicalServicePartnerJobAction::STATUS_SUBMITTED,
            'submitted' => 'Gönderildi',
            'sent' => 'Gönderildi',
            'pending' => 'Bekliyor',
            'approved' => 'Onaylandı',
            TechnicalServicePartnerJobAction::STATUS_REJECTED,
            'rejected' => 'Reddedildi',
            TechnicalServicePartnerJobAction::STATUS_REVISION_REQUESTED,
            'revision_requested' => 'Revize istendi',
            'revised' => 'Revize edildi',
            'completed' => 'Tamamlandı',
            'cancel',
            'cancelled' => 'İptal edildi',
            'superseded' => 'Yenilendi',
            '' => '-',
            default => self::safeFallback($code, 'Durum kaydı'),
        };
    }

    public static function serviceVisitReasonLabel(?string $reason): string
    {
        return match (trim((string) $reason)) {
            'spare_part' => 'Parça sonrası servis',
            'revisit' => 'Tekrar ziyaret',
            'service_request' => 'Servis talebi',
            'reopen' => 'Servis talebi',
            'support' => 'Ek talep sonrası servis',
            default => 'Ek servis ziyareti',
        };
    }

    public static function cleanDisplayText(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return strtr($value, [
            '?stanbul' => 'İstanbul',
            'Istanbul' => 'İstanbul',
            '?zmir' => 'İzmir',
            'Izmir' => 'İzmir',
            '?ankaya' => 'Çankaya',
            'Cankaya' => 'Çankaya',
            '?sküdar' => 'Üsküdar',
            'Uskudar' => 'Üsküdar',
            '?i?li' => 'Şişli',
            'Sisli' => 'Şişli',
            'Ata?ehir' => 'Ataşehir',
            'Atasehir' => 'Ataşehir',
            'Be?ikta?' => 'Beşiktaş',
            'Besiktas' => 'Beşiktaş',
            'Kad?k?y' => 'Kadıköy',
            'Kadikoy' => 'Kadıköy',
            'K???kçekmece' => 'Küçükçekmece',
            'Kucukcekmece' => 'Küçükçekmece',
            'Ak?ll?' => 'Akıllı',
            'ak?ll?' => 'akıllı',
            'AK?LL?' => 'AKILLI',
            'Kap?' => 'Kapı',
            'kap?' => 'kapı',
            'KAP?' => 'KAPI',
            'M??teri' => 'Müşteri',
            'Planl?' => 'Planlı',
            'Tamamland?' => 'Tamamlandı',
            'Atamas?' => 'Ataması',
            'Onay?' => 'Onayı',
            'onaylad?' => 'onayladı',
            'onayland?' => 'onaylandı',
            'ÃƒÂ‡' => 'Ç',
            'ÃƒÂ–' => 'Ö',
            'ÃƒÂœ' => 'Ü',
            'ÃƒÂ§' => 'ç',
            'ÃƒÂ¶' => 'ö',
            'ÃƒÂ¼' => 'ü',
            'Ã„Â°' => 'İ',
            'Ã„Â±' => 'ı',
            'Ã„ÂŸ' => 'ğ',
            'Ã…ÂŸ' => 'ş',
            'Ã…Âž' => 'Ş',
            'Ã‡' => 'Ç',
            'Ã–' => 'Ö',
            'Ãœ' => 'Ü',
            'Ã§' => 'ç',
            'Ã¶' => 'ö',
            'Ã¼' => 'ü',
            'Ä°' => 'İ',
            'Ä±' => 'ı',
            'ÄŸ' => 'ğ',
            'ÅŸ' => 'ş',
            'Åž' => 'Ş',
            'Ã‚' => '',
            'Â' => '',
            'ï¿½' => '',
            '�' => '',
        ]);
    }

    public static function displayName(?string $value): ?string
    {
        $cleaned = self::cleanDisplayText($value);

        if ($cleaned === null || trim($cleaned) === '') {
            return $cleaned;
        }

        if (preg_match('/\b(SMOKE-SCOPE|Portal|Scope|E2E)\b/u', $cleaned) === 1) {
            return str_replace('Other Usta', 'Diğer Usta', $cleaned);
        }

        return $cleaned;
    }

    public static function cityLabel(?string $value): ?string
    {
        $cleaned = self::cleanDisplayText($value);

        if ($cleaned === null || trim($cleaned) === '') {
            return $cleaned;
        }

        return TechnicalServiceTurkeyLocations::standardizeProvinceName($cleaned) ?? $cleaned;
    }

    public static function districtLabel(?string $value, ?string $city = null): ?string
    {
        $cleaned = self::cleanDisplayText($value);

        if ($cleaned === null || trim($cleaned) === '') {
            return $cleaned;
        }

        return TechnicalServiceTurkeyLocations::standardizeDistrictName(self::cityLabel($city), $cleaned) ?? $cleaned;
    }

    public static function addressLabel(?string $value): ?string
    {
        return self::cleanDisplayText($value);
    }

    private static function safeFallback(string $value, string $fallback): string
    {
        if ($value === '') {
            return $fallback;
        }

        if (preg_match('/^[a-z0-9_\-]+$/i', $value) === 1) {
            return $fallback;
        }

        return $value;
    }
}
