<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServicePartRequest;
use App\Models\TechnicalServicePartnerJobAction;

class TechnicalServiceUiLabelService
{
    public static function actionLabel(?string $action): string
    {
        $code = trim((string) $action);

        return match ($code) {
            TechnicalServicePartnerJobAction::ACTION_ACCEPTED => 'İş kabul edildi',
            TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_ACCEPTED_BY_TECHNICIAN => 'Randevu onaylandı',
            TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED => 'Randevu önerildi',
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
            'cancel',
            'cancelled' => 'İptal edildi',
            'part_requested',
            'part_request_created' => 'Parça talebi oluşturuldu',
            'part_request_'.TechnicalServicePartRequest::STATUS_APPROVED => 'Parça talebi onaylandı',
            'part_request_'.TechnicalServicePartRequest::STATUS_ORDERED => 'Parça tedarik ediliyor',
            'part_request_'.TechnicalServicePartRequest::STATUS_SENT,
            'part_sent' => 'Parça gönderildi',
            'part_request_'.TechnicalServicePartRequest::STATUS_RECEIVED,
            'part_received' => 'Parça teslim alındı',
            'part_request_'.TechnicalServicePartRequest::STATUS_SERVICE_VISIT_REQUIRED => 'Operasyon servis planlıyor',
            'part_request_'.TechnicalServicePartRequest::STATUS_SERVICE_VISIT_CREATED,
            'service_visit_created',
            'part_request_srv_created',
            'srv_child_created' => 'Servis kaydı oluşturuldu',
            'part_request_'.TechnicalServicePartRequest::STATUS_REJECTED => 'Parça talebi reddedildi',
            'technician_earning_message_sent' => 'Hakediş bilgisi gönderildi',
            'customer_called' => 'Müşteri arandı',
            'technician_revision_requested' => 'Usta revize talep etti',
            '' => 'Bilinmeyen işlem',
            default => self::safeFallback($code, 'Bilinmeyen işlem'),
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
            default => self::safeFallback($code, $code),
        };
    }

    public static function serviceVisitReasonLabel(?string $reason): string
    {
        return match (trim((string) $reason)) {
            'spare_part' => 'Parça sonrası servis',
            'revisit' => 'Tekrar ziyaret',
            'support' => 'Ek talep sonrası servis',
            default => 'Ek servis ziyareti',
        };
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
