<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technical_service_message_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->string('event', 120)->index();
            $table->foreignId('technical_service_request_id')->nullable()->constrained('technical_service_requests')->nullOnDelete();
            $table->foreignId('technical_service_partner_job_action_id')->nullable()->constrained('technical_service_partner_job_actions')->nullOnDelete();
            $table->foreignId('technical_service_assignment_offer_id')->nullable()->constrained('technical_service_assignment_offers')->nullOnDelete();
            $table->foreignId('technical_service_earning_id')->nullable()->constrained('technical_service_earnings')->nullOnDelete();
            $table->string('target_type', 64)->nullable();
            $table->string('original_phone', 32)->nullable();
            $table->string('target_phone', 32)->nullable()->index();
            $table->boolean('test_mode')->default(true);
            $table->string('status', 32)->default('not_configured')->index();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('sent_by')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
        $this->addPanelUserForeignKey('technical_service_message_dispatches', 'sent_by');

        Schema::create('technical_service_assignment_archives', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('technical_service_request_id')->constrained('technical_service_requests')->cascadeOnDelete();
            $table->foreignId('old_technician_id')->nullable()->constrained('technical_service_technicians')->nullOnDelete();
            $table->foreignId('new_technician_id')->nullable()->constrained('technical_service_technicians')->nullOnDelete();
            $table->foreignId('old_partner_id')->nullable()->constrained('b2b_partners')->nullOnDelete();
            $table->foreignId('new_partner_id')->nullable()->constrained('b2b_partners')->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->unsignedBigInteger('archived_by')->nullable()->index();
            $table->timestamp('archived_at')->useCurrent();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['technical_service_request_id', 'archived_at'], 'ts_assignment_archives_request_time_idx');
        });
        $this->addPanelUserForeignKey('technical_service_assignment_archives', 'archived_by');

        Schema::table('technical_service_request_uploads', function (Blueprint $table): void {
            if (! Schema::hasColumn('technical_service_request_uploads', 'review_status')) {
                $table->string('review_status', 32)->nullable()->after('size')->index();
            }
            if (! Schema::hasColumn('technical_service_request_uploads', 'review_note')) {
                $table->text('review_note')->nullable()->after('review_status');
            }
            if (! Schema::hasColumn('technical_service_request_uploads', 'reviewed_by')) {
                $table->unsignedBigInteger('reviewed_by')->nullable()->after('review_note')->index();
            }
            if (! Schema::hasColumn('technical_service_request_uploads', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            }
            if (! Schema::hasColumn('technical_service_request_uploads', 'review_payload')) {
                $table->json('review_payload')->nullable()->after('reviewed_at');
            }
        });
        $this->addPanelUserForeignKey('technical_service_request_uploads', 'reviewed_by');

        Schema::table('technical_service_customer_confirmations', function (Blueprint $table): void {
            if (! Schema::hasColumn('technical_service_customer_confirmations', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('approved_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('technical_service_customer_confirmations', function (Blueprint $table): void {
            if (Schema::hasColumn('technical_service_customer_confirmations', 'rejected_at')) {
                $table->dropColumn('rejected_at');
            }
        });

        if (DB::connection()->getDriverName() !== 'sqlite' && Schema::hasColumn('technical_service_request_uploads', 'reviewed_by')) {
            Schema::table('technical_service_request_uploads', function (Blueprint $table): void {
                $table->dropForeign(['reviewed_by']);
            });
        }

        Schema::table('technical_service_request_uploads', function (Blueprint $table): void {
            foreach (['review_payload', 'reviewed_at', 'reviewed_by', 'review_note', 'review_status'] as $column) {
                if (Schema::hasColumn('technical_service_request_uploads', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('technical_service_assignment_archives');
        Schema::dropIfExists('technical_service_message_dispatches');
    }

    private function addPanelUserForeignKey(string $tableName, string $column): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($column): void {
            $table->foreign($column)->references('id')->on('panel.users')->nullOnDelete();
        });
    }
};
