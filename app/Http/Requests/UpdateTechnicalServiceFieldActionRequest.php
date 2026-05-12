<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTechnicalServiceFieldActionRequest extends FormRequest
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
            'note' => ['nullable', 'string', 'max:2000'],
            'workflow_status' => ['nullable', 'string', Rule::in([
                'Beklemede',
                'Müşteri Yerinde Yok',
                'Montaj Yeri Hazır Değil',
                'Parça Bekleniyor',
            ])],
            'incomplete_reason' => ['nullable', 'string', 'max:2000'],
            'pending_reason' => ['nullable', 'string', 'max:2000'],
            'requires_second_visit' => ['nullable', 'boolean'],
            'second_visit_reason' => ['nullable', 'string', 'max:2000'],
            'checklist_payload' => ['nullable', 'array'],
            'checklist_payload.*' => ['nullable'],
            'before_photo_count' => ['nullable', 'integer', 'min:0'],
            'after_photo_count' => ['nullable', 'integer', 'min:0'],
            'general_photo_count' => ['nullable', 'integer', 'min:0'],
            'document_status' => ['nullable', 'string', Rule::in(['tamamlandı', 'tamam', 'gerekli_degil', 'eksik'])],
            'approval_method' => ['nullable', 'string', Rule::in(['otp', 'imza', 'telefon', 'panel'])],
            'approval_code' => ['nullable', 'string', 'max:255'],
            'signature_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $action = (string) $this->route('fieldAction');

            if ($action === 'checklist' && ! is_array($this->input('checklist_payload'))) {
                $validator->errors()->add('checklist_payload', 'Checklist alanı zorunludur.');
            }

            if ($action === 'photos') {
                foreach (['before_photo_count', 'after_photo_count', 'general_photo_count'] as $field) {
                    if (! $this->filled($field)) {
                        $validator->errors()->add($field, 'Fotoğraf sayısı zorunludur.');
                    }
                }
            }

            if ($action === 'customer-closure-approval' && ! $this->filled('approval_method')) {
                $validator->errors()->add('approval_method', 'Onay yöntemi zorunludur.');
            }

            if ($action === 'mark-incomplete' && ! $this->filled('incomplete_reason')) {
                $validator->errors()->add('incomplete_reason', 'Tamamlanamama nedeni zorunludur.');
            }

            if ($action === 'mark-incomplete' && $this->boolean('requires_second_visit') && ! $this->filled('second_visit_reason')) {
                $validator->errors()->add('second_visit_reason', 'İkinci randevu nedeni zorunludur.');
            }
        });
    }
}
