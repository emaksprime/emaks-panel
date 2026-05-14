<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceQrLink;
use Throwable;

class SerialProductContextResolver
{
    public function __construct(
        private readonly SerialActivationCodeResolver $activationCodeResolver,
        private readonly MikroSerialNumberService $mikroSerialNumberService,
    ) {
    }

    /**
     * @param array<string, mixed> $knownContext
     * @return array{serial_number:string,product_name:?string,product_model:?string,brand:?string,activation_code:?string,sale_mount_status:string,invoice_customer_type:string,suggested_link_type:string,context_payload:array<string,mixed>}
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
        $suggestedLinkType = TechnicalServiceQrLink::TYPE_PRE_SALE_PRODUCT;
        $payload = [
            'source' => 'known_context',
            'known_context' => $knownContext,
        ];

        try {
            $decision = $this->mikroSerialNumberService->checkInstallation($serialNumber);
            $saleMountStatus = $this->mapMikroMountStatus($decision['montaj_durumu'] ?? null);
            $productName = $productName ?? $this->firstText($decision, ['stok_adi', 'stock_name', 'Stok Adı', 'Stok Adi', 'product_name']);
            $productModel = $productModel
                ?? $this->firstText($decision, ['model_adi', 'model_name', 'Model Adı', 'Model Adi', 'product_model'])
                ?? $this->deriveModel($productName);
            $stockCode = $stockCode ?? $this->firstText($decision, ['stok_kodu', 'stock_code', 'Stok Kodu']);
            $brand = $brand
                ?? $this->firstText($decision, ['marka_kodu', 'brand', 'Marka Kodu'])
                ?? $this->deriveBrand($productName);
            $suggestedLinkType = $this->suggestedLinkType($decision, $saleMountStatus);
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

        return [
            'serial_number' => $serialNumber,
            'product_name' => $productName,
            'product_model' => $productModel,
            'brand' => $brand ?? $this->deriveBrand($productName),
            'activation_code' => $activationCode,
            'sale_mount_status' => $saleMountStatus,
            'invoice_customer_type' => $invoiceCustomerType,
            'suggested_link_type' => $suggestedLinkType,
            'context_payload' => [
                ...$payload,
                'stock_code' => $stockCode,
                'activation_code' => $activationCode,
                'suggested_link_type' => $suggestedLinkType,
            ],
        ];
    }

    private function mapMikroMountStatus(mixed $status): string
    {
        $status = $this->nullableText($status);

        return match ($status) {
            'Montaj Dahil' => TechnicalServiceMountSession::SALE_MONTAJ_DAHIL,
            'Montaj Sonradan Dahil' => TechnicalServiceMountSession::SALE_MONTAJ_SONRADAN_DAHIL,
            'Montaj Hariç', 'Montaj Haric' => TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            'Seri No Bulunamadı', 'Seri No Bulunamadi' => TechnicalServiceMountSession::SALE_NOT_FOUND,
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

        if (str_contains($value, 'GALAXY')) {
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
            'GRİ' => ['GRİ', 'GRI', 'GREY', 'GRAY'],
            'SİYAH' => ['SİYAH', 'SIYAH', 'BLACK'],
            'BEYAZ' => ['BEYAZ', 'WHITE'],
            'ALTIN' => ['ALTIN', 'GOLD'],
            'GÜMÜŞ' => ['GÜMÜŞ', 'GUMUS', 'SILVER'],
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
    private function suggestedLinkType(array $decision, string $saleMountStatus): string
    {
        if (($decision['found'] ?? true) === false || $saleMountStatus === TechnicalServiceMountSession::SALE_NOT_FOUND) {
            return TechnicalServiceQrLink::TYPE_PRE_SALE_PRODUCT;
        }

        $hasSafeDocumentContext = collect([
            'fatura_seri',
            'fatura_sira',
            'fatura_belge_no',
            'irsaliye_seri',
            'irsaliye_sira',
            'siparis_seri',
            'siparis_sira',
        ])->contains(fn (string $key): bool => $this->nullableText($decision[$key] ?? null) !== null);

        return $hasSafeDocumentContext
            ? TechnicalServiceQrLink::TYPE_SOLD_PRODUCT
            : TechnicalServiceQrLink::TYPE_PRE_SALE_PRODUCT;
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
