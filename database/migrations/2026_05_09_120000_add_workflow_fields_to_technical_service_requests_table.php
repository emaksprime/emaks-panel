<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technical_service_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('technical_service_requests', 'workflow_status')) {
                $table->string('workflow_status')->nullable()->after('status')->index();
            }
            if (! Schema::hasColumn('technical_service_requests', 'customer_contact_status')) {
                $table->string('customer_contact_status')->nullable()->after('workflow_status');
            }
            if (! Schema::hasColumn('technical_service_requests', 'customer_contacted_at')) {
                $table->timestamp('customer_contacted_at')->nullable()->after('customer_contact_status');
            }
            if (! Schema::hasColumn('technical_service_requests', 'customer_contact_note')) {
                $table->text('customer_contact_note')->nullable()->after('customer_contacted_at');
            }
            if (! Schema::hasColumn('technical_service_requests', 'customer_confirmed_at')) {
                $table->timestamp('customer_confirmed_at')->nullable()->after('customer_contact_note');
            }
            if (! Schema::hasColumn('technical_service_requests', 'customer_confirmation_method')) {
                $table->string('customer_confirmation_method')->nullable()->after('customer_confirmed_at');
            }
            if (! Schema::hasColumn('technical_service_requests', 'scheduled_date')) {
                $table->date('scheduled_date')->nullable()->after('scheduled_at')->index();
            }
            if (! Schema::hasColumn('technical_service_requests', 'scheduled_time')) {
                $table->string('scheduled_time', 16)->nullable()->after('scheduled_date');
            }
            if (! Schema::hasColumn('technical_service_requests', 'technician_approval_status')) {
                $table->string('technician_approval_status')->nullable()->after('technical_service_technician_id');
            }
            if (! Schema::hasColumn('technical_service_requests', 'technician_approved_at')) {
                $table->timestamp('technician_approved_at')->nullable()->after('technician_approval_status');
            }
            if (! Schema::hasColumn('technical_service_requests', 'technician_revision_requested_at')) {
                $table->timestamp('technician_revision_requested_at')->nullable()->after('technician_approved_at');
            }
            if (! Schema::hasColumn('technical_service_requests', 'technician_revision_note')) {
                $table->text('technician_revision_note')->nullable()->after('technician_revision_requested_at');
            }
            if (! Schema::hasColumn('technical_service_requests', 'field_status')) {
                $table->string('field_status')->nullable()->after('technician_revision_note');
            }
            if (! Schema::hasColumn('technical_service_requests', 'field_started_at')) {
                $table->timestamp('field_started_at')->nullable()->after('field_status');
            }
            if (! Schema::hasColumn('technical_service_requests', 'field_arrived_at')) {
                $table->timestamp('field_arrived_at')->nullable()->after('field_started_at');
            }
            if (! Schema::hasColumn('technical_service_requests', 'field_completed_at')) {
                $table->timestamp('field_completed_at')->nullable()->after('field_arrived_at');
            }
            if (! Schema::hasColumn('technical_service_requests', 'missing_info_reason')) {
                $table->text('missing_info_reason')->nullable()->after('field_completed_at');
            }
            if (! Schema::hasColumn('technical_service_requests', 'pending_reason')) {
                $table->text('pending_reason')->nullable()->after('missing_info_reason');
            }
            if (! Schema::hasColumn('technical_service_requests', 'requires_reschedule')) {
                $table->boolean('requires_reschedule')->nullable()->after('pending_reason');
            }
            if (! Schema::hasColumn('technical_service_requests', 'reschedule_reason')) {
                $table->text('reschedule_reason')->nullable()->after('requires_reschedule');
            }
            if (! Schema::hasColumn('technical_service_requests', 'document_status')) {
                $table->string('document_status')->nullable()->after('reschedule_reason');
            }
            if (! Schema::hasColumn('technical_service_requests', 'photo_status')) {
                $table->string('photo_status')->nullable()->after('document_status');
            }
            if (! Schema::hasColumn('technical_service_requests', 'customer_closure_approval_status')) {
                $table->string('customer_closure_approval_status')->nullable()->after('photo_status');
            }
            if (! Schema::hasColumn('technical_service_requests', 'customer_closure_approved_at')) {
                $table->timestamp('customer_closure_approved_at')->nullable()->after('customer_closure_approval_status');
            }
            if (! Schema::hasColumn('technical_service_requests', 'cancellation_reason')) {
                $table->text('cancellation_reason')->nullable()->after('customer_closure_approved_at');
            }
            if (! Schema::hasColumn('technical_service_requests', 'next_action')) {
                $table->string('next_action')->nullable()->after('cancellation_reason');
            }
            if (! Schema::hasColumn('technical_service_requests', 'sla_status')) {
                $table->string('sla_status', 32)->nullable()->after('sla_due_at')->index();
            }
        });

        Schema::create('technical_service_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 64)->index();
            $table->unsignedBigInteger('entity_id')->index();
            $table->string('action_type', 128)->index();
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('user_name')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        DB::table('technical_service_requests')
            ->whereNull('workflow_status')
            ->update([
                'workflow_status' => DB::raw("
                    CASE
                        WHEN status IN ('Tamamlandı', 'TamamlandÄ±') THEN 'Tamamlandı'
                        WHEN status IN ('İptal', 'Ä°ptal') THEN 'İptal'
                        WHEN status IN ('Devam Ediyor') THEN 'Sahada'
                        WHEN status IN ('Randevulu') THEN 'Planlı'
                        WHEN status IN ('Atandı', 'AtandÄ±') THEN 'Usta Onayı Bekleyen'
                        ELSE 'Yeni Talep'
                    END
                "),
            ]);

        $scheduledTimeExpression = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%H:%M', scheduled_at)"
            : "TO_CHAR(scheduled_at, 'HH24:MI')";

        DB::table('technical_service_requests')
            ->whereNull('scheduled_date')
            ->whereNotNull('scheduled_at')
            ->update([
                'scheduled_date' => DB::raw('DATE(scheduled_at)'),
                'scheduled_time' => DB::raw($scheduledTimeExpression),
            ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_service_audit_logs');

        Schema::table('technical_service_requests', function (Blueprint $table) {
            foreach ([
                'workflow_status',
                'customer_contact_status',
                'customer_contacted_at',
                'customer_contact_note',
                'customer_confirmed_at',
                'customer_confirmation_method',
                'scheduled_date',
                'scheduled_time',
                'technician_approval_status',
                'technician_approved_at',
                'technician_revision_requested_at',
                'technician_revision_note',
                'field_status',
                'field_started_at',
                'field_arrived_at',
                'field_completed_at',
                'missing_info_reason',
                'pending_reason',
                'requires_reschedule',
                'reschedule_reason',
                'document_status',
                'photo_status',
                'customer_closure_approval_status',
                'customer_closure_approved_at',
                'cancellation_reason',
                'next_action',
                'sla_status',
            ] as $column) {
                if (Schema::hasColumn('technical_service_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
