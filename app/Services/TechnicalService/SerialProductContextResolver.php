<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceMountSession;
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
     * @return array{serial_number:string,product_name:?string,product_model:?string,brand:?string,activation_code:?string,sale_mount_status:string,invoice_customer_type:string,context_payload:array<string,mixed>}
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
        $payload = [
            'source' => 'known_context',
            'known_context' => $knownContext,
        ];

        try {
            $decision = $this->mikroSerialNumberService->checkInstallation($serialNumber);
            $saleMountStatus = $this->mapMikroMountStatus($decision['montaj_durumu'] ?? null);
            $productName = $productName ?? $this->nullableText($decision['stok_adi'] ?? null);
            $stockCode = $stockCode ?? $this->nullableText($decision['stok_kodu'] ?? null);
            $brand = $brand ?? $this->deriveBrand($productName);
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
            'invoice_customer_type' => $knownContext['invoice_customer_type'] ?? 'unknown',
            'context_payload' => [
                ...$payload,
                'stock_code' => $stockCode,
                'activation_code' => $activationCode,
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

        return null;
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
