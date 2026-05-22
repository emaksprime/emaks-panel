<?php

namespace App\Services\Messaging;

use App\Models\TechnicalServiceAssignmentOffer;
use App\Models\TechnicalServiceEarning;
use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServicePartnerJobAction;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceTechnician;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Throwable;

class EvolutionWhatsAppMessageService
{
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
            'context' => $context,
            'metadata' => [
                'source' => 'emaks_panel',
                'provider_router' => 'n8n_evolution',
                'contract' => 'EMAKS_Evo_WhatsApp_HTTPExact_AllMessages',
            ],
        ];

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
            'job_link' => $this->firstFilled($flat['job_link'] ?? null, $request ? url('/partner/service-jobs?job_id='.$request->id) : null),
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
        ];
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
            return \Carbon\Carbon::parse($text)->format('d.m.Y');
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
