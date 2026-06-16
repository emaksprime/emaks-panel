<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRouteQuote;

class TechnicalServiceNextActionService
{
    /**
     * @return array{code:string,title:string,description:string,severity:string,primary_action:?string,secondary_actions:array<int, string>,blocking:bool}
     */
    public function forRequest(TechnicalServiceRequest $request, ?array $paymentStatus = null): array
    {
        if ($request->completed_at !== null || $this->isCompletedStatus($request->status) || $this->isCompletedStatus($request->workflow_status)) {
            return $this->payload(
                'completed',
                'İş tamamlandı',
                'Aktif operasyon aksiyonu yok. Garanti ve hakediş özetini kontrol edebilirsiniz.',
                'success',
                null,
                false
            );
        }

        if ($this->isAssignedFieldProcess($request)) {
            return $this->fieldProcessPayload();
        }

        $isServiceVisit = $this->isServiceVisitRequest($request);
        $operation = is_array($request->operation_control_payload) ? $request->operation_control_payload : [];
        $paymentStatus ??= app(TechnicalServicePaymentStatusResolver::class)->resolve($request);

        if ($isServiceVisit && ! filled($request->technical_service_technician_id)) {
            return $this->payload(
                'assign_technician',
                'Usta Ata',
                'SRV child akışı parent kapı/görsel kontrolüne takılmaz. Bu servis ziyareti için usta atanmalı.',
                'info',
                'assign_technician',
                true
            );
        }

        if (! $isServiceVisit && ($operation['door_photos_checked'] ?? null) !== 'compatible') {
            return $this->payload(
                'review_photos',
                'Kapı görsellerini kontrol et',
                'Usta ataması için kapı görselleri uygun olarak işaretlenmeli.',
                'warning',
                'review_photos',
                true
            );
        }

        if (! $isServiceVisit && ! (bool) $paymentStatus['is_paid'] && (bool) $paymentStatus['requires_payment']) {
            if (filled($paymentStatus['pending_payment_id'])) {
                $amount = is_numeric($paymentStatus['amount'] ?? null)
                    ? number_format((float) $paymentStatus['amount'], 2, ',', '.').' TRY'
                    : 'Montaj';

                return $this->payload(
                    'payment_pending',
                    'Ödeme linki gönderildi, ödeme bekleniyor',
                    sprintf('%s ödeme linki gönderildi. Ödeme tamamlanınca servis atanabilir.', $amount),
                    'warning',
                    'copy_payment_link',
                    true,
                    ['send_payment_whatsapp']
                );
            }

            return $this->payload(
                'payment_required',
                'Montaj ödemesi bekleniyor',
                'Montaj ödemesi alınmadan servis ataması yapılamaz. Ödeme alınmışsa talep ödeme kaydı kontrol edilmeli.',
                'warning',
                'create_payment_link',
                true
            );
        }

        if (! filled($request->technical_service_technician_id)) {
            return $this->payload(
                'select_technician',
                'Usta seç',
                'Uygun usta seçildikten sonra yol ücreti otomatik hesaplanır.',
                'info',
                'select_technician',
                true
            );
        }

        $approvalToken = \Illuminate\Support\Str::of((string) $request->technician_approval_status.' '.(string) $request->technician_confirmation_status)
            ->ascii()
            ->lower()
            ->toString();
        $technicianAlreadyApproved = $request->technician_approved_at !== null
            || str_contains($approvalToken, 'onay')
            || str_contains($approvalToken, 'kabul')
            || str_contains($approvalToken, 'accept');

        if ($technicianAlreadyApproved && ! filled($request->scheduled_at)) {
            return $this->payload(
                'appointment_waiting',
                'Usta randevu önerecek',
                'Usta müşteri için uygun randevu zamanı önerecek.',
                'neutral',
                null,
                false
            );
        }
        if (! in_array($request->workflow_status, ['Usta Onayı Bekleyen', 'Planlı', 'Yolda', 'Sahada', 'Tamamlandı'], true)) {
            return $this->payload(
                'assign_technician',
                'Servis ata',
                'Usta seçildi, servis ataması yapılabilir.',
                'success',
                'assign_technician',
                false
            );
        }

        if ($request->workflow_status === 'Usta Onayı Bekleyen') {
            return $this->payload(
                'technician_approval_waiting',
                'Usta onayı bekleniyor',
                'Servis atandı. Ustanın işi kabul etmesi bekleniyor.',
                'info',
                null,
                false
            );
        }

        if (! $this->hasCalculatedRouteQuote($request)) {
            return $this->payload(
                'route_fee_missing',
                'Usta yol hakedişi hesaplanmalı',
                'Seçili usta için yol hakedişi henüz hesaplanmadı.',
                'warning',
                'calculate_route_fee',
                false
            );
        }

        if (! filled($request->scheduled_at)) {
            return $this->payload(
                'appointment_waiting',
                'Randevu bekleniyor',
                'Müşteri ve usta için uygun randevu zamanı netleştirilmeli.',
                'info',
                'plan_appointment',
                false
            );
        }

        return $this->fieldProcessPayload();
    }

    /**
     * @return array{code:string,title:string,description:string,severity:string,primary_action:?string,secondary_actions:array<int, string>,blocking:bool}
     */
    private function fieldProcessPayload(): array
    {
        return $this->payload(
            'field_process',
            'İş ustada',
            'Usta fotoğrafları ve müşteri onayını tamamlayacak.',
            'neutral',
            null,
            false
        );
    }

    private function isAssignedFieldProcess(TechnicalServiceRequest $request): bool
    {
        if ($request->completed_at !== null || ! filled($request->technical_service_technician_id) || ! filled($request->scheduled_at)) {
            return false;
        }

        return in_array($request->workflow_status, ['Planlı', 'Yolda', 'Sahada'], true);
    }

    /**
     * @return array{code:string,title:string,description:string,severity:string,primary_action:?string,secondary_actions:array<int, string>,blocking:bool}
     */
    private function payload(string $code, string $title, string $description, string $severity, ?string $primaryAction, bool $blocking, array $secondaryActions = []): array
    {
        return [
            'code' => $code,
            'title' => $title,
            'description' => $description,
            'severity' => $severity,
            'primary_action' => $primaryAction,
            'secondary_actions' => array_values($secondaryActions),
            'blocking' => $blocking,
        ];
    }

    private function hasCalculatedRouteQuote(TechnicalServiceRequest $request): bool
    {
        $quote = $request->latestRouteQuote;

        return $quote instanceof TechnicalServiceRouteQuote
            && (int) $quote->technician_id === (int) $request->technical_service_technician_id
            && in_array($quote->status, [TechnicalServiceRouteQuote::STATUS_CALCULATED], true);
    }

    private function isServiceVisitRequest(TechnicalServiceRequest $request): bool
    {
        return $request->parent_request_id !== null
            || filled($request->service_code)
            || filled($request->service_visit_reason);
    }

    private function isCompletedStatus(?string $status): bool
    {
        $token = \Illuminate\Support\Str::of((string) $status)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->value();

        return in_array($token, ['tamamlandi', 'tamamlanda', 'tamamland'], true);
    }
}
