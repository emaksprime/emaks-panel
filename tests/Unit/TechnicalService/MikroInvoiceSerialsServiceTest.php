<?php

namespace Tests\Unit\TechnicalService;

use App\Services\TechnicalService\MikroInvoiceSerialsService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MikroInvoiceSerialsServiceTest extends TestCase
{
    public function test_perakende_satis_responsibility_code_is_customer_selectable(): void
    {
        $row = $this->normalize([
            'Faturadaki Seri No' => 'SN-PERAKENDE',
            'Sorumluluk Kodu' => 'PERAKENDE SATIŞ',
            'Stok Adı' => 'E10-AKILLI KAPI KİLİDİ-SİYAH',
        ]);

        $this->assertSame('PERAKENDE SATIŞ', $row['responsibility_code']);
        $this->assertSame('PERAKENDE SATIS', $row['normalized_responsibility_code']);
        $this->assertFalse($row['is_responsibility_blocked']);
        $this->assertTrue($row['customer_selectable']);
        $this->assertTrue($row['customer_visible']);
        $this->assertNull($row['hidden_reason']);
    }

    public function test_online_responsibility_code_is_customer_selectable(): void
    {
        $row = $this->normalize([
            'Faturadaki Seri No' => 'SN-ONLINE',
            'sorumluluk_kodu' => 'ONLINE',
            'Stok Adı' => 'E10-AKILLI KAPI KİLİDİ-SİYAH',
        ]);

        $this->assertSame('ONLINE', $row['normalized_responsibility_code']);
        $this->assertFalse($row['is_responsibility_blocked']);
        $this->assertTrue($row['customer_selectable']);
    }

    public function test_lowercase_responsibility_code_key_is_customer_selectable(): void
    {
        $row = $this->normalize([
            'Faturadaki Seri No' => 'SN-PERAKENDE-LOWER',
            'sorumluluk_kodu' => 'PERAKENDE SATIŞ',
            'Stok Adı' => 'E10-AKILLI KAPI KİLİDİ-SİYAH',
        ]);

        $this->assertSame('PERAKENDE SATIS', $row['normalized_responsibility_code']);
        $this->assertFalse($row['is_responsibility_blocked']);
        $this->assertTrue($row['customer_selectable']);
    }

    public function test_bayi_satis_responsibility_code_is_blocked(): void
    {
        $row = $this->normalize([
            'Faturadaki Seri No' => 'SN-BAYI',
            'sorumluluk_kodu' => 'BAYİ SATIŞ',
            'Stok Adı' => 'E10-AKILLI KAPI KİLİDİ-SİYAH',
        ]);

        $this->assertSame('BAYI SATIS', $row['normalized_responsibility_code']);
        $this->assertTrue($row['is_responsibility_blocked']);
        $this->assertFalse($row['customer_selectable']);
        $this->assertSame('responsibility_code_blocked', $row['hidden_reason']);
    }

    #[DataProvider('blockedResponsibilityProvider')]
    public function test_blocked_responsibility_codes_are_not_customer_selectable(mixed $responsibilityCode): void
    {
        $row = $this->normalize([
            'Faturadaki Seri No' => 'SN-BLOCKED',
            'sorumluluk_kodu' => $responsibilityCode,
            'Stok Adı' => 'E10-AKILLI KAPI KİLİDİ-SİYAH',
        ]);

        $this->assertTrue($row['is_responsibility_blocked']);
        $this->assertFalse($row['customer_selectable']);
        $this->assertFalse($row['customer_visible']);
        $this->assertSame('responsibility_code_blocked', $row['hidden_reason']);
    }

    public function test_returned_row_is_not_customer_selectable_even_when_responsibility_is_allowed(): void
    {
        $row = $this->normalize([
            'Faturadaki Seri No' => 'SN-RETURNED',
            'sorumluluk_kodu' => 'PERAKENDE SATIŞ',
            'Stok Adı' => 'E10-AKILLI KAPI KİLİDİ-SİYAH',
            'İade Notu' => 'RET-01',
        ]);

        $this->assertTrue($row['is_returned']);
        $this->assertFalse($row['is_responsibility_blocked']);
        $this->assertFalse($row['customer_selectable']);
        $this->assertSame('returned', $row['hidden_reason']);
    }

    public function test_current_latest_false_does_not_hide_allowed_non_returned_row(): void
    {
        $row = $this->normalize([
            'Faturadaki Seri No' => 'SN-NOT-LATEST',
            'sorumluluk_kodu' => 'PERAKENDE SATIŞ',
            'Stok Adı' => 'E10-AKILLI KAPI KİLİDİ-SİYAH',
            'Bu Fatura Bu Seri İçin Son Satış mı' => 'Hayır',
        ]);

        $this->assertFalse($row['is_current_latest_sale']);
        $this->assertTrue($row['customer_selectable']);
        $this->assertContains('Güncel son satış değil', $row['warning_labels']);
    }

    public function test_responsibility_code_source_payload_value_is_used(): void
    {
        $row = $this->normalize([
            'Faturadaki Seri No' => 'SN-SOURCE',
            'Stok Adı' => 'E10-AKILLI KAPI KİLİDİ-SİYAH',
            'source_payload' => [
                'Sorumluluk Kodu' => 'PERAKENDE SATIŞ',
            ],
        ]);

        $this->assertSame('PERAKENDE SATIS', $row['normalized_responsibility_code']);
        $this->assertTrue($row['customer_selectable']);
    }

    public function test_hareket_grup_kodu_is_not_used_as_responsibility_code(): void
    {
        $row = $this->normalize([
            'Faturadaki Seri No' => 'SN-HAREKET',
            'Stok Adı' => 'E10-AKILLI KAPI KİLİDİ-SİYAH',
            'Hareket Grup Kodu 1' => 'PERAKENDE SATIŞ',
        ]);

        $this->assertNull($row['responsibility_code']);
        $this->assertNull($row['normalized_responsibility_code']);
        $this->assertTrue($row['is_responsibility_blocked']);
        $this->assertFalse($row['customer_selectable']);
    }

    public static function blockedResponsibilityProvider(): array
    {
        return [
            'proje' => ['PROJE'],
            'null' => [null],
            'empty' => [''],
            'gr' => ['GR'],
            'cilingir_turkish' => ['ÇİLİNGİR SATIŞLARI'],
            'cilinigir_typo' => ['ÇİLİNİGİR SATIŞLARI'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        return $this->service()->normalizeRows([$row], 'SN-PRIMARY')[0];
    }

    private function service(): MikroInvoiceSerialsService
    {
        return new MikroInvoiceSerialsService();
    }
}
