<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class SupportDashboardTest extends TestCase
{
    public function test_support_resources_routes_and_permissions_are_declared(): void
    {
        $metadataMigration = $this->read('database/migrations/2026_05_12_120000_add_support_module_metadata.php');
        $dataMigration = $this->read('database/migrations/2026_05_12_130000_create_support_guide_entries_table.php');
        $routes = $this->read('routes/web.php');

        foreach (['support', 'support_keypad_guide', 'support_activation_query'] as $resourceCode) {
            $this->assertStringContainsString("'code' => '{$resourceCode}'", $metadataMigration.$dataMigration);
        }

        $this->assertStringContainsString("'route' => '/support'", $metadataMigration.$dataMigration);
        $this->assertStringContainsString("'route' => '/support/keypad-guide'", $metadataMigration.$dataMigration);
        $this->assertStringContainsString("'route' => '/support/activation'", $metadataMigration.$dataMigration);
        $this->assertStringContainsString("Route::get('support', [SupportController::class, 'index'])", $routes);
        $this->assertStringContainsString("Route::get('support/keypad-guide', [SupportController::class, 'keypadGuide'])", $routes);
        $this->assertStringContainsString("Route::get('support/activation', [SupportController::class, 'activation'])", $routes);
        $this->assertStringContainsString("Route::get('activation/search'", $routes);
        $this->assertStringContainsString("->middleware('panel.access:support')", $routes);
        $this->assertStringContainsString("->middleware(['panel.access:support', 'panel.access:support_keypad_guide'])", $routes);
        $this->assertStringContainsString("->middleware(['panel.access:support', 'panel.access:support_activation_query'])", $routes);
        $this->assertStringContainsString("->whereIn('resource_code', ['support', 'support_keypad_guide', 'support_activation_query'])", $dataMigration);
        $this->assertStringNotContainsString("DB::table('panel.roles')", $metadataMigration);
    }

    public function test_support_guide_table_and_seed_snapshot_are_declared(): void
    {
        $dataMigration = $this->read('database/migrations/2026_05_12_130000_create_support_guide_entries_table.php');
        $snapshot = $this->snapshot();

        $this->assertStringContainsString("Schema::create('panel.support_guide_entries'", $dataMigration);
        foreach ([
            'code',
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
        ] as $column) {
            $this->assertStringContainsString("'{$column}'", $dataMigration);
        }

        $this->assertSame(295, $snapshot['source']['parsedRecords']);
        $this->assertSame(295, $snapshot['source']['tableRecords']);
        $this->assertSame(263, $snapshot['source']['activeRecords']);
        $this->assertCount(295, $snapshot['entries']);
    }

    public function test_support_snapshot_keeps_aliases_sections_and_clean_titles(): void
    {
        $entries = $this->snapshot()['entries'];

        $this->assertNotEmpty(array_filter(
            $entries,
            fn (array $entry): bool => ($entry['isActive'] ?? false)
                && in_array('DDL720', $entry['deviceAliases'] ?? [], true)
        ));
        $this->assertNotEmpty(array_filter(
            $entries,
            fn (array $entry): bool => ($entry['isActive'] ?? false)
                && in_array('Galaxy 20', $entry['deviceAliases'] ?? [], true)
        ));
        $this->assertSame([], array_values(array_filter(
            $entries,
            fn (array $entry): bool => str_contains((string) ($entry['guideType'] ?? ''), 'Belirtilmemi')
                || str_contains((string) ($entry['guideType'] ?? ''), 'Belirtilmemiş')
        )));
        $this->assertNotEmpty(array_filter(
            $entries,
            fn (array $entry): bool => ($entry['method'] ?? null) === 'Uygulama ile Eşleme'
                && $this->entryHasSection($entry, 'Sıfırlama')
        ));
    }

    public function test_support_frontend_reads_inertia_props_not_static_frontend_data(): void
    {
        $supportPage = $this->read('resources/js/pages/panel/support.tsx');
        $moduleLayout = $this->read('resources/js/layouts/module-layout.tsx');

        $this->assertFileDoesNotExist($this->absolutePath('resources/js/data/support-keypad-guide.ts'));
        $this->assertStringContainsString('supportGuideData?.entries', $supportPage);
        $this->assertStringNotContainsString('@/data/support-keypad-guide', $supportPage);
        $this->assertStringContainsString('supportPermissions', $supportPage);
        $this->assertStringContainsString('/support/keypad-guide', $supportPage);
        $this->assertStringContainsString('/support/activation', $supportPage);
        $this->assertStringContainsString('deviceAliasMatchers', $supportPage);
        $this->assertStringContainsString('applyFilterCascade', $supportPage);
        $this->assertStringContainsString("item.type === 'section'", $supportPage);
        $this->assertStringContainsString('/api/support/activation/search', $supportPage);
        $this->assertStringContainsString('Henüz aktarılmış aktivasyon kaydı yok', $supportPage);
        $this->assertStringNotContainsString('/activation-code-search', $supportPage);
        $this->assertMatchesRegularExpression("/candidates:\s*\[\s*'\/support',\s*'\/support\/keypad-guide',\s*'\/support\/activation',?\s*\]/", $moduleLayout);
        $this->assertMatchesRegularExpression("/match:\s*\[\s*'\/support',\s*'\/support\/keypad-guide',\s*'\/support\/activation',?\s*\]/", $moduleLayout);
        $this->assertStringNotContainsString('Belirtilmemiş?', $supportPage);
    }

    public function test_support_backend_contract_exists(): void
    {
        $controller = $this->read('app/Http/Controllers/SupportController.php');
        $service = $this->read('app/Services/SupportGuideService.php');
        $activationService = $this->read('app/Services/SupportActivationCodeService.php');
        $model = $this->read('app/Models/SupportGuideEntry.php');
        $activationModel = $this->read('app/Models/SupportActivationCode.php');
        $admin = $this->read('app/Http/Controllers/Api/AdminController.php');

        $this->assertStringContainsString("'keypadGuide' => \$this->access->userCanAccess(\$user, 'support_keypad_guide')", $controller);
        $this->assertStringContainsString("'activationQuery' => \$this->access->userCanAccess(\$user, 'support_activation_query')", $controller);
        $this->assertStringContainsString('supportActivationSummary', $controller);
        $this->assertStringContainsString('activeGuideData', $service);
        $this->assertStringContainsString("->where('is_active', true)", $service);
        $this->assertStringContainsString('class SupportActivationCodeService', $activationService);
        $this->assertStringContainsString('public function search', $activationService);
        $this->assertStringContainsString("protected \$table = 'panel.support_guide_entries';", $model);
        $this->assertStringContainsString("protected \$table = 'panel.support_activation_codes';", $activationModel);
        $this->assertStringContainsString("str_starts_with(\$code, 'support') => 'Destek'", $admin);
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents($this->absolutePath($relativePath));

        $this->assertIsString($contents);

        return $contents;
    }

    private function absolutePath(string $relativePath): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.$relativePath;
    }

    private function entryHasSection(array $entry, string $title): bool
    {
        foreach ($entry['sections'] ?? [] as $section) {
            if (($section['title'] ?? null) === $title) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{source: array<string, mixed>, entries: list<array<string, mixed>>}
     */
    private function snapshot(): array
    {
        $snapshot = json_decode(
            $this->read('database/data/support-keypad-guide.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertIsArray($snapshot);

        return $snapshot;
    }
}
