<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technical_service_message_dispatches', function (Blueprint $table): void {
            $this->addColumns($table);
            $this->addIndexes($table);
        });
    }

    public function down(): void
    {
        Schema::table('technical_service_message_dispatches', function (Blueprint $table): void {
            $this->dropIndexes($table);

            $columns = [
                'request_id',
                'related_type',
                'related_id',
                'root_mrn',
                'mrn',
                'srv',
                'message_type',
                'channel',
                'provider_key',
                'recipient_role',
                'recipient_phone_hash',
                'recipient_phone_mask',
                'effective_target_phone_hash',
                'effective_target_phone_mask',
                'test_redirect_applied',
                'template_key',
                'template_version',
                'rendered_body_hash',
                'payload_hash',
                'idempotency_key',
                'channel_policy',
                'attempt_count',
                'max_attempts',
                'queued_at',
                'next_attempt_at',
                'sending_started_at',
                'failed_at',
                'last_error_code',
                'last_error_message_redacted',
                'provider_message_id',
                'provider_status',
                'provider_response_redacted',
                'parent_dispatch_id',
                'force_resend',
                'force_resend_reason',
                'created_by',
                'triggered_by',
                'metadata',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('technical_service_message_dispatches', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function addColumns(Blueprint $table): void
    {
        $columns = [
            'request_id' => fn () => $table->unsignedBigInteger('request_id')->nullable(),
            'related_type' => fn () => $table->string('related_type', 120)->nullable(),
            'related_id' => fn () => $table->unsignedBigInteger('related_id')->nullable(),
            'root_mrn' => fn () => $table->string('root_mrn', 80)->nullable(),
            'mrn' => fn () => $table->string('mrn', 80)->nullable(),
            'srv' => fn () => $table->string('srv', 80)->nullable(),
            'message_type' => fn () => $table->string('message_type', 120)->nullable(),
            'channel' => fn () => $table->string('channel', 32)->nullable(),
            'provider_key' => fn () => $table->string('provider_key', 80)->nullable(),
            'recipient_role' => fn () => $table->string('recipient_role', 40)->nullable(),
            'recipient_phone_hash' => fn () => $table->string('recipient_phone_hash', 64)->nullable(),
            'recipient_phone_mask' => fn () => $table->string('recipient_phone_mask', 32)->nullable(),
            'effective_target_phone_hash' => fn () => $table->string('effective_target_phone_hash', 64)->nullable(),
            'effective_target_phone_mask' => fn () => $table->string('effective_target_phone_mask', 32)->nullable(),
            'test_redirect_applied' => fn () => $table->boolean('test_redirect_applied')->default(false),
            'template_key' => fn () => $table->string('template_key', 160)->nullable(),
            'template_version' => fn () => $table->unsignedInteger('template_version')->nullable(),
            'rendered_body_hash' => fn () => $table->string('rendered_body_hash', 64)->nullable(),
            'payload_hash' => fn () => $table->string('payload_hash', 64)->nullable(),
            'idempotency_key' => fn () => $table->string('idempotency_key', 120)->nullable(),
            'channel_policy' => fn () => $table->string('channel_policy', 64)->nullable(),
            'attempt_count' => fn () => $table->unsignedInteger('attempt_count')->default(0),
            'max_attempts' => fn () => $table->unsignedInteger('max_attempts')->default(1),
            'queued_at' => fn () => $table->timestamp('queued_at')->nullable(),
            'next_attempt_at' => fn () => $table->timestamp('next_attempt_at')->nullable(),
            'sending_started_at' => fn () => $table->timestamp('sending_started_at')->nullable(),
            'failed_at' => fn () => $table->timestamp('failed_at')->nullable(),
            'last_error_code' => fn () => $table->string('last_error_code', 80)->nullable(),
            'last_error_message_redacted' => fn () => $table->text('last_error_message_redacted')->nullable(),
            'provider_message_id' => fn () => $table->string('provider_message_id', 160)->nullable(),
            'provider_status' => fn () => $table->string('provider_status', 80)->nullable(),
            'provider_response_redacted' => fn () => $table->json('provider_response_redacted')->nullable(),
            'parent_dispatch_id' => fn () => $table->unsignedBigInteger('parent_dispatch_id')->nullable(),
            'force_resend' => fn () => $table->boolean('force_resend')->default(false),
            'force_resend_reason' => fn () => $table->text('force_resend_reason')->nullable(),
            'created_by' => fn () => $table->unsignedBigInteger('created_by')->nullable(),
            'triggered_by' => fn () => $table->string('triggered_by', 80)->nullable(),
            'metadata' => fn () => $table->json('metadata')->nullable(),
        ];

        foreach ($columns as $column => $callback) {
            if (! Schema::hasColumn('technical_service_message_dispatches', $column)) {
                $callback();
            }
        }
    }

    private function addIndexes(Blueprint $table): void
    {
        $table->unique('idempotency_key', 'ts_msg_disp_idempotency_unique');
        $table->index(['status', 'next_attempt_at'], 'ts_msg_disp_status_next_idx');
        $table->index('request_id', 'ts_msg_disp_request_idx');
        $table->index(['provider_key', 'status'], 'ts_msg_disp_provider_status_idx');
        $table->index(['recipient_phone_hash', 'message_type'], 'ts_msg_disp_recipient_msg_idx');
        $table->index('parent_dispatch_id', 'ts_msg_disp_parent_idx');
    }

    private function dropIndexes(Blueprint $table): void
    {
        $table->dropUnique('ts_msg_disp_idempotency_unique');

        foreach ([
            'ts_msg_disp_status_next_idx',
            'ts_msg_disp_request_idx',
            'ts_msg_disp_provider_status_idx',
            'ts_msg_disp_recipient_msg_idx',
            'ts_msg_disp_parent_idx',
        ] as $index) {
            $table->dropIndex($index);
        }
    }
};
