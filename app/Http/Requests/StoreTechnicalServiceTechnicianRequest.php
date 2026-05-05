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
            'phone' => ['nullable', 'string', 'max:64'],
            'city' => ['nullable', 'string', 'max:128'],
            'district' => ['nullable', 'string', 'max:128'],
            'address' => ['nullable', 'string', 'max:2000'],
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
        ];
    }
}
