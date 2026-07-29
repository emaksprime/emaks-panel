<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceQrLink;
use Throwable;

class SerialProductContextResolver
{
    private const CURRENT_SOLD = 'sold_current';
    private const CURRENT_IN_STOCK_OR_CENTER = 'in_stock_or_center';
    private const CURRENT_RETURNED = 'returned';
    private const CURRENT_UNKNOWN = 'unknown';

    public function __construct(
        private readonly SerialActivationCodeResolver $activationCodeResolver,
        private readonly MikroSerialNumberService $mikroSerialNumberService,
    ) {
    }

    /**
     * @param array<string, mixed> $knownContext
     * @return array{serial_number:string,product_name:?string,product_model:?string,brand:?string,activation_code:?string,sale_mount_status:string,invoice_customer_type:string,suggested_link_type:string,current_serial_state:string,has_current_sale:bool,latest_event_type:?string,latest_valid_sale_exists:bool,stock_code:?string,context_payload:array<string,mixed>}
     */
    public function resolve(string $serialNumber, array $knownContext = []): array
    {
        $serialNumber = trim($serialNumber);
        $activationCode = $this->activationCodeResolver->resolve($serialNumber);
        $productName = $this->nullableText($knownContext['product_name'] ?? null);
        $productModel = $this->nullableText($knownContext['product_model'] ?? null);
        $brand = $this->nullableText($knownContext['brand'] ?? null);
        $stockCode = $this->nullableText($knownContext['stock_code'] ?? null);
        $saleMountStatus = $knownContext['sale_mount_status'] ?? TechnicalServiceMountSession::SALE_UNKNOWN;
        $invoiceCustomerType = $this->nullableText($knownContext['invoice_customer_type'] ?? null) ?? 'unknown';
        $payload = [
            'source' => 'known_context',
            'known_context' => $knownContext,
        ];
        $decision = [];
        $historyItems = [];

        try {
            $decision = $this->mikroSerialNumberService->checkInstallation($serialNumber);
            $saleMountStatus = $this->mapMikroMountStatus($decision['montaj_durumu'] ?? null);
            $productName = $productName ?? $this->firstText($decision, ['stok_adi', 'stock_name', 'Stok Adı', 'Stok Adi', 'Stok AdÄ±', 'product_name']);
            $stockCode = $stockCode ?? $this->firstText($decision, ['stok_kodu', 'stock_code', 'Stok Kodu']);
            $historyItems = $this->historyItemsFromDecision($decision);
            $payload = [
                'source' => 'mikro_serial_check',
                'mikro_decision' => $decision,
                'known_context' => $knownContext,
            ];
        } catch (Throwable $exception) {
            $saleMountStatus = $knownContext['sale_mount_status'] ?? TechnicalServiceMountSession::SALE_CHECK_FAILED;
            $payload = [
                'source' => 'mikro_serial_check_failed',
                'error' => $exception->getMessage(),
                'known_context' => $knownContext,
            ];
        }

        if ($historyItems === []) {
            $historyItems = $this->safeHistoryItems($serialNumber, $payload);
        }

        $latestValidSale = $this->safeLatestValidSale($serialNumber, $payload);
        $historyProductRow = $this->firstHistoryRowWithProduct($historyItems);
        $productName = $productName
            ?? $this->nullableText($latestValidSale['stock_name'] ?? null)
            ?? $this->firstText($historyProductRow ?? [], ['stok_adi', 'stock_name', 'Stok Adı', 'Stok Adi', 'Stok AdÄ±', 'product_name']);
        $stockCode = $stockCode
            ?? $this->nullableText($latestValidSale['stock_code'] ?? null)
            ?? $this->firstText($historyProductRow ?? [], ['stok_kodu', 'stock_code', 'Stok Kodu']);
        $productModel = $productModel
            ?? $this->firstText($decision, ['model_adi', 'model_name', 'Model Adı', 'Model Adi', 'Model AdÄ±', 'product_model'])
            ?? $this->firstText($historyProductRow ?? [], ['model_adi', 'model_name', 'Model Adı', 'Model Adi', 'Model AdÄ±', 'product_model'])
            ?? $this->deriveModel($productName);
        $brand = $brand
            ?? $this->firstText($decision, ['marka_kodu', 'brand', 'Marka Kodu'])
            ?? $this->firstText($historyProductRow ?? [], ['marka_kodu', 'brand', 'Marka Kodu'])
            ?? $this->deriveBrand($productName);

        $latestEvent = $this->latestHistoryItem($historyItems);
        $latestValidSaleExists = $latestValidSale !== null || $this->historyHasLatestValidSale($historyItems);
        $currentSerialState = $this->currentSerialState($decision, $historyItems, $latestValidSale, $saleMountStatus);
        $hasCurrentSale = $currentSerialState === self::CURRENT_SOLD;

        if (in_array($currentSerialState, [self::CURRENT_IN_STOCK_OR_CENTER, self::CURRENT_RETURNED], true)) {
            $saleMountStatus = TechnicalServiceMountSession::SALE_CHECK_FAILED;
        }

        $suggestedLinkType = $this->suggestedLinkType($decision, $saleMountStatus, $hasCurrentSale);

        return [
            'serial_number' => $serialNumber,
            'product_name' => $productName,
            'product_model' => $productModel,
            'brand' => $brand ?? $this->deriveBrand($productName),
            'activation_code' => $activationCode,
            'sale_mount_status' => $saleMountStatus,
            'invoice_customer_type' => $invoiceCustomerType,
            'suggested_link_type' => $suggestedLinkType,
            'current_serial_state' => $currentSerialState,
            'has_current_sale' => $hasCurrentSale,
            'latest_event_type' => $this->nullableText($latestEvent['event_type'] ?? null),
            'latest_valid_sale_exists' => $latestValidSaleExists,
            'stock_code' => $stockCode,
            'context_payload' => [
                ...$payload,
                'stock_code' => $stockCode,
                'activation_code' => $activationCode,
                'suggested_link_type' => $suggestedLinkType,
                'current_serial_state' => $currentSerialState,
                'has_current_sale' => $hasCurrentSale,
                'latest_event_type' => $this->nullableText($latestEvent['event_type'] ?? null),
                'latest_valid_sale_exists' => $latestValidSaleExists,
                'history_count' => count($historyItems),
            ],
        ];
    }

