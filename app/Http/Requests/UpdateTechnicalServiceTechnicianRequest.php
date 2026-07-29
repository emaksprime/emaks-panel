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
            'technician_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'city_plate_code' => ['sometimes', 'nullable', 'string', 'max:16'],
            'priority' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'phone_e164' => ['sometimes', 'nullable', 'string', 'max:64'],
            'phone_display' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:128'],
            'district' => ['sometimes', 'nullable', 'string', 'max:128'],
            'address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'location_code' => ['sometimes', 'nullable', 'string', 'max:255'],
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
            'cari_code' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cari_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cari_address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'cari_city_district_country' => ['sometimes', 'nullable', 'string', 'max:255'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'import_status' => ['sometimes', 'nullable', 'string', 'max:255'],
            'import_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'needs_review' => ['sometimes', 'boolean'],
            'source_key' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
