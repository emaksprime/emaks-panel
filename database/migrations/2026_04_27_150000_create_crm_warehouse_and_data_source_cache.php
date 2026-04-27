<?php

use Closure;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            try {
                DB::statement("ATTACH DATABASE ':memory:' AS crm");
            } catch (Throwable) {
                //
            }
        } else {
            DB::statement('CREATE SCHEMA IF NOT EXISTS crm');
        }

        $this->createTable('panel.data_source_cache', function (Blueprint $table) {
            $table->id();
            $table->string('cache_key', 128)->unique();
            $table->string('source_code', 128)->index();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });

        $this->createTable('crm.customers', function (Blueprint $table) {
            $table->id();
            $table->string('cari_kodu')->unique();
            $table->string('cari_adi')->nullable();
            $table->string('cari_grup')->nullable();
            $table->string('vergi_no')->nullable();
            $table->string('telefon')->nullable();
            $table->string('email')->nullable();
            $table->text('adres')->nullable();
            $table->string('temsilci_kodu')->nullable()->index();
            $table->json('raw_payload')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        $this->createTable('crm.products', function (Blueprint $table) {
            $table->id();
            $table->string('stok_kodu')->unique();
            $table->string('urun_adi')->nullable();
            $table->string('model')->nullable();
            $table->string('kategori_kodu')->nullable();
            $table->string('kategori_adi')->nullable();
            $table->string('marka')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        $this->createTable('crm.proformas', function (Blueprint $table) {
            $table->id();
            $table->string('proforma_no')->nullable()->unique();
            $table->string('cari_kodu')->nullable()->index();
            $table->string('cari_adi')->nullable();
            $table->string('status')->default('taslak')->index();
            $table->text('notes')->nullable();
            $table->json('totals')->nullable();
            $table->json('raw_payload')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamps();
        });

        $this->createTable('crm.proforma_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('proforma_id')->index();
            $table->string('stok_kodu')->nullable()->index();
            $table->string('urun_adi')->nullable();
            $table->string('model')->nullable();
            $table->decimal('quantity', 18, 4)->default(0);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('discount', 18, 4)->default(0);
            $table->decimal('vat_rate', 8, 4)->default(20);
            $table->decimal('line_total', 18, 4)->default(0);
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });

        $this->createTable('crm.orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no')->nullable()->index();
            $table->string('direction')->index();
            $table->string('cari_kodu')->nullable()->index();
            $table->string('cari_adi')->nullable();
            $table->string('status')->nullable()->index();
            $table->date('order_date')->nullable()->index();
            $table->json('totals')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        $this->createTable('crm.order_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->index();
            $table->string('stok_kodu')->nullable()->index();
            $table->string('urun_adi')->nullable();
            $table->decimal('quantity', 18, 4)->default(0);
            $table->decimal('delivered_quantity', 18, 4)->default(0);
            $table->decimal('remaining_quantity', 18, 4)->default(0);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });

        $this->createTable('crm.stock_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('stok_kodu')->index();
            $table->string('depo')->nullable()->index();
            $table->string('raf')->nullable();
            $table->decimal('quantity', 18, 4)->default(0);
            $table->json('raw_payload')->nullable();
            $table->timestamp('snapshot_at')->nullable()->index();
            $table->timestamps();
        });

        $this->createTable('crm.sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source_code', 128)->index();
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('rows_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        $this->createTable('crm.sync_errors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sync_run_id')->nullable()->index();
            $table->string('source_code', 128)->nullable()->index();
            $table->string('error_code')->nullable();
            $table->text('message')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm.sync_errors');
        Schema::dropIfExists('crm.sync_runs');
        Schema::dropIfExists('crm.stock_snapshots');
        Schema::dropIfExists('crm.order_lines');
        Schema::dropIfExists('crm.orders');
        Schema::dropIfExists('crm.proforma_lines');
        Schema::dropIfExists('crm.proformas');
        Schema::dropIfExists('crm.products');
        Schema::dropIfExists('crm.customers');
        Schema::dropIfExists('panel.data_source_cache');
    }

    private function createTable(string $tableName, Closure $callback): void
    {
        if (! Schema::hasTable($tableName)) {
            Schema::create($tableName, $callback);
        }
    }
};
