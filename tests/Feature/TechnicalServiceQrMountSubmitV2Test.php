<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceQrLink;
use App\Models\TechnicalServiceRequest;
use App\Models\User;
use App\Services\TechnicalService\MountRequestSubmitService;
use App\Services\TechnicalService\SerialProductContextResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TechnicalServiceQrMountSubmitV2Test extends TestCase
{
    use RefreshDatabase;

    public function test_show_form_decision_renders_basic_customer_form_fields(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('public/mount-request-v2')
                ->where('viewState', 'form_ready')
                ->where('actions.submit_url', route('mount-request.submit', ['token' => $token])));

        $source = file_get_contents(resource_path('js/pages/public/mount-request-v2.tsx'));
        $this->assertIsString($source);

        foreach ([
            'İsim',
            'Soyisim',
            'Telefon Numarası',
            'İl',
            'İlçe',
            'Adres',
            'Montaj şartlarını kabul ediyorum',
            'KVKK / Aydınlatma ve Açık Rıza Onayı',
            'https://emaksprime.com/kvkk-on-bilgilendirme/',
            'target="_blank"',
            'placeholder="5xxxxxxxxx"',
            'maxLength={10}',
            'inputMode="numeric"',
            'pattern="[0-9]*"',
            'normalizePhoneDigits(event.target.value)',
            'return digits.slice(0, 10);',
            'Talebiniz gönderiliyor...',
            'Gönderiliyor...',
        ] as $expectedText) {
            $this->assertStringContainsString($expectedText, $source);
        }
    }

    public function test_montaj_dahil_not_required_submit_creates_request(): void
    {
        $request = $this->submitForSaleMountStatus(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);

        $this->assertSame('Burhan Test', $request->customer_name);
        $this->assertSame('+905372081655', $request->customer_phone);
        $this->assertSame('İstanbul', $request->customer_city);
        $this->assertSame('Kadıköy', $request->customer_district);
        $this->assertSame('Test adres', $request->service_address);
        $this->assertSame('Emaks Prime Test Kilit', $request->product_name);
        $this->assertSame('DDL720', $request->product_model);
        $this->assertSame('QR-V2-SUBMIT-001', $request->serial_number);
        $this->assertSame('Montaj', $request->service_type);
    }

    public function test_montaj_haric_unpaid_submit_is_blocked(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_HARIC);
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token)->assertOk();

        $this->from('/mount-request/'.$token)
            ->post('/mount-request/'.$token.'/submit', $this->validPayload())
            ->assertRedirect('/mount-request/'.$token)
            ->assertSessionHasErrors(['form']);

        $this->assertDatabaseCount('technical_service_requests', 0);
    }

    public function test_montaj_haric_paid_submit_creates_request(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_HARIC);
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token)->assertOk();
        $this->post('/mount-request/'.$token.'/payment')->assertRedirect('/mount-request/'.$token);
        $payment = \App\Models\TechnicalServiceMountPayment::query()->firstOrFail();
        $this->get("/mount-payment/fake/{$payment->id}/approve?token={$token}")
            ->assertRedirect('/mount-request/'.$token);

        $this->post('/mount-request/'.$token.'/submit', $this->validPayload())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('viewState', 'submitted')
                ->where('message', 'Montaj talebiniz alınmıştır.')
                ->has('submitted.mrn'));

        $request = TechnicalServiceRequest::query()->firstOrFail();
        $this->assertSame(TechnicalServiceRequest::STATUS_NEW, $request->status);
        $this->assertSame(TechnicalServiceRequest::WORKFLOW_NEW_REQUEST, $request->workflow_status);
    }

    public function test_multi_product_without_payment_submit_creates_request_with_operation_warning(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_HARIC);
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token)->assertOk();
        $this->post('/mount-request/'.$token.'/multi-product')->assertRedirect('/mount-request/'.$token);

        $this->post('/mount-request/'.$token.'/submit', $this->validPayload())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('viewState', 'submitted')
                ->has('submitted.mrn'));

        $request = TechnicalServiceRequest::query()->firstOrFail();
        $this->assertStringContainsString(MountRequestSubmitService::MULTI_PRODUCT_OPERATION_WARNING, $request->description);
    }

    public function test_phone_with_plus_ninety_is_accepted_and_stored_normalized(): void
    {
        $request = $this->submitWithPhone('+905372081655');

        $this->assertSame('+905372081655', $request->customer_phone);
    }

    public function test_phone_with_leading_zero_is_accepted_and_stored_normalized(): void
    {
        $request = $this->submitWithPhone('05372081655');

        $this->assertSame('+905372081655', $request->customer_phone);
    }

    public function test_phone_with_ten_digits_is_accepted_and_stored_normalized(): void
    {
        $request = $this->submitWithPhone('5372081655');

        $this->assertSame('+905372081655', $request->customer_phone);
    }

    public function test_short_or_long_phone_is_rejected(): void
    {
        foreach (['537208165', '53720816555'] as $phone) {
            $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
            [, $token] = $this->qrLink();
            $this->get('/mount-request/'.$token)->assertOk();

            $this->from('/mount-request/'.$token)
                ->post('/mount-request/'.$token.'/submit', $this->validPayload(['phone' => $phone]))
                ->assertRedirect('/mount-request/'.$token)
                ->assertSessionHasErrors(['phone']);
        }

        $this->assertDatabaseCount('technical_service_requests', 0);
    }

    public function test_submit_requires_installation_and_kvkk_consents(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        [, $token] = $this->qrLink();
        $this->get('/mount-request/'.$token)->assertOk();

        $this->from('/mount-request/'.$token)
            ->post('/mount-request/'.$token.'/submit', $this->validPayload([
                'installation_consent' => false,
                'kvkk_consent' => false,
            ]))
            ->assertRedirect('/mount-request/'.$token)
            ->assertSessionHasErrors(['installation_consent', 'kvkk_consent']);

        $this->assertDatabaseCount('technical_service_requests', 0);
    }

    public function test_submit_persists_yeni_yeni_talep_and_never_inceleniyor(): void
    {
        $request = $this->submitForSaleMountStatus(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);

        $this->assertSame(TechnicalServiceRequest::STATUS_NEW, $request->status);
        $this->assertSame(TechnicalServiceRequest::WORKFLOW_NEW_REQUEST, $request->workflow_status);
        $this->assertNotSame('İnceleniyor', $request->status);
        $this->assertNotSame('İnceleniyor', $request->workflow_status);

        $admin = User::factory()->create(['role_code' => 'admin']);
        $this->actingAs($admin)
            ->getJson('/api/technical-service/requests?limit=10')
            ->assertOk()
            ->assertJsonPath('items.0.status', TechnicalServiceRequest::STATUS_NEW)
            ->assertJsonPath('items.0.workflow_status', TechnicalServiceRequest::WORKFLOW_NEW_REQUEST);
    }

    public function test_success_screen_shows_mrn(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        [, $token] = $this->qrLink();
        $this->get('/mount-request/'.$token)->assertOk();

        $response = $this->post('/mount-request/'.$token.'/submit', $this->validPayload())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('viewState', 'submitted')
                ->where('message', 'Montaj talebiniz alınmıştır.')
                ->has('submitted.mrn'));

        $request = TechnicalServiceRequest::query()->firstOrFail();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('submitted.mrn', $request->mrn));
        $this->assertSame(TechnicalServiceMountSession::DECISION_SUBMITTED, TechnicalServiceMountSession::query()->firstOrFail()->decision_status);
    }

    private function submitForSaleMountStatus(string $saleMountStatus): TechnicalServiceRequest
    {
        $this->fakeContext($saleMountStatus);
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token)->assertOk();
        $this->post('/mount-request/'.$token.'/submit', $this->validPayload())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('viewState', 'submitted')
                ->has('submitted.mrn'));

        return TechnicalServiceRequest::query()->firstOrFail();
    }

    private function submitWithPhone(string $phone): TechnicalServiceRequest
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token)->assertOk();
        $this->post('/mount-request/'.$token.'/submit', $this->validPayload(['phone' => $phone]))
            ->assertOk();

        return TechnicalServiceRequest::query()->latest('id')->firstOrFail();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Burhan',
            'last_name' => 'Test',
            'phone' => '+905372081655',
            'city' => 'İstanbul',
            'district' => 'Kadıköy',
            'address' => 'Test adres',
            'installation_consent' => true,
            'kvkk_consent' => true,
        ], $overrides);
    }

    private function fakeContext(string $saleMountStatus): void
    {
        $this->app->instance(
            SerialProductContextResolver::class,
            new class($saleMountStatus) extends SerialProductContextResolver {
                public function __construct(private readonly string $saleMountStatus)
                {
                }

                public function resolve(string $serialNumber, array $knownContext = []): array
                {
                    return [
                        'serial_number' => $serialNumber,
                        'product_name' => $knownContext['product_name'] ?? 'Emaks Prime Test Kilit',
                        'product_model' => $knownContext['product_model'] ?? 'DDL720',
                        'brand' => $knownContext['brand'] ?? 'EMAKS PRIME',
                        'activation_code' => null,
                        'sale_mount_status' => $this->saleMountStatus,
                        'invoice_customer_type' => 'unknown',
                        'context_payload' => ['source' => 'test_fake_context'],
                    ];
                }
            },
        );
    }

    /**
     * @return array{0: TechnicalServiceQrLink, 1: string}
     */
    private function qrLink(): array
    {
        ['link' => $link, 'token' => $token] = TechnicalServiceQrLink::createPreSaleProductLink([
            'serial_number' => 'QR-V2-SUBMIT-001',
            'product_name' => 'Emaks Prime Test Kilit',
            'product_model' => 'DDL720',
            'brand' => 'EMAKS PRIME',
        ]);

        return [$link, $token];
    }
}
