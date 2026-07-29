<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestEvent;
use Carbon\CarbonInterface;

class TechnicalServiceCancelContextService
{
    /**
     * @param array<string, mixed>|null $operationalState
     * @return array<string, mixed>|null
     */
    public function present(TechnicalServiceRequest $request, ?array $operationalState = null): ?array
    {
        $request->loadMissing([
            'events' => fn ($query) => $query->latest(),
            'technicianRecord',
            'assignmentOffers' => fn ($query) => $query->latest(),
            'partnerJobActions' => fn ($query) => $query->latest(),
        ]);

        $isCancelled = $this->isCancelled($request);
        $isCancelReview = $this->isCancellationReview($request);
        $isReopened = $this->isReopenedFromCancelReview($request);
        $previousCancelledRequest = $this->previousCancelledSibling($request);
        $relatedCurrentRequest = $isCancelled ? $this->relatedCurrentRequest($request) : null;

        if (! $isCancelled && ! $isCancelReview && ! $isReopened && ! $previousCancelledRequest instanceof TechnicalServiceRequest) {
            return null;
        }

        $cancelSource = $previousCancelledRequest instanceof TechnicalServiceRequest ? $previousCancelledRequest : $request;
        $cancelSource->loadMissing([
            'events' => fn ($query) => $query->latest(),
            'technicianRecord',
            'assignmentOffers' => fn ($query) => $query->latest(),
            'partnerJobActions' => fn ($query) => $query->latest(),
        ]);

        $currentStageLabel = $this->currentStageLabel($request, $operationalState);
        $cancelledCode = $this->requestCode($cancelSource);
        $currentCode = $relatedCurrentRequest instanceof TechnicalServiceRequest
            ? $this->requestCode($relatedCurrentRequest)
            : $this->requestCode($request);
        $cancelReason = $this->cancelReason($cancelSource);

        $context = [
            'exists' => true,
            'is_cancelled' => $isCancelled,
            'is_cancel_review' => $isCancelReview,
            'is_reopened' => $isReopened || $previousCancelledRequest instanceof TechnicalServiceRequest,
            'cancelled_request_id' => $cancelSource->id,
            'cancelled_code' => $cancelledCode,
            'previous_cancelled_request_id' => $cancelSource->id,
            'previous_cancelled_code' => $cancelledCode,
            'root_mrn' => $cancelSource->root_mrn ?: $cancelSource->mrn,
            'customer_name' => TechnicalServiceUiLabelService::cleanDisplayText($cancelSource->customer_name),
            'phone' => $cancelSource->customer_phone,
            'city' => TechnicalServiceUiLabelService::cityLabel($cancelSource->customer_city),
            'district' => TechnicalServiceUiLabelService::districtLabel($cancelSource->customer_district, TechnicalServiceUiLabelService::cityLabel($cancelSource->customer_city)),
            'product_name' => TechnicalServiceUiLabelService::cleanDisplayText($cancelSource->product_name),
            'product_model' => TechnicalServiceUiLabelService::cleanDisplayText($cancelSource->product_model),
            'serial_no' => $cancelSource->serial_number,
            'activation_code' => $cancelSource->activation_code,
            'last_technician_name' => TechnicalServiceUiLabelService::displayName($cancelSource->technicianRecord?->name ?? $cancelSource->technician_name),
            'last_technician_phone' => $cancelSource->technicianRecord?->phone,
            'last_appointment_at' => $this->dateTimeString($cancelSource->scheduled_at),
            'cancel_reason' => $cancelReason,
            'previous_cancel_reason' => $cancelReason,
            'cancelled_at' => $this->dateTimeString($cancelSource->cancelled_at ?? $this->latestCancelEvent($cancelSource)?->created_at),
            'previous_cancelled_at' => $this->dateTimeString($cancelSource->cancelled_at ?? $this->latestCancelEvent($cancelSource)?->created_at),
            'cancelled_by_label' => $this->cancelledByLabel($cancelSource),
            'earning_excluded' => true,
            'earning_excluded_label' => 'İptal nedeniyle hakedişe dahil değil',
            'previous_stage_label' => $this->previousStageLabel($cancelSource),
            'current_stage_label' => $currentStageLabel,
            'current_request_id' => $relatedCurrentRequest?->id ?? ($isCancelled ? null : $request->id),
            'current_code' => $isCancelled ? ($relatedCurrentRequest ? $currentCode : null) : $currentCode,
            'related_current_request_id' => $relatedCurrentRequest?->id,
            'related_current_code' => $relatedCurrentRequest ? $currentCode : null,
            'next_ops_message' => $this->nextOpsMessage($isCancelled, $isCancelReview, $currentStageLabel),
            'summary' => $this->summary($isCancelled, $isCancelReview, $cancelledCode, $currentStageLabel),
        ];

        return $this->withoutBlankValues($context);
    }

