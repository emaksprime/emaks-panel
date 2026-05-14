<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->repairQrLinks();
        $this->repairMountSessions();
        $this->repairMountPayments();
        $this->repairRequestSerials();
    }

    public function down(): void
    {
        // Intentionally irreversible. This migration repairs local/partial WIP schemas.
    }

    private function repairQrLinks(): void
    {
        if (! Schema::hasTable('technical_service_qr_links')) {
            return;
        }

        Schema::table('technical_service_qr_links', function (Blueprint $table): void {
            $this->stringColumn($table, 'technical_service_qr_links', 'token_hash', 128);
            $this->stringColumn($table, 'technical_service_qr_links', 'serial_number');
            $this->stringColumn($table, 'technical_service_qr_links', 'product_name');
            $this->stringColumn($table, 'technical_service_qr_links', 'product_model', nullable: true);
            $this->stringColumn($table, 'technical_service_qr_links', 'brand', nullable: true);
            $this->stringColumn($table, 'technical_service_qr_links', 'link_type', 64);
            $this->stringColumn($table, 'technical_service_qr_links', 'status', 32, default: 'active');
            $this->timestampColumn($table, 'technical_service_qr_links', 'expires_at');
            $this->timestamps($table, 'technical_service_qr_links');
        });
    }

    private function repairMountSessions(): void
    {
        if (! Schema::hasTable('technical_service_mount_sessions')) {
            return;
        }

        Schema::table('technical_service_mount_sessions', function (Blueprint $table): void {
            if (! Schema::hasColumn('technical_service_mount_sessions', 'technical_service_qr_link_id')) {
                $table->unsignedBigInteger('technical_service_qr_link_id')->nullable();
            }

            $this->stringColumn($table, 'technical_service_mount_sessions', 'session_token_hash', 128);
            $this->stringColumn($table, 'technical_service_mount_sessions', 'serial_number');
            $this->stringColumn($table, 'technical_service_mount_sessions', 'sale_mount_status', 64, default: 'unknown');
            $this->stringColumn($table, 'technical_service_mount_sessions', 'mount_payment_status', 64, nullable: true);
            $this->stringColumn($table, 'technical_service_mount_sessions', 'customer_entry_mode', 64, nullable: true);
            $this->stringColumn($table, 'technical_service_mount_sessions', 'decision_status', 64, default: 'pending_check');

            if (! Schema::hasColumn('technical_service_mount_sessions', 'check_attempt_count')) {
                $table->unsignedInteger('check_attempt_count')->default(0);
            }

            $this->timestampColumn($table, 'technical_service_mount_sessions', 'last_checked_at');

            if (! Schema::hasColumn('technical_service_mount_sessions', 'check_error')) {
                $table->text('check_error')->nullable();
            }

            if (! Schema::hasColumn('technical_service_mount_sessions', 'context_payload')) {
                $table->json('context_payload')->nullable();
            }

            $this->timestamps($table, 'technical_service_mount_sessions');
        });
    }

    private function repairMountPayments(): void
    {
        if (! Schema::hasTable('technical_service_mount_payments')) {
            return;
        }

        Schema::table('technical_service_mount_payments', function (Blueprint $table): void {
            if (! Schema::hasColumn('technical_service_mount_payments', 'technical_service_mount_session_id')) {
                $table->unsignedBigInteger('technical_service_mount_session_id')->nullable();
            }

            $this->stringColumn($table, 'technical_service_mount_payments', 'provider', 64, default: 'fake');
            $this->stringColumn($table, 'technical_service_mount_payments', 'provider_reference', nullable: true);
            $this->stringColumn($table, 'technical_service_mount_payments', 'status', 32, default: 'pending');

            if (! Schema::hasColumn('technical_service_mount_payments', 'amount')) {
                $table->decimal('amount', 12, 2)->default(3500);
            }

            $this->stringColumn($table, 'technical_service_mount_payments', 'currency', 3, default: 'TRY');

            if (! Schema::hasColumn('technical_service_mount_payments', 'payment_url')) {
                $table->text('payment_url')->nullable();
            }

            $this->timestampColumn($table, 'technical_service_mount_payments', 'paid_at');

            if (! Schema::hasColumn('technical_service_mount_payments', 'raw_payload')) {
                $table->json('raw_payload')->nullable();
            }

            $this->timestamps($table, 'technical_service_mount_payments');
        });

        if (Schema::hasColumn('technical_service_mount_payments', 'session_id')) {
            DB::table('technical_service_mount_payments')
                ->whereNull('technical_service_mount_session_id')
                ->whereNotNull('session_id')
                ->update(['technical_service_mount_session_id' => DB::raw('session_id')]);
        }

        if (Schema::hasColumn('technical_service_mount_payments', 'mount_session_id')) {
            DB::table('technical_service_mount_payments')
                ->whereNull('technical_service_mount_session_id')
                ->whereNotNull('mount_session_id')
                ->update(['technical_service_mount_session_id' => DB::raw('mount_session_id')]);
        }
    }

    private function repairRequestSerials(): void
    {
        if (! Schema::hasTable('technical_service_request_serials')) {
            return;
        }

        Schema::table('technical_service_request_serials', function (Blueprint $table): void {
            if (! Schema::hasColumn('technical_service_request_serials', 'technical_service_request_id')) {
                $table->unsignedBigInteger('technical_service_request_id')->nullable();
            }

            $this->stringColumn($table, 'technical_service_request_serials', 'mrn', nullable: true);
            $this->stringColumn($table, 'technical_service_request_serials', 'serial_number');
            $this->stringColumn($table, 'technical_service_request_serials', 'product_name', nullable: true);
            $this->stringColumn($table, 'technical_service_request_serials', 'product_model', nullable: true);
            $this->stringColumn($table, 'technical_service_request_serials', 'brand', nullable: true);
            $this->stringColumn($table, 'technical_service_request_serials', 'stock_code', nullable: true);
            $this->stringColumn($table, 'technical_service_request_serials', 'invoice_series', nullable: true);
            $this->stringColumn($table, 'technical_service_request_serials', 'invoice_number', nullable: true);

            $this->booleanColumn($table, 'technical_service_request_serials', 'customer_selected');
            $this->booleanColumn($table, 'technical_service_request_serials', 'customer_visible');

            if (! Schema::hasColumn('technical_service_request_serials', 'hidden_reason')) {
                $table->text('hidden_reason')->nullable();
            }

            $this->booleanColumn($table, 'technical_service_request_serials', 'is_primary');
            $this->booleanColumn($table, 'technical_service_request_serials', 'is_returned');

            if (! Schema::hasColumn('technical_service_request_serials', 'return_note')) {
                $table->text('return_note')->nullable();
            }

            if (! Schema::hasColumn('technical_service_request_serials', 'return_date')) {
                $table->date('return_date')->nullable();
            }

            $this->stringColumn($table, 'technical_service_request_serials', 'return_document_no', nullable: true);
            $this->stringColumn($table, 'technical_service_request_serials', 'invoice_customer_type', 64, default: 'unknown');

            if (! Schema::hasColumn('technical_service_request_serials', 'source_payload')) {
                $table->json('source_payload')->nullable();
            }

            $this->timestamps($table, 'technical_service_request_serials');
        });
    }

    private function stringColumn(
        Blueprint $table,
        string $tableName,
        string $column,
        int $length = 255,
        bool $nullable = false,
        ?string $default = null,
    ): void {
        if (Schema::hasColumn($tableName, $column)) {
            return;
        }

        $definition = $table->string($column, $length);

        if ($nullable || $default === null) {
            $definition->nullable();
        }

        if ($default !== null) {
            $definition->default($default);
        }
    }

    private function booleanColumn(Blueprint $table, string $tableName, string $column): void
    {
        if (! Schema::hasColumn($tableName, $column)) {
            $table->boolean($column)->default(false);
        }
    }

    private function timestampColumn(Blueprint $table, string $tableName, string $column): void
    {
        if (! Schema::hasColumn($tableName, $column)) {
            $table->timestamp($column)->nullable();
        }
    }

    private function timestamps(Blueprint $table, string $tableName): void
    {
        if (! Schema::hasColumn($tableName, 'created_at')) {
            $table->timestamp('created_at')->nullable();
        }

        if (! Schema::hasColumn($tableName, 'updated_at')) {
            $table->timestamp('updated_at')->nullable();
        }
    }
};
