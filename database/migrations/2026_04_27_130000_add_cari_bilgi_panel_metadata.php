<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach ([
            ['code' => 'cari_bilgi', 'name' => 'Musteri Bilgi Sayfa Erisimi', 'type' => 'page'],
            ['code' => 'finance_cari_bilgi', 'name' => 'Musteri Bilgi', 'type' => 'page'],
            ['code' => 'finance_cari_bilgi_all', 'name' => 'Musteri Bilgi Tum Cariler', 'type' => 'scope'],
            ['code' => 'cari_bilgi_dashboard', 'name' => 'Musteri Bilgi Veri Kaynagi', 'type' => 'data_source'],
        ] as $resource) {
            DB::table('panel.resources')->updateOrInsert(
                ['code' => $resource['code']],
                [
                    'name' => $resource['name'],
                    'type' => $resource['type'],
                    'active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }

        DB::table('panel.pages')->updateOrInsert(
            ['code' => 'cari_bilgi'],
            [
                'resource_code' => 'finance_cari_bilgi',
                'name' => 'Müşteri Bilgi',
                'route' => '/finance/cari-bilgi',
                'component' => 'panel/cari-bilgi',
                'layout_type' => 'module',
                'icon' => 'wallet-cards',
                'parent_id' => null,
                'description' => 'Cari bakiye, açık sipariş ve genel durum takibi',
                'page_order' => 101,
                'active' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        $pageId = DB::table('panel.pages')->where('code', 'cari_bilgi')->value('id');
        $groupId = DB::table('panel.menu_groups')->where('code', 'executive')->value('id');

        if ($pageId && $groupId) {
            DB::table('panel.page_menu')->updateOrInsert(
                ['menu_group_id' => $groupId, 'page_id' => $pageId],
                [
                    'label' => 'Müşteri Bilgi',
                    'icon' => 'wallet-cards',
                    'sort_order' => 101,
                    'is_visible' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }

        DB::table('panel.data_sources')->updateOrInsert(
            ['code' => 'cari_bilgi_dashboard'],
            [
                'name' => 'Müşteri Bilgi Dashboard',
                'db_type' => 'n8n_json',
                'query_template' => $this->queryTemplate(),
                'allowed_params' => json_encode(['search', 'rep_code', 'limit', 'scope_key']),
                'connection_meta' => json_encode([
                    'driver' => 'n8n_json',
                    'method' => 'POST',
                    'endpoint_url' => 'https://hook.emaksprime.com.tr/webhook/panel-data-source-run-v1',
                    'response_rows_key' => 'rows',
                    'source_workflow' => 'PANEL - MSSQL Gateway - DataSource Runner v1',
                    'sql_policy' => 'unchanged',
                    'target' => 'finance.cari_bilgi',
                ]),
                'preview_payload' => json_encode([]),
                'active' => true,
                'sort_order' => 29,
                'description' => 'Müşteri Bilgi ekranı için n8n JSON gateway metadata kaydı.',
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        $sourceId = DB::table('panel.data_sources')->where('code', 'cari_bilgi_dashboard')->value('id');

        DB::table('panel.page_configs')->updateOrInsert(
            ['page_code' => 'cari_bilgi'],
            [
                'layout_json' => json_encode([
                    'heroEyebrow' => 'Cari Yönetimi',
                    'previewNotice' => 'Canlı veri n8n gateway üzerinden alınır.',
                    'moduleTabs' => [
                        ['label' => 'Cari Liste', 'href' => '/cari'],
                        ['label' => 'Cari Bakiye', 'href' => '/cari/balance'],
                        ['label' => 'Cari Detay', 'href' => '/cari/detail'],
                        ['label' => 'Cari Ekstre', 'href' => '/cari/detail'],
                        ['label' => 'Müşteri Bilgi', 'href' => '/finance/cari-bilgi'],
                    ],
                ]),
                'filters_json' => json_encode([
                    'defaults' => ['scopeKey' => 'own', 'limit' => 20],
                    'limits' => [20, 50, 100],
                ]),
                'datasource_id' => $sourceId,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        foreach (DB::table('panel.roles')->pluck('code') as $roleCode) {
            $this->grant($roleCode, 'cari_bilgi', in_array($roleCode, ['admin', 'manager', 'sales'], true), $roleCode === 'admin', $now);
            $this->grant($roleCode, 'finance_cari_bilgi', in_array($roleCode, ['admin', 'manager', 'sales'], true), $roleCode === 'admin', $now);
            $this->grant($roleCode, 'finance_cari_bilgi_all', in_array($roleCode, ['admin', 'manager'], true), $roleCode === 'admin', $now);
        }
    }

    public function down(): void
    {
        DB::table('panel.role_resource_permissions')
            ->whereIn('resource_code', ['cari_bilgi', 'finance_cari_bilgi', 'finance_cari_bilgi_all'])
            ->delete();

        DB::table('panel.page_configs')->where('page_code', 'cari_bilgi')->delete();

        $pageId = DB::table('panel.pages')->where('code', 'cari_bilgi')->value('id');

        if ($pageId) {
            DB::table('panel.page_menu')->where('page_id', $pageId)->delete();
        }

        DB::table('panel.pages')->where('code', 'cari_bilgi')->delete();
        DB::table('panel.data_sources')->where('code', 'cari_bilgi_dashboard')->delete();
        DB::table('panel.resources')
            ->whereIn('code', ['cari_bilgi', 'finance_cari_bilgi', 'finance_cari_bilgi_all', 'cari_bilgi_dashboard'])
            ->delete();
    }

    private function grant(string $roleCode, string $resourceCode, bool $canView, bool $canExecute, mixed $now): void
    {
        DB::table('panel.role_resource_permissions')->updateOrInsert(
            ['role_code' => $roleCode, 'resource_code' => $resourceCode],
            [
                'can_view' => $canView,
                'can_execute' => $canExecute,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );
    }

    private function queryTemplate(): string
    {
        return <<<'SQL'
SELECT TOP (
    CASE
        WHEN TRY_CONVERT(int, NULLIF('{{limit}}', '')) IN (20, 50, 100)
            THEN TRY_CONVERT(int, NULLIF('{{limit}}', ''))
        ELSE 20
    END
)
    cari_kodu,
    cari_unvani,
    bakiye,
    bakiye_durumu,
    onayli_acik_siparis_tutari,
    onay_bekleyen_siparis_tutari,
    genel_durum,
    temsilci_kodu
FROM dbo.vw_panel_cari_bilgi_dashboard
WHERE (
        NULLIF('{{search}}', '') IS NULL
        OR cari_kodu LIKE N'%{{search}}%'
        OR cari_unvani LIKE N'%{{search}}%'
    )
    AND (
        '{{scope_key}}' = 'all'
        OR temsilci_kodu = NULLIF('{{rep_code}}', '')
    )
ORDER BY genel_durum DESC;
SQL;
    }
};
