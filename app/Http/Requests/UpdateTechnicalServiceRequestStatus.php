<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateTechnicalServiceRequestStatus extends FormRequest
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
            'status' => ['required', 'string', 'in:Yeni,Atandı,Randevulu,Devam Ediyor,Tamamlandı,İptal'],
            'note' => ['nullable', 'string', 'max:2000'],
            'resolution_notes' => ['nullable', 'string', 'max:2000'],
            'installation_completed_at' => ['nullable', 'date'],
            'installation_completion_note' => ['nullable', 'string', 'max:2000'],
            'reopen_type' => ['nullable', 'string', 'in:revisit,service_request'],
            'reopen_reason' => ['nullable', 'string', 'in:Yanlışlıkla tamamlandı,Eksik fotoğraf / belge,Müşteri onayı hatası,Usta yanlış kapattı,Operasyon düzeltmesi,Diğer'],
            'reopen_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $technicalServiceRequest = $this->route('technicalServiceRequest');
                $isReopen = $this->input('status') === 'Yeni'
                    && $technicalServiceRequest
                    && in_array($technicalServiceRequest->status, ['Tamamlandı', 'İptal'], true);

                if (! $isReopen) {
                    $this->validateInstallationCompletion($validator, $technicalServiceRequest);

                    return;
                }

                if (! filled($this->input('reopen_reason'))) {
                    $validator->errors()->add('reopen_reason', 'Yeniden açma nedeni zorunludur.');
                }

                if ($this->input('reopen_reason') === 'Diğer' && ! filled($this->input('reopen_note'))) {
                    $validator->errors()->add('reopen_note', 'Diğer nedeni seçildiğinde açıklama zorunludur.');
                }

                $this->validateInstallationCompletion($validator, $technicalServiceRequest);
            },
        ];
    }

    private function validateInstallationCompletion(Validator $validator, mixed $technicalServiceRequest): void
    {
        if (
            ! $technicalServiceRequest
            || $this->input('status') !== 'Tamamlandı'
            || $technicalServiceRequest->service_type !== 'Montaj'
        ) {
            return;
        }

        if (! filled($this->input('installation_completed_at'))) {
            $validator->errors()->add('installation_completed_at', 'Montaj tamamlanırken fiili montaj tarihi zorunludur.');

            return;
        }

        try {
            $installationCompletedAt = CarbonImmutable::parse($this->input('installation_completed_at'));
        } catch (\Throwable) {
            return;
        }

        if ($installationCompletedAt->greaterThan(now())) {
            $validator->errors()->add('installation_completed_at', 'Fiili montaj tarihi gelecek tarih olamaz.');
        }

        $hasExplanation = filled($this->input('installation_completion_note')) || filled($this->input('note'));
        $scheduledAt = $technicalServiceRequest->scheduled_at;

        if ($scheduledAt && $installationCompletedAt->toDateString() !== $scheduledAt->toDateString() && ! $hasExplanation) {
            $validator->errors()->add('installation_completion_note', 'Fiili montaj tarihi randevu tarihinden farklıysa açıklama zorunludur.');
        }

        if ($installationCompletedAt->lessThan(CarbonImmutable::now()->subDay()) && ! $hasExplanation) {
            $validator->errors()->add('installation_completion_note', 'Fiili montaj tarihi kapanıştan 1 günden fazla eskiyse açıklama zorunludur.');
        }
    }
}
