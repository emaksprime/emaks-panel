<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServicePartRequest;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use App\Services\Mikro\MikroApiClient;
use App\Services\Mikro\MikroResponseSchemaCatalog;
use App\Support\TechnicalServiceTurkeyLocations;
use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class TechnicalServicePaymentOrderContextService
{
    public const TABLE = 'technical_service_payment_order_contexts';

    public const ITEM_TABLE = 'technical_service_payment_order_context_items';

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

    private const PART_DESCRIPTION2_VERSION = 3;

    private const ITEM_CLASSIFICATION_VERSION = 'technical-service-part-classification-v2';

    private const MAX_PART_LINES = 20;

    private const PHYSICAL_STOCK_CACHE_SECONDS = 30;

    private const TAX_PROFILE_CACHE_SECONDS = 30;

    private const TAX_RATE_SCALE = 4;

    private const PHYSICAL_STOCK_SCALE = 6;

    private const PHYSICAL_STOCK_WAREHOUSES = [1, 5];

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

    /** @return array<string, mixed> */
    public function searchParts(
        TechnicalServiceRequest $request,
        string $query,
        bool $includePhysicalStock = true,
        bool $manualRetry = false,
    ): array {
        $query = preg_replace('/\s+/u', ' ', trim($query)) ?? '';
        if (mb_strlen($query) < 2 || mb_strlen($query) > 60 || preg_match('/[\x00-\x1F\x7F]/u', $query)) {
            throw ValidationException::withMessages([
                'query' => 'Parça araması 2-60 karakter olmalıdır.',
            ]);
        }

        if (app()->environment('testing') && (bool) config('services.technical_service.payment_order_context_test_stock', false)) {
            return [
                ...$this->localPartFixtures($request, $query),
                'search_state' => 'current',
                'physical_stock_state' => 'current',
                'error_code' => null,
                'error_message' => null,
                'correlation_id' => null,
                'circuit_state' => 'TEST_FIXTURE',
                'fallback_used' => false,
            ];
        }

        try {
            $result = $manualRetry
                ? $this->mikro->retrySearchStocks($query)
                : $this->mikro->searchStocks($query);
            if (($result['success'] ?? $result['ok'] ?? false) !== true) {
                throw new DomainException((string) ($result['error_code'] ?? 'MIKRO_STOCK_READ_UNAVAILABLE'));
            }
        } catch (Throwable $exception) {
            report($exception);

            return $this->stockSearchFailure($result ?? [], $exception->getMessage());
        }

        try {
            $freshnessAt = filled($result['freshness_at'] ?? null)
                ? (string) $result['freshness_at']
                : now()->toISOString();
            $searchIsStale = ($result['stale'] ?? false) === true || ($result['fallback_used'] ?? false) === true;
            $sourceLabel = $searchIsStale ? 'Eski Mikro kaydı' : 'Mikro API';
            $candidateRows = collect($result['data'] ?? $result['rows'] ?? $result['result'] ?? [])
                ->filter(fn (mixed $row): bool => is_array($row))
                ->take(self::MAX_PART_LINES)
                ->values();
            $deviceCodes = $this->panelDeviceCodes($candidateRows
                ->map(fn (array $row): string => trim((string) ($row['item_code'] ?? '')))
                ->filter()
                ->all());
            $classifiedRows = $candidateRows
                ->map(function (array $row) use ($freshnessAt, $sourceLabel, $deviceCodes, $searchIsStale): array {
                    $classification = $this->classifyStockItem($row, $deviceCodes);
                    $detailTrackingType = filter_var($row['detail_tracking_type'] ?? null, FILTER_VALIDATE_INT);
                    $serialTrackingState = match ($detailTrackingType) {
                        0 => 'not_required',
                        3 => 'required',
                        default => 'unverified',
                    };
                    $item = [
                        'item_code' => trim((string) ($row['item_code'] ?? '')),
                        'item_name' => trim((string) ($row['item_name'] ?? '')),
                        'item_short_name' => filled($row['item_short_name'] ?? null) ? trim((string) $row['item_short_name']) : null,
                        'unit_code' => filled($row['unit_code'] ?? null) ? trim((string) $row['unit_code']) : null,
                        ...$classification,
                        'warehouse_code' => null,
                        'on_hand' => null,
                        'reserved' => null,
                        'available' => null,
                        'availability_verified' => false,
                        'physical_stock_state' => $classification['selectable'] ? 'unverified' : 'not_applicable',
                        'physical_stock_verified' => false,
                        'physical_stock_total' => null,
                        'physical_stock_total_label' => null,
                        'physical_stock_warehouses' => [],
                        'physical_stock_contract_version' => MikroResponseSchemaCatalog::PHYSICAL_STOCK_CONTRACT_VERSION,
                        'physical_stock_correlation_id' => null,
                        'stock_status_label' => $classification['selectable'] ? 'Stok doğrulanamadı' : null,
                        'serial_tracking_state' => $serialTrackingState,
                        'serial_tracking_required' => $serialTrackingState === 'required',
                        'serials' => [],
                        'source' => 'mikro',
                        'source_label' => $sourceLabel,
                        'freshness_at' => $freshnessAt,
                        'mikro_contract_fingerprint' => MikroResponseSchemaCatalog::STOCK_SEARCH_RESPONSE_SCHEMA_FINGERPRINT,
                        'identity_state' => $searchIsStale ? 'stale' : 'current',
                        'identity_verified_at' => $searchIsStale ? null : now()->toISOString(),
                    ];

                    if ($item['item_code'] === '' || $item['item_name'] === '') {
                        throw new DomainException('MIKRO_STOCK_SELECTION_SCHEMA_INCOMPLETE');
                    }

                    return $item;
                })
                ->values();
        } catch (DomainException $exception) {
            report($exception);

            return $this->stockSearchFailure($result, $exception->getMessage());
        }

        if ($searchIsStale) {
            $rows = $classifiedRows
                ->map(function (array $item) use ($request): array {
                    $stale = [
                        ...$item,
                        'selectable' => false,
                        'selection_blocker' => 'Eski Mikro kaydı. Ürün ve stok bilgisi yeniden doğrulanmalıdır.',
                        'stock_status_label' => 'Eski Mikro kaydı',
                    ];

                    return $this->partSearchItem($request, $stale);
                })
                ->values();

            return [
                'source' => 'mikro',
                'source_label' => $sourceLabel,
                'freshness_at' => $freshnessAt,
                'search_state' => 'stale',
                'physical_stock_state' => 'not_requested',
                'error_code' => (string) ($result['error_code'] ?? 'MIKRO_STOCK_SEARCH_STALE'),
                'error_message' => 'Eski Mikro kaydı. Ürün ve stok bilgisi yeniden doğrulanmalıdır.',
                'correlation_id' => $result['correlation_id'] ?? null,
                'circuit_state' => (string) ($result['circuit_state'] ?? 'CLOSED'),
                'fallback_used' => true,
                'items' => $rows->all(),
            ];
        }

        $physicalMeta = [
            'state' => 'not_requested',
            'error_code' => null,
            'correlation_id' => null,
            'circuit_state' => 'CLOSED',
            'fallback_used' => false,
        ];
        if ($includePhysicalStock) {
            $stockByCode = $this->physicalStockByItemCodes(
                $classifiedRows
                    ->filter(fn (array $item): bool => (bool) ($item['selectable'] ?? false))
                    ->pluck('item_code')
                    ->all(),
                false,
                true,
                false,
                $physicalMeta,
            );
            $rows = $classifiedRows
                ->map(fn (array $item): array => $this->partSearchItem(
                    $request,
                    $this->applyPhysicalStockProjection($item, $stockByCode),
                ))
                ->values();
        } else {
            $rows = $classifiedRows
                ->map(function (array $item) use ($request): array {
                    if (($item['selectable'] ?? false) !== true) {
                        return $this->partSearchItem($request, $item);
                    }

                    $identity = $item;
                    $checking = [
                        ...$item,
                        'selectable' => false,
                        'selection_blocker' => 'Stok kontrol ediliyor...',
                        'stock_status_label' => 'Stok kontrol ediliyor...',
                    ];
                    $projection = $this->partSearchItem($request, $checking, $identity);

                    return [
                        ...$projection,
                        'stock_identity_token' => $projection['selection_token'],
                    ];
                })
                ->values();
        }

        return [
            'source' => 'mikro',
            'source_label' => $sourceLabel,
            'freshness_at' => $freshnessAt,
            'search_state' => 'current',
            'physical_stock_state' => $physicalMeta['state'],
            'error_code' => $physicalMeta['error_code'],
            'error_message' => in_array($physicalMeta['state'], ['current', 'not_requested'], true)
                ? null
                : 'Ürün Mikro API’den bulundu. Stok miktarı doğrulanamadı.',
            'correlation_id' => $result['correlation_id'] ?? null,
            'physical_stock_correlation_id' => $physicalMeta['correlation_id'],
            'circuit_state' => (string) ($result['circuit_state'] ?? 'CLOSED'),
            'physical_stock_circuit_state' => $physicalMeta['circuit_state'],
            'fallback_used' => false,
            'items' => $rows->all(),
        ];
    }

    /** @param array<int, string> $identityTokens @return array<string, mixed> */
    public function physicalStocksForPartSearch(
        TechnicalServiceRequest $request,
        array $identityTokens,
        bool $manualRetry = false,
    ): array {
        if ($identityTokens === [] || count($identityTokens) > self::MAX_PART_LINES) {
            throw ValidationException::withMessages([
                'identity_tokens' => 'Fiziksel stok kontrolü en fazla 20 ürün için yapılabilir.',
            ]);
        }

        $identities = collect($identityTokens)
            ->map(function (mixed $token) use ($request): array {
                try {
                    $decoded = json_decode(Crypt::decryptString((string) $token), true, 512, JSON_THROW_ON_ERROR);
                } catch (Throwable) {
                    throw ValidationException::withMessages([
                        'identity_tokens' => 'Mikro ürün kimliği doğrulanamadı. Stok aramasını yenileyin.',
                    ]);
                }
                if (! is_array($decoded)
                    || (int) ($decoded['request_id'] ?? 0) !== (int) $request->id
                    || ($decoded['source'] ?? null) !== 'mikro'
                    || ($decoded['identity_state'] ?? null) !== 'current'
                    || ! in_array($decoded['item_kind'] ?? null, ['part', 'accessory'], true)
                    || ($decoded['selectable'] ?? false) !== true) {
                    throw ValidationException::withMessages([
                        'identity_tokens' => 'Mikro ürün kimliği güncel değil. Stok aramasını yenileyin.',
                    ]);
                }

                unset($decoded['schema_version'], $decoded['request_id']);
                $decoded['_stock_identity_token'] = (string) $token;

                return $decoded;
            })
            ->unique(fn (array $item): string => mb_strtoupper(trim((string) ($item['item_code'] ?? '')), 'UTF-8'))
            ->values();

        $physicalMeta = [];
        $stockByCode = $this->physicalStockByItemCodes(
            $identities->pluck('item_code')->all(),
            false,
            ! $manualRetry,
            $manualRetry,
            $physicalMeta,
        );
        $items = $identities
            ->map(function (array $identity) use ($request, $stockByCode): array {
                $stockIdentityToken = (string) $identity['_stock_identity_token'];
                unset($identity['_stock_identity_token']);
                $projected = $this->applyPhysicalStockProjection($identity, $stockByCode);
                $tokenItem = ($projected['physical_stock_verified'] ?? false) === true
                    ? $projected
                    : $identity;

                return [
                    ...$this->partSearchItem($request, $projected, $tokenItem),
                    'stock_identity_token' => $stockIdentityToken,
                ];
            })
            ->values()
            ->all();

        return [
            'source' => 'mikro',
            'source_label' => 'Mikro API',
            'freshness_at' => $physicalMeta['freshness_at'] ?? null,
            'search_state' => 'current',
            'physical_stock_state' => $physicalMeta['state'] ?? 'unavailable',
            'error_code' => $physicalMeta['error_code'] ?? null,
            'error_message' => ($physicalMeta['state'] ?? 'unavailable') === 'current'
                ? null
                : 'Ürün Mikro API’den bulundu. Stok miktarı doğrulanamadı.',
            'correlation_id' => $physicalMeta['correlation_id'] ?? null,
            'circuit_state' => $physicalMeta['circuit_state'] ?? 'CLOSED',
            'fallback_used' => $physicalMeta['fallback_used'] ?? false,
            'items' => $items,
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
        $supersededPaymentIds = $contexts
            ->map(function (object $context): ?int {
                $metadata = is_string($context->metadata ?? null)
                    ? json_decode((string) $context->metadata, true)
                    : (is_array($context->metadata ?? null) ? $context->metadata : []);
                $paymentId = $metadata['superseded_payment_id'] ?? null;

                return is_numeric($paymentId) ? (int) $paymentId : null;
            })
            ->filter()
            ->unique();
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
            if ($supersededPaymentIds->contains((int) $pending['payment_id'])) {
                return [
                    ...$base,
                    'state' => 'fresh_link_required',
                    'fresh_link_required' => true,
                    'reason_required' => false,
                    'action_label' => 'Yeni bağlantı oluştur',
                    'message' => 'Parça listesi değişti. Eski ödeme bağlantısı tekrar kullanılamaz.',
                    'audit_reason' => 'Parça listesi temizlendiği için önceki bekleyen ödeme bağlantısı geçersizleştirildi.',
                    'supersede_payment_id' => $pending['payment_id'],
                ];
            }

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
        $normalized = $this->normalize($request, $purpose, $input, $amount, $currency, true);
        $taxBlockerCodes = array_values(array_intersect(
            (array) ($normalized['readiness']['blocker_codes'] ?? []),
            ['vat_unverified', 'tax_basis_unresolved'],
        ));
        if ($taxBlockerCodes !== []) {
            $taxBlockerMessage = in_array('tax_basis_unresolved', $taxBlockerCodes, true)
                ? 'Mikro stok kartında perakende ve toptan KDV oranları farklı. Sipariş vergi temeli doğrulanmadan işlem tamamlanamaz.'
                : 'KDV doğrulanamadı. Ödeme ve sipariş hazırlığı tamamlanamaz.';

            throw ValidationException::withMessages([
                'order_context' => $taxBlockerMessage,
            ]);
        }
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
            $this->insertContextLines($id, $normalized['lines'] ?? [], $actor);
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
        $this->revalidateStoredContextStock($context);

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

    /** @return array<string, mixed> */
    public function removePartContextLine(
        TechnicalServiceRequest $request,
        int $contextId,
        int $expectedRevision,
        string $lineKey,
        ?Authenticatable $actor,
    ): array {
        return DB::transaction(function () use ($request, $contextId, $expectedRevision, $lineKey, $actor): array {
            $latest = DB::table(self::TABLE)
                ->where('technical_service_request_id', $request->id)
                ->where('payment_purpose', self::PURPOSE_PART_CHARGE)
                ->orderByDesc('revision')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            if (! $latest) {
                throw ValidationException::withMessages(['order_context' => 'Parça hazırlığı bulunamadı.']);
            }

            $normalizedLineKey = trim($lineKey);
            $latestLines = DB::table(self::ITEM_TABLE)
                ->where('context_id', (int) $latest->id)
                ->orderBy('position')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $lineExists = $latestLines->contains(
                fn (object $line): bool => hash_equals((string) $line->line_key, $normalizedLineKey),
            );

            if ((int) $latest->id !== $contextId || (int) $latest->revision !== $expectedRevision) {
                if (! $lineExists) {
                    return $this->rowProjection($latest);
                }

                throw ValidationException::withMessages([
                    'expected_revision' => 'Parça hazırlığı güncellendi. Güncel durumu açıp tekrar deneyin.',
                ]);
            }

            $metadata = is_string($latest->metadata ?? null)
                ? json_decode((string) $latest->metadata, true)
                : (is_array($latest->metadata ?? null) ? $latest->metadata : []);
            if ((int) ($metadata['schema_version'] ?? 0) < 2) {
                throw ValidationException::withMessages([
                    'order_context' => 'Eski tek-parça kaydı bu ekrandan değiştirilemez.',
                ]);
            }
            if (! $lineExists) {
                return $this->rowProjection($latest);
            }
            if ((string) $latest->payment_status === self::PAYMENT_PAID) {
                throw ValidationException::withMessages([
                    'order_context' => 'Ödenmiş parça bağlamı değiştirilemez.',
                ]);
            }

            $supersededPaymentId = is_numeric($metadata['superseded_payment_id'] ?? null)
                ? (int) $metadata['superseded_payment_id']
                : null;
            if (is_numeric($latest->technical_service_mount_payment_id)) {
                $linkedPayment = TechnicalServiceMountPayment::query()
                    ->whereKey((int) $latest->technical_service_mount_payment_id)
                    ->lockForUpdate()
                    ->first();
                if ($linkedPayment instanceof TechnicalServiceMountPayment
                    && $linkedPayment->status === TechnicalServiceMountPayment::STATUS_PAID) {
                    throw ValidationException::withMessages([
                        'order_context' => 'Ödenmiş parça bağlamı değiştirilemez.',
                    ]);
                }
                if ($linkedPayment instanceof TechnicalServiceMountPayment
                    && $linkedPayment->status === TechnicalServiceMountPayment::STATUS_PENDING) {
                    $supersededPaymentId = (int) $linkedPayment->id;
                }
            }

            $removedLine = $latestLines->first(
                fn (object $line): bool => hash_equals((string) $line->line_key, $normalizedLineKey),
            );
            $remainingLines = $latestLines
                ->reject(fn (object $line): bool => hash_equals((string) $line->line_key, $normalizedLineKey))
                ->values();
            $remainingCount = $remainingLines->count();
            $lineTotalMinor = (int) $remainingLines->sum(
                fn (object $line): int => $this->decimalToScaledInteger(
                    number_format((float) $line->line_total, 2, '.', ''),
                    2,
                    'line_total',
                    0,
                    999999999999,
                ),
            );
            $lineTotal = $this->scaledIntegerToFloat($lineTotalMinor, 2);
            $totalQuantity = (float) $remainingLines->sum(fn (object $line): float => (float) $line->quantity);
            $collectionRequired = $remainingCount > 0 && (bool) $latest->collection_required;
            $paymentLinkRequired = $remainingCount > 0 && (bool) $latest->payment_link_required;
            $shipmentRequired = $remainingCount > 0 && (bool) $latest->shipment_required;
            $collectionAmount = $collectionRequired ? $lineTotal : 0.0;
            $paymentStatus = $remainingCount > 0
                ? (string) $latest->payment_status
                : self::PAYMENT_NOT_REQUIRED;
            $nextRevision = (int) $latest->revision + 1;
            $now = now();

            $descriptionContext = $this->rowProjection($latest);
            $descriptionLines = $remainingLines->map(fn (object $line): array => [
                'item_code' => (string) $line->item_code,
                'item_name' => (string) $line->item_name_snapshot,
                'quantity' => (float) $line->quantity,
                'unit_code' => $line->unit_code,
                'unit_price' => (float) $line->unit_price,
                'line_total' => (float) $line->line_total,
            ])->all();
            $descriptionContext = array_merge($descriptionContext, [
                'request_code' => trim((string) ($request->service_code ?: $request->mrn)),
                'lines' => $descriptionLines,
                'part' => $descriptionLines[0] ?? null,
                'line_count' => $remainingCount,
                'total_quantity' => $totalQuantity,
                'order_reference_total_label' => $this->moneyLabel($lineTotal, (string) $latest->currency),
                'collection_amount_label' => $this->moneyLabel($collectionAmount, (string) $latest->currency),
                'payment_status' => $paymentStatus,
                'payment_link_required' => $paymentLinkRequired,
                'collection_required' => $collectionRequired,
                'shipment_required' => $shipmentRequired,
            ]);

            $contextHash = hash('sha256', json_encode([
                'source_context_hash' => (string) $latest->context_hash,
                'request_id' => (int) $request->id,
                'payment_purpose' => self::PURPOSE_PART_CHARGE,
                'commercial_mode' => $latest->commercial_mode,
                'delivery_mode' => $latest->delivery_mode,
                'billing_source' => $latest->billing_source,
                'shipping_same_as_billing' => (bool) $latest->shipping_same_as_billing,
                'line_keys' => $remainingLines->pluck('line_key')->sort()->values()->all(),
                'line_total' => number_format($lineTotal, 2, '.', ''),
                'collection_amount' => number_format($collectionAmount, 2, '.', ''),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $nextMetadata = array_merge($metadata, [
                'schema_version' => 2,
                'line_count' => $remainingCount,
                'explicit_items' => true,
                'explicit_empty' => $remainingCount === 0,
                'source_context_id' => (int) $latest->id,
                'removed_line_key' => $normalizedLineKey,
                'superseded_payment_id' => $supersededPaymentId,
                'stock_snapshot' => null,
                'external_execution' => [
                    'payment_write_count' => 0,
                    'provider_count' => 0,
                    'mikro_write_count' => 0,
                    'carrier_count' => 0,
                    'message_send_count' => 0,
                ],
            ]);
            $payload = (array) $latest;
            unset($payload['id']);
            $payload = array_merge($payload, [
                'technical_service_mount_payment_id' => null,
                'state' => 'draft',
                'item_code' => null,
                'item_name_snapshot' => null,
                'quantity' => null,
                'unit_code' => null,
                'warehouse_code' => null,
                'stock_source' => null,
                'stock_freshness_at' => null,
                'part_serial_tracking_required' => false,
                'selected_part_serial' => null,
                'charged_amount' => $collectionAmount,
                'order_line_unit_price' => $remainingCount === 1 ? (float) $remainingLines->first()->unit_price : 0,
                'order_line_total' => $lineTotal,
                'collection_amount' => $collectionAmount,
                'payment_link_required' => $paymentLinkRequired,
                'collection_required' => $collectionRequired,
                'payment_collection_mode' => $remainingCount > 0 ? $latest->payment_collection_mode : 'none',
                'payment_status' => $paymentStatus,
                'payment_status_source' => $remainingCount > 0 ? $latest->payment_status_source : 'system',
                'payment_status_changed_by' => $remainingCount > 0 ? $latest->payment_status_changed_by : null,
                'payment_status_changed_at' => $remainingCount > 0 ? $latest->payment_status_changed_at : null,
                'payment_status_reason' => $remainingCount > 0 ? $latest->payment_status_reason : null,
                'shipment_required' => $shipmentRequired,
                'future_carrier_state' => $shipmentRequired ? $latest->future_carrier_state : 'not_required',
                'description2_preview' => $this->renderDescription2($request, $descriptionContext),
                'context_hash' => $contextHash,
                'idempotency_key' => hash('sha256', implode('|', [
                    'technical-service-payment-order-context-line-remove-v1',
                    $request->id,
                    $latest->id,
                    $normalizedLineKey,
                    $nextRevision,
                    $contextHash,
                ])),
                'correlation_id' => (string) Str::uuid(),
                'revision' => $nextRevision,
                'created_by' => $actor?->getAuthIdentifier(),
                'metadata' => json_encode($nextMetadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $nextContextId = DB::table(self::TABLE)->insertGetId($payload);

            if ($remainingLines->isNotEmpty()) {
                DB::table(self::ITEM_TABLE)->insert($remainingLines->map(
                    function (object $line, int $position) use ($nextContextId, $actor, $now): array {
                        $linePayload = (array) $line;
                        unset($linePayload['id']);
                        $linePayload['context_id'] = $nextContextId;
                        $linePayload['position'] = $position + 1;
                        $linePayload['created_by'] = $actor?->getAuthIdentifier();
                        $linePayload['updated_by'] = $actor?->getAuthIdentifier();
                        $linePayload['created_at'] = $now;
                        $linePayload['updated_at'] = $now;

                        return $linePayload;
                    },
                )->all());
            }

            $request->events()->create([
                'event_type' => 'payment_order_context_line_removed',
                'title' => 'Parça taslağı satırı kaldırıldı',
                'note' => (string) ($removedLine->item_name_snapshot ?? $removedLine->item_code),
                'from_status' => $request->workflow_status,
                'to_status' => $request->workflow_status,
                'author_user_id' => $actor?->getAuthIdentifier(),
                'metadata' => [
                    'source_order_context_id' => (int) $latest->id,
                    'order_context_id' => $nextContextId,
                    'removed_line_key' => $normalizedLineKey,
                    'before_line_count' => $latestLines->count(),
                    'after_line_count' => $remainingCount,
                    'before_context_hash' => (string) $latest->context_hash,
                    'after_context_hash' => $contextHash,
                    'payment_write_count' => 0,
                    'provider_count' => 0,
                    'mikro_write_count' => 0,
                    'carrier_count' => 0,
                    'message_send_count' => 0,
                ],
            ]);

            return $this->rowProjection(DB::table(self::TABLE)->where('id', $nextContextId)->firstOrFail());
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
        bool $revalidatePhysicalStock = false,
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
        $lines = [];
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
                $lines = $this->selectedStockItems($request, $input, $commercialMode, $deliveryMode, $currency);
                if ($revalidatePhysicalStock) {
                    $lines = $this->revalidatePhysicalStockLines($lines);
                }
                $part = $lines[0] ?? null;
                $quantity = array_sum(array_map(fn (array $line): float => (float) $line['quantity'], $lines));
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
        $futureOrderTrigger = $decision['future_order_trigger'];

        $providedAmountMinor = $this->decimalToScaledInteger(number_format($amount, 2, '.', ''), 2, 'amount', 0, 999999999999);
        if ($partSupplier === self::SUPPLIER_TECHNICIAN && is_array($part)) {
            $lines = [$this->technicianPartLine($part, $providedAmountMinor, $currency)];
            $part = $lines[0];
        }
        $orderLineTotalMinor = $partSupplier === self::SUPPLIER_EMAKS
            ? array_sum(array_column($lines, 'line_total_minor'))
            : $providedAmountMinor;
        if ($partSupplier === self::SUPPLIER_EMAKS && $providedAmountMinor !== $orderLineTotalMinor) {
            throw ValidationException::withMessages([
                'amount' => 'Parça toplamı değişti. Sunucu tarafından hesaplanan güncel toplamı kullanın.',
            ]);
        }

        $lines = $this->applyLineTaxProfiles(
            $lines,
            $taxMode,
            $revalidatePhysicalStock,
            ($input['retry_scope'] ?? null) === 'tax_profile',
        );
        $taxTotals = $this->lineTaxTotals($lines, $currency, $taxMode, $providedAmountMinor);
        $vatRate = $taxTotals['cart_vat_rate'];

        if ($purpose === self::PURPOSE_MOUNT_COLLECTION || $partSupplier === self::SUPPLIER_TECHNICIAN || $collectionRequired) {
            if (($partSupplier === self::SUPPLIER_EMAKS ? $orderLineTotalMinor : $providedAmountMinor) <= 0) {
                throw ValidationException::withMessages(['amount' => 'Tahsilat tutarı 0 TL üzerinde olmalıdır.']);
            }
        } elseif ($providedAmountMinor < 0) {
            throw ValidationException::withMessages(['amount' => 'Tutar negatif olamaz.']);
        }

        if ($commercialMode === self::COMMERCIAL_FREE && $deliveryMode === self::DELIVERY_SHIPMENT && $providedAmountMinor !== 0) {
            throw ValidationException::withMessages([
                'amount' => 'Ücretsiz sevkte sipariş ve tahsilat tutarı 0 TL olmalıdır.',
            ]);
        }

        $orderLineTotal = $this->scaledIntegerToFloat($orderLineTotalMinor, 2);
        $orderLineUnitPrice = count($lines) === 1 ? (float) ($lines[0]['unit_price'] ?? 0) : 0.0;
        $collectionAmountMinor = $collectionRequired ? $orderLineTotalMinor : 0;
        $collectionAmount = $this->scaledIntegerToFloat($collectionAmountMinor, 2);
        $paymentStatus = $collectionRequired ? self::PAYMENT_PENDING : self::PAYMENT_NOT_REQUIRED;
        $paymentStatusSource = 'system';
        $descriptionVersion = $purpose === self::PURPOSE_PART_CHARGE
            ? self::PART_DESCRIPTION2_VERSION
            : self::DESCRIPTION2_VERSION;
        $readiness = $this->partReadiness($partSupplier, $lines, $taxMode);
        $lines = array_map(function (array $line): array {
            unset(
                $line['quantity_milli'],
                $line['unit_price_minor'],
                $line['line_total_minor'],
                $line['physical_stock_total_micros'],
                $line['net_line_total_minor'],
                $line['vat_line_total_minor'],
                $line['fixture_tax_profile'],
            );

            return $line;
        }, $lines);
        $part = $lines[0] ?? $part;

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
            'tax_label' => $taxTotals['tax_label'],
            'tax_status' => $taxTotals['tax_status'],
            'tax_source' => $taxTotals['tax_source'],
            'tax_source_label' => $taxTotals['tax_source_label'],
            'mixed_vat_rates' => $taxTotals['mixed_vat_rates'],
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
            'lines' => $lines,
            'line_count' => count($lines),
            'total_quantity' => array_sum(array_map(fn (array $line): float => (float) ($line['quantity'] ?? 0), $lines)),
            'total_quantity_label' => $this->quantityLabel(array_sum(array_map(fn (array $line): float => (float) ($line['quantity'] ?? 0), $lines))),
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
            'order_reference_total' => $orderLineTotal,
            'order_reference_total_label' => $this->moneyLabel($orderLineTotal, $currency),
            'gross_total' => $taxTotals['gross_total'],
            'gross_total_label' => $taxTotals['gross_total_label'],
            'net_total' => $taxTotals['net_total'],
            'net_total_label' => $taxTotals['net_total_label'],
            'vat_total' => $taxTotals['vat_total'],
            'vat_total_label' => $taxTotals['vat_total_label'],
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
            'readiness' => $readiness,
            'mikro_write_execution_count' => 0,
            'carrier_execution_count' => 0,
            'description2_version' => $descriptionVersion,
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
            'part' => $purpose === self::PURPOSE_PART_CHARGE ? null : $this->partIdentity($part),
            'lines' => $purpose === self::PURPOSE_PART_CHARGE
                ? collect($lines)
                    ->sortBy('line_key')
                    ->map(fn (array $line): array => [
                        'item_code' => $line['item_code'],
                        'item_kind' => $line['item_kind'],
                        'quantity' => number_format((float) $line['quantity'], 3, '.', ''),
                        'gross_unit_price' => $line['gross_unit_price'],
                        'gross_line_total' => $line['gross_line_total'],
                        'selected_tax_basis' => $line['selected_tax_basis'] ?? null,
                        'selected_tax_pointer' => $line['selected_tax_pointer'] ?? null,
                        'vat_rate_snapshot' => $line['vat_rate_snapshot'] ?? null,
                        'currency' => $line['currency'],
                        'serial_tracking_state' => $line['serial_tracking_state'],
                        'selected_part_serial' => $line['selected_part_serial'],
                    ])
                    ->values()
                    ->all()
                : null,
            'related_product_serial' => $normalized['related_product_serial'],
            'order_line_total' => number_format($orderLineTotal, 2, '.', ''),
            'collection_amount' => number_format($collectionAmount, 2, '.', ''),
            'currency' => $currency,
            'shipment_required' => $shipmentRequired,
            'payment_link_required' => $paymentLinkRequired,
            'payment_status' => $paymentStatus,
            'future_order_trigger' => $futureOrderTrigger,
            'future_carrier_state' => $futureCarrierState,
            'description2_version' => $descriptionVersion,
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

    /** @param array<string, mixed> $input @return array<int, array<string, mixed>> */
    private function selectedStockItems(
        TechnicalServiceRequest $request,
        array $input,
        string $commercialMode,
        string $deliveryMode,
        string $currency,
    ): array {
        $rawLines = is_array($input['lines'] ?? null) ? array_values($input['lines']) : [[
            'stock_selection_token' => $input['stock_selection_token'] ?? null,
            'quantity' => $input['quantity'] ?? null,
            'unit_price' => $input['unit_price'] ?? null,
            'selected_part_serial' => $input['selected_part_serial'] ?? null,
        ]];
        if ($rawLines === [] || count($rawLines) > self::MAX_PART_LINES) {
            throw ValidationException::withMessages([
                'order_context.lines' => 'En fazla 20 farklı parça kalemi seçilebilir.',
            ]);
        }

        $fixtureTransport = app()->environment('testing')
            && (bool) config('services.technical_service.payment_order_context_test_stock', false);
        $merged = [];
        foreach ($rawLines as $index => $rawLine) {
            if (! is_array($rawLine)) {
                throw ValidationException::withMessages(['order_context.lines.'.($index).'' => 'Parça satırı geçersiz.']);
            }
            $token = trim((string) ($rawLine['stock_selection_token'] ?? ''));
            if ($token === '') {
                throw ValidationException::withMessages([
                    'order_context.lines.'.$index.'.stock_selection_token' => 'Mikro stok listesinden bir parça seçin.',
                ]);
            }
            try {
                $decoded = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                throw ValidationException::withMessages([
                    'order_context.lines.'.$index.'.stock_selection_token' => 'Parça seçimi doğrulanamadı. Stok aramasından tekrar seçin.',
                ]);
            }
            if (! is_array($decoded) || (int) ($decoded['request_id'] ?? 0) !== (int) $request->id) {
                throw ValidationException::withMessages([
                    'order_context.lines.'.$index.'.stock_selection_token' => 'Seçilen parça bu teknik servis talebine ait değildir.',
                ]);
            }

            $source = trim((string) ($decoded['source'] ?? ''));
            if (($source === 'test_fixture') !== $fixtureTransport || (! $fixtureTransport && $source !== 'mikro')) {
                throw ValidationException::withMessages([
                    'order_context.lines.'.$index.'.stock_selection_token' => 'Gerçek Mikro stok seçimi doğrulanamadı. Stok aramasından tekrar seçin.',
                ]);
            }
            $itemKind = trim((string) ($decoded['item_kind'] ?? ($fixtureTransport ? 'part' : 'unknown')));
            if (! in_array($itemKind, ['part', 'accessory'], true) || ($decoded['selectable'] ?? false) !== true) {
                throw ValidationException::withMessages([
                    'order_context.lines.'.$index.'.stock_selection_token' => (string) ($decoded['selection_blocker'] ?? 'Türü doğrulanmadan bu kayıt parça olarak seçilemez.'),
                ]);
            }

            $physicalStockVerified = ($decoded['physical_stock_verified'] ?? $decoded['availability_verified'] ?? false) === true;
            $physicalStockMicros = $this->signedDecimalToScaledInteger($decoded['physical_stock_total'] ?? null);
            if (! $physicalStockVerified || $physicalStockMicros === null) {
                throw ValidationException::withMessages([
                    'order_context.lines.'.$index.'.stock_selection_token' => 'Mikro stok bilgisi doğrulanamadı. Stok doğrulanmadan işlem tamamlanamaz.',
                ]);
            }
            if ($physicalStockMicros <= 0) {
                throw ValidationException::withMessages([
                    'order_context.lines.'.$index.'.stock_selection_token' => 'Stokta yok',
                ]);
            }

            $itemCode = trim((string) ($decoded['item_code'] ?? ''));
            $itemName = trim((string) ($decoded['item_name'] ?? ''));
            if ($itemCode === '' || $itemName === '') {
                throw ValidationException::withMessages([
                    'order_context.lines.'.$index.'.stock_selection_token' => 'Parça kimliği eksik. Stok aramasından tekrar seçin.',
                ]);
            }
            $quantityMilli = $this->decimalToScaledInteger(
                $rawLine['quantity'] ?? null,
                3,
                'order_context.lines.'.$index.'.quantity',
                1,
                1000000000,
            );
            $unitPriceMinor = $this->decimalToScaledInteger(
                $rawLine['unit_price'] ?? 0,
                2,
                'order_context.lines.'.$index.'.unit_price',
                0,
                1000000000,
            );
            if ($commercialMode === self::COMMERCIAL_PAID && $unitPriceMinor <= 0) {
                throw ValidationException::withMessages([
                    'order_context.lines.'.$index.'.unit_price' => 'Ücretli parça için birim fiyat 0 TL üzerinde olmalıdır.',
                ]);
            }
            if ($commercialMode === self::COMMERCIAL_FREE && $deliveryMode === self::DELIVERY_SHIPMENT) {
                $unitPriceMinor = 0;
            }
            $lineTotalMinor = intdiv(($quantityMilli * $unitPriceMinor) + 500, 1000);
            if ($lineTotalMinor > 999999999999) {
                throw ValidationException::withMessages([
                    'order_context.lines.'.$index.'.unit_price' => 'Parça satır toplamı desteklenen üst sınırı aşmamalıdır.',
                ]);
            }

            $lineKey = hash('sha256', mb_strtoupper($itemCode, 'UTF-8'));
            if (isset($merged[$lineKey])) {
                if ($merged[$lineKey]['unit_price_minor'] !== $unitPriceMinor) {
                    throw ValidationException::withMessages([
                        'order_context.lines.'.$index.'.unit_price' => 'Aynı parça tek bir birim fiyatla gönderilmelidir.',
                    ]);
                }
                $merged[$lineKey]['quantity_milli'] += $quantityMilli;
                $merged[$lineKey]['physical_stock_total_micros'] = min(
                    $merged[$lineKey]['physical_stock_total_micros'],
                    $physicalStockMicros,
                );
                $merged[$lineKey]['physical_stock_total_snapshot'] = $this->scaledIntegerToFloat(
                    $merged[$lineKey]['physical_stock_total_micros'],
                    self::PHYSICAL_STOCK_SCALE,
                );
                if ($merged[$lineKey]['quantity_milli'] > 1000000000) {
                    throw ValidationException::withMessages([
                        'order_context.lines.'.$index.'.quantity' => 'Toplam parça adedi desteklenen üst sınırı aşmamalıdır.',
                    ]);
                }
                $merged[$lineKey]['line_total_minor'] = intdiv(
                    ($merged[$lineKey]['quantity_milli'] * $unitPriceMinor) + 500,
                    1000,
                );
                if ($merged[$lineKey]['line_total_minor'] > 999999999999) {
                    throw ValidationException::withMessages([
                        'order_context.lines.'.$index.'.unit_price' => 'Parça satır toplamı desteklenen üst sınırı aşmamalıdır.',
                    ]);
                }
                $merged[$lineKey]['quantity'] = $this->scaledIntegerToFloat($merged[$lineKey]['quantity_milli'], 3);
                $merged[$lineKey]['line_total'] = $this->scaledIntegerToFloat($merged[$lineKey]['line_total_minor'], 2);

                continue;
            }

            $serialTrackingState = in_array((string) ($decoded['serial_tracking_state'] ?? ''), ['required', 'not_required'], true)
                ? (string) $decoded['serial_tracking_state']
                : 'unverified';
            $selectedSerial = trim((string) ($rawLine['selected_part_serial'] ?? ''));
            $merged[$lineKey] = [
                'line_key' => $lineKey,
                'item_code' => $itemCode,
                'item_name' => $itemName,
                'item_short_name' => $this->nullableText($decoded['item_short_name'] ?? null),
                'item_kind' => $itemKind,
                'classification_source' => (string) ($decoded['classification_source'] ?? 'no_canonical_evidence'),
                'classification_contract_version' => (string) ($decoded['classification_contract_version'] ?? self::ITEM_CLASSIFICATION_VERSION),
                'quantity_milli' => $quantityMilli,
                'quantity' => $this->scaledIntegerToFloat($quantityMilli, 3),
                'unit_code' => trim((string) ($decoded['unit_code'] ?? 'ADET')) ?: 'ADET',
                'unit_price_minor' => $unitPriceMinor,
                'unit_price' => $this->scaledIntegerToFloat($unitPriceMinor, 2),
                'line_total_minor' => $lineTotalMinor,
                'line_total' => $this->scaledIntegerToFloat($lineTotalMinor, 2),
                'currency' => $currency,
                'warehouse_code' => $this->nullableText($decoded['warehouse_code'] ?? null),
                'stock_source' => $source,
                'stock_source_label' => trim((string) ($decoded['source_label'] ?? '')),
                'stock_freshness_at' => trim((string) ($decoded['freshness_at'] ?? '')),
                'mikro_contract_fingerprint' => $this->nullableText($decoded['mikro_contract_fingerprint'] ?? null),
                'availability_verified' => $physicalStockVerified,
                'physical_stock_verified' => $physicalStockVerified,
                'physical_stock_state' => 'positive',
                'physical_stock_total_micros' => $physicalStockMicros,
                'physical_stock_total_snapshot' => $this->scaledIntegerToFloat($physicalStockMicros, self::PHYSICAL_STOCK_SCALE),
                'physical_stock_contract_version' => (string) ($decoded['physical_stock_contract_version'] ?? MikroResponseSchemaCatalog::PHYSICAL_STOCK_CONTRACT_VERSION),
                'physical_stock_correlation_id' => $this->nullableText($decoded['physical_stock_correlation_id'] ?? null),
                'on_hand' => $decoded['on_hand'] ?? null,
                'reserved' => $decoded['reserved'] ?? null,
                'available' => $decoded['available'] ?? null,
                'serial_tracking_state' => $serialTrackingState,
                'serial_tracking_required' => $serialTrackingState === 'required',
                'selected_part_serial' => $selectedSerial !== '' ? $selectedSerial : null,
                'fixture_tax_profile' => $fixtureTransport && is_array($decoded['tax_profile'] ?? null)
                    ? $decoded['tax_profile']
                    : null,
                'tax_mode_snapshot' => $commercialMode === self::COMMERCIAL_PAID && $deliveryMode === self::DELIVERY_SHIPMENT
                    ? 'standard_from_mikro'
                    : 'none',
                'vat_rate_snapshot' => $commercialMode === self::COMMERCIAL_PAID && $deliveryMode === self::DELIVERY_SHIPMENT
                    ? null
                    : 0.0,
            ];
        }

        foreach ($merged as $line) {
            if (($line['quantity_milli'] * (10 ** (self::PHYSICAL_STOCK_SCALE - 3))) > $line['physical_stock_total_micros']) {
                throw ValidationException::withMessages([
                    'order_context.lines' => 'Stokta yalnız '.$this->quantityLabel((float) $line['physical_stock_total_snapshot']).' '.$line['unit_code'].' bulunuyor.',
                ]);
            }
        }

        return array_values($merged);
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
            'line_key' => hash('sha256', mb_strtoupper($this->nullableText($input['technician_part_code'] ?? null) ?? $name, 'UTF-8')),
            'item_code' => $this->nullableText($input['technician_part_code'] ?? null),
            'item_name' => $name,
            'item_short_name' => null,
            'item_kind' => 'part',
            'classification_source' => 'technician_declaration',
            'classification_contract_version' => self::ITEM_CLASSIFICATION_VERSION,
            'quantity' => $quantity,
            'unit_code' => 'ADET',
            'warehouse_code' => null,
            'stock_source' => 'technician_declaration',
            'stock_source_label' => 'Usta beyanı',
            'stock_freshness_at' => now()->toISOString(),
            'on_hand' => null,
            'reserved' => null,
            'available' => null,
            'availability_verified' => false,
            'serial_tracking_state' => 'not_required',
            'serial_tracking_required' => false,
            'selected_part_serial' => null,
        ];
    }

    /** @param array<string, mixed> $part @return array<string, mixed> */
    private function technicianPartLine(array $part, int $totalMinor, string $currency): array
    {
        $quantityMilli = $this->decimalToScaledInteger($part['quantity'] ?? null, 3, 'order_context.quantity', 1, 1000000000);
        $unitPriceMinor = intdiv(($totalMinor * 1000) + intdiv($quantityMilli, 2), $quantityMilli);

        return [
            ...$part,
            'quantity_milli' => $quantityMilli,
            'unit_price_minor' => $unitPriceMinor,
            'line_total_minor' => $totalMinor,
            'unit_price' => $this->scaledIntegerToFloat($unitPriceMinor, 2),
            'line_total' => $this->scaledIntegerToFloat($totalMinor, 2),
            'currency' => $currency,
            'tax_mode_snapshot' => 'none',
            'vat_rate_snapshot' => 0.0,
        ];
    }

    /** @param array<int, array<string, mixed>> $lines @return array<int, array<string, mixed>> */
    private function applyLineTaxProfiles(
        array $lines,
        string $taxMode,
        bool $strict,
        bool $manualRetry,
    ): array {
        if ($lines === []) {
            return [];
        }

        if ($taxMode === 'none') {
            return array_map(fn (array $line): array => $this->withLineTaxProjection($line, [
                'retail_tax_pointer' => null,
                'retail_tax_rate' => null,
                'wholesale_tax_pointer' => null,
                'wholesale_tax_rate' => null,
                'selected_tax_basis' => 'q_series_zero',
                'selected_tax_pointer' => null,
                'selected_tax_rate' => '0',
                'tax_status' => 'verified',
                'tax_resolution_source' => 'technical_service_commercial_matrix',
                'source' => 'commercial_matrix',
                'freshness_at' => now()->toIso8601String(),
                'contract_version' => 'technical-service-commercial-matrix-v1',
                'correlation_id' => null,
            ]), $lines);
        }

        if ($taxMode !== 'standard_from_mikro') {
            return $lines;
        }

        $fixtureTransport = app()->environment('testing')
            && (bool) config('services.technical_service.payment_order_context_test_stock', false);
        $profiles = $fixtureTransport
            ? collect($lines)->mapWithKeys(function (array $line): array {
                $profile = is_array($line['fixture_tax_profile'] ?? null) ? $line['fixture_tax_profile'] : [];

                return [(string) $line['item_code'] => [
                    ...$profile,
                    'tax_resolution_source' => 'typed_test_fixture',
                    'source' => 'test_fixture',
                    'freshness_at' => now()->toIso8601String(),
                    'contract_version' => 'typed-test-tax-profile-v1',
                    'correlation_id' => null,
                ]];
            })->all()
            : $this->taxProfilesByItemCodes(array_column($lines, 'item_code'), $strict, ! $strict, $manualRetry);

        return array_map(function (array $line) use ($profiles): array {
            $code = mb_strtoupper(trim((string) ($line['item_code'] ?? '')), 'UTF-8');
            $profile = $profiles[$code] ?? $this->unavailableTaxProfile($code, 'MIKRO_TAX_PROFILE_ROW_MISSING');

            return $this->withLineTaxProjection($line, $profile);
        }, $lines);
    }

    /** @param array<string, mixed> $line @param array<string, mixed> $profile @return array<string, mixed> */
    private function withLineTaxProjection(array $line, array $profile): array
    {
        $grossUnitMinor = (int) ($line['unit_price_minor'] ?? 0);
        $grossLineMinor = (int) ($line['line_total_minor'] ?? 0);
        $currency = (string) ($line['currency'] ?? 'TRY');
        $rate = $profile['selected_tax_rate'] ?? null;
        $rateScaled = ($profile['tax_status'] ?? null) === 'verified'
            ? $this->percentageToScaledInteger($rate)
            : null;
        $netMinor = null;
        $vatMinor = null;
        if ($rateScaled !== null) {
            $base = 100 * (10 ** self::TAX_RATE_SCALE);
            $denominator = $base + $rateScaled;
            $netMinor = intdiv(($grossLineMinor * $base) + intdiv($denominator, 2), $denominator);
            $vatMinor = $grossLineMinor - $netMinor;
        }

        return [
            ...$line,
            'gross_unit_price' => $this->scaledIntegerToDecimalString($grossUnitMinor, 2),
            'gross_unit_price_label' => $this->moneyLabelFromMinor($grossUnitMinor, $currency),
            'gross_line_total' => $this->scaledIntegerToDecimalString($grossLineMinor, 2),
            'gross_line_total_label' => $this->moneyLabelFromMinor($grossLineMinor, $currency),
            'net_line_total_minor' => $netMinor,
            'net_line_total' => $netMinor === null ? null : $this->scaledIntegerToDecimalString($netMinor, 2),
            'net_line_total_label' => $netMinor === null ? null : $this->moneyLabelFromMinor($netMinor, $currency),
            'vat_line_total_minor' => $vatMinor,
            'vat_line_total' => $vatMinor === null ? null : $this->scaledIntegerToDecimalString($vatMinor, 2),
            'vat_line_total_label' => $vatMinor === null ? null : $this->moneyLabelFromMinor($vatMinor, $currency),
            'retail_tax_pointer' => $profile['retail_tax_pointer'] ?? null,
            'retail_tax_rate' => $profile['retail_tax_rate'] ?? null,
            'wholesale_tax_pointer' => $profile['wholesale_tax_pointer'] ?? null,
            'wholesale_tax_rate' => $profile['wholesale_tax_rate'] ?? null,
            'selected_tax_basis' => $profile['selected_tax_basis'] ?? null,
            'selected_tax_pointer' => $profile['selected_tax_pointer'] ?? null,
            'selected_tax_rate' => $rate,
            'selected_tax_rate_label' => $rate === null ? null : '%'.str_replace('.', ',', (string) $rate),
            'tax_status' => $profile['tax_status'] ?? 'unavailable',
            'tax_resolution_source' => $profile['tax_resolution_source'] ?? null,
            'tax_source' => $profile['source'] ?? null,
            'tax_freshness_at' => $profile['freshness_at'] ?? null,
            'tax_contract_version' => $profile['contract_version'] ?? null,
            'tax_correlation_id' => $profile['correlation_id'] ?? null,
            'tax_mode_snapshot' => $line['tax_mode_snapshot'] ?? 'standard_from_mikro',
            'vat_rate_snapshot' => $rate,
        ];
    }

    /**
     * @param  array<int, string>  $itemCodes
     * @return array<string, array<string, mixed>>
     */
    private function taxProfilesByItemCodes(
        array $itemCodes,
        bool $strict = false,
        bool $useCache = true,
        bool $manualRetry = false,
    ): array {
        $codes = collect($itemCodes)
            ->map(fn (mixed $code): string => mb_strtoupper(trim((string) $code), 'UTF-8'))
            ->filter()
            ->unique()
            ->sort()
            ->values();
        if ($codes->isEmpty()) {
            return [];
        }
        if ($codes->count() > self::MAX_PART_LINES) {
            throw new DomainException('MIKRO_TAX_PROFILE_BATCH_LIMIT_EXCEEDED');
        }

        $cacheKey = 'technical-service:tax-profile:'
            .MikroResponseSchemaCatalog::STOCK_TAX_PROFILE_CONTRACT_VERSION.':'
            .hash('sha256', json_encode($codes->all(), JSON_THROW_ON_ERROR));
        if ($useCache && ! $manualRetry) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && array_keys($cached) === $codes->all()) {
                return $cached;
            }
        }

        $result = [];
        try {
            $result = $manualRetry
                ? $this->mikro->retryStockTaxProfiles($codes->all())
                : $this->mikro->stockTaxProfiles($codes->all());
            if (($result['success'] ?? false) !== true
                || ($result['stale'] ?? false) === true
                || ($result['fallback_used'] ?? false) === true) {
                throw new DomainException((string) ($result['error_code'] ?? 'MIKRO_TAX_PROFILE_UNAVAILABLE'));
            }

            $expected = array_fill_keys($codes->all(), true);
            $profiles = [];
            foreach (($result['data'] ?? []) as $row) {
                if (! is_array($row)) {
                    throw new DomainException('MIKRO_TAX_PROFILE_SCHEMA_INCOMPLETE');
                }
                $code = mb_strtoupper(trim((string) ($row['item_code'] ?? '')), 'UTF-8');
                if (! isset($expected[$code]) || isset($profiles[$code])) {
                    throw new DomainException('MIKRO_TAX_PROFILE_SCHEMA_INCOMPLETE');
                }
                $profiles[$code] = $row;
            }
            foreach ($codes as $code) {
                $profiles[$code] ??= $this->unavailableTaxProfile($code, 'MIKRO_TAX_PROFILE_ROW_MISSING', $result);
            }
            $profiles = $codes->mapWithKeys(fn (string $code): array => [$code => $profiles[$code]])->all();
            if ($useCache && collect($profiles)->every(fn (array $profile): bool => in_array($profile['tax_status'] ?? null, ['verified', 'unresolved_basis'], true))) {
                Cache::put($cacheKey, $profiles, self::TAX_PROFILE_CACHE_SECONDS);
            }

            return $profiles;
        } catch (Throwable $exception) {
            report($exception);
            if ($strict) {
                throw ValidationException::withMessages([
                    'order_context.lines' => 'KDV doğrulanamadı. Ödeme ve sipariş hazırlığı tamamlanamaz.',
                ]);
            }

            return $codes->mapWithKeys(fn (string $code): array => [
                $code => $this->unavailableTaxProfile($code, (string) ($result['error_code'] ?? $exception->getMessage()), $result),
            ])->all();
        }
    }

    /** @param array<string, mixed> $meta @return array<string, mixed> */
    private function unavailableTaxProfile(string $itemCode, string $errorCode, array $meta = []): array
    {
        return [
            'item_code' => $itemCode,
            'retail_tax_pointer' => null,
            'retail_tax_rate' => null,
            'wholesale_tax_pointer' => null,
            'wholesale_tax_rate' => null,
            'selected_tax_basis' => null,
            'selected_tax_pointer' => null,
            'selected_tax_rate' => null,
            'tax_status' => ($meta['stale'] ?? false) === true ? 'stale' : 'unavailable',
            'tax_resolution_source' => null,
            'source' => 'mikro_api',
            'freshness_at' => $meta['freshness_at'] ?? null,
            'contract_version' => MikroResponseSchemaCatalog::STOCK_TAX_PROFILE_CONTRACT_VERSION,
            'correlation_id' => $meta['correlation_id'] ?? null,
            'error_code' => $errorCode !== '' ? $errorCode : 'MIKRO_TAX_PROFILE_UNAVAILABLE',
        ];
    }

    /** @param array<int, array<string, mixed>> $lines @return array<string, mixed> */
    private function lineTaxTotals(array $lines, string $currency, string $taxMode, int $fallbackGrossMinor): array
    {
        $grossMinor = $lines === []
            ? $fallbackGrossMinor
            : array_sum(array_map(fn (array $line): int => (int) ($line['line_total_minor'] ?? 0), $lines));
        $unresolved = collect($lines)->contains(fn (array $line): bool => ($line['tax_status'] ?? null) === 'unresolved_basis');
        $verified = $taxMode === 'none'
            || ($lines !== [] && collect($lines)->every(
                fn (array $line): bool => ($line['tax_status'] ?? null) === 'verified'
                    && is_int($line['net_line_total_minor'] ?? null)
                    && is_int($line['vat_line_total_minor'] ?? null),
            ));
        $status = $lines === []
            ? ($taxMode === 'none' ? 'verified' : 'not_applicable')
            : ($verified ? 'verified' : ($unresolved ? 'unresolved_basis' : 'unavailable'));
        $netMinor = $verified
            ? ($taxMode === 'none' ? $grossMinor : array_sum(array_column($lines, 'net_line_total_minor')))
            : null;
        $vatMinor = $verified ? $grossMinor - (int) $netMinor : null;
        $rates = collect($lines)
            ->pluck('selected_tax_rate')
            ->filter(fn (mixed $rate): bool => $rate !== null)
            ->map(fn (mixed $rate): string => (string) $rate)
            ->unique()
            ->values();
        $mixedRates = $verified && $rates->count() > 1;
        $cartVatRate = $verified && $rates->count() === 1 ? $rates->first() : ($taxMode === 'none' ? '0' : null);
        $taxSource = $taxMode === 'none' ? 'commercial_matrix' : ($taxMode === 'standard_from_mikro' ? 'mikro_api' : null);
        $taxLabel = match (true) {
            $taxMode === 'none' => 'Yok / %0',
            $status === 'verified' && $mixedRates => 'Satır bazında farklı oranlar · toplam fiyata dahil',
            $status === 'verified' => '%'.str_replace('.', ',', (string) $cartVatRate).' · toplam fiyata dahil',
            $status === 'unresolved_basis' => 'Mikro vergi temeli doğrulanmayı bekliyor',
            default => $this->taxLabel($taxMode),
        };

        return [
            'tax_status' => $status,
            'tax_source' => $taxSource,
            'tax_source_label' => $taxSource === 'mikro_api' ? 'Mikro API' : ($taxSource === 'commercial_matrix' ? 'Ticari karar matrisi' : null),
            'tax_label' => $taxLabel,
            'mixed_vat_rates' => $mixedRates,
            'cart_vat_rate' => $cartVatRate,
            'gross_total' => $this->scaledIntegerToDecimalString($grossMinor, 2),
            'gross_total_label' => $this->moneyLabelFromMinor($grossMinor, $currency),
            'net_total' => $netMinor === null ? null : $this->scaledIntegerToDecimalString($netMinor, 2),
            'net_total_label' => $netMinor === null ? null : $this->moneyLabelFromMinor($netMinor, $currency),
            'vat_total' => $vatMinor === null ? null : $this->scaledIntegerToDecimalString($vatMinor, 2),
            'vat_total_label' => $vatMinor === null ? null : $this->moneyLabelFromMinor($vatMinor, $currency),
        ];
    }

    /** @param array<int, array<string, mixed>> $lines @return array<string, mixed> */
    private function partReadiness(?string $supplier, array $lines, string $taxMode): array
    {
        if ($supplier !== self::SUPPLIER_EMAKS) {
            return [
                'ready' => true,
                'order_ready' => true,
                'payment_ready' => true,
                'blocker_codes' => [],
                'blockers' => [],
            ];
        }

        $blockers = [];
        if (collect($lines)->contains(fn (array $line): bool => ! (bool) ($line['physical_stock_verified'] ?? $line['availability_verified'] ?? false))) {
            $blockers['physical_stock_unverified'] = 'Mikro stok bilgisi doğrulanamadı. Stok doğrulanmadan işlem tamamlanamaz.';
        }
        if (collect($lines)->contains(fn (array $line): bool => (bool) ($line['physical_stock_verified'] ?? $line['availability_verified'] ?? false)
            && is_numeric($line['physical_stock_total_snapshot'] ?? null)
            && (float) $line['physical_stock_total_snapshot'] <= 0)) {
            $blockers['physical_stock_empty'] = 'Seçilen parçalardan en az biri stokta bulunmuyor.';
        }
        if (collect($lines)->contains(fn (array $line): bool => is_numeric($line['physical_stock_total_snapshot'] ?? null)
            && (float) ($line['quantity'] ?? 0) > (float) $line['physical_stock_total_snapshot'])) {
            $blockers['physical_stock_quantity_exceeded'] = 'Seçilen parça adedi doğrulanmış fiziksel stok miktarını aşıyor.';
        }
        if (collect($lines)->contains(fn (array $line): bool => ($line['serial_tracking_state'] ?? 'unverified') === 'unverified')) {
            $blockers['serial_tracking_unverified'] = 'Parça seri takip kuralı doğrulanmadan ödeme ve sipariş hazırlığı tamamlanamaz.';
        }
        if (collect($lines)->contains(fn (array $line): bool => ($line['serial_tracking_state'] ?? null) === 'required'
            && blank($line['selected_part_serial'] ?? null))) {
            $blockers['part_serial_selection_unverified'] = 'Bu parça seri numarasıyla takip ediliyor. Güncel parça seri seçimi doğrulanmadan ödeme/sipariş hazırlığı tamamlanamaz.';
        }
        if ($taxMode === 'standard_from_mikro'
            && collect($lines)->contains(fn (array $line): bool => ($line['tax_status'] ?? null) === 'unresolved_basis')) {
            $blockers['tax_basis_unresolved'] = 'Mikro stok kartında perakende ve toptan KDV oranları farklı. Sipariş vergi temeli doğrulanmadan işlem tamamlanamaz.';
        } elseif ($taxMode === 'standard_from_mikro'
            && collect($lines)->contains(fn (array $line): bool => ($line['tax_status'] ?? null) !== 'verified')) {
            $blockers['vat_unverified'] = 'KDV bilgisi Mikro stok kartından doğrulanmadan ücretli sevk hazırlığı tamamlanamaz.';
        }

        return [
            'ready' => $blockers === [],
            'order_ready' => $blockers === [],
            'payment_ready' => $blockers === [],
            'blocker_codes' => array_keys($blockers),
            'blockers' => array_values($blockers),
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
        $newLineAuthority = is_array($normalized['lines'] ?? null) && $normalized['lines'] !== [];
        $part = ! $newLineAuthority && is_array($normalized['part']) ? $normalized['part'] : [];

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
            'description2_version' => $normalized['description2_version'],
            'context_hash' => $normalized['context_hash'],
            'idempotency_key' => $idempotencyKey,
            'correlation_id' => (string) Str::uuid(),
            'revision' => $revision,
            'created_by' => $actor?->getAuthIdentifier(),
            'metadata' => json_encode([
                'schema_version' => $newLineAuthority ? 2 : 1,
                'request_code' => $normalized['request_code'],
                'root_mrn' => $normalized['root_mrn'],
                'line_count' => count($normalized['lines'] ?? []),
                'tax_projection' => [
                    'status' => $normalized['tax_status'] ?? null,
                    'source' => $normalized['tax_source'] ?? null,
                    'source_label' => $normalized['tax_source_label'] ?? null,
                    'label' => $normalized['tax_label'] ?? null,
                    'mixed_vat_rates' => (bool) ($normalized['mixed_vat_rates'] ?? false),
                    'gross_total' => $normalized['gross_total'] ?? null,
                    'gross_total_label' => $normalized['gross_total_label'] ?? null,
                    'net_total' => $normalized['net_total'] ?? null,
                    'net_total_label' => $normalized['net_total_label'] ?? null,
                    'vat_total' => $normalized['vat_total'] ?? null,
                    'vat_total_label' => $normalized['vat_total_label'] ?? null,
                ],
                'line_tax_profiles' => collect($normalized['lines'] ?? [])->mapWithKeys(
                    fn (array $line): array => [(string) $line['line_key'] => [
                        'retail_tax_pointer' => $line['retail_tax_pointer'] ?? null,
                        'retail_tax_rate' => $line['retail_tax_rate'] ?? null,
                        'wholesale_tax_pointer' => $line['wholesale_tax_pointer'] ?? null,
                        'wholesale_tax_rate' => $line['wholesale_tax_rate'] ?? null,
                        'selected_tax_basis' => $line['selected_tax_basis'] ?? null,
                        'selected_tax_pointer' => $line['selected_tax_pointer'] ?? null,
                        'selected_tax_rate' => $line['selected_tax_rate'] ?? null,
                        'selected_tax_rate_label' => $line['selected_tax_rate_label'] ?? null,
                        'tax_status' => $line['tax_status'] ?? null,
                        'tax_resolution_source' => $line['tax_resolution_source'] ?? null,
                        'tax_source' => $line['tax_source'] ?? null,
                        'tax_freshness_at' => $line['tax_freshness_at'] ?? null,
                        'tax_contract_version' => $line['tax_contract_version'] ?? null,
                        'tax_correlation_id' => $line['tax_correlation_id'] ?? null,
                        'gross_unit_price' => $line['gross_unit_price'] ?? null,
                        'gross_unit_price_label' => $line['gross_unit_price_label'] ?? null,
                        'gross_line_total' => $line['gross_line_total'] ?? null,
                        'gross_line_total_label' => $line['gross_line_total_label'] ?? null,
                        'net_line_total' => $line['net_line_total'] ?? null,
                        'net_line_total_label' => $line['net_line_total_label'] ?? null,
                        'vat_line_total' => $line['vat_line_total'] ?? null,
                        'vat_line_total_label' => $line['vat_line_total_label'] ?? null,
                    ]],
                )->all(),
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

    /** @param array<int, array<string, mixed>> $lines */
    private function insertContextLines(int $contextId, array $lines, ?Authenticatable $actor): void
    {
        if ($lines === []) {
            return;
        }
        $now = now();
        DB::table(self::ITEM_TABLE)->insert(array_map(
            fn (array $line, int $position): array => [
                'context_id' => $contextId,
                'line_key' => $line['line_key'],
                'position' => $position + 1,
                'item_code' => $line['item_code'],
                'item_name_snapshot' => $line['item_name'],
                'item_short_name_snapshot' => $line['item_short_name'] ?? null,
                'item_kind' => $line['item_kind'],
                'classification_source' => $line['classification_source'],
                'classification_contract_version' => $line['classification_contract_version'],
                'unit_code' => $line['unit_code'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'line_total' => $line['line_total'],
                'currency' => $line['currency'],
                'serial_tracking_state' => $line['serial_tracking_state'],
                'selected_part_serial' => $line['selected_part_serial'] ?? null,
                'stock_source' => $line['stock_source'],
                'stock_freshness_at' => filled($line['stock_freshness_at'] ?? null) ? $line['stock_freshness_at'] : null,
                'mikro_contract_fingerprint' => $line['mikro_contract_fingerprint'] ?? null,
                'availability_verified' => (bool) ($line['availability_verified'] ?? false),
                'physical_stock_total_snapshot' => $line['physical_stock_total_snapshot'] ?? null,
                'tax_mode_snapshot' => $line['tax_mode_snapshot'],
                'vat_rate_snapshot' => $line['vat_rate_snapshot'],
                'created_by' => $actor?->getAuthIdentifier(),
                'updated_by' => $actor?->getAuthIdentifier(),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $lines,
            array_keys($lines),
        ));
    }

    private function writePaymentSnapshot(TechnicalServiceMountPayment $payment, object $context): void
    {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $payload['order_context'] = $this->rowProjection($context, false);
        $payload['order_context_id'] = (int) $context->id;
        $payload['order_context_hash'] = (string) $context->context_hash;
        $payment->forceFill(['raw_payload' => $payload])->save();
    }

    /** @return array<string, mixed> */
    private function rowProjection(object $row, bool $includeSelectionTokens = true): array
    {
        $metadata = is_string($row->metadata ?? null)
            ? json_decode((string) $row->metadata, true)
            : (is_array($row->metadata ?? null) ? $row->metadata : []);
        $stockSnapshot = is_array($metadata['stock_snapshot'] ?? null) ? $metadata['stock_snapshot'] : [];
        $taxProjection = is_array($metadata['tax_projection'] ?? null) ? $metadata['tax_projection'] : [];
        $lineTaxProfiles = is_array($metadata['line_tax_profiles'] ?? null) ? $metadata['line_tax_profiles'] : [];
        $lines = DB::table(self::ITEM_TABLE)
            ->where('context_id', (int) $row->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(function (object $line) use ($includeSelectionTokens, $lineTaxProfiles, $row): array {
                $tax = is_array($lineTaxProfiles[(string) $line->line_key] ?? null)
                    ? $lineTaxProfiles[(string) $line->line_key]
                    : [];
                $taxNone = (string) $line->tax_mode_snapshot === 'none';
                $projection = [
                    'id' => (int) $line->id,
                    'line_key' => (string) $line->line_key,
                    'position' => (int) $line->position,
                    'item_code' => (string) $line->item_code,
                    'item_name' => (string) $line->item_name_snapshot,
                    'item_short_name' => $line->item_short_name_snapshot,
                    'item_kind' => (string) $line->item_kind,
                    'classification_source' => (string) $line->classification_source,
                    'classification_contract_version' => (string) $line->classification_contract_version,
                    'unit_code' => $line->unit_code,
                    'quantity' => (float) $line->quantity,
                    'unit_price' => (float) $line->unit_price,
                    'unit_price_label' => $this->moneyLabel((float) $line->unit_price, (string) $line->currency),
                    'line_total' => (float) $line->line_total,
                    'line_total_label' => $this->moneyLabel((float) $line->line_total, (string) $line->currency),
                    'currency' => (string) $line->currency,
                    'warehouse_code' => null,
                    'stock_source' => (string) $line->stock_source,
                    'stock_source_label' => (string) $line->stock_source === 'mikro' ? 'Mikro API' : 'Usta beyanı',
                    'stock_freshness_at' => $line->stock_freshness_at,
                    'mikro_contract_fingerprint' => $line->mikro_contract_fingerprint,
                    'availability_verified' => (bool) $line->availability_verified,
                    'physical_stock_verified' => (bool) $line->availability_verified
                        && is_numeric($line->physical_stock_total_snapshot ?? null),
                    'physical_stock_state' => ! is_numeric($line->physical_stock_total_snapshot ?? null)
                        ? 'unverified'
                        : ((float) $line->physical_stock_total_snapshot > 0 ? 'positive' : 'out_of_stock'),
                    'physical_stock_total' => is_numeric($line->physical_stock_total_snapshot ?? null)
                        ? (float) $line->physical_stock_total_snapshot
                        : null,
                    'physical_stock_total_snapshot' => is_numeric($line->physical_stock_total_snapshot ?? null)
                        ? (float) $line->physical_stock_total_snapshot
                        : null,
                    'physical_stock_total_label' => is_numeric($line->physical_stock_total_snapshot ?? null)
                        ? $this->quantityLabel((float) $line->physical_stock_total_snapshot)
                        : null,
                    'physical_stock_contract_version' => MikroResponseSchemaCatalog::PHYSICAL_STOCK_CONTRACT_VERSION,
                    'stock_status_label' => ! is_numeric($line->physical_stock_total_snapshot ?? null)
                        ? 'Stok yeniden doğrulanmalı'
                        : ((float) $line->physical_stock_total_snapshot > 0
                            ? 'Stokta: '.$this->quantityLabel((float) $line->physical_stock_total_snapshot).' '.((string) ($line->unit_code ?: 'ADET'))
                            : 'Stokta yok'),
                    'on_hand' => null,
                    'reserved' => null,
                    'available' => null,
                    'serial_tracking_state' => (string) $line->serial_tracking_state,
                    'serial_tracking_required' => (string) $line->serial_tracking_state === 'required',
                    'selected_part_serial' => $line->selected_part_serial,
                    'tax_mode_snapshot' => (string) $line->tax_mode_snapshot,
                    'vat_rate_snapshot' => is_numeric($line->vat_rate_snapshot) ? (float) $line->vat_rate_snapshot : null,
                    'gross_unit_price' => $tax['gross_unit_price'] ?? (string) $line->unit_price,
                    'gross_unit_price_label' => $tax['gross_unit_price_label'] ?? $this->moneyLabel((float) $line->unit_price, (string) $line->currency),
                    'gross_line_total' => $tax['gross_line_total'] ?? (string) $line->line_total,
                    'gross_line_total_label' => $tax['gross_line_total_label'] ?? $this->moneyLabel((float) $line->line_total, (string) $line->currency),
                    'net_line_total' => $tax['net_line_total'] ?? ($taxNone ? (string) $line->line_total : null),
                    'net_line_total_label' => $tax['net_line_total_label'] ?? ($taxNone ? $this->moneyLabel((float) $line->line_total, (string) $line->currency) : null),
                    'vat_line_total' => $tax['vat_line_total'] ?? ($taxNone ? '0.00' : null),
                    'vat_line_total_label' => $tax['vat_line_total_label'] ?? ($taxNone ? $this->moneyLabel(0, (string) $line->currency) : null),
                    'retail_tax_pointer' => $tax['retail_tax_pointer'] ?? null,
                    'retail_tax_rate' => $tax['retail_tax_rate'] ?? null,
                    'wholesale_tax_pointer' => $tax['wholesale_tax_pointer'] ?? null,
                    'wholesale_tax_rate' => $tax['wholesale_tax_rate'] ?? null,
                    'selected_tax_basis' => $tax['selected_tax_basis'] ?? ($taxNone ? 'q_series_zero' : null),
                    'selected_tax_pointer' => $tax['selected_tax_pointer'] ?? null,
                    'selected_tax_rate' => $tax['selected_tax_rate'] ?? ($taxNone ? '0' : null),
                    'selected_tax_rate_label' => $tax['selected_tax_rate_label'] ?? ($taxNone ? '%0' : null),
                    'tax_status' => $tax['tax_status'] ?? ($taxNone ? 'verified' : 'unavailable'),
                    'tax_resolution_source' => $tax['tax_resolution_source'] ?? ($taxNone ? 'technical_service_commercial_matrix' : null),
                    'tax_source' => $tax['tax_source'] ?? ($taxNone ? 'commercial_matrix' : null),
                    'tax_freshness_at' => $tax['tax_freshness_at'] ?? null,
                    'tax_contract_version' => $tax['tax_contract_version'] ?? ($taxNone ? 'technical-service-commercial-matrix-v1' : null),
                    'tax_correlation_id' => $tax['tax_correlation_id'] ?? null,
                ];
                if ($includeSelectionTokens
                    && in_array((string) $line->item_kind, ['part', 'accessory'], true)
                    && (string) $line->stock_source === 'mikro') {
                    $physicalStockTotal = is_numeric($line->physical_stock_total_snapshot ?? null)
                        ? (float) $line->physical_stock_total_snapshot
                        : null;
                    $physicalStockVerified = (bool) $line->availability_verified && $physicalStockTotal !== null;
                    $projection['selection_token'] = $this->selectionTokenForSnapshot((int) $row->technical_service_request_id, [
                        'item_code' => (string) $line->item_code,
                        'item_name' => (string) $line->item_name_snapshot,
                        'item_short_name' => $line->item_short_name_snapshot,
                        'item_kind' => (string) $line->item_kind,
                        'item_kind_label' => (string) $line->item_kind === 'accessory' ? 'Aksesuar / sunum ekipmanı' : 'Yedek parça',
                        'classification_source' => (string) $line->classification_source,
                        'classification_contract_version' => (string) $line->classification_contract_version,
                        'selectable' => $physicalStockVerified && $physicalStockTotal > 0,
                        'selection_blocker' => ! $physicalStockVerified ? 'Stok yeniden doğrulanmalı' : ($physicalStockTotal > 0 ? null : 'Stokta yok'),
                        'unit_code' => $line->unit_code,
                        'warehouse_code' => null,
                        'on_hand' => null,
                        'reserved' => null,
                        'available' => null,
                        'availability_verified' => (bool) $line->availability_verified,
                        'physical_stock_verified' => $physicalStockVerified,
                        'physical_stock_state' => ! $physicalStockVerified ? 'unverified' : ($physicalStockTotal > 0 ? 'positive' : 'out_of_stock'),
                        'physical_stock_total' => $physicalStockTotal,
                        'physical_stock_total_label' => $physicalStockTotal !== null ? $this->quantityLabel($physicalStockTotal) : null,
                        'physical_stock_contract_version' => MikroResponseSchemaCatalog::PHYSICAL_STOCK_CONTRACT_VERSION,
                        'physical_stock_correlation_id' => null,
                        'stock_status_label' => ! $physicalStockVerified
                            ? 'Stok yeniden doğrulanmalı'
                            : ($physicalStockTotal > 0
                                ? 'Stokta: '.$this->quantityLabel($physicalStockTotal).' '.((string) ($line->unit_code ?: 'ADET'))
                                : 'Stokta yok'),
                        'serial_tracking_state' => (string) $line->serial_tracking_state,
                        'serial_tracking_required' => (string) $line->serial_tracking_state === 'required',
                        'serials' => [],
                        'source' => (string) $line->stock_source,
                        'source_label' => (string) $line->stock_source === 'mikro' ? 'Mikro API' : 'Usta beyanı',
                        'freshness_at' => $line->stock_freshness_at,
                        'mikro_contract_fingerprint' => $line->mikro_contract_fingerprint,
                    ]);
                }

                return $projection;
            })
            ->values()
            ->all();
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
        $schemaVersion = (int) ($metadata['schema_version'] ?? 0);
        $hasLineAuthority = (string) $row->payment_purpose === self::PURPOSE_PART_CHARGE
            && $schemaVersion >= 2;
        $legacyPart = filled($row->item_name_snapshot)
            ? [
                'line_key' => hash('sha256', mb_strtoupper((string) ($row->item_code ?: $row->item_name_snapshot), 'UTF-8')),
                'item_code' => $row->item_code,
                'item_name' => $row->item_name_snapshot,
                'item_short_name' => null,
                'item_kind' => 'part',
                'classification_source' => 'legacy_single_item_context',
                'classification_contract_version' => 'legacy-v1',
                'quantity' => (float) $row->quantity,
                'unit_code' => $row->unit_code,
                'unit_price' => (float) $row->order_line_unit_price,
                'unit_price_label' => $this->moneyLabel((float) $row->order_line_unit_price, (string) $row->currency),
                'line_total' => (float) $row->order_line_total,
                'line_total_label' => $this->moneyLabel((float) $row->order_line_total, (string) $row->currency),
                'currency' => (string) $row->currency,
                'warehouse_code' => $row->warehouse_code,
                'stock_source' => $row->stock_source,
                'stock_source_label' => $stockSnapshot['source_label'] ?? null,
                'stock_freshness_at' => $row->stock_freshness_at,
                'on_hand' => $stockSnapshot['on_hand'] ?? null,
                'reserved' => $stockSnapshot['reserved'] ?? null,
                'available' => $stockSnapshot['available'] ?? null,
                'availability_verified' => $stockSnapshot !== [],
                'serial_tracking_state' => (bool) $row->part_serial_tracking_required ? 'required' : 'not_required',
                'serial_tracking_required' => (bool) $row->part_serial_tracking_required,
                'selected_part_serial' => $row->selected_part_serial,
                'tax_mode_snapshot' => (string) $row->tax_mode,
                'vat_rate_snapshot' => is_numeric($row->vat_rate) ? (float) $row->vat_rate : null,
            ]
            : null;
        if (! $hasLineAuthority && $lines === [] && $legacyPart !== null) {
            $lines = [$legacyPart];
        }
        $part = $lines[0] ?? null;
        $readiness = $hasLineAuthority
            ? ($lines === []
                ? [
                    'ready' => false,
                    'order_ready' => false,
                    'payment_ready' => false,
                    'blocker_codes' => ['part_lines_empty'],
                    'blockers' => ['Parça taslağında seçili kalem bulunmuyor.'],
                    'legacy_context' => false,
                ]
                : [
                    ...$this->partReadiness((string) $row->part_supplier, $lines, (string) $row->tax_mode),
                    'legacy_context' => false,
                ])
            : [
                'ready' => true,
                'order_ready' => true,
                'payment_ready' => true,
                'blocker_codes' => [],
                'blockers' => [],
                'legacy_context' => true,
            ];

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
            'tax_label' => $taxProjection['label'] ?? $this->taxLabel((string) $row->tax_mode),
            'tax_status' => $taxProjection['status'] ?? ((string) $row->tax_mode === 'none' ? 'verified' : 'unavailable'),
            'tax_source' => $taxProjection['source'] ?? ((string) $row->tax_mode === 'none' ? 'commercial_matrix' : null),
            'tax_source_label' => $taxProjection['source_label'] ?? ((string) $row->tax_mode === 'none' ? 'Ticari karar matrisi' : null),
            'mixed_vat_rates' => (bool) ($taxProjection['mixed_vat_rates'] ?? false),
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
            'lines' => $lines,
            'line_count' => count($lines),
            'total_quantity' => array_sum(array_map(fn (array $line): float => (float) ($line['quantity'] ?? 0), $lines)),
            'total_quantity_label' => $this->quantityLabel(array_sum(array_map(fn (array $line): float => (float) ($line['quantity'] ?? 0), $lines))),
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
            'order_reference_total' => (float) $row->order_line_total,
            'order_reference_total_label' => $this->moneyLabel((float) $row->order_line_total, (string) $row->currency),
            'gross_total' => $taxProjection['gross_total'] ?? (string) $row->order_line_total,
            'gross_total_label' => $taxProjection['gross_total_label'] ?? $this->moneyLabel((float) $row->order_line_total, (string) $row->currency),
            'net_total' => $taxProjection['net_total'] ?? ((string) $row->tax_mode === 'none' ? (string) $row->order_line_total : null),
            'net_total_label' => $taxProjection['net_total_label'] ?? ((string) $row->tax_mode === 'none' ? $this->moneyLabel((float) $row->order_line_total, (string) $row->currency) : null),
            'vat_total' => $taxProjection['vat_total'] ?? ((string) $row->tax_mode === 'none' ? '0.00' : null),
            'vat_total_label' => $taxProjection['vat_total_label'] ?? ((string) $row->tax_mode === 'none' ? $this->moneyLabel(0, (string) $row->currency) : null),
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
            'readiness' => $readiness,
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

        $partLines = is_array($context['lines'] ?? null) ? $context['lines'] : [];
        $shipping = $context['shipping'];
        $lines = [];
        if ($context['shipment_required'] && ! $context['shipping_same_as_billing']) {
            $lines[] = 'SEVK ADRESİ FARKLIDIR.';
        }
        $lines[] = 'MRN/SRV: '.$requestCode;
        $lines[] = 'İLGİLİ ÜRÜN SERİ NO: '.$context['related_product_serial'];

        if ($context['context_type'] === 'technician_supplied_part') {
            $part = $partLines[0] ?? [];
            $lines[] = 'PARÇA: '.trim(implode(' - ', array_filter([
                $part['item_code'] ?? null,
                $part['item_name'] ?? null,
            ], fn (mixed $value): bool => filled($value))));
            $lines[] = 'ADET: '.$this->quantityLabel((float) ($part['quantity'] ?? 0));
            $lines[] = 'TEDARİK: USTA';
            $lines[] = 'MİKRO PARÇA SİPARİŞİ: YOK';
            $lines[] = 'SEVKİYAT: YOK';
            $lines[] = 'FATURA MÜŞTERİSİ: '.$billing['name_or_title'];

            return implode("\n", $lines);
        }

        $lines[] = '';
        $lines[] = 'PARÇALAR:';
        foreach ($partLines as $index => $partLine) {
            $lines[] = ($index + 1).'. '.$this->quantityLabel((float) ($partLine['quantity'] ?? 0)).' '
                .((string) ($partLine['unit_code'] ?? 'ADET')).' · '
                .((string) ($partLine['item_code'] ?? '-')).' · '
                .((string) ($partLine['item_name'] ?? '-'));
            $lines[] = '   BİRİM TUTAR (KDV DAHİL): '.($partLine['gross_unit_price_label'] ?? $this->moneyLabel((float) ($partLine['unit_price'] ?? 0), (string) $context['currency']));
            $lines[] = '   SATIR TOPLAMI (KDV DAHİL): '.($partLine['gross_line_total_label'] ?? $this->moneyLabel((float) ($partLine['line_total'] ?? 0), (string) $context['currency']));
            if (($partLine['tax_status'] ?? null) === 'verified') {
                $lines[] = '   KDV ORANI: '.($partLine['selected_tax_rate_label'] ?? '%0');
                $lines[] = '   MATRAH: '.($partLine['net_line_total_label'] ?? '-');
                $lines[] = '   KDV TUTARI: '.($partLine['vat_line_total_label'] ?? '-');
            } else {
                $lines[] = '   KDV: DOĞRULANMAYI BEKLİYOR';
            }
            $lines[] = '';
        }
        $lines[] = 'PARÇA KALEMİ: '.count($partLines);
        $lines[] = 'TOPLAM ADET: '.$this->quantityLabel((float) ($context['total_quantity'] ?? 0));
        $lines[] = 'SİPARİŞ/REFERANS TOPLAMI (KDV DAHİL): '.$context['order_reference_total_label'];
        $lines[] = 'MÜŞTERİDEN TAHSİL EDİLECEK: '.$context['collection_amount_label'];
        if (($context['net_total_label'] ?? null) !== null && ($context['vat_total_label'] ?? null) !== null) {
            $lines[] = 'KDV HARİÇ TOPLAM: '.$context['net_total_label'];
            $lines[] = 'KDV TOPLAMI: '.$context['vat_total_label'];
            $lines[] = 'KDV TOPLAMA DAHİLDİR.';
        }
        $lines[] = 'TİCARİ DURUM: '.($context['commercial_mode'] === self::COMMERCIAL_FREE ? 'ÜCRETSİZ' : 'ÜCRETLİ');
        $lines[] = 'TESLİM: '.($context['delivery_mode'] === self::DELIVERY_HAND ? 'ELDEN' : 'SEVK');
        $lines[] = 'HEDEF SERİ: '.($context['desired_mikro_series'] ?? '-');
        $lines[] = 'KDV: '.mb_strtoupper((string) ($context['tax_label'] ?? ($context['tax_mode'] === 'none' ? 'YOK' : 'DOĞRULANMAYI BEKLİYOR')), 'UTF-8');
        if ($context['delivery_mode'] === self::DELIVERY_HAND) {
            $lines[] = $context['commercial_mode'] === self::COMMERCIAL_FREE
                ? 'TAHSİLAT: GEREKMİYOR'
                : 'ÖDEME DURUMU: '.mb_strtoupper($this->paymentStatusLabel((string) $context['payment_status']), 'UTF-8');
        } elseif ($context['shipment_required']) {
            if ($context['commercial_mode'] === self::COMMERCIAL_FREE) {
                $lines[] = 'TAHSİLAT: GEREKMİYOR';
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
                'item_short_name' => null,
                'item_kind' => 'part',
                'item_kind_label' => 'Yedek parça',
                'classification_source' => 'test_fixture',
                'classification_contract_version' => self::ITEM_CLASSIFICATION_VERSION,
                'selectable' => true,
                'selection_blocker' => null,
                'unit_code' => 'ADET',
                'warehouse_code' => 'MERKEZ',
                'on_hand' => 24.0,
                'reserved' => 3.0,
                'available' => 21.0,
                'availability_verified' => true,
                'physical_stock_state' => 'positive',
                'physical_stock_verified' => true,
                'physical_stock_total' => 24.0,
                'physical_stock_total_label' => '24',
                'physical_stock_warehouses' => [
                    ['warehouse_code' => 1, 'physical_quantity' => 20.0],
                    ['warehouse_code' => 5, 'physical_quantity' => 4.0],
                ],
                'physical_stock_contract_version' => 'test-fixture-v1',
                'physical_stock_correlation_id' => null,
                'stock_status_label' => 'Stokta: 24 ADET',
                'serial_tracking_required' => false,
                'serial_tracking_state' => 'not_required',
                'serials' => [],
                'source' => 'test_fixture',
                'source_label' => 'Test verisi',
                'freshness_at' => $freshness,
                'tax_profile' => [
                    'retail_tax_pointer' => 7,
                    'retail_tax_rate' => '20',
                    'wholesale_tax_pointer' => 7,
                    'wholesale_tax_rate' => '20',
                    'selected_tax_basis' => 'equal_rates',
                    'selected_tax_pointer' => 7,
                    'selected_tax_rate' => '20',
                    'tax_status' => 'verified',
                ],
            ],
            [
                'item_code' => 'TS-PART-002',
                'item_name' => 'Akıllı Kilit Motor Modülü',
                'item_short_name' => null,
                'item_kind' => 'part',
                'item_kind_label' => 'Yedek parça',
                'classification_source' => 'test_fixture',
                'classification_contract_version' => self::ITEM_CLASSIFICATION_VERSION,
                'selectable' => true,
                'selection_blocker' => null,
                'unit_code' => 'ADET',
                'warehouse_code' => 'MERKEZ',
                'on_hand' => 6.0,
                'reserved' => 1.0,
                'available' => 5.0,
                'availability_verified' => true,
                'physical_stock_state' => 'positive',
                'physical_stock_verified' => true,
                'physical_stock_total' => 6.0,
                'physical_stock_total_label' => '6',
                'physical_stock_warehouses' => [
                    ['warehouse_code' => 1, 'physical_quantity' => 5.0],
                    ['warehouse_code' => 5, 'physical_quantity' => 1.0],
                ],
                'physical_stock_contract_version' => 'test-fixture-v1',
                'physical_stock_correlation_id' => null,
                'stock_status_label' => 'Stokta: 6 ADET',
                'serial_tracking_required' => true,
                'serial_tracking_state' => 'required',
                'serials' => ['TSP-2026-0001', 'TSP-2026-0002', 'TSP-2026-0003'],
                'source' => 'test_fixture',
                'source_label' => 'Test verisi',
                'freshness_at' => $freshness,
                'tax_profile' => [
                    'retail_tax_pointer' => 8,
                    'retail_tax_rate' => '10',
                    'wholesale_tax_pointer' => 8,
                    'wholesale_tax_rate' => '10',
                    'selected_tax_basis' => 'equal_rates',
                    'selected_tax_pointer' => 8,
                    'selected_tax_rate' => '10',
                    'tax_status' => 'verified',
                ],
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

    /** @return array<string, mixed> */
    private function stockSearchFailure(array $result, string $fallbackError): array
    {
        $errorCode = trim((string) ($result['error_code'] ?? $fallbackError));

        return [
            'source' => 'mikro',
            'source_label' => 'Mikro API',
            'freshness_at' => $result['freshness_at'] ?? null,
            'search_state' => 'unavailable',
            'physical_stock_state' => 'not_requested',
            'error_code' => $errorCode !== '' ? $errorCode : 'MIKRO_STOCK_READ_UNAVAILABLE',
            'error_message' => 'Mikro stok araması şu anda yapılamıyor. Stok aramasını yeniden dene.',
            'correlation_id' => $result['correlation_id'] ?? null,
            'circuit_state' => (string) ($result['circuit_state'] ?? 'CLOSED'),
            'fallback_used' => (bool) ($result['fallback_used'] ?? false),
            'items' => [],
        ];
    }

    /** @param array<string, mixed> $item @param array<string, mixed>|null $tokenItem @return array<string, mixed> */
    private function partSearchItem(TechnicalServiceRequest $request, array $item, ?array $tokenItem = null): array
    {
        return [
            ...$item,
            'selection_token' => $this->selectionTokenForSnapshot((int) $request->id, $tokenItem ?? $item),
        ];
    }

    /** @param array<string, mixed> $item */
    private function selectionTokenForSnapshot(int $requestId, array $item): string
    {
        return Crypt::encryptString(json_encode([
            'schema_version' => 1,
            'request_id' => $requestId,
            ...$item,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<int, string> $itemCodes @return array<string, true> */
    private function panelDeviceCodes(array $itemCodes): array
    {
        $codes = collect($itemCodes)
            ->map(fn (string $code): string => mb_strtoupper(trim($code), 'UTF-8'))
            ->filter()
            ->unique()
            ->values();
        if ($codes->isEmpty()) {
            return [];
        }

        return DB::table('panel.support_activation_codes')
            ->whereIn(DB::raw('UPPER(TRIM(stock_code))'), $codes->all())
            ->pluck('stock_code')
            ->mapWithKeys(fn (mixed $code): array => [mb_strtoupper(trim((string) $code), 'UTF-8') => true])
            ->all();
    }

    /** @param array<string, mixed> $row @param array<string, true> $deviceCodes @return array<string, mixed> */
    private function classifyStockItem(array $row, array $deviceCodes): array
    {
        $stockType = filter_var($row['stock_type'] ?? null, FILTER_VALIDATE_INT);
        $itemCode = mb_strtoupper(trim((string) ($row['item_code'] ?? '')), 'UTF-8');
        if ($stockType === 8) {
            return [
                'item_kind' => 'part',
                'item_kind_label' => 'Yedek parça',
                'classification_source' => 'mikro_stock_type',
                'classification_contract_version' => self::ITEM_CLASSIFICATION_VERSION,
                'selectable' => true,
                'selection_blocker' => null,
            ];
        }
        if ($stockType === 6) {
            return [
                'item_kind' => 'accessory',
                'item_kind_label' => 'Aksesuar / sunum ekipmanı',
                'classification_source' => 'mikro_stock_type',
                'classification_contract_version' => self::ITEM_CLASSIFICATION_VERSION,
                'selectable' => true,
                'selection_blocker' => null,
            ];
        }
        if (isset($deviceCodes[$itemCode])) {
            return [
                'item_kind' => 'device',
                'item_kind_label' => 'Cihaz / ürün',
                'classification_source' => 'panel_product_catalog',
                'classification_contract_version' => self::ITEM_CLASSIFICATION_VERSION,
                'selectable' => false,
                'selection_blocker' => 'Bu stok cihaz ekleme akışına aittir; parça ödemesine eklenemez.',
            ];
        }

        return [
            'item_kind' => 'unknown',
            'item_kind_label' => 'Stok türü doğrulanmadı',
            'classification_source' => 'no_canonical_evidence',
            'classification_contract_version' => self::ITEM_CLASSIFICATION_VERSION,
            'selectable' => false,
            'selection_blocker' => 'Türü doğrulanmadan bu kayıt parça olarak seçilemez.',
        ];
    }

    /** @param array<string, mixed> $item @param array<string, array<string, mixed>> $stockByCode @return array<string, mixed> */
    private function applyPhysicalStockProjection(array $item, array $stockByCode): array
    {
        if (($item['selectable'] ?? false) !== true) {
            return $item;
        }

        $key = mb_strtoupper(trim((string) ($item['item_code'] ?? '')), 'UTF-8');
        $stock = $stockByCode[$key] ?? $this->unverifiedPhysicalStock($key);
        $verified = ($stock['physical_stock_verified'] ?? false) === true;
        $total = $stock['physical_stock_total'] ?? null;
        $positive = $verified && is_numeric($total) && (float) $total > 0;

        return [
            ...$item,
            'unit_code' => filled($item['unit_code'] ?? null) ? $item['unit_code'] : ($stock['unit_code'] ?? null),
            'availability_verified' => $verified,
            'physical_stock_state' => (string) ($stock['physical_stock_state'] ?? 'unverified'),
            'physical_stock_verified' => $verified,
            'physical_stock_total' => $total,
            'physical_stock_total_label' => $stock['physical_stock_total_label'] ?? null,
            'physical_stock_warehouses' => $stock['physical_stock_warehouses'] ?? [],
            'physical_stock_contract_version' => MikroResponseSchemaCatalog::PHYSICAL_STOCK_CONTRACT_VERSION,
            'physical_stock_correlation_id' => $stock['physical_stock_correlation_id'] ?? null,
            'physical_stock_error_code' => $stock['physical_stock_error_code'] ?? null,
            'stock_status_label' => $positive
                ? 'Stokta: '.($stock['physical_stock_total_label'] ?? $total).' '.((string) ($item['unit_code'] ?: $stock['unit_code'] ?: 'ADET'))
                : ($verified ? 'Stokta yok' : 'Stok doğrulanamadı'),
            'selectable' => $positive,
            'selection_blocker' => $positive ? null : ($verified ? 'Stokta yok' : 'Stok doğrulanamadı'),
            'freshness_at' => $stock['freshness_at'] ?? $item['freshness_at'],
            'mikro_contract_fingerprint' => MikroResponseSchemaCatalog::PHYSICAL_STOCK_RESPONSE_SCHEMA_FINGERPRINT,
        ];
    }

    /**
     * @param  array<int, string>  $itemCodes
     * @return array<string, array<string, mixed>>
     */
    private function physicalStockByItemCodes(
        array $itemCodes,
        bool $strict = false,
        bool $useCache = true,
        bool $manualRetry = false,
        ?array &$operationMeta = null,
    ): array {
        $codes = collect($itemCodes)
            ->map(fn (mixed $code): string => mb_strtoupper(trim((string) $code), 'UTF-8'))
            ->filter()
            ->unique()
            ->sort()
            ->values();
        if ($codes->isEmpty()) {
            $operationMeta = [
                'state' => 'current',
                'error_code' => null,
                'correlation_id' => null,
                'circuit_state' => 'CLOSED',
                'fallback_used' => false,
                'freshness_at' => now()->toISOString(),
            ];

            return [];
        }
        if ($codes->count() > self::MAX_PART_LINES) {
            throw new DomainException('MIKRO_PHYSICAL_STOCK_BATCH_LIMIT_EXCEEDED');
        }

        $cacheKey = 'technical-service:physical-stock:'
            .MikroResponseSchemaCatalog::PHYSICAL_STOCK_CONTRACT_VERSION.':'
            .hash('sha256', json_encode($codes->all(), JSON_THROW_ON_ERROR));

        if ($useCache) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                $cachedKeys = array_keys($cached);
                sort($cachedKeys, SORT_STRING);
                if ($cachedKeys === $codes->all()) {
                    $first = collect($cached)->first(fn (mixed $row): bool => is_array($row));
                    $operationMeta = [
                        'state' => 'current',
                        'error_code' => null,
                        'correlation_id' => is_array($first) ? ($first['physical_stock_correlation_id'] ?? null) : null,
                        'circuit_state' => 'CACHE',
                        'fallback_used' => false,
                        'freshness_at' => is_array($first) ? ($first['freshness_at'] ?? null) : null,
                    ];

                    return $codes
                        ->mapWithKeys(fn (string $code): array => [$code => $cached[$code]])
                        ->all();
                }
            }
        }

        $result = [];
        try {
            $result = $manualRetry
                ? $this->mikro->retryPhysicalStockQuantities($codes->all())
                : $this->mikro->physicalStockQuantities($codes->all());
            $operationMeta = [
                'state' => 'unavailable',
                'error_code' => $result['error_code'] ?? null,
                'correlation_id' => $result['correlation_id'] ?? null,
                'circuit_state' => (string) ($result['circuit_state'] ?? 'CLOSED'),
                'fallback_used' => (bool) ($result['fallback_used'] ?? false),
                'freshness_at' => $result['freshness_at'] ?? null,
            ];
            if (($result['success'] ?? false) !== true
                || ($result['stale'] ?? false) === true
                || ($result['fallback_used'] ?? false) === true) {
                throw new DomainException((string) ($result['error_code'] ?? 'MIKRO_PHYSICAL_STOCK_UNAVAILABLE'));
            }

            $expected = array_fill_keys($codes->all(), true);
            $grouped = [];
            foreach (($result['data'] ?? []) as $row) {
                if (! is_array($row)) {
                    throw new DomainException('MIKRO_PHYSICAL_STOCK_SCHEMA_INCOMPLETE');
                }
                $itemCode = mb_strtoupper(trim((string) ($row['item_code'] ?? '')), 'UTF-8');
                $warehouseCode = filter_var($row['warehouse_code'] ?? null, FILTER_VALIDATE_INT);
                if (! isset($expected[$itemCode]) || ! in_array($warehouseCode, self::PHYSICAL_STOCK_WAREHOUSES, true)) {
                    throw new DomainException('MIKRO_PHYSICAL_STOCK_SCHEMA_INCOMPLETE');
                }
                if (isset($grouped[$itemCode][$warehouseCode])) {
                    throw new DomainException('MIKRO_PHYSICAL_STOCK_DUPLICATE_WAREHOUSE');
                }
                $grouped[$itemCode][$warehouseCode] = [
                    'physical_stock_micros' => $this->signedDecimalToScaledInteger($row['physical_quantity'] ?? null),
                    'unit_code' => $this->nullableText($row['unit_code'] ?? null),
                ];
            }

            $resolved = [];
            $missingCodes = [];
            foreach ($codes as $code) {
                $warehouseRows = $grouped[$code] ?? [];
                ksort($warehouseRows, SORT_NUMERIC);
                if (array_keys($warehouseRows) !== self::PHYSICAL_STOCK_WAREHOUSES
                    || collect($warehouseRows)->contains(fn (array $row): bool => $row['physical_stock_micros'] === null)) {
                    $missingCodes[] = $code;
                    $resolved[$code] = $this->unverifiedPhysicalStock($code, [
                        'error_code' => 'MIKRO_PHYSICAL_STOCK_ROW_MISSING',
                        'correlation_id' => $result['correlation_id'] ?? null,
                        'freshness_at' => $result['freshness_at'] ?? null,
                    ]);

                    continue;
                }
                $totalMicros = array_sum(array_column($warehouseRows, 'physical_stock_micros'));
                $total = $this->scaledIntegerToDecimalString($totalMicros, self::PHYSICAL_STOCK_SCALE);
                $unitCodes = collect($warehouseRows)->pluck('unit_code')->filter()->unique()->values();
                if ($unitCodes->count() > 1) {
                    throw new DomainException('MIKRO_PHYSICAL_STOCK_UNIT_MISMATCH');
                }
                $resolved[$code] = [
                    'item_code' => $code,
                    'unit_code' => $unitCodes->first(),
                    'physical_stock_state' => $totalMicros > 0 ? 'positive' : 'out_of_stock',
                    'physical_stock_verified' => true,
                    'physical_stock_total' => $total,
                    'physical_stock_total_micros' => $totalMicros,
                    'physical_stock_total_label' => $this->quantityLabel((float) $total),
                    'physical_stock_warehouses' => collect(self::PHYSICAL_STOCK_WAREHOUSES)
                        ->map(fn (int $warehouse): array => [
                            'warehouse_code' => $warehouse,
                            'physical_quantity' => $this->scaledIntegerToDecimalString(
                                (int) $warehouseRows[$warehouse]['physical_stock_micros'],
                                self::PHYSICAL_STOCK_SCALE,
                            ),
                        ])
                        ->all(),
                    'source' => 'mikro_api',
                    'freshness_at' => (string) ($result['freshness_at'] ?? now()->toISOString()),
                    'physical_stock_contract_version' => MikroResponseSchemaCatalog::PHYSICAL_STOCK_CONTRACT_VERSION,
                    'physical_stock_correlation_id' => $this->nullableText($result['correlation_id'] ?? null),
                    'physical_stock_error_code' => null,
                ];
            }

            $operationMeta = [
                'state' => $missingCodes === [] ? 'current' : 'partial',
                'error_code' => $missingCodes === [] ? null : 'MIKRO_PHYSICAL_STOCK_ROW_MISSING',
                'correlation_id' => $result['correlation_id'] ?? null,
                'circuit_state' => (string) ($result['circuit_state'] ?? 'CLOSED'),
                'fallback_used' => false,
                'freshness_at' => $result['freshness_at'] ?? null,
            ];
            if ($useCache && $missingCodes === []) {
                Cache::put($cacheKey, $resolved, self::PHYSICAL_STOCK_CACHE_SECONDS);
            }

            return $resolved;
        } catch (Throwable $exception) {
            report($exception);
            $errorCode = trim((string) ($result['error_code'] ?? $exception->getMessage()));
            $operationMeta = [
                'state' => 'unavailable',
                'error_code' => $errorCode !== '' ? $errorCode : 'MIKRO_PHYSICAL_STOCK_UNAVAILABLE',
                'correlation_id' => $result['correlation_id'] ?? null,
                'circuit_state' => (string) ($result['circuit_state'] ?? 'CLOSED'),
                'fallback_used' => (bool) ($result['fallback_used'] ?? false),
                'freshness_at' => $result['freshness_at'] ?? null,
            ];
            if ($strict) {
                throw ValidationException::withMessages([
                    'order_context.lines' => 'Mikro stok bilgisi doğrulanamadı. Stok doğrulanmadan işlem tamamlanamaz.',
                ]);
            }

            return $codes
                ->mapWithKeys(fn (string $code): array => [$code => $this->unverifiedPhysicalStock($code, $operationMeta)])
                ->all();
        }
    }

    /** @param array<string, mixed> $meta @return array<string, mixed> */
    private function unverifiedPhysicalStock(string $itemCode, array $meta = []): array
    {
        return [
            'item_code' => $itemCode,
            'unit_code' => null,
            'physical_stock_state' => 'unverified',
            'physical_stock_verified' => false,
            'physical_stock_total' => null,
            'physical_stock_total_micros' => null,
            'physical_stock_total_label' => null,
            'physical_stock_warehouses' => [],
            'source' => 'mikro_api',
            'freshness_at' => $meta['freshness_at'] ?? null,
            'physical_stock_contract_version' => MikroResponseSchemaCatalog::PHYSICAL_STOCK_CONTRACT_VERSION,
            'physical_stock_correlation_id' => $this->nullableText($meta['correlation_id'] ?? null),
            'physical_stock_error_code' => $meta['error_code'] ?? null,
        ];
    }

    /** @param array<int, array<string, mixed>> $lines @return array<int, array<string, mixed>> */
    private function revalidatePhysicalStockLines(array $lines): array
    {
        if ($lines === [] || (app()->environment('testing')
            && (bool) config('services.technical_service.payment_order_context_test_stock', false))) {
            return $lines;
        }

        $stockByCode = $this->physicalStockByItemCodes(
            array_column($lines, 'item_code'),
            true,
            false,
        );

        return array_map(function (array $line) use ($stockByCode): array {
            $code = mb_strtoupper(trim((string) ($line['item_code'] ?? '')), 'UTF-8');
            $stock = $stockByCode[$code] ?? null;
            if (! is_array($stock) || ($stock['physical_stock_verified'] ?? false) !== true) {
                throw ValidationException::withMessages([
                    'order_context.lines' => 'Mikro stok bilgisi doğrulanamadı. Stok doğrulanmadan işlem tamamlanamaz.',
                ]);
            }
            $stockMicros = (int) $stock['physical_stock_total_micros'];
            if ($stockMicros <= 0) {
                throw ValidationException::withMessages([
                    'order_context.lines' => $code.' için stok bulunmuyor. İşlem tamamlanamaz.',
                ]);
            }
            $quantityMilli = $this->decimalToScaledInteger(
                $line['quantity'] ?? null,
                3,
                'order_context.lines',
                1,
                1000000000,
            );
            if (($quantityMilli * (10 ** (self::PHYSICAL_STOCK_SCALE - 3))) > $stockMicros) {
                throw ValidationException::withMessages([
                    'order_context.lines' => $code.' için talep edilen miktar stoktan fazla. Stok: '
                        .$stock['physical_stock_total_label'].' · Talep: '.$this->quantityLabel((float) $line['quantity']),
                ]);
            }

            return [
                ...$line,
                'availability_verified' => true,
                'physical_stock_verified' => true,
                'physical_stock_state' => 'positive',
                'physical_stock_total_micros' => $stockMicros,
                'physical_stock_total_snapshot' => $stock['physical_stock_total'],
                'physical_stock_contract_version' => MikroResponseSchemaCatalog::PHYSICAL_STOCK_CONTRACT_VERSION,
                'physical_stock_correlation_id' => $stock['physical_stock_correlation_id'],
                'stock_freshness_at' => $stock['freshness_at'],
                'mikro_contract_fingerprint' => MikroResponseSchemaCatalog::PHYSICAL_STOCK_RESPONSE_SCHEMA_FINGERPRINT,
            ];
        }, $lines);
    }

    private function revalidateStoredContextStock(object $context): void
    {
        $stored = DB::table(self::TABLE)->where('id', (int) $context->id)->first();
        if (! $stored || (string) $stored->part_supplier !== self::SUPPLIER_EMAKS) {
            return;
        }

        $projection = $this->rowProjection($stored, false);
        $this->revalidatePhysicalStockLines(is_array($projection['lines'] ?? null) ? $projection['lines'] : []);
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

    private function decimalToScaledInteger(
        mixed $value,
        int $scale,
        string $field,
        int $minimum,
        int $maximum,
    ): int {
        if (! is_scalar($value)) {
            throw ValidationException::withMessages([$field => 'Geçerli bir sayısal değer girin.']);
        }
        $normalized = str_replace(',', '.', trim((string) $value));
        if (! preg_match('/^\d+(?:\.\d{1,'.$scale.'})?$/', $normalized)) {
            throw ValidationException::withMessages([$field => 'En fazla '.$scale.' ondalık basamak içeren pozitif bir değer girin.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $fraction = str_pad($fraction, $scale, '0');
        if (strlen($whole) > 12) {
            throw ValidationException::withMessages([$field => 'Değer desteklenen üst sınırı aşmamalıdır.']);
        }
        $scaled = ((int) $whole * (10 ** $scale)) + (int) $fraction;
        if ($scaled < $minimum || $scaled > $maximum) {
            throw ValidationException::withMessages([$field => 'Değer desteklenen aralıkta olmalıdır.']);
        }

        return $scaled;
    }

    private function signedDecimalToScaledInteger(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (! is_scalar($value)) {
            throw new DomainException('MIKRO_PHYSICAL_STOCK_QUANTITY_INVALID');
        }
        $normalized = trim((string) $value);
        if (! preg_match('/^-?\d+(?:\.\d{1,'.self::PHYSICAL_STOCK_SCALE.'})?$/', $normalized)) {
            throw new DomainException('MIKRO_PHYSICAL_STOCK_QUANTITY_INVALID');
        }
        $negative = str_starts_with($normalized, '-');
        $absolute = ltrim($normalized, '-');
        [$whole, $fraction] = array_pad(explode('.', $absolute, 2), 2, '');
        if (strlen($whole) > 12) {
            throw new DomainException('MIKRO_PHYSICAL_STOCK_QUANTITY_INVALID');
        }
        $fraction = str_pad($fraction, self::PHYSICAL_STOCK_SCALE, '0');
        $scaled = ((int) $whole * (10 ** self::PHYSICAL_STOCK_SCALE)) + (int) $fraction;

        return $negative ? -$scaled : $scaled;
    }

    private function scaledIntegerToDecimalString(int $value, int $scale): string
    {
        $negative = $value < 0;
        $absolute = abs($value);
        $divisor = 10 ** $scale;
        $whole = intdiv($absolute, $divisor);
        $fraction = str_pad((string) ($absolute % $divisor), $scale, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '').$whole.'.'.$fraction;
    }

    private function scaledIntegerToFloat(int $value, int $scale): float
    {
        return $value / (10 ** $scale);
    }

    private function percentageToScaledInteger(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (! is_scalar($value)) {
            throw new DomainException('MIKRO_TAX_RATE_INVALID');
        }
        $normalized = trim((string) $value);
        if (! preg_match('/^\d+(?:\.\d{1,'.self::TAX_RATE_SCALE.'})?$/', $normalized)) {
            throw new DomainException('MIKRO_TAX_RATE_INVALID');
        }
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        if ((int) $whole > 100 || ((int) $whole === 100 && trim($fraction, '0') !== '')) {
            throw new DomainException('MIKRO_TAX_RATE_INVALID');
        }

        return ((int) $whole * (10 ** self::TAX_RATE_SCALE))
            + (int) str_pad($fraction, self::TAX_RATE_SCALE, '0');
    }

    private function moneyLabelFromMinor(int $minor, string $currency): string
    {
        $negative = $minor < 0;
        $absolute = abs($minor);
        $whole = intdiv($absolute, 100);
        $fraction = str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '')
            .number_format($whole, 0, ',', '.').','.$fraction.' '
            .($currency === 'TRY' ? 'TL' : $currency);
    }

    private function moneyLabel(float $amount, string $currency): string
    {
        return number_format($amount, 2, ',', '.').' '.($currency === 'TRY' ? 'TL' : $currency);
    }
}
