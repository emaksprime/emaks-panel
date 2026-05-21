<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransferWarehouseRackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $serialNumbers = $this->input('serial_numbers', []);

        if (is_string($serialNumbers)) {
            $serialNumbers = preg_split('/[\r\n,;]+/', $serialNumbers) ?: [];
        }

        if (! is_array($serialNumbers)) {
            $serialNumbers = [];
        }

        $normalizedSerials = array_values(array_filter(
            array_map(fn (mixed $serial): string => trim((string) $serial), $serialNumbers),
            fn (string $serial): bool => $serial !== '',
        ));

        $this->merge([
            'warehouse_no' => $this->input('warehouse_no'),
            'source_rack_code' => trim((string) $this->input('source_rack_code', '')),
            'target_rack_code' => trim((string) $this->input('target_rack_code', '')),
            'stock_code' => trim((string) $this->input('stock_code', '')),
            'item_code' => trim((string) $this->input('item_code', '')),
            'quantity' => $this->filled('quantity') ? str_replace(',', '.', (string) $this->input('quantity')) : 1,
            'serial_numbers' => $normalizedSerials,
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
            'stock_code' => ['nullable', 'string', 'max:255'],
            'item_code' => ['nullable', 'string', 'max:255'],
            'quantity' => ['nullable', 'numeric', 'gt:0'],
            'serial_numbers' => ['nullable', 'array'],
            'serial_numbers.*' => ['string', 'max:255'],
            'is_serial_tracked' => ['nullable', 'boolean'],
            'warehouse_name' => ['nullable', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'warehouse_no.required' => 'Depo seçimi zorunludur.',
            'warehouse_no.integer' => 'Depo no sayısal olmalıdır.',
            'warehouse_no.min' => 'Depo no sıfırdan büyük olmalıdır.',
            'source_rack_code.required' => 'Kaynak raf seçilmelidir.',
            'target_rack_code.required' => 'Hedef raf seçilmelidir.',
            'quantity.numeric' => 'Miktar sayısal olmalıdır.',
            'quantity.gt' => 'Miktar sıfırdan büyük olmalıdır.',
            'serial_numbers.array' => 'Seri numaraları liste olarak gönderilmelidir.',
        ];
    }
}
