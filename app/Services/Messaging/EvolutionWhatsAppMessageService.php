<?php

namespace App\Services\Messaging;

use App\Models\TechnicalServiceAssignmentOffer;
use App\Models\TechnicalServiceEarning;
use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServicePartnerJobAction;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use App\Support\PartnerPortalPublicUrl;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Throwable;

class EvolutionWhatsAppMessageService
{
    private const TEST_FIXTURE_MRN_PREFIXES = [
        'MRN-TEST',
        'MRN-ACTION',
        'MRN-PR88',
        'ACCEPT-',
        'FAZ2A-ASSIGN-',
        'TEST-',
        'SMOKE-',
    ];

    /**
     * @param  array<string, mixed>  $context
     */
    public function send(
        string $event,
        string $targetType,
        ?string $targetPhone,
        string $messageText,
        array $context = [],
        ?TechnicalServiceRequest $request = null,
        ?User $user = null,
        ?TechnicalServicePartnerJobAction $partnerJobAction = null,
        ?TechnicalServiceAssignmentOffer $assignmentOffer = null,
        ?TechnicalServiceEarning $earning = null,
    ): TechnicalServiceMessageDispatch {
        $testMode = $this->testMode();
        $originalPhone = $this->normalizePhone($targetPhone);
        $resolvedPhone = $testMode
            ? $this->normalizePhone((string) config('services.evolution.test_phone', '905467647428'))
            : $originalPhone;

        $workflowPayload = $this->workflowPayload(
            $event,
            $targetType,
            $messageText,
            $context,
            $request,
            $resolvedPhone,
            $originalPhone,
            $testMode,
        );
        $messageText = $this->messageTextWithJobLink(
            $messageText,
            $targetType,
            $workflowPayload['job_link'] ?? null,
        );
        $workflowPayload['text'] = $messageText;
        $workflowPayload['message_text'] = $messageText;

        $payload = [
            ...$workflowPayload,
            'event' => $event,
            'target_type' => $targetType,
            'target_phone' => $resolvedPhone,
            'original_phone' => $originalPhone,
            'test_mode' => $testMode,
            'mrn' => $request?->mrn,
            'technical_service_request_id' => $request?->id,
            'text' => $messageText,
            'message_text' => $messageText,
            'message_type' => (string) ($context['message_type'] ?? $event),
            'force_resend' => (bool) ($context['force_resend'] ?? false),
            'context' => $context,
            'metadata' => [
                'source' => 'emaks_panel',
                'provider_router' => 'n8n_evolution',
                'contract' => 'EMAKS_Evo_WhatsApp_HTTPExact_AllMessages',
            ],
        ];
        $payloadHash = $this->payloadHash($payload);
        $idempotencyKey = $this->idempotencyKey(
            $event,
            $targetType,
            $resolvedPhone,
            $payloadHash,
            $request,
            $assignmentOffer,
            $earning,
        );
        $payload['payload_hash'] = $payloadHash;
        $payload['idempotency_key'] = $idempotencyKey;

        $dispatch = TechnicalServiceMessageDispatch::query()->create([
            'event' => $event,
            'technical_service_request_id' => $request?->id,
            'technical_service_partner_job_action_id' => $partnerJobAction?->id,
            'technical_service_assignment_offer_id' => $assignmentOffer?->id,
            'technical_service_earning_id' => $earning?->id,
            'target_type' => $targetType,
            'original_phone' => $originalPhone,
            'target_phone' => $resolvedPhone,
            'test_mode' => $testMode,
            'status' => TechnicalServiceMessageDispatch::STATUS_NOT_CONFIGURED,
            'request_payload' => $payload,
            'sent_by' => $user?->id,
        ]);

        $suppression = $this->suppressionStatus($event, $targetType, $resolvedPhone, $request, $context);
        if ($suppression !== null) {
            return $this->markSuppressed($dispatch, $suppression['status'], $suppression['message']);
        }

        if (! $this->manualForceResend($context)) {
            $duplicate = $this->recentSentDuplicate($dispatch, $idempotencyKey, $resolvedPhone);
            if ($duplicate instanceof TechnicalServiceMessageDispatch) {
                return $this->markSuppressed(
                    $dispatch,
                    TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_DUPLICATE,
                    'Bu mesaj son 30 dakika içinde gönderildi.',
                    ['duplicate_dispatch_id' => $duplicate->id],
                );
            }
        }

        $rateLimit = $this->rateLimitStatus($resolvedPhone, $testMode);
        if ($rateLimit !== null) {
            return $this->markSuppressed(
                $dispatch,
                TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_RATE_LIMITED,
                $rateLimit['message'],
                $rateLimit['context'],
            );
        }

        $url = trim((string) config('services.evolution.n8n_webhook_url', ''));
        if ($url === '' || $resolvedPhone === '') {
            return $dispatch;
        }

        try {
            $response = Http::timeout(10)->post($url, $payload);
            $dispatch->forceFill([
                'status' => $response->successful()
                    ? TechnicalServiceMessageDispatch::STATUS_SENT
                    : TechnicalServiceMessageDispatch::STATUS_FAILED,
                'response_payload' => [
                    'status' => $response->status(),
                    'body' => $response->json() ?? $response->body(),
                ],
                'error_message' => $response->successful() ? null : mb_substr($response->body(), 0, 1000),
                'sent_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $dispatch->forceFill([
                'status' => TechnicalServiceMessageDispatch::STATUS_FAILED,
                'error_message' => $exception->getMessage(),
                'sent_at' => now(),
            ])->save();
        }

        return $dispatch;
    }

