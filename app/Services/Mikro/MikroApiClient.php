<?php

namespace App\Services\Mikro;

use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class MikroApiClient
{
    private const MAX_READ_ATTEMPTS = 2;

    private const MAX_RESPONSE_BYTES = 2097152;

    public function __construct(
        private readonly MikroOperationRegistry $registry,
        private readonly MikroFixedQueryCatalog $queries,
        private readonly MikroResponseSchemaCatalog $responseSchemas,
        private readonly MikroDailyPasswordSigner $passwordSigner,
        private readonly MikroRuntimeState $runtimeState,
        private readonly TechnicalServiceMessagingSettingsService $settings,
        private readonly MikroParityContract $parityContract,
    ) {}

    public function healthCheck(): array
    {
        return $this->execute('health.check');
    }

    /** @return array<string, mixed> */
    public function authenticatedReadCanary(string $requestedOperationKey, array $parameters): array
    {
        $context = $this->settings->mikroApiConnectionContext();

        try {
            $operation = $this->registry->assertCanaryAllowed($requestedOperationKey, $context);
            if (($operation['adapter_type'] ?? null) !== 'FIXED_QUERY') {
                throw new DomainException(MikroOperationRegistry::BLOCKED_CANARY_OPERATION);
            }
            $queryId = (string) ($operation['fixed_query_id'] ?? '');
            $sql = $this->queries->render($queryId, $parameters);
        } catch (DomainException $exception) {
            return $this->canaryFailureResult(
                $requestedOperationKey,
                isset($operation) ? (string) ($operation['canonical_operation_key'] ?? $operation['operation_key'] ?? '') : null,
                $exception->getMessage(),
            );
        }

        return $this->executeAuthenticatedReadCanary(
            $requestedOperationKey,
            $operation,
            ['SQLSorgu' => $sql],
            $context,
        );
    }

    /**
     * Parity probes reuse the authenticated canary transport but replace the fixed
     * query with a code-owned parity source. Runtime circuit, snapshots and source
     * mode remain untouched.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function authenticatedParityRead(MikroParitySource $source, array $parameters): array
    {
        $requestedOperationKey = $source->operationKey();
        $context = $this->settings->mikroApiConnectionContext();

        try {
            if ($source->phase() !== 'detail') {
                throw new DomainException('MIKRO_PARITY_DETAIL_SOURCE_REQUIRED');
            }
            $operation = $this->registry->assertCanaryAllowed($requestedOperationKey, $context);
            if (($operation['adapter_type'] ?? null) !== 'FIXED_QUERY') {
                throw new DomainException(MikroOperationRegistry::BLOCKED_CANARY_OPERATION);
            }
            $sql = $this->queries->render($source->queryId(), $parameters);
        } catch (DomainException $exception) {
            return $this->canaryFailureResult(
                $requestedOperationKey,
                isset($operation) ? (string) ($operation['canonical_operation_key'] ?? '') : null,
                $exception->getMessage(),
            );
        }

        return $this->executeAuthenticatedReadCanary(
            $requestedOperationKey,
            $operation,
            ['SQLSorgu' => $sql],
            $context,
            $source,
        );
    }

    public function userParameters(): array
    {
        return $this->execute('user.parameters');
    }

    public function listUsers(): array
    {
        return $this->execute('user.list');
    }

    public function listCustomers(string $customerCode = '', string $taxNumber = '', int $dateType = 0, string $startDate = '', string $endDate = '', string $sort = '-cari_kod', int $size = 50, int $index = 0): array
    {
        $this->assertPage($size, $index);

        return $this->execute('customer.list', [
            'CariKod' => $customerCode,
            'CariVKNTCNo' => $taxNumber,
            'TarihTipi' => $dateType,
            'IlkTarih' => $startDate,
            'SonTarih' => $endDate,
            'Sort' => $sort,
            'Size' => (string) $size,
            'Index' => $index,
        ]);
    }

    public function customerDetail(string $customerCode): array
    {
        return $this->fixed('customer.detail', ['customer_code' => $customerCode]);
    }

    public function customerBalance(string $customerCode): array
    {
        return $this->fixed('customer.balance', ['customer_code' => $customerCode]);
    }

    public function customerDocumentTimeline(string $customerCode, string $dateFrom, string $dateTo, int $limit = 100): array
    {
        return $this->fixed('customer.document.timeline', compact('customerCode', 'dateFrom', 'dateTo', 'limit'), [
            'customer_code' => $customerCode, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'limit' => $limit,
        ]);
    }

    public function listStocks(string $stockCode = '', int $dateType = 0, string $startDate = '', string $endDate = '', string $sort = '-sto_kod', int $size = 50, int $index = 0): array
    {
        $this->assertPage($size, $index);

        return $this->execute('stock.list', [
            'StokKod' => $stockCode,
            'TarihTipi' => $dateType,
            'IlkTarih' => $startDate,
            'SonTarih' => $endDate,
            'Sort' => $sort,
            'Size' => (string) $size,
            'Index' => $index,
        ]);
    }

    public function searchStocks(string $query): array
    {
        return $this->fixed('stock.search', ['search' => $query]);
    }

    public function retrySearchStocks(string $query): array
    {
        return $this->retryFixed('stock.search', ['search' => $query]);
    }

    /** @param array<int, string> $itemCodes */
    public function physicalStockQuantities(array $itemCodes): array
    {
        return $this->fixed('stock.physical_quantity', ['item_codes' => $itemCodes]);
    }

    /** @param array<int, string> $itemCodes */
    public function retryPhysicalStockQuantities(array $itemCodes): array
    {
        return $this->retryFixed('stock.physical_quantity', ['item_codes' => $itemCodes]);
    }

    /** @param array<int, string> $itemCodes */
    public function stockTaxProfiles(array $itemCodes): array
    {
        return $this->executeStockTaxProfiles($itemCodes);
    }

    /** @param array<int, string> $itemCodes */
    public function retryStockTaxProfiles(array $itemCodes): array
    {
        return $this->retryReadOperation(
            'stock.tax_profile',
            fn (): array => $this->executeStockTaxProfiles($itemCodes),
        );
    }

    public function stockAvailability(string $stockCode): array
    {
        return $this->fixed('stock.availability', ['stock_code' => $stockCode]);
    }

    public function stockMovements(string $stockCode, string $dateFrom, string $dateTo, int $limit = 100): array
    {
        return $this->fixed('stock.movement.list', [], ['stock_code' => $stockCode, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'limit' => $limit]);
    }

    public function serialLookup(string $serialNumber): array
    {
        return $this->fixed('serial.lookup', ['serial_number' => $serialNumber]);
    }

    public function serialHistory(string $serialNumber, int $limit = 100): array
    {
        return $this->fixed('serial.history', [], ['serial_number' => $serialNumber, 'limit' => $limit]);
    }

    public function listOrders(string $dateFrom, string $dateTo, int $limit = 100): array
    {
        return $this->fixed('order.list', [], ['date_from' => $dateFrom, 'date_to' => $dateTo, 'limit' => $limit]);
    }

    public function orderDetail(string $orderGuid): array
    {
        return $this->fixed('order.detail', ['order_guid' => $orderGuid]);
    }

    public function orderLines(string $orderGuid, int $limit = 100): array
    {
        return $this->fixed('order.lines', [], ['order_guid' => $orderGuid, 'limit' => $limit]);
    }

    public function orderRemainingQuantity(string $orderGuid): array
    {
        return $this->fixed('order.remaining.quantity', ['order_guid' => $orderGuid]);
    }

    public function listInvoices(string $dateFrom, string $dateTo, int $limit = 100): array
    {
        return $this->fixed('invoice.list', [], ['date_from' => $dateFrom, 'date_to' => $dateTo, 'limit' => $limit]);
    }

    public function invoiceDetail(string $invoiceGuid): array
    {
        return $this->fixed('invoice.detail', ['invoice_guid' => $invoiceGuid]);
    }

    public function invoiceLines(string $invoiceGuid, int $limit = 100): array
    {
        return $this->fixed('invoice.lines', [], ['invoice_guid' => $invoiceGuid, 'limit' => $limit]);
    }

    public function invoicePdf(string $invoiceGuid): array
    {
        $this->assertGuid($invoiceGuid);

        return $this->execute('invoice.pdf', ['Fatura_Guid' => strtolower($invoiceGuid)]);
    }

    public function listDispatches(string $dateFrom, string $dateTo, int $limit = 100): array
    {
        return $this->fixed('dispatch.list', [], ['date_from' => $dateFrom, 'date_to' => $dateTo, 'limit' => $limit]);
    }

    public function dispatchDetail(string $dispatchGuid): array
    {
        return $this->fixed('dispatch.detail', ['dispatch_guid' => $dispatchGuid]);
    }

    public function dispatchLines(string $dispatchGuid, int $limit = 100): array
    {
        return $this->fixed('dispatch.lines', [], ['dispatch_guid' => $dispatchGuid, 'limit' => $limit]);
    }

    public function dispatchPdf(int $invoiceType, string $id): array
    {
        $this->assertGuid($id);

        return $this->execute('dispatch.pdf', ['EFaturaTipi' => $invoiceType, 'Id' => strtolower($id)]);
    }

    public function eDocumentStatus(int $invoiceType, int $documentType, string $uuid): array
    {
        $this->assertGuid($uuid);

        return $this->execute('edocument.status', ['EFaturaTipi' => $invoiceType, 'EBelgeTipi' => $documentType, 'UUID' => strtolower($uuid)]);
    }

    public function eTaxpayerCheck(string $taxNumber): array
    {
        if (! preg_match('/^\d{10,11}$/', $taxNumber)) {
            throw new DomainException('MIKRO_QUERY_PARAMETER_INVALID');
        }

        return $this->execute('etaxpayer.check', ['VKN_TCKN' => $taxNumber]);
    }

    public function listReturns(string $dateFrom, string $dateTo, int $limit = 100): array
    {
        return $this->fixed('return.list', [], ['date_from' => $dateFrom, 'date_to' => $dateTo, 'limit' => $limit]);
    }

    public function returnDetail(string $returnGuid): array
    {
        return $this->fixed('return.detail', ['return_guid' => $returnGuid]);
    }

    public function exchangeStatus(string $serialNumber, int $limit = 100): array
    {
        return $this->fixed('exchange.status', [], ['serial_number' => $serialNumber, 'limit' => $limit]);
    }

    public function replacementSerialLookup(string $serialNumber): array
    {
        return $this->fixed('replacement.serial.lookup', ['serial_number' => $serialNumber]);
    }

    private function fixed(string $operationKey, array $parameters, ?array $queryParameters = null): array
    {
        $queryParameters ??= $parameters;

        try {
            $sql = $this->queries->render($operationKey, $queryParameters);
        } catch (DomainException $exception) {
            return $this->failureResult($operationKey, $exception->getMessage(), (string) Str::uuid());
        }

        return $this->execute($operationKey, ['SQLSorgu' => $sql], $queryParameters);
    }

    private function retryFixed(string $operationKey, array $parameters): array
    {
        return $this->retryReadOperation(
            $operationKey,
            fn (): array => $this->fixed($operationKey, $parameters),
        );
    }

    /** @param callable():array<string, mixed> $callback */
    private function retryReadOperation(string $operationKey, callable $callback): array
    {
        $context = $this->settings->mikroApiConnectionContext();
        $origin = rtrim((string) ($context['base_url'] ?? ''), '/');
        if ($origin === '') {
            return $callback();
        }

        $lock = Cache::lock(
            'mikro:manual-retry:'.hash('sha256', strtolower($origin).':'.$operationKey),
            30,
        );
        if (! $lock->get()) {
            return $this->failureResult(
                $operationKey,
                'MIKRO_RETRY_IN_PROGRESS',
                (string) Str::uuid(),
                null,
                0,
                null,
                null,
                $this->runtimeState->circuit($origin, $operationKey)['circuit_state'],
            );
        }

        try {
            $this->runtimeState->resetCircuit($origin, $operationKey);

            return $callback();
        } finally {
            $lock->release();
        }
    }

    /** @param array<int, string> $itemCodes */
    private function executeStockTaxProfiles(array $itemCodes): array
    {
        $operationKey = 'stock.tax_profile';
        $correlationId = (string) Str::uuid();
        $filters = ['item_codes' => $itemCodes];
        $context = $this->settings->mikroApiConnectionContext();

        try {
            $sql = $this->queries->render($operationKey, $filters);
            $operation = $this->registry->assertReadAllowed($operationKey, $context);
        } catch (DomainException $exception) {
            return $this->failureResult($operationKey, $exception->getMessage(), $correlationId);
        }

        $rateEndpoint = $operation['supporting_endpoint'] ?? null;
        if (! is_string($rateEndpoint) || ! str_starts_with($rateEndpoint, '/Api/')) {
            return $this->failureResult($operationKey, 'MIKRO_TAX_RATE_ENDPOINT_MISSING', $correlationId);
        }

        if (! ($context['live_configuration_ready'] ?? false)) {
            return $this->failureResult($operationKey, 'MIKRO_LIVE_CONFIGURATION_MISSING', $correlationId);
        }
        if ($blocker = $this->registry->baseUrlBlocker($context['base_url'] ?? null)) {
            return $this->failureResult($operationKey, 'MIKRO_INVALID_BASE_URL', $correlationId, null, 0, $blocker);
        }

        $origin = rtrim((string) $context['base_url'], '/');
        $circuit = $this->runtimeState->beforeRequest($origin, $operationKey);
        if (! $circuit['allowed']) {
            return $this->fallbackOrFailure($operationKey, $filters, 'MIKRO_CIRCUIT_OPEN', $correlationId, null, 0, $circuit['circuit_state']);
        }

        $startedAt = microtime(true);
        $lastError = 'MIKRO_CONNECTION_FAILED';
        $lastStatus = null;
        $lastMessage = null;
        $attempts = 0;

        for ($attempt = 1; $attempt <= self::MAX_READ_ATTEMPTS; $attempt++) {
            $attempts = $attempt;
            try {
                $pointerResponse = $this->sendReadRequest(
                    $origin.(string) $operation['endpoint'],
                    $this->requestPayload($operation, ['SQLSorgu' => $sql], $context),
                    $context,
                    $correlationId,
                );
                $lastStatus = $pointerResponse->status();
                if (! $pointerResponse->successful()) {
                    $lastError = $this->httpErrorCode($pointerResponse);
                    $lastMessage = $lastError;
                    if ($this->shouldRetry($lastError, $lastStatus) && $attempt < self::MAX_READ_ATTEMPTS) {
                        continue;
                    }
                    break;
                }
                if (strlen($pointerResponse->body()) > self::MAX_RESPONSE_BYTES) {
                    return $this->failureResult($operationKey, 'MIKRO_RESPONSE_TOO_LARGE', $correlationId, $lastStatus, $attempt, null, $startedAt, $circuit['circuit_state']);
                }
                $pointerJson = $pointerResponse->json();
                if (! is_array($pointerJson)) {
                    return $this->failureResult($operationKey, 'MIKRO_INVALID_RESPONSE', $correlationId, $lastStatus, $attempt, null, $startedAt, $circuit['circuit_state']);
                }
                $pointerEnvelope = $this->fixedQueryEnvelope($operationKey, $pointerJson);
                if (($pointerEnvelope['is_error'] ?? true) === true) {
                    return $this->failureResult($operationKey, 'MIKRO_BUSINESS_ERROR', $correlationId, $lastStatus, $attempt, null, $startedAt, $circuit['circuit_state']);
                }
                $pointerRows = $this->responseSchemas->normalizeStockTaxPointerRows((array) ($pointerEnvelope['rows'] ?? []));

                $rateResponse = $this->sendReadRequest(
                    $origin.$rateEndpoint,
                    $this->requestPayload($operation, [], $context),
                    $context,
                    $correlationId,
                );
                $lastStatus = $rateResponse->status();
                if (! $rateResponse->successful()) {
                    $lastError = $this->httpErrorCode($rateResponse);
                    $lastMessage = $lastError;
                    if ($this->shouldRetry($lastError, $lastStatus) && $attempt < self::MAX_READ_ATTEMPTS) {
                        continue;
                    }
                    break;
                }
                if (strlen($rateResponse->body()) > self::MAX_RESPONSE_BYTES) {
                    return $this->failureResult($operationKey, 'MIKRO_RESPONSE_TOO_LARGE', $correlationId, $lastStatus, $attempt, null, $startedAt, $circuit['circuit_state']);
                }
                $rateJson = $rateResponse->json();
                if (! is_array($rateJson)) {
                    return $this->failureResult($operationKey, 'MIKRO_INVALID_RESPONSE', $correlationId, $lastStatus, $attempt, null, $startedAt, $circuit['circuit_state']);
                }
                $rateRows = $this->responseSchemas->normalizeInstalledTaxRates($rateJson);
                $freshness = now()->toIso8601String();
                $data = $this->responseSchemas->resolveStockTaxProfiles(
                    $pointerRows,
                    $rateRows,
                    $freshness,
                    $correlationId,
                );
            } catch (ConnectionException $exception) {
                $lastError = $this->connectionErrorCode($exception);
                $lastMessage = $lastError;
                if ($this->shouldRetry($lastError, null) && $attempt < self::MAX_READ_ATTEMPTS) {
                    continue;
                }
                break;
            } catch (DomainException $exception) {
                return $this->failureResult($operationKey, 'MIKRO_INVALID_RESPONSE', $correlationId, $lastStatus, $attempt, $exception->getMessage(), $startedAt, $circuit['circuit_state']);
            } catch (Throwable) {
                $lastError = 'MIKRO_CONNECTION_FAILED';
                $lastMessage = $lastError;
                break;
            }

            $this->runtimeState->recordSuccess($origin, $operationKey);
            $this->runtimeState->storeLastGood($operationKey, $filters, $data, 'mikro', $freshness, $correlationId);

            return $this->resultEnvelope(
                $operationKey,
                true,
                $lastStatus,
                null,
                'OK',
                $startedAt,
                $attempt,
                $data,
                'mikro',
                $freshness,
                $correlationId,
                false,
                false,
                $circuit['circuit_state'],
            );
        }

        if ($this->shouldRetry($lastError, $lastStatus)) {
            $this->runtimeState->recordTransientFailure($origin, $operationKey);

            return $this->fallbackOrFailure(
                $operationKey,
                $filters,
                $lastError,
                $correlationId,
                $startedAt,
                $attempts,
                $this->runtimeState->circuit($origin, $operationKey)['circuit_state'],
                $lastStatus,
                $lastMessage,
            );
        }

        return $this->failureResult($operationKey, $lastError, $correlationId, $lastStatus, $attempts, $lastMessage, $startedAt, $circuit['circuit_state']);
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $context */
    private function sendReadRequest(string $url, array $payload, array $context, string $correlationId): Response
    {
        return Http::acceptJson()
            ->asJson()
            ->withHeaders(['X-Correlation-ID' => $correlationId])
            ->connectTimeout(min(5, (int) $context['timeout_seconds']))
            ->timeout((int) $context['timeout_seconds'])
            ->post($url, $payload);
    }

    private function execute(string $operationKey, array $payload = [], array $snapshotFilters = []): array
    {
        $correlationId = (string) Str::uuid();
        if ($snapshotFilters === [] && $payload !== []) {
            $snapshotFilters = $payload;
        }
        $context = $this->settings->mikroApiConnectionContext();

        if ($operationKey === 'user.parameters' && ! ($context['live_configuration_ready'] ?? false)) {
            return $this->failureResult($operationKey, 'MIKRO_LIVE_CONFIGURATION_MISSING', $correlationId);
        }

        try {
            $operation = $this->registry->assertReadAllowed($operationKey, $context);
        } catch (DomainException $exception) {
            return $this->failureResult($operationKey, $exception->getMessage(), $correlationId);
        }

        $configurationReady = $operationKey === 'health.check'
            ? (bool) ($context['health_configuration_ready'] ?? false)
            : (bool) ($context['live_configuration_ready'] ?? false);
        if (! $configurationReady) {
            return $this->failureResult(
                $operationKey,
                $operationKey === 'health.check'
                    ? 'MIKRO_HEALTH_CONFIGURATION_MISSING'
                    : 'MIKRO_LIVE_CONFIGURATION_MISSING',
                $correlationId,
            );
        }
        if ($blocker = $this->registry->baseUrlBlocker($context['base_url'] ?? null)) {
            return $this->failureResult($operationKey, 'MIKRO_INVALID_BASE_URL', $correlationId, null, 0, $blocker);
        }

        $origin = rtrim((string) $context['base_url'], '/');
        $circuit = $this->runtimeState->beforeRequest($origin, $operationKey);
        if (! $circuit['allowed']) {
            return $this->fallbackOrFailure($operationKey, $snapshotFilters, 'MIKRO_CIRCUIT_OPEN', $correlationId, null, 0, $circuit['circuit_state']);
        }

        $startedAt = microtime(true);
        $lastError = 'MIKRO_CONNECTION_FAILED';
        $lastStatus = null;
        $lastMessage = null;
        $attempts = 0;

        for ($attempt = 1; $attempt <= self::MAX_READ_ATTEMPTS; $attempt++) {
            $attempts = $attempt;
            try {
                $request = Http::acceptJson()
                    ->asJson()
                    ->withHeaders(['X-Correlation-ID' => $correlationId])
                    ->connectTimeout(min(5, (int) $context['timeout_seconds']))
                    ->timeout((int) $context['timeout_seconds']);
                $url = $origin.(string) $operation['endpoint'];
                $response = $operation['method'] === 'GET'
                    ? $request->get($url)
                    : $request->post($url, $this->requestPayload($operation, $payload, $context));
            } catch (ConnectionException $exception) {
                $lastError = $this->connectionErrorCode($exception);
                $lastMessage = $lastError;
                if ($this->shouldRetry($lastError, null) && $attempt < self::MAX_READ_ATTEMPTS) {
                    continue;
                }
                break;
            } catch (Throwable) {
                $lastError = 'MIKRO_CONNECTION_FAILED';
                $lastMessage = $lastError;
                break;
            }

            $lastStatus = $response->status();
            if ($response->successful()) {
                $result = $this->successResult($operationKey, $response, $correlationId, $startedAt, $attempt, $circuit['circuit_state']);
                if ($result['success']) {
                    $this->runtimeState->recordSuccess($origin, $operationKey);
                    $this->runtimeState->storeLastGood($operationKey, $snapshotFilters, (array) $result['data'], (string) $result['source'], (string) $result['freshness_at'], $correlationId);

                    return $result;
                }

                return $result;
            }

            $lastError = $this->httpErrorCode($response);
            $lastMessage = $lastError;
            if (! $this->shouldRetry($lastError, $lastStatus) || $attempt >= self::MAX_READ_ATTEMPTS) {
                break;
            }
        }

        if ($this->shouldRetry($lastError, $lastStatus)) {
            $this->runtimeState->recordTransientFailure($origin, $operationKey);

            return $this->fallbackOrFailure(
                $operationKey,
                $snapshotFilters,
                $lastError,
                $correlationId,
                $startedAt,
                $attempts,
                $this->runtimeState->circuit($origin, $operationKey)['circuit_state'],
                $lastStatus,
                $lastMessage,
            );
        }

        return $this->failureResult($operationKey, $lastError, $correlationId, $lastStatus, $attempts, $lastMessage, $startedAt, $circuit['circuit_state']);
    }

    /**
     * Authenticated canaries intentionally bypass production routing and runtime state.
     * They never read or write circuit/last-good state and cannot execute caller SQL.
     *
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function executeAuthenticatedReadCanary(string $requestedOperationKey, array $operation, array $payload, array $context, ?MikroParitySource $paritySource = null): array
    {
        $canonicalOperationKey = (string) $operation['canonical_operation_key'];
        $correlationId = (string) Str::uuid();
        $origin = rtrim((string) $context['base_url'], '/');
        $startedAt = microtime(true);
        $lastError = 'MIKRO_CONNECTION_FAILED';
        $lastStatus = null;
        $lastMessage = null;
        $attempts = 0;

        for ($attempt = 1; $attempt <= self::MAX_READ_ATTEMPTS; $attempt++) {
            $attempts = $attempt;
            try {
                $request = Http::acceptJson()
                    ->asJson()
                    ->withHeaders(['X-Correlation-ID' => $correlationId])
                    ->connectTimeout(min(5, (int) $context['timeout_seconds']))
                    ->timeout((int) $context['timeout_seconds']);
                $url = $origin.(string) $operation['endpoint'];
                $response = $operation['method'] === 'GET'
                    ? $request->get($url)
                    : $request->post($url, $this->requestPayload($operation, $payload, $context));
            } catch (ConnectionException $exception) {
                $lastError = $this->connectionErrorCode($exception);
                $lastMessage = $lastError;
                if ($this->shouldRetry($lastError, null) && $attempt < self::MAX_READ_ATTEMPTS) {
                    continue;
                }
                break;
            } catch (Throwable) {
                $lastError = 'MIKRO_CONNECTION_FAILED';
                $lastMessage = $lastError;
                break;
            }

            $lastStatus = $response->status();
            if ($response->successful()) {
                return $this->withCanaryMetadata(
                    $paritySource === null
                        ? $this->successResult($canonicalOperationKey, $response, $correlationId, $startedAt, $attempt, 'CANARY_ISOLATED')
                        : $this->successParityResult($paritySource, $canonicalOperationKey, $response, $correlationId, $startedAt, $attempt),
                    $requestedOperationKey,
                    $canonicalOperationKey,
                );
            }

            $lastError = $this->httpErrorCode($response);
            $lastMessage = $lastError;
            if (! $this->shouldRetry($lastError, $lastStatus) || $attempt >= self::MAX_READ_ATTEMPTS) {
                break;
            }
        }

        return $this->withCanaryMetadata(
            $this->failureResult(
                $canonicalOperationKey,
                $lastError,
                $correlationId,
                $lastStatus,
                $attempts,
                $lastMessage,
                $startedAt,
                'CANARY_ISOLATED',
            ),
            $requestedOperationKey,
            $canonicalOperationKey,
        );
    }

    /** @return array<string, mixed> */
    private function canaryFailureResult(string $requestedOperationKey, ?string $canonicalOperationKey, string $errorCode): array
    {
        $canonicalOperationKey = filled($canonicalOperationKey) ? $canonicalOperationKey : $requestedOperationKey;

        return $this->withCanaryMetadata(
            $this->failureResult($canonicalOperationKey, $errorCode, (string) Str::uuid(), null, 0, null, null, 'CANARY_ISOLATED'),
            $requestedOperationKey,
            $canonicalOperationKey,
        );
    }

    /** @return array<string, mixed> */
    private function withCanaryMetadata(array $result, string $requestedOperationKey, string $canonicalOperationKey): array
    {
        return [
            ...$result,
            'requested_operation_key' => $requestedOperationKey,
            'canonical_operation_key' => $canonicalOperationKey,
            'canary' => true,
            'runtime_state_mutated' => false,
            'source_mode_mutated' => false,
        ];
    }

    private function requestPayload(array $operation, array $payload, array $context): array
    {
        $apiKeyField = (string) ($operation['api_key_field'] ?? 'ApiKey');
        $auth = [
            $apiKeyField => $context['api_key'],
            'CalismaYili' => $context['working_year'],
            'FirmaKodu' => $context['firm_code'],
            'KullaniciKodu' => $context['user_code'],
            'Sifre' => $this->passwordSigner->sign((string) $context['password'], null, (string) $context['server_timezone']),
        ];

        return match ($operation['payload_style']) {
            'standard', 'fixed_query' => ['Mikro' => $auth, ...$payload],
            'mikro' => ['Mikro' => [...$auth, ...$payload]],
            'edocument' => ['Mikro' => [...$auth, 'EBelge' => $payload]],
            'etaxpayer' => ['Mikro' => [...$auth, 'EMukellef' => $payload]],
            default => ['Mikro' => $auth],
        };
    }

    private function successResult(string $operationKey, Response $response, string $correlationId, float $startedAt, int $attempt, string $circuitState): array
    {
        if ($operationKey === 'health.check') {
            $rows = [['service_status' => 'UP']];
        } else {
            $json = $response->json();
            if (! is_array($json)) {
                return $this->failureResult($operationKey, 'MIKRO_INVALID_RESPONSE', $correlationId, $response->status(), $attempt);
            }
            if (($json['success'] ?? true) === false || ($json['Success'] ?? true) === false) {
                return $this->failureResult($operationKey, 'MIKRO_BUSINESS_ERROR', $correlationId, $response->status(), $attempt);
            }
            $fixedQueryEnvelope = $this->fixedQueryEnvelope($operationKey, $json);
            if (($fixedQueryEnvelope['is_error'] ?? false) === true) {
                return $this->failureResult($operationKey, 'MIKRO_BUSINESS_ERROR', $correlationId, $response->status(), $attempt);
            }
            $rows = $fixedQueryEnvelope['rows'] ?? $this->rows($json);
            if ($rows === null) {
                return $this->failureResult($operationKey, 'MIKRO_INVALID_RESPONSE', $correlationId, $response->status(), $attempt);
            }
        }

        try {
            $data = $this->responseSchemas->normalize($operationKey, $rows);
        } catch (DomainException $exception) {
            $errorCode = $exception->getMessage() === MikroOperationRegistry::BLOCKED_RESPONSE_SCHEMA
                ? MikroOperationRegistry::BLOCKED_RESPONSE_SCHEMA
                : 'MIKRO_INVALID_RESPONSE';

            return $this->failureResult($operationKey, $errorCode, $correlationId, $response->status(), $attempt);
        }

        $freshness = now()->toIso8601String();

        return $this->resultEnvelope($operationKey, true, $response->status(), null, 'OK', $startedAt, $attempt, $data, 'mikro', $freshness, $correlationId, false, false, $circuitState);
    }

    private function successParityResult(MikroParitySource $source, string $operationKey, Response $response, string $correlationId, float $startedAt, int $attempt): array
    {
        $json = $response->json();
        if (! is_array($json)) {
            return $this->failureResult($operationKey, 'MIKRO_INVALID_RESPONSE', $correlationId, $response->status(), $attempt, null, $startedAt, 'CANARY_ISOLATED');
        }

        $fixedQueryEnvelope = $this->fixedQueryEnvelope($operationKey, $json);
        if (($fixedQueryEnvelope['is_error'] ?? false) === true) {
            return $this->failureResult($operationKey, 'MIKRO_BUSINESS_ERROR', $correlationId, $response->status(), $attempt, null, $startedAt, 'CANARY_ISOLATED');
        }
        $rows = $fixedQueryEnvelope['rows'] ?? $this->rows($json);
        if ($rows === null) {
            return $this->failureResult($operationKey, 'MIKRO_INVALID_RESPONSE', $correlationId, $response->status(), $attempt, null, $startedAt, 'CANARY_ISOLATED');
        }

        try {
            $normalized = $this->parityContract->normalizeMikro($source, $rows);
        } catch (DomainException) {
            return $this->failureResult($operationKey, 'MIKRO_PARITY_NORMALIZATION_FAILED', $correlationId, $response->status(), $attempt, null, $startedAt, 'CANARY_ISOLATED');
        }

        return $this->resultEnvelope(
            $operationKey,
            true,
            $response->status(),
            null,
            (string) $normalized['status'],
            $startedAt,
            $attempt,
            [$normalized],
            'mikro',
            now()->toIso8601String(),
            $correlationId,
            false,
            false,
            'CANARY_ISOLATED',
        );
    }

    private function fallbackOrFailure(string $operationKey, array $filters, string $errorCode, string $correlationId, ?float $startedAt, int $attempts, string $circuitState, ?int $status = null, ?string $message = null): array
    {
        $snapshot = $this->runtimeState->lastGood($operationKey, $filters);
        if (is_array($snapshot)) {
            return $this->resultEnvelope($operationKey, true, $status, $errorCode, 'LAST_GOOD_FALLBACK', $startedAt, $attempts, (array) ($snapshot['data'] ?? []), (string) ($snapshot['source'] ?? 'mikro'), (string) ($snapshot['freshness_at'] ?? now()->toIso8601String()), $correlationId, true, true, $circuitState);
        }

        return $this->failureResult($operationKey, $errorCode, $correlationId, $status, $attempts, $message, $startedAt, $circuitState);
    }

    private function failureResult(string $operationKey, string $errorCode, string $correlationId, ?int $status = null, int $attempts = 0, ?string $message = null, ?float $startedAt = null, string $circuitState = 'CLOSED'): array
    {
        return $this->resultEnvelope($operationKey, false, $status, $errorCode, $message ?? $errorCode, $startedAt, $attempts, [], 'mikro', now()->toIso8601String(), $correlationId, false, false, $circuitState);
    }

    private function resultEnvelope(string $operationKey, bool $success, ?int $status, ?string $errorCode, string $message, ?float $startedAt, int $attempts, array $data, string $source, string $freshnessAt, string $correlationId, bool $stale, bool $fallbackUsed, string $circuitState): array
    {
        return [
            'operation_key' => $operationKey,
            'success' => $success,
            'status' => $status,
            'error_code' => $errorCode,
            'message' => $message,
            'duration_ms' => $startedAt === null ? 0 : max(0, (int) round((microtime(true) - $startedAt) * 1000)),
            'attempt_count' => $attempts,
            'result_count' => count($data),
            'source' => $source,
            'freshness_at' => $freshnessAt,
            'correlation_id' => $correlationId,
            'stale' => $stale,
            'fallback_used' => $fallbackUsed,
            'circuit_state' => $circuitState,
            'data' => $data,
            'normalized_data' => count($data) === 1 && $operationKey === 'health.check' ? $data[0] : $data,
        ];
    }

    private function connectionErrorCode(ConnectionException $exception): string
    {
        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'ssl') || str_contains($message, 'tls') || str_contains($message, 'certificate')) {
            return 'MIKRO_TLS_FAILED';
        }
        if (str_contains($message, 'connect') && (str_contains($message, 'timed out') || str_contains($message, 'timeout'))) {
            return 'MIKRO_CONNECT_TIMEOUT';
        }
        if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return 'MIKRO_REQUEST_TIMEOUT';
        }

        return 'MIKRO_CONNECTION_FAILED';
    }

    private function httpErrorCode(Response $response): string
    {
        return match ($response->status()) {
            401 => 'MIKRO_AUTH_FAILED',
            403 => 'MIKRO_FORBIDDEN',
            404 => 'MIKRO_NOT_FOUND',
            409, 422 => 'MIKRO_BUSINESS_ERROR',
            429 => 'MIKRO_RATE_LIMITED',
            502, 503, 504 => 'MIKRO_SERVER_ERROR',
            default => $response->serverError() ? 'MIKRO_SERVER_ERROR' : 'MIKRO_BUSINESS_ERROR',
        };
    }

    private function shouldRetry(string $errorCode, ?int $status): bool
    {
        if (in_array($errorCode, ['MIKRO_CONNECT_TIMEOUT', 'MIKRO_REQUEST_TIMEOUT', 'MIKRO_CONNECTION_FAILED', 'MIKRO_RATE_LIMITED'], true)) {
            return true;
        }

        return $errorCode === 'MIKRO_SERVER_ERROR' && in_array($status, [502, 503, 504], true);
    }

    private function rows(array $json): ?array
    {
        foreach (['data', 'Data', 'result', 'Result', 'rows', 'Rows'] as $key) {
            if (is_array($json[$key] ?? null)) {
                $rows = array_values($json[$key]);

                return count(array_filter($rows, 'is_array')) === count($rows) ? $rows : null;
            }
        }

        if (! array_is_list($json)) {
            return null;
        }

        return count(array_filter($json, 'is_array')) === count($json) ? array_values($json) : null;
    }

    /** @return array{is_error:bool,rows:array<int, array<string, mixed>>}|null */
    private function fixedQueryEnvelope(string $operationKey, array $json): ?array
    {
        try {
            $operation = $this->registry->read($operationKey);
        } catch (DomainException) {
            return null;
        }
        if (($operation['adapter_type'] ?? null) !== 'FIXED_QUERY') {
            return null;
        }

        $result = $json['result'] ?? null;
        $envelope = is_array($result) && array_is_list($result) ? ($result[0] ?? null) : null;
        if (! is_array($envelope)
            || ! array_key_exists('StatusCode', $envelope)
            || ! array_key_exists('Data', $envelope)
            || ! array_key_exists('IsError', $envelope)) {
            return null;
        }
        if (($envelope['IsError'] ?? true) !== false || (int) ($envelope['StatusCode'] ?? 0) !== 200) {
            return ['is_error' => true, 'rows' => []];
        }

        $data = $envelope['Data'];
        if (! is_array($data) || ! array_is_list($data)) {
            return ['is_error' => true, 'rows' => []];
        }

        $rows = [];
        foreach ($data as $resultSet) {
            $sqlRows = is_array($resultSet) ? ($resultSet['SQLResult1'] ?? null) : null;
            if (! is_array($sqlRows) || ! array_is_list($sqlRows)) {
                return ['is_error' => true, 'rows' => []];
            }
            foreach ($sqlRows as $row) {
                if (! is_array($row)) {
                    return ['is_error' => true, 'rows' => []];
                }
                $rows[] = $row;
            }
        }

        return ['is_error' => false, 'rows' => $rows];
    }

    private function assertPage(int $size, int $index): void
    {
        if ($size < 1 || $size > 100 || $index < 0) {
            throw new DomainException('MIKRO_QUERY_PARAMETER_INVALID');
        }
    }

    private function assertGuid(string $guid): void
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $guid)) {
            throw new DomainException('MIKRO_QUERY_PARAMETER_INVALID');
        }
    }
}
