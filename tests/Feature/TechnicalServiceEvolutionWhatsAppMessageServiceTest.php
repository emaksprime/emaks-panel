<?php

namespace Tests\Feature;

use App\Models\B2B\B2BPartner;
use App\Models\B2B\B2BPartnerCapability;
use App\Models\B2B\B2BPartnerTechnician;
use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceTechnician;
use App\Services\Messaging\EvolutionWhatsAppMessageService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TechnicalServiceEvolutionWhatsAppMessageServiceTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public function runDatabaseMigrations(): void
    {
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();

        $this->beforeApplicationDestroyed(function (): void {
            // The in-memory connection is discarded after each test.
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_unit_tests_never_send_real_whatsapp_http(): void
    {
        $this->configureEvolution(['services.evolution.real_send_enabled' => true]);
        Http::fake();

        $dispatch = $this->service()->send(
            'customer_approval_request',
            'customer',
            '05321112233',
            'Onay linki hazir.',
            ['confirmation_url' => 'https://panel.test/service-job-confirmation/token'],
            $this->requestWithMrn('MRN-WP-UNIT-GUARD'),
        );

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_TESTING_ENVIRONMENT, $dispatch->refresh()->status);
        Http::assertNothingSent();
    }

    public function test_fixture_mrn_is_suppressed_by_default(): void
    {
        $this->configureEvolution(['services.evolution.real_send_enabled' => true]);
        Http::fake();

        $dispatch = $this->service()->send(
            'customer_approval_request',
            'customer',
            '05321112233',
            'Onay linki hazir.',
            $this->manualContext(['confirmation_url' => 'https://panel.test/service-job-confirmation/token']),
            $this->requestWithMrn('MRN-PR88-WP-SUPPRESS'),
        );

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_TEST_FIXTURE, $dispatch->refresh()->status);
        Http::assertNothingSent();
    }

    public function test_faz2a_assignment_smoke_mrn_is_suppressed_by_default(): void
    {
        $this->configureEvolution(['services.evolution.real_send_enabled' => true]);
        Http::fake();

        $dispatch = $this->service()->send(
            'assignment_offer_technician',
            'technician',
            '05321112233',
            'Yeni iş ataması yapıldı.',
            $this->manualContext(['job_link' => 'https://panel.test/partner/service-jobs?job_id=1']),
            $this->requestWithMrn('FAZ2A-ASSIGN-20260611080000'),
        );

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_TEST_FIXTURE, $dispatch->refresh()->status);
        Http::assertNothingSent();
    }

    public function test_browser_smoke_send_is_suppressed_by_default(): void
    {
        $this->configureEvolution(['services.evolution.real_send_enabled' => true]);
        Http::fake();

        $dispatch = $this->service()->send(
            'customer_approval_request',
            'customer',
            '05321112233',
            'Onay linki hazir.',
            $this->manualContext([
                'browser_smoke' => true,
                'confirmation_url' => 'https://panel.test/service-job-confirmation/token',
            ]),
            $this->requestWithMrn('MRN-WP-BROWSER-SMOKE'),
        );

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_REAL_SEND_DISABLED, $dispatch->refresh()->status);
        Http::assertNothingSent();
    }

    public function test_manual_ui_send_cannot_bypass_local_execution_mode_when_legacy_real_send_is_enabled(): void
    {
        $this->configureEvolution(['services.evolution.real_send_enabled' => true]);
        Http::fake([
            'https://n8n.test/*' => Http::response(['ok' => true], 200),
        ]);

        $dispatch = $this->service()->send(
            'customer_approval_request',
            'customer',
            '05321112233',
            'Onay linki hazir.',
            $this->manualContext(['confirmation_url' => 'https://panel.test/service-job-confirmation/token']),
            $this->requestWithMrn('MRN-WP-MANUAL-SEND'),
        );

        $payload = $dispatch->refresh()->request_payload;
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_REAL_SEND_DISABLED, $dispatch->status);
        $this->assertSame('905467647428', $payload['target_phone']);
        $this->assertSame('905321112233', $payload['original_phone']);
        $this->assertArrayHasKey('idempotency_key', $payload);
        $this->assertSame('outbound_execution_mode_local', $dispatch->response_payload['execution_mode_block_code'] ?? null);
        Http::assertNothingSent();
    }

    public function test_ci_fake_flag_cannot_bypass_local_execution_mode(): void
    {
        $this->configureEvolution(['services.evolution.real_send_enabled' => true]);
        Http::fake([
            'https://n8n.test/*' => Http::response(['ok' => true], 200),
        ]);

        $this->withCiEnvironment(function (): void {
            $dispatch = $this->service()->send(
                'customer_approval_request',
                'customer',
                '05321112233',
                'Onay linki hazir.',
                $this->manualContext(['confirmation_url' => 'https://panel.test/service-job-confirmation/token']),
                $this->requestWithMrn('MRN-WP-CI-FAKE-SEND'),
            );

            $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_REAL_SEND_DISABLED, $dispatch->refresh()->status);
            Http::assertNothingSent();
        });
    }

    public function test_repeated_direct_messages_remain_suppressed_without_http(): void
    {
        $this->configureEvolution([
            'services.evolution.real_send_enabled' => true,
            'services.evolution.test_phone_min_seconds' => 0,
        ]);
        Http::fake([
            'https://n8n.test/*' => Http::response(['ok' => true], 200),
        ]);
        $request = $this->requestWithMrn('MRN-WP-DUPLICATE');
        $context = $this->manualContext(['confirmation_url' => 'https://panel.test/service-job-confirmation/token']);

        $first = $this->service()->send('customer_approval_request', 'customer', '05321112233', 'Onay linki hazir.', $context, $request);
        $second = $this->service()->send('customer_approval_request', 'customer', '05321112233', 'Onay linki hazir.', $context, $request);

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_REAL_SEND_DISABLED, $first->refresh()->status);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_REAL_SEND_DISABLED, $second->refresh()->status);
        Http::assertNothingSent();
    }

    public function test_force_resend_cannot_bypass_execution_mode_and_fixture_guard_remains(): void
    {
        $this->configureEvolution([
            'services.evolution.real_send_enabled' => true,
            'services.evolution.test_phone_min_seconds' => 0,
        ]);
        Http::fake([
            'https://n8n.test/*' => Http::response(['ok' => true], 200),
        ]);
        $request = $this->requestWithMrn('MRN-WP-FORCE-RESEND');
        $context = $this->manualContext(['confirmation_url' => 'https://panel.test/service-job-confirmation/token']);

        $first = $this->service()->send('customer_approval_request', 'customer', '05321112233', 'Onay linki hazir.', $context, $request);
        $forced = $this->service()->send('customer_approval_request', 'customer', '05321112233', 'Onay linki hazir.', [
            ...$context,
            'force_resend' => true,
        ], $request);
        $fixture = $this->service()->send('customer_approval_request', 'customer', '05321112233', 'Onay linki hazir.', [
            ...$context,
            'force_resend' => true,
        ], $this->requestWithMrn('SMOKE-WP-FORCE-RESEND'));

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_REAL_SEND_DISABLED, $first->refresh()->status);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_REAL_SEND_DISABLED, $forced->refresh()->status);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_TEST_FIXTURE, $fixture->refresh()->status);
        Http::assertNothingSent();
    }

    public function test_fifty_direct_dispatches_remain_audited_without_http_calls(): void
    {
        $this->configureEvolution(['services.evolution.real_send_enabled' => true]);
        Http::fake([
            'https://n8n.test/*' => Http::response(['ok' => true], 200),
        ]);

        for ($i = 1; $i <= 50; $i++) {
            $this->service()->send(
                'customer_approval_request',
                'customer',
                '05321112233',
                "Onay linki hazir {$i}.",
                $this->manualContext(['confirmation_url' => "https://panel.test/service-job-confirmation/token-{$i}"]),
                $this->requestWithMrn("MRN-WP-BURST-{$i}"),
            );
        }

        $this->assertSame(0, TechnicalServiceMessageDispatch::query()->where('status', TechnicalServiceMessageDispatch::STATUS_SENT)->count());
        $this->assertSame(50, TechnicalServiceMessageDispatch::query()->where('status', TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_REAL_SEND_DISABLED)->count());
        Http::assertNothingSent();
    }

    public function test_rate_limit_flags_do_not_override_local_execution_mode(): void
    {
        $this->configureEvolution(['services.evolution.real_send_enabled' => true]);
        Http::fake([
            'https://n8n.test/*' => Http::response(['ok' => true], 200),
        ]);

        $first = $this->service()->send(
            'customer_approval_request',
            'customer',
            '05321112233',
            'Ilk onay linki.',
            $this->manualContext(['confirmation_url' => 'https://panel.test/service-job-confirmation/token-1']),
            $this->requestWithMrn('MRN-WP-RATE-1'),
        );
        $second = $this->service()->send(
            'customer_approval_request',
            'customer',
            '05321112233',
            'Ikinci onay linki.',
            $this->manualContext(['confirmation_url' => 'https://panel.test/service-job-confirmation/token-2']),
            $this->requestWithMrn('MRN-WP-RATE-2'),
        );

        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_REAL_SEND_DISABLED, $first->refresh()->status);
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_REAL_SEND_DISABLED, $second->refresh()->status);
        Http::assertNothingSent();
    }

    public function test_suppressed_dispatch_records_reason(): void
    {
        $this->configureEvolution(['services.evolution.real_send_enabled' => false]);
        Http::fake();

        $dispatch = $this->service()->send(
            'customer_approval_request',
            'customer',
            '05321112233',
            'Onay linki hazir.',
            $this->manualContext(['confirmation_url' => 'https://panel.test/service-job-confirmation/token']),
            $this->requestWithMrn('MRN-WP-SUPPRESSED-REASON'),
        );

        $dispatch->refresh();
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_REAL_SEND_DISABLED, $dispatch->status);
        $this->assertSame('suppressed', $dispatch->response_payload['status'] ?? null);
        $this->assertNotEmpty($dispatch->response_payload['message'] ?? null);
        $this->assertNotEmpty($dispatch->error_message);
        Http::assertNothingSent();
    }

    public function test_evo_test_payload_preserves_newlines_while_direct_http_remains_blocked(): void
    {
        $this->configureEvolution([
            'services.evolution.real_send_enabled' => false,
            'services.evolution.allow_unit_test_http_fake' => true,
            'services.evolution.test_phone_min_seconds' => 0,
        ]);
        Http::fake([
            'https://n8n.test/*' => Http::response(['ok' => true], 200),
        ]);

        $dispatch = $this->service()->send(
            'template_test_whatsapp',
            'shared_test_phone',
            '905467647428',
            "EMAKS Prime Teknik Servis\n\nSayın PR88 Test,\nRandevu Bilgileri\nMRN: PR88-REL4C4",
            [
                'message_type' => 'appointment_approved_customer',
                'manual_ui_send' => true,
                'provider_test' => true,
                'allow_unit_test_http_fake' => true,
            ],
        );

        $payload = $dispatch->refresh()->request_payload;
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_REAL_SEND_DISABLED, $dispatch->status);
        $this->assertSame('shared_test_phone', $payload['target_type']);
        $this->assertSame('905467647428', $payload['target_phone']);
        $this->assertStringContainsString("\n\nSayın", $payload['text']);
        $this->assertStringContainsString("\nRandevu Bilgileri\n", $payload['text']);

        Http::assertNothingSent();
    }

    public function test_evo_test_fake_failure_endpoint_is_not_called_without_canonical_claim(): void
    {
        $this->configureEvolution([
            'services.evolution.real_send_enabled' => false,
            'services.evolution.allow_unit_test_http_fake' => true,
            'services.evolution.test_phone_min_seconds' => 0,
        ]);
        Http::fake([
            'https://n8n.test/*' => Http::response('error code: 1033', 530),
        ]);

        $dispatch = $this->service()->send(
            'template_test_whatsapp',
            'shared_test_phone',
            '905467647428',
            "EMAKS Prime Teknik Servis\n\nSayın PR88 Test,\nRandevu Bilgileri\nMRN: PR88-REL4C5",
            [
                'message_type' => 'appointment_approved_customer',
                'manual_ui_send' => true,
                'provider_test' => true,
                'allow_unit_test_http_fake' => true,
            ],
        );

        $dispatch->refresh();
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_REAL_SEND_DISABLED, $dispatch->status);
        $this->assertSame('suppressed', $dispatch->response_payload['status'] ?? null);
        $this->assertSame('outbound_execution_mode_local', $dispatch->response_payload['execution_mode_block_code'] ?? null);
        $this->assertStringContainsString("\nRandevu Bilgileri\n", $dispatch->request_payload['text']);
        Http::assertNothingSent();
    }

    public function test_customer_approval_direct_path_returns_clear_local_suppression(): void
    {
        $this->configureEvolution(['services.evolution.real_send_enabled' => true]);
        Http::fake([
            'https://n8n.test/*' => Http::response(['message' => 'Workflow was started'], 200),
        ]);

        $dispatch = $this->service()->send(
            'customer_approval_request',
            'customer',
            '05321112233',
            'Musteri onay linki hazir.',
            $this->manualContext([
                'message_type' => 'customer_approval_request',
                'confirmation_url' => 'https://panel.test/service-job-confirmation/token',
            ]),
            $this->requestWithMrn('MRN-WP-CUSTOMER-APPROVAL'),
        );

        $payload = $dispatch->refresh()->request_payload;
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_REAL_SEND_DISABLED, $dispatch->status);
        $this->assertSame('customer_approval_request', $payload['message_type']);
        $this->assertSame('https://panel.test/service-job-confirmation/token', $payload['confirmation_url']);
        $this->assertSame('suppressed', $dispatch->response_payload['status'] ?? null);
        Http::assertNothingSent();
    }

    public function test_evolution_payload_contract_is_preserved_while_direct_http_is_blocked(): void
    {
        $this->configureEvolution([
            'services.evolution.real_send_enabled' => true,
            'services.evolution.test_phone_min_seconds' => 0,
            'services.partner_portal.public_url' => 'https://panel.test',
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
        $partner = B2BPartner::query()->create([
            'partner_type' => B2BPartner::TYPE_LOCKSMITH,
            'partner_code' => 'WP-CONTRACT',
            'display_name' => 'Workflow Contract Locksmith',
            'active' => true,
        ]);
        B2BPartnerCapability::query()->create([
            'partner_id' => $partner->id,
            'capability' => B2BPartner::TYPE_LOCKSMITH,
            'active' => true,
        ]);
        B2BPartnerTechnician::query()->create([
            'partner_id' => $partner->id,
            'technical_service_technician_id' => $technician->id,
            'relationship_type' => 'field_technician',
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

        $dispatch = $this->service()->send(
            'assignment_offer_technician',
            'technician',
            $technician->phone,
            'Usta hakedis bilgilendirme metni',
            $this->manualContext([
                'labor_amount' => 3000,
                'route_fee_amount' => 350,
                'total_amount' => 3350,
                'currency' => 'TRY',
                'job_link' => 'https://panel.test/partner/service-jobs?job_id='.$request->id,
            ]),
            $request,
        );

        $payload = $dispatch->refresh()->request_payload;
        $this->assertSame(TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_REAL_SEND_DISABLED, $dispatch->status);
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
        $this->assertStringStartsWith('https://panel.test/partner/service-jobs?', $payload['job_link']);
        $this->assertStringContainsString('partner_id='.$partner->id, $payload['job_link']);
        $this->assertStringContainsString('job_id='.$request->id, $payload['job_link']);
        $this->assertStringContainsString((string) $payload['job_link'], $payload['text']);
        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function configureEvolution(array $overrides = []): void
    {
        config([
            'services.evolution.n8n_webhook_url' => 'https://n8n.test/webhook/emaks/evo/send-message',
            'services.evolution.test_mode' => true,
            'services.evolution.test_phone' => '905467647428',
            'services.evolution.real_send_enabled' => false,
            'services.evolution.allow_test_fixture_send' => false,
            'services.evolution.allow_browser_smoke_send' => false,
            'services.evolution.idempotency_window_minutes' => 30,
            'services.evolution.target_min_seconds' => 5,
            'services.evolution.test_phone_min_seconds' => 20,
            'services.evolution.test_phone_window_minutes' => 10,
            'services.evolution.test_phone_window_max' => 5,
            'services.evolution.test_phone_daily_max' => 20,
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function manualContext(array $context = []): array
    {
        return [
            ...$context,
            'manual_ui_send' => true,
            'allow_unit_test_http_fake' => true,
        ];
    }

    private function requestWithMrn(string $mrn): TechnicalServiceRequest
    {
        return TechnicalServiceRequest::query()->create([
            'mrn' => $mrn,
            'customer_name' => 'WhatsApp Musteri',
            'customer_phone' => '05551112233',
            'customer_city' => 'Istanbul',
            'customer_district' => 'Kadikoy',
            'service_address' => 'Fixture adresi',
            'product_name' => 'Test Kilit',
            'service_type' => 'Montaj',
            'status' => 'Yeni',
        ]);
    }

    private function service(): EvolutionWhatsAppMessageService
    {
        return app(EvolutionWhatsAppMessageService::class);
    }

    private function withCiEnvironment(callable $callback): void
    {
        $previousPutenv = getenv('CI');
        $hadEnv = array_key_exists('CI', $_ENV);
        $previousEnv = $_ENV['CI'] ?? null;
        $hadServer = array_key_exists('CI', $_SERVER);
        $previousServer = $_SERVER['CI'] ?? null;

        putenv('CI=true');
        $_ENV['CI'] = 'true';
        $_SERVER['CI'] = 'true';

        try {
            $callback();
        } finally {
            if ($previousPutenv === false) {
                putenv('CI');
            } else {
                putenv('CI='.$previousPutenv);
            }

            if ($hadEnv) {
                $_ENV['CI'] = $previousEnv;
            } else {
                unset($_ENV['CI']);
            }

            if ($hadServer) {
                $_SERVER['CI'] = $previousServer;
            } else {
                unset($_SERVER['CI']);
            }
        }
    }
}
