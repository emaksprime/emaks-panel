<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTechnicalServiceContactLogRequest extends FormRequest
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
        $action = (string) $this->input('action');

        return [
            'action' => ['required', 'string', Rule::in([
                'customer_called',
                'customer_unreachable',
                'customer_callback_scheduled',
                'customer_confirmation_pending',
                'customer_confirmed',
                'customer_rejected',
                'wrong_number',
                'customer_requested_cancel',
            ])],
            'contacted_at' => ['nullable', 'date'],
            'contact_method' => [
                Rule::requiredIf($action === 'customer_called'),
                'nullable',
                'string',
                'max:64',
            ],
            'customer_confirmation_method' => [
                Rule::requiredIf($action === 'customer_confirmed'),
                'nullable',
                'string',
                Rule::in(['telefon', 'whatsapp', 'sms', 'eposta', 'panel']),
            ],
            'customer_preferred_date' => [
                Rule::requiredIf($action === 'customer_confirmed'),
                'nullable',
                'date',
            ],
            'customer_preferred_time_start' => [
                Rule::requiredIf($action === 'customer_confirmed'),
                'nullable',
                'date_format:H:i',
            ],
            'customer_preferred_time_end' => ['nullable', 'date_format:H:i'],
            'customer_callback_at' => [
                Rule::requiredIf($action === 'customer_callback_scheduled'),
                'nullable',
                'date',
            ],
            'customer_rejection_reason' => [
                Rule::requiredIf($action === 'customer_rejected'),
                'nullable',
                'string',
                'max:2000',
            ],
            'cancellation_reason' => ['nullable', 'string', 'max:2000'],
            'note' => [
                Rule::requiredIf($action === 'wrong_number'),
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $action = (string) $this->input('action');
            $note = trim((string) $this->input('note', ''));
            $cancellationReason = trim((string) $this->input('cancellation_reason', ''));
            $start = (string) $this->input('customer_preferred_time_start', '');
            $end = (string) $this->input('customer_preferred_time_end', '');

            if ($action === 'customer_requested_cancel' && $note === '' && $cancellationReason === '') {
                $validator->errors()->add('note', 'İptal talebinde not veya iptal nedeni zorunludur.');
            }

            if ($start !== '' && $end !== '' && strcmp($start, $end) > 0) {
                $validator->errors()->add('customer_preferred_time_end', 'Bitiş saati başlangıç saatinden önce olamaz.');
            }
        });
    }
}
