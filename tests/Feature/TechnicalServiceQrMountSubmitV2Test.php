<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceQrLink;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestSerial;
use App\Models\TechnicalServiceRequestUpload;
use App\Models\User;
use App\Services\TechnicalService\MountRequestSubmitService;
use App\Services\TechnicalService\MikroInvoiceSerialsService;
use App\Services\TechnicalService\SerialProductContextResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TechnicalServiceQrMountSubmitV2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_show_form_decision_renders_basic_customer_form_fields(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('public/mount-request-v2')
                ->where('viewState', 'form_ready')
                ->where('actions.submit_url', route('mount-request.submit', ['token' => $token]))
                ->where('allowMultiProductRequest', true));

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
            'mount-request-v2:draft:${window.location.pathname}',
            'TURKEY_PROVINCES.map',
            'getDistrictOptionsForProvince(form.data.city)',
            "const [districtSearch, setDistrictSearch] = useState('');",
            'filteredDistrictOptions',
            "handleCityChange(event.target.value)",
            "disabled={!form.data.city}",
            "placeholder={form.data.city ? 'İlçe ara' : 'Önce il seçin'}",
            'role="combobox"',
            'role="listbox"',
            'aria-controls="district-options"',
            'setDistrictDropdownOpen',
            'Sonuç bulunamadı.',
            'window.localStorage',
            'Formu temizle',
            'multiple_products',
            'selected_invoice_serials',
            'multi_product_lookup_url',
            'Aynı faturadaki ürünler kontrol ediliyor...',
            'Bu faturada birden fazla ürün görünüyor. Montaj istediğiniz ürünleri seçebilirsiniz.',
            'Operasyon ekibi diğer ürünleri ayrıca kontrol edebilir.',
            'multiProductOperationOnlyCount',
            'multiProductTotalCount',
            'Bu adreste montaj istediğiniz diğer ürünleri seçin',
            'Ek ürün talebiniz operasyon ekibine iletilecek.',
            'setMultiProductModalOpen',
            'form.post(submitUrl',
            'Talebiniz gönderiliyor...',
            'Gönderiliyor...',
            'Konumunu ekle',
            'Konum paylaşırsan usta seçimi ve yol ücreti daha doğru hesaplanır.',
            'Konumumu kullan',
            'Konum alındı',
            'Haritada aç',
            'Adres detayları',
            'Örnek çekim',
            'Kapıyı tamamen kadraja al.',
            'Kilit/yan profil net görünsün.',
            'Bulanık fotoğraf yükleme.',
            'Kameradan çek',
            'Dosyalardan seç',
            'capture="environment"',
            'image/jpeg,image/png,image/webp',
            'VITE_GOOGLE_MAPS_API_KEY',
            'forceFormData: true',
            'sm:grid-cols-2 lg:grid-cols-3',
            'truncate font-semibold',
            'Kapı ön, yan ve arka yüz fotoğrafları zorunludur.',
        ] as $expectedText) {
            $this->assertStringContainsString($expectedText, $source);
        }

        $this->assertStringNotContainsString(')).post(submitUrl', $source);
        $this->assertStringNotContainsString('data:image', $source);
        $this->assertStringNotContainsString('Google Places seçimiyle', $source);
        $this->assertStringNotContainsString('operation_control_door_photo', $source);
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
        $this->assertSame(TechnicalServiceMountSession::PAYMENT_NOT_REQUIRED, $request->mount_payment_status);
        $this->assertSame('Montaj dahil', $request->mount_payment_label);
        $this->assertSame('STK-QR-001', $request->stock_code);
        $this->assertSame('275023', $request->activation_code);
        $this->assertSame('FAT/12345', $request->invoice_display_no);
        $this->assertSame('IRS/987', $request->dispatch_display_no);
        $this->assertSame('SIP/456', $request->order_display_no);
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
        $this->assertSame(TechnicalServiceMountSession::SALE_MONTAJ_HARIC, $request->sale_mount_status);
        $this->assertSame(TechnicalServiceMountSession::PAYMENT_PAID, $request->mount_payment_status);
        $this->assertSame('Montaj ödemesi alındı', $request->mount_payment_label);
        $this->assertSame('fake', $request->mount_payment_provider);
        $this->assertNotEmpty($request->mount_payment_reference);
        $this->assertNotNull($request->mount_payment_paid_at);
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
        $this->assertSame(TechnicalServiceMountSession::PAYMENT_SKIPPED_MULTI_PRODUCT, $request->mount_payment_status);
        $this->assertSame('Çoklu ürün talebi - ödeme operasyon tarafından netleştirilecek', $request->mount_payment_label);
    }

    public function test_montaj_dahil_multi_product_checkbox_marks_operation_warning(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('allowMultiProductRequest', true));

        $this->post('/mount-request/'.$token.'/submit', $this->validPayload([
            'multiple_products' => true,
        ]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('viewState', 'submitted')
                ->has('submitted.mrn'));

        $session = TechnicalServiceMountSession::query()->firstOrFail();
        $request = TechnicalServiceRequest::query()->firstOrFail();

        $this->assertSame(TechnicalServiceMountSession::ENTRY_MULTI_PRODUCT_WITHOUT_PAYMENT, $session->customer_entry_mode);
        $this->assertStringContainsString(MountRequestSubmitService::MULTI_PRODUCT_OPERATION_WARNING, $request->description);
    }

    public function test_multi_product_submit_stores_all_invoice_rows_and_customer_visibility_policy(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        $this->fakeInvoiceSerials();
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token)->assertOk();

        $this->postJson('/mount-request/'.$token.'/invoice-serials/check')
            ->assertOk()
            ->assertJsonPath('has_selectable_serials', true)
            ->assertJsonCount(2, 'selectable_serials')
            ->assertJsonPath('selectable_serials.0.serial_number', 'SN-DIRECT-2')
            ->assertJsonPath('selectable_serials.1.serial_number', 'SN-NOT-LATEST')
            ->assertJsonMissingPath('selectable_serials.0.hidden_reason')
            ->assertJsonMissingPath('selectable_serials.0.invoice_number')
            ->assertJsonMissingPath('selectable_serials.0.return_note')
            ->assertJsonMissing(['serial_number' => 'SN-UNKNOWN'])
            ->assertJsonMissing(['serial_number' => 'SN-DEALER'])
            ->assertJsonMissing(['serial_number' => 'SN-RETURNED']);

        $this->post('/mount-request/'.$token.'/submit', $this->validPayload([
            'multiple_products' => true,
            'selected_invoice_serials' => ['SN-DIRECT-2'],
        ]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('viewState', 'submitted')
                ->has('submitted.mrn'));

        $request = TechnicalServiceRequest::query()->firstOrFail();
        $serials = TechnicalServiceRequestSerial::query()
            ->where('technical_service_request_id', $request->id)
            ->get()
            ->keyBy('serial_number');

        $this->assertCount(6, $serials);
        $this->assertTrue($serials['QR-V2-SUBMIT-001']->is_primary);
        $this->assertTrue($serials['QR-V2-SUBMIT-001']->customer_selected);
        $this->assertTrue($serials['QR-V2-SUBMIT-001']->operation_added);
        $this->assertSame($request->mrn, $serials['QR-V2-SUBMIT-001']->linked_mrn);
        $this->assertSame('+905372081655', $serials['QR-V2-SUBMIT-001']->customer_phone);
        $this->assertSame('green', $serials['QR-V2-SUBMIT-001']->color_status);
        $this->assertTrue($serials['SN-DIRECT-2']->customer_selected);
        $this->assertTrue($serials['SN-DIRECT-2']->operation_added);
        $this->assertSame($request->mrn, $serials['SN-DIRECT-2']->linked_mrn);
        $this->assertSame('+905372081655', $serials['SN-DIRECT-2']->customer_phone);
        $this->assertTrue($serials['SN-DIRECT-2']->customer_visible);
        $this->assertSame('green', $serials['SN-DIRECT-2']->color_status);
        $this->assertFalse($serials['SN-DEALER']->customer_visible);
        $this->assertSame('responsibility_code_blocked', $serials['SN-DEALER']->hidden_reason);
        $this->assertSame('orange', $serials['SN-DEALER']->color_status);
        $this->assertFalse($serials['SN-UNKNOWN']->customer_visible);
        $this->assertFalse($serials['SN-UNKNOWN']->customer_selectable);
        $this->assertSame('responsibility_code_blocked', $serials['SN-UNKNOWN']->hidden_reason);
        $this->assertTrue($serials['SN-NOT-LATEST']->customer_visible);
        $this->assertTrue($serials['SN-NOT-LATEST']->customer_selectable);
        $this->assertFalse($serials['SN-NOT-LATEST']->is_current_latest_sale);
        $this->assertSame('not_selected', $serials['SN-NOT-LATEST']->hidden_reason);
        $this->assertTrue($serials['SN-RETURNED']->is_returned);
        $this->assertSame('red', $serials['SN-RETURNED']->color_status);
        $this->assertFalse($serials['SN-RETURNED']->customer_selected);
    }

    public function test_photo_upload_with_submit_stores_operation_control_door_photo_metadata(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        [, $token] = $this->qrLink();

        $this->get('/mount-request/'.$token)->assertOk();

        $this->post('/mount-request/'.$token.'/submit', $this->validPayload([
            'door_front_photo' => $this->imageUpload('front.jpg'),
            'door_side_photo' => $this->imageUpload('side.png'),
            'door_back_photo' => $this->imageUpload('back.png'),
        ]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('viewState', 'submitted')
                ->has('submitted.mrn'));

        $request = TechnicalServiceRequest::query()->firstOrFail();
        $uploads = TechnicalServiceRequestUpload::query()
            ->where('technical_service_request_id', $request->id)
            ->orderBy('field_code')
            ->get();

        $this->assertCount(3, $uploads);
        $this->assertSame([
            'door_back_photo',
            'door_front_photo',
            'door_side_photo',
        ], $uploads->pluck('field_code')->all());
        $this->assertTrue($uploads->every(fn (TechnicalServiceRequestUpload $upload): bool => $upload->category === TechnicalServiceRequestUpload::CATEGORY_OPERATION_CONTROL_DOOR_PHOTO));
        $this->assertFalse($uploads->contains(fn (TechnicalServiceRequestUpload $upload): bool => in_array($upload->category, ['field_completion', 'saha_completion', 'completion_document'], true)));
        Storage::disk('public')->assertExists($uploads->firstOrFail()->path);
    }

    public function test_missing_door_photo_blocks_submit(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        [, $token] = $this->qrLink();
        $this->get('/mount-request/'.$token)->assertOk();

        $this->from('/mount-request/'.$token)
            ->post('/mount-request/'.$token.'/submit', $this->validPayload([
                'door_back_photo' => null,
            ]))
            ->assertRedirect('/mount-request/'.$token)
            ->assertSessionHasErrors(['door_back_photo']);

        $this->assertDatabaseCount('technical_service_requests', 0);
    }

    public function test_oversized_photo_upload_returns_turkish_validation_error(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        [, $token] = $this->qrLink();
        $this->get('/mount-request/'.$token)->assertOk();

        $this->from('/mount-request/'.$token)
            ->post('/mount-request/'.$token.'/submit', $this->validPayload([
                'door_front_photo' => $this->imageUpload('front.jpg', 'image/png', 9000),
            ]))
            ->assertRedirect('/mount-request/'.$token)
            ->assertSessionHasErrors(['door_front_photo']);

        $this->assertDatabaseCount('technical_service_requests', 0);
    }

    public function test_location_payload_submit_persists_address_and_map_fields(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        [, $token] = $this->qrLink();
        $this->get('/mount-request/'.$token)->assertOk();

        $this->post('/mount-request/'.$token.'/submit', $this->validPayload([
            'address' => '',
            'city' => 'İstanbul',
            'district' => 'Kadıköy',
            'location_latitude' => '40.9876543',
            'location_longitude' => '29.1234567',
            'location_place_id' => 'place-test',
            'location_formatted_address' => 'Caferağa Mahallesi, Kadıköy/İstanbul',
            'location_map_url' => 'https://www.google.com/maps?q=40.9876543,29.1234567',
            'building_no' => '12',
            'apartment_no' => 'A',
            'door_no' => '5',
            'floor_no' => '2',
            'site_name' => 'Test Apartmanı',
        ]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('viewState', 'submitted')
                ->has('submitted.mrn'));

        $request = TechnicalServiceRequest::query()->firstOrFail();
        $this->assertSame('İstanbul', $request->customer_city);
        $this->assertSame('Kadıköy', $request->customer_district);
        $this->assertStringContainsString('Caferağa Mahallesi', $request->service_address);
        $this->assertStringContainsString('Bina No: 12', $request->service_address);
        $this->assertSame('place-test', $request->location_place_id);
        $this->assertSame('https://www.google.com/maps?q=40.9876543,29.1234567', $request->location_map_url);
        $this->assertSame('12', $request->building_no);
        $this->assertSame('A', $request->apartment_no);
        $this->assertSame('5', $request->door_no);
        $this->assertSame('2', $request->floor_no);
    }

    public function test_manual_address_without_coordinates_is_geocoded_on_submit(): void
    {
        config(['services.google.geocoding_api_key' => 'test-geocoding-key']);
        Http::fake([
            'https://maps.googleapis.com/maps/api/geocode/json*' => Http::response([
                'status' => 'OK',
                'results' => [[
                    'formatted_address' => 'Kadıköy, İstanbul, Türkiye',
                    'geometry' => ['location' => ['lat' => 40.9876543, 'lng' => 29.1234567]],
                ]],
            ], 200),
        ]);

        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        [, $token] = $this->qrLink();
        $this->get('/mount-request/'.$token)->assertOk();

        $this->post('/mount-request/'.$token.'/submit', $this->validPayload([
            'location_latitude' => null,
            'location_longitude' => null,
            'location_formatted_address' => null,
        ]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('viewState', 'submitted')
                ->has('submitted.mrn'));

        $request = TechnicalServiceRequest::query()->firstOrFail();
        $this->assertSame('40.9876543', $request->location_latitude);
        $this->assertSame('29.1234567', $request->location_longitude);
        $this->assertSame('manual_geocoded', $request->location_source);
        $this->assertSame('address_fallback', $request->location_accuracy);
        $this->assertSame('Kadıköy, İstanbul, Türkiye', $request->location_formatted_address);
        $this->assertStringContainsString('Manual customer address geocoded', (string) $request->location_note);
        $this->assertTrue($request->qr_context_payload['customer_address_geocode']['ok'] ?? false);
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://maps.googleapis.com/maps/api/geocode/json'));
    }

    public function test_manual_address_geocode_failure_does_not_block_submit_and_records_operation_warning(): void
    {
        config(['services.google.geocoding_api_key' => 'test-geocoding-key']);
        Http::fake([
            'https://maps.googleapis.com/maps/api/geocode/json*' => Http::response([
                'status' => 'ZERO_RESULTS',
                'results' => [],
            ], 200),
        ]);

        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        [, $token] = $this->qrLink();
        $this->get('/mount-request/'.$token)->assertOk();

        $this->post('/mount-request/'.$token.'/submit', $this->validPayload([
            'location_latitude' => null,
            'location_longitude' => null,
            'location_formatted_address' => null,
        ]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('viewState', 'submitted')
                ->has('submitted.mrn'));

        $request = TechnicalServiceRequest::query()->firstOrFail();
        $this->assertNull($request->location_latitude);
        $this->assertNull($request->location_longitude);
        $this->assertSame('geocode_failed', $request->location_source);
        $this->assertStringContainsString(MountRequestSubmitService::CUSTOMER_ADDRESS_GEOCODE_WARNING, (string) $request->description);
        $this->assertFalse($request->qr_context_payload['customer_address_geocode']['ok'] ?? true);
        $this->assertSame(MountRequestSubmitService::CUSTOMER_ADDRESS_GEOCODE_WARNING, $request->qr_context_payload['customer_address_geocode']['error'] ?? null);
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://maps.googleapis.com/maps/api/geocode/json'));
    }

    public function test_submit_without_address_or_location_is_rejected(): void
    {
        $this->fakeContext(TechnicalServiceMountSession::SALE_MONTAJ_DAHIL);
        [, $token] = $this->qrLink();
        $this->get('/mount-request/'.$token)->assertOk();

        $this->from('/mount-request/'.$token)
            ->post('/mount-request/'.$token.'/submit', $this->validPayload([
                'address' => '',
                'location_formatted_address' => '',
                'location_latitude' => '',
                'location_longitude' => '',
            ]))
            ->assertRedirect('/mount-request/'.$token)
            ->assertSessionHasErrors(['address']);

        $this->assertDatabaseCount('technical_service_requests', 0);
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
            'door_front_photo' => $this->imageUpload('front.jpg'),
            'door_side_photo' => $this->imageUpload('side.png'),
            'door_back_photo' => $this->imageUpload('back.png'),
            'installation_consent' => true,
            'kvkk_consent' => true,
        ], $overrides);
    }

    private function imageUpload(string $name, string $mime = 'image/png', int $sizeKb = 16): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'qr-door-photo-');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=');
        file_put_contents($path, $png.str_repeat('0', max(0, ($sizeKb * 1024) - strlen((string) $png))));

        return new UploadedFile($path, $name, $mime, null, true);
    }

    private function fakeInvoiceSerials(): void
    {
        $this->app->instance(
            MikroInvoiceSerialsService::class,
            new class extends MikroInvoiceSerialsService {
                public function forSerial(string $serialNo): array
                {
                    $rows = $this->normalizeRows([
                        [
                            'Faturadaki Seri No' => 'QR-V2-SUBMIT-001',
                            'Stok Adı' => 'E10-AKILLI KAPI KİLİDİ-SİYAH (70LİK KİLİT)',
                            'Stok Kodu' => 'STK-E10',
                            'Bu Fatura Bu Seri İçin Son Satış mı' => 'Evet',
                            'Satış Cari Grup Adı' => 'Perakende Son Müşteri',
                            'sorumluluk_kodu' => 'PERAKENDE SATIŞ',
                        ],
                        [
                            'Faturadaki Seri No' => 'SN-DIRECT-2',
                            'Stok Adı' => 'GALAXY 20-AKILLI KAPI KİLİDİ-GRİ (70LİK KİLİT)',
                            'Stok Kodu' => 'STK-GALAXY',
                            'Bu Fatura Bu Seri İçin Son Satış mı' => 'Evet',
                            'Satış Cari Grup Adı' => 'Perakende Son Müşteri',
                            'sorumluluk_kodu' => 'PERAKENDE SATIŞ',
                        ],
                        [
                            'Faturadaki Seri No' => 'SN-DEALER',
                            'Stok Adı' => 'PHILIPS DDL720 Akıllı Kilit',
                            'Stok Kodu' => 'STK-DDL',
                            'Bu Fatura Bu Seri İçin Son Satış mı' => 'Evet',
                            'sorumluluk_kodu' => 'BAYİ SATIŞ',
                        ],
                        [
                            'Faturadaki Seri No' => 'SN-UNKNOWN',
                            'Stok Adı' => 'PHILIPS DDL720 Akıllı Kilit',
                            'Stok Kodu' => 'STK-DDL',
                            'Bu Fatura Bu Seri İçin Son Satış mı' => 'Evet',
                        ],
                        [
                            'Faturadaki Seri No' => 'SN-NOT-LATEST',
                            'Stok Adı' => 'PHILIPS DDL720 Akıllı Kilit',
                            'Stok Kodu' => 'STK-DDL',
                            'Bu Fatura Bu Seri İçin Son Satış mı' => 'Hayır',
                            'Satış Cari Grup Adı' => 'Perakende Son Müşteri',
                            'sorumluluk_kodu' => 'PERAKENDE SATIŞ',
                        ],
                        [
                            'Faturadaki Seri No' => 'SN-RETURNED',
                            'Stok Adı' => 'PHILIPS DDL720 Akıllı Kilit',
                            'Stok Kodu' => 'STK-DDL',
                            'İade Notu' => 'İADE GELMİŞ',
                            'İade Tarihi' => '14.05.2026',
                            'İade Evrak Seri' => 'IAD',
                            'İade Evrak Sıra' => '10',
                            'Bu Fatura Bu Seri İçin Son Satış mı' => 'Evet',
                            'Satış Cari Grup Adı' => 'Perakende Son Müşteri',
                        ],
                    ], $serialNo);

                    return [
                        'rows' => $rows,
                        'all_invoice_serials' => $rows,
                        'selectable_customer_serials' => array_values(array_filter($rows, fn (array $row): bool => (bool) $row['customer_selectable'])),
                        'returned_serials' => array_values(array_filter($rows, fn (array $row): bool => (bool) $row['is_returned'])),
                        'meta' => [],
                        'request' => [],
                    ];
                }
            },
        );
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
                        'activation_code' => '275023',
                        'sale_mount_status' => $this->saleMountStatus,
                        'invoice_customer_type' => 'unknown',
                        'current_serial_state' => 'sold_current',
                        'has_current_sale' => true,
                        'latest_event_type' => 'sale',
                        'latest_valid_sale_exists' => true,
                        'stock_code' => 'STK-QR-001',
                        'context_payload' => [
                            'source' => 'test_fake_context',
                            'mikro_decision' => [
                                'fatura_seri' => 'FAT',
                                'fatura_sira' => '12345',
                                'irsaliye_seri' => 'IRS',
                                'irsaliye_sira' => '987',
                                'siparis_seri' => 'SIP',
                                'siparis_sira' => '456',
                            ],
                        ],
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
