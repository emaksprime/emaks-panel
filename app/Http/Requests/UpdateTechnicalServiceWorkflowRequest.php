<?php

namespace App\Http\Requests;

use App\Services\TechnicalService\TechnicalServiceWorkflowService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTechnicalServiceWorkflowRequest extends FormRequest
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
            'workflow_status' => ['nullable', 'string', Rule::in(TechnicalServiceWorkflowService::WORKFLOW_STATUSES)],
            'action' => ['nullable', 'string', Rule::in(array_keys(TechnicalServiceWorkflowService::actionLabels()))],
            'note' => ['nullable', 'string', 'max:2000'],
            'missing_info_reason' => ['nullable', 'string', 'max:2000'],
            'pending_reason' => ['nullable', 'string', 'max:2000'],
            'cancellation_reason' => ['nullable', 'string', 'max:2000'],
            'customer_confirmation_method' => ['nullable', 'string', 'max:64'],
            'customer_closure_approval_status' => ['nullable', 'string', 'max:64'],
            'customer_closure_approved_at' => ['nullable', 'date'],
            'installation_completed_at' => ['nullable', 'date'],
            'resolution_notes' => ['nullable', 'string', 'max:2000'],
            'technician_revision_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
