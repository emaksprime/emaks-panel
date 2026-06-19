<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServicePartRequest;
use App\Models\TechnicalServicePartnerJobAction;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestSerial;
use App\Models\User;
use App\Services\Payments\PaymentProviderManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class TechnicalServicePartRequestService
{
    public function __construct(
        private readonly TechnicalServiceServiceVisitService $serviceVisits,
        private readonly PaymentProviderManager $paymentProviderManager,
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

        $this->assertNoOpenPartRequest($request);

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
     * @param  array<string, mixed>  $data
     */
    public function createFromOperations(TechnicalServiceRequest $request, ?User $user, array $data): TechnicalServicePartRequest
    {
        $request->loadMissing(['requestSerials', 'parentRequest']);
        $serial = $this->primarySerial($request);
        $partName = trim((string) ($data['part_name'] ?? $data['product_name'] ?? ''));

        if ($partName === '') {
            $partName = 'Yedek parça';
        }

        $note = trim((string) ($data['note'] ?? $data['description'] ?? ''));

        $part = TechnicalServicePartRequest::query()->create([
            'technical_service_request_id' => $request->id,
            'root_request_id' => $this->rootRequest($request)->id,
            'request_serial_id' => $serial?->id,
            'source_partner_action_id' => null,
            'requested_by_user_id' => $user?->id,
            'requested_by_technician_id' => $request->technical_service_technician_id,
            'status' => TechnicalServicePartRequest::STATUS_OPS_REVIEW,
            'part_name' => $partName,
            'part_code' => trim((string) ($data['part_code'] ?? '')) ?: null,
            'quantity' => max(1, (int) ($data['quantity'] ?? 1)),
            'reason' => $note !== '' ? $note : null,
            'technician_note' => $note !== '' ? $note : null,
            'metadata' => [
                'source' => 'ops_part_request',
                'request_mrn' => $request->mrn,
                'serial_number' => $serial?->serial_number ?? $request->serial_number,
                'created_by_user_id' => $user?->id,
            ],
        ]);

        $this->recordEvent($request, 'part_request_created', 'Parça talebi oluşturuldu', $note !== '' ? $note : null, $user, [
            'part_request_id' => $part->id,
            'source' => 'ops_part_request',
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

        $this->assertTransitionAllowed($partRequest, $targetStatus);

        $note = trim((string) ($payload['note'] ?? ''));
        if ($targetStatus === TechnicalServicePartRequest::STATUS_REJECTED && $note === '') {
            throw ValidationException::withMessages([
                'note' => 'Parça talebini reddetmek için operasyon notu zorunludur.',
            ]);
        }

        if ($targetStatus === TechnicalServicePartRequest::STATUS_SENT && $partRequest->isChargePaymentPending()) {
            throw ValidationException::withMessages([
                'payment' => 'Ücretli parça ödemesi alınmadan parça gönderildi işaretlenemez.',
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

        if ($chargeDecision === 'chargeable' && ($partAmount ?? 0) <= 0) {
            throw ValidationException::withMessages([
                'part_amount' => 'Ücretli parça kararında parça bedeli 0 TL üzerinde olmalıdır.',
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
            $metadata['charge_status'] = $chargeDecision === 'chargeable'
                ? ($metadata['charge_status'] ?? TechnicalServiceMountPayment::STATUS_PENDING)
                : 'none';

            if ($chargeDecision === 'free') {
                unset(
                    $metadata['customer_charge_payment_id'],
                    $metadata['payment_id'],
                    $metadata['payment_url'],
                    $metadata['customer_charge']
                );
            }
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

        $customerCharge = null;
        if ($chargeDecision === 'chargeable') {
            $customerCharge = $this->createCustomerChargePayment(
                $partRequest->refresh(),
                $serviceAmount ?? 0.0,
                $partAmount ?? 0.0,
                $customerMessage,
                $user,
            );

            $metadata = is_array($partRequest->metadata) ? $partRequest->metadata : [];
            $metadata['charge_status'] = $customerCharge->status;
            $metadata['customer_charge_payment_id'] = $customerCharge->id;
            $metadata['payment_id'] = $customerCharge->id;
            $metadata['payment_url'] = $customerCharge->payment_url;
            $metadata['customer_charge'] = $this->customerChargePayload($customerCharge);
            $partRequest->forceFill(['metadata' => $metadata])->save();
        }

        if ($partRequest->sourcePartnerAction instanceof TechnicalServicePartnerJobAction
            && in_array($targetStatus, [TechnicalServicePartRequest::STATUS_REJECTED, TechnicalServicePartRequest::STATUS_CLOSED], true)
        ) {
            $partRequest->sourcePartnerAction->forceFill([
                'status' => $targetStatus === TechnicalServicePartRequest::STATUS_REJECTED
                    ? TechnicalServicePartnerJobAction::STATUS_REJECTED
                    : TechnicalServicePartnerJobAction::STATUS_APPLIED,
            ])->save();
        }

        $this->recordEvent($partRequest->request, 'part_request_'.$targetStatus, $this->eventTitleForTransition($partRequest, $targetStatus), $note !== '' ? $note : null, $user, [
            'part_request_id' => $partRequest->id,
            'status' => $targetStatus,
            'customer_charge_payment_id' => $customerCharge?->id,
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

    /**
     * @return array{part_request: TechnicalServicePartRequest, service_visit: TechnicalServiceRequest}
     */
    public function receiveAndPrepareServiceVisit(TechnicalServicePartRequest $partRequest, User $user): array
    {
        return DB::transaction(function () use ($partRequest, $user): array {
            $partRequest = TechnicalServicePartRequest::query()
                ->with(['request.technicianRecord'])
                ->lockForUpdate()
                ->findOrFail($partRequest->id);

            $serviceVisit = $this->existingServiceVisitForPartRequest($partRequest);
            if (! $serviceVisit instanceof TechnicalServiceRequest) {
                $receivedPartRequest = $partRequest->status === TechnicalServicePartRequest::STATUS_SENT
                    ? $this->transition($partRequest, TechnicalServicePartRequest::STATUS_RECEIVED, $user)
                    : $partRequest;

                if (! in_array($receivedPartRequest->status, [
                    TechnicalServicePartRequest::STATUS_RECEIVED,
                    TechnicalServicePartRequest::STATUS_SERVICE_VISIT_REQUIRED,
                ], true)) {
                    throw ValidationException::withMessages([
                        'part_request' => 'Parça teslim alındıktan sonra SRV hazırlanabilir.',
                    ]);
                }

                $serviceVisit = $this->createServiceVisit($receivedPartRequest, $user, 'spare_part');
                $partRequest = $receivedPartRequest->refresh();
            }

            $serviceVisit = $this->assignServiceVisitToCurrentTechnician($partRequest, $serviceVisit, $user);
            $partRequest = $partRequest->refresh();

            return [
                'part_request' => $partRequest,
                'service_visit' => $serviceVisit,
            ];
        });
    }

    public function createServiceVisit(TechnicalServicePartRequest $partRequest, ?User $user, string $reason = 'spare_part'): TechnicalServiceRequest
    {
        $existing = $this->existingServiceVisitForPartRequest($partRequest);
        if ($existing instanceof TechnicalServiceRequest) {
            return $existing;
        }

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
                'copy_operation_control' => false,
                'parent_event_type' => 'part_request_srv_created',
                'parent_event_title' => 'Parça sonrası servis oluşturuldu',
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

    private function existingServiceVisitForPartRequest(TechnicalServicePartRequest $partRequest): ?TechnicalServiceRequest
    {
        $serviceVisitId = $partRequest->service_visit_request_id
            ?? (is_array($partRequest->metadata) ? ($partRequest->metadata['service_visit_created']['request_id'] ?? null) : null);

        if ($serviceVisitId !== null) {
            $serviceVisit = TechnicalServiceRequest::query()
                ->whereKey((int) $serviceVisitId)
                ->whereNull('cancelled_at')
                ->first();

            if ($serviceVisit instanceof TechnicalServiceRequest) {
                return $serviceVisit;
            }
        }

        return TechnicalServiceRequest::query()
            ->where('source_part_request_id', $partRequest->id)
            ->whereNull('cancelled_at')
            ->whereNotIn('status', ['İptal', 'Iptal'])
            ->whereNotIn('workflow_status', ['İptal', 'Iptal'])
            ->latest('id')
            ->first();
    }

    private function assignServiceVisitToCurrentTechnician(
        TechnicalServicePartRequest $partRequest,
        TechnicalServiceRequest $serviceVisit,
        User $user,
    ): TechnicalServiceRequest {
        $parent = $partRequest->request()->with('technicianRecord')->first();
        if (! $parent instanceof TechnicalServiceRequest || $parent->technical_service_technician_id === null) {
            return $serviceVisit->refresh();
        }

        $metadata = is_array($serviceVisit->operation_control_payload) ? $serviceVisit->operation_control_payload : [];
        $metadata['part_received_service_visit_assignment'] = [
            'source_part_request_id' => $partRequest->id,
            'parent_request_id' => $parent->id,
            'assigned_from_parent_technician_id' => $parent->technical_service_technician_id,
            'assigned_at' => now()->toISOString(),
            'assigned_by_user_id' => $user->id,
        ];

        $serviceVisit->forceFill([
            'technical_service_technician_id' => $parent->technical_service_technician_id,
            'technician_name' => $parent->technicianRecord?->name ?: $parent->technician_name,
            'technician_approval_status' => 'onayladı',
            'technician_approved_at' => $serviceVisit->technician_approved_at ?? now(),
            'scheduled_at' => null,
            'scheduled_date' => null,
            'scheduled_time' => null,
            'field_status' => null,
            'next_action' => 'Usta yeni randevu önerecek',
            'status' => 'Atandı',
            'workflow_status' => 'Usta Onayı Bekleyen',
            'operation_control_payload' => $metadata,
            'updated_by_user_id' => $user->id,
        ])->save();

        if (! $serviceVisit->events()
            ->where('event_type', 'part_received_service_visit_assigned')
            ->exists()
        ) {
            $serviceVisit->events()->create([
                'event_type' => 'part_received_service_visit_assigned',
                'title' => 'Parça sonrası SRV ustaya açıldı',
                'note' => 'Usta yeni randevu önerecek.',
                'from_status' => TechnicalServiceRequest::WORKFLOW_NEW_REQUEST,
                'to_status' => 'Usta Onayı Bekleyen',
                'author_user_id' => $user->id,
                'metadata' => [
                    'source_part_request_id' => $partRequest->id,
                    'parent_request_id' => $parent->id,
                    'technical_service_technician_id' => $parent->technical_service_technician_id,
                ],
            ]);
        }

        return $serviceVisit->refresh();
    }

    public function hasOpenBlockingPartRequest(TechnicalServiceRequest $request): bool
    {
        return $request->partRequests()
            ->whereIn('status', TechnicalServicePartRequest::ACTIVE_STATUSES)
            ->exists();
    }

    private function assertNoOpenPartRequest(TechnicalServiceRequest $request): void
    {
        if (! $this->hasOpenBlockingPartRequest($request)) {
            return;
        }

        throw ValidationException::withMessages([
            'part_request' => 'Açık parça talebi varken yeni parça talebi oluşturulamaz.',
        ]);
    }

    private function assertTransitionAllowed(TechnicalServicePartRequest $partRequest, string $targetStatus): void
    {
        $currentStatus = (string) $partRequest->status;

        if ($targetStatus === TechnicalServicePartRequest::STATUS_APPROVED
            && ! in_array($currentStatus, [
                TechnicalServicePartRequest::STATUS_REQUESTED,
                TechnicalServicePartRequest::STATUS_OPS_REVIEW,
                TechnicalServicePartRequest::STATUS_APPROVED,
            ], true)
        ) {
            throw ValidationException::withMessages([
                'status' => 'Parça kararı sadece inceleme aşamasındaki talepte verilebilir.',
            ]);
        }

        if ($targetStatus === TechnicalServicePartRequest::STATUS_RECEIVED
            && ! in_array($currentStatus, [
                TechnicalServicePartRequest::STATUS_SENT,
                TechnicalServicePartRequest::STATUS_RECEIVED,
            ], true)
        ) {
            throw ValidationException::withMessages([
                'status' => 'Parça teslim alındı aksiyonu sadece parça gönderildikten sonra yapılabilir.',
            ]);
        }

        if ($targetStatus === TechnicalServicePartRequest::STATUS_SERVICE_VISIT_REQUIRED
            && ! in_array($currentStatus, [
                TechnicalServicePartRequest::STATUS_RECEIVED,
                TechnicalServicePartRequest::STATUS_SERVICE_VISIT_REQUIRED,
            ], true)
        ) {
            throw ValidationException::withMessages([
                'status' => 'Tekrar servis kararı için parça önce teslim alınmış olmalıdır.',
            ]);
        }
    }

    private function eventTitleForTransition(TechnicalServicePartRequest $partRequest, string $targetStatus): string
    {
        if ($targetStatus === TechnicalServicePartRequest::STATUS_APPROVED && $partRequest->isChargePaymentPending()) {
            return 'Parça ödemesi istendi';
        }

        if ($targetStatus === TechnicalServicePartRequest::STATUS_APPROVED && $partRequest->isChargePaymentPaid()) {
            return 'Parça ödemesi alındı';
        }

        return TechnicalServicePartRequest::labelForStatus($targetStatus);
    }

    private function nextActionLabel(TechnicalServicePartRequest $partRequest, bool $forPartner = false): string
    {
        if ($partRequest->isChargePaymentPending()) {
            return 'Müşteri parça ödemesi bekleniyor';
        }

        if ($partRequest->isChargePaymentPaid() && $partRequest->status === TechnicalServicePartRequest::STATUS_APPROVED) {
            return 'Parça ödemesi alındı; tedarik/gönderim operasyon tarafında';
        }

        return match ((string) $partRequest->status) {
            TechnicalServicePartRequest::STATUS_REQUESTED,
            TechnicalServicePartRequest::STATUS_OPS_REVIEW => $forPartner
                ? 'Parça talebi operasyon incelemesinde'
                : 'Parça talebi inceleniyor',
            TechnicalServicePartRequest::STATUS_APPROVED => 'Parça tedarik/gönderim bekliyor',
            TechnicalServicePartRequest::STATUS_ORDERED => 'Parça tedarikte',
            TechnicalServicePartRequest::STATUS_SENT => $forPartner
                ? 'Parça gönderildi; teslim aldığınızda işaretleyin'
                : 'Parça gönderildi; usta teslim almalı',
            TechnicalServicePartRequest::STATUS_RECEIVED => $forPartner
                ? 'Parça teslim alındı; operasyon servis planını netleştiriyor'
                : 'Parça teslim alındı; tekrar servis kararını verin',
            TechnicalServicePartRequest::STATUS_SERVICE_VISIT_REQUIRED => 'Parça sonrası servis gerekli',
            TechnicalServicePartRequest::STATUS_SERVICE_VISIT_CREATED => 'Parça sonrası servis oluşturuldu',
            TechnicalServicePartRequest::STATUS_REJECTED => 'Parça talebi reddedildi',
            TechnicalServicePartRequest::STATUS_CLOSED => 'Parça talebi kapatıldı',
            default => 'Parça talebi takipte',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(TechnicalServicePartRequest $partRequest, bool $forPartner = false): array
    {
        $metadata = is_array($partRequest->metadata) ? $partRequest->metadata : [];
        $paymentId = $metadata['payment_id'] ?? $metadata['customer_charge_payment_id'] ?? null;
        $customerCharge = null;
        if (! $forPartner && $paymentId !== null) {
            $payment = TechnicalServiceMountPayment::query()->find((int) $paymentId);
            if ($payment instanceof TechnicalServiceMountPayment) {
                $customerCharge = $this->customerChargePayload($payment);
            }
        }

        return [
            'id' => $partRequest->id,
            'technical_service_request_id' => $partRequest->technical_service_request_id,
            'root_request_id' => $partRequest->root_request_id,
            'request_serial_id' => $partRequest->request_serial_id,
            'source_partner_action_id' => $partRequest->source_partner_action_id,
            'status' => $partRequest->status,
            'status_label' => $forPartner ? $partRequest->partnerStatusLabel() : $partRequest->statusLabel(),
            'next_action_label' => $this->nextActionLabel($partRequest, $forPartner),
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
            'charge_status' => $customerCharge['status'] ?? $metadata['charge_status'] ?? null,
            'is_payment_required' => $partRequest->isChargePaymentPending(),
            'is_payment_paid' => $partRequest->isChargePaymentPaid(),
            'can_ship' => $partRequest->canBeShipped(),
            'can_create_service_visit' => in_array($partRequest->status, [
                TechnicalServicePartRequest::STATUS_RECEIVED,
                TechnicalServicePartRequest::STATUS_SERVICE_VISIT_REQUIRED,
            ], true),
            'payment_id' => $paymentId,
            'payment_url' => $forPartner ? null : ($customerCharge['payment_url'] ?? $metadata['payment_url'] ?? null),
            'payment_reference' => $customerCharge['payment_reference'] ?? $metadata['payment_reference'] ?? $metadata['provider_reference'] ?? null,
            'provider_reference' => $customerCharge['provider_reference'] ?? $metadata['provider_reference'] ?? null,
            'payment_provider' => $customerCharge['provider'] ?? $metadata['payment_provider'] ?? null,
            'paid_at' => $customerCharge['paid_at'] ?? $metadata['paid_at'] ?? null,
            'paid_amount' => $customerCharge['total_amount'] ?? $metadata['paid_amount'] ?? null,
            'paid_amount_label' => isset($customerCharge['total_amount'])
                ? $this->moneyLabel((float) $customerCharge['total_amount'])
                : (isset($metadata['paid_amount']) ? $this->moneyLabel((float) $metadata['paid_amount']) : null),
            'customer_charge' => $customerCharge,
            'metadata' => $forPartner ? [] : $metadata,
            'created_at' => $partRequest->created_at?->toIso8601String(),
            'updated_at' => $partRequest->updated_at?->toIso8601String(),
        ];
    }

    private function createCustomerChargePayment(
        TechnicalServicePartRequest $partRequest,
        float $serviceAmount,
        float $partAmount,
        string $customerMessage,
        ?User $user,
    ): TechnicalServiceMountPayment {
        $request = $partRequest->request()->with('parentRequest')->firstOrFail();
        $sessionId = $this->mountSessionIdForRequest($request);
        if ($sessionId === null) {
            throw ValidationException::withMessages([
                'payment' => 'Parça ödeme linki oluşturmak için talebe bağlı ödeme oturumu bulunamadı.',
            ]);
        }

        $totalAmount = round($serviceAmount + $partAmount, 2);
        $purpose = $serviceAmount > 0 && $partAmount > 0
            ? 'service_and_part_payment'
            : 'part_payment';

        $payment = TechnicalServiceMountPayment::query()->create([
            'technical_service_mount_session_id' => $sessionId,
            'technical_service_request_id' => $request->id,
            'provider' => $this->paymentProviderManager->providerName(),
            'provider_reference' => null,
            'status' => TechnicalServiceMountPayment::STATUS_PENDING,
            'amount' => $totalAmount,
            'currency' => 'TRY',
            'raw_payload' => [
                'source' => 'operation_customer_charge',
                'provider_environment' => $this->paymentProviderManager->environment(),
                'technical_service_request_id' => $request->id,
                'root_request_id' => $request->parent_request_id ?: $request->id,
                'mrn' => $request->mrn,
                'service_code' => $request->service_code,
                'purpose' => $purpose,
                'charge_type' => $purpose,
                'service_amount' => $serviceAmount,
                'part_amount' => $partAmount,
                'total_amount' => $totalAmount,
                'part_request_id' => $partRequest->id,
                'message_template' => $customerMessage,
                'note' => 'Parça talebi #'.$partRequest->id,
                'created_by_user_id' => $user?->id,
            ],
        ]);

        try {
            $this->paymentProviderManager->createPayment($payment);
        } catch (Throwable $exception) {
            $payment->delete();

            throw ValidationException::withMessages([
                'payment' => $exception->getMessage(),
            ]);
        }

        return $payment->refresh();
    }

    private function mountSessionIdForRequest(TechnicalServiceRequest $request): ?int
    {
        if ($request->mount_session_id !== null) {
            return (int) $request->mount_session_id;
        }

        $root = $this->rootRequest($request);

        return $root->mount_session_id !== null ? (int) $root->mount_session_id : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function customerChargePayload(TechnicalServiceMountPayment $payment): array
    {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $serviceAmount = round((float) ($payload['service_amount'] ?? 0), 2);
        $partAmount = round((float) ($payload['part_amount'] ?? 0), 2);
        $totalAmount = round((float) $payment->amount, 2);

        return [
            'id' => $payment->id,
            'status' => $payment->status,
            'status_label' => $this->paymentStatusLabel($payment->status),
            'service_amount' => $serviceAmount,
            'service_amount_label' => $this->moneyLabel($serviceAmount),
            'part_amount' => $partAmount,
            'part_amount_label' => $this->moneyLabel($partAmount),
            'total_amount' => $totalAmount,
            'total_amount_label' => $this->moneyLabel($totalAmount),
            'payment_url' => $payment->payment_url,
            'provider' => $payment->provider,
            'provider_reference' => $payment->provider_reference,
            'payment_reference' => $payment->provider_reference,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'currency' => $payment->currency,
        ];
    }

    private function paymentStatusLabel(?string $status): string
    {
        return match ($status) {
            TechnicalServiceMountPayment::STATUS_PAID => 'Ödendi',
            TechnicalServiceMountPayment::STATUS_FAILED => 'Ödeme başarısız',
            TechnicalServiceMountPayment::STATUS_CANCELLED => 'İptal edildi',
            TechnicalServiceMountPayment::STATUS_EXPIRED => 'Süresi doldu',
            TechnicalServiceMountPayment::STATUS_PENDING => 'Ödeme bekliyor',
            default => 'Ödeme bilgisi yok',
        };
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
