<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technical_service_requests', function (Blueprint $table) {
            $table->timestamp('reopened_at')->nullable()->after('cancelled_at');
            $table->unsignedBigInteger('reopened_by_user_id')->nullable()->after('reopened_at');
            $table->string('reopen_reason')->nullable()->after('reopened_by_user_id');
            $table->text('reopen_note')->nullable()->after('reopen_reason');
            $table->unsignedInteger('reopen_count')->default(0)->after('reopen_note');
        });
    }

    public function down(): void
    {
        Schema::table('technical_service_requests', function (Blueprint $table) {
            $table->dropColumn([
                'reopened_at',
                'reopened_by_user_id',
                'reopen_reason',
                'reopen_note',
                'reopen_count',
            ]);
        });
    }
};
