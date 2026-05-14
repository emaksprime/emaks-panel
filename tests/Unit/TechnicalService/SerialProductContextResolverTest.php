<?php

namespace Tests\Unit\TechnicalService;

use App\Models\SupportActivationCode;
use App\Models\TechnicalServiceMountSession;
use App\Services\TechnicalService\MikroSerialNumberService;
use App\Services\TechnicalService\SerialActivationCodeResolver;
use App\Services\TechnicalService\SerialProductContextResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SerialProductContextResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_product_context_from_single_service_boundary(): void
    {
        SupportActivationCode::query()->create([
            'code' => 'UNIT-CONTEXT-001-275023',
            'stock_code' => 'STK-001',
            'stock_name' => 'PHILIPS DDL720 Akıllı Kilit',
            'serial_number' => 'UNITCONTEXT001-275023',
            'serial_number_clean' => 'UNITCONTEXT001',
            'search_code' => 'C00001',
            'activation_code' => '275023',
            'metadata' => [],
            'search_text' => 'UNIT-CONTEXT-001 275023',
            'is_active' => true,
        ]);

        $resolver = new SerialProductContextResolver(
            app(SerialActivationCodeResolver::class),
            new class extends MikroSerialNumberService {
                public function __construct()
                {
                }

                public function checkInstallation(string $serialNo): array
                {
                    return [
                        'found' => true,
                        'montaj_durumu' => 'Montaj Hariç',
                        'stok_kodu' => 'STK-001',
                        'stok_adi' => 'PHILIPS DDL720 Akıllı Kilit',
                    ];
                }
            },
        );

        $context = $resolver->resolve('UNITCONTEXT001');

        $this->assertSame('UNITCONTEXT001', $context['serial_number']);
        $this->assertSame('PHILIPS DDL720 Akıllı Kilit', $context['product_name']);
        $this->assertNull($context['product_model']);
        $this->assertSame('PHILIPS', $context['brand']);
        $this->assertSame('275023', $context['activation_code']);
        $this->assertSame(TechnicalServiceMountSession::SALE_MONTAJ_HARIC, $context['sale_mount_status']);
        $this->assertSame('unknown', $context['invoice_customer_type']);
        $this->assertSame('STK-001', $context['context_payload']['stock_code']);
    }
}