    /**
     * @param array<string, mixed>|null $operationalState
     * @return array{label: string, summary: string}
     */
    public function currentStageSummary(TechnicalServiceRequest $request, ?array $operationalState = null): array
    {
        $label = $this->currentStageLabel($request, $operationalState);

        return [
            'label' => $label,
            'summary' => "Şu anki aşama: {$label}",
        ];
    }

    /**
     * @param array<string, mixed>|null $operationalState
     */
    public function currentStageLabel(TechnicalServiceRequest $request, ?array $operationalState = null): string
    {
        if ($this->isCancellationReview($request)) {
            return 'İptal incelemede';
        }

        if ($this->isCancelled($request)) {
            return 'İptal edildi';
        }

        if ($request->reopened_at instanceof CarbonInterface) {
            return match ((string) $request->workflow_status) {
                'Yeni Talep', 'Yeni' => 'Yeniden açıldı',
                default => $this->stageLabelFromRequest($request, $operationalState),
            };
        }

        return $this->stageLabelFromRequest($request, $operationalState);
    }

    /**
     * @param array<string, mixed>|null $operationalState
     */
    private function stageLabelFromRequest(TechnicalServiceRequest $request, ?array $operationalState = null): string
    {
        $workflowStatus = TechnicalServiceUiLabelService::cleanDisplayText($request->workflow_status);
        $nextAction = TechnicalServiceUiLabelService::cleanDisplayText($request->next_action);
        $opsColumn = (string) ($operationalState['ops_column'] ?? '');

        if ($workflowStatus === 'Tamamlandı' || $request->completed_at instanceof CarbonInterface) {
            return 'Tamamlandı';
        }

        return match ($workflowStatus) {
            'Yeni Talep', 'Yeni' => 'Yeni',
            'Beklemede' => (string) $request->pending_reason === TechnicalServiceWorkflowService::CANCELLATION_REVIEW_PENDING_REASON ? 'İptal incelemede' : 'Operasyon incelemesi',
            'Usta Ataması Bekleyen' => 'Usta ataması bekliyor',
            'Usta Onayı Bekleyen' => 'Usta onayı bekliyor',
            'Müşteri Onayı Bekleyen' => 'Randevu onayı bekliyor',
            'Müşteri Onayladı' => 'Randevu onaylandı',
            'Planlı', 'Yolda', 'Sahada' => 'Servis atandı',
            'Belge / Fotoğraf Bekleyen' => 'Saha işlemi bekleniyor',
            'Müşteri Kapanış Onayı Bekleyen' => 'Son kontrol',
            'İptal' => 'İptal edildi',
            default => match ($opsColumn) {
                'new' => 'Usta ataması bekliyor',
                'assignment_pending' => 'Usta onayı bekliyor',
                'assigned' => 'Servis atandı',
                'final_check' => 'Son kontrol',
                'completed' => 'Tamamlandı',
                'cancelled' => 'İptal edildi',
                'review' => 'Operasyon incelemesi',
                default => $nextAction ?: ($workflowStatus ?: 'Operasyon incelemesi'),
            },
        };
    }

    private function isCancelled(TechnicalServiceRequest $request): bool
    {
        return $request->cancelled_at instanceof CarbonInterface
            || $this->isCancelledStatus($request->status)
            || $this->isCancelledStatus($request->workflow_status);
    }

    private function isCancellationReview(TechnicalServiceRequest $request): bool
    {
        if ((string) $request->pending_reason === TechnicalServiceWorkflowService::CANCELLATION_REVIEW_PENDING_REASON) {
            return true;
        }

        $payload = is_array($request->operation_control_payload) ? $request->operation_control_payload : [];
        $review = $payload[TechnicalServiceWorkflowService::CANCELLATION_REVIEW_KEY] ?? null;

        return is_array($review) && in_array((string) ($review['status'] ?? ''), ['pending', 'review'], true);
    }

    private function isReopenedFromCancelReview(TechnicalServiceRequest $request): bool
    {
        if (! $request->reopened_at instanceof CarbonInterface) {
            return false;
        }

        $payload = is_array($request->operation_control_payload) ? $request->operation_control_payload : [];
        $review = $payload[TechnicalServiceWorkflowService::CANCELLATION_REVIEW_KEY] ?? null;

        return is_array($review) && (string) ($review['status'] ?? '') === 'reopened';
    }

    private function previousCancelledSibling(TechnicalServiceRequest $request): ?TechnicalServiceRequest
    {
        if (blank($request->root_mrn)) {
            return null;
        }

        return TechnicalServiceRequest::query()
            ->where('id', '!=', $request->id)
            ->where('root_mrn', $request->root_mrn)
            ->where(function ($query): void {
                $query->whereNotNull('cancelled_at')
                    ->orWhereIn('status', $this->cancelledStatusAliases())
                    ->orWhereIn('workflow_status', $this->cancelledStatusAliases());
            })
            ->latest('cancelled_at')
            ->latest('id')
            ->first();
    }

