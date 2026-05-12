<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technical_service_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('technical_service_requests', 'field_completion_note')) {
                $table->text('field_completion_note')->nullable()->after('field_completed_at');
            }
            if (! Schema::hasColumn('technical_service_requests', 'technician_started_at')) {
                $table->timestamp('technician_started_at')->nullable()->after('field_completion_note');
            }
            if (! Schema::hasColumn('technical_service_requests', 'technician_arrived_at')) {
                $table->timestamp('technician_arrived_at')->nullable()->after('technician_started_at');
            }
            if (! Schema::hasColumn('technical_service_requests', 'technician_completed_at')) {
                $table->timestamp('technician_completed_at')->nullable()->after('technician_arrived_at');
            }
            if (! Schema::hasColumn('technical_service_requests', 'checklist_payload')) {
                $table->json('checklist_payload')->nullable()->after('technician_completed_at');
            }
            if (! Schema::hasColumn('technical_service_requests', 'checklist_status')) {
                $table->string('checklist_status', 64)->nullable()->after('checklist_payload');
            }
            if (! Schema::hasColumn('technical_service_requests', 'checklist_completed_at')) {
                $table->timestamp('checklist_completed_at')->nullable()->after('checklist_status');
            }
            if (! Schema::hasColumn('technical_service_requests', 'before_photo_count')) {
                $table->unsignedInteger('before_photo_count')->nullable()->after('checklist_completed_at');
            }
            if (! Schema::hasColumn('technical_service_requests', 'after_photo_count')) {
                $table->unsignedInteger('after_photo_count')->nullable()->after('before_photo_count');
            }
            if (! Schema::hasColumn('technical_service_requests', 'general_photo_count')) {
                $table->unsignedInteger('general_photo_count')->nullable()->after('after_photo_count');
            }
            if (! Schema::hasColumn('technical_service_requests', 'customer_closure_approval_method')) {
                $table->string('customer_closure_approval_method', 64)->nullable()->after('customer_closure_approved_at');
            }
            if (! Schema::hasColumn('technical_service_requests', 'customer_closure_approval_code')) {
                $table->string('customer_closure_approval_code', 128)->nullable()->after('customer_closure_approval_method');
            }
            if (! Schema::hasColumn('technical_service_requests', 'customer_signature_name')) {
                $table->string('customer_signature_name')->nullable()->after('customer_closure_approval_code');
            }
            if (! Schema::hasColumn('technical_service_requests', 'customer_signature_at')) {
                $table->timestamp('customer_signature_at')->nullable()->after('customer_signature_name');
            }
            if (! Schema::hasColumn('technical_service_requests', 'completion_block_reason')) {
                $table->text('completion_block_reason')->nullable()->after('customer_signature_at');
            }
            if (! Schema::hasColumn('technical_service_requests', 'incomplete_reason')) {
                $table->text('incomplete_reason')->nullable()->after('completion_block_reason');
            }
            if (! Schema::hasColumn('technical_service_requests', 'requires_second_visit')) {
                $table->boolean('requires_second_visit')->nullable()->after('incomplete_reason');
            }
            if (! Schema::hasColumn('technical_service_requests', 'second_visit_reason')) {
                $table->text('second_visit_reason')->nullable()->after('requires_second_visit');
            }
        });
    }

    public function down(): void
    {
        Schema::table('technical_service_requests', function (Blueprint $table) {
            foreach ([
                'field_completion_note',
                'technician_started_at',
                'technician_arrived_at',
                'technician_completed_at',
                'checklist_payload',
                'checklist_status',
                'checklist_completed_at',
                'before_photo_count',
                'after_photo_count',
                'general_photo_count',
                'customer_closure_approval_method',
                'customer_closure_approval_code',
                'customer_signature_name',
                'customer_signature_at',
                'completion_block_reason',
                'incomplete_reason',
                'requires_second_visit',
                'second_visit_reason',
            ] as $column) {
                if (Schema::hasColumn('technical_service_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
