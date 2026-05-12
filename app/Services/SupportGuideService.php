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
                    'devices' => $entry->devices ?? [],
                    'deviceAliases' => $entry->device_aliases ?? [],
                    'method' => $entry->method,
                    'guideType' => $entry->guide_type,
                    'sections' => $entry->sections ?? [],
                    'warnings' => $entry->warnings ?? [],
                    'extraNotes' => $entry->extra_notes ?? [],
                    'searchText' => $entry->search_text,
                ])
                ->values()
                ->all(),
        ];
    }
}
