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

    public function __construct(
        private readonly TechnicalServiceCodeGenerator $codeGenerator,
        private readonly WarrantyService $warranties,
    ) {}

    public function createCleanServiceVisitFromCompletedRequest(
        TechnicalServiceRequest $completedRequest,
        ?User $user,
        string $reopenReason,
        ?string $reopenNote = null,
        string $reopenType = 'service_request',
    ): TechnicalServiceRequest {
        return DB::transaction(function () use ($completedRequest, $user, $reopenReason, $reopenNote, $reopenType): TechnicalServiceRequest {
            $serviceVisitReason = $this->reopenTypeToServiceVisitReason($reopenType);
            $child = $this->createServiceVisitFromRequest(
                $completedRequest,
                $user,
                $serviceVisitReason,
                [
                    'description' => $reopenNote ?: 'Tamamlanan talep için yeni servis ziyareti',
                    'copy_operation_control' => false,
                    'parent_event_type' => 'technical_service_request_reopened_as_srv',
                    'parent_event_title' => 'Tamamlanan talep için yeni SRV açıldı',
                    'reopen_type' => $reopenType,
                    'reopen_reason' => $reopenReason,
                    'reopen_note' => $reopenNote,
                ],
            );

            $child->forceFill([
                'reopened_at' => now(),
                'reopened_by_user_id' => $user?->id,
                'reopen_reason' => $reopenReason,
                'reopen_note' => $reopenNote,
                'reopen_count' => 1,
                'pending_reason' => $reopenNote ?: $reopenReason,
                'updated_by_user_id' => $user?->id,
            ])->save();

            $parent = TechnicalServiceRequest::query()
                ->lockForUpdate()
                ->findOrFail($completedRequest->id);
            $reopenCount = ((int) $parent->reopen_count) + 1;

            $parent->forceFill([
                'reopened_at' => now(),
                'reopened_by_user_id' => $user?->id,
                'reopen_reason' => $reopenReason,
                'reopen_note' => $reopenNote,
                'reopen_count' => $reopenCount,
                'updated_by_user_id' => $user?->id,
            ])->save();

            $parent->events()->create([
                'event_type' => 'technical_service_request_reopened',
                'title' => 'Talep yeni SRV ile tekrar açıldı',
                'note' => $reopenNote ?: $child->mrn,
                'from_status' => $parent->workflow_status ?: $parent->status,
                'to_status' => TechnicalServiceRequest::STATUS_NEW,
                'author_user_id' => $user?->id,
                'metadata' => [
                    'reason' => $reopenReason,
                    'reopen_type' => $reopenType,
                    'note' => $reopenNote,
                    'user_id' => $user?->id,
                    'service_visit_request_id' => $child->id,
                    'service_code' => $child->service_code,
                    'child_mrn' => $child->mrn,
                    'reopen_count' => $reopenCount,
                    'parent_remains_completed' => true,
                ],
            ]);

            $this->recordEvent(
                $parent,
                'technical_service_request_reopened_history',
                'Talep yeni SRV ile tekrar açıldı',
                $reopenNote ?: $child->mrn,
                $user,
                [
                    'reason' => $reopenReason,
                    'reopen_type' => $reopenType,
                    'note' => $reopenNote,
                    'user_id' => $user?->id,
                    'service_visit_request_id' => $child->id,
                    'service_code' => $child->service_code,
                    'child_mrn' => $child->mrn,
                    'reopen_count' => $reopenCount,
                    'parent_remains_completed' => true,
                ],
            );

            return $child->refresh();
        });
    }

    public function reopenAccidentalCompletionInPlace(
        TechnicalServiceRequest $request,
        ?User $user,
        string $reopenReason,
        ?string $reopenNote = null,
    ): TechnicalServiceRequest {
        return DB::transaction(function () use ($request, $user, $reopenReason, $reopenNote): TechnicalServiceRequest {
            $request = TechnicalServiceRequest::query()
                ->with(['events' => fn ($query) => $query->latest()])
                ->lockForUpdate()
                ->findOrFail($request->id);

            $previousWorkflow = $this->previousNonCompletedWorkflowStatus($request) ?? 'Son Kontrol';
            $reopenCount = ((int) $request->reopen_count) + 1;

            $request->forceFill([
                'status' => $previousWorkflow,
                'workflow_status' => $previousWorkflow,
                'completed_at' => null,
                'installation_completed_at' => null,
                'field_completed_at' => null,
                'technician_completed_at' => null,
                'checklist_completed_at' => null,
                'customer_closure_approval_status' => null,
                'customer_closure_approved_at' => null,
                'field_status' => null,
                'reopened_at' => now(),
                'reopened_by_user_id' => $user?->id,
                'reopen_reason' => $reopenReason,
                'reopen_note' => $reopenNote,
                'reopen_count' => $reopenCount,
                'next_action' => 'Yanlış kapanış geri alındı',
                'updated_by_user_id' => $user?->id,
            ])->save();

            $warrantyCard = $this->warranties->revokeCompletedInstallationForRequest($request, $user);

            $this->recordEvent(
                $request,
                'technical_service_accidental_completion_reopened',
                'Yanlışlıkla tamamlanan talep geri açıldı',
                $reopenNote ?: $reopenReason,
                $user,
                [
                    'reason' => $reopenReason,
                    'note' => $reopenNote,
                    'restored_workflow_status' => $previousWorkflow,
                    'reopen_count' => $reopenCount,
                    'warranty_revoked' => $warrantyCard !== null,
                    'warranty_card_id' => $warrantyCard?->id,
                    'warranty_status' => $warrantyCard?->status,
                ],
            );

            return $request->refresh();
        });
    }

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
                'operation_control_payload' => ($options['copy_operation_control'] ?? true) && is_array($parent->operation_control_payload) ? $parent->operation_control_payload : null,
                'operation_control_checked_by_user_id' => ($options['copy_operation_control'] ?? true) ? $parent->operation_control_checked_by_user_id : null,
                'operation_control_checked_at' => ($options['copy_operation_control'] ?? true) ? $parent->operation_control_checked_at : null,
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
                'reopen_reason' => $options['reopen_reason'] ?? null,
                'reopen_note' => $options['reopen_note'] ?? null,
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

    private function reopenTypeToServiceVisitReason(string $reopenType): string
    {
        return match ($reopenType) {
            'revisit' => 'revisit',
            default => 'service_request',
        };
    }

    private function previousNonCompletedWorkflowStatus(TechnicalServiceRequest $request): ?string
    {
        $candidates = [];

        foreach ($request->events as $event) {
            foreach ([$event->to_status, $event->from_status] as $status) {
                $status = trim((string) $status);

                if ($status === '' || $this->isCompletedStatus($status) || $this->isCancelledStatus($status)) {
                    continue;
                }

                $candidates[] = $status;
            }
        }

        foreach ($candidates as $status) {
            if (! in_array($this->statusToken($status), ['yeni', 'yenitalep'], true)) {
                return $status;
            }
        }

        return $candidates[0] ?? null;
    }

    private function isCompletedStatus(?string $status): bool
    {
        $token = $this->statusToken($status);

        return in_array($token, ['tamamlandi', 'tamamlanda', 'tamamland'], true);
    }

    private function isCancelledStatus(?string $status): bool
    {
        return str_ends_with($this->statusToken($status), 'ptal');
    }

    private function statusToken(?string $status): string
    {
        return \Illuminate\Support\Str::of((string) $status)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->value();
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
            'service_request' => 'Servis talebi',
            default => 'Ek servis ziyareti',
        };
    }
}
