<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceTechnician;
use App\Services\Messaging\EvolutionWhatsAppMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TechnicalServiceEvolutionWhatsAppMessageServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_evolution_payload_matches_n8n_workflow_contract_in_test_mode(): void
    {
        config([
            'services.evolution.n8n_webhook_url' => 'https://n8n.test/webhook/emaks/evo/send-message',
            'services.evolution.test_mode' => true,
            'services.evolution.test_phone' => '905467647428',
        ]);
        Http::fake([
            'https://n8n.test/*' => Http::response(['ok' => true], 200),
        ]);

        $technician = TechnicalServiceTechnician::query()->create([
            'name' => 'Workflow Usta',
            'phone' => '+905559998877',
            'city' => 'Istanbul',
            'active' => true,
        ]);
        $request = TechnicalServiceRequest::query()->create([
            'mrn' => 'MRN-WP-CONTRACT',
            'customer_name' => 'Workflow Musteri',
            'customer_phone' => '05551112233',
            'customer_city' => 'Istanbul',
            'customer_district' => 'Kadikoy',
            'service_address' => 'Caferaga Mah. Moda Cad. No:10 Kadikoy Istanbul',
            'product_name' => 'GALAXY 20 Akilli Kapi Kilidi',
            'product_model' => 'GALAXY 20 - GRI',
            'serial_number' => 'SN-WP-1',
            'service_type' => 'Montaj',
            'status' => 'Atandi',
            'workflow_status' => 'Usta Onayi Bekleyen',
            'technical_service_technician_id' => $technician->id,
            'scheduled_date' => '2026-05-23',
            'scheduled_time' => '10:00-11:00',
        ]);

        $dispatch = app(EvolutionWhatsAppMessageService::class)->send(
            'assignment_offer_technician',
            'technician',
            $technician->phone,
            'Usta hakedis bilgilendirme metni',
            [
                'labor_amount' => 3000,
                'route_fee_amount' => 350,
                'total_amount' => 3350,
                'currency' => 'TRY',
                'job_link' => 'https://panel.test/partner/service-jobs?job_id='.$request->id,
            ],
            $request,
        );

        $payload = $dispatch->refresh()->request_payload;
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENT, $dispatch->status);
        $this->assertSame('905467647428', $payload['target_phone']);
        $this->assertSame('905559998877', $payload['original_phone']);
        $this->assertSame('assignment_offer_technician', $payload['event']);
        $this->assertSame('MRN-WP-CONTRACT', $payload['mrn']);
        $this->assertSame('Workflow Musteri', $payload['customer_name']);
        $this->assertSame('05551112233', $payload['customer_phone']);
        $this->assertSame('Workflow Usta', $payload['technician_name']);
        $this->assertSame('+905559998877', $payload['technician_phone']);
        $this->assertSame('GALAXY 20 Akilli Kapi Kilidi', $payload['product_name']);
        $this->assertSame('GALAXY 20 - GRI', $payload['model']);
        $this->assertSame('23.05.2026', $payload['appointment_date']);
        $this->assertSame('10:00-11:00', $payload['appointment_time_range']);
        $this->assertSame('3.000 TRY', $payload['labor_amount']);
        $this->assertSame('350 TRY', $payload['route_fee_amount']);
        $this->assertSame('3.350 TRY', $payload['total_amount']);
        $this->assertStringContainsString('/partner/service-jobs?job_id='.$request->id, $payload['job_link']);

        Http::assertSent(fn ($httpRequest): bool => $httpRequest->url() === 'https://n8n.test/webhook/emaks/evo/send-message'
            && $httpRequest['target_phone'] === '905467647428'
            && $httpRequest['event'] === 'assignment_offer_technician'
            && $httpRequest['labor_amount'] === '3.000 TRY');
    }

    public function test_evolution_payload_routes_real_phone_when_test_mode_is_disabled(): void
    {
        config([
            'services.evolution.n8n_webhook_url' => 'https://n8n.test/webhook/emaks/evo/send-message',
            'services.evolution.test_mode' => false,
            'services.evolution.test_phone' => '905467647428',
        ]);
        Http::fake([
            'https://n8n.test/*' => Http::response(['ok' => true], 200),
        ]);

        $dispatch = app(EvolutionWhatsAppMessageService::class)->send(
            'customer_approval_request',
            'customer',
            '05321112233',
            'Onay linki hazir.',
            ['confirmation_url' => 'https://panel.test/service-job-confirmation/token'],
        );

        $payload = $dispatch->refresh()->request_payload;
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SENT, $dispatch->status);
        $this->assertSame('905321112233', $payload['target_phone']);
        $this->assertSame('905321112233', $payload['original_phone']);
        $this->assertFalse($payload['test_mode']);
        $this->assertSame('https://panel.test/service-job-confirmation/token', $payload['confirmation_url']);
    }
}
