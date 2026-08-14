<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technical_service_payment_order_context_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('context_id')
                ->constrained('technical_service_payment_order_contexts', 'id', 'ts_poci_context_fk')
                ->restrictOnDelete();
            $table->string('line_key', 64);
            $table->unsignedSmallInteger('position');
            $table->string('item_code', 120)->nullable();
            $table->string('item_name_snapshot', 240);
            $table->string('item_short_name_snapshot', 240)->nullable();
            $table->string('item_kind', 24);
            $table->string('classification_source', 64);
            $table->string('classification_contract_version', 80);
            $table->string('unit_code', 32)->nullable();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->string('currency', 8);
            $table->string('serial_tracking_state', 32);
            $table->string('selected_part_serial', 160)->nullable();
            $table->string('stock_source', 48);
            $table->timestamp('stock_freshness_at')->nullable();
            $table->string('mikro_contract_fingerprint', 128)->nullable();
            $table->boolean('availability_verified')->default(false);
            $table->string('tax_mode_snapshot', 64);
            $table->decimal('vat_rate_snapshot', 8, 4)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['context_id', 'line_key'], 'ts_poci_context_line_unique');
            $table->unique(['context_id', 'position'], 'ts_poci_context_position_unique');
            $table->index(['item_code', 'item_kind'], 'ts_poci_item_kind_idx');
            $table->index('created_by', 'ts_poci_created_by_idx');
            $table->index('updated_by', 'ts_poci_updated_by_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE technical_service_payment_order_context_items ADD CONSTRAINT ts_poci_quantity_positive CHECK (quantity > 0)');
            DB::statement('ALTER TABLE technical_service_payment_order_context_items ADD CONSTRAINT ts_poci_prices_nonnegative CHECK (unit_price >= 0 AND line_total >= 0)');
            DB::statement("ALTER TABLE technical_service_payment_order_context_items ADD CONSTRAINT ts_poci_part_only CHECK (item_kind = 'part')");
            DB::statement('ALTER TABLE technical_service_payment_order_context_items ADD CONSTRAINT ts_poci_position_range CHECK (position BETWEEN 1 AND 20)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_service_payment_order_context_items');
    }
};
