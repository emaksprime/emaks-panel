<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceAuditLog;
use App\Models\TechnicalServiceRequest;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class TechnicalServiceWorkflowService
{
    public const WORKFLOW_STATUSES = [
        'Yeni Talep',
        'Eksik Bilgi / Fotoğraf Bekleyen',
        'Müşteri Aranacak',
        'Müşteriye Ulaşılamadı',
        'Müşteri Onayı Bekleyen',
        'Müşteri Onayladı',
        'Randevu Planlandı',
        'Usta Ataması Bekleyen',
        'Usta Onayı Bekleyen',
        'Usta Tarih Revize Talebi',
        'Planlı',
        'Yolda',
        'Sahada',
        'Beklemede',
        'Müşteri Yerinde Yok',
        'Montaj Yeri Hazır Değil',
        'Parça Bekleniyor',
        'Belge / Fotoğraf Bekleyen',
        'Müşteri Kapanış Onayı Bekleyen',
        'Tamamlandı',
        'İptal',
    ];

    public const SLA_NORMAL = 'normal';
    public const SLA_APPROACHING = 'yaklaşan';
    public const SLA_OVERDUE = 'geciken';

    private const TERMINAL_STATUSES = ['Tamamlandı', 'İptal'];
    private const CHECKLIST_ITEMS = [
        'Ürün seri numarası kontrol edildi',
        'Kapı / montaj yeri kontrol edildi',
        'Montaj uygunluğu kontrol edildi',
        'Ürün çalışır durumda test edildi',
        'Müşteriye kullanım bilgisi verildi',
        'Garanti / servis formu bilgisi kontrol edildi',
    ];

    /**
     * @return array<string, string>
     */
    public static function actionLabels(): array
    {
        return [
            'mark_missing_info' => 'Eksik Bilgi / Fotoğraf',
            'customer_called' => 'Müşteri Arandı',
            'customer_unreachable' => 'Ulaşılamadı',
            'customer_callback_scheduled' => 'Tekrar Arama Planla',
            'customer_confirmation_pending' => 'Onay Bekliyor',
            'customer_confirmed' => 'Müşteri Onayladı',
            'customer_rejected' => 'Müşteri Reddetti',
            'wrong_number' => 'Yanlış Numara',
            'customer_requested_cancel' => 'İptal Talebi',
            'schedule_planned' => 'Randevu Planla',
            'assign_technician' => 'Usta Ata',
            'wait_technician_approval' => 'Usta Onayı Bekle',
            'technician_revision_requested' => 'Usta Revize İstedi',
            'on_the_way' => 'Yolda',
            'on_site' => 'Sahada',
            'pause' => 'Beklemeye Al',
            'parts_pending' => 'Parça Bekleniyor',
            'document_pending' => 'Belge / Fotoğraf Bekliyor',
            'closure_pending' => 'Kapanış Onayı Bekliyor',
            'complete' => 'Tamamla',
            'cancel' => 'İptal Et',
            'field_travel_started' => 'Yola Çıktı',
            'field_arrived' => 'Sahaya Vardı',
            'field_work_started' => 'İşe Başladı',
            'checklist_updated' => 'Checklist Güncelle',
            'photos_updated' => 'Fotoğraf Sayılarını Güncelle',
            'customer_closure_approved' => 'Müşteri Kapanış Onayı Al',
            'field_marked_incomplete' => 'Tamamlanamadı',
            'second_visit_required' => 'Tekrar Randevu Gerekli',
            'field_completed' => 'İşi Tamamla',
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function transitionMap(): array
    {
        return [
            'Yeni Talep' => ['Eksik Bilgi / Fotoğraf Bekleyen', 'Müşteri Aranacak', 'Müşteri Onayı Bekleyen'],
            'Eksik Bilgi / Fotoğraf Bekleyen' => ['Müşteri Aranacak', 'Müşteri Onayı Bekleyen'],
            'Müşteri Aranacak' => ['Müşteriye Ulaşılamadı', 'Müşteri Onayı Bekleyen', 'Müşteri Onayladı', 'Beklemede'],
            'Müşteriye Ulaşılamadı' => ['Müşteri Aranacak', 'Müşteri Onayı Bekleyen', 'Müşteri Onayladı', 'Beklemede'],
            'Müşteri Onayı Bekleyen' => ['Müşteriye Ulaşılamadı', 'Müşteri Onayladı', 'Beklemede'],
            'Müşteri Onayladı' => ['Randevu Planlandı', 'Beklemede'],
            'Randevu Planlandı' => ['Usta Ataması Bekleyen', 'Usta Onayı Bekleyen', 'Beklemede'],
            'Usta Ataması Bekleyen' => ['Usta Onayı Bekleyen', 'Usta Tarih Revize Talebi', 'Beklemede'],
            'Usta Onayı Bekleyen' => ['Planlı', 'Usta Tarih Revize Talebi', 'Beklemede'],
            'Usta Tarih Revize Talebi' => ['Müşteri Aranacak', 'Müşteri Onayı Bekleyen', 'Müşteri Onayladı', 'Randevu Planlandı', 'Usta Onayı Bekleyen'],
            'Planlı' => ['Yolda', 'Sahada', 'Beklemede', 'İptal'],
            'Yolda' => ['Sahada', 'Beklemede', 'İptal'],
            'Sahada' => ['Belge / Fotoğraf Bekleyen', 'Müşteri Kapanış Onayı Bekleyen', 'Tamamlandı', 'Parça Bekleniyor', 'Beklemede', 'Müşteri Yerinde Yok', 'Montaj Yeri Hazır Değil'],
            'Beklemede' => ['Müşteri Aranacak', 'Müşteri Onayı Bekleyen', 'Randevu Planlandı', 'Usta Ataması Bekleyen', 'Parça Bekleniyor', 'İptal'],
            'Müşteri Yerinde Yok' => ['Randevu Planlandı', 'Müşteri Aranacak', 'İptal'],
            'Montaj Yeri Hazır Değil' => ['Randevu Planlandı', 'Beklemede', 'İptal'],
            'Parça Bekleniyor' => ['Randevu Planlandı', 'Belge / Fotoğraf Bekleyen', 'Beklemede'],
            'Belge / Fotoğraf Bekleyen' => ['Müşteri Kapanış Onayı Bekleyen', 'Tamamlandı'],
            'Müşteri Kapanış Onayı Bekleyen' => ['Tamamlandı', 'Belge / Fotoğraf Bekleyen'],
            'Tamamlandı' => [],
            'İptal' => [],
        ];
    }

    /**
     * @return array<string, array{label:string,target:string}>
     */
    public function allowedActionsFor(TechnicalServiceRequest $request): array
    {
        $status = $this->currentWorkflowStatus($request);
        $actions = [];

        $map = [
            'Yeni Talep' => [
                'mark_missing_info' => 'Eksik Bilgi / Fotoğraf Bekleyen',
                'customer_called' => 'Müşteri Onayı Bekleyen',
                'customer_unreachable' => 'Müşteriye Ulaşılamadı',
            ],
            'Eksik Bilgi / Fotoğraf Bekleyen' => [
                'customer_called' => 'Müşteri Onayı Bekleyen',
                'customer_unreachable' => 'Müşteriye Ulaşılamadı',
            ],
            'Müşteri Aranacak' => [
                'customer_called' => 'Müşteri Onayı Bekleyen',
                'customer_unreachable' => 'Müşteriye Ulaşılamadı',
                'customer_callback_scheduled' => 'Müşteriye Ulaşılamadı',
                'customer_confirmation_pending' => 'Müşteri Onayı Bekleyen',
                'customer_confirmed' => 'Müşteri Onayladı',
                'customer_rejected' => 'Beklemede',
                'wrong_number' => 'Beklemede',
                'customer_requested_cancel' => 'Beklemede',
            ],
            'Müşteriye Ulaşılamadı' => [
                'customer_called' => 'Müşteri Onayı Bekleyen',
                'customer_callback_scheduled' => 'Müşteriye Ulaşılamadı',
                'customer_confirmation_pending' => 'Müşteri Onayı Bekleyen',
                'customer_confirmed' => 'Müşteri Onayladı',
                'customer_rejected' => 'Beklemede',
                'wrong_number' => 'Beklemede',
                'customer_requested_cancel' => 'Beklemede',
            ],
            'Müşteri Onayı Bekleyen' => [
                'customer_unreachable' => 'Müşteriye Ulaşılamadı',
                'customer_callback_scheduled' => 'Müşteriye Ulaşılamadı',
                'customer_confirmation_pending' => 'Müşteri Onayı Bekleyen',
                'customer_confirmed' => 'Müşteri Onayladı',
                'customer_rejected' => 'Beklemede',
                'wrong_number' => 'Beklemede',
                'customer_requested_cancel' => 'Beklemede',
            ],
            'Müşteri Onayladı' => [
                'schedule_planned' => 'Randevu Planlandı',
                'customer_rejected' => 'Beklemede',
            ],
            'Randevu Planlandı' => [
                'assign_technician' => 'Usta Ataması Bekleyen',
                'wait_technician_approval' => 'Usta Onayı Bekleyen',
            ],
            'Usta Ataması Bekleyen' => [
                'assign_technician' => 'Usta Onayı Bekleyen',
            ],
            'Usta Onayı Bekleyen' => [
                'technician_revision_requested' => 'Usta Tarih Revize Talebi',
            ],
            'Planlı' => [
                'on_the_way' => 'Yolda',
                'on_site' => 'Sahada',
                'pause' => 'Beklemede',
                'cancel' => 'İptal',
                'field_travel_started' => 'Yolda',
                'field_marked_incomplete' => 'Beklemede',
                'second_visit_required' => 'Beklemede',
            ],
            'Yolda' => [
                'on_site' => 'Sahada',
                'pause' => 'Beklemede',
                'cancel' => 'İptal',
                'field_arrived' => 'Sahada',
                'field_marked_incomplete' => 'Beklemede',
                'second_visit_required' => 'Beklemede',
            ],
            'Sahada' => [
                'document_pending' => 'Belge / Fotoğraf Bekleyen',
                'closure_pending' => 'Müşteri Kapanış Onayı Bekleyen',
                'complete' => 'Tamamlandı',
                'parts_pending' => 'Parça Bekleniyor',
                'pause' => 'Beklemede',
                'field_work_started' => 'Sahada',
                'checklist_updated' => 'Sahada',
                'photos_updated' => 'Sahada',
                'customer_closure_approved' => 'Sahada',
                'field_marked_incomplete' => 'Beklemede',
                'second_visit_required' => 'Beklemede',
                'field_completed' => 'Tamamlandı',
            ],
            'Beklemede' => [
                'customer_called' => 'Müşteri Onayı Bekleyen',
                'customer_confirmation_pending' => 'Müşteri Onayı Bekleyen',
                'schedule_planned' => 'Randevu Planlandı',
                'parts_pending' => 'Parça Bekleniyor',
                'cancel' => 'İptal',
            ],
            'Müşteri Yerinde Yok' => [
                'schedule_planned' => 'Randevu Planlandı',
                'customer_unreachable' => 'Müşteriye Ulaşılamadı',
                'cancel' => 'İptal',
            ],
            'Montaj Yeri Hazır Değil' => [
                'schedule_planned' => 'Randevu Planlandı',
                'pause' => 'Beklemede',
                'cancel' => 'İptal',
            ],
            'Parça Bekleniyor' => [
                'schedule_planned' => 'Randevu Planlandı',
                'document_pending' => 'Belge / Fotoğraf Bekleyen',
                'photos_updated' => 'Parça Bekleniyor',
            ],
            'Belge / Fotoğraf Bekleyen' => [
                'closure_pending' => 'Müşteri Kapanış Onayı Bekleyen',
                'complete' => 'Tamamlandı',
                'photos_updated' => 'Belge / Fotoğraf Bekleyen',
                'field_completed' => 'Tamamlandı',
            ],
            'Müşteri Kapanış Onayı Bekleyen' => [
                'document_pending' => 'Belge / Fotoğraf Bekleyen',
                'complete' => 'Tamamlandı',
                'customer_closure_approved' => 'Müşteri Kapanış Onayı Bekleyen',
                'field_completed' => 'Tamamlandı',
            ],
        ];

        foreach ($map[$status] ?? [] as $action => $target) {
            $actions[$action] = [
                'label' => self::actionLabels()[$action] ?? $action,
                'target' => $target,
            ];
        }

        return $actions;
    }

    public function currentWorkflowStatus(TechnicalServiceRequest $request): string
    {
        return $this->normalizeWorkflowStatus(
            $request->workflow_status,
            $request->status,
            filled($request->technical_service_technician_id) || filled($request->technician_name),
            $request->scheduled_at !== null
        );
    }

    public function normalizeWorkflowStatus(?string $workflowStatus, ?string $legacyStatus = null, bool $hasTechnician = false, bool $hasSchedule = false): string
    {
        $normalized = $this->normalizeToken($workflowStatus);

        $workflowAliases = [
            'yenitalep' => 'Yeni Talep',
            'eksikbilgifotografbekleyen' => 'Eksik Bilgi / Fotoğraf Bekleyen',
            'musteriaranacak' => 'Müşteri Aranacak',
            'musteriyeulasilamadi' => 'Müşteriye Ulaşılamadı',
            'musterionayibekleyen' => 'Müşteri Onayı Bekleyen',
            'musterionayladi' => 'Müşteri Onayladı',
            'randevuplanlandi' => 'Randevu Planlandı',
            'ustaatamasibekleyen' => 'Usta Ataması Bekleyen',
            'ustaonayibekleyen' => 'Usta Onayı Bekleyen',
            'ustatarihrevizetalebi' => 'Usta Tarih Revize Talebi',
            'planli' => 'Planlı',
            'yolda' => 'Yolda',
            'sahada' => 'Sahada',
            'beklemede' => 'Beklemede',
            'musteriyerindeyok' => 'Müşteri Yerinde Yok',
            'montajyerihazirdegil' => 'Montaj Yeri Hazır Değil',
            'parcabekleniyor' => 'Parça Bekleniyor',
            'belgefotografbekleyen' => 'Belge / Fotoğraf Bekleyen',
            'musterikapanisonayibekleyen' => 'Müşteri Kapanış Onayı Bekleyen',
            'tamamlandi' => 'Tamamlandı',
            'iptal' => 'İptal',
        ];

        if (isset($workflowAliases[$normalized])) {
            return $workflowAliases[$normalized];
        }

        return match ($this->normalizeLegacyStatus($legacyStatus)) {
            'Tamamlandı' => 'Tamamlandı',
            'İptal' => 'İptal',
            'Devam Ediyor' => 'Sahada',
            'Randevulu' => $hasTechnician ? 'Planlı' : 'Randevu Planlandı',
            'Atandı' => $hasTechnician ? 'Usta Onayı Bekleyen' : 'Usta Ataması Bekleyen',
            default => $hasSchedule ? 'Randevu Planlandı' : 'Yeni Talep',
        };
    }

    public function normalizeLegacyStatus(?string $status): string
    {
        return match ($this->normalizeToken($status)) {
            'tamamlandi' => 'Tamamlandı',
            'iptal' => 'İptal',
            'devamediyor' => 'Devam Ediyor',
            'randevulu' => 'Randevulu',
            'atandi' => 'Atandı',
            default => 'Yeni',
        };
    }

    public function legacyStatusForWorkflow(string $workflowStatus): string
    {
        return match ($workflowStatus) {
            'Tamamlandı' => 'Tamamlandı',
            'İptal' => 'İptal',
            'Yolda',
            'Sahada',
            'Beklemede',
            'Müşteri Yerinde Yok',
            'Montaj Yeri Hazır Değil',
            'Parça Bekleniyor',
            'Belge / Fotoğraf Bekleyen',
            'Müşteri Kapanış Onayı Bekleyen' => 'Devam Ediyor',
            'Randevu Planlandı',
            'Planlı' => 'Randevulu',
            'Usta Ataması Bekleyen',
            'Usta Onayı Bekleyen',
            'Usta Tarih Revize Talebi' => 'Atandı',
            default => 'Yeni',
        };
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function initializeRequest(TechnicalServiceRequest $request, array $attributes = []): TechnicalServiceRequest
    {
        $workflowStatus = $attributes['workflow_status'] ?? $request->workflow_status ?? $request->status ?? 'Yeni Talep';
        $request->workflow_status = $this->normalizeWorkflowStatus(
            is_string($workflowStatus) ? $workflowStatus : null,
            $request->status,
            filled($request->technical_service_technician_id) || filled($request->technician_name),
            $request->scheduled_at !== null
        );
        $this->applyDerivedState($request, $attributes);

        return $request;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function transition(TechnicalServiceRequest $request, string $targetWorkflowStatus, array $payload = [], ?Authenticatable $user = null, string $actionType = 'workflow_transition'): TechnicalServiceRequest
    {
        $current = $this->currentWorkflowStatus($request);
        $target = $this->normalizeWorkflowStatus(
            $targetWorkflowStatus,
            $targetWorkflowStatus,
            filled($request->technical_service_technician_id) || filled($request->technician_name),
            $request->scheduled_at !== null
        );

        if ($current !== $target) {
            $this->assertTransitionAllowed($current, $target);
        }

        $old = $this->snapshot($request);

        $request->workflow_status = $target;
        if ($target !== 'İptal') {
            $request->cancelled_at = null;
        }

        $this->applyPayloadForWorkflow($request, $target, $payload);
        $this->applyDerivedState($request, $payload);
        $request->updated_by_user_id = $user?->id;
        $request->save();

        $this->writeAuditLog($request, $actionType, $old, $this->snapshot($request), $user, $payload['note'] ?? null);
        $this->writeEvent($request, $actionType, $current, $target, $user, $payload);

        return $request->refresh();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateSchedule(TechnicalServiceRequest $request, array $payload, ?Authenticatable $user = null): TechnicalServiceRequest
    {
        $old = $this->snapshot($request);
        $scheduledAt = $this->scheduledAtFromPayload($payload);

        $request->scheduled_date = $payload['scheduled_date'];
        $request->scheduled_time = $payload['scheduled_time'];
        $request->scheduled_at = $scheduledAt;
        $request->requires_reschedule = Arr::get($payload, 'requires_reschedule', false);
        $request->reschedule_reason = Arr::get($payload, 'reschedule_reason');
        $request->pending_reason = Arr::get($payload, 'pending_reason', $request->pending_reason);
        $request->updated_by_user_id = $user?->id;

        $preferredDate = $request->customer_preferred_date?->toDateString();
        $preferredStart = $request->customer_preferred_time_start;
        $preferredEnd = $request->customer_preferred_time_end;
        $differsFromPreference = $preferredDate !== null
            && (
                $preferredDate !== $payload['scheduled_date']
                || ($preferredStart !== null && $preferredStart !== $payload['scheduled_time'])
            );

        if ($differsFromPreference) {
            $payload['preferred_schedule_diff'] = [
                'customer_preferred_date' => $preferredDate,
                'customer_preferred_time_start' => $preferredStart,
                'customer_preferred_time_end' => $preferredEnd,
                'scheduled_date' => $payload['scheduled_date'],
                'scheduled_time' => $payload['scheduled_time'],
            ];
        }

        $target = filled($request->technical_service_technician_id) || filled($request->technician_name)
            ? 'Usta Onayı Bekleyen'
            : 'Randevu Planlandı';

        $current = $this->currentWorkflowStatus($request);
        if ($current !== $target && ! in_array($current, self::TERMINAL_STATUSES, true)) {
            $this->assertTransitionAllowed($current, $target);
            $request->workflow_status = $target;
        }

        $this->applyDerivedState($request, $payload);
        $request->save();

        $this->writeAuditLog($request, 'schedule_updated', $old, $this->snapshot($request), $user, $payload['note'] ?? null);
        $this->writeEvent($request, 'schedule_updated', $current, $this->currentWorkflowStatus($request), $user, $payload, 'Randevu güncellendi');

        return $request->refresh();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateTechnician(TechnicalServiceRequest $request, array $payload, ?Authenticatable $user = null): TechnicalServiceRequest
    {
        $old = $this->snapshot($request);

        $request->technical_service_technician_id = $payload['technical_service_technician_id'] ?? null;
        $request->technician_name = $payload['technician_name'] ?? $request->technician_name;
        $request->technician_approval_status = $payload['technician_approval_status'] ?? 'bekliyor';
        $request->technician_revision_note = $payload['technician_revision_note'] ?? $request->technician_revision_note;
        $request->updated_by_user_id = $user?->id;

        $target = ($payload['technician_approval_status'] ?? null) === 'revize_talebi'
            ? 'Usta Tarih Revize Talebi'
            : 'Usta Onayı Bekleyen';

        $current = $this->currentWorkflowStatus($request);
        if ($current !== $target && ! in_array($current, self::TERMINAL_STATUSES, true)) {
            $this->assertTransitionAllowed($current, $target);
            $request->workflow_status = $target;
        }

        $this->applyDerivedState($request, $payload);
        $request->save();

        $this->writeAuditLog($request, 'technician_updated', $old, $this->snapshot($request), $user, $payload['note'] ?? null);
        $this->writeEvent($request, 'technician_updated', $current, $this->currentWorkflowStatus($request), $user, $payload, 'Usta bilgisi güncellendi');

        return $request->refresh();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function logCustomerContact(TechnicalServiceRequest $request, array $payload, ?Authenticatable $user = null): TechnicalServiceRequest
    {
        $action = (string) ($payload['action'] ?? 'customer_called');

        $target = match ($action) {
            'customer_unreachable', 'customer_callback_scheduled' => 'Müşteriye Ulaşılamadı',
            'customer_confirmed' => 'Müşteri Onayladı',
            'customer_rejected', 'wrong_number', 'customer_requested_cancel' => 'Beklemede',
            default => 'Müşteri Onayı Bekleyen',
        };

        $payload['customer_contacted_at'] = $payload['contacted_at'] ?? now();

        if ($action === 'customer_confirmed') {
            $payload['customer_confirmed_at'] = $payload['contacted_at'] ?? now();
        }

        return $this->transition($request, $target, $payload, $user, $action);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateFieldWorkflow(TechnicalServiceRequest $request, string $fieldAction, array $payload, ?Authenticatable $user = null): TechnicalServiceRequest
    {
        $old = $this->snapshot($request);
        $current = $this->currentWorkflowStatus($request);
        $now = CarbonImmutable::now();

        switch ($fieldAction) {
            case 'start-travel':
                $this->assertFieldWorkflowStatus($current, ['Planlı']);
                $request->workflow_status = 'Yolda';
                $request->field_status = 'yolda';
                $request->technician_started_at = $this->castDateTime($payload['technician_started_at'] ?? $now);
                $actionType = 'field_travel_started';
                break;

            case 'arrive':
                $this->assertFieldWorkflowStatus($current, ['Yolda', 'Planlı']);
                $request->workflow_status = 'Sahada';
                $request->field_status = 'sahada';
                $request->technician_arrived_at = $this->castDateTime($payload['technician_arrived_at'] ?? $now);
                $request->field_arrived_at = $request->technician_arrived_at;
                $actionType = 'field_arrived';
                break;

            case 'start-work':
                $this->assertFieldWorkflowStatus($current, ['Sahada', 'Yolda']);
                $request->workflow_status = 'Sahada';
                $request->field_status = 'sahada';
                $request->field_started_at = $this->castDateTime($payload['field_started_at'] ?? $now);
                $actionType = 'field_work_started';
                break;

            case 'checklist':
                $this->assertFieldWorkflowStatus($current, ['Sahada', 'Belge / Fotoğraf Bekleyen', 'Müşteri Kapanış Onayı Bekleyen']);
                $request->checklist_payload = $this->normalizedChecklistPayload($payload['checklist_payload'] ?? []);
                $request->checklist_status = $this->isChecklistComplete($request->checklist_payload) ? 'tamamlandı' : 'eksik';
                $request->checklist_completed_at = $request->checklist_status === 'tamamlandı' ? $now : null;
                $actionType = 'checklist_updated';
                break;

            case 'photos':
                $this->assertFieldWorkflowStatus($current, ['Sahada', 'Belge / Fotoğraf Bekleyen', 'Müşteri Kapanış Onayı Bekleyen', 'Parça Bekleniyor']);
                $request->before_photo_count = (int) ($payload['before_photo_count'] ?? 0);
                $request->after_photo_count = (int) ($payload['after_photo_count'] ?? 0);
                $request->general_photo_count = (int) ($payload['general_photo_count'] ?? 0);
                $request->photo_status = $this->photoStatusForCounts($request) ? 'tamamlandı' : 'eksik';
                if (! isset($payload['document_status']) && blank($request->document_status)) {
                    $request->document_status = 'eksik';
                }
                if (isset($payload['document_status'])) {
                    $request->document_status = (string) $payload['document_status'];
                }
                $actionType = 'photos_updated';
                break;

            case 'customer-closure-approval':
                $this->assertFieldWorkflowStatus($current, ['Sahada', 'Belge / Fotoğraf Bekleyen', 'Müşteri Kapanış Onayı Bekleyen']);
                $request->customer_closure_approval_status = 'onaylandı';
                $request->customer_closure_approved_at = $this->castDateTime($payload['customer_closure_approved_at'] ?? $now);
                $request->customer_closure_approval_method = $payload['approval_method'] ?? $request->customer_closure_approval_method;
                $request->customer_closure_approval_code = $payload['approval_code'] ?? $request->customer_closure_approval_code;
                $request->customer_signature_name = $payload['signature_name'] ?? $request->customer_signature_name;
                $request->customer_signature_at = isset($payload['signature_name'])
                    ? $this->castDateTime($payload['customer_signature_at'] ?? $now)
                    : $request->customer_signature_at;
                $actionType = 'customer_closure_approved';
                break;

            case 'mark-incomplete':
                $this->assertFieldWorkflowStatus($current, ['Planlı', 'Yolda', 'Sahada', 'Belge / Fotoğraf Bekleyen', 'Müşteri Kapanış Onayı Bekleyen']);
                $request->incomplete_reason = $payload['incomplete_reason'] ?? null;
                $request->pending_reason = $payload['pending_reason'] ?? $request->incomplete_reason;
                $request->requires_second_visit = (bool) ($payload['requires_second_visit'] ?? false);
                $request->second_visit_reason = $payload['second_visit_reason'] ?? null;
                $request->field_status = 'beklemede';
                $request->workflow_status = (string) ($payload['workflow_status'] ?? 'Beklemede');
                $actionType = match ($request->workflow_status) {
                    'Parça Bekleniyor' => 'parts_pending',
                    default => $request->requires_second_visit ? 'second_visit_required' : 'field_marked_incomplete',
                };
                break;

            case 'complete':
                $this->assertFieldWorkflowStatus($current, ['Sahada', 'Belge / Fotoğraf Bekleyen', 'Müşteri Kapanış Onayı Bekleyen']);
                return $this->completeFieldWorkflow($request, $payload, $user, $old, $current);

            default:
                throw ValidationException::withMessages([
                    'field_action' => 'Geçersiz saha aksiyonu.',
                ]);
        }

        $request->field_completion_note = $payload['note'] ?? $request->field_completion_note;
        $request->updated_by_user_id = $user?->id;
        $this->applyDerivedState($request, $payload);
        $request->save();

        $this->writeAuditLog($request, $actionType, $old, $this->snapshot($request), $user, $payload['note'] ?? null);
        $this->writeEvent($request, $actionType, $current, $this->currentWorkflowStatus($request), $user, $payload);

        return $request->refresh();
    }

    /**
     * @return array{due_at:?CarbonImmutable,status:string}
     */
    public function computeSla(TechnicalServiceRequest $request): array
    {
        $status = $this->currentWorkflowStatus($request);
        $dueAt = null;

        if (in_array($status, ['Yeni Talep', 'Eksik Bilgi / Fotoğraf Bekleyen', 'Müşteri Aranacak'], true)) {
            $base = $request->created_at ? CarbonImmutable::parse($request->created_at) : CarbonImmutable::now();
            $dueAt = $base->addHours(24);
        } elseif ($status === 'Müşteriye Ulaşılamadı' && $request->customer_callback_at !== null) {
            $dueAt = CarbonImmutable::parse($request->customer_callback_at);
        } elseif ($status === 'Müşteri Onayı Bekleyen') {
            $base = $request->customer_contacted_at
                ? CarbonImmutable::parse($request->customer_contacted_at)
                : ($request->updated_at ? CarbonImmutable::parse($request->updated_at) : CarbonImmutable::now());
            $dueAt = $base->addHours(24);
        } elseif ($status === 'Müşteri Onayladı' && $request->scheduled_at === null) {
            $base = $request->customer_confirmed_at
                ? CarbonImmutable::parse($request->customer_confirmed_at)
                : ($request->updated_at ? CarbonImmutable::parse($request->updated_at) : CarbonImmutable::now());
            $dueAt = $base->addHours(24);
        } elseif ($status === 'Planlı' && $request->scheduled_at !== null && $request->scheduled_at->isPast()) {
            $dueAt = CarbonImmutable::parse($request->scheduled_at);
        } elseif ($status === 'Yolda' && $request->technician_arrived_at === null) {
            $base = $request->technician_started_at ?? $request->updated_at ?? $request->created_at ?? now();
            $dueAt = CarbonImmutable::parse($base)->addHours(2);
        } elseif ($status === 'Sahada' && $request->field_completed_at === null) {
            $base = $request->field_started_at ?? $request->field_arrived_at ?? $request->updated_at ?? $request->created_at ?? now();
            $dueAt = CarbonImmutable::parse($base)->addHours(4);
        } elseif ($status === 'Belge / Fotoğraf Bekleyen') {
            $base = $request->field_completed_at ?? $request->updated_at ?? $request->created_at ?? now();
            $dueAt = CarbonImmutable::parse($base)->addHours(24);
        } elseif ($status === 'Müşteri Kapanış Onayı Bekleyen') {
            $base = $request->field_completed_at ?? $request->updated_at ?? $request->created_at ?? now();
            $dueAt = CarbonImmutable::parse($base)->addHours(24);
        }

        if ($dueAt === null) {
            return ['due_at' => null, 'status' => self::SLA_NORMAL];
        }

        $now = CarbonImmutable::now();
        if ($dueAt->lessThanOrEqualTo($now)) {
            return ['due_at' => $dueAt, 'status' => self::SLA_OVERDUE];
        }

        $approachingThresholdHours = match ($status) {
            'Yolda' => 1,
            'Sahada' => 2,
            default => 4,
        };

        if ($dueAt->diffInHours($now) <= $approachingThresholdHours) {
            return ['due_at' => $dueAt, 'status' => self::SLA_APPROACHING];
        }

        return ['due_at' => $dueAt, 'status' => self::SLA_NORMAL];
    }

    public function nextActionFor(TechnicalServiceRequest $request): string
    {
        return match ($this->currentWorkflowStatus($request)) {
            'Yeni Talep' => filled($request->missing_info_reason) || $request->document_status === 'bekleniyor' || $request->photo_status === 'bekleniyor'
                ? 'Eksik bilgi/fotoğraf tamamlanmalı'
                : 'Müşteri aranmalı',
            'Eksik Bilgi / Fotoğraf Bekleyen' => 'Eksik bilgi/fotoğraf tamamlanmalı',
            'Müşteri Aranacak' => 'Müşteri ile uygun gün/saat görüşülmeli',
            'Müşteriye Ulaşılamadı' => $request->customer_callback_at !== null
                ? 'Belirlenen tarihte müşteri tekrar aranmalı'
                : 'Tekrar arama tarihi planlanmalı',
            'Müşteri Onayı Bekleyen' => 'Müşteri randevu onayı alınmalı',
            'Müşteri Onayladı' => 'Randevu planlanmalı',
            'Randevu Planlandı' => 'Usta ataması yapılmalı',
            'Usta Ataması Bekleyen' => 'Usta seçilmeli',
            'Usta Onayı Bekleyen' => 'Usta onayı beklenmeli',
            'Usta Tarih Revize Talebi' => 'Yeni tarih için müşteri ile tekrar görüşülmeli',
            'Planlı' => 'Saha süreci bekleniyor',
            'Yolda' => 'Usta sahaya gidiyor',
            'Sahada' => $request->checklist_status !== 'tamamlandı'
                ? 'Checklist tamamlanmalı'
                : ($this->photoStatusForCounts($request) ? 'Müşteri kapanış onayı alınmalı' : 'Checklist ve fotoğraf süreci tamamlanmalı'),
            'Beklemede' => $this->pendingNextAction($request),
            'Müşteri Yerinde Yok', 'Montaj Yeri Hazır Değil' => 'Revize randevu planlanmalı',
            'Parça Bekleniyor' => 'Parça temini ve ikinci randevu planlanmalı',
            'Belge / Fotoğraf Bekleyen' => 'Zorunlu belgeler tamamlanmalı',
            'Müşteri Kapanış Onayı Bekleyen' => 'OTP veya imza ile müşteri kapanış onayı alınmalı',
            'Tamamlandı' => 'Garanti / hakediş / doküman süreci başlatılmalı',
            'İptal' => 'Kapanmış iptal kaydı',
            default => 'Operasyon değerlendirmesi bekleniyor',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(TechnicalServiceRequest $request, bool $includeHistory = false): array
    {
        $this->applyDerivedState($request);
        $request->loadMissing([
            'events' => fn ($query) => $query->orderBy('created_at'),
            'technicianRecord',
        ]);

        $payload = $request->toArray();
        $payload['technician_phone'] = $request->technicianRecord?->phone;
        $payload['technical_service_technician_phone'] = $request->technicianRecord?->phone;
        $payload['technical_service_technician'] = $request->technicianRecord
            ? [
                'id' => $request->technicianRecord->id,
                'name' => $request->technicianRecord->name,
                'phone' => $request->technicianRecord->phone,
            ]
            : null;
        $payload['technicalServiceTechnician'] = $payload['technical_service_technician'];
        $payload['status'] = $request->status;
        $payload['workflow_status'] = $request->workflow_status;
        $payload['next_action'] = $request->next_action;
        $payload['sla_status'] = $request->sla_status ?? self::SLA_NORMAL;
        $payload['allowed_workflow_actions'] = $this->allowedActionsFor($request);
        $payload['allowed_workflow_transitions'] = self::transitionMap()[$this->currentWorkflowStatus($request)] ?? [];
        $payload['latest_event'] = $request->events->last()?->title;
        $payload = array_merge($payload, $this->financialAliases($request));

        if ($includeHistory) {
            if ($this->auditLogTableAvailable()) {
                $request->loadMissing(['auditLogs' => fn ($query) => $query->latest()]);
                $payload['audit_logs'] = $request->auditLogs->values()->all();
            } else {
                $payload['audit_logs'] = [];
                $payload['audit_logs_unavailable'] = true;
            }
        }

        return $payload;
    }

    public function assertTransitionAllowed(string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }

        $allowedTargets = self::transitionMap()[$from] ?? [];
        if (! in_array($to, $allowedTargets, true)) {
            throw ValidationException::withMessages([
                'workflow_status' => "Geçersiz statü geçişi: {$from} -> {$to}",
            ]);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyPayloadForWorkflow(TechnicalServiceRequest $request, string $target, array $payload): void
    {
        $action = (string) ($payload['action'] ?? '');

        if ($action !== '') {
            $request->customer_contact_note = $payload['customer_contact_note'] ?? $payload['note'] ?? $request->customer_contact_note;
            $request->customer_contacted_at = $this->castDateTime($payload['customer_contacted_at'] ?? $payload['contacted_at'] ?? $request->customer_contacted_at);

            switch ($action) {
                case 'customer_called':
                    $request->customer_contact_status = 'arandı';
                    $request->customer_callback_at = null;
                    $request->customer_rejection_reason = null;
                    break;
                case 'customer_unreachable':
                    $request->customer_contact_status = 'ulaşılamadı';
                    $request->customer_callback_at = $this->castDateTime($payload['customer_callback_at'] ?? $request->customer_callback_at);
                    break;
                case 'customer_callback_scheduled':
                    $request->customer_contact_status = 'tekrar_aranacak';
                    $request->customer_callback_at = $this->castDateTime($payload['customer_callback_at'] ?? $request->customer_callback_at);
                    break;
                case 'customer_confirmation_pending':
                    $request->customer_contact_status = 'müşteri_onayı_bekleniyor';
                    $request->customer_preferred_date = $this->castDate($payload['customer_preferred_date'] ?? $request->customer_preferred_date);
                    $request->customer_preferred_time_start = $payload['customer_preferred_time_start'] ?? $request->customer_preferred_time_start;
                    $request->customer_preferred_time_end = $payload['customer_preferred_time_end'] ?? $request->customer_preferred_time_end;
                    break;
                case 'customer_confirmed':
                    $request->customer_contact_status = 'müşteri_onayladı';
                    $request->customer_confirmed_at = $this->castDateTime($payload['customer_confirmed_at'] ?? now());
                    $request->customer_confirmation_method = $payload['customer_confirmation_method']
                        ?? $payload['contact_method']
                        ?? $request->customer_confirmation_method
                        ?? 'telefon';
                    $request->customer_preferred_date = $this->castDate($payload['customer_preferred_date'] ?? $request->customer_preferred_date);
                    $request->customer_preferred_time_start = $payload['customer_preferred_time_start'] ?? $request->customer_preferred_time_start;
                    $request->customer_preferred_time_end = $payload['customer_preferred_time_end'] ?? $request->customer_preferred_time_end;
                    $request->customer_callback_at = null;
                    $request->customer_rejection_reason = null;
                    break;
                case 'customer_rejected':
                    $request->customer_contact_status = 'müşteri_reddetti';
                    $request->customer_rejection_reason = $payload['customer_rejection_reason'] ?? $request->customer_rejection_reason;
                    break;
                case 'wrong_number':
                    $request->customer_contact_status = 'yanlış_numara';
                    break;
                case 'customer_requested_cancel':
                    $request->customer_contact_status = 'iptal_talebi';
                    $request->cancellation_reason = $payload['cancellation_reason'] ?? $payload['note'] ?? $request->cancellation_reason;
                    break;
            }
        }

        switch ($target) {
            case 'Eksik Bilgi / Fotoğraf Bekleyen':
                $request->missing_info_reason = $payload['missing_info_reason'] ?? $payload['note'] ?? $request->missing_info_reason;
                $request->document_status = 'bekleniyor';
                $request->photo_status = 'bekleniyor';
                break;
            case 'Müşteriye Ulaşılamadı':
                $request->customer_contact_status = $request->customer_contact_status ?? 'ulaşılamadı';
                break;
            case 'Müşteri Onayı Bekleyen':
                $request->customer_contact_status = $request->customer_contact_status ?? 'müşteri_onayı_bekleniyor';
                break;
            case 'Müşteri Onayladı':
                $request->customer_contact_status = $request->customer_contact_status ?? 'müşteri_onayladı';
                break;
            case 'Usta Onayı Bekleyen':
                $request->technician_approval_status = 'bekliyor';
                break;
            case 'Usta Tarih Revize Talebi':
                $request->technician_approval_status = 'revize_talebi';
                $request->technician_revision_requested_at = $this->castDateTime($payload['technician_revision_requested_at'] ?? now());
                $request->technician_revision_note = $payload['technician_revision_note'] ?? $payload['note'] ?? $request->technician_revision_note;
                $request->requires_reschedule = true;
                break;
            case 'Planlı':
                $request->technician_approval_status = 'onayladı';
                $request->technician_approved_at = $this->castDateTime($payload['technician_approved_at'] ?? now());
                break;
            case 'Yolda':
                $request->field_status = 'yolda';
                $request->field_started_at = $this->castDateTime($payload['field_started_at'] ?? now());
                break;
            case 'Sahada':
                $request->field_status = 'sahada';
                $request->field_arrived_at = $this->castDateTime($payload['field_arrived_at'] ?? now());
                $request->field_started_at = $request->field_started_at ?? $request->field_arrived_at;
                break;
            case 'Beklemede':
            case 'Müşteri Yerinde Yok':
            case 'Montaj Yeri Hazır Değil':
                $request->field_status = 'beklemede';
                $request->pending_reason = $payload['pending_reason'] ?? $target;
                $request->requires_reschedule = true;
                break;
            case 'Parça Bekleniyor':
                $request->field_status = 'parca_bekleniyor';
                $request->pending_reason = $payload['pending_reason'] ?? 'Parça bekleniyor';
                $request->requires_reschedule = true;
                break;
            case 'Belge / Fotoğraf Bekleyen':
                $request->document_status = 'bekleniyor';
                $request->photo_status = 'bekleniyor';
                $request->field_completed_at = $request->field_completed_at ?? $this->castDateTime(now());
                break;
            case 'Müşteri Kapanış Onayı Bekleyen':
                $request->customer_closure_approval_status = 'bekliyor';
                $request->field_completed_at = $request->field_completed_at ?? $this->castDateTime(now());
                break;
            case 'Tamamlandı':
                $request->completed_at = $this->castDateTime($payload['completed_at'] ?? now());
                $request->field_completed_at = $request->field_completed_at ?? $request->completed_at;
                $request->document_status = $request->document_status ?? 'tamam';
                $request->photo_status = $request->photo_status ?? 'tamam';
                $request->customer_closure_approval_status = $payload['customer_closure_approval_status']
                    ?? $request->customer_closure_approval_status
                    ?? 'onaylandı';
                $request->customer_closure_approved_at = $this->castDateTime($payload['customer_closure_approved_at'] ?? now());
                if (! empty($payload['installation_completed_at'])) {
                    $request->installation_completed_at = $this->castDateTime($payload['installation_completed_at']);
                }
                $request->resolution_notes = $payload['resolution_notes'] ?? $payload['note'] ?? $request->resolution_notes;
                break;
            case 'İptal':
                $request->cancelled_at = $this->castDateTime($payload['cancelled_at'] ?? now());
                $request->cancellation_reason = $payload['cancellation_reason'] ?? $payload['note'] ?? $request->cancellation_reason;
                break;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyDerivedState(TechnicalServiceRequest $request, array $payload = []): void
    {
        $request->workflow_status = $this->currentWorkflowStatus($request);
        $request->status = $this->legacyStatusForWorkflow($request->workflow_status);
        $request->next_action = $this->nextActionFor($request);

        if ($request->scheduled_at !== null) {
            $request->scheduled_date = $request->scheduled_at->toDateString();
            $request->scheduled_time = $request->scheduled_at->format('H:i');
        }

        if ($request->workflow_status === 'Müşteri Aranacak' && blank($request->customer_contact_status)) {
            $request->customer_contact_status = 'aranacak';
        }

        if ($request->workflow_status === 'Usta Ataması Bekleyen' && blank($request->technician_name) && blank($request->technical_service_technician_id)) {
            $request->technician_approval_status = null;
        }

        if ($request->workflow_status === 'Usta Onayı Bekleyen' && blank($request->technician_approval_status)) {
            $request->technician_approval_status = 'bekliyor';
        }

        if ($request->workflow_status === 'Planlı' && blank($request->field_status)) {
            $request->field_status = 'planlı';
        }

        if ($request->workflow_status === 'Yolda' && blank($request->field_status)) {
            $request->field_status = 'yolda';
        }

        if ($request->workflow_status === 'Sahada' && blank($request->field_status)) {
            $request->field_status = 'sahada';
        }

        if ($request->workflow_status === 'Tamamlandı' && blank($request->field_status)) {
            $request->field_status = 'tamamlandı';
        }

        if ($request->checklist_payload === null && in_array($request->workflow_status, ['Planlı', 'Yolda', 'Sahada', 'Belge / Fotoğraf Bekleyen', 'Müşteri Kapanış Onayı Bekleyen'], true)) {
            $request->checklist_payload = $this->defaultChecklistPayload();
        }

        if (array_key_exists('next_action', $payload) && is_string($payload['next_action'])) {
            $request->next_action = $payload['next_action'];
        }

        $sla = $this->computeSla($request);
        $request->sla_due_at = $sla['due_at'];
        $request->sla_status = $sla['status'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeEvent(
        TechnicalServiceRequest $request,
        string $actionType,
        string $fromStatus,
        string $toStatus,
        ?Authenticatable $user,
        array $payload,
        ?string $title = null
    ): void {
        $request->events()->create([
            'event_type' => $actionType,
            'title' => $title ?? (self::actionLabels()[$actionType] ?? 'Workflow güncellendi'),
            'note' => $payload['note'] ?? null,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'author_user_id' => $user?->id,
            'metadata' => Arr::except($payload, ['note']),
        ]);
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     */
    private function writeAuditLog(
        TechnicalServiceRequest $request,
        string $actionType,
        array $old,
        array $new,
        ?Authenticatable $user,
        ?string $note
    ): void {
        if (! $this->auditLogTableAvailable()) {
            return;
        }

        TechnicalServiceAuditLog::query()->create([
            'entity_type' => 'technical_service_request',
            'entity_id' => $request->id,
            'action_type' => $actionType,
            'old_value' => $old,
            'new_value' => $new,
            'user_id' => $user?->id,
            'user_name' => method_exists($user, 'getAttribute')
                ? ($user?->getAttribute('full_name') ?: $user?->getAttribute('name') ?: $user?->getAttribute('username'))
                : null,
            'note' => $note,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(TechnicalServiceRequest $request): array
    {
        return Arr::only($request->toArray(), [
            'status',
            'workflow_status',
            'customer_contact_status',
            'customer_contacted_at',
            'customer_contact_note',
            'customer_confirmed_at',
            'customer_confirmation_method',
            'customer_preferred_date',
            'customer_preferred_time_start',
            'customer_preferred_time_end',
            'customer_callback_at',
            'customer_rejection_reason',
            'scheduled_at',
            'scheduled_date',
            'scheduled_time',
            'technical_service_technician_id',
            'technician_name',
            'technician_approval_status',
            'field_status',
            'document_status',
            'photo_status',
            'field_started_at',
            'field_arrived_at',
            'field_completed_at',
            'field_completion_note',
            'technician_started_at',
            'technician_arrived_at',
            'technician_completed_at',
            'checklist_payload',
            'checklist_status',
            'checklist_completed_at',
            'before_photo_count',
            'after_photo_count',
            'general_photo_count',
            'customer_closure_approval_status',
            'customer_closure_approval_method',
            'customer_closure_approval_code',
            'completed_at',
            'completion_block_reason',
            'incomplete_reason',
            'requires_second_visit',
            'second_visit_reason',
            'cancelled_at',
            'cancellation_reason',
            'next_action',
            'sla_due_at',
            'sla_status',
        ]);
    }

    /**
     * @return array<string, float|int|string|null>
     */
    private function financialAliases(TechnicalServiceRequest $request): array
    {
        $customerAmount = $this->customerAmountForService($request->service_type);
        $travelRoundTripKm = $request->travel_round_trip_km !== null ? (float) $request->travel_round_trip_km : null;
        $travelBillableKm = $request->travel_billable_km !== null
            ? (float) $request->travel_billable_km
            : ($travelRoundTripKm !== null ? max($travelRoundTripKm - 30, 0) : null);
        $travelFee = $request->travel_fee_amount !== null
            ? (float) $request->travel_fee_amount
            : ($travelBillableKm !== null ? $travelBillableKm * 10 : null);
        $technicianFee = $request->technician_payment_amount !== null
            ? (float) $request->technician_payment_amount
            : $customerAmount;
        $totalTechnicianCost = $technicianFee !== null && $travelFee !== null
            ? $technicianFee + $travelFee
            : null;
        $profit = $customerAmount !== null && $totalTechnicianCost !== null
            ? $customerAmount - $totalTechnicianCost
            : null;

        return [
            'customer_fee' => $customerAmount,
            'customer_amount' => $customerAmount,
            'customer_price' => $customerAmount,
            'customer_payment' => $customerAmount,
            'service_fee' => $customerAmount,
            'technician_fee' => $technicianFee,
            'technician_cost' => $technicianFee,
            'technician_payment' => $technicianFee,
            'master_fee' => $technicianFee,
            'labor_cost' => $technicianFee,
            'travel_fee' => $travelFee,
            'travel_cost' => $travelFee,
            'travel_km' => $travelRoundTripKm,
            'travel_round_trip_km' => $travelRoundTripKm,
            'travel_billable_km' => $travelBillableKm,
            'total_technician_cost' => $totalTechnicianCost,
            'total_technician_cost_amount' => $totalTechnicianCost,
            'cost_delta' => $profit,
            'profit' => $profit,
            'margin' => $profit,
        ];
    }

    private function customerAmountForService(?string $serviceType): ?float
    {
        return match ($this->normalizeToken($serviceType)) {
            'montaj' => 3000.0,
            'servis', 'ariza' => 1800.0,
            default => null,
        };
    }

    private function auditLogTableAvailable(): bool
    {
        return Schema::hasTable('technical_service_audit_logs');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function scheduledAtFromPayload(array $payload): ?CarbonImmutable
    {
        $date = (string) ($payload['scheduled_date'] ?? '');
        $time = (string) ($payload['scheduled_time'] ?? '');

        if ($date === '' || $time === '') {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d H:i', "{$date} {$time}");
    }

    private function castDateTime(mixed $value): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value);
    }

    private function castDate(mixed $value): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->startOfDay();
    }

    /**
     * @param list<string> $allowed
     */
    private function assertFieldWorkflowStatus(string $current, array $allowed): void
    {
        if (! in_array($current, $allowed, true)) {
            throw ValidationException::withMessages([
                'workflow_status' => 'Bu saha aksiyonu mevcut durum için kullanılamaz.',
            ]);
        }
    }

    /**
     * @return array<string, bool>
     */
    private function defaultChecklistPayload(): array
    {
        $payload = [];

        foreach (self::CHECKLIST_ITEMS as $item) {
            $payload[$item] = false;
        }

        return $payload;
    }

    /**
     * @param mixed $payload
     * @return array<string, bool>
     */
    private function normalizedChecklistPayload(mixed $payload): array
    {
        $items = is_array($payload) ? $payload : [];
        $normalized = [];

        foreach (self::CHECKLIST_ITEMS as $item) {
            $normalized[$item] = filter_var($items[$item] ?? false, FILTER_VALIDATE_BOOL);
        }

        return $normalized;
    }

    /**
     * @param array<string, bool>|null $payload
     */
    private function isChecklistComplete(?array $payload): bool
    {
        if (! is_array($payload) || $payload === []) {
            return false;
        }

        foreach (self::CHECKLIST_ITEMS as $item) {
            if (! ($payload[$item] ?? false)) {
                return false;
            }
        }

        return true;
    }

    private function photoStatusForCounts(TechnicalServiceRequest $request): bool
    {
        return (int) ($request->before_photo_count ?? 0) >= 3
            && (int) ($request->after_photo_count ?? 0) >= 3
            && (int) ($request->general_photo_count ?? 0) >= 1;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $old
     */
    private function completeFieldWorkflow(
        TechnicalServiceRequest $request,
        array $payload,
        ?Authenticatable $user,
        array $old,
        string $current
    ): TechnicalServiceRequest {
        $blockers = [];

        if ($request->checklist_status !== 'tamamlandı') {
            $blockers[] = 'Checklist tamamlanmadı';
        }

        if (! $this->photoStatusForCounts($request)) {
            $blockers[] = 'Fotoğraf yükleme kriterleri tamamlanmadı';
        }

        if (! in_array($request->document_status, ['tamamlandı', 'tamam', 'gerekli_degil'], true)) {
            $blockers[] = 'Belge durumu tamamlanmadı';
        }

        if ($request->customer_closure_approval_status !== 'onaylandı') {
            $blockers[] = 'Müşteri kapanış onayı eksik';
        }

        if ($blockers !== []) {
            $request->completion_block_reason = implode(' | ', $blockers);
            $request->updated_by_user_id = $user?->id;

            if ($request->customer_closure_approval_status !== 'onaylandı') {
                $request->workflow_status = 'Müşteri Kapanış Onayı Bekleyen';
                $request->customer_closure_approval_status = $request->customer_closure_approval_status ?? 'bekliyor';
            } elseif (
                ! $this->photoStatusForCounts($request)
                || ! in_array($request->document_status, ['tamamlandı', 'tamam', 'gerekli_degil'], true)
            ) {
                $request->workflow_status = 'Belge / Fotoğraf Bekleyen';
                $request->photo_status = $this->photoStatusForCounts($request) ? ($request->photo_status ?? 'tamamlandı') : 'eksik';
                $request->document_status = in_array($request->document_status, ['tamamlandı', 'tamam', 'gerekli_degil'], true)
                    ? $request->document_status
                    : 'eksik';
            } else {
                $request->workflow_status = 'Sahada';
            }

            $this->applyDerivedState($request, $payload);
            $request->save();

            $this->writeAuditLog($request, 'field_completion_blocked', $old, $this->snapshot($request), $user, $request->completion_block_reason);
            $this->writeEvent($request, 'field_completion_blocked', $current, $this->currentWorkflowStatus($request), $user, [
                'note' => $request->completion_block_reason,
                'blockers' => $blockers,
            ], 'Saha kapanışı bloklandı');

            throw ValidationException::withMessages([
                'workflow_status' => $request->completion_block_reason,
            ]);
        }

        $request->workflow_status = 'Tamamlandı';
        $request->field_status = 'tamamlandı';
        $request->field_completed_at = $this->castDateTime($payload['field_completed_at'] ?? now());
        $request->technician_completed_at = $this->castDateTime($payload['technician_completed_at'] ?? $request->field_completed_at);
        $request->completed_at = $request->field_completed_at;
        $request->field_completion_note = $payload['note'] ?? $request->field_completion_note;
        $request->completion_block_reason = null;
        $request->photo_status = 'tamamlandı';
        $request->updated_by_user_id = $user?->id;
        $this->applyDerivedState($request, $payload);
        $request->save();

        $this->writeAuditLog($request, 'field_completed', $old, $this->snapshot($request), $user, $payload['note'] ?? null);
        $this->writeEvent($request, 'field_completed', $current, $this->currentWorkflowStatus($request), $user, $payload, 'Saha işi tamamlandı');

        return $request->refresh();
    }

    private function pendingNextAction(TechnicalServiceRequest $request): string
    {
        if ($request->requires_second_visit) {
            return 'İkinci randevu planlanmalı';
        }

        if (filled($request->incomplete_reason)) {
            return 'Tamamlanamama nedeni değerlendirilip yeni aksiyon planlanmalı';
        }

        return match ($request->customer_contact_status) {
            'müşteri_reddetti' => 'Müşteri ret nedeni değerlendirilmeli',
            'yanlış_numara' => 'Doğru müşteri telefonu güncellenmeli',
            'iptal_talebi' => 'İptal nedeni onaylanmalı',
            default => 'Revize randevu planlanmalı',
        };
    }

    private function normalizeToken(?string $value): string
    {
        return Str::of((string) $value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->value();
    }
}
