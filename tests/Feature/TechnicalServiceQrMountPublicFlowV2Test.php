<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceQrLink;
use App\Models\User;
use App\Services\TechnicalService\SerialProductContextResolver;
use App\Services\TechnicalService\MikroInvoiceSerialsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TechnicalServiceQrMountPublicFlowV2Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_qr_link_screen_uses_serial_context_flow(): void
    {
        $source = file_get_contents(resource_path('js/pages/panel/admin/technical-service-qr-links.tsx'));
        $this->assertIsString($source);

        foreach ([
            'Seri No',
            'Seri bağlamını çöz',
            '/api/admin/technical-service/serial-context',
            'QR Link Oluştur',
            'Çözülen seri bağlamı',
            'Satılmış ürün',
            'Satılmamış ürün / ön baskı',
        ] as $expectedText) {
            $this->assertStringContainsString($expectedText, $source);
        }

        $this->assertStringNotContainsString('value={form.product_name}', $source);
        $this->assertStringNotContainsString('value={form.brand}', $source);
        $this->assertStringNotContainsString('value={form.link_type}', $source);
    }

    public function test_admin_can_resolve_serial_context(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        $user = User::factory()->create(['role_code' => 'admin']);

        $this->actingAs($user)
            ->getJson('/api/admin/technical-service/serial-context?serial_number=QR-V2-ADMIN-001')
            ->assertOk()
            ->assertJsonPath('context.serial_number', 'QR-V2-ADMIN-001')
            ->assertJsonPath('context.product_name', 'Emaks Prime Test Kilit')
            ->assertJsonPath('context.product_model', 'DDL720')
            ->assertJsonPath('context.brand', 'EMAKS PRIME')
            ->assertJsonPath('context.suggested_link_type', TechnicalServiceQrLink::TYPE_PRE_SALE_PRODUCT);
    }

    public function test_admin_context_shows_sold_product_when_serial_has_document_context(): void
    {
        $this->fakeContext(
            TechnicalServiceMountSession::SALE_MONTAJ_HARIC,
            TechnicalServiceQrLink::TYPE_SOLD_PRODUCT,
        );
        $user = User::factory()->create(['role_code' => 'admin']);

        $this->actingAs($user)
            ->getJson('/api/admin/technical-service/serial-context?serial_number=QR-V2-SOLD-001')
            ->assertOk()
            ->assertJsonPath('context.suggested_link_type', TechnicalServiceQrLink::TYPE_SOLD_PRODUCT);
    }

    public function test_context_must_resolve_before_link_creation(): void
    {
        $this->fakeContextWithoutProduct();
        $user = User::factory()->create(['role_code' => 'admin']);

        $this->actingAs($user)
            ->postJson('/api/admin/technical-service/qr-links', ['serial_number' => 'QR-V2-MISSING-PRODUCT'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['serial_number']);

        $this->assertDatabaseCount('technical_service_qr_links', 0);
    }

    public function test_admin_authorized_user_can_create_pre_sale_qr_link_from_resolved_serial_context(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        $user = User::factory()->create(['role_code' => 'admin']);

        $payload = [
            'serial_number' => 'QR-V2-ADMIN-001',
            'product_name' => 'Client Side Lie',
            'product_model' => 'Wrong Model',
            'brand' => 'Wrong Brand',
            'link_type' => TechnicalServiceQrLink::TYPE_MANUAL_TEST,
        ];

        $response = $this->actingAs($user)
            ->postJson('/api/admin/technical-service/qr-links', $payload)
            ->assertCreated()
            ->assertJsonPath('link.serial_number', 'QR-V2-ADMIN-001')
            ->assertJsonPath('link.product_name', 'Emaks Prime Test Kilit')
            ->assertJsonPath('link.brand', 'EMAKS PRIME')
            ->assertJsonPath('link.link_type', TechnicalServiceQrLink::TYPE_PRE_SALE_PRODUCT)
            ->assertJsonPath('path', fn (string $path): bool => str_starts_with($path, '/mount-request/'));

        $token = $response->json('token');
        $this->assertIsString($token);
        $this->assertSame(64, strlen($token));
        $this->assertDatabaseHas('technical_service_qr_links', [
            'token_hash' => TechnicalServiceQrLink::hashToken($token),
            'serial_number' => 'QR-V2-ADMIN-001',
            'product_name' => 'Emaks Prime Test Kilit',
            'product_model' => 'DDL720',
            'brand' => 'EMAKS PRIME',
            'link_type' => TechnicalServiceQrLink::TYPE_PRE_SALE_PRODUCT,
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

    public function test_mount_payment_schema_has_v2_session_column(): void
    {
        $this->assertTrue(Schema::hasColumn('technical_service_mount_payments', 'technical_service_mount_session_id'));
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

    public function test_in_stock_current_state_allows_form_with_operation_check_message(): void
    {
        $this->fakeContext(
            TechnicalServiceMountSession::SALE_CHECK_FAILED,
            TechnicalServiceQrLink::TYPE_PRE_SALE_PRODUCT,
            'in_stock_or_center',
        );
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('viewState', 'check_pending')
                ->where('message', 'Bu ürünün satış bilgisi henüz doğrulanamadı. Talebiniz operasyon ekibi tarafından kontrol edilecektir.'));
    }

    public function test_form_ready_public_page_does_not_show_mount_status_block(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token)
            ->assertOk()
            ->assertDontSee('Montaj durumu', false);
    }

    public function test_payment_required_public_page_shows_payment_message(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_HARIC);
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('public/mount-request-v2')
                ->where('viewState', 'payment_required')
                ->where('message', 'Bu ürün için montaj ödemesi gereklidir.')
                ->where('statusLabel', 'Montaj ödemesi gerekli'));
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
        ] as $hiddenText) {
            $this->assertStringNotContainsString($hiddenText, $content);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function fakeInvoiceSerialRows(array $rows): void
    {
        $this->app->instance(
            MikroInvoiceSerialsService::class,
            new class($rows) extends MikroInvoiceSerialsService {
                public function __construct(
                    private readonly array $rows,
                ) {
                }

                public function forSerial(string $serialNo): array
                {
                    $rows = $this->normalizeRows($this->rows, $serialNo);

                    return [
                        'rows' => $rows,
                        'all_invoice_serials' => $rows,
                        'selectable_customer_serials' => array_values(array_filter(
                            $rows,
                            fn (array $row): bool => (bool) ($row['customer_selectable'] ?? false),
                        )),
                        'returned_serials' => array_values(array_filter(
                            $rows,
                            fn (array $row): bool => (bool) ($row['is_returned'] ?? false),
                        )),
                        'meta' => [],
                        'request' => [],
                    ];
                }
            },
        );
    }

    public static function responsibilityCodeCaseProvider(): array
    {
        return [
            'perakende_satis' => ['PERAKENDE SATIŞ', true],
            'online' => ['ONLINE', true],
            'perakende' => ['PERAKENDE', true],
            'bayi_satis_turkish' => ['BAYİ SATIŞ', false],
            'bayi_satis' => ['BAYI SATIS', false],
            'proje' => ['PROJE', false],
            'null' => [null, false],
            'gr' => ['GR', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('responsibilityCodeCaseProvider')]
    public function test_multi_product_check_uses_responsibility_code_for_selectability(?string $responsibilityCode, bool $expectedSelectable): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        $this->fakeInvoiceSerialRows([
            [
                'Faturadaki Seri No' => 'SN-RESP',
                'sorumluluk_kodu' => $responsibilityCode,
                'stok_adi' => 'E10-AKILLI KAPI KILIDI-SIYAH',
            ],
        ]);
        [, $token] = $this->qrLink();

        $response = $this->postJson('/mount-request/'.$token.'/invoice-serials/check')->assertOk();

        $response
            ->assertJsonPath('ok', true)
            ->assertJsonPath('total_count', 1);

        if ($expectedSelectable) {
            $response
                ->assertJsonPath('has_selectable_serials', true)
                ->assertJsonPath('selectable_serials.0.serial_number', 'SN-RESP')
                ->assertJsonPath('selectable_serials.0.product_name', 'E10-AKILLI KAPI KILIDI-SIYAH')
                ->assertJsonPath('message', null);
        } else {
            $response->assertJsonPath('has_selectable_serials', false);
            $response->assertJsonCount(0, 'selectable_serials');
        }
    }

    public function test_multi_product_check_returns_perakende_satis_rows_as_selectable(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        $this->fakeInvoiceSerialRows([
            [
                'Faturadaki Seri No' => 'K111LBS60E230531C38424',
                'Sorumluluk Kodu' => 'PERAKENDE SATIŞ',
                'stok_adi' => 'E10-AKILLI KAPI KILIDI-SIYAH',
                'Bu Fatura Bu Seri İçin Son Satış mı' => 'Hayır',
            ],
        ]);
        [, $token] = $this->qrLink();

        $this->postJson('/mount-request/'.$token.'/invoice-serials/check')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('has_selectable_serials', true)
            ->assertJsonCount(1, 'selectable_serials')
            ->assertJsonPath('selectable_serials.0.serial_number', 'K111LBS60E230531C38424')
            ->assertJsonPath('blocked_count', 0)
            ->assertJsonPath('returned_count', 0)
            ->assertJsonPath('total_count', 1)
            ->assertJsonMissingPath('selectable_serials.0.hidden_reason')
            ->assertJsonMissingPath('selectable_serials.0.responsibility_code');
    }

    public function test_multi_product_check_excludes_bayi_satis_rows_from_public_response(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        $this->fakeInvoiceSerialRows([
            [
                'Faturadaki Seri No' => 'SN-BAYI',
                'Sorumluluk Kodu' => 'BAYİ SATIŞ',
                'stok_adi' => 'E10-AKILLI KAPI KILIDI-SIYAH',
            ],
        ]);
        [, $token] = $this->qrLink();

        $this->postJson('/mount-request/'.$token.'/invoice-serials/check')
            ->assertOk()
            ->assertJsonPath('has_selectable_serials', false)
            ->assertJsonCount(0, 'selectable_serials')
            ->assertJsonPath('blocked_count', 1);
    }

    public function test_multi_product_check_excludes_proje_rows_from_public_response(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        $this->fakeInvoiceSerialRows([
            [
                'Faturadaki Seri No' => 'SN-PROJE',
                'Sorumluluk Kodu' => 'PROJE',
                'stok_adi' => 'E10-AKILLI KAPI KILIDI-SIYAH',
            ],
        ]);
        [, $token] = $this->qrLink();

        $this->postJson('/mount-request/'.$token.'/invoice-serials/check')
            ->assertOk()
            ->assertJsonPath('has_selectable_serials', false)
            ->assertJsonCount(0, 'selectable_serials')
            ->assertJsonPath('blocked_count', 1);
    }

    public function test_multi_product_check_excludes_returned_rows_even_if_responsibility_is_allowed(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        $this->fakeInvoiceSerialRows([
            [
                'Faturadaki Seri No' => 'SN-RETURNED',
                'sorumluluk_kodu' => 'ONLINE',
                'stok_adi' => 'E10-AKILLI KAPI KILIDI-SIYAH',
                'Iade Notu' => 'RET-01',
            ],
        ]);
        [, $token] = $this->qrLink();

        $this->postJson('/mount-request/'.$token.'/invoice-serials/check')
            ->assertOk()
            ->assertJsonPath('has_selectable_serials', false)
            ->assertJsonCount(0, 'selectable_serials')
            ->assertJsonPath('returned_count', 1);
    }

    public function test_multi_product_check_with_selectable_rows_returns_modal_state_without_fallback_message(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        $this->fakeInvoiceSerialRows([
            [
                'Faturadaki Seri No' => 'SN-SELECTABLE',
                'sorumluluk_kodu' => 'ONLINE',
                'stok_adi' => 'E10-AKILLI KAPI KILIDI-SIYAH',
            ],
            [
                'Faturadaki Seri No' => 'SN-RETURNED',
                'sorumluluk_kodu' => 'ONLINE',
                'stok_adi' => 'E10-AKILLI KAPI KILIDI-SIYAH',
                'Iade Notu' => 'RET-01',
            ],
        ]);
        [, $token] = $this->qrLink();

        $this->postJson('/mount-request/'.$token.'/invoice-serials/check')
            ->assertOk()
            ->assertJsonPath('has_selectable_serials', true)
            ->assertJsonCount(1, 'selectable_serials')
            ->assertJsonPath('message', null);
    }

    public function test_multi_product_check_without_selectable_rows_returns_fallback_message(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        $this->fakeInvoiceSerialRows([
            [
                'Faturadaki Seri No' => 'SN-GR',
                'sorumluluk_kodu' => 'GR',
                'stok_adi' => 'E10-AKILLI KAPI KILIDI-SIYAH',
            ],
        ]);
        [, $token] = $this->qrLink();

        $this->postJson('/mount-request/'.$token.'/invoice-serials/check')
            ->assertOk()
            ->assertJsonPath('has_selectable_serials', false)
            ->assertJsonCount(0, 'selectable_serials')
            ->assertJsonPath('message', 'Ek ürün talebiniz operasyon ekibine iletilecek.');
    }

    public function test_frontend_opens_multi_product_popup_when_selectable_payload_exists(): void
    {
        $source = file_get_contents(resource_path('js/pages/public/mount-request-v2.tsx'));
        $this->assertIsString($source);

        $this->assertStringContainsString('items.length > 0 || Boolean(payload.has_selectable_serials)', $source);
        $this->assertStringContainsString('setMultiProductModalOpen(hasSelectableSerials)', $source);
        $this->assertStringContainsString('Bu adreste montaj istediğiniz diğer ürünleri seçin', $source);
        $this->assertStringContainsString('Ek ürün talebiniz operasyon ekibine iletilecek.', $source);
    }

    private function fakeContext(
        string $saleMountStatus,
        string $suggestedLinkType = TechnicalServiceQrLink::TYPE_PRE_SALE_PRODUCT,
        string $currentSerialState = 'sold_current',
    ): void
    {
        $this->app->instance(
            SerialProductContextResolver::class,
            new class($saleMountStatus, $suggestedLinkType, $currentSerialState) extends SerialProductContextResolver {
                public function __construct(
                    private readonly string $saleMountStatus,
                    private readonly string $suggestedLinkType,
                    private readonly string $currentSerialState,
                )
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
                        'suggested_link_type' => $this->suggestedLinkType,
                        'current_serial_state' => $this->currentSerialState,
                        'has_current_sale' => $this->currentSerialState === 'sold_current',
                        'latest_event_type' => null,
                        'latest_valid_sale_exists' => $this->currentSerialState === 'sold_current',
                        'stock_code' => 'STK-TEST',
                        'context_payload' => ['source' => 'test_fake_context'],
                    ];
                }
            },
        );
    }

    private function fakeContextWithoutProduct(): void
    {
        $this->app->instance(
            SerialProductContextResolver::class,
            new class extends SerialProductContextResolver {
                public function __construct()
                {
                }

                public function resolve(string $serialNumber, array $knownContext = []): array
                {
                    return [
                        'serial_number' => $serialNumber,
                        'product_name' => null,
                        'product_model' => null,
                        'brand' => null,
                        'activation_code' => null,
                        'sale_mount_status' => TechnicalServiceMountSession::SALE_CHECK_FAILED,
                        'invoice_customer_type' => 'unknown',
                        'suggested_link_type' => TechnicalServiceQrLink::TYPE_PRE_SALE_PRODUCT,
                        'current_serial_state' => 'unknown',
                        'has_current_sale' => false,
                        'latest_event_type' => null,
                        'latest_valid_sale_exists' => false,
                        'stock_code' => null,
                        'context_payload' => ['source' => 'test_missing_product'],
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
