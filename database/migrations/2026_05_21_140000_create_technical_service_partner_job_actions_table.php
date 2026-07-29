<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technical_service_partner_job_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('technical_service_request_id')
                ->constrained('technical_service_requests')
                ->cascadeOnDelete();
            $table->foreignId('partner_id')
                ->constrained('b2b_partners')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->index();
            $table->foreignId('technical_service_technician_id')
                ->nullable()
                ->constrained('technical_service_technicians')
                ->nullOnDelete();
            $table->string('action', 64)->index();
            $table->string('status', 32)->default('submitted')->index();
            $table->json('payload')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['partner_id', 'action']);
            $table->index(['technical_service_request_id', 'action'], 'ts_partner_actions_request_action_idx');
        });

        $this->addPanelUserForeignKey();
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_service_partner_job_actions');
    }

    private function addPanelUserForeignKey(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('technical_service_partner_job_actions', function (Blueprint $table): void {
            $table->foreign('user_id')->references('id')->on('panel.users')->cascadeOnDelete();
        });
    }
};
