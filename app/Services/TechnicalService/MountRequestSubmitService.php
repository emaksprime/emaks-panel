<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestSerial;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class MountRequestSubmitService
{
    public const MULTI_PRODUCT_OPERATION_WARNING = 'Müşteri birden fazla ürün montaj talebi iletti. Müşteri ile iletişime geçiniz.';
    public const CHECK_PENDING_WARNING = 'Seri / montaj kontrolü bekliyor.';

    /**
     * @param array<string, mixed> $payload
     */
    public function submit(TechnicalServiceMountSession $session, array $payload = []): TechnicalServiceRequest
    {
        $link = $session->qrLink;
        $context = $session->context_payload ?? [];
        $customerName = $this->nullableText($payload['customer_name'] ?? null) ?? 'QR Montaj Müşterisi';
        $description = array_values(array_filter([
            $this->nullableText($payload['description'] ?? null),
            $session->customer_entry_mode === TechnicalServiceMountSession::ENTRY_MULTI_PRODUCT_WITHOUT_PAYMENT
                ? self::MULTI_PRODUCT_OPERATION_WARNING
                : null,
            $session->sale_mount_status === TechnicalServiceMountSession::SALE_CHECK_FAILED
                ? self::CHECK_PENDING_WARNING
                : null,
        ]));

        $request = TechnicalServiceRequest::query()->create([
            'mrn' => $this->nextMrn(),
            'customer_name' => $customerName,
            'customer_phone' => $this->nullableText($payload['customer_phone'] ?? null) ?? '+900000000000',
            'customer_city' => $this->nullableText($payload['customer_city'] ?? null) ?? '-',
            'customer_district' => $this->nullableText($payload['customer_district'] ?? null) ?? '-',
            'service_address' => $this->nullableText($payload['service_address'] ?? null) ?? '-',
            'product_name' => $this->nullableText($context['product_name'] ?? null)
                ?? $link?->product_name
                ?? 'Teknik Servis Ürünü',
            'product_model' => $this->nullableText($context['product_model'] ?? null) ?? $link?->product_model,
            'serial_number' => $session->serial_number,
            'service_type' => 'Montaj',
            'status' => TechnicalServiceRequest::STATUS_NEW,
            'workflow_status' => TechnicalServiceRequest::WORKFLOW_NEW_REQUEST,
            'priority' => TechnicalServiceRequest::PRIORITY_MEDIUM,
            'risk_level' => TechnicalServiceRequest::RISK_MEDIUM,
            'source_channel' => TechnicalServiceRequest::SOURCE_QR_MOUNT_FORM,
            'description' => implode("\n", $description),
        ]);

        $this->attachPrimarySerial($request, $session, $context);

        $session->forceFill([
            'decision_status' => TechnicalServiceMountSession::DECISION_SUBMITTED,
        ])->save();

        return $request->fresh(['requestSerials']);
    }

    private function attachPrimarySerial(
        TechnicalServiceRequest $request,
        TechnicalServiceMountSession $session,
        array $context,
    ): void {
        TechnicalServiceRequestSerial::query()->create([
            'technical_service_request_id' => $request->id,
            'mrn' => $request->mrn,
            'serial_number' => $session->serial_number,
            'product_name' => $request->product_name,
            'product_model' => $request->product_model,
            'brand' => $this->nullableText($context['brand'] ?? null) ?? $session->qrLink?->brand,
            'stock_code' => $this->nullableText($context['stock_code'] ?? null),
            'customer_selected' => true,
            'customer_visible' => true,
            'is_primary' => true,
            'is_returned' => false,
            'invoice_customer_type' => TechnicalServiceRequestSerial::CUSTOMER_UNKNOWN,
            'source_payload' => Arr::except($context, ['secret', 'token']),
        ]);
    }

    private function nextMrn(): string
    {
        do {
            $mrn = 'MRN-'.Str::upper(Str::random(10));
        } while (TechnicalServiceRequest::query()->where('mrn', $mrn)->exists());

        return $mrn;
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
