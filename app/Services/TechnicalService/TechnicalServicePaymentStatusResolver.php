<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestSerial;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;

class TechnicalServicePaymentStatusResolver
{
    /**
     * @return array{
     *     is_paid: bool,
     *     requires_payment: bool,
     *     source: string,
     *     stage_label: string,
     *     amount: float|null,
     *     paid_at: string|null,
     *     pending_payment_id: int|null,
     *     latest_payment_id: int|null,
     *     message: string
     * }
     */
    public function resolve(TechnicalServiceRequest $request): array
    {
        $latestPaidPayment = $this->latestRequestPayment($request, TechnicalServiceMountPayment::STATUS_PAID);
        $latestPayment = $this->latestRequestPayment($request);

        if ($latestPaidPayment instanceof TechnicalServiceMountPayment) {
            return $this->paidPayload(
                $this->paymentSource($latestPaidPayment),
                $this->paymentStageLabel($latestPaidPayment),
                (float) $latestPaidPayment->amount,
                $this->dateTimeString($latestPaidPayment->paid_at),
                $latestPaidPayment->id,
                'Montaj ödemesi onaylandı.'
            );
        }

        if ($this->serialPayloadHasPaidMount($request)) {
            return $this->paidPayload(
                'request_serial_payload',
                'Seri satırında montaj dahil görünüyor',
                null,
                null,
                $latestPayment?->id,
                'Seri satırı montaj dahil olarak işaretlendi.'
            );
        }

        $contextPayment = $this->contextPaidPayload($request, $latestPayment?->id);
        if ($contextPayment !== null) {
            return $contextPayment;
        }

        if ($request->mount_payment_status === TechnicalServiceMountSession::PAYMENT_PAID) {
            return $this->paidPayload(
                'manual',
                $request->mount_payment_provider === 'fake' ? 'Ödeme onaylandı' : 'Form üzerinden ödeme alındı',
                null,
                $this->dateTimeString($request->mount_payment_paid_at),
                $latestPayment?->id,
                'Montaj ödemesi onaylandı.'
            );
        }

        if ($latestPayment instanceof TechnicalServiceMountPayment
            && $latestPayment->status === TechnicalServiceMountPayment::STATUS_PENDING
        ) {
            return [
                'is_paid' => false,
                'requires_payment' => true,
                'source' => $this->paymentSource($latestPayment),
                'stage_label' => 'Ödeme linki gönderildi, ödeme bekleniyor',
                'amount' => (float) $latestPayment->amount,
                'paid_at' => null,
                'pending_payment_id' => $latestPayment->id,
                'latest_payment_id' => $latestPayment->id,
                'message' => 'Ödeme linki gönderildi, ödeme bekleniyor.',
            ];
        }

        if ($this->mikroRequiresPayment($request)) {
            return [
                'is_paid' => false,
                'requires_payment' => true,
                'source' => 'mikro_initial_sale',
                'stage_label' => $request->mount_payment_status === TechnicalServiceMountSession::PAYMENT_SKIPPED_MULTI_PRODUCT
                    ? 'Çoklu ürün ödeme operasyon tarafından netleştirilecek'
                    : 'Montaj ödemesi bekleniyor',
                'amount' => null,
                'paid_at' => null,
                'pending_payment_id' => null,
                'latest_payment_id' => $latestPayment?->id,
                'message' => 'Mikro başlangıç sinyaline göre montaj ödemesi bekleniyor.',
            ];
        }

        if (in_array($request->sale_mount_status, [
            TechnicalServiceMountSession::SALE_MONTAJ_DAHIL,
            TechnicalServiceMountSession::SALE_MONTAJ_SONRADAN_DAHIL,
        ], true) || $request->mount_payment_status === TechnicalServiceMountSession::PAYMENT_NOT_REQUIRED) {
            return [
                'is_paid' => false,
                'requires_payment' => false,
                'source' => 'mikro_initial_sale',
                'stage_label' => 'Mikro montaj dahil',
                'amount' => null,
                'paid_at' => null,
                'pending_payment_id' => null,
                'latest_payment_id' => $latestPayment?->id,
                'message' => 'Mikro başlangıç sinyaline göre montaj ödemesi gerekmiyor.',
            ];
        }

        return [
            'is_paid' => false,
            'requires_payment' => false,
            'source' => 'none',
            'stage_label' => 'Ödeme gereksinimi yok',
            'amount' => null,
            'paid_at' => null,
            'pending_payment_id' => null,
            'latest_payment_id' => $latestPayment?->id,
            'message' => 'Montaj ödemesi için aktif bekleyen kayıt yok.',
        ];
    }

