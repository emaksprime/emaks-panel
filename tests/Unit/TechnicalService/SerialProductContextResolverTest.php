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
     */
    private function resolver(array $mikroRow): SerialProductContextResolver
    {
        return new SerialProductContextResolver(
            app(SerialActivationCodeResolver::class),
            new class($mikroRow) extends MikroSerialNumberService {
                /**
                 * @param array<string, mixed> $mikroRow
                 */
                public function __construct(private readonly array $mikroRow)
                {
                }

                public function checkInstallation(string $serialNo): array
                {
                    return $this->mikroRow;
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
