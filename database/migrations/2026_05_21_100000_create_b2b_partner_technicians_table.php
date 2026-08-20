<?php

use App\Models\B2B\B2BPartner;
use App\Models\TechnicalServiceTechnician;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('b2b_partner_technicians', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(B2BPartner::class, 'partner_id')->constrained('b2b_partners')->cascadeOnDelete();
            $table->foreignIdFor(TechnicalServiceTechnician::class, 'technical_service_technician_id')->constrained('technical_service_technicians')->cascadeOnDelete();
            $table->string('relationship_type', 64)->default('field_technician')->index();
            $table->boolean('is_primary')->default(false)->index();
            $table->boolean('active')->default(true)->index();
            $table->string('source', 64)->nullable()->index();
            $table->string('match_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamps();
            $table->unique(['partner_id', 'technical_service_technician_id'], 'b2b_partner_technician_unique');
            $table->index(['technical_service_technician_id', 'active'], 'b2b_partner_technician_active_index');
        });

        DB::table('b2b_partners')
            ->whereNotNull('technical_service_technician_id')
            ->orderBy('id')
            ->get(['id', 'technical_service_technician_id', 'metadata'])
            ->each(function (object $partner): void {
                DB::table('b2b_partner_technicians')->updateOrInsert(
                    [
                        'partner_id' => $partner->id,
                        'technical_service_technician_id' => $partner->technical_service_technician_id,
                    ],
                    [
                        'relationship_type' => 'field_technician',
                        'is_primary' => true,
                        'active' => true,
                        'source' => 'legacy_mirror',
                        'match_reason' => 'legacy_technical_service_technician_id',
                        'metadata' => is_string($partner->metadata) ? $partner->metadata : null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_partner_technicians');
    }
};
