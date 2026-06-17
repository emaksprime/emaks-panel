<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('b2b_partners', function (Blueprint $table): void {
            if (! Schema::hasColumn('b2b_partners', 'tax_number')) {
                $table->string('tax_number')->nullable();
            }

            if (! Schema::hasColumn('b2b_partners', 'tax_office')) {
                $table->string('tax_office')->nullable();
            }

            if (! Schema::hasColumn('b2b_partners', 'tax_identity_type')) {
                $table->string('tax_identity_type', 32)->nullable();
            }

            if (! Schema::hasColumn('b2b_partners', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable();
            }

            if (! Schema::hasColumn('b2b_partners', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable();
            }

            if (! Schema::hasColumn('b2b_partners', 'google_formatted_address')) {
                $table->text('google_formatted_address')->nullable();
            }

            if (! Schema::hasColumn('b2b_partners', 'google_plus_code')) {
                $table->string('google_plus_code')->nullable();
            }

            if (! Schema::hasColumn('b2b_partners', 'location_source')) {
                $table->string('location_source')->nullable();
            }

            if (! Schema::hasColumn('b2b_partners', 'geocode_status')) {
                $table->string('geocode_status')->nullable();
            }

            if (! Schema::hasColumn('b2b_partners', 'geocode_source')) {
                $table->string('geocode_source')->nullable();
            }

            if (! Schema::hasColumn('b2b_partners', 'geocode_confidence')) {
                $table->decimal('geocode_confidence', 8, 4)->nullable();
            }

            if (! Schema::hasColumn('b2b_partners', 'geocoded_at')) {
                $table->timestamp('geocoded_at')->nullable();
            }

            if (! Schema::hasColumn('b2b_partners', 'geocode_payload')) {
                $table->json('geocode_payload')->nullable();
            }

            if (! Schema::hasColumn('b2b_partners', 'needs_review')) {
                $table->boolean('needs_review')->default(false);
            }

            if (! Schema::hasColumn('b2b_partners', 'review_reason')) {
                $table->text('review_reason')->nullable();
            }

            if (! Schema::hasColumn('b2b_partners', 'review_reasons')) {
                $table->json('review_reasons')->nullable();
            }

            if (! Schema::hasColumn('b2b_partners', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable();
            }

            if (! Schema::hasColumn('b2b_partners', 'reviewed_by')) {
                $table->unsignedBigInteger('reviewed_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('b2b_partners', function (Blueprint $table): void {
            foreach ([
                'tax_number',
                'tax_office',
                'tax_identity_type',
                'latitude',
                'longitude',
                'google_formatted_address',
                'google_plus_code',
                'location_source',
                'geocode_status',
                'geocode_source',
                'geocode_confidence',
                'geocoded_at',
                'geocode_payload',
                'needs_review',
                'review_reason',
                'review_reasons',
                'reviewed_at',
                'reviewed_by',
            ] as $column) {
                if (Schema::hasColumn('b2b_partners', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
