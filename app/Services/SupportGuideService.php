<?php

namespace App\Services;

use App\Models\SupportGuideEntry;

class SupportGuideService
{
    /**
     * @return array{sourceSheet: string, total: int, entries: list<array<string, mixed>>}
     */
    public function activeGuideData(): array
    {
        $entries = SupportGuideEntry::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return [
            'sourceSheet' => $entries->first()?->source_sheet ?? 'Yahya Düzenleme',
            'total' => $entries->count(),
            'entries' => $entries
                ->map(fn (SupportGuideEntry $entry): array => [
                    'id' => $entry->id,
                    'code' => $entry->code,
                    'sourceRow' => $entry->source_row,
                    'devices' => $this->entryDevices($entry),
                    'deviceAliases' => $this->entryAliases($entry),
                    'method' => $entry->method,
                    'guideType' => $entry->title ?? $entry->guide_type,
                    'sections' => $this->entrySections($entry),
                    'warnings' => $entry->warnings ?? [],
                    'extraNotes' => $entry->extra_notes ?? [],
                    'searchText' => $entry->search_text,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return list<string>
     */
    private function entryDevices(SupportGuideEntry $entry): array
    {
        return collect($entry->devices ?? [])
            ->merge([$entry->product_keyword])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function entryAliases(SupportGuideEntry $entry): array
    {
        return collect($entry->device_aliases ?? [])
            ->merge([$entry->stok_kodu])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<array{title: string|null, steps: list<string>}>
     */
    private function entrySections(SupportGuideEntry $entry): array
    {
        $sections = $entry->sections ?? [];

        if ($sections !== []) {
            return $sections;
        }

        $content = trim((string) $entry->guide_content);

        if ($content === '') {
            return [];
        }

        return [
            [
                'title' => $entry->title ?? $entry->guide_type,
                'steps' => collect(preg_split('/\r\n|\n|\r/', $content) ?: [])
                    ->map(fn (string $line): string => trim($line))
                    ->filter()
                    ->values()
                    ->all(),
            ],
        ];
    }
}