    private function mapMikroMountStatus(mixed $status): string
    {
        $status = $this->nullableText($status);

        return match ($status) {
            'Montaj Dahil' => TechnicalServiceMountSession::SALE_MONTAJ_DAHIL,
            'Montaj Sonradan Dahil' => TechnicalServiceMountSession::SALE_MONTAJ_SONRADAN_DAHIL,
            'Montaj Hariç', 'Montaj Haric', 'Montaj HariÃ§' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'Seri No Bulunamadı', 'Seri No Bulunamadi', 'Seri No BulunamadÄ±' => TechnicalServiceMountSession::SALE_NOT_FOUND,
            default => TechnicalServiceMountSession::SALE_UNKNOWN,
        };
    }

    private function deriveBrand(?string $productName): ?string
    {
        $value = mb_strtoupper((string) $productName, 'UTF-8');

        if (str_contains($value, 'PHILIPS')) {
            return 'PHILIPS';
        }

        if (str_contains($value, 'EMAKS PRIME')) {
            return 'EMAKS PRIME';
        }

        if (preg_match('/\bDDL[0-9A-Z-]*/u', $value)) {
            return 'PHILIPS';
        }

        if (
            str_contains($value, 'GALAXY')
            || str_contains($value, 'ALPHA')
            || preg_match('/\bE(?:10B?|20B?)\b/u', $value)
        ) {
            return 'EMAKS PRIME';
        }

        return null;
    }

    private function deriveModel(?string $productName): ?string
    {
        $value = trim((string) $productName);

        if ($value === '') {
            return null;
        }

        if (preg_match('/\b(DDL[0-9A-Z]+(?:-[0-9A-Z]+)*)\b/iu', $value, $matches)) {
            return mb_strtoupper($matches[1], 'UTF-8');
        }

        if (preg_match('/\b(E(?:10B?|20B?))\b/iu', $value, $matches)) {
            return mb_strtoupper($matches[1], 'UTF-8');
        }

        if (preg_match('/\b(ALPHA-[0-9A-Z]+)\b/iu', $value, $matches)) {
            return mb_strtoupper($matches[1], 'UTF-8');
        }

        if (preg_match('/\b(GALAXY\s*\d+)\b/iu', $value, $matches)) {
            $model = preg_replace('/\s+/u', ' ', mb_strtoupper($matches[1], 'UTF-8')) ?? mb_strtoupper($matches[1], 'UTF-8');
            $color = $this->firstColor($value);

            return $color ? "{$model} - {$color}" : $model;
        }

        return null;
    }

