<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceAdminOverride;
use App\Models\TechnicalServiceAuditLog;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceTechnician;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class TechnicalServiceAdminOverrideService
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function fieldPolicy(): array
    {
        return [
            'customer_name' => ['column' => 'customer_name', 'label' => 'Müşteri adı', 'group' => 'customer', 'sensitive' => false],
            'customer_phone' => ['column' => 'customer_phone', 'label' => 'Müşteri telefonu', 'group' => 'customer', 'sensitive' => false],
            'customer_address' => ['column' => 'service_address', 'label' => 'Servis adresi', 'group' => 'customer', 'sensitive' => false],
            'city' => ['column' => 'customer_city', 'label' => 'İl', 'group' => 'customer', 'sensitive' => false],
            'district' => ['column' => 'customer_district', 'label' => 'İlçe', 'group' => 'customer', 'sensitive' => false],
            'latitude' => ['column' => 'location_latitude', 'label' => 'Konum enlem', 'group' => 'route', 'sensitive' => false],
            'longitude' => ['column' => 'location_longitude', 'label' => 'Konum boylam', 'group' => 'route', 'sensitive' => false],
            'plus_code' => ['column' => 'location_place_id', 'label' => 'Konum kodu', 'group' => 'route', 'sensitive' => false],
            'google_formatted_address' => ['column' => 'location_formatted_address', 'label' => 'Google adres özeti', 'group' => 'route', 'sensitive' => false],
            'appointment_at' => ['column' => 'scheduled_at', 'label' => 'Randevu zamanı', 'group' => 'schedule', 'sensitive' => false],
            'priority' => ['column' => 'priority', 'label' => 'Öncelik', 'group' => 'workflow', 'sensitive' => false],
            'assigned_technician_id' => ['column' => 'technical_service_technician_id', 'label' => 'Atanan usta', 'group' => 'assignment', 'sensitive' => false],
            'technician_route_distance' => ['column' => 'travel_round_trip_km', 'label' => 'Usta yol mesafesi', 'group' => 'earning', 'sensitive' => false],
            'route_earning' => ['column' => 'travel_fee_amount', 'label' => 'Yol hakedişi', 'group' => 'earning', 'sensitive' => false],
            'labor_earning' => ['column' => 'technician_payment_amount', 'label' => 'İşçilik hakedişi', 'group' => 'earning', 'sensitive' => false],
            'operation_note' => ['column' => 'operation_control_payload.operation_note', 'label' => 'Operasyon notu', 'group' => 'workflow', 'sensitive' => false],
            'appointment_note' => ['column' => 'operation_control_payload.appointment_note', 'label' => 'Randevu notu', 'group' => 'schedule', 'sensitive' => false],
            'earning_note' => ['column' => 'operation_control_payload.earning_note', 'label' => 'Hakediş notu', 'group' => 'earning', 'sensitive' => false],
            'serial_no' => ['column' => 'serial_number', 'label' => 'Seri numarası', 'group' => 'serial', 'sensitive' => true],
            'activation_code' => ['column' => 'activation_code', 'label' => 'Aktivasyon kodu', 'group' => 'serial', 'sensitive' => true],
            'product_code' => ['column' => 'stock_code', 'label' => 'Ürün stok kodu', 'group' => 'serial', 'sensitive' => true],
            'product_model' => ['column' => 'product_model', 'label' => 'Ürün modeli', 'group' => 'serial', 'sensitive' => true],
            'customer_collection_note' => ['column' => 'operation_control_payload.customer_collection_note', 'label' => 'Tahsilat düzeltme notu', 'group' => 'payment', 'sensitive' => true],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function blockedFields(): array
    {
        return [
            'mrn',
            'service_code',
            'root_mrn',
            'parent_request_id',
            'paid_payment_amount',
            'customer_collection',
            'mikro_raw',
            'mssql_raw',
            'event_history_delete',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function submit(
        TechnicalServiceRequest $request,
        array $payload,
        ?Authenticatable $actor,
        bool $forceRequest = false,
        string $source = TechnicalServiceAdminOverride::SOURCE_ADMIN_APPLY,
    ): TechnicalServiceAdminOverride {
        $fieldKey = trim((string) ($payload['field_key'] ?? ''));
        $reason = trim((string) ($payload['reason'] ?? ''));

        $policy = $this->policyFor($fieldKey);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Düzeltme nedeni zorunludur.']);
        }

        $newValue = $payload['new_value'] ?? null;
        $mode = (string) ($payload['mode'] ?? 'apply');
        $mustRequestApproval = $forceRequest || $mode === 'request' || (bool) ($policy['sensitive'] ?? false);

        return DB::transaction(function () use ($request, $policy, $fieldKey, $newValue, $reason, $actor, $source, $mustRequestApproval, $payload): TechnicalServiceAdminOverride {
            $oldValue = $this->readValue($request, (string) $policy['column']);
            $recomputeFlags = $this->recomputeFlagsForField($fieldKey);
            $status = $mustRequestApproval ? TechnicalServiceAdminOverride::STATUS_PENDING : TechnicalServiceAdminOverride::STATUS_APPLIED;
            $now = now();

            $override = TechnicalServiceAdminOverride::query()->create([
                ...$this->requestLedgerContext($request),
                'field_key' => $fieldKey,
                'field_label' => (string) $policy['label'],
                'field_group' => (string) $policy['group'],
                'source' => $mustRequestApproval ? $source : TechnicalServiceAdminOverride::SOURCE_ADMIN_APPLY,
                'status' => $status,
                'old_value' => $this->valuePayload($oldValue),
                'requested_value' => $this->valuePayload($newValue),
                'new_value' => $status === TechnicalServiceAdminOverride::STATUS_APPLIED ? $this->valuePayload($newValue) : null,
                'recompute_flags' => $recomputeFlags,
                'reason' => $reason,
                'requested_by' => $actor?->getAuthIdentifier(),
                'approved_by' => $status === TechnicalServiceAdminOverride::STATUS_APPLIED ? $actor?->getAuthIdentifier() : null,
                'applied_by' => $status === TechnicalServiceAdminOverride::STATUS_APPLIED ? $actor?->getAuthIdentifier() : null,
                'requested_at' => $now,
                'approved_at' => $status === TechnicalServiceAdminOverride::STATUS_APPLIED ? $now : null,
                'applied_at' => $status === TechnicalServiceAdminOverride::STATUS_APPLIED ? $now : null,
                'metadata' => [
                    'sensitive' => (bool) ($policy['sensitive'] ?? false),
                    'mode' => $payload['mode'] ?? null,
                    'admin_override' => true,
                    'actor_label' => $this->actorName($actor),
                    'approver_label' => $status === TechnicalServiceAdminOverride::STATUS_APPLIED ? $this->actorName($actor) : null,
                    'applier_label' => $status === TechnicalServiceAdminOverride::STATUS_APPLIED ? $this->actorName($actor) : null,
                ],
            ]);

            if ($status === TechnicalServiceAdminOverride::STATUS_APPLIED) {
                $this->applyOverride($request, $override, $newValue, $actor);
            } else {
                $this->writeEvent($request, 'field_override_requested', $actor, [
                    'field_key' => $fieldKey,
                    'field_label' => $policy['label'],
                    'reason' => $reason,
                    'requested_value' => $newValue,
                ], 'Düzeltme talebi oluşturuldu');
            }

            return $override->refresh();
        });
    }

    public function approve(TechnicalServiceRequest $request, TechnicalServiceAdminOverride $override, ?Authenticatable $actor, ?string $note = null): TechnicalServiceAdminOverride
    {
        $this->assertOverrideBelongsToRequest($request, $override);

        if ($override->status !== TechnicalServiceAdminOverride::STATUS_PENDING) {
            throw ValidationException::withMessages(['override' => 'Bu düzeltme talebi beklemede değil.']);
        }

        return DB::transaction(function () use ($request, $override, $actor, $note): TechnicalServiceAdminOverride {
            $requestedValue = $override->requested_value['value'] ?? null;
            $override->forceFill([
                'status' => TechnicalServiceAdminOverride::STATUS_APPLIED,
                'approved_by' => $actor?->getAuthIdentifier(),
                'applied_by' => $actor?->getAuthIdentifier(),
                'approved_at' => now(),
                'applied_at' => now(),
                'new_value' => $this->valuePayload($requestedValue),
                'metadata' => array_merge($override->metadata ?? [], [
                    'approver_label' => $this->actorName($actor),
                    'applier_label' => $this->actorName($actor),
                    'approval_note' => $note,
                ]),
            ])->save();

            $this->applyOverride($request, $override->refresh(), $requestedValue, $actor, $note);

            return $override->refresh();
        });
    }

    public function reject(TechnicalServiceRequest $request, TechnicalServiceAdminOverride $override, ?Authenticatable $actor, string $note): TechnicalServiceAdminOverride
    {
        $this->assertOverrideBelongsToRequest($request, $override);

        if ($override->status !== TechnicalServiceAdminOverride::STATUS_PENDING) {
            throw ValidationException::withMessages(['override' => 'Bu düzeltme talebi beklemede değil.']);
        }

        return DB::transaction(function () use ($request, $override, $actor, $note): TechnicalServiceAdminOverride {
            $override->forceFill([
                'status' => TechnicalServiceAdminOverride::STATUS_REJECTED,
                'rejected_by' => $actor?->getAuthIdentifier(),
                'rejected_at' => now(),
                'rejection_reason' => $note,
                'metadata' => array_merge($override->metadata ?? [], [
                    'rejector_label' => $this->actorName($actor),
                ]),
            ])->save();

            $this->writeEvent($request, 'field_override_rejected', $actor, [
                'field_key' => $override->field_key,
                'field_label' => $override->field_label,
                'note' => $note,
            ], 'Düzeltme talebi reddedildi');

            return $override->refresh();
        });
    }

    public function logRecompute(TechnicalServiceRequest $request, ?Authenticatable $actor, string $reason): TechnicalServiceAdminOverride
    {
        return DB::transaction(function () use ($request, $actor, $reason): TechnicalServiceAdminOverride {
            $override = TechnicalServiceAdminOverride::query()->create([
                ...$this->requestLedgerContext($request),
                'field_key' => 'system_recompute',
                'field_label' => 'Sistem yeniden hesaplama kontrolü',
                'field_group' => 'system',
                'source' => TechnicalServiceAdminOverride::SOURCE_SYSTEM_RECOMPUTE,
                'status' => TechnicalServiceAdminOverride::STATUS_APPLIED,
                'old_value' => null,
                'requested_value' => null,
                'new_value' => null,
                'recompute_flags' => ['route_quote', 'earning', 'dashboard_state'],
                'reason' => $reason,
                'requested_by' => $actor?->getAuthIdentifier(),
                'approved_by' => $actor?->getAuthIdentifier(),
                'applied_by' => $actor?->getAuthIdentifier(),
                'requested_at' => now(),
                'approved_at' => now(),
                'applied_at' => now(),
                'metadata' => [
                    'actor_label' => $this->actorName($actor),
                    'approver_label' => $this->actorName($actor),
                    'applier_label' => $this->actorName($actor),
                ],
            ]);

            $this->writeEvent($request, 'admin_recompute_requested', $actor, [
                'note' => $reason,
                'recompute_flags' => $override->recompute_flags,
            ], 'Yeniden hesaplama kontrolü kaydedildi');

            return $override;
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function serializeForRequest(TechnicalServiceRequest $request): array
    {
        if (! Schema::hasTable('technical_service_admin_overrides')) {
            return [];
        }

        return $request->adminOverrides()
            ->latest()
            ->limit(30)
            ->get()
            ->map(fn (TechnicalServiceAdminOverride $override): array => $this->serialize($override))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function summaryForRequest(TechnicalServiceRequest $request): array
    {
        if (! Schema::hasTable('technical_service_admin_overrides')) {
            return [
                'pending_count' => 0,
                'applied_count' => 0,
                'rejected_count' => 0,
            ];
        }

        $counts = $request->adminOverrides()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'pending_count' => (int) ($counts[TechnicalServiceAdminOverride::STATUS_PENDING] ?? 0),
            'applied_count' => (int) ($counts[TechnicalServiceAdminOverride::STATUS_APPLIED] ?? 0),
            'rejected_count' => (int) ($counts[TechnicalServiceAdminOverride::STATUS_REJECTED] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function correctionPolicyPayload(): array
    {
        return [
            'fields' => collect($this->fieldPolicy())
                ->map(fn (array $policy, string $key): array => [
                    'key' => $key,
                    'label' => $policy['label'],
                    'group' => $policy['group'],
                    'sensitive' => (bool) ($policy['sensitive'] ?? false),
                    'recompute_flags' => $this->recomputeFlagsForField($key),
                ])
                ->values()
                ->all(),
            'blocked_fields' => $this->blockedFields(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(TechnicalServiceAdminOverride $override): array
    {
        return [
            'id' => $override->id,
            'request_id' => $override->request_id,
            'root_request_id' => $override->root_request_id,
            'request_code' => $override->request_code,
            'root_mrn' => $override->root_mrn,
            'field_key' => $override->field_key,
            'field_label' => $override->field_label,
            'field_group' => $override->field_group,
            'source' => $override->source,
            'source_label' => $this->sourceLabel($override->source),
            'status' => $override->status,
            'status_label' => $this->statusLabel($override->status),
            'old_value' => $override->old_value,
            'requested_value' => $override->requested_value,
            'new_value' => $override->new_value,
            'recompute_flags' => $override->recompute_flags ?? [],
            'recompute_flag_labels' => $this->recomputeFlagLabels($override->recompute_flags ?? []),
            'reason' => TechnicalServiceUiLabelService::cleanDisplayText($override->reason),
            'approval_note' => TechnicalServiceUiLabelService::cleanDisplayText($override->metadata['approval_note'] ?? null),
            'rejection_reason' => TechnicalServiceUiLabelService::cleanDisplayText($override->rejection_reason),
            'requested_by' => $override->requested_by,
            'requested_actor_label' => TechnicalServiceUiLabelService::displayName($override->metadata['actor_label'] ?? null),
            'approved_by' => $override->approved_by,
            'approved_actor_label' => TechnicalServiceUiLabelService::displayName($override->metadata['approver_label'] ?? null),
            'applied_by' => $override->applied_by,
            'applied_actor_label' => TechnicalServiceUiLabelService::displayName($override->metadata['applier_label'] ?? null),
            'rejected_by' => $override->rejected_by,
            'rejected_actor_label' => TechnicalServiceUiLabelService::displayName($override->metadata['rejector_label'] ?? null),
            'requested_at' => $override->requested_at?->toIso8601String(),
            'approved_at' => $override->approved_at?->toIso8601String(),
            'applied_at' => $override->applied_at?->toIso8601String(),
            'rejected_at' => $override->rejected_at?->toIso8601String(),
            'created_at' => $override->created_at?->toIso8601String(),
        ];
    }

    private function policyFor(string $fieldKey): array
    {
        if (in_array($fieldKey, $this->blockedFields(), true)) {
            throw ValidationException::withMessages(['field_key' => 'Bu alan düzeltme akışına kapalıdır.']);
        }

        $policy = $this->fieldPolicy()[$fieldKey] ?? null;
        if ($policy === null) {
            throw ValidationException::withMessages(['field_key' => 'Bu alan için güvenli düzeltme tanımı yok.']);
        }

        return $policy;
    }

    private function readValue(TechnicalServiceRequest $request, string $column): mixed
    {
        if (str_starts_with($column, 'operation_control_payload.')) {
            $payload = is_array($request->operation_control_payload) ? $request->operation_control_payload : [];

            return data_get($payload, substr($column, strlen('operation_control_payload.')));
        }

        return $request->getAttribute($column);
    }

    private function applyOverride(
        TechnicalServiceRequest $request,
        TechnicalServiceAdminOverride $override,
        mixed $newValue,
        ?Authenticatable $actor,
        ?string $reviewNote = null,
    ): void {
        $policy = $this->policyFor($override->field_key);
        $column = (string) $policy['column'];
        $oldSnapshot = Arr::only($request->fresh()?->toArray() ?? $request->toArray(), [
            'customer_phone',
            'customer_city',
            'customer_district',
            'service_address',
            'serial_number',
            'activation_code',
            'stock_code',
            'product_model',
            'scheduled_at',
            'scheduled_date',
            'scheduled_time',
            'technical_service_technician_id',
            'technician_name',
            'travel_round_trip_km',
            'travel_fee_amount',
            'technician_payment_amount',
            'operation_control_payload',
        ]);

        if (str_starts_with($column, 'operation_control_payload.')) {
            $payload = is_array($request->operation_control_payload) ? $request->operation_control_payload : [];
            data_set($payload, substr($column, strlen('operation_control_payload.')), $newValue);
            $request->operation_control_payload = $payload;
        } elseif ($override->field_key === 'appointment_at') {
            $scheduledAt = filled($newValue) ? CarbonImmutable::parse((string) $newValue) : null;
            $request->scheduled_at = $scheduledAt;
            $request->scheduled_date = $scheduledAt?->toDateString();
            $request->scheduled_time = $scheduledAt?->format('H:i');
        } elseif ($override->field_key === 'assigned_technician_id') {
            $technician = $newValue !== null && $newValue !== ''
                ? TechnicalServiceTechnician::query()->findOrFail((int) $newValue)
                : null;
            $request->technical_service_technician_id = $technician?->id;
            $request->technician_name = $technician?->name;
        } elseif (in_array($override->field_key, ['latitude', 'longitude'], true)) {
            $request->setAttribute($column, $newValue === null || $newValue === '' ? null : round((float) $newValue, 7));
        } elseif (in_array($override->field_key, ['technician_route_distance', 'route_earning', 'labor_earning'], true)) {
            $request->setAttribute($column, $newValue === null || $newValue === '' ? null : round((float) $newValue, 2));
        } else {
            $request->setAttribute($column, $newValue);
        }

        $request->updated_by_user_id = $actor?->getAuthIdentifier();
        $request->save();
        $request->refresh();

        $newSnapshot = Arr::only($request->toArray(), array_keys($oldSnapshot));
        $this->writeAuditLog($request, 'field_override_applied', $oldSnapshot, $newSnapshot, $actor, $reviewNote ?: $override->reason);
        $this->writeEvent($request, 'field_override_applied', $actor, [
            'field_key' => $override->field_key,
            'field_label' => $override->field_label,
            'old_value' => $override->old_value,
            'new_value' => $this->valuePayload($newValue),
            'recompute_flags' => $override->recompute_flags,
            'note' => $reviewNote ?: $override->reason,
        ], 'Düzeltme uygulandı');
    }

    /**
     * @return array<int, string>
     */
    private function recomputeFlagsForField(string $fieldKey): array
    {
        return match ($fieldKey) {
            'customer_address', 'city', 'district', 'latitude', 'longitude', 'plus_code', 'google_formatted_address', 'assigned_technician_id', 'technician_route_distance' => ['route_quote', 'dashboard_state'],
            'route_earning', 'labor_earning', 'earning_note' => ['earning', 'dashboard_state'],
            'appointment_at', 'appointment_note', 'priority' => ['dashboard_state'],
            'serial_no', 'activation_code', 'product_code', 'product_model' => ['warranty_context', 'dashboard_state'],
            default => ['dashboard_state'],
        };
    }

    /**
     * @param array<int, string> $flags
     * @return array<int, string>
     */
    private function recomputeFlagLabels(array $flags): array
    {
        $labels = [
            'route_quote' => 'Yol hesaplaması kontrol edilmeli',
            'earning' => 'Hakediş özeti kontrol edilmeli',
            'dashboard_state' => 'Operasyon kartı yenilenmeli',
            'warranty_context' => 'Seri/garanti bağlamı kontrol edilmeli',
        ];

        return collect($flags)
            ->map(fn (string $flag): string => $labels[$flag] ?? 'Sistem kontrolü')
            ->values()
            ->all();
    }

    private function statusLabel(?string $status): string
    {
        return match ((string) $status) {
            TechnicalServiceAdminOverride::STATUS_PENDING => 'Onay bekliyor',
            TechnicalServiceAdminOverride::STATUS_APPLIED => 'Uygulandı',
            TechnicalServiceAdminOverride::STATUS_REJECTED => 'Reddedildi',
            default => 'Düzeltme kaydı',
        };
    }

    private function sourceLabel(?string $source): string
    {
        return match ((string) $source) {
            TechnicalServiceAdminOverride::SOURCE_ADMIN_APPLY => 'Admin doğrudan uyguladı',
            TechnicalServiceAdminOverride::SOURCE_ADMIN_APPROVAL => 'Admin onayı',
            TechnicalServiceAdminOverride::SOURCE_OPS_REQUEST => 'Operasyon talebi',
            TechnicalServiceAdminOverride::SOURCE_PARTNER_REQUEST => 'Usta/partner talebi',
            TechnicalServiceAdminOverride::SOURCE_SYSTEM_RECOMPUTE => 'Sistem kontrolü',
            default => 'Düzeltme kaynağı',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function valuePayload(mixed $value): array
    {
        return [
            'value' => $value,
            'display' => $this->displayValue($value),
        ];
    }

    private function displayValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'Boş';
        }

        if (is_bool($value)) {
            return $value ? 'Evet' : 'Hayır';
        }

        if (is_scalar($value)) {
            return TechnicalServiceUiLabelService::cleanDisplayText((string) $value) ?: 'Boş';
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'Kayıt değeri';
    }

    private function actorName(?Authenticatable $actor): ?string
    {
        if (! method_exists($actor, 'getAttribute')) {
            return null;
        }

        return $actor?->getAttribute('full_name')
            ?: $actor?->getAttribute('name')
            ?: $actor?->getAttribute('username')
            ?: $actor?->getAttribute('email');
    }

    /**
     * @return array<string, mixed>
     */
    private function requestLedgerContext(TechnicalServiceRequest $request): array
    {
        $rootCode = $request->root_mrn ?: ($request->parent_request_id === null ? $request->mrn : null);
        $rootRequest = $request->parent_request_id === null
            ? $request
            : null;

        if ($rootRequest === null && filled($rootCode)) {
            $rootRequest = TechnicalServiceRequest::query()
                ->where('mrn', $rootCode)
                ->whereNull('parent_request_id')
                ->first();
        }

        if ($rootRequest === null && $request->parent_request_id !== null) {
            $rootRequest = TechnicalServiceRequest::query()->find($request->parent_request_id);
        }

        return [
            'request_id' => $request->id,
            'root_request_id' => $rootRequest?->id,
            'request_code' => $request->mrn ?: $request->service_code,
            'root_mrn' => $rootCode ?: $rootRequest?->mrn,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeEvent(TechnicalServiceRequest $request, string $eventType, ?Authenticatable $actor, array $payload, string $title): void
    {
        $request->events()->create([
            'event_type' => $eventType,
            'title' => $title,
            'note' => $payload['note'] ?? $payload['reason'] ?? null,
            'from_status' => $request->workflow_status,
            'to_status' => $request->workflow_status,
            'author_user_id' => $actor?->getAuthIdentifier(),
            'metadata' => Arr::except($payload, ['note', 'reason']),
        ]);
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     */
    private function writeAuditLog(TechnicalServiceRequest $request, string $actionType, array $old, array $new, ?Authenticatable $actor, ?string $note): void
    {
        if (! Schema::hasTable('technical_service_audit_logs')) {
            return;
        }

        TechnicalServiceAuditLog::query()->create([
            'entity_type' => 'technical_service_request',
            'entity_id' => $request->id,
            'action_type' => $actionType,
            'old_value' => $old,
            'new_value' => $new,
            'user_id' => $actor?->getAuthIdentifier(),
            'user_name' => $this->actorName($actor),
            'note' => $note,
        ]);
    }

    private function assertOverrideBelongsToRequest(TechnicalServiceRequest $request, TechnicalServiceAdminOverride $override): void
    {
        if ((int) $override->request_id !== (int) $request->id) {
            throw ValidationException::withMessages(['override' => 'Düzeltme kaydı bu talebe ait değil.']);
        }
    }
}
