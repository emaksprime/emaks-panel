<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technical_service_assignment_offers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('technical_service_request_id')
                ->constrained('technical_service_requests')
                ->cascadeOnDelete();
            $table->foreignId('technical_service_technician_id')
                ->nullable()
                ->constrained('technical_service_technicians')
                ->nullOnDelete();
            $table->foreignId('route_quote_id')
                ->nullable()
                ->constrained('technical_service_route_quotes')
                ->nullOnDelete();
            $table->decimal('labor_amount', 12, 2)->default(0);
            $table->decimal('route_fee_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('currency', 8)->default('TRY');
            $table->string('status', 32)->default('sent')->index();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('sent_by')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['technical_service_request_id', 'status'], 'ts_assignment_offers_request_status_idx');
            $table->index(['technical_service_technician_id', 'status'], 'ts_assignment_offers_technician_status_idx');
        });

        $this->addPanelUserForeignKey();
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_service_assignment_offers');
    }

    private function addPanelUserForeignKey(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('technical_service_assignment_offers', function (Blueprint $table): void {
            $table->foreign('sent_by')->references('id')->on('panel.users')->nullOnDelete();
        });
    }
};
