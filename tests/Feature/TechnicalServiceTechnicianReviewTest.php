<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechnicalServiceTechnicianReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_location_fix_clears_review_when_complete(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Review Manual Class Usta',
            'first_name' => 'Review',
            'needs_review' => true,
            'review_status' => 'review_required',
            'review_reasons' => ['Telefon eksik.', 'Adres/şehir eksik.', 'Koordinat eksik.'],
            'active' => true,
        ]);

        $this->actingAs($user)
            ->patchJson("/api/technical-service/technicians/{$technician->id}/location-review", [
                'phone' => '+905555555662',
                'city' => 'İzmir',
                'district' => 'Konak',
                'address' => 'Manuel Mahallesi No:1',
                'latitude' => 38.423734,
                'longitude' => 27.142826,
                'start_latitude' => 38.423734,
                'start_longitude' => 27.142826,
                'mark_reviewed' => true,
            ])
            ->assertOk()
            ->assertJsonPath('technician.needs_review', false)
            ->assertJsonPath('technician.review_status', 'reviewed');

        $technician->refresh();
        $this->assertSame($user->id, $technician->reviewed_by);
        $this->assertNotNull($technician->reviewed_at);
    }

    public function test_mark_reviewed_fails_if_required_fields_missing(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);
        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Eksik Kontrol Class Usta',
            'first_name' => 'Eksik',
            'phone' => '+905555555663',
            'city' => 'İstanbul',
            'needs_review' => true,
            'active' => true,
        ]);

        $this->actingAs($user)
            ->postJson("/api/technical-service/technicians/{$technician->id}/mark-reviewed")
            ->assertStatus(422)
            ->assertJsonPath('errors.mark_reviewed.0', 'Kontrol kapatılamaz: telefon/adres/koordinat eksik.');
    }
}
