<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('b2b_partner_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('b2b_partners')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('order_no', 64)->unique();
            $table->string('status', 32)->default('ops_review')->index();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('submitted_at')->nullable()->index();
            $table->timestamps();
            $table->index(['partner_id', 'status']);
        });

        Schema::create('b2b_partner_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('b2b_partner_orders')->cascadeOnDelete();
            $table->string('product_code', 128);
            $table->string('product_name');
            $table->unsignedInteger('requested_quantity');
            $table->string('stock_status', 32)->default('unknown')->index();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        $this->addPanelUserForeignKey();
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_partner_order_items');
        Schema::dropIfExists('b2b_partner_orders');
    }

    private function addPanelUserForeignKey(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('b2b_partner_orders', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('panel.users')->cascadeOnDelete();
        });
    }
};
