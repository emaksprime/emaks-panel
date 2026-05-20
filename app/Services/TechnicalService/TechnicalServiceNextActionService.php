<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRouteQuote;
use Illuminate\Support\Arr;

class TechnicalServiceNextActionService
{
    /**
     * @return array{code:string,title:string,description:string,severity:string,primary_action:?string,secondary_actions:array<int, string>,blocking:bool}
     */
    public function forRequest(TechnicalServiceRequest $request): array
    {
        $operation = is_array($request->operation_control_payload) ? $request->operation_control_payload : [];
        $payment = $this->latestExtraPayment($request);

        if (($operation['door_photos_checked'] ?? null) !== 'compatible') {
            return $this->payload(
                'review_photos',
                'Kapı görsellerini kontrol et',
                'Usta ataması için kapı görselleri uygun olarak işaretlenmeli.',
                'warning',
                'review_photos',
                true
            );
        }

        if ($payment instanceof TechnicalServiceMountPayment && $payment->status === TechnicalServiceMountPayment::STATUS_PENDING) {
            return $this->payload(
                'payment_pending',
                'Ödeme bekleniyor',
                sprintf('%s %s ödeme linki gönderildi. Ödeme tamamlanınca servis atanabilir.', number_format((float) $payment->amount, 2, ',', '.'), $payment->currency),
                'warning',
                'copy_payment_link',
                true,
                ['send_payment_whatsapp']
            );
        }

        if ($this->requiresMountExclusionAcknowledgement($request)) {
            return $this->payload(
                'mount_exclusion_ack_required',
                'Montaj hariç çoklu ürün onayı gerekiyor',
                'Montaj ödemesi alınmadığı için operasyon onayı ve kısa açıklama tamamlanmalı.',
                'warning',
                'acknowledge_mount_exclusion',
                true
            );
        }

        if ($this->paymentRequired($request) && ! $this->mountPaymentReceived($request)) {
            return $this->payload(
                'payment_required',
                'Montaj ödemesini kontrol et',
                'Ödeme alınmadıysa müşteriye ödeme linki gönderilmelidir.',
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
                'assign_technician',
                true
            );
        }

        if (! $this->hasCalculatedRouteQuote($request)) {
            return $this->payload(
                'route_fee_missing',
                'Yol ücretini kontrol et',
                'Seçili usta için yol ücreti henüz hesaplanmadı.',
                'warning',
                'calculate_route_fee',
                true
            );
        }

        if (! in_array($request->workflow_status, ['Usta Onayı Bekleyen', 'Planlı', 'Yolda', 'Sahada', 'Tamamlandı'], true)) {
            return $this->payload(
                'assign_technician',
                'Servis atanabilir',
                'Kontroller tamamlandı. Seçili ustaya servis ataması yapılabilir.',
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

        return $this->payload(
            'field_process',
            'Saha süreci takip ediliyor',
            'Randevu ve atama tamamlandı. Saha akışı izlenmeli.',
            'success',
            null,
            false
        );
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

    private function latestExtraPayment(TechnicalServiceRequest $request): ?TechnicalServiceMountPayment
    {
        if ($request->mount_session_id === null) {
            return null;
        }

        return TechnicalServiceMountPayment::query()
            ->where('technical_service_mount_session_id', $request->mount_session_id)
            ->where('technical_service_request_id', $request->id)
            ->latest('id')
            ->get()
            ->first(function (TechnicalServiceMountPayment $payment): bool {
                $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];

                return ($payload['source'] ?? null) === 'operation_extra_mount_fee';
            });
    }

    private function mountPaymentReceived(TechnicalServiceRequest $request): bool
    {
        $context = is_array($request->qr_context_payload) ? $request->qr_context_payload : [];
        $payment = $this->latestExtraPayment($request);

        return $request->mount_payment_status === TechnicalServiceMountSession::PAYMENT_PAID
            || ($payment instanceof TechnicalServiceMountPayment && $payment->status === TechnicalServiceMountPayment::STATUS_PAID)
            || Arr::get($context, 'mount_payment_status') === TechnicalServiceMountSession::PAYMENT_PAID
            || Arr::get($context, 'payment.status') === TechnicalServiceMountSession::PAYMENT_PAID;
    }

    private function requiresMountExclusionAcknowledgement(TechnicalServiceRequest $request): bool
    {
        $operation = is_array($request->operation_control_payload) ? $request->operation_control_payload : [];
        $ack = is_array($operation['mount_exclusion_acknowledgement'] ?? null)
            ? $operation['mount_exclusion_acknowledgement']
            : [];

        return $request->sale_mount_status === TechnicalServiceMountSession::SALE_MONTAJ_HARIC
            && $this->hasMultiProductRequest($request)
            && ! $this->mountPaymentReceived($request)
            && ! (bool) ($ack['acknowledged'] ?? false);
    }

    private function paymentRequired(TechnicalServiceRequest $request): bool
    {
        return in_array($request->mount_payment_status, [
            TechnicalServiceMountSession::PAYMENT_PENDING,
            TechnicalServiceMountSession::PAYMENT_FAILED,
            TechnicalServiceMountSession::PAYMENT_CANCELLED,
        ], true);
    }

    private function hasMultiProductRequest(TechnicalServiceRequest $request): bool
    {
        if ($request->mount_payment_status === TechnicalServiceMountSession::PAYMENT_SKIPPED_MULTI_PRODUCT) {
            return true;
        }

        return $request->requestSerials()
            ->where(function ($query): void {
                $query->where('customer_selected', true)
                    ->orWhere('operation_added', true);
            })
            ->count() > 1;
    }

    private function hasCalculatedRouteQuote(TechnicalServiceRequest $request): bool
    {
        $quote = $request->latestRouteQuote;

        return $quote instanceof TechnicalServiceRouteQuote
            && (int) $quote->technician_id === (int) $request->technical_service_technician_id
            && in_array($quote->status, [TechnicalServiceRouteQuote::STATUS_CALCULATED], true);
    }
}