    private function latestRequestPayment(TechnicalServiceRequest $request, ?string $status = null): ?TechnicalServiceMountPayment
    {
        $directQuery = TechnicalServiceMountPayment::query()
            ->where('technical_service_request_id', $request->id);

        if ($status !== null) {
            $directQuery->where('status', $status);
        }

        $direct = $directQuery->latest('id')->first();

        if ($direct instanceof TechnicalServiceMountPayment) {
            return $direct;
        }

        if ($request->mount_session_id === null) {
            return null;
        }

        $sessionQuery = TechnicalServiceMountPayment::query()
            ->where('technical_service_mount_session_id', $request->mount_session_id)
            ->latest('id');

        if ($status !== null) {
            $sessionQuery->where('status', $status);
        }

        return $sessionQuery->get()
            ->first(function (TechnicalServiceMountPayment $payment) use ($request): bool {
                $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];

                return (int) ($payload['technical_service_request_id'] ?? 0) === (int) $request->id;
            });
    }

    private function serialPayloadHasPaidMount(TechnicalServiceRequest $request): bool
    {
        $serials = $request->relationLoaded('requestSerials')
            ? $request->requestSerials
            : $request->requestSerials()->get();

        return $serials->contains(function (TechnicalServiceRequestSerial $serial): bool {
            $payload = is_array($serial->source_payload) ? $serial->source_payload : [];
            $status = $this->normalize((string) ($payload['mount_payment_status'] ?? $serial->mount_payment_status ?? ''));
            $label = $this->normalize((string) ($payload['mount_status_label'] ?? $serial->mount_status_label ?? ''));

            return $status === TechnicalServiceMountPayment::STATUS_PAID
                || $status === TechnicalServiceMountSession::PAYMENT_PAID
                || str_contains($label, 'montaj dahil');
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function contextPaidPayload(TechnicalServiceRequest $request, ?int $latestPaymentId): ?array
    {
        $context = is_array($request->qr_context_payload) ? $request->qr_context_payload : [];
        $paidSignals = [
            Arr::get($context, 'mount_payment_status'),
            Arr::get($context, 'payment_status'),
            Arr::get($context, 'payment.status'),
            Arr::get($context, 'sale_and_payment.mount_payment_status'),
            Arr::get($context, 'sale_and_payment.payment_status'),
        ];

        foreach ($paidSignals as $signal) {
            if ($signal === TechnicalServiceMountSession::PAYMENT_PAID || $signal === TechnicalServiceMountPayment::STATUS_PAID) {
                return $this->paidPayload(
                    'public_form_payment',
                    'Form üzerinden ödeme alındı',
                    $this->firstNumeric([
                        Arr::get($context, 'paid_amount'),
                        Arr::get($context, 'payment.amount'),
                        Arr::get($context, 'sale_and_payment.paid_amount'),
                    ]),
                    $this->firstString([
                        Arr::get($context, 'paid_at'),
                        Arr::get($context, 'payment.paid_at'),
                        Arr::get($context, 'sale_and_payment.paid_at'),
                    ]),
                    $latestPaymentId,
                    'QR/Form ödeme bilgisi onaylı görünüyor.'
                );
            }
        }

        return null;
    }

    private function mikroRequiresPayment(TechnicalServiceRequest $request): bool
    {
        return $request->sale_mount_status === TechnicalServiceMountSession::SALE_MONTAJ_HARIC
            || in_array($request->mount_payment_status, [
                TechnicalServiceMountSession::PAYMENT_PENDING,
                TechnicalServiceMountSession::PAYMENT_FAILED,
                TechnicalServiceMountSession::PAYMENT_CANCELLED,
                TechnicalServiceMountSession::PAYMENT_SKIPPED_MULTI_PRODUCT,
            ], true);
    }

    private function paymentSource(TechnicalServiceMountPayment $payment): string
    {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $purpose = (string) ($payload['purpose'] ?? $payload['reason'] ?? '');

        if ($purpose === 'multi_product' || $purpose === 'multi_product_mount') {
            return 'multi_product_extra_payment';
        }

        if (($payload['source'] ?? null) === 'public_form_payment') {
            return 'public_form_payment';
        }

        return 'operation_payment_link';
    }

    private function paymentStageLabel(TechnicalServiceMountPayment $payment): string
    {
        return match ($this->paymentSource($payment)) {
            'multi_product_extra_payment' => 'Çoklu ürün ek ödemesi alındı',
            'public_form_payment' => 'Form üzerinden ödeme alındı',
            default => 'Operasyon ödeme linkiyle ödeme alındı',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function paidPayload(string $source, string $stageLabel, ?float $amount, ?string $paidAt, ?int $latestPaymentId, string $message): array
    {
        return [
            'is_paid' => true,
            'requires_payment' => false,
            'source' => $source,
            'stage_label' => $stageLabel,
            'amount' => $amount,
            'paid_at' => $paidAt,
            'pending_payment_id' => null,
            'latest_payment_id' => $latestPaymentId,
            'message' => $message,
        ];
    }

    private function dateTimeString(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toISOString();
        }

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    /**
     * @param array<int, mixed> $values
     */
    private function firstNumeric(array $values): ?float
    {
        foreach ($values as $value) {
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    /**
     * @param array<int, mixed> $values
     */
    private function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    private function normalize(string $value): string
    {
        return trim(mb_strtolower($value, 'UTF-8'));
    }
}
