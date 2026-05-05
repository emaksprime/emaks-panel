<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technical_service_requests', function (Blueprint $table) {
            $table->timestamp('installation_completed_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('technical_service_requests', function (Blueprint $table) {
            $table->dropColumn('installation_completed_at');
        });
    }
};
