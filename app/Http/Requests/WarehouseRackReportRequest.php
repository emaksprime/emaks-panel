<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WarehouseRackReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'warehouse_no' => $this->filled('warehouse_no') ? $this->input('warehouse_no') : null,
            'rack_code' => trim((string) $this->input('rack_code', '')),
            'stock_code' => trim((string) $this->input('stock_code', '')),
            'serial_no' => trim((string) $this->input('serial_no', '')),
            'search' => trim((string) $this->input('search', '')),
            'item_type' => $this->input('item_type') ?: 'all',
            'only_in_stock' => $this->boolean('only_in_stock'),
            'page' => $this->input('page', 1),
            'per_page' => $this->input('per_page', 100),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'warehouse_no' => ['nullable', 'integer', 'min:1'],
            'rack_code' => ['nullable', 'string', 'max:255'],
            'stock_code' => ['nullable', 'string', 'max:255'],
            'serial_no' => ['nullable', 'string', 'max:255'],
            'search' => ['nullable', 'string', 'max:255'],
            'item_type' => ['nullable', 'string', 'in:serial,stock,all'],
            'only_in_stock' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:250'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'warehouse_no.integer' => 'Depo no sayısal olmalıdır.',
            'warehouse_no.min' => 'Depo no sıfırdan büyük olmalıdır.',
            'item_type.in' => 'Tip filtresi serial, stock veya all olmalıdır.',
            'page.min' => 'Sayfa numarası sıfırdan büyük olmalıdır.',
            'per_page.max' => 'Sayfa başına en fazla 250 kayıt listelenebilir.',
        ];
    }
}