    private function relatedCurrentRequest(TechnicalServiceRequest $request): ?TechnicalServiceRequest
    {
        if (blank($request->root_mrn)) {
            return null;
        }

        return TechnicalServiceRequest::query()
            ->where('id', '!=', $request->id)
            ->where('root_mrn', $request->root_mrn)
            ->whereNull('cancelled_at')
            ->whereNotIn('status', $this->cancelledStatusAliases())
            ->whereNotIn('workflow_status', $this->cancelledStatusAliases())
            ->latest('updated_at')
            ->latest('id')
            ->first();
    }

    private function isCancelledStatus(mixed $value): bool
    {
        $status = TechnicalServiceUiLabelService::cleanDisplayText(is_scalar($value) ? (string) $value : null);

        return in_array($status, ['İptal', 'Iptal'], true);
    }

    /**
     * @return list<string>
     */
    private function cancelledStatusAliases(): array
    {
        return ['İptal', 'Iptal', "\xC3\x84\xC2\xB0ptal"];
    }

    private function requestCode(TechnicalServiceRequest $request): string
    {
        return (string) ($request->service_code ?: $request->mrn);
    }

    private function cancelReason(TechnicalServiceRequest $request): string
    {
        $event = $this->latestCancelEvent($request);
        $metadata = is_array($event?->metadata) ? $event->metadata : [];

        foreach ([
            $request->cancellation_reason,
            $event?->note,
            $metadata['cancellation_reason'] ?? null,
            $metadata['reason_label'] ?? null,
            $metadata['reason'] ?? null,
            $metadata['note'] ?? null,
        ] as $candidate) {
            $value = TechnicalServiceUiLabelService::cleanDisplayText(is_scalar($candidate) ? (string) $candidate : null);
            if (filled($value)) {
                return $value;
            }
        }

        return $this->isCancellationReview($request)
            ? 'Operasyon iptal talebini inceliyor.'
            : 'Operasyon iptal kaydı.';
    }

    private function cancelledByLabel(TechnicalServiceRequest $request): string
    {
        $event = $this->latestCancelEvent($request);

        if ($event?->author_user_id !== null) {
            return 'Operasyon';
        }

        return 'Sistem';
    }

    private function previousStageLabel(TechnicalServiceRequest $request): string
    {
        $event = $this->latestCancelEvent($request);

        return match ((string) ($event?->from_status ?? '')) {
            'Planlı', 'Yolda', 'Sahada' => 'Servis atandı',
            'Usta Onayı Bekleyen' => 'Usta onayı bekliyordu',
            'Yeni Talep', 'Yeni' => 'Yeni',
            'Beklemede' => 'Operasyon incelemesi',
            '' => 'Operasyon incelemesi',
            default => TechnicalServiceUiLabelService::cleanDisplayText($event?->from_status) ?: 'Operasyon incelemesi',
        };
    }

    private function nextOpsMessage(bool $isCancelled, bool $isCancelReview, string $currentStageLabel): string
    {
        if ($isCancelReview) {
            return 'Bu iş iptal incelemesinde. OPS iptali onaylayabilir veya işi yeniden açabilir.';
        }

        if ($isCancelled) {
            return 'İş iptal edildi. Gizli iptal durumunda tutuluyor.';
        }

        return "Önceki iş iptal edildi. Şu an: {$currentStageLabel}.";
    }

    private function summary(bool $isCancelled, bool $isCancelReview, string $cancelledCode, string $currentStageLabel): string
    {
        if ($isCancelReview) {
            return 'İptal talebi incelemede. OPS karar vermeli.';
        }

        if ($isCancelled) {
            return 'İş iptal edildi. Hakedişe dahil değil.';
        }

        return "Önceki iş {$cancelledCode} iptal edildi. Şu an: {$currentStageLabel}.";
    }

    private function latestCancelEvent(TechnicalServiceRequest $request): ?TechnicalServiceRequestEvent
    {
        return $request->events
            ->sortByDesc(fn (TechnicalServiceRequestEvent $event): int => (int) $event->created_at?->getTimestamp())
            ->first(fn (TechnicalServiceRequestEvent $event): bool => in_array((string) $event->event_type, [
                'cancellation_confirmed',
                'cancel',
                'cancelled',
                'cancellation_requested',
            ], true))
            ?? null;
    }

    private function dateTimeString(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function withoutBlankValues(array $payload): array
    {
        return collect($payload)
            ->reject(fn ($value): bool => $value === null || $value === '')
            ->all();
    }
}
