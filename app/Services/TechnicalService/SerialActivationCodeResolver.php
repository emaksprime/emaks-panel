<?php

namespace App\Services\TechnicalService;

use App\Models\SupportActivationCode;

class SerialActivationCodeResolver
{
    public function resolve(?string $serialNumber): ?string
    {
        $serialNumber = $this->nullableText($serialNumber);

        if ($serialNumber === null) {
            return null;
        }

        $cleanSerial = $this->serialNumberClean($serialNumber);
        $record = SupportActivationCode::query()
            ->where('is_active', true)
            ->where(function ($query) use ($serialNumber, $cleanSerial): void {
                $query->where('serial_number_clean', $cleanSerial)
                    ->orWhere('serial_number', $serialNumber);
            })
            ->orderByDesc('id')
            ->first();

        if (! $record instanceof SupportActivationCode) {
            return null;
        }

        return $this->nullableText($record->activation_code)
            ?? $this->suffixActivationCode($record->serial_number);
    }

    public function serialNumberClean(string $serialNumber): string
    {
        $serialNumber = trim($serialNumber);
        $lastDash = strrpos($serialNumber, '-');

        if ($lastDash === false) {
            return $serialNumber;
        }

        return trim(substr($serialNumber, 0, $lastDash));
    }

    private function suffixActivationCode(?string $serialNumber): ?string
    {
        $serialNumber = $this->nullableText($serialNumber);

        if ($serialNumber === null) {
            return null;
        }

        $lastDash = strrpos($serialNumber, '-');

        if ($lastDash === false) {
            return null;
        }

        return $this->nullableText(substr($serialNumber, $lastDash + 1));
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
