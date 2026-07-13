<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AssignTechnicalServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'technical_service_technician_id' => ['nullable', 'integer', 'exists:technical_service_technicians,id'],
            'b2b_partner_id' => ['nullable', 'integer', 'exists:b2b_partners,id'],
            'technician_name' => ['required_without:technical_service_technician_id', 'nullable', 'string', 'max:255'],
            'route_quote_id' => ['nullable', 'integer', 'exists:technical_service_route_quotes,id'],
            'travel_round_trip_km' => ['required_without:route_quote_id', 'nullable', 'numeric', 'min:0'],
            'mount_payment_missing' => ['nullable', 'boolean'],
            'mount_exclusion_acknowledged' => ['nullable', 'boolean'],
            'mount_exclusion_note' => ['nullable', 'string', 'max:2000'],
            'appointment_time_slot' => ['nullable', 'string', 'in:10:00 - 12:00,12:00 - 14:00,14:00 - 16:00,16:00 - 18:00'],
            'override_without_payment' => ['nullable', 'boolean'],
            'override_reason' => ['required_if:override_without_payment,true', 'nullable', 'string', 'min:5', 'max:2000'],
            'note' => ['nullable', 'string', 'max:2000'],
            'assignment_offer' => ['nullable', 'array'],
            'assignment_offer.labor_amount' => ['nullable', 'numeric', 'min:0'],
            'assignment_offer.route_fee_amount' => ['nullable', 'numeric', 'min:0'],
            'assignment_offer.total_amount' => ['nullable', 'numeric', 'min:0'],
            'assignment_offer.customer_direct_to_technician_amount' => ['nullable', 'numeric', 'min:0'],
            'assignment_offer.currency' => ['nullable', 'string', 'max:8'],
            'assignment_offer.note' => ['nullable', 'string', 'max:2000'],
            'labor_amount' => ['nullable', 'numeric', 'min:0'],
            'travel_amount' => ['nullable', 'numeric', 'min:0'],
            'customer_direct_to_technician_amount' => ['nullable', 'numeric', 'min:0'],
            'earning_note' => ['nullable', 'string', 'max:2000'],
            'confirm_assignment' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasFinalEarningPayload = $this->hasAny([
                'labor_amount',
                'travel_amount',
                'customer_direct_to_technician_amount',
                'earning_note',
                'confirm_assignment',
            ]);

            if ($hasFinalEarningPayload && ! $this->boolean('confirm_assignment')) {
                $validator->errors()->add('confirm_assignment', 'Son hakediş onayı zorunludur.');
            }
        });
    }
}
