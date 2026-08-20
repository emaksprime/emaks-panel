<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTechnicalServiceTechnicianRequest extends FormRequest
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
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'technician_type' => ['nullable', 'string', 'max:64'],
            'city_plate_code' => ['nullable', 'string', 'max:16'],
            'priority' => ['nullable', 'integer', 'min:0'],
            'phone' => ['nullable', 'string', 'max:64'],
            'phone_e164' => ['nullable', 'string', 'max:64'],
            'phone_display' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:128'],
            'district' => ['nullable', 'string', 'max:128'],
            'address' => ['nullable', 'string', 'max:2000'],
            'location_code' => ['nullable', 'string', 'max:255'],
            'google_plus_code' => ['nullable', 'string', 'max:255'],
            'google_formatted_address' => ['nullable', 'string', 'max:2000'],
            'default_start_address' => ['nullable', 'string', 'max:2000'],
            'default_start_plus_code' => ['nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:2000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'start_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'start_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'mikro_cari_kodu' => ['nullable', 'string', 'max:255'],
            'mikro_cari_adi' => ['nullable', 'string', 'max:255'],
            'cari_code' => ['nullable', 'string', 'max:255'],
            'cari_title' => ['nullable', 'string', 'max:255'],
            'cari_address' => ['nullable', 'string', 'max:2000'],
            'cari_city_district_country' => ['nullable', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'import_status' => ['nullable', 'string', 'max:255'],
            'import_note' => ['nullable', 'string', 'max:2000'],
            'needs_review' => ['sometimes', 'boolean'],
            'source_key' => ['nullable', 'string', 'max:255'],
        ];
    }
}
