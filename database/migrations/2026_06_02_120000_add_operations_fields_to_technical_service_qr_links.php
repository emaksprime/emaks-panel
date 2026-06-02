<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technical_service_qr_links', function (Blueprint $table): void {
            if (! Schema::hasColumn('technical_service_qr_links', 'public_token')) {
                $table->string('public_token', 96)->nullable()->after('token_hash');
            }

            if (! Schema::hasColumn('technical_service_qr_links', 'created_by')) {
                $table->unsignedBigInteger('created_by')
                    ->nullable()
                    ->after('status')
                    ->index();
            }

            if (! Schema::hasColumn('technical_service_qr_links', 'printed_at')) {
                $table->timestamp('printed_at')->nullable()->after('created_by');
            }

            if (! Schema::hasColumn('technical_service_qr_links', 'last_scanned_at')) {
                $table->timestamp('last_scanned_at')->nullable()->after('printed_at');
            }

            if (! Schema::hasColumn('technical_service_qr_links', 'scan_count')) {
                $table->unsignedInteger('scan_count')->default(0)->after('last_scanned_at');
            }

            if (! Schema::hasColumn('technical_service_qr_links', 'metadata')) {
                $table->json('metadata')->nullable()->after('scan_count');
            }
        });

        DB::table('technical_service_qr_links')
            ->whereNull('public_token')
            ->orderBy('id')
            ->select(['id'])
            ->chunkById(200, function ($links): void {
                foreach ($links as $link) {
                    do {
                        $token = Str::random(64);
                    } while (DB::table('technical_service_qr_links')->where('public_token', $token)->exists());

                    DB::table('technical_service_qr_links')
                        ->where('id', $link->id)
                        ->update(['public_token' => $token]);
                }
            });

        Schema::table('technical_service_qr_links', function (Blueprint $table): void {
            $table->unique('public_token', 'technical_service_qr_links_public_token_unique');
        });
    }

    public function down(): void
    {
        Schema::table('technical_service_qr_links', function (Blueprint $table): void {
            $table->dropUnique('technical_service_qr_links_public_token_unique');

            if (Schema::hasColumn('technical_service_qr_links', 'created_by')) {
                $table->dropIndex(['created_by']);
                $table->dropColumn('created_by');
            }

            foreach (['metadata', 'scan_count', 'last_scanned_at', 'printed_at', 'public_token'] as $column) {
                if (Schema::hasColumn('technical_service_qr_links', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
