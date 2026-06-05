<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServicePartRequest;
use App\Models\TechnicalServicePartnerJobAction;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestSerial;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class TechnicalServicePartRequestService
{
    public function __construct(
        private readonly TechnicalServiceServiceVisitService $serviceVisits,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createFromPartnerSupport(
        TechnicalServiceRequest $request,
        User $user,
        TechnicalServicePartnerJobAction $sourceAction,
        array $data,
    ): TechnicalServicePartRequest {
        $request->loadMissing(['requestSerials', 'parentRequest']);
        $serial = $this->primarySerial($request);
        $partName = trim((string) ($data['product_name'] ?? ''));

        if ($partName === '') {
            $partName = 'Yedek parça';
        }

        $part = TechnicalServicePartRequest::query()->create([
            'technical_service_request_id' => $request->id,
            'root_request_id' => $this->rootRequest($request)->id,
            'request_serial_id' => $serial?->id,
            'source_partner_action_id' => $sourceAction->id,
            'requested_by_user_id' => $user->id,
            'requested_by_technician_id' => $request->technical_service_technician_id,
            'status' => TechnicalServicePartRequest::STATUS_OPS_REVIEW,
            'part_name' => $partName,
            'quantity' => max(1, (int) ($data['quantity'] ?? 1)),
            'reason' => $data['description'] ?? null,
            'technician_note' => $data['description'] ?? null,
            'metadata' => [
                'source' => 'partner_support_request',
                'support_type' => $data['type'] ?? null,
                'request_mrn' => $request->mrn,
                'serial_number' => $serial?->serial_number ?? $request->serial_number,
            ],
        ]);

        $this->recordEvent($request, 'part_request_created', 'Parça talebi oluşturuldu', $part->technician_note, $user, [
            'part_request_id' => $part->id,
            'source_partner_action_id' => $sourceAction->id,
        ]);

        return $part;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function transition(TechnicalServicePartRequest $partRequest, string $targetStatus, ?User $user, array $payload = []): TechnicalServicePartRequest
    {
        $targetStatus = trim($targetStatus);
        $allowed = [
            TechnicalServicePartRequest::STATUS_APPROVED,
            TechnicalServicePartRequest::STATUS_REJECTED,
            TechnicalServicePartRequest::STATUS_ORDERED,
            TechnicalServicePartRequest::STATUS_SENT,
            TechnicalServicePartRequest::STATUS_RECEIVED,
            TechnicalServicePartRequest::STATUS_SERVICE_VISIT_REQUIRED,
            TechnicalServicePartRequest::STATUS_CLOSED,
        ];

        if (! in_array($targetStatus, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => 'Geçerli bir parça talebi aksiyonu seçilmelidir.',
            ]);
        }

        $note = trim((string) ($payload['note'] ?? ''));
        if ($targetStatus === TechnicalServicePartRequest::STATUS_REJECTED && $note === '') {
            throw ValidationException::withMessages([
                'note' => 'Parça talebini reddetmek için operasyon notu zorunludur.',
            ]);
        }

        if ($targetStatus === TechnicalServicePartRequest::STATUS_SENT && $partRequest->status !== TechnicalServicePartRequest::STATUS_SENT) {
            $partRequest->sent_at = now();
        }

        if ($targetStatus === TechnicalServicePartRequest::STATUS_RECEIVED && $partRequest->status !== TechnicalServicePartRequest::STATUS_RECEIVED) {
            $partRequest->received_at = now();
            $partRequest->received_by_user_id = $user?->id;
        }

        if ($targetStatus === TechnicalServicePartRequest::STATUS_SERVICE_VISIT_REQUIRED) {
            $partRequest->requires_service_visit = true;
        }

        $metadata = is_array($partRequest->metadata) ? $partRequest->metadata : [];
        $chargeDecision = $payload['charge_decision'] ?? null;
        $serviceAmount = array_key_exists('service_amount', $payload) ? round((float) ($payload['service_amount'] ?? 0), 2) : null;
        $partAmount = array_key_exists('part_amount', $payload) ? round((float) ($payload['part_amount'] ?? 0), 2) : null;
        $customerMessage = trim((string) ($payload['customer_message'] ?? ''));

        if ($chargeDecision === 'chargeable' && (($serviceAmount ?? 0) + ($partAmount ?? 0)) <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Ücretli parça kararında servis veya parça bedeli girilmelidir.',
            ]);
        }

        if ($chargeDecision === 'chargeable' && $customerMessage === '') {
            throw ValidationException::withMessages([
                'customer_message' => 'Ücretli parça kararında müşteriye gönderilecek mesaj zorunludur.',
            ]);
        }

        if (in_array($chargeDecision, ['free', 'chargeable'], true)) {
            $metadata['charge_decision'] = $chargeDecision;
            $metadata['charge_decision_label'] = $chargeDecision === 'chargeable'
                ? 'Ücretli'
                : 'Ücretsiz / garanti kapsamında';
            $metadata['service_amount'] = $serviceAmount ?? 0.0;
            $metadata['part_amount'] = $partAmount ?? 0.0;
            $metadata['total_amount'] = round(($serviceAmount ?? 0) + ($partAmount ?? 0), 2);
            $metadata['customer_message'] = $customerMessage !== '' ? $customerMessage : null;
            $metadata['charge_decided_at'] = now()->toISOString();
            $metadata['charge_decided_by_user_id'] = $user?->id;
        }

        $partRequest->forceFill([
            'status' => $targetStatus,
            'ops_note' => $note !== '' ? $note : $partRequest->ops_note,
            'partner_message' => trim((string) ($payload['partner_message'] ?? '')) ?: $partRequest->partner_message,
            'shipment_provider' => $payload['shipment_provider'] ?? $partRequest->shipment_provider,
            'tracking_no' => $payload['tracking_no'] ?? $partRequest->tracking_no,
            'metadata' => [
                ...$metadata,
                'last_transition' => [
                    'status' => $targetStatus,
                    'at' => now()->toISOString(),
                    'user_id' => $user?->id,
                ],
            ],
        ])->save();

        if ($partRequest->sourcePartnerAction instanceof TechnicalServicePartnerJobAction
            && in_array($targetStatus, [TechnicalServicePartRequest::STATUS_REJECTED, TechnicalServicePartRequest::STATUS_CLOSED], true)
        ) {
            $partRequest->sourcePartnerAction->forceFill([
                'status' => $targetStatus === TechnicalServicePartRequest::STATUS_REJECTED
                    ? TechnicalServicePartnerJobAction::STATUS_REJECTED
                    : TechnicalServicePartnerJobAction::STATUS_APPLIED,
            ])->save();
        }

        $this->recordEvent($partRequest->request, 'part_request_'.$targetStatus, TechnicalServicePartRequest::labelForStatus($targetStatus), $note !== '' ? $note : null, $user, [
            'part_request_id' => $partRequest->id,
            'status' => $targetStatus,
        ]);

        return $partRequest->refresh();
    }

    public function markReceivedByTechnician(TechnicalServicePartRequest $partRequest, User $user): TechnicalServicePartRequest
    {
        if ($partRequest->status !== TechnicalServicePartRequest::STATUS_SENT) {
            throw ValidationException::withMessages([
                'part_request' => 'Parça teslim alındı aksiyonu sadece parça gönderildikten sonra yapılabilir.',
            ]);
        }

        return $this->transition($partRequest, TechnicalServicePartRequest::STATUS_RECEIVED, $user);
    }

    public function createServiceVisit(TechnicalServicePartRequest $partRequest, ?User $user, string $reason = 'spare_part'): TechnicalServiceRequest
    {
        if (! in_array($partRequest->status, [
            TechnicalServicePartRequest::STATUS_RECEIVED,
            TechnicalServicePartRequest::STATUS_SERVICE_VISIT_REQUIRED,
        ], true)) {
            throw ValidationException::withMessages([
                'part_request' => 'SRV oluşturmak için parça teslim alınmış veya servis gerekli olarak işaretlenmiş olmalıdır.',
            ]);
        }

        $child = $this->serviceVisits->createServiceVisitFromRequest(
            $partRequest->request()->firstOrFail(),
            $user,
            $reason,
            [
                'source_part_request' => $partRequest,
                'source_partner_action_id' => $partRequest->source_partner_action_id,
                'description' => 'Parça sonrası servis: '.$partRequest->part_name,
                'parent_event_type' => 'part_request_srv_created',
                'parent_event_title' => 'SRV kaydı oluşturuldu',
            ],
        );

        $partRequest->forceFill([
            'status' => TechnicalServicePartRequest::STATUS_SERVICE_VISIT_CREATED,
            'requires_service_visit' => true,
            'service_visit_request_id' => $child->id,
            'metadata' => [
                ...(is_array($partRequest->metadata) ? $partRequest->metadata : []),
                'service_visit_created' => [
                    'request_id' => $child->id,
                    'mrn' => $child->mrn,
                    'service_code' => $child->service_code,
                    'created_at' => now()->toISOString(),
                    'created_by_user_id' => $user?->id,
                ],
            ],
        ])->save();

        if ($partRequest->sourcePartnerAction instanceof TechnicalServicePartnerJobAction) {
            $partRequest->sourcePartnerAction->forceFill([
                'status' => TechnicalServicePartnerJobAction::STATUS_APPLIED,
            ])->save();
        }

        return $child->refresh();
    }

    public function hasOpenBlockingPartRequest(TechnicalServiceRequest $request): bool
    {
        return $request->partRequests()
            ->whereIn('status', TechnicalServicePartRequest::ACTIVE_STATUSES)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(TechnicalServicePartRequest $partRequest, bool $forPartner = false): array
    {
        $metadata = is_array($partRequest->metadata) ? $partRequest->metadata : [];

        return [
            'id' => $partRequest->id,
            'technical_service_request_id' => $partRequest->technical_service_request_id,
            'root_request_id' => $partRequest->root_request_id,
            'request_serial_id' => $partRequest->request_serial_id,
            'source_partner_action_id' => $partRequest->source_partner_action_id,
            'status' => $partRequest->status,
            'status_label' => $forPartner ? $partRequest->partnerStatusLabel() : $partRequest->statusLabel(),
            'part_name' => $partRequest->part_name,
            'part_code' => $partRequest->part_code,
            'quantity' => $partRequest->quantity,
            'reason' => $partRequest->reason,
            'technician_note' => $partRequest->technician_note,
            'ops_note' => $forPartner ? null : $partRequest->ops_note,
            'partner_message' => $partRequest->partner_message,
            'shipment_provider' => $partRequest->shipment_provider,
            'tracking_no' => $partRequest->tracking_no,
            'sent_at' => $partRequest->sent_at?->toIso8601String(),
            'received_at' => $partRequest->received_at?->toIso8601String(),
            'requires_service_visit' => (bool) $partRequest->requires_service_visit,
            'service_visit_request_id' => $partRequest->service_visit_request_id,
            'charge_decision' => $metadata['charge_decision'] ?? null,
            'charge_decision_label' => $metadata['charge_decision_label'] ?? null,
            'service_amount' => $metadata['service_amount'] ?? null,
            'service_amount_label' => isset($metadata['service_amount']) ? $this->moneyLabel((float) $metadata['service_amount']) : null,
            'part_amount' => $metadata['part_amount'] ?? null,
            'part_amount_label' => isset($metadata['part_amount']) ? $this->moneyLabel((float) $metadata['part_amount']) : null,
            'total_amount' => $metadata['total_amount'] ?? null,
            'total_amount_label' => isset($metadata['total_amount']) ? $this->moneyLabel((float) $metadata['total_amount']) : null,
            'customer_message' => $forPartner ? null : ($metadata['customer_message'] ?? null),
            'metadata' => $forPartner ? [] : $metadata,
            'created_at' => $partRequest->created_at?->toIso8601String(),
            'updated_at' => $partRequest->updated_at?->toIso8601String(),
        ];
    }

    private function moneyLabel(float $amount): string
    {
        return number_format($amount, 0, ',', '.').' TL';
    }

    private function primarySerial(TechnicalServiceRequest $request): ?TechnicalServiceRequestSerial
    {
        return $request->requestSerials
            ->firstWhere('is_primary', true)
            ?? $request->requestSerials->first();
    }

    private function rootRequest(TechnicalServiceRequest $request): TechnicalServiceRequest
    {
        if (! $request->parent_request_id) {
            return $request;
        }

        return TechnicalServiceRequest::query()
            ->where('mrn', $request->root_mrn)
            ->first()
            ?? $request->parentRequest
            ?? $request;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordEvent(TechnicalServiceRequest $request, string $eventType, string $title, ?string $note, ?User $user, array $metadata = []): void
    {
        $request->events()->create([
            'event_type' => $eventType,
            'title' => $title,
            'note' => $note,
            'from_status' => $request->workflow_status,
            'to_status' => $request->workflow_status,
            'author_user_id' => $user?->id,
            'metadata' => $metadata,
        ]);
    }
}
