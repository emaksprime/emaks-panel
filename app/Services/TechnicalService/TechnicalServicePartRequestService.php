<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServicePartRequest;
use App\Models\TechnicalServicePartnerJobAction;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestSerial;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TechnicalServicePartRequestService
{
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

        $partRequest->forceFill([
            'status' => $targetStatus,
            'ops_note' => $note !== '' ? $note : $partRequest->ops_note,
            'partner_message' => trim((string) ($payload['partner_message'] ?? '')) ?: $partRequest->partner_message,
            'shipment_provider' => $payload['shipment_provider'] ?? $partRequest->shipment_provider,
            'tracking_no' => $payload['tracking_no'] ?? $partRequest->tracking_no,
            'metadata' => [
                ...(is_array($partRequest->metadata) ? $partRequest->metadata : []),
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

        return DB::transaction(function () use ($partRequest, $user, $reason): TechnicalServiceRequest {
            $parent = $partRequest->request()->with(['requestSerials', 'parentRequest'])->lockForUpdate()->firstOrFail();
            $root = $this->rootRequest($parent);
            $sequence = $this->nextServiceSequence($root);
            $serviceCode = sprintf('SRV-%03d', $sequence);
            $mrn = $this->uniqueServiceVisitMrn((string) ($root->root_mrn ?: $root->mrn), $serviceCode);

            $child = TechnicalServiceRequest::query()->create([
                'mrn' => $mrn,
                'parent_request_id' => $parent->id,
                'root_mrn' => $root->root_mrn ?: $root->mrn,
                'service_sequence' => $sequence,
                'service_code' => $serviceCode,
                'service_visit_reason' => $reason,
                'source_part_request_id' => $partRequest->id,
                'source_partner_action_id' => $partRequest->source_partner_action_id,
                'customer_name' => $parent->customer_name,
                'customer_phone' => $parent->customer_phone,
                'customer_city' => $parent->customer_city,
                'customer_district' => $parent->customer_district,
                'service_address' => $parent->service_address,
                'product_name' => $parent->product_name,
                'product_model' => $parent->product_model,
                'brand' => $parent->brand,
                'stock_code' => $parent->stock_code,
                'activation_code' => $parent->activation_code,
                'serial_number' => $parent->serial_number,
                'service_type' => $parent->service_type,
                'status' => TechnicalServiceRequest::STATUS_NEW,
                'workflow_status' => TechnicalServiceRequest::WORKFLOW_NEW_REQUEST,
                'priority' => $parent->priority ?: TechnicalServiceRequest::PRIORITY_MEDIUM,
                'risk_level' => $parent->risk_level ?: TechnicalServiceRequest::RISK_MEDIUM,
                'source_channel' => 'srv_child_request',
                'operation_control_payload' => is_array($parent->operation_control_payload) ? $parent->operation_control_payload : null,
                'operation_control_checked_by_user_id' => $parent->operation_control_checked_by_user_id,
                'operation_control_checked_at' => $parent->operation_control_checked_at,
                'location_latitude' => $parent->location_latitude,
                'location_longitude' => $parent->location_longitude,
                'location_place_id' => $parent->location_place_id,
                'location_formatted_address' => $parent->location_formatted_address,
                'location_map_url' => $parent->location_map_url,
                'location_source' => $parent->location_source,
                'location_accuracy' => $parent->location_accuracy,
                'location_note' => $parent->location_note,
                'building_no' => $parent->building_no,
                'apartment_no' => $parent->apartment_no,
                'door_no' => $parent->door_no,
                'floor_no' => $parent->floor_no,
                'site_name' => $parent->site_name,
                'created_by_user_id' => $user?->id,
                'updated_by_user_id' => $user?->id,
                'description' => 'Parça sonrası servis: '.$partRequest->part_name,
            ]);

            $this->copyRequestSerials($parent, $child);

            $partRequest->forceFill([
                'status' => TechnicalServicePartRequest::STATUS_SERVICE_VISIT_CREATED,
                'requires_service_visit' => true,
                'service_visit_request_id' => $child->id,
                'metadata' => [
                    ...(is_array($partRequest->metadata) ? $partRequest->metadata : []),
                    'service_visit_created' => [
                        'request_id' => $child->id,
                        'mrn' => $child->mrn,
                        'service_code' => $serviceCode,
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

            $this->recordEvent($parent, 'part_request_srv_created', 'SRV kaydı oluşturuldu', $child->mrn, $user, [
                'part_request_id' => $partRequest->id,
                'service_visit_request_id' => $child->id,
                'service_code' => $serviceCode,
            ]);
            $this->recordEvent($child, 'srv_child_created', 'SRV kaydı oluşturuldu', 'Ana talep: '.$parent->mrn, $user, [
                'parent_request_id' => $parent->id,
                'source_part_request_id' => $partRequest->id,
            ]);

            return $child->refresh();
        });
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
        return [
            'id' => $partRequest->id,
            'technical_service_request_id' => $partRequest->technical_service_request_id,
            'root_request_id' => $partRequest->root_request_id,
            'request_serial_id' => $partRequest->request_serial_id,
            'source_partner_action_id' => $partRequest->source_partner_action_id,
            'status' => $partRequest->status,
            'status_label' => $partRequest->statusLabel(),
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
            'metadata' => $forPartner ? [] : ($partRequest->metadata ?? []),
            'created_at' => $partRequest->created_at?->toIso8601String(),
            'updated_at' => $partRequest->updated_at?->toIso8601String(),
        ];
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

    private function nextServiceSequence(TechnicalServiceRequest $root): int
    {
        $rootMrn = $root->root_mrn ?: $root->mrn;
        $max = TechnicalServiceRequest::query()
            ->where(function ($query) use ($root, $rootMrn): void {
                $query->where('parent_request_id', $root->id)
                    ->orWhere('root_mrn', $rootMrn);
            })
            ->max('service_sequence');

        return max(1, ((int) $max) + 1);
    }

    private function uniqueServiceVisitMrn(string $rootMrn, string $serviceCode): string
    {
        $base = $rootMrn.'-'.$serviceCode;
        $candidate = $base;
        $index = 2;

        while (TechnicalServiceRequest::query()->where('mrn', $candidate)->exists()) {
            $candidate = $base.'-'.$index;
            $index++;
        }

        return $candidate;
    }

    private function copyRequestSerials(TechnicalServiceRequest $parent, TechnicalServiceRequest $child): void
    {
        $parent->loadMissing('requestSerials');

        foreach ($parent->requestSerials as $serial) {
            TechnicalServiceRequestSerial::query()->create([
                'technical_service_request_id' => $child->id,
                'mrn' => $child->mrn,
                'serial_number' => $serial->serial_number,
                'product_name' => $serial->product_name,
                'product_model' => $serial->product_model,
                'brand' => $serial->brand,
                'stock_code' => $serial->stock_code,
                'invoice_series' => $serial->invoice_series,
                'invoice_number' => $serial->invoice_number,
                'customer_selected' => (bool) $serial->customer_selected,
                'customer_selectable' => (bool) ($serial->customer_selectable ?? false),
                'customer_visible' => (bool) $serial->customer_visible,
                'hidden_reason' => $serial->hidden_reason,
                'operation_added' => (bool) ($serial->operation_added ?? false),
                'operation_added_by' => $serial->operation_added_by,
                'operation_added_at' => $serial->operation_added_at,
                'customer_phone' => $serial->customer_phone,
                'linked_mrn' => $parent->mrn,
                'operation_note' => $serial->operation_note,
                'warning_labels' => $serial->warning_labels,
                'is_primary' => (bool) $serial->is_primary,
                'is_returned' => (bool) $serial->is_returned,
                'return_note' => $serial->return_note,
                'return_date' => $serial->return_date,
                'return_document_no' => $serial->return_document_no,
                'is_current_latest_sale' => (bool) ($serial->is_current_latest_sale ?? false),
                'color_status' => $serial->color_status,
                'invoice_customer_type' => $serial->invoice_customer_type,
                'source_payload' => [
                    ...(is_array($serial->source_payload) ? $serial->source_payload : []),
                    'copied_from_request_id' => $parent->id,
                    'copied_from_serial_id' => $serial->id,
                ],
            ]);
        }
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
