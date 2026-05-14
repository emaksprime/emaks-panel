<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceQrLink;
use App\Models\User;
use App\Services\TechnicalService\SerialProductContextResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TechnicalServiceQrMountPublicFlowV2Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_authorized_user_can_create_pre_sale_qr_link(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);

        $payload = [
            'serial_number' => 'QR-V2-ADMIN-001',
            'product_name' => 'Emaks Prime Test Kilit',
            'product_model' => 'DDL720',
            'brand' => 'EMAKS PRIME',
            'link_type' => TechnicalServiceQrLink::TYPE_PRE_SALE_PRODUCT,
        ];

        $response = $this->actingAs($user)
            ->postJson('/api/admin/technical-service/qr-links', $payload)
            ->assertCreated()
            ->assertJsonPath('link.serial_number', 'QR-V2-ADMIN-001')
            ->assertJsonPath('link.product_name', 'Emaks Prime Test Kilit')
            ->assertJsonPath('link.brand', 'EMAKS PRIME')
            ->assertJsonPath('path', fn (string $path): bool => str_starts_with($path, '/mount-request/'));

        $token = $response->json('token');
        $this->assertIsString($token);
        $this->assertSame(64, strlen($token));
        $this->assertDatabaseHas('technical_service_qr_links', [
            'token_hash' => TechnicalServiceQrLink::hashToken($token),
            'serial_number' => 'QR-V2-ADMIN-001',
            'status' => TechnicalServiceQrLink::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseMissing('technical_service_qr_links', [
            'token_hash' => $token,
        ]);
    }

    public function test_invalid_token_shows_safe_invalid_page(): void
    {
        $this->get('/mount-request/not-a-real-token')
            ->assertStatus(404)
            ->assertInertia(fn (Assert $page) => $page
                ->component('public/mount-request-v2')
                ->where('viewState', 'invalid_link')
                ->where('message', 'Montaj talep linki geçersiz veya süresi dolmuş.'));
    }

    public function test_valid_token_opens_public_decision_screen_and_creates_or_reuses_session(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        [$link, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('public/mount-request-v2')
                ->where('viewState', 'form_ready')
                ->where('product.product_name', 'Emaks Prime Test Kilit')
                ->where('product.serial_number', $link->serial_number));

        $this->assertDatabaseCount('technical_service_mount_sessions', 1);

        $this->get('/mount-request/'.$token)->assertOk();

        $this->assertDatabaseCount('technical_service_mount_sessions', 1);
    }

    public function test_montaj_dahil_context_shows_form_ready_screen(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('viewState', 'form_ready')
                ->where('message', 'Montaj talep formunuz açılmaya hazır.')
                ->where('statusLabel', 'Montaj dahil'));
    }

    public function test_montaj_haric_unpaid_context_shows_payment_decision_screen(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_HARIC);
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('viewState', 'payment_required')
                ->where('message', 'Bu ürün için montaj ödemesi gereklidir.')
                ->where('statusLabel', 'Montaj ödemesi gerekli'));
    }

    public function test_montaj_haric_unpaid_screen_has_payment_and_multi_product_actions(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_HARIC);
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('actions.payment_label', 'Montaj ödemesi yap')
                ->where('actions.multi_product_label', 'Birden fazla ürün için montaj talebim var'));
    }

    public function test_multi_product_without_payment_updates_session_and_shows_multi_form_decision(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_HARIC);
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token)->assertOk();
        $this->post('/mount-request/'.$token.'/multi-product')->assertRedirect('/mount-request/'.$token);

        $session = TechnicalServiceMountSession::query()->firstOrFail();
        $this->assertSame(TechnicalServiceMountSession::ENTRY_MULTI_PRODUCT_WITHOUT_PAYMENT, $session->customer_entry_mode);
        $this->assertSame(TechnicalServiceMountSession::PAYMENT_SKIPPED_MULTI_PRODUCT, $session->mount_payment_status);

        $this->get('/mount-request/'.$token)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('viewState', 'multi_product_ready')
                ->where('message', 'Birden fazla ürün için montaj talebiniz alınmaya hazır. Operasyon ekibi sizinle iletişime geçecektir.'));
    }

    public function test_fake_payment_approve_updates_payment_and_session_then_returns_form_ready_screen(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_HARIC);
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token)->assertOk();
        $this->post('/mount-request/'.$token.'/payment')->assertRedirect('/mount-request/'.$token);

        $payment = TechnicalServiceMountPayment::query()->firstOrFail();
        $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $payment->status);

        $this->get("/mount-payment/fake/{$payment->id}/approve?token={$token}")
            ->assertRedirect('/mount-request/'.$token);

        $payment->refresh();
        $session = $payment->session->refresh();
        $this->assertSame(TechnicalServiceMountPayment::STATUS_PAID, $payment->status);
        $this->assertSame(TechnicalServiceMountSession::PAYMENT_PAID, $session->mount_payment_status);
        $this->assertSame(TechnicalServiceMountSession::ENTRY_PAID_SINGLE_PRODUCT, $session->customer_entry_mode);

        $this->get('/mount-request/'.$token)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('viewState', 'form_ready')
                ->where('statusLabel', 'Montaj ödemesi alındı'));
    }

    public function test_public_page_never_exposes_invoice_customer_or_internal_enum_labels(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_HARIC);
        [, $token] = $this->qrLink();

        $content = $this->get('/mount-request/'.$token)
            ->assertOk()
            ->getContent();

        foreach ([
            'cari_kodu',
            'bayi adı',
            'invoice_serials',
            'montaj_haric',
            'show_payment',
            'technical_service_invoice_serials',
        ] as $hiddenText) {
            $this->assertStringNotContainsString($hiddenText, $content);
        }
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
            'serial_number' => 'QR-V2-PUBLIC-001',
            'product_name' => 'Emaks Prime Test Kilit',
            'product_model' => 'DDL720',
            'brand' => 'EMAKS PRIME',
        ]);

        return [$link, $token];
    }
}
