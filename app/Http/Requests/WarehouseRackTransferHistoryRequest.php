<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WarehouseRackTransferHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'date_from' => trim((string) $this->query('date_from', '')),
            'date_to' => trim((string) $this->query('date_to', '')),
            'warehouse_no' => $this->query('warehouse_no'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'warehouse_no' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date_from.date' => 'Başlangıç tarihi geçerli olmalıdır.',
            'date_to.date' => 'Bitiş tarihi geçerli olmalıdır.',
            'date_to.after_or_equal' => 'Bitiş tarihi başlangıç tarihinden önce olamaz.',
            'warehouse_no.integer' => 'Depo no sayısal olmalıdır.',
            'warehouse_no.min' => 'Depo no sıfırdan büyük olmalıdır.',
        ];
    }
}
