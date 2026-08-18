<?php

namespace App\Services\TechnicalService;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class TechnicalServiceCodeGenerator
{
    public const ROOT_MRN_SEQUENCE = 'technical_service_root_mrn_sequence';

    public function nextMrn(?string $customerName = null, ?CarbonInterface $date = null): string
    {
        $date ??= now();
        $yearMonth = $date->format('ym');
        $day = $date->format('d');
        $initials = $this->customerInitials($customerName);
        $sequence = $this->nextGlobalSequence();

        return sprintf(
            'MRN-%s%s%s%s',
            $yearMonth,
            $initials,
            $day,
            str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
        );
    }

    public function serviceCodeForRoot(string $rootMrn, int $sequence): string
    {
        return sprintf('SRV-%s-%03d', $this->rootMrnBody($rootMrn), max(1, $sequence));
    }

    private function nextGlobalSequence(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Global root MRN allocation requires PostgreSQL.');
        }

        $row = DB::selectOne(
            "select nextval('technical_service_root_mrn_sequence'::regclass) as sequence_value"
        );
        $sequence = (int) ($row?->sequence_value ?? 0);

        if ($sequence < 1) {
            throw new RuntimeException('Global root MRN sequence returned an invalid value.');
        }

        return $sequence;
    }

    private function customerInitials(?string $customerName): string
    {
        $name = trim((string) preg_replace('/\s+/', ' ', (string) $customerName));

        if ($name === '') {
            return 'XX';
        }

        $parts = explode(' ', $name);
        $first = $parts[0] ?? '';
        $last = count($parts) > 1 ? (string) end($parts) : '';

        return $this->initial($first, 'X').$this->initial($last, 'X');
    }

    private function initial(string $value, string $fallback): string
    {
        $normalized = $this->normalizeTurkish($value);

        return preg_match('/[A-Z]/', $normalized, $matches) === 1 ? $matches[0] : $fallback;
    }

    private function normalizeTurkish(string $value): string
    {
        $value = strtr($value, [
            'ı' => 'i',
            'İ' => 'I',
            'ğ' => 'g',
            'Ğ' => 'G',
            'ü' => 'u',
            'Ü' => 'U',
            'ş' => 's',
            'Ş' => 'S',
            'ö' => 'o',
            'Ö' => 'O',
            'ç' => 'c',
            'Ç' => 'C',
        ]);

        return Str::upper($value);
    }

    private function rootMrnBody(string $rootMrn): string
    {
        $rootMrn = trim($rootMrn);

        return str_starts_with($rootMrn, 'MRN-') ? substr($rootMrn, 4) : $rootMrn;
    }
}
