<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SalesMainDataRequest extends FormRequest
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
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'grain' => ['nullable', 'in:day,week,month,year'],
            'detail_type' => ['nullable', 'in:cari,urun'],
            'scope_key' => ['nullable', 'string', 'max:64'],
            'rep_code' => ['nullable', 'string', 'max:40'],
            'customer_filter' => ['nullable', 'string', 'max:1000'],
            'cari_filter' => ['nullable', 'string', 'max:1000'],
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'bypass_cache' => ['nullable', 'boolean'],
        ];
    }
}
