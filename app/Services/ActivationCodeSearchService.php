<?php

namespace App\Services;

use App\Models\ActivationCodeRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ActivationCodeSearchService
{
    public const MIN_QUERY_LENGTH = 6;

    public function search(string $query, int $limit = 50): array
    {
        $rawQuery = trim($query);
        $normalizedQuery = $this->normalizeSearchValue($rawQuery);

        $records = ActivationCodeRecord::query()
            ->where(function (Builder $builder) use ($rawQuery, $normalizedQuery): void {
                if ($rawQuery !== '') {
                    $builder->orWhereRaw('LOWER(serial_prefix) like ?', ['%'.Str::lower($rawQuery).'%']);
                }

                if ($normalizedQuery !== '') {
                    $builder->orWhere('serial_prefix_clean', 'like', '%'.$normalizedQuery.'%');

                    if (strlen($normalizedQuery) === 6) {
                        $builder->orWhere('serial_prefix_tail_6', $normalizedQuery);
                    }

                    if (strlen($normalizedQuery) === 10) {
                        $builder->orWhere('serial_prefix_tail_10', $normalizedQuery);
                    }
                }
            })
            ->orderByDesc('imported_at')
            ->orderBy('stock_name')
            ->limit($limit)
            ->get();

        return [
            'query' => $rawQuery,
            'normalized_query' => $normalizedQuery,
            'count' => $records->count(),
            'items' => $this->mapRecords($records),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildRecordPayload(
        string $serialNo,
        ?string $stockCode = null,
        ?string $stockName = null,
        ?string $sourceFileName = null,
    ): array {
        $serialNo = trim($serialNo);
        $serialPrefix = $this->serialPrefix($serialNo);
        $serialPrefixClean = $this->normalizeSearchValue($serialPrefix);
        $activationCode = $this->activationCodeFromSerial($serialNo);
        $serialNoClean = $this->normalizeSearchValue($serialNo);

        return [
            'stock_code' => $this->nullableText($stockCode),
            'stock_name' => $this->nullableText($stockName),
            'serial_no' => $serialNo,
            'serial_prefix' => $serialPrefix,
            'serial_prefix_clean' => $serialPrefixClean,
            'serial_prefix_tail_6' => $this->tail($serialPrefixClean, 6),
            'serial_prefix_tail_10' => $this->tail($serialPrefixClean, 10),
            'activation_code' => $activationCode,
            'serial_no_clean' => $serialNoClean,
            'serial_tail_6' => $this->tail($serialNoClean, 6),
            'serial_tail_10' => $this->tail($serialNoClean, 10),
            'search_code' => $activationCode !== null ? $this->normalizeSearchValue($activationCode) : $this->tail($serialNoClean, 6),
            'source_file_name' => $this->nullableText($sourceFileName),
            'imported_at' => now(),
        ];
    }

    public function activationCodeFromSerial(?string $serialNo): ?string
    {
        $serialNo = trim((string) $serialNo);

        if ($serialNo === '' || ! str_contains($serialNo, '-')) {
            return null;
        }

        $suffix = trim((string) Str::afterLast($serialNo, '-'));

        return $suffix !== '' ? $suffix : null;
    }

    public function normalizeSearchValue(?string $value): string
    {
        $value = Str::upper(trim((string) $value));

        return preg_replace('/[^A-Z0-9]+/u', '', $value) ?? '';
    }

    public function minimumQueryLength(): int
    {
        return self::MIN_QUERY_LENGTH;
    }

    public function serialPrefix(?string $serialNo): ?string
    {
        $serialNo = trim((string) $serialNo);

        if ($serialNo === '') {
            return null;
        }

        if (! str_contains($serialNo, '-')) {
            return $serialNo;
        }

        $prefix = trim((string) Str::beforeLast($serialNo, '-'));

        return $prefix !== '' ? $prefix : null;
    }

    private function tail(string $value, int $length): ?string
    {
        if ($value === '') {
            return null;
        }

        return strlen($value) <= $length ? $value : substr($value, -$length);
    }

    /**
     * @param  Collection<int, ActivationCodeRecord>  $records
     * @return array<int, array<string, mixed>>
     */
    private function mapRecords(Collection $records): array
    {
        return $records
            ->map(fn (ActivationCodeRecord $record) => [
                'id' => $record->id,
                'serial_no' => $record->serial_no,
                'serial_prefix' => $record->serial_prefix,
                'stock_name' => $record->stock_name,
                'stock_code' => $record->stock_code,
                'activation_code' => $record->activation_code,
                'activation_code_missing' => $record->activation_code === null || $record->activation_code === '',
            ])
            ->values()
            ->all();
    }

    private function nullableText(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
