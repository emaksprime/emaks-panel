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
            'technician_name' => ['required_without:technical_service_technician_id', 'nullable', 'string', 'max:255'],
            'travel_round_trip_km' => ['required', 'numeric', 'min:0'],
            'mount_payment_missing' => ['nullable', 'boolean'],
            'appointment_time_slot' => ['nullable', 'string', 'in:10:00 - 12:00,12:00 - 14:00,14:00 - 16:00,16:00 - 18:00'],
            'override_without_payment' => ['nullable', 'boolean'],
            'override_reason' => ['required_if:override_without_payment,true', 'nullable', 'string', 'min:5', 'max:2000'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->boolean('mount_payment_missing')) {
                return;
            }

            if (! $this->boolean('override_without_payment')) {
                $validator->errors()->add('override_without_payment', 'Montaj Hariç işler için operasyon onayı zorunludur.');
            }

            if (mb_strlen(trim((string) $this->input('override_reason'))) < 5) {
                $validator->errors()->add('override_reason', 'Atama nedeni en az 5 karakter olmalıdır.');
            }
        });
    }
}
