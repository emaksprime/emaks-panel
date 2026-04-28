<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTechnicalServiceRequestStatus extends FormRequest
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
            'status' => ['required', 'string', 'in:Yeni,Atandı,Randevulu,Devam Ediyor,Tamamlandı,İptal'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
