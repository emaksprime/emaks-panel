<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceAuditLog;
use App\Models\TechnicalServiceAssignmentOffer;
use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServicePartnerJobAction;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestUpload;
use App\Models\TechnicalServiceRouteQuote;
use App\Models\TechnicalServiceTechnician;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TechnicalServiceWorkflowService
{
    private const FIELD_COMPLETION_DOCUMENT_TYPES = [
        'before_photo' => 'Öncesi',
        'after_photo' => 'Sonrası',
        'warranty_document_photo' => 'Garanti Belgesi',
    ];

    private const CUSTOMER_DOOR_PHOTO_FIELDS = [
        'door_front_photo',
        'door_side_photo',
        'door_back_photo',
    ];

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
            'field_travel_started' => 'Randevu zamanı geldi',
            'field_arrived' => 'Randevu zamanı geldi',
            'field_work_started' => 'Tamamlama işlemi başladı',
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
            'Yeni Talep' => ['Eksik Bilgi / Fotoğraf Bekleyen', 'Müşteri Aranacak', 'Müşteri Onayı Bekleyen', 'Usta Onayı Bekleyen'],
            'Eksik Bilgi / Fotoğraf Bekleyen' => ['Müşteri Aranacak', 'Müşteri Onayı Bekleyen'],
            'Müşteri Aranacak' => ['Müşteriye Ulaşılamadı', 'Müşteri Onayı Bekleyen', 'Müşteri Onayladı', 'Beklemede'],
            'Müşteriye Ulaşılamadı' => ['Müşteri Aranacak', 'Müşteri Onayı Bekleyen', 'Müşteri Onayladı', 'Beklemede'],
            'Müşteri Onayı Bekleyen' => ['Müşteriye Ulaşılamadı', 'Müşteri Onayladı', 'Usta Onayı Bekleyen', 'Beklemede'],
            'Müşteri Onayladı' => ['Randevu Planlandı', 'Beklemede'],
            'Randevu Planlandı' => ['Yeni Talep', 'Usta Ataması Bekleyen', 'Usta Onayı Bekleyen', 'Beklemede', 'Tamamlandı'],
            'Usta Ataması Bekleyen' => ['Usta Onayı Bekleyen', 'Usta Tarih Revize Talebi', 'Beklemede'],
            'Usta Onayı Bekleyen' => ['Planlı', 'Usta Tarih Revize Talebi', 'Beklemede'],
            'Usta Tarih Revize Talebi' => ['Müşteri Aranacak', 'Müşteri Onayı Bekleyen', 'Müşteri Onayladı', 'Randevu Planlandı', 'Usta Onayı Bekleyen'],
            'Planlı' => ['Usta Onayı Bekleyen', 'Yolda', 'Sahada', 'Beklemede', 'İptal'],
            'Yolda' => ['Sahada', 'Beklemede', 'İptal'],
            'Sahada' => ['Belge / Fotoğraf Bekleyen', 'Müşteri Kapanış Onayı Bekleyen', 'Tamamlandı', 'Parça Bekleniyor', 'Beklemede', 'Müşteri Yerinde Yok', 'Montaj Yeri Hazır Değil'],
            'Beklemede' => ['Müşteri Aranacak', 'Müşteri Onayı Bekleyen', 'Randevu Planlandı', 'Usta Ataması Bekleyen', 'Parça Bekleniyor', 'İptal'],
            'Müşteri Yerinde Yok' => ['Randevu Planlandı', 'Müşteri Aranacak', 'İptal'],
            'Montaj Yeri Hazır Değil' => ['Randevu Planlandı', 'Beklemede', 'İptal'],
            'Parça Bekleniyor' => ['Randevu Planlandı', 'Belge / Fotoğraf Bekleyen', 'Beklemede'],
            'Belge / Fotoğraf Bekleyen' => ['Müşteri Kapanış Onayı Bekleyen', 'Tamamlandı'],
            'Müşteri Kapanış Onayı Bekleyen' => ['Tamamlandı', 'Belge / Fotoğraf Bekleyen'],
            'Son Kontrol' => ['Tamamlandı', 'Belge / Fotoğraf Bekleyen', 'Müşteri Kapanış Onayı Bekleyen'],
            'Tamamlandı' => ['Yeni Talep'],
            'İptal' => ['Yeni Talep'],
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
                'assign_technician' => 'Usta Onayı Bekleyen',
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
            'sonkontrol' => 'Son Kontrol',
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

        $hasTechnician = filled($request->technical_service_technician_id) || filled($request->technician_name);
        $approveTechnician = (bool) Arr::get($payload, 'approve_technician', false);
        $target = $hasTechnician
            ? ($approveTechnician ? 'Planlı' : 'Usta Onayı Bekleyen')
            : 'Randevu Planlandı';

        $current = $this->currentWorkflowStatus($request);
        if ($current !== $target && ! in_array($current, self::TERMINAL_STATUSES, true)) {
            $this->assertTransitionAllowed($current, $target);
            $request->workflow_status = $target;
        }

        if ($target === 'Planlı') {
            $request->technician_approval_status = 'onayladı';
            $request->technician_approved_at = $this->castDateTime($payload['technician_approved_at'] ?? now());
        } elseif ($target === 'Usta Onayı Bekleyen') {
            $request->technician_approval_status = 'bekliyor';
            $request->technician_approved_at = null;
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
        $this->assertOperationControlsAllowAssignment($request);

        $old = $this->snapshot($request);
        $reassignAfterReview = (bool) ($payload['reassign_after_review'] ?? false);
        $reopenedForAssignment = false;

        $request->technical_service_technician_id = $payload['technical_service_technician_id'] ?? null;
        $request->technician_name = $payload['technician_name'] ?? $request->technician_name;
        $request->technician_approval_status = $payload['technician_approval_status'] ?? 'bekliyor';
        $request->technician_revision_note = $payload['technician_revision_note'] ?? $request->technician_revision_note;
        $request->updated_by_user_id = $user?->id;

        $target = ($payload['technician_approval_status'] ?? null) === 'revize_talebi'
            ? 'Usta Tarih Revize Talebi'
            : 'Usta Onayı Bekleyen';

        $current = $this->currentWorkflowStatus($request);
        if ($reassignAfterReview && $target === 'Usta Onayı Bekleyen' && $this->canReopenForAssignment($request)) {
            $this->prepareReviewReassignmentState($request);
            $request->workflow_status = $target;
            $reopenedForAssignment = true;
        } elseif ($current !== $target && ! in_array($current, self::TERMINAL_STATUSES, true)) {
            $this->assertTransitionAllowed($current, $target);
            $request->workflow_status = $target;
        }

        $this->applyDerivedState($request, $payload);
        $request->save();

        $actionType = $reopenedForAssignment ? 'reassign_after_review' : 'technician_updated';
        $title = $reopenedForAssignment ? 'İş yeniden atama akışına alındı' : 'Usta bilgisi güncellendi';

        $this->writeAuditLog($request, $actionType, $old, $this->snapshot($request), $user, $payload['note'] ?? null);
        $this->writeEvent($request, $actionType, $current, $this->currentWorkflowStatus($request), $user, $payload, $title);

        return $request->refresh();
    }

    public function canReopenForAssignment(TechnicalServiceRequest $request): bool
    {
        $status = $this->currentWorkflowStatus($request);

        if (in_array($status, [
            'Beklemede',
            'Parça Bekleniyor',
            'Belge / Fotoğraf Bekleyen',
            'Müşteri Kapanış Onayı Bekleyen',
            'Müşteri Yerinde Yok',
            'Montaj Yeri Hazır Değil',
            'Usta Tarih Revize Talebi',
        ], true)) {
            return true;
        }

        return TechnicalServicePartnerJobAction::query()
            ->where('technical_service_request_id', $request->id)
            ->where('status', TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW)
            ->whereIn('action', $this->reviewReassignmentActionTypes())
            ->exists();
    }

    /**
     * @return list<string>
     */
    public function reviewReassignmentActionTypes(): array
    {
        return [
            TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED,
            TechnicalServicePartnerJobAction::ACTION_CUSTOMER_APPROVAL_REJECTED,
            TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED,
            TechnicalServicePartnerJobAction::ACTION_PRICE_REVISION_REQUESTED,
            TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED,
            TechnicalServicePartnerJobAction::ACTION_REVISIT_REQUESTED,
            TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_CHANGE_REQUESTED,
            TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED,
        ];
    }

    private function prepareReviewReassignmentState(TechnicalServiceRequest $request): void
    {
        $request->completed_at = null;
        $request->installation_completed_at = null;
        $request->field_status = null;
        $request->field_completed_at = null;
        $request->technician_completed_at = null;
        $request->checklist_status = null;
        $request->checklist_completed_at = null;
        $request->document_status = null;
        $request->photo_status = null;
        $request->customer_closure_approval_status = null;
        $request->customer_closure_approval_method = null;
        $request->customer_closure_approval_code = null;
        $request->customer_closure_approved_at = null;
        $request->completion_block_reason = null;
        $request->incomplete_reason = null;
        $request->requires_second_visit = false;
        $request->second_visit_reason = null;
        $request->pending_reason = null;
        $request->requires_reschedule = false;
        $request->reschedule_reason = null;
        $request->scheduled_at = null;
        $request->scheduled_date = null;
        $request->scheduled_time = null;
        $request->technician_approved_at = null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{request:TechnicalServiceRequest,message_text:string,copy_text:string,whatsapp_url:string}
     */
    public function recordTechnicianEarningsMessage(
        TechnicalServiceRequest $request,
        TechnicalServiceTechnician $technician,
        array $payload,
        ?Authenticatable $user = null
    ): array {
        $old = $this->snapshot($request);
        $laborAmount = $this->nullableFloat($payload['labor_amount'] ?? null)
            ?? $this->nullableFloat($request->technician_payment_amount)
            ?? $this->customerAmountForService($request->service_type)
            ?? 0.0;
        $routeFeeAmount = $this->nullableFloat($payload['route_fee_amount'] ?? null)
            ?? $this->nullableFloat($request->travel_fee_amount)
            ?? 0.0;
        $submittedTotalAmount = $this->nullableFloat($payload['total_amount'] ?? null);
        $totalAmount = round($laborAmount + $routeFeeAmount, 2);
        $totalAmountCorrected = $submittedTotalAmount !== null && abs($submittedTotalAmount - $totalAmount) > 0.01;
        $note = trim((string) ($payload['note'] ?? ''));
        $messageText = $this->technicianEarningsMessageText($request, $technician, $laborAmount, $routeFeeAmount, $totalAmount, $note);
        $messagePayload = $this->technicianEarningMessageDispatchPayload($request, $technician, [
            'labor_amount' => $laborAmount,
            'route_fee_amount' => $routeFeeAmount,
            'total_amount' => $totalAmount,
            'currency' => 'TRY',
            'note' => $note !== '' ? $note : null,
        ]);

        $operationControl = is_array($request->operation_control_payload) ? $request->operation_control_payload : [];
        $operationControl['technician_earning_message'] = [
            'status' => 'sent',
            'sent_at' => now()->toISOString(),
            'technician_id' => $technician->id,
            'technician_name' => $technician->name,
            'technician_phone' => $this->technicianPhone($technician),
            'labor_amount' => round($laborAmount, 2),
            'route_fee_amount' => round($routeFeeAmount, 2),
            'total_amount' => round($totalAmount, 2),
            'submitted_total_amount' => $submittedTotalAmount !== null ? round($submittedTotalAmount, 2) : null,
            'total_amount_corrected' => $totalAmountCorrected,
            'manual_override' => (bool) ($payload['manual_override'] ?? false),
            'note' => $note !== '' ? $note : null,
            'message_text' => $messageText,
            'message_payload' => $messagePayload,
        ];

        $request->forceFill([
            'technician_payment_amount' => round($laborAmount, 2),
            'travel_fee_amount' => round($routeFeeAmount, 2),
            'operation_control_payload' => $operationControl,
            'updated_by_user_id' => $user?->id,
        ])->save();

        $offer = $this->syncAssignmentOfferFromEarningsMessage(
            $request,
            $technician,
            $laborAmount,
            $routeFeeAmount,
            $totalAmount,
            $note,
            $messagePayload,
            $user,
        );

        $eventPayload = [
            'technician_id' => $technician->id,
            'technical_service_assignment_offer_id' => $offer->id,
            'labor_amount' => round($laborAmount, 2),
            'route_fee_amount' => round($routeFeeAmount, 2),
            'total_amount' => round($totalAmount, 2),
            'submitted_total_amount' => $submittedTotalAmount !== null ? round($submittedTotalAmount, 2) : null,
            'total_amount_corrected' => $totalAmountCorrected,
            'manual_override' => (bool) ($payload['manual_override'] ?? false),
            'note' => $note !== '' ? $note : null,
        ];

        $this->writeAuditLog($request, 'technician_earning_message_sent', $old, $this->snapshot($request), $user, $note !== '' ? $note : null);
        $this->writeEvent(
            $request,
            'technician_earning_message_sent',
            $this->currentWorkflowStatus($request),
            $this->currentWorkflowStatus($request),
            $user,
            $eventPayload,
            'Hakediş bilgisi gönderildi'
        );

        $whatsappPhone = $this->whatsappPhone($this->technicianPhone($technician));
        $whatsappUrl = $whatsappPhone !== ''
            ? 'https://wa.me/'.$whatsappPhone.'?text='.rawurlencode($messageText)
            : '';

        return [
            'request' => $request->refresh(),
            'message_text' => $messageText,
            'copy_text' => $messageText,
            'whatsapp_url' => $whatsappUrl,
        ];
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
                $this->assertFieldWorkflowStatus($current, ['Sahada', 'Belge / Fotoğraf Bekleyen', 'Müşteri Kapanış Onayı Bekleyen', 'Son Kontrol']);
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
            'Planlı' => 'Randevu onaylandı',
            'Yolda' => 'Randevu onaylandı',
            'Sahada' => $request->checklist_status !== 'tamamlandı'
                ? 'Tamamlama kontrolü bekleniyor'
                : ($this->photoStatusForCounts($request) ? 'Müşteri kapanış onayı alınmalı' : 'Fotoğraf ve belge süreci tamamlanmalı'),
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
            'requestSerials',
            'uploads',
            'parentRequest',
            'sourcePartRequest',
            'latestRouteQuote',
            'latestAssignmentOffer.technician',
            'partnerJobActions' => fn ($query) => $query->latest()->limit(12),
            'partRequests' => fn ($query) => $query->latest(),
        ]);

        $payload = $request->toArray();
        $payload['service_type'] = $this->displayServiceType($request);
        $payload['events'] = $this->eventPayload($request->events);
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
        $payload['field_completion_note'] = TechnicalServiceUiLabelService::cleanDisplayText($request->field_completion_note);
        $payload['completion_block_reason'] = TechnicalServiceUiLabelService::cleanDisplayText($request->completion_block_reason);
        $payload['sla_status'] = $request->sla_status ?? self::SLA_NORMAL;
        $payload['allowed_workflow_actions'] = $this->allowedActionsFor($request);
        $payload['allowed_workflow_transitions'] = self::transitionMap()[$this->currentWorkflowStatus($request)] ?? [];
        $payload['latest_event'] = $request->events->last()?->title;
        $payload = array_merge($payload, $this->financialAliases($request));
        $payload['qr_source'] = $this->qrSourcePayload($request);
        $payload['product'] = $this->qrProductPayload($request);
        $payload['sale_and_payment'] = $this->saleAndPaymentPayload($request);
        $payload['documents'] = $this->documentPayload($request);
        $payload['operation_control'] = $this->operationControlPayload($request);
        $payload['assignment_blockers'] = $this->assignmentBlockers($request);
        $payload['invoice_serials'] = $this->invoiceSerialsPayload($request);
        $payload['location'] = $this->locationPayload($request);
        $payload['door_photos'] = $this->doorPhotoPayload($request);
        $payload['field_completion_documents'] = $this->fieldCompletionDocumentPayload($request);
        $payload['route_fee_config'] = app(TechnicalServiceRouteCostService::class)->feeConfig();
        $payload['route_quote'] = $this->routeQuotePayload($request);
        $payload['assignment_offer'] = $this->assignmentOfferPayload($request->latestAssignmentOffer);
        $payload['earning_breakdown'] = $this->earningBreakdownPayload($request);
        $payload['partner_portal_actions'] = $this->partnerPortalActionPayload($request);
        $payload['part_requests'] = $request->partRequests
            ->map(fn ($partRequest): array => app(TechnicalServicePartRequestService::class)->serialize($partRequest))
            ->values()
            ->all();
        $payload['active_part_request'] = collect($payload['part_requests'])
            ->first(fn (array $partRequest): bool => in_array((string) ($partRequest['status'] ?? ''), \App\Models\TechnicalServicePartRequest::ACTIVE_STATUSES, true));
        $payload['root_mrn'] = $request->root_mrn;
        $payload['service_code'] = $request->service_code;
        $payload['service_visit_reason'] = $request->service_visit_reason;
        $payload['display_mrn'] = $request->service_code
            ? trim((string) ($request->root_mrn ?: $request->mrn)).' / '.$request->service_code
            : $request->mrn;
        $payload['service_visit_history'] = $this->serviceVisitHistoryPayload($request);
        $operationalState = app(TechnicalServiceOperationalStatePresenter::class)->present($request);
        $payload['operational_state'] = $operationalState;
        $payload['kanban_column'] = $operationalState['ops_column'];
        $payload['display_action_label'] = $operationalState['display_action_label'];
        $payload['display_tags'] = $operationalState['display_tags'];
        $payload['attention'] = $operationalState['attention'];
        $payload['next_action_payload'] = app(TechnicalServiceNextActionService::class)->forRequest($request);

        if ($includeHistory) {
            if ($this->auditLogTableAvailable()) {
                $request->loadMissing(['auditLogs' => fn ($query) => $query->latest()]);
                $payload['audit_logs'] = $this->auditLogPayload($request->auditLogs);
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

    public function assertOperationControlsAllowAssignment(TechnicalServiceRequest $request): void
    {
        $operationControl = $this->operationControlPayload($request);
        $errors = [];

        if (($operationControl['payment_checked'] ?? 'unreviewed') !== 'yes') {
            $errors['operation_control.payment_checked'] = 'Usta atanamaz. Önce ödeme kontrolünü tamamlayın.';
        }

        if (($operationControl['door_photos_checked'] ?? 'unreviewed') !== 'compatible') {
            $errors['operation_control.door_photos_checked'] = 'Usta atanamaz. Önce kapı görsellerini uygun olarak kontrol edin.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
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
                $request->technician_approved_at = null;
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

        if ($request->workflow_status === 'Usta Onayı Bekleyen' && $request->technician_approval_status === 'bekliyor') {
            $request->technician_approved_at = null;
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
            'qr_link_id',
            'mount_session_id',
            'brand',
            'stock_code',
            'activation_code',
            'current_serial_state',
            'has_current_sale',
            'sale_mount_status',
            'mount_payment_status',
            'mount_payment_label',
            'mount_payment_provider',
            'mount_payment_reference',
            'mount_payment_paid_at',
            'invoice_series',
            'invoice_number',
            'invoice_display_no',
            'dispatch_series',
            'dispatch_number',
            'dispatch_display_no',
            'order_series',
            'order_number',
            'order_display_no',
            'invoice_customer_type',
            'operation_control_payload',
            'operation_control_checked_by_user_id',
            'operation_control_checked_at',
            'cancelled_at',
            'cancellation_reason',
            'next_action',
            'sla_due_at',
            'sla_status',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function qrSourcePayload(TechnicalServiceRequest $request): array
    {
        return [
            'source_channel' => $request->source_channel,
            'qr_link_id' => $request->qr_link_id,
            'mount_session_id' => $request->mount_session_id,
            'current_serial_state' => $request->current_serial_state,
            'has_current_sale' => $request->has_current_sale,
            'invoice_customer_type' => $request->invoice_customer_type,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function qrProductPayload(TechnicalServiceRequest $request): array
    {
        return [
            'serial_number' => $request->serial_number,
            'product_name' => $request->product_name,
            'product_model' => $request->product_model,
            'brand' => $request->brand,
            'stock_code' => $request->stock_code,
            'activation_code' => $request->activation_code,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function saleAndPaymentPayload(TechnicalServiceRequest $request): array
    {
        $extraPayment = $this->latestExtraMountPaymentPayload($request);
        $customerCharges = $this->customerChargeSummaryPayload($request);
        $paymentStatus = app(TechnicalServicePaymentStatusResolver::class)->resolve($request);
        $paidAmount = $this->primaryMountPaidAmount($request, $paymentStatus, $extraPayment);
        $paymentSummary = $this->paymentSummaryPayload($request, $paymentStatus, $extraPayment, $customerCharges, $paidAmount);

        return [
            'sale_mount_status' => $request->sale_mount_status,
            'sale_mount_label' => $this->saleMountLabel($request->sale_mount_status),
            'mount_payment_status' => $request->mount_payment_status,
            'mount_payment_label' => $request->mount_payment_label ?? $this->mountPaymentLabel($request->mount_payment_status, $request->sale_mount_status),
            'mount_payment_received' => $paymentStatus['is_paid'],
            'payment_stage_label' => $paymentStatus['stage_label'],
            'paid_amount' => $paidAmount,
            'paid_amount_label' => $this->moneyLabel($paidAmount),
            'payment_status_label' => $this->paymentStatusLabel($request->mount_payment_status, $paymentStatus),
            'payment_reference' => $request->mount_payment_reference,
            'payment_provider' => $request->mount_payment_provider,
            'paid_at' => $paymentStatus['paid_at'] ?? $this->dateTimeString($request->mount_payment_paid_at),
            'payment_paid_at' => $paymentStatus['paid_at'] ?? $this->dateTimeString($request->mount_payment_paid_at),
            'ops_payment_check_label' => $this->opsPaymentCheckLabel($request),
            'payment_status' => $paymentStatus,
            'extra_mount_payment' => $extraPayment,
            'customer_charges' => $customerCharges,
            'payment_summary' => $paymentSummary,
            'technician_earning_message' => $this->technicianEarningMessagePayload($request),
        ];
    }

    /**
     * @param array<string, mixed> $paymentStatus
     * @param array<string, mixed>|null $extraPayment
     * @param array<string, mixed> $customerCharges
     * @return array<string, mixed>
     */
    private function paymentSummaryPayload(
        TechnicalServiceRequest $request,
        array $paymentStatus,
        ?array $extraPayment,
        array $customerCharges,
        ?float $paidMountAmount
    ): array {
        $paidExtraAmount = ($extraPayment['status'] ?? null) === TechnicalServiceMountPayment::STATUS_PAID
            ? round((float) ($extraPayment['amount'] ?? 0), 2)
            : 0.0;
        $paidServiceAmount = round((float) ($customerCharges['paid_service_amount'] ?? 0), 2);
        $paidPartAmount = round((float) ($customerCharges['paid_part_amount'] ?? 0), 2);
        $hasMountCollection = $paidMountAmount !== null;
        $hasAnyCollection = $hasMountCollection || $paidExtraAmount > 0 || $paidServiceAmount > 0 || $paidPartAmount > 0;
        $totalCustomerCollection = $hasAnyCollection
            ? round((float) ($paidMountAmount ?? 0) + $paidExtraAmount + $paidServiceAmount + $paidPartAmount, 2)
            : null;

        return [
            'mount' => [
                'status' => $request->mount_payment_status,
                'status_label' => $this->paymentStatusLabel($request->mount_payment_status, $paymentStatus),
                'amount' => $paidMountAmount,
                'amount_label' => $this->moneyLabel($paidMountAmount),
                'source' => 'mount_payment',
            ],
            'service' => [
                'status' => $paidServiceAmount > 0 ? TechnicalServiceMountPayment::STATUS_PAID : null,
                'status_label' => $paidServiceAmount > 0 ? 'Ödendi' : 'Ödeme bilgisi yok',
                'amount' => $paidServiceAmount,
                'amount_label' => $this->moneyLabel($paidServiceAmount),
                'source' => $paidServiceAmount > 0 ? 'customer_charge' : null,
            ],
            'part' => [
                'status' => $paidPartAmount > 0 ? TechnicalServiceMountPayment::STATUS_PAID : null,
                'status_label' => $paidPartAmount > 0 ? 'Ödendi' : 'Ödeme bilgisi yok',
                'amount' => $paidPartAmount,
                'amount_label' => $this->moneyLabel($paidPartAmount),
                'source' => $paidPartAmount > 0 ? 'customer_charge' : null,
            ],
            'extra' => [
                'status' => $paidExtraAmount > 0 ? TechnicalServiceMountPayment::STATUS_PAID : null,
                'status_label' => $paidExtraAmount > 0 ? 'Ödendi' : 'Ödeme bilgisi yok',
                'amount' => $paidExtraAmount,
                'amount_label' => $this->moneyLabel($paidExtraAmount),
                'source' => $paidExtraAmount > 0 ? 'extra_mount_payment' : null,
            ],
            'total_customer_collection' => $totalCustomerCollection,
            'total_customer_collection_label' => $this->moneyLabel($totalCustomerCollection),
        ];
    }

    public function mountPaymentReceived(TechnicalServiceRequest $request): bool
    {
        return (bool) app(TechnicalServicePaymentStatusResolver::class)->resolve($request)['is_paid'];
    }

    public function requiresMountExclusionAcknowledgement(TechnicalServiceRequest $request): bool
    {
        $paymentStatus = app(TechnicalServicePaymentStatusResolver::class)->resolve($request);

        return $request->sale_mount_status === TechnicalServiceMountSession::SALE_MONTAJ_HARIC
            && $this->hasMultiProductMountRequest($request)
            && ! (bool) $paymentStatus['is_paid'];
    }

    /**
     * @param array<string, mixed>|null $extraPayment
     * @return array{received:bool,stage_label:string,amount:float|null}
     */
    private function mountPaymentSummaryPayload(TechnicalServiceRequest $request, ?array $extraPayment): array
    {
        if (($extraPayment['status'] ?? null) === TechnicalServiceMountPayment::STATUS_PAID) {
            $reason = (string) ($extraPayment['reason'] ?? '');

            return [
                'received' => true,
                'stage_label' => $reason === 'multi_product' ? 'Çoklu ürün ek ödemesi alındı' : 'Operasyon ödeme linkiyle ödeme alındı',
                'amount' => isset($extraPayment['amount']) ? (float) $extraPayment['amount'] : null,
            ];
        }

        if ($request->mount_payment_status === TechnicalServiceMountSession::PAYMENT_PAID) {
            return [
                'received' => true,
                'stage_label' => $request->mount_payment_provider === 'fake'
                    ? 'Ödeme onaylandı'
                    : 'Form üzerinden ödeme alındı',
                'amount' => $this->customerAmountForService($request->service_type),
            ];
        }

        return [
            'received' => false,
            'stage_label' => $request->mount_payment_status === TechnicalServiceMountSession::PAYMENT_SKIPPED_MULTI_PRODUCT
                ? 'Çoklu ürün ödeme operasyon tarafından netleştirilecek'
                : 'Montaj ödemesi henüz alınmadı',
            'amount' => null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function technicianEarningMessagePayload(TechnicalServiceRequest $request): ?array
    {
        $operationControl = is_array($request->operation_control_payload) ? $request->operation_control_payload : [];
        $payload = $operationControl['technician_earning_message'] ?? null;

        if (! is_array($payload)) {
            return null;
        }

        $laborAmount = $this->nullableFloat($payload['labor_amount'] ?? null)
            ?? $this->nullableFloat($request->technician_payment_amount)
            ?? $this->customerAmountForService($request->service_type)
            ?? 0.0;
        $routeFeeAmount = $this->nullableFloat($payload['route_fee_amount'] ?? null)
            ?? $this->nullableFloat($request->travel_fee_amount)
            ?? 0.0;
        $submittedTotalAmount = $this->nullableFloat($payload['total_amount'] ?? null);
        $totalAmount = round($laborAmount + $routeFeeAmount, 2);
        $technician = $request->technicianRecord;

        $payload['labor_amount'] = round($laborAmount, 2);
        $payload['route_fee_amount'] = round($routeFeeAmount, 2);
        $payload['total_amount'] = $totalAmount;
        $payload['submitted_total_amount'] = $payload['submitted_total_amount'] ?? ($submittedTotalAmount !== null ? round($submittedTotalAmount, 2) : null);
        $payload['total_amount_corrected'] = (bool) ($payload['total_amount_corrected'] ?? ($submittedTotalAmount !== null && abs($submittedTotalAmount - $totalAmount) > 0.01));

        if ($technician instanceof TechnicalServiceTechnician) {
            $payload['message_text'] = $this->technicianEarningsMessageText(
                $request,
                $technician,
                $laborAmount,
                $routeFeeAmount,
                $totalAmount,
                (string) ($payload['note'] ?? ''),
            );
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestExtraMountPaymentPayload(TechnicalServiceRequest $request): ?array
    {
        if ($request->mount_session_id === null) {
            return null;
        }

        $payments = TechnicalServiceMountPayment::query()
            ->where('technical_service_mount_session_id', $request->mount_session_id)
            ->latest('id')
            ->get();

        $payment = $payments->first(function (TechnicalServiceMountPayment $payment) use ($request): bool {
            $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];

            return (int) ($payment->technical_service_request_id ?? 0) === (int) $request->id
                && ($payload['source'] ?? null) === 'operation_extra_mount_fee';
        }) ?? $payments->first(function (TechnicalServiceMountPayment $payment) use ($request): bool {
            $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];

            return ($payload['source'] ?? null) === 'operation_extra_mount_fee'
                && (int) ($payload['technical_service_request_id'] ?? 0) === (int) $request->id;
        });

        if (! $payment instanceof TechnicalServiceMountPayment) {
            return null;
        }

        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];

        return [
            'id' => $payment->id,
            'status' => $payment->status,
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'payment_url' => $payment->payment_url,
            'provider' => $payment->provider,
            'provider_reference' => $payment->provider_reference,
            'paid_at' => $this->dateTimeString($payment->paid_at),
            'reason' => $payload['reason'] ?? null,
            'purpose' => $payload['purpose'] ?? $payload['reason'] ?? null,
            'note' => $payload['note'] ?? null,
            'selected_serial_ids' => is_array($payload['selected_serial_ids'] ?? null)
                ? array_values($payload['selected_serial_ids'])
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function earningBreakdownPayload(TechnicalServiceRequest $request): array
    {
        $requests = $this->rootFinancialRequests($request)
            ->reject(fn (TechnicalServiceRequest $related): bool => $this->isCancelledRequest($related))
            ->values();
        $rows = $requests
            ->map(fn (TechnicalServiceRequest $related): array => $this->earningBreakdownRow($request, $related))
            ->values();
        $current = $rows->firstWhere('id', $request->id);
        $laborTotal = round((float) $rows->sum('labor_amount'), 2);
        $routeTotal = round((float) $rows->sum('route_fee_amount'), 2);
        $total = round((float) $rows->sum('total_amount'), 2);

        return [
            'root_request_id' => $this->rootFinancialRequest($request)?->id ?? $request->id,
            'root_mrn' => $request->root_mrn ?: ($request->parentRequest?->mrn ?: $request->mrn),
            'current_visit' => $current,
            'rows' => $rows->all(),
            'root_total' => [
                'labor_amount' => $laborTotal,
                'route_fee_amount' => $routeTotal,
                'total_amount' => $total,
                'labor_amount_label' => $this->moneyLabel($laborTotal),
                'route_fee_amount_label' => $this->moneyLabel($routeTotal),
                'total_amount_label' => $this->moneyLabel($total),
                'job_count' => $rows->count(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function earningBreakdownRow(TechnicalServiceRequest $currentRequest, TechnicalServiceRequest $request): array
    {
        $request->loadMissing('latestAssignmentOffer');
        $offer = $request->latestAssignmentOffer;
        $laborAmount = $offer instanceof TechnicalServiceAssignmentOffer
            ? (float) ($offer->labor_amount ?? 0)
            : (float) ($this->nullableFloat($request->technician_payment_amount) ?? 0);
        $routeFeeAmount = $offer instanceof TechnicalServiceAssignmentOffer
            ? (float) ($offer->route_fee_amount ?? 0)
            : (float) ($this->nullableFloat($request->travel_fee_amount) ?? 0);
        $totalAmount = round($laborAmount + $routeFeeAmount, 2);
        $kindLabel = $request->parent_request_id !== null || filled($request->service_code) ? 'Servis' : 'Montaj';

        return [
            'id' => $request->id,
            'mrn' => $request->mrn,
            'display_mrn' => $request->service_code
                ? trim((string) ($request->root_mrn ?: $request->mrn)).' / '.$request->service_code
                : $request->mrn,
            'service_code' => $request->service_code,
            'kind' => $kindLabel === 'Servis' ? 'service' : 'mount',
            'kind_label' => $kindLabel,
            'is_current' => (int) $request->id === (int) $currentRequest->id,
            'is_parent' => $request->parent_request_id === null,
            'technician_id' => $request->technical_service_technician_id,
            'technician_name' => $request->technician_name,
            'labor_amount' => round($laborAmount, 2),
            'route_fee_amount' => round($routeFeeAmount, 2),
            'total_amount' => $totalAmount,
            'labor_amount_label' => $this->moneyLabel($laborAmount),
            'route_fee_amount_label' => $this->moneyLabel($routeFeeAmount),
            'total_amount_label' => $this->moneyLabel($totalAmount),
            'status' => $offer instanceof TechnicalServiceAssignmentOffer ? $offer->status : null,
            'status_label' => $this->assignmentOfferStatusLabel($offer instanceof TechnicalServiceAssignmentOffer ? $offer->status : null),
            'completed_at' => $this->dateTimeString($request->completed_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customerChargeSummaryPayload(TechnicalServiceRequest $request): array
    {
        $rows = $this->customerChargePayments($request)
            ->map(fn (TechnicalServiceMountPayment $payment): array => $this->customerChargePaymentPayload($payment))
            ->values();
        $paidRows = $rows->filter(fn (array $row): bool => ($row['status'] ?? null) === TechnicalServiceMountPayment::STATUS_PAID);

        return [
            'rows' => $rows->all(),
            'latest' => $rows->first(),
            'total_service_amount' => round((float) $rows->sum('service_amount'), 2),
            'total_part_amount' => round((float) $rows->sum('part_amount'), 2),
            'total_amount' => round((float) $rows->sum('amount'), 2),
            'paid_service_amount' => round((float) $paidRows->sum('service_amount'), 2),
            'paid_part_amount' => round((float) $paidRows->sum('part_amount'), 2),
            'paid_total_amount' => round((float) $paidRows->sum('amount'), 2),
            'pending_total_amount' => round((float) $rows
                ->filter(fn (array $row): bool => ($row['status'] ?? null) !== TechnicalServiceMountPayment::STATUS_PAID)
                ->sum('amount'), 2),
        ];
    }

    /**
     * @return Collection<int, TechnicalServiceMountPayment>
     */
    private function customerChargePayments(TechnicalServiceRequest $request): Collection
    {
        $requestIds = $this->rootFinancialRequests($request)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values();

        if ($requestIds->isEmpty()) {
            return collect();
        }

        return TechnicalServiceMountPayment::query()
            ->with('technicalServiceRequest')
            ->whereIn('technical_service_request_id', $requestIds->all())
            ->latest('id')
            ->get()
            ->filter(function (TechnicalServiceMountPayment $payment): bool {
                $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];

                return ($payload['source'] ?? null) === 'operation_customer_charge';
            })
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function customerChargePaymentPayload(TechnicalServiceMountPayment $payment): array
    {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $serviceAmount = (float) ($payload['service_amount'] ?? 0);
        $partAmount = (float) ($payload['part_amount'] ?? 0);
        $amount = (float) $payment->amount;

        if ($serviceAmount <= 0 && $partAmount <= 0) {
            $purpose = (string) ($payload['purpose'] ?? $payload['charge_type'] ?? '');
            if ($purpose === 'part_payment') {
                $partAmount = $amount;
            } else {
                $serviceAmount = $amount;
            }
        }
        $messageTemplate = TechnicalServiceUiLabelService::cleanDisplayText($payload['message_template'] ?? null);
        $paymentUrl = (string) ($payment->payment_url ?? '');
        $messageText = trim((string) ($messageTemplate ?: 'Emaks Prime servis/parça ödemeniz için bağlantı aşağıdadır.'));
        if ($paymentUrl !== '' && ! str_contains($messageText, $paymentUrl)) {
            $messageText = trim($messageText)."\n\n".$paymentUrl;
        }

        return [
            'id' => $payment->id,
            'request_id' => $payment->technical_service_request_id,
            'mrn' => $payment->technicalServiceRequest?->mrn,
            'service_code' => $payment->technicalServiceRequest?->service_code,
            'status' => $payment->status,
            'status_label' => $this->customerChargeStatusLabel($payment->status),
            'amount' => round($amount, 2),
            'amount_label' => $this->moneyLabel($amount),
            'service_amount' => round($serviceAmount, 2),
            'service_amount_label' => $this->moneyLabel($serviceAmount),
            'part_amount' => round($partAmount, 2),
            'part_amount_label' => $this->moneyLabel($partAmount),
            'currency' => $payment->currency,
            'payment_url' => $payment->payment_url,
            'provider' => $payment->provider,
            'provider_reference' => $payment->provider_reference,
            'paid_at' => $this->dateTimeString($payment->paid_at),
            'purpose' => $payload['purpose'] ?? $payload['charge_type'] ?? null,
            'purpose_label' => $this->customerChargePurposeLabel((string) ($payload['purpose'] ?? $payload['charge_type'] ?? '')),
            'note' => TechnicalServiceUiLabelService::cleanDisplayText($payload['note'] ?? null),
            'message_template' => $messageTemplate,
            'message_text' => $messageText,
        ];
    }

    /**
     * @return Collection<int, TechnicalServiceRequest>
     */
    private function rootFinancialRequests(TechnicalServiceRequest $request): Collection
    {
        $root = $this->rootFinancialRequest($request) ?? $request;
        $root->loadMissing(['latestAssignmentOffer', 'childRequests.latestAssignmentOffer']);

        return collect([$root])
            ->concat($root->childRequests)
            ->unique('id')
            ->values();
    }

    private function rootFinancialRequest(TechnicalServiceRequest $request): ?TechnicalServiceRequest
    {
        if ($request->parent_request_id === null) {
            return $request;
        }

        if ($request->parentRequest instanceof TechnicalServiceRequest) {
            return $request->parentRequest;
        }

        return TechnicalServiceRequest::query()
            ->with('latestAssignmentOffer')
            ->find($request->parent_request_id);
    }

    private function isCancelledRequest(TechnicalServiceRequest $request): bool
    {
        return $request->cancelled_at !== null
            || str_contains($this->normalizeToken($request->status), 'ptal')
            || str_contains($this->normalizeToken($request->workflow_status), 'ptal');
    }

    private function assignmentOfferStatusLabel(?string $status): string
    {
        return match ($status) {
            TechnicalServiceAssignmentOffer::STATUS_SENT => 'Gönderildi',
            TechnicalServiceAssignmentOffer::STATUS_ACCEPTED => 'Kabul edildi',
            TechnicalServiceAssignmentOffer::STATUS_REVISED => 'Revize edildi',
            TechnicalServiceAssignmentOffer::STATUS_CANCELLED => 'İptal edildi',
            TechnicalServiceAssignmentOffer::STATUS_DRAFT => 'Taslak',
            default => 'Hakediş yok',
        };
    }

    private function customerChargeStatusLabel(?string $status): string
    {
        return match ($status) {
            TechnicalServiceMountPayment::STATUS_PAID => 'Ödendi',
            TechnicalServiceMountPayment::STATUS_PENDING => 'Ödeme bekleniyor',
            TechnicalServiceMountPayment::STATUS_FAILED => 'Ödeme başarısız',
            TechnicalServiceMountPayment::STATUS_CANCELLED => 'İptal edildi',
            TechnicalServiceMountPayment::STATUS_EXPIRED => 'Süresi doldu',
            default => 'Ödeme bilgisi yok',
        };
    }

    private function customerChargePurposeLabel(string $purpose): string
    {
        return match ($purpose) {
            'service_payment' => 'Servis ücreti',
            'part_payment' => 'Parça ücreti',
            'service_and_part_payment' => 'Servis ve parça ücreti',
            default => 'Servis / parça ödemesi',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function documentPayload(TechnicalServiceRequest $request): array
    {
        return [
            'invoice_series' => $request->invoice_series,
            'invoice_number' => $request->invoice_number,
            'invoice_display_no' => $request->invoice_display_no ?: '-',
            'dispatch_series' => $request->dispatch_series,
            'dispatch_number' => $request->dispatch_number,
            'dispatch_display_no' => $request->dispatch_display_no ?: '-',
            'order_series' => $request->order_series,
            'order_number' => $request->order_number,
            'order_display_no' => $request->order_display_no ?: '-',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceSerialsPayload(TechnicalServiceRequest $request): array
    {
        $rows = $request->requestSerials
            ->map(fn ($serial): array => $this->requestSerialPayload($serial))
            ->values();

        $selected = $rows->filter(fn (array $row): bool => (bool) ($row['customer_selected'] ?? false)
            || (bool) ($row['operation_added'] ?? false))->values();
        $returned = $rows->filter(fn (array $row): bool => (bool) ($row['is_returned'] ?? false))->values();
        $other = $rows
            ->filter(fn (array $row): bool => ! (bool) ($row['customer_selected'] ?? false)
                && ! (bool) ($row['operation_added'] ?? false)
                && ! (bool) ($row['is_returned'] ?? false)
                && (bool) ($row['customer_visible'] ?? false))
            ->values();
        $hidden = $rows
            ->filter(fn (array $row): bool => ! (bool) ($row['customer_selected'] ?? false)
                && ! (bool) ($row['operation_added'] ?? false)
                && ! (bool) ($row['is_returned'] ?? false)
                && ! (bool) ($row['customer_visible'] ?? false))
            ->values();
        $addedCount = $rows
            ->filter(fn (array $row): bool => ! (bool) ($row['is_primary'] ?? false)
                && ((bool) ($row['customer_selected'] ?? false) || (bool) ($row['operation_added'] ?? false)))
            ->count();
        $addableCount = $rows
            ->filter(fn (array $row): bool => ! (bool) ($row['is_primary'] ?? false)
                && ! (bool) ($row['is_returned'] ?? false)
                && ! (bool) ($row['customer_selected'] ?? false)
                && ! (bool) ($row['operation_added'] ?? false))
            ->count();
        $displayLimit = 20;

        return [
            'selected_serials' => $selected->take($displayLimit)->all(),
            'other_serials' => $other->take($displayLimit)->all(),
            'hidden_serials' => $hidden->take($displayLimit)->all(),
            'returned_serials' => $returned->take($displayLimit)->all(),
            'all_invoice_serials' => $rows->take($displayLimit)->all(),
            'selected_serial_count' => $selected->count(),
            'other_serial_count' => $other->count(),
            'hidden_serial_count' => $hidden->count(),
            'added_serial_count' => $addedCount,
            'addable_serial_count' => $addableCount,
            'returned_serial_count' => $returned->count(),
            'all_invoice_serial_count' => $rows->count(),
            'display_limit' => $displayLimit,
            'has_returned' => $returned->isNotEmpty(),
            'has_multi_product' => $rows->count() > 1
                || $request->mount_payment_status === TechnicalServiceMountSession::PAYMENT_SKIPPED_MULTI_PRODUCT,
            'check_error' => Arr::get($request->qr_context_payload ?? [], 'invoice_serials.check_error'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requestSerialPayload($serial): array
    {
        $source = is_array($serial->source_payload) ? $serial->source_payload : [];
        $latestSaleRaw = $serial->getRawOriginal('is_current_latest_sale');
        $latestSale = $latestSaleRaw === null ? null : (bool) $serial->is_current_latest_sale;

        return [
            'id' => $serial->id,
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
            'hidden_reason_label' => $this->hiddenReasonLabel($serial->hidden_reason, $source),
            'responsibility_code' => $source['responsibility_code'] ?? null,
            'normalized_responsibility_code' => $source['normalized_responsibility_code'] ?? null,
            'is_responsibility_blocked' => (bool) ($source['is_responsibility_blocked'] ?? false),
            'operation_added' => (bool) ($serial->operation_added ?? false),
            'operation_added_by' => $serial->operation_added_by,
            'operation_added_at' => $serial->operation_added_at?->toISOString(),
            'customer_phone' => $serial->customer_phone,
            'linked_mrn' => $serial->linked_mrn,
            'operation_note' => $serial->operation_note,
            'is_primary' => (bool) $serial->is_primary,
            'is_returned' => (bool) $serial->is_returned,
            'return_note' => $serial->return_note,
            'return_date' => $serial->return_date?->toDateString(),
            'return_document_no' => $serial->return_document_no,
            'is_current_latest_sale' => $latestSale,
            'latest_sale_conflict' => (bool) ($source['latest_sale_conflict'] ?? false),
            'operation_warning' => $source['operation_warning'] ?? null,
            'warning_labels' => is_array($serial->warning_labels ?? null)
                ? array_values($serial->warning_labels)
                : (is_array($source['warning_labels'] ?? null) ? array_values($source['warning_labels']) : []),
            'current_latest_sale_date' => $source['current_latest_sale_date'] ?? null,
            'current_latest_sale_invoice_series' => $source['current_latest_sale_invoice_series'] ?? null,
            'current_latest_sale_invoice_number' => $source['current_latest_sale_invoice_number'] ?? null,
            'mount_payment_status' => $source['mount_payment_status'] ?? $source['extra_mount_payment_status'] ?? null,
            'mount_status_label' => $source['mount_status_label'] ?? null,
            'extra_mount_payment_id' => $source['extra_mount_payment_id'] ?? null,
            'invoice_customer_type' => $serial->invoice_customer_type,
            'color_status' => $serial->color_status ?: $this->serialColorStatus($serial),
        ];
    }

    /**
     * @param array<string, mixed> $source
     */
    private function hiddenReasonLabel(?string $reason, array $source = []): string
    {
        if (in_array($reason, ['dealer_or_partner', 'responsibility_code_blocked'], true)) {
            return 'Müşteriye gösterilmedi - sorumluluk kodu: '.$this->responsibilityCodeLabel($source);
        }

        return match ($reason) {
            'returned' => 'İade gelen seri',
            'dealer_or_partner' => 'Müşteriye gösterilmedi - bayi/proje',
            'unknown_customer_type' => 'Müşteriye gösterilmedi',
            'not_latest_sale' => 'Güncel son satış değil',
            'not_selected' => 'Müşteri seçmedi',
            default => 'Müşteriye gösterilmedi',
        };
    }

    /**
     * @param array<string, mixed> $source
     */
    private function responsibilityCodeLabel(array $source): string
    {
        $code = trim((string) ($source['responsibility_code'] ?? $source['normalized_responsibility_code'] ?? ''));

        return $code !== '' ? $code : 'Boş';
    }

    private function serialColorStatus($serial): string
    {
        if ((bool) $serial->is_returned) {
            return 'red';
        }

        return (bool) $serial->customer_selected || (bool) ($serial->operation_added ?? false) ? 'green' : 'orange';
    }

    /**
     * @return array<string, mixed>
     */
    private function operationControlPayload(TechnicalServiceRequest $request): array
    {
        $payload = is_array($request->operation_control_payload) ? $request->operation_control_payload : [];

        $result = array_replace([
            'payment_checked' => 'unreviewed',
            'address_checked' => 'unreviewed',
            'door_photos_checked' => 'unreviewed',
            'missing_info' => 'unreviewed',
            'customer_call_required' => 'unreviewed',
            'schedule_update_required' => 'unreviewed',
            'note' => null,
            'checked_by_user_id' => $request->operation_control_checked_by_user_id,
            'checked_at' => $this->dateTimeString($request->operation_control_checked_at),
        ], $payload, [
            'checked_by_user_id' => $request->operation_control_checked_by_user_id,
            'checked_at' => $this->dateTimeString($request->operation_control_checked_at),
        ]);
        $acknowledgement = is_array($result['mount_exclusion_acknowledgement'] ?? null)
            ? $result['mount_exclusion_acknowledgement']
            : [];

        $result['mount_exclusion_acknowledgement'] = array_replace([
            'required' => false,
            'payment_received' => false,
            'acknowledged' => false,
            'note' => null,
            'acknowledged_at' => null,
            'acknowledged_by_user_id' => null,
        ], $acknowledgement, [
            'required' => $this->requiresMountExclusionAcknowledgement($request),
            'payment_received' => $this->mountPaymentReceived($request),
        ]);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function locationPayload(TechnicalServiceRequest $request): array
    {
        return [
            'latitude' => $request->location_latitude !== null ? (float) $request->location_latitude : null,
            'longitude' => $request->location_longitude !== null ? (float) $request->location_longitude : null,
            'place_id' => $request->location_place_id,
            'formatted_address' => $request->location_formatted_address,
            'map_url' => $request->location_map_url,
            'source' => $request->location_source,
            'accuracy' => $request->location_accuracy,
            'note' => $request->location_note,
            'building_no' => $request->building_no,
            'apartment_no' => $request->apartment_no,
            'door_no' => $request->door_no,
            'floor_no' => $request->floor_no,
            'site_name' => $request->site_name,
            'shared' => filled($request->location_latitude) && filled($request->location_longitude),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function doorPhotoPayload(TechnicalServiceRequest $request): array
    {
        return $request->uploads
            ->filter(fn (TechnicalServiceRequestUpload $upload): bool => $upload->category === TechnicalServiceRequestUpload::CATEGORY_OPERATION_CONTROL_DOOR_PHOTO
                && in_array((string) $upload->field_code, self::CUSTOMER_DOOR_PHOTO_FIELDS, true))
            ->map(function (TechnicalServiceRequestUpload $upload) use ($request): array {
                $authenticatedUrl = route('api.technical-service.requests.uploads.show', [
                    'technicalServiceRequest' => $request->id,
                    'upload' => $upload->id,
                ]);

                return [
                    'id' => $upload->id,
                    'field_code' => $upload->field_code,
                    'category' => $upload->category,
                    'original_name' => $upload->original_name,
                    'mime' => $upload->mime,
                    'size' => $upload->size,
                    'url' => $authenticatedUrl,
                    'preview_url' => $authenticatedUrl,
                    'download_url' => $authenticatedUrl,
                    'review_status' => $upload->review_status,
                    'review_note' => $upload->review_note,
                    'reviewed_at' => $this->dateTimeString($upload->reviewed_at),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fieldCompletionDocumentPayload(TechnicalServiceRequest $request): array
    {
        return $request->uploads
            ->filter(fn (TechnicalServiceRequestUpload $upload): bool => $this->isFieldCompletionDocument($upload))
            ->map(function (TechnicalServiceRequestUpload $upload) use ($request): array {
                $authenticatedUrl = route('api.technical-service.requests.uploads.show', [
                    'technicalServiceRequest' => $request->id,
                    'upload' => $upload->id,
                ]);

                $fieldCode = (string) $upload->field_code;

                return [
                    'id' => $upload->id,
                    'field_code' => $fieldCode,
                    'label' => self::FIELD_COMPLETION_DOCUMENT_TYPES[$fieldCode] ?? $upload->original_name,
                    'category' => $upload->category,
                    'original_name' => $upload->original_name,
                    'mime' => $upload->mime,
                    'size' => $upload->size,
                    'url' => $authenticatedUrl,
                    'preview_url' => $authenticatedUrl,
                    'download_url' => $authenticatedUrl,
                    'review_status' => $upload->review_status,
                    'review_note' => $upload->review_note,
                    'reviewed_at' => $this->dateTimeString($upload->reviewed_at),
                ];
            })
            ->values()
            ->all();
    }

    private function isFieldCompletionDocument(TechnicalServiceRequestUpload $upload): bool
    {
        if ($upload->category === TechnicalServiceRequestUpload::CATEGORY_PARTNER_PORTAL_FIELD_DOCUMENT) {
            return true;
        }

        return $upload->category === TechnicalServiceRequestUpload::CATEGORY_OPERATION_CONTROL_DOOR_PHOTO
            && array_key_exists((string) $upload->field_code, self::FIELD_COMPLETION_DOCUMENT_TYPES);
    }

    /**
     * @return array{payment_check_required:bool,door_photo_check_required:bool,messages:array<int,string>}
     */
    private function assignmentBlockers(TechnicalServiceRequest $request): array
    {
        $operationControl = $this->operationControlPayload($request);
        $messages = [];
        $paymentRequired = ($operationControl['payment_checked'] ?? 'unreviewed') !== 'yes';
        $doorPhotoRequired = ($operationControl['door_photos_checked'] ?? 'unreviewed') !== 'compatible';
        $mountExclusionAckRequired = $this->requiresMountExclusionAcknowledgement($request);

        if ($paymentRequired) {
            $messages[] = 'Usta atanamaz. Önce ödeme kontrolünü tamamlayın.';
        }

        if ($doorPhotoRequired) {
            $messages[] = 'Usta atanamaz. Önce kapı görsellerini uygun olarak kontrol edin.';
        }

        return [
            'payment_check_required' => $paymentRequired,
            'door_photo_check_required' => $doorPhotoRequired,
            'mount_exclusion_ack_required' => $mountExclusionAckRequired,
            'mount_payment_received' => $this->mountPaymentReceived($request),
            'messages' => $messages,
        ];
    }

    private function hasMultiProductMountRequest(TechnicalServiceRequest $request): bool
    {
        $context = is_array($request->qr_context_payload) ? $request->qr_context_payload : [];
        $serialCount = $request->relationLoaded('requestSerials')
            ? $request->requestSerials->count()
            : $request->requestSerials()->count();

        return $request->mount_payment_status === TechnicalServiceMountSession::PAYMENT_SKIPPED_MULTI_PRODUCT
            || $serialCount > 1
            || (bool) Arr::get($context, 'multiple_products')
            || (bool) Arr::get($context, 'multi_product')
            || (string) Arr::get($context, 'customer_entry_mode') === TechnicalServiceMountSession::ENTRY_MULTI_PRODUCT_WITHOUT_PAYMENT;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function routeQuotePayload(TechnicalServiceRequest $request): ?array
    {
        $quote = $request->latestRouteQuote;

        if (! $quote instanceof TechnicalServiceRouteQuote) {
            return null;
        }

        return app(TechnicalServiceRouteCostService::class)->payload($quote);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function assignmentOfferPayload(?TechnicalServiceAssignmentOffer $offer): ?array
    {
        if (! $offer instanceof TechnicalServiceAssignmentOffer) {
            return null;
        }

        $metadata = is_array($offer->metadata) ? $offer->metadata : [];
        $messagePayload = is_array($metadata['message_payload'] ?? null) ? $metadata['message_payload'] : [];
        $messageDispatch = is_array($metadata['message_dispatch'] ?? null) ? $metadata['message_dispatch'] : [];

        return [
            'id' => $offer->id,
            'technical_service_request_id' => $offer->technical_service_request_id,
            'technical_service_technician_id' => $offer->technical_service_technician_id,
            'technician_name' => $offer->technician?->name,
            'route_quote_id' => $offer->route_quote_id,
            'labor_amount' => (float) $offer->labor_amount,
            'route_fee_amount' => (float) $offer->route_fee_amount,
            'total_amount' => (float) $offer->total_amount,
            'currency' => $offer->currency,
            'status' => $offer->status,
            'note' => $offer->note,
            'sent_at' => $this->dateTimeString($offer->sent_at),
            'metadata' => $metadata,
            'message_payload' => $messagePayload,
            'message_text' => $messagePayload['message_text'] ?? null,
            'job_link' => $messagePayload['job_link'] ?? null,
            'dispatch_status' => $messageDispatch['status'] ?? null,
            'created_at' => $this->dateTimeString($offer->created_at),
            'updated_at' => $this->dateTimeString($offer->updated_at),
        ];
    }

    /**
     * @param  array<string, mixed>  $messagePayload
     */
    private function syncAssignmentOfferFromEarningsMessage(
        TechnicalServiceRequest $request,
        TechnicalServiceTechnician $technician,
        float $laborAmount,
        float $routeFeeAmount,
        float $totalAmount,
        string $note,
        array $messagePayload,
        ?Authenticatable $user,
    ): TechnicalServiceAssignmentOffer {
        TechnicalServiceAssignmentOffer::query()
            ->where('technical_service_request_id', $request->id)
            ->where('technical_service_technician_id', '<>', $technician->id)
            ->whereIn('status', [
                TechnicalServiceAssignmentOffer::STATUS_SENT,
                TechnicalServiceAssignmentOffer::STATUS_REVISED,
            ])
            ->update([
                'status' => TechnicalServiceAssignmentOffer::STATUS_CANCELLED,
                'updated_at' => now(),
            ]);

        $offer = TechnicalServiceAssignmentOffer::query()
            ->where('technical_service_request_id', $request->id)
            ->where('technical_service_technician_id', $technician->id)
            ->whereIn('status', [
                TechnicalServiceAssignmentOffer::STATUS_SENT,
                TechnicalServiceAssignmentOffer::STATUS_REVISED,
            ])
            ->latest('id')
            ->first();

        $metadata = $offer instanceof TechnicalServiceAssignmentOffer && is_array($offer->metadata)
            ? $offer->metadata
            : [];
        $metadata['source'] = $metadata['source'] ?? 'technician_earning_message';
        $metadata['message_payload'] = $messagePayload;
        $metadata['synced_from_earning_message_at'] = now()->toISOString();

        if (! $offer instanceof TechnicalServiceAssignmentOffer) {
            $offer = new TechnicalServiceAssignmentOffer([
                'technical_service_request_id' => $request->id,
                'technical_service_technician_id' => $technician->id,
                'route_quote_id' => $request->latestRouteQuote?->id,
                'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
                'sent_by' => $user?->id,
                'sent_at' => now(),
            ]);
        }

        $offer->forceFill([
            'labor_amount' => round($laborAmount, 2),
            'route_fee_amount' => round($routeFeeAmount, 2),
            'total_amount' => round($totalAmount, 2),
            'currency' => 'TRY',
            'status' => TechnicalServiceAssignmentOffer::STATUS_SENT,
            'note' => $note !== '' ? $note : null,
            'metadata' => $metadata,
        ])->save();

        return $offer;
    }

    /**
     * @param  array<string, mixed>  $amounts
     * @return array<string, mixed>
     */
    private function technicianEarningMessageDispatchPayload(
        TechnicalServiceRequest $request,
        TechnicalServiceTechnician $technician,
        array $amounts,
    ): array {
        $phone = $technician->phone_e164 ?: ($technician->phone_display ?: $technician->phone);

        return [
            'channel' => 'system_payload',
            'recipient' => 'technician',
            'technician_id' => $technician->id,
            'technician_name' => $technician->name,
            'technician_phone' => $phone,
            'mrn' => $request->mrn,
            'customer_name' => $request->customer_name,
            'customer_phone' => $request->customer_phone,
            'address' => $request->location_formatted_address ?: $request->service_address,
            'job_link' => url('/partner/service-jobs?job_id='.$request->id),
            'appointment_date' => $request->scheduled_date?->toDateString(),
            'appointment_time' => $request->scheduled_time,
            'labor_amount' => round((float) ($amounts['labor_amount'] ?? 0), 2),
            'route_fee_amount' => round((float) ($amounts['route_fee_amount'] ?? 0), 2),
            'total_amount' => round((float) ($amounts['total_amount'] ?? 0), 2),
            'currency' => $amounts['currency'] ?? 'TRY',
            'note' => $amounts['note'] ?? null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function eventPayload($events): array
    {
        return $events
            ->map(function ($event): array {
                $row = $event->toArray();
                $eventType = (string) ($event->event_type ?? '');

                return [
                    ...$row,
                    'title' => TechnicalServiceUiLabelService::cleanDisplayText($event->title),
                    'note' => TechnicalServiceUiLabelService::cleanDisplayText($event->note),
                    'event_type_label' => TechnicalServiceUiLabelService::actionLabel($eventType),
                    'title_label' => filled($event->title)
                        ? TechnicalServiceUiLabelService::actionLabel($eventType)
                        : TechnicalServiceUiLabelService::actionLabel($eventType),
                    'from_status_label' => TechnicalServiceUiLabelService::statusLabel($event->from_status),
                    'to_status_label' => TechnicalServiceUiLabelService::statusLabel($event->to_status),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function auditLogPayload($logs): array
    {
        return $logs
            ->map(function (TechnicalServiceAuditLog $log): array {
                $row = $log->toArray();

                return [
                    ...$row,
                    'action_label' => TechnicalServiceUiLabelService::actionLabel($log->action_type),
                ];
            })
            ->values()
            ->all();
    }

    private function displayServiceType(TechnicalServiceRequest $request): ?string
    {
        if ($request->parent_request_id !== null || $request->service_code !== null) {
            return 'Servis';
        }

        return $request->service_type;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serviceVisitHistoryPayload(TechnicalServiceRequest $request): ?array
    {
        $rootMrn = (string) ($request->root_mrn ?: ($request->parent_request_id ? $request->parentRequest?->mrn : $request->mrn));
        $isServiceVisit = filled($request->service_code) || $request->parent_request_id !== null;
        $parent = $request->parentRequest;
        $root = null;

        if ($rootMrn !== '') {
            $root = TechnicalServiceRequest::query()
                ->with([
                    'events' => fn ($query) => $query->latest()->limit(8),
                    'partRequests' => fn ($query) => $query->latest()->limit(6),
                ])
                ->where('mrn', $rootMrn)
                ->first();
        }

        if (! $root instanceof TechnicalServiceRequest && $parent instanceof TechnicalServiceRequest) {
            $root = $parent;
        }

        $siblings = collect();
        if ($root instanceof TechnicalServiceRequest) {
            $siblings = TechnicalServiceRequest::query()
                ->where(function ($query) use ($root, $rootMrn): void {
                    $query->where('parent_request_id', $root->id);

                    if ($rootMrn !== '') {
                        $query->orWhere('root_mrn', $rootMrn);
                    }
                })
                ->orderBy('service_sequence')
                ->orderBy('id')
                ->limit(12)
                ->get();
        }

        if (! $isServiceVisit && $siblings->isEmpty()) {
            return null;
        }

        $partRequestSerializer = app(TechnicalServicePartRequestService::class);

        return [
            'root_mrn' => $rootMrn !== '' ? $rootMrn : null,
            'service_code' => $request->service_code,
            'reason' => $request->service_visit_reason,
            'reason_label' => TechnicalServiceUiLabelService::serviceVisitReasonLabel($request->service_visit_reason),
            'parent_request' => $root instanceof TechnicalServiceRequest ? $this->serviceVisitRequestSummary($root) : null,
            'parent_events' => $root instanceof TechnicalServiceRequest
                ? array_slice($this->eventPayload($root->events), 0, 8)
                : [],
            'parent_part_requests' => $root instanceof TechnicalServiceRequest
                ? $root->partRequests
                    ->map(fn ($partRequest): array => $partRequestSerializer->serialize($partRequest))
                    ->values()
                    ->all()
                : [],
            'sibling_service_visits' => $siblings
                ->reject(fn (TechnicalServiceRequest $sibling): bool => (int) $sibling->id === (int) $request->id)
                ->map(fn (TechnicalServiceRequest $sibling): array => $this->serviceVisitRequestSummary($sibling))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceVisitRequestSummary(TechnicalServiceRequest $request): array
    {
        return [
            'id' => $request->id,
            'mrn' => $request->mrn,
            'root_mrn' => $request->root_mrn,
            'service_code' => $request->service_code,
            'service_visit_reason' => $request->service_visit_reason,
            'service_visit_reason_label' => TechnicalServiceUiLabelService::serviceVisitReasonLabel($request->service_visit_reason),
            'status' => $request->status,
            'workflow_status' => $request->workflow_status,
            'completed_at' => $this->dateTimeString($request->completed_at),
            'created_at' => $this->dateTimeString($request->created_at),
            'latest_event' => $request->relationLoaded('events')
                ? TechnicalServiceUiLabelService::cleanDisplayText($request->events->first()?->title)
                : null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function partnerPortalActionPayload(TechnicalServiceRequest $request): array
    {
        if (! $request->relationLoaded('partnerJobActions')) {
            return [];
        }

        return $request->partnerJobActions
            ->map(fn (TechnicalServicePartnerJobAction $action): array => [
                'id' => $action->id,
                'partner_id' => $action->partner_id,
                'user_id' => $action->user_id,
                'technical_service_technician_id' => $action->technical_service_technician_id,
                'action' => $action->action,
                'action_label' => TechnicalServiceUiLabelService::actionLabel($action->action),
                'status' => $action->status,
                'status_label' => TechnicalServiceUiLabelService::statusLabel($action->status),
                'note' => TechnicalServiceUiLabelService::cleanDisplayText($action->note),
                'payload' => is_array($action->payload) ? $action->payload : [],
                'created_at' => $this->dateTimeString($action->created_at),
                'updated_at' => $this->dateTimeString($action->updated_at),
            ])
            ->values()
            ->all();
    }

    private function appointmentStartAt(TechnicalServiceRequest $request): ?CarbonImmutable
    {
        if ($request->scheduled_at instanceof CarbonInterface) {
            return CarbonImmutable::instance($request->scheduled_at);
        }

        if ($request->scheduled_at !== null && $request->scheduled_at !== '') {
            return CarbonImmutable::parse($request->scheduled_at);
        }

        if ($request->scheduled_date instanceof CarbonInterface && filled($request->scheduled_time)) {
            return CarbonImmutable::parse($request->scheduled_date->toDateString().' '.$request->scheduled_time);
        }

        return null;
    }

    private function appointmentTrackingEligible(TechnicalServiceRequest $request): bool
    {
        if ($request->completed_at !== null || in_array($request->workflow_status, ['Tamamlandı', 'TamamlandÄ±', 'İptal', 'Ä°ptal'], true)) {
            return false;
        }

        $request->loadMissing(['partnerJobActions' => fn ($query) => $query->latest()->limit(12)]);
        $blockingAction = $request->partnerJobActions
            ->first(fn (TechnicalServicePartnerJobAction $action): bool => $action->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW
                && in_array($action->action, [
                    TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED,
                    TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED,
                    TechnicalServicePartnerJobAction::ACTION_CUSTOMER_APPROVAL_REJECTED,
                ], true));

        return ! $blockingAction instanceof TechnicalServicePartnerJobAction;
    }

    private function appointmentAttentionState(TechnicalServiceRequest $request): ?array
    {
        if (! $this->appointmentTrackingEligible($request)) {
            return null;
        }

        $startAt = $this->appointmentStartAt($request);
        if (! $startAt instanceof CarbonImmutable || $startAt->isFuture()) {
            return null;
        }

        if (CarbonImmutable::now()->greaterThanOrEqualTo($startAt->addHours(12))) {
            return [
                'sort_priority' => 1,
                'attention_level' => 'critical',
                'attention_reason' => 'İş kapanışı için usta ile iletişime geçin',
                'last_action_at' => $this->dateTimeString($startAt),
                'action' => 'appointment_overdue_for_closure',
            ];
        }

        return [
            'sort_priority' => 8,
            'attention_level' => 'info',
            'attention_reason' => 'Usta müşteride',
            'last_action_at' => $this->dateTimeString($startAt),
            'action' => 'appointment_in_progress',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attentionPayload(TechnicalServiceRequest $request): array
    {
        $request->loadMissing(['partnerJobActions' => fn ($query) => $query->latest()->limit(12)]);
        $opsReview = $request->partnerJobActions
            ->filter(fn (TechnicalServicePartnerJobAction $action): bool => $action->status === TechnicalServicePartnerJobAction::STATUS_OPS_REVIEW);

        $appointmentAttention = $this->appointmentAttentionState($request);
        if (($appointmentAttention['sort_priority'] ?? null) === 1) {
            return $appointmentAttention;
        }

        $orderedActions = [
            TechnicalServicePartnerJobAction::ACTION_PRICE_REVISION_REQUESTED => ['priority' => 4, 'level' => 'critical', 'reason' => 'Hakediş revize talebi'],
            TechnicalServicePartnerJobAction::ACTION_JOB_REJECTED => ['priority' => 2, 'level' => 'critical', 'reason' => 'Usta işi reddetti'],
            TechnicalServicePartnerJobAction::ACTION_CUSTOMER_APPROVAL_REJECTED => ['priority' => 3, 'level' => 'critical', 'reason' => 'Müşteri onayı reddedildi'],
            TechnicalServicePartnerJobAction::ACTION_COMPLETION_SUBMITTED => ['priority' => 5, 'level' => 'warning', 'reason' => 'Son kontrol bekliyor'],
            TechnicalServicePartnerJobAction::ACTION_APPOINTMENT_PROPOSED => ['priority' => 6, 'level' => 'warning', 'reason' => 'Usta randevu önerdi'],
            TechnicalServicePartnerJobAction::ACTION_SUPPORT_REQUESTED => ['priority' => 9, 'level' => 'warning', 'reason' => 'Ek talep var'],
            TechnicalServicePartnerJobAction::ACTION_REVISIT_REQUESTED => ['priority' => 9, 'level' => 'warning', 'reason' => 'Tekrar ziyaret talebi'],
        ];

        foreach ($orderedActions as $actionType => $payload) {
            $action = $opsReview->firstWhere('action', $actionType);
            if ($action instanceof TechnicalServicePartnerJobAction) {
                return [
                    'sort_priority' => $payload['priority'],
                    'attention_level' => $payload['level'],
                    'attention_reason' => $payload['reason'],
                    'last_action_at' => $this->dateTimeString($action->created_at),
                    'action' => $action->action,
                ];
            }
        }

        if ($appointmentAttention !== null) {
            return $appointmentAttention;
        }

        return [
            'sort_priority' => in_array($request->workflow_status, ['TamamlandÄ±', 'Tamamlandı', 'Ä°ptal', 'İptal'], true) ? 100 : 12,
            'attention_level' => 'normal',
            'attention_reason' => null,
            'last_action_at' => $this->dateTimeString($request->updated_at),
            'action' => null,
        ];
    }

    private function saleMountLabel(?string $status): string
    {
        return match ($status) {
            TechnicalServiceMountSession::SALE_MONTAJ_DAHIL,
            TechnicalServiceMountSession::SALE_MONTAJ_SONRADAN_DAHIL => 'Montaj dahil',
            TechnicalServiceMountSession::SALE_MONTAJ_HARIC => 'Montaj Hariç',
            TechnicalServiceMountSession::SALE_CHECK_FAILED => 'Kontrol bekliyor',
            TechnicalServiceMountSession::SALE_NOT_FOUND => 'Seri bulunamadı',
            default => '-',
        };
    }

    private function mountPaymentLabel(?string $paymentStatus, ?string $saleMountStatus): string
    {
        return match ($paymentStatus) {
            TechnicalServiceMountSession::PAYMENT_PAID => 'Montaj ödemesi alındı',
            TechnicalServiceMountSession::PAYMENT_NOT_REQUIRED => 'Montaj dahil',
            TechnicalServiceMountSession::PAYMENT_SKIPPED_MULTI_PRODUCT => 'Çoklu ürün talebi - ödeme operasyon tarafından netleştirilecek',
            TechnicalServiceMountSession::PAYMENT_PENDING => 'Montaj ödemesi bekleniyor',
            default => in_array($saleMountStatus, [
                TechnicalServiceMountSession::SALE_MONTAJ_DAHIL,
                TechnicalServiceMountSession::SALE_MONTAJ_SONRADAN_DAHIL,
            ], true) ? 'Montaj dahil' : '-',
        };
    }

    private function dateTimeString(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toISOString();
        }

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function moneyLabel(?float $amount): ?string
    {
        if ($amount === null) {
            return null;
        }

        $decimals = floor($amount) === $amount ? 0 : 2;

        return number_format($amount, $decimals, ',', '.').' TL';
    }

    /**
     * @param array<string, mixed> $paymentStatus
     */
    private function paymentStatusLabel(?string $status, array $paymentStatus): string
    {
        if ((bool) ($paymentStatus['is_paid'] ?? false)) {
            return 'Ödendi';
        }

        return match ($this->normalizeToken($status)) {
            'paid' => 'Ödendi',
            'pending' => 'Ödeme bekleniyor',
            'failed' => 'Ödeme başarısız',
            'cancelled' => 'İptal edildi',
            'expired' => 'Süresi doldu',
            'notrequired' => 'Ödeme gerekmiyor',
            'skippedmultiproduct' => 'Operasyon kontrolünde',
            default => 'Ödeme bilgisi yok',
        };
    }

    private function opsPaymentCheckLabel(TechnicalServiceRequest $request): string
    {
        $payload = is_array($request->operation_control_payload) ? $request->operation_control_payload : [];

        return match ((string) ($payload['payment_checked'] ?? 'unreviewed')) {
            'yes' => 'Evet',
            'no' => 'Hayır',
            default => 'Kontrol edilmedi',
        };
    }

    /**
     * @param array<string, mixed> $paymentStatus
     * @param array<string, mixed>|null $extraPayment
     */
    private function primaryMountPaidAmount(TechnicalServiceRequest $request, array $paymentStatus, ?array $extraPayment): ?float
    {
        $latestPaymentId = $paymentStatus['latest_payment_id'] ?? null;
        $extraPaymentId = $extraPayment['id'] ?? null;
        $latestPaymentIsExtra = $latestPaymentId !== null
            && $extraPaymentId !== null
            && (int) $latestPaymentId === (int) $extraPaymentId;

        if ((bool) ($paymentStatus['is_paid'] ?? false)
            && ! $latestPaymentIsExtra
            && isset($paymentStatus['amount'])
            && is_numeric($paymentStatus['amount'])) {
            return (float) $paymentStatus['amount'];
        }

        if ($request->mount_payment_status === TechnicalServiceMountSession::PAYMENT_PAID) {
            return $this->customerAmountForService($request->service_type);
        }

        return null;
    }

    /**
     * @return array<string, float|int|string|null>
     */
    private function financialAliases(TechnicalServiceRequest $request): array
    {
        $paymentStatus = app(TechnicalServicePaymentStatusResolver::class)->resolve($request);
        $extraPayment = $this->latestExtraMountPaymentPayload($request);
        $customerCharges = $this->customerChargeSummaryPayload($request);
        $paidMountCustomerAmount = $this->primaryMountPaidAmount($request, $paymentStatus, $extraPayment);
        $customerAmount = $paidMountCustomerAmount ?? $this->customerAmountForService($request->service_type);
        $paidExtraCustomerAmount = ($extraPayment['status'] ?? null) === TechnicalServiceMountPayment::STATUS_PAID
            ? (float) ($extraPayment['amount'] ?? 0)
            : 0.0;
        $paidCustomerChargeAmount = (float) ($customerCharges['paid_total_amount'] ?? 0);
        $totalCustomerCollected = $paidMountCustomerAmount !== null
            ? $paidMountCustomerAmount + $paidExtraCustomerAmount + $paidCustomerChargeAmount
            : ($paidExtraCustomerAmount + $paidCustomerChargeAmount > 0 ? $paidExtraCustomerAmount + $paidCustomerChargeAmount : null);
        $travelRoundTripKm = $request->travel_round_trip_km !== null ? (float) $request->travel_round_trip_km : null;
        $travelBillableKm = $request->travel_billable_km !== null
            ? (float) $request->travel_billable_km
            : ($travelRoundTripKm !== null ? max($travelRoundTripKm - 30, 0) : null);
        $travelFee = $request->travel_fee_amount !== null
            ? (float) $request->travel_fee_amount
            : ($travelBillableKm !== null && is_numeric(config('services.google.routes_fee_per_km'))
                ? $travelBillableKm * (float) config('services.google.routes_fee_per_km')
                : null);
        $technicianFee = $request->technician_payment_amount !== null
            ? (float) $request->technician_payment_amount
            : $customerAmount;
        $totalTechnicianCost = $technicianFee !== null && $travelFee !== null
            ? $technicianFee + $travelFee
            : null;
        $profit = $totalCustomerCollected !== null && $totalTechnicianCost !== null
            ? $totalCustomerCollected - $totalTechnicianCost
            : null;

        return [
            'customer_fee' => $customerAmount,
            'customer_amount' => $customerAmount,
            'customer_price' => $customerAmount,
            'customer_payment' => $customerAmount,
            'extra_customer_payment' => $paidExtraCustomerAmount,
            'customer_charge_payment' => $paidCustomerChargeAmount,
            'service_customer_payment' => (float) ($customerCharges['paid_service_amount'] ?? 0),
            'part_customer_payment' => (float) ($customerCharges['paid_part_amount'] ?? 0),
            'total_customer_collected' => $totalCustomerCollected,
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

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function technicianPhone(TechnicalServiceTechnician $technician): string
    {
        return trim((string) ($technician->phone_e164 ?: $technician->phone_display ?: $technician->phone ?: ''));
    }

    private function whatsappPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '0')) {
            $digits = '90'.substr($digits, 1);
        }

        if (! str_starts_with($digits, '90') && strlen($digits) === 10) {
            $digits = '90'.$digits;
        }

        return $digits;
    }

    private function moneyText(float $amount): string
    {
        return number_format($amount, 2, ',', '.').' TL';
    }

    private function technicianEarningsMessageText(
        TechnicalServiceRequest $request,
        TechnicalServiceTechnician $technician,
        float $laborAmount,
        float $routeFeeAmount,
        float $totalAmount,
        string $note
    ): string {
        $region = trim(implode(' / ', array_filter([$request->customer_city, $request->customer_district])));
        $lines = [
            'Merhaba '.$technician->name.',',
            'Hakediş bilgisi:',
            'MRN: '.$request->mrn,
            'Bölge: '.($region !== '' ? $region : '-'),
            'Ürün / Seri: '.trim(($request->product_name ?: '-').' / '.($request->serial_number ?: '-')),
            'Montaj işçilik: '.$this->moneyText($laborAmount),
            'Usta yol hakedişi: '.$this->moneyText($routeFeeAmount),
            'Toplam hakediş: '.$this->moneyText($totalAmount),
            'Randevu: '.($request->scheduled_at?->format('d.m.Y H:i') ?: ($request->scheduled_date?->format('d.m.Y') ?: '-')),
        ];

        if ($note !== '') {
            $lines[] = 'Not: '.$note;
        }

        return implode("\n", $lines);
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
        if ($this->fieldCompletionDocumentsComplete($request)) {
            return true;
        }

        return (int) ($request->before_photo_count ?? 0) >= 3
            && (int) ($request->after_photo_count ?? 0) >= 3
            && (int) ($request->general_photo_count ?? 0) >= 1;
    }

    private function fieldCompletionDocumentsComplete(TechnicalServiceRequest $request): bool
    {
        $request->loadMissing('uploads');

        $presentTypes = $request->uploads
            ->filter(fn (TechnicalServiceRequestUpload $upload): bool => $this->isFieldCompletionDocument($upload))
            ->map(fn (TechnicalServiceRequestUpload $upload): string => (string) $upload->field_code)
            ->filter(fn (string $field): bool => array_key_exists($field, self::FIELD_COMPLETION_DOCUMENT_TYPES))
            ->unique();

        return $presentTypes->count() === count(self::FIELD_COMPLETION_DOCUMENT_TYPES);
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
