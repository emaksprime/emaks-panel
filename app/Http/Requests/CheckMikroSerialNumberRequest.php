<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckMikroSerialNumberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'serial_no' => ['required', 'string', 'min:2', 'max:128'],
        ];
    }
}
