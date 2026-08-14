<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServicePartRequest;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use App\Services\Mikro\MikroApiClient;
use App\Support\TechnicalServiceTurkeyLocations;
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

    public const COMMERCIAL_FREE = 'free';

    public const COMMERCIAL_PAID = 'paid';

    public const DELIVERY_HAND = 'hand_delivery';

    public const DELIVERY_SHIPMENT = 'shipment';

    public const PAYMENT_NOT_REQUIRED = 'not_required';

    public const PAYMENT_PENDING = 'pending';

    public const PAYMENT_PAID = 'paid';

    public const PAYMENT_CANCELLED = 'cancelled';

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

        if (app()->environment('testing') && (bool) config('services.technical_service.payment_order_context_test_stock', false)) {
            return $this->localPartFixtures($request, $query);
        }

        try {
            $result = $this->mikro->listStocks($query, size: 20);
            if (($result['success'] ?? $result['ok'] ?? false) !== true) {
                throw new DomainException((string) ($result['error_code'] ?? 'MIKRO_STOCK_READ_UNAVAILABLE'));
            }
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'query' => 'Mikro stok bağlantısı hazır değil. Gerçek stok doğrulanmadan parça seçilemez.',
            ]);
        }

        try {
            $freshnessAt = filled($result['freshness_at'] ?? null)
                ? (string) $result['freshness_at']
                : now()->toISOString();
            $sourceLabel = (bool) ($result['stale'] ?? false)
                ? 'Mikro API (güncel olmayan doğrulanmış kayıt)'
                : 'Mikro API';
            $rows = collect($result['data'] ?? $result['rows'] ?? $result['result'] ?? [])
                ->filter(fn (mixed $row): bool => is_array($row))
                ->take(20)
                ->map(function (array $row) use ($request, $freshnessAt, $sourceLabel): array {
                    $item = [
                        'item_code' => trim((string) ($row['item_code'] ?? '')),
                        'item_name' => trim((string) ($row['item_name'] ?? '')),
                        'unit_code' => filled($row['unit_code'] ?? null) ? trim((string) $row['unit_code']) : null,
                        'warehouse_code' => null,
                        'on_hand' => null,
                        'reserved' => null,
                        'available' => null,
                        'availability_verified' => false,
                        'serial_tracking_required' => false,
                        'serials' => [],
                        'source' => 'mikro',
                        'source_label' => $sourceLabel,
                        'freshness_at' => $freshnessAt,
                    ];

                    if ($item['item_code'] === '' || $item['item_name'] === '') {
                        throw new DomainException('MIKRO_STOCK_SELECTION_SCHEMA_INCOMPLETE');
                    }

                    return $this->partSearchItem($request, $item);
                })
                ->values();
        } catch (DomainException $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'query' => 'Mikro stok bağlantısı hazır değil. Gerçek stok doğrulanmadan parça seçilemez.',
            ]);
        }

        if ($rows->isEmpty()) {
            return [
                'source' => 'mikro',
                'source_label' => $sourceLabel,
                'freshness_at' => $freshnessAt,
                'items' => [],
            ];
        }

        return [
            'source' => 'mikro',
            'source_label' => $sourceLabel,
            'freshness_at' => $freshnessAt,
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
        ?string $providerFamily = null,
        ?string $providerMode = null,
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
            'payment_retry' => $this->paymentRetryProjection(
                $request,
                $purpose,
                $normalized,
                $providerFamily,
                $providerMode,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function paymentRetryPlan(
        TechnicalServiceRequest $request,
        string $purpose,
        array $input,
        float $amount,
        string $currency,
        ?string $providerFamily,
        ?string $providerMode,
    ): array {
        $preview = $this->preview(
            $request,
            $purpose,
            $input,
            $amount,
            $currency,
            $providerFamily,
            $providerMode,
        );
        $expectedHash = trim((string) ($input['expected_context_hash'] ?? ''));
        $expectedRevision = (int) ($input['expected_revision'] ?? 0);
        $idempotentExistingPayment = in_array((string) data_get($preview, 'payment_retry.state'), [
            'reuse_pending',
            'already_paid',
        ], true)
            && $expectedRevision > 0
            && $expectedRevision <= (int) $preview['revision'];
        if ($expectedHash === ''
            || ! hash_equals((string) $preview['context_hash'], $expectedHash)
            || ($expectedRevision !== (int) $preview['revision'] && ! $idempotentExistingPayment)) {
            throw ValidationException::withMessages([
                'order_context' => 'Sipariş hazırlığı değişti. Güncel önizlemeyi kontrol edip tekrar deneyin.',
            ]);
        }

        return $preview['payment_retry'];
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array<string, mixed>
     */
    private function paymentRetryProjection(
        TechnicalServiceRequest $request,
        string $purpose,
        array $normalized,
        ?string $providerFamily,
        ?string $providerMode,
    ): array {
        $contexts = DB::table(self::TABLE)
            ->where('technical_service_request_id', $request->id)
            ->where('payment_purpose', $purpose)
            ->orderByDesc('revision')
            ->orderByDesc('id')
            ->get();
        $contextsById = $contexts->keyBy(fn (object $context): string => (string) $context->id);
        $contextsByPaymentId = $contexts
            ->filter(fn (object $context): bool => is_numeric($context->technical_service_mount_payment_id))
            ->keyBy(fn (object $context): string => (string) $context->technical_service_mount_payment_id);
        $payments = TechnicalServiceMountPayment::query()
            ->where('technical_service_request_id', $request->id)
            ->orderByDesc('id')
            ->get();
        $candidates = $payments->map(function (TechnicalServiceMountPayment $payment) use (
            $contextsById,
            $contextsByPaymentId,
            $purpose,
        ): ?array {
            $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
            $snapshot = is_array($payload['order_context'] ?? null) ? $payload['order_context'] : [];
            $contextId = is_numeric($snapshot['id'] ?? null)
                ? (int) $snapshot['id']
                : (is_numeric($payload['order_context_id'] ?? null) ? (int) $payload['order_context_id'] : null);
            $context = $contextId !== null ? $contextsById->get((string) $contextId) : null;
            $context ??= $contextsByPaymentId->get((string) $payment->id);
            $candidatePurpose = trim((string) ($snapshot['payment_purpose'] ?? $payload['purpose'] ?? $context?->payment_purpose ?? ''));
            if ($candidatePurpose !== $purpose) {
                return null;
            }

            return [
                'payment' => $payment,
                'payment_id' => (int) $payment->id,
                'status' => (string) $payment->status,
                'context_hash' => trim((string) ($snapshot['context_hash'] ?? $payload['order_context_hash'] ?? $context?->context_hash ?? '')),
            ];
        })->filter()->values();
        $counts = [
            'paid' => $candidates->where('status', TechnicalServiceMountPayment::STATUS_PAID)->count(),
            'pending' => $candidates->where('status', TechnicalServiceMountPayment::STATUS_PENDING)->count(),
            'cancelled' => $candidates->where('status', TechnicalServiceMountPayment::STATUS_CANCELLED)->count(),
            'failed' => $candidates->where('status', TechnicalServiceMountPayment::STATUS_FAILED)->count(),
            'expired' => $candidates->where('status', TechnicalServiceMountPayment::STATUS_EXPIRED)->count(),
        ];
        $base = [
            'state' => 'none',
            'fresh_link_required' => false,
            'reason_required' => false,
            'action_label' => null,
            'message' => null,
            'audit_reason' => null,
            'supersede_payment_id' => null,
            'authoritative_counts' => $counts,
        ];
        $exact = $candidates
            ->filter(fn (array $candidate): bool => $candidate['context_hash'] !== ''
                && hash_equals((string) $normalized['context_hash'], $candidate['context_hash']));
        $paid = $exact->firstWhere('status', TechnicalServiceMountPayment::STATUS_PAID);
        if (is_array($paid)) {
            return [...$base, 'state' => 'already_paid'];
        }

        $pending = $exact->firstWhere('status', TechnicalServiceMountPayment::STATUS_PENDING);
        if (is_array($pending)) {
            /** @var TechnicalServiceMountPayment $payment */
            $payment = $pending['payment'];
            if ($this->pendingPaymentMatchesCurrentAuthority($payment, $providerFamily, $providerMode)) {
                return [...$base, 'state' => 'reuse_pending'];
            }

            return [
                ...$base,
                'state' => 'fresh_link_required',
                'fresh_link_required' => true,
                'reason_required' => true,
                'action_label' => 'Yeni bağlantı oluştur',
                'message' => 'Eski ödeme bağlantısı tekrar kullanılamaz. Bu işlem için yeni bağlantı oluşturulacaktır.',
                'audit_reason' => 'Önceki bekleyen ödeme bağlantısının provider veya session kimliği güncel authority ile eşleşmedi.',
                'supersede_payment_id' => $pending['payment_id'],
            ];
        }

        $terminal = $exact->first(fn (array $candidate): bool => in_array($candidate['status'], [
            TechnicalServiceMountPayment::STATUS_CANCELLED,
            TechnicalServiceMountPayment::STATUS_FAILED,
            TechnicalServiceMountPayment::STATUS_EXPIRED,
        ], true));
        if (is_array($terminal)) {
            return [
                ...$base,
                'state' => 'fresh_link_required',
                'fresh_link_required' => true,
                'reason_required' => true,
                'action_label' => 'Yeni bağlantı oluştur',
                'message' => 'Eski ödeme bağlantısı tekrar kullanılamaz. Bu işlem için yeni bağlantı oluşturulacaktır.',
                'audit_reason' => 'Terminal ödeme geçmişi korunarak açık kullanıcı kararıyla yeni bağlantı oluşturuldu.',
            ];
        }

        $changedPending = $candidates->first(fn (array $candidate): bool => $candidate['status'] === TechnicalServiceMountPayment::STATUS_PENDING
            && ($candidate['context_hash'] === '' || ! hash_equals((string) $normalized['context_hash'], $candidate['context_hash'])));
        if (is_array($changedPending)) {
            return [
                ...$base,
                'state' => 'fresh_link_required',
                'fresh_link_required' => true,
                'reason_required' => false,
                'action_label' => 'Yeni bağlantı oluştur',
                'message' => 'Fatura, sevk, parça veya tutar değişti. Eski ödeme bağlantısı sonlandırılıp bu işlem için yeni bağlantı oluşturulacaktır.',
                'audit_reason' => 'Fatura, sevk, parça veya tutar değiştiği için önceki bekleyen ödeme bağlantısı sonlandırıldı.',
                'supersede_payment_id' => $changedPending['payment_id'],
            ];
        }

        return $base;
    }

    private function pendingPaymentMatchesCurrentAuthority(
        TechnicalServiceMountPayment $payment,
        ?string $providerFamily,
        ?string $providerMode,
    ): bool {
        if (trim((string) $payment->provider_reference) === '' || trim((string) $payment->payment_url) === '') {
            return false;
        }

        $expectedFamily = $this->canonicalProviderFamily($providerFamily);
        if ($expectedFamily !== null && $this->canonicalProviderFamily((string) $payment->provider) !== $expectedFamily) {
            return false;
        }
        $expectedMode = strtolower(trim((string) $providerMode));
        if ($expectedMode === '') {
            return true;
        }
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $storedMode = strtolower(trim((string) ($payload['provider_mode'] ?? $payload['provider_environment'] ?? '')));

        return $storedMode !== '' && hash_equals($expectedMode, $storedMode);
    }

    private function canonicalProviderFamily(?string $provider): ?string
    {
        return match (strtolower(trim((string) $provider))) {
            'fake', 'fake_payment' => 'fake',
            'iyzico', 'iyzico_sandbox', 'iyzico_live' => 'iyzico',
            default => null,
        };
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
        ?string $retryReason = null,
        ?string $providerFamily = null,
        ?string $providerMode = null,
    ): array {
        $normalized = $this->normalize($request, $purpose, $input, $amount, $currency);
        $expectedHash = trim((string) ($input['expected_context_hash'] ?? ''));
        $expectedRevision = (int) ($input['expected_revision'] ?? 0);
        if ($expectedHash === '' || ! hash_equals($normalized['context_hash'], $expectedHash)) {
            throw ValidationException::withMessages([
                'order_context' => 'Sipariş hazırlığı değişti. Güncel önizlemeyi kontrol edip tekrar deneyin.',
            ]);
        }

        $retry = $this->paymentRetryProjection(
            $request,
            $purpose,
            $normalized,
            $providerFamily,
            $providerMode,
        );
        $resolvedRetryReason = trim((string) $retryReason);
        if (($retry['state'] ?? 'none') === 'fresh_link_required') {
            if (! $allowTerminalRetry) {
                throw ValidationException::withMessages([
                    'order_context' => (string) $retry['message'],
                ]);
            }
            if (is_numeric($retry['supersede_payment_id'] ?? null)) {
                throw ValidationException::withMessages([
                    'order_context' => 'Eski bekleyen ödeme bağlantısı sonlandırılmadan yeni bağlantı oluşturulamaz.',
                ]);
            }
            if (($retry['reason_required'] ?? false) === true && mb_strlen($resolvedRetryReason) < 3) {
                throw ValidationException::withMessages([
                    'terminal_retry_reason' => 'Yeni bağlantı oluşturma nedenini yazınız.',
                ]);
            }
            if ($resolvedRetryReason === '') {
                $resolvedRetryReason = (string) ($retry['audit_reason'] ?? 'Ödeme bağlamı değiştiği için yeni bağlantı oluşturuldu.');
            }
        }

        $result = DB::transaction(function () use (
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
                    if ($expectedRevision <= 0 || $expectedRevision > (int) $exact->revision) {
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

            $pendingPaymentIds = TechnicalServiceMountPayment::query()
                ->whereIn('id', $rows
                    ->pluck('technical_service_mount_payment_id')
                    ->filter(fn (mixed $paymentId): bool => is_numeric($paymentId))
                    ->map(fn (mixed $paymentId): int => (int) $paymentId)
                    ->all())
                ->where('status', TechnicalServiceMountPayment::STATUS_PENDING)
                ->pluck('id')
                ->map(fn (mixed $paymentId): int => (int) $paymentId)
                ->all();
            $pendingDifferentContext = $rows->first(fn (object $row): bool => is_numeric($row->technical_service_mount_payment_id)
                && in_array((int) $row->technical_service_mount_payment_id, $pendingPaymentIds, true)
                && ! hash_equals((string) $row->context_hash, $normalized['context_hash']));
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
            $expectedRevisionAuthority = $exact ? $latestRevision : $revision;
            if (($exact && (int) $exact->revision !== $latestRevision)
                || $expectedRevision !== $expectedRevisionAuthority) {
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

        return [...$result, 'retry_reason' => $resolvedRetryReason !== '' ? $resolvedRetryReason : null];
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
                'payment_status' => self::PAYMENT_PENDING,
                'payment_status_source' => 'provider',
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
                'payment_status' => self::PAYMENT_CANCELLED,
                'payment_status_source' => 'provider',
                'payment_status_changed_at' => now(),
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
            'payment_status' => self::PAYMENT_PAID,
            'payment_status_source' => 'provider',
            'payment_status_changed_at' => now(),
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

    /** @return array<string, mixed>|null */
    public function latestPartContext(TechnicalServiceRequest $request): ?array
    {
        $context = DB::table(self::TABLE)
            ->where('technical_service_request_id', $request->id)
            ->where('payment_purpose', self::PURPOSE_PART_CHARGE)
            ->orderByDesc('revision')
            ->orderByDesc('id')
            ->first();

        return $context ? $this->rowProjection($context) : null;
    }

    /** @return array<string, mixed> */
    public function finalizeWithoutPayment(object $context, ?Authenticatable $actor): array
    {
        return DB::transaction(function () use ($context, $actor): array {
            $locked = DB::table(self::TABLE)->where('id', (int) $context->id)->lockForUpdate()->first();
            if (! $locked) {
                throw ValidationException::withMessages(['order_context' => 'Sipariş hazırlığı bulunamadı.']);
            }
            if ((bool) $locked->payment_link_required) {
                throw ValidationException::withMessages(['order_context' => 'Bu işlem ödeme bağlantısı gerektiriyor.']);
            }

            $state = (string) $locked->payment_status === self::PAYMENT_NOT_REQUIRED
                ? 'ready_without_collection'
                : ((string) $locked->payment_status === self::PAYMENT_PAID ? 'manual_collection_paid' : 'manual_collection_pending');
            if ((string) $locked->state !== $state) {
                DB::table(self::TABLE)->where('id', $locked->id)->update([
                    'state' => $state,
                    'updated_at' => now(),
                ]);
                $request = TechnicalServiceRequest::query()->whereKey($locked->technical_service_request_id)->firstOrFail();
                $request->events()->create([
                    'event_type' => 'payment_order_context_saved_without_provider',
                    'title' => (string) $locked->payment_status === self::PAYMENT_NOT_REQUIRED
                        ? 'Tahsilatsız parça hazırlığı kaydedildi'
                        : 'Elden teslim tahsilat durumu kaydedildi',
                    'note' => 'Ödeme sağlayıcısı, Mikro write ve kargo çağrısı yapılmadı.',
                    'from_status' => $request->workflow_status,
                    'to_status' => $request->workflow_status,
                    'author_user_id' => $actor?->getAuthIdentifier(),
                    'metadata' => [
                        'order_context_id' => (int) $locked->id,
                        'payment_status' => $locked->payment_status,
                        'payment_link_required' => false,
                        'provider_execution_count' => 0,
                    ],
                ]);
            }

            return $this->rowProjection(DB::table(self::TABLE)->where('id', $locked->id)->firstOrFail());
        });
    }

    /** @return array<string, mixed> */
    public function updateHandDeliveryState(
        TechnicalServiceRequest $request,
        int $contextId,
        int $expectedRevision,
        string $action,
        ?string $paymentStatus,
        ?string $reason,
        ?Authenticatable $actor,
    ): array {
        return DB::transaction(function () use ($request, $contextId, $expectedRevision, $action, $paymentStatus, $reason, $actor): array {
            $context = DB::table(self::TABLE)
                ->where('id', $contextId)
                ->where('technical_service_request_id', $request->id)
                ->lockForUpdate()
                ->first();
            if (! $context) {
                throw ValidationException::withMessages(['order_context' => 'Parça hazırlığı bulunamadı.']);
            }
            if ((int) $context->revision !== $expectedRevision) {
                throw ValidationException::withMessages(['expected_revision' => 'Parça hazırlığı güncellendi. Güncel durumu açıp tekrar deneyin.']);
            }
            if ((string) $context->delivery_mode !== self::DELIVERY_HAND || (bool) $context->payment_link_required) {
                throw ValidationException::withMessages(['order_context' => 'Yalnız linksiz elden teslim durumu bu alandan güncellenebilir.']);
            }

            $beforeDelivery = (string) $context->delivery_status;
            $beforePayment = (string) $context->payment_status;
            $nextDelivery = $beforeDelivery;
            $nextPayment = $beforePayment;
            $nextSource = (string) $context->payment_status_source;
            $normalizedReason = trim((string) $reason);

            if ($action === 'record_delivery') {
                $nextDelivery = 'delivered';
                if ((string) $context->commercial_mode === self::COMMERCIAL_PAID
                    && (string) $context->delivery_target === 'technician') {
                    $nextPayment = self::PAYMENT_PAID;
                    $nextSource = 'auto_from_technician_delivery';
                }
            } elseif ($action === 'set_payment_status') {
                if ((string) $context->commercial_mode !== self::COMMERCIAL_PAID
                    || ! in_array($paymentStatus, [self::PAYMENT_PENDING, self::PAYMENT_PAID, self::PAYMENT_CANCELLED], true)) {
                    throw ValidationException::withMessages(['payment_status' => 'Geçerli ödeme durumunu seçin.']);
                }
                if ($normalizedReason === '') {
                    throw ValidationException::withMessages(['reason' => 'Ödeme durumu değişikliği için açıklama yazınız.']);
                }
                $nextPayment = (string) $paymentStatus;
                $nextSource = 'manual';
            } else {
                throw ValidationException::withMessages(['action' => 'Geçerli teslim veya ödeme durumu aksiyonunu seçin.']);
            }

            if ($beforeDelivery === $nextDelivery && $beforePayment === $nextPayment) {
                return $this->rowProjection($context);
            }

            $financeReview = (bool) $context->finance_review_required
                || ($beforeDelivery === 'delivered' && $beforePayment === self::PAYMENT_PAID && $nextPayment === self::PAYMENT_CANCELLED);
            DB::table(self::TABLE)->where('id', $context->id)->update([
                'delivery_status' => $nextDelivery,
                'payment_status' => $nextPayment,
                'payment_status_source' => $nextSource,
                'payment_status_changed_by' => $actor?->getAuthIdentifier(),
                'payment_status_changed_at' => now(),
                'payment_status_reason' => $normalizedReason !== '' ? $normalizedReason : null,
                'finance_review_required' => $financeReview,
                'state' => $nextPayment === self::PAYMENT_PAID ? 'manual_collection_paid' : ($nextPayment === self::PAYMENT_CANCELLED ? 'cancelled' : 'manual_collection_pending'),
                'revision' => $expectedRevision + 1,
                'updated_at' => now(),
            ]);

            $request->events()->create([
                'event_type' => $action === 'record_delivery' ? 'part_hand_delivery_recorded' : 'part_hand_payment_status_changed',
                'title' => $action === 'record_delivery' ? 'Parça elden teslim edildi' : 'Elden teslim ödeme durumu güncellendi',
                'note' => $normalizedReason !== '' ? $normalizedReason : 'Teslim kaydı üzerinden otomatik ödeme durumu uygulandı.',
                'from_status' => $request->workflow_status,
                'to_status' => $request->workflow_status,
                'author_user_id' => $actor?->getAuthIdentifier(),
                'metadata' => [
                    'order_context_id' => (int) $context->id,
                    'before' => ['delivery_status' => $beforeDelivery, 'payment_status' => $beforePayment],
                    'after' => ['delivery_status' => $nextDelivery, 'payment_status' => $nextPayment],
                    'payment_status_source' => $nextSource,
                    'finance_review_required' => $financeReview,
                    'correlation_id' => $context->correlation_id,
                    'payment_write_count' => 0,
                    'earning_write_count' => 0,
                ],
            ]);

            return $this->rowProjection(DB::table(self::TABLE)->where('id', $context->id)->firstOrFail());
        });
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
        $commercialMode = self::COMMERCIAL_PAID;
        $deliveryMode = null;
        $deliveryStatus = 'pending';
        $shippingSameAsBilling = false;
        $deliveryTarget = null;
        $shipping = null;
        $quantity = 1.0;

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
                $commercialMode = trim((string) ($input['commercial_mode'] ?? ''));
                $deliveryMode = trim((string) ($input['delivery_mode'] ?? ''));
                if (! in_array($commercialMode, [self::COMMERCIAL_FREE, self::COMMERCIAL_PAID], true)) {
                    throw ValidationException::withMessages([
                        'order_context.commercial_mode' => 'Parçanın ücretsiz veya ücretli olduğunu seçin.',
                    ]);
                }
                if (! in_array($deliveryMode, [self::DELIVERY_HAND, self::DELIVERY_SHIPMENT], true)) {
                    throw ValidationException::withMessages([
                        'order_context.delivery_mode' => 'Parçanın elden teslim veya sevk edileceğini seçin.',
                    ]);
                }
                $part = $this->selectedStockItem($request, $input);
                $quantity = (float) ($part['quantity'] ?? 1);
                $collectionAllocation = self::ALLOCATION_RETAIN_COMPANY;
                if ($deliveryMode === self::DELIVERY_SHIPMENT) {
                    $shippingSameAsBilling = (bool) ($input['shipping_same_as_billing'] ?? false);
                    [$deliveryTarget, $shipping] = $this->shippingSnapshot($request, $input, $billing, $shippingSameAsBilling);
                } else {
                    $deliveryTarget = trim((string) ($input['delivery_target'] ?? 'technician'));
                    if (! in_array($deliveryTarget, ['technician', 'mrn_customer', 'billing_address'], true)) {
                        throw ValidationException::withMessages([
                            'order_context.delivery_target' => 'Elden teslim alıcısını seçin.',
                        ]);
                    }
                }
            } else {
                $contextType = 'technician_supplied_part';
                $collectionAllocation = self::ALLOCATION_PAY_TECHNICIAN;
                $part = $this->technicianPartSnapshot($input);
                $quantity = (float) ($part['quantity'] ?? 1);
                $this->activeTechnician($request);
            }
        }

        $decision = $this->commercialDecision($purpose, $partSupplier, $commercialMode, $deliveryMode);
        $shipmentRequired = (bool) $decision['shipment_required'];
        $futureCarrierState = $shipmentRequired ? 'waiting_future_integration' : 'not_required';
        $desiredMikroSeries = $decision['desired_mikro_series'];
        $futureMikroWriteState = $decision['future_mikro_write_state'];
        $paymentLinkRequired = (bool) $decision['payment_link_required'];
        $collectionRequired = (bool) $decision['collection_required'];
        $paymentCollectionMode = (string) $decision['payment_collection_mode'];
        $taxMode = (string) $decision['tax_mode'];
        $vatRate = $taxMode === 'none' ? 0.0 : null;
        $futureOrderTrigger = $decision['future_order_trigger'];

        if ($purpose === self::PURPOSE_MOUNT_COLLECTION || $partSupplier === self::SUPPLIER_TECHNICIAN || $collectionRequired) {
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'Tahsilat tutarı 0 TL üzerinde olmalıdır.']);
            }
        } elseif ($amount < 0) {
            throw ValidationException::withMessages(['amount' => 'Tutar negatif olamaz.']);
        }

        if ($commercialMode === self::COMMERCIAL_FREE && $deliveryMode === self::DELIVERY_SHIPMENT && abs($amount) > 0.0001) {
            throw ValidationException::withMessages([
                'amount' => 'Ücretsiz sevkte sipariş ve tahsilat tutarı 0 TL olmalıdır.',
            ]);
        }

        $orderLineTotal = $purpose === self::PURPOSE_PART_CHARGE && $partSupplier === self::SUPPLIER_EMAKS
            ? ($commercialMode === self::COMMERCIAL_FREE && $deliveryMode === self::DELIVERY_SHIPMENT ? 0.0 : round($amount, 2))
            : round($amount, 2);
        $orderLineUnitPrice = $quantity > 0 ? round($orderLineTotal / $quantity, 2) : $orderLineTotal;
        $collectionAmount = $collectionRequired ? round($amount, 2) : 0.0;
        $paymentStatus = $collectionRequired ? self::PAYMENT_PENDING : self::PAYMENT_NOT_REQUIRED;
        $paymentStatusSource = 'system';

        foreach (['desired_mikro_series', 'tax_mode', 'payment_link_required'] as $serverOwnedField) {
            if (array_key_exists($serverOwnedField, $input)
                && $input[$serverOwnedField] !== null
                && (string) $input[$serverOwnedField] !== (string) $decision[$serverOwnedField]) {
                throw ValidationException::withMessages([
                    'order_context.'.$serverOwnedField => 'Seri, KDV ve ödeme bağlantısı kuralları sunucu tarafından belirlenir.',
                ]);
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
            'tax_mode' => $taxMode,
            'tax_label' => $this->taxLabel($taxMode),
            'vat_rate' => $vatRate,
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
            'commercial_mode' => $commercialMode,
            'commercial_mode_label' => $commercialMode === self::COMMERCIAL_FREE ? 'Ücretsiz' : 'Ücretli',
            'delivery_mode' => $deliveryMode,
            'delivery_mode_label' => $deliveryMode === self::DELIVERY_SHIPMENT ? 'Sevk' : ($deliveryMode === self::DELIVERY_HAND ? 'Elden' : 'Yok'),
            'delivery_status' => $deliveryStatus,
            'delivery_status_label' => 'Teslim bekliyor',
            'payment_collection_mode' => $paymentCollectionMode,
            'payment_status' => $paymentStatus,
            'payment_status_label' => $this->paymentStatusLabel($paymentStatus),
            'payment_status_source' => $paymentStatusSource,
            'payment_status_source_label' => 'Sistem',
            'payment_link_required' => $paymentLinkRequired,
            'collection_required' => $collectionRequired,
            'order_line_unit_price' => $orderLineUnitPrice,
            'order_line_unit_price_label' => $this->moneyLabel($orderLineUnitPrice, $currency),
            'order_line_total' => $orderLineTotal,
            'order_line_total_label' => $this->moneyLabel($orderLineTotal, $currency),
            'collection_amount' => $collectionAmount,
            'collection_amount_label' => $this->moneyLabel($collectionAmount, $currency),
            'future_order_trigger' => $futureOrderTrigger,
            'finance_review_required' => false,
            'related_product_serial' => $relatedProductSerial !== '' ? $relatedProductSerial : null,
            'charged_amount' => $collectionAmount,
            'charged_amount_label' => $this->moneyLabel($collectionAmount, $currency),
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
            'tax_mode' => $taxMode,
            'vat_rate' => $vatRate,
            'billing' => $billing,
            'shipping_same_as_billing' => $shippingSameAsBilling,
            'delivery_target' => $deliveryTarget,
            'shipping' => $shipping,
            'part_supplier' => $partSupplier,
            'commercial_mode' => $commercialMode,
            'delivery_mode' => $deliveryMode,
            'collection_allocation' => $collectionAllocation,
            'part' => $this->partIdentity($part),
            'related_product_serial' => $normalized['related_product_serial'],
            'order_line_total' => number_format($orderLineTotal, 2, '.', ''),
            'collection_amount' => number_format($collectionAmount, 2, '.', ''),
            'currency' => $currency,
            'shipment_required' => $shipmentRequired,
            'payment_link_required' => $paymentLinkRequired,
            'payment_status' => $paymentStatus,
            'future_order_trigger' => $futureOrderTrigger,
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
            $name = trim((string) $request->customer_name);
            [$firstName, $lastName] = $this->splitIndividualName($name);
            $snapshot = [
                'source' => $source,
                'billing_type' => 'individual',
                'customer_code' => null,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'legal_title' => null,
                'name_or_title' => $name,
                'phone' => $this->normalizePhone($request->customer_phone, 'order_context.billing.phone'),
                'email' => null,
                'tckn' => null,
                'vkn' => null,
                'tax_identity' => null,
                'tax_office' => null,
                'address' => $this->requestAddress($request),
                'city' => $this->normalizeCity($request->customer_city, $request->customer_district, 'Fatura'),
                'district' => trim((string) $request->customer_district),
                'postal_code' => null,
            ];
        } elseif ($source === 'manual_billing_draft') {
            $billing = is_array($input['billing'] ?? null) ? $input['billing'] : [];
            $billingType = trim((string) ($billing['billing_type'] ?? ''));
            if (! in_array($billingType, ['individual', 'company'], true)) {
                throw ValidationException::withMessages([
                    'order_context.billing.billing_type' => 'Fatura müşterisi kişi veya şirket olarak seçilmelidir.',
                ]);
            }
            $firstName = trim((string) ($billing['first_name'] ?? ''));
            $lastName = trim((string) ($billing['last_name'] ?? ''));
            $legalTitle = trim((string) ($billing['legal_title'] ?? ''));
            if ($billingType === 'individual' && ($firstName === '' || $lastName === '')) {
                throw ValidationException::withMessages([
                    $firstName === '' ? 'order_context.billing.first_name' : 'order_context.billing.last_name' => $firstName === ''
                        ? 'Ad alanı zorunludur.'
                        : 'Soyad alanı zorunludur.',
                ]);
            }
            if ($billingType === 'company' && $legalTitle === '') {
                throw ValidationException::withMessages([
                    'order_context.billing.legal_title' => 'Şirket unvanı zorunludur.',
                ]);
            }
            $email = $this->nullableText($billing['email'] ?? null);
            if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw ValidationException::withMessages([
                    'order_context.billing.email' => 'Geçerli bir e-posta adresi girin.',
                ]);
            }
            $tckn = $this->validatedIdentityNumber($billing['tckn'] ?? null, 11, 'TCKN', 'order_context.billing.tckn');
            $vkn = $this->validatedIdentityNumber($billing['vkn'] ?? null, 10, 'VKN', 'order_context.billing.vkn');
            if ($billingType === 'individual' && $vkn !== null) {
                throw ValidationException::withMessages(['order_context.billing.vkn' => 'VKN yalnız şirket faturasında kullanılabilir.']);
            }
            if ($billingType === 'company' && $tckn !== null) {
                throw ValidationException::withMessages(['order_context.billing.tckn' => 'TCKN yalnız kişi faturasında kullanılabilir.']);
            }
            $snapshot = [
                'source' => $source,
                'billing_type' => $billingType,
                'customer_code' => $this->nullableText($billing['customer_code'] ?? null),
                'first_name' => $billingType === 'individual' ? $firstName : null,
                'last_name' => $billingType === 'individual' ? $lastName : null,
                'legal_title' => $billingType === 'company' ? $legalTitle : null,
                'name_or_title' => $billingType === 'company' ? $legalTitle : trim($firstName.' '.$lastName),
                'phone' => $this->normalizePhone($billing['phone'] ?? null, 'order_context.billing.phone'),
                'email' => $email,
                'tckn' => $tckn,
                'vkn' => $vkn,
                'tax_identity' => $billingType === 'company' ? $vkn : $tckn,
                'tax_office' => $this->nullableText($billing['tax_office'] ?? null),
                'address' => trim((string) ($billing['address'] ?? '')),
                'city' => $this->normalizeCity($billing['city'] ?? null, $billing['district'] ?? null, 'Fatura'),
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
                'recipient_phone' => $this->normalizePhone($request->customer_phone, 'order_context.shipping.recipient_phone'),
                'address' => $this->requestAddress($request),
                'city' => $this->normalizeCity($request->customer_city, $request->customer_district, 'Sevk'),
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

        $fixtureTransport = app()->environment('testing')
            && (bool) config('services.technical_service.payment_order_context_test_stock', false);
        $source = trim((string) ($decoded['source'] ?? ''));
        if (($source === 'test_fixture') !== $fixtureTransport) {
            throw ValidationException::withMessages([
                'order_context.stock_selection_token' => 'Gerçek Mikro stok seçimi doğrulanamadı. Stok aramasından tekrar seçin.',
            ]);
        }
        if (! $fixtureTransport && $source !== 'mikro') {
            throw ValidationException::withMessages([
                'order_context.stock_selection_token' => 'Gerçek Mikro stok seçimi doğrulanamadı. Stok aramasından tekrar seçin.',
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
        if ($serialRequired && $selectedSerial === '') {
            throw ValidationException::withMessages([
                'order_context.selected_part_serial' => 'Seri takipli parça için doğrulanmış parça seri numarasını seçin.',
            ]);
        }

        $onHand = $decoded['on_hand'] ?? null;
        $reserved = $decoded['reserved'] ?? null;
        $available = $decoded['available'] ?? null;
        if ($fixtureTransport) {
            if ($serialRequired && ! $serials->contains($selectedSerial)) {
                throw ValidationException::withMessages([
                    'order_context.selected_part_serial' => 'Seri takipli parça için doğrulanmış parça seri numarasını seçin.',
                ]);
            }
        } elseif (! (bool) ($decoded['availability_verified'] ?? false)) {
            throw ValidationException::withMessages([
                'order_context.stock_selection_token' => 'Stok miktarı henüz doğrulanmadı. Parça kimliği bulundu; stok uygunluğu doğrulanmadan ödeme ve sipariş hazırlığı başlatılamaz.',
            ]);
        } else {
            $availability = $this->mikro->stockAvailability(trim((string) ($decoded['item_code'] ?? '')));
            $availabilityRow = collect($availability['data'] ?? [])->first(fn (mixed $row): bool => is_array($row));
            if (($availability['ok'] ?? false) !== true || ! is_array($availabilityRow)) {
                throw ValidationException::withMessages([
                    'order_context.stock_selection_token' => 'Mikro stok kullanılabilirliği doğrulanamadı. Parça seçimi tamamlanamadı.',
                ]);
            }
            $onHand = round((float) ($availabilityRow['depot_1_quantity'] ?? 0) + (float) ($availabilityRow['depot_5_quantity'] ?? 0), 3);
            $available = is_numeric($availabilityRow['available_quantity'] ?? null)
                ? (float) $availabilityRow['available_quantity']
                : null;
            $reserved = $available !== null ? max(0, round($onHand - $available, 3)) : null;

            if ($serialRequired) {
                $serialResult = $this->mikro->serialLookup($selectedSerial);
                $serialRow = collect($serialResult['data'] ?? [])->first(fn (mixed $row): bool => is_array($row));
                if (($serialResult['ok'] ?? false) !== true
                    || ! is_array($serialRow)
                    || trim((string) ($serialRow['stock_code'] ?? '')) !== trim((string) ($decoded['item_code'] ?? ''))) {
                    throw ValidationException::withMessages([
                        'order_context.selected_part_serial' => 'Parça seri numarası seçilen Mikro stok kartıyla doğrulanamadı.',
                    ]);
                }
            }
        }

        return [
            'item_code' => trim((string) ($decoded['item_code'] ?? '')),
            'item_name' => trim((string) ($decoded['item_name'] ?? '')),
            'quantity' => $quantity,
            'unit_code' => trim((string) ($decoded['unit_code'] ?? 'ADET')) ?: 'ADET',
            'warehouse_code' => $this->nullableText($decoded['warehouse_code'] ?? null),
            'stock_source' => $source,
            'stock_source_label' => trim((string) ($decoded['source_label'] ?? '')),
            'stock_freshness_at' => trim((string) ($decoded['freshness_at'] ?? '')),
            'on_hand' => $onHand,
            'reserved' => $reserved,
            'available' => $available,
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
            'tax_mode' => $normalized['tax_mode'],
            'vat_rate' => $normalized['vat_rate'],
            'future_mikro_write_state' => $normalized['future_mikro_write_state'],
            'future_order_trigger' => $normalized['future_order_trigger'],
            'finance_review_required' => false,
            'billing_source' => $billing['source'],
            'billing_type' => $billing['billing_type'],
            'billing_customer_code' => $billing['customer_code'],
            'billing_first_name' => $billing['first_name'],
            'billing_last_name' => $billing['last_name'],
            'billing_legal_title' => $billing['legal_title'],
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
            'commercial_mode' => $normalized['commercial_mode'],
            'delivery_mode' => $normalized['delivery_mode'],
            'delivery_status' => $normalized['delivery_status'],
            'payment_collection_mode' => $normalized['payment_collection_mode'],
            'payment_status' => $normalized['payment_status'],
            'payment_status_source' => $normalized['payment_status_source'],
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
            'order_line_unit_price' => $normalized['order_line_unit_price'],
            'order_line_total' => $normalized['order_line_total'],
            'collection_amount' => $normalized['collection_amount'],
            'payment_link_required' => $normalized['payment_link_required'],
            'collection_required' => $normalized['collection_required'],
            'currency' => $normalized['currency'],
            'shipment_required' => $normalized['shipment_required'],
            'future_carrier_state' => $normalized['future_carrier_state'],
            'description2_preview' => $normalized['description2_preview'],
            'description2_version' => self::DESCRIPTION2_VERSION,
            'context_hash' => $normalized['context_hash'],
            'idempotency_key' => $idempotencyKey,
            'correlation_id' => (string) Str::uuid(),
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
            'billing_type' => $row->billing_type ?: 'individual',
            'customer_code' => $row->billing_customer_code,
            'first_name' => $row->billing_first_name,
            'last_name' => $row->billing_last_name,
            'legal_title' => $row->billing_legal_title,
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
            'tax_mode' => $row->tax_mode,
            'tax_label' => $this->taxLabel((string) $row->tax_mode),
            'vat_rate' => is_numeric($row->vat_rate) ? (float) $row->vat_rate : null,
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
            'commercial_mode' => $row->commercial_mode,
            'commercial_mode_label' => (string) $row->commercial_mode === self::COMMERCIAL_FREE ? 'Ücretsiz' : ((string) $row->commercial_mode === self::COMMERCIAL_PAID ? 'Ücretli' : null),
            'delivery_mode' => $row->delivery_mode,
            'delivery_mode_label' => (string) $row->delivery_mode === self::DELIVERY_SHIPMENT ? 'Sevk' : ((string) $row->delivery_mode === self::DELIVERY_HAND ? 'Elden' : 'Yok'),
            'delivery_status' => $row->delivery_status,
            'delivery_status_label' => $this->deliveryStatusLabel((string) $row->delivery_status),
            'payment_collection_mode' => $row->payment_collection_mode,
            'payment_status' => $row->payment_status,
            'payment_status_label' => $this->paymentStatusLabel((string) $row->payment_status),
            'payment_status_source' => $row->payment_status_source,
            'payment_status_source_label' => $this->paymentStatusSourceLabel((string) $row->payment_status_source),
            'payment_link_required' => (bool) $row->payment_link_required,
            'collection_required' => (bool) $row->collection_required,
            'order_line_unit_price' => (float) $row->order_line_unit_price,
            'order_line_unit_price_label' => $this->moneyLabel((float) $row->order_line_unit_price, (string) $row->currency),
            'order_line_total' => (float) $row->order_line_total,
            'order_line_total_label' => $this->moneyLabel((float) $row->order_line_total, (string) $row->currency),
            'collection_amount' => (float) $row->collection_amount,
            'collection_amount_label' => $this->moneyLabel((float) $row->collection_amount, (string) $row->currency),
            'future_order_trigger' => $row->future_order_trigger,
            'finance_review_required' => (bool) $row->finance_review_required,
            'payment_status_reason' => $row->payment_status_reason,
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
                'HEDEF SERİ: S',
                'KDV: MİKRO HİZMET KARTI',
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

        if ($context['context_type'] === 'technician_supplied_part') {
            $lines[] = 'TEDARİK: USTA';
            $lines[] = 'MİKRO PARÇA SİPARİŞİ: YOK';
            $lines[] = 'SEVKİYAT: YOK';
            $lines[] = 'FATURA MÜŞTERİSİ: '.$billing['name_or_title'];

            return implode("\n", $lines);
        }

        $lines[] = 'TİCARİ DURUM: '.($context['commercial_mode'] === self::COMMERCIAL_FREE ? 'ÜCRETSİZ' : 'ÜCRETLİ');
        if ($context['delivery_mode'] === self::DELIVERY_HAND) {
            $lines[] = 'TESLİM: ELDEN';
            $lines[] = $context['commercial_mode'] === self::COMMERCIAL_FREE
                ? 'SİPARİŞ SATIR DEĞERİ: '.$context['order_line_total_label']
                : 'TUTAR: '.$context['collection_amount_label'];
            $lines[] = 'KDV: YOK';
            $lines[] = 'HEDEF SERİ: Q';
            $lines[] = $context['commercial_mode'] === self::COMMERCIAL_FREE
                ? 'TAHSİLAT: GEREKMİYOR'
                : 'ÖDEME DURUMU: '.mb_strtoupper($this->paymentStatusLabel((string) $context['payment_status']), 'UTF-8');
        } elseif ($context['shipment_required']) {
            if ($context['commercial_mode'] === self::COMMERCIAL_FREE) {
                $lines[] = 'SİPARİŞ TUTARI: '.$context['order_line_total_label'];
                $lines[] = 'KDV: YOK';
                $lines[] = 'HEDEF SERİ: Q';
                $lines[] = 'TAHSİLAT: GEREKMİYOR';
            } else {
                $lines[] = 'HEDEF SERİ: S';
                $lines[] = 'KDV: MİKRO STOK KARTI';
            }
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
            'recipient_phone' => $this->normalizePhone($technician->phone_e164 ?: $technician->phone, 'order_context.shipping.recipient_phone'),
            'address' => $address,
            'city' => $this->normalizeCity($technician->city, $technician->district, 'Sevk'),
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
            'recipient_phone' => $this->normalizePhone($shipping['recipient_phone'] ?? null, 'order_context.shipping.recipient_phone'),
            'address' => trim((string) ($shipping['address'] ?? '')),
            'city' => $this->normalizeCity($shipping['city'] ?? null, $shipping['district'] ?? null, 'Sevk'),
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

    /** @return array<string, mixed> */
    private function commercialDecision(string $purpose, ?string $supplier, string $commercialMode, ?string $deliveryMode): array
    {
        if ($purpose === self::PURPOSE_MOUNT_COLLECTION) {
            return [
                'desired_mikro_series' => 'S',
                'tax_mode' => 'standard_from_mikro_service_item',
                'payment_link_required' => true,
                'collection_required' => true,
                'payment_collection_mode' => 'payment_link',
                'future_order_trigger' => 'payment_paid',
                'future_mikro_write_state' => 'not_authorized',
                'shipment_required' => false,
            ];
        }

        if ($supplier === self::SUPPLIER_TECHNICIAN) {
            return [
                'desired_mikro_series' => null,
                'tax_mode' => 'none',
                'payment_link_required' => true,
                'collection_required' => true,
                'payment_collection_mode' => 'payment_link',
                'future_order_trigger' => null,
                'future_mikro_write_state' => 'not_required',
                'shipment_required' => false,
            ];
        }

        return match ($commercialMode.'|'.$deliveryMode) {
            self::COMMERCIAL_FREE.'|'.self::DELIVERY_HAND => [
                'desired_mikro_series' => 'Q',
                'tax_mode' => 'none',
                'payment_link_required' => false,
                'collection_required' => false,
                'payment_collection_mode' => 'none',
                'future_order_trigger' => 'ops_approved',
                'future_mikro_write_state' => 'not_authorized',
                'shipment_required' => false,
            ],
            self::COMMERCIAL_FREE.'|'.self::DELIVERY_SHIPMENT => [
                'desired_mikro_series' => 'Q',
                'tax_mode' => 'none',
                'payment_link_required' => false,
                'collection_required' => false,
                'payment_collection_mode' => 'none',
                'future_order_trigger' => 'ops_approved',
                'future_mikro_write_state' => 'not_authorized',
                'shipment_required' => true,
            ],
            self::COMMERCIAL_PAID.'|'.self::DELIVERY_HAND => [
                'desired_mikro_series' => 'Q',
                'tax_mode' => 'none',
                'payment_link_required' => false,
                'collection_required' => true,
                'payment_collection_mode' => 'manual',
                'future_order_trigger' => 'delivery_recorded',
                'future_mikro_write_state' => 'not_authorized',
                'shipment_required' => false,
            ],
            self::COMMERCIAL_PAID.'|'.self::DELIVERY_SHIPMENT => [
                'desired_mikro_series' => 'S',
                'tax_mode' => 'standard_from_mikro',
                'payment_link_required' => true,
                'collection_required' => true,
                'payment_collection_mode' => 'payment_link',
                'future_order_trigger' => 'payment_paid',
                'future_mikro_write_state' => 'not_authorized',
                'shipment_required' => true,
            ],
            default => throw ValidationException::withMessages([
                'order_context' => 'Parça ticari durumu ve teslim şekli birlikte seçilmelidir.',
            ]),
        };
    }

    /** @return array{0:string,1:string} */
    private function splitIndividualName(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        if (count($parts) < 2) {
            return [$name, ''];
        }

        $lastName = (string) array_pop($parts);

        return [trim(implode(' ', $parts)), $lastName];
    }

    private function normalizePhone(mixed $value, string $field): string
    {
        $raw = trim((string) $value);
        if ($raw === '' || preg_match('/[[:alpha:]]/u', $raw)) {
            throw ValidationException::withMessages([$field => 'Geçerli bir telefon numarası girin.']);
        }
        $leadingPlus = str_starts_with($raw, '+');
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($digits) < 10 || strlen($digits) > 15) {
            throw ValidationException::withMessages([$field => 'Telefon numarası 10-15 rakam içermelidir.']);
        }

        return $leadingPlus ? '+'.$digits : $digits;
    }

    private function normalizeCity(mixed $city, mixed $district, string $label): string
    {
        $cityName = trim((string) $city);
        $districtName = trim((string) $district);
        $canonicalCity = TechnicalServiceTurkeyLocations::standardizeProvinceName($cityName);
        if ($canonicalCity === null) {
            throw ValidationException::withMessages([
                'order_context' => $label.' ili geçerli bir Türkiye ili olmalıdır.',
            ]);
        }
        if ($districtName !== '' && TechnicalServiceTurkeyLocations::findProvinceByName($districtName) !== null) {
            throw ValidationException::withMessages([
                'order_context' => $label.' il ve ilçe bilgileri ters girilmiş görünüyor.',
            ]);
        }

        return $canonicalCity;
    }

    private function validatedIdentityNumber(mixed $value, int $length, string $label, string $field): ?string
    {
        $identity = $this->nullableText($value);
        if ($identity !== null && ! preg_match('/^\d{'.$length.'}$/', $identity)) {
            throw ValidationException::withMessages([$field => $label.' '.$length.' rakam olmalıdır.']);
        }

        return $identity;
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
            'ready_without_collection' => 'Tahsilat gerekmiyor',
            'manual_collection_pending' => 'Ödeme bekleniyor',
            'manual_collection_paid' => 'Ödeme alındı',
            'paid_waiting_mikro_write' => $contextType === 'technician_supplied_part'
                ? 'Ödeme alındı; Mikro siparişi gerekmiyor'
                : 'Ödeme alındı; Mikro yazımı bekliyor',
            'cancelled' => 'İptal edildi',
            default => 'Sipariş hazırlığı bekliyor',
        };
    }

    private function taxLabel(string $taxMode): string
    {
        return match ($taxMode) {
            'none' => 'Yok / %0',
            'standard_from_mikro' => 'Mikro stok kartından',
            'standard_from_mikro_service_item' => 'Mikro hizmet kartından',
            default => 'Doğrulanmadı',
        };
    }

    private function paymentStatusLabel(string $status): string
    {
        return match ($status) {
            self::PAYMENT_NOT_REQUIRED => 'Tahsilat gerekmiyor',
            self::PAYMENT_PENDING => 'Ödeme bekleniyor',
            self::PAYMENT_PAID => 'Ödeme alındı',
            self::PAYMENT_CANCELLED => 'İptal',
            default => 'Belirlenmedi',
        };
    }

    private function paymentStatusSourceLabel(string $source): string
    {
        return match ($source) {
            'auto_from_technician_delivery' => 'Teslim kaydı',
            'manual' => 'OPS kararı',
            'provider' => 'Ödeme sağlayıcısı',
            default => 'Sistem',
        };
    }

    private function deliveryStatusLabel(string $status): string
    {
        return match ($status) {
            'delivered' => 'Teslim edildi',
            'cancelled' => 'İptal edildi',
            default => 'Teslim bekliyor',
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
