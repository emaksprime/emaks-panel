<?php

use App\Models\B2B\B2BPartner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('b2b_partners', function (Blueprint $table) {
            if (! Schema::hasColumn('b2b_partners', 'address')) {
                $table->text('address')->nullable();
            }
        });

        Schema::create('b2b_cari_snapshot_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source_code', 128)->index();
            $table->string('status', 32)->index();
            $table->timestamp('started_at')->index();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('total_received')->default(0);
            $table->unsignedInteger('total_normalized')->default(0);
            $table->unsignedInteger('excluded_online_retail_count')->default(0);
            $table->unsignedInteger('new_count')->default(0);
            $table->unsignedInteger('changed_count')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('b2b_cari_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('source_code', 128)->index();
            $table->string('base_mikro_cari_kodu', 128)->index();
            $table->string('mikro_cari_kodu', 128)->index();
            $table->string('mikro_cari_unvan')->nullable();
            $table->string('normalized_unvan')->nullable()->index();
            $table->string('cari_grup_kodu', 128)->nullable();
            $table->string('responsibility_code', 128)->nullable();
            $table->string('temsilci_kodu', 128)->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('email')->nullable();
            $table->string('city', 128)->nullable()->index();
            $table->string('district', 128)->nullable();
            $table->text('address')->nullable();
            $table->string('tax_no', 64)->nullable();
            $table->string('tax_office', 128)->nullable();
            $table->json('suggested_capabilities')->nullable();
            $table->json('child_cari_accounts')->nullable();
            $table->json('invoice_profile')->nullable();
            $table->json('shipping_profile')->nullable();
            $table->json('raw_payload')->nullable();
            $table->string('payload_hash', 64)->index();
            $table->foreignIdFor(B2BPartner::class, 'existing_partner_id')->nullable()->constrained('b2b_partners')->nullOnDelete();
            $table->string('candidate_status', 32)->index();
            $table->string('review_reason')->nullable();
            $table->timestamp('last_seen_at')->index();
            $table->timestamps();
            $table->unique(['source_code', 'base_mikro_cari_kodu'], 'b2b_cari_snapshot_source_base_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_cari_snapshots');
        Schema::dropIfExists('b2b_cari_snapshot_runs');

        Schema::table('b2b_partners', function (Blueprint $table) {
            if (Schema::hasColumn('b2b_partners', 'address')) {
                $table->dropColumn('address');
            }
        });
    }
};
