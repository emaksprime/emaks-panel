<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('panel.data_sources')) {
            return;
        }

        $template = DB::table('panel.data_sources')
            ->where('code', 'technical_service_serial_check')
            ->value('query_template');

        if (! is_string($template) || trim($template) === '') {
            return;
        }

        if (str_contains($template, 'model_adi') && str_contains($template, 'marka_kodu')) {
            return;
        }

        $updated = $template;
        $updated = str_replace(
            "        s.sto_isim AS stok_adi,\n        c.cari_unvan1 AS cari_unvani",
            "        s.sto_isim AS stok_adi,\n        s.sto_marka_kodu AS marka_kodu,\n        mdl.mdl_ismi AS model_adi,\n        c.cari_unvan1 AS cari_unvani",
            $updated,
        );
        $updated = str_replace(
            "    LEFT JOIN STOKLAR AS s ON s.sto_kod = sh.sth_stok_kod\n    LEFT JOIN CARI_HESAPLAR AS c ON c.cari_kod = sh.sth_cari_kodu",
            "    LEFT JOIN STOKLAR AS s ON s.sto_kod = sh.sth_stok_kod\n    LEFT JOIN STOK_MODEL_TANIMLARI AS mdl ON mdl.mdl_kodu = s.sto_model_kodu\n    LEFT JOIN CARI_HESAPLAR AS c ON c.cari_kod = sh.sth_cari_kodu",
            $updated,
        );
        $updated = str_replace(
            "    ls.stok_adi,\n    ls.hareket_tarihi AS irsaliye_tarihi",
            "    ls.stok_adi,\n    ls.marka_kodu,\n    ls.model_adi,\n    ls.hareket_tarihi AS irsaliye_tarihi",
            $updated,
        );

        if ($updated === $template) {
            return;
        }

        DB::table('panel.data_sources')
            ->where('code', 'technical_service_serial_check')
            ->update([
                'query_template' => $updated,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Do not attempt lossy SQL text rollback.
    }
};
