<?php

namespace App\Services\TechnicalService;

use App\Models\TechnicalServiceAssignmentOffer;
use App\Models\TechnicalServiceAuditLog;
use App\Models\TechnicalServiceEarningItem;
use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceMountSession;
use App\Models\TechnicalServicePartnerJobAction;
use App\Models\TechnicalServicePartRequest;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestUpload;
use App\Models\TechnicalServiceRouteQuote;
use App\Models\TechnicalServiceSettlement;
use App\Models\TechnicalServiceTechnician;
use App\Services\B2B\B2BPartnerServiceJobScopeService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TechnicalServiceWorkflowService
{
    public const CANCELLATION_REVIEW_PENDING_REASON = 'İptal talebi incelemede';

    public const CANCELLATION_REVIEW_KEY = 'cancel_review';

    private const FIELD_COMPLETION_DOCUMENT_TYPES = [
        'before_photo' => 'Öncesi',
        'after_photo' => 'Sonrası',
        'warranty_document_photo' => 'Garanti Belgesi',
    ];

    private const OPS_EXTRA_DOCUMENT_TYPES = [
        'ops_extra_photo' => 'OPS Ek Görsel',
        'ops_door_front_photo' => 'OPS Kapı Ön Yüzü',
        'ops_door_side_photo' => 'OPS Kapı Yan Yüzü',
        'ops_door_back_photo' => 'OPS Kapı Arka Yüzü',
        'ops_door_photo' => 'OPS Ek Kapı Görseli',
        'ops_additional_document' => 'OPS Ek Belge',
    ];

    private const CUSTOMER_DOOR_PHOTO_FIELDS = [
        'door_front_photo',
        'door_side_photo',
        'door_back_photo',
    ];

    private const PAYMENT_LINK_DISPATCH_EVIDENCE_STATUSES = [
        TechnicalServiceMessageDispatch::STATUS_QUEUED,
        TechnicalServiceMessageDispatch::STATUS_SENDING,
        TechnicalServiceMessageDispatch::STATUS_SENT,
        TechnicalServiceMessageDispatch::STATUS_TEST_SENT,
        TechnicalServiceMessageDispatch::STATUS_FAILED,
        TechnicalServiceMessageDispatch::STATUS_PROVIDER_ERROR,
        TechnicalServiceMessageDispatch::STATUS_RATE_LIMITED,
    ];

    private const OPS_DOOR_PHOTO_FIELDS = [
        'ops_door_front_photo',
        'ops_door_side_photo',
        'ops_door_back_photo',
        'ops_door_photo',
    ];

    public function __construct(
        private readonly B2BPartnerServiceJobScopeService $partnerJobScope,
    ) {}

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
     * @var array<int, array<string, mixed>>
     */
    private array $paymentStatusCache = [];

    /**
     * @var array<int, array<string, mixed>|null>
     */
    private array $extraMountPaymentCache = [];

    /**
     * @var array<int, Collection<int, TechnicalServiceMountPayment>>
     */
    private array $mountPaymentsByRequestIdCache = [];

    /**
     * @var array<int, Collection<int, TechnicalServiceMountPayment>>
     */
    private array $mountPaymentsBySessionIdCache = [];

    /**
     * @var array<int, array{send_count:int,last_message_sent_at:string|null,latest_dispatch_id:int|null,latest_dispatch_status:string|null}>
     */
    private array $paymentLinkMessageStateCache = [];

    /**
     * @var array<string, Collection<int, TechnicalServiceMountPayment>>
     */
    private array $customerChargePaymentsCache = [];

    /**
     * @var array<int, array{status:string,status_label:string,paid_at:string|null,earning_id:int|null}>
     */
    private array $payoutPaymentStatusCache = [];

    /**
     * @var array<int, TechnicalServiceRequest|null>
     */
    private array $rootFinancialRequestCache = [];

    /**
     * @var array<int, Collection<int, TechnicalServiceRequest>>
     */
    private array $rootFinancialRequestsCache = [];

    /**
     * @return array<string, string>
     */
    public static function actionLabels(): array
    {
        return [
            'mark_missing_info' => 'Eksik Bilgi / Fotoğraf',
            'missing_info_reviewed' => 'Eksik fotoğraf kontrol edildi',
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
            'cancellation_requested' => 'İptal talebi incelemeye alındı',
            'cancellation_confirmed' => 'İş iptal edildi',
            'technical_service_request_reopened' => 'İş yeniden açıldı',
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
            'Eksik Bilgi / Fotoğraf Bekleyen' => ['Eksik Bilgi / Fotoğraf Bekleyen', 'Müşteri Aranacak', 'Müşteri Onayı Bekleyen', 'İptal'],
            'Müşteri Aranacak' => ['Müşteriye Ulaşılamadı', 'Müşteri Onayı Bekleyen', 'Müşteri Onayladı', 'Beklemede', 'İptal'],
            'Müşteriye Ulaşılamadı' => ['Müşteri Aranacak', 'Müşteri Onayı Bekleyen', 'Müşteri Onayladı', 'Beklemede', 'İptal'],
            'Müşteri Onayı Bekleyen' => ['Müşteriye Ulaşılamadı', 'Müşteri Onayladı', 'Usta Onayı Bekleyen', 'Beklemede', 'İptal'],
            'Müşteri Onayladı' => ['Randevu Planlandı', 'Beklemede', 'İptal'],
            'Randevu Planlandı' => ['Yeni Talep', 'Usta Ataması Bekleyen', 'Usta Onayı Bekleyen', 'Beklemede', 'Tamamlandı', 'İptal'],
            'Usta Ataması Bekleyen' => ['Usta Onayı Bekleyen', 'Usta Tarih Revize Talebi', 'Beklemede', 'İptal'],
            'Usta Onayı Bekleyen' => ['Planlı', 'Usta Tarih Revize Talebi', 'Beklemede', 'İptal'],
            'Usta Tarih Revize Talebi' => ['Müşteri Aranacak', 'Müşteri Onayı Bekleyen', 'Müşteri Onayladı', 'Randevu Planlandı', 'Usta Onayı Bekleyen', 'İptal'],
            'Planlı' => ['Usta Onayı Bekleyen', 'Yolda', 'Sahada', 'Beklemede', 'İptal'],
            'Yolda' => ['Sahada', 'Beklemede', 'İptal'],
            'Sahada' => ['Belge / Fotoğraf Bekleyen', 'Müşteri Kapanış Onayı Bekleyen', 'Tamamlandı', 'Parça Bekleniyor', 'Beklemede', 'Müşteri Yerinde Yok', 'Montaj Yeri Hazır Değil', 'İptal'],
            'Beklemede' => ['Müşteri Aranacak', 'Müşteri Onayı Bekleyen', 'Randevu Planlandı', 'Usta Ataması Bekleyen', 'Parça Bekleniyor', 'İptal'],
            'Müşteri Yerinde Yok' => ['Randevu Planlandı', 'Müşteri Aranacak', 'İptal'],
            'Montaj Yeri Hazır Değil' => ['Randevu Planlandı', 'Beklemede', 'İptal'],
            'Parça Bekleniyor' => ['Randevu Planlandı', 'Belge / Fotoğraf Bekleyen', 'Beklemede', 'İptal'],
            'Belge / Fotoğraf Bekleyen' => ['Müşteri Kapanış Onayı Bekleyen', 'Tamamlandı', 'Beklemede', 'İptal'],
            'Müşteri Kapanış Onayı Bekleyen' => ['Tamamlandı', 'Belge / Fotoğraf Bekleyen', 'İptal'],
            'Son Kontrol' => ['Tamamlandı', 'Belge / Fotoğraf Bekleyen', 'Müşteri Kapanış Onayı Bekleyen', 'İptal'],
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
                'mark_missing_info' => 'Eksik Bilgi / Fotoğraf Bekleyen',
                'missing_info_reviewed' => 'Müşteri Aranacak',
                'customer_called' => 'Müşteri Onayı Bekleyen',
                'customer_unreachable' => 'Müşteriye Ulaşılamadı',
                'cancel' => 'İptal',
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
            'Usta Tarih Revize Talebi' => [
                'schedule_planned' => 'Randevu Planlandı',
                'assign_technician' => 'Usta Onayı Bekleyen',
                'cancel' => 'İptal',
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
                'cancel' => 'İptal',
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
                'cancel' => 'İptal',
            ],
            'Belge / Fotoğraf Bekleyen' => [
                'closure_pending' => 'Müşteri Kapanış Onayı Bekleyen',
                'complete' => 'Tamamlandı',
                'photos_updated' => 'Belge / Fotoğraf Bekleyen',
                'field_completed' => 'Tamamlandı',
                'cancel' => 'İptal',
            ],
            'Müşteri Kapanış Onayı Bekleyen' => [
                'document_pending' => 'Belge / Fotoğraf Bekleyen',
                'complete' => 'Tamamlandı',
                'customer_closure_approved' => 'Müşteri Kapanış Onayı Bekleyen',
                'field_completed' => 'Tamamlandı',
                'cancel' => 'İptal',
            ],
        ];

        foreach ($map[$status] ?? [] as $action => $target) {
            $actions[$action] = [
                'label' => $this->actionLabelForRequest($request, $action),
                'target' => $target,
            ];
        }

        if ($this->isServiceVisitRequest($request) && ! in_array($status, self::TERMINAL_STATUSES, true)) {
            $actions['cancel'] = [
                'label' => $this->actionLabelForRequest($request, 'cancel'),
                'target' => 'İptal',
            ];
        }

        return $actions;
    }

    private function actionLabelForRequest(TechnicalServiceRequest $request, string $action): string
    {
        if ($action === 'cancel' && $this->isServiceVisitRequest($request)) {
            return "SRV'yi İptal Et";
        }

        return self::actionLabels()[$action] ?? $action;
    }

    public function currentWorkflowStatus(TechnicalServiceRequest $request): string
    {
        if ($this->requestLooksCancelled($request)) {
            return $this->cancelledWorkflowStatus();
        }

        if ($this->requestShouldStayCompleted($request)) {
            return $this->completedWorkflowStatus();
        }

        return $this->normalizeWorkflowStatus(
            $request->workflow_status,
            $request->status,
            filled($request->technical_service_technician_id) || filled($request->technician_name),
            $request->scheduled_at !== null
        );
    }

    private function requestLooksCancelled(TechnicalServiceRequest $request): bool
    {
        return $request->cancelled_at !== null
            || str_ends_with($this->normalizeToken($request->workflow_status), 'ptal')
            || str_ends_with($this->normalizeToken($request->status), 'ptal');
    }

    private function requestShouldStayCompleted(TechnicalServiceRequest $request): bool
    {
        $completedTokens = ['tamamlandi', 'tamamlanda', 'tamamland'];
        $statusIsCompleted = in_array($this->normalizeToken($request->workflow_status), $completedTokens, true)
            || in_array($this->normalizeToken($request->status), $completedTokens, true);

        if (! $statusIsCompleted) {
            return false;
        }

        if ($request->completed_at === null && $request->installation_completed_at === null) {
            return false;
        }

        if ($request->reopened_at === null) {
            return true;
        }

        $operationControl = is_array($request->operation_control_payload) ? $request->operation_control_payload : [];
        if (isset($operationControl['service_visit_delegation'])) {
            return true;
        }

        if ($request->relationLoaded('childRequests')) {
            return $request->childRequests
                ->contains(fn (TechnicalServiceRequest $child): bool => filled($child->service_code) && $child->cancelled_at === null);
        }

        return TechnicalServiceRequest::query()
            ->where('parent_request_id', $request->id)
            ->whereNotNull('service_code')
            ->whereNull('cancelled_at')
            ->exists();
    }

    public function normalizeWorkflowStatus(?string $workflowStatus, ?string $legacyStatus = null, bool $hasTechnician = false, bool $hasSchedule = false): string
    {
        $normalized = $this->normalizeToken($workflowStatus);

        if (in_array($normalized, ['tamamlandi', 'tamamlanda', 'tamamland'], true)) {
            return $this->completedWorkflowStatus();
        }

        if (str_ends_with($normalized, 'ptal')) {
            return $this->cancelledWorkflowStatus();
        }

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
        $normalized = $this->normalizeToken($status);

        if (in_array($normalized, ['tamamlandi', 'tamamlanda', 'tamamland'], true)) {
            return $this->completedWorkflowStatus();
        }

        if (str_ends_with($normalized, 'ptal')) {
            return $this->cancelledWorkflowStatus();
        }

        return match ($normalized) {
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
        $workflowStatus = $this->normalizeWorkflowStatus($workflowStatus, $workflowStatus);
        $workflowToken = $this->normalizeToken($workflowStatus);

        if (in_array($workflowToken, ['tamamlandi', 'tamamlanda', 'tamamland'], true)) {
            return $workflowStatus;
        }

        if (str_ends_with($workflowToken, 'ptal')) {
            return $workflowStatus;
        }

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
     * @param  array<string, mixed>  $attributes
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
     * @param  array<string, mixed>  $payload
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

        $isCancellationReviewReopen = $target === 'Yeni Talep' && $this->isCancellationReview($request);
        if ($current !== $target && ! $isCancellationReviewReopen) {
            $this->assertTransitionAllowed($current, $target);
        }

        $old = $this->snapshot($request);

        $request->workflow_status = $target;
        if ($target !== 'İptal') {
            $request->cancelled_at = null;
            $this->resolveCancellationReview($request, $payload, $actionType === 'technical_service_request_reopened' ? 'reopened' : 'resolved');
        }

        $this->applyPayloadForWorkflow($request, $target, $payload);
        $this->applyDerivedState($request, $payload);
        if ($target === 'Tamamlandı') {
            $this->storeCompletedEarningSnapshot($request);
        }
        $request->updated_by_user_id = $user?->id;
        $request->save();

        $this->writeAuditLog($request, $actionType, $old, $this->snapshot($request), $user, $payload['note'] ?? null);
        $this->writeEvent($request, $actionType, $current, $target, $user, $payload);

        return $request->refresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function startCancellationReview(TechnicalServiceRequest $request, array $payload = [], ?Authenticatable $user = null): TechnicalServiceRequest
    {
        $current = $this->currentWorkflowStatus($request);
        $old = $this->snapshot($request);
        $operationControl = is_array($request->operation_control_payload) ? $request->operation_control_payload : [];
        $reason = $payload['cancellation_reason'] ?? $payload['note'] ?? $request->cancellation_reason;

        $operationControl[self::CANCELLATION_REVIEW_KEY] = [
            'status' => 'pending',
            'requested_at' => now()->toISOString(),
            'requested_by_user_id' => $user?->id,
            'reason' => $reason,
            'source' => $payload['source'] ?? 'ops_status_update',
        ];

        $request->workflow_status = 'Beklemede';
        $request->cancelled_at = null;
        $request->cancellation_reason = $reason;
        $request->pending_reason = self::CANCELLATION_REVIEW_PENDING_REASON;
        $request->requires_reschedule = false;
        $request->operation_control_payload = $operationControl;
        $request->updated_by_user_id = $user?->id;
        $this->applyDerivedState($request, ['next_action' => 'İptal talebi incelenmeli']);
        $request->save();

        $eventPayload = [
            'note' => $payload['note'] ?? null,
            'cancellation_reason' => $reason,
            'cancel_review' => $operationControl[self::CANCELLATION_REVIEW_KEY],
        ];
        $this->writeAuditLog($request, 'cancellation_requested', $old, $this->snapshot($request), $user, $eventPayload['note'] ?? null);
        $this->writeEvent($request, 'cancellation_requested', $current, $this->currentWorkflowStatus($request), $user, $eventPayload, 'İptal talebi incelemeye alındı');

        return $request->refresh();
    }

    public function isCancellationReview(TechnicalServiceRequest $request): bool
    {
        if ($request->cancelled_at !== null) {
            return false;
        }

        $operationControl = is_array($request->operation_control_payload) ? $request->operation_control_payload : [];
        $review = $operationControl[self::CANCELLATION_REVIEW_KEY] ?? $operationControl['cancellation_review'] ?? null;
        $reviewStatus = is_array($review) ? (string) ($review['status'] ?? '') : '';

        return in_array($reviewStatus, ['pending', 'review'], true)
            || (string) $request->pending_reason === self::CANCELLATION_REVIEW_PENDING_REASON;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateSchedule(TechnicalServiceRequest $request, array $payload, ?Authenticatable $user = null): TechnicalServiceRequest
    {
        $old = $this->snapshot($request);
        $scheduledAt = $this->scheduledAtFromPayload($payload);

        $previousSchedule = [
            'scheduled_date' => $request->scheduled_date?->toDateString(),
            'scheduled_time' => $request->scheduled_time,
            'scheduled_at' => $this->dateTimeString($request->scheduled_at),
            'requires_reschedule' => (bool) $request->requires_reschedule,
            'reschedule_reason' => $request->reschedule_reason,
            'pending_reason' => $request->pending_reason,
        ];
        $nextRequiresReschedule = array_key_exists('requires_reschedule', $payload)
            ? (bool) $payload['requires_reschedule']
            : (bool) $request->requires_reschedule;
        $nextRescheduleReason = array_key_exists('reschedule_reason', $payload)
            ? $payload['reschedule_reason']
            : $request->reschedule_reason;
        $nextPendingReason = Arr::get($payload, 'pending_reason', $request->pending_reason);
        $scheduleChanged = $previousSchedule['scheduled_date'] !== $payload['scheduled_date']
            || $previousSchedule['scheduled_time'] !== $payload['scheduled_time'];
        $controlChanged = $previousSchedule['requires_reschedule'] !== $nextRequiresReschedule
            || $previousSchedule['reschedule_reason'] !== $nextRescheduleReason
            || $previousSchedule['pending_reason'] !== $nextPendingReason;

        if (! $scheduleChanged && ! $controlChanged) {
            return $request->refresh();
        }

        $request->scheduled_date = $payload['scheduled_date'];
        $request->scheduled_time = $payload['scheduled_time'];
        $request->scheduled_at = $scheduledAt;
        $request->requires_reschedule = $nextRequiresReschedule;
        $request->reschedule_reason = $nextRescheduleReason;
        $request->pending_reason = $nextPendingReason;
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
        $current = $this->currentWorkflowStatus($request);
        $technicianApprovalStatus = $this->normalizeToken($request->technician_approval_status);
        $alreadyApproved = $current === 'Planlı'
            || $request->technician_approved_at !== null
            || in_array($technicianApprovalStatus, ['onayladi', 'onaylandi', 'kabul edildi'], true);
        $approveTechnician = array_key_exists('approve_technician', $payload)
            ? (bool) $payload['approve_technician']
            : $alreadyApproved;
        $target = $hasTechnician
            ? ($approveTechnician ? 'Planlı' : 'Usta Onayı Bekleyen')
            : 'Randevu Planlandı';

        $preAppointmentStatuses = ['Yeni Talep', 'Müşteri Onayladı', 'Usta Ataması Bekleyen', 'Usta Onayı Bekleyen', 'Randevu Planlandı', 'Planlı'];
        if (! in_array($current, $preAppointmentStatuses, true) && ! in_array($current, self::TERMINAL_STATUSES, true)) {
            $target = $current;
        }

        if ($current !== $target && ! in_array($current, self::TERMINAL_STATUSES, true)) {
            $this->assertTransitionAllowed($current, $target);
            $request->workflow_status = $target;
        }

        if ($target === 'Planlı') {
            $request->technician_approval_status = 'onayladı';
            $request->technician_approved_at = $this->castDateTime(
                $payload['technician_approved_at'] ?? $request->technician_approved_at ?? now()
            );
        } elseif ($target === 'Usta Onayı Bekleyen' && in_array($current, $preAppointmentStatuses, true)) {
            $request->technician_approval_status = 'bekliyor';
            $request->technician_approved_at = null;
        }

        $this->applyDerivedState($request, $payload);
        $request->save();

        $eventPayload = [
            ...$payload,
            'previous_schedule' => $previousSchedule,
            'new_schedule' => [
                'scheduled_date' => $request->scheduled_date?->toDateString(),
                'scheduled_time' => $request->scheduled_time,
                'scheduled_at' => $this->dateTimeString($request->scheduled_at),
                'scheduled_time_end' => $payload['scheduled_time_end'] ?? null,
            ],
            'schedule_changed' => $scheduleChanged,
        ];

        $this->writeAuditLog($request, 'schedule_updated', $old, $this->snapshot($request), $user, $payload['note'] ?? null);
        $this->writeEvent($request, 'schedule_updated', $current, $this->currentWorkflowStatus($request), $user, $eventPayload, 'Randevu güncellendi');

        return $request->refresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateTechnician(TechnicalServiceRequest $request, array $payload, ?Authenticatable $user = null): TechnicalServiceRequest
    {
        $this->assertOperationControlsAllowAssignment($request, $payload);

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
     * @param  array<string, mixed>  $payload
     * @return array{request:TechnicalServiceRequest,assignment_offer:TechnicalServiceAssignmentOffer,earning_snapshot:array<string,mixed>,message_preview:string,message_text:string,copy_text:string,whatsapp_url:string}
     */
    public function recordTechnicianEarningsMessage(
        TechnicalServiceRequest $request,
        TechnicalServiceTechnician $technician,
        TechnicalServiceAssignmentOffer $offer,
        array $payload,
        ?Authenticatable $user = null
    ): array {
        if ((int) $offer->technical_service_request_id !== (int) $request->id
            || (int) $offer->technical_service_technician_id !== (int) $technician->id
        ) {
            throw ValidationException::withMessages([
                'assignment_offer' => 'Canonical hakediş kaydı aktif talep ve usta ile eşleşmiyor.',
            ]);
        }

        $old = $this->snapshot($request);
        $presentation = $this->technicianEarningPresentation($request, $technician, $offer);
        $earningSnapshot = $presentation['earning_snapshot'];
        $laborAmount = (float) $earningSnapshot['labor_amount'];
        $routeFeeAmount = (float) $earningSnapshot['route_fee_amount'];
        $submittedTotalAmount = $this->nullableFloat($payload['total_amount'] ?? null);
        $totalAmount = (float) $earningSnapshot['total_amount'];
        $totalAmountCorrected = $submittedTotalAmount !== null && abs($submittedTotalAmount - $totalAmount) > 0.01;
        $note = (string) ($earningSnapshot['operation_note'] ?? '');
        $messageText = $presentation['message_preview'];
        $messagePayload = $this->technicianEarningMessageDispatchPayload($request, $technician, [
            'labor_amount' => $laborAmount,
            'route_fee_amount' => $routeFeeAmount,
            'total_amount' => $totalAmount,
            'currency' => 'TRY',
            'note' => $note !== '' ? $note : null,
        ]);
        $messagePayload['earning_snapshot'] = $earningSnapshot;
        $messagePayload['earning_revision'] = $earningSnapshot['revision'];

        $operationControl = is_array($request->operation_control_payload) ? $request->operation_control_payload : [];
        $operationControl['technician_earning_message'] = [
            'status' => 'prepared',
            'prepared_at' => now()->toISOString(),
            'sent_at' => null,
            'dispatch_id' => null,
            'assignment_offer_id' => $offer->id,
            'earning_snapshot_revision' => $earningSnapshot['revision'],
            'earning_snapshot' => $earningSnapshot,
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
            'operation_control_payload' => $operationControl,
            'updated_by_user_id' => $user?->id,
        ])->save();

        $eventPayload = [
            'technician_id' => $technician->id,
            'technical_service_assignment_offer_id' => $offer->id,
            'earning_snapshot_revision' => $earningSnapshot['revision'],
            'labor_amount' => round($laborAmount, 2),
            'route_fee_amount' => round($routeFeeAmount, 2),
            'total_amount' => round($totalAmount, 2),
            'submitted_total_amount' => $submittedTotalAmount !== null ? round($submittedTotalAmount, 2) : null,
            'total_amount_corrected' => $totalAmountCorrected,
            'manual_override' => (bool) ($payload['manual_override'] ?? false),
            'note' => $note !== '' ? $note : null,
        ];

        $this->writeAuditLog($request, 'technician_earning_message_prepared', $old, $this->snapshot($request), $user, $note !== '' ? $note : null);
        $this->writeEvent(
            $request,
            'technician_earning_message_prepared',
            $this->currentWorkflowStatus($request),
            $this->currentWorkflowStatus($request),
            $user,
            $eventPayload,
            'Hakediş bilgisi gönderim için hazırlandı'
        );

        $whatsappPhone = $this->whatsappPhone($this->technicianPhone($technician));
        $whatsappUrl = $whatsappPhone !== ''
            ? 'https://wa.me/'.$whatsappPhone.'?text='.rawurlencode($messageText)
            : '';

        return [
            'request' => $request->refresh(),
            'assignment_offer' => $offer->refresh(),
            'earning_snapshot' => $earningSnapshot,
            'message_preview' => $messageText,
            'message_text' => $messageText,
            'copy_text' => $messageText,
            'whatsapp_url' => $whatsappUrl,
        ];
    }

    /**
     * @return array{earning_snapshot:array<string,mixed>,message_preview:string}
     */
    public function technicianEarningPresentation(
        TechnicalServiceRequest $request,
        TechnicalServiceTechnician $technician,
        TechnicalServiceAssignmentOffer $offer,
    ): array {
        if ((int) $offer->technical_service_request_id !== (int) $request->id
            || (int) $offer->technical_service_technician_id !== (int) $technician->id
        ) {
            throw ValidationException::withMessages([
                'assignment_offer' => 'Canonical hakediş kaydı aktif talep ve usta ile eşleşmiyor.',
            ]);
        }

        $earningSnapshot = $this->canonicalTechnicianEarningSnapshot($offer);
        $jobCardContext = $this->partnerJobScope->technicianJobCardContext($request);
        $jobCardUrl = is_string($jobCardContext['canonical_url'] ?? null)
            ? $jobCardContext['canonical_url']
            : null;

        return [
            'earning_snapshot' => $earningSnapshot,
            'message_preview' => $this->technicianEarningsMessageText(
                $request,
                $technician,
                (float) $earningSnapshot['labor_amount'],
                (float) $earningSnapshot['route_fee_amount'],
                (float) $earningSnapshot['total_amount'],
                (string) ($earningSnapshot['operation_note'] ?? ''),
                $jobCardUrl,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function canonicalTechnicianEarningSnapshot(TechnicalServiceAssignmentOffer $offer): array
    {
        $laborAmount = round((float) $offer->labor_amount, 2);
        $routeFeeAmount = round((float) $offer->route_fee_amount, 2);
        $totalAmount = round($laborAmount + $routeFeeAmount, 2);
        if (abs(((float) $offer->total_amount) - $totalAmount) > 0.01) {
            throw ValidationException::withMessages([
                'assignment_offer' => 'Canonical hakediş toplamı işçilik ve yol toplamıyla eşleşmiyor.',
            ]);
        }

        $operationNote = trim((string) ($offer->note ?? ''));
        $persistedAt = $offer->updated_at?->toISOString();
        $revisionPayload = [
            'schema_version' => 1,
            'assignment_id' => (int) $offer->id,
            'technician_id' => (int) $offer->technical_service_technician_id,
            'labor_amount' => number_format($laborAmount, 2, '.', ''),
            'route_fee_amount' => number_format($routeFeeAmount, 2, '.', ''),
            'total_amount' => number_format($totalAmount, 2, '.', ''),
            'currency' => (string) ($offer->currency ?: 'TRY'),
            'operation_note' => $operationNote,
            'persisted_at' => $persistedAt,
        ];

        return [
            ...$revisionPayload,
            'labor_amount' => $laborAmount,
            'route_fee_amount' => $routeFeeAmount,
            'total_amount' => $totalAmount,
            'operation_note' => $operationNote !== '' ? $operationNote : null,
            'revision' => hash('sha256', json_encode($revisionPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
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
     * @param  array<string, mixed>  $payload
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
                if (
                    $current === 'Belge / Fotoğraf Bekleyen'
                    && $this->photoStatusForCounts($request)
                    && in_array($request->document_status, ['tamamlandı', 'tamam', 'gerekli_degil'], true)
                ) {
                    $request->workflow_status = 'Müşteri Kapanış Onayı Bekleyen';
                    $request->customer_closure_approval_status = $request->customer_closure_approval_status ?? 'bekliyor';
                    $request->missing_info_reason = null;
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
            'Tamamlandı' => 'İş tamamlandı',
            'İptal' => 'Kapanmış iptal kaydı',
            default => 'Operasyon değerlendirmesi bekleniyor',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function preloadSerializationContext(Collection $requests): void
    {
        $requests = $requests
            ->filter(fn (mixed $request): bool => $request instanceof TechnicalServiceRequest)
            ->unique(fn (TechnicalServiceRequest $request): int => (int) $request->id)
            ->values();

        if ($requests->isEmpty()) {
            return;
        }

        $this->loadSerializationRelations($requests);

        $rootIds = $requests
            ->flatMap(fn (TechnicalServiceRequest $request): array => array_filter([
                $request->parent_request_id !== null ? (int) $request->parent_request_id : null,
                $request->parent_request_id === null ? (int) $request->id : null,
                $request->parentRequest instanceof TechnicalServiceRequest ? (int) $request->parentRequest->id : null,
            ]))
            ->unique()
            ->values();
        $rootMrns = $requests
            ->map(fn (TechnicalServiceRequest $request): string => (string) ($request->root_mrn ?: ($request->parent_request_id === null ? $request->mrn : '')))
            ->filter(fn (string $mrn): bool => $mrn !== '')
            ->unique()
            ->values();
        $roots = collect();

        if ($rootIds->isNotEmpty() || $rootMrns->isNotEmpty()) {
            $roots = TechnicalServiceRequest::query()
                ->where(function ($query) use ($rootIds, $rootMrns): void {
                    if ($rootIds->isNotEmpty()) {
                        $query->whereIn('id', $rootIds->all());
                    }

                    if ($rootMrns->isNotEmpty()) {
                        $rootIds->isNotEmpty()
                            ? $query->orWhereIn('mrn', $rootMrns->all())
                            : $query->whereIn('mrn', $rootMrns->all());
                    }
                })
                ->get();
        }

        $this->loadSerializationRelations($roots);

        $related = $requests->concat($roots);
        $roots->each(function (TechnicalServiceRequest $root) use (&$related): void {
            if ($root->relationLoaded('childRequests')) {
                $related = $related->concat($root->childRequests);
            }
        });
        $requests->each(function (TechnicalServiceRequest $request) use (&$related): void {
            if ($request->parentRequest instanceof TechnicalServiceRequest) {
                $related = $related->push($request->parentRequest);
            }
        });

        $related = $related
            ->filter(fn (mixed $request): bool => $request instanceof TechnicalServiceRequest)
            ->unique(fn (TechnicalServiceRequest $request): int => (int) $request->id)
            ->values();

        $this->loadSerializationRelations($related);
        $this->seedRootFinancialContext($related, $roots);
        $this->preloadPaymentLookups($related);
        $this->preloadPayoutStatuses($related);
    }

    /**
     * @param  Collection<int, TechnicalServiceRequest>  $requests
     */
    private function loadSerializationRelations(Collection $requests): void
    {
        $models = new EloquentCollection($requests
            ->filter(fn (mixed $request): bool => $request instanceof TechnicalServiceRequest)
            ->unique(fn (TechnicalServiceRequest $request): int => (int) $request->id)
            ->values()
            ->all());

        if ($models->isEmpty()) {
            return;
        }

        $models->loadMissing([
            'events' => fn ($query) => $query->orderBy('created_at'),
            'technicianRecord',
            'requestSerials',
            'uploads',
            'parentRequest.latestAssignmentOffer.technician',
            'parentRequest.technicianRecord',
            'sourcePartRequest',
            'latestRouteQuote',
            'latestAssignmentOffer.technician',
            'partnerJobActions' => fn ($query) => $query->latest(),
            'partRequests' => fn ($query) => $query->latest(),
            'childRequests' => fn ($query) => $query->orderBy('service_sequence')->orderBy('id'),
            'childRequests.events' => fn ($query) => $query->orderBy('created_at'),
            'childRequests.technicianRecord',
            'childRequests.requestSerials',
            'childRequests.uploads',
            'childRequests.latestRouteQuote',
            'childRequests.latestAssignmentOffer.technician',
            'childRequests.partnerJobActions' => fn ($query) => $query->latest(),
            'childRequests.partRequests' => fn ($query) => $query->latest(),
        ]);
    }

    /**
     * @param  Collection<int, TechnicalServiceRequest>  $requests
     * @param  Collection<int, TechnicalServiceRequest>  $roots
     */
    private function seedRootFinancialContext(Collection $requests, Collection $roots): void
    {
        $rootById = $roots->keyBy('id');
        $rootByMrn = $roots
            ->filter(fn (TechnicalServiceRequest $request): bool => filled($request->mrn))
            ->keyBy('mrn');

        $requests
            ->filter(fn (TechnicalServiceRequest $request): bool => $request->parent_request_id === null)
            ->each(function (TechnicalServiceRequest $request) use (&$rootById, &$rootByMrn): void {
                $rootById[(int) $request->id] = $request;
                if (filled($request->mrn)) {
                    $rootByMrn[(string) $request->mrn] = $request;
                }
            });

        $requests->each(function (TechnicalServiceRequest $request) use ($rootById, $rootByMrn): void {
            $root = $request->parent_request_id === null
                ? ($rootById[(int) $request->id] ?? $request)
                : ($rootById[(int) $request->parent_request_id]
                    ?? ($rootByMrn[(string) $request->root_mrn] ?? null)
                    ?? ($request->parentRequest instanceof TechnicalServiceRequest ? $request->parentRequest : null));

            if (! $root instanceof TechnicalServiceRequest) {
                return;
            }

            $group = collect([$root]);
            if ($root->relationLoaded('childRequests')) {
                $group = $group->concat($root->childRequests);
            }

            $group = $group
                ->filter(fn (mixed $related): bool => $related instanceof TechnicalServiceRequest)
                ->unique(fn (TechnicalServiceRequest $related): int => (int) $related->id)
                ->values();

            $group->each(function (TechnicalServiceRequest $related) use ($root, $group): void {
                $this->rootFinancialRequestCache[(int) $related->id] = $root;
                $this->rootFinancialRequestsCache[(int) $related->id] = $group;
            });
        });
    }

    /**
     * @param  Collection<int, TechnicalServiceRequest>  $requests
     */
    private function preloadPaymentLookups(Collection $requests): void
    {
        $requestIds = $requests
            ->pluck('id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $sessionIds = $requests
            ->pluck('mount_session_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $requestIds->each(fn (int $id) => $this->mountPaymentsByRequestIdCache[$id] = collect());
        $sessionIds->each(fn (int $id) => $this->mountPaymentsBySessionIdCache[$id] = collect());

        if ($requestIds->isEmpty() && $sessionIds->isEmpty()) {
            return;
        }

        $payments = TechnicalServiceMountPayment::query()
            ->with('technicalServiceRequest')
            ->where(function ($query) use ($requestIds, $sessionIds): void {
                if ($requestIds->isNotEmpty()) {
                    $query->whereIn('technical_service_request_id', $requestIds->all());
                }

                if ($sessionIds->isNotEmpty()) {
                    $requestIds->isNotEmpty()
                        ? $query->orWhereIn('technical_service_mount_session_id', $sessionIds->all())
                        : $query->whereIn('technical_service_mount_session_id', $sessionIds->all());
                }
            })
            ->latest('id')
            ->get();

        $payments->each(function (TechnicalServiceMountPayment $payment): void {
            if ($payment->technical_service_request_id !== null) {
                $requestId = (int) $payment->technical_service_request_id;
                $this->mountPaymentsByRequestIdCache[$requestId] = ($this->mountPaymentsByRequestIdCache[$requestId] ?? collect())->push($payment);
            }

            if ($payment->technical_service_mount_session_id !== null) {
                $sessionId = (int) $payment->technical_service_mount_session_id;
                $this->mountPaymentsBySessionIdCache[$sessionId] = ($this->mountPaymentsBySessionIdCache[$sessionId] ?? collect())->push($payment);
            }
        });

        $this->preloadPaymentLinkMessageStates($payments);
    }

    /**
     * @param  Collection<int, TechnicalServiceMountPayment>  $payments
     */
    private function preloadPaymentLinkMessageStates(Collection $payments): void
    {
        $payments = $payments
            ->filter(fn (mixed $payment): bool => $payment instanceof TechnicalServiceMountPayment)
            ->unique(fn (TechnicalServiceMountPayment $payment): int => (int) $payment->id)
            ->values();
        $paymentIds = $payments
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->values();

        $paymentIds->each(function (int $paymentId): void {
            $this->paymentLinkMessageStateCache[$paymentId] ??= $this->emptyPaymentLinkMessageState();
        });

        if ($paymentIds->isEmpty()) {
            return;
        }

        $requestIds = $payments
            ->pluck('technical_service_request_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($requestIds->isEmpty()) {
            return;
        }

        $paymentIdLookup = $paymentIds->flip();
        TechnicalServiceMessageDispatch::query()
            ->whereIn('technical_service_request_id', $requestIds->all())
            ->whereIn('message_type', ['payment_link_customer', 'part_fee_payment_link_customer'])
            ->whereIn('status', self::PAYMENT_LINK_DISPATCH_EVIDENCE_STATUSES)
            ->latest('id')
            ->get()
            ->each(function (TechnicalServiceMessageDispatch $dispatch) use ($paymentIdLookup): void {
                $metadata = is_array($dispatch->metadata) ? $dispatch->metadata : [];
                $paymentId = (int) ($metadata['payment_id'] ?? 0);
                if ($paymentId <= 0 || ! $paymentIdLookup->has($paymentId)) {
                    return;
                }

                $current = $this->paymentLinkMessageStateCache[$paymentId] ?? $this->emptyPaymentLinkMessageState();
                if ($current['latest_dispatch_id'] !== null) {
                    return;
                }

                $this->paymentLinkMessageStateCache[$paymentId] = [
                    'send_count' => max(1, (int) ($metadata['message_send_count'] ?? 0)),
                    'last_message_sent_at' => $this->dateTimeString($dispatch->sent_at ?? $dispatch->queued_at ?? $dispatch->created_at),
                    'latest_dispatch_id' => (int) $dispatch->id,
                    'latest_dispatch_status' => (string) $dispatch->status,
                ];
            });
    }

    /**
     * @return array{send_count:int,last_message_sent_at:string|null,latest_dispatch_id:int|null,latest_dispatch_status:string|null}
     */
    private function emptyPaymentLinkMessageState(): array
    {
        return [
            'send_count' => 0,
            'last_message_sent_at' => null,
            'latest_dispatch_id' => null,
            'latest_dispatch_status' => null,
        ];
    }

    /**
     * @return array{send_count:int,last_message_sent_at:string|null,latest_dispatch_id:int|null,latest_dispatch_status:string|null}
     */
    public function paymentLinkMessageState(TechnicalServiceMountPayment $payment): array
    {
        $paymentId = (int) $payment->id;
        if (! array_key_exists($paymentId, $this->paymentLinkMessageStateCache)) {
            $this->preloadPaymentLinkMessageStates(collect([$payment]));
        }

        return $this->paymentLinkMessageStateCache[$paymentId] ?? $this->emptyPaymentLinkMessageState();
    }

    /**
     * @param  Collection<int, TechnicalServiceRequest>  $requests
     */
    private function preloadPayoutStatuses(Collection $requests): void
    {
        $requestIds = $requests
            ->pluck('id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $requestIds->each(fn (int $id) => $this->payoutPaymentStatusCache[$id] = $this->emptyPayoutPaymentStatusPayload());

        if ($requestIds->isEmpty()) {
            return;
        }

        TechnicalServiceEarningItem::query()
            ->with('earning')
            ->whereIn('technical_service_request_id', $requestIds->all())
            ->latest('id')
            ->get()
            ->groupBy('technical_service_request_id')
            ->each(function (Collection $items, mixed $requestId): void {
                $item = $items->first();
                if ($item instanceof TechnicalServiceEarningItem) {
                    $this->payoutPaymentStatusCache[(int) $requestId] = $this->payoutPaymentStatusFromItem($item);
                }
            });
    }

    /**
     * @param  Collection<int, TechnicalServiceMountPayment>  $payments
     * @return Collection<int, TechnicalServiceMountPayment>
     */
    private function sortedUniquePayments(Collection $payments): Collection
    {
        return $payments
            ->filter(fn (mixed $payment): bool => $payment instanceof TechnicalServiceMountPayment)
            ->unique(fn (TechnicalServiceMountPayment $payment): int => (int) $payment->id)
            ->sortByDesc('id')
            ->values();
    }

    /**
     * @return Collection<int, TechnicalServiceMountPayment>|null
     */
    private function cachedPaymentsForRequest(TechnicalServiceRequest $request): ?Collection
    {
        $hasCache = array_key_exists((int) $request->id, $this->mountPaymentsByRequestIdCache)
            || ($request->mount_session_id !== null && array_key_exists((int) $request->mount_session_id, $this->mountPaymentsBySessionIdCache));

        if (! $hasCache) {
            return null;
        }

        $payments = $this->mountPaymentsByRequestIdCache[(int) $request->id] ?? collect();

        if ($request->mount_session_id !== null) {
            $payments = $payments->concat($this->mountPaymentsBySessionIdCache[(int) $request->mount_session_id] ?? collect());
        }

        return $this->sortedUniquePayments($payments);
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentStatusForRequest(TechnicalServiceRequest $request): array
    {
        $requestId = (int) $request->id;
        if (array_key_exists($requestId, $this->paymentStatusCache)) {
            return $this->paymentStatusCache[$requestId];
        }

        $payments = $this->cachedPaymentsForRequest($request);
        $status = app(TechnicalServicePaymentStatusResolver::class)->resolve($request, $payments);
        $this->paymentStatusCache[$requestId] = $status;

        return $status;
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentOwnershipForRequest(TechnicalServiceRequest $request, ?TechnicalServiceSettlement $settlement = null): array
    {
        $settlement ??= $request->relationLoaded('settlement')
            ? $request->settlement
            : $request->settlement()->first();

        return app(TechnicalServicePaymentOwnershipService::class)->summary(
            $request,
            $settlement instanceof TechnicalServiceSettlement ? $settlement : null,
            $this->cachedPaymentsForRequest($request),
        );
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
            'settlement',
            'partnerJobActions' => fn ($query) => $query->latest()->limit(12),
            'partRequests' => fn ($query) => $query->latest(),
        ]);

        $payload = $request->toArray();
        $displayCity = TechnicalServiceUiLabelService::cityLabel($request->customer_city);
        $displayDistrict = TechnicalServiceUiLabelService::districtLabel($request->customer_district, $displayCity);
        $payload['customer_name'] = TechnicalServiceUiLabelService::cleanDisplayText($request->customer_name);
        $payload['customer_city'] = $displayCity;
        $payload['customer_district'] = $displayDistrict;
        $payload['service_address'] = TechnicalServiceUiLabelService::addressLabel($request->service_address);
        $payload['location_formatted_address'] = TechnicalServiceUiLabelService::addressLabel($request->location_formatted_address);
        $payload['product_name'] = TechnicalServiceUiLabelService::cleanDisplayText($request->product_name);
        $payload['product_model'] = TechnicalServiceUiLabelService::cleanDisplayText($request->product_model);
        $payload['technician_name'] = TechnicalServiceUiLabelService::displayName($request->technician_name);
        $payload['status'] = TechnicalServiceUiLabelService::cleanDisplayText($request->status);
        $payload['workflow_status'] = TechnicalServiceUiLabelService::cleanDisplayText($request->workflow_status);
        $payload['next_action'] = TechnicalServiceUiLabelService::cleanDisplayText($request->next_action);
        $payload['technician_record'] = $request->technicianRecord
            ? $this->technicianRecordDisplayPayload($request->technicianRecord)
            : null;
        $payload['service_type'] = $this->displayServiceType($request);
        $payload['events'] = $this->eventPayload($request->events);
        $payload['technician_phone'] = $request->technicianRecord?->phone;
        $payload['technical_service_technician_phone'] = $request->technicianRecord?->phone;
        $payload['technical_service_technician'] = $request->technicianRecord
            ? [
                'id' => $request->technicianRecord->id,
                'name' => TechnicalServiceUiLabelService::displayName($request->technicianRecord->name),
                'phone' => $request->technicianRecord->phone,
            ]
            : null;
        $payload['technicalServiceTechnician'] = $payload['technical_service_technician'];
        $payload['field_completion_note'] = TechnicalServiceUiLabelService::cleanDisplayText($request->field_completion_note);
        $payload['completion_block_reason'] = TechnicalServiceUiLabelService::cleanDisplayText($request->completion_block_reason);
        $payload['sla_status'] = $request->sla_status ?? self::SLA_NORMAL;
        $payload['allowed_workflow_actions'] = $this->allowedActionsFor($request);
        $payload['allowed_workflow_transitions'] = self::transitionMap()[$this->currentWorkflowStatus($request)] ?? [];
        $payload['latest_event'] = TechnicalServiceUiLabelService::cleanDisplayText($request->events->last()?->title);
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
        $payload['previous_field_completion_documents'] = $this->fieldCompletionDocumentPayload($request, onlyPrevious: true);
        $payload['route_fee_config'] = app(TechnicalServiceRouteCostService::class)->feeConfig();
        $payload['route_quote'] = $this->routeQuotePayload($request);
        $payload['assignment_offer'] = $this->assignmentOfferPayload($request, $request->latestAssignmentOffer);
        $payload['technician_job_card'] = $this->partnerJobScope->technicianJobCardContext($request);
        $payload['settlement'] = $this->settlementPayload($request->settlement);
        $payload['technician_revision_offer'] = $this->technicianRevisionOfferPayload($request);
        $payload['earning_breakdown'] = $this->earningBreakdownPayload($request);
        $payload['finance_summary'] = $this->financeSummaryPayload($request, $payload['earning_breakdown']);
        $payload['partner_portal_actions'] = $this->partnerPortalActionPayload($request);
        $payload['part_requests'] = $request->partRequests
            ->map(fn ($partRequest): array => app(TechnicalServicePartRequestService::class)->serialize($partRequest))
            ->values()
            ->all();
        $payload['active_part_request'] = collect($payload['part_requests'])
            ->first(fn (array $partRequest): bool => in_array((string) ($partRequest['status'] ?? ''), TechnicalServicePartRequest::ACTIVE_STATUSES, true));
        $payload['root_mrn'] = $request->root_mrn;
        $payload['service_code'] = $request->service_code;
        $payload['service_visit_reason'] = $request->service_visit_reason;
        $payload['display_mrn'] = $request->service_code
            ? trim((string) ($request->root_mrn ?: $request->mrn)).' / '.$request->service_code
            : $request->mrn;
        $payload['service_visit_history'] = $includeHistory
            ? $this->serviceVisitHistoryPayload($request)
            : null;
        $payload['mrn_srv_history'] = $payload['service_visit_history'] !== null
            ? Arr::only($payload['service_visit_history'], ['root_request', 'current_request', 'direct_parent_request', 'items'])
            : null;
        $operationalState = app(TechnicalServiceOperationalStatePresenter::class)->present($request);
        $cancelContext = app(TechnicalServiceCancelContextService::class)->present($request, $operationalState);
        $payload['operational_state'] = $operationalState;
        $payload['cancel_context'] = $cancelContext;
        $payload['current_stage_summary'] = app(TechnicalServiceCancelContextService::class)->currentStageSummary($request, $operationalState);
        $payload['kanban_column'] = $operationalState['ops_column'];
        $payload['display_action_label'] = $operationalState['display_action_label'];
        $payload['display_tags'] = $operationalState['display_tags'];
        $payload['attention'] = $operationalState['attention'];
        $payload['action_owner'] = $operationalState['dashboard_action_owner'] ?? $operationalState['action_owner'];
        $payload['action_owner_label'] = $operationalState['action_owner_label'] ?? null;
        $payload['action_priority'] = $operationalState['action_priority_score'] ?? $operationalState['sort_priority'] ?? null;
        $payload['action_bucket'] = $operationalState['action_bucket'] ?? null;
        $payload['card_tone'] = $operationalState['card_tone'] ?? null;
        $payload['action_title'] = $operationalState['action_title'] ?? null;
        $payload['action_reason'] = $operationalState['action_reason'] ?? null;
        $payload['action_filter_keys'] = $operationalState['action_filter_keys'] ?? [];
        $payload['visible_sections'] = $this->visibleSectionsPayload($request);
        $payload['next_action_payload'] = app(TechnicalServiceNextActionService::class)->forRequest($request, $this->paymentStatusForRequest($request));
        $payload['admin_override_summary'] = app(TechnicalServiceAdminOverrideService::class)->summaryForRequest($request);
        $payload['field_correction_policy'] = app(TechnicalServiceAdminOverrideService::class)->correctionPolicyPayload();

        if ($includeHistory) {
            if ($this->auditLogTableAvailable()) {
                $request->loadMissing(['auditLogs' => fn ($query) => $query->latest()]);
                $payload['audit_logs'] = $this->auditLogPayload($request->auditLogs);
            } else {
                $payload['audit_logs'] = [];
                $payload['audit_logs_unavailable'] = true;
            }

            $payload['admin_overrides'] = app(TechnicalServiceAdminOverrideService::class)->serializeForRequest($request);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function operationControlUpdatePayload(TechnicalServiceRequest $request): array
    {
        $this->applyDerivedState($request);
        $request->loadMissing([
            'childRequests',
            'parentRequest',
            'partRequests' => fn ($query) => $query->latest(),
        ]);

        $operationalState = app(TechnicalServiceOperationalStatePresenter::class)->present($request);
        $cancelContext = app(TechnicalServiceCancelContextService::class)->present($request, $operationalState);

        return [
            'id' => $request->id,
            'operation_control' => $this->operationControlPayload($request),
            'assignment_blockers' => $this->assignmentBlockers($request),
            'allowed_workflow_actions' => $this->allowedActionsFor($request),
            'allowed_workflow_transitions' => self::transitionMap()[$this->currentWorkflowStatus($request)] ?? [],
            'operational_state' => $operationalState,
            'cancel_context' => $cancelContext,
            'current_stage_summary' => app(TechnicalServiceCancelContextService::class)->currentStageSummary($request, $operationalState),
            'kanban_column' => $operationalState['ops_column'],
            'display_action_label' => $operationalState['display_action_label'],
            'display_tags' => $operationalState['display_tags'],
            'attention' => $operationalState['attention'],
            'visible_sections' => $this->visibleSectionsPayload($request),
            'next_action' => TechnicalServiceUiLabelService::cleanDisplayText($request->next_action),
            'next_action_payload' => app(TechnicalServiceNextActionService::class)->forRequest($request, $this->paymentStatusForRequest($request)),
            'updated_at' => $this->dateTimeString($request->updated_at),
        ];
    }

    public function assertTransitionAllowed(string $from, string $to): void
    {
        $from = $this->normalizeWorkflowStatus($from, $from);
        $to = $this->normalizeWorkflowStatus($to, $to);

        if ($from === $to) {
            return;
        }

        $transitionMap = self::transitionMap();
        $allowedTargets = $transitionMap[$from] ?? null;
        if ($allowedTargets === null) {
            $fromToken = $this->normalizeToken($from);
            foreach ($transitionMap as $source => $targets) {
                if ($this->normalizeToken($this->normalizeWorkflowStatus($source, $source)) === $fromToken) {
                    $allowedTargets = $targets;
                    break;
                }
            }
        }

        $allowedTargets = $allowedTargets ?? [];
        $toToken = $this->normalizeToken($to);
        $isAllowed = collect($allowedTargets)
            ->contains(fn (string $target): bool => $this->normalizeToken($this->normalizeWorkflowStatus($target, $target)) === $toToken);

        if (! $isAllowed) {
            throw ValidationException::withMessages([
                'workflow_status' => "Geçersiz statü geçişi: {$from} -> {$to}",
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $assignmentPayload
     */
    public function assertOperationControlsAllowAssignment(TechnicalServiceRequest $request, array $assignmentPayload = []): void
    {
        if ($this->isServiceVisitRequest($request)) {
            return;
        }

        $operationControl = $this->operationControlPayload($request);
        $errors = [];

        if ($this->assignmentPaymentCheckRequired($request, $operationControl)
            && ! $this->payloadHasCustomerDirectTechnicianDecision($assignmentPayload)
        ) {
            $errors['payment_decision'] = 'Ödeme yöntemi netleşmeden atama güncellenemez. Ödeme linki oluşturun veya müşterinin ustaya ödeyeceği tutarı belirleyin.';
        }

        if (($operationControl['door_photos_checked'] ?? 'unreviewed') !== 'compatible') {
            $errors['operation_control.door_photos_checked'] = 'Usta atanamaz. Önce kapı görsellerini uygun olarak kontrol edin.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadHasCustomerDirectTechnicianDecision(array $payload): bool
    {
        $amount = Arr::get($payload, 'customer_direct_to_technician_amount');

        if (! is_numeric($amount)) {
            $amount = Arr::get($payload, 'assignment_offer.customer_direct_to_technician_amount');
        }

        return is_numeric($amount) && (float) $amount > 0;
    }

    /**
     * @param  array<string, mixed>  $payload
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
                case 'missing_info_reviewed':
                    $request->missing_info_reason = null;
                    $request->document_status = in_array($request->document_status, ['bekleniyor', 'eksik', null], true)
                        ? 'tamam'
                        : $request->document_status;
                    $request->photo_status = in_array($request->photo_status, ['bekleniyor', 'eksik', null], true)
                        ? 'tamam'
                        : $request->photo_status;
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
                $this->resolveCancellationReview($request, $payload, 'approved');
                $this->markAssignmentOffersCancelled($request, $payload);
                break;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveCancellationReview(TechnicalServiceRequest $request, array $payload, string $status): void
    {
        $operationControl = is_array($request->operation_control_payload) ? $request->operation_control_payload : [];
        $review = $operationControl[self::CANCELLATION_REVIEW_KEY] ?? null;
        if (! is_array($review)) {
            return;
        }

        if (! in_array((string) ($review['status'] ?? ''), ['pending', 'review'], true)) {
            return;
        }

        $operationControl[self::CANCELLATION_REVIEW_KEY] = array_merge($review, [
            'status' => $status,
            'resolved_at' => now()->toISOString(),
            'resolution_note' => $payload['note'] ?? $payload['reopen_note'] ?? null,
        ]);
        $request->operation_control_payload = $operationControl;

        if ((string) $request->pending_reason === self::CANCELLATION_REVIEW_PENDING_REASON) {
            $request->pending_reason = null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function markAssignmentOffersCancelled(TechnicalServiceRequest $request, array $payload): void
    {
        TechnicalServiceAssignmentOffer::query()
            ->where('technical_service_request_id', $request->id)
            ->where('status', '!=', TechnicalServiceAssignmentOffer::STATUS_CANCELLED)
            ->get()
            ->each(function (TechnicalServiceAssignmentOffer $offer) use ($request, $payload): void {
                $metadata = is_array($offer->metadata) ? $offer->metadata : [];
                $metadata['cancellation_exclusion'] = [
                    'status' => 'excluded_from_payable',
                    'excluded_at' => now()->toISOString(),
                    'reason' => $payload['cancellation_reason'] ?? $payload['note'] ?? $request->cancellation_reason,
                    'source' => 'request_cancellation',
                ];

                $offer->forceFill([
                    'status' => TechnicalServiceAssignmentOffer::STATUS_CANCELLED,
                    'metadata' => $metadata,
                ])->save();
            });
    }

    /**
     * @param  array<string, mixed>  $payload
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
     * @param  array<string, mixed>  $payload
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
        $actorRole = trim((string) data_get($user, 'role_code')) ?: ($user === null ? 'system_worker' : 'authenticated_user');
        $source = $payload['source'] ?? ($user === null
            ? 'system_worker'
            : (str_starts_with($actorRole, 'b2b_') ? 'partner_portal' : 'technical_service_admin'));

        $request->events()->create([
            'event_type' => $actionType,
            'title' => $title ?? (self::actionLabels()[$actionType] ?? 'Workflow güncellendi'),
            'note' => $payload['note'] ?? null,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'author_user_id' => $user?->id,
            'metadata' => [
                ...Arr::except($payload, ['note', 'source']),
                'actor_user_id' => $user?->getAuthIdentifier(),
                'actor_role' => $actorRole,
                'source' => $source,
                'occurred_at_istanbul' => now('Europe/Istanbul')->toIso8601String(),
                'request_id' => $request->id,
                'mrn' => $request->mrn,
                'srv' => $request->service_code,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
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
            'product_name' => TechnicalServiceUiLabelService::cleanDisplayText($request->product_name),
            'product_model' => TechnicalServiceUiLabelService::cleanDisplayText($request->product_model),
            'brand' => TechnicalServiceUiLabelService::cleanDisplayText($request->brand),
            'stock_code' => $request->stock_code,
            'activation_code' => $request->activation_code,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function saleAndPaymentPayload(TechnicalServiceRequest $request): array
    {
        $mountPayments = $this->mountCustomerPaymentSummaryPayload($request);
        $extraPayment = $this->latestExtraMountPaymentPayload($request);
        $customerCharges = $this->customerChargeSummaryPayload($request);
        $paymentStatus = $this->paymentStatusForRequest($request);
        $paymentOwnership = $this->paymentOwnershipForRequest($request);
        $paidAmount = $this->primaryMountPaidAmount($request, $paymentStatus, $extraPayment, $mountPayments);
        $paymentSummary = $this->paymentSummaryPayload($request, $paymentStatus, $extraPayment, $customerCharges, $paidAmount, $mountPayments);

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
            'payment_ownership' => $paymentOwnership,
            'extra_mount_payment' => $extraPayment,
            'mount_payments' => $mountPayments,
            'customer_charges' => $customerCharges,
            'payment_summary' => $paymentSummary,
            'technician_earning_message' => $this->technicianEarningMessagePayload($request),
        ];
    }

    /**
     * @param  array<string, mixed>  $paymentStatus
     * @param  array<string, mixed>|null  $extraPayment
     * @param  array<string, mixed>  $customerCharges
     * @return array<string, mixed>
     */
    private function paymentSummaryPayload(
        TechnicalServiceRequest $request,
        array $paymentStatus,
        ?array $extraPayment,
        array $customerCharges,
        ?float $paidMountAmount,
        array $mountPayments = []
    ): array {
        $paidExtraAmount = round((float) ($mountPayments['paid_extra_amount'] ?? 0), 2);
        if ($paidExtraAmount <= 0 && ($extraPayment['status'] ?? null) === TechnicalServiceMountPayment::STATUS_PAID) {
            $paidExtraAmount = round((float) ($extraPayment['amount'] ?? 0), 2);
        }
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
            'paid_customer_payment_total' => round((float) ($mountPayments['paid_total_amount'] ?? (($paidMountAmount ?? 0) + $paidExtraAmount)), 2),
            'paid_customer_payment_total_label' => $this->moneyLabel($mountPayments['paid_total_amount'] ?? (($paidMountAmount ?? 0) + $paidExtraAmount)),
            'pending_customer_payment_total' => round((float) ($mountPayments['pending_total_amount'] ?? 0), 2),
            'pending_customer_payment_total_label' => $this->moneyLabel($mountPayments['pending_total_amount'] ?? 0),
            'has_mount_collection' => $hasMountCollection,
            'has_service_charge' => $paidServiceAmount > 0,
            'has_part_charge' => $paidPartAmount > 0,
            'has_extra_charge' => $paidExtraAmount > 0,
        ];
    }

    public function mountPaymentReceived(TechnicalServiceRequest $request): bool
    {
        return (bool) $this->paymentStatusForRequest($request)['is_paid'];
    }

    public function requiresMountExclusionAcknowledgement(TechnicalServiceRequest $request): bool
    {
        if (! $this->preFormPaymentControlEnabled()) {
            return false;
        }

        $paymentStatus = $this->paymentStatusForRequest($request);
        if ((bool) ($paymentStatus['is_paid'] ?? false)
            || filled($paymentStatus['pending_payment_id'] ?? null)
            || $this->hasCustomerDirectTechnicianDecision($request)
        ) {
            return false;
        }

        return $request->sale_mount_status === TechnicalServiceMountSession::SALE_MONTAJ_HARIC
            && $this->hasMultiProductMountRequest($request)
            && ! (bool) ($paymentStatus['is_paid'] ?? false);
    }

    /**
     * @param  array<string, mixed>|null  $extraPayment
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

        $dispatch = is_numeric($payload['dispatch_id'] ?? null)
            ? TechnicalServiceMessageDispatch::query()->find((int) $payload['dispatch_id'])
            : null;
        if ($dispatch instanceof TechnicalServiceMessageDispatch) {
            $payload['status'] = $dispatch->status;
            $payload['queued_at'] = $this->dateTimeString($dispatch->queued_at);
            $payload['sent_at'] = $this->dateTimeString($dispatch->sent_at);
            $payload['last_error_code'] = $dispatch->last_error_code;
            $payload['last_error_message_redacted'] = $dispatch->last_error_message_redacted;
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
        $requestId = (int) $request->id;
        if (array_key_exists($requestId, $this->extraMountPaymentCache)) {
            return $this->extraMountPaymentCache[$requestId];
        }

        if ($request->mount_session_id === null) {
            $this->extraMountPaymentCache[$requestId] = null;

            return null;
        }

        $payments = $this->mountCustomerPaymentsForRequest($request)
            ->filter(fn (TechnicalServiceMountPayment $payment): bool => (int) ($payment->technical_service_mount_session_id ?? 0) === (int) $request->mount_session_id)
            ->values();

        $activePayments = $payments
            ->filter(fn (TechnicalServiceMountPayment $payment): bool => in_array($payment->status, [
                TechnicalServiceMountPayment::STATUS_PENDING,
                TechnicalServiceMountPayment::STATUS_PAID,
            ], true))
            ->values();

        $payment = $activePayments->first(function (TechnicalServiceMountPayment $payment) use ($request): bool {
            $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];

            return (int) ($payment->technical_service_request_id ?? 0) === (int) $request->id
                && ($payload['source'] ?? null) === 'operation_extra_mount_fee';
        }) ?? $activePayments->first(function (TechnicalServiceMountPayment $payment) use ($request): bool {
            $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];

            return ($payload['source'] ?? null) === 'operation_extra_mount_fee'
                && (int) ($payload['technical_service_request_id'] ?? 0) === (int) $request->id;
        });

        if (! $payment instanceof TechnicalServiceMountPayment) {
            $this->extraMountPaymentCache[$requestId] = null;

            return null;
        }

        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $providerDecision = is_array($payload['provider_decision'] ?? null) ? $payload['provider_decision'] : [];
        $providerGateway = is_array($payload['provider_gateway'] ?? null) ? $payload['provider_gateway'] : [];
        $paymentUrl = trim((string) ($payment->payment_url ?? ''));

        $messageState = $this->paymentLinkMessageState($payment);

        return $this->extraMountPaymentCache[$requestId] = array_merge([
            'id' => $payment->id,
            'request_id' => $payment->technical_service_request_id,
            'request_code' => $payload['request_code'] ?? $payload['mrn'] ?? $request->mrn,
            'root_mrn' => $payload['root_mrn'] ?? $request->root_mrn,
            'serial_no' => $payload['serial_number'] ?? $request->serial_number,
            'serial_number' => $payload['serial_number'] ?? $request->serial_number,
            'customer_name' => TechnicalServiceUiLabelService::cleanDisplayText($payload['customer_name'] ?? $request->customer_name),
            'customer_phone' => $payload['customer_phone'] ?? $request->customer_phone,
            'customer_email' => $payload['customer_email'] ?? null,
            'status' => $payment->status,
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'payment_url' => $paymentUrl !== '' ? $paymentUrl : null,
            'copy_url' => $paymentUrl !== '' ? $paymentUrl : null,
            'provider' => $payment->provider,
            'provider_mode' => $payload['provider_mode'] ?? $providerDecision['provider_mode'] ?? ($payment->provider === 'fake' ? 'local' : ($payload['provider_environment'] ?? null)),
            'provider_transport' => $payload['provider_transport'] ?? $providerDecision['provider_transport'] ?? ($payment->provider === 'fake' ? 'fake_local' : null),
            'provider_token' => $payment->provider_reference,
            'provider_reference' => $payment->provider_reference,
            'provider_status' => $providerGateway['provider_status'] ?? $providerGateway['raw_status'] ?? $payment->status,
            'paid_at' => $this->dateTimeString($payment->paid_at),
            'reason' => $payload['reason'] ?? null,
            'purpose' => $payload['purpose'] ?? $payload['reason'] ?? null,
            'note' => $payload['note'] ?? null,
            'message_send_count' => max((int) ($payload['message_send_count'] ?? 0), $messageState['send_count']),
            'last_message_sent_at' => data_get($payload, 'message_send_history.'.(max(0, count((array) ($payload['message_send_history'] ?? [])) - 1).'.requested_at'))
                ?? $messageState['last_message_sent_at'],
            'selected_serial_ids' => is_array($payload['selected_serial_ids'] ?? null)
                ? array_values($payload['selected_serial_ids'])
                : [],
        ], TechnicalServicePaymentActionPresenter::forPayment($payment));
    }

    /**
     * @return array<string, mixed>
     */
    private function mountCustomerPaymentSummaryPayload(TechnicalServiceRequest $request): array
    {
        $payments = $this->mountCustomerPaymentsForRequest($request);
        $this->preloadPaymentLinkMessageStates($payments);
        $rows = $payments
            ->map(fn (TechnicalServiceMountPayment $payment): array => $this->mountCustomerPaymentPayload($payment))
            ->values();
        $paidRows = $rows
            ->filter(fn (array $row): bool => ($row['status'] ?? null) === TechnicalServiceMountPayment::STATUS_PAID)
            ->values();
        $pendingRows = $rows
            ->filter(fn (array $row): bool => ($row['status'] ?? null) === TechnicalServiceMountPayment::STATUS_PENDING)
            ->values();
        $cancelledRows = $rows
            ->filter(fn (array $row): bool => ($row['status'] ?? null) === TechnicalServiceMountPayment::STATUS_CANCELLED)
            ->values();
        $paidMountRows = $paidRows
            ->reject(fn (array $row): bool => (bool) ($row['is_extra_payment'] ?? false))
            ->values();
        $paidExtraRows = $paidRows
            ->filter(fn (array $row): bool => (bool) ($row['is_extra_payment'] ?? false))
            ->values();
        $paidTotal = round((float) $paidRows->sum('amount'), 2);
        $pendingTotal = round((float) $pendingRows->sum('amount'), 2);
        $cancelledTotal = round((float) $cancelledRows->sum('amount'), 2);
        $paidMountTotal = round((float) $paidMountRows->sum('amount'), 2);
        $paidExtraTotal = round((float) $paidExtraRows->sum('amount'), 2);

        return [
            'rows' => $rows->all(),
            'paid_rows' => $paidRows->all(),
            'pending_rows' => $pendingRows->all(),
            'cancelled_rows' => $cancelledRows->all(),
            'latest' => $rows->first(),
            'latest_paid' => $paidRows->first(),
            'latest_pending' => $pendingRows->first(),
            'latest_cancelled' => $cancelledRows->first(),
            'paid_total_amount' => $paidTotal,
            'paid_total_amount_label' => $this->moneyLabel($paidTotal),
            'pending_total_amount' => $pendingTotal,
            'pending_total_amount_label' => $this->moneyLabel($pendingTotal),
            'cancelled_total_amount' => $cancelledTotal,
            'cancelled_total_amount_label' => $this->moneyLabel($cancelledTotal),
            'paid_mount_amount' => $paidMountTotal,
            'paid_mount_amount_label' => $this->moneyLabel($paidMountTotal),
            'paid_extra_amount' => $paidExtraTotal,
            'paid_extra_amount_label' => $this->moneyLabel($paidExtraTotal),
            'has_paid' => $paidTotal > 0,
            'has_pending' => $pendingTotal > 0,
            'has_cancelled' => $cancelledRows->isNotEmpty(),
        ];
    }

    /**
     * @return Collection<int, TechnicalServiceMountPayment>
     */
    private function mountCustomerPaymentsForRequest(TechnicalServiceRequest $request): Collection
    {
        $cachedPayments = $this->cachedPaymentsForRequest($request);
        $payments = $cachedPayments ?? TechnicalServiceMountPayment::query()
            ->with('technicalServiceRequest')
            ->where(function ($query) use ($request): void {
                $query->where('technical_service_request_id', $request->id);

                if ($request->mount_session_id !== null) {
                    $query->orWhere('technical_service_mount_session_id', $request->mount_session_id);
                }
            })
            ->latest('id')
            ->get();

        return $this->sortedUniquePayments($payments)
            ->filter(fn (TechnicalServiceMountPayment $payment): bool => $this->mountCustomerPaymentBelongsToRequest($payment, $request))
            ->reject(fn (TechnicalServiceMountPayment $payment): bool => $this->isCustomerChargePayment($payment))
            ->values();
    }

    private function mountCustomerPaymentBelongsToRequest(TechnicalServiceMountPayment $payment, TechnicalServiceRequest $request): bool
    {
        if ((int) ($payment->technical_service_request_id ?? 0) === (int) $request->id) {
            return true;
        }

        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        if ((int) ($payload['technical_service_request_id'] ?? 0) === (int) $request->id) {
            return true;
        }

        return $request->source_channel === TechnicalServiceRequest::SOURCE_QR_MOUNT_FORM
            && $request->mount_session_id !== null
            && (int) ($payment->technical_service_mount_session_id ?? 0) === (int) $request->mount_session_id
            && $payment->technical_service_request_id === null
            && in_array(($payload['source'] ?? null), ['public_mount_payment', 'public_form_payment'], true);
    }

    private function isCustomerChargePayment(TechnicalServiceMountPayment $payment): bool
    {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];

        return ($payload['source'] ?? null) === 'operation_customer_charge';
    }

    /**
     * @return array<string, mixed>
     */
    private function mountCustomerPaymentPayload(TechnicalServiceMountPayment $payment): array
    {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $messageState = $this->paymentLinkMessageState($payment);
        $amount = round((float) $payment->amount, 2);
        $source = (string) ($payload['source'] ?? '');
        $purpose = (string) ($payload['purpose'] ?? $payload['reason'] ?? '');
        $providerDecision = is_array($payload['provider_decision'] ?? null) ? $payload['provider_decision'] : [];
        $providerGateway = is_array($payload['provider_gateway'] ?? null) ? $payload['provider_gateway'] : [];
        $providerGatewaySync = is_array($payload['provider_gateway_sync'] ?? null) ? $payload['provider_gateway_sync'] : [];
        $paymentUrl = trim((string) ($payment->payment_url ?? ''));
        $isExtraPayment = $source === 'operation_extra_mount_fee'
            || in_array($purpose, ['mount_extra', 'manual_mount_payment', 'multi_product_mount', 'route_fee', 'montage_difference', 'multi_product', 'manual_extra'], true);

        return array_merge([
            'id' => $payment->id,
            'request_id' => $payment->technical_service_request_id,
            'mrn' => $payload['mrn'] ?? $payload['request_code'] ?? $payment->technicalServiceRequest?->mrn,
            'request_code' => $payload['request_code'] ?? $payload['mrn'] ?? $payment->technicalServiceRequest?->mrn,
            'root_mrn' => $payload['root_mrn'] ?? $payment->technicalServiceRequest?->root_mrn,
            'serial_no' => $payload['serial_number'] ?? $payment->technicalServiceRequest?->serial_number,
            'serial_number' => $payload['serial_number'] ?? $payment->technicalServiceRequest?->serial_number,
            'customer_name' => TechnicalServiceUiLabelService::cleanDisplayText($payload['customer_name'] ?? $payment->technicalServiceRequest?->customer_name),
            'customer_phone' => $payload['customer_phone'] ?? $payment->technicalServiceRequest?->customer_phone,
            'customer_email' => $payload['customer_email'] ?? null,
            'status' => $payment->status,
            'status_label' => $this->customerChargeStatusLabel($payment->status),
            'amount' => $amount,
            'amount_label' => $this->moneyLabel($amount),
            'currency' => $payment->currency,
            'payment_url' => $paymentUrl !== '' ? $paymentUrl : null,
            'copy_url' => $paymentUrl !== '' ? $paymentUrl : null,
            'provider' => $payment->provider,
            'provider_mode' => $payload['provider_mode'] ?? $providerDecision['provider_mode'] ?? ($payment->provider === 'fake' ? 'local' : ($payload['provider_environment'] ?? null)),
            'provider_transport' => $payload['provider_transport'] ?? $providerDecision['provider_transport'] ?? ($payment->provider === 'fake' ? 'fake_local' : null),
            'provider_token' => $payment->provider_reference,
            'provider_reference' => $payment->provider_reference,
            'provider_status' => $providerGatewaySync['provider_status']
                ?? $providerGateway['provider_status']
                ?? $providerGatewaySync['raw_status']
                ?? $providerGateway['raw_status']
                ?? $payment->status,
            'paid_at' => $this->dateTimeString($payment->paid_at),
            'cancelled_at' => $payload['cancelled_at'] ?? null,
            'cancelled_by_name' => TechnicalServiceUiLabelService::cleanDisplayText($payload['cancelled_by_name'] ?? null),
            'cancellation_reason' => TechnicalServiceUiLabelService::cleanDisplayText($payload['cancellation_reason'] ?? null),
            'message_send_count' => max((int) ($payload['message_send_count'] ?? 0), $messageState['send_count']),
            'last_message_sent_at' => data_get($payload, 'message_send_history.'.(max(0, count((array) ($payload['message_send_history'] ?? [])) - 1).'.requested_at'))
                ?? $messageState['last_message_sent_at'],
            'source' => $source !== '' ? $source : null,
            'amount_source' => $payload['amount_source'] ?? null,
            'purpose' => $payload['purpose'] ?? null,
            'reason' => $payload['reason'] ?? null,
            'note' => TechnicalServiceUiLabelService::cleanDisplayText($payload['note'] ?? null),
            'is_extra_payment' => $isExtraPayment,
            'readonly' => in_array($payment->status, [
                TechnicalServiceMountPayment::STATUS_PAID,
                TechnicalServiceMountPayment::STATUS_CANCELLED,
            ], true),
            'can_cancel' => $payment->status === TechnicalServiceMountPayment::STATUS_PENDING,
            'created_at' => $this->dateTimeString($payment->created_at),
            'updated_at' => $this->dateTimeString($payment->updated_at),
        ], TechnicalServicePaymentActionPresenter::forPayment($payment));
    }

    /**
     * @return array<string, mixed>
     */
    private function earningBreakdownPayload(TechnicalServiceRequest $request): array
    {
        $requests = $this->rootFinancialRequests($request)
            ->reject(fn (TechnicalServiceRequest $related): bool => $this->isCancelledRequest($related))
            ->values();
        $payoutApproval = $this->finalPayoutApprovalPayload($request, $requests);
        $rows = $requests
            ->map(fn (TechnicalServiceRequest $related): array => $this->earningBreakdownRow($request, $related, $payoutApproval))
            ->values();
        $current = $rows->firstWhere('id', $request->id);
        $includedRows = $rows
            ->filter(fn (array $row): bool => (bool) ($row['payout_included'] ?? true))
            ->values();
        if ($includedRows->isEmpty() && $rows->isNotEmpty() && ($payoutApproval['status'] ?? null) !== 'approved') {
            $includedRows = $rows;
        }
        $laborTotal = round((float) $includedRows->sum('labor_amount'), 2);
        $routeTotal = round((float) $includedRows->sum('route_fee_amount'), 2);
        $total = round((float) $includedRows->sum('total_amount'), 2);
        $technicianNames = $rows
            ->pluck('technician_name')
            ->filter(fn (mixed $name): bool => filled($name))
            ->map(fn (mixed $name): string => (string) $name)
            ->unique()
            ->values();

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
                'technician_count' => $technicianNames->count(),
                'technician_names' => $technicianNames->all(),
                'is_multi_technician' => $technicianNames->count() > 1,
                'payout_approval_required' => (bool) ($payoutApproval['required'] ?? false),
                'payout_approval_status' => $payoutApproval['status'] ?? 'not_required',
                'payout_approval_status_label' => $payoutApproval['status_label'] ?? 'Tekil iş',
                'included_job_count' => $includedRows->count(),
                'approved_job_count' => count($payoutApproval['approved_request_ids'] ?? []),
                'excluded_job_count' => count($payoutApproval['excluded_request_ids'] ?? []),
            ],
        ];
    }

    /**
     * @param  Collection<int, TechnicalServiceRequest>  $requests
     * @return array<string, mixed>
     */
    private function finalPayoutApprovalPayload(TechnicalServiceRequest $request, Collection $requests): array
    {
        $requestIds = $requests
            ->map(fn (TechnicalServiceRequest $related): int => (int) $related->id)
            ->values();
        $serviceVisitCount = $requests
            ->filter(fn (TechnicalServiceRequest $related): bool => $related->parent_request_id !== null || filled($related->service_code))
            ->count();
        $required = $serviceVisitCount > 0;

        if (! $required) {
            return [
                'required' => false,
                'status' => 'not_required',
                'status_label' => 'Tekil iş',
                'approved_request_ids' => [],
                'excluded_request_ids' => [],
            ];
        }

        $root = $this->rootFinancialRequest($request) ?? $request;
        $payloads = [
            is_array($root->operation_control_payload) ? $root->operation_control_payload : [],
            is_array($request->operation_control_payload) ? $request->operation_control_payload : [],
        ];
        $approval = null;
        foreach ($payloads as $payload) {
            if (is_array($payload['ops_final_payout_approval'] ?? null)) {
                $approval = $payload['ops_final_payout_approval'];
                break;
            }
        }

        if (! is_array($approval)) {
            return [
                'required' => true,
                'status' => 'pending',
                'status_label' => 'İş bazlı onay bekliyor',
                'approved_request_ids' => [],
                'excluded_request_ids' => [],
            ];
        }

        $approved = collect($approval['approved_request_ids'] ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0 && $requestIds->contains($id))
            ->unique()
            ->values();
        $excluded = $requestIds
            ->reject(fn (int $id): bool => $approved->contains($id))
            ->values();

        return [
            'required' => true,
            'status' => 'approved',
            'status_label' => 'İş bazlı onaylandı',
            'approved_request_ids' => $approved->all(),
            'excluded_request_ids' => $excluded->all(),
            'approved_at' => $approval['approved_at'] ?? null,
            'approved_by_user_id' => $approval['approved_by_user_id'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function earningBreakdownApprovalFields(TechnicalServiceRequest $request, array $payoutApproval): array
    {
        if (! (bool) ($payoutApproval['required'] ?? false)) {
            return [
                'payout_included' => true,
                'payout_approval_status' => 'not_required',
                'payout_approval_status_label' => 'Tekil iş',
            ];
        }

        if (($payoutApproval['status'] ?? null) !== 'approved') {
            return [
                'payout_included' => true,
                'payout_approval_status' => 'pending',
                'payout_approval_status_label' => 'Onay bekliyor',
            ];
        }

        $approvedIds = collect($payoutApproval['approved_request_ids'] ?? [])->map(fn (mixed $id): int => (int) $id);
        $included = $approvedIds->contains((int) $request->id);

        return [
            'payout_included' => $included,
            'payout_approval_status' => $included ? 'approved' : 'excluded',
            'payout_approval_status_label' => $included ? 'Onaylandı' : 'Hakedişten çıkarıldı',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function earningBreakdownRow(TechnicalServiceRequest $currentRequest, TechnicalServiceRequest $request, array $payoutApproval = []): array
    {
        $request->loadMissing(['latestAssignmentOffer.technician', 'technicianRecord']);
        $completedSnapshot = $this->completedEarningSnapshot($request);
        $approvalFields = $this->earningBreakdownApprovalFields($request, $payoutApproval);
        if ($completedSnapshot !== null) {
            $laborAmount = round((float) ($completedSnapshot['labor_amount'] ?? 0), 2);
            $routeFeeAmount = round((float) ($completedSnapshot['route_fee_amount'] ?? 0), 2);
            $totalAmount = round((float) ($completedSnapshot['total_amount'] ?? ($laborAmount + $routeFeeAmount)), 2);
            $payoutStatus = (string) ($completedSnapshot['payout_status'] ?? $this->locksmithPayoutStatus(null, $totalAmount));
            $kindLabel = $request->parent_request_id !== null || filled($request->service_code) ? 'Servis' : 'Montaj';
            $paymentStatus = $this->payoutPaymentStatusPayload($request);
            $technician = $this->earningRowTechnicianPayload($request, $completedSnapshot, $request->latestAssignmentOffer);

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
                'technician_id' => $technician['technician_id'],
                'technician_name' => $technician['technician_name'],
                'technician_source' => $technician['source'],
                'labor_amount' => $laborAmount,
                'route_fee_amount' => $routeFeeAmount,
                'total_amount' => $totalAmount,
                'labor_amount_label' => $this->moneyLabel($laborAmount),
                'route_fee_amount_label' => $this->moneyLabel($routeFeeAmount),
                'total_amount_label' => $this->moneyLabel($totalAmount),
                'status' => $completedSnapshot['status'] ?? null,
                'status_label' => (string) ($completedSnapshot['status_label'] ?? $this->assignmentOfferStatusLabel($completedSnapshot['status'] ?? null)),
                'payout_status' => $payoutStatus,
                'payout_status_label' => (string) ($completedSnapshot['payout_status_label'] ?? $this->locksmithPayoutStatusLabel($payoutStatus)),
                'payment_status' => $paymentStatus['status'],
                'payment_status_label' => $paymentStatus['status_label'],
                'paid_at' => $paymentStatus['paid_at'],
                'is_confirmed' => $payoutStatus === 'confirmed',
                'is_draft' => $payoutStatus === 'draft',
                ...$approvalFields,
                'source' => 'completed_earning_snapshot',
                'completed_at' => $this->dateTimeString($request->completed_at),
            ];
        }
        $offer = $request->latestAssignmentOffer;
        $laborAmount = $offer instanceof TechnicalServiceAssignmentOffer
            ? (float) ($offer->labor_amount ?? 0)
            : (float) ($this->nullableFloat($request->technician_payment_amount) ?? 0);
        $routeFeeAmount = $offer instanceof TechnicalServiceAssignmentOffer
            ? (float) ($offer->route_fee_amount ?? 0)
            : (float) ($this->nullableFloat($request->travel_fee_amount) ?? 0);
        $totalAmount = round($laborAmount + $routeFeeAmount, 2);
        $offerStatus = $offer instanceof TechnicalServiceAssignmentOffer ? $offer->status : null;
        $payoutStatus = $this->locksmithPayoutStatus($offerStatus, $totalAmount);
        $kindLabel = $request->parent_request_id !== null || filled($request->service_code) ? 'Servis' : 'Montaj';
        $paymentStatus = $this->payoutPaymentStatusPayload($request);
        $technician = $this->earningRowTechnicianPayload($request, null, $offer);

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
            'technician_id' => $technician['technician_id'],
            'technician_name' => $technician['technician_name'],
            'technician_source' => $technician['source'],
            'labor_amount' => round($laborAmount, 2),
            'route_fee_amount' => round($routeFeeAmount, 2),
            'total_amount' => $totalAmount,
            'labor_amount_label' => $this->moneyLabel($laborAmount),
            'route_fee_amount_label' => $this->moneyLabel($routeFeeAmount),
            'total_amount_label' => $this->moneyLabel($totalAmount),
            'status' => $offerStatus,
            'status_label' => $this->assignmentOfferStatusLabel($offerStatus),
            'payout_status' => $payoutStatus,
            'payout_status_label' => $this->locksmithPayoutStatusLabel($payoutStatus),
            'payment_status' => $paymentStatus['status'],
            'payment_status_label' => $paymentStatus['status_label'],
            'paid_at' => $paymentStatus['paid_at'],
            'is_confirmed' => $payoutStatus === 'confirmed',
            'is_draft' => $payoutStatus === 'draft',
            ...$approvalFields,
            'source' => $offer instanceof TechnicalServiceAssignmentOffer
                ? 'assignment_offer'
                : ($totalAmount > 0 ? 'request_default' : 'none'),
            'completed_at' => $this->dateTimeString($request->completed_at),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $completedSnapshot
     * @return array{technician_id:mixed,technician_name:string|null,source:string}
     */
    private function earningRowTechnicianPayload(
        TechnicalServiceRequest $request,
        ?array $completedSnapshot = null,
        ?TechnicalServiceAssignmentOffer $offer = null,
    ): array {
        $source = 'request_assignment';
        $technicianId = $request->technical_service_technician_id;
        $technicianName = $request->technician_name ?? $request->technicianRecord?->name;

        if ($offer instanceof TechnicalServiceAssignmentOffer) {
            $offer->loadMissing('technician');
            $technicianId ??= $offer->technical_service_technician_id;
            $technicianName = $this->firstFilled($technicianName, $offer->technician?->name);
            $source = 'assignment_offer';
        }

        if ($completedSnapshot !== null) {
            $technicianId = $completedSnapshot['technical_service_technician_id']
                ?? $completedSnapshot['technician_id']
                ?? $technicianId;
            $technicianName = $this->firstFilled($completedSnapshot['technician_name'] ?? null, $technicianName);
            $source = 'completed_earning_snapshot';
        }

        return [
            'technician_id' => $technicianId,
            'technician_name' => TechnicalServiceUiLabelService::displayName($technicianName),
            'source' => $source,
        ];
    }

    private function firstFilled(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (filled($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $earningBreakdown
     * @return array<string, mixed>
     */
    private function financeSummaryPayload(TechnicalServiceRequest $request, array $earningBreakdown): array
    {
        $currentCollection = $this->financeCustomerCollectionForRequest($request);
        $rootCollection = $this->financeRootCustomerCollection($request);
        $currentPayout = $this->financePayoutFromRow($earningBreakdown['current_visit'] ?? null, $request);
        $rootPayout = $this->financePayoutFromRootTotal($earningBreakdown['root_total'] ?? null);
        $currentNetMargin = round($currentCollection['total_amount'] - $currentPayout['total_amount'], 2);
        $rootNetMargin = round($rootCollection['total_amount'] - $rootPayout['total_amount'], 2);
        $isServiceVisit = $this->isServiceVisitRequest($request);
        $warrantyCovered = $isServiceVisit
            && $currentCollection['service_amount'] <= 0
            && $currentCollection['part_amount'] <= 0;
        $completedSnapshot = $this->completedEarningSnapshot($request);
        $currentOperationCost = $this->financeOperationCostPayload($currentPayout, $warrantyCovered);
        $confirmedPayout = ($currentPayout['payout_status'] ?? null) === 'confirmed' ? $currentPayout : null;
        $draftPayout = ($currentPayout['payout_status'] ?? null) === 'draft' ? $currentPayout : null;

        return [
            'currency' => 'TRY',
            'current_visit_customer_collection' => $currentCollection,
            'current_visit_locksmith_payout' => $currentPayout,
            'current_visit_operation_cost' => $currentOperationCost,
            'root_total_customer_collection' => $rootCollection,
            'root_total_locksmith_payout' => $rootPayout,
            'current_visit' => [
                'is_service_visit' => $isServiceVisit,
                'technician_id' => $currentPayout['technician_id'] ?? $completedSnapshot['technical_service_technician_id'] ?? $request->technical_service_technician_id,
                'technician_name' => TechnicalServiceUiLabelService::displayName((string) ($currentPayout['technician_name'] ?? $completedSnapshot['technician_name'] ?? $request->technician_name)),
                'customer_collection' => $currentCollection,
                'locksmith_payout' => $currentPayout,
                'operation_cost' => $currentOperationCost,
                'warranty_customer_charge' => $this->financeWarrantyCustomerChargePayload($currentCollection, $warrantyCovered),
                'confirmed_locksmith_payout' => $confirmedPayout,
                'draft_locksmith_payout' => $draftPayout,
                'payout_status' => $currentPayout['payout_status'] ?? 'none',
                'payout_status_label' => $currentPayout['payout_status_label'] ?? $this->locksmithPayoutStatusLabel('none'),
                'payment_status' => $currentPayout['payment_status'] ?? 'not_recorded',
                'payment_status_label' => $currentPayout['payment_status_label'] ?? $this->payoutPaymentStatusLabel('not_recorded'),
                'paid_at' => $currentPayout['paid_at'] ?? null,
                'completed_earning_snapshot' => $completedSnapshot,
                'net_margin' => $this->financeNetMarginPayload($currentNetMargin),
                'warranty_covered' => $warrantyCovered,
                'warranty_note' => $warrantyCovered
                    ? 'Garanti kapsamında - müşteriden servis/parça tahsilatı yok'
                    : null,
                'operation_cost_note' => $warrantyCovered && $currentPayout['total_amount'] > 0
                    ? 'Usta hakedişi operasyon maliyeti olarak hesaplandı'
                    : null,
            ],
            'root_total' => [
                'customer_collection' => $rootCollection,
                'locksmith_payout' => $rootPayout,
                'net_margin' => $this->financeNetMarginPayload($rootNetMargin),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function financeCustomerCollectionForRequest(TechnicalServiceRequest $request): array
    {
        $isServiceVisit = $this->isServiceVisitRequest($request);
        $paymentStatus = $this->paymentStatusForRequest($request);
        $extraPayment = $this->latestExtraMountPaymentPayload($request);
        $mountPayments = $this->mountCustomerPaymentSummaryPayload($request);
        $customerCharges = $this->customerChargeSummaryForRequests(collect([$request]));
        $mountAmount = $isServiceVisit
            ? 0.0
            : round((float) ($this->primaryMountPaidAmount($request, $paymentStatus, $extraPayment, $mountPayments) ?? 0), 2);
        $extraAmount = $isServiceVisit
            ? 0.0
            : round((float) ($mountPayments['paid_extra_amount'] ?? 0), 2);
        $serviceAmount = round((float) ($customerCharges['paid_service_amount'] ?? 0), 2);
        $partAmount = round((float) ($customerCharges['paid_part_amount'] ?? 0), 2);
        $totalAmount = round($mountAmount + $extraAmount + $serviceAmount + $partAmount, 2);

        return [
            'mount_amount' => $mountAmount,
            'service_amount' => $serviceAmount,
            'part_amount' => $partAmount,
            'extra_amount' => $extraAmount,
            'total_amount' => $totalAmount,
            'mount_amount_label' => $this->moneyLabel($mountAmount),
            'service_amount_label' => $this->moneyLabel($serviceAmount),
            'part_amount_label' => $this->moneyLabel($partAmount),
            'extra_amount_label' => $this->moneyLabel($extraAmount),
            'total_amount_label' => $this->moneyLabel($totalAmount),
            'has_collection' => $totalAmount > 0,
            'has_mount_collection' => $mountAmount > 0,
            'has_service_charge' => $serviceAmount > 0,
            'has_part_charge' => $partAmount > 0,
            'has_extra_charge' => $extraAmount > 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function financeRootCustomerCollection(TechnicalServiceRequest $request): array
    {
        $totals = $this->rootFinancialRequests($request)
            ->reject(fn (TechnicalServiceRequest $related): bool => $this->isCancelledRequest($related))
            ->map(fn (TechnicalServiceRequest $related): array => $this->financeCustomerCollectionForRequest($related))
            ->reduce(function (array $carry, array $row): array {
                $carry['mount_amount'] += (float) ($row['mount_amount'] ?? 0);
                $carry['service_amount'] += (float) ($row['service_amount'] ?? 0);
                $carry['part_amount'] += (float) ($row['part_amount'] ?? 0);
                $carry['extra_amount'] += (float) ($row['extra_amount'] ?? 0);

                return $carry;
            }, [
                'mount_amount' => 0.0,
                'service_amount' => 0.0,
                'part_amount' => 0.0,
                'extra_amount' => 0.0,
            ]);
        $mountAmount = round((float) $totals['mount_amount'], 2);
        $serviceAmount = round((float) $totals['service_amount'], 2);
        $partAmount = round((float) $totals['part_amount'], 2);
        $extraAmount = round((float) $totals['extra_amount'], 2);
        $totalAmount = round($mountAmount + $serviceAmount + $partAmount + $extraAmount, 2);

        return [
            'mount_amount' => $mountAmount,
            'service_amount' => $serviceAmount,
            'part_amount' => $partAmount,
            'extra_amount' => $extraAmount,
            'total_amount' => $totalAmount,
            'mount_amount_label' => $this->moneyLabel($mountAmount),
            'service_amount_label' => $this->moneyLabel($serviceAmount),
            'part_amount_label' => $this->moneyLabel($partAmount),
            'extra_amount_label' => $this->moneyLabel($extraAmount),
            'total_amount_label' => $this->moneyLabel($totalAmount),
            'has_collection' => $totalAmount > 0,
            'has_mount_collection' => $mountAmount > 0,
            'has_service_charge' => $serviceAmount > 0,
            'has_part_charge' => $partAmount > 0,
            'has_extra_charge' => $extraAmount > 0,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $row
     * @return array<string, mixed>
     */
    private function financePayoutFromRow(?array $row, TechnicalServiceRequest $request): array
    {
        $laborAmount = round((float) ($row['labor_amount'] ?? $this->nullableFloat($request->technician_payment_amount) ?? 0), 2);
        $routeFeeAmount = round((float) ($row['route_fee_amount'] ?? $this->nullableFloat($request->travel_fee_amount) ?? 0), 2);
        $totalAmount = round((float) ($row['total_amount'] ?? ($laborAmount + $routeFeeAmount)), 2);
        $paymentStatus = $this->payoutPaymentStatusPayload($request);

        return [
            'labor_amount' => $laborAmount,
            'route_fee_amount' => $routeFeeAmount,
            'total_amount' => $totalAmount,
            'labor_amount_label' => $this->moneyLabel($laborAmount),
            'route_fee_amount_label' => $this->moneyLabel($routeFeeAmount),
            'total_amount_label' => $this->moneyLabel($totalAmount),
            'technician_id' => $row['technician_id'] ?? $request->technical_service_technician_id,
            'technician_name' => $row['technician_name'] ?? TechnicalServiceUiLabelService::displayName($request->technician_name),
            'status' => $row['status'] ?? null,
            'status_label' => $row['status_label'] ?? $this->assignmentOfferStatusLabel($row['status'] ?? null),
            'payout_status' => $row['payout_status'] ?? $this->locksmithPayoutStatus($row['status'] ?? null, $totalAmount),
            'payout_status_label' => $row['payout_status_label'] ?? $this->locksmithPayoutStatusLabel(
                $row['payout_status'] ?? $this->locksmithPayoutStatus($row['status'] ?? null, $totalAmount)
            ),
            'payment_status' => $row['payment_status'] ?? $paymentStatus['status'],
            'payment_status_label' => $row['payment_status_label'] ?? $paymentStatus['status_label'],
            'paid_at' => $row['paid_at'] ?? $paymentStatus['paid_at'],
            'is_confirmed' => (bool) ($row['is_confirmed'] ?? (($row['payout_status'] ?? null) === 'confirmed')),
            'is_draft' => (bool) ($row['is_draft'] ?? (($row['payout_status'] ?? null) === 'draft')),
            'source' => $row['source'] ?? ($totalAmount > 0 ? 'request_default' : 'none'),
        ];
    }

    /**
     * @param  array<string, mixed>  $payout
     * @return array<string, mixed>
     */
    private function financeOperationCostPayload(array $payout, bool $warrantyCovered): array
    {
        $laborAmount = $warrantyCovered ? round((float) ($payout['labor_amount'] ?? 0), 2) : 0.0;
        $routeFeeAmount = $warrantyCovered ? round((float) ($payout['route_fee_amount'] ?? 0), 2) : 0.0;
        $totalAmount = round($laborAmount + $routeFeeAmount, 2);

        return [
            'labor_amount' => $laborAmount,
            'route_fee_amount' => $routeFeeAmount,
            'total_amount' => $totalAmount,
            'labor_amount_label' => $this->moneyLabel($laborAmount),
            'route_fee_amount_label' => $this->moneyLabel($routeFeeAmount),
            'total_amount_label' => $this->moneyLabel($totalAmount),
            'applies' => $warrantyCovered && $totalAmount > 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $collection
     * @return array<string, mixed>
     */
    private function financeWarrantyCustomerChargePayload(array $collection, bool $warrantyCovered): array
    {
        $serviceAmount = $warrantyCovered ? 0.0 : round((float) ($collection['service_amount'] ?? 0), 2);
        $partAmount = $warrantyCovered ? 0.0 : round((float) ($collection['part_amount'] ?? 0), 2);
        $totalAmount = round($serviceAmount + $partAmount, 2);

        return [
            'service_amount' => $serviceAmount,
            'part_amount' => $partAmount,
            'total_amount' => $totalAmount,
            'service_amount_label' => $this->moneyLabel($serviceAmount),
            'part_amount_label' => $this->moneyLabel($partAmount),
            'total_amount_label' => $this->moneyLabel($totalAmount),
            'covered_by_warranty' => $warrantyCovered,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $rootTotal
     * @return array<string, mixed>
     */
    private function financePayoutFromRootTotal(?array $rootTotal): array
    {
        $laborAmount = round((float) ($rootTotal['labor_amount'] ?? 0), 2);
        $routeFeeAmount = round((float) ($rootTotal['route_fee_amount'] ?? 0), 2);
        $totalAmount = round((float) ($rootTotal['total_amount'] ?? ($laborAmount + $routeFeeAmount)), 2);

        return [
            'labor_amount' => $laborAmount,
            'route_fee_amount' => $routeFeeAmount,
            'total_amount' => $totalAmount,
            'labor_amount_label' => $this->moneyLabel($laborAmount),
            'route_fee_amount_label' => $this->moneyLabel($routeFeeAmount),
            'total_amount_label' => $this->moneyLabel($totalAmount),
            'job_count' => $rootTotal['job_count'] ?? 0,
            'technician_count' => $rootTotal['technician_count'] ?? 0,
            'technician_names' => $rootTotal['technician_names'] ?? [],
            'is_multi_technician' => (bool) ($rootTotal['is_multi_technician'] ?? false),
            'payout_approval_required' => (bool) ($rootTotal['payout_approval_required'] ?? false),
            'payout_approval_status' => $rootTotal['payout_approval_status'] ?? 'not_required',
            'payout_approval_status_label' => $rootTotal['payout_approval_status_label'] ?? 'Tekil iş',
            'included_job_count' => $rootTotal['included_job_count'] ?? ($rootTotal['job_count'] ?? 0),
            'approved_job_count' => $rootTotal['approved_job_count'] ?? 0,
            'excluded_job_count' => $rootTotal['excluded_job_count'] ?? 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function financeNetMarginPayload(float $amount): array
    {
        return [
            'amount' => round($amount, 2),
            'amount_label' => $this->moneyLabel($amount),
            'is_negative' => $amount < 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customerChargeSummaryPayload(TechnicalServiceRequest $request): array
    {
        return $this->customerChargeSummaryForRequests($this->rootFinancialRequests($request));
    }

    /**
     * @param  Collection<int, TechnicalServiceRequest>  $requests
     * @return array<string, mixed>
     */
    private function customerChargeSummaryForRequests(Collection $requests): array
    {
        $payments = $this->customerChargePaymentsForRequests($requests);
        $this->preloadPaymentLinkMessageStates($payments);
        $rows = $payments
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
        return $this->customerChargePaymentsForRequests($this->rootFinancialRequests($request));
    }

    /**
     * @param  Collection<int, TechnicalServiceRequest>  $requests
     * @return Collection<int, TechnicalServiceMountPayment>
     */
    private function customerChargePaymentsForRequests(Collection $requests): Collection
    {
        $requestIds = $requests
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values();

        if ($requestIds->isEmpty()) {
            return collect();
        }

        $cacheKey = $requestIds
            ->sort()
            ->implode(':');
        if (array_key_exists($cacheKey, $this->customerChargePaymentsCache)) {
            return $this->customerChargePaymentsCache[$cacheKey];
        }

        $hasRequestPaymentCache = $requestIds
            ->every(fn (int $requestId): bool => array_key_exists($requestId, $this->mountPaymentsByRequestIdCache));
        if ($hasRequestPaymentCache) {
            $payments = $requestIds
                ->flatMap(fn (int $requestId): Collection => $this->mountPaymentsByRequestIdCache[$requestId] ?? collect());

            return $this->customerChargePaymentsCache[$cacheKey] = $this->filterCustomerChargePayments($payments);
        }

        return $this->customerChargePaymentsCache[$cacheKey] = TechnicalServiceMountPayment::query()
            ->with('technicalServiceRequest')
            ->whereIn('technical_service_request_id', $requestIds->all())
            ->latest('id')
            ->get()
            ->pipe(fn (Collection $payments): Collection => $this->filterCustomerChargePayments($payments));
    }

    /**
     * @param  Collection<int, TechnicalServiceMountPayment>  $payments
     * @return Collection<int, TechnicalServiceMountPayment>
     */
    private function filterCustomerChargePayments(Collection $payments): Collection
    {
        return $this->sortedUniquePayments($payments)
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
        $messageState = $this->paymentLinkMessageState($payment);
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
        $providerDecision = is_array($payload['provider_decision'] ?? null) ? $payload['provider_decision'] : [];
        $providerGateway = is_array($payload['provider_gateway'] ?? null) ? $payload['provider_gateway'] : [];
        $providerGatewaySync = is_array($payload['provider_gateway_sync'] ?? null) ? $payload['provider_gateway_sync'] : [];
        $messageText = trim((string) ($messageTemplate ?: 'Emaks Prime servis/parça ödemeniz için bağlantı aşağıdadır.'));
        if ($paymentUrl !== '' && ! str_contains($messageText, $paymentUrl)) {
            $messageText = trim($messageText)."\n\n".$paymentUrl;
        }

        return array_merge([
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
            'copy_url' => $paymentUrl !== '' ? $paymentUrl : null,
            'provider' => $payment->provider,
            'provider_mode' => $payload['provider_mode'] ?? $providerDecision['provider_mode'] ?? ($payment->provider === 'fake' ? 'local' : ($payload['provider_environment'] ?? null)),
            'provider_transport' => $payload['provider_transport'] ?? $providerDecision['provider_transport'] ?? ($payment->provider === 'fake' ? 'fake_local' : null),
            'provider_token' => $payment->provider_reference,
            'provider_reference' => $payment->provider_reference,
            'provider_payment_reference' => $payment->provider_payment_reference,
            'provider_transaction_reference' => $payment->provider_transaction_reference,
            'provider_receipt_reference' => $payment->provider_receipt_reference,
            'provider_status' => $providerGatewaySync['provider_status']
                ?? $providerGateway['provider_status']
                ?? $providerGatewaySync['raw_status']
                ?? $providerGateway['raw_status']
                ?? $payment->status,
            'paid_at' => $this->dateTimeString($payment->paid_at),
            'cancelled_at' => $payload['cancelled_at'] ?? null,
            'cancelled_by_name' => TechnicalServiceUiLabelService::cleanDisplayText($payload['cancelled_by_name'] ?? null),
            'cancellation_reason' => TechnicalServiceUiLabelService::cleanDisplayText($payload['cancellation_reason'] ?? null),
            'purpose' => $payload['purpose'] ?? $payload['charge_type'] ?? null,
            'purpose_label' => $this->customerChargePurposeLabel((string) ($payload['purpose'] ?? $payload['charge_type'] ?? '')),
            'note' => TechnicalServiceUiLabelService::cleanDisplayText($payload['note'] ?? null),
            'message_template' => $messageTemplate,
            'message_text' => $messageText,
            'message_send_count' => max((int) ($payload['message_send_count'] ?? 0), $messageState['send_count']),
            'last_message_sent_at' => data_get($payload, 'message_send_history.'.(max(0, count((array) ($payload['message_send_history'] ?? [])) - 1).'.requested_at'))
                ?? $messageState['last_message_sent_at'],
            'source' => $payload['source'] ?? null,
            'readonly' => in_array($payment->status, [
                TechnicalServiceMountPayment::STATUS_PAID,
                TechnicalServiceMountPayment::STATUS_CANCELLED,
            ], true),
            'can_cancel' => $payment->status === TechnicalServiceMountPayment::STATUS_PENDING,
            'created_at' => $this->dateTimeString($payment->created_at),
            'updated_at' => $this->dateTimeString($payment->updated_at),
        ], TechnicalServicePaymentActionPresenter::forPayment($payment));
    }

    /**
     * @return Collection<int, TechnicalServiceRequest>
     */
    private function rootFinancialRequests(TechnicalServiceRequest $request): Collection
    {
        $requestId = (int) $request->id;
        if (array_key_exists($requestId, $this->rootFinancialRequestsCache)) {
            return $this->rootFinancialRequestsCache[$requestId];
        }

        $root = $this->rootFinancialRequest($request) ?? $request;
        $root->loadMissing([
            'latestAssignmentOffer.technician',
            'technicianRecord',
            'childRequests.latestAssignmentOffer.technician',
            'childRequests.technicianRecord',
        ]);

        $requests = collect([$root])
            ->concat($root->childRequests)
            ->unique('id')
            ->values();

        $requests->each(function (TechnicalServiceRequest $related) use ($root, $requests): void {
            $this->rootFinancialRequestCache[(int) $related->id] = $root;
            $this->rootFinancialRequestsCache[(int) $related->id] = $requests;
        });

        return $this->rootFinancialRequestsCache[$requestId] ?? $requests;
    }

    private function rootFinancialRequest(TechnicalServiceRequest $request): ?TechnicalServiceRequest
    {
        $requestId = (int) $request->id;
        if (array_key_exists($requestId, $this->rootFinancialRequestCache)) {
            return $this->rootFinancialRequestCache[$requestId];
        }

        if ($request->parent_request_id === null) {
            return $this->rootFinancialRequestCache[$requestId] = $request;
        }

        if ($request->parentRequest instanceof TechnicalServiceRequest) {
            return $this->rootFinancialRequestCache[$requestId] = $request->parentRequest;
        }

        return $this->rootFinancialRequestCache[$requestId] = TechnicalServiceRequest::query()
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
            'confirmed', 'final', 'finalized', 'payable' => 'Kesinleşti',
            default => 'Hakediş yok',
        };
    }

    private function locksmithPayoutStatus(?string $offerStatus, float $totalAmount): string
    {
        if ($totalAmount <= 0) {
            return 'none';
        }

        return match ($offerStatus) {
            TechnicalServiceAssignmentOffer::STATUS_SENT,
            TechnicalServiceAssignmentOffer::STATUS_ACCEPTED,
            TechnicalServiceAssignmentOffer::STATUS_REVISED => 'confirmed',
            TechnicalServiceAssignmentOffer::STATUS_DRAFT => 'draft',
            TechnicalServiceAssignmentOffer::STATUS_CANCELLED => 'none',
            default => 'draft',
        };
    }

    private function locksmithPayoutStatusLabel(string $status): string
    {
        return match ($status) {
            'confirmed' => 'Onaylanan usta hakedişi',
            'draft' => 'Önerilen / taslak hakediş',
            default => 'Hakediş yok',
        };
    }

    private function storeCompletedEarningSnapshot(TechnicalServiceRequest $request): void
    {
        $operationControl = is_array($request->operation_control_payload) ? $request->operation_control_payload : [];
        $operationControl['completed_earning_snapshot'] = $this->buildCompletedEarningSnapshotPayload($request);
        $request->operation_control_payload = $operationControl;
    }

    public function finalizeCompletedEarningSnapshotForOpsPayoutApproval(
        TechnicalServiceRequest $request,
        ?Authenticatable $user = null,
    ): TechnicalServiceRequest {
        if ($request->completed_at === null && $request->installation_completed_at === null) {
            return $request;
        }

        $operationControl = is_array($request->operation_control_payload) ? $request->operation_control_payload : [];
        $snapshot = is_array($operationControl['completed_earning_snapshot'] ?? null)
            ? $operationControl['completed_earning_snapshot']
            : $this->buildCompletedEarningSnapshotPayload($request);
        $currentStatus = mb_strtolower(trim((string) ($snapshot['status'] ?? $snapshot['payout_status'] ?? '')));
        $messageStatus = mb_strtolower(trim((string) ($snapshot['earning_message_status'] ?? '')));
        $finalizedStatus = in_array($currentStatus, ['sent', 'submitted'], true)
            || in_array($messageStatus, ['sent', 'submitted'], true)
            ? 'sent'
            : 'finalized';

        $snapshot['status'] = $finalizedStatus;
        $snapshot['status_label'] = $this->assignmentOfferStatusLabel($finalizedStatus);
        $snapshot['payout_status'] = 'confirmed';
        $snapshot['payout_status_label'] = $this->locksmithPayoutStatusLabel('confirmed');
        $snapshot['finalized_at'] = now()->toISOString();
        $snapshot['finalized_by_user_id'] = $user?->id;
        $snapshot['finalization_source'] = 'ops_final_payout_approval';

        $operationControl['completed_earning_snapshot'] = $snapshot;
        $request->forceFill(['operation_control_payload' => $operationControl])->save();

        return $request->refresh();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function completedEarningSnapshot(TechnicalServiceRequest $request): ?array
    {
        if ($request->completed_at === null && $request->installation_completed_at === null) {
            return null;
        }

        $operationControl = is_array($request->operation_control_payload) ? $request->operation_control_payload : [];
        $snapshot = $operationControl['completed_earning_snapshot'] ?? null;

        return is_array($snapshot) ? $snapshot : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCompletedEarningSnapshotPayload(TechnicalServiceRequest $request): array
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
        $offerStatus = $offer instanceof TechnicalServiceAssignmentOffer ? $offer->status : null;
        $payoutStatus = $this->locksmithPayoutStatus($offerStatus, $totalAmount);
        $earningMessage = $this->technicianEarningMessagePayload($request);
        $paymentStatus = $this->payoutPaymentStatusPayload($request);
        $customerCollection = $this->financeCustomerCollectionForRequest($request);
        $isServiceVisit = $this->isServiceVisitRequest($request);
        $warrantyCovered = $isServiceVisit
            && $customerCollection['service_amount'] <= 0
            && $customerCollection['part_amount'] <= 0;

        return [
            'completed_request_id' => $request->id,
            'mrn' => $request->mrn,
            'root_mrn' => $request->root_mrn ?: ($request->parentRequest?->mrn ?: $request->mrn),
            'service_code' => $request->service_code,
            'parent_request_id' => $request->parent_request_id,
            'completed_at' => $this->dateTimeString($request->completed_at),
            'technical_service_technician_id' => $request->technical_service_technician_id,
            'technician_name' => TechnicalServiceUiLabelService::displayName($request->technician_name),
            'labor_amount' => round($laborAmount, 2),
            'route_fee_amount' => round($routeFeeAmount, 2),
            'total_amount' => $totalAmount,
            'status' => $offerStatus,
            'status_label' => $this->assignmentOfferStatusLabel($offerStatus),
            'payout_status' => $payoutStatus,
            'payout_status_label' => $this->locksmithPayoutStatusLabel($payoutStatus),
            'earning_message_status' => $earningMessage['status'] ?? null,
            'earning_message_sent_at' => $earningMessage['sent_at'] ?? null,
            'earning_message_label' => $earningMessage !== null ? 'Hakediş bilgisi gönderildi' : 'Hakediş bilgisi gönderilmedi',
            'payment_status' => $paymentStatus['status'],
            'payment_status_label' => $paymentStatus['status_label'],
            'paid_at' => $paymentStatus['paid_at'],
            'customer_collection' => $customerCollection,
            'warranty_covered' => $warrantyCovered,
            'source' => 'completion_snapshot',
        ];
    }

    /**
     * @return array{status:string,status_label:string,paid_at:string|null,earning_id:int|null}
     */
    private function payoutPaymentStatusPayload(TechnicalServiceRequest $request): array
    {
        $requestId = (int) $request->id;
        if (array_key_exists($requestId, $this->payoutPaymentStatusCache)) {
            return $this->payoutPaymentStatusCache[$requestId];
        }

        $item = TechnicalServiceEarningItem::query()
            ->with('earning')
            ->where('technical_service_request_id', $request->id)
            ->latest('id')
            ->first();

        return $this->payoutPaymentStatusCache[$requestId] = $item instanceof TechnicalServiceEarningItem
            ? $this->payoutPaymentStatusFromItem($item)
            : $this->emptyPayoutPaymentStatusPayload();
    }

    /**
     * @return array{status:string,status_label:string,paid_at:string|null,earning_id:int|null}
     */
    private function payoutPaymentStatusFromItem(TechnicalServiceEarningItem $item): array
    {
        if ($item->earning === null) {
            return $this->emptyPayoutPaymentStatusPayload();
        }

        $paidAt = $item->earning->paid_at?->toIso8601String();
        $status = $paidAt !== null ? 'paid' : 'pending';

        return [
            'status' => $status,
            'status_label' => $this->payoutPaymentStatusLabel($status),
            'paid_at' => $paidAt,
            'earning_id' => $item->earning->id,
        ];
    }

    /**
     * @return array{status:string,status_label:string,paid_at:string|null,earning_id:int|null}
     */
    private function emptyPayoutPaymentStatusPayload(): array
    {
        return [
            'status' => 'not_recorded',
            'status_label' => $this->payoutPaymentStatusLabel('not_recorded'),
            'paid_at' => null,
            'earning_id' => null,
        ];
    }

    private function payoutPaymentStatusLabel(string $status): string
    {
        return match ($status) {
            'paid' => 'Ödendi',
            'pending' => 'Ödeme bekliyor',
            default => 'Hakediş ödeme kaydı yok',
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
            'product_name' => TechnicalServiceUiLabelService::cleanDisplayText($serial->product_name),
            'product_model' => TechnicalServiceUiLabelService::cleanDisplayText($serial->product_model),
            'brand' => TechnicalServiceUiLabelService::cleanDisplayText($serial->brand),
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
     * @param  array<string, mixed>  $source
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
     * @param  array<string, mixed>  $source
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
        $isServiceVisit = $this->isServiceVisitRequest($request);
        $showMountControls = ! $isServiceVisit;
        $preFormPaymentControlEnabled = $this->preFormPaymentControlEnabled();
        $paymentRequiredForAssignment = $this->paymentControlAppliesToAssignment($request);
        $addressControlActionable = $showMountControls
            && (
                ($payload['address_checked'] ?? 'unreviewed') !== 'unreviewed'
                || filled($request->location_note)
                || blank($request->service_address)
            );

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
        $result['is_service_visit'] = $isServiceVisit;
        $result['applies_to_assignment'] = ! $isServiceVisit;
        $result['payment_required_for_assignment'] = $paymentRequiredForAssignment;
        $result['show_mount_controls'] = $showMountControls;
        $result['show_payment_control'] = $showMountControls && $preFormPaymentControlEnabled;
        $result['pre_form_payment_control_enabled'] = $preFormPaymentControlEnabled;
        $result['show_door_photo_control'] = $showMountControls;
        $result['show_address_control'] = $addressControlActionable;
        $result['show_schedule_control'] = $showMountControls
            || ($payload['schedule_update_required'] ?? 'unreviewed') !== 'unreviewed';
        $result['context_mode'] = $isServiceVisit ? 'service_visit_context' : 'mount_operation';

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function locationPayload(TechnicalServiceRequest $request): array
    {
        $routeLatitude = $request->location_latitude !== null ? (float) $request->location_latitude : null;
        $routeLongitude = $request->location_longitude !== null ? (float) $request->location_longitude : null;
        $routeSource = $routeLatitude !== null && $routeLongitude !== null ? 'request' : null;

        if (($routeLatitude === null || $routeLongitude === null) && $request->parent_request_id !== null) {
            $request->loadMissing('parentRequest');
            $parent = $request->parentRequest;

            if ($parent instanceof TechnicalServiceRequest
                && $parent->location_latitude !== null
                && $parent->location_longitude !== null
            ) {
                $routeLatitude = (float) $parent->location_latitude;
                $routeLongitude = (float) $parent->location_longitude;
                $routeSource = 'parent_request';
            }
        }

        return [
            'latitude' => $request->location_latitude !== null ? (float) $request->location_latitude : null,
            'longitude' => $request->location_longitude !== null ? (float) $request->location_longitude : null,
            'route_latitude' => $routeLatitude,
            'route_longitude' => $routeLongitude,
            'route_source' => $routeSource,
            'place_id' => $request->location_place_id,
            'formatted_address' => TechnicalServiceUiLabelService::addressLabel($request->location_formatted_address),
            'map_url' => $request->location_map_url,
            'source' => $request->location_source,
            'accuracy' => $request->location_accuracy,
            'note' => TechnicalServiceUiLabelService::addressLabel($request->location_note),
            'building_no' => $request->building_no,
            'apartment_no' => $request->apartment_no,
            'door_no' => $request->door_no,
            'floor_no' => $request->floor_no,
            'site_name' => TechnicalServiceUiLabelService::addressLabel($request->site_name),
            'shared' => filled($request->location_latitude) && filled($request->location_longitude),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function doorPhotoPayload(TechnicalServiceRequest $request): array
    {
        return $request->uploads
            ->filter(fn (TechnicalServiceRequestUpload $upload): bool => (
                $upload->category === TechnicalServiceRequestUpload::CATEGORY_OPERATION_CONTROL_DOOR_PHOTO
                && in_array((string) $upload->field_code, self::CUSTOMER_DOOR_PHOTO_FIELDS, true)
            ) || $this->isOpsDoorPhotoUpload($upload))
            ->sort(function (TechnicalServiceRequestUpload $left, TechnicalServiceRequestUpload $right): int {
                $createdAtCompare = ($left->created_at?->getTimestamp() ?? 0) <=> ($right->created_at?->getTimestamp() ?? 0);

                if ($createdAtCompare !== 0) {
                    return $createdAtCompare;
                }

                return ((int) $left->id) <=> ((int) $right->id);
            })
            ->map(function (TechnicalServiceRequestUpload $upload) use ($request): array {
                $authenticatedUrl = route('api.technical-service.requests.uploads.show', [
                    'technicalServiceRequest' => $request->id,
                    'upload' => $upload->id,
                ], false);

                $fieldCode = (string) $upload->field_code;

                return [
                    'id' => $upload->id,
                    'field_code' => $fieldCode,
                    'label' => self::OPS_EXTRA_DOCUMENT_TYPES[$fieldCode] ?? $upload->original_name,
                    'category' => $upload->category,
                    'original_name' => $upload->original_name,
                    'mime' => $upload->mime,
                    'size' => $upload->size,
                    'url' => $authenticatedUrl,
                    'preview_url' => $authenticatedUrl,
                    'download_url' => $authenticatedUrl,
                    'review_status' => $upload->review_status,
                    'review_note' => $upload->review_note,
                    'created_at' => $this->dateTimeString($upload->created_at),
                    'reviewed_at' => $this->dateTimeString($upload->reviewed_at),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fieldCompletionDocumentPayload(
        TechnicalServiceRequest $request,
        bool $onlyPrevious = false,
        bool $includePrevious = false,
    ): array {
        $request->loadMissing('uploads');
        $documents = $request->uploads
            ->filter(function (TechnicalServiceRequestUpload $upload) use ($request, $onlyPrevious, $includePrevious): bool {
                if (! $this->isFieldCompletionDocument($upload)) {
                    return false;
                }

                $isPrevious = $this->fieldDocumentPredatesActiveReopen($request, $upload->created_at ?? $upload->updated_at);

                return $onlyPrevious ? $isPrevious : ($includePrevious || ! $isPrevious);
            });

        if (! $onlyPrevious && ! $includePrevious) {
            $documents = $this->currentFieldCompletionDocuments($request)
                ->values()
                ->concat($this->currentOpsExtraDocuments($request)->values());
        }

        return $documents
            ->map(function (TechnicalServiceRequestUpload $upload) use ($request): array {
                $authenticatedUrl = route('api.technical-service.requests.uploads.show', [
                    'technicalServiceRequest' => $request->id,
                    'upload' => $upload->id,
                ], false);

                $fieldCode = (string) $upload->field_code;

                return [
                    'id' => $upload->id,
                    'field_code' => $fieldCode,
                    'label' => self::FIELD_COMPLETION_DOCUMENT_TYPES[$fieldCode]
                        ?? self::OPS_EXTRA_DOCUMENT_TYPES[$fieldCode]
                        ?? $upload->original_name,
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
     * @return Collection<string, TechnicalServiceRequestUpload>
     */
    private function currentFieldCompletionDocuments(TechnicalServiceRequest $request): Collection
    {
        $request->loadMissing('uploads');

        return $request->uploads
            ->filter(fn (TechnicalServiceRequestUpload $upload): bool => $this->isFieldCompletionDocument($upload)
                && ! $this->fieldDocumentPredatesActiveReopen($request, $upload->created_at ?? $upload->updated_at))
            ->filter(fn (TechnicalServiceRequestUpload $upload): bool => array_key_exists((string) $upload->field_code, self::FIELD_COMPLETION_DOCUMENT_TYPES))
            ->sort(function (TechnicalServiceRequestUpload $left, TechnicalServiceRequestUpload $right): int {
                $createdAtCompare = ($right->created_at?->getTimestamp() ?? 0) <=> ($left->created_at?->getTimestamp() ?? 0);

                if ($createdAtCompare !== 0) {
                    return $createdAtCompare;
                }

                return ((int) $right->id) <=> ((int) $left->id);
            })
            ->unique(fn (TechnicalServiceRequestUpload $upload): string => (string) $upload->field_code)
            ->mapWithKeys(fn (TechnicalServiceRequestUpload $upload): array => [
                (string) $upload->field_code => $upload,
            ]);
    }

    /**
     * @return Collection<int, TechnicalServiceRequestUpload>
     */
    private function currentOpsExtraDocuments(TechnicalServiceRequest $request): Collection
    {
        $request->loadMissing('uploads');

        return $request->uploads
            ->filter(fn (TechnicalServiceRequestUpload $upload): bool => $upload->category === TechnicalServiceRequestUpload::CATEGORY_OPS_EXTRA_DOCUMENT
                && ! $this->isOpsDoorPhotoUpload($upload)
                && ! $this->fieldDocumentPredatesActiveReopen($request, $upload->created_at ?? $upload->updated_at))
            ->sort(function (TechnicalServiceRequestUpload $left, TechnicalServiceRequestUpload $right): int {
                $createdAtCompare = ($right->created_at?->getTimestamp() ?? 0) <=> ($left->created_at?->getTimestamp() ?? 0);

                if ($createdAtCompare !== 0) {
                    return $createdAtCompare;
                }

                return ((int) $right->id) <=> ((int) $left->id);
            })
            ->values();
    }

    private function isFieldCompletionDocument(TechnicalServiceRequestUpload $upload): bool
    {
        if ($upload->category === TechnicalServiceRequestUpload::CATEGORY_OPS_EXTRA_DOCUMENT) {
            return ! $this->isOpsDoorPhotoUpload($upload);
        }

        if ($upload->category === TechnicalServiceRequestUpload::CATEGORY_PARTNER_PORTAL_FIELD_DOCUMENT) {
            return true;
        }

        return $upload->category === TechnicalServiceRequestUpload::CATEGORY_OPERATION_CONTROL_DOOR_PHOTO
            && array_key_exists((string) $upload->field_code, self::FIELD_COMPLETION_DOCUMENT_TYPES);
    }

    private function isOpsDoorPhotoUpload(TechnicalServiceRequestUpload $upload): bool
    {
        return $upload->category === TechnicalServiceRequestUpload::CATEGORY_OPS_EXTRA_DOCUMENT
            && in_array((string) $upload->field_code, self::OPS_DOOR_PHOTO_FIELDS, true);
    }

    /**
     * @return array{payment_check_required:bool,payment_required_for_assignment:bool,door_photo_check_required:bool,mount_exclusion_ack_required:bool,mount_payment_received:bool,applies_to_assignment:bool,payment_decision:string,messages:array<int,string>}
     */
    private function assignmentBlockers(TechnicalServiceRequest $request): array
    {
        if ($this->isServiceVisitRequest($request)) {
            return [
                'payment_check_required' => false,
                'payment_required_for_assignment' => false,
                'door_photo_check_required' => false,
                'mount_exclusion_ack_required' => false,
                'mount_payment_received' => false,
                'applies_to_assignment' => false,
                'payment_decision' => 'no_payment_required',
                'messages' => [],
            ];
        }

        $operationControl = $this->operationControlPayload($request);
        $messages = [];
        $paymentStatus = $this->paymentStatusForRequest($request);
        $paymentDecision = $this->preFormPaymentControlEnabled()
            ? $this->assignmentPaymentDecision($request, $paymentStatus)
            : 'no_payment_required';
        $paymentControlApplies = (bool) ($operationControl['payment_required_for_assignment'] ?? false);
        $paymentRequired = $this->assignmentPaymentCheckRequired($request, $operationControl);
        $doorPhotoRequired = ($operationControl['door_photos_checked'] ?? 'unreviewed') !== 'compatible';
        $mountExclusionAckRequired = $this->requiresMountExclusionAcknowledgement($request);

        if ($paymentRequired) {
            $messages[] = 'Ödeme yöntemi netleşmeden atama güncellenemez. Ödeme linki oluşturun veya müşterinin ustaya ödeyeceği tutarı belirleyin.';
        }

        if ($doorPhotoRequired) {
            $messages[] = 'Usta atanamaz. Önce kapı görsellerini uygun olarak kontrol edin.';
        }

        return [
            'payment_check_required' => $paymentRequired,
            'payment_required_for_assignment' => $paymentControlApplies,
            'door_photo_check_required' => $doorPhotoRequired,
            'mount_exclusion_ack_required' => $mountExclusionAckRequired,
            'mount_payment_received' => $this->mountPaymentReceived($request),
            'applies_to_assignment' => true,
            'payment_decision' => $paymentDecision,
            'messages' => $messages,
        ];
    }

    /**
     * @param  array<string, mixed>  $operationControl
     */
    private function assignmentPaymentCheckRequired(TechnicalServiceRequest $request, array $operationControl): bool
    {
        return $this->paymentControlAppliesToAssignment($request);
    }

    private function paymentControlAppliesToAssignment(TechnicalServiceRequest $request): bool
    {
        if ($this->isServiceVisitRequest($request) || ! $this->preFormPaymentControlEnabled()) {
            return false;
        }

        $paymentStatus = $this->paymentStatusForRequest($request);

        return in_array($this->assignmentPaymentDecision($request, $paymentStatus), [
            'payment_decision_missing',
            'payment_needed_no_decision',
        ], true);
    }

    private function preFormPaymentControlEnabled(): bool
    {
        return app(QrPublicFlowSettingsService::class)->preFormPaymentEnabled();
    }

    /**
     * @param  array<string, mixed>  $paymentStatus
     */
    private function assignmentPaymentDecision(TechnicalServiceRequest $request, array $paymentStatus): string
    {
        $ownership = $this->paymentOwnershipForRequest($request);
        $state = (string) ($ownership['payer_state_key'] ?? '');

        return match ($state) {
            TechnicalServicePaymentOwnershipService::STATE_COMPANY_COLLECTED_ONLINE => 'company_collected_online',
            TechnicalServicePaymentOwnershipService::STATE_COMPANY_COLLECTED_EXTERNAL => 'company_collected_external',
            TechnicalServicePaymentOwnershipService::STATE_PENDING_ONLINE_PAYMENT => 'pending_online_payment',
            TechnicalServicePaymentOwnershipService::STATE_CUSTOMER_PAYS_TECHNICIAN => 'customer_pays_technician',
            TechnicalServicePaymentOwnershipService::STATE_NO_PAYMENT_REQUIRED => 'no_payment_required',
            default => (bool) ($paymentStatus['requires_payment'] ?? false)
                ? 'payment_decision_missing'
                : 'no_payment_required',
        };
    }

    private function hasCustomerDirectTechnicianDecision(TechnicalServiceRequest $request): bool
    {
        return (bool) ($this->paymentOwnershipForRequest($request)['customer_should_pay_technician'] ?? false);
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
    private function assignmentOfferPayload(
        TechnicalServiceRequest $request,
        ?TechnicalServiceAssignmentOffer $offer,
    ): ?array {
        if (! $offer instanceof TechnicalServiceAssignmentOffer) {
            return null;
        }

        $metadata = is_array($offer->metadata) ? $offer->metadata : [];
        $messagePayload = is_array($metadata['message_payload'] ?? null) ? $metadata['message_payload'] : [];
        $messageDispatch = is_array($metadata['message_dispatch'] ?? null) ? $metadata['message_dispatch'] : [];
        $offer->loadMissing('technician');
        $presentation = $offer->technician instanceof TechnicalServiceTechnician
            ? $this->technicianEarningPresentation($request, $offer->technician, $offer)
            : null;

        return [
            'id' => $offer->id,
            'technical_service_request_id' => $offer->technical_service_request_id,
            'technical_service_technician_id' => $offer->technical_service_technician_id,
            'technician_name' => TechnicalServiceUiLabelService::displayName($offer->technician?->name),
            'route_quote_id' => $offer->route_quote_id,
            'labor_amount' => (float) $offer->labor_amount,
            'route_fee_amount' => (float) $offer->route_fee_amount,
            'total_amount' => (float) $offer->total_amount,
            'currency' => $offer->currency,
            'status' => $offer->status,
            'note' => TechnicalServiceUiLabelService::cleanDisplayText($offer->note),
            'sent_at' => $this->dateTimeString($offer->sent_at),
            'metadata' => $metadata,
            'message_payload' => $messagePayload,
            'earning_snapshot' => $presentation['earning_snapshot'] ?? $this->canonicalTechnicianEarningSnapshot($offer),
            'message_preview' => $presentation['message_preview'] ?? null,
            'message_text' => $presentation['message_preview'] ?? ($messagePayload['message_text'] ?? null),
            'job_link' => $messagePayload['job_link'] ?? null,
            'dispatch_status' => $messageDispatch['status'] ?? null,
            'created_at' => $this->dateTimeString($offer->created_at),
            'updated_at' => $this->dateTimeString($offer->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function settlementPayload(?TechnicalServiceSettlement $settlement): ?array
    {
        if (! $settlement instanceof TechnicalServiceSettlement) {
            return null;
        }

        $settlement->loadMissing('request');
        $paymentOwnership = $settlement->request instanceof TechnicalServiceRequest
            ? $this->paymentOwnershipForRequest($settlement->request, $settlement)
            : null;

        return [
            'id' => $settlement->id,
            'technical_service_request_id' => $settlement->technical_service_request_id,
            'technical_service_assignment_offer_id' => $settlement->technical_service_assignment_offer_id,
            'technical_service_technician_id' => $settlement->technical_service_technician_id,
            'b2b_partner_id' => $settlement->b2b_partner_id,
            'currency' => $settlement->currency,
            'labor_earning_amount' => (float) $settlement->labor_earning_amount,
            'route_earning_amount' => (float) $settlement->route_earning_amount,
            'technician_earning_total' => (float) $settlement->technician_earning_total,
            'customer_collection_amount' => (float) $settlement->customer_collection_amount,
            'customer_direct_to_technician_amount' => (float) $settlement->customer_direct_to_technician_amount,
            'customer_direct_assumed_paid_amount' => (float) $settlement->customer_direct_assumed_paid_amount,
            'company_payable_amount' => (float) $settlement->company_payable_amount,
            'company_paid_amount' => (float) $settlement->company_paid_amount,
            'company_remaining_amount' => (float) $settlement->company_remaining_amount,
            'overpay_warning_amount' => (float) $settlement->overpay_warning_amount,
            'overpay_requires_review' => (bool) $settlement->overpay_requires_review,
            'review_reason' => TechnicalServiceUiLabelService::cleanDisplayText($settlement->review_reason),
            'status' => $settlement->status,
            'status_label' => $this->settlementStatusPayloadLabel($settlement->status),
            'review_decision' => $this->settlementReviewDecisionPayload($settlement),
            'payer_state_key' => $paymentOwnership['payer_state_key'] ?? null,
            'payer_state_label' => $paymentOwnership['payer_state_label'] ?? null,
            'payer_state_description' => $paymentOwnership['payer_state_description'] ?? null,
            'payment_instruction_for_customer' => $paymentOwnership['payment_instruction_for_customer'] ?? null,
            'customer_should_pay_technician' => $paymentOwnership['customer_should_pay_technician'] ?? false,
            'company_collected_amount' => $paymentOwnership['company_collected_amount'] ?? (float) $settlement->customer_collection_amount,
            'company_collected_source' => $paymentOwnership['company_collected_source'] ?? null,
            'active_customer_direct_to_technician_amount' => $paymentOwnership['active_customer_direct_to_technician_amount'] ?? 0,
            'pending_payment_total' => $paymentOwnership['pending_payment_total'] ?? 0,
            'cancelled_payment_total' => $paymentOwnership['cancelled_payment_total'] ?? 0,
            'wp_payment_message_trigger' => $paymentOwnership['wp_payment_message_trigger'] ?? 'appointment_approval',
            'wp_payment_message_ready' => $paymentOwnership['wp_payment_message_ready'] ?? false,
            'settlement_source' => $settlement->settlement_source,
            'metadata' => is_array($settlement->metadata) ? $settlement->metadata : [],
            'created_at' => $this->dateTimeString($settlement->created_at),
            'updated_at' => $this->dateTimeString($settlement->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function settlementReviewDecisionPayload(TechnicalServiceSettlement $settlement): ?array
    {
        $metadata = is_array($settlement->metadata) ? $settlement->metadata : [];
        $decision = $metadata['admin_review'] ?? null;

        if (! is_array($decision)) {
            return null;
        }

        $decisionKey = (string) ($decision['decision'] ?? '');

        return [
            'decision' => $decisionKey,
            'decision_label' => $this->settlementReviewDecisionLabel($decisionKey),
            'reason' => TechnicalServiceUiLabelService::cleanDisplayText($decision['reason'] ?? null),
            'reviewed_at' => $decision['reviewed_at'] ?? null,
            'reviewed_by' => $decision['reviewed_by'] ?? null,
            'reviewed_by_name' => TechnicalServiceUiLabelService::cleanDisplayText($decision['reviewed_by_name'] ?? null),
            'customer_direct_to_technician_amount' => isset($decision['customer_direct_to_technician_amount'])
                ? (float) $decision['customer_direct_to_technician_amount']
                : null,
            'company_payable_amount' => isset($decision['company_payable_amount'])
                ? (float) $decision['company_payable_amount']
                : null,
            'overpay_warning_amount' => isset($decision['overpay_warning_amount'])
                ? (float) $decision['overpay_warning_amount']
                : null,
            'requires_review_after_decision' => isset($decision['requires_review_after_decision'])
                ? (bool) $decision['requires_review_after_decision']
                : null,
        ];
    }

    private function settlementReviewDecisionLabel(string $decision): string
    {
        return match ($decision) {
            'approve_difference' => 'Farkı onayla',
            'correct_direct_amount' => 'Tutarları düzelt',
            'exclude' => 'Hakedişe dahil değil',
            default => TechnicalServiceUiLabelService::cleanDisplayText($decision) ?: 'İnceleme kararı',
        };
    }

    private function settlementStatusPayloadLabel(?string $status): string
    {
        return match ($status) {
            TechnicalServiceSettlement::STATUS_CALCULATED => 'Hesaplandı',
            TechnicalServiceSettlement::STATUS_ADMIN_REVIEW => 'Admin incelemesi',
            TechnicalServiceSettlement::STATUS_FINALIZED => 'Kesinleşti',
            TechnicalServiceSettlement::STATUS_SENT => 'Gönderildi',
            TechnicalServiceSettlement::STATUS_PARTIAL_PAID => 'Kısmi ödendi',
            TechnicalServiceSettlement::STATUS_PAID => 'Ödendi',
            TechnicalServiceSettlement::STATUS_EXCLUDED => 'Hakedişe dahil değil',
            TechnicalServiceSettlement::STATUS_DRAFT => 'Taslak',
            default => TechnicalServiceUiLabelService::cleanDisplayText($status) ?: 'Settlement yok',
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function technicianRevisionOfferPayload(TechnicalServiceRequest $request): ?array
    {
        if (! $request->relationLoaded('partnerJobActions')) {
            return null;
        }

        $action = $request->partnerJobActions
            ->sortByDesc(fn (TechnicalServicePartnerJobAction $item): int => (int) $item->id)
            ->first(fn (TechnicalServicePartnerJobAction $item): bool => $item->action === TechnicalServicePartnerJobAction::ACTION_PRICE_REVISION_REQUESTED
                && ! $this->actionResolvedForNewWork($item)
                && ! $this->recordPredatesActiveReopen($request, $item->created_at ?? $item->updated_at));

        if (! $action instanceof TechnicalServicePartnerJobAction) {
            return null;
        }

        $payload = is_array($action->payload) ? $action->payload : [];
        $laborAmount = $this->nullableFloat($payload['labor_amount'] ?? null);
        $routeFeeAmount = $this->nullableFloat($payload['route_fee_amount'] ?? null);
        $totalAmount = round(($laborAmount ?? 0.0) + ($routeFeeAmount ?? 0.0), 2);
        $status = $this->technicianRevisionOfferStatus($request, $action);
        $technician = $action->technician ?: $request->technicianRecord;

        return [
            'exists' => true,
            'id' => $action->id,
            'status' => $status,
            'status_label' => match ($status) {
                'pending' => 'Operasyon yanıtı bekliyor',
                'resolved' => 'Yanıtlandı',
                'accepted' => 'Kabul edildi',
                'rejected' => 'Reddedildi',
                'revision_requested' => 'Düzenleme istendi',
                'countered' => 'Karşı teklif verildi',
                default => TechnicalServiceUiLabelService::statusLabel($status),
            },
            'technician_id' => $action->technical_service_technician_id ?? $request->technical_service_technician_id,
            'technician_name' => TechnicalServiceUiLabelService::displayName($technician?->name ?? $request->technician_name),
            'labor_earning' => $laborAmount,
            'route_earning' => $routeFeeAmount,
            'total_earning' => $totalAmount,
            'note' => TechnicalServiceUiLabelService::cleanDisplayText($payload['note'] ?? $action->note),
            'requested_at' => $this->dateTimeString($action->created_at),
            'resolved_at' => isset($payload['resolved_at']) ? (string) $payload['resolved_at'] : null,
            'resolved_by' => $payload['resolved_by_user_id'] ?? null,
            'source' => 'partner_portal',
        ];
    }

    private function technicianRevisionOfferStatus(TechnicalServiceRequest $request, TechnicalServicePartnerJobAction $action): string
    {
        $payload = is_array($action->payload) ? $action->payload : [];
        if (($payload['revision_status'] ?? null) === 'resolved' || isset($payload['resolved_assignment_offer_id'])) {
            return 'resolved';
        }

        if ($action->status === TechnicalServicePartnerJobAction::STATUS_APPLIED) {
            return 'resolved';
        }

        if ($action->status === TechnicalServicePartnerJobAction::STATUS_REJECTED) {
            return 'rejected';
        }

        if ($action->status === TechnicalServicePartnerJobAction::STATUS_REVISION_REQUESTED) {
            return 'revision_requested';
        }

        if ($this->priceRevisionResolvedByAssignmentOffer($request, $action)) {
            return 'resolved';
        }

        return 'pending';
    }

    private function actionResolvedForNewWork(TechnicalServicePartnerJobAction $action): bool
    {
        $payload = is_array($action->payload) ? $action->payload : [];

        return (bool) ($payload['resolved_by_reassignment'] ?? false)
            || isset($payload['service_visit_created']);
    }

    private function priceRevisionResolvedByAssignmentOffer(TechnicalServiceRequest $request, TechnicalServicePartnerJobAction $action): bool
    {
        if ($action->action !== TechnicalServicePartnerJobAction::ACTION_PRICE_REVISION_REQUESTED) {
            return false;
        }

        $offer = $request->latestAssignmentOffer;
        if (! $offer instanceof TechnicalServiceAssignmentOffer) {
            return false;
        }

        $actionTechnicianId = (int) ($action->technical_service_technician_id ?? 0);
        if ($actionTechnicianId > 0 && $actionTechnicianId !== (int) $offer->technical_service_technician_id) {
            return false;
        }

        $metadata = is_array($offer->metadata) ? $offer->metadata : [];
        $resolvedIds = collect($metadata['resolved_price_revision_action_ids'] ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values();
        if ($resolvedIds->contains((int) $action->id)) {
            return true;
        }

        $revisedAt = $offer->updated_at;
        if (isset($metadata['revised_at'])) {
            try {
                $revisedAt = CarbonImmutable::parse((string) $metadata['revised_at']);
            } catch (\Throwable) {
                $revisedAt = $offer->updated_at;
            }
        }

        $actionAt = $action->created_at ?? $action->updated_at;

        return $offer->status === TechnicalServiceAssignmentOffer::STATUS_REVISED
            && $revisedAt instanceof CarbonInterface
            && $actionAt instanceof CarbonInterface
            && $revisedAt->greaterThanOrEqualTo($actionAt);
    }

    /**
     * @return array<string, mixed>
     */
    private function technicianRecordDisplayPayload(TechnicalServiceTechnician $technician): array
    {
        $payload = $technician->toArray();
        $city = TechnicalServiceUiLabelService::cityLabel($technician->city);

        $payload['name'] = TechnicalServiceUiLabelService::displayName($technician->name);
        $payload['first_name'] = TechnicalServiceUiLabelService::displayName($technician->first_name);
        $payload['last_name'] = TechnicalServiceUiLabelService::displayName($technician->last_name);
        $payload['display_name'] = TechnicalServiceUiLabelService::displayName($technician->display_name);
        $payload['city'] = $city;
        $payload['district'] = TechnicalServiceUiLabelService::districtLabel($technician->district, $city);

        foreach ([
            'address',
            'google_formatted_address',
            'default_start_address',
            'mikro_cari_adi',
            'cari_title',
            'cari_address',
            'cari_city_district_country',
            'note',
            'route_note',
        ] as $field) {
            $payload[$field] = TechnicalServiceUiLabelService::addressLabel($technician->{$field});
        }

        return $payload;
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
        $jobCardContext = $this->partnerJobScope->technicianJobCardContext($request);
        $jobLink = is_string($jobCardContext['canonical_url'] ?? null)
            ? $jobCardContext['canonical_url']
            : null;

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
            'job_link' => $jobLink,
            'technician_job_card_url' => $jobLink,
            'technician_job_card_short_url' => $jobLink,
            'technician_job_card_ready' => (bool) ($jobCardContext['ready'] ?? false),
            'technician_job_card_blocker_code' => $jobCardContext['blocker_code'] ?? null,
            'technician_job_card_blocker_message' => $jobCardContext['blocker_message'] ?? null,
            'assignment_partner_id' => $jobCardContext['partner_id'] ?? null,
            'assignment_partner_technician_link_id' => $jobCardContext['partner_technician_link_id'] ?? null,
            'appointment_date' => $request->scheduled_date?->toDateString(),
            'appointment_time' => $request->scheduled_time,
            'labor_amount' => round((float) ($amounts['labor_amount'] ?? 0), 2),
            'route_fee_amount' => round((float) ($amounts['route_fee_amount'] ?? 0), 2),
            'total_amount' => round((float) ($amounts['total_amount'] ?? 0), 2),
            'currency' => $amounts['currency'] ?? 'TRY',
            'note' => $amounts['note'] ?? null,
            'payment_message_trigger' => 'appointment_approval',
            'payment_instruction_included' => false,
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
                $title = TechnicalServiceUiLabelService::cleanDisplayText($event->title);
                $eventTypeLabel = TechnicalServiceUiLabelService::actionLabel($eventType);

                return [
                    ...$row,
                    'title' => $title,
                    'note' => TechnicalServiceUiLabelService::cleanDisplayText($event->note),
                    'event_type_label' => $eventTypeLabel,
                    'title_label' => filled($title) ? $title : $eventTypeLabel,
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
        if ($this->isServiceVisitRequest($request)) {
            return 'Servis';
        }

        return $request->service_type;
    }

    private function isServiceVisitRequest(TechnicalServiceRequest $request): bool
    {
        return $request->parent_request_id !== null || filled($request->service_code);
    }

    /**
     * @return array<string, mixed>
     */
    private function visibleSectionsPayload(TechnicalServiceRequest $request): array
    {
        $displayServiceType = $this->displayServiceType($request);
        $isServiceVisit = $this->isServiceVisitRequest($request) || $displayServiceType === 'Servis';
        $isMount = $displayServiceType === 'Montaj' && ! $isServiceVisit;
        $isCompleted = in_array($this->normalizeToken($request->status), ['tamamlandi', 'tamamland'], true)
            || in_array($this->normalizeToken($request->workflow_status), ['tamamlandi', 'tamamland'], true)
            || $request->completed_at !== null
            || $request->installation_completed_at !== null;
        $partRequests = $request->relationLoaded('partRequests')
            ? $request->partRequests
            : $request->partRequests()->get();
        $operationControlPayload = is_array($request->operation_control_payload)
            ? $request->operation_control_payload
            : [];
        $activePartRequests = $partRequests->filter(
            fn (TechnicalServicePartRequest $partRequest): bool => in_array((string) $partRequest->status, TechnicalServicePartRequest::ACTIVE_STATUSES, true),
        );
        $hasChargeablePartRequest = $activePartRequests->contains(function (TechnicalServicePartRequest $partRequest): bool {
            $metadata = is_array($partRequest->metadata) ? $partRequest->metadata : [];

            return ($metadata['charge_decision'] ?? null) === 'chargeable'
                || ($metadata['payment_decision'] ?? null) === 'chargeable';
        });

        return [
            'warranty' => ($isMount && $isCompleted) || $isServiceVisit,
            'warranty_mode' => $isServiceVisit ? 'compact' : (($isMount && $isCompleted) ? 'full' : 'hidden'),
            'service_part_charge' => $hasChargeablePartRequest,
            'part_request_decision' => $activePartRequests->isNotEmpty(),
            'earnings_breakdown' => $isServiceVisit || ($request->relationLoaded('childRequests')
                ? $request->childRequests->isNotEmpty()
                : $request->childRequests()->exists()),
            'is_service_visit' => $isServiceVisit,
            'operation_mount_controls' => ! $isServiceVisit,
            'payment_control' => ! $isServiceVisit && $this->preFormPaymentControlEnabled(),
            'door_photo_control' => ! $isServiceVisit,
            'address_control' => ! $isServiceVisit
                && (
                    ($operationControlPayload['address_checked'] ?? 'unreviewed') !== 'unreviewed'
                    || filled($request->location_note)
                    || blank($request->service_address)
                ),
            'schedule_control' => ! $isServiceVisit
                || (($operationControlPayload['schedule_update_required'] ?? 'unreviewed') !== 'unreviewed'),
            'manual_checks' => $isServiceVisit ? [
                [
                    'code' => 'warranty_check',
                    'label' => 'Garanti durumunu kontrol et',
                ],
            ] : [],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serviceVisitHistoryPayload(TechnicalServiceRequest $request): ?array
    {
        $rootMrn = $this->canonicalServiceVisitRootMrn($request);
        $isServiceVisit = filled($request->service_code) || $request->parent_request_id !== null;
        $root = $this->canonicalServiceVisitRootRequest($request, $rootMrn);
        $directParent = $this->directServiceVisitParent($request);
        $historyRequests = $this->canonicalServiceVisitHistoryRequests($request, $root, $rootMrn);
        $rootId = $root instanceof TechnicalServiceRequest ? (int) $root->id : null;
        $siblings = $historyRequests
            ->reject(fn (TechnicalServiceRequest $record): bool => (int) $record->id === (int) $request->id)
            ->reject(fn (TechnicalServiceRequest $record): bool => $rootId !== null && (int) $record->id === $rootId)
            ->values();

        if (! $isServiceVisit && $siblings->isEmpty()) {
            return null;
        }

        $partRequestSerializer = app(TechnicalServicePartRequestService::class);
        $this->loadServiceVisitHistoryRelations($historyRequests);
        $historyItems = $historyRequests
            ->map(fn (TechnicalServiceRequest $record): array => $this->serviceVisitHistoryItem($record, $request, $root))
            ->values();

        return [
            'root_mrn' => $rootMrn !== '' ? $rootMrn : null,
            'service_code' => $request->service_code,
            'reason' => $request->service_visit_reason,
            'reason_label' => TechnicalServiceUiLabelService::serviceVisitReasonLabel($request->service_visit_reason),
            'parent_request' => $root instanceof TechnicalServiceRequest ? $this->serviceVisitRequestSummary($root) : null,
            'root_request' => $root instanceof TechnicalServiceRequest ? $this->serviceVisitRequestSummary($root) : null,
            'direct_parent_request' => $directParent instanceof TechnicalServiceRequest ? $this->serviceVisitRequestSummary($directParent) : null,
            'current_request' => $this->serviceVisitRequestSummary($request),
            'items' => $historyItems->all(),
            'parent_events' => $this->serviceVisitTimelineEvents($historyRequests),
            'parent_part_requests' => $historyRequests
                ->flatMap(fn (TechnicalServiceRequest $record) => $record->partRequests)
                ->sortByDesc('created_at')
                ->take(12)
                ->map(fn ($partRequest): array => $partRequestSerializer->serialize($partRequest))
                ->values()
                ->all(),
            'sibling_service_visits' => $siblings
                ->reject(fn (TechnicalServiceRequest $sibling): bool => (int) $sibling->id === (int) $request->id)
                ->map(fn (TechnicalServiceRequest $sibling): array => $this->serviceVisitRequestSummary($sibling))
                ->values()
                ->all(),
            'history_records' => $historyRequests
                ->map(fn (TechnicalServiceRequest $record): array => [
                    ...$this->serviceVisitHistoryRecord($record),
                    ...$this->serviceVisitHistoryItem($record, $request, $root),
                ])
                ->values()
                ->all(),
        ];
    }

    private function canonicalServiceVisitRootMrn(TechnicalServiceRequest $request): string
    {
        if (filled($request->root_mrn)) {
            return (string) $request->root_mrn;
        }

        $root = $this->canonicalServiceVisitRootRequest($request, '');

        if ($root instanceof TechnicalServiceRequest) {
            return (string) $root->mrn;
        }

        return (string) ($request->parent_request_id === null ? $request->mrn : ($request->parentRequest?->root_mrn ?: $request->parentRequest?->mrn ?: ''));
    }

    private function canonicalServiceVisitRootRequest(TechnicalServiceRequest $request, string $rootMrn): ?TechnicalServiceRequest
    {
        if ($request->parent_request_id === null && ! filled($request->service_code)) {
            return $request;
        }

        if ($rootMrn !== '') {
            $root = TechnicalServiceRequest::query()
                ->with([
                    'technicianRecord',
                    'uploads',
                    'events' => fn ($query) => $query->latest()->limit(8),
                    'partRequests' => fn ($query) => $query->latest()->limit(6),
                ])
                ->where('mrn', $rootMrn)
                ->orderByRaw('case when parent_request_id is null then 0 else 1 end')
                ->oldest('id')
                ->first();

            if ($root instanceof TechnicalServiceRequest) {
                return $root;
            }
        }

        $visited = [];
        $cursor = $this->directServiceVisitParent($request);
        $depth = 0;

        while ($cursor instanceof TechnicalServiceRequest && $depth < 16) {
            $cursorId = (int) $cursor->id;
            if (isset($visited[$cursorId])) {
                return null;
            }

            $visited[$cursorId] = true;

            if ($cursor->parent_request_id === null) {
                return $rootMrn === '' || (string) $cursor->mrn === $rootMrn
                    ? $cursor
                    : null;
            }

            if ($rootMrn !== '' && (string) $cursor->mrn === $rootMrn) {
                return $cursor;
            }

            $cursor = $cursor->parentRequest instanceof TechnicalServiceRequest
                ? $cursor->parentRequest
                : TechnicalServiceRequest::query()->find($cursor->parent_request_id);
            $depth++;
        }

        return null;
    }

    private function directServiceVisitParent(TechnicalServiceRequest $request): ?TechnicalServiceRequest
    {
        if ($request->parent_request_id === null) {
            return null;
        }

        if ($request->parentRequest instanceof TechnicalServiceRequest) {
            return $request->parentRequest;
        }

        return TechnicalServiceRequest::query()->find($request->parent_request_id);
    }

    /**
     * @return Collection<int, TechnicalServiceRequest>
     */
    private function canonicalServiceVisitHistoryRequests(TechnicalServiceRequest $request, ?TechnicalServiceRequest $root, string $rootMrn): Collection
    {
        $records = collect([$root, $request])
            ->filter(fn ($record): bool => $record instanceof TechnicalServiceRequest);

        if ($rootMrn !== '') {
            $records = $records->merge(TechnicalServiceRequest::query()
                ->with([
                    'technicianRecord',
                    'uploads',
                    'events' => fn ($query) => $query->latest()->limit(8),
                    'partRequests' => fn ($query) => $query->latest()->limit(6),
                ])
                ->where('root_mrn', $rootMrn)
                ->orderBy('service_sequence')
                ->orderBy('id')
                ->limit(48)
                ->get());
        }

        $records = $records->merge($this->serviceVisitParentChain($request));

        if ($root instanceof TechnicalServiceRequest) {
            $records = $records->merge(TechnicalServiceRequest::query()
                ->with([
                    'technicianRecord',
                    'uploads',
                    'events' => fn ($query) => $query->latest()->limit(8),
                    'partRequests' => fn ($query) => $query->latest()->limit(6),
                ])
                ->where('parent_request_id', $root->id)
                ->orderBy('service_sequence')
                ->orderBy('id')
                ->limit(48)
                ->get());
        }

        $rootId = $root instanceof TechnicalServiceRequest ? (int) $root->id : null;

        return $records
            ->filter(fn ($record): bool => $record instanceof TechnicalServiceRequest)
            ->unique(fn (TechnicalServiceRequest $record): int => (int) $record->id)
            ->sort(fn (TechnicalServiceRequest $left, TechnicalServiceRequest $right): int => $this->serviceVisitHistorySortKey($left, $rootId) <=> $this->serviceVisitHistorySortKey($right, $rootId))
            ->values();
    }

    /**
     * @return Collection<int, TechnicalServiceRequest>
     */
    private function serviceVisitParentChain(TechnicalServiceRequest $request): Collection
    {
        $records = collect();
        $visited = [];
        $cursor = $this->directServiceVisitParent($request);
        $depth = 0;

        while ($cursor instanceof TechnicalServiceRequest && $depth < 16) {
            $cursorId = (int) $cursor->id;
            if (isset($visited[$cursorId])) {
                break;
            }

            $visited[$cursorId] = true;
            $records->push($cursor);

            if ($cursor->parent_request_id === null) {
                break;
            }

            $cursor = $cursor->parentRequest instanceof TechnicalServiceRequest
                ? $cursor->parentRequest
                : TechnicalServiceRequest::query()->find($cursor->parent_request_id);
            $depth++;
        }

        return $records;
    }

    /**
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function serviceVisitHistorySortKey(TechnicalServiceRequest $request, ?int $rootId): array
    {
        $isRoot = $rootId !== null && (int) $request->id === $rootId;
        $createdAt = $request->created_at instanceof CarbonInterface ? $request->created_at->getTimestamp() : 0;

        return [
            $isRoot ? 0 : 1,
            $isRoot ? 0 : $this->serviceVisitSequenceForSort($request),
            $createdAt,
            (int) $request->id,
        ];
    }

    private function serviceVisitSequenceForSort(TechnicalServiceRequest $request): int
    {
        if ((int) $request->service_sequence > 0) {
            return (int) $request->service_sequence;
        }

        $code = (string) ($request->service_code ?: $request->mrn);
        if (preg_match('/-(\d{3,})$/', $code, $matches) === 1) {
            return (int) ltrim($matches[1], '0') ?: 0;
        }

        return 9999;
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceVisitHistoryItem(TechnicalServiceRequest $record, TechnicalServiceRequest $current, ?TechnicalServiceRequest $root): array
    {
        $isRoot = $root instanceof TechnicalServiceRequest && (int) $record->id === (int) $root->id;
        $isCurrent = (int) $record->id === (int) $current->id;
        $sequence = $isRoot ? 0 : $this->serviceVisitSequenceForSort($record);
        $reasonLabel = $isRoot
            ? 'Montaj'
            : TechnicalServiceUiLabelService::serviceVisitReasonLabel($record->service_visit_reason);
        $statusLabel = TechnicalServiceUiLabelService::cleanDisplayText($record->workflow_status ?: $record->status);

        return [
            'code' => $record->service_code ?: $record->mrn,
            'type' => $isRoot ? 'root_mrn' : 'srv',
            'label' => $isRoot ? 'Ana talep' : 'Servis ziyareti',
            'reason' => $reasonLabel,
            'status_label' => $statusLabel,
            'is_current' => $isCurrent,
            'sequence' => $sequence,
        ];
    }

    /**
     * @param  Collection<int, TechnicalServiceRequest>  $requests
     */
    private function loadServiceVisitHistoryRelations(Collection $requests): void
    {
        $models = new EloquentCollection($requests
            ->filter(fn (mixed $request): bool => $request instanceof TechnicalServiceRequest)
            ->unique(fn (TechnicalServiceRequest $request): int => (int) $request->id)
            ->values()
            ->all());

        if ($models->isEmpty()) {
            return;
        }

        $models->loadMissing([
            'technicianRecord',
            'uploads',
            'events' => fn ($query) => $query->latest()->limit(8),
            'partRequests' => fn ($query) => $query->latest()->limit(6),
        ]);
    }

    /**
     * @param  Collection<int, TechnicalServiceRequest>  $requests
     * @return array<int, array<string, mixed>>
     */
    private function serviceVisitTimelineEvents(Collection $requests): array
    {
        $events = $requests
            ->flatMap(fn (TechnicalServiceRequest $record) => $record->events)
            ->sortByDesc('created_at')
            ->take(12)
            ->values();

        return $this->eventPayload($events);
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
            'status' => TechnicalServiceUiLabelService::cleanDisplayText($request->status),
            'workflow_status' => TechnicalServiceUiLabelService::cleanDisplayText($request->workflow_status),
            'completed_at' => $this->dateTimeString($request->completed_at),
            'created_at' => $this->dateTimeString($request->created_at),
            'latest_event' => $request->relationLoaded('events')
                ? TechnicalServiceUiLabelService::cleanDisplayText($request->events->first()?->title)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceVisitHistoryRecord(TechnicalServiceRequest $request): array
    {
        $request->loadMissing(['technicianRecord', 'uploads', 'events' => fn ($query) => $query->latest()->limit(6)]);

        return [
            ...$this->serviceVisitRequestSummary($request),
            'customer_name' => TechnicalServiceUiLabelService::cleanDisplayText($request->customer_name),
            'technician_name' => TechnicalServiceUiLabelService::displayName($request->technicianRecord?->name ?? $request->technician_name),
            'technician_phone' => $request->technicianRecord?->phone,
            'scheduled_at' => $this->dateTimeString($request->scheduled_at),
            'field_started_at' => $this->dateTimeString($request->field_started_at),
            'technician_arrived_at' => $this->dateTimeString($request->technician_arrived_at),
            'field_completed_at' => $this->dateTimeString($request->field_completed_at),
            'technician_completed_at' => $this->dateTimeString($request->technician_completed_at),
            'completion_note' => TechnicalServiceUiLabelService::cleanDisplayText($request->field_completion_note),
            'documents' => $this->fieldCompletionDocumentPayload($request, includePrevious: true),
            'events' => array_slice($this->eventPayload($request->events), 0, 6),
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
     * @param  array<string, mixed>  $paymentStatus
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
     * @param  array<string, mixed>  $paymentStatus
     * @param  array<string, mixed>|null  $extraPayment
     */
    private function primaryMountPaidAmount(TechnicalServiceRequest $request, array $paymentStatus, ?array $extraPayment, array $mountPayments = []): ?float
    {
        if ($this->isServiceVisitRequest($request)) {
            return null;
        }

        $paidMountAmount = round((float) ($mountPayments['paid_mount_amount'] ?? 0), 2);
        if ($paidMountAmount > 0) {
            return $paidMountAmount;
        }

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

        return null;
    }

    /**
     * @return array<string, float|int|string|null>
     */
    private function financialAliases(TechnicalServiceRequest $request): array
    {
        $isServiceVisit = $this->isServiceVisitRequest($request);
        $paymentStatus = $this->paymentStatusForRequest($request);
        $extraPayment = $this->latestExtraMountPaymentPayload($request);
        $mountPayments = $this->mountCustomerPaymentSummaryPayload($request);
        $customerCharges = $this->customerChargeSummaryPayload($request);
        $paidMountCustomerAmount = $this->primaryMountPaidAmount($request, $paymentStatus, $extraPayment, $mountPayments);
        $customerAmount = $paidMountCustomerAmount ?? ($isServiceVisit ? null : $this->customerAmountForService($request->service_type));
        $paidExtraCustomerAmount = (float) ($mountPayments['paid_extra_amount'] ?? 0);
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
            : ($isServiceVisit ? null : $customerAmount);
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
        string $note,
        ?string $jobCardUrl = null,
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
        if (filled($jobCardUrl)) {
            $lines[] = 'İş kartı:';
            $lines[] = $jobCardUrl;
        }

        return implode("\n", $lines);
    }

    private function auditLogTableAvailable(): bool
    {
        return Schema::hasTable('technical_service_audit_logs');
    }

    /**
     * @param  array<string, mixed>  $payload
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
     * @param  list<string>  $allowed
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
     * @param  array<string, bool>|null  $payload
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
            ->filter(fn (TechnicalServiceRequestUpload $upload): bool => ! $this->fieldDocumentPredatesActiveReopen($request, $upload->created_at ?? $upload->updated_at))
            ->map(fn (TechnicalServiceRequestUpload $upload): string => (string) $upload->field_code)
            ->filter(fn (string $field): bool => array_key_exists($field, self::FIELD_COMPLETION_DOCUMENT_TYPES))
            ->unique();

        return $presentTypes->count() === count(self::FIELD_COMPLETION_DOCUMENT_TYPES);
    }

    private function recordPredatesActiveReopen(TechnicalServiceRequest $request, mixed $recordAt): bool
    {
        return $request->reopened_at instanceof CarbonInterface
            && $recordAt instanceof CarbonInterface
            && $recordAt->lessThan($request->reopened_at);
    }

    private function fieldDocumentPredatesActiveReopen(TechnicalServiceRequest $request, mixed $recordAt): bool
    {
        return $request->reopened_at instanceof CarbonInterface
            && $recordAt instanceof CarbonInterface
            && $recordAt->lessThanOrEqualTo($request->reopened_at);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $old
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
        $this->storeCompletedEarningSnapshot($request);
        $request->save();
        app(TechnicalServiceSettlementCompletionService::class)->apply($request->refresh(), $user);

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

    private function completedWorkflowStatus(): string
    {
        return "Tamamland\u{0131}";
    }

    private function cancelledWorkflowStatus(): string
    {
        return "\u{0130}ptal";
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
