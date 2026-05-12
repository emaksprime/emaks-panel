<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        if (! Schema::hasTable('panel.support_guide_entries')) {
            Schema::create('panel.support_guide_entries', function (Blueprint $table): void {
                $table->id();
                $table->text('code')->unique();
                $table->text('source_sheet')->default('Yahya Düzenleme');
                $table->integer('source_row')->nullable();
                $table->jsonb('devices')->default('[]');
                $table->jsonb('device_aliases')->default('[]');
                $table->text('method')->nullable();
                $table->text('guide_type');
                $table->jsonb('sections')->default('[]');
                $table->jsonb('warnings')->default('[]');
                $table->jsonb('extra_notes')->default('[]');
                $table->text('search_text')->nullable();
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(100);
                $table->timestampTz('created_at');
                $table->timestampTz('updated_at');
            });
        }

        $this->upsertSupportMetadata($now);
        $this->seedGuideEntries($now);

        DB::table('panel.role_resource_permissions')
            ->whereIn('resource_code', ['support', 'support_keypad_guide', 'support_activation_query'])
            ->delete();
    }

    public function down(): void
    {
        Schema::dropIfExists('panel.support_guide_entries');

        DB::table('panel.page_configs')->whereIn('page_code', ['support_keypad_guide', 'support_activation_query'])->delete();
        DB::table('panel.pages')->whereIn('code', ['support_keypad_guide', 'support_activation_query'])->delete();
        DB::table('panel.resources')->whereIn('code', ['support_keypad_guide', 'support_activation_query'])->delete();
    }

    private function upsertSupportMetadata(mixed $now): void
    {
        foreach ($this->resources() as $resource) {
            DB::table('panel.resources')->updateOrInsert(
                ['code' => $resource['code']],
                [
                    'name' => $resource['name'],
                    'type' => 'page',
                    'description' => $resource['description'],
                    'active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        foreach ($this->pages() as $page) {
            DB::table('panel.pages')->updateOrInsert(
                ['code' => $page['code']],
                [
                    'resource_code' => $page['resource_code'],
                    'name' => $page['name'],
                    'route' => $page['route'],
                    'component' => 'panel/support',
                    'layout_type' => 'module',
                    'icon' => 'life-buoy',
                    'parent_id' => null,
                    'description' => $page['description'],
                    'page_order' => $page['page_order'],
                    'active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        DB::table('panel.page_configs')->updateOrInsert(
            ['page_code' => 'support'],
            [
                'layout_json' => json_encode([
                    'heroEyebrow' => 'Destek',
                    'moduleTabs' => [
                        ['label' => 'Tuşlama ve Kurulum Rehberi', 'href' => '/support/keypad-guide'],
                        ['label' => 'Aktivasyon Sorgu', 'href' => '/support/activation'],
                    ],
                ], JSON_UNESCAPED_UNICODE),
                'filters_json' => json_encode([], JSON_UNESCAPED_UNICODE),
                'datasource_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        foreach (['support_keypad_guide', 'support_activation_query'] as $pageCode) {
            DB::table('panel.page_configs')->updateOrInsert(
                ['page_code' => $pageCode],
                [
                    'layout_json' => json_encode(['heroEyebrow' => 'Destek'], JSON_UNESCAPED_UNICODE),
                    'filters_json' => json_encode([], JSON_UNESCAPED_UNICODE),
                    'datasource_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    private function seedGuideEntries(mixed $now): void
    {
        $payload = json_decode(
            file_get_contents(database_path('data/support-keypad-guide.json')) ?: '{}',
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $entries = $payload['entries'] ?? [];
        $sourceSheet = $payload['source']['sheetName'] ?? 'Yahya Düzenleme';

        foreach (array_chunk($entries, 50) as $chunk) {
            DB::table('panel.support_guide_entries')->upsert(
                array_map(fn (array $entry): array => [
                    'code' => $entry['code'],
                    'source_sheet' => $sourceSheet,
                    'source_row' => $entry['sourceRow'] ?? null,
                    'devices' => json_encode($entry['devices'] ?? [], JSON_UNESCAPED_UNICODE),
                    'device_aliases' => json_encode($entry['deviceAliases'] ?? [], JSON_UNESCAPED_UNICODE),
                    'method' => $entry['method'] ?? null,
                    'guide_type' => $entry['guideType'],
                    'sections' => json_encode($entry['sections'] ?? [], JSON_UNESCAPED_UNICODE),
                    'warnings' => json_encode($entry['warnings'] ?? [], JSON_UNESCAPED_UNICODE),
                    'extra_notes' => json_encode($entry['extraNotes'] ?? [], JSON_UNESCAPED_UNICODE),
                    'search_text' => $entry['searchText'] ?? null,
                    'is_active' => (bool) ($entry['isActive'] ?? true),
                    'sort_order' => $entry['sortOrder'] ?? 100,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk),
                ['code'],
                [
                    'source_sheet',
                    'source_row',
                    'devices',
                    'device_aliases',
                    'method',
                    'guide_type',
                    'sections',
                    'warnings',
                    'extra_notes',
                    'search_text',
                    'is_active',
                    'sort_order',
                    'updated_at',
                ],
            );
        }
    }

    /**
     * @return list<array{code: string, name: string, description: string}>
     */
    private function resources(): array
    {
        return [
            [
                'code' => 'support',
                'name' => 'Destek Merkezi',
                'description' => 'Philips / Emaks Prime cihaz kurulum, tuşlama ve aktivasyon destek ekranı',
            ],
            [
                'code' => 'support_keypad_guide',
                'name' => 'Destek - Tuşlama ve Kurulum Rehberi',
                'description' => 'Cihaz kurulum ve tuşlama rehberi içeriği',
            ],
            [
                'code' => 'support_activation_query',
                'name' => 'Destek - Aktivasyon Sorgu',
                'description' => 'Aktivasyon sorgu ekranı erişimi',
            ],
        ];
    }

    /**
     * @return list<array{code: string, resource_code: string, name: string, route: string, description: string, page_order: int}>
     */
    private function pages(): array
    {
        return [
            [
                'code' => 'support',
                'resource_code' => 'support',
                'name' => 'Destek Merkezi',
                'route' => '/support',
                'description' => 'Philips / Emaks Prime cihaz kurulum, tuşlama ve aktivasyon destek ekranı',
                'page_order' => 95,
            ],
            [
                'code' => 'support_keypad_guide',
                'resource_code' => 'support_keypad_guide',
                'name' => 'Tuşlama ve Kurulum Rehberi',
                'route' => '/support/keypad-guide',
                'description' => 'Cihaz kurulum ve tuşlama rehberi',
                'page_order' => 96,
            ],
            [
                'code' => 'support_activation_query',
                'resource_code' => 'support_activation_query',
                'name' => 'Aktivasyon Sorgu',
                'route' => '/support/activation',
                'description' => 'Aktivasyon sorgu ekranı',
                'page_order' => 97,
            ],
        ];
    }
};
