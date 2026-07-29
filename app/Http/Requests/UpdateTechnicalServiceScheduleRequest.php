<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTechnicalServiceScheduleRequest extends FormRequest
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
            'scheduled_date' => ['required', 'date'],
            'scheduled_time' => ['required', 'date_format:H:i'],
            'scheduled_time_end' => ['nullable', 'date_format:H:i', 'after:scheduled_time'],
            'note' => ['nullable', 'string', 'max:2000'],
            'requires_reschedule' => ['nullable', 'boolean'],
            'reschedule_reason' => ['nullable', 'string', 'max:2000'],
            'pending_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
