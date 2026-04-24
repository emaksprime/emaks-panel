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
        ];
    }
}
