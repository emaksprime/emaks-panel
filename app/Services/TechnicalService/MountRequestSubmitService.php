<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestSerial;
use App\Models\TechnicalServiceRequestUpload;
use App\Services\Messaging\TechnicalServiceWorkflowMessageDispatchService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class MountRequestSubmitService
{
    public const CUSTOMER_ADDRESS_GEOCODE_WARNING = 'Müşteri adresi koordinata çevrilemedi.';

    public function __construct(
        private readonly TechnicalServiceGeocodingService $geocodingService,
        private readonly TechnicalServiceCodeGenerator $codeGenerator,
        private readonly TechnicalServiceWorkflowMessageDispatchService $workflowMessages,
    ) {}

    public const MULTI_PRODUCT_OPERATION_WARNING = 'Müşteri birden fazla ürün montaj talebi iletti. Müşteri ile iletişime geçiniz.';

    public const CHECK_PENDING_WARNING = 'Seri / montaj kontrolü bekliyor.';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function submit(TechnicalServiceMountSession $session, array $payload = []): TechnicalServiceRequest
    {
        $session->loadMissing(['qrLink', 'payments']);
        $link = $session->qrLink;
        $context = $session->context_payload ?? [];
        $payment = $this->latestPayment($session);
        $paymentState = $this->paymentState($session, $payment);
        $documents = $this->documentContext($context);
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
        $location = $this->resolveLocation($payload);
        $requestContextPayload = Arr::except($context, ['secret', 'token']);

        if (($location['warning'] ?? null) !== null) {
            $description[] = self::CUSTOMER_ADDRESS_GEOCODE_WARNING;
        }

        if (($location['geocode_attempted'] ?? false) === true) {
            $requestContextPayload['customer_address_geocode'] = [
                'ok' => $location['latitude'] !== null && $location['longitude'] !== null,
                'source' => $location['source'],
                'accuracy' => $location['accuracy'],
                'error' => $location['warning'],
            ];
        }

        if ($payment instanceof TechnicalServiceMountPayment && $payment->status === TechnicalServiceMountPayment::STATUS_PAID) {
            $requestContextPayload['payment'] = [
                'status' => TechnicalServiceMountPayment::STATUS_PAID,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'paid_at' => $payment->paid_at?->toISOString(),
                'provider' => $payment->provider,
                'provider_reference' => $payment->provider_reference,
            ];
            $requestContextPayload['paid_amount'] = (float) $payment->amount;
            $requestContextPayload['mount_payment_status'] = TechnicalServiceMountSession::PAYMENT_PAID;
        }

        $request = TechnicalServiceRequest::query()->create([
            'mrn' => $this->codeGenerator->nextMrn($customerName),
            'customer_name' => $customerName,
            'customer_phone' => $this->nullableText($payload['customer_phone'] ?? null) ?? '+900000000000',
            'customer_city' => $this->nullableText($payload['customer_city'] ?? null) ?? '-',
            'customer_district' => $this->nullableText($payload['customer_district'] ?? null) ?? '-',
            'service_address' => $this->nullableText($payload['service_address'] ?? null) ?? '-',
            'product_name' => $this->nullableText($context['product_name'] ?? null)
                ?? $link?->product_name
                ?? 'Teknik Servis Ürünü',
            'product_model' => $this->nullableText($context['product_model'] ?? null) ?? $link?->product_model,
            'brand' => $this->nullableText($context['brand'] ?? null) ?? $link?->brand,
            'stock_code' => $this->nullableText($context['stock_code'] ?? null),
            'activation_code' => $this->nullableText($context['activation_code'] ?? null),
            'serial_number' => $session->serial_number,
            'service_type' => 'Montaj',
            'status' => TechnicalServiceRequest::STATUS_NEW,
            'workflow_status' => TechnicalServiceRequest::WORKFLOW_NEW_REQUEST,
            'priority' => TechnicalServiceRequest::PRIORITY_MEDIUM,
            'risk_level' => TechnicalServiceRequest::RISK_MEDIUM,
            'source_channel' => TechnicalServiceRequest::SOURCE_QR_MOUNT_FORM,
            'qr_link_id' => $link?->id,
            'mount_session_id' => $session->id,
            'current_serial_state' => $this->nullableText($context['current_serial_state'] ?? null),
            'has_current_sale' => $this->nullableBool($context['has_current_sale'] ?? null),
            'sale_mount_status' => $session->sale_mount_status,
            'mount_payment_status' => $paymentState['status'],
            'mount_payment_label' => $paymentState['label'],
            'mount_payment_provider' => $payment?->provider,
            'mount_payment_reference' => $payment?->provider_reference,
            'mount_payment_paid_at' => $payment?->paid_at,
            'invoice_series' => $documents['invoice_series'],
            'invoice_number' => $documents['invoice_number'],
            'invoice_display_no' => $documents['invoice_display_no'],
            'dispatch_series' => $documents['dispatch_series'],
            'dispatch_number' => $documents['dispatch_number'],
            'dispatch_display_no' => $documents['dispatch_display_no'],
            'order_series' => $documents['order_series'],
            'order_number' => $documents['order_number'],
            'order_display_no' => $documents['order_display_no'],
            'invoice_customer_type' => $this->nullableText($context['invoice_customer_type'] ?? null)
                ?? TechnicalServiceRequestSerial::CUSTOMER_UNKNOWN,
            'qr_context_payload' => $requestContextPayload,
            'location_latitude' => $location['latitude'],
            'location_longitude' => $location['longitude'],
            'location_place_id' => $this->nullableText($payload['location_place_id'] ?? null),
            'location_formatted_address' => $location['formatted_address'],
            'location_map_url' => $this->nullableText($payload['location_map_url'] ?? null),
            'location_source' => $location['source'],
            'location_accuracy' => $location['accuracy'],
            'location_note' => $location['note'],
            'building_no' => $this->nullableText($payload['building_no'] ?? null),
            'apartment_no' => $this->nullableText($payload['apartment_no'] ?? null),
            'door_no' => $this->nullableText($payload['door_no'] ?? null),
            'floor_no' => $this->nullableText($payload['floor_no'] ?? null),
            'site_name' => $this->nullableText($payload['site_name'] ?? null),
            'description' => implode("\n", $description),
        ]);

        $invoiceRows = Arr::get($context, 'invoice_serials.all_invoice_serials', []);
        $this->syncRequestSerials(
            $request,
            is_array($invoiceRows) ? $invoiceRows : [],
            is_array($payload['selected_invoice_serials'] ?? null) ? $payload['selected_invoice_serials'] : [],
        );
        $this->storeDoorPhotos(
            $request,
            is_array($payload['door_photos'] ?? null) ? $payload['door_photos'] : [],
        );
        $this->linkPaymentToRequest($payment, $request);

        $session->forceFill([
            'decision_status' => TechnicalServiceMountSession::DECISION_SUBMITTED,
        ])->save();

        $request->events()->create([
            'event_type' => 'technical_service_request_created',
            'title' => 'Teknik servis talebi oluşturuldu',
            'note' => null,
            'from_status' => null,
            'to_status' => $request->workflow_status,
            'author_user_id' => null,
            'metadata' => [
                'actor_user_id' => null,
                'actor_role' => 'customer_public',
                'source' => 'public_mount_request',
                'occurred_at_istanbul' => now('Europe/Istanbul')->toIso8601String(),
                'request_id' => $request->id,
                'mrn' => $request->mrn,
                'srv' => $request->service_code,
                'source_channel' => $request->source_channel,
            ],
        ]);

        $this->workflowMessages->queueWorkflowDispatches(
            $request->refresh(),
            'new_request_created_ops',
            'ops',
            [
                'actor_name' => 'Müşteri montaj formu',
                'customer_name' => $request->customer_name,
                'customer_phone' => $request->customer_phone,
                'product_name' => $request->product_name,
                'address' => $request->location_formatted_address ?: $request->service_address,
                'next_action_text' => 'Talebi inceleyin ve uygun ustayı atayın.',
            ],
            null,
            null,
            [
                'triggered_by' => 'public_mount_request_created',
                'event_version' => 'new-request:'.$request->id,
                'metadata' => [
                    'workflow_event' => 'new_request_created_ops',
                    'source' => 'public_mount_request',
                ],
            ],
        );

        $this->workflowMessages->queueWorkflowDispatches(
            $request->refresh(),
            'mount_request_created_customer',
            'customer',
            [],
            null,
            null,
            [
                'recipient_phone' => $request->customer_phone,
                'triggered_by' => 'public_mount_request_created',
                'event_version' => 'mount-request-customer:'.$request->id,
                'metadata' => [
                    'workflow_event' => 'mount_request_created_customer',
                    'source' => 'public_mount_request',
                ],
            ],
        );

        return $request->fresh(['requestSerials', 'uploads']);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{latitude:?float,longitude:?float,formatted_address:?string,source:?string,accuracy:?string,note:?string,warning:?string,geocode_attempted:bool}
     */
    private function resolveLocation(array $payload): array
    {
        $formattedAddress = $this->nullableText($payload['location_formatted_address'] ?? null);
        $coordinates = $this->geocodingService->validCoordinatePair(
            $payload['location_latitude'] ?? null,
            $payload['location_longitude'] ?? null,
        );

        if ($coordinates !== null) {
            return [
                'latitude' => $coordinates['latitude'],
                'longitude' => $coordinates['longitude'],
                'formatted_address' => $formattedAddress,
                'source' => $this->nullableText($payload['location_source'] ?? null),
                'accuracy' => $this->nullableText($payload['location_accuracy'] ?? null),
                'note' => null,
                'warning' => null,
                'geocode_attempted' => false,
            ];
        }

        $query = $this->customerAddressQuery($payload);

        if ($query === null) {
            return [
                'latitude' => null,
                'longitude' => null,
                'formatted_address' => $formattedAddress,
                'source' => null,
                'accuracy' => null,
                'note' => null,
                'warning' => null,
                'geocode_attempted' => false,
            ];
        }

        $result = $this->geocodingService->geocodeText($query, 'customer_manual_address');

        if (($result['ok'] ?? false) === true) {
            $resultFormattedAddress = $this->nullableText($result['formatted_address'] ?? null);

            return [
                'latitude' => (float) $result['latitude'],
                'longitude' => (float) $result['longitude'],
                'formatted_address' => $formattedAddress ?? $resultFormattedAddress,
                'source' => 'manual_geocoded',
                'accuracy' => $this->nullableText($result['quality'] ?? null),
                'note' => $this->locationNote($result),
                'warning' => null,
                'geocode_attempted' => true,
            ];
        }

        return [
            'latitude' => null,
            'longitude' => null,
            'formatted_address' => $formattedAddress,
            'source' => 'geocode_failed',
            'accuracy' => null,
            'note' => $this->nullableText($result['error_message'] ?? null),
            'warning' => self::CUSTOMER_ADDRESS_GEOCODE_WARNING,
            'geocode_attempted' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function customerAddressQuery(array $payload): ?string
    {
        $address = $this->geocodingService->joinParts([
            $payload['location_formatted_address'] ?? null,
            $payload['service_address'] ?? null,
            $payload['customer_district'] ?? null,
            $payload['customer_city'] ?? null,
        ]);

        return $address !== null ? $this->geocodingService->joinParts([$address, 'Türkiye']) : null;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function locationNote(array $result): string
    {
        $formatted = $this->nullableText($result['formatted_address'] ?? null);
        $summary = 'Manual customer address geocoded';

        if ($formatted !== null) {
            $summary .= "; formatted: {$formatted}";
        }

        return $summary.'; at '.now()->toDateTimeString();
    }

    /**
     * @param  array<string, mixed>  $doorPhotos
     */
    private function storeDoorPhotos(TechnicalServiceRequest $request, array $doorPhotos): void
    {
        foreach (['door_front_photo', 'door_side_photo', 'door_back_photo'] as $fieldCode) {
            $file = $doorPhotos[$fieldCode] ?? null;

            if (! $file instanceof UploadedFile) {
                continue;
            }

            $extension = $file->extension() ?: $file->guessExtension() ?: 'jpg';
            $filename = $fieldCode.'-'.Str::uuid().'.'.$extension;
            $path = $file->storeAs("technical-service/requests/{$request->id}/door-photos", $filename, 'public');

            TechnicalServiceRequestUpload::query()->create([
                'technical_service_request_id' => $request->id,
                'field_code' => $fieldCode,
                'category' => TechnicalServiceRequestUpload::CATEGORY_OPERATION_CONTROL_DOOR_PHOTO,
                'original_name' => $file->getClientOriginalName(),
                'path' => $path,
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize() ?: 0,
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $invoiceRows
     * @param  array<int, string>  $selectedSerials
     */
    public function syncRequestSerials(
        TechnicalServiceRequest $request,
        array $invoiceRows,
        array $selectedSerials = [],
        array $operationAddedSerials = [],
        ?int $operationAddedBy = null,
    ): void {
        $request->requestSerials()->delete();
        $primarySerial = $this->normalizeSerial($request->serial_number);
        $selectedSet = array_fill_keys(array_filter(array_map(
            fn (mixed $serial): string => $this->normalizeSerial($serial),
            [...$selectedSerials, $request->serial_number],
        )), true);
        $operationAddedSet = array_fill_keys(array_filter(array_map(
            fn (mixed $serial): string => $this->normalizeSerial($serial),
            $operationAddedSerials,
        )), true);
        $persistedPrimary = false;

        foreach ($invoiceRows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $serialNumber = $this->nullableText($row['serial_number'] ?? null);

            if ($serialNumber === null) {
                continue;
            }

            $normalizedSerial = $this->normalizeSerial($serialNumber);
            $isPrimary = $normalizedSerial === $primarySerial;
            $isSelected = isset($selectedSet[$normalizedSerial]);
            $isOperationAdded = $isSelected || isset($operationAddedSet[$normalizedSerial]);
            $isReturned = (bool) ($row['is_returned'] ?? false);
            $customerVisible = (bool) ($row['customer_visible'] ?? false);
            $hiddenReason = $this->nullableText($row['hidden_reason'] ?? null);

            if (! $isSelected && $customerVisible && $hiddenReason === null) {
                $hiddenReason = 'not_selected';
            }

            TechnicalServiceRequestSerial::query()->create([
                'technical_service_request_id' => $request->id,
                'mrn' => $request->mrn,
                'serial_number' => $serialNumber,
                'product_name' => $this->nullableText($row['product_name'] ?? null),
                'product_model' => $this->nullableText($row['product_model'] ?? null),
                'brand' => $this->nullableText($row['brand'] ?? null),
                'stock_code' => $this->nullableText($row['stock_code'] ?? null),
                'invoice_series' => $this->nullableText($row['invoice_series'] ?? null),
                'invoice_number' => $this->nullableText($row['invoice_number'] ?? null),
                'customer_selected' => $isSelected,
                'customer_selectable' => (bool) ($row['customer_selectable'] ?? false),
                'customer_visible' => $customerVisible,
                'hidden_reason' => $hiddenReason,
                'operation_added' => $isOperationAdded && ! $isReturned,
                'operation_added_by' => $isOperationAdded && ! $isReturned ? $operationAddedBy : null,
                'operation_added_at' => $isOperationAdded && ! $isReturned ? now() : null,
                'customer_phone' => $request->customer_phone,
                'linked_mrn' => $request->mrn,
                'operation_note' => $this->nullableText($row['operation_note'] ?? null),
                'warning_labels' => $this->warningLabels($row),
                'is_primary' => $isPrimary,
                'is_returned' => $isReturned,
                'return_note' => $this->nullableText($row['return_note'] ?? null),
                'return_date' => $this->returnDate($row['return_date'] ?? null),
                'return_document_no' => $this->nullableText($row['return_document_no'] ?? null),
                'is_current_latest_sale' => array_key_exists('is_current_latest_sale', $row)
                    ? $row['is_current_latest_sale']
                    : null,
                'color_status' => $isReturned ? 'red' : ($isSelected || $isOperationAdded ? 'green' : 'orange'),
                'invoice_customer_type' => $this->nullableText($row['invoice_customer_type'] ?? null)
                    ?? TechnicalServiceRequestSerial::CUSTOMER_UNKNOWN,
                'source_payload' => $row['source_payload'] ?? $row,
            ]);

            $persistedPrimary = $persistedPrimary || $isPrimary;
        }

        if (! $persistedPrimary) {
            $this->attachPrimarySerial($request);
        }
    }

    private function attachPrimarySerial(
        TechnicalServiceRequest $request,
    ): void {
        TechnicalServiceRequestSerial::query()->create([
            'technical_service_request_id' => $request->id,
            'mrn' => $request->mrn,
            'serial_number' => $request->serial_number,
            'product_name' => $request->product_name,
            'product_model' => $request->product_model,
            'brand' => $request->brand,
            'stock_code' => $request->stock_code,
            'invoice_series' => $request->invoice_series,
            'invoice_number' => $request->invoice_number,
            'customer_selected' => true,
            'customer_selectable' => true,
            'customer_visible' => true,
            'operation_added' => true,
            'operation_added_at' => now(),
            'customer_phone' => $request->customer_phone,
            'linked_mrn' => $request->mrn,
            'is_primary' => true,
            'is_returned' => false,
            'is_current_latest_sale' => (bool) $request->has_current_sale,
            'color_status' => 'green',
            'invoice_customer_type' => $request->invoice_customer_type ?? TechnicalServiceRequestSerial::CUSTOMER_UNKNOWN,
            'source_payload' => Arr::except($request->qr_context_payload ?? [], ['secret', 'token']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, string>
     */
    private function warningLabels(array $row): array
    {
        $labels = $row['warning_labels'] ?? Arr::get($row, 'source_payload.warning_labels', []);

        if (! is_array($labels)) {
            $labels = [$labels];
        }

        return array_values(array_filter(array_map(
            fn (mixed $label): string => trim((string) $label),
            $labels,
        ), fn (string $label): bool => $label !== ''));
    }

    private function latestPayment(TechnicalServiceMountSession $session): ?TechnicalServiceMountPayment
    {
        $payment = $session->payments()
            ->latest('id')
            ->first();

        return $payment instanceof TechnicalServiceMountPayment ? $payment : null;
    }

    /**
     * @return array{status:string,label:string}
     */
    private function paymentState(TechnicalServiceMountSession $session, ?TechnicalServiceMountPayment $payment): array
    {
        if ($payment instanceof TechnicalServiceMountPayment && $payment->status === TechnicalServiceMountPayment::STATUS_PAID) {
            return [
                'status' => TechnicalServiceMountSession::PAYMENT_PAID,
                'label' => 'Montaj ödemesi alındı',
            ];
        }

        if ($session->mount_payment_status === TechnicalServiceMountSession::PAYMENT_NOT_REQUIRED) {
            return [
                'status' => TechnicalServiceMountSession::PAYMENT_NOT_REQUIRED,
                'label' => 'Montaj dahil',
            ];
        }

        if ($session->mount_payment_status === TechnicalServiceMountSession::PAYMENT_SKIPPED_MULTI_PRODUCT) {
            return [
                'status' => TechnicalServiceMountSession::PAYMENT_SKIPPED_MULTI_PRODUCT,
                'label' => 'Çoklu ürün talebi - ödeme operasyon tarafından netleştirilecek',
            ];
        }

        return [
            'status' => $session->mount_payment_status ?? TechnicalServiceMountSession::PAYMENT_PENDING,
            'label' => 'Montaj ödemesi bekleniyor',
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{invoice_series:?string,invoice_number:?string,invoice_display_no:?string,dispatch_series:?string,dispatch_number:?string,dispatch_display_no:?string,order_series:?string,order_number:?string,order_display_no:?string}
     */
    private function documentContext(array $context): array
    {
        $decision = Arr::get($context, 'resolver_payload.mikro_decision', []);
        if (! is_array($decision)) {
            $decision = [];
        }

        $invoiceSeries = $this->firstText($context, $decision, ['invoice_series', 'fatura_seri', 'Fatura Seri']);
        $invoiceNumber = $this->firstText($context, $decision, ['invoice_number', 'fatura_sira', 'Fatura Sıra', 'Fatura Sira']);
        $dispatchSeries = $this->firstText($context, $decision, ['dispatch_series', 'irsaliye_seri', 'İrsaliye Seri', 'Irsaliye Seri']);
        $dispatchNumber = $this->firstText($context, $decision, ['dispatch_number', 'irsaliye_sira', 'İrsaliye Sıra', 'Irsaliye Sira']);
        $orderSeries = $this->firstText($context, $decision, ['order_series', 'siparis_seri', 'Sipariş Seri', 'Siparis Seri']);
        $orderNumber = $this->firstText($context, $decision, ['order_number', 'siparis_sira', 'Sipariş Sıra', 'Siparis Sira']);

        return [
            'invoice_series' => $invoiceSeries,
            'invoice_number' => $invoiceNumber,
            'invoice_display_no' => $this->displayDocumentNo($invoiceSeries, $invoiceNumber),
            'dispatch_series' => $dispatchSeries,
            'dispatch_number' => $dispatchNumber,
            'dispatch_display_no' => $this->displayDocumentNo($dispatchSeries, $dispatchNumber),
            'order_series' => $orderSeries,
            'order_number' => $orderNumber,
            'order_display_no' => $this->displayDocumentNo($orderSeries, $orderNumber),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $decision
     * @param  array<int, string>  $keys
     */
    private function firstText(array $context, array $decision, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->nullableText($context[$key] ?? null) ?? $this->nullableText($decision[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function displayDocumentNo(?string $series, ?string $number): ?string
    {
        $series = $this->nullableText($series);
        $number = $this->nullableText($number);

        if ($series !== null && $number !== null) {
            return "{$series}/{$number}";
        }

        return $number ?? $series;
    }

    private function nullableBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private function normalizeSerial(mixed $value): string
    {
        return mb_strtoupper(trim((string) $value), 'UTF-8');
    }

    private function returnDate(mixed $value): ?string
    {
        $value = $this->nullableText($value);

        if ($value === null) {
            return null;
        }

        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $value, $matches)) {
            return "{$matches[3]}-{$matches[2]}-{$matches[1]}";
        }

        return substr($value, 0, 10);
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function linkPaymentToRequest(?TechnicalServiceMountPayment $payment, TechnicalServiceRequest $request): void
    {
        if (! $payment instanceof TechnicalServiceMountPayment) {
            return;
        }

        $rawPayload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $rawPayload['source'] = 'public_form_payment';
        $rawPayload['technical_service_request_id'] = $request->id;
        $rawPayload['mrn'] = $request->mrn;
        $rawPayload['mount_payment_status'] = $payment->status;
        $rawPayload['paid_amount'] = (float) $payment->amount;
        $rawPayload['paid_at'] = $payment->paid_at?->toISOString();

        $payment->forceFill([
            'technical_service_request_id' => $request->id,
            'raw_payload' => $rawPayload,
        ])->save();
    }
}
