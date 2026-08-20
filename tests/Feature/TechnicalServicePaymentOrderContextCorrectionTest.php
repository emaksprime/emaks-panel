<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceQrLink;
use App\Models\TechnicalServiceRequest;
use App\Models\User;
use App\Services\TechnicalService\TechnicalServicePaymentOrderContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class TechnicalServicePaymentOrderContextCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Mail::fake();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('c', 32)),
            'services.technical_service.payment_order_context_test_stock' => true,
        ]);
    }

    public function test_non_ops_cannot_create_payment_context_correction(): void
    {
        $fixture = $this->correctionFixture();
        $nonOps = User::factory()->create(['role_code' => 'sales']);

        $this->actingAs($nonOps)
            ->postJson($this->correctionUrl($fixture), $this->correctionPayload($fixture))
            ->assertForbidden();

        $this->assertSame(2, $this->contextCount($fixture));
    }

    public function test_correction_requires_reason(): void
    {
        $fixture = $this->correctionFixture();
        $payload = $this->correctionPayload($fixture);
        $payload['reason'] = 'kısa';

        $this->actingAs($fixture['actor'])
            ->postJson($this->correctionUrl($fixture), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->assertSame(2, $this->contextCount($fixture));
    }

    public function test_correction_rejects_stale_expected_revision(): void
    {
        $fixture = $this->correctionFixture();
        $arguments = $fixture['arguments'];
        $arguments['expectedLatestRevision']++;

        $this->expectException(ConflictHttpException::class);
        $this->service()->createCorrectionRevision(...$arguments);
    }

    public function test_correction_rejects_stale_expected_hash(): void
    {
        $fixture = $this->correctionFixture();
        $arguments = $fixture['arguments'];
        $arguments['expectedLatestHash'] = str_repeat('a', 64);

        $this->expectException(ConflictHttpException::class);
        $this->service()->createCorrectionRevision(...$arguments);
    }

    public function test_correction_rejects_wrong_source_context(): void
    {
        $fixture = $this->correctionFixture();
        $other = $this->correctionFixture();
        $arguments = $fixture['arguments'];
        $arguments['sourceContextId'] = (int) $other['source']->id;
        $arguments['sourceRevision'] = (int) $other['source']->revision;
        $arguments['sourceHash'] = (string) $other['source']->context_hash;

        $this->expectException(ValidationException::class);
        $this->service()->createCorrectionRevision(...$arguments);
    }

    public function test_correction_preserves_all_historical_context_rows(): void
    {
        $fixture = $this->correctionFixture();
        $before = DB::table(TechnicalServicePaymentOrderContextService::TABLE)
            ->whereIn('id', [(int) $fixture['source']->id, (int) $fixture['latest']->id])
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();

        $this->correct($fixture);

        $after = DB::table(TechnicalServicePaymentOrderContextService::TABLE)
            ->whereIn('id', [(int) $fixture['source']->id, (int) $fixture['latest']->id])
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
        $this->assertSame($before, $after);
    }

    public function test_correction_preserves_historical_item_rows(): void
    {
        $fixture = $this->correctionFixture();
        $contextIds = [(int) $fixture['source']->id, (int) $fixture['latest']->id];
        $before = DB::table(TechnicalServicePaymentOrderContextService::ITEM_TABLE)
            ->whereIn('context_id', $contextIds)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();

        $this->correct($fixture);

        $after = DB::table(TechnicalServicePaymentOrderContextService::ITEM_TABLE)
            ->whereIn('context_id', $contextIds)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
        $this->assertSame($before, $after);
    }

    public function test_correction_appends_exactly_one_revision(): void
    {
        $fixture = $this->correctionFixture();
        $result = $this->correct($fixture);

        $this->assertTrue($result['created']);
        $this->assertSame(3, $this->contextCount($fixture));
        $this->assertSame((int) $fixture['latest']->revision + 1, $result['context']['revision']);
    }

    public function test_correction_appends_exactly_two_active_items(): void
    {
        $fixture = $this->correctionFixture();
        $result = $this->correct($fixture);

        $this->assertSame(2, DB::table(TechnicalServicePaymentOrderContextService::ITEM_TABLE)
            ->where('context_id', $result['context']['id'])
            ->count());
        $this->assertSame(2, $result['context']['line_count']);
    }

    public function test_correction_writes_one_audit_event(): void
    {
        $fixture = $this->correctionFixture();
        $before = $this->correctionEventCount($fixture);

        $this->correct($fixture);

        $this->assertSame($before + 1, $this->correctionEventCount($fixture));
    }

    public function test_repeated_correction_is_idempotent(): void
    {
        $fixture = $this->correctionFixture();
        $first = $this->correct($fixture);
        $counts = [$this->contextCount($fixture), $this->itemCount($fixture), $this->correctionEventCount($fixture)];
        $second = $this->correct($fixture);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['context']['id'], $second['context']['id']);
        $this->assertSame($counts, [$this->contextCount($fixture), $this->itemCount($fixture), $this->correctionEventCount($fixture)]);
    }

    public function test_paid_emaks_shipment_correction_resolves_series_s(): void
    {
        $result = $this->correct($this->correctionFixture());

        $this->assertSame('S', $result['context']['desired_mikro_series']);
        $this->assertSame('shipment', $result['context']['delivery_mode']);
        $this->assertSame('paid', $result['context']['commercial_mode']);
    }

    public function test_correction_preserves_two_items(): void
    {
        $fixture = $this->correctionFixture();
        $sourceCodes = collect($this->service()->contextProjection($fixture['source'])['lines'])->pluck('item_code')->all();
        $result = $this->correct($fixture);

        $this->assertSame($sourceCodes, collect($result['context']['lines'])->pluck('item_code')->all());
    }

    public function test_correction_preserves_gross_total_2000_try(): void
    {
        $result = $this->correct($this->correctionFixture());

        $this->assertSame('2000.00', $result['context']['gross_total']);
        $this->assertSame(2000.0, $result['context']['order_line_total']);
        $this->assertSame('TRY', $result['context']['currency']);
    }

    public function test_correction_preserves_vat_included_totals(): void
    {
        $fixture = $this->correctionFixture();
        $source = $this->service()->contextProjection($fixture['source']);
        $result = $this->correct($fixture)['context'];

        $this->assertSame('standard_from_mikro', $result['tax_mode']);
        $this->assertSame($source['gross_total'], $result['gross_total']);
        $this->assertSame($source['net_total'], $result['net_total']);
        $this->assertSame($source['vat_total'], $result['vat_total']);
    }

    public function test_correction_preserves_billing_and_shipping(): void
    {
        $fixture = $this->correctionFixture();
        $source = $this->service()->contextProjection($fixture['source']);
        $result = $this->correct($fixture)['context'];

        $this->assertSame($source['billing'], $result['billing']);
        $this->assertSame($source['shipping'], $result['shipping']);
        $this->assertSame($source['shipping_same_as_billing'], $result['shipping_same_as_billing']);
    }

    public function test_provider_attempt_fields_do_not_override_business_series(): void
    {
        $fixture = $this->correctionFixture();
        DB::table(TechnicalServicePaymentOrderContextService::TABLE)
            ->where('id', $fixture['source']->id)
            ->update(['payment_status_source' => 'provider']);

        $result = $this->correct($fixture)['context'];

        $this->assertSame('S', $result['desired_mikro_series']);
        $this->assertSame('system', $result['payment_status_source']);
        $this->assertSame('pending', $result['payment_status']);
    }

    public function test_latest_projection_uses_correction_revision(): void
    {
        $fixture = $this->correctionFixture();
        $created = $this->correct($fixture)['context'];
        $latest = $this->service()->latestPartContext($fixture['request']->fresh());

        $this->assertSame($created['id'], $latest['id']);
        $this->assertSame('S', $latest['desired_mikro_series']);
        $this->assertNotNull($latest['correction']);
    }

    public function test_old_q_revision_remains_history_not_active_authority(): void
    {
        $fixture = $this->correctionFixture();
        $latestId = (int) $fixture['latest']->id;

        $this->correct($fixture);

        $old = DB::table(TechnicalServicePaymentOrderContextService::TABLE)->where('id', $latestId)->firstOrFail();
        $this->assertSame('Q', $old->desired_mikro_series);
        $this->assertNotSame($latestId, $this->service()->latestPartContext($fixture['request'])['id']);
    }

    public function test_manual_events_remain_visible_after_correction(): void
    {
        $fixture = $this->correctionFixture();
        $eventIds = collect(['part_hand_delivery_recorded', 'part_hand_payment_status_changed'])
            ->map(fn (string $type): int => (int) $fixture['request']->events()->create([
                'event_type' => $type,
                'title' => 'Korunan manuel UAT olayı',
                'note' => 'Context '.$fixture['latest']->id,
                'from_status' => $fixture['request']->workflow_status,
                'to_status' => $fixture['request']->workflow_status,
                'author_user_id' => $fixture['actor']->id,
                'metadata' => ['order_context_id' => (int) $fixture['latest']->id],
            ])->id)
            ->all();

        $this->correct($fixture);

        $this->assertSame(2, DB::table('technical_service_request_events')->whereIn('id', $eventIds)->count());
        $this->assertSame(2, DB::table('technical_service_request_events')->whereIn('id', $eventIds)->where('title', 'Korunan manuel UAT olayı')->count());
    }

    public function test_history_uses_turkish_correction_label(): void
    {
        $fixture = $this->correctionFixture();
        $result = $this->correct($fixture);
        $event = DB::table('technical_service_request_events')
            ->where('technical_service_request_id', $fixture['request']->id)
            ->where('event_type', 'payment_order_context_corrected')
            ->firstOrFail();

        $this->assertSame(TechnicalServicePaymentOrderContextService::CORRECTION_HISTORY_LABEL, $event->title);
        $this->assertSame(TechnicalServicePaymentOrderContextService::CORRECTION_HISTORY_LABEL, $result['context']['correction']['history_label']);
    }

    public function test_correction_creates_no_payment(): void
    {
        $fixture = $this->correctionFixture();
        $before = DB::table('technical_service_mount_payments')->count();

        $this->correct($fixture);

        $this->assertSame($before, DB::table('technical_service_mount_payments')->count());
    }

    public function test_correction_calls_no_iyzico_provider(): void
    {
        $this->correct($this->correctionFixture());

        Http::assertNothingSent();
    }

    public function test_correction_creates_no_msim(): void
    {
        $fixture = $this->correctionFixture();
        $before = Schema::hasTable(TechnicalServicePaymentOrderContextService::MIKRO_SIMULATION_TABLE)
            ? DB::table(TechnicalServicePaymentOrderContextService::MIKRO_SIMULATION_TABLE)->count()
            : 0;

        $this->correct($fixture);

        $after = Schema::hasTable(TechnicalServicePaymentOrderContextService::MIKRO_SIMULATION_TABLE)
            ? DB::table(TechnicalServicePaymentOrderContextService::MIKRO_SIMULATION_TABLE)->count()
            : 0;
        $this->assertSame($before, $after);
    }

    public function test_correction_creates_no_receipt_intent(): void
    {
        $fixture = $this->correctionFixture();
        $before = DB::table('technical_service_message_dispatches')->count();

        $this->correct($fixture);

        $this->assertSame($before, DB::table('technical_service_message_dispatches')->count());
    }

    public function test_correction_sends_no_email_whatsapp_or_sms(): void
    {
        $this->correct($this->correctionFixture());

        Mail::assertNothingSent();
        $this->assertDatabaseCount('technical_service_message_dispatches', 0);
    }

    public function test_correction_performs_no_mikro_write(): void
    {
        $result = $this->correct($this->correctionFixture());
        $event = DB::table('technical_service_request_events')->where('event_type', 'payment_order_context_corrected')->firstOrFail();
        $metadata = json_decode((string) $event->metadata, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $result['context']['mikro_write_execution_count']);
        $this->assertSame(0, $metadata['mikro_write_count']);
        Http::assertNothingSent();
    }

    public function test_correction_uses_no_n8n_or_direct_mssql(): void
    {
        $this->correct($this->correctionFixture());

        $this->assertSame('pgsql', DB::connection()->getDriverName());
        Http::assertNothingSent();
    }

    public function test_correction_query_count_is_bounded(): void
    {
        $fixture = $this->correctionFixture();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->correct($fixture);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(12, count($queries));
    }

    /** @return array<string, mixed> */
    private function correctionFixture(): array
    {
        ['link' => $link] = TechnicalServiceQrLink::createPreSaleProductLink([
            'serial_number' => 'SN-CORRECTION-'.Str::uuid(),
            'product_name' => 'Akıllı Kilit',
            'product_model' => 'K1',
            'brand' => 'EMAKS',
        ]);
        ['session' => $session] = TechnicalServiceMountSession::startForLink($link);
        $request = TechnicalServiceRequest::query()->create([
            'mrn' => 'MRN-CORRECTION-'.Str::uuid(),
            'qr_link_id' => $link->id,
            'mount_session_id' => $session->id,
            'customer_name' => 'Düzeltme Müşterisi',
            'customer_phone' => '905551112233',
            'customer_city' => 'Denizli',
            'customer_district' => 'Pamukkale',
            'service_address' => 'Merkez No:21',
            'product_name' => 'Akıllı Kilit',
            'product_model' => 'K1',
            'serial_number' => 'PRODUCT-'.Str::uuid(),
            'service_type' => 'Montaj',
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_DAHIL,
            'status' => 'Yeni',
            'workflow_status' => 'Yeni Talep',
            'priority' => 'Orta',
            'risk_level' => 'Orta',
            'source_channel' => 'panel',
        ]);
        $actor = User::factory()->create(['role_code' => 'admin']);
        $items = collect($this->service()->searchParts($request, 'TS-PART')['items'])->keyBy('item_code');
        $lines = [
            [
                'stock_selection_token' => $items['TS-PART-001']['selection_token'],
                'quantity' => 1,
                'unit_price' => 1000,
            ],
            [
                'stock_selection_token' => $items['TS-PART-002']['selection_token'],
                'quantity' => 1,
                'unit_price' => 1000,
                'selected_part_serial' => 'TSP-2026-0001',
            ],
        ];
        $base = [
            'billing_source' => 'mrn_customer',
            'part_supplier' => 'emaks_prime',
            'commercial_mode' => 'paid',
            'lines' => $lines,
        ];
        $sourceInput = [
            ...$base,
            'delivery_mode' => 'shipment',
            'shipping_same_as_billing' => true,
        ];
        $sourcePreview = $this->service()->preview($request, 'part_charge', $sourceInput, 2000, 'TRY');
        $source = $this->service()->prepare($request, 'part_charge', [
            ...$sourceInput,
            'expected_context_hash' => $sourcePreview['context_hash'],
            'expected_revision' => $sourcePreview['revision'],
        ], 2000, 'TRY', $actor, false)['context'];

        $latestInput = [
            ...$base,
            'delivery_mode' => 'hand_delivery',
            'delivery_target' => 'mrn_customer',
        ];
        $latestPreview = $this->service()->preview($request, 'part_charge', $latestInput, 2000, 'TRY');
        $latest = $this->service()->prepare($request, 'part_charge', [
            ...$latestInput,
            'expected_context_hash' => $latestPreview['context_hash'],
            'expected_revision' => $latestPreview['revision'],
        ], 2000, 'TRY', $actor, false)['context'];

        return [
            'request' => $request,
            'actor' => $actor,
            'source' => $source,
            'latest' => $latest,
            'arguments' => [
                'request' => $request,
                'expectedLatestContextId' => (int) $latest->id,
                'expectedLatestRevision' => (int) $latest->revision,
                'expectedLatestHash' => (string) $latest->context_hash,
                'sourceContextId' => (int) $source->id,
                'sourceRevision' => (int) $source->revision,
                'sourceHash' => (string) $source->context_hash,
                'reason' => 'Başarısız Iyzico oluşturma denemesindeki context kimliği düzeltiliyor.',
                'actor' => $actor,
                'correlationId' => (string) Str::uuid(),
            ],
        ];
    }

    /** @param array<string, mixed> $fixture @return array{context:array<string, mixed>,created:bool} */
    private function correct(array $fixture): array
    {
        return $this->service()->createCorrectionRevision(...$fixture['arguments']);
    }

    /** @param array<string, mixed> $fixture @return array<string, mixed> */
    private function correctionPayload(array $fixture): array
    {
        $arguments = $fixture['arguments'];

        return [
            'expected_context_id' => $arguments['expectedLatestContextId'],
            'expected_revision' => $arguments['expectedLatestRevision'],
            'expected_hash' => $arguments['expectedLatestHash'],
            'source_context_id' => $arguments['sourceContextId'],
            'source_revision' => $arguments['sourceRevision'],
            'source_hash' => $arguments['sourceHash'],
            'reason' => $arguments['reason'],
        ];
    }

    /** @param array<string, mixed> $fixture */
    private function correctionUrl(array $fixture): string
    {
        return '/api/technical-service/requests/'.$fixture['request']->id.'/payment-order-context/corrections';
    }

    /** @param array<string, mixed> $fixture */
    private function contextCount(array $fixture): int
    {
        return DB::table(TechnicalServicePaymentOrderContextService::TABLE)
            ->where('technical_service_request_id', $fixture['request']->id)
            ->where('payment_purpose', TechnicalServicePaymentOrderContextService::PURPOSE_PART_CHARGE)
            ->count();
    }

    /** @param array<string, mixed> $fixture */
    private function itemCount(array $fixture): int
    {
        return DB::table(TechnicalServicePaymentOrderContextService::ITEM_TABLE)
            ->whereIn('context_id', DB::table(TechnicalServicePaymentOrderContextService::TABLE)
                ->where('technical_service_request_id', $fixture['request']->id)
                ->select('id'))
            ->count();
    }

    /** @param array<string, mixed> $fixture */
    private function correctionEventCount(array $fixture): int
    {
        return DB::table('technical_service_request_events')
            ->where('technical_service_request_id', $fixture['request']->id)
            ->where('event_type', 'payment_order_context_corrected')
            ->count();
    }

    private function service(): TechnicalServicePaymentOrderContextService
    {
        return app(TechnicalServicePaymentOrderContextService::class);
    }
}
