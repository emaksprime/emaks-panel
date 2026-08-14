<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServicePartRequest;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use App\Services\Mikro\MikroApiClient;
use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class TechnicalServicePaymentOrderContextService
{
    public const TABLE = 'technical_service_payment_order_contexts';

    public const PURPOSE_MOUNT_COLLECTION = 'mount_collection';

    public const PURPOSE_PART_CHARGE = 'part_charge';

    public const SUPPLIER_EMAKS = 'emaks_prime';

    public const SUPPLIER_TECHNICIAN = 'technician';

    public const ALLOCATION_RETAIN_COMPANY = 'retain_company';

    public const ALLOCATION_PAY_TECHNICIAN = 'pay_technician';

    private const DESCRIPTION2_VERSION = 1;

    public function __construct(
        private readonly MikroApiClient $mikro,
        private readonly TechnicalServiceAssignmentSettlementService $assignmentSettlements,
    ) {}

    /** @return array<int, array{key:string,label:string}> */
    public function paymentPurposes(): array
    {
        return [
            ['key' => 'general_extra', 'label' => 'Genel ek tahsilat'],
            ['key' => 'extra_service', 'label' => 'Ek servis'],
            ['key' => 'route_difference', 'label' => 'Yol farkı'],
            ['key' => self::PURPOSE_MOUNT_COLLECTION, 'label' => 'Montaj ücreti tahsilatı'],
            ['key' => self::PURPOSE_PART_CHARGE, 'label' => 'Parça ödemesi'],
        ];
    }

    /**
     * @return array{source:string,source_label:string,freshness_at:string,items:array<int, array<string, mixed>>}
     */
    public function searchParts(TechnicalServiceRequest $request, string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            throw ValidationException::withMessages([
                'query' => 'Parça araması için en az 2 karakter girin.',
            ]);
        }

        if (app()->environment(['local', 'testing'])) {
            return $this->localPartFixtures($request, $query);
        }

        try {
            $result = $this->mikro->listStocks($query, size: 20);
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'query' => 'Mikro stok bilgisi şu anda alınamıyor. Parça seçimi tamamlanamadı.',
            ]);
        }

        try {
            $rows = collect($result['data'] ?? $result['rows'] ?? $result['result'] ?? [])
                ->filter(fn (mixed $row): bool => is_array($row))
                ->take(20)
                ->map(function (array $row) use ($request): array {
                    $item = [
                        'item_code' => trim((string) ($row['stock_code'] ?? '')),
                        'item_name' => trim((string) ($row['stock_name'] ?? '')),
                        'unit_code' => trim((string) ($row['unit_code'] ?? 'ADET')) ?: 'ADET',
                        'warehouse_code' => trim((string) ($row['warehouse_code'] ?? '')) ?: null,
                        'on_hand' => is_numeric($row['on_hand'] ?? null) ? (float) $row['on_hand'] : null,
                        'reserved' => is_numeric($row['reserved'] ?? null) ? (float) $row['reserved'] : null,
                        'available' => is_numeric($row['available'] ?? null) ? (float) $row['available'] : null,
                        'serial_tracking_required' => (bool) ($row['serial_tracking_required'] ?? false),
                        'serials' => [],
                        'source' => 'mikro',
                        'source_label' => 'Mikro read-only',
                        'freshness_at' => now()->toISOString(),
                    ];

                    if ($item['item_code'] === '' || $item['item_name'] === '' || $item['available'] === null) {
                        throw new DomainException('MIKRO_STOCK_SELECTION_SCHEMA_INCOMPLETE');
                    }

                    return $this->partSearchItem($request, $item);
                })
                ->values();
        } catch (DomainException $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'query' => 'Mikro stok yanıt sözleşmesi parça seçimi için doğrulanmadı. Parça seçimi tamamlanamadı.',
            ]);
        }

        if ($rows->isEmpty()) {
            return [
                'source' => 'mikro',
                'source_label' => 'Mikro read-only',
                'freshness_at' => now()->toISOString(),
                'items' => [],
            ];
        }

        return [
            'source' => 'mikro',
            'source_label' => 'Mikro read-only',
            'freshness_at' => now()->toISOString(),
            'items' => $rows->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function preview(
        TechnicalServiceRequest $request,
        string $purpose,
        array $input,
        float $amount,
        string $currency,
    ): array {
        $normalized = $this->normalize($request, $purpose, $input, $amount, $currency);
        $existing = DB::table(self::TABLE)
            ->where('technical_service_request_id', $request->id)
            ->where('payment_purpose', $purpose)
            ->where('context_hash', $normalized['context_hash'])
            ->latest('revision')
            ->first();
        $latestRevision = (int) DB::table(self::TABLE)
            ->where('technical_service_request_id', $request->id)
            ->where('payment_purpose', $purpose)
            ->max('revision');
        $revision = $existing ? (int) $existing->revision : $latestRevision + 1;

        return [
            ...$normalized,
            'id' => $existing ? (int) $existing->id : null,
            'revision' => $revision,
            'reusable' => $existing !== null,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{context:object,payment:?TechnicalServiceMountPayment,created:bool}
     */
    public function prepare(
        TechnicalServiceRequest $request,
        string $purpose,
        array $input,
        float $amount,
        string $currency,
        ?Authenticatable $actor,
        bool $allowTerminalRetry,
    ): array {
        $normalized = $this->normalize($request, $purpose, $input, $amount, $currency);
        $expectedHash = trim((string) ($input['expected_context_hash'] ?? ''));
        $expectedRevision = (int) ($input['expected_revision'] ?? 0);
        if ($expectedHash === '' || ! hash_equals($normalized['context_hash'], $expectedHash)) {
            throw ValidationException::withMessages([
                'order_context' => 'Sipariş hazırlığı değişti. Güncel önizlemeyi kontrol edip tekrar deneyin.',
            ]);
        }

        return DB::transaction(function () use (
            $request,
            $purpose,
            $normalized,
            $expectedRevision,
            $actor,
            $allowTerminalRetry,
        ): array {
            $rows = DB::table(self::TABLE)
                ->where('technical_service_request_id', $request->id)
                ->where('payment_purpose', $purpose)
                ->orderBy('revision')
                ->lockForUpdate()
                ->get();
            $exact = $rows->last(fn (object $row): bool => hash_equals((string) $row->context_hash, $normalized['context_hash']));
            $latestRevision = (int) ($rows->max('revision') ?? 0);

            if ($exact && is_numeric($exact->technical_service_mount_payment_id)) {
                $payment = TechnicalServiceMountPayment::query()
                    ->whereKey((int) $exact->technical_service_mount_payment_id)
                    ->lockForUpdate()
                    ->first();
                if ($payment instanceof TechnicalServiceMountPayment
                    && in_array($payment->status, [
                        TechnicalServiceMountPayment::STATUS_PENDING,
                        TechnicalServiceMountPayment::STATUS_PAID,
                    ], true)) {
                    if ($expectedRevision !== (int) $exact->revision) {
                        throw ValidationException::withMessages([
                            'order_context' => 'Sipariş hazırlığı revizyonu güncellendi. Önizlemeyi yenileyin.',
                        ]);
                    }

                    return ['context' => $exact, 'payment' => $payment, 'created' => false];
                }
                if ($payment instanceof TechnicalServiceMountPayment
                    && in_array($payment->status, [
                        TechnicalServiceMountPayment::STATUS_CANCELLED,
                        TechnicalServiceMountPayment::STATUS_FAILED,
                        TechnicalServiceMountPayment::STATUS_EXPIRED,
                    ], true)
                    && ! $allowTerminalRetry) {
                    throw ValidationException::withMessages([
                        'terminal_retry_reason' => 'Önceki bağlantı terminal durumda. Açıklamalı yeni bağlantı oluşturun.',
                    ]);
                }
            }

            $pendingDifferentContext = $rows
                ->filter(fn (object $row): bool => ! hash_equals((string) $row->context_hash, $normalized['context_hash']))
                ->first(function (object $row): bool {
                    if (! is_numeric($row->technical_service_mount_payment_id)) {
                        return false;
                    }

                    return TechnicalServiceMountPayment::query()
                        ->whereKey((int) $row->technical_service_mount_payment_id)
                        ->where('status', TechnicalServiceMountPayment::STATUS_PENDING)
                        ->exists();
                });
            if ($pendingDifferentContext) {
                throw ValidationException::withMessages([
                    'order_context' => 'Fatura, sevk, parça veya tutar değişti. Önce mevcut bekleyen ödeme bağlantısını iptal edin.',
                ]);
            }

            if ($exact && ! is_numeric($exact->technical_service_mount_payment_id)) {
                if ($expectedRevision !== (int) $exact->revision) {
                    throw ValidationException::withMessages([
                        'order_context' => 'Sipariş hazırlığı revizyonu güncellendi. Önizlemeyi yenileyin.',
                    ]);
                }

                return ['context' => $exact, 'payment' => null, 'created' => false];
            }

            $revision = $latestRevision + 1;
            if ($expectedRevision !== $revision) {
                throw ValidationException::withMessages([
                    'order_context' => 'Sipariş hazırlığı revizyonu güncellendi. Önizlemeyi yenileyin.',
                ]);
            }

            $idempotencyKey = hash('sha256', implode('|', [
                'technical-service-payment-order-context-v1',
                $request->id,
                $purpose,
                $normalized['context_hash'],
                $revision,
            ]));
            $id = DB::table(self::TABLE)->insertGetId($this->databasePayload(
                $request,
                $purpose,
                $normalized,
                $revision,
                $idempotencyKey,
                $actor,
            ));
            $context = DB::table(self::TABLE)->where('id', $id)->first();

            $request->events()->create([
                'event_type' => 'payment_order_context_prepared',
                'title' => $purpose === self::PURPOSE_MOUNT_COLLECTION
                    ? 'Montaj sipariş hazırlığı oluşturuldu'
                    : 'Parça sipariş hazırlığı oluşturuldu',
                'note' => 'Yalnız yerel hazırlık kaydı oluşturuldu; Mikro ve kargo yazımı yapılmadı.',
                'from_status' => $request->workflow_status,
                'to_status' => $request->workflow_status,
                'author_user_id' => $actor?->getAuthIdentifier(),
                'metadata' => [
                    'order_context_id' => $id,
                    'context_hash' => $normalized['context_hash'],
                    'revision' => $revision,
                    'payment_purpose' => $purpose,
                    'desired_mikro_series' => $normalized['desired_mikro_series'],
                    'future_mikro_write_state' => $normalized['future_mikro_write_state'],
                    'shipment_required' => $normalized['shipment_required'],
                ],
            ]);

            return ['context' => $context, 'payment' => null, 'created' => true];
        });
    }

    public function attachPayment(object $context, TechnicalServiceMountPayment $payment): TechnicalServiceMountPayment
    {
        return DB::transaction(function () use ($context, $payment): TechnicalServiceMountPayment {
            $lockedContext = DB::table(self::TABLE)->where('id', (int) $context->id)->lockForUpdate()->first();
            $lockedPayment = TechnicalServiceMountPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if (! $lockedContext
                || (int) $lockedContext->technical_service_request_id !== (int) $lockedPayment->technical_service_request_id) {
                throw ValidationException::withMessages([
                    'order_context' => 'Ödeme ve sipariş hazırlığı aynı talebe ait olmalıdır.',
                ]);
            }
            if (is_numeric($lockedContext->technical_service_mount_payment_id)
                && (int) $lockedContext->technical_service_mount_payment_id !== (int) $lockedPayment->id) {
                throw ValidationException::withMessages([
                    'order_context' => 'Sipariş hazırlığı başka bir ödeme bağlantısına bağlıdır.',
                ]);
            }

            DB::table(self::TABLE)->where('id', $lockedContext->id)->update([
                'technical_service_mount_payment_id' => $lockedPayment->id,
                'state' => 'payment_pending',
                'updated_at' => now(),
            ]);
            $context = DB::table(self::TABLE)->where('id', $lockedContext->id)->first();
            $this->writePaymentSnapshot($lockedPayment, $context);

            return $lockedPayment->fresh();
        });
    }

    public function releaseFailedPayment(int $contextId, ?TechnicalServiceMountPayment $payment): void
    {
        DB::transaction(function () use ($contextId, $payment): void {
            $context = DB::table(self::TABLE)->where('id', $contextId)->lockForUpdate()->first();
            if (! $context) {
                return;
            }
            if ($payment instanceof TechnicalServiceMountPayment
                && is_numeric($context->technical_service_mount_payment_id)
                && (int) $context->technical_service_mount_payment_id !== (int) $payment->id) {
                return;
            }

            DB::table(self::TABLE)->where('id', $contextId)->update([
                'technical_service_mount_payment_id' => null,
                'state' => 'draft',
                'updated_at' => now(),
            ]);
        });
    }

    public function markCancelled(TechnicalServiceMountPayment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            $context = DB::table(self::TABLE)
                ->where('technical_service_mount_payment_id', $payment->id)
                ->lockForUpdate()
                ->first();
            if (! $context || $payment->status !== TechnicalServiceMountPayment::STATUS_CANCELLED) {
                return;
            }

            DB::table(self::TABLE)->where('id', $context->id)->update([
                'state' => 'cancelled',
                'updated_at' => now(),
            ]);
            $this->writePaymentSnapshot($payment, DB::table(self::TABLE)->where('id', $context->id)->first());
        });
    }

    public function markPaidWithinTransaction(TechnicalServiceMountPayment $payment): void
    {
        $context = DB::table(self::TABLE)
            ->where('technical_service_mount_payment_id', $payment->id)
            ->lockForUpdate()
            ->first();
        if (! $context || $payment->status !== TechnicalServiceMountPayment::STATUS_PAID) {
            return;
        }
        if ((string) $context->state === 'paid_waiting_mikro_write') {
            return;
        }
        if ((string) $context->state === 'cancelled') {
            throw ValidationException::withMessages([
                'order_context' => 'İptal edilmiş sipariş hazırlığı ödeme sonrası hazır duruma geçirilemez.',
            ]);
        }

        $request = TechnicalServiceRequest::query()->whereKey($context->technical_service_request_id)->lockForUpdate()->firstOrFail();
        if ((string) $context->context_type === 'technician_supplied_part') {
            $actor = is_numeric($context->created_by) ? User::query()->find((int) $context->created_by) : null;
            if (! $actor instanceof User) {
                throw ValidationException::withMessages([
                    'order_context' => 'Ustanın sağladığı parça tahsilatını hakedişe bağlayan yetkili kullanıcı bulunamadı.',
                ]);
            }
            $this->assignmentSettlements->applyPreparedPartSupplierAllocation($request, $payment, $actor);
        }

        DB::table(self::TABLE)->where('id', $context->id)->update([
            'state' => 'paid_waiting_mikro_write',
            'updated_at' => now(),
        ]);
        $updatedContext = DB::table(self::TABLE)->where('id', $context->id)->first();
        $this->writePaymentSnapshot($payment, $updatedContext);

        $request->events()->create([
            'event_type' => 'payment_order_context_paid_ready',
            'title' => (string) $context->context_type === 'mount_service'
                ? 'S hizmet siparişi hazırlığı tamamlandı'
                : ((string) $context->context_type === 'technician_supplied_part'
                    ? 'Ustanın sağladığı parça tahsilatı hakedişe bağlandı'
                    : 'S parça siparişi hazırlığı tamamlandı'),
            'note' => (string) $context->context_type === 'technician_supplied_part'
                ? 'Mikro ve kargo hazırlığı gerekmez; mevcut hakediş sahibi üzerinden allocation bir kez uygulandı.'
                : 'Mikro yazımı ve kargo sağlayıcı çağrısı yapılmadı.',
            'from_status' => $request->workflow_status,
            'to_status' => $request->workflow_status,
            'author_user_id' => $context->created_by,
            'metadata' => [
                'order_context_id' => (int) $context->id,
                'payment_id' => (int) $payment->id,
                'context_hash' => (string) $context->context_hash,
                'desired_mikro_series' => $context->desired_mikro_series,
                'future_mikro_write_state' => $context->future_mikro_write_state,
                'future_carrier_state' => $context->future_carrier_state,
                'mikro_write_execution_count' => 0,
                'carrier_execution_count' => 0,
            ],
        ]);
    }

    /** @return array<string, mixed>|null */
    public function paymentProjection(TechnicalServiceMountPayment $payment): ?array
    {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $snapshot = $payload['order_context'] ?? null;

        return is_array($snapshot) ? $snapshot : null;
    }

    /** @return array<string, mixed> */
    public function contextProjection(object $context): array
    {
        return $this->rowProjection($context);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalize(
        TechnicalServiceRequest $request,
        string $purpose,
        array $input,
        float $amount,
        string $currency,
    ): array {
        if (! in_array($purpose, [self::PURPOSE_MOUNT_COLLECTION, self::PURPOSE_PART_CHARGE], true)) {
            throw ValidationException::withMessages(['purpose' => 'Sipariş hazırlığı bu tahsilat amacı için kullanılamaz.']);
        }
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Ödeme tutarı 0 TL üzerinde olmalıdır.']);
        }
        $currency = strtoupper(trim($currency));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw ValidationException::withMessages(['currency' => 'Para birimi üç harfli kod olmalıdır.']);
        }

        $billing = $this->billingSnapshot($request, $input);
        $requestCode = trim((string) ($request->service_code ?: $request->mrn));
        $relatedProductSerial = trim((string) $request->serial_number);
        $contextType = $purpose === self::PURPOSE_MOUNT_COLLECTION ? 'mount_service' : 'part_sale';
        $partSupplier = null;
        $collectionAllocation = null;
        $part = null;
        $shippingSameAsBilling = false;
        $deliveryTarget = null;
        $shipping = null;
        $shipmentRequired = false;
        $futureCarrierState = 'not_required';
        $desiredMikroSeries = 'S';
        $futureMikroWriteState = 'not_authorized';

        if ($purpose === self::PURPOSE_PART_CHARGE) {
            if ($relatedProductSerial === '') {
                throw ValidationException::withMessages([
                    'order_context.related_product_serial' => 'Parça sipariş hazırlığı için servis verilen ürün seri numarası gereklidir.',
                ]);
            }
            $partSupplier = trim((string) ($input['part_supplier'] ?? ''));
            if (! in_array($partSupplier, [self::SUPPLIER_EMAKS, self::SUPPLIER_TECHNICIAN], true)) {
                throw ValidationException::withMessages([
                    'order_context.part_supplier' => 'Parçayı EMAKS Prime veya ustanın sağlayacağını seçin.',
                ]);
            }

            if ($partSupplier === self::SUPPLIER_EMAKS) {
                $part = $this->selectedStockItem($request, $input);
                $shipmentRequired = true;
                $futureCarrierState = 'waiting_future_integration';
                $collectionAllocation = self::ALLOCATION_RETAIN_COMPANY;
                $shippingSameAsBilling = (bool) ($input['shipping_same_as_billing'] ?? false);
                [$deliveryTarget, $shipping] = $this->shippingSnapshot($request, $input, $billing, $shippingSameAsBilling);
            } else {
                $contextType = 'technician_supplied_part';
                $desiredMikroSeries = null;
                $futureMikroWriteState = 'not_required';
                $collectionAllocation = self::ALLOCATION_PAY_TECHNICIAN;
                $part = $this->technicianPartSnapshot($input);
                $this->activeTechnician($request);
            }
        }

        $partRequestId = $this->partRequestId($request, $input);
        $normalized = [
            'request_id' => (int) $request->id,
            'root_request_id' => (int) ($request->parent_request_id ?: $request->id),
            'srv_request_id' => $request->parent_request_id !== null || filled($request->service_code) ? (int) $request->id : null,
            'request_code' => $requestCode,
            'root_mrn' => trim((string) ($request->root_mrn ?: $request->mrn)),
            'part_request_id' => $partRequestId,
            'payment_purpose' => $purpose,
            'purpose_label' => $purpose === self::PURPOSE_MOUNT_COLLECTION ? 'Montaj ücreti tahsilatı' : 'Parça ödemesi',
            'context_type' => $contextType,
            'state' => 'draft',
            'state_label' => 'Sipariş hazırlığı taslak',
            'desired_mikro_series' => $desiredMikroSeries,
            'future_mikro_write_state' => $futureMikroWriteState,
            'future_mikro_write_label' => $futureMikroWriteState === 'not_required'
                ? 'Mikro siparişi gerekmiyor'
                : 'Mikro yazımı bu aşamada kapalı',
            'billing' => $billing,
            'shipping_same_as_billing' => $shippingSameAsBilling,
            'delivery_target' => $deliveryTarget,
            'delivery_target_label' => $this->deliveryTargetLabel($deliveryTarget),
            'shipping' => $shipping,
            'part_supplier' => $partSupplier,
            'part_supplier_label' => match ($partSupplier) {
                self::SUPPLIER_EMAKS => 'EMAKS Prime',
                self::SUPPLIER_TECHNICIAN => 'Usta',
                default => null,
            },
            'collection_allocation' => $collectionAllocation,
            'collection_allocation_label' => match ($collectionAllocation) {
                self::ALLOCATION_RETAIN_COMPANY => 'Şirkette bırakılacak',
                self::ALLOCATION_PAY_TECHNICIAN => 'Ustaya hakediş olarak eklenecek',
                default => null,
            },
            'part' => $part,
            'related_product_serial' => $relatedProductSerial !== '' ? $relatedProductSerial : null,
            'charged_amount' => round($amount, 2),
            'charged_amount_label' => $this->moneyLabel($amount, $currency),
            'currency' => $currency,
            'shipment_required' => $shipmentRequired,
            'future_carrier_state' => $futureCarrierState,
            'future_carrier_label' => $shipmentRequired
                ? 'Kargo hazırlığı bekliyor; HepsiJet entegrasyonu çalıştırılmayacak'
                : 'Sevkiyat yok',
            'mikro_write_execution_count' => 0,
            'carrier_execution_count' => 0,
            'description2_version' => self::DESCRIPTION2_VERSION,
        ];
        $normalized['description2_preview'] = $this->renderDescription2($request, $normalized);
        $identity = [
            'request_id' => $normalized['request_id'],
            'root_request_id' => $normalized['root_request_id'],
            'srv_request_id' => $normalized['srv_request_id'],
            'payment_purpose' => $purpose,
            'context_type' => $contextType,
            'desired_mikro_series' => $desiredMikroSeries,
            'billing' => $billing,
            'shipping_same_as_billing' => $shippingSameAsBilling,
            'delivery_target' => $deliveryTarget,
            'shipping' => $shipping,
            'part_supplier' => $partSupplier,
            'collection_allocation' => $collectionAllocation,
            'part' => $this->partIdentity($part),
            'related_product_serial' => $normalized['related_product_serial'],
            'charged_amount' => number_format($amount, 2, '.', ''),
            'currency' => $currency,
            'shipment_required' => $shipmentRequired,
            'future_carrier_state' => $futureCarrierState,
            'description2_version' => self::DESCRIPTION2_VERSION,
        ];
        $normalized['context_hash'] = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $normalized;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function billingSnapshot(TechnicalServiceRequest $request, array $input): array
    {
        $source = trim((string) ($input['billing_source'] ?? 'mrn_customer'));
        if ($source === 'mrn_customer') {
            $snapshot = [
                'source' => $source,
                'customer_code' => null,
                'name_or_title' => trim((string) $request->customer_name),
                'phone' => trim((string) $request->customer_phone),
                'email' => null,
                'tax_identity' => null,
                'tax_office' => null,
                'address' => $this->requestAddress($request),
                'city' => trim((string) $request->customer_city),
                'district' => trim((string) $request->customer_district),
                'postal_code' => null,
            ];
        } elseif ($source === 'manual_billing_draft') {
            $billing = is_array($input['billing'] ?? null) ? $input['billing'] : [];
            $snapshot = [
                'source' => $source,
                'customer_code' => $this->nullableText($billing['customer_code'] ?? null),
                'name_or_title' => trim((string) ($billing['name_or_title'] ?? '')),
                'phone' => trim((string) ($billing['phone'] ?? '')),
                'email' => $this->nullableText($billing['email'] ?? null),
                'tax_identity' => $this->nullableText($billing['tax_identity'] ?? null),
                'tax_office' => $this->nullableText($billing['tax_office'] ?? null),
                'address' => trim((string) ($billing['address'] ?? '')),
                'city' => trim((string) ($billing['city'] ?? '')),
                'district' => trim((string) ($billing['district'] ?? '')),
                'postal_code' => $this->nullableText($billing['postal_code'] ?? null),
            ];
        } else {
            throw ValidationException::withMessages([
                'order_context.billing_source' => 'Bu ortamda yalnız MRN müşterisi veya yerel manuel fatura taslağı seçilebilir. Mikro müşteri yazımı kapalıdır.',
            ]);
        }

        $this->requireSnapshot($snapshot, ['name_or_title', 'phone', 'address', 'city', 'district'], 'Fatura bilgileri');

        return $snapshot;
    }

    /** @param array<string, mixed> $input @return array{0:string,1:array<string, mixed>} */
    private function shippingSnapshot(
        TechnicalServiceRequest $request,
        array $input,
        array $billing,
        bool $sameAsBilling,
    ): array {
        if ($sameAsBilling) {
            return ['billing_address', $this->recipientFromBilling($billing)];
        }

        $target = trim((string) ($input['delivery_target'] ?? ''));
        $snapshot = match ($target) {
            'billing_address' => $this->recipientFromBilling($billing),
            'mrn_customer' => [
                'recipient_name' => trim((string) $request->customer_name),
                'recipient_phone' => trim((string) $request->customer_phone),
                'address' => $this->requestAddress($request),
                'city' => trim((string) $request->customer_city),
                'district' => trim((string) $request->customer_district),
                'postal_code' => null,
            ],
            'technician' => $this->recipientFromTechnician($this->activeTechnician($request)),
            'custom_recipient' => $this->customRecipient($input),
            default => throw ValidationException::withMessages([
                'order_context.delivery_target' => 'Parça için sevk alıcısı ve adresini seçin.',
            ]),
        };
        $this->requireSnapshot($snapshot, ['recipient_name', 'recipient_phone', 'address', 'city', 'district'], 'Sevk bilgileri');

        return [$target, $snapshot];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function selectedStockItem(TechnicalServiceRequest $request, array $input): array
    {
        $token = trim((string) ($input['stock_selection_token'] ?? ''));
        if ($token === '') {
            throw ValidationException::withMessages([
                'order_context.stock_selection_token' => 'EMAKS Prime parçası için Mikro stok listesinden bir parça seçin.',
            ]);
        }

        try {
            $decoded = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'order_context.stock_selection_token' => 'Parça seçimi doğrulanamadı. Stok aramasından tekrar seçin.',
            ]);
        }
        if (! is_array($decoded) || (int) ($decoded['request_id'] ?? 0) !== (int) $request->id) {
            throw ValidationException::withMessages([
                'order_context.stock_selection_token' => 'Seçilen parça bu teknik servis talebine ait değildir.',
            ]);
        }

        $quantity = is_numeric($input['quantity'] ?? null) ? round((float) $input['quantity'], 3) : 0.0;
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['order_context.quantity' => 'Parça adedi 0 üzerinde olmalıdır.']);
        }
        $serialRequired = (bool) ($decoded['serial_tracking_required'] ?? false);
        $selectedSerial = trim((string) ($input['selected_part_serial'] ?? ''));
        $serials = collect(is_array($decoded['serials'] ?? null) ? $decoded['serials'] : [])
            ->map(fn (mixed $serial): string => trim((string) $serial))
            ->filter()
            ->values();
        if ($serialRequired && ($selectedSerial === '' || ! $serials->contains($selectedSerial))) {
            throw ValidationException::withMessages([
                'order_context.selected_part_serial' => 'Seri takipli parça için doğrulanmış parça seri numarasını seçin.',
            ]);
        }

        return [
            'item_code' => trim((string) ($decoded['item_code'] ?? '')),
            'item_name' => trim((string) ($decoded['item_name'] ?? '')),
            'quantity' => $quantity,
            'unit_code' => trim((string) ($decoded['unit_code'] ?? 'ADET')) ?: 'ADET',
            'warehouse_code' => $this->nullableText($decoded['warehouse_code'] ?? null),
            'stock_source' => trim((string) ($decoded['source'] ?? '')),
            'stock_source_label' => trim((string) ($decoded['source_label'] ?? '')),
            'stock_freshness_at' => trim((string) ($decoded['freshness_at'] ?? '')),
            'on_hand' => $decoded['on_hand'] ?? null,
            'reserved' => $decoded['reserved'] ?? null,
            'available' => $decoded['available'] ?? null,
            'serial_tracking_required' => $serialRequired,
            'selected_part_serial' => $serialRequired ? $selectedSerial : null,
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function technicianPartSnapshot(array $input): array
    {
        $quantity = is_numeric($input['quantity'] ?? null) ? round((float) $input['quantity'], 3) : 0.0;
        $name = trim((string) ($input['technician_part_name'] ?? ''));
        if ($name === '' || $quantity <= 0) {
            throw ValidationException::withMessages([
                'order_context.technician_part_name' => 'Ustanın sağladığı parça adı ve adedi zorunludur.',
            ]);
        }

        return [
            'item_code' => $this->nullableText($input['technician_part_code'] ?? null),
            'item_name' => $name,
            'quantity' => $quantity,
            'unit_code' => 'ADET',
            'warehouse_code' => null,
            'stock_source' => 'technician_declaration',
            'stock_source_label' => 'Usta beyanı',
            'stock_freshness_at' => now()->toISOString(),
            'on_hand' => null,
            'reserved' => null,
            'available' => null,
            'serial_tracking_required' => false,
            'selected_part_serial' => null,
        ];
    }

    /** @param array<string, mixed> $normalized @return array<string, mixed> */
    private function databasePayload(
        TechnicalServiceRequest $request,
        string $purpose,
        array $normalized,
        int $revision,
        string $idempotencyKey,
        ?Authenticatable $actor,
    ): array {
        $billing = $normalized['billing'];
        $shipping = is_array($normalized['shipping']) ? $normalized['shipping'] : [];
        $part = is_array($normalized['part']) ? $normalized['part'] : [];

        return [
            'technical_service_request_id' => $request->id,
            'root_request_id' => $normalized['root_request_id'],
            'srv_request_id' => $normalized['srv_request_id'],
            'technical_service_mount_payment_id' => null,
            'technical_service_part_request_id' => $normalized['part_request_id'],
            'payment_purpose' => $purpose,
            'context_type' => $normalized['context_type'],
            'state' => 'draft',
            'desired_mikro_series' => $normalized['desired_mikro_series'],
            'future_mikro_write_state' => $normalized['future_mikro_write_state'],
            'billing_source' => $billing['source'],
            'billing_customer_code' => $billing['customer_code'],
            'billing_name_or_title' => $billing['name_or_title'],
            'billing_phone' => $billing['phone'],
            'billing_email' => $billing['email'],
            'billing_tax_identity' => $billing['tax_identity'],
            'billing_tax_office' => $billing['tax_office'],
            'billing_address' => $billing['address'],
            'billing_city' => $billing['city'],
            'billing_district' => $billing['district'],
            'billing_postal_code' => $billing['postal_code'],
            'shipping_same_as_billing' => $normalized['shipping_same_as_billing'],
            'delivery_target' => $normalized['delivery_target'],
            'shipping_recipient_name' => $shipping['recipient_name'] ?? null,
            'shipping_recipient_phone' => $shipping['recipient_phone'] ?? null,
            'shipping_address' => $shipping['address'] ?? null,
            'shipping_city' => $shipping['city'] ?? null,
            'shipping_district' => $shipping['district'] ?? null,
            'shipping_postal_code' => $shipping['postal_code'] ?? null,
            'part_supplier' => $normalized['part_supplier'],
            'collection_allocation' => $normalized['collection_allocation'],
            'item_code' => $part['item_code'] ?? null,
            'item_name_snapshot' => $part['item_name'] ?? null,
            'quantity' => $part['quantity'] ?? null,
            'unit_code' => $part['unit_code'] ?? null,
            'warehouse_code' => $part['warehouse_code'] ?? null,
            'stock_source' => $part['stock_source'] ?? null,
            'stock_freshness_at' => filled($part['stock_freshness_at'] ?? null) ? $part['stock_freshness_at'] : null,
            'part_serial_tracking_required' => (bool) ($part['serial_tracking_required'] ?? false),
            'selected_part_serial' => $part['selected_part_serial'] ?? null,
            'related_product_serial' => $normalized['related_product_serial'],
            'charged_amount' => $normalized['charged_amount'],
            'currency' => $normalized['currency'],
            'shipment_required' => $normalized['shipment_required'],
            'future_carrier_state' => $normalized['future_carrier_state'],
            'description2_preview' => $normalized['description2_preview'],
            'description2_version' => self::DESCRIPTION2_VERSION,
            'context_hash' => $normalized['context_hash'],
            'idempotency_key' => $idempotencyKey,
            'revision' => $revision,
            'created_by' => $actor?->getAuthIdentifier(),
            'metadata' => json_encode([
                'schema_version' => 1,
                'request_code' => $normalized['request_code'],
                'root_mrn' => $normalized['root_mrn'],
                'stock_snapshot' => $part === [] ? null : [
                    'on_hand' => $part['on_hand'] ?? null,
                    'reserved' => $part['reserved'] ?? null,
                    'available' => $part['available'] ?? null,
                    'source_label' => $part['stock_source_label'] ?? null,
                ],
                'external_execution' => [
                    'mikro_write_count' => 0,
                    'carrier_count' => 0,
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function writePaymentSnapshot(TechnicalServiceMountPayment $payment, object $context): void
    {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $payload['order_context'] = $this->rowProjection($context);
        $payload['order_context_id'] = (int) $context->id;
        $payload['order_context_hash'] = (string) $context->context_hash;
        $payment->forceFill(['raw_payload' => $payload])->save();
    }

    /** @return array<string, mixed> */
    private function rowProjection(object $row): array
    {
        $metadata = is_string($row->metadata ?? null)
            ? json_decode((string) $row->metadata, true)
            : (is_array($row->metadata ?? null) ? $row->metadata : []);
        $stockSnapshot = is_array($metadata['stock_snapshot'] ?? null) ? $metadata['stock_snapshot'] : [];
        $billing = [
            'source' => (string) $row->billing_source,
            'customer_code' => $row->billing_customer_code,
            'name_or_title' => (string) $row->billing_name_or_title,
            'phone' => (string) $row->billing_phone,
            'email' => $row->billing_email,
            'tax_identity' => $row->billing_tax_identity,
            'tax_office' => $row->billing_tax_office,
            'address' => (string) $row->billing_address,
            'city' => (string) $row->billing_city,
            'district' => (string) $row->billing_district,
            'postal_code' => $row->billing_postal_code,
        ];
        $shipping = filled($row->shipping_recipient_name)
            ? [
                'recipient_name' => $row->shipping_recipient_name,
                'recipient_phone' => $row->shipping_recipient_phone,
                'address' => $row->shipping_address,
                'city' => $row->shipping_city,
                'district' => $row->shipping_district,
                'postal_code' => $row->shipping_postal_code,
            ]
            : null;
        $part = filled($row->item_name_snapshot)
            ? [
                'item_code' => $row->item_code,
                'item_name' => $row->item_name_snapshot,
                'quantity' => (float) $row->quantity,
                'unit_code' => $row->unit_code,
                'warehouse_code' => $row->warehouse_code,
                'stock_source' => $row->stock_source,
                'stock_source_label' => $stockSnapshot['source_label'] ?? null,
                'stock_freshness_at' => $row->stock_freshness_at,
                'on_hand' => $stockSnapshot['on_hand'] ?? null,
                'reserved' => $stockSnapshot['reserved'] ?? null,
                'available' => $stockSnapshot['available'] ?? null,
                'serial_tracking_required' => (bool) $row->part_serial_tracking_required,
                'selected_part_serial' => $row->selected_part_serial,
            ]
            : null;

        return [
            'id' => (int) $row->id,
            'payment_id' => is_numeric($row->technical_service_mount_payment_id) ? (int) $row->technical_service_mount_payment_id : null,
            'request_id' => (int) $row->technical_service_request_id,
            'root_request_id' => (int) $row->root_request_id,
            'srv_request_id' => is_numeric($row->srv_request_id) ? (int) $row->srv_request_id : null,
            'part_request_id' => is_numeric($row->technical_service_part_request_id) ? (int) $row->technical_service_part_request_id : null,
            'payment_purpose' => (string) $row->payment_purpose,
            'purpose_label' => (string) $row->payment_purpose === self::PURPOSE_MOUNT_COLLECTION ? 'Montaj ücreti tahsilatı' : 'Parça ödemesi',
            'context_type' => (string) $row->context_type,
            'state' => (string) $row->state,
            'state_label' => $this->stateLabel((string) $row->state, (string) $row->context_type),
            'desired_mikro_series' => $row->desired_mikro_series,
            'future_mikro_write_state' => (string) $row->future_mikro_write_state,
            'future_mikro_write_label' => (string) $row->future_mikro_write_state === 'not_required'
                ? 'Mikro siparişi gerekmiyor'
                : 'Mikro yazımı bu aşamada kapalı',
            'billing' => $billing,
            'shipping_same_as_billing' => (bool) $row->shipping_same_as_billing,
            'delivery_target' => $row->delivery_target,
            'delivery_target_label' => $this->deliveryTargetLabel($row->delivery_target),
            'shipping' => $shipping,
            'part_supplier' => $row->part_supplier,
            'part_supplier_label' => (string) $row->part_supplier === self::SUPPLIER_EMAKS ? 'EMAKS Prime' : ((string) $row->part_supplier === self::SUPPLIER_TECHNICIAN ? 'Usta' : null),
            'collection_allocation' => $row->collection_allocation,
            'collection_allocation_label' => (string) $row->collection_allocation === self::ALLOCATION_RETAIN_COMPANY
                ? 'Şirkette bırakılacak'
                : ((string) $row->collection_allocation === self::ALLOCATION_PAY_TECHNICIAN ? 'Ustaya hakediş olarak eklenecek' : null),
            'part' => $part,
            'related_product_serial' => $row->related_product_serial,
            'charged_amount' => (float) $row->charged_amount,
            'charged_amount_label' => $this->moneyLabel((float) $row->charged_amount, (string) $row->currency),
            'currency' => (string) $row->currency,
            'shipment_required' => (bool) $row->shipment_required,
            'future_carrier_state' => (string) $row->future_carrier_state,
            'future_carrier_label' => (bool) $row->shipment_required
                ? 'Kargo hazırlığı bekliyor; HepsiJet entegrasyonu çalıştırılmayacak'
                : 'Sevkiyat yok',
            'description2_preview' => (string) $row->description2_preview,
            'description2_version' => (int) $row->description2_version,
            'context_hash' => (string) $row->context_hash,
            'revision' => (int) $row->revision,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
            'mikro_write_execution_count' => 0,
            'carrier_execution_count' => 0,
        ];
    }

    /** @param array<string, mixed> $context */
    private function renderDescription2(TechnicalServiceRequest $request, array $context): string
    {
        $requestCode = (string) $context['request_code'];
        $billing = $context['billing'];
        if ($context['context_type'] === 'mount_service') {
            return implode("\n", [
                'MRN/SRV: '.$requestCode,
                'HİZMET: MONTAJ',
                'ÜRÜN: '.trim((string) ($request->product_model ?: $request->product_name)),
                'SERİ NO: '.((string) ($context['related_product_serial'] ?? '-')),
                'FATURA MÜŞTERİSİ: '.$billing['name_or_title'],
                'SEVKİYAT: YOK',
            ]);
        }

        $part = $context['part'];
        $shipping = $context['shipping'];
        $itemIdentity = trim(implode(' - ', array_filter([
            $part['item_code'] ?? null,
            $part['item_name'] ?? null,
        ], fn (mixed $value): bool => filled($value))));
        $lines = [];
        if ($context['shipment_required'] && ! $context['shipping_same_as_billing']) {
            $lines[] = 'SEVK ADRESİ FARKLIDIR.';
        }
        $lines[] = 'MRN/SRV: '.$requestCode;
        $lines[] = 'İLGİLİ ÜRÜN SERİ NO: '.$context['related_product_serial'];
        $lines[] = 'PARÇA: '.$itemIdentity;
        $lines[] = 'ADET: '.$this->quantityLabel((float) ($part['quantity'] ?? 0));
        if ($context['shipment_required']) {
            if ($context['shipping_same_as_billing']) {
                $lines[] = 'SEVK/FATURA: AYNI';
            } else {
                $lines[] = 'TESLİM TİPİ: '.mb_strtoupper($this->deliveryTargetLabel($context['delivery_target']) ?? '-', 'UTF-8');
            }
            $lines[] = 'ALICI: '.$shipping['recipient_name'];
            $lines[] = 'TELEFON: '.$shipping['recipient_phone'];
            $lines[] = 'ADRES: '.$shipping['address'].' / '.$shipping['district'].' / '.$shipping['city'];
            $lines[] = 'FATURA MÜŞTERİSİ: '.$billing['name_or_title'];
            if (filled($billing['customer_code'] ?? null)) {
                $lines[] = 'FATURA CARİ KODU: '.$billing['customer_code'];
            }
        } else {
            $lines[] = 'TEDARİK: USTA';
            $lines[] = 'SEVKİYAT: YOK';
            $lines[] = 'FATURA MÜŞTERİSİ: '.$billing['name_or_title'];
        }

        return implode("\n", $lines);
    }

    /** @return array{source:string,source_label:string,freshness_at:string,items:array<int, array<string, mixed>>} */
    private function localPartFixtures(TechnicalServiceRequest $request, string $query): array
    {
        $freshness = now()->toISOString();
        $fixtures = [
            [
                'item_code' => 'TS-PART-001',
                'item_name' => 'Gateway',
                'unit_code' => 'ADET',
                'warehouse_code' => 'MERKEZ',
                'on_hand' => 24.0,
                'reserved' => 3.0,
                'available' => 21.0,
                'serial_tracking_required' => false,
                'serials' => [],
                'source' => 'test_fixture',
                'source_label' => 'Test verisi',
                'freshness_at' => $freshness,
            ],
            [
                'item_code' => 'TS-PART-002',
                'item_name' => 'Akıllı Kilit Motor Modülü',
                'unit_code' => 'ADET',
                'warehouse_code' => 'MERKEZ',
                'on_hand' => 6.0,
                'reserved' => 1.0,
                'available' => 5.0,
                'serial_tracking_required' => true,
                'serials' => ['TSP-2026-0001', 'TSP-2026-0002', 'TSP-2026-0003'],
                'source' => 'test_fixture',
                'source_label' => 'Test verisi',
                'freshness_at' => $freshness,
            ],
        ];
        $needle = Str::lower(Str::ascii($query));
        $items = collect($fixtures)
            ->filter(function (array $item) use ($needle): bool {
                $haystack = Str::lower(Str::ascii($item['item_code'].' '.$item['item_name']));

                return str_contains($haystack, $needle);
            })
            ->take(20)
            ->map(fn (array $item): array => $this->partSearchItem($request, $item))
            ->values()
            ->all();

        return [
            'source' => 'test_fixture',
            'source_label' => 'Test verisi',
            'freshness_at' => $freshness,
            'items' => $items,
        ];
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private function partSearchItem(TechnicalServiceRequest $request, array $item): array
    {
        $tokenPayload = [
            'schema_version' => 1,
            'request_id' => (int) $request->id,
            ...$item,
        ];

        return [
            ...$item,
            'selection_token' => Crypt::encryptString(json_encode($tokenPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];
    }

    private function activeTechnician(TechnicalServiceRequest $request): TechnicalServiceTechnician
    {
        $request->loadMissing('technicianRecord');
        $technician = $request->technicianRecord;
        if (! $technician instanceof TechnicalServiceTechnician
            || (int) $request->technical_service_technician_id !== (int) $technician->id) {
            throw ValidationException::withMessages([
                'order_context.delivery_target' => 'Aktif usta belirlenemedi. Usta tedarikli parça veya usta sevki için önce atamayı tamamlayın.',
            ]);
        }

        return $technician;
    }

    /** @return array<string, mixed> */
    private function recipientFromTechnician(TechnicalServiceTechnician $technician): array
    {
        $address = trim((string) ($technician->address ?: $technician->google_formatted_address ?: $technician->default_start_address));
        $snapshot = [
            'recipient_name' => trim((string) $technician->name),
            'recipient_phone' => trim((string) ($technician->phone_e164 ?: $technician->phone)),
            'address' => $address,
            'city' => trim((string) $technician->city),
            'district' => trim((string) $technician->district),
            'postal_code' => null,
        ];
        if (collect(['recipient_name', 'recipient_phone', 'address', 'city', 'district'])->contains(fn (string $field): bool => trim((string) $snapshot[$field]) === '')) {
            throw ValidationException::withMessages([
                'order_context.delivery_target' => 'Ustanın sevk bilgileri eksik. Kargo hazırlığından önce usta bilgilerini tamamlayın.',
            ]);
        }

        return $snapshot;
    }

    /** @param array<string, mixed> $billing @return array<string, mixed> */
    private function recipientFromBilling(array $billing): array
    {
        return [
            'recipient_name' => $billing['name_or_title'],
            'recipient_phone' => $billing['phone'],
            'address' => $billing['address'],
            'city' => $billing['city'],
            'district' => $billing['district'],
            'postal_code' => $billing['postal_code'],
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function customRecipient(array $input): array
    {
        $shipping = is_array($input['shipping'] ?? null) ? $input['shipping'] : [];

        return [
            'recipient_name' => trim((string) ($shipping['recipient_name'] ?? '')),
            'recipient_phone' => trim((string) ($shipping['recipient_phone'] ?? '')),
            'address' => trim((string) ($shipping['address'] ?? '')),
            'city' => trim((string) ($shipping['city'] ?? '')),
            'district' => trim((string) ($shipping['district'] ?? '')),
            'postal_code' => $this->nullableText($shipping['postal_code'] ?? null),
        ];
    }

    /** @param array<string, mixed> $snapshot @param array<int, string> $fields */
    private function requireSnapshot(array $snapshot, array $fields, string $label): void
    {
        $missing = collect($fields)->first(fn (string $field): bool => trim((string) ($snapshot[$field] ?? '')) === '');
        if ($missing !== null) {
            throw ValidationException::withMessages([
                'order_context' => $label.' eksik. Ad, telefon, adres, şehir ve ilçe bilgilerini tamamlayın.',
            ]);
        }
    }

    private function requestAddress(TechnicalServiceRequest $request): string
    {
        return trim((string) ($request->location_formatted_address ?: $request->service_address));
    }

    /** @param array<string, mixed> $input */
    private function partRequestId(TechnicalServiceRequest $request, array $input): ?int
    {
        if (! is_numeric($input['part_request_id'] ?? null)) {
            return null;
        }

        $partRequestId = (int) $input['part_request_id'];
        $partRequest = TechnicalServicePartRequest::query()->find($partRequestId);
        if (! $partRequest instanceof TechnicalServicePartRequest) {
            throw ValidationException::withMessages([
                'order_context.part_request_id' => 'Bağlı parça talebi bulunamadı.',
            ]);
        }

        $rootRequestId = (int) ($request->parent_request_id ?: $request->id);
        $ownerRequestId = (int) $partRequest->technical_service_request_id;
        $belongsToLifecycle = $ownerRequestId === (int) $request->id
            || $ownerRequestId === $rootRequestId
            || TechnicalServiceRequest::query()
                ->whereKey($ownerRequestId)
                ->where('parent_request_id', $rootRequestId)
                ->exists();
        if (! $belongsToLifecycle) {
            throw ValidationException::withMessages([
                'order_context.part_request_id' => 'Bağlı parça talebi bu MRN/SRV yaşam döngüsüne ait değildir.',
            ]);
        }

        return $partRequestId;
    }

    private function nullableText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    /** @param array<string, mixed>|null $part @return array<string, mixed>|null */
    private function partIdentity(?array $part): ?array
    {
        if ($part === null) {
            return null;
        }

        return [
            'item_code' => $part['item_code'] ?? null,
            'item_name' => $part['item_name'] ?? null,
            'quantity' => $part['quantity'] ?? null,
            'unit_code' => $part['unit_code'] ?? null,
            'warehouse_code' => $part['warehouse_code'] ?? null,
            'stock_source' => $part['stock_source'] ?? null,
            'serial_tracking_required' => (bool) ($part['serial_tracking_required'] ?? false),
            'selected_part_serial' => $part['selected_part_serial'] ?? null,
        ];
    }

    private function deliveryTargetLabel(mixed $target): ?string
    {
        return match ((string) $target) {
            'billing_address' => 'Fatura adresi',
            'mrn_customer' => 'MRN müşterisi',
            'technician' => 'Usta',
            'custom_recipient' => 'Farklı alıcı',
            default => null,
        };
    }

    private function stateLabel(string $state, string $contextType): string
    {
        return match ($state) {
            'draft' => 'Sipariş hazırlığı taslak',
            'payment_link_ready', 'payment_pending' => 'Ödeme bekleniyor',
            'paid_waiting_mikro_write' => $contextType === 'technician_supplied_part'
                ? 'Ödeme alındı; Mikro siparişi gerekmiyor'
                : 'Ödeme alındı; Mikro yazımı bekliyor',
            'cancelled' => 'İptal edildi',
            default => 'Sipariş hazırlığı bekliyor',
        };
    }

    private function quantityLabel(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 3, ',', '.'), '0'), ',');
    }

    private function moneyLabel(float $amount, string $currency): string
    {
        return number_format($amount, 2, ',', '.').' '.($currency === 'TRY' ? 'TL' : $currency);
    }
}
