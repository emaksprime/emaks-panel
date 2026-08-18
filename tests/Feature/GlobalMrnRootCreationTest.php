<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServiceQrLink;
use App\Models\TechnicalServiceRequest;
use App\Models\User;
use App\Services\Messaging\TechnicalServiceMessageContextBuilder;
use App\Services\Messaging\TechnicalServiceWorkflowMessageDispatchService;
use App\Services\TechnicalService\MountRequestSubmitService;
use App\Services\TechnicalService\TechnicalServiceCodeGenerator;
use App\Services\TechnicalService\TechnicalServiceWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\TestCase;

class GlobalMrnRootCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('g', 32))]);
        $this->restartSequence();
        $this->mock(TechnicalServiceWorkflowMessageDispatchService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('queueWorkflowDispatches')->andReturn([]);
        });
    }

    public function test_public_form_and_ops_creation_use_same_generator(): void
    {
        $ops = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/requests', $this->opsPayload())
            ->assertCreated()
            ->json('request');
        $public = app(MountRequestSubmitService::class)->submit(
            $this->mountSession(),
            $this->publicPayload(),
        );

        $this->assertSame(1, $this->sequence((string) $ops['mrn']));
        $this->assertSame(2, $this->sequence($public->mrn));
    }

    public function test_all_root_creation_callers_use_global_sequence_owner(): void
    {
        $callers = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            if (is_string($source) && str_contains($source, '->nextMrn(')) {
                $callers[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        sort($callers);

        $this->assertSame([
            str_replace('\\', '/', app_path('Http/Controllers/Api/TechnicalServiceController.php')),
            str_replace('\\', '/', app_path('Services/TechnicalService/MountRequestSubmitService.php')),
        ], $callers);
        $this->assertStringContainsString(
            "nextval('technical_service_root_mrn_sequence'::regclass)",
            (string) file_get_contents(app_path('Services/TechnicalService/TechnicalServiceCodeGenerator.php')),
        );
    }

    public function test_duplicate_submit_returns_existing_root_mrn(): void
    {
        $session = $this->mountSession();
        $service = app(MountRequestSubmitService::class);

        $first = $service->submit($session, $this->publicPayload());
        $second = $service->submit($session->fresh(), $this->publicPayload());
        $nextMrn = app(TechnicalServiceCodeGenerator::class)->nextMrn('Next Customer');

        $this->assertTrue($first->is($second));
        $this->assertSame($first->mrn, $second->mrn);
        $this->assertSame(1, TechnicalServiceRequest::query()->where('mount_session_id', $session->id)->count());
        $this->assertSame(1, $first->events()->where('event_type', 'technical_service_request_created')->count());
        $this->assertSame(2, $this->sequence($nextMrn));
    }

    public function test_mrn_validation_accepts_five_or_more_digit_global_suffix(): void
    {
        $request = $this->longMrnRequest();

        $this->actingAs($this->admin())
            ->getJson('/api/technical-service/requests?search='.urlencode($request->mrn))
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);
    }

    public function test_search_and_serializers_preserve_full_long_mrn(): void
    {
        $request = $this->longMrnRequest();
        $serialized = app(TechnicalServiceWorkflowService::class)
            ->serialize($request->fresh(), false, false, false);

        $this->assertSame($request->mrn, $serialized['mrn']);
        $this->assertSame($request->mrn, $serialized['display_mrn']);

        $this->actingAs($this->admin())
            ->getJson('/api/technical-service/requests?search='.urlencode($request->mrn))
            ->assertOk()
            ->assertJsonPath('items.0.mrn', $request->mrn)
            ->assertJsonPath('items.0.display_mrn', $request->mrn);
    }

    public function test_qr_and_message_interpolation_do_not_truncate_long_mrn(): void
    {
        $this->restartSequence(10000);
        $request = app(MountRequestSubmitService::class)->submit(
            $this->mountSession(),
            $this->publicPayload(),
        );
        $message = app(TechnicalServiceMessageContextBuilder::class)->build(
            'mount_request_created_customer',
            'whatsapp',
            ['sample_context' => false, 'request' => $request->fresh()],
        );

        $this->assertSame(10000, $this->sequence($request->mrn));
        $this->assertStringEndsWith('10000', $request->mrn);
        $this->assertSame($request->mrn, $request->events()->firstOrFail()->metadata['mrn']);
        $this->assertSame($request->mrn, $message['context']['mrn']);
        $this->assertSame($request->mrn, $message['context']['request_code']);
    }

    private function admin(): User
    {
        return User::factory()->create(['role_code' => 'admin']);
    }

    /** @return array<string, mixed> */
    private function opsPayload(): array
    {
        return [
            'customer_name' => 'Ops Customer',
            'customer_phone' => '+905551112233',
            'customer_city' => 'Istanbul',
            'customer_district' => 'Kadikoy',
            'service_address' => 'Ops address',
            'product_name' => 'Ops Product',
            'serial_number' => 'REL4H-OPS-ROOT',
            'service_type' => 'Montaj',
            'source_channel' => 'panel',
        ];
    }

    /** @return array<string, mixed> */
    private function publicPayload(): array
    {
        return [
            'customer_name' => 'Public Customer',
            'customer_phone' => '+905551112233',
            'customer_city' => 'Istanbul',
            'customer_district' => 'Kadikoy',
            'service_address' => 'Public address',
            'location_latitude' => 41.0082,
            'location_longitude' => 28.9784,
            'location_formatted_address' => 'Public address, Istanbul',
            'location_source' => 'browser',
            'location_accuracy' => 'exact',
        ];
    }

    private function mountSession(): TechnicalServiceMountSession
    {
        ['link' => $link] = TechnicalServiceQrLink::createPreSaleProductLink([
            'serial_number' => 'REL4H-PUBLIC-ROOT-'.uniqid(),
            'product_name' => 'Public Product',
            'product_model' => 'REL4H',
            'brand' => 'EMAKS',
        ]);
        ['session' => $session] = TechnicalServiceMountSession::startForLink($link);

        $session->forceFill([
            'sale_mount_status' => TechnicalServiceMountSession::SALE_MONTAJ_DAHIL,
            'mount_payment_status' => TechnicalServiceMountSession::PAYMENT_NOT_REQUIRED,
            'customer_entry_mode' => TechnicalServiceMountSession::ENTRY_INCLUDED_MOUNT,
            'decision_status' => TechnicalServiceMountSession::DECISION_FORM_OPEN,
            'context_payload' => [
                ...(array) $session->context_payload,
                'stock_code' => 'REL4H-PUBLIC',
                'current_serial_state' => 'sold_current',
                'has_current_sale' => true,
            ],
        ])->save();

        return $session->fresh();
    }

    private function longMrnRequest(): TechnicalServiceRequest
    {
        return TechnicalServiceRequest::query()->create([
            'mrn' => 'MRN-2606MP0310000',
            'customer_name' => 'Long MRN Customer',
            'customer_phone' => '+905551112233',
            'customer_city' => 'Istanbul',
            'customer_district' => 'Kadikoy',
            'service_address' => 'Long MRN address',
            'product_name' => 'Long MRN Product',
            'serial_number' => 'REL4H-LONG-ROOT',
            'service_type' => 'Montaj',
            'status' => TechnicalServiceRequest::STATUS_NEW,
            'workflow_status' => TechnicalServiceRequest::WORKFLOW_NEW_REQUEST,
            'priority' => TechnicalServiceRequest::PRIORITY_MEDIUM,
            'risk_level' => TechnicalServiceRequest::RISK_MEDIUM,
            'source_channel' => 'panel',
        ]);
    }

    private function restartSequence(int $nextValue = 1): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        DB::selectOne(
            "select setval('technical_service_root_mrn_sequence'::regclass, cast(? as bigint), false)",
            [$nextValue],
        );
    }

    private function sequence(string $mrn): int
    {
        $matched = preg_match('/^MRN-\d{4}[A-Z]{2}\d{2}(\d{4,})$/D', $mrn, $matches);
        $this->assertSame(1, $matched, 'Unexpected MRN format: '.$mrn);

        return (int) $matches[1];
    }
}
