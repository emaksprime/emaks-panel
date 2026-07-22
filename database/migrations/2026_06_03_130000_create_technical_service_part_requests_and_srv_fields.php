<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('technical_service_part_requests')) {
            Schema::create('technical_service_part_requests', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('technical_service_request_id')
                    ->constrained('technical_service_requests')
                    ->cascadeOnDelete();
                $table->foreignId('root_request_id')
                    ->nullable()
                    ->constrained('technical_service_requests')
                    ->nullOnDelete();
                $table->foreignId('request_serial_id')
                    ->nullable()
                    ->constrained('technical_service_request_serials')
                    ->nullOnDelete();
                $table->foreignId('source_partner_action_id')
                    ->nullable()
                    ->constrained('technical_service_partner_job_actions')
                    ->nullOnDelete();
                $table->unsignedBigInteger('requested_by_user_id')->nullable()->index();
                $table->foreignId('requested_by_technician_id')
                    ->nullable()
                    ->constrained('technical_service_technicians')
                    ->nullOnDelete();
                $table->string('status', 64)->default('ops_review')->index();
                $table->string('part_name');
                $table->string('part_code')->nullable();
                $table->unsignedInteger('quantity')->default(1);
                $table->text('reason')->nullable();
                $table->text('technician_note')->nullable();
                $table->text('ops_note')->nullable();
                $table->text('partner_message')->nullable();
                $table->string('shipment_provider')->nullable();
                $table->string('tracking_no')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('received_at')->nullable();
                $table->unsignedBigInteger('received_by_user_id')->nullable()->index();
                $table->boolean('requires_service_visit')->default(false)->index();
                $table->foreignId('service_visit_request_id')
                    ->nullable()
                    ->constrained('technical_service_requests')
                    ->nullOnDelete();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['technical_service_request_id', 'status'], 'ts_part_requests_request_status_idx');
                $table->index(['root_request_id', 'status'], 'ts_part_requests_root_status_idx');
            });

            $this->addPanelUserForeignKeys();
        }

        if (Schema::hasTable('technical_service_requests')) {
            Schema::table('technical_service_requests', function (Blueprint $table): void {
                if (! Schema::hasColumn('technical_service_requests', 'parent_request_id')) {
                    $table->unsignedBigInteger('parent_request_id')->nullable()->index();
                }

                if (! Schema::hasColumn('technical_service_requests', 'root_mrn')) {
                    $table->string('root_mrn', 96)->nullable()->index();
                }

                if (! Schema::hasColumn('technical_service_requests', 'service_sequence')) {
                    $table->unsignedInteger('service_sequence')->nullable()->index();
                }

                if (! Schema::hasColumn('technical_service_requests', 'service_code')) {
                    $table->string('service_code', 32)->nullable()->index();
                }

                if (! Schema::hasColumn('technical_service_requests', 'service_visit_reason')) {
                    $table->string('service_visit_reason', 128)->nullable();
                }

                if (! Schema::hasColumn('technical_service_requests', 'source_part_request_id')) {
                    $table->unsignedBigInteger('source_part_request_id')->nullable()->index();
                }

                if (! Schema::hasColumn('technical_service_requests', 'source_partner_action_id')) {
                    $table->unsignedBigInteger('source_partner_action_id')->nullable()->index();
                }
            });

            $this->addRequestForeignKeys();
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('technical_service_requests')) {
            Schema::table('technical_service_requests', function (Blueprint $table): void {
                foreach ([
                    'technical_service_requests_parent_request_id_foreign',
                    'technical_service_requests_source_part_request_id_foreign',
                    'technical_service_requests_source_partner_action_id_foreign',
                ] as $foreignKey) {
                    try {
                        $table->dropForeign($foreignKey);
                    } catch (Throwable) {
                        // SQLite and older local databases may not have these constraints.
                    }
                }

                foreach ([
                    'parent_request_id',
                    'root_mrn',
                    'service_sequence',
                    'service_code',
                    'service_visit_reason',
                    'source_part_request_id',
                    'source_partner_action_id',
                ] as $column) {
                    if (Schema::hasColumn('technical_service_requests', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('technical_service_part_requests');
    }

    private function addPanelUserForeignKeys(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('technical_service_part_requests', function (Blueprint $table): void {
            $table->foreign('requested_by_user_id')->references('id')->on('panel.users')->nullOnDelete();
            $table->foreign('received_by_user_id')->references('id')->on('panel.users')->nullOnDelete();
        });
    }

    private function addRequestForeignKeys(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('technical_service_requests', function (Blueprint $table): void {
            $table->foreign('parent_request_id')
                ->references('id')
                ->on('technical_service_requests')
                ->nullOnDelete();
            $table->foreign('source_part_request_id')
                ->references('id')
                ->on('technical_service_part_requests')
                ->nullOnDelete();
            $table->foreign('source_partner_action_id')
                ->references('id')
                ->on('technical_service_partner_job_actions')
                ->nullOnDelete();
        });
    }
};
