<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceCustomerConfirmation;
use App\Models\TechnicalServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceJobConfirmationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_job_confirmation_expired_link_renders_friendly_410_page(): void
    {
        $confirmation = TechnicalServiceCustomerConfirmation::query()->create([
            'technical_service_request_id' => $this->request()->id,
            'token' => 'expired-confirmation-token',
            'status' => TechnicalServiceCustomerConfirmation::STATUS_CANCELLED,
            'payload' => [],
        ]);

        $this->post("/service-job-confirmation/{$confirmation->token}/approve")
            ->assertStatus(410)
            ->assertSee('Bu onay bağlantısı artık geçerli değil', false)
            ->assertDontSee('Laravel', false);
    }

    public function test_service_job_confirmation_past_expires_at_renders_friendly_410_page(): void
    {
        $confirmation = TechnicalServiceCustomerConfirmation::query()->create([
            'technical_service_request_id' => $this->request()->id,
            'token' => 'past-expires-at-confirmation-token',
            'status' => TechnicalServiceCustomerConfirmation::STATUS_PENDING,
            'expires_at' => now()->subMinute(),
            'payload' => [],
        ]);

        $this->get("/service-job-confirmation/{$confirmation->token}")
            ->assertStatus(410)
            ->assertSee('Bu onay bağlantısı artık geçerli değil', false)
            ->assertDontSee('Laravel', false);
    }

    public function test_service_job_confirmation_already_approved_is_idempotent_or_friendly(): void
    {
        $confirmation = TechnicalServiceCustomerConfirmation::query()->create([
            'technical_service_request_id' => $this->request()->id,
            'token' => 'already-approved-confirmation-token',
            'status' => TechnicalServiceCustomerConfirmation::STATUS_APPROVED,
            'approved_at' => now(),
            'payload' => [],
        ]);

        $this->post("/service-job-confirmation/{$confirmation->token}/approve", [
            'customer_note' => 'Tekrar onay denemesi.',
        ])
            ->assertOk()
            ->assertSee('Teşekkür ederiz', false)
            ->assertDontSee('Laravel', false);
    }

    private function request(): TechnicalServiceRequest
    {
        return TechnicalServiceRequest::query()->create([
            'mrn' => 'MRN-CONFIRMATION-'.uniqid(),
            'customer_name' => 'Onay Müşterisi',
            'customer_phone' => '+905550000000',
            'customer_city' => 'İstanbul',
            'customer_district' => 'Kadıköy',
            'service_address' => 'Onay test adresi',
            'product_name' => 'Test Kilit',
            'service_type' => 'Montaj',
            'status' => TechnicalServiceRequest::STATUS_NEW,
            'workflow_status' => TechnicalServiceRequest::WORKFLOW_NEW_REQUEST,
            'source_channel' => TechnicalServiceRequest::SOURCE_QR_MOUNT_FORM,
        ]);
    }
}
