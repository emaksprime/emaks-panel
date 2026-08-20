<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('technical_service_technicians')) {
            Schema::table('technical_service_technicians', function (Blueprint $table): void {
                if (! Schema::hasColumn('technical_service_technicians', 'review_status')) {
                    $table->string('review_status', 64)->nullable()->index()->after('needs_review');
                }

                if (! Schema::hasColumn('technical_service_technicians', 'review_reason')) {
                    $table->text('review_reason')->nullable()->after('review_status');
                }

                if (! Schema::hasColumn('technical_service_technicians', 'review_reasons')) {
                    $table->json('review_reasons')->nullable()->after('review_reason');
                }

                if (! Schema::hasColumn('technical_service_technicians', 'reviewed_at')) {
                    $table->timestamp('reviewed_at')->nullable()->after('review_reasons');
                }

                if (! Schema::hasColumn('technical_service_technicians', 'reviewed_by')) {
                    $table->unsignedBigInteger('reviewed_by')->nullable()->index()->after('reviewed_at');
                }

                if (! Schema::hasColumn('technical_service_technicians', 'geocode_status')) {
                    $table->string('geocode_status', 64)->nullable()->index()->after('route_note');
                }

                if (! Schema::hasColumn('technical_service_technicians', 'geocode_source')) {
                    $table->string('geocode_source', 64)->nullable()->after('geocode_status');
                }

                if (! Schema::hasColumn('technical_service_technicians', 'geocode_confidence')) {
                    $table->unsignedTinyInteger('geocode_confidence')->nullable()->after('geocode_source');
                }

                if (! Schema::hasColumn('technical_service_technicians', 'geocoded_at')) {
                    $table->timestamp('geocoded_at')->nullable()->after('geocode_confidence');
                }

                if (! Schema::hasColumn('technical_service_technicians', 'geocode_payload')) {
                    $table->json('geocode_payload')->nullable()->after('geocoded_at');
                }
            });
        }

        if (Schema::hasTable('b2b_partner_technicians')) {
            Schema::table('b2b_partner_technicians', function (Blueprint $table): void {
                if (! Schema::hasColumn('b2b_partner_technicians', 'service_city')) {
                    $table->string('service_city', 128)->nullable()->index()->after('match_reason');
                }

                if (! Schema::hasColumn('b2b_partner_technicians', 'service_district')) {
                    $table->string('service_district', 128)->nullable()->after('service_city');
                }

                if (! Schema::hasColumn('b2b_partner_technicians', 'service_region_note')) {
                    $table->text('service_region_note')->nullable()->after('service_district');
                }

                if (! Schema::hasColumn('b2b_partner_technicians', 'priority')) {
                    $table->unsignedInteger('priority')->default(1)->index()->after('service_region_note');
                }

                if (! Schema::hasColumn('b2b_partner_technicians', 'needs_review')) {
                    $table->boolean('needs_review')->default(false)->index()->after('priority');
                }

                if (! Schema::hasColumn('b2b_partner_technicians', 'review_reason')) {
                    $table->text('review_reason')->nullable()->after('needs_review');
                }

                if (! Schema::hasColumn('b2b_partner_technicians', 'review_reasons')) {
                    $table->json('review_reasons')->nullable()->after('review_reason');
                }

                if (! Schema::hasColumn('b2b_partner_technicians', 'reviewed_at')) {
                    $table->timestamp('reviewed_at')->nullable()->after('review_reasons');
                }

                if (! Schema::hasColumn('b2b_partner_technicians', 'reviewed_by')) {
                    $table->unsignedBigInteger('reviewed_by')->nullable()->index()->after('reviewed_at');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('b2b_partner_technicians')) {
            Schema::table('b2b_partner_technicians', function (Blueprint $table): void {
                foreach ([
                    'reviewed_by',
                    'reviewed_at',
                    'review_reasons',
                    'review_reason',
                    'needs_review',
                    'priority',
                    'service_region_note',
                    'service_district',
                    'service_city',
                ] as $column) {
                    if (Schema::hasColumn('b2b_partner_technicians', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('technical_service_technicians')) {
            Schema::table('technical_service_technicians', function (Blueprint $table): void {
                foreach ([
                    'geocode_payload',
                    'geocoded_at',
                    'geocode_confidence',
                    'geocode_source',
                    'geocode_status',
                    'reviewed_by',
                    'reviewed_at',
                    'review_reasons',
                    'review_reason',
                    'review_status',
                ] as $column) {
                    if (Schema::hasColumn('technical_service_technicians', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
