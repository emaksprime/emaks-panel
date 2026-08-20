<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTechnicalServiceTechnicianWorkflowRequest extends FormRequest
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
            'technical_service_technician_id' => ['nullable', 'integer', 'exists:technical_service_technicians,id'],
            'b2b_partner_id' => ['nullable', 'integer', 'exists:b2b_partners,id'],
            'technician_name' => ['required_without:technical_service_technician_id', 'nullable', 'string', 'max:255'],
            'technician_approval_status' => ['nullable', 'string', Rule::in(['bekliyor', 'onayladı', 'revize_talebi'])],
            'technician_revision_note' => ['nullable', 'string', 'max:2000'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
