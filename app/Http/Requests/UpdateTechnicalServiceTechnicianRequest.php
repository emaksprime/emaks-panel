<?php

namespace App\Http\Requests;

class UpdateTechnicalServiceTechnicianRequest extends StoreTechnicalServiceTechnicianRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'city' => ['sometimes', 'nullable', 'string', 'max:128'],
            'district' => ['sometimes', 'nullable', 'string', 'max:128'],
            'address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'google_plus_code' => ['sometimes', 'nullable', 'string', 'max:255'],
            'google_formatted_address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'default_start_address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'default_start_plus_code' => ['sometimes', 'nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'start_latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'start_longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'mikro_cari_kodu' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mikro_cari_adi' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
