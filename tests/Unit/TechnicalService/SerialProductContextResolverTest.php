<?php

namespace Tests\Unit\TechnicalService;

use App\Models\SupportActivationCode;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceQrLink;
use App\Services\TechnicalService\MikroSerialNumberService;
use App\Services\TechnicalService\SerialActivationCodeResolver;
use App\Services\TechnicalService\SerialProductContextResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SerialProductContextResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_stok_adi_is_used_as_product_name_and_activation_code_comes_from_resolver(): void
    {
        $this->activationCode('UNITCONTEXT001', '275023', 'C00001');

        $context = $this->resolver([
            'found' => true,
            'montaj_durumu' => 'Montaj Hariç',
            'stok_kodu' => 'STK-001',
            'stok_adi' => 'PHILIPS DDL720 Akıllı Kilit',
        ])->resolve('UNITCONTEXT001');

        $this->assertSame('UNITCONTEXT001', $context['serial_number']);
        $this->assertSame('PHILIPS DDL720 Akıllı Kilit', $context['product_name']);
        $this->assertSame('DDL720', $context['product_model']);
        $this->assertSame('PHILIPS', $context['brand']);
        $this->assertSame('275023', $context['activation_code']);
        $this->assertSame(TechnicalServiceMountSession::SALE_MONTAJ_HARIC, $context['sale_mount_status']);
        $this->assertSame('unknown', $context['invoice_customer_type']);
        $this->assertSame(TechnicalServiceQrLink::TYPE_PRE_SALE_PRODUCT, $context['suggested_link_type']);
        $this->assertSame('STK-001', $context['context_payload']['stock_code']);
    }

    public function test_model_adi_is_used_when_available(): void
    {
        $context = $this->resolver([
            'found' => true,
            'montaj_durumu' => 'Montaj Dahil',
            'Stok Adı' => 'EMAKS PRIME Akıllı Kilit',
            'Model Adı' => 'DDL720',
        ])->resolve('UNITCONTEXT002');

        $this->assertSame('EMAKS PRIME Akıllı Kilit', $context['product_name']);
        $this->assertSame('DDL720', $context['product_model']);
        $this->assertSame('EMAKS PRIME', $context['brand']);
    }

    public function test_model_stays_null_when_model_column_is_missing_and_stock_name_is_not_copied(): void
    {
        $context = $this->resolver([
            'found' => true,
            'montaj_durumu' => 'Montaj Dahil',
            'stok_adi' => 'EMAKS PRIME Akıllı Kilit',
        ])->resolve('UNITCONTEXT003');

        $this->assertSame('EMAKS PRIME Akıllı Kilit', $context['product_name']);
        $this->assertNull($context['product_model']);
        $this->assertNotSame($context['product_name'], $context['product_model']);
    }

    public function test_brand_is_derived_from_product_name_when_brand_column_is_missing(): void
    {
        $philips = $this->resolver([
            'found' => true,
            'montaj_durumu' => 'Montaj Dahil',
            'stok_adi' => 'DDL720-MVP-17HWSE - BAS ÇEK KİLİTLEME - GRİ / GREY',
        ])->resolve('UNITCONTEXT004');

        $emaks = $this->resolver([
            'found' => true,
            'montaj_durumu' => 'Montaj Dahil',
            'stok_adi' => 'GALAXY 20-AKILLI KAPI KİLİDİ-GRİ (70LİK KİLİT)',
        ])->resolve('UNITCONTEXT005');

        $this->assertSame('PHILIPS', $philips['brand']);
        $this->assertSame('EMAKS PRIME', $emaks['brand']);
    }

    public function test_safe_model_is_derived_from_product_name_when_model_column_is_missing(): void
    {
        $ddl = $this->resolver([
            'found' => true,
            'montaj_durumu' => 'Montaj Dahil',
            'stok_adi' => 'DDL720-MVP-17HWSE - BAS ÇEK KİLİTLEME - GRİ / GREY',
        ])->resolve('UNITCONTEXT008');

        $galaxy = $this->resolver([
            'found' => true,
            'montaj_durumu' => 'Montaj Dahil',
            'stok_adi' => 'GALAXY 20-AKILLI KAPI KİLİDİ-GRİ (70LİK KİLİT)',
        ])->resolve('UNITCONTEXT009');

        $this->assertSame('DDL720-MVP-17HWSE', $ddl['product_model']);
        $this->assertSame('GALAXY 20 - GRİ', $galaxy['product_model']);
    }

    public function test_emaks_prime_family_models_and_brand_are_derived_from_product_name(): void
    {
        $e10 = $this->resolver([
            'found' => true,
            'montaj_durumu' => 'Montaj Dahil',
            'stok_adi' => 'E10-AKILLI KAPI KİLİDİ-SİYAH (70LİK KİLİT)',
        ])->resolve('UNITCONTEXT017');

        $galaxy = $this->resolver([
            'found' => true,
            'montaj_durumu' => 'Montaj Dahil',
            'stok_adi' => 'GALAXY 20-AKILLI KAPI KİLİDİ-GRİ (70LİK KİLİT)',
        ])->resolve('UNITCONTEXT018');

        $ddl = $this->resolver([
            'found' => true,
            'montaj_durumu' => 'Montaj Dahil',
            'stok_adi' => 'DDL720-MVP-17HWSE - BAS ÇEK KİLİTLEME - GRİ / GREY',
        ])->resolve('UNITCONTEXT019');

        $alpha = $this->resolver([
            'found' => true,
            'montaj_durumu' => 'Montaj Dahil',
            'stok_adi' => 'ALPHA-5HB - BAS ÇEK KİLİTLEME - SİYAH / BLACK (70LİK KİLİT)',
        ])->resolve('UNITCONTEXT020');

        $this->assertSame('E10', $e10['product_model']);
        $this->assertSame('EMAKS PRIME', $e10['brand']);
        $this->assertSame('GALAXY 20 - GRİ', $galaxy['product_model']);
        $this->assertSame('EMAKS PRIME', $galaxy['brand']);
        $this->assertSame('PHILIPS', $ddl['brand']);
        $this->assertSame('ALPHA-5HB', $alpha['product_model']);
        $this->assertSame('EMAKS PRIME', $alpha['brand']);
    }

    public function test_sold_product_link_type_depends_on_document_context_not_invoice_customer_type(): void
    {
        $context = $this->resolver([
            'found' => true,
            'montaj_durumu' => 'Montaj Hariç',
            'stok_adi' => 'PHILIPS EasyKey',
            'fatura_sira' => 'FTR-123',
        ])->resolve('UNITCONTEXT010');

        $this->assertSame('unknown', $context['invoice_customer_type']);
        $this->assertSame(TechnicalServiceQrLink::TYPE_SOLD_PRODUCT, $context['suggested_link_type']);
    }

    public function test_not_found_context_stays_pre_sale_product(): void
    {
        $context = $this->resolver([
            'found' => false,
            'montaj_durumu' => 'Seri No Bulunamadı',
            'stok_adi' => null,
        ])->resolve('UNITCONTEXT011');

        $this->assertSame(TechnicalServiceQrLink::TYPE_PRE_SALE_PRODUCT, $context['suggested_link_type']);
    }

    public function test_product_name_can_fall_back_to_serial_history_stock_name(): void
    {
        $context = $this->resolver([
            'found' => true,
            'montaj_durumu' => 'Seri No BulunamadÄ±',
        ], [
            [
                'event_type' => 'stok_hareketi',
                'event_date' => '2026-05-10',
                'stok_kodu' => 'STK-HISTORY',
                'stok_adi' => 'GALAXY 20-AKILLI KAPI KÄ°LÄ°DÄ°-GRÄ° (70LÄ°K KÄ°LÄ°T)',
            ],
        ])->resolve('UNITCONTEXT012');

        $this->assertSame('GALAXY 20-AKILLI KAPI KÄ°LÄ°DÄ°-GRÄ° (70LÄ°K KÄ°LÄ°T)', $context['product_name']);
        $this->assertSame('STK-HISTORY', $context['stock_code']);
        $this->assertSame('EMAKS PRIME', $context['brand']);
        $this->assertSame(TechnicalServiceQrLink::TYPE_PRE_SALE_PRODUCT, $context['suggested_link_type']);
    }

    public function test_latest_stock_or_center_movement_is_pre_sale_and_never_montaj_dahil(): void
    {
        $context = $this->resolver([
            'found' => true,
            'montaj_durumu' => 'Montaj Dahil',
            'stok_adi' => 'PHILIPS DDL720 AkÄ±llÄ± Kilit',
            'fatura_sira' => 'FTR-OLD',
        ], [
            [
                'event_type' => 'satis',
                'event_date' => '2026-05-01',
                'stok_adi' => 'PHILIPS DDL720 AkÄ±llÄ± Kilit',
                'is_latest_valid_sale' => false,
            ],
            [
                'event_type' => 'stok_hareketi',
                'event_date' => '2026-05-12',
                'title' => 'Merkez depo giris',
                'stok_adi' => 'PHILIPS DDL720 AkÄ±llÄ± Kilit',
            ],
        ])->resolve('UNITCONTEXT013');

        $this->assertSame('in_stock_or_center', $context['current_serial_state']);
        $this->assertFalse($context['has_current_sale']);
        $this->assertSame(TechnicalServiceMountSession::SALE_CHECK_FAILED, $context['sale_mount_status']);
        $this->assertSame(TechnicalServiceQrLink::TYPE_PRE_SALE_PRODUCT, $context['suggested_link_type']);
    }

    public function test_current_sale_keeps_sold_product_link_type(): void
    {
        $context = $this->resolver([
            'found' => true,
            'montaj_durumu' => 'Montaj HariÃ§',
            'stok_adi' => 'DDL720-MVP-17HWSE - BAS Ã‡EK KÄ°LÄ°TLEME - GRÄ° / GREY',
            'fatura_sira' => 'FTR-CURRENT',
        ], [
            [
                'event_type' => 'satis',
                'event_date' => '2026-05-12',
                'stok_adi' => 'DDL720-MVP-17HWSE - BAS Ã‡EK KÄ°LÄ°TLEME - GRÄ° / GREY',
                'is_latest_valid_sale' => true,
            ],
        ])->resolve('UNITCONTEXT014');

        $this->assertSame('sold_current', $context['current_serial_state']);
        $this->assertTrue($context['has_current_sale']);
        $this->assertSame(TechnicalServiceQrLink::TYPE_SOLD_PRODUCT, $context['suggested_link_type']);
    }

    public function test_past_sale_followed_by_return_is_not_sold_product(): void
    {
        $context = $this->resolver([
            'found' => true,
            'montaj_durumu' => 'Montaj Dahil',
            'stok_adi' => 'PHILIPS DDL720 AkÄ±llÄ± Kilit',
            'fatura_sira' => 'FTR-OLD',
        ], [
            [
                'event_type' => 'satis',
                'event_date' => '2026-05-01',
                'stok_adi' => 'PHILIPS DDL720 AkÄ±llÄ± Kilit',
            ],
            [
                'event_type' => 'iade',
                'event_date' => '2026-05-13',
                'title' => 'Iade geldi',
                'stok_adi' => 'PHILIPS DDL720 AkÄ±llÄ± Kilit',
            ],
        ])->resolve('UNITCONTEXT015');

        $this->assertSame('returned', $context['current_serial_state']);
        $this->assertFalse($context['has_current_sale']);
        $this->assertSame(TechnicalServiceMountSession::SALE_CHECK_FAILED, $context['sale_mount_status']);
        $this->assertSame(TechnicalServiceQrLink::TYPE_PRE_SALE_PRODUCT, $context['suggested_link_type']);
    }

    public function test_missing_product_name_remains_null_for_clear_admin_error(): void
    {
        $context = $this->resolver([
            'found' => false,
            'montaj_durumu' => 'Seri No BulunamadÄ±',
        ])->resolve('UNITCONTEXT016');

        $this->assertNull($context['product_name']);
        $this->assertSame(TechnicalServiceQrLink::TYPE_PRE_SALE_PRODUCT, $context['suggested_link_type']);
    }

    public function test_brand_column_wins_when_available(): void
    {
        $context = $this->resolver([
            'found' => true,
            'montaj_durumu' => 'Montaj Dahil',
            'stok_adi' => 'Akıllı Kilit',
            'Marka Kodu' => 'PHILIPS',
        ])->resolve('UNITCONTEXT006');

        $this->assertSame('PHILIPS', $context['brand']);
    }

    public function test_arama_kodu_is_not_used_as_activation_code(): void
    {
        $this->activationCode('UNITCONTEXT007', '275023', 'A01809');

        $context = $this->resolver([
            'found' => true,
            'montaj_durumu' => 'Montaj Dahil',
            'stok_adi' => 'PHILIPS EasyKey',
        ])->resolve('UNITCONTEXT007');

        $this->assertSame('275023', $context['activation_code']);
        $this->assertNotSame('A01809', $context['activation_code']);
    }

    /**
     * @param array<string, mixed> $mikroRow
     * @param array<int, array<string, mixed>> $historyItems
     * @param array<string, mixed>|null $latestSale
     */
    private function resolver(array $mikroRow, array $historyItems = [], ?array $latestSale = null): SerialProductContextResolver
    {
        return new SerialProductContextResolver(
            app(SerialActivationCodeResolver::class),
            new class($mikroRow, $historyItems, $latestSale) extends MikroSerialNumberService {
                /**
                 * @param array<string, mixed> $mikroRow
                 * @param array<int, array<string, mixed>> $historyItems
                 * @param array<string, mixed>|null $latestSale
                 */
                public function __construct(
                    private readonly array $mikroRow,
                    private readonly array $historyItems,
                    private readonly ?array $latestSale,
                )
                {
                }

                public function checkInstallation(string $serialNo): array
                {
                    return $this->mikroRow;
                }

                public function history(string $serialNo): array
                {
                    return [
                        'serial_no' => $serialNo,
                        'decision' => $this->mikroRow,
                        'items' => $this->historyItems,
                    ];
                }

                public function latestValidSale(string $serialNo): ?array
                {
                    return $this->latestSale;
                }
            },
        );
    }

    private function activationCode(string $serialNumberClean, string $activationCode, string $searchCode): void
    {
        SupportActivationCode::query()->create([
            'code' => $serialNumberClean.'-'.$activationCode,
            'stock_code' => 'STK-001',
            'stock_name' => 'PHILIPS DDL720 Akıllı Kilit',
            'serial_number' => $serialNumberClean.'-'.$activationCode,
            'serial_number_clean' => $serialNumberClean,
            'search_code' => $searchCode,
            'activation_code' => $activationCode,
            'metadata' => [],
            'search_text' => $serialNumberClean.' '.$activationCode.' '.$searchCode,
            'is_active' => true,
        ]);
    }
}
