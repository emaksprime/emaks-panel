<?php

namespace App\Services;

use App\Models\SupportGuideEntry;
use App\Models\SupportKeyingGuideProduct;
use Illuminate\Support\Str;

class SupportGuideService
{
    /**
     * @return array{sourceSheet: string, total: int, entries: list<array<string, mixed>>}
     */
    public function activeGuideData(): array
    {
        $staticEntries = SupportGuideEntry::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $managedProducts = SupportKeyingGuideProduct::query()
            ->with(['steps' => fn ($steps) => $steps
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (SupportKeyingGuideProduct $product): bool => $product->steps->isNotEmpty());
        $managedEntries = collect($managedProducts
            ->flatMap(fn (SupportKeyingGuideProduct $product): array => $this->managedProductEntries($product))
            ->values()
            ->all());
        $staticPayload = collect($staticEntries
            ->map(fn (SupportGuideEntry $entry): array => [
                'id' => $entry->id,
                'code' => $entry->code,
                'sourceRow' => $entry->source_row,
                'devices' => $this->entryDevices($entry),
                'deviceAliases' => $this->entryAliases($entry),
                'method' => $entry->method,
                'guideType' => $entry->guide_type,
                'sections' => $this->entrySections($entry),
                'warnings' => $entry->warnings ?? [],
                'extraNotes' => $entry->extra_notes ?? [],
                'searchText' => $entry->search_text,
            ])
            ->values()
            ->all());

        return [
            'sourceSheet' => $staticEntries->first()?->source_sheet ?? 'Yahya Düzenleme',
            'total' => $staticPayload->count() + $managedEntries->count(),
            'entries' => $managedEntries
                ->merge($staticPayload)
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function matchingGuideForActivation(array $activationItem): ?array
    {
        $haystack = $this->normalized([
            $activationItem['stock_name'] ?? null,
            $activationItem['stock_code'] ?? null,
        ]);

        if ($haystack === '') {
            return null;
        }

        foreach ($this->activeGuideData()['entries'] as $entry) {
            if ($this->entryHasContent($entry) && $this->entryMatchesActivationHaystack($entry, $haystack)) {
                return $entry;
            }
        }

        return null;
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
     * @return array<string, mixed>
     */
    /**
     * @return list<array<string, mixed>>
     */
    private function managedProductEntries(SupportKeyingGuideProduct $product): array
    {
        $keywords = $this->keywords($product->search_keywords);
        return $product->steps
            ->filter(fn ($step): bool => trim((string) $step->content) !== '')
            ->map(fn ($step): array => [
                'id' => 'product-step-'.$step->id,
                'code' => 'support_keying_product_'.$product->id.'_step_'.$step->id,
                'sourceRow' => null,
                'devices' => [$product->product_name],
                'deviceAliases' => $keywords,
                'method' => $step->entry_method,
                'guideType' => $step->entry_format ?: $step->title,
                'sections' => [
                    [
                        'title' => $step->title,
                        'steps' => collect(preg_split('/\r\n|\n|\r/', (string) $step->content) ?: [])
                            ->map(fn (string $line): string => trim($line))
                            ->filter()
                            ->values()
                            ->all(),
                    ],
                ],
                'warnings' => [],
                'extraNotes' => [],
                'searchText' => $this->normalized([
                    $product->product_name,
                    $product->search_keywords,
                    $step->entry_method,
                    $step->entry_format,
                    $step->title,
                    $step->content,
                ]),
            ])
            ->filter(fn (array $entry): bool => ($entry['sections'][0]['steps'] ?? []) !== [])
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

    /**
     * @return list<string>
     */
    private function keywords(?string $value): array
    {
        return collect(preg_split('/[\r\n,;]+/', (string) $value) ?: [])
            ->map(fn (string $keyword): string => trim($keyword))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function entryHasContent(array $entry): bool
    {
        foreach ($entry['sections'] ?? [] as $section) {
            if (collect($section['steps'] ?? [])->contains(fn ($step): bool => trim((string) $step) !== '')) {
                return true;
            }
        }

        return false;
    }

    private function entryMatchesActivationHaystack(array $entry, string $haystack): bool
    {
        $needles = collect($entry['devices'] ?? [])
            ->merge($entry['deviceAliases'] ?? [])
            ->map(fn ($value): string => $this->normalized([$value]))
            ->filter(fn (string $value): bool => strlen($value) >= 2)
            ->values();

        return $needles->contains(fn (string $needle): bool => str_contains($haystack, $needle) || str_contains($needle, $haystack));
    }

    private function normalized(array $values): string
    {
        return collect($values)
            ->filter(fn ($value): bool => $value !== null && trim((string) $value) !== '')
            ->map(fn ($value): string => Str::of((string) $value)
                ->lower()
                ->ascii()
                ->replaceMatches('/[^a-z0-9]+/', ' ')
                ->squish()
                ->toString())
            ->filter()
            ->implode(' ');
    }
}
