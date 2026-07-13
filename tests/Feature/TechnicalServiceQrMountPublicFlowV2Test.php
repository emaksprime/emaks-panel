<?php

namespace Tests\Feature;

use App\Models\DataSource;
use App\Models\PageConfig;
use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceQrLink;
use App\Models\TechnicalServiceRequest;
use App\Models\User;
use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use App\Services\N8nPanelDataGateway;
use App\Services\Payments\PaymentProviderGatewayClient;
use App\Services\Payments\PaymentProviderGatewayRequest;
use App\Services\Payments\PaymentProviderGatewayResponse;
use App\Services\Payments\TechnicalServicePaymentProviderCredentialService;
use App\Services\TechnicalService\MikroInvoiceSerialsService;
use App\Services\TechnicalService\SerialProductContextResolver;
use App\Services\TechnicalService\TechnicalServiceCodeGenerator;
use App\Services\TechnicalService\TechnicalServiceWorkflowService;
use App\Support\PartnerPortalPublicUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
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

    public function test_ops_qr_product_svg_endpoint_can_encode_public_base_url_override(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);
        ['link' => $link] = TechnicalServiceQrLink::createPreSaleProductLink([
            'serial_number' => 'QR-SVG-BASE-001',
            'product_name' => 'Emaks Prime Test Kilit',
            'product_model' => 'DDL720',
            'brand' => 'EMAKS PRIME',
        ]);

        $defaultSvg = $this->actingAs($user)
            ->get('/api/technical-service/qr-products/'.$link->id.'/svg')
            ->assertOk()
            ->getContent();

        $overrideSvg = $this->actingAs($user)
            ->get('/api/technical-service/qr-products/'.$link->id.'/svg?public_base_url='.rawurlencode('http://panel.test:8000'))
            ->assertOk()
            ->getContent();

        $this->assertNotSame($defaultSvg, $overrideSvg);
    }

    public function test_qr_products_page_allows_simple_public_form_base_url_override(): void
    {
        $source = file_get_contents(resource_path('js/pages/panel/technical-service-qr-products.tsx'));
        $this->assertIsString($source);

        foreach ([
            'Public form base URL',
            'Bu QR şu linki açacak',
            'Telefon bu URL’ye erişemez',
            'technical-service-qr-public-form-base-url',
            'public_base_url',
            'selectedPublicUrl',
            'qrSvgUrlForLink',
            'Telefon konumu test edilecekse HTTPS test URL’si girin',
        ] as $expectedText) {
            $this->assertStringContainsString($expectedText, $source);
        }

        $forbiddenLanExample = implode('.', ['10', '0', '28', '64']);

        $this->assertStringNotContainsString($forbiddenLanExample, $source);
    }

    public function test_public_qr_accepts_https_base_url_for_location_testing(): void
    {
        $source = file_get_contents(resource_path('js/pages/panel/technical-service-qr-products.tsx'));
        $this->assertIsString($source);

        $this->assertStringContainsString("['http:', 'https:']", $source);
        $this->assertStringContainsString('Telefon konumu test edilecekse HTTPS test URL’si girin', $source);
        $this->assertStringContainsString('QR önizleme ve QR görseli bu HTTPS base ile üretilir.', $source);
    }

    public function test_qr_encoded_url_matches_https_public_base(): void
    {
        $source = file_get_contents(resource_path('js/pages/panel/technical-service-qr-products.tsx'));
        $this->assertIsString($source);

        $this->assertStringContainsString('const params = new URLSearchParams({ public_base_url: normalizedBase })', $source);
        $this->assertStringContainsString('return `${link.qr_svg_url}?${params.toString()}`', $source);
        $this->assertStringContainsString('{selectedPublicUrl}', $source);
    }

    public function test_live_qr_public_url_uses_public_qr_base_url(): void
    {
        config([
            'services.public_urls.qr_base_url' => 'https://qr.example.test',
            'services.public_urls.app_url' => 'https://app.example.test',
            'app.url' => 'https://app-url.example.test',
        ]);
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        $user = User::factory()->create(['role_code' => 'admin']);

        $response = $this->actingAs($user)
            ->postJson('/api/technical-service/qr-products', [
                'serial_number' => 'QR-LIVE-BASE-001',
                'product_name' => 'Emaks Prime Test Kilit',
            ])
            ->assertCreated();

        $this->assertSame('https://qr.example.test'.$response->json('path'), $response->json('public_url'));
    }

    public function test_live_public_urls_use_dashboard_domain_when_configured(): void
    {
        config([
            'services.public_urls.app_url' => 'https://dashboard.emaksprime.com.tr',
            'services.public_urls.qr_base_url' => 'https://dashboard.emaksprime.com.tr',
            'services.public_urls.payment_base_url' => 'https://dashboard.emaksprime.com.tr',
            'app.url' => 'https://fallback.example.test',
        ]);

        $this->assertSame(
            'https://dashboard.emaksprime.com.tr/mount-request/live-token',
            PartnerPortalPublicUrl::qrUrl('/mount-request/live-token'),
        );
        $this->assertSame(
            'https://dashboard.emaksprime.com.tr/mount-payment/live-token',
            PartnerPortalPublicUrl::paymentUrl('/mount-payment/live-token'),
        );
    }

    public function test_production_qr_url_rejects_localhost(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['services.public_urls.qr_base_url' => 'http://127.0.0.1:8000']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Production QR public URL localhost veya özel ağ IP');

        PartnerPortalPublicUrl::qrUrl('/mount-request/local-token');
    }

    public function test_production_payment_url_rejects_localhost(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['services.public_urls.payment_base_url' => 'http://localhost:8000']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Production payment public URL localhost veya özel ağ IP');

        PartnerPortalPublicUrl::paymentUrl('/mount-payment/local-token');
    }

    public function test_production_public_url_rejects_private_lan_ip(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['services.public_urls.qr_base_url' => 'http://192.168.1.20:8000']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Production QR public URL localhost veya özel ağ IP');

        PartnerPortalPublicUrl::qrUrl('/mount-request/private-token');
    }

    public function test_local_public_form_base_override_allowed_in_local_only(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');

        $this->assertSame(
            'http://10.0.0.50:8000/mount-request/local-token',
            PartnerPortalPublicUrl::qrUrl('/mount-request/local-token', 'http://10.0.0.50:8000'),
        );
    }

    public function test_public_mount_request_get_does_not_require_auth(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        [$link, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('public/mount-request-v2')
                ->where('viewState', 'checking')
                ->has('csrfToken')
                ->where('actions.check_url', '/mount-request/'.$token.'/check')
                ->where('actions.form_url', '/mount-request/'.$token.'/form')
                ->where('product.serial_number', $link->serial_number));
    }

    public function test_public_mount_request_action_urls_are_relative_for_lan_hosts(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token.'/form')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('actions.create_payment_url', '/mount-request/'.$token.'/payment')
                ->where('actions.multi_product_url', '/mount-request/'.$token.'/multi-product')
                ->where('actions.multi_product_lookup_url', '/mount-request/'.$token.'/invoice-serials/check')
                ->where('actions.submit_url', '/mount-request/'.$token.'/submit'));
    }

    public function test_admin_technical_service_api_requires_auth(): void
    {
        $this->getJson('/api/technical-service/requests')
            ->assertUnauthorized();
    }

    public function test_vite_dev_server_origin_can_be_configured_for_lan_public_form_assets(): void
    {
        $source = file_get_contents(base_path('vite.config.ts'));
        $this->assertIsString($source);

        foreach ([
            'VITE_DEV_SERVER_ORIGIN',
            'server:',
            'origin: devServerOrigin',
            'localDevCorsOrigins',
            'cors:',
            'hmr:',
            'clientPort',
        ] as $expectedText) {
            $this->assertStringContainsString($expectedText, $source);
        }

        $this->assertStringNotContainsString('10.0.28.64', $source);
    }

    public function test_env_example_documents_lan_vite_origin_without_hardcoded_ip(): void
    {
        $source = file_get_contents(base_path('.env.example'));
        $this->assertIsString($source);

        $this->assertStringContainsString('VITE_DEV_SERVER_ORIGIN=', $source);
        $this->assertStringContainsString('http://YOUR-LAN-IP:5173', $source);
        $this->assertStringNotContainsString('10.0.28.64', $source);
    }

    public function test_public_mount_form_reads_csrf_from_stable_prop(): void
    {
        $source = file_get_contents(resource_path('js/pages/public/mount-request-v2.tsx'));
        $this->assertIsString($source);

        $this->assertStringContainsString('csrfToken?: string;', $source);
        $this->assertStringContainsString("csrfToken = '',", $source);
        $this->assertStringContainsString('const csrfValue = csrfToken;', $source);
        $this->assertStringNotContainsString('const csrfValue = csrfToken();', $source);
        $this->assertStringNotContainsString('document.querySelector<HTMLMetaElement>(\'meta[name="csrf-token"]\')', $source);
    }

    public function test_public_pages_use_customer_layout_without_panel_navigation(): void
    {
        $appSource = file_get_contents(resource_path('js/app.tsx'));
        $layoutSource = file_get_contents(resource_path('js/layouts/public-layout.tsx'));
        $formSource = file_get_contents(resource_path('js/pages/public/mount-request-v2.tsx'));

        $this->assertIsString($appSource);
        $this->assertIsString($layoutSource);
        $this->assertIsString($formSource);

        $this->assertStringContainsString("case name.startsWith('public/')", $appSource);
        $this->assertStringContainsString('return PublicLayout;', $appSource);
        $this->assertStringNotContainsString('AppLayout', $layoutSource);
        $this->assertStringNotContainsString('Operasyon Paneli', $formSource);
        $this->assertStringContainsString('Emaks Prime Teknik Servis', $formSource);
        $this->assertStringContainsString('Montaj formuna yönlendiriliyorsunuz', $formSource);
        $this->assertStringContainsString('Cihaz bilgileriniz kontrol ediliyor', $formSource);
    }

    public function test_qr_redirect_operational_status_copy_is_present_on_public_customer_page(): void
    {
        $source = file_get_contents(resource_path('js/pages/public/mount-request-v2.tsx'));
        $this->assertIsString($source);

        $this->assertStringContainsString('Montaj formuna yönlendiriliyorsunuz', $source);
        $this->assertStringContainsString('Cihaz bilgileriniz kontrol ediliyor', $source);
        $this->assertStringContainsString('actions?.check_url', $source);
        $this->assertStringContainsString('router.visit(nextUrl', $source);
    }

    public function test_location_modal_handles_insecure_context(): void
    {
        $source = file_get_contents(resource_path('js/pages/public/mount-request-v2.tsx'));
        $this->assertIsString($source);

        $this->assertStringContainsString('function geolocationUnavailableMessage()', $source);
        $this->assertStringContainsString('!window.isSecureContext', $source);
        $this->assertStringContainsString('Telefon konumunu kullanmak için HTTPS bağlantı gerekir.', $source);
        $this->assertStringContainsString('HTTPS test bağlantısı kullanabilirsiniz.', $source);
        $this->assertStringContainsString('Public form base URL alanına HTTPS test URL’si girildiğinde QR bu URL ile üretilebilir.', $source);
    }

    public function test_public_location_secure_context_allows_geolocation_attempt(): void
    {
        $source = file_get_contents(resource_path('js/pages/public/mount-request-v2.tsx'));
        $this->assertIsString($source);

        $this->assertStringContainsString('const localDesktopHost', $source);
        $this->assertStringContainsString('return null;', $source);
        $this->assertStringContainsString('navigator.geolocation.getCurrentPosition', $source);
    }

    public function test_public_form_submit_allows_manual_address_without_geolocation(): void
    {
        $source = file_get_contents(resource_path('js/pages/public/mount-request-v2.tsx'));
        $this->assertIsString($source);

        $this->assertStringContainsString('Harita yerine elle adres girişi kullanılacak.', $source);
        $this->assertStringContainsString('İl, ilçe ve açık adres alanlarını doldurarak devam edebilirsiniz.', $source);
        $this->assertStringContainsString('address:', $source);
    }

    public function test_location_modal_handles_geolocation_timeout(): void
    {
        $source = file_get_contents(resource_path('js/pages/public/mount-request-v2.tsx'));
        $this->assertIsString($source);

        $this->assertStringContainsString('error.code === error.TIMEOUT', $source);
        $this->assertStringContainsString('Konum alınamadı. Adresi elle girebilirsiniz.', $source);
    }

    public function test_map_load_failure_shows_manual_address_fallback(): void
    {
        $source = file_get_contents(resource_path('js/pages/public/mount-request-v2.tsx'));
        $this->assertIsString($source);

        $this->assertStringContainsString('setMapUnavailable(true)', $source);
        $this->assertStringContainsString('Harita yüklenemedi. Adresi elle girebilirsiniz.', $source);
        $this->assertStringContainsString('Harita yerine elle adres girişi kullanılacak.', $source);
        $this->assertStringContainsString('if (geolocationUnavailableMessage())', $source);
        $this->assertStringContainsString('}, [locationModalOpen]);', $source);
    }

    public function test_public_form_does_not_show_other_serials_section_by_default(): void
    {
        $source = file_get_contents(resource_path('js/pages/public/mount-request-v2.tsx'));
        $this->assertIsString($source);

        $this->assertStringNotContainsString('DİĞER SERİLERİ KONTROL ET', $source);
        $this->assertStringContainsString('Birden fazla ürünüm var', $source);
        $this->assertStringContainsString('Aynı faturadaki diğer ürünleri seçmek için tıklayın.', $source);
    }

    public function test_public_multiple_products_button_opens_modal(): void
    {
        $source = file_get_contents(resource_path('js/pages/public/mount-request-v2.tsx'));
        $this->assertIsString($source);

        $this->assertStringContainsString('const openMultiProductModal = () =>', $source);
        $this->assertStringContainsString('setMultiProductLookupRequested(true)', $source);
        $this->assertStringContainsString('setMultiProductLookupAttempt((current) => current + 1)', $source);
        $this->assertStringContainsString('setMultiProductModalOpen(items.length > 0)', $source);
        $this->assertStringContainsString('Aynı faturadaki diğer ürünler', $source);
        $this->assertStringContainsString('Seçili ürünleri talebe ekle', $source);
    }

    public function test_public_form_does_not_auto_open_multi_product_modal(): void
    {
        $source = file_get_contents(resource_path('js/pages/public/mount-request-v2.tsx'));
        $this->assertIsString($source);

        $this->assertStringContainsString('const [multiProductLookupRequested, setMultiProductLookupRequested] = useState(false);', $source);
        $this->assertStringContainsString('if (!multiProductLookupRequested) {', $source);
        $this->assertStringNotContainsString('}, [form.data.multiple_products, actions?.multi_product_lookup_url', $source);
        $this->assertStringContainsString('setMultiProductModalOpen(false);', $source);
    }

    public function test_public_form_does_not_open_empty_multi_product_modal(): void
    {
        $source = file_get_contents(resource_path('js/pages/public/mount-request-v2.tsx'));
        $this->assertIsString($source);

        $this->assertStringContainsString('setMultiProductModalOpen(items.length > 0)', $source);
        $this->assertStringContainsString('items.length > 0 ? \'\' : (payload.message || MULTI_PRODUCT_NO_SELECTABLE_MESSAGE)', $source);
        $this->assertStringContainsString('Bu fatura için ek montaj seçilebilir ürün bulunamadı. Ek ürün talebiniz operasyon ekibine iletilecek.', $source);
    }

    public function test_public_multi_product_gateway_timeout_shows_inline_message(): void
    {
        $source = file_get_contents(resource_path('js/pages/public/mount-request-v2.tsx'));
        $this->assertIsString($source);

        $this->assertStringContainsString('const MULTI_PRODUCT_LOOKUP_TIMEOUT_MS = 15000;', $source);
        $this->assertStringContainsString('const abortController = new AbortController();', $source);
        $this->assertStringContainsString('abortController.abort();', $source);
        $this->assertStringContainsString('Ek ürünler şu anda kontrol edilemedi. Talebiniz operasyon ekibine iletilecek.', $source);
        $this->assertStringContainsString('setMultiProductModalOpen(false);', $source);
    }

    public function test_public_multi_product_retry_restarts_lookup(): void
    {
        $source = file_get_contents(resource_path('js/pages/public/mount-request-v2.tsx'));
        $this->assertIsString($source);

        $this->assertStringContainsString('const [multiProductLookupAttempt, setMultiProductLookupAttempt] = useState(0);', $source);
        $this->assertStringContainsString('setMultiProductLookupAttempt((current) => current + 1)', $source);
        $this->assertStringContainsString('Tekrar kontrol et', $source);
        $this->assertStringContainsString('multiProductLookupAttempt,', $source);
    }

    public function test_public_multi_serial_modal_hides_internal_rows(): void
    {
        $source = file_get_contents(resource_path('js/pages/public/mount-request-v2.tsx'));
        $this->assertIsString($source);

        $this->assertStringNotContainsString('Müşteriye gösterilmedi', $source);
        $this->assertStringNotContainsString('operation_only_count', $source);
        $this->assertStringContainsString('payload.selectable_serials', $source);
    }

    public function test_public_payment_button_redirects_to_relative_payment_route_not_app_url(): void
    {
        config(['app.url' => 'http://127.0.0.1:8000']);
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_HARIC);
        [, $token] = $this->qrLink();

        $response = $this
            ->withServerVariables(['HTTP_HOST' => '10.0.28.64:8000'])
            ->post('/mount-request/'.$token.'/payment');

        $payment = TechnicalServiceMountPayment::query()->firstOrFail();

        $response->assertRedirect('/mount-payment/'.$payment->provider_reference);
        $this->assertStringNotContainsString('127.0.0.1', (string) $response->headers->get('Location'));
    }

    public function test_public_multiple_product_button_redirects_to_relative_form_route_not_app_url(): void
    {
        config(['app.url' => 'http://127.0.0.1:8000']);
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_HARIC);
        [, $token] = $this->qrLink();

        $response = $this
            ->withServerVariables(['HTTP_HOST' => '10.0.28.64:8000'])
            ->post('/mount-request/'.$token.'/multi-product');

        $response->assertRedirect('/mount-request/'.$token.'/form');
        $this->assertStringNotContainsString('127.0.0.1', (string) $response->headers->get('Location'));
    }

    public function test_admin_can_toggle_pre_form_payment_setting_without_new_schema(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);

        $this->actingAs($user)
            ->patchJson('/api/technical-service/qr-flow-settings', [
                'pre_form_payment_for_mount_excluded_enabled' => true,
            ])
            ->assertOk()
            ->assertJsonPath('settings.pre_form_payment_for_mount_excluded_enabled', true)
            ->assertJsonPath('settings.key', 'technical_service.qr.pre_form_payment_for_mount_excluded_enabled');

        $this->assertDatabaseHas('panel.page_configs', [
            'page_code' => 'technical_service_admin',
        ]);
    }

    public function test_admin_can_toggle_ops_detail_visibility_settings_without_new_schema(): void
    {
        $user = User::factory()->create(['role_code' => 'admin']);

        $this->actingAs($user)
            ->patchJson('/api/technical-service/qr-flow-settings', [
                'ops_detail_visibility' => [
                    'show_mount_excluded_approval_block' => true,
                    'show_payment_mount_control_block' => true,
                    'show_address_control_block' => false,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('settings.ops_detail_visibility.show_mount_excluded_approval_block', true)
            ->assertJsonPath('settings.ops_detail_visibility.show_payment_mount_control_block', true)
            ->assertJsonPath('settings.ops_detail_visibility.show_address_control_block', false)
            ->assertJsonPath(
                'settings.ops_detail_visibility.keys.show_mount_excluded_approval_block',
                'technical_service.ops_detail.show_mount_excluded_approval_block'
            );

        $config = PageConfig::query()
            ->where('page_code', 'technical_service_admin')
            ->firstOrFail();

        $this->assertTrue(data_get($config->layout_json, 'technical_service.ops_detail.show_mount_excluded_approval_block'));
        $this->assertTrue(data_get($config->layout_json, 'technical_service.ops_detail.show_payment_mount_control_block'));
        $this->assertFalse(data_get($config->layout_json, 'technical_service.ops_detail.show_address_control_block'));
    }

    public function test_admin_settings_page_contains_ops_detail_visibility_toggles(): void
    {
        $source = file_get_contents(resource_path('js/pages/panel/technical-service-admin.tsx'));

        $this->assertIsString($source);
        $this->assertStringContainsString('OPS detay görünürlüğü', $source);
        $this->assertStringContainsString('Montaj hariç / çoklu ürün onayı bloğunu göster', $source);
        $this->assertStringContainsString('Ödeme / montaj kontrol bloğunu göster', $source);
        $this->assertStringContainsString('Adres kontrol bloğunu göster', $source);
        $this->assertStringContainsString('updateOpsDetailVisibility', $source);
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
                ->where('viewState', 'checking')
                ->where('product.product_name', 'Emaks Prime Test Kilit')
                ->where('product.serial_number', $link->serial_number)
                ->where('actions.check_url', '/mount-request/'.$token.'/check')
                ->where('actions.form_url', '/mount-request/'.$token.'/form'));

        $this->assertDatabaseCount('technical_service_mount_sessions', 0);

        $this->postJson('/mount-request/'.$token.'/check')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('target_url', '/mount-request/'.$token.'/form')
            ->assertJsonPath('view_state', 'form_ready');

        $this->assertDatabaseCount('technical_service_mount_sessions', 1);

        $this->postJson('/mount-request/'.$token.'/check')->assertOk();

        $this->assertDatabaseCount('technical_service_mount_sessions', 1);
    }

    public function test_public_qr_opens_interstitial_before_form(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('public/mount-request-v2')
                ->where('viewState', 'checking')
                ->where('message', 'Cihaz bilgileriniz kontrol ediliyor.')
                ->where('actions.check_url', '/mount-request/'.$token.'/check')
                ->where('actions.form_url', '/mount-request/'.$token.'/form'));
    }

    public function test_public_qr_background_check_runs(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        [, $token] = $this->qrLink();

        $this->postJson('/mount-request/'.$token.'/check')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('view_state', 'form_ready')
            ->assertJsonPath('target_url', '/mount-request/'.$token.'/form')
            ->assertJsonPath('message', 'Montaj talep formunuz açılmaya hazır.');

        $this->assertDatabaseCount('technical_service_mount_sessions', 1);
    }

    public function test_public_qr_check_failure_still_opens_form_with_warning(): void
    {
        $this->fakeContextThrows();
        [, $token] = $this->qrLink();

        $this->postJson('/mount-request/'.$token.'/check')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('view_state', 'check_pending')
            ->assertJsonPath('target_url', '/mount-request/'.$token.'/form')
            ->assertJsonPath('message', 'Cihaz bilgileri tam doğrulanamadı; operasyon ekibi kontrol edecektir.');

        $this->get('/mount-request/'.$token.'/form')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('viewState', 'check_pending')
                ->where('message', 'Cihaz bilgileri tam doğrulanamadı; operasyon ekibi kontrol edecektir.'));
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

        $this->get('/mount-request/'.$token.'/form')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('viewState', 'form_ready')
                ->where('message', 'Montaj talep formunuz açılmaya hazır.')
                ->where('statusLabel', 'Montaj dahil'));
    }

    public function test_in_stock_current_state_requires_payment_before_form(): void
    {
        $this->enablePreFormPayment();
        $this->fakeContext(
            TechnicalServiceMountSession::SALE_CHECK_FAILED,
            TechnicalServiceQrLink::TYPE_PRE_SALE_PRODUCT,
            'in_stock_or_center',
        );
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token.'/form')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('viewState', 'payment_required')
                ->where('message', 'Bu ürün için montaj ödemesi gereklidir.')
                ->where('statusLabel', 'Montaj ödemesi gerekli'));
    }

    public function test_unsold_serial_redirects_to_payment_decision(): void
    {
        $this->enablePreFormPayment();
        $this->fakeContext(
            TechnicalServiceMountSession::SALE_NOT_FOUND,
            TechnicalServiceQrLink::TYPE_PRE_SALE_PRODUCT,
            'unknown',
        );
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token.'/form')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('viewState', 'payment_required')
                ->where('message', 'Bu ürün için montaj ödemesi gereklidir.')
                ->where('statusLabel', 'Montaj ödemesi gerekli'));
    }

    public function test_paid_unsold_serial_opens_mount_form(): void
    {
        $this->enablePreFormPayment();
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

        $this->get('/mount-request/'.$token.'/form')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('viewState', 'payment_required'));

        $paymentResponse = $this->post('/mount-request/'.$token.'/payment');
        $payment = TechnicalServiceMountPayment::query()->firstOrFail();
        $paymentResponse->assertRedirect('/mount-payment/'.$payment->provider_reference);

        $this->get("/mount-payment/fake/{$payment->id}/approve?token={$token}")
            ->assertRedirect('/mount-request/'.$token.'/form');

        $this->get('/mount-request/'.$token.'/form')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('viewState', 'form_ready')
                ->where('statusLabel', 'Montaj ödemesi alındı'));
    }

    public function test_qr_payment_required_uses_unified_direct_provider_when_real_payment_enabled(): void
    {
        $this->enablePreFormPayment();
        config([
            'payments.real_provider_enabled' => true,
            'payments.provider_name' => 'iyzico',
            'payments.provider_transport' => 'direct_laravel',
            'payments.gateway.mode' => 'sandbox',
        ]);
        app(TechnicalServicePaymentProviderCredentialService::class)
            ->saveIyzicoCredentials('sandbox', 'TEST_QR_SANDBOX_API_KEY', 'TEST_QR_SANDBOX_SECRET_KEY');

        $client = new class implements PaymentProviderGatewayClient
        {
            public bool $called = false;

            public ?PaymentProviderGatewayRequest $lastRequest = null;

            public function send(PaymentProviderGatewayRequest $request): PaymentProviderGatewayResponse
            {
                $this->called = true;
                $this->lastRequest = $request;

                return PaymentProviderGatewayResponse::fromArray([
                    'ok' => true,
                    'provider' => 'iyzico',
                    'mode' => 'sandbox',
                    'operation' => $request->operation(),
                    'provider_token' => 'qr-direct-provider-token',
                    'payment_url' => 'https://sandbox-payment.iyzipay.com/pay/qr-direct-provider-token',
                    'provider_status' => 'ACTIVE',
                    'provider_response_redacted' => ['status' => 'success'],
                ]);
            }
        };
        $this->app->instance(PaymentProviderGatewayClient::class, $client);
        $this->fakeContext(
            TechnicalServiceMountSession::SALE_NOT_FOUND,
            TechnicalServiceQrLink::TYPE_PRE_SALE_PRODUCT,
            'unknown',
        );
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token.'/form')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('viewState', 'payment_required'));

        $this->post('/mount-request/'.$token.'/payment')
            ->assertRedirect('/mount-payment/qr-direct-provider-token');

        $this->assertTrue($client->called);
        $payload = $client->lastRequest?->toArray();
        $this->assertSame('create_link', $payload['operation']);
        $this->assertSame('sandbox', $payload['mode']);
        $this->assertSame('technical_service', $payload['metadata']['source']);
        $this->assertSame('QR-V2-PUBLIC-001', $payload['serial_no']);
        $this->assertStringNotContainsString('TEST_QR_SANDBOX_SECRET_KEY', json_encode($payload, JSON_THROW_ON_ERROR));

        $payment = TechnicalServiceMountPayment::query()->firstOrFail();
        $this->assertSame('iyzico', $payment->provider);
        $this->assertSame('qr-direct-provider-token', $payment->provider_reference);
        $this->assertSame('https://sandbox-payment.iyzipay.com/pay/qr-direct-provider-token', $payment->payment_url);
        $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $payment->status);
        $this->assertSame('public_mount_payment', $payment->raw_payload['source']);
        $this->assertSame('sandbox', $payment->raw_payload['provider_mode']);
        $this->assertSame('direct_laravel', $payment->raw_payload['provider_transport']);
        $this->assertSame('iyzico', $payment->raw_payload['provider_decision']['provider']);
        $this->assertSame('QR-V2-PUBLIC-001', $payment->raw_payload['serial_number']);
        $this->assertNull($payment->technical_service_request_id);

        $this->get('/mount-payment/'.$payment->provider_reference)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('public/mount-payment')
                ->where('payment.payment_url', 'https://sandbox-payment.iyzipay.com/pay/qr-direct-provider-token')
                ->where('payment.copy_url', 'https://sandbox-payment.iyzipay.com/pay/qr-direct-provider-token')
                ->where('payment.provider_label', 'Iyzico Sandbox')
                ->where('payment.payment_action_kind', 'open_provider_url')
                ->where('payment.payment_action_label', 'Iyzico ödeme ekranını aç')
                ->where('payment.can_open_payment_url', true)
                ->where('payment.can_fake_complete_payment', false)
                ->where('payment.fake_approve_url', null));

        $this->post('/mount-payment/'.$payment->provider_reference.'/fake-approve')
            ->assertNotFound();
        $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $payment->refresh()->status);
    }

    public function test_public_iyzico_payment_action_opens_provider_url_and_never_marks_paid_locally(): void
    {
        config([
            'payments.provider' => 'fake',
            'payments.enable_fake_approve' => true,
        ]);
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_HARIC);
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token.'/form')->assertOk();
        $session = TechnicalServiceMountSession::query()->firstOrFail();
        $session->forceFill([
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_PENDING,
        ])->save();
        $payment = TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $session->id,
            'provider' => 'iyzico',
            'provider_reference' => 'iyzico-public-action-token',
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'amount' => 3500,
            'currency' => 'TRY',
            'payment_url' => 'https://sandbox-payment.iyzipay.com/pay/iyzico-public-action-token',
            'raw_payload' => [
                'source' => 'public_mount_payment',
                'provider_mode' => 'sandbox',
                'provider_transport' => 'direct_laravel',
                'provider_decision' => [
                    'provider' => 'iyzico',
                    'provider_mode' => 'sandbox',
                    'provider_transport' => 'direct_laravel',
                ],
                'provider_gateway' => [
                    'provider_status' => 'ACTIVE',
                ],
            ],
        ]);

        $this->get('/mount-request/'.$token.'/form')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payment.payment_action_kind', 'open_provider_url')
                ->where('payment.fake_approve_url', null));

        $this->get('/mount-payment/'.$payment->provider_reference)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('public/mount-payment')
                ->where('payment.provider_label', 'Iyzico Sandbox')
                ->where('payment.payment_action_kind', 'open_provider_url')
                ->where('payment.payment_action_label', 'Iyzico ödeme ekranını aç')
                ->where('payment.payment_url', 'https://sandbox-payment.iyzipay.com/pay/iyzico-public-action-token')
                ->where('payment.fake_approve_url', null));

        $this->post('/mount-payment/'.$payment->provider_reference.'/fake-approve')
            ->assertNotFound();
        $this->get("/mount-payment/fake/{$payment->id}/approve?token={$token}")
            ->assertNotFound();

        $payment->refresh();
        $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $payment->status);
        $this->assertNull($payment->paid_at);
        $this->assertSame(TechnicalServiceMountSession::PAYMENT_PENDING, $payment->session->refresh()->mount_payment_status);
    }

    public function test_qr_payment_required_blocks_when_real_provider_readiness_is_missing(): void
    {
        $this->enablePreFormPayment();
        config([
            'payments.real_provider_enabled' => true,
            'payments.provider_name' => 'iyzico',
            'payments.provider_transport' => 'direct_laravel',
            'payments.gateway.mode' => 'sandbox',
        ]);
        $this->fakeContext(
            TechnicalServiceMountSession::SALE_NOT_FOUND,
            TechnicalServiceQrLink::TYPE_PRE_SALE_PRODUCT,
            'unknown',
        );
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token.'/form')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('viewState', 'payment_required'));

        $this->post('/mount-request/'.$token.'/payment')
            ->assertRedirect('/mount-request/'.$token.'/payment')
            ->assertSessionHasErrors('payment');

        $this->assertSame(0, TechnicalServiceMountPayment::query()->count());
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

        $this->get('/mount-request/'.$token.'/form')
            ->assertOk()
            ->assertDontSee('Montaj durumu', false);
    }

    public function test_payment_required_public_page_shows_payment_message(): void
    {
        $this->enablePreFormPayment();
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_HARIC);
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token.'/form')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('public/mount-request-v2')
                ->where('viewState', 'payment_required')
                ->where('message', 'Bu ürün için montaj ödemesi gereklidir.')
                ->where('statusLabel', 'Montaj ödemesi gerekli'));
    }

    public function test_setting_off_mount_excluded_opens_form_with_payment_context(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_HARIC);
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token.'/form')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('public/mount-request-v2')
                ->where('viewState', 'form_ready')
                ->where('message', 'Montaj talep formunuz açılmaya hazır.')
                ->where('statusLabel', 'Montaj ödemesi gerekli')
                ->where('allowMultiProductRequest', true));

        $session = TechnicalServiceMountSession::query()->firstOrFail();
        $this->assertSame(TechnicalServiceMountSession::PAYMENT_PENDING, $session->mount_payment_status);
        $this->assertSame(TechnicalServiceMountSession::ENTRY_SINGLE_PRODUCT, $session->customer_entry_mode);
    }

    public function test_setting_on_mount_excluded_routes_payment_first(): void
    {
        $this->enablePreFormPayment();
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_HARIC);
        [, $token] = $this->qrLink();

        $this->postJson('/mount-request/'.$token.'/check')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('view_state', 'payment_required')
            ->assertJsonPath('target_url', '/mount-request/'.$token.'/payment')
            ->assertJsonPath('message', 'Bu ürün için montaj ödemesi gereklidir.');

        $session = TechnicalServiceMountSession::query()->firstOrFail();
        $this->assertSame(TechnicalServiceMountSession::PAYMENT_PENDING, $session->mount_payment_status);
        $this->assertSame(TechnicalServiceMountSession::DECISION_READY, $session->decision_status);

        $this->get('/mount-request/'.$token.'/payment')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('public/mount-request-v2')
                ->where('viewState', 'payment_required')
                ->where('message', 'Bu ürün için montaj ödemesi gereklidir.')
                ->where('actions.create_payment_url', '/mount-request/'.$token.'/payment'));
    }

    public function test_pre_form_payment_setting_on_mount_included_opens_form(): void
    {
        $this->enablePreFormPayment();
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        [, $token] = $this->qrLink();

        $this->postJson('/mount-request/'.$token.'/check')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('view_state', 'form_ready')
            ->assertJsonPath('target_url', '/mount-request/'.$token.'/form')
            ->assertJsonPath('message', 'Montaj talep formunuz açılmaya hazır.');
    }

    public function test_public_payment_step_redirects_to_form_when_payment_not_required(): void
    {
        $this->enablePreFormPayment();
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token.'/payment')
            ->assertRedirect('/mount-request/'.$token.'/form');
    }

    public function test_unresolved_serial_does_not_hard_block_form(): void
    {
        $this->fakeContext(
            TechnicalServiceMountSession::SALE_CHECK_FAILED,
            TechnicalServiceQrLink::TYPE_PRE_SALE_PRODUCT,
            'unknown',
        );
        [, $token] = $this->qrLink();

        $this->postJson('/mount-request/'.$token.'/check')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('view_state', 'check_pending')
            ->assertJsonPath('target_url', '/mount-request/'.$token.'/form')
            ->assertJsonPath('message', 'Seri / montaj kontrolü şu anda tamamlanamadı. Formu doldurabilirsiniz; operasyon ekibi kontrolü tamamlayacaktır.');
    }

    public function test_montaj_haric_unpaid_context_shows_payment_decision_screen(): void
    {
        $this->enablePreFormPayment();
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_HARIC);
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token.'/form')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('viewState', 'payment_required')
                ->where('message', 'Bu ürün için montaj ödemesi gereklidir.')
                ->where('statusLabel', 'Montaj ödemesi gerekli'));
    }

    public function test_montaj_haric_unpaid_screen_has_payment_and_multi_product_actions(): void
    {
        $this->enablePreFormPayment();
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_HARIC);
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token.'/form')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('actions.payment_label', 'Montaj ödemesi yap')
                ->where('actions.multi_product_label', 'Birden fazla ürünüm var'));
    }

    public function test_multi_product_without_payment_updates_session_and_shows_multi_form_decision(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_HARIC);
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token.'/form')->assertOk();
        $this->post('/mount-request/'.$token.'/multi-product')->assertRedirect('/mount-request/'.$token.'/form');

        $session = TechnicalServiceMountSession::query()->firstOrFail();
        $this->assertSame(TechnicalServiceMountSession::ENTRY_MULTI_PRODUCT_WITHOUT_PAYMENT, $session->customer_entry_mode);
        $this->assertSame(TechnicalServiceMountSession::PAYMENT_SKIPPED_MULTI_PRODUCT, $session->mount_payment_status);

        $this->get('/mount-request/'.$token.'/form')
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

        $this->get('/mount-request/'.$token.'/form')->assertOk();
        $paymentResponse = $this->post('/mount-request/'.$token.'/payment');

        $payment = TechnicalServiceMountPayment::query()->firstOrFail();
        $paymentResponse->assertRedirect('/mount-payment/'.$payment->provider_reference);
        $this->assertSame(TechnicalServiceMountPayment::STATUS_PENDING, $payment->status);

        $this->get("/mount-payment/fake/{$payment->id}/approve?token={$token}")
            ->assertRedirect('/mount-request/'.$token.'/form');

        $payment->refresh();
        $session = $payment->session->refresh();
        $this->assertSame(TechnicalServiceMountPayment::STATUS_PAID, $payment->status);
        $this->assertSame(TechnicalServiceMountSession::PAYMENT_PAID, $session->mount_payment_status);
        $this->assertSame(TechnicalServiceMountSession::ENTRY_PAID_SINGLE_PRODUCT, $session->customer_entry_mode);

        $this->get('/mount-request/'.$token.'/form')
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

        $paymentResponse = $this->post('/mount-request/'.$token.'/payment');
        $payment = TechnicalServiceMountPayment::query()->firstOrFail();
        $paymentResponse->assertRedirect('/mount-payment/'.$payment->provider_reference);

        $this->post('/mount-payment/'.$payment->provider_reference.'/fake-approve')
            ->assertRedirect('/mount-request/'.$token.'/form');

        $this->get('/mount-payment/'.$payment->provider_reference)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('public/mount-payment')
                ->where('payment.status', TechnicalServiceMountPayment::STATUS_PAID)
                ->where('payment.mount_form_url', route('mount-request.form', ['token' => $token]))
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

            $paymentResponse = $this->post('/mount-request/'.$token.'/payment');
            $payment = TechnicalServiceMountPayment::query()->firstOrFail();
            $paymentResponse->assertRedirect('/mount-payment/'.$payment->provider_reference);

            $this->post('/mount-payment/'.$payment->provider_reference.'/fake-approve')
                ->assertRedirect('/mount-request/'.$token.'/form');

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

    public function test_public_mount_submit_queues_single_new_request_ops_event_with_public_actor(): void
    {
        config([
            'payments.provider' => 'fake',
            'payments.enable_fake_approve' => true,
        ]);
        Storage::fake('public');
        Http::fake();
        Carbon::setTestNow('2026-06-03 10:00:00');

        try {
            app(TechnicalServiceMessagingSettingsService::class)->update([
                'messaging_enabled' => true,
                'test_mode_enabled' => true,
                'shared_test_phone' => '0555 000 00 00',
                'ops_whatsapp_enabled' => true,
                'ops_whatsapp_phone' => '0555 000 00 00',
                'message_types' => [
                    'new_request_created_ops' => ['enabled' => true, 'channel_policy' => 'whatsapp_only'],
                ],
            ]);
            $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
            [, $token] = $this->qrLink();

            $this->submitMountRequest($token)->assertOk();

            $request = TechnicalServiceRequest::query()->where('mrn', 'MRN-2606MP030001')->firstOrFail();
            $event = $request->events()->where('event_type', 'technical_service_request_created')->sole();
            $dispatch = TechnicalServiceMessageDispatch::query()
                ->where('technical_service_request_id', $request->id)
                ->where('message_type', 'new_request_created_ops')
                ->sole();

            $this->assertNull($event->author_user_id);
            $this->assertSame('customer_public', data_get($event->metadata, 'actor_role'));
            $this->assertSame('public_mount_request', data_get($event->metadata, 'source'));
            $this->assertSame('whatsapp', $dispatch->channel);
            $this->assertSame('ops', $dispatch->recipient_role);
            $this->assertNull($dispatch->sent_at);
            $this->assertNull($dispatch->provider_message_id);
            $this->assertDatabaseMissing('technical_service_message_dispatches', [
                'technical_service_request_id' => $request->id,
                'message_type' => 'new_request_created_ops',
                'channel' => 'sms',
            ]);
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

            $paymentResponse = $this->post('/mount-request/'.$token.'/payment');
            $payment = TechnicalServiceMountPayment::query()->firstOrFail();
            $paymentResponse->assertRedirect('/mount-payment/'.$payment->provider_reference);

            $this->post('/mount-payment/'.$payment->provider_reference.'/fake-approve')
                ->assertRedirect('/mount-request/'.$token.'/form');

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

        $this->get('/mount-request/'.$token.'/form')->assertOk();
        $paymentResponse = $this->post('/mount-request/'.$token.'/payment');

        $payment = TechnicalServiceMountPayment::query()->firstOrFail();
        $paymentResponse->assertRedirect('/mount-payment/'.$payment->provider_reference);

        $this->get('/mount-payment/'.$payment->provider_reference)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('public/mount-payment')
                ->where('payment.provider_label', 'Fake/Yerel ödeme simülasyonu')
                ->where('payment.payment_action_kind', 'fake_complete')
                ->where('payment.payment_action_label', 'Fake ödeme tamamla')
                ->where('payment.fake_approve_url', route('mount-payment.fake-token.approve', ['token' => $payment->provider_reference], false)));

        $this->post('/mount-payment/'.$payment->provider_reference.'/fake-approve')
            ->assertRedirect('/mount-request/'.$token.'/form');

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

        $this->get('/mount-request/'.$token.'/form')->assertOk();
        $paymentResponse = $this->post('/mount-request/'.$token.'/payment');

        $payment = TechnicalServiceMountPayment::query()->firstOrFail();
        $paymentResponse->assertRedirect('/mount-payment/'.$payment->provider_reference);

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

        $this->get('/mount-request/'.$token.'/form')->assertOk();
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
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function fakeInvoiceSerialRows(array $rows): void
    {
        $this->app->instance(
            MikroInvoiceSerialsService::class,
            new class($rows) extends MikroInvoiceSerialsService
            {
                public function __construct(
                    private readonly array $rows,
                ) {}

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

    #[DataProvider('responsibilityCodeCaseProvider')]
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
            ->assertJsonPath('message', 'Bu fatura için ek montaj seçilebilir ürün bulunamadı. Ek ürün talebiniz operasyon ekibine iletilecek.');
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

    public function test_public_multi_product_check_refreshes_cached_empty_non_fixture_context(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        [, $token] = $this->qrLink();

        config(['services.technical_service.invoice_serials_mode' => 'disabled']);
        $this->postJson('/mount-request/'.$token.'/invoice-serials/check')
            ->assertOk()
            ->assertJsonPath('has_selectable_serials', false)
            ->assertJsonCount(0, 'selectable_serials');

        $this->fakeInvoiceSerialRows([
            [
                'Faturadaki Seri No' => 'TS7B-SIBLING-001',
                'sorumluluk_kodu' => 'PERAKENDE SATIŞ',
                'stok_adi' => 'TS7B Kardeş Kilit',
            ],
        ]);

        $this->postJson('/mount-request/'.$token.'/invoice-serials/check')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('has_selectable_serials', true)
            ->assertJsonCount(1, 'selectable_serials')
            ->assertJsonPath('selectable_serials.0.serial_number', 'TS7B-SIBLING-001')
            ->assertJsonPath('message', null)
            ->assertJsonPath('total_count', 1);
    }

    public function test_gateway_mode_uses_real_mikro_invoice_serial_query_without_persisting_datasource_metadata(): void
    {
        config(['services.technical_service.invoice_serials_mode' => 'gateway']);

        $queryKey = 'query'.'_template';
        $allowedKey = 'allowed'.'_params';
        $connectionKey = 'connection'.'_meta';

        DataSource::query()->create([
            'code' => 'technical_service_serial_check',
            'name' => 'Seri kontrol',
            'db_type' => 'n8n_json',
            $queryKey => 'SELECT 1',
            $allowedKey => ['serial_no'],
            $connectionKey => [
                'endpoint_url' => 'https://local-gateway.invalid',
                'response_rows_key' => 'rows',
            ],
            'active' => true,
        ]);

        $this->app->instance(N8nPanelDataGateway::class, new class extends N8nPanelDataGateway
        {
            public function run(string $sourceCode, array $filters, ?DataSource $dataSource = null): array
            {
                $template = (string) $dataSource?->getAttribute('query'.'_template');
                $allowed = $dataSource?->getAttribute('allowed'.'_params');

                if ($sourceCode !== MikroInvoiceSerialsService::SOURCE_INVOICE_SERIALS) {
                    throw new RuntimeException('Unexpected invoice serial source.');
                }

                if (($filters['serial_no'] ?? null) !== 'TS7B1-MAIN') {
                    throw new RuntimeException('Serial parameter was not passed to gateway.');
                }

                foreach (['CIHAZ_HAREKETLERI', 'STOK_HAREKETLERI', 'sth_cari_srm_merkezi', 'sth_stok_srm_merkezi'] as $needle) {
                    if (! str_contains($template, $needle)) {
                        throw new RuntimeException('Missing Mikro invoice serial query fragment: '.$needle);
                    }
                }

                if ($allowed !== ['serial_no', 'bypass_cache']) {
                    throw new RuntimeException('Gateway params are not constrained.');
                }

                return [
                    'rows' => [
                        [
                            'Faturadaki Seri No' => 'TS7B1-MAIN',
                            'Stok Adı' => 'Ana Kilit',
                            'Sorumluluk Kodu' => 'PERAKENDE SATIŞ',
                        ],
                        [
                            'Faturadaki Seri No' => 'TS7B1-SIBLING',
                            'Stok Adı' => 'Kardeş Kilit',
                            'Sorumluluk Kodu' => 'PERAKENDE SATIŞ',
                        ],
                    ],
                    'meta' => ['gateway' => 'fake'],
                    'request' => ['serial_no' => $filters['serial_no']],
                ];
            }
        });

        $result = app(MikroInvoiceSerialsService::class)->forSerial('TS7B1-MAIN');

        $this->assertSame('gateway', $result['meta']['status']);
        $this->assertSame('technical_service_invoice_serials', $result['meta']['source']);
        $this->assertSame(
            ['TS7B1-MAIN', 'TS7B1-SIBLING'],
            collect($result['all_invoice_serials'])->pluck('serial_number')->all(),
        );
        $this->assertSame(
            ['TS7B1-MAIN', 'TS7B1-SIBLING'],
            collect($result['selectable_customer_serials'])->pluck('serial_number')->all(),
        );
        $this->assertDatabaseMissing('panel.data_sources', [
            'code' => 'technical_service_invoice_serials',
        ]);
    }

    public function test_frontend_opens_customer_safe_multi_product_popup(): void
    {
        $source = file_get_contents(resource_path('js/pages/public/mount-request-v2.tsx'));
        $this->assertIsString($source);

        $this->assertStringContainsString('setMultiProductModalOpen(items.length > 0)', $source);
        $this->assertStringContainsString('setMultiProductLookupRequested(true)', $source);
        $this->assertStringNotContainsString('Diğer serileri kontrol et', $source);
        $this->assertStringContainsString('Aynı faturadaki diğer ürünler', $source);
        $this->assertStringContainsString('Bu faturada birden fazla ürün görünüyor. Montaj istediğiniz ürünleri seçebilirsiniz.', $source);
        $this->assertStringNotContainsString('Operasyon ekibi diğer ürünleri ayrıca kontrol edebilir.', $source);
        $this->assertStringContainsString('Bu fatura için ek montaj seçilebilir ürün bulunamadı. Ek ürün talebiniz operasyon ekibine iletilecek.', $source);
        $this->assertStringContainsString('Ek ürünler şu anda kontrol edilemedi. Talebiniz operasyon ekibine iletilecek.', $source);
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
    ): void {
        $this->app->instance(
            SerialProductContextResolver::class,
            new class($saleMountStatus, $suggestedLinkType, $currentSerialState) extends SerialProductContextResolver
            {
                public function __construct(
                    private readonly string $saleMountStatus,
                    private readonly string $suggestedLinkType,
                    private readonly string $currentSerialState,
                ) {}

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
            new class extends SerialProductContextResolver
            {
                public function __construct() {}

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
            new class extends SerialProductContextResolver
            {
                public function __construct() {}

                public function resolve(string $serialNumber, array $knownContext = []): array
                {
                    throw new RuntimeException('Fixture Mikro resolver failed.');
                }
            },
        );
    }

    private function enablePreFormPayment(): void
    {
        PageConfig::query()->updateOrCreate(
            ['page_code' => 'technical_service_admin'],
            ['layout_json' => [
                'technical_service' => [
                    'qr' => [
                        'pre_form_payment_for_mount_excluded_enabled' => true,
                    ],
                ],
            ]],
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
