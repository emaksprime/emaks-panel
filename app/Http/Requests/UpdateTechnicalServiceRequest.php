<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTechnicalServiceRequest extends FormRequest
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
            'customer_name' => ['sometimes', 'string', 'max:255'],
            'customer_phone' => ['sometimes', 'string', 'max:64'],
            'customer_city' => ['sometimes', 'string', 'max:128'],
            'customer_district' => ['sometimes', 'string', 'max:128'],
            'service_address' => ['sometimes', 'string', 'max:1024'],
            'product_name' => ['sometimes', 'string', 'max:255'],
            'product_model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'service_type' => ['sometimes', 'string', 'max:128'],
            'status' => ['sometimes', 'string', 'in:Yeni,Atandı,Randevulu,Devam Ediyor,Tamamlandı,İptal'],
            'priority' => ['sometimes', 'string', 'in:Düşük,Orta,Yüksek,Kritik'],
            'risk_level' => ['sometimes', 'string', 'in:Düşük,Orta,Yüksek,Kritik'],
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
            'sla_due_at' => ['sometimes', 'nullable', 'date'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'resolution_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'schedule_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'source_channel' => ['sometimes', 'nullable', 'string', 'max:128'],
            'travel_round_trip_km' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'technician_payment_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }
}