    private function firstColor(string $value): ?string
    {
        $upper = mb_strtoupper($value, 'UTF-8');
        $colors = [
            'GRİ' => ['GRİ', 'GRI', 'GREY', 'GRAY', 'GRÄ°'],
            'SİYAH' => ['SİYAH', 'SIYAH', 'BLACK', 'SÄ°YAH'],
            'BEYAZ' => ['BEYAZ', 'WHITE'],
            'ALTIN' => ['ALTIN', 'GOLD'],
            'GÜMÜŞ' => ['GÜMÜŞ', 'GUMUS', 'SILVER', 'GÃœMÃœÅ'],
        ];

        foreach ($colors as $normalized => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($upper, $needle)) {
                    return $normalized;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $decision
     * @return array<int, array<string, mixed>>
     */
    private function historyItemsFromDecision(array $decision): array
    {
        $history = $decision['history'] ?? [];

        if (! is_array($history)) {
            return [];
        }

        return array_values(array_filter($history, fn (mixed $item): bool => is_array($item)));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function safeHistoryItems(string $serialNumber, array &$payload): array
    {
        try {
            $history = $this->mikroSerialNumberService->history($serialNumber);
            $items = $history['items'] ?? [];

            if (! is_array($items)) {
                return [];
            }

            $payload['mikro_history'] = [
                'source' => 'technical_service_serial_history',
                'count' => count($items),
            ];

            return array_values(array_filter($items, fn (mixed $item): bool => is_array($item)));
        } catch (Throwable $exception) {
            $payload['history_error'] = $exception->getMessage();

            return [];
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function safeLatestValidSale(string $serialNumber, array &$payload): ?array
    {
        try {
            $latestSale = $this->mikroSerialNumberService->latestValidSale($serialNumber);

            if ($latestSale !== null) {
                $payload['latest_valid_sale'] = [
                    'source' => 'technical_service_warranty_serial',
                    'document_no' => $latestSale['document_no'] ?? null,
                    'date' => $latestSale['date'] ?? null,
                ];
            }

            return $latestSale;
        } catch (Throwable $exception) {
            $payload['latest_valid_sale_error'] = $exception->getMessage();

            return null;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $historyItems
     * @return array<string, mixed>|null
     */
    private function firstHistoryRowWithProduct(array $historyItems): ?array
    {
        foreach (array_reverse($historyItems) as $item) {
            if ($this->firstText($item, ['stok_adi', 'stock_name', 'Stok Adı', 'Stok Adi', 'Stok AdÄ±', 'product_name']) !== null) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $historyItems
     * @return array<string, mixed>|null
     */
    private function latestHistoryItem(array $historyItems): ?array
    {
        $latest = null;
        $latestDate = null;

        foreach ($historyItems as $item) {
            $date = $this->nullableText($item['event_date'] ?? null);

            if ($date === null) {
                $latest ??= $item;
                continue;
            }

            if ($latestDate === null || strcmp($date, $latestDate) >= 0) {
                $latestDate = $date;
                $latest = $item;
            }
        }

        return $latest;
    }

    /**
     * @param array<int, array<string, mixed>> $historyItems
     */
    private function historyHasLatestValidSale(array $historyItems): bool
    {
        foreach ($historyItems as $item) {
            if ($this->truthy($item['is_latest_valid_sale'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $decision
     * @param array<int, array<string, mixed>> $historyItems
     * @param array<string, mixed>|null $latestValidSale
     */
    private function currentSerialState(
        array $decision,
        array $historyItems,
        ?array $latestValidSale,
        string $saleMountStatus,
    ): string {
        $latestEvent = $this->latestHistoryItem($historyItems);
        $latestEventText = $this->eventText($latestEvent);

        if ($this->containsAny($latestEventText, ['iade', 'return'])) {
            return self::CURRENT_RETURNED;
        }

        if ($this->containsAny($latestEventText, ['depo', 'stok', 'merkez', 'giris', 'giriş', 'transfer', 'sayim', 'sayım'])) {
            return self::CURRENT_IN_STOCK_OR_CENTER;
        }

        if ($latestValidSale !== null || $this->historyHasLatestValidSale($historyItems)) {
            return self::CURRENT_SOLD;
        }

        if ($this->containsAny($latestEventText, ['satis', 'satış', 'sale', 'cikis', 'çıkış'])) {
            return self::CURRENT_SOLD;
        }

        if (($decision['found'] ?? true) === false || $saleMountStatus === TechnicalServiceMountSession::SALE_NOT_FOUND) {
            return self::CURRENT_UNKNOWN;
        }

        return $this->hasDocumentContext($decision) ? self::CURRENT_SOLD : self::CURRENT_UNKNOWN;
    }

    /**
     * @param array<string, mixed>|null $event
     */
    private function eventText(?array $event): string
    {
        if ($event === null) {
            return '';
        }

        return mb_strtolower(implode(' ', array_filter([
            $this->nullableText($event['event_type'] ?? null),
            $this->nullableText($event['title'] ?? null),
            $this->nullableText($event['description'] ?? null),
        ])), 'UTF-8');
    }

    /**
     * @param array<int, string> $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $keys
     */
    private function firstText(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->nullableText($row[$key] ?? null);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $decision
     */
    private function suggestedLinkType(array $decision, string $saleMountStatus, bool $hasCurrentSale): string
    {
        if (($decision['found'] ?? true) === false || $saleMountStatus === TechnicalServiceMountSession::SALE_NOT_FOUND) {
            return TechnicalServiceQrLink::TYPE_PRE_SALE_PRODUCT;
        }

        return $hasCurrentSale
            ? TechnicalServiceQrLink::TYPE_SOLD_PRODUCT
            : TechnicalServiceQrLink::TYPE_PRE_SALE_PRODUCT;
    }

    /**
     * @param array<string, mixed> $decision
     */
    private function hasDocumentContext(array $decision): bool
    {
        foreach ([
            'fatura_seri',
            'fatura_sira',
            'fatura_belge_no',
            'irsaliye_seri',
            'irsaliye_sira',
            'siparis_seri',
            'siparis_sira',
        ] as $key) {
            if ($this->nullableText($decision[$key] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'evet'], true);
        }

        return false;
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
