<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServicePartnerJobAction;
use App\Models\TechnicalServicePartRequest;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestSerial;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TechnicalServiceServiceVisitService
{
    private const SERVICE_VISIT_TYPE = 'Servis';

    public function __construct(private readonly TechnicalServiceCodeGenerator $codeGenerator) {}

    /**
     * @param array<string, mixed> $options
     */
    public function createServiceVisitFromRequest(
        TechnicalServiceRequest $parent,
        ?User $user,
        string $reason,
        array $options = [],
    ): TechnicalServiceRequest {
        return DB::transaction(function () use ($parent, $user, $reason, $options): TechnicalServiceRequest {
            $parent = TechnicalServiceRequest::query()
                ->with(['requestSerials', 'parentRequest'])
                ->lockForUpdate()
                ->findOrFail($parent->id);
            $root = $this->rootRequest($parent);
            $sequence = $this->nextServiceSequence($root);
            $rootMrn = (string) ($root->root_mrn ?: $root->mrn);
            $serviceCode = $this->codeGenerator->serviceCodeForRoot($rootMrn, $sequence);
            $mrn = $this->uniqueServiceVisitMrn($serviceCode);
            $sourcePartRequest = $options['source_part_request'] ?? null;
            $sourcePartnerAction = $options['source_partner_action'] ?? null;

            $child = TechnicalServiceRequest::query()->create([
                'mrn' => $mrn,
                'parent_request_id' => $parent->id,
                'root_mrn' => $rootMrn,
                'service_sequence' => $sequence,
                'service_code' => $serviceCode,
                'service_visit_reason' => $reason,
                'source_part_request_id' => $sourcePartRequest instanceof TechnicalServicePartRequest
                    ? $sourcePartRequest->id
                    : ($options['source_part_request_id'] ?? null),
                'source_partner_action_id' => $sourcePartnerAction instanceof TechnicalServicePartnerJobAction
                    ? $sourcePartnerAction->id
                    : ($options['source_partner_action_id'] ?? null),
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
                'service_type' => self::SERVICE_VISIT_TYPE,
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
                'description' => $options['description'] ?? $this->defaultDescription($reason),
            ]);

            $this->copyRequestSerials($parent, $child);
            $this->markParentDelegatedToServiceVisit($parent, $child, $user);

            $eventMetadata = [
                'service_visit_request_id' => $child->id,
                'service_code' => $serviceCode,
                'reason' => $reason,
                'source_partner_action_id' => $child->source_partner_action_id,
                'source_part_request_id' => $child->source_part_request_id,
            ];

            $this->recordEvent(
                $parent,
                (string) ($options['parent_event_type'] ?? 'service_visit_created'),
                (string) ($options['parent_event_title'] ?? 'SRV kaydı oluşturuldu'),
                $child->mrn,
                $user,
                $eventMetadata,
            );
            $this->recordEvent(
                $child,
                'srv_child_created',
                'SRV kaydı oluşturuldu',
                'Ana talep: '.$parent->mrn,
                $user,
                [
                    'parent_request_id' => $parent->id,
                    ...$eventMetadata,
                ],
            );

            return $child->refresh();
        });
    }

    public function closeParentIfChildCompleted(TechnicalServiceRequest $child, ?User $user): ?TechnicalServiceRequest
    {
        return DB::transaction(function () use ($child, $user): ?TechnicalServiceRequest {
            $child = TechnicalServiceRequest::query()
                ->with('parentRequest')
                ->lockForUpdate()
                ->findOrFail($child->id);

            if ($child->parent_request_id === null || ! $this->isCompleted($child)) {
                return null;
            }

            $parent = TechnicalServiceRequest::query()
                ->lockForUpdate()
                ->find($child->parent_request_id);

            if (! $parent instanceof TechnicalServiceRequest
                || $this->isCancelled($parent)
                || $this->isCompleted($parent)) {
                return null;
            }

            $completedAt = $child->completed_at
                ?? $child->installation_completed_at
                ?? now();
            $serviceCode = (string) ($child->service_code ?: $child->mrn);

            $parent->forceFill([
                'status' => 'Tamamlandı',
                'workflow_status' => 'Tamamlandı',
                'field_status' => 'tamamlandı',
                'completed_at' => $parent->completed_at ?? $completedAt,
                'field_completed_at' => $parent->field_completed_at ?? $completedAt,
                'technician_completed_at' => $parent->technician_completed_at ?? $completedAt,
                'completion_block_reason' => null,
                'next_action' => 'SRV ile tamamlandı',
                'updated_by_user_id' => $user?->id,
            ])->save();

            $this->recordEvent(
                $parent,
                'srv_child_completed_parent_closed',
                $serviceCode.' tamamlandı, ana talep kapatıldı',
                $child->mrn,
                $user,
                [
                    'service_visit_request_id' => $child->id,
                    'service_code' => $child->service_code,
                    'child_mrn' => $child->mrn,
                    'child_completed_at' => $child->completed_at?->toIso8601String(),
                ],
            );

            return $parent->refresh();
        });
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

    private function uniqueServiceVisitMrn(string $serviceCode): string
    {
        $base = $serviceCode;
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
                'is_returned' => (bool) ($serial->is_returned ?? false),
                'is_current_latest_sale' => (bool) ($serial->is_current_latest_sale ?? false),
                'color_status' => $serial->color_status,
                'warning_labels' => $serial->warning_labels,
                'is_primary' => (bool) ($serial->is_primary ?? false),
                'return_note' => $serial->return_note,
                'return_date' => $serial->return_date,
                'return_document_no' => $serial->return_document_no,
                'invoice_customer_type' => $serial->invoice_customer_type,
                'source_payload' => [
                    ...(is_array($serial->source_payload) ? $serial->source_payload : []),
                    'copied_from_request_id' => $parent->id,
                    'copied_from_serial_id' => $serial->id,
                ],
            ]);
        }
    }

    private function markParentDelegatedToServiceVisit(TechnicalServiceRequest $parent, TechnicalServiceRequest $child, ?User $user): void
    {
        $delegationPayload = [
            'request_id' => $child->id,
            'mrn' => $child->mrn,
            'service_code' => $child->service_code,
            'reason' => $child->service_visit_reason,
            'delegated_at' => now()->toISOString(),
            'delegated_by_user_id' => $user?->id,
        ];

        $parent->forceFill([
            'field_status' => 'srv_delegated',
            'requires_second_visit' => false,
            'second_visit_reason' => null,
            'pending_reason' => null,
            'requires_reschedule' => false,
            'reschedule_reason' => null,
            'completion_block_reason' => null,
            'next_action' => 'SRV ile takip ediliyor',
            'updated_by_user_id' => $user?->id,
            'operation_control_payload' => [
                ...(is_array($parent->operation_control_payload) ? $parent->operation_control_payload : []),
                'service_visit_delegation' => $delegationPayload,
            ],
        ])->save();

        $parent->partnerJobActions()
            ->where('status', TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW)
            ->whereIn('action', [
                TechnicalServicePartnerJobAction::ACTION_REVISIT_REQUESTED,
                TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED,
            ])
            ->get()
            ->each(function (TechnicalServicePartnerJobAction $action) use ($delegationPayload): void {
                $payload = is_array($action->payload) ? $action->payload : [];

                $action->forceFill([
                    'status' => TechnicalServicePartnerJobAction::STATUS_APPLIED,
                    'payload' => [
                        ...$payload,
                        'service_visit_created' => $delegationPayload,
                    ],
                ])->save();
            });
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function recordEvent(
        TechnicalServiceRequest $request,
        string $eventType,
        string $title,
        ?string $note,
        ?User $user,
        array $metadata,
    ): void {
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

    private function isCancelled(TechnicalServiceRequest $request): bool
    {
        return $request->cancelled_at !== null
            || in_array($request->status, ['İptal', 'Iptal', 'Ä°ptal'], true)
            || in_array($request->workflow_status, ['İptal', 'Iptal', 'Ä°ptal'], true);
    }

    private function isCompleted(TechnicalServiceRequest $request): bool
    {
        return $request->completed_at !== null
            || $request->installation_completed_at !== null
            || in_array($request->status, ['Tamamlandı', 'Tamamlandi', 'TamamlandÄ±'], true)
            || in_array($request->workflow_status, ['Tamamlandı', 'Tamamlandi', 'TamamlandÄ±'], true);
    }

    private function defaultDescription(string $reason): string
    {
        return match ($reason) {
            'spare_part' => 'Parça sonrası servis',
            'revisit' => 'Tekrar ziyaret servisi',
            default => 'Ek servis ziyareti',
        };
    }
}
