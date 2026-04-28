<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTechnicalServiceRequest extends FormRequest
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
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:64'],
            'customer_city' => ['required', 'string', 'max:128'],
            'customer_district' => ['required', 'string', 'max:128'],
            'service_address' => ['required', 'string', 'max:1024'],
            'product_name' => ['required', 'string', 'max:255'],
            'product_model' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'service_type' => ['required', 'string', 'max:128'],
            'status' => ['nullable', 'string', 'in:Yeni,Atandı,Randevulu,Devam Ediyor,Tamamlandı,İptal'],
            'priority' => ['nullable', 'string', 'in:Düşük,Orta,Yüksek,Kritik'],
            'risk_level' => ['nullable', 'string', 'in:Düşük,Orta,Yüksek,Kritik'],
            'technician_name' => ['nullable', 'string', 'max:255'],
            'scheduled_at' => ['nullable', 'date'],
            'sla_due_at' => ['nullable', 'date'],
            'completed_at' => ['nullable', 'date'],
            'cancelled_at' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:2000'],
            'resolution_notes' => ['nullable', 'string', 'max:2000'],
            'source_channel' => ['nullable', 'string', 'max:128'],
        ];
    }
}
