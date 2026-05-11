<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            try {
                DB::statement("ATTACH DATABASE ':memory:' AS panel");
            } catch (Throwable) {
                //
            }
        } else {
            DB::statement('CREATE SCHEMA IF NOT EXISTS panel');
        }

        Schema::create('panel.log_archives', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('original_log_id')->unique();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('action')->index();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('archived_at')->useCurrent();
            $table->string('archive_month', 7)->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel.log_archives');
    }
};
