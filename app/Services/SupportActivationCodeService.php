<?php

namespace App\Services;

use App\Models\SupportActivationCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SupportActivationCodeService
{
    public const MIN_QUERY_LENGTH = 2;

    /**
     * @return array{query: string, normalized_query: string, count: int, total: int, items: list<array<string, mixed>>}
     */
    public function search(string $query, int $limit = 50): array
    {
        $rawQuery = trim($query);
        $normalizedQuery = $this->normalizeSearchValue($rawQuery);
        $digits = preg_replace('/\D+/', '', $rawQuery) ?? '';
        $needle = Str::lower($rawQuery);

        if ($rawQuery === '' || strlen($normalizedQuery) < self::MIN_QUERY_LENGTH) {
            return [
                'query' => $rawQuery,
                'normalized_query' => $normalizedQuery,
                'count' => 0,
                'total' => $this->activeCount(),
                'items' => [],
            ];
        }

        $records = SupportActivationCode::query()
            ->where('is_active', true)
            ->where(function (Builder $builder) use ($needle, $normalizedQuery, $digits): void {
                if ($needle !== '') {
                    $builder
                        ->orWhereRaw('LOWER(stock_code) like ?', ['%'.$needle.'%'])
                        ->orWhereRaw('LOWER(stock_name) like ?', ['%'.$needle.'%'])
                        ->orWhereRaw('LOWER(serial_number) like ?', ['%'.$needle.'%'])
                        ->orWhereRaw('LOWER(serial_number_clean) like ?', ['%'.$needle.'%'])
                        ->orWhereRaw('LOWER(search_code) like ?', ['%'.$needle.'%'])
                        ->orWhereRaw('LOWER(activation_code) like ?', ['%'.$needle.'%'])
                        ->orWhereRaw('LOWER(search_text) like ?', ['%'.$needle.'%']);
                }

                if ($normalizedQuery !== '') {
                    $builder
                        ->orWhereRaw('LOWER(stock_code) like ?', ['%'.Str::lower($normalizedQuery).'%'])
                        ->orWhereRaw('LOWER(stock_name) like ?', ['%'.Str::lower($normalizedQuery).'%'])
                        ->orWhereRaw('LOWER(serial_number) like ?', ['%'.Str::lower($normalizedQuery).'%'])
                        ->orWhereRaw('LOWER(serial_number_clean) like ?', ['%'.Str::lower($normalizedQuery).'%'])
                        ->orWhereRaw('LOWER(search_code) like ?', ['%'.Str::lower($normalizedQuery).'%'])
                        ->orWhereRaw('LOWER(activation_code) like ?', ['%'.Str::lower($normalizedQuery).'%'])
                        ->orWhereRaw('LOWER(search_text) like ?', ['%'.Str::lower($normalizedQuery).'%'])
                        ->orWhere('code', 'like', '%'.$normalizedQuery.'%');
                }

                if ($digits !== '') {
                    $builder
                        ->orWhereRaw('LOWER(serial_number) like ?', ['%'.$digits.'%'])
                        ->orWhereRaw('LOWER(serial_number_clean) like ?', ['%'.$digits.'%'])
                        ->orWhereRaw('LOWER(search_code) like ?', ['%'.$digits.'%'])
                        ->orWhereRaw('LOWER(activation_code) like ?', ['%'.$digits.'%'])
                        ->orWhereRaw('LOWER(search_text) like ?', ['%'.$digits.'%']);
                }
            })
            ->orderBy('stock_name')
            ->orderBy('serial_number')
            ->limit($limit)
            ->get();

        return [
            'query' => $rawQuery,
            'normalized_query' => $normalizedQuery,
            'count' => $records->count(),
            'total' => $this->activeCount(),
            'items' => $this->mapRecords($records),
        ];
    }

    public function activeCount(): int
    {
        return SupportActivationCode::query()
            ->where('is_active', true)
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    public function buildRecordPayload(array $record): array
    {
        $stockCode = $this->nullableText($record['stock_code'] ?? $record['stockCode'] ?? null);
        $stockName = $this->nullableText($record['stock_name'] ?? $record['stockName'] ?? $record['product_model'] ?? $record['productModel'] ?? null);
        $serialNumber = $this->nullableText($record['serial_number'] ?? $record['serialNo'] ?? null);
        $serialNumberClean = $this->nullableText($record['serial_number_clean'] ?? $record['serialNumberClean'] ?? null);
        $searchCode = $this->nullableText($record['search_code'] ?? $record['searchCode'] ?? null);
        $activationCode = $this->nullableText($record['activation_code'] ?? $record['activationCode'] ?? null);
        $activationLink = $this->nullableText($record['activation_link'] ?? $record['activationLink'] ?? null);
        $metadata = is_array($record['metadata'] ?? null) ? $record['metadata'] : [];
        [$serialNumberClean, $activationCode] = $this->applySerialSuffixActivationCode(
            $serialNumber,
            $serialNumberClean,
            $activationCode,
        );
        $code = $this->nullableText($record['code'] ?? null)
            ?? $this->recordCode($serialNumberClean, $activationCode, $serialNumber);

        return [
            'code' => $code,
            'stock_code' => $stockCode,
            'stock_name' => $stockName,
            'serial_number' => $serialNumber,
            'serial_number_clean' => $serialNumberClean,
            'search_code' => $searchCode,
            'activation_code' => $activationCode,
            'activation_link' => $activationLink,
            'metadata' => $metadata,
            'search_text' => $this->nullableText($record['search_text'] ?? $record['searchText'] ?? null)
                ?? $this->searchText([
                    $code,
                    $stockCode,
                    $stockName,
                    $serialNumber,
                    $serialNumberClean,
                    $searchCode,
                    $activationCode,
                    $activationLink,
                    json_encode($metadata, JSON_UNESCAPED_UNICODE),
                ]),
            'is_active' => (bool) ($record['is_active'] ?? $record['isActive'] ?? true),
        ];
    }

    public function normalizeSearchValue(?string $value): string
    {
        $value = Str::upper(trim((string) $value));

        return preg_replace('/[^A-Z0-9]+/u', '', $value) ?? '';
    }

    /**
     * @param Collection<int, SupportActivationCode> $records
     * @return list<array<string, mixed>>
     */
    private function mapRecords(Collection $records): array
    {
        return $records
            ->map(fn (SupportActivationCode $record): array => [
                'id' => $record->id,
                'code' => $record->code,
                'stock_code' => $record->stock_code,
                'stock_name' => $record->stock_name,
                'serial_number' => $record->serial_number,
                'serial_number_clean' => $record->serial_number_clean,
                'search_code' => $record->search_code,
                'activation_code' => $record->activation_code,
                'activation_link' => $record->activation_link,
                'metadata' => $record->metadata ?? [],
            ])
            ->values()
            ->all();
    }

    private function recordCode(
        ?string $serialNumberClean,
        ?string $activationCode,
        ?string $serialNumber,
    ): string {
        $serialNumberClean = $this->normalizeSearchValue($serialNumberClean);
        $activationCode = $this->normalizeSearchValue($activationCode);

        if ($serialNumberClean !== '' && $activationCode !== '') {
            return $serialNumberClean.'-'.$activationCode;
        }

        $serialNumber = $this->normalizeSearchValue($serialNumber);

        if ($serialNumber !== '') {
            return $serialNumber;
        }

        if ($serialNumberClean !== '') {
            return $serialNumberClean;
        }

        return 'support_activation_'.sha1((string) Str::uuid());
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function applySerialSuffixActivationCode(
        ?string $serialNumber,
        ?string $serialNumberClean,
        ?string $activationCode,
    ): array {
        if ($serialNumber === null || ! str_contains($serialNumber, '-')) {
            return [$serialNumberClean, $activationCode];
        }

        $lastDash = strrpos($serialNumber, '-');

        if ($lastDash === false) {
            return [$serialNumberClean, $activationCode];
        }

        $serialPrefix = $this->nullableText(substr($serialNumber, 0, $lastDash));
        $serialSuffix = $this->nullableText(substr($serialNumber, $lastDash + 1));

        return [
            $serialPrefix ?? $serialNumberClean,
            $serialSuffix ?? $activationCode,
        ];
    }

    private function searchText(array $values): string
    {
        return collect($values)
            ->filter(fn ($value): bool => $value !== null && trim((string) $value) !== '')
            ->flatMap(fn ($value): array => [
                Str::lower((string) $value),
                Str::lower($this->normalizeSearchValue((string) $value)),
                preg_replace('/\D+/', '', (string) $value) ?? '',
            ])
            ->filter()
            ->unique()
            ->implode(' ');
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
