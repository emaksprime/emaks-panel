<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ValidateWarehouseRackTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'warehouse_no' => $this->input('warehouse_no'),
            'source_rack_code' => trim((string) $this->input('source_rack_code', '')),
            'target_rack_code' => trim((string) $this->input('target_rack_code', '')),
            'item_code' => trim((string) $this->input('item_code', '')),
            'quantity' => $this->filled('quantity') ? $this->input('quantity') : 1,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'warehouse_no' => ['required', 'integer', 'min:1'],
            'source_rack_code' => ['required', 'string', 'max:255'],
            'target_rack_code' => ['required', 'string', 'max:255'],
            'item_code' => ['required', 'string', 'max:255'],
            'quantity' => ['nullable', 'numeric', 'gt:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'warehouse_no.required' => 'Depo no zorunludur.',
            'warehouse_no.integer' => 'Depo no sayısal olmalıdır.',
            'warehouse_no.min' => 'Depo no sıfırdan büyük olmalıdır.',
            'source_rack_code.required' => 'Kaynak raf okutulmalı veya yazılmalı.',
            'target_rack_code.required' => 'Hedef raf okutulmalı veya yazılmalı.',
            'item_code.required' => 'Ürün / seri no okutulmalı veya yazılmalı.',
            'quantity.numeric' => 'Miktar sayısal olmalıdır.',
            'quantity.gt' => 'Miktar sıfırdan büyük olmalıdır.',
        ];
    }
}
