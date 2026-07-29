<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('b2b_partner_capabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('b2b_partners')->cascadeOnDelete();
            $table->string('capability', 32);
            $table->boolean('active')->default(true)->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['partner_id', 'capability'], 'b2b_partner_capabilities_unique');
            $table->index(['capability', 'active']);
        });

        DB::table('b2b_partners')
            ->select(['id', 'partner_type'])
            ->orderBy('id')
            ->get()
            ->each(function (object $partner): void {
                $capability = in_array($partner->partner_type, ['dealer', 'locksmith'], true)
                    ? $partner->partner_type
                    : 'dealer';

                DB::table('b2b_partner_capabilities')->insert([
                    'partner_id' => $partner->id,
                    'capability' => $capability,
                    'active' => true,
                    'metadata' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_partner_capabilities');
    }
};
