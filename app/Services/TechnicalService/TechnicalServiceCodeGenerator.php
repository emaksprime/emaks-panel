<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceRequest;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

class TechnicalServiceCodeGenerator
{
    public function nextMrn(?string $customerName = null, ?CarbonInterface $date = null): string
    {
        $date ??= now();
        $yearMonth = $date->format('ym');
        $day = $date->format('d');
        $initials = $this->customerInitials($customerName);
        $sequence = $this->nextDailySequence($yearMonth, $day);

        do {
            $mrn = sprintf('MRN-%s%s%s%04d', $yearMonth, $initials, $day, $sequence);
            $sequence++;
        } while (TechnicalServiceRequest::query()->where('mrn', $mrn)->exists());

        return $mrn;
    }

    public function serviceCodeForRoot(string $rootMrn, int $sequence): string
    {
        return sprintf('SRV-%s-%03d', $this->rootMrnBody($rootMrn), max(1, $sequence));
    }

    private function nextDailySequence(string $yearMonth, string $day): int
    {
        $pattern = sprintf('MRN-%s__%s____', $yearMonth, $day);
        $max = TechnicalServiceRequest::query()
            ->where('mrn', 'like', $pattern)
            ->pluck('mrn')
            ->map(function (string $mrn): int {
                if (preg_match('/(\d{4})$/', $mrn, $matches)) {
                    return (int) $matches[1];
                }

                return 0;
            })
            ->max();

        return ((int) $max) + 1;
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
