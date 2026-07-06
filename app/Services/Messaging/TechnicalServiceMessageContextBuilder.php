<?php

namespace App\Services\Messaging;

use App\Models\TechnicalServiceRequest;
use App\Services\TechnicalService\TechnicalServicePaymentOwnershipService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

class TechnicalServiceMessageContextBuilder
{
    public function __construct(
        private readonly TechnicalServiceMessageVariableRegistry $variables,
        private readonly TechnicalServicePaymentOwnershipService $paymentOwnership,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function build(string $messageType, string $channel, array $input = []): array
    {
        $context = (bool) ($input['sample_context'] ?? true)
            ? $this->variables->sampleContext($messageType, $channel)
            : [];
        $context['__sample_context'] = (bool) ($input['sample_context'] ?? true);

        $request = $this->requestFromInput($input);
        if ($request instanceof TechnicalServiceRequest) {
            $context = [
                ...$context,
                ...$this->contextFromRequest($request),
            ];
        }

        $overrides = is_array($input['context'] ?? null) ? $input['context'] : [];
        $context = [
            ...$context,
            ...$this->normalizeOverrides($overrides),
        ];
        $context = $this->clearSampleDerivedValues($context, $overrides);

        $amount = $this->money($context['customer_payment_amount'] ?? null);
        if ($amount > 0.0 && empty($context['customer_payment_amount_formatted'])) {
            $context['customer_payment_amount_formatted'] = $this->formatTry($amount);
        }

        $context = $this->withDerivedContext($context);

        return [
            'context' => $context,
            'request_id' => $request?->id,
            'payer_state_key' => (string) ($context['payer_state_key'] ?? 'sample'),
            'recipient_role' => $this->recipientRole($messageType),
            'recipient_phone' => $this->recipientPhone($messageType, $context),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function clearSampleDerivedValues(array $context, array $overrides): array
    {
        if (! filter_var($context['__sample_context'] ?? false, FILTER_VALIDATE_BOOL)) {
            return $context;
        }

        foreach ([
            'appointment_customer_window',
            'appointment_customer_window_label',
            'appointment_window_for_customer',
            'appointment_window_for_technician',
            'appointment_exact_time_range',
            'appointment_start_time',
            'appointment_end_time',
            'appointment_slot_label',
            'appointment_time_range',
            'appointment_assignment_timing_text',
            'customer_job_type_label',
            'customer_reference_code',
            'customer_reference_phrase',
            'customer_appointment_action_phrase',
            'customer_update_action_phrase',
            'customer_record_created_phrase',
            'customer_hidden_internal_references',
            'srv_line',
            'product_line',
            'serial_line',
            'serial_no_line',
            'maps_url_line',
            'sms_short_address',
            'customer_visible_note_line',
            'technician_visible_note_line',
            'customer_visible_note_block',
            'technician_visible_note_block',
            'payment_instruction_text',
            'payment_instruction_block',
            'short_payment_instruction',
            'sms_payment_line',
            'technician_payment_context',
            'internal_job_reference',
        ] as $key) {
            if (! array_key_exists($key, $overrides)) {
                unset($context[$key]);
            }
        }

        return $context;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function requestFromInput(array $input): ?TechnicalServiceRequest
    {
        $id = (int) ($input['technical_service_request_id'] ?? $input['request_id'] ?? 0);

        if ($id <= 0) {
            return null;
        }

        return TechnicalServiceRequest::query()
            ->with(['settlement', 'technicianRecord'])
            ->find($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function contextFromRequest(TechnicalServiceRequest $request): array
    {
        $ownership = $this->paymentOwnership->summary($request, $request->settlement);
        $appointmentDate = $request->scheduled_date?->format('d.m.Y') ?: $request->scheduled_at?->format('d.m.Y');
        $appointmentTime = $request->scheduled_time ?: $request->scheduled_at?->format('H:i');
        $amount = $this->money($ownership['active_customer_direct_to_technician_amount'] ?? 0);
        $laborAmount = $this->money($request->technician_payment_amount);
        $routeAmount = $this->money($request->travel_fee_amount);
        $totalAmount = round($laborAmount + $routeAmount, 2);
        $mapsUrl = $request->location_map_url ?: $this->mapsLink($request);

        return [
            'customer_name' => $request->customer_name,
            'customer_phone' => $this->normalizePhone($request->customer_phone),
            'request_code' => $request->mrn ?: $request->service_code,
            'mrn' => $request->mrn,
            'srv' => $request->service_code,
            'serial_no' => $request->serial_number,
            'product_name' => $request->product_name,
            'brand' => $request->brand,
            'model' => $request->product_model,
            'appointment_date' => $appointmentDate,
            'appointment_time' => $appointmentTime,
            'appointment_datetime' => trim((string) $appointmentDate.' '.(string) $appointmentTime),
            'technician_name' => $request->technicianRecord?->name ?: $request->technician_name,
            'technician_phone' => $this->normalizePhone($request->technicianRecord?->phone ?? null),
            'city' => $request->customer_city,
            'district' => $request->customer_district,
            'address' => $request->location_formatted_address ?: $request->service_address,
            'maps_url' => $mapsUrl,
            'company_name' => 'EMAKS',
            'customer_payment_amount' => $amount,
            'customer_payment_amount_formatted' => $amount > 0.0 ? $this->formatTry($amount) : null,
            'payer_state_key' => $ownership['payer_state_key'] ?? null,
            'payer_state_label' => $ownership['payer_state_label'] ?? null,
            'payment_instruction_for_customer' => $ownership['payment_instruction_for_customer'] ?? null,
            'technician_job_card_url' => null,
            'labor_amount_formatted' => $laborAmount > 0.0 ? $this->formatTry($laborAmount) : null,
            'route_fee_formatted' => $routeAmount > 0.0 ? $this->formatTry($routeAmount) : null,
            'technician_earning_total_formatted' => $totalAmount > 0.0 ? $this->formatTry($totalAmount) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function normalizeOverrides(array $overrides): array
    {
        return collect($overrides)
            ->mapWithKeys(fn (mixed $value, mixed $key): array => [(string) $key => is_string($value) ? trim($value) : $value])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function withDerivedContext(array $context): array
    {
        $window = $this->appointmentWindow(
            $context['appointment_date_formatted'] ?? $context['appointment_date'] ?? null,
            $this->appointmentTimeInput($context),
            $context['appointment_slot_text'] ?? null,
        );

        $context['appointment_date_formatted'] = $this->filledString($context['appointment_date_formatted'] ?? null) ?: $window['date'];
        $context['appointment_time_range'] = $this->filledString($context['appointment_time_range'] ?? null) ?: $window['time_range'];
        $context['appointment_start_time'] = $this->filledString($context['appointment_start_time'] ?? null) ?: $window['start_time'];
        $context['appointment_end_time'] = $this->filledString($context['appointment_end_time'] ?? null) ?: $window['end_time'];
        $context['appointment_exact_time_range'] = $this->filledString($context['appointment_exact_time_range'] ?? null) ?: $window['exact_time_range'];
        $context['appointment_slot_label'] = $this->filledString($context['appointment_slot_label'] ?? null) ?: $window['slot_label'];
        $context['appointment_customer_window'] = $this->filledString($context['appointment_customer_window'] ?? null) ?: $window['customer_window'];
        $context['appointment_customer_window_label'] = $this->filledString($context['appointment_customer_window_label'] ?? null) ?: $window['customer_window_label'];
        $context['appointment_window_for_customer'] = $this->filledString($context['appointment_window_for_customer'] ?? null) ?: $context['appointment_customer_window'];
        $context['appointment_window_for_technician'] = $this->filledString($context['appointment_window_for_technician'] ?? null)
            ?: $window['technician_window'];
        $context['appointment_assignment_timing_text'] = $this->filledString($context['appointment_assignment_timing_text'] ?? null)
            ?: ($context['appointment_exact_time_range'] !== ''
                ? trim((string) $context['appointment_date_formatted'].' '.(string) $context['appointment_exact_time_range'])
                : 'Randevu saati OPS onayı sonrası netleşecektir.');

        $reference = $this->customerReference($context);
        $context['customer_job_type_label'] = $this->filledString($context['customer_job_type_label'] ?? null) ?: $reference['job_type_label'];
        $context['customer_reference_code'] = $this->filledString($context['customer_reference_code'] ?? null) ?: $reference['reference_code'];
        $context['customer_reference_phrase'] = $this->filledString($context['customer_reference_phrase'] ?? null) ?: $reference['reference_phrase'];
        $context['customer_appointment_action_phrase'] = $this->filledString($context['customer_appointment_action_phrase'] ?? null) ?: $reference['appointment_action_phrase'];
        $context['customer_update_action_phrase'] = $this->filledString($context['customer_update_action_phrase'] ?? null) ?: $reference['update_action_phrase'];
        $context['customer_record_created_phrase'] = $this->filledString($context['customer_record_created_phrase'] ?? null) ?: $reference['record_created_phrase'];
        $context['customer_hidden_internal_references'] = $reference['hidden_internal_references'];

        $context['srv_line'] = $this->line('SRV', $context['srv'] ?? null);
        $context['product_line'] = $this->line('Ürün', $context['product_name'] ?? null);
        $context['serial_line'] = $this->line('Seri No', $context['serial_no'] ?? null);
        $context['serial_no_line'] = $this->line('Seri No', $context['serial_no'] ?? null);
        $context['maps_url_line'] = $this->line('Harita', $context['maps_url'] ?? null);
        $context['sms_short_address'] = $this->smsShortAddress($context);
        $context['customer_visible_note_line'] = $this->line('Not', $context['customer_visible_note'] ?? null);
        $context['technician_visible_note_line'] = $this->line('Not', $context['technician_visible_note'] ?? null);
        $context['customer_visible_note_block'] = $this->block('Not', $context['customer_visible_note'] ?? null);
        $context['technician_visible_note_block'] = $this->block('Not', $context['technician_visible_note'] ?? null);

        $payment = $this->paymentInstructions($context);
        $context['payment_instruction_text'] = $this->filledString($context['payment_instruction_text'] ?? null) ?: $payment['payment_instruction_text'];
        $context['payment_instruction_block'] = $this->filledString($context['payment_instruction_block'] ?? null) ?: $payment['payment_instruction_block'];
        $context['short_payment_instruction'] = $this->filledString($context['short_payment_instruction'] ?? null) ?: $payment['short_payment_instruction'];
        $context['sms_payment_line'] = $this->filledString($context['sms_payment_line'] ?? null) ?: $payment['sms_payment_line'];
        $context['technician_payment_context'] = $this->filledString($context['technician_payment_context'] ?? null) ?: $payment['technician_payment_context'];
        $sampleContext = filter_var($context['__sample_context'] ?? false, FILTER_VALIDATE_BOOL);
        $context['payment_link_sms'] = $this->filledString($context['payment_link_sms'] ?? null) ?: $this->shortLink($context['payment_link'] ?? null, 'https://e.ms/pay/PR88', $sampleContext);
        $context['confirmation_link_sms'] = $this->filledString($context['confirmation_link_sms'] ?? null) ?: $this->shortLink($context['confirmation_link'] ?? null, 'https://e.ms/onay/PR88', $sampleContext);
        $context['technician_job_card_short_url'] = $this->filledString($context['technician_job_card_short_url'] ?? null) ?: $this->shortLink($context['technician_job_card_url'] ?? null, 'https://e.ms/job/PR88', $sampleContext);
        $context['payment_amount_formatted'] = $this->filledString($context['payment_amount_formatted'] ?? null)
            ?: $this->filledString($context['customer_payment_amount_formatted'] ?? null);
        $context['sms_title'] = $this->filledString($context['sms_title'] ?? null) ?: 'EMAKS';
        $context['sms_custom_id'] = $this->filledString($context['sms_custom_id'] ?? null)
            ?: ($this->filledString($context['mrn'] ?? null) ?: 'PR88-REL4C');

        $context['technician_earning_summary_text'] = $this->earningSummary($context);
        $context['technician_earning_summary_block'] = $this->earningSummaryBlock($context);
        $context['internal_job_reference'] = $this->filledString($context['internal_job_reference'] ?? null)
            ?: $this->internalJobReference($context);
        $context['actor_name'] = $this->filledString($context['actor_name'] ?? null)
            ?: ($this->filledString($context['technician_name'] ?? null) ?: 'OPS kullanıcı');
        $context['support_subject'] = $this->filledString($context['support_subject'] ?? null) ?: '';
        $context['support_note'] = $this->filledString($context['support_note'] ?? null) ?: '';
        $context['created_at_formatted'] = $this->filledString($context['created_at_formatted'] ?? null) ?: '';
        $context['rejection_reason'] = $this->filledString($context['rejection_reason'] ?? null) ?: '';
        $context['rejected_at_formatted'] = $this->filledString($context['rejected_at_formatted'] ?? null) ?: '';
        $context['old_amount_formatted'] = $this->filledString($context['old_amount_formatted'] ?? null) ?: '';
        $context['requested_amount_formatted'] = $this->filledString($context['requested_amount_formatted'] ?? null) ?: '';
        $context['revision_reason'] = $this->filledString($context['revision_reason'] ?? null) ?: '';
        $context['completed_at_formatted'] = $this->filledString($context['completed_at_formatted'] ?? null) ?: '';
        $context['next_action_text'] = $this->filledString($context['next_action_text'] ?? null)
            ?: 'OPS son kontrol / müşteri onayı';

        return $context;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{job_type_label:string,reference_code:string,reference_phrase:string,appointment_action_phrase:string,record_created_phrase:string,update_action_phrase:string,hidden_internal_references:array<string, string>}
     */
    private function customerReference(array $context): array
    {
        $explicit = mb_strtolower((string) ($context['customer_job_type_label']
            ?? $context['job_type']
            ?? $context['request_kind']
            ?? $context['service_kind']
            ?? ''));
        $mrn = $this->filledString($context['mrn'] ?? null);
        $srv = $this->filledString($context['srv'] ?? null);
        $isService = str_contains($explicit, 'servis')
            || str_contains($explicit, 'service')
            || str_contains($explicit, 'srv')
            || ($explicit === '' && $srv !== null && str_starts_with(mb_strtoupper($srv), 'SRV'));
        $isMount = str_contains($explicit, 'montaj')
            || str_contains($explicit, 'mount')
            || str_contains($explicit, 'mrn')
            || ($explicit === '' && $srv === null && $mrn !== null);

        if ($isService && $srv !== null) {
            return [
                'job_type_label' => 'servis',
                'reference_code' => $srv,
                'reference_phrase' => "{$srv} numaralı servis",
                'appointment_action_phrase' => "{$srv} numaralı servis randevunuz onaylanmıştır.",
                'record_created_phrase' => "{$srv} numaralı servis randevu kaydınız oluşturulmuştur.",
                'update_action_phrase' => "{$srv} numaralı servis randevunuz güncellenmiştir.",
                'hidden_internal_references' => $mrn !== null ? ['mrn' => $mrn] : [],
            ];
        }

        if ($isMount && $mrn !== null) {
            return [
                'job_type_label' => 'montaj',
                'reference_code' => $mrn,
                'reference_phrase' => "{$mrn} numaralı montaj",
                'appointment_action_phrase' => "{$mrn} numaralı montaj randevunuz onaylanmıştır.",
                'record_created_phrase' => "{$mrn} numaralı montaj randevu kaydınız oluşturulmuştur.",
                'update_action_phrase' => "{$mrn} numaralı montaj randevunuz güncellenmiştir.",
                'hidden_internal_references' => [],
            ];
        }

        return [
            'job_type_label' => 'genel',
            'reference_code' => '',
            'reference_phrase' => 'teknik servis',
            'appointment_action_phrase' => 'Teknik servis randevunuz onaylanmıştır.',
            'record_created_phrase' => 'Teknik servis randevu kaydınız oluşturulmuştur.',
            'update_action_phrase' => 'Teknik servis randevunuz güncellenmiştir.',
            'hidden_internal_references' => array_filter([
                'mrn' => $mrn,
                'srv' => $srv,
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function appointmentTimeInput(array $context): mixed
    {
        $range = $this->filledString($context['appointment_time_range'] ?? null);
        if ($range !== null) {
            return $range;
        }

        $time = $this->filledString($context['appointment_time'] ?? null);
        if ($time !== null) {
            return $time;
        }

        $start = $this->filledString($context['appointment_start_time'] ?? null);
        $end = $this->filledString($context['appointment_end_time'] ?? null);
        if ($start !== null && $end !== null) {
            return "{$start} - {$end}";
        }

        return $start;
    }

    /**
     * @return array{date:string,time_range:string,slot_label:string,customer_window:string,customer_window_label:string,technician_window:string,exact_time_range:string,start_time:string,end_time:string}
     */
    private function appointmentWindow(mixed $date, mixed $time, mixed $slotText): array
    {
        $dateFormatted = $this->formatDate($date);
        $slot = $this->filledString($slotText);

        if ($slot !== null) {
            $slotTimes = $this->extractTimes($slot);
            $slotLabel = $this->slotLabel($slot, $slotTimes[0] ?? null);
            $customerWindow = $this->customerWindowForSlot($slot, $slotTimes[0] ?? null);
            $exactRange = count($slotTimes) >= 2
                ? $this->normalizeTime($slotTimes[0]).' - '.$this->normalizeTime($slotTimes[1])
                : '';

            return [
                'date' => $dateFormatted,
                'time_range' => $exactRange ?: $slot,
                'slot_label' => $slotLabel,
                'customer_window' => $customerWindow,
                'customer_window_label' => $slotLabel,
                'technician_window' => $exactRange,
                'exact_time_range' => $exactRange,
                'start_time' => count($slotTimes) >= 1 ? $this->normalizeTime($slotTimes[0]) : '',
                'end_time' => count($slotTimes) >= 2 ? $this->normalizeTime($slotTimes[1]) : '',
            ];
        }

        $timeText = $this->filledString($time);
        if ($timeText === null) {
            return [
                'date' => $dateFormatted,
                'time_range' => '',
                'slot_label' => '',
                'customer_window' => '',
                'customer_window_label' => '',
                'technician_window' => '',
                'exact_time_range' => '',
                'start_time' => '',
                'end_time' => '',
            ];
        }

        $times = $this->extractTimes($timeText);

        if (count($times) >= 2) {
            $range = $this->normalizeTime($times[0]).' - '.$this->normalizeTime($times[1]);
            $customerWindow = $this->customerWindowForStart($times[0]);

            return [
                'date' => $dateFormatted,
                'time_range' => $range,
                'slot_label' => $customerWindow['label'],
                'customer_window' => $customerWindow['window'],
                'customer_window_label' => $customerWindow['label'],
                'technician_window' => $range,
                'exact_time_range' => $range,
                'start_time' => $this->normalizeTime($times[0]),
                'end_time' => $this->normalizeTime($times[1]),
            ];
        }

        if (count($times) === 1) {
            $start = $this->normalizeTime($times[0]);
            $customerWindow = $this->customerWindowForStart($start);

            return [
                'date' => $dateFormatted,
                'time_range' => $start,
                'slot_label' => $customerWindow['label'],
                'customer_window' => $customerWindow['window'],
                'customer_window_label' => $customerWindow['label'],
                'technician_window' => '',
                'exact_time_range' => '',
                'start_time' => $start,
                'end_time' => '',
            ];
        }

        return [
            'date' => $dateFormatted,
            'time_range' => '',
            'slot_label' => '',
            'customer_window' => '',
            'customer_window_label' => '',
            'technician_window' => '',
            'exact_time_range' => '',
            'start_time' => '',
            'end_time' => '',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function extractTimes(string $value): array
    {
        preg_match_all('/([01]?\d|2[0-3]):([0-5]\d)/', $value, $matches);

        return $matches[0] ?? [];
    }

    /**
     * @return array{window:string,label:string}
     */
    private function customerWindowForStart(string $start): array
    {
        $normalized = $this->normalizeTime($start);
        $hour = (int) substr($normalized, 0, 2);
        $minute = (int) substr($normalized, 3, 2);
        $minutes = ($hour * 60) + $minute;

        if ($minutes <= (12 * 60) + 59) {
            return [
                'window' => '09:00 - 13:00 arası',
                'label' => 'öğleden önce',
            ];
        }

        return [
            'window' => '13:00 - 19:00 arası',
            'label' => 'öğleden sonra',
        ];
    }

    private function customerWindowForSlot(string $slot, ?string $start): string
    {
        $lower = mb_strtolower($slot, 'UTF-8');

        if (str_contains($lower, 'sabah') || str_contains($lower, 'öğleden önce') || str_contains($lower, 'morning')) {
            return '09:00 - 13:00 arası';
        }

        if (str_contains($lower, 'öğleden sonra') || str_contains($lower, 'afternoon')) {
            return '13:00 - 19:00 arası';
        }

        if ($start !== null) {
            return $this->customerWindowForStart($start)['window'];
        }

        return $slot;
    }

    private function slotLabel(string $slot, ?string $start): string
    {
        $lower = mb_strtolower($slot, 'UTF-8');

        if (str_contains($lower, 'sabah') || str_contains($lower, 'öğleden önce') || str_contains($lower, 'morning')) {
            return 'öğleden önce';
        }

        if (str_contains($lower, 'öğleden sonra') || str_contains($lower, 'afternoon')) {
            return 'öğleden sonra';
        }

        if ($start !== null) {
            return $this->customerWindowForStart($start)['label'];
        }

        return $slot;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{payment_instruction_text:string,payment_instruction_block:string,short_payment_instruction:string,sms_payment_line:string,technician_payment_context:string}
     */
    private function paymentInstructions(array $context): array
    {
        $payerState = (string) ($context['payer_state_key'] ?? '');
        $amount = $this->money($context['customer_payment_amount'] ?? null);
        $formatted = $this->filledString($context['customer_payment_amount_formatted'] ?? null);
        $paymentNote = $this->customerPaymentNoteText($context);

        if ($amount > 0.0 && $formatted === null) {
            $formatted = $this->formatTry($amount);
        }

        return match ($payerState) {
            TechnicalServicePaymentOwnershipService::STATE_COMPANY_COLLECTED_ONLINE => [
                'payment_instruction_text' => '',
                'payment_instruction_block' => '',
                'short_payment_instruction' => '',
                'sms_payment_line' => '',
                'technician_payment_context' => 'Müşteri ödemesi şirket tarafından tahsil edildi.',
            ],
            TechnicalServicePaymentOwnershipService::STATE_COMPANY_COLLECTED_EXTERNAL => [
                'payment_instruction_text' => '',
                'payment_instruction_block' => '',
                'short_payment_instruction' => '',
                'sms_payment_line' => '',
                'technician_payment_context' => 'Müşteri ödemesi şirket tarafından kayıt altına alındı.',
            ],
            TechnicalServicePaymentOwnershipService::STATE_CUSTOMER_PAYS_TECHNICIAN => [
                'payment_instruction_text' => $formatted !== null ? "Randevu sırasında ustaya ödenecek tutar: {$formatted}." : '',
                'payment_instruction_block' => implode("\n", array_filter([
                    $formatted !== null ? "Randevu sırasında ustaya ödenecek tutar: {$formatted}." : '',
                    $paymentNote !== null ? "Not: {$paymentNote}" : '',
                ])),
                'short_payment_instruction' => implode(' ', array_filter([
                    $formatted !== null ? "Ustaya ödenecek tutar: {$formatted}." : '',
                    $paymentNote !== null ? $this->shortCustomerPaymentNote($paymentNote) : '',
                ])),
                'sms_payment_line' => implode(' ', array_filter([
                    $formatted !== null ? "Ustaya ödenecek tutar: {$formatted}." : '',
                    $paymentNote !== null ? $this->shortCustomerPaymentNote($paymentNote) : '',
                ])),
                'technician_payment_context' => $formatted !== null ? "Müşteri randevuda ustaya {$formatted} ödeyecek." : '',
            ],
            TechnicalServicePaymentOwnershipService::STATE_PENDING_ONLINE_PAYMENT => [
                'payment_instruction_text' => '',
                'payment_instruction_block' => '',
                'short_payment_instruction' => '',
                'sms_payment_line' => '',
                'technician_payment_context' => 'Online ödeme durumu panelden takip edilmelidir.',
            ],
            default => [
                'payment_instruction_text' => '',
                'payment_instruction_block' => '',
                'short_payment_instruction' => '',
                'sms_payment_line' => '',
                'technician_payment_context' => '',
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function customerPaymentNoteText(array $context): ?string
    {
        foreach ([
            'customer_payment_note_text',
            'customer_visible_payment_note',
            'payment_note_text',
        ] as $key) {
            $value = $this->filledString($context[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        $methods = mb_strtolower((string) ($context['customer_payment_methods'] ?? $context['payment_methods'] ?? ''), 'UTF-8');
        if (str_contains($methods, 'nakit') || str_contains($methods, 'havale')) {
            return 'Ödemeler nakit ve havale kabul edilmektedir.';
        }

        return null;
    }

    private function shortCustomerPaymentNote(string $note): string
    {
        $lower = mb_strtolower($note, 'UTF-8');

        if (str_contains($lower, 'nakit') && str_contains($lower, 'havale')) {
            return 'Nakit/havale kabul edilir.';
        }

        return mb_strlen($note) > 48 ? mb_substr($note, 0, 45).'...' : $note;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function earningSummary(array $context): string
    {
        $labor = $this->filledString($context['labor_amount_formatted'] ?? null);
        $route = $this->filledString($context['route_fee_formatted'] ?? null);
        $total = $this->filledString($context['technician_earning_total_formatted'] ?? null);

        if ($labor !== null || $route !== null || $total !== null) {
            return implode("\n", array_filter([
                $labor !== null ? "İşçilik/Montaj: {$labor}" : null,
                $route !== null ? "Yol: {$route}" : null,
                $total !== null ? "Toplam: {$total}" : null,
            ]));
        }

        return 'Hakediş bilgisi paneldeki iş kartında görülebilir.';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function earningSummaryBlock(array $context): string
    {
        $summary = $this->earningSummary($context);

        return str_contains($summary, ':')
            ? "Hakediş Özeti\n{$summary}"
            : $summary;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function internalJobReference(array $context): string
    {
        $srv = $this->filledString($context['srv'] ?? null);
        $mrn = $this->filledString($context['mrn'] ?? null);
        $requestCode = $this->filledString($context['request_code'] ?? null);

        if ($srv !== null && $mrn !== null) {
            return "SRV: {$srv} / MRN: {$mrn}";
        }

        if ($srv !== null) {
            return "SRV: {$srv}";
        }

        if ($mrn !== null) {
            return "MRN: {$mrn}";
        }

        return $requestCode !== null ? "Talep: {$requestCode}" : '';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function recipientPhone(string $messageType, array $context): ?string
    {
        $role = $this->recipientRole($messageType);
        $phone = $role === 'technician'
            ? Arr::get($context, 'technician_phone')
            : Arr::get($context, 'customer_phone');

        return is_string($phone) ? $this->normalizePhone($phone) : null;
    }

    private function recipientRole(string $messageType): string
    {
        return (string) (TechnicalServiceMessagingSettingsService::MESSAGE_TYPES[$messageType]['recipient_role'] ?? 'customer');
    }

    private function normalizePhone(?string $phone): string
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

    private function formatTry(float $amount): string
    {
        return number_format($amount, 2, ',', '.').' TL';
    }

    private function money(mixed $value): float
    {
        return round((float) ($value ?? 0), 2);
    }

    private function formatDate(mixed $date): string
    {
        $value = $this->filledString($date);
        if ($value === null) {
            return '';
        }

        if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $value) === 1) {
            return $value;
        }

        try {
            return CarbonImmutable::parse($value)->format('d.m.Y');
        } catch (\Throwable) {
            return $value;
        }
    }

    private function normalizeTime(string $time): string
    {
        [$hour, $minute] = array_pad(explode(':', $time, 2), 2, '00');

        return sprintf('%02d:%02d', (int) $hour, (int) $minute);
    }

    private function line(string $label, mixed $value): string
    {
        $text = $this->filledString($value);

        return $text === null ? '' : "{$label}: {$text}";
    }

    private function block(string $label, mixed $value): string
    {
        $text = $this->filledString($value);

        return $text === null ? '' : "{$label}\n{$text}";
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function smsShortAddress(array $context): string
    {
        return implode(' / ', array_filter([
            $this->filledString($context['district'] ?? null),
            $this->filledString($context['city'] ?? null),
        ])) ?: 'Bölge bilgisi panelde';
    }

    private function shortLink(mixed $value, string $sampleFallback, bool $allowSampleFallback): string
    {
        $link = $this->filledString($value);

        if ($link === null) {
            return $allowSampleFallback ? $sampleFallback : '';
        }

        if (str_starts_with($link, 'https://e.ms/')) {
            return $link;
        }

        if (! $allowSampleFallback) {
            return '';
        }

        return mb_strlen($link) > 40 ? $sampleFallback : $link;
    }

    private function mapsLink(TechnicalServiceRequest $request): ?string
    {
        if ($request->location_latitude !== null && $request->location_longitude !== null) {
            return 'https://www.google.com/maps/search/?api=1&query='
                .rawurlencode((string) $request->location_latitude.','.(string) $request->location_longitude);
        }

        $address = $this->filledString($request->location_formatted_address ?: $request->service_address);

        return $address === null ? null : 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($address);
    }

    private function filledString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
