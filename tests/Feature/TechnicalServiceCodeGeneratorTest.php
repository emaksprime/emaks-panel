<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceRequest;
use App\Services\TechnicalService\TechnicalServiceCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TechnicalServiceCodeGeneratorTest extends TestCase
{
    use RefreshDatabase;

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
                "MRN-2606IS030001",
                app(TechnicalServiceCodeGenerator::class)->nextMrn("\u{0130}lker \u{015E}ahin")
            );
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

    public function test_srv_code_uses_root_mrn_body_and_sequence(): void
    {
        $this->assertSame(
            'SRV-2606MP030001-001',
            app(TechnicalServiceCodeGenerator::class)->serviceCodeForRoot('MRN-2606MP030001', 1)
        );
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
}
