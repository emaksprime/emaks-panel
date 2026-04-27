<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CariBilgiDataRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:120'],
            'scope_key' => ['nullable', 'in:all,own'],
            'limit' => ['nullable', 'integer', 'in:20,50,100'],
        ];
    }
}
