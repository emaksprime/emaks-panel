<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceQrLink;
use App\Models\TechnicalServiceRequest;
use App\Models\User;
use App\Services\TechnicalService\MikroInvoiceSerialsService;
use App\Services\TechnicalService\SerialProductContextResolver;
use App\Services\TechnicalService\TechnicalServiceCodeGenerator;
use App\Services\TechnicalService\TechnicalServiceWorkflowService;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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

    public function test_ops_qr_product_create_reuses_duplicate_serial_without_new_link(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        $user = User::factory()->create(['role_code' => 'admin']);

        $this->actingAs($user)
            ->postJson('/api/technical-service/qr-products', [
                'serial_number' => 'QR-DUP-001',
                'product_name' => 'Manual Name',
            ])
            ->assertCreated()
            ->assertJsonPath('created', true)
            ->assertJsonPath('link.serial_number', 'QR-DUP-001');

        $this->actingAs($user)
            ->postJson('/api/technical-service/qr-products', [
                'serial_number' => 'qr-dup-001',
                'product_name' => 'Changed Name',
            ])
            ->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('link.product_name', 'Emaks Prime Test Kilit');

        $this->assertDatabaseCount('technical_service_qr_links', 1);
    }

    public function test_qr_create_allows_manual_product_when_serial_unresolved(): void
    {
        $this->fakeContextThrows();
        $user = User::factory()->create(['role_code' => 'admin']);

        $this->actingAs($user)
            ->postJson('/api/technical-service/qr-products', [
                'serial_number' => 'K193LGS61E221207B34767',
                'product_name' => 'Akıllı Kilit',
                'product_model' => 'DDL720',
                'brand' => 'Emaks Prime',
            ])
            ->assertCreated()
            ->assertJsonPath('created', true)
            ->assertJsonPath('context.product_name', 'Akıllı Kilit')
            ->assertJsonPath('context.product_model', 'DDL720')
            ->assertJsonPath('context.brand', 'Emaks Prime')
            ->assertJsonPath('context.resolution_status', 'manual_fallback')
            ->assertJsonPath('context.resolution_source', 'manual_fallback')
            ->assertJsonPath('context.ops_review_required', true)
            ->assertJsonPath('warning', 'Mikro seri kaydı bulunamadı; manuel ürün bilgisiyle QR oluşturuldu.');

        $this->assertDatabaseHas('technical_service_qr_links', [
            'serial_number' => 'K193LGS61E221207B34767',
            'product_name' => 'Akıllı Kilit',
            'product_model' => 'DDL720',
            'brand' => 'Emaks Prime',
        ]);
    }

    public function test_qr_create_allows_manual_product_when_resolver_has_no_product_name(): void
    {
        $this->fakeContextWithoutProduct();
        $user = User::factory()->create(['role_code' => 'admin']);

        $this->actingAs($user)
            ->postJson('/api/technical-service/qr-products', [
                'serial_number' => 'QR-PARTIAL-MANUAL-001',
                'product_name' => 'Manuel Kilit',
                'model' => 'DDL720',
                'brand' => 'Emaks Prime',
            ])
            ->assertCreated()
            ->assertJsonPath('context.product_name', 'Manuel Kilit')
            ->assertJsonPath('context.product_model', 'DDL720')
            ->assertJsonPath('context.resolution_status', 'partial_with_manual')
            ->assertJsonPath('context.resolution_source', 'partial_mikro_manual')
            ->assertJsonPath('context.warning', 'Mikro seri kaydı kısmi geldi; manuel ürün bilgisiyle QR oluşturuldu.');
    }

    public function test_qr_create_requires_product_name_when_serial_unresolved(): void
    {
        $this->fakeContextWithoutProduct();
        $user = User::factory()->create(['role_code' => 'admin']);

        $this->actingAs($user)
            ->postJson('/api/technical-service/qr-products', [
                'serial_number' => 'QR-MISSING-MANUAL-PRODUCT',
                'product_model' => 'DDL720',
                'brand' => 'Emaks Prime',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['serial_number'])
            ->assertJsonPath('errors.serial_number.0', 'Seri Mikro’da çözülemedi. QR oluşturmak için ürün adı girin.');

        $this->assertDatabaseCount('technical_service_qr_links', 0);
    }

    public function test_qr_create_normalizes_serial_for_duplicate_guard(): void
    {
        $this->fakeContextThrows();
        $user = User::factory()->create(['role_code' => 'admin']);

        $this->actingAs($user)
            ->postJson('/api/technical-service/qr-products', [
                'serial_number' => 'QRSPACE001',
                'product_name' => 'İlk Kilit',
            ])
            ->assertCreated()
            ->assertJsonPath('link.serial_number', 'QRSPACE001');

        $this->actingAs($user)
            ->postJson('/api/technical-service/qr-products', [
                'serial_number' => " qr space\u{200B}001 ",
                'product_name' => 'İkinci Kilit',
            ])
            ->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('link.product_name', 'İlk Kilit');

        $this->assertDatabaseCount('technical_service_qr_links', 1);
    }

    public function test_qr_bulk_preview_counts_manual_fallback_rows(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);

        $csv = implode("\n", [
            'seri_no,product_name,model,brand',
            'BULK-MANUAL-001,Manuel Kilit,DDL720,Emaks Prime',
            'BULK-MANUAL-002,,DDL720,Emaks Prime',
        ]);

        $this->actingAs($user)
            ->postJson('/api/technical-service/qr-products/bulk', [
                'csv_text' => $csv,
            ])
            ->assertOk()
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.created', 1)
            ->assertJsonPath('summary.failed', 1)
            ->assertJsonPath('summary.manual_fallback', 1)
            ->assertJsonPath('results.0.context.resolution_status', 'manual_fallback')
            ->assertJsonPath('results.1.status', 'failed');

        $this->assertDatabaseCount('technical_service_qr_links', 1);
    }

    public function test_serial_resolver_response_has_clear_status_and_warning(): void
    {
        $this->fakeContextWithoutProduct();
        $user = User::factory()->create(['role_code' => 'admin']);

        $this->actingAs($user)
            ->getJson('/api/technical-service/qr-products/serial-context?serial_number=QR-RESOLVE-WARNING')
            ->assertOk()
            ->assertJsonPath('context.serial_number', 'QR-RESOLVE-WARNING')
            ->assertJsonPath('context.product_name', null)
            ->assertJsonPath('context.resolution_status', 'requires_manual_product')
            ->assertJsonPath('context.requires_manual_product', true)
            ->assertJsonPath('context.warning', 'Seri Mikro’da çözülemedi. QR oluşturmak için ürün adı girin.');
    }

    public function test_ops_qr_product_bulk_csv_creates_and_skips_duplicates(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);

        $csv = implode("\n", [
            'seri_no,product_name,model,brand',
            'BULK-001,Test Kilit,F3,Emaks Prime',
            'BULK-001,Test Kilit,F3,Emaks Prime',
            'BULK-002,Test Panel,P2,Emaks Prime',
        ]);

        $this->actingAs($user)
            ->postJson('/api/technical-service/qr-products/bulk', [
                'csv_text' => $csv,
            ])
            ->assertOk()
            ->assertJsonPath('summary.total', 3)
            ->assertJsonPath('summary.created', 2)
            ->assertJsonPath('summary.skipped', 1)
            ->assertJsonPath('summary.failed', 0);

        $this->assertDatabaseHas('technical_service_qr_links', [
            'serial_number' => 'BULK-001',
            'product_name' => 'Test Kilit',
        ]);
        $this->assertDatabaseHas('technical_service_qr_links', [
            'serial_number' => 'BULK-002',
            'product_name' => 'Test Panel',
        ]);
        $this->assertDatabaseCount('technical_service_qr_links', 2);
    }

    public function test_qr_products_index_is_paginated_by_default(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);

        foreach (range(1, 60) as $index) {
            TechnicalServiceQrLink::createPreSaleProductLink([
                'serial_number' => sprintf('QR-PAGE-%03d', $index),
                'product_name' => 'Sayfalı Test Kilit',
                'product_model' => 'PAGE-'.$index,
                'brand' => 'Emaks Prime',
            ]);
        }

        $this->actingAs($user)
            ->getJson('/api/technical-service/qr-products')
            ->assertOk()
            ->assertJsonCount(25, 'data')
            ->assertJsonCount(25, 'links')
            ->assertJsonPath('meta.total', 60)
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('meta.last_page', 3);
    }

    public function test_qr_products_search_is_server_side_and_paginated(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);

        foreach (range(1, 30) as $index) {
            TechnicalServiceQrLink::createPreSaleProductLink([
                'serial_number' => sprintf('QR-SEARCH-%03d', $index),
                'product_name' => $index === 29 ? 'Aranan Akıllı Kilit' : 'Başka Ürün',
                'product_model' => 'SEARCH-'.$index,
                'brand' => 'Emaks Prime',
            ]);
        }

        $this->actingAs($user)
            ->getJson('/api/technical-service/qr-products?search=Aranan&per_page=25')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.serial_number', 'QR-SEARCH-029');
    }

    public function test_qr_bulk_upload_large_batch_returns_bounded_summary(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);
        $rows = ['seri_no,product_name,model,brand'];

        foreach (range(1, 30) as $index) {
            $rows[] = ',Eksik Seri,MODEL,Emaks Prime';
        }

        $this->actingAs($user)
            ->postJson('/api/technical-service/qr-products/bulk', [
                'csv_text' => implode("\n", $rows),
            ])
            ->assertOk()
            ->assertJsonPath('summary.total', 30)
            ->assertJsonPath('summary.failed', 30)
            ->assertJsonCount(20, 'results')
            ->assertJsonCount(20, 'errors')
            ->assertJsonPath('meta.results_truncated', true)
            ->assertJsonPath('meta.errors_truncated', true);
    }

    public function test_ops_qr_product_svg_endpoint_returns_qr_svg(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);
        ['link' => $link] = TechnicalServiceQrLink::createPreSaleProductLink([
            'serial_number' => 'QR-SVG-001',
            'product_name' => 'Emaks Prime Test Kilit',
            'product_model' => 'DDL720',
            'brand' => 'EMAKS PRIME',
        ]);

        $this->actingAs($user)
            ->get('/api/technical-service/qr-products/'.$link->id.'/svg')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml; charset=UTF-8')
            ->assertSee('<svg', false);
    }

    public function test_partner_user_cannot_access_ops_qr_product_management_api(): void
    {
        $portalUser = User::factory()->create(['role_code' => 'b2b_locksmith']);

        $this->actingAs($portalUser)
            ->getJson('/api/technical-service/qr-products')
            ->assertForbidden();
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

    public function test_public_mount_request_updates_qr_scan_metrics(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        [$link, $token] = $this->qrLink();

        $this->assertSame(0, $link->scan_count);

        $this->get('/mount-request/'.$token)->assertOk();

        $link->refresh();
        $this->assertSame(1, $link->scan_count);
        $this->assertNotNull($link->last_scanned_at);
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

    public function test_in_stock_current_state_requires_payment_before_form(): void
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
                ->where('viewState', 'payment_required')
                ->where('message', 'Bu ürün için montaj ödemesi gereklidir.')
                ->where('statusLabel', 'Montaj ödemesi gerekli'));
    }

    public function test_unsold_serial_redirects_to_payment_decision(): void
    {
        $this->fakeContext(
            TechnicalServiceMountSession::SALE_NOT_FOUND,
            TechnicalServiceQrLink::TYPE_PRE_SALE_PRODUCT,
            'unknown',
        );
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('viewState', 'payment_required')
                ->where('message', 'Bu ürün için montaj ödemesi gereklidir.')
                ->where('statusLabel', 'Montaj ödemesi gerekli'));
    }

    public function test_paid_unsold_serial_opens_mount_form(): void
    {
        config([
            'payments.provider' => 'fake',
            'payments.enable_fake_approve' => true,
        ]);
        $this->fakeContext(
            TechnicalServiceMountSession::SALE_NOT_FOUND,
            TechnicalServiceQrLink::TYPE_PRE_SALE_PRODUCT,
            'unknown',
        );
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('viewState', 'payment_required'));

        $this->post('/mount-request/'.$token.'/payment')->assertRedirect('/mount-request/'.$token);
        $payment = TechnicalServiceMountPayment::query()->firstOrFail();

        $this->get("/mount-payment/fake/{$payment->id}/approve?token={$token}")
            ->assertRedirect('/mount-request/'.$token);

        $this->get('/mount-request/'.$token)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('viewState', 'form_ready')
                ->where('statusLabel', 'Montaj ödemesi alındı'));
    }

    public function test_mrn_generator_uses_date_initials_and_daily_sequence(): void
    {
        Carbon::setTestNow('2026-06-03 10:00:00');

        try {
            $generator = app(TechnicalServiceCodeGenerator::class);

            $this->assertSame('MRN-2606MP030001', $generator->nextMrn('Mehmet Burhan Pekguzel'));

            $this->createRequestWithMrn('MRN-2606MP030001');

            $this->assertSame('MRN-2606AC030002', $generator->nextMrn('Ayse Celik'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_mrn_generator_normalizes_turkish_characters(): void
    {
        Carbon::setTestNow('2026-06-03 10:00:00');

        try {
            $this->assertSame(
                'MRN-2606IS030001',
                app(TechnicalServiceCodeGenerator::class)->nextMrn("\u{0130}lker \u{015E}ahin")
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_mrn_generator_handles_single_word_customer(): void
    {
        Carbon::setTestNow('2026-06-03 10:00:00');

        try {
            $this->assertSame('MRN-2606MX030001', app(TechnicalServiceCodeGenerator::class)->nextMrn('Mehmet'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_mrn_generator_handles_single_word_customer_with_x(): void
    {
        Carbon::setTestNow('2026-06-03 10:00:00');

        try {
            $this->assertSame('MRN-2606BX030001', app(TechnicalServiceCodeGenerator::class)->nextMrn('Burhan'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_mrn_generator_avoids_collision(): void
    {
        Carbon::setTestNow('2026-06-03 10:00:00');

        try {
            $this->createRequestWithMrn('MRN-2606MP030001');
            $this->createRequestWithMrn('MRN-2606MP030002');

            $this->assertSame('MRN-2606MP030003', app(TechnicalServiceCodeGenerator::class)->nextMrn('Mehmet Pekguzel'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_existing_mrn_values_are_not_migrated(): void
    {
        $legacy = $this->createRequestWithMrn('MRN-LEGACY-2024-001');

        Carbon::setTestNow('2026-06-03 10:00:00');

        try {
            $this->assertSame('MRN-2606MP030001', app(TechnicalServiceCodeGenerator::class)->nextMrn('Mehmet Pekguzel'));
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame('MRN-LEGACY-2024-001', $legacy->fresh()->mrn);
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
        config([
            'payments.provider' => 'fake',
            'payments.enable_fake_approve' => true,
        ]);

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

    public function test_paid_mount_payment_shows_continue_to_form_button(): void
    {
        config([
            'payments.provider' => 'fake',
            'payments.enable_fake_approve' => true,
        ]);

        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_HARIC);
        [, $token] = $this->qrLink();

        $this->post('/mount-request/'.$token.'/payment')->assertRedirect('/mount-request/'.$token);
        $payment = TechnicalServiceMountPayment::query()->firstOrFail();

        $this->post('/mount-payment/'.$payment->provider_reference.'/fake-approve')
            ->assertRedirect('/mount-request/'.$token);

        $this->get('/mount-payment/'.$payment->provider_reference)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('public/mount-payment')
                ->where('payment.status', TechnicalServiceMountPayment::STATUS_PAID)
                ->where('payment.mount_form_url', route('mount-request.show', ['token' => $token]))
                ->where('requestSummary.serial_number', 'QR-V2-PUBLIC-001')
                ->where('requestSummary.product_name', 'Emaks Prime Test Kilit'));
    }

    public function test_paid_mount_payment_does_not_redirect_if_request_already_submitted(): void
    {
        config([
            'payments.provider' => 'fake',
            'payments.enable_fake_approve' => true,
        ]);
        Storage::fake('public');
        Carbon::setTestNow('2026-06-03 10:00:00');

        try {
            $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_HARIC);
            [, $token] = $this->qrLink();

            $this->post('/mount-request/'.$token.'/payment')->assertRedirect('/mount-request/'.$token);
            $payment = TechnicalServiceMountPayment::query()->firstOrFail();

            $this->post('/mount-payment/'.$payment->provider_reference.'/fake-approve')
                ->assertRedirect('/mount-request/'.$token);

            $this->submitMountRequest($token)->assertOk();

            $this->get('/mount-payment/'.$payment->provider_reference)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('public/mount-payment')
                    ->where('payment.status', TechnicalServiceMountPayment::STATUS_PAID)
                    ->where('payment.mount_form_url', null)
                    ->where('requestSummary.mrn', 'MRN-2606MP030001'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_paid_mount_amount_is_used_as_customer_collection(): void
    {
        config([
            'payments.provider' => 'fake',
            'payments.enable_fake_approve' => true,
        ]);
        Storage::fake('public');
        Carbon::setTestNow('2026-06-03 10:00:00');

        try {
            $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_HARIC);
            [, $token] = $this->qrLink();

            $this->post('/mount-request/'.$token.'/payment')->assertRedirect('/mount-request/'.$token);
            $payment = TechnicalServiceMountPayment::query()->firstOrFail();

            $this->post('/mount-payment/'.$payment->provider_reference.'/fake-approve')
                ->assertRedirect('/mount-request/'.$token);

            $this->submitMountRequest($token)->assertOk();

            $request = TechnicalServiceRequest::query()->where('mrn', 'MRN-2606MP030001')->firstOrFail();
            $payment->refresh();
            $this->assertSame($request->id, $payment->technical_service_request_id);
            $this->assertSame(3500.0, (float) $payment->amount);

            $serialized = app(TechnicalServiceWorkflowService::class)
                ->serialize($request->fresh(['requestSerials', 'uploads']), true);

            $this->assertTrue($serialized['sale_and_payment']['mount_payment_received']);
            $this->assertSame(3500.0, $serialized['sale_and_payment']['payment_status']['amount']);
            $this->assertSame(3500.0, $serialized['sale_and_payment']['paid_amount']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_public_payment_page_fake_approve_requires_explicit_non_production_gate(): void
    {
        config([
            'payments.provider' => 'fake',
            'payments.enable_fake_approve' => true,
        ]);

        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_HARIC);
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token)->assertOk();
        $this->post('/mount-request/'.$token.'/payment')->assertRedirect('/mount-request/'.$token);

        $payment = TechnicalServiceMountPayment::query()->firstOrFail();

        $this->get('/mount-payment/'.$payment->provider_reference)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('public/mount-payment')
                ->where('payment.fake_approve_url', route('mount-payment.fake-token.approve', ['token' => $payment->provider_reference])));

        $this->post('/mount-payment/'.$payment->provider_reference.'/fake-approve')
            ->assertRedirect('/mount-request/'.$token);

        $payment->refresh();

        $this->assertSame(TechnicalServiceMountPayment::STATUS_PAID, $payment->status);
        $this->assertSame(TechnicalServiceMountSession::PAYMENT_PAID, $payment->session->refresh()->mount_payment_status);
    }

    public function test_fake_provider_can_create_payment_without_exposing_fake_approve(): void
    {
        config([
            'payments.provider' => 'fake',
            'payments.enable_fake_approve' => false,
        ]);

        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_HARIC);
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token)->assertOk();
        $this->post('/mount-request/'.$token.'/payment')->assertRedirect('/mount-request/'.$token);

        $payment = TechnicalServiceMountPayment::query()->firstOrFail();

        $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $payment->status);
        $this->assertNotEmpty($payment->provider_reference);

        $this->get('/mount-payment/'.$payment->provider_reference)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('public/mount-payment')
                ->where('payment.fake_approve_url', null));
    }

    public function test_production_environment_never_exposes_or_allows_fake_approve(): void
    {
        config([
            'payments.provider' => 'fake',
            'payments.enable_fake_approve' => true,
        ]);
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_HARIC);
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token)->assertOk();
        $this->withSession(['_token' => 'test-csrf'])
            ->post('/mount-request/'.$token.'/payment', ['_token' => 'test-csrf'])
            ->assertNotFound();

        $session = TechnicalServiceMountSession::query()->firstOrFail();
        $payment = TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'provider' => 'fake',
            'provider_reference' => 'fake-production-disabled',
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'amount' => 3500,
            'currency' => 'TRY',
            'payment_url' => '/mount-payment/fake-production-disabled',
        ]);

        $this->get('/mount-payment/'.$payment->provider_reference)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('public/mount-payment')
                ->where('payment.fake_approve_url', null));

        $this->get("/mount-payment/fake/{$payment->id}/approve?token={$token}")
            ->assertNotFound();
        $this->withSession(['_token' => 'test-csrf'])
            ->post('/mount-payment/'.$payment->provider_reference.'/fake-approve', ['_token' => 'test-csrf'])
            ->assertNotFound();

        $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $payment->refresh()->status);
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

    public function test_public_multi_serial_list_is_bounded_and_searchable(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        $rows = [];

        foreach (range(1, 30) as $index) {
            $rows[] = [
                'Faturadaki Seri No' => sprintf('MULTI-%04d', $index),
                'Sorumluluk Kodu' => 'PERAKENDE SATIŞ',
                'stok_adi' => 'Çoklu Ürün '.$index,
                'Bu Fatura Bu Seri İçin Son Satış mı' => 'Hayır',
            ];
        }

        $this->fakeInvoiceSerialRows($rows);
        [, $token] = $this->qrLink();

        $this->postJson('/mount-request/'.$token.'/invoice-serials/check')
            ->assertOk()
            ->assertJsonPath('has_selectable_serials', true)
            ->assertJsonCount(20, 'selectable_serials')
            ->assertJsonPath('selectable_total', 30)
            ->assertJsonPath('meta.total', 30)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.last_page', 2);

        $this->postJson('/mount-request/'.$token.'/invoice-serials/check?search=MULTI-0029')
            ->assertOk()
            ->assertJsonCount(1, 'selectable_serials')
            ->assertJsonPath('selectable_serials.0.serial_number', 'MULTI-0029')
            ->assertJsonPath('meta.total', 1);
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

    public function test_fixture_mode_public_popup_only_returns_customer_selectable_invoice_serials(): void
    {
        config(['services.technical_service.invoice_serials_mode' => 'fixture']);
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        ['token' => $token] = TechnicalServiceQrLink::createPreSaleProductLink([
            'serial_number' => 'TEST-SERIAL-001',
            'product_name' => 'Fixture Ana Kilit',
            'product_model' => 'FIXTURE',
            'brand' => 'EMAKS PRIME',
        ]);

        $this->postJson('/mount-request/'.$token.'/invoice-serials/check')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('has_selectable_serials', true)
            ->assertJsonCount(1, 'selectable_serials')
            ->assertJsonPath('selectable_serials.0.serial_number', 'TEST-SERIAL-002')
            ->assertJsonPath('blocked_count', 3)
            ->assertJsonPath('returned_count', 1)
            ->assertJsonPath('operation_only_count', 4)
            ->assertJsonPath('total_count', 5)
            ->assertJsonMissingPath('selectable_serials.0.hidden_reason')
            ->assertJsonMissingPath('selectable_serials.0.responsibility_code');
    }

    public function test_fixture_mode_refreshes_cached_empty_context_for_local_serial_alias(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        ['token' => $token] = TechnicalServiceQrLink::createPreSaleProductLink([
            'serial_number' => 'W720FWS03E241227A00997',
            'product_name' => 'Local Alias Kilit',
            'product_model' => 'FIXTURE',
            'brand' => 'EMAKS PRIME',
        ]);

        config(['services.technical_service.invoice_serials_mode' => 'disabled']);
        $this->postJson('/mount-request/'.$token.'/invoice-serials/check')
            ->assertOk()
            ->assertJsonPath('has_selectable_serials', false)
            ->assertJsonCount(0, 'selectable_serials');

        config(['services.technical_service.invoice_serials_mode' => 'fixture']);
        $this->postJson('/mount-request/'.$token.'/invoice-serials/check')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('has_selectable_serials', true)
            ->assertJsonCount(2, 'selectable_serials')
            ->assertJsonPath('selectable_serials.0.serial_number', 'TEST-SERIAL-001')
            ->assertJsonPath('selectable_serials.1.serial_number', 'TEST-SERIAL-002')
            ->assertJsonPath('operation_only_count', 4)
            ->assertJsonPath('returned_count', 1)
            ->assertJsonPath('total_count', 6);
    }

    public function test_frontend_opens_multi_product_popup_when_selectable_payload_exists(): void
    {
        $source = file_get_contents(resource_path('js/pages/public/mount-request-v2.tsx'));
        $this->assertIsString($source);

        $this->assertStringContainsString('items.length > 0 || Boolean(payload.has_selectable_serials)', $source);
        $this->assertStringContainsString('setMultiProductModalOpen(hasSelectableSerials)', $source);
        $this->assertStringContainsString('Diğer serileri kontrol et', $source);
        $this->assertStringContainsString('Bu adreste montajını istediğiniz diğer ürünleri seçin', $source);
        $this->assertStringContainsString('Bu faturada birden fazla ürün görünüyor. Montaj istediğiniz ürünleri seçebilirsiniz.', $source);
        $this->assertStringContainsString('Operasyon ekibi diğer ürünleri ayrıca kontrol edebilir.', $source);
        $this->assertStringContainsString('Ek ürün talebiniz operasyon ekibine iletilecek.', $source);
    }

    private function createRequestWithMrn(string $mrn): TechnicalServiceRequest
    {
        return TechnicalServiceRequest::query()->create([
            'mrn' => $mrn,
            'customer_name' => 'Test Customer',
            'customer_phone' => '+905551112233',
            'customer_city' => 'Istanbul',
            'customer_district' => 'Kadikoy',
            'service_address' => 'Test adres',
            'product_name' => 'Test Urun',
            'product_model' => 'TST',
            'serial_number' => 'SN-'.$mrn,
            'service_type' => 'Montaj',
            'status' => TechnicalServiceRequest::STATUS_NEW,
            'workflow_status' => TechnicalServiceRequest::WORKFLOW_NEW_REQUEST,
            'priority' => TechnicalServiceRequest::PRIORITY_MEDIUM,
            'risk_level' => TechnicalServiceRequest::RISK_MEDIUM,
        ]);
    }

    private function submitMountRequest(string $token)
    {
        return $this->post('/mount-request/'.$token.'/submit', [
            'first_name' => 'Mehmet',
            'last_name' => 'Pekguzel',
            'phone' => '5551112233',
            'city' => 'Istanbul',
            'district' => 'Kadikoy',
            'address' => 'Moda Caddesi No 10',
            'installation_consent' => '1',
            'kvkk_consent' => '1',
            'door_front_photo' => $this->fakeUploadImage('front.png'),
            'door_side_photo' => $this->fakeUploadImage('side.png'),
            'door_back_photo' => $this->fakeUploadImage('back.png'),
        ]);
    }

    private function fakeUploadImage(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ts-upload-');
        $this->assertIsString($path);

        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
            true
        ));

        return new UploadedFile($path, $name, 'image/png', null, true);
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

    private function fakeContextThrows(): void
    {
        $this->app->instance(
            SerialProductContextResolver::class,
            new class extends SerialProductContextResolver {
                public function __construct()
                {
                }

                public function resolve(string $serialNumber, array $knownContext = []): array
                {
                    throw new \RuntimeException('Fixture Mikro resolver failed.');
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
