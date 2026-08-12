<?php

namespace App\Services\Messaging;

use App\Models\IntegrationProviderCredential;
use App\Models\PageConfig;
use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServicePartRequest;
use App\Models\TechnicalServiceRequest;
use App\Models\TechnicalServiceRequestSerial;
use App\Models\User;
use App\Services\ExternalEffects\ExternalEffectCapabilityRegistry;
use App\Services\ExternalEffects\ExternalExecutionControlPlaneService;
use App\Services\Mikro\MikroOperationRegistry;
use App\Services\Mikro\MikroRuntimeState;
use App\Services\Payments\TechnicalServiceMailTransportSettingsService;
use App\Services\Payments\TechnicalServicePaymentProviderSettingsService;
use App\Services\TechnicalService\TechnicalServicePaymentActionPresenter;
use App\Support\PartnerPortalPublicUrl;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class TechnicalServiceMessagingSettingsService
{
    public function __construct(
        private readonly MikroOperationRegistry $mikroOperationRegistry,
        private readonly MikroRuntimeState $mikroRuntimeState,
    ) {}

    public const PAGE_CODE = 'technical_service_admin';

    public const ROOT_KEY = 'technical_service.messaging';

    public const LIFECYCLE_PAGE_CODE = 'technical_service_manual_e2e_lifecycle';

    public const LIFECYCLE_ROOT_KEY = 'technical_service.messaging.lifecycle';

    public const MANUAL_E2E_PHASE_FROZEN = 'frozen';

    public const MANUAL_E2E_PHASE_PREPARED = 'prepared';

    public const MANUAL_E2E_PHASE_WINDOW_OPEN = 'window_open';

    public const MANUAL_E2E_WINDOW_TTL_SECONDS = 30;

    public const SCOPED_LOCAL_UAT_EFFECT_WINDOW_SECONDS = 900;

    public const SCOPED_LOCAL_UAT_MAX_TTL_SECONDS = 3600;

    private const SCOPED_LOCAL_UAT_MESSAGE_BODY_MATRIX = [
        'appointment_approved_customer|whatsapp|customer|evo_whatsapp' => 'whatsapp',
        'appointment_approved_technician|whatsapp|technician|evo_whatsapp' => 'whatsapp',
        'appointment_approved_customer|sms|customer|nac_sms' => 'sms',
    ];

    private const SCOPED_LOCAL_UAT_TOKEN_OVERRIDE_KEYS = [
        'expected_body_token',
        'body_token',
        'smoke_token',
        'uat_token',
    ];

    public const SCOPED_EFFECT_PAYMENT_CREATE = 'sandbox_payment_create';

    public const SCOPED_EFFECT_PAYMENT_CALLBACK = 'sandbox_payment_callback';

    public const SCOPED_PAYMENT_OUTCOME_NEW_PENDING = 'new_pending';

    public const SCOPED_PAYMENT_OUTCOME_REUSED_PENDING = 'reused_pending';

    public const SCOPED_PAYMENT_OUTCOME_ALREADY_PAID = 'already_paid';

    public const SCOPED_PAYMENT_OUTCOME_TERMINAL_NOT_REUSABLE = 'terminal_not_reusable';

    public const OUTBOUND_EXECUTION_MODE_LOCAL = 'local';

    public const OUTBOUND_EXECUTION_MODE_LIVE = 'live';

    public const OUTBOUND_WORKER_LOCK_KEY = 'technical_service_message_dispatch_live_worker';

    public const OUTBOUND_WORKER_LEASE_KEY = 'technical_service_message_dispatch_live_worker_lease';

    public const OUTBOUND_WORKER_HEARTBEAT_STALE_AFTER_SECONDS = 30;

    public const LOCAL_MANUAL_ACCEPTANCE_PROFILE = 'LOCAL_MANUAL_ACCEPTANCE';

    public const LOCAL_MANUAL_ACCEPTANCE_PROFILE_VERSION = 1;

    private const MANUAL_E2E_ADVISORY_LOCK_CLASS_ID = 1162690891;

    private const MANUAL_E2E_ADVISORY_LOCK_OBJECT_ID = 1296384581;

    private static bool $lifecycleLockHeldInProcess = false;

    private const AUTHORITATIVE_LIFECYCLE_FIELDS = [
        'manual_e2e_enabled',
        'real_send_enabled',
        'test_mode_enabled',
        'queue_paused',
        'ops_whatsapp_enabled',
        'ops_whatsapp_phone',
        'manual_e2e_phase',
        'manual_e2e_active_run_id',
        'manual_e2e_started_at',
        'manual_e2e_created_after',
        'manual_e2e_expires_at',
        'manual_e2e_last_run_id',
        'manual_e2e_last_stopped_at',
        'manual_e2e_open_window',
        'manual_e2e_active_claim',
        'manual_e2e_run_snapshot',
        'manual_e2e_window_history',
        'scoped_local_uat_active_effect_claim',
        'scoped_local_uat_effect_history',
        'normal_outbound_active_claim',
        'normal_outbound_history',
        'outbound_execution_mode',
        'outbound_mode_revision',
        'outbound_mode_changed_at',
        'outbound_mode_changed_by',
        'outbound_mode_reason',
        'manual_e2e_ttl_seconds',
        'manual_e2e_allowlisted_phones',
        'manual_e2e_partner_portal_origin_enabled',
        'manual_e2e_partner_portal_origin',
        'messaging_enabled',
        'test_phone',
        'customer_test_phone',
        'technician_ops_test_phone',
        'provider_key',
        'active_provider',
        'default_provider',
        'fallback_provider',
        'provider_priority',
        'providers',
        'nac_sms',
        'evo_whatsapp',
        'message_types',
        'mikro_api',
        'send_delay_seconds',
        'duplicate_cooldown_minutes',
        'hourly_limit',
        'daily_limit',
        'max_auto_retries',
        'allow_browser_smoke_send',
        'allow_test_fixture_send',
        'local_manual_acceptance_enabled',
        'local_manual_acceptance_activated_at',
        'local_manual_acceptance_profile_fingerprint',
        'local_manual_acceptance_reason',
    ];

    public const GENERIC_LIFECYCLE_FIELDS = [
        'manual_e2e_enabled',
        'queue_paused',
        'manual_e2e_active_run_id',
        'manual_e2e_started_at',
        'manual_e2e_created_after',
        'manual_e2e_expires_at',
        'manual_e2e_last_run_id',
        'manual_e2e_last_stopped_at',
        'manual_e2e_phase',
        'manual_e2e_open_window',
        'manual_e2e_active_claim',
        'manual_e2e_window_history',
        'manual_e2e_run_snapshot',
        'scoped_local_uat_active_effect_claim',
        'scoped_local_uat_effect_history',
        'normal_outbound_active_claim',
        'normal_outbound_history',
        'outbound_execution_mode',
        'outbound_mode_revision',
        'outbound_mode_changed_at',
        'outbound_mode_changed_by',
        'outbound_mode_reason',
        'manual_e2e_run_id',
        'smoke_run_id',
        'manual_e2e',
        'local_manual_acceptance_enabled',
        'local_manual_acceptance_activated_at',
        'local_manual_acceptance_profile_fingerprint',
        'local_manual_acceptance_reason',
        'local_manual_acceptance_profile',
    ];

    private const ACTIVE_RUN_LOCKED_FIELDS = [
        'messaging_enabled',
        'test_mode_enabled',
        'test_phone',
        'shared_test_phone',
        'customer_test_phone',
        'technician_ops_test_phone',
        'manual_e2e_allowlisted_phones',
        'manual_e2e_ttl_seconds',
        'manual_e2e_partner_portal_origin_enabled',
        'manual_e2e_partner_portal_origin',
        'ops_whatsapp_enabled',
        'ops_whatsapp_phone',
        'provider_key',
        'active_provider',
        'default_provider',
        'fallback_provider',
        'provider_priority',
        'nac_sms',
        'evo_whatsapp',
        'mikro_api',
        'message_types',
        'send_delay_seconds',
        'duplicate_cooldown_minutes',
        'hourly_limit',
        'daily_limit',
        'max_auto_retries',
        'allow_browser_smoke_send',
        'allow_test_fixture_send',
    ];

    private const PROVIDER_CAPABILITY_DEFAULTS = [
        'supports_text' => false,
        'supports_sms' => false,
        'supports_whatsapp' => false,
        'supports_voice' => false,
        'supports_template' => false,
        'supports_media' => false,
        'supports_callback' => false,
        'supports_delivery_receipt' => false,
        'supports_reports' => false,
        'supports_credit' => false,
        'supports_sender_list' => false,
        'supports_gateway_list' => false,
        'supports_cancel' => false,
        'supports_read' => false,
        'supports_write' => false,
        'requires_approval' => false,
    ];

    public const PROVIDERS = [
        'null_local' => [
            'label' => 'Null / Local',
            'channel' => 'local',
            'description' => 'Güvenli varsayılan; provider çağrısı yapmaz.',
            'status_label' => 'Güvenli varsayılan',
            'default_enabled' => true,
            'contract_confirmed' => true,
            'current_practical' => false,
            'disabled_reason' => null,
            'capabilities' => [
                'supports_text' => true,
                'supports_sms' => false,
                'supports_whatsapp' => false,
                'supports_voice' => false,
                'supports_template' => false,
                'supports_media' => false,
                'supports_callback' => false,
                'supports_delivery_receipt' => false,
            ],
        ],
        'evo_whatsapp' => [
            'label' => 'Evo WhatsApp',
            'channel' => 'whatsapp',
            'description' => 'Mevcut WhatsApp sağlayıcısı; queue mesajları Laravel üzerinden Direct Evolution API ile gönderilir.',
            'status_label' => 'Mevcut sağlayıcı',
            'default_enabled' => true,
            'contract_confirmed' => true,
            'current_practical' => true,
            'disabled_reason' => null,
            'capabilities' => [
                'supports_text' => true,
                'supports_sms' => false,
                'supports_whatsapp' => true,
                'supports_voice' => false,
                'supports_template' => true,
                'supports_media' => false,
                'supports_callback' => false,
                'supports_delivery_receipt' => false,
            ],
        ],
        'nac_sms' => [
            'label' => 'NAC SMS',
            'channel' => 'sms',
            'description' => 'NAC SMS API doğrudan Laravel adapter ile çalışır; Basic Auth ve gönderici ayarları admin panelden yönetilir.',
            'status_label' => 'SMS sağlayıcısı',
            'default_enabled' => false,
            'contract_confirmed' => true,
            'current_practical' => true,
            'disabled_reason' => 'NAC gerçek gönderimi yalnız aktif Manual E2E run context ve güvenli queue worker ile yapılır.',
            'capabilities' => [
                'supports_text' => true,
                'supports_sms' => true,
                'supports_template' => true,
                'supports_callback' => true,
                'supports_delivery_receipt' => true,
                'supports_reports' => true,
                'supports_credit' => true,
                'supports_sender_list' => true,
                'supports_gateway_list' => true,
                'supports_cancel' => true,
            ],
        ],
        'voibot_voice' => [
            'label' => 'Voibot Voice',
            'channel' => 'voice',
            'description' => 'Sesli arama sağlayıcısı adayı; API/sözleşme kesinleşene kadar kapalıdır.',
            'status_label' => 'Sözleşme bekliyor',
            'default_enabled' => false,
            'contract_confirmed' => false,
            'current_practical' => false,
            'disabled_reason' => 'Voibot API/Postman/OpenAPI, webhook ve outbound call sözleşmesi bekleniyor.',
            'capabilities' => [
                'supports_text' => false,
                'supports_sms' => false,
                'supports_whatsapp' => false,
                'supports_voice' => true,
                'supports_template' => false,
                'supports_media' => false,
                'supports_callback' => false,
                'supports_delivery_receipt' => false,
            ],
        ],
        'voibot_messaging_if_supported' => [
            'label' => 'Voibot Messaging',
            'channel' => 'messaging',
            'description' => 'Voibot WhatsApp/SMS/message-send desteği doğrulanırsa bağlanacak aday.',
            'status_label' => 'Message API bekliyor',
            'default_enabled' => false,
            'contract_confirmed' => false,
            'current_practical' => false,
            'disabled_reason' => 'Voibot message-send desteği ve limitleri doğrulanmadı.',
            'capabilities' => [
                'supports_text' => false,
                'supports_sms' => false,
                'supports_whatsapp' => false,
                'supports_voice' => false,
                'supports_template' => false,
                'supports_media' => false,
                'supports_callback' => false,
                'supports_delivery_receipt' => false,
            ],
        ],
        'future_sms_provider' => [
            'label' => 'Future SMS Provider',
            'channel' => 'sms',
            'description' => 'Gelecek SMS sağlayıcısı için ayrılmış pasif slot.',
            'status_label' => 'Gelecek',
            'default_enabled' => false,
            'contract_confirmed' => false,
            'current_practical' => false,
            'disabled_reason' => 'SMS sağlayıcı seçimi yapılmadı.',
            'capabilities' => [
                'supports_text' => true,
                'supports_sms' => true,
                'supports_whatsapp' => false,
                'supports_voice' => false,
                'supports_template' => false,
                'supports_media' => false,
                'supports_callback' => false,
                'supports_delivery_receipt' => false,
            ],
        ],
        'mikro_api' => [
            'label' => 'Mikro API',
            'channel' => 'erp',
            'description' => 'Mikro read-only contract entegrasyonu; yazma ve generic operasyon yüzeyi kapalıdır.',
            'status_label' => 'ERP API hazırlığı',
            'default_enabled' => false,
            'contract_confirmed' => true,
            'current_practical' => false,
            'disabled_reason' => 'Canlı private endpoint ve Panel uygulama credential bilgileri bekleniyor.',
            'capabilities' => [
                'supports_read' => true,
                'supports_write' => false,
                'requires_approval' => true,
            ],
        ],
    ];

    public const SMS_CHANNEL_POLICIES = [
        'whatsapp_only',
        'sms_only',
        'whatsapp_primary_sms_fallback',
        'whatsapp_and_sms',
        'disabled',
    ];

    public const CHANNEL_MODES = [
        'disabled',
        'test',
        'live',
    ];

    public const MESSAGE_TYPES = [
        'new_request_created_ops' => [
            'label' => 'OPS yeni teknik servis talebi',
            'recipient_role' => 'ops',
            'description' => 'Yeni teknik servis talebi oluştuğunda OPS WhatsApp bilgilendirmesi.',
        ],
        'mount_request_created_customer' => [
            'label' => 'Müşteri montaj talebi alındı',
            'recipient_role' => 'customer',
            'description' => 'Müşteri montaj talebini gönderdiğinde alındı teyidi verir.',
        ],
        'appointment_approved_customer' => [
            'label' => 'Müşteri randevu onayı',
            'recipient_role' => 'customer',
            'description' => 'OPS randevuyu onayladığında müşteriye gider.',
        ],
        'appointment_approved_technician' => [
            'label' => 'Usta randevu onayı',
            'recipient_role' => 'technician',
            'description' => 'OPS randevuyu onayladığında ustaya iş ve adres bilgisini verir.',
        ],
        'appointment_updated_customer' => [
            'label' => 'Müşteri randevu güncelleme',
            'recipient_role' => 'customer',
            'description' => 'Onaylı randevuda anlamlı tarih/saat değişikliği olursa müşteriye gider.',
        ],
        'appointment_updated_technician' => [
            'label' => 'Usta randevu güncelleme',
            'recipient_role' => 'technician',
            'description' => 'Onaylı randevuda anlamlı tarih/saat değişikliği olursa ustaya gider.',
        ],
        'appointment_cancelled_customer' => [
            'label' => 'Müşteri randevu iptali',
            'recipient_role' => 'customer',
            'description' => 'Randevu/iş iptal edildiğinde müşteriye gider.',
        ],
        'appointment_cancelled_technician' => [
            'label' => 'Usta randevu iptali',
            'recipient_role' => 'technician',
            'description' => 'Randevu/iş iptal edildiğinde ustaya gider.',
        ],
        'customer_approval_request' => [
            'label' => 'Müşteri işlem onayı',
            'recipient_role' => 'customer',
            'description' => 'Saha tamamlandıktan sonra müşteri onay linki için kullanılır.',
        ],
        'payment_link_customer' => [
            'label' => 'Müşteri ödeme linki',
            'recipient_role' => 'customer',
            'description' => 'Açık aksiyonla gönderilir; link oluşturmak tek başına mesaj göndermez.',
        ],
        'payment_received_ops' => [
            'label' => 'Ödeme alındı / OPS bildirimi',
            'recipient_role' => 'ops',
            'description' => 'Trusted ödeme reconcile sonrası OPS WhatsApp bilgilendirmesi.',
        ],
        'customer_pays_technician_notice' => [
            'label' => 'Ustaya ödeme bilgilendirmesi',
            'recipient_role' => 'customer',
            'description' => 'Müşteri ustaya ödeme yapacaksa açık tutar zorunludur.',
        ],
        'assignment_offer_technician' => [
            'label' => 'Usta iş teklifi',
            'recipient_role' => 'technician',
            'description' => 'Usta atama/teklif bilgilendirmesi; müşteri randevu mesajı değildir.',
        ],
        'appointment_proposed_ops' => [
            'label' => 'OPS randevu önerisi',
            'recipient_role' => 'ops',
            'description' => 'Usta randevu saati önerdiğinde OPS WhatsApp bilgilendirmesi.',
        ],
        'earnings_message_technician' => [
            'label' => 'Usta hakediş mesajı',
            'recipient_role' => 'technician',
            'description' => 'Hakediş ekranındaki manuel bilgilendirme mesajı.',
        ],
        'price_revision_response_technician' => [
            'label' => 'Usta hakediş revizyon cevabı',
            'recipient_role' => 'technician',
            'description' => 'OPS hakediş revizyonuna yanıt verdiğinde ustaya gider.',
        ],
        'final_control_completed_customer' => [
            'label' => 'Müşteri son kontrol tamamlandı',
            'recipient_role' => 'customer',
            'description' => 'OPS son kontrolü tamamladığında müşteriye kapanış bilgisi gider.',
        ],
        'activation_code_customer' => [
            'label' => 'Müşteri aktivasyon kodu',
            'recipient_role' => 'customer',
            'description' => 'Aktivasyon kodu hazır olduğunda müşteriye gider.',
        ],
        'warranty_started_customer' => [
            'label' => 'Müşteri garanti başlangıcı',
            'recipient_role' => 'customer',
            'description' => 'Garanti başlangıcı netleştiğinde müşteriye gider.',
        ],
        'activation_warranty_customer' => [
            'label' => 'Aktivasyon ve garanti bilgilendirmesi',
            'recipient_role' => 'customer',
            'description' => 'Final kontrol sonrası aktivasyon, garanti ve anket bilgisini tek mesajda verir.',
        ],
        'completion_submitted_ops' => [
            'label' => 'OPS tamamlandı bildirimi',
            'recipient_role' => 'ops',
            'description' => 'Usta işi tamamladığında OPS bilgilendirmesi.',
        ],
        'part_request_ops' => [
            'label' => 'OPS parça talebi',
            'recipient_role' => 'ops',
            'description' => 'Parça talebi oluştuğunda OPS bilgilendirmesi.',
        ],
        'part_received_ops' => [
            'label' => 'Parça teslim alındı / OPS bildirimi',
            'recipient_role' => 'ops',
            'description' => 'Usta parçayı teslim aldığında OPS WhatsApp bilgilendirmesi.',
        ],
        'part_fee_payment_link_customer' => [
            'label' => 'Parça ücreti ödeme bağlantısı',
            'recipient_role' => 'customer',
            'description' => 'Ücretli parça kararı sonrası müşteriye ödeme linki gönderimi.',
        ],
        'support_request_ops' => [
            'label' => 'OPS destek talebi',
            'recipient_role' => 'ops',
            'description' => 'Usta/partner destek istediğinde OPS bilgilendirmesi.',
        ],
        'job_rejected_ops' => [
            'label' => 'OPS iş reddi',
            'recipient_role' => 'ops',
            'description' => 'Usta işi reddettiğinde OPS bilgilendirmesi.',
        ],
        'price_revision_requested_ops' => [
            'label' => 'OPS fiyat revizyonu',
            'recipient_role' => 'ops',
            'description' => 'Usta fiyat revizyonu istediğinde OPS bilgilendirmesi.',
        ],
        'future_survey_customer' => [
            'label' => 'Müşteri anketi',
            'recipient_role' => 'customer',
            'description' => 'REL-14 için kayıtlı gelecek mesaj tipi.',
            'future' => true,
        ],
    ];

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $settings = $this->settings();
        $evo = $this->evoWhatsappPayload($settings);
        $readiness = $this->readiness($settings);
        $manualE2e = TechnicalServiceManualE2ERunContext::fromSettings($settings)->payload();

        return [
            'keys' => [
                'root' => self::ROOT_KEY,
            ],
            'execution_mode' => $this->executionModePayloadForSettings($settings, true),
            'global' => $this->globalPayload($settings, $manualE2e),
            'readiness' => $readiness,
            'portal_origins' => $this->portalOriginReadiness($settings),
            'manual_e2e' => $manualE2e,
            'provider' => [
                'active_provider' => $settings['active_provider'],
                'default_provider' => $settings['default_provider'],
                'fallback_provider' => $settings['fallback_provider'],
                'provider_priority' => $settings['provider_priority'],
                'webhook_url_configured' => $readiness['provider_webhook_configured'],
                'direct_api_ready' => $evo['direct_api_ready'],
                'direct_api_endpoint' => $evo['endpoint_url'],
                'provider_secret_configured' => $readiness['provider_secret_configured'],
                'webhook_url_value' => $readiness['provider_webhook_configured'] ? 'configured' : null,
                'secret_value' => null,
                'webhook_path' => null,
                'router' => 'Laravel queue mesajları Direct Evolution API ile gönderir; n8n/webhook queue runtime değildir.',
            ],
            'providers' => $this->providerPayload($settings, $readiness),
            'capability_map' => $this->capabilityMap(),
            'evo_whatsapp' => $evo,
            'nac_sms' => $this->nacSmsPayload($settings),
            'mikro_api' => $this->mikroApiPayload($settings),
            'admin_sections' => $this->adminSections($settings, $readiness),
            'message_types' => $this->messageTypePayload($settings['message_types']),
            'warnings' => [
                'Yerel gerçek gönderim Admin mesaj sistemi, gerçek gönderim ve test modu ayarlarıyla yönetilir.',
                'Randevu mesajları usta seçildiğinde değil OPS randevu onayında gider.',
                'Test modu açıkken hedef numara müşteri veya usta / OPS rolüne ait test numarasına çevrilir.',
                'Provider dispatch’leri test allowlisti, queue, rate limit ve idempotency kontrollerinden geçer.',
                'Voibot ses/mesaj sağlayıcısı API sözleşmesi doğrulanana kadar kapalıdır.',
                'Evo Direct API, NAC SMS ve Mikro API canlı aksiyonları credential/readiness/queue/onay tamamlanmadan çalışmaz.',
            ],
            'helper_texts' => [
                'secrets' => 'Evo, NAC, Voibot, Mikro veya n8n token/API key bu ekranda düz metin saklanmaz ve gösterilmez.',
                'queue' => 'Yerel provider kuyruğu gerçek gönderim açıkken normal dispatch’leri tek sender ve kalıcı claim ile işler.',
                'test_phone' => 'Test modu açıkken müşteri ve usta / OPS mesajları ayrı kayıtlı test telefonlarına yönlenir.',
                'active_provider' => 'Öncelikli sağlayıcı manuel test/readiness için varsayılan bakılan sağlayıcıdır.',
                'default_provider' => 'Varsayılan test sağlayıcısı otomasyon değil, güvenli preview/test tercihidir.',
                'fallback_provider' => 'Otomatik provider fallback bu kontrollü akışta kapalıdır; kanal politikası açık provider seçimini kullanır.',
                'channel_policy' => 'Kanal politikası WhatsApp/SMS seçimini tanımlar; birlikte gönderim güvenli queue ve idempotency kontrolleriyle yürür.',
            ],
        ];
    }

    /**
     * Queue planning needs stored policy, not the expensive provider-readiness report.
     * Transport workers still enforce the canonical execution-mode and readiness gates.
     *
     * @return array<string, mixed>
     */
    public function workflowDispatchSnapshot(): array
    {
        $settings = $this->settings();
        $manualE2e = TechnicalServiceManualE2ERunContext::fromSettings($settings)->payload();

        return [
            'global' => $this->globalPayload($settings, $manualE2e),
            'providers' => $this->workflowProviderPayload($settings),
            'message_types' => $this->messageTypePayload($settings['message_types']),
        ];
    }

    /**
     * Dedicated lifecycle responses intentionally omit full phone numbers and credentials.
     *
     * @return array<string, mixed>
     */
    public function manualE2ELifecyclePayload(): array
    {
        $payload = $this->payload();

        return [
            'global' => Arr::only((array) $payload['global'], [
                'manual_e2e_enabled',
                'real_send_enabled',
                'queue_paused',
                'test_mode_enabled',
                'ops_whatsapp_enabled',
                'manual_e2e_phase',
                'manual_e2e_active_run_id',
                'manual_e2e_started_at',
                'manual_e2e_created_after',
                'manual_e2e_expires_at',
                'manual_e2e_last_run_id',
                'manual_e2e_last_stopped_at',
            ]),
            'manual_e2e' => $payload['manual_e2e'],
            'readiness' => Arr::only((array) $payload['readiness'], [
                'queue_ready',
                'manual_e2e_active',
                'manual_e2e_worker_command_ready',
                'manual_e2e_blocker_code',
                'can_send_real',
                'effective_mode',
                'disabled_reasons',
            ]),
        ];
    }

    /**
     * Dedicated execution-mode responses omit provider secrets and phone values.
     *
     * @return array<string, mixed>
     */
    public function executionModePayload(): array
    {
        return $this->executionModePayloadForSettings($this->settings(), true);
    }

    /**
     * @return array<string, mixed>
     */
    public function transitionExecutionMode(
        string $mode,
        string $reason,
        User $actor,
        int $expectedRevision,
        ?string $confirmation = null,
        ?string $correlationId = null,
    ): array {
        app(ExternalExecutionControlPlaneService::class)->transition(
            $mode,
            $reason,
            $actor,
            $expectedRevision,
            $confirmation,
            $correlationId,
        );

        return $this->executionModePayload();
    }

    /**
     * @return array<string, mixed>
     */
    public function executionModeSnapshot(?string $provider = null): array
    {
        $settings = $this->settings();
        $context = TechnicalServiceManualE2ERunContext::fromSettings($settings);
        $runSnapshot = is_array($settings['manual_e2e_run_snapshot'] ?? null)
            ? $settings['manual_e2e_run_snapshot']
            : [];
        $scopedSnapshot = $context->isActive() && $this->isScopedLocalUatSettings($settings)
            ? Arr::only($runSnapshot, [
                'scoped_local_uat_profile_id',
                'scoped_local_uat_profile_version',
                'scoped_local_uat_profile_fingerprint',
                'scoped_local_uat_security_fingerprint',
                'scoped_local_uat_capability_snapshots',
                'scoped_local_uat_production_ready',
                'scoped_local_uat_sandbox_payment',
                'scoped_local_uat_real_payment',
                'scoped_local_uat_ops_sms',
                'scoped_local_uat_run_id',
                'expected_body_token',
                'expected_body_token_fingerprint',
                'global_execution_mode',
                'global_execution_state',
                'global_execution_epoch',
                'global_execution_revision',
                'global_runtime_environment',
                'global_profile_fingerprint',
            ])
            : [];
        $localAcceptanceSnapshot = $this->localManualAcceptanceIsCurrent($settings)
            ? [
                'local_manual_acceptance_profile_id' => self::LOCAL_MANUAL_ACCEPTANCE_PROFILE,
                'local_manual_acceptance_profile_version' => self::LOCAL_MANUAL_ACCEPTANCE_PROFILE_VERSION,
                'local_manual_acceptance_activated_at' => $settings['local_manual_acceptance_activated_at'],
                'local_manual_acceptance_profile_fingerprint' => $settings['local_manual_acceptance_profile_fingerprint'],
            ]
            : [
                'local_manual_acceptance_profile_id' => null,
                'local_manual_acceptance_profile_version' => null,
                'local_manual_acceptance_activated_at' => null,
                'local_manual_acceptance_profile_fingerprint' => null,
            ];

        return [
            ...app(ExternalExecutionControlPlaneService::class)->messagingSnapshot($provider),
            ...$scopedSnapshot,
            ...$localAcceptanceSnapshot,
            'messaging_enabled' => (bool) ($settings['messaging_enabled'] ?? false),
        ];
    }

    /**
     * Bind the server-owned token only while rendering the three synthetic
     * messages authorized by the active scoped UAT profile.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function bindLockedScopedLocalUatRenderedBody(array $input): array
    {
        $settings = $this->settings();
        if (! $this->isScopedLocalUatSettings($settings)) {
            return $input;
        }

        $context = TechnicalServiceManualE2ERunContext::fromSettings($settings);
        $runId = $context->activeRunId();
        if (! $context->enabled() || $runId === null) {
            return $input;
        }

        $matrixKey = implode('|', [
            (string) ($input['message_type'] ?? $input['event'] ?? ''),
            (string) ($input['channel'] ?? ''),
            (string) ($input['recipient_role'] ?? $input['target_type'] ?? ''),
            (string) ($input['provider_key'] ?? ''),
        ]);
        $presentation = self::SCOPED_LOCAL_UAT_MESSAGE_BODY_MATRIX[$matrixKey] ?? null;
        if ($presentation === null || ($input['sample_context'] ?? null) !== false) {
            return $input;
        }

        $requestId = (int) ($input['technical_service_request_id'] ?? $input['request_id'] ?? 0);
        if (! $this->isSyntheticScopedLocalUatMessageRequest($requestId)) {
            return $input;
        }

        if ($this->scopedLocalUatTokenOverridePresent($input)) {
            throw ValidationException::withMessages([
                'expected_body_token' => 'Allowlistli Yerel UAT mesaj tokenı caller veya request tarafından değiştirilemez.',
            ]);
        }

        $runSnapshot = is_array($settings['manual_e2e_run_snapshot'] ?? null)
            ? $settings['manual_e2e_run_snapshot']
            : [];
        $contract = self::resolvedScopedLocalUatMessageBodyContract($runSnapshot, $runId);
        if ($context->contextBlockingReason() !== null || $contract === null) {
            throw new ConflictHttpException('Allowlistli Yerel UAT mesaj token snapshotı doğrulanamadı.');
        }

        $body = $input['rendered_body'] ?? null;
        if (! is_scalar($body) || trim((string) $body) === '') {
            throw ValidationException::withMessages([
                'rendered_body' => 'Allowlistli Yerel UAT mesaj gövdesi token bağlamak için hazır değil.',
            ]);
        }

        $body = rtrim((string) $body);
        $token = $contract['expected_body_token'];
        if (str_contains($body, $token)
            || preg_match('/(?:^|\R)UAT(?: doğrulama:)?\s+\S+/u', $body) === 1) {
            throw ValidationException::withMessages([
                'rendered_body' => 'Allowlistli Yerel UAT mesaj gövdesi caller-owned smoke token içeremez.',
            ]);
        }

        $tokenLine = $presentation === 'sms'
            ? 'UAT '.$token
            : 'UAT doğrulama: '.$token;

        return [
            ...$input,
            'rendered_body' => $body.($presentation === 'sms' ? "\n" : "\n\n").$tokenLine,
            'expected_body_token_fingerprint' => $contract['expected_body_token_fingerprint'],
            'expected_body_token_source' => 'locked_run_snapshot',
        ];
    }

    /**
     * The global control plane and messaging lifecycle share one lock domain.
     */
    public function withGlobalExecutionControlLock(callable $callback): mixed
    {
        $lock = $this->acquireLifecycleLock();

        try {
            return $callback();
        } finally {
            $lock();
        }
    }

    /**
     * @return array{active_run_id:string|null,redacted_diff:array<string, array{0:bool,1:bool}>}
     */
    public function applyGlobalExecutionModeWithinTransaction(string $mode): array
    {
        if (! self::$lifecycleLockHeldInProcess || DB::transactionLevel() === 0) {
            throw new ConflictHttpException('Global çalışma modu adapter geçişi authoritative lock ve transaction gerektirir.');
        }

        $locked = $this->lockedAuthoritativeSettings();
        $current = $locked['settings'];
        $context = TechnicalServiceManualE2ERunContext::fromSettings($current);
        $activeRunId = $context->activeRunId();
        $next = $current;
        if ($mode === ExternalExecutionControlPlaneService::MODE_LOCAL) {
            $next = $this->freezeManualE2EForExecutionMode($next, $context);
        } else {
            $next['manual_e2e_phase'] = self::MANUAL_E2E_PHASE_FROZEN;
            $next['manual_e2e_enabled'] = false;
            $next['manual_e2e_open_window'] = null;
            $next['manual_e2e_active_claim'] = null;
            $next['manual_e2e_active_run_id'] = null;
            $next['manual_e2e_started_at'] = null;
            $next['manual_e2e_created_after'] = null;
            $next['manual_e2e_expires_at'] = null;
            $next['test_mode_enabled'] = false;
            $next['real_send_enabled'] = $this->runtimeEnvironment() === 'production';
            $next['queue_paused'] = $this->runtimeEnvironment() !== 'production';
            $next['local_manual_acceptance_enabled'] = false;
            $next['local_manual_acceptance_activated_at'] = null;
            $next['local_manual_acceptance_profile_fingerprint'] = null;
            $next['local_manual_acceptance_reason'] = null;
        }

        if ($mode === ExternalExecutionControlPlaneService::MODE_LOCAL) {
            $this->assertLocalExecutionModeState($next);
        } else {
            $this->validateSettings($next);
        }
        $this->persistAuthoritativeSettings($locked, $next);

        return [
            'active_run_id' => $activeRunId,
            'redacted_diff' => [
                'real_send_enabled' => [(bool) $current['real_send_enabled'], (bool) $next['real_send_enabled']],
                'queue_paused' => [(bool) $current['queue_paused'], (bool) $next['queue_paused']],
                'manual_e2e_enabled' => [(bool) $current['manual_e2e_enabled'], (bool) $next['manual_e2e_enabled']],
            ],
        ];
    }

    public function invalidateGlobalExecutionRunLease(string $runId): bool
    {
        return $this->invalidateManualE2EWorkerLease($runId);
    }

    /**
     * @return array<string, array{ready:bool,blockers:array<int,string>,profile_fingerprint:string}>
     */
    public function globalExecutionCapabilityReadiness(): array
    {
        $settings = $this->settings();
        $production = $this->runtimeEnvironment() === 'production';
        $portalOrigins = $this->portalOriginReadiness($settings);
        $sharedBlockers = [];
        if (! (bool) ($settings['messaging_enabled'] ?? false)) {
            $sharedBlockers[] = 'messaging_disabled';
        }
        if (collect($settings['message_types'] ?? [])
            ->filter(fn (array $type): bool => (bool) ($type['enabled'] ?? false) && (bool) ($type['real_send_allowed'] ?? false))
            ->isEmpty()) {
            $sharedBlockers[] = 'message_type_not_ready';
        }

        if ($production) {
            if ($this->runtimeReleaseSha() === null) {
                $sharedBlockers[] = 'release_sha_missing';
            }
            if ((bool) config('app.debug', false)) {
                $sharedBlockers[] = 'app_debug_enabled';
            }
            if (! (bool) ($portalOrigins['live_public']['ready'] ?? false)) {
                $sharedBlockers[] = 'public_https_not_ready';
            }
            if (trim((string) (getenv('TRUSTED_PROXIES') ?: '')) === '') {
                $sharedBlockers[] = 'trusted_proxy_not_ready';
            }
            if (! (bool) config('session.secure', false)) {
                $sharedBlockers[] = 'secure_cookie_not_ready';
            }
            if (trim((string) config('session.domain', '')) === '') {
                $sharedBlockers[] = 'session_domain_not_ready';
            }
        } else {
            if (array_values(array_filter((array) ($settings['manual_e2e_allowlisted_phones'] ?? []))) === []) {
                $sharedBlockers[] = 'manual_e2e_allowlist_missing';
            }
            if (! (bool) ($portalOrigins['manual_e2e']['ready'] ?? false)
                && ! (bool) ($portalOrigins['live_public']['ready'] ?? false)) {
                $sharedBlockers[] = 'manual_e2e_origin_not_ready';
            }
        }

        $evoBlockers = $this->evoProviderReadyForLive($settings, $production)
            ? $sharedBlockers
            : [...$sharedBlockers, 'evo_not_ready'];
        $nacBlockers = $this->nacProviderReadyForLive($settings, $production)
            ? $sharedBlockers
            : [...$sharedBlockers, 'nac_not_ready'];

        return [
            ExternalEffectCapabilityRegistry::MESSAGING_EVOLUTION_SEND => [
                'ready' => $evoBlockers === [],
                'blockers' => $evoBlockers,
                'profile_fingerprint' => $this->messagingProfileFingerprint('evo_whatsapp', $settings),
            ],
            ExternalEffectCapabilityRegistry::MESSAGING_NAC_SEND => [
                'ready' => $nacBlockers === [],
                'blockers' => $nacBlockers,
                'profile_fingerprint' => $this->messagingProfileFingerprint('nac_sms', $settings),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function globalExecutionTransitionReadiness(bool $checkLifecycleLock = false): array
    {
        return $this->executionModeReadinessForSettings($this->settings(), $checkLifecycleLock);
    }

    /**
     * @return array<string, array{capability_revision:int,profile_fingerprint:string}>
     */
    public function globalExecutionCapabilityIdentities(): array
    {
        $settings = $this->settings();

        return [
            ExternalEffectCapabilityRegistry::MESSAGING_EVOLUTION_SEND => [
                'capability_revision' => 1,
                'profile_fingerprint' => $this->messagingProfileFingerprint('evo_whatsapp', $settings),
            ],
            ExternalEffectCapabilityRegistry::MESSAGING_NAC_SEND => [
                'capability_revision' => 1,
                'profile_fingerprint' => $this->messagingProfileFingerprint('nac_sms', $settings),
            ],
        ];
    }

    /**
     * Returns only redacted, deterministic control-plane inputs. It never
     * opens a run, queue, provider, payment, or mail transport.
     *
     * @return array<string, mixed>
     */
    public function scopedLocalUatControlPlaneState(bool $checkLifecycleLock = true): array
    {
        $settings = $this->settings();
        $profile = app(ExternalEffectCapabilityRegistry::class)->localAllowlistedUatProfile();
        $effectWindowContract = self::manualE2EEffectWindowContract((string) $profile['id']);
        $mailService = app(TechnicalServiceMailTransportSettingsService::class);
        $mail = $mailService->payload();
        $payment = app(TechnicalServicePaymentProviderSettingsService::class);
        $paymentPayload = $payment->payload();
        $paymentMode = $payment->providerMode();
        $fakePaymentReady = ! $payment->realProviderEnabled()
            && $payment->effectiveProvider() === 'fake'
            && $paymentMode === 'sandbox';
        $sandboxPaymentReady = $paymentMode === 'sandbox'
            && ($fakePaymentReady || $payment->providerSendReady('sandbox'));
        $sandboxPaymentProvider = $fakePaymentReady ? 'fake_payment' : 'iyzico_sandbox';

        $allowlist = array_values(array_unique(array_filter(array_map(
            fn (mixed $phone): string => $this->normalizePhone((string) $phone),
            (array) ($settings['manual_e2e_allowlisted_phones'] ?? []),
        ))));
        sort($allowlist);
        $opsPhone = $this->normalizePhone((string) ($settings['ops_whatsapp_phone'] ?? ''));
        $opsEnabled = (bool) ($settings['ops_whatsapp_enabled'] ?? false);
        $emails = array_values(array_unique(array_filter(array_map(
            static fn (mixed $email): string => strtolower(trim((string) $email)),
            $payment->paymentNotificationRecipients(),
        ), static fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)));
        sort($emails);

        $eventBlockers = [];
        $eventPolicy = [];
        foreach ((array) $profile['messaging_events'] as $event => $providerChannels) {
            $type = is_array($settings['message_types'][$event] ?? null)
                ? $settings['message_types'][$event]
                : [];
            $eventPolicy[$event] = [
                'enabled' => (bool) ($type['enabled'] ?? false),
                'real_send_allowed' => (bool) ($type['real_send_allowed'] ?? false),
                'channel_policy' => (string) ($type['channel_policy'] ?? 'disabled'),
                'whatsapp_provider' => (string) ($type['whatsapp_provider'] ?? ''),
                'sms_provider' => (string) ($type['sms_provider'] ?? ''),
            ];
            if (! $eventPolicy[$event]['enabled'] || ! $eventPolicy[$event]['real_send_allowed']) {
                $eventBlockers[] = [
                    'code' => 'scoped_uat_event_not_ready:'.$event,
                    'message' => $event.' allowlistli Yerel UAT için etkin ve gerçek-send izinli olmalı.',
                ];

                continue;
            }
            foreach ((array) $providerChannels as $channel => $provider) {
                if (! $this->messageTypeAllowsChannel($type, (string) $channel, (string) $provider)) {
                    $eventBlockers[] = [
                        'code' => 'scoped_uat_event_channel_not_ready:'.$event.':'.$channel,
                        'message' => $event.' '.$channel.' kanalı code-owned UAT profiliyle eşleşmiyor.',
                    ];
                }
            }
        }
        ksort($eventPolicy);

        $evoReady = (bool) ($settings['messaging_enabled'] ?? false)
            && $this->evoProviderReadyForLive($settings, false);
        $nacReady = (bool) ($settings['messaging_enabled'] ?? false)
            && $this->nacProviderReadyForLive($settings, false);
        $smtpReady = (bool) data_get($mail, 'outgoing.ready', false)
            && $payment->paymentNotificationEnabled();
        $smtpConfigurationFingerprint = $mailService->scopedLocalUatConfigurationFingerprint();
        $paymentConfigurationFingerprint = $this->scopedLocalUatPaymentConfigurationFingerprint($paymentPayload);
        $capabilities = [
            ExternalEffectCapabilityRegistry::MESSAGING_EVOLUTION_SEND => [
                'ready' => $evoReady,
                'blockers' => $evoReady ? [] : ['evo_not_ready'],
                'capability_revision' => 1,
                'profile_fingerprint' => $this->messagingProfileFingerprint('evo_whatsapp', $settings),
            ],
            ExternalEffectCapabilityRegistry::MESSAGING_NAC_SEND => [
                'ready' => $nacReady,
                'blockers' => $nacReady ? [] : ['nac_not_ready'],
                'capability_revision' => 1,
                'profile_fingerprint' => $this->messagingProfileFingerprint('nac_sms', $settings),
            ],
            ExternalEffectCapabilityRegistry::MAIL_SMTP_SEND => [
                'ready' => $smtpReady,
                'blockers' => $smtpReady ? [] : ['smtp_not_ready'],
                'capability_revision' => 1,
                'profile_fingerprint' => hash('sha256', json_encode([
                    'configuration_fingerprint' => $smtpConfigurationFingerprint,
                    'notification_enabled' => $payment->paymentNotificationEnabled(),
                    'recipient_allowlist_fingerprint' => hash('sha256', implode('|', $emails)),
                    'event_revision' => 'sandbox_payment_notification:v1',
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            ],
            ExternalEffectCapabilityRegistry::PAYMENT_LOCAL_SANDBOX_EXECUTE => [
                'ready' => $sandboxPaymentReady,
                'blockers' => $sandboxPaymentReady ? [] : ['sandbox_payment_not_ready'],
                'capability_revision' => 1,
                'profile_fingerprint' => $paymentConfigurationFingerprint,
            ],
        ];

        $pendingStatuses = [
            TechnicalServiceMessageDispatch::STATUS_QUEUED,
            TechnicalServiceMessageDispatch::STATUS_SENDING,
            TechnicalServiceMessageDispatch::STATUS_RATE_LIMITED,
        ];
        $pending = $this->pendingExternalDispatchCount();
        $unsafe = $this->unsafeExternalDispatchCount();
        $nonAllowlistedPending = TechnicalServiceMessageDispatch::query()
            ->whereIn('provider_key', ['evo_whatsapp', 'nac_sms'])
            ->whereIn('status', $pendingStatuses)
            ->whereNotIn('target_phone', $allowlist ?: ['__scoped_uat_allowlist_missing__'])
            ->count();
        $duplicatePending = TechnicalServiceMessageDispatch::query()
            ->whereIn('provider_key', ['evo_whatsapp', 'nac_sms'])
            ->whereIn('status', $pendingStatuses)
            ->whereNotNull('idempotency_key')
            ->select('idempotency_key')
            ->groupBy('idempotency_key')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();
        $manualWorker = $this->manualE2EWorkerLeaseStatus();
        $broadWorker = $this->outboundWorkerLeaseStatus();
        $context = TechnicalServiceManualE2ERunContext::fromSettings($settings);
        $portal = $this->portalOriginReadiness($settings);
        $mikro = (array) ($settings['mikro_api'] ?? []);
        $ttl = max(60, min(
            self::SCOPED_LOCAL_UAT_MAX_TTL_SECONDS,
            (int) ($settings['manual_e2e_ttl_seconds'] ?? self::SCOPED_LOCAL_UAT_MAX_TTL_SECONDS),
        ));
        $invariantBlockers = $eventBlockers;
        $addBlocker = static function (array &$target, bool $blocked, string $code, string $message): void {
            if ($blocked) {
                $target[] = ['code' => $code, 'message' => $message];
            }
        };
        $addBlocker($invariantBlockers, count($allowlist) !== 2 || collect($allowlist)->contains(fn (string $phone): bool => ! $this->validPhone($phone)), 'scoped_uat_phone_allowlist_invalid', 'Allowlistli Yerel UAT için exact iki geçerli telefon zorunlu.');
        $addBlocker($invariantBlockers, $opsEnabled && ($opsPhone === '' || ! in_array($opsPhone, $allowlist, true)), 'scoped_uat_ops_target_invalid', 'OPS WhatsApp hedefi exact telefon allowlisti içinde olmalı.');
        $addBlocker($invariantBlockers, count($emails) !== 1, 'scoped_uat_email_allowlist_invalid', 'Allowlistli Yerel UAT için tek geçerli e-posta alıcısı zorunlu.');
        $addBlocker($invariantBlockers, $pending > 0, 'scoped_uat_pending_dispatch', 'Scoped run öncesinde pending provider dispatch sıfır olmalı.');
        $addBlocker($invariantBlockers, $unsafe > 0, 'scoped_uat_unsafe_dispatch', 'Scoped run öncesinde unsafe provider dispatch sıfır olmalı.');
        $addBlocker($invariantBlockers, $nonAllowlistedPending > 0, 'scoped_uat_non_allowlisted_pending', 'Allowlist dışı pending dispatch bulundu.');
        $addBlocker($invariantBlockers, $duplicatePending > 0, 'scoped_uat_duplicate_pending', 'Duplicate pending dispatch bulundu.');
        $addBlocker($invariantBlockers, ($manualWorker['state'] ?? 'none') !== 'none', 'scoped_uat_manual_worker_present', 'Scoped run öncesinde Manual E2E worker bulunmamalı.');
        $addBlocker($invariantBlockers, ($broadWorker['state'] ?? 'none') !== 'none', 'scoped_uat_broad_worker_present', 'Scoped run öncesinde broad outbound worker bulunmamalı.');
        $addBlocker($invariantBlockers, ! $this->lockAvailable(TechnicalServiceManualE2ERunContext::WORKER_LOCK_KEY), 'scoped_uat_worker_lock_busy', 'Scoped worker lock alınamıyor.');
        if ($checkLifecycleLock) {
            $addBlocker($invariantBlockers, ! $this->lifecycleLockAvailable(), 'scoped_uat_lifecycle_lock_busy', 'Scoped lifecycle lock alınamıyor.');
        }
        $addBlocker($invariantBlockers, $context->enabled() || $context->activeRunId() !== null, 'scoped_uat_active_run_exists', 'Önce mevcut Manual E2E run dondurulmalı.');
        $addBlocker($invariantBlockers, $context->phase() !== self::MANUAL_E2E_PHASE_FROZEN, 'scoped_uat_not_frozen', 'Manual E2E lifecycle frozen olmalı.');
        $addBlocker($invariantBlockers, (bool) ($settings['real_send_enabled'] ?? false), 'scoped_uat_real_send_open', 'Real-send gate scoped run öncesinde kapalı olmalı.');
        $addBlocker($invariantBlockers, ! (bool) ($settings['queue_paused'] ?? true), 'scoped_uat_queue_open', 'Queue scoped run öncesinde paused olmalı.');
        $addBlocker($invariantBlockers, is_array($settings['normal_outbound_active_claim'] ?? null), 'scoped_uat_normal_claim_present', 'Normal outbound claim çözülmeden scoped run açılamaz.');
        $addBlocker($invariantBlockers, ! (bool) data_get($portal, 'manual_e2e.ready', false), 'scoped_uat_local_origin_not_ready', 'RFC1918 LAN origin allowlistli Yerel UAT için zorunlu.');
        $addBlocker($invariantBlockers, (bool) ($mikro['enabled'] ?? false) || (bool) ($mikro['read_sync_enabled'] ?? false) || (bool) ($mikro['write_enabled'] ?? false), 'scoped_uat_mikro_switch_open', 'Mikro active/read-sync/write scoped UAT boyunca kapalı kalmalı.');
        $addBlocker($invariantBlockers, $paymentMode !== 'sandbox', 'scoped_uat_real_payment_mode', 'Scoped UAT yalnız sandbox/fake ödeme modunu kabul eder.');
        $addBlocker($invariantBlockers, (int) ($settings['hourly_limit'] ?? 0) < (int) data_get($profile, 'limits.total', 8) || (int) ($settings['daily_limit'] ?? 0) < (int) data_get($profile, 'limits.total', 8), 'scoped_uat_rate_limit_invalid', 'Messaging rate limitleri scoped UAT üst sınırını güvenle taşımalı.');

        $mikroInvariant = $this->scopedLocalUatMikroInvariant($mikro);
        $securityProfile = [
            'profile_fingerprint' => $profile['profile_fingerprint'],
            'phone_allowlist_fingerprint' => $this->allowlistFingerprint($allowlist),
            'ops_phone_fingerprint' => $opsPhone === '' ? null : hash('sha256', $opsPhone),
            'email_allowlist_fingerprint' => hash('sha256', implode('|', $emails)),
            'event_policy' => $eventPolicy,
            'portal_origin_fingerprint' => (string) data_get($portal, 'manual_e2e.profile_fingerprint', ''),
            'mikro_invariant' => $mikroInvariant,
            'ttl_seconds' => $ttl,
            'effect_window_seconds' => $effectWindowContract['effect_window_seconds'],
            'effect_window_fingerprint' => $effectWindowContract['effect_window_fingerprint'],
            'payment_provider' => $sandboxPaymentProvider,
            'payment_mode' => $paymentMode,
            'smtp_configuration_fingerprint' => $smtpConfigurationFingerprint,
            'payment_configuration_fingerprint' => $paymentConfigurationFingerprint,
        ];

        return [
            'capabilities' => $capabilities,
            'invariant_blockers' => $invariantBlockers,
            'security_fingerprint' => hash('sha256', json_encode($securityProfile, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'phone_allowlist_fingerprint' => $securityProfile['phone_allowlist_fingerprint'],
            'phone_allowlist_count' => count($allowlist),
            'phone_allowlist_masks' => array_map(fn (string $phone): string => $this->maskPhone($phone), $allowlist),
            'email_allowlist_fingerprint' => $securityProfile['email_allowlist_fingerprint'],
            'email_allowlist_count' => count($emails),
            'email_allowlist_masks' => array_map(fn (string $email): string => $this->maskEmail($email), $emails),
            'event_policy' => $eventPolicy,
            'event_policy_fingerprint' => hash('sha256', json_encode($eventPolicy, JSON_THROW_ON_ERROR)),
            'evo_ready' => $evoReady,
            'nac_ready' => $nacReady,
            'smtp_ready' => $smtpReady,
            'sandbox_payment_ready' => $sandboxPaymentReady,
            'sandbox_payment_provider' => $sandboxPaymentProvider,
            'pending_external_count' => $pending,
            'unsafe_external_count' => $unsafe,
            'non_allowlisted_pending_count' => $nonAllowlistedPending,
            'duplicate_pending_count' => $duplicatePending,
            'manual_worker_state' => (string) ($manualWorker['state'] ?? 'none'),
            'broad_worker_state' => (string) ($broadWorker['state'] ?? 'none'),
            'ttl_seconds' => $ttl,
            'effect_window_seconds' => $effectWindowContract['effect_window_seconds'],
            'effect_window_fingerprint' => $effectWindowContract['effect_window_fingerprint'],
            'portal_origins' => $portal,
        ];
    }

    /**
     * @return array{allowed:bool,code:string|null,message:string|null}
     */
    public function dispatchExecutionAuthorization(
        TechnicalServiceMessageDispatch $dispatch,
        bool $manualE2E,
        ?string $outboundWorkerOwner = null,
    ): array {
        return $this->dispatchExecutionAuthorizationForSettings(
            $dispatch,
            $manualE2E,
            $this->settings(),
            $outboundWorkerOwner,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{allowed:bool,code:string|null,message:string|null}
     */
    public function outboundSnapshotAuthorization(string $provider, array $metadata): array
    {
        $capabilityCode = app(ExternalExecutionControlPlaneService::class)
            ->messagingCapabilityCode($provider);
        if ($capabilityCode === null) {
            return $this->executionBlock('external_capability_unknown', 'Dispatch provider capability registry içinde kayıtlı değil.');
        }

        $authorization = array_key_exists('scoped_local_uat_profile_id', $metadata)
            ? app(ExternalExecutionControlPlaneService::class)
                ->authorizeScopedLocalUatCapabilitySnapshot($capabilityCode, $metadata)
            : app(ExternalExecutionControlPlaneService::class)
                ->authorizeCapabilitySnapshot($capabilityCode, $metadata);
        if ($authorization['allowed']) {
            return $authorization;
        }

        $blockCode = match ($authorization['code'] ?? null) {
            'global_execution_mode_local' => 'outbound_execution_mode_local',
            'global_execution_snapshot_stale' => 'outbound_mode_revision_stale',
            'external_capability_not_ready' => 'outbound_provider_set_not_ready',
            default => (string) ($authorization['code'] ?? 'external_execution_control_blocked'),
        };

        return $this->executionBlock(
            $blockCode,
            (string) ($authorization['message'] ?? 'Global execution snapshot current state ile eşleşmiyor.'),
        );
    }

    /**
     * Authorize creation of an external dispatch. Normal local business
     * messages use the stored Admin authority; scoped UAT messages retain
     * their immutable run snapshot contract.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{allowed:bool,code:string|null,message:string|null}
     */
    public function outboundQueueAuthorization(
        string $provider,
        string $channel,
        string $messageType,
        string $targetPhone,
        array $metadata,
        string $body = '',
    ): array {
        $settings = $this->settings();
        if ($this->executionMode($settings) === self::OUTBOUND_EXECUTION_MODE_LOCAL
            && ! $this->metadataHasManualE2EScope($metadata)) {
            return $this->normalLocalDispatchAuthorization(
                $provider,
                $channel,
                $messageType,
                $targetPhone,
                $body,
                $metadata,
                $settings,
            );
        }

        return $this->outboundSnapshotAuthorization($provider, $metadata);
    }

    /**
     * Internal authorization for the non-messaging actions carried by the
     * immutable scoped profile. No controller accepts these scope fields.
     *
     * @return array{allowed:bool,code:string|null,message:string|null}
     */
    public function scopedLocalUatActionAuthorization(
        string $capabilityCode,
        string $event,
        string $channel,
        string $provider,
        ?string $recipient = null,
        ?string $recipientRole = null,
        ?string $payloadUrl = null,
    ): array {
        $settings = $this->settings();
        $context = TechnicalServiceManualE2ERunContext::fromSettings($settings);
        $snapshot = is_array($settings['manual_e2e_run_snapshot'] ?? null)
            ? $settings['manual_e2e_run_snapshot']
            : [];
        if (! $context->isActive() || ! $this->isScopedLocalUatSettings($settings)) {
            return $this->executionBlock('scoped_uat_active_run_missing', 'Aktif allowlistli Yerel UAT run bulunamadı.');
        }
        if (self::resolvedManualE2EEffectWindowContract($snapshot) === null) {
            return $this->executionBlock('scoped_uat_snapshot_stale', 'Allowlistli Yerel UAT effect-window snapshotı geçersiz.');
        }

        $authorization = app(ExternalExecutionControlPlaneService::class)
            ->authorizeScopedLocalUatCapabilitySnapshot($capabilityCode, $snapshot);
        if (! $authorization['allowed']) {
            return $authorization;
        }

        $profile = app(ExternalEffectCapabilityRegistry::class)->localAllowlistedUatProfile();
        $expectedProvider = data_get($profile, 'messaging_events.'.$event.'.'.$channel);
        if (! is_string($expectedProvider)) {
            $action = is_array($profile['action_events'][$event] ?? null)
                ? $profile['action_events'][$event]
                : [];
            $expectedProvider = (string) ($action['channel'] ?? '') === $channel
                && in_array($provider, (array) ($action['providers'] ?? []), true)
                    ? $provider
                    : null;
        }
        $expectedCapability = match ($channel) {
            'whatsapp' => ExternalEffectCapabilityRegistry::MESSAGING_EVOLUTION_SEND,
            'sms' => ExternalEffectCapabilityRegistry::MESSAGING_NAC_SEND,
            'email' => ExternalEffectCapabilityRegistry::MAIL_SMTP_SEND,
            'sandbox_payment' => ExternalEffectCapabilityRegistry::PAYMENT_LOCAL_SANDBOX_EXECUTE,
            default => null,
        };
        if ($expectedProvider !== $provider || $expectedCapability !== $capabilityCode) {
            return $this->executionBlock('FAIL_CLOSED_UNAUTHORIZED_CAPABILITY', 'Event, kanal, provider veya capability scoped profile içinde izinli değil.');
        }
        if ($channel === 'sms' && $recipientRole === 'ops') {
            return $this->executionBlock('FAIL_CLOSED_UNAUTHORIZED_CAPABILITY', 'OPS SMS scoped profile içinde kapalıdır.');
        }

        if (in_array($channel, ['whatsapp', 'sms'], true)
            && ! in_array($this->normalizePhone((string) $recipient), $context->allowlistedPhones(), true)) {
            return $this->executionBlock('scoped_uat_recipient_not_allowlisted', 'Telefon alıcısı provider çağrısından önce reddedildi.');
        }
        if ($channel === 'email') {
            $allowedEmails = array_values(array_unique(array_filter(array_map(
                static fn (mixed $email): string => strtolower(trim((string) $email)),
                app(TechnicalServicePaymentProviderSettingsService::class)->paymentNotificationRecipients(),
            ), static fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)));
            if (! in_array(strtolower(trim((string) $recipient)), $allowedEmails, true)) {
                return $this->executionBlock('scoped_uat_email_not_allowlisted', 'E-posta alıcısı transport çağrısından önce reddedildi.');
            }
        }
        if ($payloadUrl !== null && ! $this->scopedLocalUatBodyUrlsAreSafe($payloadUrl, $settings)) {
            return $this->executionBlock('scoped_uat_payload_origin_invalid', 'Payload linki exact LAN origin ile eşleşmiyor.');
        }

        return ['allowed' => true, 'code' => null, 'message' => null];
    }

    /** Resolve the immutable provider family and mode through one code-owned contract. */
    public function canonicalScopedLocalUatProviderIdentity(
        string $providerFamily,
        string $providerMode,
    ): string {
        $providerValue = strtolower(trim($providerFamily));
        $modeValue = strtolower(trim($providerMode));
        $family = match ($providerValue) {
            'fake', 'fake_payment' => 'fake',
            'iyzico', 'iyzico_sandbox', 'iyzico_live' => 'iyzico',
            default => throw new ConflictHttpException('scoped_uat_provider_identity_invalid: Provider family authority canonical değil.'),
        };
        $mode = match ($modeValue) {
            'local', 'sandbox', 'live' => $modeValue,
            default => throw new ConflictHttpException('scoped_uat_provider_identity_invalid: Provider mode authority canonical değil.'),
        };
        $identity = match ([$family, $mode]) {
            ['fake', 'local'] => 'fake_payment',
            ['iyzico', 'sandbox'] => 'iyzico_sandbox',
            ['iyzico', 'live'] => 'iyzico_live',
            default => throw new ConflictHttpException('scoped_uat_provider_identity_invalid: Provider family ve mode authority canonical değil.'),
        };
        $embeddedIdentity = match ($providerValue) {
            'fake_payment' => 'fake_payment',
            'iyzico_sandbox' => 'iyzico_sandbox',
            'iyzico_live' => 'iyzico_live',
            default => null,
        };
        if ($embeddedIdentity !== null && ! hash_equals($embeddedIdentity, $identity)) {
            throw new ConflictHttpException('scoped_uat_provider_identity_invalid: Composite provider identity ve mode authority eşleşmiyor.');
        }

        return $identity;
    }

    /**
     * @return array{required:bool,duplicate:bool,claim_nonce:string|null,duplicate_payment_id:int|null,outcome:string|null}
     */
    public function claimScopedLocalUatEmailEffect(
        TechnicalServiceMountPayment $payment,
        array $recipients,
    ): array {
        return $this->claimScopedLocalUatEffect(
            $payment,
            ExternalEffectCapabilityRegistry::MAIL_SMTP_SEND,
            'sandbox_payment_notification',
            'email',
            'smtp',
            'sandbox_payment_notification',
            $recipients,
        );
    }

    /**
     * @return array{required:bool,duplicate:bool,claim_nonce:string|null,duplicate_payment_id:int|null,outcome:string|null}
     */
    public function claimScopedLocalUatSandboxPaymentEffect(
        TechnicalServiceMountPayment $payment,
        string $operation,
        string $providerFamily,
        string $providerMode,
    ): array {
        if ($operation !== self::SCOPED_EFFECT_PAYMENT_CREATE) {
            throw new ConflictHttpException('FAIL_CLOSED_UNAUTHORIZED_CAPABILITY: Scoped UAT ödeme operation izni yok.');
        }

        $providerIdentity = $this->canonicalScopedLocalUatProviderIdentity($providerFamily, $providerMode);

        return $this->claimScopedLocalUatEffect(
            $payment,
            ExternalEffectCapabilityRegistry::PAYMENT_LOCAL_SANDBOX_EXECUTE,
            'sandbox_payment',
            'sandbox_payment',
            $providerIdentity,
            $operation,
            null,
            [],
            [
                'family' => strtolower(trim($providerFamily)),
                'mode' => strtolower(trim($providerMode)),
            ],
        );
    }

    /**
     * Callback authority is resolved from the completed stored session, never
     * from request-controlled run or provider values.
     *
     * @param  array<string, mixed>  $callbackPayload
     * @return array{required:bool,duplicate:bool,claim_nonce:string|null,duplicate_payment_id:int|null,outcome:string|null}
     */
    public function claimScopedLocalUatSandboxPaymentCallbackEffect(
        TechnicalServiceMountPayment $payment,
        array $callbackPayload = [],
    ): array {
        return $this->claimScopedLocalUatEffect(
            $payment,
            ExternalEffectCapabilityRegistry::PAYMENT_LOCAL_SANDBOX_EXECUTE,
            'sandbox_payment_callback',
            'sandbox_payment',
            null,
            self::SCOPED_EFFECT_PAYMENT_CALLBACK,
            null,
            $callbackPayload,
        );
    }

    public function canonicalScopedLocalUatPaymentForPresentation(
        TechnicalServiceMountPayment $payment,
    ): TechnicalServiceMountPayment {
        return DB::transaction(function () use ($payment): TechnicalServiceMountPayment {
            $lockedPayment = TechnicalServiceMountPayment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            return $this->lockedScopedLocalUatCanonicalCallbackPayment($lockedPayment);
        });
    }

    /** @return array{entity_type:string,entity_id:int,payment_purpose:string,identity_hash:string,part_request_fingerprint:string|null,selected_serials_fingerprint:string|null,selected_serial_count:int} */
    public function canonicalPaymentBusinessIdentity(TechnicalServiceMountPayment $payment): array
    {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];

        return $this->scopedLocalUatPaymentBusinessIdentity($payment, $payload);
    }

    public function canonicalPaymentAmountMinorUnits(TechnicalServiceMountPayment $payment): string
    {
        return $this->scopedLocalUatAmountMinorUnits($payment);
    }

    public function canonicalPaymentCurrency(TechnicalServiceMountPayment $payment): string
    {
        return $this->scopedLocalUatCurrency($payment);
    }

    public function scopedLocalUatPaymentSessionIsCurrent(TechnicalServiceMountPayment $payment): bool
    {
        try {
            $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
            $authority = $payload['scoped_local_uat_payment_session_authority'] ?? null;
            if (! is_array($authority)) {
                return false;
            }
            $settings = $this->settings();
            $context = TechnicalServiceManualE2ERunContext::fromSettings($settings);
            if (! $context->isActive()
                || ! $this->isScopedLocalUatSettings($settings)
                || (string) ($authority['run_id'] ?? '') !== (string) $context->activeRunId()) {
                return false;
            }
            $this->assertScopedLocalUatStoredPaymentSessionAuthority(
                $settings,
                [
                    ...$authority,
                    'callback_submission' => [],
                ],
                $payment,
                $payload,
            );

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function assertScopedLocalUatPaymentReconciliationAllowed(
        TechnicalServiceMountPayment $payment,
    ): void {
        $settings = $this->settings();
        $context = TechnicalServiceManualE2ERunContext::fromSettings($settings);
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $tagged = filter_var(
            data_get($payload, 'scoped_local_uat.synthetic_uat', $payload['synthetic_uat'] ?? false),
            FILTER_VALIDATE_BOOL,
        );
        $activeScopedRun = $context->enabled()
            && $context->activeRunId() !== null
            && $this->isScopedLocalUatSettings($settings);
        if (! $tagged && ! $activeScopedRun) {
            return;
        }
        if (! $tagged || ! $activeScopedRun || ! $context->isActive()
            || ! $this->scopedLocalUatPaymentSessionIsCurrent($payment)) {
            throw new ConflictHttpException('scoped_uat_callback_session_authority_missing: Payment reconciliation exact current stored session authority gerektirir.');
        }

        $provider = $this->scopedLocalUatCanonicalPaymentProvider($payment);
        $origin = (string) data_get($payload, 'scoped_local_uat.origin', $payload['scoped_local_uat_origin'] ?? '');
        if ($provider === null) {
            throw new ConflictHttpException('scoped_uat_provider_snapshot_invalid: Reconciliation provider authority çözülemedi.');
        }
        $authorization = $this->scopedLocalUatActionAuthorization(
            ExternalEffectCapabilityRegistry::PAYMENT_LOCAL_SANDBOX_EXECUTE,
            'sandbox_payment_callback',
            'sandbox_payment',
            $provider,
            null,
            null,
            $origin,
        );
        if (! $authorization['allowed']) {
            throw new ConflictHttpException((string) $authorization['code'].': '.(string) $authorization['message']);
        }
    }

    public function assertScopedLocalUatUnsupportedPaymentEffect(
        TechnicalServiceMountPayment $payment,
        string $operation,
    ): void {
        $settings = $this->settings();
        $context = TechnicalServiceManualE2ERunContext::fromSettings($settings);
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $tagged = filter_var(
            data_get($payload, 'scoped_local_uat.synthetic_uat', $payload['synthetic_uat'] ?? false),
            FILTER_VALIDATE_BOOL,
        );
        $activeScopedRun = $context->enabled()
            && $context->activeRunId() !== null
            && $this->isScopedLocalUatSettings($settings);

        if ($tagged || $activeScopedRun) {
            throw new ConflictHttpException('FAIL_CLOSED_UNAUTHORIZED_CAPABILITY: '.$operation.' scoped UAT ödeme profiline ait değil.');
        }
    }

    public function completeScopedLocalUatEffect(string $claimNonce): void
    {
        $this->finalizeScopedLocalUatEffect($claimNonce, true);
    }

    public function failScopedLocalUatEffect(string $claimNonce, ?Throwable $exception = null): void
    {
        $this->finalizeScopedLocalUatEffect($claimNonce, false, $exception);
    }

    public function beginScopedLocalUatEffectDispatch(string $claimNonce): void
    {
        $lock = $this->acquireLifecycleLock();

        try {
            DB::transaction(function () use ($claimNonce): void {
                $locked = $this->lockedAuthoritativeSettings();
                $settings = $locked['settings'];
                [$claim, $payment] = $this->lockedScopedLocalUatEffectClaim($settings, $claimNonce);
                $claim = $this->validatedScopedLocalUatDispatchingClaim($settings, $claim, $payment);

                $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
                $payload['scoped_local_uat_effect_claim'] = $claim;
                $payment->forceFill(['raw_payload' => $payload])->save();
                $settings['scoped_local_uat_active_effect_claim'] = $claim;
                $this->validateSettings($settings);
                $this->persistAuthoritativeSettings($locked, $settings);
            });
        } finally {
            $lock();
        }
    }

    /**
     * The callback's authority validation, dispatch transition and paid-state
     * mutation share one transaction and the same lock used by freeze.
     *
     * @template T
     *
     * @param  callable(TechnicalServiceMountPayment): T  $callback
     * @return T
     */
    public function executeScopedLocalUatPaymentCallback(
        string $claimNonce,
        callable $callback,
    ): mixed {
        $lock = $this->acquireLifecycleLock();

        try {
            return DB::transaction(function () use ($claimNonce, $callback): mixed {
                $locked = $this->lockedAuthoritativeSettings();
                $settings = $locked['settings'];
                [$claim, $payment] = $this->lockedScopedLocalUatEffectClaim($settings, $claimNonce);
                if ((string) ($claim['operation'] ?? '') !== self::SCOPED_EFFECT_PAYMENT_CALLBACK) {
                    throw new ConflictHttpException('scoped_uat_callback_authority_invalid: Claim callback operationına ait değil.');
                }

                $claim = $this->validatedScopedLocalUatDispatchingClaim($settings, $claim, $payment);
                $result = $callback($payment);
                $this->finalizeScopedLocalUatEffectWithinTransaction($locked, $settings, $claim, true);

                return $result;
            });
        } finally {
            $lock();
        }
    }

    /**
     * External SMTP/payment settings share the same lifecycle lock as run
     * creation so a configuration write cannot race the immutable snapshot.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withScopedLocalUatConfigurationMutationLock(string $scope, callable $callback): mixed
    {
        if (! in_array($scope, ['smtp', 'payment', 'payment_credentials'], true)) {
            throw new ConflictHttpException('Bilinmeyen scoped UAT configuration mutation kapsamı.');
        }

        $lock = $this->acquireLifecycleLock();

        try {
            return DB::transaction(function () use ($callback): mixed {
                $this->assertNoActiveRunMutation();

                return $callback();
            });
        } finally {
            $lock();
        }
    }

    public function assertScopedLocalUatSmtpTestAllowed(): void
    {
        $settings = $this->settings();
        $context = TechnicalServiceManualE2ERunContext::fromSettings($settings);
        if ($context->enabled() && $context->activeRunId() !== null && $this->isScopedLocalUatSettings($settings)) {
            throw new ConflictHttpException('FAIL_CLOSED_UNAUTHORIZED_CAPABILITY: Scoped UAT sırasında yalnız ödeme bildirim e-postası gönderilebilir.');
        }
    }

    /**
     * @param  array<string, mixed>  $callbackPayload
     * @return array{required:bool,duplicate:bool,claim_nonce:string|null,duplicate_payment_id:int|null,outcome:string|null}
     */
    private function claimScopedLocalUatEffect(
        TechnicalServiceMountPayment $payment,
        string $capability,
        string $event,
        string $channel,
        ?string $provider,
        string $operation,
        string|array|null $recipient = null,
        array $callbackPayload = [],
        ?array $providerAuthority = null,
    ): array {
        $lock = $this->acquireLifecycleLock();

        try {
            return DB::transaction(function () use ($payment, $capability, $event, $channel, $provider, $operation, $recipient, $callbackPayload, $providerAuthority): array {
                $locked = $this->lockedAuthoritativeSettings();
                $settings = $locked['settings'];
                $context = TechnicalServiceManualE2ERunContext::fromSettings($settings);
                $lockedPayment = TechnicalServiceMountPayment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
                $payload = is_array($lockedPayment->raw_payload) ? $lockedPayment->raw_payload : [];
                $tagged = filter_var(
                    data_get($payload, 'scoped_local_uat.synthetic_uat', $payload['synthetic_uat'] ?? false),
                    FILTER_VALIDATE_BOOL,
                );
                $activeScopedRun = $context->enabled()
                    && $context->activeRunId() !== null
                    && $this->isScopedLocalUatSettings($settings);

                if (! $tagged && ! $activeScopedRun) {
                    return [
                        'required' => false,
                        'duplicate' => false,
                        'claim_nonce' => null,
                        'duplicate_payment_id' => null,
                        'outcome' => null,
                    ];
                }
                if (! $tagged || ! $activeScopedRun || ! $context->isActive()) {
                    throw new ConflictHttpException('scoped_uat_active_run_missing: Effect exact aktif synthetic UAT run ile bağlı değil.');
                }
                if ($operation === self::SCOPED_EFFECT_PAYMENT_CALLBACK) {
                    $lockedPayment = $this->lockedScopedLocalUatCanonicalCallbackPayment($lockedPayment);
                    $payload = is_array($lockedPayment->raw_payload) ? $lockedPayment->raw_payload : [];
                    $tagged = filter_var(
                        data_get($payload, 'scoped_local_uat.synthetic_uat', $payload['synthetic_uat'] ?? false),
                        FILTER_VALIDATE_BOOL,
                    );
                    if (! $tagged) {
                        throw new ConflictHttpException('scoped_uat_callback_session_authority_missing: Canonical payment synthetic UAT authority taşımıyor.');
                    }
                }
                if ($channel === 'email') {
                    $submittedRecipients = is_array($recipient) ? $recipient : [$recipient];
                    $normalizedRecipients = array_values(array_unique(array_filter(array_map(
                        static fn (mixed $email): string => strtolower(trim((string) $email)),
                        $submittedRecipients,
                    ), static fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)));
                    if (count($normalizedRecipients) !== 1 || count($normalizedRecipients) !== count($submittedRecipients)) {
                        throw new ConflictHttpException('scoped_uat_email_not_allowlisted: Scoped UAT e-posta effecti exact tek allowlisted alıcı gerektirir.');
                    }
                    $recipient = $normalizedRecipients[0];
                }
                if ($context->phase() !== self::MANUAL_E2E_PHASE_PREPARED
                    || is_array($settings['manual_e2e_open_window'] ?? null)
                    || is_array($settings['manual_e2e_active_claim'] ?? null)) {
                    throw new ConflictHttpException('scoped_uat_effect_claim_busy: Başka bir scoped effect veya dispatch penceresi aktif.');
                }

                $runId = (string) data_get($payload, 'scoped_local_uat.run_id', $payload['scoped_local_uat_run_id'] ?? '');
                $origin = (string) data_get($payload, 'scoped_local_uat.origin', $payload['scoped_local_uat_origin'] ?? '');
                if ($runId === '' || $runId !== $context->activeRunId()) {
                    throw new ConflictHttpException('scoped_uat_wrong_run_id: Effect aktif run kimliğiyle eşleşmiyor.');
                }
                if ($lockedPayment->created_at === null
                    || $context->createdAfter() === null
                    || $lockedPayment->created_at->lt($context->createdAfter())
                    || $context->expiresAt() === null
                    || ! $lockedPayment->created_at->lt($context->expiresAt())) {
                    throw new ConflictHttpException('scoped_uat_effect_before_enable: Effect aktif run zaman sınırı dışında.');
                }
                if (! is_numeric($lockedPayment->technical_service_request_id)
                    && ! is_numeric($lockedPayment->technical_service_mount_session_id)) {
                    throw new ConflictHttpException('scoped_uat_non_synthetic_entity: Scoped ödeme synthetic request/session ile bağlı olmalı.');
                }

                $businessIdentity = $this->scopedLocalUatPaymentBusinessIdentity($lockedPayment, $payload);
                $resolvedProviderFamily = null;
                $resolvedProviderMode = null;
                $snapshotProvider = (string) data_get(
                    $settings,
                    'manual_e2e_run_snapshot.scoped_local_uat_sandbox_payment_provider',
                    '',
                );
                if ($channel === 'sandbox_payment') {
                    if (! in_array($snapshotProvider, ['fake_payment', 'iyzico_sandbox'], true)) {
                        throw new ConflictHttpException('scoped_uat_provider_snapshot_invalid: Run payment provider snapshotı geçersiz.');
                    }
                    if ($operation === self::SCOPED_EFFECT_PAYMENT_CALLBACK) {
                        $provider = $snapshotProvider;
                        $resolvedProviderMode = $this->scopedLocalUatProviderModeForProvider($snapshotProvider);
                    } else {
                        $submittedProviderFamily = is_array($providerAuthority)
                            ? strtolower(trim((string) ($providerAuthority['family'] ?? '')))
                            : '';
                        $submittedProviderMode = is_array($providerAuthority)
                            ? strtolower(trim((string) ($providerAuthority['mode'] ?? '')))
                            : '';
                        $lockedProviderAuthority = $this->lockedScopedLocalUatPaymentProviderAuthority(
                            $locked['main_page'],
                        );
                        if (! hash_equals($lockedProviderAuthority['family'], $submittedProviderFamily)) {
                            throw new ConflictHttpException('scoped_uat_provider_snapshot_mismatch: provider_family_mismatch; manager provider family locked settings authority ile eşleşmiyor.');
                        }
                        if (! hash_equals($lockedProviderAuthority['mode'], $submittedProviderMode)) {
                            throw new ConflictHttpException('scoped_uat_provider_mode_mismatch: Manager provider mode locked settings authority ile eşleşmiyor.');
                        }
                        $resolvedProviderFamily = $lockedProviderAuthority['family'];
                        $resolvedProviderMode = $lockedProviderAuthority['mode'];
                        $provider = $this->assertScopedLocalUatPaymentProviderIdentityBeforeClaim(
                            $lockedPayment,
                            $payload,
                            $snapshotProvider,
                            $resolvedProviderFamily,
                            $resolvedProviderMode,
                        );
                    }
                }
                if (! is_string($provider) || $provider === '') {
                    throw new ConflictHttpException('scoped_uat_provider_snapshot_invalid: Effect provider authority çözülemedi.');
                }

                $authorization = $this->scopedLocalUatActionAuthorization(
                    $capability,
                    $event,
                    $channel,
                    $provider,
                    $recipient,
                    $channel === 'email' ? 'ops' : null,
                    $origin,
                );
                if (! $authorization['allowed']) {
                    throw new ConflictHttpException((string) $authorization['code'].': '.(string) $authorization['message']);
                }

                $mikro = (array) ($settings['mikro_api'] ?? []);
                if ((bool) ($mikro['enabled'] ?? false)
                    || (bool) ($mikro['read_sync_enabled'] ?? false)
                    || (bool) ($mikro['write_enabled'] ?? false)
                    || ! (bool) ($mikro['write_approval_required'] ?? true)) {
                    throw new ConflictHttpException('scoped_uat_mikro_invariant_drift: Mikro false/false/false ve approval-required invariantı değişti.');
                }

                $capabilitySnapshots = (array) data_get(
                    $settings,
                    'manual_e2e_run_snapshot.scoped_local_uat_capability_snapshots',
                    [],
                );
                $capabilitySnapshot = is_array($capabilitySnapshots[$capability] ?? null)
                    ? $capabilitySnapshots[$capability]
                    : [];
                $configurationFingerprint = (string) ($capabilitySnapshot['profile_fingerprint'] ?? '');
                if (! preg_match('/^[a-f0-9]{64}$/', $configurationFingerprint)) {
                    throw new ConflictHttpException('scoped_uat_configuration_fingerprint_missing: Effect configuration bağı doğrulanamadı.');
                }

                $recipientFingerprint = $recipient === null
                    ? null
                    : hash('sha256', strtolower(trim($recipient)));
                $idempotencyHash = $this->scopedLocalUatEffectIdempotencyHash(
                    $runId,
                    $operation,
                    $businessIdentity,
                    $this->scopedLocalUatAmountMinorUnits($lockedPayment),
                    $this->scopedLocalUatCurrency($lockedPayment),
                    $provider,
                    $recipientFingerprint,
                );
                $previous = $this->scopedLocalUatExactPaymentEffect(
                    $lockedPayment,
                    $runId,
                    $idempotencyHash,
                );
                if (! is_array($previous) && $operation === self::SCOPED_EFFECT_PAYMENT_CREATE) {
                    $previous = $this->scopedLocalUatPaymentEffectForBusiness(
                        $lockedPayment,
                        $runId,
                        $idempotencyHash,
                        $businessIdentity,
                    );
                }
                if (is_array($previous)) {
                    if ($operation === self::SCOPED_EFFECT_PAYMENT_CREATE) {
                        $canonicalPaymentId = (int) ($previous['payment_id'] ?? $lockedPayment->getKey());
                        $canonicalPayment = TechnicalServiceMountPayment::query()
                            ->whereKey($canonicalPaymentId)
                            ->lockForUpdate()
                            ->firstOrFail();
                        $outcome = $this->scopedLocalUatCreateReuseOutcome(
                            $canonicalPayment,
                            $runId,
                            $businessIdentity,
                            $provider,
                            $idempotencyHash,
                        );
                        if ($canonicalPaymentId !== (int) $lockedPayment->getKey()
                            && $outcome !== self::SCOPED_PAYMENT_OUTCOME_TERMINAL_NOT_REUSABLE) {
                            $this->markScopedLocalUatDuplicatePayment(
                                $lockedPayment,
                                $canonicalPayment,
                                $runId,
                                $businessIdentity,
                                $provider,
                                $idempotencyHash,
                            );
                        }

                        return [
                            'required' => true,
                            'duplicate' => true,
                            'claim_nonce' => null,
                            'duplicate_payment_id' => $canonicalPaymentId,
                            'outcome' => $outcome,
                        ];
                    }

                    if ((string) ($previous['status'] ?? '') === 'completed') {
                        $canonicalPaymentId = (int) ($previous['payment_id'] ?? $lockedPayment->getKey());
                        $callbackSubmission = $this->scopedLocalUatCallbackSubmission($callbackPayload, $provider);
                        $duplicateClaim = [
                            'run_id' => $runId,
                            'provider' => $provider,
                            'business_entity_type' => $businessIdentity['entity_type'],
                            'business_entity_id' => $businessIdentity['entity_id'],
                            'payment_purpose' => $businessIdentity['payment_purpose'],
                            'business_identity_hash' => $businessIdentity['identity_hash'],
                            'callback_submission' => $callbackSubmission,
                        ];
                        $this->assertScopedLocalUatStoredPaymentSessionAuthority(
                            $settings,
                            $duplicateClaim,
                            $lockedPayment,
                            $payload,
                        );
                        $this->assertScopedLocalUatCallbackReplayMatches($previous, $callbackSubmission);
                        if ($lockedPayment->status !== TechnicalServiceMountPayment::STATUS_PAID) {
                            throw new ConflictHttpException('scoped_uat_callback_state_invalid: Completed callback history canonical paid state ile eşleşmiyor.');
                        }

                        return [
                            'required' => true,
                            'duplicate' => true,
                            'claim_nonce' => null,
                            'duplicate_payment_id' => $canonicalPaymentId,
                            'outcome' => null,
                        ];
                    }

                    throw new ConflictHttpException('scoped_uat_effect_replay_blocked: Effect idempotency anahtarı daha önce tüketildi.');
                }
                if (is_array($settings['scoped_local_uat_active_effect_claim'] ?? null)) {
                    throw new ConflictHttpException('scoped_uat_effect_claim_busy: Başka bir scoped effect claim aktif.');
                }

                if ($channel === 'email') {
                    $counts = $this->scopedLocalUatMessagingAttemptCounts($settings, $runId);
                    $limits = (array) data_get($settings, 'manual_e2e_run_snapshot.scoped_local_uat_limits', []);
                    if ((int) ($limits['email'] ?? 0) <= $counts['email']
                        || (int) ($limits['total'] ?? 0) <= $counts['total']) {
                        throw new ConflictHttpException('scoped_uat_effect_quota_exceeded: E-posta veya toplam mesaj kotası transport öncesi doldu.');
                    }
                }

                $claimNonce = Str::random(64);
                $claim = [
                    'id' => (string) Str::uuid(),
                    'status' => 'claimed',
                    'run_id' => $runId,
                    'capability' => $capability,
                    'event' => $event,
                    'channel' => $channel,
                    'provider' => $provider,
                    'operation' => $operation,
                    'payment_id' => (int) $lockedPayment->getKey(),
                    'profile_id' => ExternalEffectCapabilityRegistry::LOCAL_ALLOWLISTED_UAT_PROFILE,
                    'business_entity_type' => $businessIdentity['entity_type'],
                    'business_entity_id' => $businessIdentity['entity_id'],
                    'payment_purpose' => $businessIdentity['payment_purpose'],
                    'business_identity_hash' => $businessIdentity['identity_hash'],
                    'part_request_fingerprint' => $businessIdentity['part_request_fingerprint'],
                    'selected_serials_fingerprint' => $businessIdentity['selected_serials_fingerprint'],
                    'selected_serial_count' => $businessIdentity['selected_serial_count'],
                    'amount_minor' => $this->scopedLocalUatAmountMinorUnits($lockedPayment),
                    'currency' => $this->scopedLocalUatCurrency($lockedPayment),
                    'request_id' => is_numeric($lockedPayment->technical_service_request_id)
                        ? (int) $lockedPayment->technical_service_request_id
                        : null,
                    'recipient_fingerprint' => $recipientFingerprint,
                    'idempotency_hash' => $idempotencyHash,
                    'claim_hash' => hash('sha256', $claimNonce),
                    'configuration_fingerprint' => $configurationFingerprint,
                    'origin_fingerprint' => hash('sha256', strtolower(trim($origin))),
                    'effect_created_at' => $lockedPayment->created_at->toIso8601String(),
                    'claimed_at' => now()->toIso8601String(),
                    'attempted' => true,
                ];
                if ($operation === self::SCOPED_EFFECT_PAYMENT_CALLBACK) {
                    $claim['callback_submission'] = $this->scopedLocalUatCallbackSubmission($callbackPayload, $provider);
                }
                if ($channel === 'sandbox_payment') {
                    $claim['provider_family'] = $resolvedProviderFamily;
                    $claim['provider_mode'] = $resolvedProviderMode;
                }
                if ($operation === self::SCOPED_EFFECT_PAYMENT_CREATE && $resolvedProviderMode !== null) {
                    $payload['provider_mode'] = $resolvedProviderMode;
                }
                $payload['scoped_local_uat_effect_claim'] = $claim;
                $lockedPayment->forceFill(['raw_payload' => $payload])->save();
                $settings['scoped_local_uat_active_effect_claim'] = $claim;
                $this->validateSettings($settings);
                $this->persistAuthoritativeSettings($locked, $settings);

                return [
                    'required' => true,
                    'duplicate' => false,
                    'claim_nonce' => $claimNonce,
                    'duplicate_payment_id' => null,
                    'outcome' => $operation === self::SCOPED_EFFECT_PAYMENT_CREATE
                        ? self::SCOPED_PAYMENT_OUTCOME_NEW_PENDING
                        : null,
                ];
            });
        } finally {
            $lock();
        }
    }

    private function finalizeScopedLocalUatEffect(
        string $claimNonce,
        bool $completed,
        ?Throwable $exception = null,
    ): void {
        $lock = $this->acquireLifecycleLock();

        try {
            DB::transaction(function () use ($claimNonce, $completed, $exception): void {
                $locked = $this->lockedAuthoritativeSettings();
                $settings = $locked['settings'];
                $claimHash = hash('sha256', $claimNonce);
                $claim = is_array($settings['scoped_local_uat_active_effect_claim'] ?? null)
                    ? $settings['scoped_local_uat_active_effect_claim']
                    : null;
                if ($claim === null || ! hash_equals((string) ($claim['claim_hash'] ?? ''), $claimHash)) {
                    $previous = collect((array) ($settings['scoped_local_uat_effect_history'] ?? []))
                        ->first(fn (mixed $entry): bool => is_array($entry)
                            && hash_equals((string) ($entry['claim_hash'] ?? ''), $claimHash));
                    if (is_array($previous) && in_array((string) ($previous['status'] ?? ''), [
                        'completed',
                        'failed',
                        'frozen_unresolved',
                        'execution_mode_local_frozen',
                    ], true)) {
                        return;
                    }

                    throw new ConflictHttpException('Scoped effect sonucu aktif claim ile eşleşmiyor.');
                }
                $this->finalizeScopedLocalUatEffectWithinTransaction(
                    $locked,
                    $settings,
                    $claim,
                    $completed,
                    $exception,
                );
            });
        } finally {
            $lock();
        }
    }

    /**
     * @param  array{lifecycle_page:PageConfig,main_page:PageConfig,settings:array<string,mixed>}  $locked
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $claim
     */
    private function finalizeScopedLocalUatEffectWithinTransaction(
        array $locked,
        array $settings,
        array $claim,
        bool $completed,
        ?Throwable $exception = null,
    ): void {
        $status = (string) ($claim['status'] ?? '');
        if ($completed && $status !== 'dispatching') {
            throw new ConflictHttpException('scoped_uat_effect_not_dispatching: Effect final dispatch gate olmadan tamamlanamaz.');
        }
        if (! $completed && ! in_array($status, ['claimed', 'dispatching'], true)) {
            throw new ConflictHttpException('scoped_uat_effect_claim_invalid: Effect claim durumu sonuç için geçersiz.');
        }

        $historyEntry = [
            ...$this->scopedLocalUatHistoryClaim($claim),
            'status' => $completed ? 'completed' : 'failed',
            'outcome' => $completed ? 'provider_accepted' : 'failed_no_retry',
            'completed_at' => now()->toIso8601String(),
            'error_class' => $completed || $exception === null ? null : class_basename($exception),
            'replay_blocked' => true,
        ];
        $payment = TechnicalServiceMountPayment::query()
            ->whereKey((int) ($claim['payment_id'] ?? 0))
            ->lockForUpdate()
            ->first();
        if ($payment instanceof TechnicalServiceMountPayment) {
            $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
            $paymentHistory = is_array($payload['scoped_local_uat_effect_history'] ?? null)
                ? $payload['scoped_local_uat_effect_history']
                : [];
            $payload['scoped_local_uat_effect_claim'] = null;
            $payload['scoped_local_uat_effect_history'] = array_values([
                ...$paymentHistory,
                $historyEntry,
            ]);
            if ($completed && (string) ($claim['operation'] ?? '') === self::SCOPED_EFFECT_PAYMENT_CREATE) {
                $sessionAuthority = $this->scopedLocalUatPaymentSessionAuthority(
                    $payment,
                    $claim,
                );
                $historyEntry = [
                    ...$historyEntry,
                    'provider_mode' => $sessionAuthority['provider_mode'],
                    'provider_reference_hash' => $sessionAuthority['provider_reference_hash'],
                    'payment_url_hash' => $sessionAuthority['payment_url_hash'],
                ];
                $payload['scoped_local_uat_effect_history'] = array_values([
                    ...$paymentHistory,
                    $historyEntry,
                ]);
                $payload['scoped_local_uat_payment_session_authority'] = $sessionAuthority;
            }
            $updates = ['raw_payload' => $payload];
            if (! $completed && (string) ($claim['operation'] ?? '') === self::SCOPED_EFFECT_PAYMENT_CREATE) {
                $updates['status'] = TechnicalServiceMountPayment::STATUS_FAILED;
            }
            $payment->forceFill($updates)->save();
        }

        $settings['scoped_local_uat_active_effect_claim'] = null;
        $settings['scoped_local_uat_effect_history'] = $this->appendWindowHistory(
            (array) ($settings['scoped_local_uat_effect_history'] ?? []),
            $historyEntry,
        );
        $this->validateSettings($settings);
        $this->persistAuthoritativeSettings($locked, $settings);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{0:array<string,mixed>,1:TechnicalServiceMountPayment}
     */
    private function lockedScopedLocalUatEffectClaim(array $settings, string $claimNonce): array
    {
        $claimHash = hash('sha256', $claimNonce);
        $claim = is_array($settings['scoped_local_uat_active_effect_claim'] ?? null)
            ? $settings['scoped_local_uat_active_effect_claim']
            : null;
        if ($claim === null || ! hash_equals((string) ($claim['claim_hash'] ?? ''), $claimHash)) {
            $previous = collect((array) ($settings['scoped_local_uat_effect_history'] ?? []))
                ->first(fn (mixed $entry): bool => is_array($entry)
                    && hash_equals((string) ($entry['claim_hash'] ?? ''), $claimHash));
            $code = is_array($previous) && str_contains((string) ($previous['status'] ?? ''), 'frozen')
                ? 'scoped_uat_effect_frozen_before_dispatch'
                : 'scoped_uat_effect_claim_missing';

            throw new ConflictHttpException($code.': Final effect gate aktif claim bulamadı.');
        }

        $payment = TechnicalServiceMountPayment::query()
            ->whereKey((int) ($claim['payment_id'] ?? 0))
            ->lockForUpdate()
            ->firstOrFail();
        $paymentClaim = data_get($payment->raw_payload, 'scoped_local_uat_effect_claim');
        if (! is_array($paymentClaim)
            || ! hash_equals((string) ($paymentClaim['claim_hash'] ?? ''), $claimHash)
            || (string) ($paymentClaim['id'] ?? '') !== (string) ($claim['id'] ?? '')) {
            throw new ConflictHttpException('scoped_uat_effect_claim_drift: Payment claim authority settings claim ile eşleşmiyor.');
        }

        return [$claim, $payment];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $claim
     * @return array<string, mixed>
     */
    private function validatedScopedLocalUatDispatchingClaim(
        array $settings,
        array $claim,
        TechnicalServiceMountPayment $payment,
    ): array {
        if ((string) ($claim['status'] ?? '') !== 'claimed') {
            throw new ConflictHttpException('scoped_uat_effect_claim_not_current: Effect yalnız claimed durumundan dispatching durumuna geçebilir.');
        }

        $context = TechnicalServiceManualE2ERunContext::fromSettings($settings);
        $runId = (string) ($claim['run_id'] ?? '');
        if (! $context->isActive()
            || $context->phase() !== self::MANUAL_E2E_PHASE_PREPARED
            || $runId === ''
            || $runId !== $context->activeRunId()
            || ! $this->isScopedLocalUatSettings($settings)) {
            throw new ConflictHttpException('scoped_uat_effect_frozen_before_dispatch: Run final effect gate öncesi kapandı veya donduruldu.');
        }

        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $tagged = filter_var(
            data_get($payload, 'scoped_local_uat.synthetic_uat', $payload['synthetic_uat'] ?? false),
            FILTER_VALIDATE_BOOL,
        );
        $paymentRunId = (string) data_get($payload, 'scoped_local_uat.run_id', $payload['scoped_local_uat_run_id'] ?? '');
        $origin = (string) data_get($payload, 'scoped_local_uat.origin', $payload['scoped_local_uat_origin'] ?? '');
        if (! $tagged || $paymentRunId !== $runId) {
            throw new ConflictHttpException('scoped_uat_wrong_run_id: Final effect payment/run bağı değişti.');
        }
        if ($payment->created_at === null
            || $context->createdAfter() === null
            || $payment->created_at->lt($context->createdAfter())
            || $context->expiresAt() === null
            || ! now()->lt($context->expiresAt())
            || ! $payment->created_at->lt($context->expiresAt())) {
            throw new ConflictHttpException('scoped_uat_effect_expired: Final effect gate zaman sınırı geçersiz.');
        }
        if (! hash_equals(
            (string) ($claim['origin_fingerprint'] ?? ''),
            hash('sha256', strtolower(trim($origin))),
        ) || ! $this->scopedLocalUatBodyUrlsAreSafe($origin, $settings)) {
            throw new ConflictHttpException('scoped_uat_payload_origin_invalid: Final effect origin bağı değişti.');
        }

        $capability = (string) ($claim['capability'] ?? '');
        $event = (string) ($claim['event'] ?? '');
        $channel = (string) ($claim['channel'] ?? '');
        $provider = (string) ($claim['provider'] ?? '');
        $profile = app(ExternalEffectCapabilityRegistry::class)->localAllowlistedUatProfile();
        $expectedProvider = data_get($profile, 'messaging_events.'.$event.'.'.$channel);
        if (! is_string($expectedProvider)) {
            $action = is_array($profile['action_events'][$event] ?? null)
                ? $profile['action_events'][$event]
                : [];
            $expectedProvider = (string) ($action['channel'] ?? '') === $channel
                && in_array($provider, (array) ($action['providers'] ?? []), true)
                    ? $provider
                    : null;
        }
        $expectedCapability = match ($channel) {
            'email' => ExternalEffectCapabilityRegistry::MAIL_SMTP_SEND,
            'sandbox_payment' => ExternalEffectCapabilityRegistry::PAYMENT_LOCAL_SANDBOX_EXECUTE,
            default => null,
        };
        if ($expectedProvider !== $provider || $expectedCapability !== $capability) {
            throw new ConflictHttpException('FAIL_CLOSED_UNAUTHORIZED_CAPABILITY: Final effect profile bağı geçersiz.');
        }

        $authorization = app(ExternalExecutionControlPlaneService::class)
            ->authorizeScopedLocalUatCapabilitySnapshot(
                $capability,
                (array) ($settings['manual_e2e_run_snapshot'] ?? []),
            );
        if (! $authorization['allowed']) {
            throw new ConflictHttpException((string) $authorization['code'].': '.(string) $authorization['message']);
        }
        $mikro = (array) ($settings['mikro_api'] ?? []);
        if ((bool) ($mikro['enabled'] ?? false)
            || (bool) ($mikro['read_sync_enabled'] ?? false)
            || (bool) ($mikro['write_enabled'] ?? false)
            || ! (bool) ($mikro['write_approval_required'] ?? true)) {
            throw new ConflictHttpException('scoped_uat_mikro_invariant_drift: Mikro invariantı final effect gate öncesi değişti.');
        }

        $capabilitySnapshots = (array) data_get(
            $settings,
            'manual_e2e_run_snapshot.scoped_local_uat_capability_snapshots',
            [],
        );
        $capabilitySnapshot = is_array($capabilitySnapshots[$capability] ?? null)
            ? $capabilitySnapshots[$capability]
            : [];
        $snapshotFingerprint = (string) ($capabilitySnapshot['profile_fingerprint'] ?? '');
        if (! preg_match('/^[a-f0-9]{64}$/', $snapshotFingerprint)
            || ! hash_equals((string) ($claim['configuration_fingerprint'] ?? ''), $snapshotFingerprint)) {
            throw new ConflictHttpException('scoped_uat_configuration_fingerprint_drift: Effect configuration snapshotı final gate öncesi değişti.');
        }

        $businessIdentity = $this->scopedLocalUatPaymentBusinessIdentity($payment, $payload);
        $expectedIdempotency = $this->scopedLocalUatEffectIdempotencyHash(
            $runId,
            (string) ($claim['operation'] ?? ''),
            $businessIdentity,
            $this->scopedLocalUatAmountMinorUnits($payment),
            $this->scopedLocalUatCurrency($payment),
            $provider,
            is_string($claim['recipient_fingerprint'] ?? null) ? $claim['recipient_fingerprint'] : null,
        );
        if (! hash_equals((string) ($claim['idempotency_hash'] ?? ''), $expectedIdempotency)) {
            throw new ConflictHttpException('scoped_uat_effect_idempotency_drift: Business effect identity final gate öncesi değişti.');
        }

        if ($channel === 'email') {
            $allowedFingerprints = array_map(
                static fn (string $email): string => hash('sha256', strtolower(trim($email))),
                app(TechnicalServicePaymentProviderSettingsService::class)->paymentNotificationRecipients(),
            );
            if (! in_array((string) ($claim['recipient_fingerprint'] ?? ''), $allowedFingerprints, true)) {
                throw new ConflictHttpException('scoped_uat_email_not_allowlisted: E-posta allowlist bağı final gate öncesi değişti.');
            }
            $counts = $this->scopedLocalUatMessagingAttemptCounts($settings, $runId);
            $limits = (array) data_get($settings, 'manual_e2e_run_snapshot.scoped_local_uat_limits', []);
            if ((int) ($limits['email'] ?? 0) < $counts['email']
                || (int) ($limits['total'] ?? 0) < $counts['total']) {
                throw new ConflictHttpException('scoped_uat_effect_quota_exceeded: E-posta veya toplam mesaj kotası final gate öncesi aşıldı.');
            }
        }

        if ($channel === 'sandbox_payment') {
            $snapshotProvider = (string) data_get(
                $settings,
                'manual_e2e_run_snapshot.scoped_local_uat_sandbox_payment_provider',
                '',
            );
            if ($provider !== $snapshotProvider) {
                throw new ConflictHttpException('scoped_uat_provider_snapshot_mismatch: Effect providerı immutable run snapshot ile eşleşmiyor.');
            }
            if ((string) ($claim['operation'] ?? '') === self::SCOPED_EFFECT_PAYMENT_CALLBACK) {
                $this->assertScopedLocalUatStoredPaymentSessionAuthority($settings, $claim, $payment, $payload);
                $this->bindScopedLocalUatCallbackReferences($payment, $claim);
            } else {
                $this->assertScopedLocalUatPaymentProviderIdentityBeforeClaim(
                    $payment,
                    $payload,
                    $snapshotProvider,
                    strtolower(trim((string) ($claim['provider_family'] ?? ''))),
                    strtolower(trim((string) ($claim['provider_mode'] ?? ''))),
                );
            }
        }

        return [
            ...$claim,
            'status' => 'dispatching',
            'dispatching_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{entity_type:string,entity_id:int,payment_purpose:string,identity_hash:string,part_request_fingerprint:string|null,selected_serials_fingerprint:string|null,selected_serial_count:int}
     */
    private function scopedLocalUatPaymentBusinessIdentity(
        TechnicalServiceMountPayment $payment,
        array $payload,
    ): array {
        if (is_numeric($payment->technical_service_request_id) && (int) $payment->technical_service_request_id > 0) {
            $entityType = 'technical_service_request';
            $entityId = (int) $payment->technical_service_request_id;
        } elseif (is_numeric($payment->technical_service_mount_session_id) && (int) $payment->technical_service_mount_session_id > 0) {
            $entityType = 'technical_service_mount_session';
            $entityId = (int) $payment->technical_service_mount_session_id;
        } else {
            throw new ConflictHttpException('scoped_uat_non_synthetic_entity: Payment business identity çözülemedi.');
        }

        $source = strtolower(trim((string) ($payload['source'] ?? '')));
        $paymentPurpose = $this->scopedLocalUatCanonicalPaymentPurpose($source, $payload);
        $partRequestId = isset($payload['part_request_id'])
            ? $this->scopedLocalUatPositiveIdentifier($payload['part_request_id'], 'part_request_id')
            : null;
        if (in_array($paymentPurpose, ['part_payment', 'service_and_part_payment'], true) && $partRequestId === null) {
            throw new ConflictHttpException('CONTRACT_FIELD_UNAVAILABLE: Scoped part payment part_request_id gerektirir.');
        }

        $rawSerialIds = $payload['selected_serial_ids'] ?? [];
        if (! is_array($rawSerialIds)) {
            throw new ConflictHttpException('CONTRACT_FIELD_UNAVAILABLE: selected_serial_ids canonical array olmalı.');
        }
        $selectedSerialIds = [];
        foreach ($rawSerialIds as $serialId) {
            $selectedSerialIds[] = $this->scopedLocalUatPositiveIdentifier($serialId, 'selected_serial_ids');
        }
        $selectedSerialIds = array_values(array_unique($selectedSerialIds));
        sort($selectedSerialIds, SORT_NUMERIC);
        if (in_array($paymentPurpose, ['multi_product_mount', 'montage_difference'], true)
            && $selectedSerialIds === []) {
            throw new ConflictHttpException('CONTRACT_FIELD_UNAVAILABLE: Serial-bound payment selected_serial_ids gerektirir.');
        }

        $requestId = is_numeric($payment->technical_service_request_id)
            ? (int) $payment->technical_service_request_id
            : null;
        $sessionId = is_numeric($payment->technical_service_mount_session_id)
            ? (int) $payment->technical_service_mount_session_id
            : null;
        if ($partRequestId !== null && ($requestId === null || ! TechnicalServicePartRequest::query()
            ->whereKey($partRequestId)
            ->where('technical_service_request_id', $requestId)
            ->exists())) {
            throw new ConflictHttpException('CONTRACT_FIELD_UNAVAILABLE: part_request_id payment request sahipliğiyle eşleşmiyor.');
        }
        if ($selectedSerialIds !== []) {
            if ($requestId === null) {
                throw new ConflictHttpException('CONTRACT_FIELD_UNAVAILABLE: selected_serial_ids request authority olmadan doğrulanamaz.');
            }
            $ownedSerialCount = TechnicalServiceRequestSerial::query()
                ->where('technical_service_request_id', $requestId)
                ->whereIn('id', $selectedSerialIds)
                ->count();
            if ($ownedSerialCount !== count($selectedSerialIds)) {
                throw new ConflictHttpException('CONTRACT_FIELD_UNAVAILABLE: selected_serial_ids payment request sahipliğiyle eşleşmiyor.');
            }
        }
        $identity = [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'request_id' => $requestId,
            'session_id' => $sessionId,
            'payment_purpose' => $paymentPurpose,
            'part_request_id' => $partRequestId,
            'selected_serial_ids' => $selectedSerialIds,
        ];
        $identityHash = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'payment_purpose' => $paymentPurpose,
            'identity_hash' => $identityHash,
            'part_request_fingerprint' => $partRequestId === null ? null : hash('sha256', (string) $partRequestId),
            'selected_serials_fingerprint' => $selectedSerialIds === []
                ? null
                : hash('sha256', implode(',', $selectedSerialIds)),
            'selected_serial_count' => count($selectedSerialIds),
        ];
    }

    private function scopedLocalUatAmountMinorUnits(TechnicalServiceMountPayment $payment): string
    {
        $rawAmount = $payment->getRawOriginal('amount');

        return $this->scopedLocalUatStrictDecimalMinorUnits($rawAmount ?? $payment->amount, 'amount');
    }

    /** @param array<string, mixed> $payload */
    private function scopedLocalUatCanonicalPaymentPurpose(string $source, array $payload): string
    {
        $values = [];
        foreach (['purpose', 'charge_type'] as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }
            if (! is_scalar($payload[$key]) || trim((string) $payload[$key]) === '') {
                throw new ConflictHttpException('scoped_uat_payment_purpose_invalid: Present purpose/charge_type değeri geçersiz.');
            }
            $values[] = $this->scopedLocalUatCanonicalPaymentPurposeValue($source, (string) $payload[$key]);
        }
        if ($values === [] && array_key_exists('reason', $payload)) {
            if (! is_scalar($payload['reason']) || trim((string) $payload['reason']) === '') {
                throw new ConflictHttpException('scoped_uat_payment_purpose_invalid: Present reason değeri geçersiz.');
            }
            $values[] = $this->scopedLocalUatCanonicalPaymentPurposeValue($source, (string) $payload['reason']);
        }
        if ($values === []) {
            $values[] = $this->scopedLocalUatCanonicalPaymentPurposeValue($source, '');
        }
        if (count(array_unique($values)) !== 1) {
            throw new ConflictHttpException('scoped_uat_payment_purpose_conflict: purpose ve charge_type aynı business obligationı göstermiyor.');
        }

        return $values[0];
    }

    private function scopedLocalUatCanonicalPaymentPurposeValue(string $source, string $value): string
    {
        $value = strtolower(trim($value));

        return match ($source) {
            'scoped_local_uat_sandbox' => $value === '' || $value === 'sandbox_payment'
                ? 'sandbox_payment'
                : throw new ConflictHttpException('scoped_uat_payment_purpose_invalid: Sandbox payment purpose code-owned allowlist içinde değil.'),
            'public_mount_payment' => $value === 'service_payment'
                ? 'service_payment'
                : throw new ConflictHttpException('scoped_uat_payment_purpose_invalid: Public mount payment purpose code-owned allowlist içinde değil.'),
            'operation_extra_mount_fee' => match ($value) {
                'multi_product', 'multi_product_mount' => 'multi_product_mount',
                'route_fee' => 'route_fee',
                'montage_difference' => 'montage_difference',
                'manual_extra', 'mount_extra', 'manual_mount_payment' => 'extra_mount_fee',
                default => throw new ConflictHttpException('scoped_uat_payment_purpose_invalid: Extra mount payment purpose code-owned allowlist içinde değil.'),
            },
            'operation_customer_charge' => match ($value) {
                'part_payment' => 'part_payment',
                'service_and_part_payment' => 'service_and_part_payment',
                'service_payment' => 'customer_charge',
                default => throw new ConflictHttpException('scoped_uat_payment_purpose_invalid: Customer charge purpose code-owned allowlist içinde değil.'),
            },
            default => throw new ConflictHttpException('scoped_uat_payment_purpose_invalid: Payment purpose code-owned allowlist içinde değil.'),
        };
    }

    private function scopedLocalUatPositiveIdentifier(mixed $value, string $field): int
    {
        if (is_int($value)) {
            $identifier = $value;
        } elseif (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value)) {
            $identifier = (int) $value;
        } else {
            throw new ConflictHttpException('CONTRACT_FIELD_UNAVAILABLE: '.$field.' pozitif canonical kimlik olmalı.');
        }
        if ($identifier < 1) {
            throw new ConflictHttpException('CONTRACT_FIELD_UNAVAILABLE: '.$field.' pozitif canonical kimlik olmalı.');
        }

        return $identifier;
    }

    private function scopedLocalUatStrictDecimalMinorUnits(mixed $value, string $field): string
    {
        if (is_int($value)) {
            $decimal = (string) $value;
        } elseif (is_float($value) && is_finite($value)) {
            $rounded = round($value, 2);
            if (abs($value - $rounded) > 0.000000001) {
                throw new ConflictHttpException('CONTRACT_FIELD_UNAVAILABLE: '.$field.' float artefact içeriyor.');
            }
            $decimal = number_format($rounded, 2, '.', '');
        } elseif (is_string($value)) {
            $decimal = trim($value);
        } else {
            throw new ConflictHttpException('CONTRACT_FIELD_UNAVAILABLE: '.$field.' strict decimal olmalı.');
        }
        if (! preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/', $decimal, $matches)) {
            throw new ConflictHttpException('CONTRACT_FIELD_UNAVAILABLE: '.$field.' scientific notation veya geçersiz decimal içeriyor.');
        }
        $fraction = str_pad((string) ($matches[2] ?? ''), 2, '0');
        $minor = ltrim($matches[1].$fraction, '0') ?: '0';
        if ($minor === '0') {
            throw new ConflictHttpException('CONTRACT_FIELD_UNAVAILABLE: '.$field.' pozitif olmalı.');
        }

        return $minor;
    }

    private function scopedLocalUatCurrency(TechnicalServiceMountPayment $payment): string
    {
        $currency = strtoupper(trim((string) $payment->currency));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new ConflictHttpException('CONTRACT_FIELD_UNAVAILABLE: currency canonical üç harfli kod olmalı.');
        }

        return $currency;
    }

    /**
     * @param  array{identity_hash:string}  $businessIdentity
     */
    private function scopedLocalUatEffectIdempotencyHash(
        string $runId,
        string $operation,
        array $businessIdentity,
        string $amountMinor,
        string $currency,
        string $provider,
        ?string $recipientFingerprint,
    ): string {
        return hash('sha256', implode('|', [
            ExternalEffectCapabilityRegistry::LOCAL_ALLOWLISTED_UAT_PROFILE,
            $runId,
            $operation,
            $businessIdentity['identity_hash'],
            $amountMinor,
            $currency,
            $provider,
            (string) $recipientFingerprint,
        ]));
    }

    private function scopedLocalUatCreateReuseOutcome(
        TechnicalServiceMountPayment $canonicalPayment,
        string $runId,
        array $businessIdentity,
        string $provider,
        string $idempotencyHash,
    ): string {
        $status = (string) $canonicalPayment->status;
        if (in_array($status, [
            TechnicalServiceMountPayment::STATUS_FAILED,
            TechnicalServiceMountPayment::STATUS_CANCELLED,
            TechnicalServiceMountPayment::STATUS_EXPIRED,
        ], true)) {
            return self::SCOPED_PAYMENT_OUTCOME_TERMINAL_NOT_REUSABLE;
        }
        $this->assertScopedLocalUatPendingReuseAuthority(
            $canonicalPayment,
            $runId,
            $businessIdentity,
            $provider,
            $idempotencyHash,
        );

        if ($status === TechnicalServiceMountPayment::STATUS_PAID) {
            return self::SCOPED_PAYMENT_OUTCOME_ALREADY_PAID;
        }
        if ($status === TechnicalServiceMountPayment::STATUS_PENDING
            && trim((string) $canonicalPayment->payment_url) !== '') {
            return self::SCOPED_PAYMENT_OUTCOME_REUSED_PENDING;
        }

        return self::SCOPED_PAYMENT_OUTCOME_TERMINAL_NOT_REUSABLE;
    }

    /** @return array<string, mixed>|null */
    private function scopedLocalUatPaymentEffectForBusiness(
        TechnicalServiceMountPayment $payment,
        string $runId,
        string $idempotencyHash,
        array $businessIdentity,
    ): ?array {
        $query = TechnicalServiceMountPayment::query()
            ->where('amount', $payment->amount)
            ->where('currency', strtoupper((string) $payment->currency));
        if (is_numeric($payment->technical_service_request_id) && (int) $payment->technical_service_request_id > 0) {
            $query->where('technical_service_request_id', (int) $payment->technical_service_request_id);
        } else {
            $query->where('technical_service_mount_session_id', (int) $payment->technical_service_mount_session_id);
        }

        foreach ($query->orderBy('id')->lockForUpdate()->get() as $candidate) {
            $effect = $this->scopedLocalUatExactPaymentEffect($candidate, $runId, $idempotencyHash);
            if (is_array($effect)) {
                return [
                    ...$effect,
                    'payment_id' => (int) $candidate->getKey(),
                ];
            }
            $authority = data_get($candidate->raw_payload, 'scoped_local_uat_payment_session_authority');
            $candidatePayload = is_array($candidate->raw_payload) ? $candidate->raw_payload : [];
            $candidateBusinessIdentity = $this->scopedLocalUatPaymentBusinessIdentity($candidate, $candidatePayload);
            if (is_array($authority)
                && (string) ($authority['create_status'] ?? '') === 'completed'
                && (string) ($authority['run_id'] ?? '') === $runId
                && (
                    hash_equals((string) ($authority['idempotency_hash'] ?? ''), $idempotencyHash)
                    || hash_equals($candidateBusinessIdentity['identity_hash'], $businessIdentity['identity_hash'])
                )) {
                throw new ConflictHttpException('UNSAFE_PENDING_NOT_REUSABLE: Completed metadata exact successful create history yerine geçemez.');
            }
        }

        return null;
    }

    /**
     * @param  array{entity_type:string,entity_id:int,payment_purpose:string,identity_hash:string,part_request_fingerprint:string|null,selected_serials_fingerprint:string|null,selected_serial_count:int}  $businessIdentity
     */
    private function assertScopedLocalUatPendingReuseAuthority(
        TechnicalServiceMountPayment $payment,
        string $runId,
        array $businessIdentity,
        string $provider,
        string $idempotencyHash,
    ): void {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $authority = $payload['scoped_local_uat_payment_session_authority'] ?? null;
        $history = is_array($payload['scoped_local_uat_effect_history'] ?? null)
            ? $payload['scoped_local_uat_effect_history']
            : [];
        $paymentId = (int) $payment->getKey();
        $providerReference = trim((string) $payment->provider_reference);
        $paymentUrl = trim((string) $payment->payment_url);
        $amountMinor = $this->scopedLocalUatAmountMinorUnits($payment);
        $currency = $this->scopedLocalUatCurrency($payment);
        $providerMode = $this->scopedLocalUatProviderModeForProvider($provider);
        $canonicalProvider = $this->scopedLocalUatCanonicalPaymentProvider($payment);
        $canonicalBusinessIdentity = $this->scopedLocalUatPaymentBusinessIdentity($payment, $payload);

        $sameEffect = static fn (mixed $entry): bool => is_array($entry)
            && (string) ($entry['operation'] ?? '') === self::SCOPED_EFFECT_PAYMENT_CREATE
            && (int) ($entry['payment_id'] ?? 0) === $paymentId
            && (string) ($entry['profile_id'] ?? '') === ExternalEffectCapabilityRegistry::LOCAL_ALLOWLISTED_UAT_PROFILE
            && (string) ($entry['run_id'] ?? '') === $runId
            && hash_equals((string) ($entry['idempotency_hash'] ?? ''), $idempotencyHash)
            && hash_equals((string) ($entry['business_identity_hash'] ?? ''), $businessIdentity['identity_hash'])
            && (string) ($entry['provider'] ?? '') === $provider
            && (string) ($entry['amount_minor'] ?? '') === $amountMinor
            && (string) ($entry['currency'] ?? '') === $currency;

        $failedHistory = collect($history)->contains(
            static fn (mixed $entry): bool => $sameEffect($entry)
                && (string) ($entry['status'] ?? '') === 'failed',
        );
        if ($failedHistory) {
            throw new ConflictHttpException('scoped_uat_effect_replay_blocked: Failed payment create history explicit retry authority olmadan reuse edilemez.');
        }

        $successfulHistory = collect($history)->first(
            static fn (mixed $entry): bool => $sameEffect($entry)
                && (string) ($entry['status'] ?? '') === 'completed'
                && (string) ($entry['outcome'] ?? '') === 'provider_accepted'
                && (string) ($entry['provider_mode'] ?? '') === $providerMode
                && hash_equals((string) ($entry['provider_reference_hash'] ?? ''), hash('sha256', $providerReference))
                && hash_equals((string) ($entry['payment_url_hash'] ?? ''), hash('sha256', $paymentUrl)),
        );
        $configurationFingerprint = is_array($authority)
            ? (string) ($authority['configuration_fingerprint'] ?? '')
            : '';
        $authorityValid = is_array($authority)
            && (string) ($authority['create_status'] ?? '') === 'completed'
            && (string) ($authority['profile_id'] ?? '') === ExternalEffectCapabilityRegistry::LOCAL_ALLOWLISTED_UAT_PROFILE
            && (string) ($authority['run_id'] ?? '') === $runId
            && (int) ($authority['payment_id'] ?? 0) === $paymentId
            && (string) ($authority['business_entity_type'] ?? '') === $businessIdentity['entity_type']
            && (int) ($authority['business_entity_id'] ?? 0) === $businessIdentity['entity_id']
            && (string) ($authority['payment_purpose'] ?? '') === $businessIdentity['payment_purpose']
            && hash_equals((string) ($authority['business_identity_hash'] ?? ''), $businessIdentity['identity_hash'])
            && hash_equals($canonicalBusinessIdentity['identity_hash'], $businessIdentity['identity_hash'])
            && (string) ($authority['part_request_fingerprint'] ?? '') === (string) $businessIdentity['part_request_fingerprint']
            && (string) ($authority['selected_serials_fingerprint'] ?? '') === (string) $businessIdentity['selected_serials_fingerprint']
            && (int) ($authority['selected_serial_count'] ?? -1) === $businessIdentity['selected_serial_count']
            && hash_equals((string) ($authority['idempotency_hash'] ?? ''), $idempotencyHash)
            && (string) ($authority['provider'] ?? '') === $provider
            && (string) ($authority['provider_mode'] ?? '') === $providerMode
            && $canonicalProvider === $provider
            && $providerReference !== ''
            && hash_equals((string) ($authority['provider_reference'] ?? ''), $providerReference)
            && hash_equals((string) ($authority['provider_reference_hash'] ?? ''), hash('sha256', $providerReference))
            && $paymentUrl !== ''
            && hash_equals((string) ($authority['payment_url_hash'] ?? ''), hash('sha256', $paymentUrl))
            && (string) ($authority['amount_minor'] ?? '') === $amountMinor
            && (string) ($authority['currency'] ?? '') === $currency
            && preg_match('/^[a-f0-9]{64}$/', $configurationFingerprint) === 1
            && is_array($successfulHistory)
            && hash_equals((string) ($successfulHistory['configuration_fingerprint'] ?? ''), $configurationFingerprint);
        if (! $authorityValid) {
            throw new ConflictHttpException('UNSAFE_PENDING_NOT_REUSABLE: Pending payment exact successful create/session history authority taşımıyor.');
        }
    }

    /** @return array<string, mixed>|null */
    private function scopedLocalUatExactPaymentEffect(
        TechnicalServiceMountPayment $payment,
        string $runId,
        string $idempotencyHash,
    ): ?array {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $history = is_array($payload['scoped_local_uat_effect_history'] ?? null)
            ? $payload['scoped_local_uat_effect_history']
            : [];
        $activeClaim = is_array($payload['scoped_local_uat_effect_claim'] ?? null)
            ? $payload['scoped_local_uat_effect_claim']
            : null;
        if ($activeClaim !== null) {
            $history[] = $activeClaim;
        }

        foreach (array_reverse($history) as $entry) {
            if (is_array($entry)
                && (string) ($entry['run_id'] ?? '') === $runId
                && hash_equals((string) ($entry['idempotency_hash'] ?? ''), $idempotencyHash)) {
                return [
                    ...$entry,
                    'payment_id' => (int) $payment->getKey(),
                ];
            }
        }

        return null;
    }

    private function markScopedLocalUatDuplicatePayment(
        TechnicalServiceMountPayment $payment,
        TechnicalServiceMountPayment $canonicalPayment,
        string $runId,
        array $businessIdentity,
        string $provider,
        string $idempotencyHash,
    ): void {
        $authority = data_get($canonicalPayment->raw_payload, 'scoped_local_uat_payment_session_authority');
        if (! is_array($authority) || (string) ($authority['create_status'] ?? '') !== 'completed') {
            throw new ConflictHttpException('scoped_uat_duplicate_payment_authority_invalid: Canonical stored session authority eksik.');
        }
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $payload['scoped_local_uat_duplicate_payment'] = [
            'schema_version' => 2,
            'status' => 'superseded',
            'profile_id' => ExternalEffectCapabilityRegistry::LOCAL_ALLOWLISTED_UAT_PROFILE,
            'run_id' => $runId,
            'canonical_payment_id' => (int) $canonicalPayment->getKey(),
            'idempotency_hash' => $idempotencyHash,
            'business_identity_hash' => $businessIdentity['identity_hash'],
            'provider' => $provider,
            'provider_reference_hash' => hash('sha256', (string) $canonicalPayment->provider_reference),
            'amount_minor' => $this->scopedLocalUatAmountMinorUnits($payment),
            'currency' => $this->scopedLocalUatCurrency($payment),
            'canonical_authority_fingerprint' => $this->scopedLocalUatDuplicateAuthorityFingerprint($authority),
            'resolved_at' => now()->toIso8601String(),
        ];
        $payment->forceFill([
            'status' => TechnicalServiceMountPayment::STATUS_CANCELLED,
            'raw_payload' => $payload,
        ])->save();
    }

    private function lockedScopedLocalUatCanonicalCallbackPayment(
        TechnicalServiceMountPayment $payment,
    ): TechnicalServiceMountPayment {
        $duplicate = data_get($payment->raw_payload, 'scoped_local_uat_duplicate_payment');
        if (! is_array($duplicate)) {
            return $payment;
        }
        $canonicalPaymentId = $duplicate['canonical_payment_id'] ?? null;
        $idempotencyHash = (string) ($duplicate['idempotency_hash'] ?? '');
        if ((int) ($duplicate['schema_version'] ?? 0) !== 2
            || (string) ($duplicate['status'] ?? '') !== 'superseded'
            || (string) ($duplicate['profile_id'] ?? '') !== ExternalEffectCapabilityRegistry::LOCAL_ALLOWLISTED_UAT_PROFILE
            || $payment->status !== TechnicalServiceMountPayment::STATUS_CANCELLED
            || ! is_numeric($canonicalPaymentId)
            || (int) $canonicalPaymentId < 1
            || (int) $canonicalPaymentId === (int) $payment->getKey()
            || ! preg_match('/^[a-f0-9]{64}$/', $idempotencyHash)) {
            throw new ConflictHttpException('scoped_uat_duplicate_payment_authority_invalid: Duplicate payment canonical bağı geçersiz.');
        }

        $canonical = TechnicalServiceMountPayment::query()
            ->whereKey((int) $canonicalPaymentId)
            ->lockForUpdate()
            ->firstOrFail();
        $authority = data_get($canonical->raw_payload, 'scoped_local_uat_payment_session_authority');
        $paymentPayload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $businessIdentity = $this->scopedLocalUatPaymentBusinessIdentity($payment, $paymentPayload);
        $canonicalPayload = is_array($canonical->raw_payload) ? $canonical->raw_payload : [];
        $canonicalBusinessIdentity = $this->scopedLocalUatPaymentBusinessIdentity($canonical, $canonicalPayload);
        $provider = $this->scopedLocalUatCanonicalPaymentProvider($payment);
        $canonicalProvider = $this->scopedLocalUatCanonicalPaymentProvider($canonical);
        $canonicalReference = trim((string) $canonical->provider_reference);
        $expectedIdempotencyHash = $provider === null
            ? ''
            : $this->scopedLocalUatEffectIdempotencyHash(
                (string) ($duplicate['run_id'] ?? ''),
                self::SCOPED_EFFECT_PAYMENT_CREATE,
                $businessIdentity,
                $this->scopedLocalUatAmountMinorUnits($payment),
                $this->scopedLocalUatCurrency($payment),
                $provider,
                null,
            );
        $invalid = ! is_array($authority)
            || (string) ($authority['create_status'] ?? '') !== 'completed'
            || (int) ($authority['payment_id'] ?? 0) !== (int) $canonical->getKey()
            || ! hash_equals((string) ($authority['idempotency_hash'] ?? ''), $idempotencyHash)
            || ! hash_equals($expectedIdempotencyHash, $idempotencyHash)
            || ! hash_equals((string) ($authority['business_identity_hash'] ?? ''), $businessIdentity['identity_hash'])
            || ! hash_equals($canonicalBusinessIdentity['identity_hash'], $businessIdentity['identity_hash'])
            || ! hash_equals((string) ($duplicate['business_identity_hash'] ?? ''), $businessIdentity['identity_hash'])
            || (string) ($duplicate['run_id'] ?? '') !== (string) ($authority['run_id'] ?? '')
            || (string) ($duplicate['provider'] ?? '') !== (string) ($authority['provider'] ?? '')
            || $provider === null
            || $provider !== $canonicalProvider
            || $provider !== (string) ($authority['provider'] ?? '')
            || $canonicalReference === ''
            || ! hash_equals((string) ($authority['provider_reference'] ?? ''), $canonicalReference)
            || ! hash_equals((string) ($duplicate['provider_reference_hash'] ?? ''), hash('sha256', $canonicalReference))
            || (string) ($duplicate['amount_minor'] ?? '') !== $this->scopedLocalUatAmountMinorUnits($payment)
            || (string) ($authority['amount_minor'] ?? '') !== $this->scopedLocalUatAmountMinorUnits($canonical)
            || $this->scopedLocalUatAmountMinorUnits($payment) !== $this->scopedLocalUatAmountMinorUnits($canonical)
            || (string) ($duplicate['currency'] ?? '') !== $this->scopedLocalUatCurrency($payment)
            || (string) ($authority['currency'] ?? '') !== $this->scopedLocalUatCurrency($canonical)
            || $this->scopedLocalUatCurrency($payment) !== $this->scopedLocalUatCurrency($canonical)
            || ! hash_equals(
                (string) ($duplicate['canonical_authority_fingerprint'] ?? ''),
                $this->scopedLocalUatDuplicateAuthorityFingerprint($authority),
            );
        if ($invalid) {
            throw new ConflictHttpException('DUPLICATE_POINTER_AUTHORITY_MISMATCH: Duplicate pointer full stored authority ile eşleşmiyor.');
        }

        return $canonical;
    }

    /** @param array<string, mixed> $authority */
    private function scopedLocalUatDuplicateAuthorityFingerprint(array $authority): string
    {
        $canonical = [
            'profile_id' => (string) ($authority['profile_id'] ?? ''),
            'run_id' => (string) ($authority['run_id'] ?? ''),
            'payment_id' => (int) ($authority['payment_id'] ?? 0),
            'business_entity_type' => (string) ($authority['business_entity_type'] ?? ''),
            'business_entity_id' => (int) ($authority['business_entity_id'] ?? 0),
            'payment_purpose' => (string) ($authority['payment_purpose'] ?? ''),
            'business_identity_hash' => (string) ($authority['business_identity_hash'] ?? ''),
            'part_request_fingerprint' => $authority['part_request_fingerprint'] ?? null,
            'selected_serials_fingerprint' => $authority['selected_serials_fingerprint'] ?? null,
            'selected_serial_count' => (int) ($authority['selected_serial_count'] ?? 0),
            'idempotency_hash' => (string) ($authority['idempotency_hash'] ?? ''),
            'provider' => (string) ($authority['provider'] ?? ''),
            'provider_reference_hash' => hash('sha256', (string) ($authority['provider_reference'] ?? '')),
            'amount_minor' => (string) ($authority['amount_minor'] ?? ''),
            'currency' => (string) ($authority['currency'] ?? ''),
        ];

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, mixed> $payload */
    private function scopedLocalUatCallbackSubmission(array $payload, string $storedProvider): array
    {
        $providerMode = $this->scopedLocalUatProviderModeForProvider($storedProvider);
        if (array_key_exists('provider_mode', $payload)) {
            if (! is_scalar($payload['provider_mode']) || trim((string) $payload['provider_mode']) === '') {
                throw new ConflictHttpException('scoped_uat_callback_field_malformed: provider_mode present fakat geçersiz.');
            }
            $requestedProviderMode = strtolower(trim((string) $payload['provider_mode']));
            if (! in_array($requestedProviderMode, ['local', 'sandbox', 'live'], true)
                || ! hash_equals($providerMode, $requestedProviderMode)) {
                throw new ConflictHttpException('scoped_uat_callback_provider_mode_mismatch: provider_mode stored authority ile eşleşmiyor.');
            }
        }
        $runId = $this->scopedLocalUatCallbackAlias(
            $payload,
            ['run_id', 'scoped_local_uat_run_id'],
            'run_id',
            fn (mixed $value): string => $this->scopedLocalUatCallbackIdentifier($value, 'run_id'),
        );
        $provider = $this->scopedLocalUatCallbackAlias(
            $payload,
            ['provider', 'provider_key', 'payment_provider'],
            'provider',
            function (mixed $value) use ($providerMode): string {
                if (! is_scalar($value) || trim((string) $value) === '') {
                    throw new ConflictHttpException('scoped_uat_callback_field_malformed: provider present fakat geçersiz.');
                }
                try {
                    $provider = $this->canonicalScopedLocalUatProviderIdentity(
                        (string) $value,
                        (string) $providerMode,
                    );
                } catch (ConflictHttpException) {
                    throw new ConflictHttpException('scoped_uat_callback_field_malformed: provider canonical değil.');
                }

                return $provider;
            },
        );
        $reference = $this->scopedLocalUatCallbackAlias(
            $payload,
            ['provider_reference', 'provider_token', 'token'],
            'provider_reference',
            fn (mixed $value): string => $this->scopedLocalUatCallbackIdentifier($value, 'provider_reference'),
        );
        $paymentReference = $this->scopedLocalUatCallbackAlias(
            $payload,
            ['provider_payment_reference', 'payment_reference', 'paymentId'],
            'provider_payment_reference',
            fn (mixed $value): string => $this->scopedLocalUatCallbackIdentifier($value, 'provider_payment_reference'),
        );
        $transactionReference = $this->scopedLocalUatCallbackAlias(
            $payload,
            ['provider_transaction_reference', 'payment_transaction_id', 'paymentTransactionId', 'transaction_id'],
            'provider_transaction_reference',
            fn (mixed $value): string => $this->scopedLocalUatCallbackIdentifier($value, 'provider_transaction_reference'),
        );
        $localPaymentId = $this->scopedLocalUatCallbackAlias(
            $payload,
            ['payment_id', 'mount_payment_id'],
            'payment_id',
            fn (mixed $value): string => (string) $this->scopedLocalUatPositiveIdentifier($value, 'payment_id'),
        );
        $amountMinor = $this->scopedLocalUatCallbackAlias(
            $payload,
            ['amount', 'paid_amount'],
            'amount',
            fn (mixed $value): string => $this->scopedLocalUatStrictDecimalMinorUnits($value, 'callback amount'),
        );
        $currency = $this->scopedLocalUatCallbackAlias(
            $payload,
            ['currency', 'payment_currency', 'currency_code'],
            'currency',
            function (mixed $value): string {
                if (! is_scalar($value)) {
                    throw new ConflictHttpException('scoped_uat_callback_field_malformed: currency present fakat geçersiz.');
                }
                $currency = strtoupper(trim((string) $value));
                if (! preg_match('/^[A-Z]{3}$/', $currency)) {
                    throw new ConflictHttpException('scoped_uat_callback_field_malformed: currency canonical değil.');
                }

                return $currency;
            },
        );
        $profileId = $this->scopedLocalUatCallbackAlias(
            $payload,
            ['profile_id', 'scoped_local_uat_profile_id'],
            'profile_id',
            fn (mixed $value): string => $this->scopedLocalUatCallbackIdentifier($value, 'profile_id'),
        );
        $callbackIdentity = $this->scopedLocalUatCallbackAlias(
            $payload,
            ['callback_id', 'idempotency_key'],
            'callback_identity',
            fn (mixed $value): string => hash('sha256', $this->scopedLocalUatCallbackIdentifier($value, 'callback_identity')),
        );
        $submission = [
            'run_id' => $runId,
            'profile_id' => $profileId,
            'provider' => $provider,
            'provider_mode' => $providerMode,
            'provider_reference_hash' => $reference === null ? null : hash('sha256', $reference),
            'provider_payment_reference_hash' => $paymentReference === null ? null : hash('sha256', $paymentReference),
            'provider_transaction_reference_hash' => $transactionReference === null ? null : hash('sha256', $transactionReference),
            'payment_id' => $localPaymentId,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'callback_identity_hash' => $callbackIdentity,
        ];

        return [
            ...$submission,
            'submission_fingerprint' => hash('sha256', json_encode($submission, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            '_provider_payment_reference_binding' => $paymentReference,
            '_provider_transaction_reference_binding' => $transactionReference,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $aliases
     * @param  callable(mixed): string  $normalizer
     */
    private function scopedLocalUatCallbackAlias(
        array $payload,
        array $aliases,
        string $field,
        callable $normalizer,
    ): ?string {
        $values = [];
        foreach ($aliases as $alias) {
            if (! array_key_exists($alias, $payload)) {
                continue;
            }
            $values[] = $normalizer($payload[$alias]);
        }
        $values = array_values(array_unique($values));
        if (count($values) > 1) {
            throw new ConflictHttpException('scoped_uat_callback_alias_conflict: '.$field.' aliasları çelişiyor.');
        }

        return $values[0] ?? null;
    }

    private function scopedLocalUatCallbackIdentifier(mixed $value, string $field): string
    {
        if (! is_scalar($value)) {
            throw new ConflictHttpException('scoped_uat_callback_field_malformed: '.$field.' present fakat scalar değil.');
        }
        $identifier = trim((string) $value);
        if ($identifier === '' || strlen($identifier) > 512 || preg_match('/[\x00-\x1F\x7F]/', $identifier)) {
            throw new ConflictHttpException('scoped_uat_callback_field_malformed: '.$field.' present fakat geçersiz.');
        }

        return $identifier;
    }

    /** @param array<string, mixed> $previous @param array<string, mixed> $submission */
    private function assertScopedLocalUatCallbackReplayMatches(array $previous, array $submission): void
    {
        $previousSubmission = is_array($previous['callback_submission'] ?? null)
            ? $previous['callback_submission']
            : null;
        if ($previousSubmission === null
            || ! hash_equals(
                (string) ($previousSubmission['submission_fingerprint'] ?? ''),
                (string) ($submission['submission_fingerprint'] ?? ''),
            )) {
            throw new ConflictHttpException('scoped_uat_callback_replay_mismatch: Duplicate callback exact stored submission ile eşleşmiyor.');
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $claim
     * @param  array<string, mixed>  $payload
     */
    private function assertScopedLocalUatStoredPaymentSessionAuthority(
        array $settings,
        array $claim,
        TechnicalServiceMountPayment $payment,
        array $payload,
    ): void {
        $authority = $payload['scoped_local_uat_payment_session_authority'] ?? null;
        if (! is_array($authority) || (string) ($authority['create_status'] ?? '') !== 'completed') {
            throw new ConflictHttpException('scoped_uat_callback_session_authority_missing: Başarılı stored payment create/session authority bulunamadı.');
        }

        $snapshotProvider = (string) data_get(
            $settings,
            'manual_e2e_run_snapshot.scoped_local_uat_sandbox_payment_provider',
            '',
        );
        $businessIdentity = $this->scopedLocalUatPaymentBusinessIdentity($payment, $payload);
        $providerReference = trim((string) $payment->provider_reference);
        $providerPaymentReference = trim((string) $payment->provider_payment_reference);
        $providerTransactionReference = trim((string) $payment->provider_transaction_reference);
        $paymentUrl = trim((string) $payment->payment_url);
        $providerMode = $this->scopedLocalUatProviderModeForProvider($snapshotProvider);
        $submission = is_array($claim['callback_submission'] ?? null) ? $claim['callback_submission'] : [];
        $createHistory = collect((array) ($payload['scoped_local_uat_effect_history'] ?? []))
            ->first(fn (mixed $entry): bool => is_array($entry)
                && (string) ($entry['operation'] ?? '') === self::SCOPED_EFFECT_PAYMENT_CREATE
                && (string) ($entry['status'] ?? '') === 'completed'
                && (int) ($entry['payment_id'] ?? 0) === (int) $payment->getKey()
                && (string) ($entry['profile_id'] ?? '') === ExternalEffectCapabilityRegistry::LOCAL_ALLOWLISTED_UAT_PROFILE
                && (string) ($entry['run_id'] ?? '') === (string) ($authority['run_id'] ?? '')
                && hash_equals((string) ($entry['idempotency_hash'] ?? ''), (string) ($authority['idempotency_hash'] ?? ''))
                && hash_equals((string) ($entry['business_identity_hash'] ?? ''), $businessIdentity['identity_hash'])
                && (string) ($entry['provider'] ?? '') === $snapshotProvider
                && (string) ($entry['provider_mode'] ?? '') === $providerMode
                && hash_equals((string) ($entry['provider_reference_hash'] ?? ''), hash('sha256', $providerReference))
                && hash_equals((string) ($entry['payment_url_hash'] ?? ''), hash('sha256', $paymentUrl))
                && (string) ($entry['amount_minor'] ?? '') === $this->scopedLocalUatAmountMinorUnits($payment)
                && (string) ($entry['currency'] ?? '') === $this->scopedLocalUatCurrency($payment));
        if (! is_array($createHistory)) {
            throw new ConflictHttpException('scoped_uat_callback_session_history_missing: Successful create history callback öncesi zorunludur.');
        }
        $invalid = (string) ($authority['profile_id'] ?? '') !== ExternalEffectCapabilityRegistry::LOCAL_ALLOWLISTED_UAT_PROFILE
            || (string) ($authority['run_id'] ?? '') !== (string) ($claim['run_id'] ?? '')
            || (int) ($authority['payment_id'] ?? 0) !== (int) $payment->getKey()
            || (string) ($authority['business_entity_type'] ?? '') !== $businessIdentity['entity_type']
            || (int) ($authority['business_entity_id'] ?? 0) !== $businessIdentity['entity_id']
            || (string) ($authority['payment_purpose'] ?? '') !== $businessIdentity['payment_purpose']
            || ! hash_equals(
                (string) ($authority['business_identity_hash'] ?? ''),
                $businessIdentity['identity_hash'],
            )
            || (string) ($authority['part_request_fingerprint'] ?? '') !== (string) $businessIdentity['part_request_fingerprint']
            || (string) ($authority['selected_serials_fingerprint'] ?? '') !== (string) $businessIdentity['selected_serials_fingerprint']
            || (int) ($authority['selected_serial_count'] ?? -1) !== $businessIdentity['selected_serial_count']
            || (string) ($authority['provider'] ?? '') !== $snapshotProvider
            || (string) ($claim['provider'] ?? '') !== $snapshotProvider
            || $this->scopedLocalUatCanonicalPaymentProvider($payment) !== $snapshotProvider
            || (string) ($authority['provider_mode'] ?? '') !== $providerMode
            || $providerReference === ''
            || (string) ($authority['provider_reference'] ?? '') !== $providerReference
            || $paymentUrl === ''
            || ! hash_equals((string) ($authority['payment_url_hash'] ?? ''), hash('sha256', $paymentUrl))
            || (string) ($authority['amount_minor'] ?? '') !== $this->scopedLocalUatAmountMinorUnits($payment)
            || (string) ($authority['currency'] ?? '') !== $this->scopedLocalUatCurrency($payment)
            || ! filter_var($authority['synthetic_uat'] ?? false, FILTER_VALIDATE_BOOL)
            || ((string) ($submission['run_id'] ?? '') !== ''
                && (string) ($submission['run_id'] ?? '') !== (string) ($authority['run_id'] ?? ''))
            || ((string) ($submission['profile_id'] ?? '') !== ''
                && (string) ($submission['profile_id'] ?? '') !== (string) ($authority['profile_id'] ?? ''))
            || ((string) ($submission['provider'] ?? '') !== ''
                && (string) ($submission['provider'] ?? '') !== $snapshotProvider)
            || ((string) ($submission['provider_mode'] ?? '') !== ''
                && (string) $submission['provider_mode'] !== $providerMode)
            || ((string) ($submission['provider_reference_hash'] ?? '') !== ''
                && ! hash_equals(
                    (string) $submission['provider_reference_hash'],
                    hash('sha256', $providerReference),
                ))
            || ((string) ($submission['provider_payment_reference_hash'] ?? '') !== ''
                && $providerPaymentReference !== ''
                && ! hash_equals(
                    (string) $submission['provider_payment_reference_hash'],
                    hash('sha256', $providerPaymentReference),
                ))
            || ((string) ($submission['provider_transaction_reference_hash'] ?? '') !== ''
                && $providerTransactionReference !== ''
                && ! hash_equals(
                    (string) $submission['provider_transaction_reference_hash'],
                    hash('sha256', $providerTransactionReference),
                ))
            || ((string) ($submission['payment_id'] ?? '') !== ''
                && (int) $submission['payment_id'] !== (int) $payment->getKey())
            || ((string) ($submission['amount_minor'] ?? '') !== ''
                && (string) $submission['amount_minor'] !== $this->scopedLocalUatAmountMinorUnits($payment))
            || ((string) ($submission['currency'] ?? '') !== ''
                && (string) $submission['currency'] !== $this->scopedLocalUatCurrency($payment));
        if ($invalid) {
            throw new ConflictHttpException('scoped_uat_callback_session_authority_mismatch: Callback stored session/run/provider bağıyla eşleşmiyor.');
        }
    }

    /** @param array<string, mixed> $claim */
    private function scopedLocalUatPaymentSessionAuthority(
        TechnicalServiceMountPayment $payment,
        array $claim,
    ): array {
        $provider = $this->scopedLocalUatCanonicalPaymentProvider($payment);
        $providerReference = trim((string) $payment->provider_reference);
        $paymentUrl = trim((string) $payment->payment_url);
        if ($provider === null
            || $provider !== (string) ($claim['provider'] ?? '')
            || $providerReference === ''
            || $paymentUrl === '') {
            throw new ConflictHttpException('scoped_uat_payment_session_incomplete: Provider create sonucu stored session authority üretemedi.');
        }

        return [
            'schema_version' => 1,
            'create_status' => 'completed',
            'profile_id' => ExternalEffectCapabilityRegistry::LOCAL_ALLOWLISTED_UAT_PROFILE,
            'run_id' => (string) ($claim['run_id'] ?? ''),
            'payment_id' => (int) $payment->getKey(),
            'business_entity_type' => (string) ($claim['business_entity_type'] ?? ''),
            'business_entity_id' => (int) ($claim['business_entity_id'] ?? 0),
            'payment_purpose' => (string) ($claim['payment_purpose'] ?? ''),
            'business_identity_hash' => (string) ($claim['business_identity_hash'] ?? ''),
            'part_request_fingerprint' => $claim['part_request_fingerprint'] ?? null,
            'selected_serials_fingerprint' => $claim['selected_serials_fingerprint'] ?? null,
            'selected_serial_count' => (int) ($claim['selected_serial_count'] ?? 0),
            'idempotency_hash' => (string) ($claim['idempotency_hash'] ?? ''),
            'provider' => $provider,
            'provider_mode' => $this->scopedLocalUatProviderModeForProvider($provider),
            'provider_reference' => $providerReference,
            'provider_reference_hash' => hash('sha256', $providerReference),
            'payment_url_hash' => hash('sha256', $paymentUrl),
            'amount_minor' => (string) ($claim['amount_minor'] ?? ''),
            'currency' => (string) ($claim['currency'] ?? ''),
            'configuration_fingerprint' => (string) ($claim['configuration_fingerprint'] ?? ''),
            'synthetic_uat' => true,
            'created_at' => now()->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $claim */
    private function bindScopedLocalUatCallbackReferences(
        TechnicalServiceMountPayment $payment,
        array $claim,
    ): void {
        $submission = is_array($claim['callback_submission'] ?? null)
            ? $claim['callback_submission']
            : [];
        $bindings = [
            'provider_payment_reference' => $submission['_provider_payment_reference_binding'] ?? null,
            'provider_transaction_reference' => $submission['_provider_transaction_reference_binding'] ?? null,
        ];
        $updates = [];
        foreach ($bindings as $field => $incoming) {
            if ($incoming === null) {
                continue;
            }
            $incoming = trim((string) $incoming);
            if ($incoming === '') {
                throw new ConflictHttpException('scoped_uat_callback_field_malformed: Callback reference boş olamaz.');
            }
            $stored = trim((string) $payment->getAttribute($field));
            if ($stored === '') {
                $updates[$field] = $incoming;

                continue;
            }
            if (! hash_equals($stored, $incoming)) {
                throw new ConflictHttpException('scoped_uat_callback_reference_mismatch: Stored provider reference değiştirilemez.');
            }
        }
        if ($updates !== []) {
            $payment->forceFill($updates)->save();
        }
    }

    /** @param array<string, mixed> $claim */
    private function scopedLocalUatHistoryClaim(array $claim): array
    {
        $history = $claim;
        if (is_array($history['callback_submission'] ?? null)) {
            unset(
                $history['callback_submission']['_provider_payment_reference_binding'],
                $history['callback_submission']['_provider_transaction_reference_binding'],
            );
        }

        return $history;
    }

    private function scopedLocalUatCanonicalPaymentProvider(TechnicalServiceMountPayment $payment): ?string
    {
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $mode = (string) (
            data_get($payload, 'provider_mode')
            ?? data_get($payload, 'provider_decision.provider_mode')
            ?? data_get($payload, 'provider_gateway.mode')
            ?? ''
        );

        try {
            return $this->canonicalScopedLocalUatProviderIdentity((string) $payment->provider, $mode);
        } catch (ConflictHttpException) {
            return null;
        }
    }

    /**
     * Validate the locked payment against the locked run snapshot before any
     * claim, settings, history, audit, or provider-decision mutation.
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertScopedLocalUatPaymentProviderIdentityBeforeClaim(
        TechnicalServiceMountPayment $payment,
        array $payload,
        string $snapshotProviderIdentity,
        string $providerFamily,
        string $providerMode,
    ): string {
        if (! in_array($providerFamily, ['fake', 'iyzico'], true)) {
            throw new ConflictHttpException('scoped_uat_provider_identity_invalid: Payment provider family-only authority çözülemedi.');
        }

        $storedProviderFamily = strtolower(trim((string) $payment->provider));
        if (! hash_equals($providerFamily, $storedProviderFamily)) {
            throw new ConflictHttpException('scoped_uat_provider_snapshot_mismatch: provider_family_mismatch; payment provider family stored authority ile eşleşmiyor.');
        }

        $providerIdentity = $this->canonicalScopedLocalUatProviderIdentity($providerFamily, $providerMode);
        if (! hash_equals($snapshotProviderIdentity, $providerIdentity)) {
            throw new ConflictHttpException('scoped_uat_provider_snapshot_mismatch: Payment provider family/mode immutable run snapshot ile eşleşmiyor.');
        }

        $storedMode = Arr::get($payload, 'provider_mode')
            ?? Arr::get($payload, 'provider_decision.provider_mode')
            ?? Arr::get($payload, 'provider_gateway.mode')
            ?? Arr::get($payload, 'provider_gateway.provider_mode');
        if ($storedMode !== null && (! is_scalar($storedMode)
            || ! hash_equals(
                $providerIdentity,
                $this->canonicalScopedLocalUatProviderIdentity($storedProviderFamily, (string) $storedMode),
            ))) {
            throw new ConflictHttpException('scoped_uat_provider_mode_mismatch: Payment provider mode stored authority ile eşleşmiyor.');
        }

        return $providerIdentity;
    }

    /**
     * @return array{family:string,mode:string,identity:string}
     */
    private function lockedScopedLocalUatPaymentProviderAuthority(PageConfig $page): array
    {
        $layout = is_array($page->layout_json) ? $page->layout_json : [];
        $realProviderEnabled = filter_var(
            Arr::get(
                $layout,
                TechnicalServicePaymentProviderSettingsService::REAL_PROVIDER_ENABLED_KEY,
                config('payments.real_provider_enabled', false),
            ),
            FILTER_VALIDATE_BOOLEAN,
        );
        $configuredProvider = strtolower(trim((string) Arr::get(
            $layout,
            TechnicalServicePaymentProviderSettingsService::PROVIDER_KEY,
            config('payments.provider_name', config('payments.provider', 'iyzico')),
        )));
        $providerFamily = $realProviderEnabled
            ? match ($configuredProvider) {
                'fake', 'fake_payment' => 'fake',
                'iyzico', 'iyzico_sandbox', 'iyzico_live' => 'iyzico',
                default => throw new ConflictHttpException('scoped_uat_provider_identity_invalid: Locked provider family authority canonical değil.'),
            }
        : 'fake';
        $providerMode = $providerFamily === 'fake'
            ? 'local'
            : match (strtolower(trim((string) Arr::get(
                $layout,
                TechnicalServicePaymentProviderSettingsService::PROVIDER_MODE_KEY,
                config('payments.gateway.mode', config('payments.environment', 'sandbox')),
            )))) {
                'sandbox' => 'sandbox',
                'live' => 'live',
                default => throw new ConflictHttpException('scoped_uat_provider_identity_invalid: Locked provider mode authority canonical değil.'),
            };
        $identityInput = $realProviderEnabled ? $configuredProvider : $providerFamily;

        return [
            'family' => $providerFamily,
            'mode' => $providerMode,
            'identity' => $this->canonicalScopedLocalUatProviderIdentity($identityInput, $providerMode),
        ];
    }

    private function scopedLocalUatProviderModeForProvider(string $provider): string
    {
        return match ($provider) {
            'fake_payment' => 'local',
            'iyzico_sandbox' => 'sandbox',
            'iyzico_live' => 'live',
            default => throw new ConflictHttpException('scoped_uat_provider_snapshot_invalid: Stored provider mode authority çözülemedi.'),
        };
    }

    /**
     * @param  array<string, mixed>  $paymentPayload
     */
    private function scopedLocalUatPaymentConfigurationFingerprint(array $paymentPayload): string
    {
        $notificationRecipients = array_values(array_unique(array_filter(array_map(
            static fn (mixed $recipient): string => strtolower(trim((string) $recipient)),
            (array) data_get($paymentPayload, 'payment_notification.recipients', []),
        ))));
        sort($notificationRecipients);

        $profile = [
            'configured_provider' => (string) ($paymentPayload['configured_provider'] ?? ''),
            'effective_provider' => (string) ($paymentPayload['provider'] ?? ''),
            'provider_mode' => (string) ($paymentPayload['provider_mode'] ?? ''),
            'provider_transport' => (string) ($paymentPayload['provider_transport'] ?? ''),
            'real_provider_enabled' => (bool) ($paymentPayload['real_provider_enabled'] ?? true),
            'fake_active' => (bool) ($paymentPayload['fake_active'] ?? false),
            'credential_source' => (string) ($paymentPayload['secret_source'] ?? ''),
            'credential_reference' => hash('sha256', implode('|', [
                (string) data_get($paymentPayload, 'credentials.masked_api_key', ''),
                (string) data_get($paymentPayload, 'credentials.masked_secret_key', ''),
                (string) data_get($paymentPayload, 'credentials.last_updated_at', ''),
            ])),
            'notification_enabled' => (bool) data_get($paymentPayload, 'payment_notification.enabled', false),
            'notification_recipient_fingerprint' => hash('sha256', implode('|', $notificationRecipients)),
            'payment_origin' => (string) data_get($paymentPayload, 'back_url.public_base_url', ''),
            'sandbox_contract_revision' => 'local-sandbox-effect:v1',
        ];

        return hash('sha256', json_encode($profile, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>  $mikro
     * @return array<string, mixed>
     */
    private function scopedLocalUatMikroInvariant(array $mikro): array
    {
        $runtimeProfile = [
            'api_version' => (string) ($mikro['api_version'] ?? ''),
            'base_url' => (string) ($mikro['base_url'] ?? ''),
            'company_code' => (string) ($mikro['company_code'] ?? ''),
            'branch_code' => (string) ($mikro['branch_code'] ?? ''),
            'fiscal_year' => (string) ($mikro['fiscal_year'] ?? ''),
            'operation_controls' => $this->canonicalizeFingerprintValue((array) ($mikro['operation_controls'] ?? [])),
        ];

        return [
            'active' => (bool) ($mikro['enabled'] ?? false),
            'read_sync' => (bool) ($mikro['read_sync_enabled'] ?? false),
            'write' => (bool) ($mikro['write_enabled'] ?? false),
            'write_approval_required' => (bool) ($mikro['write_approval_required'] ?? true),
            'runtime_profile_fingerprint' => hash(
                'sha256',
                json_encode($runtimeProfile, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ),
        ];
    }

    private function canonicalizeFingerprintValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalizeFingerprintValue($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonicalizeFingerprintValue($item), $value);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{whatsapp:int,sms:int,email:int,total:int}
     */
    private function scopedLocalUatMessagingAttemptCounts(array $settings, string $runId): array
    {
        $dispatchHistory = collect((array) ($settings['manual_e2e_window_history'] ?? []))
            ->filter(fn (mixed $entry): bool => is_array($entry)
                && (string) ($entry['run_id'] ?? '') === $runId);
        $effectHistory = collect((array) ($settings['scoped_local_uat_effect_history'] ?? []))
            ->filter(fn (mixed $entry): bool => is_array($entry)
                && (string) ($entry['run_id'] ?? '') === $runId
                && (string) ($entry['channel'] ?? '') === 'email'
                && filter_var($entry['attempted'] ?? false, FILTER_VALIDATE_BOOL));
        $activeEffect = is_array($settings['scoped_local_uat_active_effect_claim'] ?? null)
            ? $settings['scoped_local_uat_active_effect_claim']
            : null;
        $activeEmail = $activeEffect !== null
            && (string) ($activeEffect['run_id'] ?? '') === $runId
            && (string) ($activeEffect['channel'] ?? '') === 'email'
            && filter_var($activeEffect['attempted'] ?? false, FILTER_VALIDATE_BOOL)
                ? 1
                : 0;
        $whatsapp = $dispatchHistory->where('channel', 'whatsapp')->count();
        $sms = $dispatchHistory->where('channel', 'sms')->count();
        $email = $effectHistory->count() + $activeEmail;

        return [
            'whatsapp' => $whatsapp,
            'sms' => $sms,
            'email' => $email,
            'total' => $whatsapp + $sms + $email,
        ];
    }

    /**
     * Legacy direct clients never possess the queue claim/permit tuple.
     *
     * @return array{allowed:false,code:string,message:string}
     */
    public function directProviderExecutionBlock(string $provider): array
    {
        $provider = $this->normalizeProviderKey($provider);
        $mode = $this->executionMode($this->settings());

        return [
            'allowed' => false,
            'code' => $mode === self::OUTBOUND_EXECUTION_MODE_LOCAL
                ? 'outbound_execution_mode_local'
                : 'direct_provider_claim_required',
            'message' => $mode === self::OUTBOUND_EXECUTION_MODE_LOCAL
                ? 'Mesajlaşma çalışma modu Lokal; dış provider çağrısı kapalı.'
                : "{$provider} doğrudan çağrılamaz; server-side dispatch claim ve tek kullanımlık permit zorunlu.",
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function update(array $values): array
    {
        $lock = $this->acquireLifecycleLock();

        try {
            DB::transaction(function () use ($values): void {
                $locked = $this->lockedAuthoritativeSettings();
                $current = $locked['settings'];
                $next = $this->buildGenericSettingsUpdate($current, $values);
                $this->persistAuthoritativeSettings($locked, $next);
            });
        } finally {
            $lock();
        }

        return $this->payload();
    }

    /**
     * Open the persistent, allowlisted local manual-acceptance sender profile.
     * The profile is code-owned; callers can only supply an audit reason.
     *
     * @return array<string, mixed>
     */
    public function activateLocalManualAcceptance(string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Yerel manuel kabul profili için neden zorunlu.',
            ]);
        }
        if ($this->runtimeEnvironment() === 'production') {
            throw new ConflictHttpException('Yerel manuel kabul profili production ortamında açılamaz.');
        }

        $lock = $this->acquireLifecycleLock();
        try {
            DB::transaction(function () use ($reason): void {
                $locked = $this->lockedAuthoritativeSettings();
                $current = $locked['settings'];
                if ($this->localManualAcceptanceIsCurrent($current)) {
                    return;
                }

                $this->assertLocalManualAcceptanceActivationReady($current);
                $activatedAt = CarbonImmutable::now()->toIso8601String();
                $next = [
                    ...$current,
                    'manual_e2e_enabled' => false,
                    'manual_e2e_phase' => self::MANUAL_E2E_PHASE_FROZEN,
                    'manual_e2e_active_run_id' => null,
                    'manual_e2e_open_window' => null,
                    'manual_e2e_active_claim' => null,
                    'test_mode_enabled' => false,
                    'real_send_enabled' => true,
                    'queue_paused' => false,
                    'local_manual_acceptance_enabled' => true,
                    'local_manual_acceptance_activated_at' => $activatedAt,
                    'local_manual_acceptance_reason' => Str::limit($reason, 160, ''),
                    'local_manual_acceptance_profile_fingerprint' => null,
                ];
                $next['local_manual_acceptance_profile_fingerprint'] = $this->localManualAcceptanceFingerprint($next);
                $this->validateSettings($next);
                $this->persistAuthoritativeSettings($locked, $next);
            });
        } finally {
            $lock();
        }

        return $this->localManualAcceptancePayload();
    }

    /** @return array<string, mixed> */
    public function deactivateLocalManualAcceptance(string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Yerel manuel kabul profilini kapatma nedeni zorunlu.',
            ]);
        }

        $lock = $this->acquireLifecycleLock();
        try {
            DB::transaction(function () use ($reason): void {
                $locked = $this->lockedAuthoritativeSettings();
                $current = $locked['settings'];
                if (is_array($current['normal_outbound_active_claim'] ?? null)) {
                    throw new ConflictHttpException('Provider çağrısı sürerken yerel manuel kabul profili kapatılamaz.');
                }

                $next = $this->deactivateLocalManualAcceptanceSettings($current, $reason);
                $this->validateSettings($next);
                $this->persistAuthoritativeSettings($locked, $next);
            });
        } finally {
            $lock();
        }

        return $this->localManualAcceptancePayload();
    }

    /** @return array<string, mixed> */
    public function localManualAcceptancePayload(): array
    {
        $settings = $this->settings();
        $runtimeProfileFingerprint = $this->localManualAcceptanceFingerprint($settings);
        $allowlist = array_values(array_filter(array_map(
            fn (mixed $phone): string => $this->normalizePhone((string) $phone),
            (array) ($settings['manual_e2e_allowlisted_phones'] ?? []),
        )));
        $worker = $this->outboundWorkerLeaseStatus();

        return [
            'profile' => self::LOCAL_MANUAL_ACCEPTANCE_PROFILE,
            'enabled' => $this->localManualAcceptanceIsCurrent($settings),
            'activated_at' => $this->nullableScalar($settings['local_manual_acceptance_activated_at'] ?? null),
            'profile_fingerprint' => $this->nullableScalar($settings['local_manual_acceptance_profile_fingerprint'] ?? null),
            'runtime_profile_fingerprint' => $runtimeProfileFingerprint,
            'allowlist' => array_map(fn (string $phone): string => $this->maskPhone($phone), $allowlist),
            'allowlist_fingerprints' => array_map(fn (string $phone): string => hash('sha256', $phone), $allowlist),
            'real_send_enabled' => (bool) ($settings['real_send_enabled'] ?? false),
            'queue_paused' => (bool) ($settings['queue_paused'] ?? true),
            'worker' => $worker,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>|null  $current
     */
    public function assertGenericUpdateAllowed(array $values, ?array $current = null): void
    {
        $submittedFields = collect(array_keys(Arr::dot($values)))
            ->map(fn (string $path): string => (string) str($path)->afterLast('.'))
            ->merge(array_keys($values))
            ->unique()
            ->values()
            ->all();
        $lifecycleFields = array_values(array_intersect($submittedFields, self::GENERIC_LIFECYCLE_FIELDS));
        if ($lifecycleFields !== []) {
            $message = 'Manual E2E ve gerçek gönderim durumu genel ayarlar üzerinden değiştirilemez. Manual E2E kontrol panelindeki güvenli açma/dondurma aksiyonunu kullanın.';

            throw ValidationException::withMessages(array_fill_keys($lifecycleFields, $message));
        }

        $current ??= $this->settings();
        if ($this->executionMode($current) === self::OUTBOUND_EXECUTION_MODE_LIVE) {
            throw new ConflictHttpException('Canlı çalışma modunda provider ve mesaj ayarları değiştirilemez. Önce çalışma modunu Lokal olarak dondurun.');
        }
        $context = TechnicalServiceManualE2ERunContext::fromSettings($current);
        if (! $context->enabled()
            && $context->activeRunId() === null
            && ! is_array($current['normal_outbound_active_claim'] ?? null)) {
            return;
        }

        $lockedFields = array_values(array_intersect(array_keys($values), self::ACTIVE_RUN_LOCKED_FIELDS));
        if ($lockedFields !== []) {
            throw new ConflictHttpException('Aktif Manual E2E oturumu varken gönderim güvenliği ayarları değiştirilemez. Önce gönderimleri dondurun.');
        }
    }

    /**
     * Payment mode and notification recipients are part of the immutable
     * scoped run profile even though their settings are owned by another page.
     *
     * @param  array<string, mixed>  $values
     */
    public function assertScopedLocalUatPaymentSettingsMutationAllowed(array $values): void
    {
        $settings = $this->settings();
        $context = TechnicalServiceManualE2ERunContext::fromSettings($settings);
        if (! $context->enabled()
            || $context->activeRunId() === null
            || ! $this->isScopedLocalUatSettings($settings)) {
            return;
        }

        $locked = array_intersect(array_keys($values), [
            'real_provider_enabled',
            'provider_mode',
            'payment_notification_enabled',
            'payment_notification_recipients',
        ]);
        if ($locked !== []) {
            throw new ConflictHttpException('Aktif allowlistli Yerel UAT run sırasında ödeme modu ve e-posta allowlisti değiştirilemez.');
        }
    }

    private function assertNoActiveRunMutation(?array $settings = null): void
    {
        $current = $settings ?? $this->settings();
        $context = TechnicalServiceManualE2ERunContext::fromSettings($current);
        if ($this->executionMode($current) === self::OUTBOUND_EXECUTION_MODE_LIVE
            || (bool) ($current['local_manual_acceptance_enabled'] ?? false)
            || $context->enabled()
            || $context->activeRunId() !== null
            || is_array($current['scoped_local_uat_active_effect_claim'] ?? null)
            || is_array($current['normal_outbound_active_claim'] ?? null)) {
            throw new ConflictHttpException('Canlı çalışma modu veya aktif provider yaşam döngüsü varken gönderim güvenliği ayarları değiştirilemez. Önce çalışma modunu Lokal olarak dondurun.');
        }
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function buildGenericSettingsUpdate(array $current, array $values): array
    {
        $this->assertGenericUpdateAllowed($values, $current);
        $next = $current;

        foreach ([
            'messaging_enabled',
            'real_send_enabled',
            'test_mode_enabled',
            'allow_browser_smoke_send',
            'allow_test_fixture_send',
            'ops_whatsapp_enabled',
            'manual_e2e_partner_portal_origin_enabled',
        ] as $key) {
            if (array_key_exists($key, $values)) {
                $next[$key] = (bool) $values[$key];
            }
        }

        foreach ([
            'provider_key',
            'active_provider',
            'default_provider',
            'fallback_provider',
        ] as $key) {
            if (array_key_exists($key, $values)) {
                $next[$key] = $this->normalizeProviderKey((string) $values[$key]);
            }
        }

        if (array_key_exists('active_provider', $values) && ! array_key_exists('provider_key', $values)) {
            $next['provider_key'] = $next['active_provider'];
        }

        if (array_key_exists('provider_priority', $values)) {
            $next['provider_priority'] = $this->normalizeProviderPriority((array) $values['provider_priority']);
        }

        if (array_key_exists('shared_test_phone', $values)) {
            $next['test_phone'] = $this->normalizePhone((string) $values['shared_test_phone']);
        } elseif (array_key_exists('test_phone', $values)) {
            $next['test_phone'] = $this->normalizePhone((string) $values['test_phone']);
        }
        if ((array_key_exists('shared_test_phone', $values) || array_key_exists('test_phone', $values))
            && ! array_key_exists('customer_test_phone', $values)
            && ! array_key_exists('technician_ops_test_phone', $values)) {
            $next['customer_test_phone'] = $next['test_phone'];
            $next['technician_ops_test_phone'] = $next['test_phone'];
        }
        foreach (['customer_test_phone', 'technician_ops_test_phone'] as $roleTestPhone) {
            if (array_key_exists($roleTestPhone, $values)) {
                $next[$roleTestPhone] = $this->normalizePhone((string) $values[$roleTestPhone]);
            }
        }

        if (array_key_exists('ops_whatsapp_phone', $values)) {
            $next['ops_whatsapp_phone'] = $this->normalizePhone((string) $values['ops_whatsapp_phone']);
        }

        if (array_key_exists('manual_e2e_partner_portal_origin', $values)) {
            $origin = trim((string) $values['manual_e2e_partner_portal_origin']);
            $next['manual_e2e_partner_portal_origin'] = $origin === ''
                ? null
                : (PartnerPortalPublicUrl::normalizeOrigin($origin) ?? $origin);
        }

        if (array_key_exists('manual_e2e_allowlisted_phones', $values)) {
            $next['manual_e2e_allowlisted_phones'] = array_values(array_unique(array_filter(array_map(
                fn (mixed $phone): string => $this->normalizePhone((string) $phone),
                (array) $values['manual_e2e_allowlisted_phones'],
            ))));
        }

        foreach ([
            'send_delay_seconds',
            'duplicate_cooldown_minutes',
            'hourly_limit',
            'daily_limit',
            'max_auto_retries',
            'manual_e2e_ttl_seconds',
        ] as $key) {
            if (array_key_exists($key, $values)) {
                $next[$key] = (int) $values[$key];
            }
        }

        if (array_key_exists('message_types', $values)) {
            $next['message_types'] = $this->mergeMessageTypeSettings($current['message_types'], (array) $values['message_types']);
        }

        if (array_key_exists('nac_sms', $values)) {
            $next['nac_sms'] = $this->mergeNacSmsSettings($current['nac_sms'], (array) $values['nac_sms']);
        }

        if (array_key_exists('evo_whatsapp', $values)) {
            $next['evo_whatsapp'] = $this->mergeEvoWhatsappSettings($current['evo_whatsapp'], (array) $values['evo_whatsapp']);
        }

        if (array_key_exists('mikro_api', $values)) {
            $next['mikro_api'] = $this->mergeMikroApiSettings($current['mikro_api'], (array) $values['mikro_api']);
        }

        $next = $this->applyAdminLocalDeliveryAuthority($current, $next);
        $this->validateSettings($next);

        return $next;
    }

    /**
     * In non-production, the Admin messaging and real-send toggles are the
     * normal delivery authority. Legacy profile fields are cleared so a
     * release fingerprint cannot silently override those stored settings.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $next
     * @return array<string, mixed>
     */
    private function applyAdminLocalDeliveryAuthority(array $current, array $next): array
    {
        if ($this->runtimeEnvironment() === 'production'
            || $this->executionMode($next) !== self::OUTBOUND_EXECUTION_MODE_LOCAL) {
            return $next;
        }

        if (! (bool) ($next['real_send_enabled'] ?? false)) {
            return $this->deactivateLocalManualAcceptanceSettings($next, 'Admin gerçek gönderim ayarı kapalı.');
        }

        $preflight = [
            ...$next,
            'real_send_enabled' => false,
            'queue_paused' => true,
            'local_manual_acceptance_enabled' => false,
            'local_manual_acceptance_activated_at' => null,
            'local_manual_acceptance_profile_fingerprint' => null,
        ];
        try {
            $this->assertLocalManualAcceptanceActivationReady($preflight);
        } catch (ConflictHttpException $exception) {
            throw ValidationException::withMessages([
                'real_send_enabled' => $exception->getMessage(),
            ]);
        }

        return [
            ...$next,
            'real_send_enabled' => true,
            'queue_paused' => false,
            'local_manual_acceptance_enabled' => false,
            'local_manual_acceptance_activated_at' => null,
            'local_manual_acceptance_profile_fingerprint' => null,
            'local_manual_acceptance_reason' => 'Admin ayarları normal yerel gönderim otoritesidir.',
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function prepareManualE2E(array $values = []): array
    {
        $lock = $this->acquireLifecycleLock();

        try {
            $this->reconcileStaleManualE2EWorkerLease();
            if (! $this->lockAvailable(TechnicalServiceManualE2ERunContext::WORKER_LOCK_KEY)) {
                throw new ConflictHttpException('Başka bir Manual E2E worker çalışıyor veya worker lock sahipliği güvenli biçimde doğrulanamadı.');
            }

            DB::transaction(function () use ($values): void {
                $locked = $this->lockedAuthoritativeSettings();
                $current = $locked['settings'];
                $context = TechnicalServiceManualE2ERunContext::fromSettings($current);
                $scopedLocalUat = $this->executionMode($current) === self::OUTBOUND_EXECUTION_MODE_LOCAL;
                if ($this->runtimeEnvironment() === 'production') {
                    throw ValidationException::withMessages([
                        'manual_e2e' => 'Manual E2E production ortamında hazırlanamaz.',
                    ]);
                }
                if ($context->phase() !== self::MANUAL_E2E_PHASE_FROZEN
                    || $context->enabled()
                    || $context->activeRunId() !== null
                    || is_array($current['manual_e2e_open_window'] ?? null)
                    || is_array($current['manual_e2e_active_claim'] ?? null)
                    || is_array($current['scoped_local_uat_active_effect_claim'] ?? null)) {
                    throw new ConflictHttpException('Aktif Manual E2E oturumu zaten var. Yeni run için önce gönderimleri dondurun.');
                }

                if ($scopedLocalUat && $values !== []) {
                    throw ValidationException::withMessages([
                        'manual_e2e' => 'Allowlistli Yerel UAT profili, TTL ve izin listeleri request tarafından değiştirilemez.',
                    ]);
                }

                $allowlist = $scopedLocalUat
                    ? (array) ($current['manual_e2e_allowlisted_phones'] ?? [])
                    : (array_key_exists('manual_e2e_allowlisted_phones', $values)
                        ? array_values(array_unique(array_filter(array_map(
                            fn (mixed $phone): string => $this->normalizePhone((string) $phone),
                            (array) $values['manual_e2e_allowlisted_phones'],
                        ))))
                        : (array) ($current['manual_e2e_allowlisted_phones'] ?? []));
                $maximumTtl = $scopedLocalUat
                    ? self::SCOPED_LOCAL_UAT_MAX_TTL_SECONDS
                    : TechnicalServiceManualE2ERunContext::DEFAULT_TTL_SECONDS;
                $ttl = max(60, min(
                    $maximumTtl,
                    (int) ($values['manual_e2e_ttl_seconds'] ?? $current['manual_e2e_ttl_seconds'] ?? $maximumTtl),
                ));
                $candidate = [
                    ...$current,
                    'manual_e2e_allowlisted_phones' => $allowlist,
                    'manual_e2e_ttl_seconds' => $ttl,
                ];
                $readiness = $this->manualE2EReadinessForSettings($candidate, false);
                if (! $readiness['eligible']) {
                    throw ValidationException::withMessages([
                        'manual_e2e' => array_map(
                            fn (array $blocker): string => $blocker['message'],
                            $readiness['blockers'],
                        ),
                    ]);
                }

                $startedAt = CarbonImmutable::now();
                $runId = TechnicalServiceManualE2ERunContext::generateRunId($startedAt);
                while ($runId === ($current['manual_e2e_last_run_id'] ?? null)) {
                    $runId = TechnicalServiceManualE2ERunContext::generateRunId($startedAt);
                }
                $messageBodyContract = $scopedLocalUat
                    ? self::scopedLocalUatMessageBodyContract($runId)
                    : null;

                $runSnapshot = $scopedLocalUat
                    ? app(ExternalExecutionControlPlaneService::class)->scopedLocalUatSnapshot()
                    : $this->executionModeSnapshot();
                if ($scopedLocalUat) {
                    $scopedState = $this->scopedLocalUatControlPlaneState(false);
                    $runSnapshot = [
                        ...$runSnapshot,
                        'scoped_local_uat_run_id' => $runId,
                        'scoped_local_uat_created_after' => $startedAt->toIso8601String(),
                        'scoped_local_uat_email_allowlist_fingerprint' => (string) $scopedState['email_allowlist_fingerprint'],
                        'scoped_local_uat_event_policy_fingerprint' => (string) $scopedState['event_policy_fingerprint'],
                        'scoped_local_uat_sandbox_payment_provider' => (string) $scopedState['sandbox_payment_provider'],
                        'scoped_local_uat_limits' => app(ExternalEffectCapabilityRegistry::class)
                            ->localAllowlistedUatProfile()['limits'],
                        'expected_body_token' => $messageBodyContract['expected_body_token'],
                        'expected_body_token_fingerprint' => $messageBodyContract['expected_body_token_fingerprint'],
                    ];
                }
                $effectWindowContract = self::manualE2EEffectWindowContract(
                    is_scalar($runSnapshot['scoped_local_uat_profile_id'] ?? null)
                        ? (string) $runSnapshot['scoped_local_uat_profile_id']
                        : null,
                );
                $runSnapshot = [
                    ...$runSnapshot,
                    'effect_window_seconds' => $effectWindowContract['effect_window_seconds'],
                    'effect_window_fingerprint' => $effectWindowContract['effect_window_fingerprint'],
                ];

                $next = [
                    ...$candidate,
                    'manual_e2e_enabled' => true,
                    'real_send_enabled' => false,
                    'test_mode_enabled' => false,
                    'queue_paused' => true,
                    'manual_e2e_phase' => self::MANUAL_E2E_PHASE_PREPARED,
                    'manual_e2e_active_run_id' => $runId,
                    'manual_e2e_started_at' => $startedAt->toIso8601String(),
                    'manual_e2e_created_after' => $startedAt->toIso8601String(),
                    'manual_e2e_expires_at' => $startedAt->addSeconds($ttl)->toIso8601String(),
                    'manual_e2e_open_window' => null,
                    'manual_e2e_active_claim' => null,
                    'scoped_local_uat_active_effect_claim' => null,
                    'manual_e2e_run_snapshot' => [
                        ...$runSnapshot,
                        'allowlist_fingerprint' => $this->allowlistFingerprint($allowlist),
                        'evo_ready' => (bool) $readiness['evo_ready'],
                        'nac_ready' => (bool) $readiness['nac_ready'],
                        'prepared_at' => $startedAt->toIso8601String(),
                        'prepared_by' => Auth::id(),
                    ],
                ];
                $this->validateSettings($next);
                $this->persistAuthoritativeSettings($locked, $next);
            });
        } finally {
            $lock();
        }

        return $this->payload();
    }

    /**
     * Backward-compatible service name with prepare-only semantics.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function enableManualE2E(array $values = []): array
    {
        return $this->prepareManualE2E($values);
    }

    /**
     * @return array<string, mixed>
     */
    public function openManualE2ESendWindow(string $runId, int $dispatchId): array
    {
        $lock = $this->acquireLifecycleLock();

        try {
            DB::transaction(function () use ($runId, $dispatchId): void {
                $locked = $this->lockedAuthoritativeSettings();
                $current = $locked['settings'];
                $context = TechnicalServiceManualE2ERunContext::fromSettings($current);
                $normalizedRunId = TechnicalServiceManualE2ERunContext::normalizeRunId($runId);

                if ($normalizedRunId === null || $context->activeRunId() !== $normalizedRunId) {
                    throw new ConflictHttpException('Manual E2E run veya dispatch doğrulanamadı.');
                }
                if ($context->phase() !== self::MANUAL_E2E_PHASE_PREPARED || $context->contextBlockingReason() !== null) {
                    throw new ConflictHttpException('Manual E2E run gönderim penceresi açmaya hazır değil.');
                }
                if (is_array($current['manual_e2e_open_window'] ?? null)
                    || is_array($current['manual_e2e_active_claim'] ?? null)
                    || is_array($current['scoped_local_uat_active_effect_claim'] ?? null)) {
                    throw new ConflictHttpException('Manual E2E run içinde açık veya sonuçlanmamış bir gönderim penceresi var.');
                }
                if (collect((array) ($current['manual_e2e_window_history'] ?? []))->contains(
                    fn (mixed $entry): bool => is_array($entry)
                        && (string) ($entry['run_id'] ?? '') === $normalizedRunId
                        && (int) ($entry['dispatch_id'] ?? 0) === $dispatchId,
                )) {
                    throw new ConflictHttpException('Bu dispatch için Manual E2E gönderim penceresi daha önce tüketildi veya kapatıldı.');
                }

                $dispatch = TechnicalServiceMessageDispatch::query()->whereKey($dispatchId)->lockForUpdate()->first();
                if (! $dispatch instanceof TechnicalServiceMessageDispatch) {
                    throw new ConflictHttpException('Manual E2E run veya dispatch doğrulanamadı.');
                }

                $authoritative = $this->assertManualE2EDispatchEligible($current, $context, $dispatch);
                $executionAuthorization = $this->dispatchExecutionAuthorizationForSettings($dispatch, true, $current);
                if (! $executionAuthorization['allowed']) {
                    throw new ConflictHttpException((string) $executionAuthorization['message']);
                }
                $effectWindowContract = self::resolvedManualE2EEffectWindowContract(
                    is_array($current['manual_e2e_run_snapshot'] ?? null)
                        ? $current['manual_e2e_run_snapshot']
                        : [],
                );
                $runExpiresAt = $context->expiresAt();
                if ($effectWindowContract === null || $runExpiresAt === null) {
                    throw new ConflictHttpException('Manual E2E effect-window snapshotı doğrulanamadı.');
                }
                $openedAt = CarbonImmutable::now();
                $effectExpiresAt = $openedAt->addSeconds($effectWindowContract['effect_window_seconds']);
                if ($runExpiresAt->lt($effectExpiresAt)) {
                    $effectExpiresAt = $runExpiresAt;
                }
                if (! $openedAt->lt($effectExpiresAt)) {
                    throw new ConflictHttpException('Manual E2E effect-window süresi doldu.');
                }
                $window = [
                    'id' => (string) Str::uuid(),
                    'status' => 'open',
                    'run_id' => $normalizedRunId,
                    'dispatch_id' => $dispatch->id,
                    'provider' => $authoritative['provider'],
                    'channel' => $authoritative['channel'],
                    'recipient_fingerprint' => $authoritative['recipient_fingerprint'],
                    'role_target' => $authoritative['role_target'],
                    'request_id' => $authoritative['request_id'],
                    'offer_cycle_id' => $authoritative['offer_cycle_id'],
                    'idempotency_fingerprint' => $authoritative['idempotency_fingerprint'],
                    'body_fingerprint' => $authoritative['body_fingerprint'],
                    'effect_window_seconds' => $effectWindowContract['effect_window_seconds'],
                    'effect_window_fingerprint' => $effectWindowContract['effect_window_fingerprint'],
                    'opened_at' => $openedAt->toIso8601String(),
                    'expires_at' => $effectExpiresAt->toIso8601String(),
                    'maximum_attempts' => 1,
                    'opened_by' => Auth::id(),
                ];

                $next = [
                    ...$current,
                    'manual_e2e_phase' => self::MANUAL_E2E_PHASE_WINDOW_OPEN,
                    'manual_e2e_open_window' => $window,
                    'real_send_enabled' => true,
                    'queue_paused' => false,
                ];
                $this->validateSettings($next);
                $this->persistAuthoritativeSettings($locked, $next);
            });
        } finally {
            $lock();
        }

        return $this->payload();
    }

    /**
     * @return array<string, mixed>
     */
    public function closeManualE2ESendWindow(string $runId, int $dispatchId): array
    {
        $lock = $this->acquireLifecycleLock();

        try {
            DB::transaction(function () use ($runId, $dispatchId): void {
                $locked = $this->lockedAuthoritativeSettings();
                $current = $locked['settings'];
                $context = TechnicalServiceManualE2ERunContext::fromSettings($current);
                $normalizedRunId = TechnicalServiceManualE2ERunContext::normalizeRunId($runId);
                if ($normalizedRunId === null || $context->activeRunId() !== $normalizedRunId) {
                    throw new ConflictHttpException('Manual E2E run veya dispatch doğrulanamadı.');
                }

                $window = is_array($current['manual_e2e_open_window'] ?? null)
                    ? $current['manual_e2e_open_window']
                    : null;
                $claim = is_array($current['manual_e2e_active_claim'] ?? null)
                    ? $current['manual_e2e_active_claim']
                    : null;
                if ($window !== null
                    && ((string) ($window['run_id'] ?? '') !== $normalizedRunId
                        || (int) ($window['dispatch_id'] ?? 0) !== $dispatchId)) {
                    throw new ConflictHttpException('Manual E2E run veya dispatch doğrulanamadı.');
                }
                if ($claim !== null
                    && ((string) ($claim['run_id'] ?? '') !== $normalizedRunId
                        || (int) ($claim['dispatch_id'] ?? 0) !== $dispatchId)) {
                    throw new ConflictHttpException('Manual E2E run veya dispatch doğrulanamadı.');
                }
                $historyContainsDispatch = collect((array) ($current['manual_e2e_window_history'] ?? []))->contains(
                    fn (mixed $entry): bool => is_array($entry)
                        && (string) ($entry['run_id'] ?? '') === $normalizedRunId
                        && (int) ($entry['dispatch_id'] ?? 0) === $dispatchId,
                );
                $dispatch = TechnicalServiceMessageDispatch::query()
                    ->whereKey($dispatchId)
                    ->lockForUpdate()
                    ->first();
                if (! $dispatch instanceof TechnicalServiceMessageDispatch
                    || TechnicalServiceManualE2ERunContext::dispatchRunId((array) $dispatch->metadata) !== $normalizedRunId) {
                    throw new ConflictHttpException('Manual E2E run veya dispatch doğrulanamadı.');
                }
                $durablyClosed = filter_var(
                    data_get($dispatch->metadata, 'manual_e2e_window_consumed', false),
                    FILTER_VALIDATE_BOOL,
                ) && (string) data_get($dispatch->metadata, 'manual_e2e_consumed_run_id', '') === $normalizedRunId;
                if ($window === null && $claim === null && ! $historyContainsDispatch && ! $durablyClosed) {
                    throw new ConflictHttpException('Kapatılacak Manual E2E gönderim penceresi bulunamadı.');
                }

                if ($window !== null) {
                    $dispatch->forceFill([
                        'metadata' => [
                            ...((array) $dispatch->metadata),
                            'manual_e2e_window_consumed' => true,
                            'manual_e2e_consumed_window_id' => (string) ($window['id'] ?? ''),
                            'manual_e2e_consumed_run_id' => $normalizedRunId,
                            'manual_e2e_window_closed_at' => now()->toIso8601String(),
                        ],
                    ])->save();
                }

                $next = [
                    ...$current,
                    'manual_e2e_phase' => self::MANUAL_E2E_PHASE_PREPARED,
                    'manual_e2e_open_window' => null,
                    'real_send_enabled' => false,
                    'queue_paused' => true,
                ];
                if ($window !== null) {
                    $next['manual_e2e_window_history'] = $this->appendWindowHistory(
                        (array) ($current['manual_e2e_window_history'] ?? []),
                        [...$window, 'status' => 'closed', 'closed_at' => now()->toIso8601String(), 'closed_by' => Auth::id()],
                    );
                }
                $this->validateSettings($next);
                $this->persistAuthoritativeSettings($locked, $next);
            });
        } finally {
            $lock();
        }

        return $this->payload();
    }

    /**
     * Atomically consumes an open window and persists attempt=1 before HTTP.
     *
     * @return array{claim_nonce:string,run_id:string,dispatch_id:int,provider:string,channel:string}
     */
    public function claimManualE2ESend(int $dispatchId, ?string $expectedRunId): array
    {
        $lock = $this->acquireLifecycleLock();

        try {
            return DB::transaction(function () use ($dispatchId, $expectedRunId): array {
                $locked = $this->lockedAuthoritativeSettings();
                $current = $locked['settings'];
                $context = TechnicalServiceManualE2ERunContext::fromSettings($current);
                $runId = TechnicalServiceManualE2ERunContext::normalizeRunId($expectedRunId);
                $window = is_array($current['manual_e2e_open_window'] ?? null)
                    ? $current['manual_e2e_open_window']
                    : null;
                if ($context->phase() !== self::MANUAL_E2E_PHASE_WINDOW_OPEN
                    || $context->contextBlockingReason() !== null
                    || $runId === null
                    || $runId !== $context->activeRunId()
                    || $window === null
                    || (string) ($window['status'] ?? '') !== 'open'
                    || (string) ($window['run_id'] ?? '') !== $runId
                    || (int) ($window['dispatch_id'] ?? 0) !== $dispatchId
                    || $this->invalidWindowTtl($window, $current)
                    || $this->windowExpired($window)
                    || ! (bool) ($current['real_send_enabled'] ?? false)
                    || (bool) ($current['queue_paused'] ?? true)
                ) {
                    throw new ConflictHttpException('Exact Manual E2E gönderim penceresi doğrulanamadı.');
                }

                $dispatch = TechnicalServiceMessageDispatch::query()->whereKey($dispatchId)->lockForUpdate()->first();
                if (! $dispatch instanceof TechnicalServiceMessageDispatch) {
                    throw new ConflictHttpException('Exact Manual E2E gönderim penceresi doğrulanamadı.');
                }
                $authoritative = $this->assertManualE2EDispatchEligible($current, $context, $dispatch);
                $executionAuthorization = $this->dispatchExecutionAuthorizationForSettings($dispatch, true, $current);
                if (! $executionAuthorization['allowed']) {
                    throw new ConflictHttpException((string) $executionAuthorization['message']);
                }
                if (! $this->manualE2ESecurityTupleMatches($window, $authoritative)) {
                    throw new ConflictHttpException('Exact Manual E2E gönderim penceresi doğrulanamadı.');
                }

                $claimNonce = Str::random(64);
                $claimHash = hash('sha256', $claimNonce);
                $claimedAt = now()->toIso8601String();
                $claim = [
                    ...$window,
                    'status' => 'claimed',
                    'claim_hash' => $claimHash,
                    'claimed_at' => $claimedAt,
                ];
                $dispatch->forceFill([
                    'status' => TechnicalServiceMessageDispatch::STATUS_SENDING,
                    'sending_started_at' => now(),
                    'attempt_count' => 1,
                    'metadata' => [
                        ...((array) $dispatch->metadata),
                        'manual_e2e_window_id' => $window['id'],
                        'manual_e2e_claim_hash' => $claimHash,
                        'manual_e2e_claimed_at' => $claimedAt,
                        'provider_send_attempted' => false,
                        'external_provider_call' => false,
                    ],
                ])->save();

                $next = [
                    ...$current,
                    'manual_e2e_phase' => self::MANUAL_E2E_PHASE_PREPARED,
                    'manual_e2e_open_window' => null,
                    'manual_e2e_active_claim' => $claim,
                    'real_send_enabled' => false,
                    'queue_paused' => true,
                ];
                $this->validateSettings($next);
                $this->persistAuthoritativeSettings($locked, $next);

                return [
                    'claim_nonce' => $claimNonce,
                    'run_id' => $runId,
                    'dispatch_id' => $dispatch->id,
                    'provider' => $authoritative['provider'],
                    'channel' => $authoritative['channel'],
                ];
            });
        } finally {
            $lock();
        }
    }

    public function startManualE2ETransport(int $dispatchId, string $claimNonce): void
    {
        $lock = $this->acquireLifecycleLock();

        try {
            DB::transaction(function () use ($dispatchId, $claimNonce): void {
                $locked = $this->lockedAuthoritativeSettings();
                $current = $locked['settings'];
                $context = TechnicalServiceManualE2ERunContext::fromSettings($current);
                $claim = is_array($current['manual_e2e_active_claim'] ?? null)
                    ? $current['manual_e2e_active_claim']
                    : null;
                $claimHash = hash('sha256', $claimNonce);
                if ($context->contextBlockingReason() !== null
                    || $context->phase() !== self::MANUAL_E2E_PHASE_PREPARED
                    || $claim === null
                    || (string) ($claim['status'] ?? '') !== 'claimed'
                    || (int) ($claim['dispatch_id'] ?? 0) !== $dispatchId
                    || (string) ($claim['run_id'] ?? '') !== $context->activeRunId()
                    || ! hash_equals((string) ($claim['claim_hash'] ?? ''), $claimHash)
                    || $this->invalidWindowTtl($claim, $current)
                    || $this->windowExpired($claim)
                ) {
                    throw new ConflictHttpException('Manual E2E transport izni geçersiz veya daha önce kullanılmış.');
                }

                $dispatch = TechnicalServiceMessageDispatch::query()->whereKey($dispatchId)->lockForUpdate()->first();
                $authoritative = $dispatch instanceof TechnicalServiceMessageDispatch
                    ? $this->manualE2EDispatchSecurityTuple($dispatch, $current)
                    : null;
                if (! $dispatch instanceof TechnicalServiceMessageDispatch
                    || $dispatch->status !== TechnicalServiceMessageDispatch::STATUS_SENDING
                    || (int) $dispatch->attempt_count !== 1
                    || ! hash_equals((string) data_get($dispatch->metadata, 'manual_e2e_claim_hash', ''), $claimHash)
                    || $authoritative === null
                    || ! $this->manualE2ESecurityTupleMatches($claim, $authoritative)
                    || TechnicalServiceManualE2ERunContext::dispatchRunId((array) $dispatch->metadata) !== (string) ($claim['run_id'] ?? '')
                    || filled($dispatch->provider_message_id)
                    || $dispatch->sent_at !== null
                ) {
                    throw new ConflictHttpException('Manual E2E transport izni dispatch ile eşleşmiyor.');
                }
                $executionAuthorization = $this->dispatchExecutionAuthorizationForSettings($dispatch, true, $current);
                if (! $executionAuthorization['allowed']) {
                    throw new ConflictHttpException((string) $executionAuthorization['message']);
                }

                $startedAt = now()->toIso8601String();
                $dispatch->forceFill([
                    'metadata' => [
                        ...((array) $dispatch->metadata),
                        'manual_e2e_transport_permit_consumed' => true,
                        'manual_e2e_external_call_state' => 'authorized_not_confirmed',
                        'manual_e2e_http_started_at' => $startedAt,
                    ],
                ])->save();
                $current['manual_e2e_active_claim'] = [
                    ...$claim,
                    'status' => 'http_started',
                    'http_started_at' => $startedAt,
                ];
                $this->persistAuthoritativeSettings($locked, $current);
            });
        } finally {
            $lock();
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function finalizeManualE2ESend(int $dispatchId, string $claimNonce, array $result): TechnicalServiceMessageDispatch
    {
        $lock = $this->acquireLifecycleLock();

        try {
            return DB::transaction(function () use ($dispatchId, $claimNonce, $result): TechnicalServiceMessageDispatch {
                $locked = $this->lockedAuthoritativeSettings();
                $current = $locked['settings'];
                $claimHash = hash('sha256', $claimNonce);
                $dispatch = TechnicalServiceMessageDispatch::query()->whereKey($dispatchId)->lockForUpdate()->firstOrFail();
                if (hash_equals((string) data_get($dispatch->metadata, 'manual_e2e_finalized_claim_hash', ''), $claimHash)) {
                    return $dispatch;
                }

                $claim = is_array($current['manual_e2e_active_claim'] ?? null)
                    ? $current['manual_e2e_active_claim']
                    : null;
                if ($claim === null
                    || ! in_array((string) ($claim['status'] ?? ''), ['claimed', 'http_started'], true)
                    || (int) ($claim['dispatch_id'] ?? 0) !== $dispatchId
                    || ! hash_equals((string) ($claim['claim_hash'] ?? ''), $claimHash)
                    || ! hash_equals((string) data_get($dispatch->metadata, 'manual_e2e_claim_hash', ''), $claimHash)
                    || (int) $dispatch->attempt_count !== 1
                ) {
                    throw new ConflictHttpException('Manual E2E sonucu claim ile eşleşmiyor.');
                }

                $providerStatus = (string) ($result['provider_status'] ?? 'manual_e2e_provider_result_missing');
                $transportStarted = (string) ($claim['status'] ?? '') === 'http_started';
                $accepted = $transportStarted
                    && in_array((string) ($result['status'] ?? ''), TechnicalServiceMessageDispatch::SUCCESS_STATUSES, true)
                    && filled($result['provider_message_id'] ?? null);
                $ambiguous = $transportStarted && (
                    (bool) ($result['ambiguous'] ?? false)
                    || $providerStatus === 'exception'
                );
                $finalizedAt = now()->toIso8601String();
                $terminalStatus = $accepted
                    ? TechnicalServiceMessageDispatch::STATUS_SENT
                    : TechnicalServiceMessageDispatch::STATUS_BLOCKED;
                $outcome = $accepted ? 'provider_accepted' : ($ambiguous ? 'ambiguous_no_retry' : 'rejected_no_retry');
                $errorCode = $accepted
                    ? null
                    : ($ambiguous ? 'manual_e2e_ambiguous_no_retry' : 'manual_e2e_provider_rejected_no_retry');

                $dispatch->forceFill([
                    'status' => $terminalStatus,
                    'provider_status' => $providerStatus,
                    'provider_message_id' => $accepted ? (string) $result['provider_message_id'] : null,
                    'provider_response_redacted' => $result['response'] ?? null,
                    'response_payload' => $result['response'] ?? null,
                    'last_error_code' => $errorCode,
                    'last_error_message_redacted' => $accepted ? null : ($result['error'] ?? 'Manual E2E provider sonucu tekrar gönderime kapatıldı.'),
                    'error_message' => $accepted ? null : ($result['error'] ?? 'Manual E2E provider sonucu tekrar gönderime kapatıldı.'),
                    'sent_at' => $accepted ? now() : null,
                    'failed_at' => $accepted ? null : now(),
                    'metadata' => [
                        ...((array) $dispatch->metadata),
                        'provider_send_attempted' => $transportStarted,
                        'external_provider_call' => $transportStarted,
                        'manual_e2e_external_call_state' => $transportStarted
                            ? 'transport_invoked'
                            : 'not_invoked',
                        'manual_e2e_finalized_claim_hash' => $claimHash,
                        'manual_e2e_finalized_at' => $finalizedAt,
                        'manual_e2e_outcome' => $outcome,
                        'provider_accepted' => $accepted,
                        'delivery_proven' => false,
                        'manual_e2e_replay_blocked' => true,
                    ],
                ])->save();

                $history = [
                    ...$claim,
                    'status' => 'finalized',
                    'outcome' => $outcome,
                    'finalized_at' => $finalizedAt,
                    'provider_message_id_present' => $accepted,
                ];
                $next = [
                    ...$current,
                    'manual_e2e_phase' => self::MANUAL_E2E_PHASE_PREPARED,
                    'manual_e2e_active_claim' => null,
                    'real_send_enabled' => false,
                    'queue_paused' => true,
                    'manual_e2e_window_history' => $this->appendWindowHistory(
                        (array) ($current['manual_e2e_window_history'] ?? []),
                        $history,
                    ),
                ];
                $this->validateSettings($next);
                $this->persistAuthoritativeSettings($locked, $next);

                return $dispatch;
            });
        } finally {
            $lock();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function freezeManualE2E(): array
    {
        $lock = $this->acquireLifecycleLock();
        $activeRunId = null;

        try {
            DB::transaction(function () use (&$activeRunId): void {
                $locked = $this->lockedAuthoritativeSettings();
                $current = $locked['settings'];
                $context = TechnicalServiceManualE2ERunContext::fromSettings($current);
                $activeRunId = $context->activeRunId();
                $history = (array) ($current['manual_e2e_window_history'] ?? []);
                foreach (['manual_e2e_open_window', 'manual_e2e_active_claim'] as $field) {
                    if (is_array($current[$field] ?? null)) {
                        $history = $this->appendWindowHistory($history, [
                            ...$current[$field],
                            'status' => 'frozen_unresolved',
                            'frozen_at' => now()->toIso8601String(),
                            'frozen_by' => Auth::id(),
                        ]);
                    }
                }
                $effectHistory = (array) ($current['scoped_local_uat_effect_history'] ?? []);
                $dispatchingEffect = null;
                if (is_array($current['scoped_local_uat_active_effect_claim'] ?? null)
                    && (string) ($current['scoped_local_uat_active_effect_claim']['status'] ?? '') === 'dispatching') {
                    $dispatchingEffect = [
                        ...$current['scoped_local_uat_active_effect_claim'],
                        'run_frozen_at' => now()->toIso8601String(),
                    ];
                    $payment = TechnicalServiceMountPayment::query()
                        ->whereKey((int) ($dispatchingEffect['payment_id'] ?? 0))
                        ->lockForUpdate()
                        ->first();
                    if ($payment instanceof TechnicalServiceMountPayment) {
                        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
                        $payload['scoped_local_uat_effect_claim'] = $dispatchingEffect;
                        $payment->forceFill(['raw_payload' => $payload])->save();
                    }
                } elseif (is_array($current['scoped_local_uat_active_effect_claim'] ?? null)) {
                    $frozenEffect = [
                        ...$current['scoped_local_uat_active_effect_claim'],
                        'status' => 'frozen_unresolved',
                        'outcome' => 'failed_no_retry',
                        'frozen_at' => now()->toIso8601String(),
                        'frozen_by' => Auth::id(),
                        'replay_blocked' => true,
                    ];
                    $effectHistory = $this->appendWindowHistory($effectHistory, $frozenEffect);
                    $payment = TechnicalServiceMountPayment::query()
                        ->whereKey((int) ($frozenEffect['payment_id'] ?? 0))
                        ->lockForUpdate()
                        ->first();
                    if ($payment instanceof TechnicalServiceMountPayment) {
                        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
                        $paymentHistory = is_array($payload['scoped_local_uat_effect_history'] ?? null)
                            ? $payload['scoped_local_uat_effect_history']
                            : [];
                        $payload['scoped_local_uat_effect_claim'] = null;
                        $payload['scoped_local_uat_effect_history'] = array_values([
                            ...$paymentHistory,
                            $frozenEffect,
                        ]);
                        $payment->forceFill(['raw_payload' => $payload])->save();
                    }
                }
                $next = $this->deactivateManualE2EContext($current, $context);
                $next['test_mode_enabled'] = false;
                $next['manual_e2e_window_history'] = $history;
                $next['scoped_local_uat_effect_history'] = $effectHistory;
                if ($dispatchingEffect !== null) {
                    $next['scoped_local_uat_active_effect_claim'] = $dispatchingEffect;
                }
                $this->validateSettings($next);
                $this->persistAuthoritativeSettings($locked, $next);
            });

            if ($activeRunId !== null) {
                $this->invalidateManualE2EWorkerLease($activeRunId);
            } else {
                $this->reconcileStaleManualE2EWorkerLease();
            }
        } finally {
            $lock();
        }

        return $this->payload();
    }

    public function manualE2EContext(): TechnicalServiceManualE2ERunContext
    {
        return TechnicalServiceManualE2ERunContext::fromSettings($this->settings());
    }

    /**
     * Run a non-Manual outbound path while lifecycle transitions are excluded.
     * The persisted state check is short; no database transaction remains open
     * while the callback may perform provider I/O.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withManualE2EFrozenOutbound(callable $callback): mixed
    {
        return $this->withManualE2EFrozenLifecycleLock($callback, true);
    }

    /**
     * Let the queue processor classify an already-attempted dispatch before
     * the unresolved-attempt guard runs. The processor must call
     * assertManualE2EFrozenOutboundLockHeld() before any new claim.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withManualE2EFrozenDispatchProcessing(callable $callback): mixed
    {
        return $this->withManualE2EFrozenLifecycleLock($callback, false);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withManualE2EFrozenLifecycleLock(callable $callback, bool $assertNoUnresolvedAttempt): mixed
    {
        $lock = $this->acquireLifecycleLock();

        try {
            DB::transaction(function (): void {
                $locked = $this->lockedAuthoritativeSettings();
                $current = $locked['settings'];
                $this->validateManualE2ELifecycleState($current);
                $context = TechnicalServiceManualE2ERunContext::fromSettings($current);
                if ($context->enabled()
                    || $context->activeRunId() !== null
                    || $context->phase() !== self::MANUAL_E2E_PHASE_FROZEN) {
                    throw ValidationException::withMessages([
                        'manual_e2e' => 'Aktif Manual E2E sırasında doğrudan provider çağrısı yasaktır; exact persisted claim zorunlu.',
                    ]);
                }
            });

            if ($assertNoUnresolvedAttempt) {
                // Direct clients create their dispatch inside the callback, so
                // unresolved attempts must stop them before that mutation.
                $this->assertManualE2EFrozenOutboundLockHeld(0);
            }

            return $callback();
        } finally {
            $lock();
        }
    }

    public function assertProviderHttpOutsideTransaction(): void
    {
        $connection = DB::connection();
        $transactionLevel = $connection->transactionLevel();
        $testHarnessTransaction = app()->runningUnitTests()
            && app()->bound('db.transactions')
            && $transactionLevel === 1;
        if (! $testHarnessTransaction
            && ($transactionLevel !== 0 || $connection->getPdo()->inTransaction())) {
            throw ValidationException::withMessages([
                'manual_e2e' => 'Provider outbound açık veya dış DB transaction içinden başlatılamaz.',
            ]);
        }
    }

    /**
     * Persist the one-time normal outbound transport permit before provider I/O.
     * The lifecycle ledger remains authoritative even if a stale dispatch model
     * later overwrites the dispatch status or metadata.
     */
    public function startNormalOutboundTransport(int $dispatchId, string $claimNonce): void
    {
        if (! self::$lifecycleLockHeldInProcess || DB::transactionLevel() !== 0) {
            throw new ConflictHttpException('Normal outbound transport izni güvenli transaction sınırında başlatılamadı.');
        }

        $claimNonce = trim($claimNonce);
        if ($claimNonce === '') {
            throw new ConflictHttpException('Normal outbound transport claim doğrulanamadı.');
        }

        DB::transaction(function () use ($dispatchId, $claimNonce): void {
            $locked = $this->lockedAuthoritativeSettings();
            $current = $locked['settings'];
            $this->validateManualE2ELifecycleState($current);
            $context = TechnicalServiceManualE2ERunContext::fromSettings($current);
            $durableAmbiguousClaim = $this->durableAmbiguousNormalOutboundClaim($current);
            if ($context->enabled()
                || $context->activeRunId() !== null
                || $context->phase() !== self::MANUAL_E2E_PHASE_FROZEN
                || (is_array($current['normal_outbound_active_claim'] ?? null) && $durableAmbiguousClaim === null)) {
                throw new ConflictHttpException('Normal outbound transport yalnız frozen lifecycle içinde başlatılabilir.');
            }

            $dispatch = TechnicalServiceMessageDispatch::query()
                ->whereKey($dispatchId)
                ->lockForUpdate()
                ->first();
            $claimHash = hash('sha256', $claimNonce);
            if (! $dispatch instanceof TechnicalServiceMessageDispatch
                || $dispatch->status !== TechnicalServiceMessageDispatch::STATUS_SENDING
                || (int) $dispatch->attempt_count !== 1
                || ! hash_equals(
                    (string) data_get($dispatch->metadata, 'normal_processor_claim_hash', ''),
                    $claimHash,
                )
                || filter_var(data_get($dispatch->metadata, 'provider_send_attempted', false), FILTER_VALIDATE_BOOL)
                || filled($dispatch->provider_message_id)
                || $dispatch->sent_at !== null
                || ! in_array((string) $dispatch->provider_key, ['evo_whatsapp', 'nac_sms'], true)
                || ! in_array((string) $dispatch->channel, ['whatsapp', 'sms'], true)) {
                throw new ConflictHttpException('Normal outbound transport claim dispatch ile eşleşmiyor.');
            }

            $target = $this->normalizePhone((string) $dispatch->target_phone);
            if ($target === '') {
                throw new ConflictHttpException('Normal outbound transport recipient doğrulanamadı.');
            }
            $executionAuthorization = $this->dispatchExecutionAuthorizationForSettings($dispatch, false, $current);
            if (! $executionAuthorization['allowed']) {
                throw new ConflictHttpException((string) $executionAuthorization['message']);
            }

            $startedAt = now()->toIso8601String();
            $tupleHash = $this->normalOutboundTupleHash($dispatch);
            $activeClaim = [
                'status' => 'http_started',
                'claim_hash' => $claimHash,
                'dispatch_id' => $dispatch->id,
                'provider' => (string) $dispatch->provider_key,
                'channel' => (string) $dispatch->channel,
                'recipient_fingerprint' => hash('sha256', $target),
                'tuple_hash' => $tupleHash,
                'attempt_count' => 1,
                'http_started_at' => $startedAt,
            ];

            $dispatch->forceFill([
                'metadata' => [
                    ...((array) $dispatch->metadata),
                    'provider_send_attempted' => true,
                    'external_provider_call' => true,
                    'normal_outbound_http_started_at' => $startedAt,
                    'normal_outbound_authoritative_claim_hash' => $claimHash,
                    'normal_outbound_tuple_hash' => $tupleHash,
                ],
            ])->save();

            $current['normal_outbound_active_claim'] = $activeClaim;
            $this->persistAuthoritativeSettings($locked, $current);
        });

        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Provider HTTP açık DB transaction içinde başlatılamaz.');
        }
    }

    public function normalOutboundTransportStarted(int $dispatchId, string $claimNonce): bool
    {
        $claim = $this->settings()['normal_outbound_active_claim'] ?? null;
        $claimHash = hash('sha256', trim($claimNonce));

        return is_array($claim)
            && (int) ($claim['dispatch_id'] ?? 0) === $dispatchId
            && in_array((string) ($claim['status'] ?? ''), ['http_started', 'ambiguous_no_retry'], true)
            && hash_equals((string) ($claim['claim_hash'] ?? ''), $claimHash);
    }

    public function terminalOutboundLineageHasAttempt(TechnicalServiceMessageDispatch $dispatch): bool
    {
        $metadata = (array) $dispatch->metadata;
        $terminalIdempotencyKey = trim((string) ($metadata['terminal_idempotency_key'] ?? ''));
        if (! filter_var($metadata['terminal_idempotency_requeued'] ?? false, FILTER_VALIDATE_BOOL)
            || $terminalIdempotencyKey === '') {
            return false;
        }

        return TechnicalServiceMessageDispatch::query()
            ->where('id', '<>', $dispatch->id)
            ->where(function ($lineage) use ($terminalIdempotencyKey): void {
                $lineage
                    ->where('idempotency_key', $terminalIdempotencyKey)
                    ->orWhere('metadata->terminal_idempotency_key', $terminalIdempotencyKey);
            })
            ->where(function ($attempted): void {
                $attempted
                    ->where('attempt_count', '>', 0)
                    ->orWhereNotNull('provider_message_id')
                    ->orWhereNotNull('sent_at');
            })
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function finalizeNormalOutboundSend(
        int $dispatchId,
        string $claimNonce,
        array $result,
    ): TechnicalServiceMessageDispatch {
        if (! self::$lifecycleLockHeldInProcess || DB::transactionLevel() !== 0) {
            throw new ConflictHttpException('Normal outbound sonucu güvenli transaction sınırında finalize edilemedi.');
        }

        $claimNonce = trim($claimNonce);
        if ($claimNonce === '') {
            throw new ConflictHttpException('Normal outbound finalize claim doğrulanamadı.');
        }

        return DB::transaction(function () use ($dispatchId, $claimNonce, $result): TechnicalServiceMessageDispatch {
            $locked = $this->lockedAuthoritativeSettings();
            $current = $locked['settings'];
            $claimHash = hash('sha256', $claimNonce);
            $dispatch = TechnicalServiceMessageDispatch::query()
                ->whereKey($dispatchId)
                ->lockForUpdate()
                ->firstOrFail();

            if (hash_equals(
                (string) data_get($dispatch->metadata, 'normal_outbound_finalized_claim_hash', ''),
                $claimHash,
            )) {
                return $dispatch;
            }

            $activeClaim = is_array($current['normal_outbound_active_claim'] ?? null)
                ? $current['normal_outbound_active_claim']
                : null;
            $historyContainsClaim = collect((array) ($current['normal_outbound_history'] ?? []))->contains(
                fn (mixed $entry): bool => is_array($entry)
                    && (int) ($entry['dispatch_id'] ?? 0) === $dispatchId
                    && hash_equals((string) ($entry['claim_hash'] ?? ''), $claimHash),
            );
            if ($activeClaim === null && $historyContainsClaim) {
                return $dispatch;
            }

            $target = $this->normalizePhone((string) $dispatch->target_phone);
            $tupleHash = $this->normalOutboundTupleHash($dispatch);
            if ($activeClaim === null
                || ! in_array((string) ($activeClaim['status'] ?? ''), ['http_started', 'ambiguous_no_retry'], true)
                || (int) ($activeClaim['dispatch_id'] ?? 0) !== $dispatchId
                || ! hash_equals((string) ($activeClaim['claim_hash'] ?? ''), $claimHash)
                || ! hash_equals((string) ($activeClaim['tuple_hash'] ?? ''), $tupleHash)
                || ! hash_equals((string) ($activeClaim['recipient_fingerprint'] ?? ''), hash('sha256', $target))
                || (string) ($activeClaim['provider'] ?? '') !== (string) $dispatch->provider_key
                || (string) ($activeClaim['channel'] ?? '') !== (string) $dispatch->channel
                || filled($dispatch->provider_message_id)
                || $dispatch->sent_at !== null) {
                throw new ConflictHttpException('Normal outbound sonucu authoritative claim ile eşleşmiyor.');
            }

            $providerStatus = trim((string) ($result['provider_status'] ?? 'normal_outbound_result_missing'));
            $accepted = in_array((string) ($result['status'] ?? ''), TechnicalServiceMessageDispatch::SUCCESS_STATUSES, true)
                && filled($result['provider_message_id'] ?? null);
            $ambiguous = ! $accepted && (
                (bool) ($result['ambiguous'] ?? false)
                || (string) ($result['status'] ?? '') === TechnicalServiceMessageDispatch::STATUS_SENDING
                || in_array($providerStatus, ['exception', 'accepted_without_message_id', 'accepted_without_pkgid'], true)
            );
            $status = $accepted
                ? TechnicalServiceMessageDispatch::STATUS_SENT
                : ($ambiguous
                    ? TechnicalServiceMessageDispatch::STATUS_SENDING
                    : (in_array((string) ($result['status'] ?? ''), [
                        TechnicalServiceMessageDispatch::STATUS_FAILED,
                        TechnicalServiceMessageDispatch::STATUS_PROVIDER_ERROR,
                        TechnicalServiceMessageDispatch::STATUS_BLOCKED,
                    ], true)
                        ? (string) $result['status']
                        : TechnicalServiceMessageDispatch::STATUS_PROVIDER_ERROR));
            $outcome = $accepted
                ? 'provider_accepted'
                : ($ambiguous ? 'ambiguous_no_retry' : 'provider_rejected_no_retry');
            $finalizedAt = now()->toIso8601String();

            $dispatch->forceFill([
                'status' => $status,
                'attempt_count' => max(1, (int) $dispatch->attempt_count),
                'sending_started_at' => $dispatch->sending_started_at ?? now(),
                'provider_status' => $providerStatus,
                'provider_message_id' => $accepted ? (string) $result['provider_message_id'] : null,
                'provider_response_redacted' => $result['response'] ?? null,
                'response_payload' => $result['response'] ?? null,
                'last_error_code' => $accepted ? null : $providerStatus,
                'last_error_message_redacted' => $accepted ? null : ($result['error'] ?? 'Provider sonucu tekrar gönderime kapatıldı.'),
                'error_message' => $accepted ? null : ($result['error'] ?? 'Provider sonucu tekrar gönderime kapatıldı.'),
                'sent_at' => $accepted ? now() : null,
                'failed_at' => ! $accepted && ! $ambiguous ? now() : null,
                'metadata' => [
                    ...((array) $dispatch->metadata),
                    'normal_processor_claim_hash' => $claimHash,
                    'normal_outbound_authoritative_claim_hash' => $claimHash,
                    'normal_outbound_tuple_hash' => $tupleHash,
                    'normal_outbound_http_started_at' => $activeClaim['http_started_at'] ?? null,
                    'provider_send_attempted' => true,
                    'external_provider_call' => true,
                    'normal_outbound_outcome' => $outcome,
                    'normal_outbound_replay_blocked' => true,
                    'normal_outbound_finalized_claim_hash' => $claimHash,
                    'normal_outbound_finalized_at' => $finalizedAt,
                    'provider_accepted' => $accepted,
                    'delivery_proven' => false,
                ],
            ])->save();

            $historyEntry = [
                ...$activeClaim,
                'status' => $ambiguous ? 'ambiguous_no_retry' : 'finalized',
                'outcome' => $outcome,
                'provider_status' => $providerStatus,
                'provider_message_id_present' => $accepted,
                'finalized_at' => $finalizedAt,
            ];
            $current['normal_outbound_history'] = $this->appendWindowHistory(
                (array) ($current['normal_outbound_history'] ?? []),
                $historyEntry,
            );
            $current['normal_outbound_active_claim'] = $ambiguous
                ? $historyEntry
                : null;
            $this->persistAuthoritativeSettings($locked, $current);

            return $dispatch;
        });
    }

    public function assertManualE2ELifecycleStateValid(): void
    {
        $this->validateManualE2ELifecycleState($this->settings());
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{provider:string,channel:string,recipient_fingerprint:string,role_target:string,request_id:int,offer_cycle_id:int|null,idempotency_fingerprint:string,body_fingerprint:string}
     */
    private function assertManualE2EDispatchEligible(
        array $settings,
        TechnicalServiceManualE2ERunContext $context,
        TechnicalServiceMessageDispatch $dispatch,
    ): array {
        $metadata = (array) $dispatch->metadata;
        $authoritative = $this->manualE2EDispatchSecurityTuple($dispatch, $settings);
        $provider = $authoritative['provider'];
        $channel = $authoritative['channel'];
        $recipientRole = (string) $dispatch->recipient_role;
        $target = $this->normalizePhone((string) $dispatch->target_phone);
        $expectedTarget = $this->normalizePhone((string) ($metadata['role_target_phone'] ?? ''));
        $runId = TechnicalServiceManualE2ERunContext::dispatchRunId($metadata);
        $requestId = $authoritative['request_id'];

        if ($dispatch->status !== TechnicalServiceMessageDispatch::STATUS_QUEUED
            || (int) $dispatch->attempt_count !== 0
            || filled($dispatch->provider_message_id)
            || $dispatch->sent_at !== null
            || filter_var($metadata['provider_send_attempted'] ?? false, FILTER_VALIDATE_BOOL)
            || filter_var($metadata['manual_e2e_window_consumed'] ?? false, FILTER_VALIDATE_BOOL)
        ) {
            throw new ConflictHttpException('Dispatch provider attempt için uygun değil.');
        }
        $parentDispatchId = (int) ($dispatch->parent_dispatch_id
            ?? $metadata['force_resend_from_dispatch_id']
            ?? 0);
        if (filter_var($metadata['manual_e2e_replay_blocked'] ?? false, FILTER_VALIDATE_BOOL)
            || $parentDispatchId > 0) {
            throw new ConflictHttpException('Dispatch önceki Manual E2E attempt üzerinden replay edilemez.');
        }
        if ($this->terminalOutboundLineageHasAttempt($dispatch)) {
            throw new ConflictHttpException('Dispatch daha önce attempt almış terminal idempotency kaydı üzerinden replay edilemez.');
        }
        if (! filter_var($metadata['manual_e2e'] ?? false, FILTER_VALIDATE_BOOL)
            || ! filter_var($metadata['test_smoke'] ?? false, FILTER_VALIDATE_BOOL)
            || $runId === null
            || $runId !== $context->activeRunId()
        ) {
            throw new ConflictHttpException('Dispatch aktif Manual E2E run ile eşleşmiyor.');
        }
        if ($dispatch->created_at === null
            || $context->createdAfter() === null
            || $dispatch->created_at->lt($context->createdAfter()->subSecond())
            || $context->expiresAt() === null
            || ! $dispatch->created_at->lt($context->expiresAt())
        ) {
            throw new ConflictHttpException('Dispatch aktif Manual E2E zaman sınırı dışında.');
        }
        if (! in_array($provider, ['evo_whatsapp', 'nac_sms'], true)
            || ($provider === 'evo_whatsapp' && $channel !== 'whatsapp')
            || ($provider === 'nac_sms' && $channel !== 'sms')
        ) {
            throw new ConflictHttpException('Dispatch provider ve kanal eşleşmesi geçersiz.');
        }
        if ($this->isScopedLocalUatSettings($settings)) {
            $profile = app(ExternalEffectCapabilityRegistry::class)->localAllowlistedUatProfile();
            $event = (string) $dispatch->message_type;
            $expectedProvider = data_get($profile, 'messaging_events.'.$event.'.'.$channel);
            if (! is_string($expectedProvider) || $expectedProvider !== $provider) {
                throw new ConflictHttpException('FAIL_CLOSED_UNAUTHORIZED_CAPABILITY: Event, kanal veya provider allowlistli Yerel UAT profiline ait değil.');
            }
            if ($channel === 'sms' && $recipientRole === 'ops') {
                throw new ConflictHttpException('FAIL_CLOSED_UNAUTHORIZED_CAPABILITY: OPS SMS allowlistli Yerel UAT profilinde kapalıdır.');
            }
            if (! $this->scopedLocalUatBodyUrlsAreSafe($dispatch->bodyForProvider(), $settings)) {
                throw new ConflictHttpException('FAIL_CLOSED_UNAUTHORIZED_CAPABILITY: Mesaj linki exact LAN origin ile eşleşmiyor.');
            }

            $attemptCounts = $this->scopedLocalUatMessagingAttemptCounts(
                $settings,
                (string) $context->activeRunId(),
            );
            $channelLimit = (int) data_get($profile, 'limits.'.$channel, 0);
            $totalLimit = (int) data_get($profile, 'limits.total', 0);
            if ($channelLimit <= 0
                || $attemptCounts[$channel] >= $channelLimit
                || $totalLimit <= 0
                || $attemptCounts['total'] >= $totalLimit) {
                throw new ConflictHttpException('FAIL_CLOSED_UNAUTHORIZED_CAPABILITY: Allowlistli Yerel UAT gönderim üst sınırı aşıldı.');
            }
        }
        if ($target === ''
            || ! in_array($target, $context->allowlistedPhones(), true)
            || $expectedTarget === ''
            || $expectedTarget !== $target
            || (string) ($metadata['recipient_role_expected'] ?? '') !== $recipientRole
        ) {
            throw new ConflictHttpException('Dispatch recipient ve rol hedefi doğrulanamadı.');
        }
        $snapshotFingerprint = (string) data_get($settings, 'manual_e2e_run_snapshot.allowlist_fingerprint', '');
        if ($snapshotFingerprint === '' || ! hash_equals($snapshotFingerprint, $this->allowlistFingerprint($context->allowlistedPhones()))) {
            throw new ConflictHttpException('Manual E2E allowlist snapshot değişti.');
        }
        if ($provider === 'evo_whatsapp' && ! (bool) $this->evoWhatsappReadiness($settings)['ready']) {
            throw new ConflictHttpException('Evo provider readiness geçersiz.');
        }
        if ($provider === 'nac_sms' && ! (bool) ($this->nacSmsPayload($settings)['test_ready'] ?? false)) {
            throw new ConflictHttpException('NAC provider readiness geçersiz.');
        }

        $idempotencyKey = trim((string) $dispatch->idempotency_key);
        $alreadyAttempted = TechnicalServiceMessageDispatch::query()
            ->where('id', '<>', $dispatch->id)
            ->where('idempotency_key', $idempotencyKey)
            ->where(function ($query): void {
                $query->where('attempt_count', '>', 0)
                    ->orWhereNotNull('provider_message_id')
                    ->orWhereNotNull('sent_at');
            })
            ->exists();
        if ($alreadyAttempted) {
            throw new ConflictHttpException('Dispatch idempotency anahtarı daha önce tüketilmiş.');
        }

        $offerCycleId = $dispatch->technical_service_assignment_offer_id !== null
            ? (int) $dispatch->technical_service_assignment_offer_id
            : null;
        $unsafePending = TechnicalServiceMessageDispatch::query()
            ->where('id', '<>', $dispatch->id)
            ->whereIn('provider_key', ['evo_whatsapp', 'nac_sms'])
            ->whereIn('status', [
                TechnicalServiceMessageDispatch::STATUS_QUEUED,
                TechnicalServiceMessageDispatch::STATUS_SENDING,
                TechnicalServiceMessageDispatch::STATUS_RATE_LIMITED,
            ])
            ->get()
            ->contains(function (TechnicalServiceMessageDispatch $other) use ($context, $dispatch, $requestId, $offerCycleId): bool {
                $otherMetadata = (array) $other->metadata;
                $otherRequestId = (int) ($other->technical_service_request_id ?? $other->request_id ?? 0);
                $otherOfferCycleId = $other->technical_service_assignment_offer_id !== null
                    ? (int) $other->technical_service_assignment_offer_id
                    : null;

                return $other->status !== TechnicalServiceMessageDispatch::STATUS_QUEUED
                    || (int) $other->attempt_count !== 0
                    || ! filter_var($otherMetadata['manual_e2e'] ?? false, FILTER_VALIDATE_BOOL)
                    || TechnicalServiceManualE2ERunContext::dispatchRunId($otherMetadata) !== $context->activeRunId()
                    || ! in_array($this->normalizePhone((string) $other->target_phone), $context->allowlistedPhones(), true)
                    || $otherRequestId !== $requestId
                    || $otherOfferCycleId !== $offerCycleId
                    || (string) $other->message_type !== (string) $dispatch->message_type
                    || (string) $other->recipient_role !== (string) $dispatch->recipient_role;
            });
        if ($unsafePending) {
            throw new ConflictHttpException('Manual E2E run dışında unsafe pending provider dispatch bulundu.');
        }

        return $authoritative;
    }

    /**
     * @return array{provider:string,channel:string,recipient_fingerprint:string,role_target:string,request_id:int,offer_cycle_id:int|null,idempotency_fingerprint:string,body_fingerprint:string}
     */
    private function manualE2EDispatchSecurityTuple(
        TechnicalServiceMessageDispatch $dispatch,
        array $settings,
    ): array {
        $metadata = (array) $dispatch->metadata;
        $recipientRole = (string) $dispatch->recipient_role;
        $target = $this->normalizePhone((string) $dispatch->target_phone);
        $requestId = (int) ($dispatch->technical_service_request_id ?? $dispatch->request_id ?? 0);
        if ($requestId <= 0) {
            throw new ConflictHttpException('Dispatch request kapsamı doğrulanamadı.');
        }

        $request = TechnicalServiceRequest::query()->find($requestId);
        if (! $request instanceof TechnicalServiceRequest) {
            throw new ConflictHttpException('Dispatch request kapsamı doğrulanamadı.');
        }
        $roleTarget = match ($recipientRole) {
            'technician' => filled($request->technical_service_technician_id)
                ? 'technician:'.(int) $request->technical_service_technician_id
                : null,
            'customer' => 'customer:'.$requestId,
            'ops' => 'ops:'.$requestId,
            default => null,
        };
        if ($roleTarget === null) {
            throw new ConflictHttpException('Dispatch recipient rolü authoritative hedefe bağlanamadı.');
        }

        $body = $dispatch->bodyForProvider();
        $bodyErrors = [...$dispatch->providerBodyValidationErrors(), ...$dispatch->roleBodyValidationErrors()];
        $expectedBodyToken = trim((string) ($metadata['expected_body_token'] ?? ''));
        if ($this->isScopedLocalUatSettings($settings)) {
            $runId = TechnicalServiceManualE2ERunContext::dispatchRunId($metadata);
            $snapshot = is_array($settings['manual_e2e_run_snapshot'] ?? null)
                ? $settings['manual_e2e_run_snapshot']
                : [];
            $contract = $runId === null
                ? null
                : self::resolvedScopedLocalUatMessageBodyContract($snapshot, $runId);
            $metadataFingerprint = trim((string) ($metadata['expected_body_token_fingerprint'] ?? ''));
            $legacyReferences = array_values(array_unique(array_filter(array_map(
                static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '',
                [
                    $contract['expected_body_token'] ?? null,
                    $request->mrn,
                    $request->root_mrn,
                    $request->service_code,
                    $requestId,
                ],
            ))));
            if ($contract === null
                || $this->scopedLocalUatTokenOverridePresent([
                    'payload' => (array) $dispatch->request_payload,
                    'metadata' => $metadata,
                ])
                || ! in_array($expectedBodyToken, $legacyReferences, true)
                || ! hash_equals($contract['expected_body_token_fingerprint'], $metadataFingerprint)
                || substr_count($body, $contract['expected_body_token']) !== 1) {
                throw new ConflictHttpException('Dispatch mesaj gövdesi locked UAT smoke tokenını doğrulamıyor.');
            }
            $expectedBodyToken = $contract['expected_body_token'];
        }
        if ($expectedBodyToken === '' || ! str_contains($body, $expectedBodyToken) || $bodyErrors !== []) {
            throw new ConflictHttpException('Dispatch mesaj gövdesi authoritative smoke referansını doğrulamıyor.');
        }

        $idempotencyKey = trim((string) $dispatch->idempotency_key);
        if ($idempotencyKey === '') {
            throw new ConflictHttpException('Dispatch idempotency anahtarı eksik.');
        }

        return [
            'provider' => (string) $dispatch->provider_key,
            'channel' => (string) $dispatch->channel,
            'recipient_fingerprint' => hash('sha256', $target),
            'role_target' => $roleTarget,
            'request_id' => $requestId,
            'offer_cycle_id' => $dispatch->technical_service_assignment_offer_id !== null
                ? (int) $dispatch->technical_service_assignment_offer_id
                : null,
            'idempotency_fingerprint' => hash('sha256', $idempotencyKey),
            'body_fingerprint' => hash('sha256', $body),
        ];
    }

    private function normalOutboundTupleHash(TechnicalServiceMessageDispatch $dispatch): string
    {
        return hash('sha256', implode("\0", [
            (string) $dispatch->provider_key,
            (string) $dispatch->channel,
            $this->normalizePhone((string) $dispatch->target_phone),
            hash('sha256', $dispatch->bodyForProvider()),
            (string) $dispatch->idempotency_key,
        ]));
    }

    /**
     * @param  array<string, mixed>  $stored
     * @param  array{provider:string,channel:string,recipient_fingerprint:string,role_target:string,request_id:int,offer_cycle_id:int|null,idempotency_fingerprint:string,body_fingerprint:string}  $authoritative
     */
    private function manualE2ESecurityTupleMatches(array $stored, array $authoritative): bool
    {
        if (! array_key_exists('offer_cycle_id', $stored)) {
            return false;
        }

        $storedOfferCycleId = $stored['offer_cycle_id'] === null
            ? null
            : (int) ($stored['offer_cycle_id'] ?? 0);

        return (string) ($stored['provider'] ?? '') === $authoritative['provider']
            && (string) ($stored['channel'] ?? '') === $authoritative['channel']
            && (string) ($stored['recipient_fingerprint'] ?? '') === $authoritative['recipient_fingerprint']
            && (string) ($stored['role_target'] ?? '') === $authoritative['role_target']
            && (int) ($stored['request_id'] ?? 0) === $authoritative['request_id']
            && $storedOfferCycleId === $authoritative['offer_cycle_id']
            && (string) ($stored['idempotency_fingerprint'] ?? '') === $authoritative['idempotency_fingerprint']
            && (string) ($stored['body_fingerprint'] ?? '') === $authoritative['body_fingerprint'];
    }

    private function isSyntheticScopedLocalUatMessageRequest(int $requestId): bool
    {
        if ($requestId <= 0) {
            return false;
        }

        $request = TechnicalServiceRequest::query()->find($requestId);
        if (! $request instanceof TechnicalServiceRequest) {
            return false;
        }

        return $request->events()
            ->where('event_type', 'synthetic_uat_context_created')
            ->get(['metadata'])
            ->contains(fn ($event): bool => filter_var(
                data_get($event->metadata, 'synthetic_uat', false),
                FILTER_VALIDATE_BOOL,
            ));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function scopedLocalUatTokenOverridePresent(array $input): bool
    {
        $payload = is_array($input['payload'] ?? null) ? $input['payload'] : [];
        $context = is_array($input['context'] ?? null)
            ? $input['context']
            : (is_array($payload['context'] ?? null) ? $payload['context'] : []);
        foreach (self::SCOPED_LOCAL_UAT_TOKEN_OVERRIDE_KEYS as $key) {
            if (array_key_exists($key, $input)
                || array_key_exists($key, $payload)
                || array_key_exists($key, $context)) {
                return true;
            }
        }

        $metadata = is_array($input['metadata'] ?? null) ? $input['metadata'] : [];

        return collect(['body_token', 'smoke_token', 'uat_token'])
            ->contains(fn (string $key): bool => array_key_exists($key, $metadata));
    }

    /**
     * @return array{expected_body_token:string,expected_body_token_fingerprint:string}
     */
    private static function scopedLocalUatMessageBodyContract(string $runId): array
    {
        $token = 'UAT-'.strtoupper(substr(hash('sha256', implode("\0", [
            ExternalEffectCapabilityRegistry::LOCAL_ALLOWLISTED_UAT_PROFILE,
            trim($runId),
            'locked-message-body-v1',
        ])), 0, 24));
        $fingerprint = hash('sha256', json_encode([
            'profile_id' => ExternalEffectCapabilityRegistry::LOCAL_ALLOWLISTED_UAT_PROFILE,
            'run_id' => trim($runId),
            'expected_body_token' => $token,
            'message_matrix' => array_keys(self::SCOPED_LOCAL_UAT_MESSAGE_BODY_MATRIX),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return [
            'expected_body_token' => $token,
            'expected_body_token_fingerprint' => $fingerprint,
        ];
    }

    /**
     * @param  array<string, mixed>  $runSnapshot
     * @return array{expected_body_token:string,expected_body_token_fingerprint:string}|null
     */
    private static function resolvedScopedLocalUatMessageBodyContract(array $runSnapshot, string $runId): ?array
    {
        $runId = trim($runId);
        if ($runId === ''
            || ($runSnapshot['scoped_local_uat_profile_id'] ?? null) !== ExternalEffectCapabilityRegistry::LOCAL_ALLOWLISTED_UAT_PROFILE
            || (string) ($runSnapshot['scoped_local_uat_run_id'] ?? '') !== $runId) {
            return null;
        }

        $contract = self::scopedLocalUatMessageBodyContract($runId);
        if (! hash_equals($contract['expected_body_token'], (string) ($runSnapshot['expected_body_token'] ?? ''))
            || ! hash_equals(
                $contract['expected_body_token_fingerprint'],
                (string) ($runSnapshot['expected_body_token_fingerprint'] ?? ''),
            )) {
            return null;
        }

        return $contract;
    }

    /**
     * @return array{profile_id:string,effect_window_seconds:int,effect_window_fingerprint:string}
     */
    public static function manualE2EEffectWindowContract(?string $profileId): array
    {
        $profileId = trim((string) $profileId);
        $isScopedLocalUat = $profileId === ExternalEffectCapabilityRegistry::LOCAL_ALLOWLISTED_UAT_PROFILE;
        $resolvedProfileId = $isScopedLocalUat
            ? ExternalEffectCapabilityRegistry::LOCAL_ALLOWLISTED_UAT_PROFILE
            : 'default';
        $seconds = $isScopedLocalUat
            ? self::SCOPED_LOCAL_UAT_EFFECT_WINDOW_SECONDS
            : self::MANUAL_E2E_WINDOW_TTL_SECONDS;
        $fingerprint = hash('sha256', json_encode([
            'profile_id' => $resolvedProfileId,
            'effect_window_seconds' => $seconds,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return [
            'profile_id' => $resolvedProfileId,
            'effect_window_seconds' => $seconds,
            'effect_window_fingerprint' => $fingerprint,
        ];
    }

    /**
     * @param  array<string, mixed>  $runSnapshot
     * @return array{profile_id:string,effect_window_seconds:int,effect_window_fingerprint:string}|null
     */
    public static function resolvedManualE2EEffectWindowContract(array $runSnapshot): ?array
    {
        $hasScopedProfile = array_key_exists('scoped_local_uat_profile_id', $runSnapshot);
        $profileId = $hasScopedProfile && is_scalar($runSnapshot['scoped_local_uat_profile_id'])
            ? trim((string) $runSnapshot['scoped_local_uat_profile_id'])
            : null;
        if ($hasScopedProfile && $profileId !== ExternalEffectCapabilityRegistry::LOCAL_ALLOWLISTED_UAT_PROFILE) {
            return null;
        }

        $contract = self::manualE2EEffectWindowContract($profileId);
        if ((int) ($runSnapshot['effect_window_seconds'] ?? 0) !== $contract['effect_window_seconds']
            || ! hash_equals(
                $contract['effect_window_fingerprint'],
                (string) ($runSnapshot['effect_window_fingerprint'] ?? ''),
            )) {
            return null;
        }

        return $contract;
    }

    /**
     * @param  array<string, mixed>  $window
     */
    private function windowExpired(array $window): bool
    {
        try {
            $expiresAt = CarbonImmutable::parse((string) ($window['expires_at'] ?? ''));
        } catch (Throwable) {
            return true;
        }

        return ! CarbonImmutable::now()->lt($expiresAt);
    }

    /**
     * @param  array<int, array<string, mixed>>  $history
     * @param  array<string, mixed>  $entry
     * @return array<int, array<string, mixed>>
     */
    private function appendWindowHistory(array $history, array $entry): array
    {
        $history[] = $entry;

        return array_values(array_slice($history, -20));
    }

    /**
     * @param  array<int, string>  $phones
     */
    private function allowlistFingerprint(array $phones): string
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            fn (mixed $phone): string => $this->normalizePhone((string) $phone),
            $phones,
        ))));
        sort($normalized);

        return hash('sha256', implode('|', $normalized));
    }

    /**
     * @return array<string, mixed>
     */
    public function registerManualE2EWorkerLease(
        string $runId,
        string $lockOwner,
        CarbonImmutable $startedAt,
        CarbonImmutable $expiresAt,
    ): array {
        $lock = $this->acquireLifecycleLock();

        try {
            $settings = $this->settings();
            $context = TechnicalServiceManualE2ERunContext::fromSettings($settings);
            if (! $context->isActive()
                || $context->activeRunId() !== $runId
                || trim($lockOwner) === ''
                || $this->runtimeEnvironment() === 'production'
                || ! $this->manualE2EExecutionScopeAllowed($settings, $context)) {
                throw new ConflictHttpException('Worker lease aktif Manual E2E run ile eşleşmiyor.');
            }

            $now = CarbonImmutable::now();
            $lease = [
                'run_id' => $runId,
                'lock_owner' => $lockOwner,
                'process_id' => getmypid() ?: null,
                'outbound_mode_revision' => $this->executionModeRevision($settings),
                'started_at' => $startedAt->toIso8601String(),
                'heartbeat_at' => $now->toIso8601String(),
                'expires_at' => $expiresAt->toIso8601String(),
                'invalidated_at' => null,
            ];
            Cache::put(
                TechnicalServiceManualE2ERunContext::WORKER_LEASE_KEY,
                $lease,
                max(60, $context->remainingTtlSeconds() + 60),
            );

            return $this->workerLeasePublicPayload($lease, $now);
        } finally {
            $lock();
        }
    }

    public function heartbeatManualE2EWorkerLease(string $runId, string $lockOwner): bool
    {
        try {
            $lock = $this->acquireLifecycleLock();
        } catch (ConflictHttpException) {
            return false;
        }

        try {
            $lease = $this->manualE2EWorkerLease();
            $settings = $this->settings();
            $context = TechnicalServiceManualE2ERunContext::fromSettings($settings);
            if ($lease === null
                || ($lease['run_id'] ?? null) !== $runId
                || ($lease['lock_owner'] ?? null) !== $lockOwner
                || (int) ($lease['outbound_mode_revision'] ?? -1) !== $this->executionModeRevision($settings)
                || filled($lease['invalidated_at'] ?? null)
                || $this->runtimeEnvironment() === 'production'
                || ! $this->manualE2EExecutionScopeAllowed($settings, $context)
                || ! $context->isActive()
                || $context->activeRunId() !== $runId) {
                return false;
            }

            $lease['heartbeat_at'] = CarbonImmutable::now()->toIso8601String();
            Cache::put(
                TechnicalServiceManualE2ERunContext::WORKER_LEASE_KEY,
                $lease,
                max(60, $context->remainingTtlSeconds() + 60),
            );

            return true;
        } finally {
            $lock();
        }
    }

    public function clearManualE2EWorkerLease(string $runId, string $lockOwner): bool
    {
        try {
            $lock = $this->acquireLifecycleLock();
        } catch (ConflictHttpException) {
            return false;
        }

        try {
            $lease = $this->manualE2EWorkerLease();
            if ($lease === null
                || ($lease['run_id'] ?? null) !== $runId
                || ($lease['lock_owner'] ?? null) !== $lockOwner) {
                return false;
            }

            Cache::forget(TechnicalServiceManualE2ERunContext::WORKER_LEASE_KEY);

            return true;
        } finally {
            $lock();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function manualE2EWorkerLeaseStatus(): array
    {
        $lease = $this->manualE2EWorkerLease();

        return $this->workerLeasePublicPayload($lease, CarbonImmutable::now());
    }

    /**
     * @return array<string, mixed>
     */
    public function manualE2EReadiness(): array
    {
        return $this->manualE2EReadinessForSettings($this->settings(), true);
    }

    /**
     * @return array<string, mixed>
     */
    public function reset(): array
    {
        $lock = $this->acquireLifecycleLock();

        try {
            DB::transaction(function (): void {
                $locked = $this->lockedAuthoritativeSettings();
                $current = $locked['settings'];
                $this->assertNoActiveRunMutation($current);
                $defaults = $this->defaultSettings();
                foreach (self::GENERIC_LIFECYCLE_FIELDS as $field) {
                    if (array_key_exists($field, $current) && array_key_exists($field, $defaults)) {
                        $defaults[$field] = $current[$field];
                    }
                }

                $this->persistAuthoritativeSettings($locked, $defaults);
            });
        } finally {
            $lock();
        }

        return $this->payload();
    }

    /**
     * @return array{valid:bool,normalized:string,masked:string}
     */
    public function validatePhone(string $phone): array
    {
        $normalized = $this->normalizePhone($phone);

        if (! $this->validPhone($normalized)) {
            throw ValidationException::withMessages([
                'test_phone' => 'Geçerli bir test telefon numarası girilmeli.',
            ]);
        }

        return [
            'valid' => true,
            'normalized' => $normalized,
            'masked' => $this->maskPhone($normalized),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function saveNacSmsCredentials(array $values): array
    {
        return $this->withActiveRunSafetyLock(
            fn (): array => $this->persistNacSmsCredentials($values),
        );
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function persistNacSmsCredentials(array $values): array
    {
        $username = trim((string) ($values['username'] ?? ''));
        $password = (string) ($values['password'] ?? '');

        if ($username === '' || $password === '') {
            throw ValidationException::withMessages([
                'username' => 'NAC SMS kullanıcı adı ve şifre zorunlu.',
            ]);
        }

        $credential = IntegrationProviderCredential::query()->updateOrCreate(
            [
                'scope' => IntegrationProviderCredential::SCOPE_TECHNICAL_SERVICE,
                'provider' => 'nac_sms',
                'profile_key' => IntegrationProviderCredential::PROFILE_DEFAULT,
                'mode' => IntegrationProviderCredential::MODE_LIVE,
            ],
            [
                'username_encrypted' => $username,
                'password_encrypted' => $password,
                'username_mask' => $this->maskValue($username),
                'credentials_status' => IntegrationProviderCredential::STATUS_CONFIGURED,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
                'metadata' => ['auth' => 'basic'],
            ],
        );

        if ($credential->created_by === null) {
            $credential->forceFill(['created_by' => Auth::id()])->save();
        }

        return $this->payload();
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function saveMikroApiCredentials(array $values): array
    {
        return $this->withActiveRunSafetyLock(function () use ($values): array {
            $credential = IntegrationProviderCredential::query()->firstOrNew([
                'scope' => IntegrationProviderCredential::SCOPE_TECHNICAL_SERVICE,
                'provider' => 'mikro_api',
                'profile_key' => IntegrationProviderCredential::PROFILE_DEFAULT,
                'mode' => IntegrationProviderCredential::MODE_LIVE,
            ]);
            $updates = [];

            foreach ([
                'api_key' => ['encrypted' => 'api_key_encrypted', 'mask' => 'api_key_mask'],
                'password' => ['encrypted' => 'password_encrypted', 'mask' => null],
                'token' => ['encrypted' => 'token_encrypted', 'mask' => 'token_mask'],
            ] as $input => $fields) {
                if (! array_key_exists($input, $values)) {
                    continue;
                }

                $value = trim((string) $values[$input]);
                if ($value === '') {
                    continue;
                }

                $updates[$fields['encrypted']] = $value;
                if ($fields['mask'] !== null) {
                    $updates[$fields['mask']] = $this->maskValue($value);
                }
            }

            if ($updates === []) {
                return $this->payload();
            }

            $credential->fill($updates);
            $credential->forceFill([
                'credentials_status' => IntegrationProviderCredential::STATUS_CONFIGURED,
                'created_by' => $credential->created_by ?? Auth::id(),
                'updated_by' => Auth::id(),
                'metadata' => [
                    ...(array) ($credential->metadata ?? []),
                    'auth' => filled($credential->token_encrypted) ? 'api_key_basic_token' : 'api_key_basic',
                    'password_transform' => 'none_by_panel',
                ],
            ])->save();

            return $this->payload();
        });
    }

    /**
     * @param  array<int, string>  $targets
     * @return array<string, mixed>
     */
    public function clearMikroApiCredentials(array $targets): array
    {
        return $this->withActiveRunSafetyLock(function () use ($targets): array {
            $allowed = [
                'api_key' => ['api_key_encrypted', 'api_key_mask'],
                'password' => ['password_encrypted'],
                'token' => ['token_encrypted', 'token_mask'],
            ];
            $targets = array_values(array_unique($targets));

            if ($targets === [] || array_diff($targets, array_keys($allowed)) !== []) {
                throw ValidationException::withMessages([
                    'credentials' => 'Temizlenecek Mikro secret alanı geçersiz.',
                ]);
            }

            $credential = $this->credential('mikro_api');
            if ($credential === null) {
                return $this->payload();
            }

            $updates = [];
            foreach ($targets as $target) {
                foreach ($allowed[$target] as $field) {
                    $updates[$field] = null;
                }
            }

            $credential->fill($updates);
            $hasSecret = filled($credential->api_key_encrypted)
                || filled($credential->password_encrypted)
                || filled($credential->token_encrypted);
            $credential->forceFill([
                'credentials_status' => $hasSecret
                    ? IntegrationProviderCredential::STATUS_CONFIGURED
                    : IntegrationProviderCredential::STATUS_MISSING,
                'updated_by' => Auth::id(),
                'metadata' => [
                    ...(array) ($credential->metadata ?? []),
                    'auth' => filled($credential->token_encrypted) ? 'api_key_basic_token' : 'api_key_basic',
                    'last_clear_targets' => $targets,
                ],
            ])->save();

            return $this->payload();
        });
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function saveEvoWhatsappCredentials(array $values): array
    {
        return $this->withActiveRunSafetyLock(
            fn (): array => $this->persistEvoWhatsappCredentials($values),
        );
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function persistEvoWhatsappCredentials(array $values): array
    {
        $apiKey = trim((string) ($values['api_key'] ?? ''));
        $token = trim((string) ($values['token'] ?? ''));

        if ($apiKey === '' && $token === '') {
            throw ValidationException::withMessages([
                'api_key' => 'Evo Direct API key veya token zorunlu.',
            ]);
        }

        $credential = IntegrationProviderCredential::query()->updateOrCreate(
            [
                'scope' => IntegrationProviderCredential::SCOPE_TECHNICAL_SERVICE,
                'provider' => 'evo_whatsapp',
                'profile_key' => IntegrationProviderCredential::PROFILE_DEFAULT,
                'mode' => IntegrationProviderCredential::MODE_LIVE,
            ],
            [
                'api_key_encrypted' => $apiKey === '' ? null : $apiKey,
                'token_encrypted' => $token === '' ? null : $token,
                'api_key_mask' => $apiKey === '' ? null : $this->maskValue($apiKey),
                'token_mask' => $token === '' ? null : $this->maskValue($token),
                'credentials_status' => IntegrationProviderCredential::STATUS_CONFIGURED,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
                'metadata' => ['auth' => $token === '' ? 'api_key' : 'token', 'transport' => 'direct_evolution_api'],
            ],
        );

        if ($credential->created_by === null) {
            $credential->forceFill(['created_by' => Auth::id()])->save();
        }

        return $this->payload();
    }

    /**
     * @return array<string, mixed>
     */
    public function clearProviderCredentials(string $provider): array
    {
        $provider = $this->normalizeProviderKey($provider);

        if (in_array($provider, ['nac_sms', 'evo_whatsapp'], true)) {
            return $this->withActiveRunSafetyLock(
                fn (): array => $this->deleteProviderCredentials($provider),
            );
        }

        return $this->deleteProviderCredentials($provider);
    }

    /**
     * @return array<string, mixed>
     */
    private function deleteProviderCredentials(string $provider): array
    {
        if (! in_array($provider, ['nac_sms', 'mikro_api', 'evo_whatsapp'], true)) {
            throw ValidationException::withMessages([
                'provider' => 'Bu sağlayıcının credential temizleme işlemi bu ekranda desteklenmiyor.',
            ]);
        }

        IntegrationProviderCredential::query()
            ->where('scope', IntegrationProviderCredential::SCOPE_TECHNICAL_SERVICE)
            ->where('provider', $provider)
            ->where('profile_key', IntegrationProviderCredential::PROFILE_DEFAULT)
            ->delete();

        return $this->payload();
    }

    /**
     * Secrets from this context must only be consumed while constructing a Mikro request.
     *
     * @return array<string, mixed>
     */
    public function mikroApiConnectionContext(): array
    {
        $mikro = $this->settings()['mikro_api'];
        $credential = $this->credential('mikro_api');
        $userCode = $this->mikroUserCode($mikro, $credential);
        $healthReady = in_array(
            strtolower(trim((string) ($mikro['last_health_check_status'] ?? ''))),
            ['ok', 'pass', 'success', 'up'],
            true,
        );

        return [
            'base_url' => $mikro['base_url'],
            'api_version' => $mikro['api_version'],
            'application_code' => $mikro['application_code'],
            'firm_code' => $mikro['company_code'],
            'branch_code' => $mikro['branch_code'],
            'terminal_code' => $mikro['workstation_code'],
            'working_year' => $mikro['fiscal_year'],
            'timeout_seconds' => (int) $mikro['timeout_seconds'],
            'server_timezone' => $mikro['server_timezone'],
            'enabled' => (bool) $mikro['enabled'],
            'read_sync_enabled' => (bool) $mikro['read_sync_enabled'],
            'write_enabled' => (bool) $mikro['write_enabled'],
            'write_approval_required' => (bool) $mikro['write_approval_required'],
            'operation_controls' => (array) ($mikro['operation_controls'] ?? []),
            'api_key' => $credential?->api_key_encrypted,
            'token' => $credential?->token_encrypted,
            'user_code' => $userCode,
            'password' => $credential?->password_encrypted,
            'health_configuration_ready' => $this->mikroHealthConfigurationBlockerCodes($mikro) === [],
            'health_blocker_codes' => $this->mikroHealthConfigurationBlockerCodes($mikro),
            'health_ready' => $healthReady,
            'live_configuration_ready' => $this->mikroLiveConfigurationReady($mikro, $credential),
            'blocker_codes' => $this->mikroConfigurationBlockerCodes($mikro, $credential),
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function recordMikroHealthCheckResult(array $result): array
    {
        $lock = $this->acquireLifecycleLock();

        try {
            DB::transaction(function () use ($result): void {
                $locked = $this->lockedAuthoritativeSettings();
                $next = $locked['settings'];
                $success = (bool) ($result['success'] ?? false)
                    && ! (bool) ($result['stale'] ?? false)
                    && ! (bool) ($result['fallback_used'] ?? false)
                    && blank($result['error_code'] ?? null);
                $errorCode = $result['error_code'] ?? null;
                $safeError = is_string($errorCode) && preg_match('/^MIKRO_[A-Z0-9_]+$/', $errorCode) === 1
                    ? $errorCode
                    : 'MIKRO_HEALTHCHECK_FAILED';

                $next['mikro_api']['last_health_check_status'] = $success ? 'success' : 'failed';
                $next['mikro_api']['last_error_redacted'] = $success ? null : $safeError;
                $this->persistAuthoritativeSettings($locked, $next);
            });
        } finally {
            $lock();
        }

        return $this->payload();
    }

    public function testModeEnabled(): bool
    {
        return (bool) $this->settings()['test_mode_enabled'];
    }

    public function realSendEnabled(): bool
    {
        $settings = $this->settings();

        return (bool) $settings['messaging_enabled']
            && (bool) $settings['real_send_enabled']
            && $this->readiness($settings)['can_send_real'];
    }

    public function testPhone(): string
    {
        return (string) $this->settings()['test_phone'];
    }

    /**
     * Apply the stored Admin test-mode authority to normal business dispatches.
     * Scoped Manual E2E dispatches keep their immutable run recipient snapshot.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function withNormalDispatchRecipientAuthority(array $input): array
    {
        $metadata = is_array($input['metadata'] ?? null) ? $input['metadata'] : [];
        if (filter_var($metadata['manual_e2e'] ?? false, FILTER_VALIDATE_BOOL)
            || array_key_exists('scoped_local_uat_profile_id', $metadata)) {
            return $input;
        }

        $provider = $this->normalizeProviderKey((string) ($input['provider_key'] ?? ''));
        $channel = strtolower(trim((string) ($input['channel'] ?? '')));
        $role = strtolower(trim((string) ($input['recipient_role'] ?? $input['target_type'] ?? '')));
        if (! in_array($provider, ['evo_whatsapp', 'nac_sms'], true)
            || ! in_array($channel, ['whatsapp', 'sms'], true)
            || ! in_array($role, ['customer', 'technician', 'ops'], true)) {
            return $input;
        }

        $settings = $this->settings();
        if (! (bool) ($settings['messaging_enabled'] ?? false)
            || ! (bool) ($settings['real_send_enabled'] ?? false)) {
            return $input;
        }
        $testMode = (bool) ($settings['test_mode_enabled'] ?? false);
        $originalPhone = $this->normalizePhone((string) (
            $input['recipient_phone']
                ?? $input['target_phone']
                ?? $input['effective_target_phone']
                ?? ''
        ));
        $targetPhone = $testMode
            ? $this->testPhoneForRole($settings, $role)
            : $originalPhone;

        $input['target_phone'] = $targetPhone;
        $input['effective_target_phone'] = $targetPhone;
        $input['test_mode'] = $testMode;
        $input['test_redirect_applied'] = $testMode && $targetPhone !== '';
        if (is_array($input['payload'] ?? null)) {
            $input['payload']['test_redirect_applied'] = $input['test_redirect_applied'];
            $input['payload']['target_role'] = $role;
        }
        $input['metadata'] = [
            ...$metadata,
            'test_recipient_role' => $testMode ? $role : null,
            'test_recipient_authority' => $testMode ? 'admin_role_settings' : null,
        ];

        return $input;
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        $settings = $this->settingsFromLayout($this->layout());
        $lifecycleLayout = PageConfig::query()
            ->where('page_code', self::LIFECYCLE_PAGE_CODE)
            ->value('layout_json');

        return is_array($lifecycleLayout)
            ? $this->applyAuthoritativeLifecycleState($settings, $lifecycleLayout)
            : $settings;
    }

    /**
     * @param  array<string, mixed>  $layout
     * @return array<string, mixed>
     */
    private function settingsFromLayout(array $layout): array
    {
        $stored = Arr::get($layout, self::ROOT_KEY, []);
        $stored = is_array($stored) ? $stored : [];
        $hasStoredCustomerTestPhone = array_key_exists('customer_test_phone', $stored);
        $hasStoredTechnicianOpsTestPhone = array_key_exists('technician_ops_test_phone', $stored);
        $defaults = $this->defaultSettings();
        $settings = array_replace_recursive($defaults, $stored);
        $settings['message_types'] = $this->mergeMessageTypeSettings(
            $defaults['message_types'],
            is_array($settings['message_types'] ?? null) ? $settings['message_types'] : [],
        );
        $settings['provider_key'] = $this->normalizeProviderKey((string) ($settings['provider_key'] ?? $defaults['provider_key']));
        $settings['active_provider'] = $this->normalizeProviderKey((string) ($settings['active_provider'] ?? $settings['provider_key']));
        $settings['default_provider'] = $this->normalizeProviderKey((string) ($settings['default_provider'] ?? $defaults['default_provider']));
        $settings['fallback_provider'] = $this->normalizeProviderKey((string) ($settings['fallback_provider'] ?? $defaults['fallback_provider']));
        $settings['provider_priority'] = $this->normalizeProviderPriority(is_array($settings['provider_priority'] ?? null) ? $settings['provider_priority'] : []);
        $settings['providers'] = $this->mergeProviderSettings(
            $defaults['providers'],
            is_array($settings['providers'] ?? null) ? $settings['providers'] : [],
        );
        $settings['nac_sms'] = $this->mergeNacSmsSettings(
            $defaults['nac_sms'],
            is_array($settings['nac_sms'] ?? null) ? $settings['nac_sms'] : [],
        );
        if ((int) $settings['nac_sms']['validity'] < 60) {
            $settings['nac_sms']['validity'] = 60;
        }
        $settings['evo_whatsapp'] = $this->mergeEvoWhatsappSettings(
            $defaults['evo_whatsapp'],
            is_array($settings['evo_whatsapp'] ?? null) ? $settings['evo_whatsapp'] : [],
        );
        $settings['mikro_api'] = $this->mergeMikroApiSettings(
            $defaults['mikro_api'],
            is_array($settings['mikro_api'] ?? null) ? $settings['mikro_api'] : [],
        );
        $legacyTestPhone = $this->normalizePhone((string) ($settings['test_phone'] ?? ''));
        $allowlistedPhones = array_values(array_unique(array_filter(array_map(
            fn (mixed $phone): string => $this->normalizePhone((string) $phone),
            (array) ($settings['manual_e2e_allowlisted_phones'] ?? []),
        ))));
        $legacyCustomerTestPhone = collect($allowlistedPhones)
            ->first(fn (string $phone): bool => $phone !== $legacyTestPhone)
            ?? $legacyTestPhone;
        $settings['test_phone'] = $legacyTestPhone;
        $settings['customer_test_phone'] = $this->normalizePhone((string) (
            $hasStoredCustomerTestPhone
                ? ($settings['customer_test_phone'] ?? '')
                : $legacyCustomerTestPhone
        ));
        $settings['technician_ops_test_phone'] = $this->normalizePhone((string) (
            $hasStoredTechnicianOpsTestPhone
                ? ($settings['technician_ops_test_phone'] ?? '')
                : $legacyTestPhone
        ));
        $settings['manual_e2e_ttl_seconds'] = max(60, min(
            TechnicalServiceManualE2ERunContext::DEFAULT_TTL_SECONDS,
            (int) ($settings['manual_e2e_ttl_seconds'] ?? TechnicalServiceManualE2ERunContext::DEFAULT_TTL_SECONDS),
        ));
        foreach ([
            'manual_e2e_active_run_id',
            'manual_e2e_started_at',
            'manual_e2e_created_after',
            'manual_e2e_expires_at',
            'manual_e2e_last_run_id',
            'manual_e2e_last_stopped_at',
        ] as $manualE2eField) {
            $value = $settings[$manualE2eField] ?? null;
            $settings[$manualE2eField] = is_scalar($value) && trim((string) $value) !== ''
                ? trim((string) $value)
                : null;
        }

        $phase = is_scalar($settings['manual_e2e_phase'] ?? null)
            ? trim((string) $settings['manual_e2e_phase'])
            : '';
        $settings['manual_e2e_phase'] = in_array($phase, [
            self::MANUAL_E2E_PHASE_FROZEN,
            self::MANUAL_E2E_PHASE_PREPARED,
            self::MANUAL_E2E_PHASE_WINDOW_OPEN,
        ], true)
            ? $phase
            : (! (bool) ($settings['manual_e2e_enabled'] ?? false)
                && $settings['manual_e2e_active_run_id'] === null
                    ? null
                    : 'invalid');
        foreach (['manual_e2e_open_window', 'manual_e2e_active_claim', 'manual_e2e_run_snapshot', 'scoped_local_uat_active_effect_claim', 'normal_outbound_active_claim'] as $field) {
            $value = $settings[$field] ?? null;
            $settings[$field] = is_array($value)
                ? $value
                : ($value === null ? null : ['status' => 'invalid']);
        }
        $settings['manual_e2e_window_history'] = is_array($settings['manual_e2e_window_history'] ?? null)
            ? array_values($settings['manual_e2e_window_history'])
            : [];
        $settings['scoped_local_uat_effect_history'] = is_array($settings['scoped_local_uat_effect_history'] ?? null)
            ? array_values($settings['scoped_local_uat_effect_history'])
            : [];
        $settings['normal_outbound_history'] = is_array($settings['normal_outbound_history'] ?? null)
            ? array_values($settings['normal_outbound_history'])
            : [];
        // Legacy messaging mode fields are retained only for backward-compatible
        // storage reads. They never promote or override the global authority.
        $settings['outbound_execution_mode'] = ($settings['outbound_execution_mode'] ?? null) === self::OUTBOUND_EXECUTION_MODE_LIVE
            ? self::OUTBOUND_EXECUTION_MODE_LIVE
            : self::OUTBOUND_EXECUTION_MODE_LOCAL;
        $settings['outbound_mode_revision'] = max(1, (int) ($settings['outbound_mode_revision'] ?? 1));
        $settings['outbound_mode_changed_at'] = $this->nullableScalar($settings['outbound_mode_changed_at'] ?? null);
        $settings['outbound_mode_changed_by'] = is_numeric($settings['outbound_mode_changed_by'] ?? null)
            ? (int) $settings['outbound_mode_changed_by']
            : null;
        $settings['outbound_mode_reason'] = $this->nullableScalar($settings['outbound_mode_reason'] ?? null);
        $settings['local_manual_acceptance_enabled'] = (bool) ($settings['local_manual_acceptance_enabled'] ?? false);
        $settings['local_manual_acceptance_activated_at'] = $this->nullableScalar($settings['local_manual_acceptance_activated_at'] ?? null);
        $settings['local_manual_acceptance_profile_fingerprint'] = $this->nullableScalar($settings['local_manual_acceptance_profile_fingerprint'] ?? null);
        $settings['local_manual_acceptance_reason'] = $this->nullableScalar($settings['local_manual_acceptance_reason'] ?? null);

        return $settings;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultSettings(): array
    {
        return [
            'messaging_enabled' => false,
            'real_send_enabled' => false,
            'test_mode_enabled' => true,
            'test_phone' => $this->normalizePhone((string) config('services.evolution.test_phone', '')),
            'customer_test_phone' => $this->normalizePhone((string) config('services.evolution.test_phone', '')),
            'technician_ops_test_phone' => $this->normalizePhone((string) config('services.evolution.test_phone', '')),
            'queue_paused' => true,
            'outbound_execution_mode' => self::OUTBOUND_EXECUTION_MODE_LOCAL,
            'outbound_mode_revision' => 1,
            'outbound_mode_changed_at' => null,
            'outbound_mode_changed_by' => null,
            'outbound_mode_reason' => null,
            'provider_key' => 'null_local',
            'active_provider' => 'null_local',
            'default_provider' => 'null_local',
            'fallback_provider' => 'evo_whatsapp',
            'provider_priority' => array_keys(self::PROVIDERS),
            'providers' => $this->defaultProviderSettings(),
            'send_delay_seconds' => 90,
            'duplicate_cooldown_minutes' => 10,
            'hourly_limit' => 30,
            'daily_limit' => 200,
            'max_auto_retries' => 0,
            'allow_browser_smoke_send' => false,
            'allow_test_fixture_send' => false,
            'local_manual_acceptance_enabled' => false,
            'local_manual_acceptance_activated_at' => null,
            'local_manual_acceptance_profile_fingerprint' => null,
            'local_manual_acceptance_reason' => null,
            'manual_e2e_enabled' => false,
            'manual_e2e_phase' => null,
            'manual_e2e_active_run_id' => null,
            'manual_e2e_started_at' => null,
            'manual_e2e_created_after' => null,
            'manual_e2e_expires_at' => null,
            'manual_e2e_last_run_id' => null,
            'manual_e2e_last_stopped_at' => null,
            'manual_e2e_open_window' => null,
            'manual_e2e_active_claim' => null,
            'manual_e2e_run_snapshot' => null,
            'manual_e2e_window_history' => [],
            'scoped_local_uat_active_effect_claim' => null,
            'scoped_local_uat_effect_history' => [],
            'normal_outbound_active_claim' => null,
            'normal_outbound_history' => [],
            'manual_e2e_ttl_seconds' => TechnicalServiceManualE2ERunContext::DEFAULT_TTL_SECONDS,
            'manual_e2e_allowlisted_phones' => [],
            'manual_e2e_partner_portal_origin_enabled' => false,
            'manual_e2e_partner_portal_origin' => null,
            'ops_whatsapp_enabled' => false,
            'ops_whatsapp_phone' => null,
            'message_types' => $this->defaultMessageTypeSettings(),
            'evo_whatsapp' => [
                'direct_api_enabled' => false,
                'direct_api_base_url' => null,
                'direct_api_instance_name' => null,
                'delay' => 0,
                'link_preview' => false,
                'last_test_status' => null,
                'last_error_redacted' => null,
            ],
            'nac_sms' => [
                'enabled' => false,
                'profile' => 'legacy_working_http_9587',
                'scheme' => 'http',
                'host' => 'smslogin.nac.com.tr',
                'port' => 9587,
                'path' => '/sms/create',
                'request_shape' => 'legacy_working_minimal',
                'sender' => null,
                'title' => 'EMAKS TEST',
                'gateway_uuid' => null,
                'encoding' => 0,
                'commercial' => false,
                'skip_ahs_query' => false,
                'recipient_type' => 0,
                'validity' => 60,
                'report_push_url' => null,
                'use_shared_test_phone' => true,
                'test_phone' => null,
                'real_send_allowed' => false,
                'last_credit_check_status' => null,
                'last_sender_list_status' => null,
                'last_gateway_list_status' => null,
                'last_error_redacted' => null,
            ],
            'mikro_api' => [
                'enabled' => false,
                'base_url' => null,
                'api_version' => 'V17',
                'application_code' => null,
                'application_name' => null,
                'company_code' => null,
                'branch_code' => null,
                'workstation_code' => null,
                'fiscal_year' => null,
                'user_code' => null,
                'timeout_seconds' => 15,
                'server_timezone' => (string) config('services.mikro_api.server_timezone', 'Europe/Istanbul'),
                'license_status' => 'unknown',
                'app_customer_license_status' => 'unknown',
                'read_sync_enabled' => false,
                'write_enabled' => false,
                'write_approval_required' => true,
                'operation_catalog_status' => 'active',
                'operation_controls' => [],
                'last_health_check_status' => null,
                'last_error_redacted' => null,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function defaultProviderSettings(): array
    {
        $defaults = [];

        foreach (self::PROVIDERS as $key => $definition) {
            $defaults[$key] = [
                'enabled' => (bool) $definition['default_enabled'],
                'real_send_allowed' => false,
                'test_send_allowed' => true,
                'notes' => null,
            ];
        }

        return $defaults;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function defaultMessageTypeSettings(): array
    {
        $defaults = [];
        $coreWorkflowDefaults = [
            'new_request_created_ops' => [
                'enabled' => true,
                'channel_policy' => 'whatsapp_only',
                'whatsapp_mode' => 'test',
                'sms_mode' => 'disabled',
            ],
            'mount_request_created_customer' => [
                'enabled' => true,
                'channel_policy' => 'whatsapp_and_sms',
                'whatsapp_mode' => 'test',
                'sms_mode' => 'test',
            ],
            'payment_received_ops' => [
                'enabled' => true,
                'channel_policy' => 'whatsapp_only',
                'whatsapp_mode' => 'test',
                'sms_mode' => 'disabled',
            ],
            'part_received_ops' => [
                'enabled' => true,
                'channel_policy' => 'whatsapp_only',
                'whatsapp_mode' => 'test',
                'sms_mode' => 'disabled',
            ],
            'activation_warranty_customer' => [
                'enabled' => true,
                'channel_policy' => 'whatsapp_and_sms',
                'whatsapp_mode' => 'test',
                'sms_mode' => 'test',
            ],
        ];

        foreach (self::MESSAGE_TYPES as $key => $definition) {
            $defaults[$key] = [
                'enabled' => false,
                'real_send_allowed' => false,
                'test_send_allowed' => true,
                'channel_policy' => 'whatsapp_only',
                'whatsapp_mode' => 'test',
                'sms_mode' => 'disabled',
                'whatsapp_provider' => 'evo_whatsapp',
                'sms_provider' => 'nac_sms',
                'template_key' => null,
                'notes' => null,
            ];

            if (($definition['future'] ?? false) === true) {
                $defaults[$key]['test_send_allowed'] = false;
                $defaults[$key]['channel_policy'] = 'disabled';
                $defaults[$key]['whatsapp_mode'] = 'disabled';
            }

            if (isset($coreWorkflowDefaults[$key])) {
                $defaults[$key] = array_replace($defaults[$key], $coreWorkflowDefaults[$key]);
            }
        }

        return $defaults;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $updates
     * @return array<string, mixed>
     */
    private function mergeMessageTypeSettings(array $current, array $updates): array
    {
        $next = $current;

        foreach ($updates as $messageType => $values) {
            if (! array_key_exists((string) $messageType, self::MESSAGE_TYPES)) {
                throw ValidationException::withMessages([
                    'message_types' => "Bilinmeyen mesaj tipi: {$messageType}",
                ]);
            }

            if (! is_array($values)) {
                continue;
            }

            foreach ([
                'enabled',
                'real_send_allowed',
                'test_send_allowed',
            ] as $key) {
                if (array_key_exists($key, $values)) {
                    $next[$messageType][$key] = (bool) $values[$key];
                }
            }

            foreach ([
                'template_key',
                'notes',
            ] as $key) {
                if (array_key_exists($key, $values)) {
                    $value = trim((string) $values[$key]);
                    $next[$messageType][$key] = $value === '' ? null : $value;
                }
            }

            foreach ([
                'channel_policy',
                'whatsapp_mode',
                'sms_mode',
                'whatsapp_provider',
                'sms_provider',
            ] as $key) {
                if (! array_key_exists($key, $values)) {
                    continue;
                }

                $value = trim((string) $values[$key]);

                if ($key === 'channel_policy') {
                    if (! in_array($value, self::SMS_CHANNEL_POLICIES, true)) {
                        throw ValidationException::withMessages([
                            'message_types' => "Bilinmeyen kanal politikası: {$value}",
                        ]);
                    }

                    $next[$messageType][$key] = $value;

                    continue;
                }

                if (in_array($key, ['whatsapp_mode', 'sms_mode'], true)) {
                    if (! in_array($value, self::CHANNEL_MODES, true)) {
                        throw ValidationException::withMessages([
                            'message_types' => "Bilinmeyen kanal modu: {$value}",
                        ]);
                    }

                    $next[$messageType][$key] = $value;

                    continue;
                }

                $next[$messageType][$key] = $this->normalizeProviderKey($value);
            }
        }

        return $next;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function deactivateManualE2EContext(array $settings, TechnicalServiceManualE2ERunContext $context): array
    {
        if ($context->activeRunId() !== null) {
            $settings['manual_e2e_last_run_id'] = $context->activeRunId();
            $settings['manual_e2e_last_stopped_at'] = CarbonImmutable::now()->toIso8601String();
        }

        $settings['manual_e2e_enabled'] = false;
        $settings['real_send_enabled'] = false;
        $settings['queue_paused'] = true;
        $settings['ops_whatsapp_enabled'] = false;
        $settings['manual_e2e_phase'] = self::MANUAL_E2E_PHASE_FROZEN;
        $settings['manual_e2e_open_window'] = null;
        $settings['manual_e2e_active_claim'] = null;
        $settings['scoped_local_uat_active_effect_claim'] = null;
        $settings['manual_e2e_active_run_id'] = null;
        $settings['manual_e2e_started_at'] = null;
        $settings['manual_e2e_created_after'] = null;
        $settings['manual_e2e_expires_at'] = null;
        $settings['local_manual_acceptance_enabled'] = false;
        $settings['local_manual_acceptance_activated_at'] = null;
        $settings['local_manual_acceptance_profile_fingerprint'] = null;
        $settings['local_manual_acceptance_reason'] = null;

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function manualE2EReadinessForSettings(array $settings, bool $checkLifecycleLock): array
    {
        if ($this->executionMode($settings) === self::OUTBOUND_EXECUTION_MODE_LOCAL) {
            $scoped = app(ExternalExecutionControlPlaneService::class)
                ->scopedLocalUatReadiness($checkLifecycleLock);
            $inputs = $this->scopedLocalUatControlPlaneState($checkLifecycleLock);
            $opsPhone = $this->normalizePhone((string) ($settings['ops_whatsapp_phone'] ?? ''));

            return [
                'eligible' => (bool) $scoped['eligible'],
                'ready' => (bool) $scoped['ready'],
                'production_ready' => false,
                'classification' => (string) $scoped['classification'],
                'profile_id' => (string) $scoped['profile_id'],
                'profile_version' => (int) $scoped['profile_version'],
                'profile_fingerprint' => (string) $scoped['profile_fingerprint'],
                'required_capabilities' => (array) $scoped['required_capabilities'],
                'ready_capabilities' => (array) $scoped['ready_capabilities'],
                'missing_capabilities' => (array) $scoped['missing_capabilities'],
                'unrelated_global_blockers' => (array) $scoped['unrelated_global_blockers'],
                'global_live_ready' => (bool) $scoped['global_live_ready'],
                'global_live_blocker_count' => (int) $scoped['global_live_blocker_count'],
                'blockers' => (array) $scoped['blockers'],
                'warnings' => [],
                'portal_origins' => (array) $inputs['portal_origins'],
                'evo_ready' => (bool) $inputs['evo_ready'],
                'nac_ready' => (bool) $inputs['nac_ready'],
                'smtp_ready' => (bool) $inputs['smtp_ready'],
                'sandbox_payment_ready' => (bool) $inputs['sandbox_payment_ready'],
                'allowlisted_phones' => [],
                'allowlisted_phone_masks' => (array) $inputs['phone_allowlist_masks'],
                'customer_allowlisted_phone_masks' => array_values(array_filter(
                    (array) $inputs['phone_allowlist_masks'],
                    fn (mixed $mask): bool => $opsPhone === '' || $mask !== $this->maskPhone($opsPhone),
                )),
                'ops_whatsapp_phone_mask' => $opsPhone !== '' ? $this->maskPhone($opsPhone) : null,
                'ops_whatsapp_enabled' => (bool) ($settings['ops_whatsapp_enabled'] ?? false),
                'ops_sms_enabled' => false,
                'email_allowlist_masks' => (array) $inputs['email_allowlist_masks'],
                'pending_external_count' => (int) $inputs['pending_external_count'],
                'unsafe_external_count' => (int) $inputs['unsafe_external_count'],
                'non_allowlisted_pending_count' => (int) $inputs['non_allowlisted_pending_count'],
                'duplicate_pending_count' => (int) $inputs['duplicate_pending_count'],
                'worker_lock_available' => (string) $inputs['manual_worker_state'] === 'none',
                'worker_lock_raw_available' => (string) $inputs['manual_worker_state'] === 'none',
                'worker_state' => (string) $inputs['manual_worker_state'],
                'worker_run_id' => null,
                'worker_heartbeat_at' => null,
                'worker_stale_recoverable' => false,
                'lifecycle_lock_available' => ! collect((array) $scoped['blockers'])
                    ->contains(fn (array $blocker): bool => ($blocker['code'] ?? null) === 'scoped_uat_lifecycle_lock_busy'),
                'active_run_id' => TechnicalServiceManualE2ERunContext::fromSettings($settings)->activeRunId(),
                'active_run_status' => TechnicalServiceManualE2ERunContext::fromSettings($settings)->payload()['status'],
                'ttl_seconds' => (int) $inputs['ttl_seconds'],
                'channel_policies' => collect((array) $inputs['event_policy'])
                    ->map(fn (array $policy, string $event): array => [
                        'message_type' => $event,
                        'channel_policy' => (string) ($policy['channel_policy'] ?? 'disabled'),
                        'whatsapp_mode' => 'scoped',
                        'sms_mode' => 'scoped',
                    ])
                    ->values()
                    ->all(),
            ];
        }

        $blockers = [];
        if ($this->executionMode($settings) !== self::OUTBOUND_EXECUTION_MODE_LIVE) {
            $blockers[] = ['code' => 'outbound_execution_mode_local', 'message' => 'Manual E2E hazırlığı için çalışma modu önce Canlı API Testi olmalı.'];
        }
        if ($this->runtimeEnvironment() === 'production') {
            $blockers[] = ['code' => 'manual_e2e_production_forbidden', 'message' => 'Manual E2E production ortamında hazırlanamaz.'];
        }
        $allowlist = array_values(array_unique(array_filter(array_map(
            fn (mixed $phone): string => $this->normalizePhone((string) $phone),
            (array) ($settings['manual_e2e_allowlisted_phones'] ?? []),
        ))));
        if ($allowlist === [] || collect($allowlist)->contains(fn (string $phone): bool => ! $this->validPhone($phone))) {
            $blockers[] = ['code' => 'manual_e2e_allowlist_invalid', 'message' => 'Manual E2E için en az bir geçerli allowlist telefonu zorunlu.'];
        }

        $opsPhone = $this->normalizePhone((string) ($settings['ops_whatsapp_phone'] ?? ''));
        if ((bool) ($settings['ops_whatsapp_enabled'] ?? false)
            && (! $this->validPhone($opsPhone) || ! in_array($opsPhone, $allowlist, true))) {
            $blockers[] = ['code' => 'manual_e2e_ops_target_invalid', 'message' => 'Manual E2E için geçerli OPS telefonu allowlist içinde olmalı.'];
        }

        if (! (bool) ($settings['messaging_enabled'] ?? false)) {
            $blockers[] = ['code' => 'messaging_disabled', 'message' => 'Manual E2E için mesaj sistemi açık olmalı.'];
        }

        $evoReady = (bool) $this->evoWhatsappReadiness($settings)['ready'];
        if (! $evoReady) {
            $blockers[] = ['code' => 'evo_not_ready', 'message' => 'Manual E2E için Direct Evo API readiness tamamlanmalı.'];
        }

        $nacReady = (bool) ($this->nacSmsPayload($settings)['test_ready'] ?? false);
        if (! $nacReady) {
            $blockers[] = ['code' => 'nac_not_ready', 'message' => 'Manual E2E için NAC Direct Laravel test readiness tamamlanmalı.'];
        }

        $pendingStatuses = [
            TechnicalServiceMessageDispatch::STATUS_QUEUED,
            TechnicalServiceMessageDispatch::STATUS_SENDING,
            TechnicalServiceMessageDispatch::STATUS_RATE_LIMITED,
        ];
        $providers = ['evo_whatsapp', 'nac_sms'];
        $pending = TechnicalServiceMessageDispatch::query()
            ->whereIn('provider_key', $providers)
            ->where(function ($query) use ($pendingStatuses): void {
                $query->whereIn('status', $pendingStatuses)
                    ->orWhere(function ($query): void {
                        $query->where('status', TechnicalServiceMessageDispatch::STATUS_CANCELLED)
                            ->where('attempt_count', '>', 0)
                            ->whereNull('provider_message_id')
                            ->whereNull('sent_at')
                            ->whereNull('failed_at');
                    });
            })
            ->count();
        if ($pending > 0) {
            $blockers[] = ['code' => 'pending_provider_dispatch', 'message' => 'Manual E2E açılmadan önce external provider kuyruğu boş olmalı.'];
        }

        $unsafe = TechnicalServiceMessageDispatch::query()
            ->whereIn('provider_key', $providers)
            ->where(function ($query) use ($pendingStatuses): void {
                $query->whereIn('status', $pendingStatuses)
                    ->orWhere(function ($query): void {
                        $query->where('status', TechnicalServiceMessageDispatch::STATUS_CANCELLED)
                            ->where('attempt_count', '>', 0)
                            ->whereNull('provider_message_id')
                            ->whereNull('sent_at')
                            ->whereNull('failed_at');
                    });
            })
            ->whereNotIn('target_phone', $allowlist ?: ['__manual_e2e_allowlist_missing__'])
            ->count();
        if ($unsafe > 0) {
            $blockers[] = ['code' => 'unsafe_provider_dispatch', 'message' => 'Allowlist dışı pending provider dispatch bulundu.'];
        }

        if (is_array($settings['normal_outbound_active_claim'] ?? null)) {
            $blockers[] = [
                'code' => 'normal_outbound_attempt_unresolved',
                'message' => 'Son external provider attempt sonucu authoritative ledger içinde çözülmeden Manual E2E hazırlanamaz.',
            ];
        }

        $workerLease = $this->manualE2EWorkerLeaseStatus();
        $rawWorkerLockAvailable = $this->lockAvailable(TechnicalServiceManualE2ERunContext::WORKER_LOCK_KEY);
        $workerLockAvailable = $rawWorkerLockAvailable || (bool) ($workerLease['stale_recoverable'] ?? false);
        if (! $workerLockAvailable) {
            $blockers[] = ['code' => 'manual_e2e_worker_active', 'message' => 'Başka bir Manual E2E worker çalışıyor.'];
        }

        $lifecycleLockAvailable = ! $checkLifecycleLock || $this->lifecycleLockAvailable();
        if (! $lifecycleLockAvailable) {
            $blockers[] = ['code' => 'manual_e2e_lifecycle_busy', 'message' => 'Manual E2E yaşam döngüsü başka bir işlem tarafından güncelleniyor.'];
        }

        $context = TechnicalServiceManualE2ERunContext::fromSettings($settings);
        if ($context->enabled() || $context->activeRunId() !== null) {
            $blockers[] = ['code' => 'manual_e2e_active_run_exists', 'message' => 'Aktif Manual E2E oturumu zaten var. Önce gönderimleri dondurun.'];
        }
        if ((bool) ($settings['real_send_enabled'] ?? false)) {
            $blockers[] = ['code' => 'manual_e2e_real_send_not_frozen', 'message' => 'Gerçek gönderim açık. Yeni run öncesinde gönderimler dondurulmalı.'];
        }
        if (! (bool) ($settings['queue_paused'] ?? true)) {
            $blockers[] = ['code' => 'manual_e2e_queue_not_frozen', 'message' => 'Kuyruk duraklatılmamış. Yeni run öncesinde gönderimler dondurulmalı.'];
        }
        $portalOrigins = $this->portalOriginReadiness($settings);
        if (! (bool) $portalOrigins['manual_e2e']['ready'] && ! (bool) $portalOrigins['live_public']['ready']) {
            $blockers[] = [
                'code' => 'manual_e2e_partner_portal_origin_missing',
                'message' => 'Manual E2E için telefon erişimine uygun LAN portal origin veya public HTTPS portal zorunlu.',
            ];
        }

        $publicWarnings = [];
        if (! $rawWorkerLockAvailable && (bool) ($workerLease['stale_recoverable'] ?? false)) {
            $publicWarnings[] = [
                'code' => 'manual_e2e_worker_stale_lock_recoverable',
                'message' => 'Önceki Manual E2E worker lease süresi dolmuş; güvenli açma işlemi owner/run doğrulamasıyla stale lock temizleyecek.',
            ];
        }
        $publicUrl = $portalOrigins['live_public']['origin'];
        if ($publicUrl === null) {
            $publicWarnings[] = ['code' => 'public_url_missing', 'message' => 'Partner/müşteri public URL tanımlı değil; telefon linkleri canlı akışta açılamaz.'];
        } elseif (! (bool) $portalOrigins['live_public']['ready']) {
            $publicWarnings[] = ['code' => 'public_url_private', 'message' => 'Canlı partner portalı public HTTPS değil; LAN override yalnız kontrollü Manual E2E teknisyen mesajlarında kullanılabilir.'];
        }
        if ((bool) $portalOrigins['manual_e2e']['loopback']) {
            $publicWarnings[] = ['code' => 'manual_e2e_portal_loopback', 'message' => 'Loopback portal yalnız geliştirici önizlemesidir; telefon erişimine hazır sayılmaz.'];
        }

        $channelPolicies = collect((array) ($settings['message_types'] ?? []))
            ->filter(fn (mixed $type): bool => is_array($type) && (bool) ($type['enabled'] ?? false))
            ->map(fn (array $type, string $key): array => [
                'message_type' => $key,
                'channel_policy' => (string) ($type['channel_policy'] ?? 'disabled'),
                'whatsapp_mode' => (string) ($type['whatsapp_mode'] ?? 'disabled'),
                'sms_mode' => (string) ($type['sms_mode'] ?? 'disabled'),
            ])
            ->values()
            ->all();

        return [
            'eligible' => $blockers === [],
            'blockers' => $blockers,
            'warnings' => $publicWarnings,
            'portal_origins' => $portalOrigins,
            'evo_ready' => $evoReady,
            'nac_ready' => $nacReady,
            'allowlisted_phones' => $allowlist,
            'allowlisted_phone_masks' => array_map(fn (string $phone): string => $this->maskPhone($phone), $allowlist),
            'customer_allowlisted_phone_masks' => array_map(
                fn (string $phone): string => $this->maskPhone($phone),
                array_values(array_filter($allowlist, fn (string $phone): bool => $phone !== $opsPhone)),
            ),
            'ops_whatsapp_phone_mask' => $opsPhone !== '' ? $this->maskPhone($opsPhone) : null,
            'ops_whatsapp_enabled' => (bool) ($settings['ops_whatsapp_enabled'] ?? false),
            'ops_sms_enabled' => false,
            'pending_external_count' => $pending,
            'unsafe_external_count' => $unsafe,
            'worker_lock_available' => $workerLockAvailable,
            'worker_lock_raw_available' => $rawWorkerLockAvailable,
            'worker_state' => $workerLease['state'],
            'worker_run_id' => $workerLease['run_id'],
            'worker_heartbeat_at' => $workerLease['heartbeat_at'],
            'worker_stale_recoverable' => $workerLease['stale_recoverable'],
            'lifecycle_lock_available' => $lifecycleLockAvailable,
            'active_run_id' => $context->activeRunId(),
            'active_run_status' => $context->payload()['status'],
            'ttl_seconds' => (int) ($settings['manual_e2e_ttl_seconds'] ?? TechnicalServiceManualE2ERunContext::DEFAULT_TTL_SECONDS),
            'channel_policies' => $channelPolicies,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function portalOriginReadiness(array $settings): array
    {
        $manualEnabled = (bool) ($settings['manual_e2e_partner_portal_origin_enabled'] ?? false);
        $manualRaw = trim((string) ($settings['manual_e2e_partner_portal_origin'] ?? ''));
        $manualOrigin = PartnerPortalPublicUrl::normalizeOrigin($manualRaw);
        $manualPrivateLan = $manualOrigin !== null && PartnerPortalPublicUrl::isPrivateLanOrigin($manualOrigin);
        $manualLoopback = $manualOrigin !== null && PartnerPortalPublicUrl::isLoopbackOrigin($manualOrigin);
        $manualValid = $manualOrigin !== null && ($manualPrivateLan || $manualLoopback);
        $manualPhoneReady = $manualValid && $manualPrivateLan && ! $manualLoopback;
        $manualReady = app()->environment('local', 'testing') && $manualEnabled && $manualPhoneReady;

        $configuredPublic = trim((string) config('services.partner_portal.public_url', ''));
        $fallbackPublic = trim((string) config('app.url', ''));
        $liveRaw = $configuredPublic !== '' ? $configuredPublic : $fallbackPublic;
        $liveOrigin = PartnerPortalPublicUrl::normalizeBaseUrl($liveRaw);
        $liveReady = PartnerPortalPublicUrl::isPublicHttpsUrl($liveOrigin);

        return [
            'manual_e2e' => [
                'enabled' => $manualEnabled,
                'origin' => $manualOrigin ?? ($manualRaw !== '' ? $manualRaw : null),
                'valid' => $manualValid,
                'private_lan' => $manualPrivateLan,
                'loopback' => $manualLoopback,
                'phone_ready' => $manualPhoneReady,
                'ready' => $manualReady,
                'status' => ! $manualEnabled || $manualRaw === ''
                    ? 'missing'
                    : ($manualValid ? ($manualReady ? 'ready' : 'missing') : 'invalid'),
                'status_label' => ! $manualEnabled || $manualRaw === ''
                    ? 'Missing'
                    : ($manualValid ? ($manualReady ? 'Ready' : 'Missing') : 'Invalid'),
                'source' => 'admin_manual_e2e_partner_portal_origin',
                'warning' => $manualLoopback
                    ? 'Loopback adres telefondan erişilebilir değildir.'
                    : null,
            ],
            'live_public' => [
                'origin' => $liveOrigin,
                'ready' => $liveReady,
                'status' => $liveReady ? 'ready' : 'missing',
                'status_label' => $liveReady ? 'Ready' : 'Missing',
                'source' => $configuredPublic !== ''
                    ? 'services.partner_portal.public_url'
                    : 'app.url',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function validateSettings(array $settings): void
    {
        $this->validateManualE2ELifecycleState($settings);

        if ((int) ($settings['manual_e2e_ttl_seconds'] ?? 0) < 60
            || (int) ($settings['manual_e2e_ttl_seconds'] ?? 0) > TechnicalServiceManualE2ERunContext::DEFAULT_TTL_SECONDS) {
            throw ValidationException::withMessages([
                'manual_e2e_ttl_seconds' => 'Manual E2E TTL 60 ile 14400 saniye arasında olmalı.',
            ]);
        }

        if ((bool) $settings['messaging_enabled']
            && (bool) $settings['test_mode_enabled']
            && ! $this->validPhone((string) $settings['test_phone'])) {
            throw ValidationException::withMessages([
                'test_phone' => 'Test modu aktifken geçerli test telefon numarası zorunlu.',
            ]);
        }
        if ((bool) $settings['messaging_enabled'] && (bool) $settings['test_mode_enabled']) {
            foreach ([
                'customer_test_phone' => 'Test modu aktifken geçerli müşteri test telefonu zorunlu.',
                'technician_ops_test_phone' => 'Test modu aktifken geçerli usta / OPS test telefonu zorunlu.',
            ] as $field => $message) {
                if (! $this->validPhone((string) ($settings[$field] ?? ''))) {
                    throw ValidationException::withMessages([$field => $message]);
                }
            }
        }

        $manualPortalOrigin = trim((string) ($settings['manual_e2e_partner_portal_origin'] ?? ''));
        $normalizedManualPortalOrigin = PartnerPortalPublicUrl::normalizeOrigin($manualPortalOrigin);
        if ($manualPortalOrigin !== ''
            && ($normalizedManualPortalOrigin === null
                || (! PartnerPortalPublicUrl::isPrivateLanOrigin($normalizedManualPortalOrigin)
                    && ! PartnerPortalPublicUrl::isLoopbackOrigin($normalizedManualPortalOrigin)))
        ) {
            throw ValidationException::withMessages([
                'manual_e2e_partner_portal_origin' => 'Manual E2E portal adresi path/query/credential içermeyen RFC1918 LAN veya loopback origin olmalı.',
            ]);
        }
        if ((bool) ($settings['manual_e2e_partner_portal_origin_enabled'] ?? false) && $manualPortalOrigin === '') {
            throw ValidationException::withMessages([
                'manual_e2e_partner_portal_origin' => 'Yerel portal adresi etkinleştirildiğinde origin zorunlu.',
            ]);
        }

        if ((int) $settings['send_delay_seconds'] < 30) {
            throw ValidationException::withMessages([
                'send_delay_seconds' => 'Gönderim aralığı en az 30 saniye olmalı.',
            ]);
        }

        if ((int) $settings['duplicate_cooldown_minutes'] < 1) {
            throw ValidationException::withMessages([
                'duplicate_cooldown_minutes' => 'Duplicate cooldown en az 1 dakika olmalı.',
            ]);
        }

        if ((int) $settings['hourly_limit'] < 1 || (int) $settings['daily_limit'] < 1) {
            throw ValidationException::withMessages([
                'limits' => 'Saatlik ve günlük limit pozitif olmalı.',
            ]);
        }

        if ((int) $settings['max_auto_retries'] < 0 || (int) $settings['max_auto_retries'] > 3) {
            throw ValidationException::withMessages([
                'max_auto_retries' => 'Maksimum otomatik retry 0 ile 3 arasında olmalı.',
            ]);
        }

        $this->validateNacSmsSettings($settings['nac_sms']);
        $this->validateMikroApiSettings($settings['mikro_api']);

        foreach ([
            'provider_key',
            'active_provider',
            'default_provider',
            'fallback_provider',
        ] as $providerField) {
            if (! array_key_exists((string) $settings[$providerField], self::PROVIDERS)) {
                throw ValidationException::withMessages([
                    $providerField => 'Bilinmeyen mesajlaşma sağlayıcısı seçildi.',
                ]);
            }
        }

        if ((bool) $settings['real_send_enabled']
            && ! $this->manualE2EExecutionScopeAllowed($settings)
            && ! $this->normalLocalAdminExecutionAuthority($settings)) {
            throw ValidationException::withMessages([
                'real_send_enabled' => 'Gerçek gönderim yalnız global Canlı, normal yerel Admin otoritesi veya exact allowlistli Yerel UAT kapsamında açılabilir.',
            ]);
        }

        $evo = $settings['evo_whatsapp'] ?? [];
        $evoBaseUrl = trim((string) ($evo['direct_api_base_url'] ?? ''));
        $evoInstanceName = trim((string) ($evo['direct_api_instance_name'] ?? ''));

        if ($evoBaseUrl !== '' && ! filter_var($evoBaseUrl, FILTER_VALIDATE_URL)) {
            throw ValidationException::withMessages([
                'evo_whatsapp.direct_api_base_url' => 'Evo Direct API base URL geçerli bir URL olmalı.',
            ]);
        }

        if ($evoInstanceName !== '' && ! preg_match('/^[A-Za-z0-9._:-]+$/', $evoInstanceName)) {
            throw ValidationException::withMessages([
                'evo_whatsapp.direct_api_instance_name' => 'Evo instance adı sadece harf, sayı, nokta, tire, alt çizgi veya iki nokta içerebilir.',
            ]);
        }

        foreach ($settings['message_types'] ?? [] as $messageType => $typeSettings) {
            if (($typeSettings['whatsapp_mode'] ?? 'test') === 'live'
                || ($typeSettings['sms_mode'] ?? 'disabled') === 'live') {
                throw ValidationException::withMessages([
                    'message_types' => "{$messageType} doğrudan canlı kanal moduna alınamaz; kontrollü gerçek gönderim Manual E2E yaşam döngüsü ve queue guard üzerinden yönetilir.",
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function validateManualE2ELifecycleState(array $settings): void
    {
        $storedPhase = trim((string) ($settings['manual_e2e_phase'] ?? ''));
        $manualEnabled = (bool) ($settings['manual_e2e_enabled'] ?? false);
        $realSend = (bool) ($settings['real_send_enabled'] ?? false);
        $queuePaused = (bool) ($settings['queue_paused'] ?? true);
        $runId = TechnicalServiceManualE2ERunContext::normalizeRunId($settings['manual_e2e_active_run_id'] ?? null);
        $window = is_array($settings['manual_e2e_open_window'] ?? null) ? $settings['manual_e2e_open_window'] : null;
        $claim = is_array($settings['manual_e2e_active_claim'] ?? null) ? $settings['manual_e2e_active_claim'] : null;
        $effectClaim = is_array($settings['scoped_local_uat_active_effect_claim'] ?? null)
            ? $settings['scoped_local_uat_active_effect_claim']
            : null;
        $normalClaim = is_array($settings['normal_outbound_active_claim'] ?? null)
            ? $settings['normal_outbound_active_claim']
            : null;
        $executionMode = $this->executionMode($settings);
        $productionLive = $executionMode === self::OUTBOUND_EXECUTION_MODE_LIVE
            && $this->runtimeEnvironment() === 'production';
        $localAcceptanceEnabled = (bool) ($settings['local_manual_acceptance_enabled'] ?? false);
        $localAcceptanceCurrent = $this->localManualAcceptanceIsCurrent($settings);

        // Legacy/non-manual settings predate persisted lifecycle phases. Once a
        // lifecycle operation writes a phase, every transition is strict.
        if ($storedPhase === ''
            && ! $manualEnabled
            && ! $realSend
            && ! $localAcceptanceEnabled
            && $runId === null
            && $window === null
            && $claim === null
            && $effectClaim === null
            && $normalClaim === null) {
            return;
        }

        $phase = $storedPhase !== '' ? $storedPhase : self::MANUAL_E2E_PHASE_FROZEN;
        $startedAt = $this->safeDate($settings['manual_e2e_started_at'] ?? null);
        $createdAfter = $this->safeDate($settings['manual_e2e_created_after'] ?? null);
        $expiresAt = $this->safeDate($settings['manual_e2e_expires_at'] ?? null);
        $runSnapshot = is_array($settings['manual_e2e_run_snapshot'] ?? null)
            ? $settings['manual_e2e_run_snapshot']
            : [];
        $snapshotFingerprint = trim((string) ($runSnapshot['allowlist_fingerprint'] ?? ''));
        $frozenDispatchingEffect = $phase === self::MANUAL_E2E_PHASE_FROZEN
            && $runId === null
            && $effectClaim !== null
            && (string) ($effectClaim['status'] ?? '') === 'dispatching'
            && (string) ($effectClaim['run_id'] ?? '') !== ''
            && (string) ($effectClaim['run_id'] ?? '') === (string) ($settings['manual_e2e_last_run_id'] ?? '');
        $effectRunId = $runId ?? ($frozenDispatchingEffect
            ? (string) ($settings['manual_e2e_last_run_id'] ?? '')
            : null);
        $runContextInvalid = $runId !== null && (
            $startedAt === null
            || $createdAfter === null
            || $expiresAt === null
            || $startedAt->gt($createdAfter)
            || ! $createdAfter->lt($expiresAt)
            || $snapshotFingerprint === ''
            || ! hash_equals($snapshotFingerprint, $this->allowlistFingerprint((array) ($settings['manual_e2e_allowlisted_phones'] ?? [])))
            || ! app(ExternalExecutionControlPlaneService::class)->messagingRunSnapshotIsCurrent($runSnapshot)
            || $this->scopedLocalUatRunEnvelopeInvalid($settings, $runId, $createdAfter, $expiresAt)
            || $this->runtimeEnvironment() === 'production'
        );

        $normalClaimInvalid = $normalClaim !== null && (
            ! in_array((string) ($normalClaim['status'] ?? ''), ['http_started', 'ambiguous_no_retry'], true)
            || (int) ($normalClaim['dispatch_id'] ?? 0) <= 0
            || (int) ($normalClaim['attempt_count'] ?? 0) !== 1
            || ! in_array((string) ($normalClaim['provider'] ?? ''), ['evo_whatsapp', 'nac_sms'], true)
            || ! in_array((string) ($normalClaim['channel'] ?? ''), ['whatsapp', 'sms'], true)
            || ! preg_match('/^[a-f0-9]{64}$/', (string) ($normalClaim['claim_hash'] ?? ''))
            || ! preg_match('/^[a-f0-9]{64}$/', (string) ($normalClaim['recipient_fingerprint'] ?? ''))
            || ! preg_match('/^[a-f0-9]{64}$/', (string) ($normalClaim['tuple_hash'] ?? ''))
        );
        $effectClaimInvalid = $effectClaim !== null && (
            ! in_array((string) ($effectClaim['status'] ?? ''), ['claimed', 'dispatching'], true)
            || (string) ($effectClaim['run_id'] ?? '') !== $effectRunId
            || (int) ($effectClaim['payment_id'] ?? 0) <= 0
            || ! in_array((string) ($effectClaim['channel'] ?? ''), ['email', 'sandbox_payment'], true)
            || ! in_array((string) ($effectClaim['provider'] ?? ''), ['smtp', 'fake_payment', 'iyzico_sandbox'], true)
            || ! in_array((string) ($effectClaim['operation'] ?? ''), [
                'sandbox_payment_notification',
                self::SCOPED_EFFECT_PAYMENT_CREATE,
                self::SCOPED_EFFECT_PAYMENT_CALLBACK,
            ], true)
            || ! preg_match('/^[a-f0-9]{64}$/', (string) ($effectClaim['claim_hash'] ?? ''))
            || ! preg_match('/^[a-f0-9]{64}$/', (string) ($effectClaim['idempotency_hash'] ?? ''))
            || ! preg_match('/^[a-f0-9]{64}$/', (string) ($effectClaim['configuration_fingerprint'] ?? ''))
            || ! preg_match('/^[a-f0-9]{64}$/', (string) ($effectClaim['origin_fingerprint'] ?? ''))
            || ! filter_var($effectClaim['attempted'] ?? false, FILTER_VALIDATE_BOOL)
            || ((string) ($effectClaim['channel'] ?? '') === 'email'
                && ((string) ($effectClaim['capability'] ?? '') !== ExternalEffectCapabilityRegistry::MAIL_SMTP_SEND
                    || (string) ($effectClaim['provider'] ?? '') !== 'smtp'
                    || ! preg_match('/^[a-f0-9]{64}$/', (string) ($effectClaim['recipient_fingerprint'] ?? ''))))
            || ((string) ($effectClaim['channel'] ?? '') === 'sandbox_payment'
                && ((string) ($effectClaim['capability'] ?? '') !== ExternalEffectCapabilityRegistry::PAYMENT_LOCAL_SANDBOX_EXECUTE
                    || ! in_array((string) ($effectClaim['provider'] ?? ''), ['fake_payment', 'iyzico_sandbox'], true)))
        );

        $invalid = $normalClaimInvalid || $effectClaimInvalid || match ($phase) {
            self::MANUAL_E2E_PHASE_FROZEN => $manualEnabled
                || ($localAcceptanceEnabled
                    && ! $localAcceptanceCurrent
                    && ! $this->normalLocalAdminExecutionAuthority($settings))
                || ($productionLive
                    ? (! $realSend || $queuePaused || $localAcceptanceEnabled)
                    : ($localAcceptanceCurrent ? (! $realSend || $queuePaused) : ($realSend === $queuePaused)))
                || $runId !== null
                || $window !== null
                || $claim !== null
                || ($effectClaim !== null && ! $frozenDispatchingEffect),
            self::MANUAL_E2E_PHASE_PREPARED => ! $manualEnabled
                || $localAcceptanceEnabled
                || $realSend
                || ! $queuePaused
                || $runId === null
                || $runContextInvalid
                || $window !== null
                || ($claim !== null && (
                    ! in_array((string) ($claim['status'] ?? ''), ['claimed', 'http_started'], true)
                    || (string) ($claim['run_id'] ?? '') !== $runId
                    || (int) ($claim['dispatch_id'] ?? 0) <= 0
                    || trim((string) ($claim['claim_hash'] ?? '')) === ''
                    || $this->manualE2ESecurityTupleIncomplete($claim)
                    || $this->invalidWindowTtl($claim, $settings)
                ))
                || ($claim !== null && $effectClaim !== null)
                || $normalClaim !== null,
            self::MANUAL_E2E_PHASE_WINDOW_OPEN => ! $manualEnabled
                || $localAcceptanceEnabled
                || ! $this->manualE2EExecutionScopeAllowed($settings)
                || ! $realSend
                || $queuePaused
                || $runId === null
                || $runContextInvalid
                || $window === null
                || $claim !== null
                || $effectClaim !== null
                || (string) ($window['status'] ?? '') !== 'open'
                || (string) ($window['run_id'] ?? '') !== $runId
                || (int) ($window['dispatch_id'] ?? 0) <= 0
                || ! in_array((string) ($window['provider'] ?? ''), ['evo_whatsapp', 'nac_sms'], true)
                || ! in_array((string) ($window['channel'] ?? ''), ['whatsapp', 'sms'], true)
                || (int) ($window['maximum_attempts'] ?? 0) !== 1
                || $this->manualE2ESecurityTupleIncomplete($window)
                || $this->invalidWindowTtl($window, $settings)
                || $normalClaim !== null,
            default => true,
        };

        if ($invalid) {
            throw ValidationException::withMessages([
                'manual_e2e' => 'Manual E2E lifecycle durumu geçersiz; provider kapıları fail-closed tutuldu.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function manualE2ESecurityTupleIncomplete(array $scope): bool
    {
        return (int) ($scope['request_id'] ?? 0) <= 0
            || ! array_key_exists('offer_cycle_id', $scope)
            || trim((string) ($scope['role_target'] ?? '')) === ''
            || ! preg_match('/^[a-f0-9]{64}$/', (string) ($scope['recipient_fingerprint'] ?? ''))
            || ! preg_match('/^[a-f0-9]{64}$/', (string) ($scope['idempotency_fingerprint'] ?? ''))
            || ! preg_match('/^[a-f0-9]{64}$/', (string) ($scope['body_fingerprint'] ?? ''))
            || (int) ($scope['effect_window_seconds'] ?? 0) <= 0
            || ! preg_match('/^[a-f0-9]{64}$/', (string) ($scope['effect_window_fingerprint'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function scopedLocalUatRunEnvelopeInvalid(
        array $settings,
        string $runId,
        ?CarbonImmutable $createdAfter,
        ?CarbonImmutable $expiresAt,
    ): bool {
        $snapshot = is_array($settings['manual_e2e_run_snapshot'] ?? null)
            ? $settings['manual_e2e_run_snapshot']
            : [];
        if (($snapshot['scoped_local_uat_profile_id'] ?? null) !== ExternalEffectCapabilityRegistry::LOCAL_ALLOWLISTED_UAT_PROFILE) {
            return false;
        }

        $profile = app(ExternalEffectCapabilityRegistry::class)->localAllowlistedUatProfile();
        $effectWindowContract = self::resolvedManualE2EEffectWindowContract($snapshot);
        $snapshotCreatedAfter = $this->safeDate($snapshot['scoped_local_uat_created_after'] ?? null);
        $ttlSeconds = $createdAfter !== null && $expiresAt !== null
            ? (int) floor($createdAfter->diffInSeconds($expiresAt))
            : 0;

        return (string) ($snapshot['scoped_local_uat_run_id'] ?? '') !== $runId
            || $createdAfter === null
            || $snapshotCreatedAfter === null
            || ! $snapshotCreatedAfter->equalTo($createdAfter)
            || $ttlSeconds < 60
            || $ttlSeconds > self::SCOPED_LOCAL_UAT_MAX_TTL_SECONDS
            || $effectWindowContract === null
            || $effectWindowContract['effect_window_seconds'] !== self::SCOPED_LOCAL_UAT_EFFECT_WINDOW_SECONDS
            || self::resolvedScopedLocalUatMessageBodyContract($snapshot, $runId) === null
            || (array) ($snapshot['scoped_local_uat_limits'] ?? []) !== (array) $profile['limits']
            || ! preg_match('/^[a-f0-9]{64}$/', (string) ($snapshot['scoped_local_uat_email_allowlist_fingerprint'] ?? ''))
            || ! preg_match('/^[a-f0-9]{64}$/', (string) ($snapshot['scoped_local_uat_event_policy_fingerprint'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $window
     * @param  array<string, mixed>  $settings
     */
    private function invalidWindowTtl(array $window, array $settings): bool
    {
        $snapshot = is_array($settings['manual_e2e_run_snapshot'] ?? null)
            ? $settings['manual_e2e_run_snapshot']
            : [];
        $effectWindowContract = self::resolvedManualE2EEffectWindowContract($snapshot);
        $runExpiresAt = $this->safeDate($settings['manual_e2e_expires_at'] ?? null);
        try {
            $openedAt = CarbonImmutable::parse((string) ($window['opened_at'] ?? ''));
            $expiresAt = CarbonImmutable::parse((string) ($window['expires_at'] ?? ''));
        } catch (Throwable) {
            return true;
        }

        if ($effectWindowContract === null || $runExpiresAt === null) {
            return true;
        }

        $expectedExpiresAt = $openedAt->addSeconds($effectWindowContract['effect_window_seconds']);
        if ($runExpiresAt->lt($expectedExpiresAt)) {
            $expectedExpiresAt = $runExpiresAt;
        }

        return ! $openedAt->lt($expiresAt)
            || ! $expiresAt->equalTo($expectedExpiresAt)
            || (int) ($window['effect_window_seconds'] ?? 0) !== $effectWindowContract['effect_window_seconds']
            || ! hash_equals(
                $effectWindowContract['effect_window_fingerprint'],
                (string) ($window['effect_window_fingerprint'] ?? ''),
            );
    }

    private function safeDate(mixed $value): ?CarbonImmutable
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function readiness(array $settings): array
    {
        $portalOrigins = $this->portalOriginReadiness($settings);
        $webhookConfigured = trim((string) config('services.evolution.n8n_webhook_url', '')) !== '';
        $evoDirect = $this->evoWhatsappReadiness($settings);
        $providerSecretConfigured = $evoDirect['credentials_ready'];
        $testPhoneConfigured = $this->validPhone((string) ($settings['customer_test_phone'] ?? ''))
            && $this->validPhone((string) ($settings['technician_ops_test_phone'] ?? ''));
        $activeProvider = $this->normalizeProviderKey((string) $settings['active_provider']);
        $activeProviderDefinition = self::PROVIDERS[$activeProvider];
        $activeProviderEnabled = $this->providerEnabled($activeProvider, $settings);
        $activeProviderSupportsText = (bool) ($activeProviderDefinition['capabilities']['supports_text'] ?? false);
        $activeProviderCredentialsReady = $this->providerCredentialsReady($activeProvider, $settings, $webhookConfigured);
        $activeProviderRealReady = $this->providerRealReady($activeProvider, $settings, $webhookConfigured);
        $realAllowedTypes = collect($settings['message_types'] ?? [])
            ->filter(fn (array $type): bool => (bool) ($type['enabled'] ?? false)
                && ($this->messageTypeAllowsChannel($type, 'whatsapp', (string) ($type['whatsapp_provider'] ?? ''))
                    || $this->messageTypeAllowsChannel($type, 'sms', (string) ($type['sms_provider'] ?? ''))))
            ->keys()
            ->values()
            ->all();
        $testAllowedTypes = collect($settings['message_types'] ?? [])
            ->filter(fn (array $type): bool => (bool) ($type['enabled'] ?? false) && (bool) ($type['test_send_allowed'] ?? false))
            ->keys()
            ->values()
            ->all();

        $manualE2eContext = TechnicalServiceManualE2ERunContext::fromSettings($settings);
        $queueReady = $this->safeQueueReady($settings, $manualE2eContext);
        $disabledReasons = [];

        if (! (bool) $settings['messaging_enabled']) {
            $disabledReasons[] = 'Mesaj sistemi kapalı.';
        }
        if (! $testPhoneConfigured) {
            $disabledReasons[] = 'Test telefonu eksik.';
        }
        if ($activeProvider === 'evo_whatsapp' && ! $evoDirect['ready']) {
            $disabledReasons[] = 'Evo Direct API base URL/instance/API key eksik.';
        }
        if ($activeProvider === 'nac_sms' && ! $activeProviderCredentialsReady) {
            $disabledReasons[] = 'NAC SMS Basic Auth bilgileri eksik.';
        }
        if ($activeProvider === 'mikro_api' && ! $activeProviderCredentialsReady) {
            $disabledReasons[] = 'Mikro API key/token eksik.';
        }
        if (! $activeProviderEnabled) {
            $disabledReasons[] = 'Aktif mesajlaşma sağlayıcısı kapalı.';
        }
        if (! $activeProviderSupportsText) {
            $disabledReasons[] = 'Aktif sağlayıcı mesaj gönderimini desteklemiyor veya sözleşmesi bekleniyor.';
        }
        if (! (bool) ($activeProviderDefinition['contract_confirmed'] ?? false)) {
            $disabledReasons[] = 'Aktif sağlayıcının API sözleşmesi doğrulanmadı.';
        }
        if ($realAllowedTypes === []) {
            $disabledReasons[] = 'Aktif kanal/provider kombinasyonuna sahip mesaj tipi yok.';
        }
        if ((bool) ($settings['queue_paused'] ?? true)) {
            $disabledReasons[] = 'Provider kuyruğu duraklatıldı.';
        } elseif (! $queueReady) {
            $disabledReasons[] = 'Provider kuyruğu açık ancak current release sender heartbeat hazır değil.';
        }

        $canSendTest = (bool) $settings['messaging_enabled']
            && (bool) $settings['test_mode_enabled']
            && $testPhoneConfigured
            && $testAllowedTypes !== []
            && $activeProviderEnabled
            && $activeProviderSupportsText;
        $canSendReal = (bool) $settings['messaging_enabled']
            && (bool) $settings['real_send_enabled']
            && (! (bool) $settings['test_mode_enabled'] || $testPhoneConfigured)
            && $realAllowedTypes !== []
            && $activeProviderRealReady
            && $queueReady;

        return [
            'messaging_enabled' => (bool) $settings['messaging_enabled'],
            'real_send_enabled' => (bool) $settings['real_send_enabled'],
            'test_mode_enabled' => (bool) $settings['test_mode_enabled'],
            'test_phone_configured' => $testPhoneConfigured,
            'provider_webhook_configured' => $webhookConfigured,
            'provider_secret_configured' => $providerSecretConfigured,
            'evo_direct_api_enabled' => $evoDirect['enabled'],
            'evo_direct_api_ready' => $evoDirect['ready'],
            'evo_direct_api_credentials_ready' => $evoDirect['credentials_ready'],
            'evo_direct_api_base_url_configured' => $evoDirect['base_url_ready'],
            'evo_direct_api_instance_configured' => $evoDirect['instance_ready'],
            'active_provider' => $activeProvider,
            'active_provider_label' => $activeProviderDefinition['label'],
            'default_provider' => $settings['default_provider'],
            'fallback_provider' => $settings['fallback_provider'],
            'provider_priority' => $settings['provider_priority'],
            'active_provider_enabled' => $activeProviderEnabled,
            'active_provider_supports_text' => $activeProviderSupportsText,
            'active_provider_contract_confirmed' => (bool) ($activeProviderDefinition['contract_confirmed'] ?? false),
            'active_provider_credentials_ready' => $activeProviderCredentialsReady,
            'active_provider_real_ready' => $activeProviderRealReady,
            'queue_ready' => $queueReady,
            'manual_e2e_active' => $manualE2eContext->isActive(),
            'manual_e2e_worker_command_ready' => $manualE2eContext->workerCommand() !== null,
            'manual_e2e_blocker_code' => $manualE2eContext->contextBlockingReason()['code'] ?? null,
            'manual_e2e_partner_portal_ready' => (bool) $portalOrigins['manual_e2e']['ready'],
            'live_public_partner_portal_ready' => (bool) $portalOrigins['live_public']['ready'],
            'can_send_test' => $canSendTest,
            'can_send_real' => $canSendReal,
            'effective_mode' => $this->effectiveMode($settings, $testPhoneConfigured),
            'disabled_reasons' => array_values(array_unique($disabledReasons)),
            'real_allowed_message_types' => $realAllowedTypes,
            'test_allowed_message_types' => $testAllowedTypes,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function effectiveMode(array $settings, bool $testPhoneConfigured): string
    {
        $activeProvider = $this->normalizeProviderKey((string) $settings['active_provider']);
        $activeProviderDefinition = self::PROVIDERS[$activeProvider];

        if (! (bool) $settings['messaging_enabled']) {
            return 'disabled';
        }

        if (! $this->providerEnabled($activeProvider, $settings)) {
            return 'blocked_provider_disabled';
        }

        if (! (bool) ($activeProviderDefinition['capabilities']['supports_text'] ?? false)) {
            return 'blocked_provider_contract_pending';
        }

        if (! (bool) $settings['real_send_enabled']) {
            return 'blocked_real_send_disabled';
        }

        if ((bool) $settings['test_mode_enabled']) {
            return $testPhoneConfigured ? 'test_redirect' : 'blocked_missing_test_phone';
        }

        if ($activeProvider === 'evo_whatsapp' && ! $this->evoWhatsappReadiness($settings)['ready']) {
            return 'blocked_provider_missing';
        }

        if (! $this->providerRealReady($activeProvider, $settings, false)) {
            return 'blocked_provider_not_ready';
        }

        return 'real_ready';
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function safeQueueReady(
        array $settings,
        ?TechnicalServiceManualE2ERunContext $context = null,
    ): bool {
        $context ??= TechnicalServiceManualE2ERunContext::fromSettings($settings);

        if ($this->executionMode($settings) === self::OUTBOUND_EXECUTION_MODE_LIVE
            && $this->runtimeEnvironment() === 'production') {
            $worker = $this->outboundWorkerLeaseStatus();
            $releaseSha = $this->runtimeReleaseSha();

            return (bool) ($settings['messaging_enabled'] ?? false)
                && (bool) ($settings['real_send_enabled'] ?? false)
                && ! (bool) ($settings['queue_paused'] ?? true)
                && $context->phase() === self::MANUAL_E2E_PHASE_FROZEN
                && $releaseSha !== null
                && ($worker['state'] ?? null) === 'active'
                && hash_equals((string) ($worker['release_sha'] ?? ''), $releaseSha);
        }

        if ($this->normalLocalAdminExecutionAuthority($settings)
            || $this->localManualAcceptanceIsCurrent($settings)) {
            $worker = $this->outboundWorkerLeaseStatus();
            $releaseSha = $this->runtimeReleaseSha();

            return (bool) ($settings['messaging_enabled'] ?? false)
                && (bool) ($settings['real_send_enabled'] ?? false)
                && ! (bool) ($settings['queue_paused'] ?? true)
                && $releaseSha !== null
                && ($worker['state'] ?? null) === 'active'
                && ($worker['profile'] ?? null) === self::LOCAL_MANUAL_ACCEPTANCE_PROFILE
                && hash_equals((string) ($worker['release_sha'] ?? ''), $releaseSha);
        }

        return (bool) ($settings['messaging_enabled'] ?? false)
            && $this->manualE2EExecutionScopeAllowed($settings, $context)
            && (bool) ($settings['real_send_enabled'] ?? false)
            && ! (bool) ($settings['test_mode_enabled'] ?? false)
            && ! (bool) ($settings['queue_paused'] ?? true)
            && $context->isActive()
            && $context->workerCommand() !== null;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function manualE2EExecutionScopeAllowed(
        array $settings,
        ?TechnicalServiceManualE2ERunContext $context = null,
    ): bool {
        if ($this->executionMode($settings) === self::OUTBOUND_EXECUTION_MODE_LIVE) {
            return true;
        }

        if ($this->runtimeEnvironment() === 'production') {
            return false;
        }

        if ($this->localManualAcceptanceIsCurrent($settings)) {
            return true;
        }

        $context ??= TechnicalServiceManualE2ERunContext::fromSettings($settings);
        $snapshot = is_array($settings['manual_e2e_run_snapshot'] ?? null)
            ? $settings['manual_e2e_run_snapshot']
            : [];

        return $this->executionMode($settings) === self::OUTBOUND_EXECUTION_MODE_LOCAL
            && $this->isScopedLocalUatSettings($settings)
            && app(ExternalExecutionControlPlaneService::class)->messagingRunSnapshotIsCurrent($snapshot);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function normalLocalAdminExecutionAuthority(array $settings): bool
    {
        $context = TechnicalServiceManualE2ERunContext::fromSettings($settings);

        return $this->runtimeEnvironment() !== 'production'
            && $this->executionMode($settings) === self::OUTBOUND_EXECUTION_MODE_LOCAL
            && $context->phase() === self::MANUAL_E2E_PHASE_FROZEN
            && ! $context->enabled()
            && $context->activeRunId() === null;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function isScopedLocalUatSettings(array $settings): bool
    {
        return data_get($settings, 'manual_e2e_run_snapshot.scoped_local_uat_profile_id')
            === ExternalEffectCapabilityRegistry::LOCAL_ALLOWLISTED_UAT_PROFILE;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function localManualAcceptanceIsCurrent(array $settings): bool
    {
        if (! (bool) ($settings['local_manual_acceptance_enabled'] ?? false)
            || $this->runtimeEnvironment() === 'production'
            || $this->executionMode($settings) !== self::OUTBOUND_EXECUTION_MODE_LOCAL) {
            return false;
        }

        $activatedAt = $this->safeDate($settings['local_manual_acceptance_activated_at'] ?? null);
        $storedFingerprint = trim((string) ($settings['local_manual_acceptance_profile_fingerprint'] ?? ''));

        return $activatedAt !== null
            && preg_match('/^[a-f0-9]{64}$/', $storedFingerprint) === 1
            && hash_equals($storedFingerprint, $this->localManualAcceptanceFingerprint($settings));
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function localManualAcceptanceFingerprint(array $settings): string
    {
        $allowlist = array_values(array_unique(array_filter(array_map(
            fn (mixed $phone): string => $this->normalizePhone((string) $phone),
            (array) ($settings['manual_e2e_allowlisted_phones'] ?? []),
        ))));
        sort($allowlist);

        $messageTypes = [];
        foreach ((array) ($settings['message_types'] ?? []) as $key => $policy) {
            if (! is_array($policy) || ! (bool) ($policy['enabled'] ?? false)) {
                continue;
            }
            $messageTypes[(string) $key] = Arr::only($policy, [
                'enabled',
                'channel_policy',
                'whatsapp_mode',
                'sms_mode',
                'whatsapp_provider',
                'sms_provider',
                'template_key',
            ]);
        }
        ksort($messageTypes);
        $global = app(ExternalExecutionControlPlaneService::class)->state();

        return hash('sha256', json_encode([
            'profile' => self::LOCAL_MANUAL_ACCEPTANCE_PROFILE,
            'version' => self::LOCAL_MANUAL_ACCEPTANCE_PROFILE_VERSION,
            'activated_at' => $this->nullableScalar($settings['local_manual_acceptance_activated_at'] ?? null),
            'runtime_environment' => $this->runtimeEnvironment(),
            'release_sha' => $this->runtimeReleaseSha(),
            'global_operator_mode' => $global['operator_mode'] ?? null,
            'global_state' => $global['transition_state'] ?? null,
            'global_epoch' => (int) ($global['epoch'] ?? 0),
            'global_revision' => (int) ($global['revision'] ?? 0),
            'global_profile_fingerprint' => $global['profile_fingerprint'] ?? null,
            'messaging_enabled' => (bool) ($settings['messaging_enabled'] ?? false),
            'real_send_enabled' => (bool) ($settings['real_send_enabled'] ?? false),
            'queue_paused' => (bool) ($settings['queue_paused'] ?? true),
            'test_mode_enabled' => (bool) ($settings['test_mode_enabled'] ?? false),
            'customer_test_phone_fingerprint' => hash('sha256', (string) ($settings['customer_test_phone'] ?? '')),
            'technician_ops_test_phone_fingerprint' => hash('sha256', (string) ($settings['technician_ops_test_phone'] ?? '')),
            'max_auto_retries' => (int) ($settings['max_auto_retries'] ?? -1),
            'allowlist_fingerprint' => $this->allowlistFingerprint($allowlist),
            'origin_enabled' => (bool) ($settings['manual_e2e_partner_portal_origin_enabled'] ?? false),
            'origin_fingerprint' => hash('sha256', (string) ($settings['manual_e2e_partner_portal_origin'] ?? '')),
            'evo_profile_fingerprint' => $this->messagingProfileFingerprint('evo_whatsapp', $settings),
            'nac_profile_fingerprint' => $this->messagingProfileFingerprint('nac_sms', $settings),
            'evo_real_send_allowed' => $this->providerRealSendAllowed('evo_whatsapp', $settings),
            'nac_real_send_allowed' => $this->providerRealSendAllowed('nac_sms', $settings),
            'message_types' => $messageTypes,
            'mikro' => [
                'active' => (bool) data_get($settings, 'mikro_api.active', false),
                'read_sync' => (bool) data_get($settings, 'mikro_api.read_sync', false),
                'write' => (bool) data_get($settings, 'mikro_api.write', false),
            ],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function assertLocalManualAcceptanceActivationReady(array $settings): void
    {
        $context = TechnicalServiceManualE2ERunContext::fromSettings($settings);
        $allowlist = array_values(array_unique(array_filter(array_map(
            fn (mixed $phone): string => $this->normalizePhone((string) $phone),
            (array) ($settings['manual_e2e_allowlisted_phones'] ?? []),
        ))));
        $origin = PartnerPortalPublicUrl::normalizeOrigin((string) ($settings['manual_e2e_partner_portal_origin'] ?? ''));
        $worker = $this->outboundWorkerLeaseStatus();
        $global = app(ExternalExecutionControlPlaneService::class)->state();

        if ($this->executionMode($settings) !== self::OUTBOUND_EXECUTION_MODE_LOCAL
            || ($global['transition_state'] ?? null) !== ExternalExecutionControlPlaneService::STATE_LOCAL) {
            throw new ConflictHttpException('Yerel manuel kabul için global execution authority local olmalı.');
        }
        if ($this->runtimeReleaseSha() === null) {
            throw new ConflictHttpException('Yerel manuel kabul için exact runtime release SHA zorunlu.');
        }
        if ($context->phase() !== self::MANUAL_E2E_PHASE_FROZEN
            || $context->enabled()
            || $context->activeRunId() !== null
            || is_array($settings['manual_e2e_open_window'] ?? null)
            || is_array($settings['manual_e2e_active_claim'] ?? null)
            || is_array($settings['scoped_local_uat_active_effect_claim'] ?? null)
            || is_array($settings['normal_outbound_active_claim'] ?? null)) {
            throw new ConflictHttpException('Yerel manuel kabul profili yalnız frozen ve claimsiz lifecycle durumundan açılabilir.');
        }
        if ((bool) ($settings['real_send_enabled'] ?? false)
            || ! (bool) ($settings['queue_paused'] ?? true)) {
            throw new ConflictHttpException('Yerel manuel kabul profili yalnız dış etkiye kapalı queue durumundan açılabilir.');
        }
        if (! (bool) ($settings['messaging_enabled'] ?? false)
            || (int) ($settings['max_auto_retries'] ?? -1) !== 0) {
            throw new ConflictHttpException('Yerel manuel kabul kill-switch veya retry sözleşmesi hazır değil.');
        }
        if ($allowlist === [] || collect($allowlist)->contains(fn (string $phone): bool => ! $this->validPhone($phone))) {
            throw new ConflictHttpException('Yerel manuel kabul için geçerli recipient allowlist zorunlu.');
        }
        if ((bool) ($settings['test_mode_enabled'] ?? false)) {
            foreach (['customer', 'technician'] as $role) {
                $roleTestPhone = $this->testPhoneForRole($settings, $role);
                if ($roleTestPhone === '' || ! in_array($roleTestPhone, $allowlist, true)) {
                    throw new ConflictHttpException("Test modu için {$role} test telefonu recipient allowlist içinde olmalı.");
                }
            }
        }
        if (! (bool) ($settings['manual_e2e_partner_portal_origin_enabled'] ?? false)
            || $origin === null
            || ! PartnerPortalPublicUrl::isPrivateLanOrigin($origin)) {
            throw new ConflictHttpException('Yerel manuel kabul için telefon erişimine açık private LAN origin zorunlu.');
        }
        $workerState = (string) ($worker['state'] ?? 'none');
        if (! in_array($workerState, ['none', 'active'], true)
            || ($workerState === 'active'
                && (($worker['profile'] ?? null) !== self::LOCAL_MANUAL_ACCEPTANCE_PROFILE
                    || ! hash_equals(
                        (string) ($worker['release_sha'] ?? ''),
                        (string) $this->runtimeReleaseSha(),
                    )))
            || $this->pendingExternalDispatchCount() !== 0
            || $this->unsafeExternalDispatchCount() !== 0) {
            throw new ConflictHttpException('Yerel manuel kabul açılmadan önce sender current release olmalı ve actionable external backlog sıfır kalmalı.');
        }

        $activePolicies = collect((array) ($settings['message_types'] ?? []))
            ->filter(fn (mixed $policy): bool => is_array($policy)
                && (bool) ($policy['enabled'] ?? false));
        if ($activePolicies->isEmpty()) {
            throw new ConflictHttpException('Yerel manuel kabul için en az bir aktif mesaj tipi zorunlu.');
        }
        foreach ($activePolicies as $policy) {
            foreach ([
                ['whatsapp', (string) ($policy['whatsapp_provider'] ?? '')],
                ['sms', (string) ($policy['sms_provider'] ?? '')],
            ] as [$channel, $provider]) {
                if ($this->messageTypeAllowsChannel((array) $policy, $channel, $provider)
                    && ! $this->providerRealReady($provider, $settings, false)) {
                    throw new ConflictHttpException("Yerel manuel kabul {$provider} readiness geçmedi.");
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function deactivateLocalManualAcceptanceSettings(array $settings, string $reason): array
    {
        return [
            ...$settings,
            'real_send_enabled' => false,
            'queue_paused' => true,
            'local_manual_acceptance_enabled' => false,
            'local_manual_acceptance_activated_at' => null,
            'local_manual_acceptance_profile_fingerprint' => null,
            'local_manual_acceptance_reason' => Str::limit(trim($reason), 160, ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function metadataHasManualE2EScope(array $metadata): bool
    {
        return filter_var($metadata['manual_e2e'] ?? false, FILTER_VALIDATE_BOOL)
            || TechnicalServiceManualE2ERunContext::dispatchRunId($metadata) !== null;
    }

    /**
     * Canonical authorization shared by normal local enqueue and claim. It
     * intentionally ignores the legacy per-event real-send bit while keeping
     * event/channel/provider, recipient, readiness and URL gates fail-closed.
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $metadata
     * @return array{allowed:bool,code:string|null,message:string|null}
     */
    private function normalLocalDispatchAuthorization(
        string $provider,
        string $channel,
        string $messageType,
        string $targetPhone,
        string $body,
        array $metadata,
        array $settings,
    ): array {
        if (! $this->normalLocalAdminExecutionAuthority($settings)
            || ! (bool) ($settings['messaging_enabled'] ?? false)
            || ! (bool) ($settings['real_send_enabled'] ?? false)
            || (bool) ($settings['queue_paused'] ?? true)) {
            return $this->executionBlock('outbound_execution_mode_local', 'Mesaj Lokal çalışma modunda dış sağlayıcıya gönderilmeden kaydedildi.');
        }

        $provider = $this->normalizeProviderKey($provider);
        $typePolicy = (array) data_get($settings, 'message_types.'.$messageType, []);
        if (! in_array($provider, ['evo_whatsapp', 'nac_sms'], true)
            || ! in_array($channel, ['whatsapp', 'sms'], true)
            || ! (bool) ($typePolicy['enabled'] ?? false)
            || ! $this->messageTypeAllowsChannel($typePolicy, $channel, $provider)) {
            return $this->executionBlock('message_type_channel_disabled', 'Dispatch mesaj tipi, kanal veya provider ayarı aktif değil.');
        }

        $target = $this->normalizePhone($targetPhone);
        $allowlist = array_values(array_filter(array_map(
            fn (mixed $phone): string => $this->normalizePhone((string) $phone),
            (array) ($settings['manual_e2e_allowlisted_phones'] ?? []),
        )));
        if ($target === ''
            || ((bool) ($settings['test_mode_enabled'] ?? false)
                && ! in_array($target, $allowlist, true))) {
            return $this->executionBlock('normal_local_recipient_blocked', 'Dispatch recipient test allowlist dışında.');
        }
        if (! $this->providerRealReady($provider, $settings, false)) {
            return $this->executionBlock('outbound_provider_set_not_ready', 'Dispatch provider readiness geçmedi.');
        }
        if (! $this->localManualAcceptanceBodyUrlsAreSafe($body, $messageType, $metadata, $settings)) {
            return $this->executionBlock('normal_local_link_host_blocked', 'Dispatch body yalnız configured uygulama originini, canonical Google Maps bağlantısını veya locked canonical ödeme linkini içerebilir.');
        }

        return ['allowed' => true, 'code' => null, 'message' => null];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function providerCredentialsReady(string $provider, array $settings, bool $webhookConfigured): bool
    {
        $provider = $this->normalizeProviderKey($provider);

        if ($provider === 'null_local') {
            return true;
        }

        if ($provider === 'evo_whatsapp') {
            return $this->evoWhatsappReadiness($settings)['ready'];
        }

        if ($provider === 'nac_sms') {
            return $this->credential('nac_sms')?->basicAuthReady() ?? false;
        }

        if ($provider === 'mikro_api') {
            return $this->credential('mikro_api')?->apiKeyReady() ?? false;
        }

        return false;
    }

    private function providerRealReady(string $provider, array $settings, bool $webhookConfigured): bool
    {
        $provider = $this->normalizeProviderKey($provider);
        $definition = self::PROVIDERS[$provider];

        if (! $this->providerEnabled($provider, $settings)
            || ! $this->providerRealSendAllowed($provider, $settings)
            || ! (bool) ($definition['contract_confirmed'] ?? false)
            || ! (bool) ($definition['capabilities']['supports_text'] ?? false)) {
            return false;
        }

        if ($provider === 'evo_whatsapp') {
            return $this->evoWhatsappReadiness($settings)['ready'];
        }

        if ($provider === 'nac_sms') {
            return $this->nacProviderReadyForLive($settings, $this->runtimeEnvironment() === 'production');
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $readiness
     * @return array<int, array<string, mixed>>
     */
    private function providerPayload(array $settings, array $readiness): array
    {
        $payload = [];

        foreach (self::PROVIDERS as $key => $definition) {
            $providerSettings = $settings['providers'][$key] ?? [];
            $realReady = $this->providerRealReady($key, $settings, (bool) $readiness['provider_webhook_configured']);
            $readyReason = $definition['disabled_reason'] ?? null;

            if ($key === 'evo_whatsapp' && ! (bool) ($readiness['evo_direct_api_ready'] ?? false)) {
                $readyReason = 'Evo Direct API base URL/instance/API key eksik.';
            }

            if ($key === 'null_local') {
                $readyReason = 'Provider çağrısı yapmayan güvenli yerel mod.';
            }

            $payload[] = [
                'key' => $key,
                'label' => $definition['label'],
                'channel' => $definition['channel'],
                'description' => $definition['description'],
                'status_label' => $definition['status_label'],
                'enabled' => $this->providerEnabled($key, $settings),
                'real_send_allowed' => $this->providerRealSendAllowed($key, $settings),
                'test_send_allowed' => (bool) ($providerSettings['test_send_allowed'] ?? false),
                'contract_confirmed' => (bool) ($definition['contract_confirmed'] ?? false),
                'current_practical' => (bool) ($definition['current_practical'] ?? false),
                'active' => $readiness['active_provider'] === $key,
                'default' => $settings['default_provider'] === $key,
                'fallback' => $settings['fallback_provider'] === $key,
                'real_ready' => $realReady,
                'ready_reason' => $readyReason,
                'capabilities' => $this->providerCapabilities($key),
                'notes' => $providerSettings['notes'] ?? null,
            ];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<int, array<string, mixed>>
     */
    private function workflowProviderPayload(array $settings): array
    {
        $payload = [];

        foreach (self::PROVIDERS as $key => $definition) {
            $payload[] = [
                'key' => $key,
                'enabled' => $this->providerEnabled($key, $settings),
                'contract_confirmed' => (bool) ($definition['contract_confirmed'] ?? false),
                'capabilities' => $this->providerCapabilities($key),
            ];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $manualE2e
     * @return array<string, mixed>
     */
    private function globalPayload(array $settings, array $manualE2e): array
    {
        return [
            'messaging_enabled' => (bool) $settings['messaging_enabled'],
            'real_send_enabled' => (bool) $settings['real_send_enabled'],
            'test_mode_enabled' => (bool) $settings['test_mode_enabled'],
            'manual_e2e_enabled' => (bool) ($settings['manual_e2e_enabled'] ?? false),
            'manual_e2e_active_run_id' => $manualE2e['active_run_id'],
            'manual_e2e_started_at' => $manualE2e['started_at'],
            'manual_e2e_created_after' => $manualE2e['created_after'],
            'manual_e2e_expires_at' => $manualE2e['expires_at'],
            'manual_e2e_last_run_id' => $manualE2e['last_run_id'],
            'manual_e2e_last_stopped_at' => $manualE2e['last_stopped_at'],
            'manual_e2e_phase' => $manualE2e['phase'],
            'manual_e2e_ttl_seconds' => (int) ($settings['manual_e2e_ttl_seconds'] ?? TechnicalServiceManualE2ERunContext::DEFAULT_TTL_SECONDS),
            'manual_e2e_allowlisted_phones' => $settings['manual_e2e_allowlisted_phones'] ?? [],
            'manual_e2e_partner_portal_origin_enabled' => (bool) ($settings['manual_e2e_partner_portal_origin_enabled'] ?? false),
            'manual_e2e_partner_portal_origin' => $settings['manual_e2e_partner_portal_origin'] ?? null,
            'manual_e2e_allowlisted_phone_masks' => array_map(
                fn (string $phone): string => $this->maskPhone($phone),
                $settings['manual_e2e_allowlisted_phones'] ?? [],
            ),
            'ops_whatsapp_enabled' => (bool) ($settings['ops_whatsapp_enabled'] ?? false),
            'ops_whatsapp_phone' => $settings['ops_whatsapp_phone'] ?? null,
            'ops_whatsapp_phone_masked' => $this->maskPhone((string) ($settings['ops_whatsapp_phone'] ?? '')),
            'shared_test_phone' => $settings['test_phone'],
            'shared_test_phone_masked' => $this->maskPhone($settings['test_phone']),
            'test_phone' => $settings['test_phone'],
            'test_phone_masked' => $this->maskPhone($settings['test_phone']),
            'customer_test_phone' => $settings['customer_test_phone'],
            'customer_test_phone_masked' => $this->maskPhone($settings['customer_test_phone']),
            'technician_ops_test_phone' => $settings['technician_ops_test_phone'],
            'technician_ops_test_phone_masked' => $this->maskPhone($settings['technician_ops_test_phone']),
            'queue_paused' => (bool) $settings['queue_paused'],
            'provider_key' => $settings['provider_key'],
            'active_provider' => $settings['active_provider'],
            'default_provider' => $settings['default_provider'],
            'fallback_provider' => $settings['fallback_provider'],
            'provider_priority' => $settings['provider_priority'],
            'send_delay_seconds' => (int) $settings['send_delay_seconds'],
            'duplicate_cooldown_minutes' => (int) $settings['duplicate_cooldown_minutes'],
            'hourly_limit' => (int) $settings['hourly_limit'],
            'daily_limit' => (int) $settings['daily_limit'],
            'max_auto_retries' => (int) $settings['max_auto_retries'],
            'allow_browser_smoke_send' => (bool) $settings['allow_browser_smoke_send'],
            'allow_test_fixture_send' => (bool) $settings['allow_test_fixture_send'],
        ];
    }

    /**
     * @return array<string, array<string, bool>>
     */
    private function capabilityMap(): array
    {
        $map = [];

        foreach (self::PROVIDERS as $key => $definition) {
            $map[$key] = $this->providerCapabilities($key);
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<int, array<string, mixed>>
     */
    private function messageTypePayload(array $settings): array
    {
        $payload = [];

        foreach (self::MESSAGE_TYPES as $key => $definition) {
            $typeSettings = $settings[$key] ?? [];
            $payload[] = [
                'key' => $key,
                'label' => $definition['label'],
                'recipient_role' => $definition['recipient_role'],
                'description' => $definition['description'],
                'future' => (bool) ($definition['future'] ?? false),
                'enabled' => (bool) ($typeSettings['enabled'] ?? false),
                'real_send_allowed' => (bool) ($typeSettings['real_send_allowed'] ?? false),
                'test_send_allowed' => (bool) ($typeSettings['test_send_allowed'] ?? true),
                'channel_policy' => $typeSettings['channel_policy'] ?? 'whatsapp_only',
                'whatsapp_mode' => $typeSettings['whatsapp_mode'] ?? 'test',
                'sms_mode' => $typeSettings['sms_mode'] ?? 'disabled',
                'whatsapp_provider' => $typeSettings['whatsapp_provider'] ?? 'evo_whatsapp',
                'sms_provider' => $typeSettings['sms_provider'] ?? 'nac_sms',
                'template_key' => $typeSettings['template_key'] ?? null,
                'notes' => $typeSettings['notes'] ?? null,
            ];
        }

        return $payload;
    }

    private function normalizeProviderKey(string $provider): string
    {
        $provider = trim($provider);

        if ($provider === 'evolution_n8n') {
            return 'evo_whatsapp';
        }

        if ($provider === 'sms_nac') {
            return 'nac_sms';
        }

        return array_key_exists($provider, self::PROVIDERS) ? $provider : 'null_local';
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function providerEnabled(string $provider, array $settings): bool
    {
        $provider = $this->normalizeProviderKey($provider);

        if ($provider === 'nac_sms') {
            return (bool) ($settings['nac_sms']['enabled'] ?? false);
        }

        if ($provider === 'mikro_api') {
            return (bool) ($settings['mikro_api']['enabled'] ?? false);
        }

        return (bool) ($settings['providers'][$provider]['enabled'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function providerRealSendAllowed(string $provider, array $settings): bool
    {
        $provider = $this->normalizeProviderKey($provider);

        if ($provider === 'nac_sms') {
            return (bool) ($settings['nac_sms']['real_send_allowed'] ?? false);
        }

        return (bool) ($settings['providers'][$provider]['real_send_allowed'] ?? false);
    }

    /**
     * @param  array<int, mixed>  $priority
     * @return array<int, string>
     */
    private function normalizeProviderPriority(array $priority): array
    {
        $normalized = [];

        foreach ($priority as $provider) {
            $key = $this->normalizeProviderKey((string) $provider);

            if (! in_array($key, $normalized, true)) {
                $normalized[] = $key;
            }
        }

        foreach (array_keys(self::PROVIDERS) as $provider) {
            if (! in_array($provider, $normalized, true)) {
                $normalized[] = $provider;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $updates
     * @return array<string, mixed>
     */
    private function mergeProviderSettings(array $current, array $updates): array
    {
        $next = $current;

        foreach ($updates as $provider => $values) {
            $key = $this->normalizeProviderKey((string) $provider);

            if (! is_array($values)) {
                continue;
            }

            foreach ([
                'enabled',
                'real_send_allowed',
                'test_send_allowed',
            ] as $field) {
                if (array_key_exists($field, $values)) {
                    $next[$key][$field] = (bool) $values[$field];
                }
            }

            if (array_key_exists('notes', $values)) {
                $value = trim((string) $values['notes']);
                $next[$key]['notes'] = $value === '' ? null : $value;
            }
        }

        return $next;
    }

    /**
     * @return array<string, bool>
     */
    private function providerCapabilities(string $provider): array
    {
        return array_replace(
            self::PROVIDER_CAPABILITY_DEFAULTS,
            self::PROVIDERS[$provider]['capabilities'] ?? [],
        );
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $updates
     * @return array<string, mixed>
     */
    private function mergeEvoWhatsappSettings(array $current, array $updates): array
    {
        $next = $current;

        foreach ([
            'direct_api_enabled',
            'link_preview',
        ] as $field) {
            if (array_key_exists($field, $updates)) {
                $next[$field] = (bool) $updates[$field];
            }
        }

        foreach ([
            'direct_api_base_url',
            'direct_api_instance_name',
            'last_test_status',
            'last_error_redacted',
        ] as $field) {
            if (array_key_exists($field, $updates)) {
                $value = trim((string) $updates[$field]);
                $next[$field] = $value === '' ? null : $value;
            }
        }

        if (array_key_exists('delay', $updates)) {
            $next['delay'] = max(0, min(120, (int) $updates['delay']));
        }

        return $next;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $updates
     * @return array<string, mixed>
     */
    private function mergeNacSmsSettings(array $current, array $updates): array
    {
        $next = $current;

        foreach ([
            'enabled',
            'commercial',
            'skip_ahs_query',
            'use_shared_test_phone',
            'real_send_allowed',
        ] as $field) {
            if (array_key_exists($field, $updates)) {
                $next[$field] = (bool) $updates[$field];
            }
        }

        foreach ([
            'profile',
            'scheme',
            'host',
            'path',
            'request_shape',
            'sender',
            'title',
            'gateway_uuid',
            'report_push_url',
            'test_phone',
        ] as $field) {
            if (array_key_exists($field, $updates)) {
                $value = trim((string) $updates[$field]);
                $next[$field] = $value === '' ? null : $value;
            }
        }

        foreach ([
            'port',
            'encoding',
            'recipient_type',
            'validity',
        ] as $field) {
            if (array_key_exists($field, $updates)) {
                $next[$field] = (int) $updates[$field];
            }
        }

        if (array_key_exists('test_phone', $updates) && filled($updates['test_phone'])) {
            $next['test_phone'] = $this->normalizePhone((string) $updates['test_phone']);
        }

        if (array_key_exists('profile', $updates)) {
            $next = $this->applyNacEndpointProfile($next);
        }

        return $next;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function applyNacEndpointProfile(array $settings): array
    {
        return match ($settings['profile'] ?? 'legacy_working_http_9587') {
            'docs_https_9588' => [
                ...$settings,
                'scheme' => 'https',
                'host' => 'smslogin.nac.com.tr',
                'port' => 9588,
                'path' => '/sms/create',
                'request_shape' => 'docs_full',
            ],
            'legacy_working_http_9587' => [
                ...$settings,
                'scheme' => 'http',
                'host' => 'smslogin.nac.com.tr',
                'port' => 9587,
                'path' => '/sms/create',
                'request_shape' => 'legacy_working_minimal',
            ],
            default => $settings,
        };
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $updates
     * @return array<string, mixed>
     */
    private function mergeMikroApiSettings(array $current, array $updates): array
    {
        $next = $current;

        foreach ([
            'enabled',
            'read_sync_enabled',
            'write_enabled',
            'write_approval_required',
        ] as $field) {
            if (array_key_exists($field, $updates)) {
                $next[$field] = (bool) $updates[$field];
            }
        }

        foreach ([
            'base_url',
            'api_version',
            'application_code',
            'application_name',
            'company_code',
            'branch_code',
            'workstation_code',
            'fiscal_year',
            'user_code',
            'server_timezone',
            'license_status',
            'app_customer_license_status',
            'last_health_check_status',
            'last_error_redacted',
        ] as $field) {
            if (array_key_exists($field, $updates)) {
                $value = trim((string) $updates[$field]);
                $next[$field] = $value === '' ? null : $value;
            }
        }

        if (array_key_exists('timeout_seconds', $updates)) {
            $next['timeout_seconds'] = (int) $updates['timeout_seconds'];
        }

        if (array_key_exists('operation_controls', $updates)) {
            $next['operation_controls'] = $this->mergeMikroOperationControls(
                (array) ($current['operation_controls'] ?? []),
                (array) $updates['operation_controls'],
            );
        }

        $next['operation_catalog_status'] = 'active';

        return $next;
    }

    /**
     * @param  array<string, array<string, mixed>>  $current
     * @param  array<string, array<string, mixed>>  $updates
     * @return array<string, array<string, mixed>>
     */
    private function mergeMikroOperationControls(array $current, array $updates): array
    {
        $next = $current;

        foreach ($updates as $operationKey => $values) {
            if (! is_string($operationKey) || ! is_array($values)) {
                throw ValidationException::withMessages(['mikro_api.operation_controls' => 'Mikro operasyon kontrolü geçersiz.']);
            }

            try {
                $operation = $this->mikroOperationRegistry->operation($operationKey);
            } catch (DomainException) {
                throw ValidationException::withMessages(["mikro_api.operation_controls.{$operationKey}" => 'Bilinmeyen Mikro operasyonu.']);
            }

            $control = (array) ($next[$operationKey] ?? []);
            if (array_key_exists('runtime_enabled', $values)) {
                $control['runtime_enabled'] = (bool) ($operation['runtime_eligible'] ?? false)
                    && (bool) $values['runtime_enabled'];
            }

            if (($operation['mode'] ?? null) === 'READ' && array_key_exists('source_mode', $values)) {
                $sourceMode = strtolower(trim((string) $values['source_mode']));
                if (! $this->mikroOperationRegistry->sourceModeAllowed($sourceMode)) {
                    throw ValidationException::withMessages(["mikro_api.operation_controls.{$operationKey}.source_mode" => 'Mikro source mode geçersiz.']);
                }
                $control['source_mode'] = $sourceMode;
            }

            if (($operation['mode'] ?? null) === 'WRITE') {
                $control['source_mode'] = 'disabled';
                $control['approval_required'] = true;
                $control['runtime_enabled'] = false;
            }

            $next[$operationKey] = $control;
        }

        ksort($next);

        return $next;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function validateNacSmsSettings(array $settings): void
    {
        if (! in_array($settings['profile'], ['docs_https_9588', 'legacy_working_http_9587', 'custom'], true)) {
            throw ValidationException::withMessages(['nac_sms.profile' => 'NAC SMS endpoint profili geçersiz.']);
        }

        if (! in_array($settings['scheme'], ['https', 'http'], true)) {
            throw ValidationException::withMessages(['nac_sms.scheme' => 'NAC SMS şema http veya https olmalı.']);
        }

        if (! in_array($settings['request_shape'], ['legacy_working_minimal', 'docs_full'], true)) {
            throw ValidationException::withMessages(['nac_sms.request_shape' => 'NAC SMS request shape geçersiz.']);
        }

        $path = trim((string) ($settings['path'] ?? ''));
        if ($path === '' || ! str_starts_with($path, '/')) {
            throw ValidationException::withMessages(['nac_sms.path' => 'NAC SMS path / ile başlamalı.']);
        }

        if ((int) $settings['port'] < 1 || (int) $settings['port'] > 65535) {
            throw ValidationException::withMessages(['nac_sms.port' => 'NAC SMS port 1-65535 arasında olmalı.']);
        }

        $title = trim((string) ($settings['title'] ?? ''));
        if ($title !== '' && (mb_strlen($title) < 5 || mb_strlen($title) > 50)) {
            throw ValidationException::withMessages(['nac_sms.title' => 'NAC SMS paket başlığı 5-50 karakter olmalı.']);
        }

        if (! in_array((int) $settings['encoding'], [0, 1, 2], true)) {
            throw ValidationException::withMessages(['nac_sms.encoding' => 'NAC SMS encoding 0, 1 veya 2 olmalı.']);
        }

        if (! in_array((int) $settings['recipient_type'], [0, 1, 2], true)) {
            throw ValidationException::withMessages(['nac_sms.recipient_type' => 'NAC SMS alıcı tipi 0, 1 veya 2 olmalı.']);
        }

        if ((int) $settings['validity'] < 60 || (int) $settings['validity'] > 1440) {
            throw ValidationException::withMessages(['nac_sms.validity' => 'Single SMS geçerlilik süresi 60-1440 aralığında olmalıdır.']);
        }

        if (! (bool) $settings['use_shared_test_phone']
            && filled($settings['test_phone'])
            && ! $this->validPhone((string) $settings['test_phone'])) {
            throw ValidationException::withMessages(['nac_sms.test_phone' => 'NAC SMS test telefonu geçerli olmalı.']);
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function validateMikroApiSettings(array $settings): void
    {
        if ((int) $settings['timeout_seconds'] < 3 || (int) $settings['timeout_seconds'] > 120) {
            throw ValidationException::withMessages(['mikro_api.timeout_seconds' => 'Mikro API timeout 3-120 saniye arasında olmalı.']);
        }

        if ((bool) $settings['write_enabled'] && ! (bool) $settings['write_approval_required']) {
            throw ValidationException::withMessages(['mikro_api.write_approval_required' => 'Mikro yazma işlemleri onaysız açılamaz.']);
        }

        try {
            new DateTimeZone((string) $settings['server_timezone']);
        } catch (Throwable) {
            throw ValidationException::withMessages(['mikro_api.server_timezone' => 'Mikro sunucu saat dilimi geçersiz.']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function evoWhatsappPayload(array $settings): array
    {
        $evo = $settings['evo_whatsapp'];
        $credential = $this->credential('evo_whatsapp');
        $readiness = $this->evoWhatsappReadiness($settings);

        $blocking = [];
        if (! $readiness['enabled']) {
            $blocking[] = 'Evo Direct API kapalı.';
        }
        if (! $readiness['base_url_ready']) {
            $blocking[] = 'Evo Direct API base URL eksik.';
        }
        if (! $readiness['instance_ready']) {
            $blocking[] = 'Evo instance adı eksik.';
        }
        if (! $readiness['credentials_ready']) {
            $blocking[] = 'Evo Direct API key/token eksik.';
        }

        return [
            'direct_api_enabled' => $readiness['enabled'],
            'direct_api_base_url' => $evo['direct_api_base_url'],
            'direct_api_instance_name' => $evo['direct_api_instance_name'],
            'endpoint_url' => $this->evoEndpointUrl((string) ($evo['direct_api_base_url'] ?? ''), (string) ($evo['direct_api_instance_name'] ?? '')),
            'delay' => (int) $evo['delay'],
            'link_preview' => (bool) $evo['link_preview'],
            'credentials_ready' => $readiness['credentials_ready'],
            'api_key_mask' => $credential?->api_key_mask,
            'token_mask' => $credential?->token_mask,
            'direct_api_ready' => $readiness['ready'],
            'queue_ready' => $readiness['ready'],
            'test_ready' => $readiness['ready'],
            'live_ready' => $readiness['ready'] && (bool) $settings['real_send_enabled'],
            'legacy_webhook_configured' => trim((string) config('services.evolution.n8n_webhook_url', '')) !== '',
            'transport' => 'direct_evolution_api',
            'last_test_status' => $evo['last_test_status'],
            'last_error_redacted' => $evo['last_error_redacted'],
            'blocking_reasons' => array_values(array_unique($blocking)),
        ];
    }

    /**
     * @return array{enabled:bool,ready:bool,credentials_ready:bool,base_url_ready:bool,instance_ready:bool}
     */
    private function evoWhatsappReadiness(array $settings): array
    {
        $evo = $settings['evo_whatsapp'] ?? [];
        $credential = $this->credential('evo_whatsapp');
        $enabled = (bool) ($evo['direct_api_enabled'] ?? false);
        $baseUrlReady = trim((string) ($evo['direct_api_base_url'] ?? '')) !== '';
        $instanceReady = trim((string) ($evo['direct_api_instance_name'] ?? '')) !== '';
        $credentialsReady = $credential?->apiKeyReady() ?? false;

        return [
            'enabled' => $enabled,
            'ready' => $enabled && $baseUrlReady && $instanceReady && $credentialsReady,
            'credentials_ready' => $credentialsReady,
            'base_url_ready' => $baseUrlReady,
            'instance_ready' => $instanceReady,
        ];
    }

    private function evoEndpointUrl(string $baseUrl, string $instanceName): ?string
    {
        $baseUrl = trim($baseUrl);
        $instanceName = trim($instanceName);

        if ($baseUrl === '' || $instanceName === '') {
            return null;
        }

        return rtrim($baseUrl, '/').'/message/sendText/'.$instanceName;
    }

    /**
     * @return array<string, mixed>
     */
    private function nacSmsPayload(array $settings): array
    {
        $nac = $settings['nac_sms'];
        $credential = $this->credential('nac_sms');
        $credentialsReady = $credential?->basicAuthReady() ?? false;
        $senderReady = filled($nac['sender'] ?? null);
        $hostReady = filled($nac['host'] ?? null) && filled($nac['port'] ?? null) && filled($nac['path'] ?? null);
        $testPhone = (bool) $nac['use_shared_test_phone']
            ? (string) $settings['test_phone']
            : (string) ($nac['test_phone'] ?? '');
        $testPhoneReady = $this->validPhone($testPhone);
        $queueReady = $this->safeQueueReady($settings);
        $liveReady = (bool) $nac['enabled']
            && $credentialsReady
            && $senderReady
            && $hostReady
            && $queueReady
            && (bool) $settings['real_send_enabled']
            && (bool) $nac['real_send_allowed'];

        $blocking = [];
        if (! (bool) $nac['enabled']) {
            $blocking[] = 'NAC SMS sağlayıcısı kapalı.';
        }
        if (! $credentialsReady) {
            $blocking[] = 'NAC SMS Basic Auth bilgileri eksik.';
        }
        if (! $senderReady) {
            $blocking[] = 'NAC SMS gönderen başlığı eksik.';
        }
        if (! $hostReady) {
            $blocking[] = 'NAC SMS host/port/path eksik.';
        }
        if (! $testPhoneReady) {
            $blocking[] = 'Ortak test telefonu eksik veya geçersiz.';
        }
        if ((bool) ($settings['queue_paused'] ?? true)) {
            $blocking[] = 'Provider kuyruğu duraklatıldı.';
        } elseif (! $queueReady) {
            $blocking[] = 'NAC gerçek gönderimi için aktif Manual E2E run context ve güvenli worker komutu zorunlu.';
        }

        return [
            'enabled' => (bool) $nac['enabled'],
            'profile' => $nac['profile'],
            'scheme' => $nac['scheme'],
            'host' => $nac['host'],
            'port' => (int) $nac['port'],
            'path' => $nac['path'],
            'request_shape' => $nac['request_shape'],
            'base_url' => $this->nacBaseUrl($nac),
            'endpoint_url' => $this->nacEndpointUrl($nac),
            'sender' => $nac['sender'],
            'title' => $this->nacTitle($nac),
            'gateway_uuid' => $nac['gateway_uuid'],
            'encoding' => (int) $nac['encoding'],
            'commercial' => (bool) $nac['commercial'],
            'skip_ahs_query' => (bool) $nac['skip_ahs_query'],
            'recipient_type' => (int) $nac['recipient_type'],
            'validity' => (int) $nac['validity'],
            'report_push_url' => $nac['report_push_url'],
            'use_shared_test_phone' => (bool) $nac['use_shared_test_phone'],
            'test_phone' => $nac['test_phone'],
            'test_phone_masked' => $this->maskPhone($testPhone),
            'real_send_allowed' => (bool) $nac['real_send_allowed'],
            'credentials_ready' => $credentialsReady,
            'username_mask' => $credential?->username_mask,
            'password_mask' => $credentialsReady ? '********' : null,
            'test_ready' => (bool) $nac['enabled'] && $credentialsReady && $senderReady && $hostReady && $testPhoneReady,
            'live_ready' => $liveReady,
            'queue_ready' => $queueReady,
            'last_credit_check_status' => $nac['last_credit_check_status'],
            'last_sender_list_status' => $nac['last_sender_list_status'],
            'last_gateway_list_status' => $nac['last_gateway_list_status'],
            'last_error_redacted' => $nac['last_error_redacted'],
            'blocking_reasons' => array_values(array_unique($blocking)),
            'endpoints' => [
                'single_sms' => 'POST /sms/create',
                'credit' => 'GET /user/credit',
                'sender_list' => 'POST /sms/list-sender',
                'gateway_list' => 'POST /sms/list-gateway',
                'report' => 'POST /sms/list',
                'report_item' => 'POST /sms/list-item',
                'cancel' => 'POST /sms/cancel',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mikroApiPayload(array $settings): array
    {
        $mikro = $settings['mikro_api'];
        $credential = $this->credential('mikro_api');
        $controls = (array) ($mikro['operation_controls'] ?? []);
        $catalog = $this->mikroOperationRegistry->summary($controls);
        $runtimeStates = [];
        $origin = trim((string) ($mikro['base_url'] ?? ''));
        foreach ($catalog['operations'] as $operation) {
            if (($operation['mode'] ?? null) === 'READ' && $origin !== '') {
                $runtimeStates[$operation['operation_key']] = $this->mikroRuntimeState->circuit($origin, $operation['operation_key']);
            }
        }
        $catalog = $this->mikroOperationRegistry->summary($controls, $runtimeStates);
        $contractReady = $catalog['status'] === 'active'
            && ($catalog['matrix_complete'] ?? false);
        $userCode = $this->mikroUserCode($mikro, $credential);
        $apiKeyPresent = $credential !== null && filled($credential->api_key_encrypted);
        $passwordPresent = $credential !== null && filled($credential->password_encrypted);
        $tokenPresent = $credential !== null && filled($credential->token_encrypted);
        $credentialsReady = $apiKeyPresent && $passwordPresent;
        $healthBlockerCodes = $this->mikroHealthConfigurationBlockerCodes($mikro);
        $healthConfigurationReady = $healthBlockerCodes === [];
        $liveConfigurationReady = $this->mikroLiveConfigurationReady($mikro, $credential);
        $healthReady = in_array(
            strtolower(trim((string) ($mikro['last_health_check_status'] ?? ''))),
            ['ok', 'pass', 'success', 'up'],
            true,
        );
        $canaryEligibility = $this->mikroOperationRegistry->canaryEligibility([
            'base_url' => $mikro['base_url'],
            'live_configuration_ready' => $liveConfigurationReady,
            'health_ready' => $healthReady,
            'write_enabled' => (bool) $mikro['write_enabled'],
        ]);
        $writeApprovalRequired = (bool) $mikro['write_approval_required'];
        $readReady = (bool) $mikro['enabled']
            && (bool) $mikro['read_sync_enabled']
            && $liveConfigurationReady
            && $healthReady;
        $readinessStatus = match (true) {
            ! $contractReady => 'BLOCKED',
            ! $liveConfigurationReady => 'CONTRACT_READY',
            ! $healthReady => 'LIVE_CONNECTIVITY_PENDING',
            $readReady => 'READY',
            default => 'BLOCKED',
        };

        $blocking = [];
        if (! (bool) $mikro['enabled']) {
            $blocking[] = 'Mikro API kapalı.';
        }
        if (! (bool) $mikro['read_sync_enabled']) {
            $blocking[] = 'Mikro read sync kapalı.';
        }
        if (! $writeApprovalRequired) {
            $blocking[] = 'Mikro yazma onayı zorunlu olmalı.';
        }
        foreach ($this->mikroConfigurationBlockerCodes($mikro, $credential) as $blockerCode) {
            $blocking[] = match ($blockerCode) {
                'MIKRO_BASE_URL_MISSING' => 'Mikro API base URL eksik.',
                'MIKRO_APPLICATION_CODE_MISSING' => 'Panel Mikro uygulama kodu eksik.',
                'MIKRO_API_KEY_MISSING' => 'Panel Mikro API key eksik.',
                'MIKRO_USER_CODE_MISSING' => 'Mikro kullanıcı kodu eksik.',
                'MIKRO_PASSWORD_MISSING' => 'Mikro parola secret bilgisi eksik.',
                'MIKRO_FIRM_CODE_MISSING' => 'Mikro firma kodu eksik.',
                'MIKRO_WORKING_YEAR_MISSING' => 'Mikro çalışma yılı eksik.',
                'MIKRO_SERVER_TIMEZONE_INVALID' => 'Mikro sunucu saat dilimi eksik veya geçersiz.',
                'MIKRO_API_VERSION_UNSUPPORTED' => 'Yalnız Mikro V17 contractı desteklenir.',
                'MIKRO_TIMEOUT_INVALID' => 'Mikro HealthCheck timeout ayarı geçersiz.',
                default => 'Mikro private base URL güvenlik sözleşmesini geçmiyor.',
            };
        }

        return [
            'enabled' => (bool) $mikro['enabled'],
            'base_url' => $mikro['base_url'],
            'api_version' => $mikro['api_version'],
            'application_code' => $mikro['application_code'],
            'application_name' => $mikro['application_name'],
            'company_code' => $mikro['company_code'],
            'branch_code' => $mikro['branch_code'],
            'workstation_code' => $mikro['workstation_code'],
            'fiscal_year' => $mikro['fiscal_year'],
            'user_code' => $userCode,
            'timeout_seconds' => (int) $mikro['timeout_seconds'],
            'server_timezone' => $mikro['server_timezone'],
            'license_status' => $mikro['license_status'],
            'app_customer_license_status' => $mikro['app_customer_license_status'],
            'read_sync_enabled' => (bool) $mikro['read_sync_enabled'],
            'write_enabled' => (bool) $mikro['write_enabled'],
            'write_approval_required' => $writeApprovalRequired,
            'operation_controls' => (array) ($mikro['operation_controls'] ?? []),
            'operation_catalog_status' => $catalog['status'],
            'operation_catalog' => $catalog,
            'read_operation_count' => $catalog['read_count'],
            'write_operation_count' => $catalog['write_count'],
            'implemented_read_operation_count' => $catalog['implemented_read_count'],
            'enabled_read_operation_count' => $catalog['enabled_read_count'],
            'enabled_write_operation_count' => $catalog['enabled_write_count'],
            'direct_endpoint_operation_count' => $catalog['direct_endpoint_count'],
            'fixed_query_operation_count' => $catalog['fixed_query_count'],
            'contract_blocked_operation_count' => $catalog['contract_blocked_count'],
            'server_verified_read_operation_count' => $catalog['server_verified_read_count'],
            'server_unverified_operation_count' => $catalog['server_unverified_count'],
            'runtime_eligible_read_operation_count' => $catalog['runtime_eligible_read_count'],
            'contract_ready' => $contractReady,
            'health_configuration_ready' => $healthConfigurationReady,
            'private_network_ready' => $healthReady,
            'health_ready' => $healthReady,
            'live_credentials_ready' => $credentialsReady,
            'authenticated_canary_allowed' => $canaryEligibility['allowed'],
            'authenticated_canary_blocker_codes' => $canaryEligibility['blocker_codes'],
            'authenticated_canary_operations' => $canaryEligibility['operations'],
            'authenticated_read_ready' => false,
            'live_configuration_ready' => $liveConfigurationReady,
            'readiness_status' => $readinessStatus,
            'live_activation_status' => $liveConfigurationReady
                ? ($healthReady ? 'READY' : 'LIVE_CONNECTIVITY_PENDING')
                : 'LIVE_CONFIGURATION_MISSING',
            'live_activation_blocker' => $liveConfigurationReady
                ? ($healthReady ? null : 'MIKRO_LIVE_CONNECTIVITY_PENDING')
                : 'MIKRO_LIVE_CONFIGURATION_MISSING',
            'connection_test_allowed' => $healthConfigurationReady,
            'credentials_ready' => $credentialsReady,
            'api_key_present' => $apiKeyPresent,
            'password_present' => $passwordPresent,
            'token_present' => $tokenPresent,
            'user_code_mask' => filled($userCode) ? $this->maskValue((string) $userCode) : null,
            'password_mask' => $passwordPresent ? '********' : null,
            'api_key_mask' => $credential?->api_key_mask,
            'token_mask' => $credential?->token_mask,
            'read_ready' => $readReady,
            'write_ready' => false,
            'last_health_check_status' => $mikro['last_health_check_status'],
            'last_error_redacted' => $mikro['last_error_redacted'],
            'health_blocker_codes' => $healthBlockerCodes,
            'blocker_codes' => $this->mikroConfigurationBlockerCodes($mikro, $credential),
            'blocking_reasons' => array_values(array_unique($blocking)),
        ];
    }

    /**
     * @param  array<string, mixed>  $mikro
     */
    private function mikroLiveConfigurationReady(
        array $mikro,
        ?IntegrationProviderCredential $credential,
    ): bool {
        return $this->mikroConfigurationBlockerCodes($mikro, $credential) === [];
    }

    /**
     * @param  array<string, mixed>  $mikro
     * @return array<int, string>
     */
    private function mikroConfigurationBlockerCodes(
        array $mikro,
        ?IntegrationProviderCredential $credential,
    ): array {
        $blocking = $this->mikroHealthConfigurationBlockerCodes($mikro);
        $userCode = $this->mikroUserCode($mikro, $credential);

        if (blank($mikro['application_code'] ?? null)) {
            $blocking[] = 'MIKRO_APPLICATION_CODE_MISSING';
        }
        if (blank($mikro['company_code'] ?? null)) {
            $blocking[] = 'MIKRO_FIRM_CODE_MISSING';
        }
        if (blank($mikro['fiscal_year'] ?? null)) {
            $blocking[] = 'MIKRO_WORKING_YEAR_MISSING';
        }
        try {
            new DateTimeZone((string) ($mikro['server_timezone'] ?? ''));
        } catch (Throwable) {
            $blocking[] = 'MIKRO_SERVER_TIMEZONE_INVALID';
        }
        if ($credential === null || blank($credential->api_key_encrypted)) {
            $blocking[] = 'MIKRO_API_KEY_MISSING';
        }
        if (blank($userCode)) {
            $blocking[] = 'MIKRO_USER_CODE_MISSING';
        }
        if ($credential === null || blank($credential->password_encrypted)) {
            $blocking[] = 'MIKRO_PASSWORD_MISSING';
        }

        return array_values(array_unique($blocking));
    }

    /**
     * @param  array<string, mixed>  $mikro
     * @return array<int, string>
     */
    private function mikroHealthConfigurationBlockerCodes(array $mikro): array
    {
        $blocking = [];
        $baseUrl = trim((string) ($mikro['base_url'] ?? ''));

        if ($baseUrl === '') {
            $blocking[] = 'MIKRO_BASE_URL_MISSING';
        } elseif ($baseUrlBlocker = $this->mikroOperationRegistry->baseUrlBlocker($baseUrl)) {
            $blocking[] = $baseUrlBlocker;
        }
        if (strtoupper(trim((string) ($mikro['api_version'] ?? ''))) !== 'V17') {
            $blocking[] = 'MIKRO_API_VERSION_UNSUPPORTED';
        }
        $timeout = (int) ($mikro['timeout_seconds'] ?? 0);
        if ($timeout < 3 || $timeout > 120) {
            $blocking[] = 'MIKRO_TIMEOUT_INVALID';
        }

        return $blocking;
    }

    /**
     * @param  array<string, mixed>  $mikro
     */
    private function mikroUserCode(array $mikro, ?IntegrationProviderCredential $credential): ?string
    {
        $configured = trim((string) ($mikro['user_code'] ?? ''));
        if ($configured !== '') {
            return $configured;
        }

        $legacy = trim((string) ($credential?->username_encrypted ?? ''));

        return $legacy === '' ? null : $legacy;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function adminSections(array $settings, array $readiness): array
    {
        $evo = $this->evoWhatsappPayload($settings);
        $nac = $this->nacSmsPayload($settings);
        $mikro = $this->mikroApiPayload($settings);

        return [
            ['key' => 'general', 'label' => 'Genel / Panel Görünümü', 'ready' => true, 'summary' => 'UI görünürlük ve operasyon detay kontrolleri.'],
            ['key' => 'payments', 'label' => 'Ödeme Sağlayıcıları', 'ready' => true, 'summary' => 'Iyzico/fake ödeme, reconcile ve ödeme mail durumu.'],
            ['key' => 'mail', 'label' => 'Mail Ayarları', 'ready' => true, 'summary' => 'SMTP outgoing ve IMAP/POP3 incoming ayarları.'],
            ['key' => 'messaging', 'label' => 'Mesajlaşma Sağlayıcıları', 'ready' => $readiness['can_send_test'], 'summary' => 'Test modu, gerçek gönderim guard ve kanal politikaları.'],
            ['key' => 'evo', 'label' => 'WhatsApp / Evo', 'ready' => $evo['direct_api_ready'], 'summary' => 'Queue WhatsApp mesajları Direct Evolution API ile gönderilir.'],
            ['key' => 'nac_sms', 'label' => 'SMS API / NAC', 'ready' => $nac['test_ready'], 'summary' => 'NAC SMS Basic Auth, sender, gateway ve rapor altyapısı.'],
            ['key' => 'voibot', 'label' => 'Voibot Hazırlık', 'ready' => false, 'summary' => 'Voibot API sözleşmesi bekleniyor.'],
            ['key' => 'mikro_api', 'label' => 'Mikro API', 'ready' => $mikro['read_ready'], 'summary' => 'Mikro API credential, lisans ve yazma onayı altyapısı.'],
            ['key' => 'templates', 'label' => 'Şablonlar', 'ready' => true, 'summary' => 'Template/preview/variable validation aktif; gönderim yok.'],
            ['key' => 'queue', 'label' => 'Kuyruk / Gönderim Logları', 'ready' => true, 'summary' => 'Outbox, duplicate, rate-limit ve provider gönderim logları aktif.'],
            ['key' => 'health', 'label' => 'Entegrasyon Sağlığı', 'ready' => false, 'summary' => 'Canlı readiness blok nedenleri ve provider sağlık özeti.'],
        ];
    }

    private function credential(string $provider): ?IntegrationProviderCredential
    {
        return IntegrationProviderCredential::query()
            ->where('scope', IntegrationProviderCredential::SCOPE_TECHNICAL_SERVICE)
            ->where('provider', $provider)
            ->where('profile_key', IntegrationProviderCredential::PROFILE_DEFAULT)
            ->where('mode', IntegrationProviderCredential::MODE_LIVE)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $nac
     */
    private function nacBaseUrl(array $nac): string
    {
        $scheme = in_array($nac['scheme'], ['https', 'http'], true) ? $nac['scheme'] : 'https';
        $host = trim((string) $nac['host']);
        $port = (int) $nac['port'];

        return "{$scheme}://{$host}:{$port}";
    }

    /**
     * @param  array<string, mixed>  $nac
     */
    private function nacEndpointUrl(array $nac): string
    {
        $path = '/'.ltrim(trim((string) ($nac['path'] ?? '/sms/create')), '/');

        return $this->nacBaseUrl($nac).$path;
    }

    /**
     * @param  array<string, mixed>  $nac
     */
    private function nacTitle(array $nac): string
    {
        $title = trim((string) ($nac['title'] ?? ''));

        return $title === '' ? 'EMAKS TEST' : $title;
    }

    private function maskValue(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (strlen($value) <= 6) {
            return substr($value, 0, 1).'****'.substr($value, -1);
        }

        return substr($value, 0, 3).'****'.substr($value, -3);
    }

    /**
     * @return array<string, mixed>
     */
    private function layout(): array
    {
        $layout = PageConfig::query()
            ->where('page_code', self::PAGE_CODE)
            ->value('layout_json');

        return is_array($layout) ? $layout : [];
    }

    private function lockedPageConfig(): PageConfig
    {
        $page = PageConfig::query()->firstOrCreate(
            ['page_code' => self::PAGE_CODE],
            ['layout_json' => []],
        );

        return PageConfig::query()->whereKey($page->getKey())->lockForUpdate()->firstOrFail();
    }

    private function lockedLifecyclePageConfig(): PageConfig
    {
        $seedSettings = $this->settingsFromLayout($this->layout());
        $seedLayout = [];
        Arr::set($seedLayout, self::LIFECYCLE_ROOT_KEY, $this->lifecycleStateFromSettings($seedSettings));
        $page = PageConfig::query()->firstOrCreate(
            ['page_code' => self::LIFECYCLE_PAGE_CODE],
            ['layout_json' => $seedLayout],
        );

        return PageConfig::query()->whereKey($page->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * @return array{lifecycle_page:PageConfig,main_page:PageConfig,settings:array<string,mixed>}
     */
    private function lockedAuthoritativeSettings(): array
    {
        $lifecyclePage = $this->lockedLifecyclePageConfig();
        $mainPage = $this->lockedPageConfig();
        $settings = $this->applyAuthoritativeLifecycleState(
            $this->settingsFromLayout((array) $mainPage->layout_json),
            (array) $lifecyclePage->layout_json,
        );

        return [
            'lifecycle_page' => $lifecyclePage,
            'main_page' => $mainPage,
            'settings' => $settings,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $lifecycleLayout
     * @return array<string, mixed>
     */
    private function applyAuthoritativeLifecycleState(array $settings, array $lifecycleLayout): array
    {
        $stored = Arr::get($lifecycleLayout, self::LIFECYCLE_ROOT_KEY);
        if (! is_array($stored)) {
            return $settings;
        }

        $merged = array_replace($settings, Arr::only($stored, self::AUTHORITATIVE_LIFECYCLE_FIELDS));
        $layout = [];
        Arr::set($layout, self::ROOT_KEY, $merged);

        return $this->settingsFromLayout($layout);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function lifecycleStateFromSettings(array $settings): array
    {
        return Arr::only($settings, self::AUTHORITATIVE_LIFECYCLE_FIELDS);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function persistSettingsToPage(PageConfig $page, array $settings): void
    {
        $layout = is_array($page->layout_json) ? $page->layout_json : [];
        Arr::set($layout, self::ROOT_KEY, $settings);
        $page->forceFill(['layout_json' => $layout])->save();
    }

    /**
     * @param  array{lifecycle_page:PageConfig,main_page:PageConfig,settings:array<string,mixed>}  $locked
     * @param  array<string, mixed>  $settings
     */
    private function persistAuthoritativeSettings(array $locked, array $settings): void
    {
        $this->persistSettingsToPage($locked['main_page'], $settings);

        $layout = is_array($locked['lifecycle_page']->layout_json)
            ? $locked['lifecycle_page']->layout_json
            : [];
        Arr::set($layout, self::LIFECYCLE_ROOT_KEY, $this->lifecycleStateFromSettings($settings));
        $locked['lifecycle_page']->forceFill(['layout_json' => $layout])->save();
    }

    /** @return Closure(): void */
    private function acquireLifecycleLock(int $seconds = 15): Closure
    {
        if (self::$lifecycleLockHeldInProcess) {
            throw new ConflictHttpException('Manual E2E yaşam döngüsü başka bir işlem tarafından güncelleniyor.');
        }

        $connection = DB::connection();
        if ($connection->getDriverName() === 'pgsql') {
            $result = (array) $connection->selectOne(
                'select pg_try_advisory_lock(?, ?) as acquired',
                [self::MANUAL_E2E_ADVISORY_LOCK_CLASS_ID, self::MANUAL_E2E_ADVISORY_LOCK_OBJECT_ID],
            );
            if (! $this->databaseBoolean($result['acquired'] ?? false)) {
                throw new ConflictHttpException('Manual E2E yaşam döngüsü başka bir işlem tarafından güncelleniyor.');
            }

            self::$lifecycleLockHeldInProcess = true;
            $released = false;

            return function () use ($connection, &$released): void {
                if ($released) {
                    return;
                }

                try {
                    $result = (array) $connection->selectOne(
                        'select pg_advisory_unlock(?, ?) as released',
                        [self::MANUAL_E2E_ADVISORY_LOCK_CLASS_ID, self::MANUAL_E2E_ADVISORY_LOCK_OBJECT_ID],
                    );
                    if (! $this->databaseBoolean($result['released'] ?? false)) {
                        throw new RuntimeException('Manual E2E PostgreSQL advisory lock sahipliği kayboldu.');
                    }
                } finally {
                    $released = true;
                    self::$lifecycleLockHeldInProcess = false;
                }
            };
        }

        if ($connection->getDriverName() !== 'sqlite') {
            throw new ConflictHttpException('Manual E2E lifecycle lock bu veritabanı sürücüsünde fail-closed kapalıdır.');
        }

        $lock = Cache::lock(TechnicalServiceManualE2ERunContext::LIFECYCLE_LOCK_KEY, $seconds);
        if (! $lock->get()) {
            throw new ConflictHttpException('Manual E2E yaşam döngüsü başka bir işlem tarafından güncelleniyor.');
        }

        self::$lifecycleLockHeldInProcess = true;
        $released = false;

        return static function () use ($lock, &$released): void {
            if ($released) {
                return;
            }

            try {
                $lock->release();
            } finally {
                $released = true;
                self::$lifecycleLockHeldInProcess = false;
            }
        };
    }

    public function assertManualE2EFrozenOutboundLockHeld(int $dispatchId): void
    {
        if (! self::$lifecycleLockHeldInProcess) {
            throw ValidationException::withMessages([
                'manual_e2e' => 'Normal outbound için authoritative lifecycle lock zorunlu.',
            ]);
        }

        $connection = DB::connection();
        if ($connection->getDriverName() === 'pgsql') {
            $result = (array) $connection->selectOne(
                <<<'SQL'
select exists (
    select 1
    from pg_locks
    where locktype = 'advisory'
      and pid = pg_backend_pid()
      and classid = ?
      and objid = ?
      and objsubid = 2
      and mode = 'ExclusiveLock'
      and granted = true
) as held
SQL,
                [self::MANUAL_E2E_ADVISORY_LOCK_CLASS_ID, self::MANUAL_E2E_ADVISORY_LOCK_OBJECT_ID],
            );
            if (! $this->databaseBoolean($result['held'] ?? false)) {
                throw ValidationException::withMessages([
                    'manual_e2e' => 'Normal outbound PostgreSQL lifecycle lock sahipliği doğrulanamadı.',
                ]);
            }
        } elseif ($connection->getDriverName() !== 'sqlite') {
            throw ValidationException::withMessages([
                'manual_e2e' => 'Normal outbound lifecycle lock bu veritabanı sürücüsünde doğrulanamadı.',
            ]);
        }

        $current = $this->settings();
        $this->validateManualE2ELifecycleState($current);
        if (is_array($current['normal_outbound_active_claim'] ?? null)
            && $this->durableAmbiguousNormalOutboundClaim($current) === null) {
            throw ValidationException::withMessages([
                'manual_e2e' => 'Authoritative external provider attempt sonucu çözülmeden yeni outbound başlatılamaz.',
            ]);
        }
        $context = TechnicalServiceManualE2ERunContext::fromSettings($current);
        if ($context->enabled()
            || $context->activeRunId() !== null
            || $context->phase() !== self::MANUAL_E2E_PHASE_FROZEN) {
            throw ValidationException::withMessages([
                'manual_e2e' => 'Aktif Manual E2E sırasında normal outbound provider çağrısı yasaktır.',
            ]);
        }

        $otherUnresolvedAttempt = TechnicalServiceMessageDispatch::query()
            ->where('id', '<>', $dispatchId)
            ->where('attempt_count', '>', 0)
            ->whereIn('provider_key', ['evo_whatsapp', 'nac_sms'])
            ->where(function ($query): void {
                $query->whereIn('status', [
                    TechnicalServiceMessageDispatch::STATUS_QUEUED,
                    TechnicalServiceMessageDispatch::STATUS_SENDING,
                    TechnicalServiceMessageDispatch::STATUS_RATE_LIMITED,
                ])->orWhere(function ($query): void {
                    $query->where('status', TechnicalServiceMessageDispatch::STATUS_CANCELLED)
                        ->whereNull('provider_message_id')
                        ->whereNull('sent_at')
                        ->whereNull('failed_at');
                });
            })
            ->get()
            ->contains(fn (TechnicalServiceMessageDispatch $candidate): bool => ! $this->isDurableAmbiguousNormalOutboundDispatch($candidate));
        if ($otherUnresolvedAttempt) {
            throw ValidationException::withMessages([
                'manual_e2e' => 'Başka bir external provider attempt sonucu belirsizken normal outbound başlatılamaz.',
            ]);
        }
    }

    /** @param array<string, mixed> $settings */
    private function durableAmbiguousNormalOutboundClaim(array $settings): ?TechnicalServiceMessageDispatch
    {
        $claim = is_array($settings['normal_outbound_active_claim'] ?? null)
            ? $settings['normal_outbound_active_claim']
            : null;
        if ($claim === null || (string) ($claim['status'] ?? '') !== 'ambiguous_no_retry') {
            return null;
        }

        $dispatch = TechnicalServiceMessageDispatch::query()->find((int) ($claim['dispatch_id'] ?? 0));
        if (! $dispatch instanceof TechnicalServiceMessageDispatch
            || ! $this->isDurableAmbiguousNormalOutboundDispatch($dispatch)) {
            return null;
        }

        $claimHash = (string) ($claim['claim_hash'] ?? '');

        return hash_equals((string) data_get($dispatch->metadata, 'normal_outbound_finalized_claim_hash', ''), $claimHash)
            && (string) $dispatch->provider_key === (string) ($claim['provider'] ?? '')
            && (string) $dispatch->channel === (string) ($claim['channel'] ?? '')
                ? $dispatch
                : null;
    }

    private function isDurableAmbiguousNormalOutboundDispatch(TechnicalServiceMessageDispatch $dispatch): bool
    {
        $metadata = (array) $dispatch->metadata;
        $claimHash = trim((string) ($metadata['normal_outbound_finalized_claim_hash'] ?? ''));

        return preg_match('/^[a-f0-9]{64}$/', $claimHash) === 1
            && hash_equals((string) ($metadata['normal_processor_claim_hash'] ?? ''), $claimHash)
            && hash_equals((string) ($metadata['normal_outbound_authoritative_claim_hash'] ?? ''), $claimHash)
            && filled($metadata['normal_outbound_finalized_at'] ?? null)
            && $dispatch->status === TechnicalServiceMessageDispatch::STATUS_SENDING
            && (int) $dispatch->attempt_count === 1
            && $dispatch->provider_message_id === null
            && $dispatch->sent_at === null
            && $dispatch->failed_at === null
            && filter_var($metadata['provider_send_attempted'] ?? false, FILTER_VALIDATE_BOOL)
            && filter_var($metadata['external_provider_call'] ?? false, FILTER_VALIDATE_BOOL)
            && filter_var($metadata['normal_outbound_replay_blocked'] ?? false, FILTER_VALIDATE_BOOL)
            && (string) ($metadata['normal_outbound_outcome'] ?? '') === 'ambiguous_no_retry';
    }

    private function databaseBoolean(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || in_array(mb_strtolower(trim((string) $value)), ['1', 't', 'true', 'yes', 'on'], true);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withActiveRunSafetyLock(callable $callback): mixed
    {
        $lock = $this->acquireLifecycleLock();

        try {
            $this->assertNoActiveRunMutation();

            return $callback();
        } finally {
            $lock();
        }
    }

    private function lockAvailable(string $key): bool
    {
        $lock = Cache::lock($key, 5);
        if (! $lock->get()) {
            return false;
        }

        $lock->release();

        return true;
    }

    private function lifecycleLockAvailable(): bool
    {
        try {
            $release = $this->acquireLifecycleLock();
            $release();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function manualE2EWorkerLease(): ?array
    {
        $lease = Cache::get(TechnicalServiceManualE2ERunContext::WORKER_LEASE_KEY);

        return is_array($lease) ? $lease : null;
    }

    /**
     * @param  array<string, mixed>|null  $lease
     * @return array<string, mixed>
     */
    private function workerLeasePublicPayload(?array $lease, CarbonImmutable $now): array
    {
        if ($lease === null) {
            return [
                'state' => 'none',
                'run_id' => null,
                'started_at' => null,
                'heartbeat_at' => null,
                'expires_at' => null,
                'stale_recoverable' => false,
            ];
        }

        $heartbeat = $this->parseWorkerLeaseDate($lease['heartbeat_at'] ?? null);
        $expiresAt = $this->parseWorkerLeaseDate($lease['expires_at'] ?? null);
        $invalidated = filled($lease['invalidated_at'] ?? null);
        $stale = $invalidated
            || $heartbeat === null
            || $heartbeat->addSeconds(TechnicalServiceManualE2ERunContext::WORKER_HEARTBEAT_STALE_AFTER_SECONDS)->lte($now)
            || ($expiresAt !== null && $expiresAt->lte($now));

        return [
            'state' => $invalidated ? 'invalidated' : ($stale ? 'stale' : 'active'),
            'run_id' => TechnicalServiceManualE2ERunContext::normalizeRunId($lease['run_id'] ?? null),
            'started_at' => $this->parseWorkerLeaseDate($lease['started_at'] ?? null)?->toIso8601String(),
            'heartbeat_at' => $heartbeat?->toIso8601String(),
            'expires_at' => $expiresAt?->toIso8601String(),
            'stale_recoverable' => $stale && filled($lease['lock_owner'] ?? null),
        ];
    }

    private function invalidateManualE2EWorkerLease(string $runId): bool
    {
        $lease = $this->manualE2EWorkerLease();
        if ($lease === null || ($lease['run_id'] ?? null) !== $runId) {
            return false;
        }

        $lease['invalidated_at'] = CarbonImmutable::now()->toIso8601String();
        Cache::put(TechnicalServiceManualE2ERunContext::WORKER_LEASE_KEY, $lease, 60);

        return $this->releaseOwnedWorkerLockAndLease($lease);
    }

    private function reconcileStaleManualE2EWorkerLease(): bool
    {
        $lease = $this->manualE2EWorkerLease();
        if ($lease === null) {
            return false;
        }

        $state = $this->workerLeasePublicPayload($lease, CarbonImmutable::now());
        if (! (bool) ($state['stale_recoverable'] ?? false)) {
            return false;
        }

        return $this->releaseOwnedWorkerLockAndLease($lease);
    }

    /**
     * @param  array<string, mixed>  $lease
     */
    private function releaseOwnedWorkerLockAndLease(array $lease): bool
    {
        $owner = trim((string) ($lease['lock_owner'] ?? ''));
        if ($owner === '') {
            return false;
        }

        try {
            $released = Cache::restoreLock(
                TechnicalServiceManualE2ERunContext::WORKER_LOCK_KEY,
                $owner,
            )->release();
        } catch (Throwable) {
            return false;
        }

        if (! $released) {
            return false;
        }

        $current = $this->manualE2EWorkerLease();
        if ($current !== null
            && ($current['run_id'] ?? null) === ($lease['run_id'] ?? null)
            && ($current['lock_owner'] ?? null) === $owner) {
            Cache::forget(TechnicalServiceManualE2ERunContext::WORKER_LEASE_KEY);
        }

        return true;
    }

    private function parseWorkerLeaseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function executionModePayloadForSettings(array $settings, bool $checkLifecycleLock): array
    {
        $control = app(ExternalExecutionControlPlaneService::class)->payload();
        $legacyReadiness = $this->executionModeReadinessForSettings($settings, $checkLifecycleLock);

        return [
            ...$control,
            'classification' => $control['mode'] === self::OUTBOUND_EXECUTION_MODE_LOCAL
                ? 'Global Lokal / no-external-effect modu'
                : ($this->runtimeEnvironment() === 'production'
                    ? 'Global production operasyon modu'
                    : 'Global Canlı / bounded Manual E2E modu'),
            'real_send_enabled' => (bool) ($settings['real_send_enabled'] ?? false),
            'queue_paused' => (bool) ($settings['queue_paused'] ?? true),
            'manual_e2e_enabled' => (bool) ($settings['manual_e2e_enabled'] ?? false),
            'manual_e2e_phase' => (string) ($settings['manual_e2e_phase'] ?? self::MANUAL_E2E_PHASE_FROZEN),
            'release_sha' => $this->runtimeReleaseSha(),
            'readiness' => [
                ...$legacyReadiness,
                ...((array) $control['readiness']),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function executionModeReadinessForSettings(array $settings, bool $checkLifecycleLock): array
    {
        $environment = $this->runtimeEnvironment();
        $production = $environment === 'production';
        $mode = $this->executionMode($settings);
        $context = TechnicalServiceManualE2ERunContext::fromSettings($settings);
        $portalOrigins = $this->portalOriginReadiness($settings);
        $evoReady = $this->evoProviderReadyForLive($settings, $production);
        $nacReady = $this->nacProviderReadyForLive($settings, $production);
        $worker = $this->outboundWorkerLeaseStatus();
        $pending = $this->pendingExternalDispatchCount();
        $unsafe = $this->unsafeExternalDispatchCount();
        $releaseSha = $this->runtimeReleaseSha();
        $blockers = [];

        $addBlocker = static function (array &$target, bool $blocked, string $code, string $message): void {
            if ($blocked) {
                $target[] = ['code' => $code, 'message' => $message];
            }
        };

        try {
            $this->validateManualE2ELifecycleState($settings);
            $lifecycleValid = true;
        } catch (Throwable) {
            $lifecycleValid = false;
        }

        $manualFrozen = $lifecycleValid
            && ! $context->enabled()
            && $context->activeRunId() === null
            && $context->phase() === self::MANUAL_E2E_PHASE_FROZEN
            && ! is_array($settings['manual_e2e_open_window'] ?? null)
            && ! is_array($settings['manual_e2e_active_claim'] ?? null);
        $normalClaimClear = ! is_array($settings['normal_outbound_active_claim'] ?? null);
        $normalQueueClosed = ! (bool) ($settings['real_send_enabled'] ?? false)
            && (bool) ($settings['queue_paused'] ?? true);
        $killSwitchConsistent = $mode === self::OUTBOUND_EXECUTION_MODE_LIVE && $production
            ? (bool) ($settings['real_send_enabled'] ?? false) && ! (bool) ($settings['queue_paused'] ?? true)
            : $normalQueueClosed;
        $workerHealthy = ($worker['state'] ?? 'none') === 'active'
            && $releaseSha !== null
            && hash_equals((string) ($worker['release_sha'] ?? ''), $releaseSha);
        $realAllowedMessageTypeCount = collect($settings['message_types'] ?? [])
            ->filter(fn (array $type): bool => (bool) ($type['enabled'] ?? false) && (bool) ($type['real_send_allowed'] ?? false))
            ->count();

        $addBlocker($blockers, ! (bool) ($settings['messaging_enabled'] ?? false), 'messaging_disabled', 'Mesaj sistemi açık olmalı.');
        $addBlocker($blockers, ! $evoReady, 'evo_not_ready', 'Evo Direct API profili ve credential readiness tamamlanmalı.');
        $addBlocker($blockers, ! $nacReady, 'nac_not_ready', 'NAC SMS profili ve credential readiness tamamlanmalı.');
        $addBlocker($blockers, $realAllowedMessageTypeCount === 0, 'message_type_not_ready', 'En az bir operasyon mesaj tipi gerçek gönderime açık olmalı.');
        $addBlocker($blockers, ! $lifecycleValid, 'lifecycle_invalid', 'Manual E2E lifecycle state geçersiz.');
        $addBlocker($blockers, ! $manualFrozen, 'manual_e2e_not_frozen', 'Manual E2E frozen olmalı; active run/window/claim bulunmamalı.');
        $addBlocker($blockers, ! $normalClaimClear, 'normal_outbound_claim_active', 'Çözülmemiş normal outbound claim varken mod değiştirilemez.');
        $addBlocker($blockers, $pending > 0, 'pending_external_dispatch', 'Pending external dispatch sayısı sıfır olmalı.');
        $addBlocker($blockers, $unsafe > 0, 'unsafe_external_dispatch', 'Unsafe external dispatch sayısı sıfır olmalı.');
        $addBlocker($blockers, ! $killSwitchConsistent, 'provider_kill_switch_inconsistent', 'Mevcut real-send ve queue kill-switch durumu seçili modla tutarlı değil.');
        if ($checkLifecycleLock) {
            $addBlocker($blockers, ! $this->lifecycleLockAvailable(), 'lifecycle_lock_busy', 'Messaging lifecycle transition lock şu anda alınamıyor.');
        }

        if ($production) {
            $trustedProxies = trim((string) (getenv('TRUSTED_PROXIES') ?: ''));
            $sessionDomain = trim((string) config('session.domain', ''));
            $addBlocker($blockers, $releaseSha === null, 'release_sha_missing', 'Production runtime release SHA doğrulanamıyor.');
            $addBlocker($blockers, (bool) config('app.debug', false), 'app_debug_enabled', 'Production live modunda APP_DEBUG kapalı olmalı.');
            $addBlocker($blockers, ! (bool) ($portalOrigins['live_public']['ready'] ?? false), 'public_https_not_ready', 'Canonical public HTTPS partner portal URL hazır olmalı.');
            $addBlocker($blockers, $trustedProxies === '', 'trusted_proxy_not_ready', 'Trusted proxy yapılandırması doğrulanmalı.');
            $addBlocker($blockers, ! (bool) config('session.secure', false), 'secure_cookie_not_ready', 'Production session cookie secure olmalı.');
            $addBlocker($blockers, $sessionDomain === '', 'session_domain_not_ready', 'Production session domain tanımlı olmalı.');
            $addBlocker($blockers, ! $workerHealthy, 'outbound_worker_not_healthy', 'Exact release ile çalışan normal outbound worker heartbeat bulunmalı.');
        } else {
            $allowlist = array_values(array_filter((array) ($settings['manual_e2e_allowlisted_phones'] ?? [])));
            $manualE2EOriginReady = (bool) ($portalOrigins['manual_e2e']['ready'] ?? false)
                || (bool) ($portalOrigins['live_public']['ready'] ?? false);
            $addBlocker($blockers, $allowlist === [], 'manual_e2e_allowlist_missing', 'Non-production live modu için Manual E2E allowlist zorunlu.');
            $addBlocker($blockers, ! $manualE2EOriginReady, 'manual_e2e_origin_not_ready', 'Telefon erişimine uygun Manual E2E LAN origin veya public HTTPS portal hazır olmalı.');
            $addBlocker($blockers, ! $normalQueueClosed, 'normal_queue_not_closed', 'Non-production live modunda normal provider kuyruğu kapalı kalmalı.');
            $addBlocker($blockers, ($worker['state'] ?? 'none') === 'stale', 'outbound_worker_stale', 'Stale normal outbound worker lease temizlenmeli.');
        }

        return [
            'eligible' => $blockers === [],
            'target_mode' => self::OUTBOUND_EXECUTION_MODE_LIVE,
            'runtime_environment' => $environment,
            'classification' => $production
                ? 'Production operasyon modu'
                : 'Canlı API Testi — yalnız Manual E2E',
            'blockers' => $blockers,
            'evo_ready' => $evoReady,
            'nac_ready' => $nacReady,
            'queue_worker_ready' => $production ? $workerHealthy : true,
            'queue_worker_state' => $worker['state'],
            'queue_worker_heartbeat_at' => $worker['heartbeat_at'],
            'scheduler_topology_accepted' => $production ? $workerHealthy : false,
            'public_https_ready' => (bool) ($portalOrigins['live_public']['ready'] ?? false),
            'manual_e2e_origin_ready' => (bool) ($portalOrigins['manual_e2e']['ready'] ?? false),
            'pending_external_count' => $pending,
            'unsafe_external_count' => $unsafe,
            'manual_e2e_frozen' => $manualFrozen,
            'normal_outbound_claim_clear' => $normalClaimClear,
            'normal_queue_closed' => $normalQueueClosed,
            'provider_kill_switch_consistent' => $killSwitchConsistent,
            'allowlist_count' => count((array) ($settings['manual_e2e_allowlisted_phones'] ?? [])),
            'release_sha' => $releaseSha,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{allowed:bool,code:string|null,message:string|null}
     */
    private function dispatchExecutionAuthorizationForSettings(
        TechnicalServiceMessageDispatch $dispatch,
        bool $manualE2E,
        array $settings,
        ?string $outboundWorkerOwner = null,
    ): array {
        $mode = $this->executionMode($settings);
        $environment = $this->runtimeEnvironment();
        $metadata = (array) $dispatch->metadata;
        $context = TechnicalServiceManualE2ERunContext::fromSettings($settings);
        $scopedManualE2E = $manualE2E
            && $mode === self::OUTBOUND_EXECUTION_MODE_LOCAL
            && $this->isScopedLocalUatSettings($settings);
        $normalLocalOutbound = ! $manualE2E
            && $mode === self::OUTBOUND_EXECUTION_MODE_LOCAL
            && $environment !== 'production';

        if ($mode !== self::OUTBOUND_EXECUTION_MODE_LIVE && ! $scopedManualE2E && ! $normalLocalOutbound) {
            return $this->executionBlock('outbound_execution_mode_local', 'Global sistem çalışma modu Lokal; dış provider çağrısı kapalı.');
        }
        if (! in_array((string) $dispatch->provider_key, ['evo_whatsapp', 'nac_sms'], true)
            || ! in_array((string) $dispatch->channel, ['whatsapp', 'sms'], true)
            || ($dispatch->provider_key === 'evo_whatsapp' && $dispatch->channel !== 'whatsapp')
            || ($dispatch->provider_key === 'nac_sms' && $dispatch->channel !== 'sms')) {
            return $this->executionBlock('outbound_provider_channel_mismatch', 'Dispatch provider/channel tuple çalışma modu için geçersiz.');
        }
        if (! $normalLocalOutbound) {
            $globalAuthorization = $this->outboundSnapshotAuthorization(
                (string) $dispatch->provider_key,
                $metadata,
            );
            if (! $globalAuthorization['allowed']) {
                return $globalAuthorization;
            }
        }
        $providerReady = $normalLocalOutbound
            ? ($dispatch->provider_key === 'evo_whatsapp'
                ? $this->evoProviderReadyForLive($settings, false)
                : $this->nacProviderReadyForLive($settings, false))
            : ($this->evoProviderReadyForLive($settings, $environment === 'production')
                && $this->nacProviderReadyForLive($settings, $environment === 'production'));
        if (! $providerReady) {
            return $this->executionBlock('outbound_provider_set_not_ready', 'Dispatch provider readiness geçmedi; outbound fail-closed tutuldu.');
        }

        if ($manualE2E) {
            $runSnapshot = is_array($settings['manual_e2e_run_snapshot'] ?? null)
                ? $settings['manual_e2e_run_snapshot']
                : [];
            if ($environment === 'production'
                || ! app(ExternalExecutionControlPlaneService::class)->messagingRunSnapshotIsCurrent($runSnapshot)) {
                return $this->executionBlock('manual_e2e_environment_scope_invalid', 'Manual E2E run snapshotı current non-production çalışma modu ile eşleşmiyor.');
            }
            if (! $context->isActive() || ! $context->matchesDispatch($dispatch)) {
                return $this->executionBlock('manual_e2e_execution_scope_invalid', 'Dispatch active Manual E2E run scope ile eşleşmiyor.');
            }

            return ['allowed' => true, 'code' => null, 'message' => null];
        }

        if ($normalLocalOutbound) {
            $localAuthorization = $this->normalLocalDispatchAuthorization(
                (string) $dispatch->provider_key,
                (string) $dispatch->channel,
                (string) $dispatch->message_type,
                (string) $dispatch->target_phone,
                $dispatch->bodyForProvider(),
                $metadata,
                $settings,
            );
            if (! $localAuthorization['allowed']) {
                return $localAuthorization;
            }
        } elseif ($environment !== 'production') {
            return $this->executionBlock('non_production_normal_outbound_blocked', 'Non-production ortamda normal operasyon outbound kapalı; yalnız exact Manual E2E permit kullanılabilir.');
        } elseif ($context->enabled()
            || $context->activeRunId() !== null
            || $context->phase() !== self::MANUAL_E2E_PHASE_FROZEN
            || ! (bool) ($settings['messaging_enabled'] ?? false)
            || ! (bool) ($settings['real_send_enabled'] ?? false)
            || (bool) ($settings['queue_paused'] ?? true)) {
            return $this->executionBlock('production_live_gate_closed', 'Production live provider gate veya queue state hazır değil.');
        }
        if (! $normalLocalOutbound) {
            $typePolicy = (array) data_get($settings, 'message_types.'.$dispatch->message_type, []);
            if (! (bool) ($typePolicy['enabled'] ?? false)
                || ! $this->messageTypeAllowsChannel(
                    $typePolicy,
                    (string) $dispatch->channel,
                    (string) $dispatch->provider_key,
                )) {
                return $this->executionBlock('message_type_channel_disabled', 'Dispatch mesaj tipi, kanal veya provider ayarı aktif değil.');
            }
        }
        $worker = $this->outboundWorkerLeaseStatus();
        $rawWorker = $this->outboundWorkerLease();
        $releaseSha = $this->runtimeReleaseSha();
        if (($worker['state'] ?? null) !== 'active') {
            return $this->executionBlock('outbound_worker_not_healthy', 'Normal outbound worker claim ilerlemesi sağlıklı değil.');
        }
        if ($releaseSha === null
            || ! hash_equals((string) ($worker['release_sha'] ?? ''), $releaseSha)) {
            return $this->executionBlock('outbound_worker_release_mismatch', 'Normal outbound worker heartbeat current release ile eşleşmiyor.');
        }
        if ($normalLocalOutbound
            && ($rawWorker['profile'] ?? null) !== self::LOCAL_MANUAL_ACCEPTANCE_PROFILE) {
            return $this->executionBlock('outbound_worker_profile_mismatch', 'Normal outbound worker yerel sender owner ile eşleşmiyor.');
        }
        if ($normalLocalOutbound
            && ! hash_equals(
                $this->localManualAcceptanceFingerprint($settings),
                (string) ($rawWorker['profile_fingerprint'] ?? ''),
            )) {
            return $this->executionBlock('outbound_worker_profile_mismatch', 'Normal outbound worker current messaging profili ile eşleşmiyor.');
        }
        $currentOwner = trim((string) ($rawWorker['lock_owner'] ?? ''));
        $expectedOwnerHash = $currentOwner === '' ? '' : hash('sha256', $currentOwner);
        $claimOwnerHash = trim((string) data_get($dispatch->metadata, 'normal_outbound_worker_lease_hash', ''));
        if ($outboundWorkerOwner !== null) {
            if ($currentOwner === '' || ! hash_equals($currentOwner, trim($outboundWorkerOwner))) {
                return $this->executionBlock('outbound_worker_lease_mismatch', 'Normal outbound çağrısı active worker lease sahibiyle eşleşmiyor.');
            }
        } elseif ($expectedOwnerHash === '' || ! hash_equals($expectedOwnerHash, $claimOwnerHash)) {
            return $this->executionBlock('outbound_worker_lease_mismatch', 'Normal outbound claim active worker lease ile bağlı değil.');
        }

        return ['allowed' => true, 'code' => null, 'message' => null];
    }

    /**
     * @return array{allowed:false,code:string,message:string}
     */
    private function executionBlock(string $code, string $message): array
    {
        return ['allowed' => false, 'code' => $code, 'message' => $message];
    }

    /**
     * Emergency local transition validates only the closed outbound invariant.
     * Existing provider/profile defects must never prevent the safe direction.
     *
     * @param  array<string, mixed>  $settings
     */
    private function assertLocalExecutionModeState(array $settings): void
    {
        if ($this->executionMode($settings) !== self::OUTBOUND_EXECUTION_MODE_LOCAL
            || (bool) ($settings['manual_e2e_enabled'] ?? false)
            || (bool) ($settings['local_manual_acceptance_enabled'] ?? false)
            || (bool) ($settings['real_send_enabled'] ?? false)
            || ! (bool) ($settings['queue_paused'] ?? true)
            || (bool) ($settings['ops_whatsapp_enabled'] ?? false)
            || (string) ($settings['manual_e2e_phase'] ?? '') !== self::MANUAL_E2E_PHASE_FROZEN
            || TechnicalServiceManualE2ERunContext::normalizeRunId($settings['manual_e2e_active_run_id'] ?? null) !== null
            || is_array($settings['manual_e2e_open_window'] ?? null)
            || is_array($settings['manual_e2e_active_claim'] ?? null)) {
            throw new ConflictHttpException('Lokal çalışma modu outbound kapıları fail-closed duruma getirilemedi.');
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function executionMode(array $settings): string
    {
        return app(ExternalExecutionControlPlaneService::class)->state()['operator_mode'];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function executionModeRevision(array $settings): int
    {
        return (int) app(ExternalExecutionControlPlaneService::class)->state()['revision'];
    }

    private function runtimeEnvironment(): string
    {
        if (app()->environment('production')) {
            return 'production';
        }
        if (app()->environment('staging')) {
            return 'staging';
        }

        return 'local';
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function messagingProfileFingerprint(string $provider, array $settings): string
    {
        $profile = $provider === 'evo_whatsapp'
            ? [
                'provider' => $provider,
                'enabled' => (bool) data_get($settings, 'evo_whatsapp.direct_api_enabled', false),
                'base_url' => (string) data_get($settings, 'evo_whatsapp.direct_api_base_url', ''),
                'instance' => (string) data_get($settings, 'evo_whatsapp.direct_api_instance_name', ''),
            ]
            : [
                'provider' => $provider,
                'enabled' => (bool) data_get($settings, 'nac_sms.enabled', false),
                'profile' => (string) data_get($settings, 'nac_sms.profile', ''),
                'scheme' => (string) data_get($settings, 'nac_sms.scheme', ''),
                'host' => (string) data_get($settings, 'nac_sms.host', ''),
                'port' => (int) data_get($settings, 'nac_sms.port', 0),
                'path' => (string) data_get($settings, 'nac_sms.path', ''),
                'sender' => (string) data_get($settings, 'nac_sms.sender', ''),
            ];

        return hash('sha256', json_encode([
            'environment' => $this->runtimeEnvironment(),
            'profile' => $profile,
        ], JSON_THROW_ON_ERROR));
    }

    private function runtimeReleaseSha(): ?string
    {
        $candidate = trim((string) (config('app.release_sha')
            ?: getenv('APP_RELEASE_SHA')
            ?: getenv('SOURCE_COMMIT')
            ?: ''));

        return preg_match('/^[a-f0-9]{40}$/i', $candidate) === 1
            ? strtolower($candidate)
            : null;
    }

    private function nullableScalar(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== ''
            ? trim((string) $value)
            : null;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function evoProviderReadyForLive(array $settings, bool $production): bool
    {
        $readiness = $this->evoWhatsappReadiness($settings);
        $url = trim((string) data_get($settings, 'evo_whatsapp.direct_api_base_url', ''));
        $urlReady = filter_var($url, FILTER_VALIDATE_URL) !== false
            && (! $production || PartnerPortalPublicUrl::isPublicHttpsUrl($url));

        return $readiness['ready']
            && $urlReady
            && $this->providerEnabled('evo_whatsapp', $settings)
            && $this->providerRealSendAllowed('evo_whatsapp', $settings);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function nacProviderReadyForLive(array $settings, bool $production): bool
    {
        $nac = (array) ($settings['nac_sms'] ?? []);
        $scheme = (string) ($nac['scheme'] ?? '');
        $profile = (string) ($nac['profile'] ?? '');
        $host = trim((string) ($nac['host'] ?? ''));
        $path = trim((string) ($nac['path'] ?? ''));
        $profileValid = in_array($profile, ['docs_https_9588', 'legacy_working_http_9587', 'custom'], true)
            && in_array($scheme, ['http', 'https'], true)
            && $host !== ''
            && str_starts_with($path, '/');
        if ($production && $profile === 'custom' && $scheme !== 'https') {
            $profileValid = false;
        }

        return $profileValid
            && (bool) ($nac['enabled'] ?? false)
            && (bool) ($nac['real_send_allowed'] ?? false)
            && trim((string) ($nac['sender'] ?? '')) !== ''
            && ($this->credential('nac_sms')?->basicAuthReady() ?? false);
    }

    private function pendingExternalDispatchCount(): int
    {
        return TechnicalServiceMessageDispatch::query()
            ->whereIn('provider_key', ['evo_whatsapp', 'nac_sms'])
            ->whereIn('status', [
                TechnicalServiceMessageDispatch::STATUS_QUEUED,
                TechnicalServiceMessageDispatch::STATUS_RATE_LIMITED,
                TechnicalServiceMessageDispatch::STATUS_SENDING,
            ])
            ->count();
    }

    private function unsafeExternalDispatchCount(): int
    {
        return TechnicalServiceMessageDispatch::query()
            ->whereIn('provider_key', ['evo_whatsapp', 'nac_sms'])
            ->where(function ($query): void {
                $query
                    ->where('status', TechnicalServiceMessageDispatch::STATUS_SENDING)
                    ->orWhere(function ($query): void {
                        $query->where('attempt_count', '>', 0)
                            ->whereNull('provider_message_id')
                            ->whereNull('sent_at')
                            ->whereNull('failed_at');
                    });
            })
            ->count();
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function freezeManualE2EForExecutionMode(
        array $settings,
        TechnicalServiceManualE2ERunContext $context,
    ): array {
        $history = (array) ($settings['manual_e2e_window_history'] ?? []);
        foreach (['manual_e2e_open_window', 'manual_e2e_active_claim'] as $field) {
            $scope = $settings[$field] ?? null;
            if (is_array($scope)) {
                $history = $this->appendWindowHistory($history, [
                    ...$scope,
                    'status' => 'execution_mode_local_frozen',
                    'closed_at' => CarbonImmutable::now()->toIso8601String(),
                ]);
            }
        }

        $effectHistory = (array) ($settings['scoped_local_uat_effect_history'] ?? []);
        $dispatchingEffect = null;
        if (is_array($settings['scoped_local_uat_active_effect_claim'] ?? null)
            && (string) ($settings['scoped_local_uat_active_effect_claim']['status'] ?? '') === 'dispatching') {
            $dispatchingEffect = [
                ...$settings['scoped_local_uat_active_effect_claim'],
                'run_frozen_at' => CarbonImmutable::now()->toIso8601String(),
            ];
        } elseif (is_array($settings['scoped_local_uat_active_effect_claim'] ?? null)) {
            $effectHistory = $this->appendWindowHistory($effectHistory, [
                ...$settings['scoped_local_uat_active_effect_claim'],
                'status' => 'execution_mode_local_frozen',
                'outcome' => 'failed_no_retry',
                'closed_at' => CarbonImmutable::now()->toIso8601String(),
                'replay_blocked' => true,
            ]);
        }

        $settings = $this->deactivateManualE2EContext($settings, $context);
        $settings['test_mode_enabled'] = false;
        $settings['manual_e2e_window_history'] = $history;
        $settings['scoped_local_uat_effect_history'] = $effectHistory;
        if ($dispatchingEffect !== null) {
            $settings['scoped_local_uat_active_effect_claim'] = $dispatchingEffect;
        }

        return $settings;
    }

    /**
     * @return array<string, mixed>
     */
    public function registerOutboundWorkerLease(
        string $lockOwner,
        CarbonImmutable $startedAt,
        CarbonImmutable $expiresAt,
    ): array {
        $releaseSha = $this->runtimeReleaseSha();
        $settings = $this->settings();
        $context = TechnicalServiceManualE2ERunContext::fromSettings($settings);
        $production = $this->runtimeEnvironment() === 'production';
        $localSender = ! $production
            && $this->executionMode($settings) === self::OUTBOUND_EXECUTION_MODE_LOCAL;
        if (trim($lockOwner) === '' || $releaseSha === null) {
            throw new ConflictHttpException('Normal outbound worker lock owner veya exact release SHA olmadan kaydedilemez.');
        }
        if (! $production && ! $localSender) {
            throw new ConflictHttpException('Normal outbound worker current production veya local sender authority olmadan kaydedilemez.');
        }
        if ($context->phase() !== self::MANUAL_E2E_PHASE_FROZEN
            || $context->enabled()
            || $context->activeRunId() !== null) {
            throw new ConflictHttpException('Normal outbound worker frozen normal lifecycle dışında kaydedilemez.');
        }
        $now = CarbonImmutable::now();
        $currentLease = $this->outboundWorkerLease();
        if ($currentLease !== null
            && ! hash_equals((string) ($currentLease['lock_owner'] ?? ''), trim($lockOwner))
            && ($this->outboundWorkerLeasePublicPayload($currentLease, $now)['state'] ?? 'none') !== 'stale') {
            throw new ConflictHttpException('Başka bir normal outbound sender owner zaten aktif.');
        }
        $profile = $production
            ? 'PRODUCTION_NORMAL_OUTBOUND'
            : self::LOCAL_MANUAL_ACCEPTANCE_PROFILE;
        $lease = [
            'lock_owner' => $lockOwner,
            'process_id' => getmypid() ?: null,
            'release_sha' => $releaseSha,
            'mode' => $production ? 'normal_live_worker' : 'local_message_sender',
            'profile' => $profile,
            'profile_fingerprint' => $localSender
                ? $this->localManualAcceptanceFingerprint($settings)
                : null,
            'activated_at' => $localSender
                ? (string) ($settings['local_manual_acceptance_activated_at'] ?? '')
                : null,
            'started_at' => $startedAt->toIso8601String(),
            'heartbeat_at' => $now->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
            'cycle_count' => 0,
            'processed_count' => 0,
            'last_cycle_candidate_count' => 0,
            'last_cycle_progress_count' => 0,
            'last_cycle_at' => null,
            'last_progress_at' => null,
        ];
        Cache::put(self::OUTBOUND_WORKER_LEASE_KEY, $lease, max(60, $now->diffInSeconds($expiresAt) + 60));

        return $this->outboundWorkerLeasePublicPayload($lease, $now);
    }

    public function heartbeatOutboundWorkerLease(string $lockOwner): bool
    {
        $lease = $this->outboundWorkerLease();
        if ($lease === null
            || ($lease['lock_owner'] ?? null) !== $lockOwner
            || ($lease['release_sha'] ?? null) !== $this->runtimeReleaseSha()
            || ($this->outboundWorkerLeasePublicPayload($lease, CarbonImmutable::now())['state'] ?? 'none') === 'stale'
            || ! $this->outboundWorkerLeaseMatchesCurrentScope($lease, $this->settings())) {
            return false;
        }

        $lease['heartbeat_at'] = CarbonImmutable::now()->toIso8601String();
        Cache::put(self::OUTBOUND_WORKER_LEASE_KEY, $lease, 120);

        return true;
    }

    public function recordOutboundWorkerCycle(
        string $lockOwner,
        int $candidateCount,
        int $progressCount,
    ): bool {
        $lease = $this->outboundWorkerLease();
        if ($lease === null
            || ($lease['lock_owner'] ?? null) !== $lockOwner
            || ($lease['release_sha'] ?? null) !== $this->runtimeReleaseSha()
            || ! $this->outboundWorkerLeaseMatchesCurrentScope($lease, $this->settings())) {
            return false;
        }

        $now = CarbonImmutable::now();
        $candidateCount = max(0, $candidateCount);
        $progressCount = max(0, min($candidateCount, $progressCount));
        $lease['heartbeat_at'] = $now->toIso8601String();
        $lease['cycle_count'] = max(0, (int) ($lease['cycle_count'] ?? 0)) + 1;
        $lease['processed_count'] = max(0, (int) ($lease['processed_count'] ?? 0)) + $progressCount;
        $lease['last_cycle_candidate_count'] = $candidateCount;
        $lease['last_cycle_progress_count'] = $progressCount;
        $lease['last_cycle_at'] = $now->toIso8601String();
        if ($progressCount > 0) {
            $lease['last_progress_at'] = $now->toIso8601String();
        }
        Cache::put(self::OUTBOUND_WORKER_LEASE_KEY, $lease, 120);

        return true;
    }

    public function clearOutboundWorkerLease(string $lockOwner): bool
    {
        $lease = $this->outboundWorkerLease();
        if ($lease === null || ($lease['lock_owner'] ?? null) !== $lockOwner) {
            return false;
        }

        Cache::forget(self::OUTBOUND_WORKER_LEASE_KEY);

        return true;
    }

    public function normalOutboundWorkerMayProcess(string $lockOwner): bool
    {
        return (bool) ($this->outboundWorkerProcessingScope($lockOwner)['allowed'] ?? false);
    }

    /**
     * Internal sender scope. Raw allowlist values never leave the worker
     * process and are not included in public payloads.
     *
     * @return array{allowed:bool,profile:string|null,created_after:string|null,allowlisted_phones:array<int,string>}
     */
    public function outboundWorkerProcessingScope(string $lockOwner): array
    {
        $lease = $this->outboundWorkerLease();
        $settings = $this->settings();
        if ($lease === null
            || trim($lockOwner) === ''
            || ! hash_equals((string) ($lease['lock_owner'] ?? ''), trim($lockOwner))
            || ! hash_equals((string) ($lease['release_sha'] ?? ''), (string) $this->runtimeReleaseSha())
            || ($this->outboundWorkerLeasePublicPayload($lease, CarbonImmutable::now())['state'] ?? 'none') !== 'active'
            || ! $this->outboundWorkerLeaseMatchesCurrentScope($lease, $settings)) {
            return ['allowed' => false, 'profile' => null, 'created_after' => null, 'allowlisted_phones' => []];
        }

        if (($lease['profile'] ?? null) === self::LOCAL_MANUAL_ACCEPTANCE_PROFILE) {
            if (! $this->normalLocalAdminExecutionAuthority($settings)
                || ! (bool) ($settings['messaging_enabled'] ?? false)
                || ! (bool) ($settings['real_send_enabled'] ?? false)
                || (bool) ($settings['queue_paused'] ?? true)) {
                return ['allowed' => false, 'profile' => self::LOCAL_MANUAL_ACCEPTANCE_PROFILE, 'created_after' => null, 'allowlisted_phones' => []];
            }

            return [
                'allowed' => true,
                'profile' => self::LOCAL_MANUAL_ACCEPTANCE_PROFILE,
                'created_after' => null,
                'allowlisted_phones' => (bool) ($settings['test_mode_enabled'] ?? false)
                    ? array_values(array_filter(array_map(
                        fn (mixed $phone): string => $this->normalizePhone((string) $phone),
                        (array) ($settings['manual_e2e_allowlisted_phones'] ?? []),
                    )))
                    : [],
            ];
        }

        return ['allowed' => true, 'profile' => 'PRODUCTION_NORMAL_OUTBOUND', 'created_after' => null, 'allowlisted_phones' => []];
    }

    /**
     * @return array<string, mixed>
     */
    public function outboundWorkerLeaseStatus(): array
    {
        return $this->outboundWorkerLeasePublicPayload($this->outboundWorkerLease(), CarbonImmutable::now());
    }

    /**
     * @param  array<string, mixed>  $lease
     * @param  array<string, mixed>  $settings
     */
    private function outboundWorkerLeaseMatchesCurrentScope(array $lease, array $settings): bool
    {
        $context = TechnicalServiceManualE2ERunContext::fromSettings($settings);
        if ($context->phase() !== self::MANUAL_E2E_PHASE_FROZEN
            || $context->enabled()
            || $context->activeRunId() !== null) {
            return false;
        }

        if (($lease['profile'] ?? null) === self::LOCAL_MANUAL_ACCEPTANCE_PROFILE) {
            return $this->runtimeEnvironment() !== 'production'
                && $this->executionMode($settings) === self::OUTBOUND_EXECUTION_MODE_LOCAL
                && preg_match('/^[a-f0-9]{64}$/', (string) ($lease['profile_fingerprint'] ?? '')) === 1
                && hash_equals(
                    $this->localManualAcceptanceFingerprint($settings),
                    (string) $lease['profile_fingerprint'],
                );
        }

        return ($lease['profile'] ?? null) === 'PRODUCTION_NORMAL_OUTBOUND'
            && $this->runtimeEnvironment() === 'production'
            && $this->executionMode($settings) === self::OUTBOUND_EXECUTION_MODE_LIVE
            && (bool) ($settings['messaging_enabled'] ?? false)
            && (bool) ($settings['real_send_enabled'] ?? false)
            && ! (bool) ($settings['queue_paused'] ?? true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function outboundWorkerLease(): ?array
    {
        $lease = Cache::get(self::OUTBOUND_WORKER_LEASE_KEY);

        return is_array($lease) ? $lease : null;
    }

    /**
     * @param  array<string, mixed>|null  $lease
     * @return array<string, mixed>
     */
    private function outboundWorkerLeasePublicPayload(?array $lease, CarbonImmutable $now): array
    {
        if ($lease === null) {
            return [
                'state' => 'none',
                'process_id' => null,
                'release_sha' => null,
                'mode' => null,
                'profile' => null,
                'profile_fingerprint' => null,
                'activated_at' => null,
                'started_at' => null,
                'heartbeat_at' => null,
                'expires_at' => null,
                'cycle_count' => 0,
                'processed_count' => 0,
                'last_cycle_candidate_count' => 0,
                'last_cycle_progress_count' => 0,
                'last_cycle_at' => null,
                'last_progress_at' => null,
                'claim_progress_state' => 'not_observed',
            ];
        }

        $heartbeat = $this->parseWorkerLeaseDate($lease['heartbeat_at'] ?? null);
        $expiresAt = $this->parseWorkerLeaseDate($lease['expires_at'] ?? null);
        $stale = $heartbeat === null
            || $heartbeat->addSeconds(self::OUTBOUND_WORKER_HEARTBEAT_STALE_AFTER_SECONDS)->lte($now)
            || ($expiresAt !== null && $expiresAt->lte($now));
        $lastCycleAt = $this->parseWorkerLeaseDate($lease['last_cycle_at'] ?? null);
        $lastProgressAt = $this->parseWorkerLeaseDate($lease['last_progress_at'] ?? null);
        $lastCandidateCount = max(0, (int) ($lease['last_cycle_candidate_count'] ?? 0));
        $lastProgressCount = max(0, (int) ($lease['last_cycle_progress_count'] ?? 0));
        $claimProgressState = $lastCycleAt === null
            ? 'not_observed'
            : ($lastCandidateCount === 0
                ? 'idle'
                : ($lastProgressCount > 0 ? 'progressing' : 'stalled'));

        return [
            'state' => $stale ? 'stale' : ($claimProgressState === 'stalled' ? 'unhealthy' : 'active'),
            'process_id' => is_numeric($lease['process_id'] ?? null) ? (int) $lease['process_id'] : null,
            'release_sha' => is_scalar($lease['release_sha'] ?? null) ? (string) $lease['release_sha'] : null,
            'mode' => is_scalar($lease['mode'] ?? null) ? (string) $lease['mode'] : null,
            'profile' => is_scalar($lease['profile'] ?? null) ? (string) $lease['profile'] : null,
            'profile_fingerprint' => is_scalar($lease['profile_fingerprint'] ?? null) ? (string) $lease['profile_fingerprint'] : null,
            'activated_at' => $this->parseWorkerLeaseDate($lease['activated_at'] ?? null)?->toIso8601String(),
            'started_at' => $this->parseWorkerLeaseDate($lease['started_at'] ?? null)?->toIso8601String(),
            'heartbeat_at' => $heartbeat?->toIso8601String(),
            'expires_at' => $expiresAt?->toIso8601String(),
            'cycle_count' => max(0, (int) ($lease['cycle_count'] ?? 0)),
            'processed_count' => max(0, (int) ($lease['processed_count'] ?? 0)),
            'last_cycle_candidate_count' => $lastCandidateCount,
            'last_cycle_progress_count' => $lastProgressCount,
            'last_cycle_at' => $lastCycleAt?->toIso8601String(),
            'last_progress_at' => $lastProgressAt?->toIso8601String(),
            'claim_progress_state' => $claimProgressState,
        ];
    }

    private function normalizePhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?: '';

        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '0')) {
            return '90'.substr($digits, 1);
        }

        if (strlen($digits) === 10) {
            return '90'.$digits;
        }

        return $digits;
    }

    /** @param array<string, mixed> $settings */
    public function testPhoneForRole(array $settings, string $role): string
    {
        $field = $role === 'customer'
            ? 'customer_test_phone'
            : 'technician_ops_test_phone';

        return $this->normalizePhone((string) ($settings[$field] ?? ''));
    }

    private function validPhone(string $phone): bool
    {
        $normalized = $this->normalizePhone($phone);

        return preg_match('/^[1-9][0-9]{10,14}$/', $normalized) === 1;
    }

    /**
     * @param  array<string, mixed>  $type
     */
    private function messageTypeAllowsChannel(array $type, string $channel, string $provider): bool
    {
        $policy = (string) ($type['channel_policy'] ?? 'disabled');
        $allowedPolicies = $channel === 'whatsapp'
            ? ['whatsapp_only', 'whatsapp_primary_sms_fallback', 'whatsapp_and_sms']
            : ['sms_only', 'whatsapp_primary_sms_fallback', 'whatsapp_and_sms'];
        $providerField = $channel === 'whatsapp' ? 'whatsapp_provider' : 'sms_provider';
        $modeField = $channel === 'whatsapp' ? 'whatsapp_mode' : 'sms_mode';

        return in_array($channel, ['whatsapp', 'sms'], true)
            && in_array($policy, $allowedPolicies, true)
            && (string) ($type[$providerField] ?? '') === $provider
            && (string) ($type[$modeField] ?? 'disabled') !== 'disabled';
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function scopedLocalUatBodyUrlsAreSafe(string $body, array $settings): bool
    {
        preg_match_all('~https?://[^\s<>"\']+~iu', $body, $matches);
        $urls = array_map(
            static fn (string $url): string => rtrim($url, '.,;:!?)]}'),
            (array) ($matches[0] ?? []),
        );
        if ($urls === []) {
            return true;
        }

        $expected = PartnerPortalPublicUrl::normalizeOrigin(
            (string) ($settings['manual_e2e_partner_portal_origin'] ?? ''),
        );
        if ($expected === null || ! PartnerPortalPublicUrl::isPrivateLanOrigin($expected)) {
            return false;
        }

        foreach ($urls as $url) {
            $parts = parse_url($url);
            if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
                return false;
            }
            $origin = strtolower((string) $parts['scheme']).'://'.strtolower((string) $parts['host']);
            if (isset($parts['port'])) {
                $origin .= ':'.(int) $parts['port'];
            }
            if (! hash_equals(strtolower($expected), $origin)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $metadata
     */
    private function localManualAcceptanceBodyUrlsAreSafe(
        string $body,
        string $messageType,
        array $metadata,
        array $settings,
    ): bool {
        preg_match_all('~https?://[^\s<>"\']+~iu', $body, $matches);
        $urls = array_map(
            static fn (string $url): string => rtrim($url, '.,;:!?)]}'),
            (array) ($matches[0] ?? []),
        );
        if ($urls === []) {
            return true;
        }

        $expected = PartnerPortalPublicUrl::normalizeOrigin(
            (string) ($settings['manual_e2e_partner_portal_origin'] ?? ''),
        );
        if ($expected === null || ! PartnerPortalPublicUrl::isPrivateLanOrigin($expected)) {
            return false;
        }

        foreach ($urls as $url) {
            $parts = parse_url($url);
            if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
                return false;
            }
            $origin = strtolower((string) $parts['scheme']).'://'.strtolower((string) $parts['host']);
            if (isset($parts['port'])) {
                $origin .= ':'.(int) $parts['port'];
            }
            if (hash_equals(strtolower($expected), $origin)) {
                continue;
            }
            if (! $this->isCanonicalGoogleMapsUrl($parts)) {
                if (! $this->isCanonicalPaymentLinkUrl($url, $messageType, $metadata)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param array<string, mixed> $metadata */
    private function isCanonicalPaymentLinkUrl(string $url, string $messageType, array $metadata): bool
    {
        if (! in_array($messageType, ['payment_link_customer', 'part_fee_payment_link_customer'], true)) {
            return false;
        }

        $paymentId = (int) ($metadata['payment_id'] ?? 0);
        if ($paymentId <= 0) {
            return false;
        }

        $payment = TechnicalServiceMountPayment::query()->find($paymentId);
        if (! $payment instanceof TechnicalServiceMountPayment
            || $payment->status !== TechnicalServiceMountPayment::STATUS_PENDING
            || strtolower(trim((string) $payment->provider)) !== 'iyzico'
            || ! hash_equals(trim((string) $payment->payment_url), trim($url))) {
            return false;
        }

        $settings = app(TechnicalServicePaymentProviderSettingsService::class);
        $payload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $storedMode = strtolower(trim((string) (
            data_get($payload, 'provider_mode')
            ?? data_get($payload, 'provider_decision.provider_mode')
            ?? data_get($payload, 'provider_gateway.mode')
            ?? ''
        )));
        if (! $settings->realProviderEnabled()
            || $settings->configuredProvider() !== 'iyzico'
            || ! in_array($storedMode, ['sandbox', 'live'], true)
            || ! hash_equals($settings->providerMode(), $storedMode)) {
            return false;
        }

        $paymentUrlHash = hash('sha256', trim($url));
        $authority = data_get($payload, 'canonical_payment_session_authority');
        $authorityMatches = is_array($authority)
            && (string) ($authority['create_status'] ?? '') === 'completed'
            && (int) ($authority['payment_id'] ?? 0) === $paymentId
            && (string) ($authority['provider'] ?? '') === 'iyzico'
            && hash_equals((string) ($authority['payment_url_hash'] ?? ''), $paymentUrlHash);
        $historyMatches = collect((array) data_get($payload, 'canonical_payment_create_history', []))
            ->contains(fn (mixed $entry): bool => is_array($entry)
                && (string) ($entry['operation'] ?? '') === 'payment_create'
                && (string) ($entry['status'] ?? '') === 'completed'
                && (int) ($entry['payment_id'] ?? 0) === $paymentId
                && (string) ($entry['provider'] ?? '') === 'iyzico'
                && hash_equals((string) ($entry['payment_url_hash'] ?? ''), $paymentUrlHash));
        if (! $authorityMatches || ! $historyMatches) {
            return false;
        }

        try {
            $presented = TechnicalServicePaymentActionPresenter::forPayment($payment);
        } catch (Throwable) {
            return false;
        }

        return (bool) ($presented['can_send'] ?? false)
            && hash_equals((string) ($presented['canonical_url'] ?? ''), trim($url));
    }

    /** @param array<string, mixed> $parts */
    private function isCanonicalGoogleMapsUrl(array $parts): bool
    {
        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'www.google.com'
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
            || isset($parts['user'])
            || isset($parts['pass'])
            || ! in_array((string) ($parts['path'] ?? ''), ['/maps/search', '/maps/search/'], true)
        ) {
            return false;
        }

        parse_str((string) ($parts['query'] ?? ''), $query);

        return (string) ($query['api'] ?? '') === '1'
            && trim((string) ($query['query'] ?? '')) !== '';
    }

    private function maskEmail(string $email): string
    {
        $email = strtolower(trim($email));
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($local === '' || $domain === '') {
            return '[invalid-email]';
        }

        return substr($local, 0, 1).'***@'.$domain;
    }

    private function maskPhone(?string $phone): ?string
    {
        $normalized = $this->normalizePhone($phone);

        if ($normalized === '') {
            return null;
        }

        return substr($normalized, 0, 4).'****'.substr($normalized, -3);
    }
}