    public function testMode(): bool
    {
        return filter_var(config('services.evolution.test_mode', true), FILTER_VALIDATE_BOOL);
    }

    public function realSendEnabled(): bool
    {
        return filter_var(config('services.evolution.real_send_enabled', false), FILTER_VALIDATE_BOOL);
    }

    public function normalizePhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?: '';

        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '0')) {
            return '90'.substr($digits, 1);
        }

        if (strlen($digits) === 10) {
            return '90'.$digits;
        }

        return $digits;
    }

    /**
     * n8n workflow `EMAKS_Evo_WhatsApp_HTTPExact_AllMessages` expects message
     * variables at the top level, not only under `context`.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function workflowPayload(
        string $event,
        string $targetType,
        string $messageText,
        array $context,
        ?TechnicalServiceRequest $request,
        string $resolvedPhone,
        string $originalPhone,
        bool $testMode,
    ): array {
        $flat = $this->flatContext($context);
        $technician = $request?->technicianRecord;
        $address = $this->firstFilled(
            $flat['address'] ?? null,
            $flat['customer_address'] ?? null,
            $request?->location_formatted_address,
            $request?->service_address,
        );
        $appointmentDate = $this->firstFilled(
            $flat['appointment_date'] ?? null,
            $request?->scheduled_at,
            $request?->scheduled_date,
        );
        $currency = $this->firstFilled($flat['currency'] ?? null, 'TRY');

        return [
            'event' => $event,
            'target_type' => $targetType,
            'target_phone' => $resolvedPhone,
            'original_phone' => $originalPhone,
            'test_mode' => $testMode,
            'mrn' => $this->firstFilled($flat['mrn'] ?? null, $flat['request_mrn'] ?? null, $request?->mrn),
            'customer_name' => $this->firstFilled($flat['customer_name'] ?? null, $flat['customer'] ?? null, $request?->customer_name),
            'customer_phone' => $this->firstFilled($flat['customer_phone'] ?? null, $flat['phone'] ?? null, $request?->customer_phone),
            'technician_name' => $this->firstFilled($flat['technician_name'] ?? null, $flat['technician'] ?? null, $technician?->name),
            'technician_phone' => $this->firstFilled($flat['technician_phone'] ?? null, $this->technicianPhone($technician)),
            'ops_phone' => $this->firstFilled($flat['ops_phone'] ?? null),
            'product_name' => $this->firstFilled($flat['product_name'] ?? null, $flat['product'] ?? null, $request?->product_name),
            'model' => $this->firstFilled($flat['model'] ?? null, $request?->product_model),
            'serial_no' => $this->firstFilled($flat['serial_no'] ?? null, $request?->serial_number),
            'appointment_date' => $this->dateForWorkflow($appointmentDate),
            'appointment_time_range' => $this->firstFilled(
                $flat['appointment_time_range'] ?? null,
                $flat['time_range'] ?? null,
                $flat['appointment_time'] ?? null,
                $request?->scheduled_time,
            ),
            'appointment_slot_text' => $this->firstFilled($flat['appointment_slot_text'] ?? null),
            'address' => $address,
            'maps_url' => $this->firstFilled(
                $flat['maps_url'] ?? null,
                $flat['map_url'] ?? null,
                $flat['google_maps_url'] ?? null,
                $flat['maps_link'] ?? null,
                $this->mapsLink($request, $address),
            ),
            'confirmation_url' => $this->firstFilled(
                $flat['confirmation_url'] ?? null,
                $flat['approval_url'] ?? null,
                $flat['public_url'] ?? null,
            ),
            'approval_url' => $this->firstFilled($flat['approval_url'] ?? null, $flat['confirmation_url'] ?? null),
            'job_link' => $this->firstFilled($flat['job_link'] ?? null, $request ? PartnerPortalPublicUrl::url('/partner/service-jobs?job_id='.$request->id) : null),
            'labor_amount' => $this->moneyForWorkflow($flat['labor_amount'] ?? null, $currency),
            'route_fee_amount' => $this->moneyForWorkflow($flat['route_fee_amount'] ?? null, $currency),
            'total_amount' => $this->moneyForWorkflow($flat['total_amount'] ?? null, $currency),
            'currency' => $currency,
            'request_type' => $this->firstFilled($flat['request_type'] ?? null, $flat['type'] ?? null),
            'part_name' => $this->firstFilled($flat['part_name'] ?? null, $flat['product_name'] ?? null),
            'quantity' => $this->firstFilled($flat['quantity'] ?? null),
            'slots_text' => $this->firstFilled($flat['slots_text'] ?? null),
            'reason' => $this->firstFilled($flat['reason'] ?? null),
            'note' => $this->firstFilled($flat['note'] ?? null),
            'text' => $messageText,
            'message_text' => $messageText,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{status:string,message:string}|null
     */
    private function suppressionStatus(
        string $event,
        string $targetType,
        string $resolvedPhone,
        ?TechnicalServiceRequest $request,
        array $context,
    ): ?array {
        $mrn = (string) ($request?->mrn ?? ($context['mrn'] ?? ''));
        if (filter_var($context['prepare_only'] ?? false, FILTER_VALIDATE_BOOL)) {
            return [
                'status' => TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_REAL_SEND_DISABLED,
                'message' => 'Mesaj taslağı hazırlandı; gerçek WhatsApp gönderilmedi.',
            ];
        }

        if (app()->runningUnitTests() && ! $this->allowUnitTestHttpFake($context)) {
            return [
                'status' => TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_TESTING_ENVIRONMENT,
                'message' => 'PHPUnit/test ortamında gerçek WhatsApp gönderimi engellendi.',
            ];
        }

        if ($this->isTestFixtureMrn($mrn) && ! filter_var(config('services.evolution.allow_test_fixture_send', false), FILTER_VALIDATE_BOOL)) {
            return [
                'status' => TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_TEST_FIXTURE,
                'message' => 'Test fixture MRN için gerçek WhatsApp gönderimi engellendi.',
            ];
        }

        if (app()->environment('testing') && ! $this->realSendEnabled() && ! $this->allowUnitTestHttpFake($context)) {
            return [
                'status' => TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_TESTING_ENVIRONMENT,
                'message' => 'PHPUnit/test ortamında gerçek WhatsApp gönderimi engellendi.',
            ];
        }

        if ($this->isCiEnvironment() && ! $this->allowUnitTestHttpFake($context)) {
            return [
                'status' => TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_TESTING_ENVIRONMENT,
                'message' => 'CI/test ortamında gerçek WhatsApp gönderimi engellendi.',
            ];
        }

        if (($context['browser_smoke'] ?? false) && ! filter_var(config('services.evolution.allow_browser_smoke_send', false), FILTER_VALIDATE_BOOL)) {
            return [
                'status' => TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_REAL_SEND_DISABLED,
                'message' => 'Browser smoke için gerçek WhatsApp gönderimi engellendi.',
            ];
        }

        if ($this->allowsTemplateProviderTest($event, $targetType, $resolvedPhone, $context)) {
            return null;
        }

        if (! $this->realSendEnabled()) {
            return [
                'status' => TechnicalServiceMessageDispatch::STATUS_SUPPRESSED_REAL_SEND_DISABLED,
                'message' => 'Gerçek WhatsApp gönderimi kapalı. EVOLUTION_REAL_SEND_ENABLED=true olmalı.',
            ];
        }

        return null;
    }

    private function isTestFixtureMrn(string $mrn): bool
    {
        foreach (self::TEST_FIXTURE_MRN_PREFIXES as $prefix) {
            if (str_starts_with($mrn, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function allowsTemplateProviderTest(string $event, string $targetType, string $resolvedPhone, array $context): bool
    {
        if ($event !== 'template_test_whatsapp' || $targetType !== 'shared_test_phone') {
            return false;
        }

        if (! filter_var($context['provider_test'] ?? false, FILTER_VALIDATE_BOOL)
            || ! filter_var($context['manual_ui_send'] ?? false, FILTER_VALIDATE_BOOL)) {
            return false;
        }

        $testPhone = $this->normalizePhone((string) config('services.evolution.test_phone', ''));
        $webhookUrl = trim((string) config('services.evolution.n8n_webhook_url', ''));

        return $testPhone !== '' && $resolvedPhone === $testPhone && $webhookUrl !== '';
    }

    /**
     * @param  array<string, mixed>  $extraResponse
     */
    private function markSuppressed(
        TechnicalServiceMessageDispatch $dispatch,
        string $status,
        string $message,
        array $extraResponse = [],
    ): TechnicalServiceMessageDispatch {
        $dispatch->forceFill([
            'status' => $status,
            'response_payload' => [
                'status' => 'suppressed',
                'message' => $message,
                ...$extraResponse,
            ],
            'error_message' => $message,
        ])->save();

        return $dispatch;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function allowUnitTestHttpFake(array $context): bool
    {
        if (! app()->runningUnitTests()) {
            return false;
        }

        if (! filter_var(config('services.evolution.allow_unit_test_http_fake', false), FILTER_VALIDATE_BOOL)
            && ! filter_var($context['allow_unit_test_http_fake'] ?? false, FILTER_VALIDATE_BOOL)) {
            return false;
        }

        return filter_var($context['manual_ui_send'] ?? false, FILTER_VALIDATE_BOOL);
    }

    private function isCiEnvironment(): bool
    {
        return filter_var(env('CI', false), FILTER_VALIDATE_BOOL)
            || filter_var(env('GITHUB_ACTIONS', false), FILTER_VALIDATE_BOOL);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function manualForceResend(array $context): bool
    {
        return filter_var($context['force_resend'] ?? false, FILTER_VALIDATE_BOOL)
            && filter_var($context['manual_ui_send'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadHash(array $payload): string
    {
        return sha1($this->stableJson([
            'event' => $payload['event'] ?? null,
            'target_type' => $payload['target_type'] ?? null,
            'target_phone' => $payload['target_phone'] ?? null,
            'message_type' => $payload['message_type'] ?? null,
            'message_text' => $payload['message_text'] ?? null,
            'confirmation_url' => $payload['confirmation_url'] ?? null,
            'approval_url' => $payload['approval_url'] ?? null,
            'job_link' => $payload['job_link'] ?? null,
        ]));
    }

    private function idempotencyKey(
        string $event,
        string $targetType,
        string $resolvedPhone,
        string $payloadHash,
        ?TechnicalServiceRequest $request,
        ?TechnicalServiceAssignmentOffer $assignmentOffer,
        ?TechnicalServiceEarning $earning,
    ): string {
        return sha1(implode('|', [
            $event,
            $targetType,
            $resolvedPhone,
            (string) ($request?->id ?? 'no-request'),
            (string) ($assignmentOffer?->id ?? 'no-offer'),
            (string) ($earning?->id ?? 'no-earning'),
            $payloadHash,
        ]));
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function stableJson(array $value): string
    {
        ksort($value);

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array{message:string,context:array<string, mixed>}|null
     */
    private function rateLimitStatus(string $resolvedPhone, bool $testMode): ?array
    {
        if ($resolvedPhone === '') {
            return null;
        }

        $testPhone = $this->normalizePhone((string) config('services.evolution.test_phone', '905467647428'));
        $isTestPhone = $testMode || ($testPhone !== '' && $resolvedPhone === $testPhone);
        $minSeconds = $isTestPhone
            ? max(0, (int) config('services.evolution.test_phone_min_seconds', 20))
            : max(0, (int) config('services.evolution.target_min_seconds', 5));

        if ($minSeconds > 0) {
            $latest = TechnicalServiceMessageDispatch::query()
                ->where('target_phone', $resolvedPhone)
                ->where('status', TechnicalServiceMessageDispatch::STATUS_SENT)
                ->latest('id')
                ->first();

            if ($latest instanceof TechnicalServiceMessageDispatch && $latest->created_at?->gt(now()->subSeconds($minSeconds))) {
                return [
                    'message' => "WhatsApp gönderimi hız limiti nedeniyle engellendi. Aynı hedefe {$minSeconds} saniye içinde tekrar gönderilemez.",
                    'context' => [
                        'rate_limit' => 'min_seconds',
                        'target_phone' => $resolvedPhone,
                        'min_seconds' => $minSeconds,
                        'latest_dispatch_id' => $latest->id,
                    ],
                ];
            }
        }

        if (! $isTestPhone) {
            return null;
        }

        $windowMinutes = max(1, (int) config('services.evolution.test_phone_window_minutes', 10));
        $windowMax = max(1, (int) config('services.evolution.test_phone_window_max', 5));
        $windowCount = TechnicalServiceMessageDispatch::query()
            ->where('target_phone', $resolvedPhone)
            ->where('status', TechnicalServiceMessageDispatch::STATUS_SENT)
            ->where('created_at', '>=', now()->subMinutes($windowMinutes))
            ->count();

        if ($windowCount >= $windowMax) {
            return [
                'message' => "WhatsApp test telefonu için {$windowMinutes} dakikada en fazla {$windowMax} gönderim yapılabilir.",
                'context' => [
                    'rate_limit' => 'test_phone_window',
                    'target_phone' => $resolvedPhone,
                    'window_minutes' => $windowMinutes,
                    'window_max' => $windowMax,
                    'sent_count' => $windowCount,
                ],
            ];
        }

        $dailyMax = max(1, (int) config('services.evolution.test_phone_daily_max', 20));
        $dailyCount = TechnicalServiceMessageDispatch::query()
            ->where('target_phone', $resolvedPhone)
            ->where('status', TechnicalServiceMessageDispatch::STATUS_SENT)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        if ($dailyCount >= $dailyMax) {
            return [
                'message' => "WhatsApp test telefonu için günlük en fazla {$dailyMax} gönderim yapılabilir.",
                'context' => [
                    'rate_limit' => 'test_phone_daily',
                    'target_phone' => $resolvedPhone,
                    'daily_max' => $dailyMax,
                    'sent_count' => $dailyCount,
                ],
            ];
        }

        return null;
    }

    private function recentSentDuplicate(
        TechnicalServiceMessageDispatch $current,
        string $idempotencyKey,
        string $resolvedPhone,
    ): ?TechnicalServiceMessageDispatch {
        if ($resolvedPhone === '' || $idempotencyKey === '') {
            return null;
        }

        $minutes = max(1, (int) config('services.evolution.idempotency_window_minutes', 30));

        return TechnicalServiceMessageDispatch::query()
            ->where('id', '<>', $current->id)
            ->where('target_phone', $resolvedPhone)
            ->where('status', TechnicalServiceMessageDispatch::STATUS_SENT)
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->latest('id')
            ->get()
            ->first(fn (TechnicalServiceMessageDispatch $dispatch): bool => (string) data_get($dispatch->request_payload, 'idempotency_key') === $idempotencyKey);
    }

    private function messageTextWithJobLink(string $messageText, string $targetType, mixed $jobLink): string
    {
        $link = trim((string) $jobLink);
        if ($link === '' || ! in_array($targetType, ['technician', 'ops'], true) || str_contains($messageText, $link)) {
            return $messageText;
        }

        return rtrim($messageText)."\n\nİş linki:\n".$link;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function flatContext(array $context): array
    {
        $nested = [];
        foreach (['message_payload', 'payload'] as $key) {
            if (isset($context[$key]) && is_array($context[$key])) {
                $nested = [...$nested, ...$context[$key]];
            }
        }

        return [...$nested, ...$context];
    }

    private function technicianPhone(?TechnicalServiceTechnician $technician): ?string
    {
        if (! $technician instanceof TechnicalServiceTechnician) {
            return null;
        }

        return $this->firstFilled($technician->phone_e164, $technician->phone_display, $technician->phone);
    }

    private function mapsLink(?TechnicalServiceRequest $request, ?string $address): ?string
    {
        if (! $request instanceof TechnicalServiceRequest) {
            return null;
        }

        if ($request->location_latitude !== null && $request->location_longitude !== null) {
            return 'https://www.google.com/maps/search/?api=1&query='
                .rawurlencode((string) $request->location_latitude.','.(string) $request->location_longitude);
        }

        $query = trim((string) $address);

        return $query === '' ? null : 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($query);
    }

    private function dateForWorkflow(mixed $value): string
    {
        if ($value instanceof CarbonInterface) {
            return $value->format('d.m.Y');
        }

        $text = $this->firstFilled($value);
        if ($text === '') {
            return '';
        }

        try {
            return Carbon::parse($text)->format('d.m.Y');
        } catch (Throwable) {
            return $text;
        }
    }

    private function moneyForWorkflow(mixed $value, string $currency): string
    {
        $text = $this->firstFilled($value);
        if ($text === '') {
            return '';
        }

        if (! is_numeric($text)) {
            return $text;
        }

        $amount = number_format((float) $text, 2, ',', '.');
        $amount = preg_replace('/,00$/', '', $amount) ?: $amount;

        return trim($amount.' '.$currency);
    }

    private function firstFilled(mixed ...$values): string
    {
        foreach ($values as $value) {
            if ($value instanceof CarbonInterface) {
                return $value->toIso8601String();
            }

            if ($value !== null && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }
}
