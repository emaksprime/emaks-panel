<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
