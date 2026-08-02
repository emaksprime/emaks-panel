<?php

namespace App\Services\Messaging;

use App\Models\IntegrationProviderCredential;
use App\Models\PageConfig;
use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServiceMountPayment;
use App\Models\TechnicalServiceRequest;
use App\Models\User;
use App\Services\ExternalEffects\ExternalEffectCapabilityRegistry;
use App\Services\ExternalEffects\ExternalExecutionControlPlaneService;
use App\Services\Mikro\MikroOperationRegistry;
use App\Services\Mikro\MikroRuntimeState;
use App\Services\Payments\TechnicalServiceMailTransportSettingsService;
use App\Services\Payments\TechnicalServicePaymentProviderSettingsService;
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

    public const SCOPED_LOCAL_UAT_MAX_TTL_SECONDS = 3600;

    public const SCOPED_EFFECT_PAYMENT_CREATE = 'sandbox_payment_create';

    public const SCOPED_EFFECT_PAYMENT_CALLBACK = 'sandbox_payment_callback';

    public const OUTBOUND_EXECUTION_MODE_LOCAL = 'local';

    public const OUTBOUND_EXECUTION_MODE_LIVE = 'live';

    public const OUTBOUND_WORKER_LOCK_KEY = 'technical_service_message_dispatch_live_worker';

    public const OUTBOUND_WORKER_LEASE_KEY = 'technical_service_message_dispatch_live_worker_lease';

    public const OUTBOUND_WORKER_HEARTBEAT_STALE_AFTER_SECONDS = 30;

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
    ];

    public const GENERIC_LIFECYCLE_FIELDS = [
        'manual_e2e_enabled',
        'real_send_enabled',
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
    ];

    private const ACTIVE_RUN_LOCKED_FIELDS = [
        'messaging_enabled',
        'test_mode_enabled',
        'test_phone',
        'shared_test_phone',
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
            'global' => [
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
            ],
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
                'Manual E2E önce güvenli hazırlık durumuna alınır; gerçek gönderim yalnız exact dispatch için kısa tek kullanımlık pencereyle açılır.',
                'Randevu mesajları usta seçildiğinde değil OPS randevu onayında gider.',
                'Test modu açıkken hedef numara test numarasına çevrilir.',
                'Provider dispatch’leri allowlist, run context, queue, rate limit ve idempotency kontrollerinden geçer.',
                'Voibot ses/mesaj sağlayıcısı API sözleşmesi doğrulanana kadar kapalıdır.',
                'Evo Direct API, NAC SMS ve Mikro API canlı aksiyonları credential/readiness/queue/onay tamamlanmadan çalışmaz.',
            ],
            'helper_texts' => [
                'secrets' => 'Evo, NAC, Voibot, Mikro veya n8n token/API key bu ekranda düz metin saklanmaz ve gösterilmez.',
                'queue' => 'Gerçek provider kuyruğu yalnız aktif Manual E2E run, exact dispatch penceresi ve kalıcı tek kullanımlık claim ile işlenir.',
                'test_phone' => 'Test modu açıkken müşteri/usta yerine ortak test telefonuna yönlenir.',
                'active_provider' => 'Öncelikli sağlayıcı manuel test/readiness için varsayılan bakılan sağlayıcıdır.',
                'default_provider' => 'Varsayılan test sağlayıcısı otomasyon değil, güvenli preview/test tercihidir.',
                'fallback_provider' => 'Otomatik provider fallback bu kontrollü akışta kapalıdır; kanal politikası açık provider seçimini kullanır.',
                'channel_policy' => 'Kanal politikası WhatsApp/SMS seçimini tanımlar; birlikte gönderim güvenli queue ve idempotency kontrolleriyle yürür.',
            ],
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
        $scopedSnapshot = $this->isScopedLocalUatSettings($settings)
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
                'global_execution_mode',
                'global_execution_state',
                'global_execution_epoch',
                'global_execution_revision',
                'global_runtime_environment',
                'global_profile_fingerprint',
            ])
            : [];

        return [
            ...app(ExternalExecutionControlPlaneService::class)->messagingSnapshot($provider),
            ...$scopedSnapshot,
            'messaging_enabled' => (bool) ($settings['messaging_enabled'] ?? false),
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
                if (! $this->messageTypeAllowsScopedChannel($type, (string) $channel, (string) $provider)) {
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

    /**
     * @return array{required:bool,duplicate:bool,claim_nonce:string|null}
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
     * @return array{required:bool,duplicate:bool,claim_nonce:string|null}
     */
    public function claimScopedLocalUatSandboxPaymentEffect(
        TechnicalServiceMountPayment $payment,
        string $operation,
        string $provider,
    ): array {
        if (! in_array($operation, [self::SCOPED_EFFECT_PAYMENT_CREATE, self::SCOPED_EFFECT_PAYMENT_CALLBACK], true)) {
            throw new ConflictHttpException('FAIL_CLOSED_UNAUTHORIZED_CAPABILITY: Scoped UAT ödeme operation izni yok.');
        }

        return $this->claimScopedLocalUatEffect(
            $payment,
            ExternalEffectCapabilityRegistry::PAYMENT_LOCAL_SANDBOX_EXECUTE,
            'sandbox_payment',
            'sandbox_payment',
            $provider,
            $operation,
        );
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
     * @return array{required:bool,duplicate:bool,claim_nonce:string|null}
     */
    private function claimScopedLocalUatEffect(
        TechnicalServiceMountPayment $payment,
        string $capability,
        string $event,
        string $channel,
        string $provider,
        string $operation,
        string|array|null $recipient = null,
    ): array {
        $lock = $this->acquireLifecycleLock();

        try {
            return DB::transaction(function () use ($payment, $capability, $event, $channel, $provider, $operation, $recipient): array {
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
                    return ['required' => false, 'duplicate' => false, 'claim_nonce' => null];
                }
                if (! $tagged || ! $activeScopedRun || ! $context->isActive()) {
                    throw new ConflictHttpException('scoped_uat_active_run_missing: Effect exact aktif synthetic UAT run ile bağlı değil.');
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

                $state = $this->scopedLocalUatControlPlaneState(false);
                $capabilityState = is_array($state['capabilities'][$capability] ?? null)
                    ? $state['capabilities'][$capability]
                    : [];
                $configurationFingerprint = (string) ($capabilityState['profile_fingerprint'] ?? '');
                if (! preg_match('/^[a-f0-9]{64}$/', $configurationFingerprint)) {
                    throw new ConflictHttpException('scoped_uat_configuration_fingerprint_missing: Effect configuration bağı doğrulanamadı.');
                }

                $recipientFingerprint = $recipient === null
                    ? null
                    : hash('sha256', strtolower(trim($recipient)));
                $idempotencyHash = hash('sha256', implode('|', [
                    ExternalEffectCapabilityRegistry::LOCAL_ALLOWLISTED_UAT_PROFILE,
                    $runId,
                    $operation,
                    'payment:'.$lockedPayment->getKey(),
                    number_format((float) $lockedPayment->amount, 2, '.', ''),
                    strtoupper((string) $lockedPayment->currency),
                    (string) $recipientFingerprint,
                ]));
                $history = (array) ($settings['scoped_local_uat_effect_history'] ?? []);
                $previous = collect($history)->first(fn (mixed $entry): bool => is_array($entry)
                    && (string) ($entry['run_id'] ?? '') === $runId
                    && hash_equals((string) ($entry['idempotency_hash'] ?? ''), $idempotencyHash));
                if (is_array($previous)) {
                    if ((string) ($previous['status'] ?? '') === 'completed') {
                        return ['required' => true, 'duplicate' => true, 'claim_nonce' => null];
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
                $payload['scoped_local_uat_effect_claim'] = $claim;
                $lockedPayment->forceFill(['raw_payload' => $payload])->save();
                $settings['scoped_local_uat_active_effect_claim'] = $claim;
                $this->validateSettings($settings);
                $this->persistAuthoritativeSettings($locked, $settings);

                return ['required' => true, 'duplicate' => false, 'claim_nonce' => $claimNonce];
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
                    if (is_array($previous) && (string) ($previous['status'] ?? '') === 'completed') {
                        return;
                    }

                    throw new ConflictHttpException('Scoped effect sonucu aktif claim ile eşleşmiyor.');
                }

                $historyEntry = [
                    ...$claim,
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
                    $payload['scoped_local_uat_effect_history'] = array_values(array_slice([
                        ...$paymentHistory,
                        $historyEntry,
                    ], -20));
                    $payment->forceFill(['raw_payload' => $payload])->save();
                }

                $settings['scoped_local_uat_active_effect_claim'] = null;
                $settings['scoped_local_uat_effect_history'] = $this->appendWindowHistory(
                    (array) ($settings['scoped_local_uat_effect_history'] ?? []),
                    $historyEntry,
                );
                $this->validateSettings($settings);
                $this->persistAuthoritativeSettings($locked, $settings);
            });
        } finally {
            $lock();
        }
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

        $this->validateSettings($next);

        return $next;
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
                    ];
                }

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
                $openedAt = CarbonImmutable::now();
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
                    'opened_at' => $openedAt->toIso8601String(),
                    'expires_at' => $openedAt->addSeconds(self::MANUAL_E2E_WINDOW_TTL_SECONDS)->toIso8601String(),
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
                    || $this->windowExpired($claim)
                ) {
                    throw new ConflictHttpException('Manual E2E transport izni geçersiz veya daha önce kullanılmış.');
                }

                $dispatch = TechnicalServiceMessageDispatch::query()->whereKey($dispatchId)->lockForUpdate()->first();
                $authoritative = $dispatch instanceof TechnicalServiceMessageDispatch
                    ? $this->manualE2EDispatchSecurityTuple($dispatch)
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
                if (is_array($current['scoped_local_uat_active_effect_claim'] ?? null)) {
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
                        $payload['scoped_local_uat_effect_history'] = array_values(array_slice([
                            ...$paymentHistory,
                            $frozenEffect,
                        ], -20));
                        $payment->forceFill(['raw_payload' => $payload])->save();
                    }
                }
                $next = $this->deactivateManualE2EContext($current, $context);
                $next['test_mode_enabled'] = false;
                $next['manual_e2e_window_history'] = $history;
                $next['scoped_local_uat_effect_history'] = $effectHistory;
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
        if ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
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
            if ($context->enabled()
                || $context->activeRunId() !== null
                || $context->phase() !== self::MANUAL_E2E_PHASE_FROZEN
                || is_array($current['normal_outbound_active_claim'] ?? null)) {
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
        $authoritative = $this->manualE2EDispatchSecurityTuple($dispatch);
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
    private function manualE2EDispatchSecurityTuple(TechnicalServiceMessageDispatch $dispatch): array
    {
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

        $expectedBodyToken = trim((string) ($metadata['expected_body_token'] ?? ''));
        $body = $dispatch->bodyForProvider();
        $bodyErrors = [...$dispatch->providerBodyValidationErrors(), ...$dispatch->roleBodyValidationErrors()];
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
            && $this->manualE2EExecutionScopeAllowed($settings)
            && (bool) $settings['real_send_enabled']
            && $this->readiness($settings)['can_send_real'];
    }

    public function testPhone(): string
    {
        return (string) $this->settings()['test_phone'];
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
        $defaults = $this->defaultSettings();
        $settings = is_array($stored) ? array_replace_recursive($defaults, $stored) : $defaults;
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
            && ! $this->manualE2EExecutionScopeAllowed($settings)) {
            throw ValidationException::withMessages([
                'real_send_enabled' => 'Gerçek gönderim yalnız global Canlı veya exact allowlistli Yerel UAT run kapsamında açılabilir.',
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

        // Legacy/non-manual settings predate persisted lifecycle phases. Once a
        // lifecycle operation writes a phase, every transition is strict.
        if ($storedPhase === ''
            && ! $manualEnabled
            && ! $realSend
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
            (string) ($effectClaim['status'] ?? '') !== 'claimed'
            || (string) ($effectClaim['run_id'] ?? '') !== $runId
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
                || ($productionLive ? (! $realSend || $queuePaused) : ($realSend || ! $queuePaused))
                || $runId !== null
                || $window !== null
                || $claim !== null
                || $effectClaim !== null,
            self::MANUAL_E2E_PHASE_PREPARED => ! $manualEnabled
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
                ))
                || ($claim !== null && $effectClaim !== null)
                || $normalClaim !== null,
            self::MANUAL_E2E_PHASE_WINDOW_OPEN => ! $manualEnabled
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
                || $this->invalidWindowTtl($window)
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
            || ! preg_match('/^[a-f0-9]{64}$/', (string) ($scope['body_fingerprint'] ?? ''));
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
            || (array) ($snapshot['scoped_local_uat_limits'] ?? []) !== (array) $profile['limits']
            || ! preg_match('/^[a-f0-9]{64}$/', (string) ($snapshot['scoped_local_uat_email_allowlist_fingerprint'] ?? ''))
            || ! preg_match('/^[a-f0-9]{64}$/', (string) ($snapshot['scoped_local_uat_event_policy_fingerprint'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $window
     */
    private function invalidWindowTtl(array $window): bool
    {
        try {
            $openedAt = CarbonImmutable::parse((string) ($window['opened_at'] ?? ''));
            $expiresAt = CarbonImmutable::parse((string) ($window['expires_at'] ?? ''));
        } catch (Throwable) {
            return true;
        }

        return ! $openedAt->lt($expiresAt)
            || $openedAt->diffInSeconds($expiresAt) > self::MANUAL_E2E_WINDOW_TTL_SECONDS;
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
        $testPhoneConfigured = $this->validPhone((string) $settings['test_phone']);
        $activeProvider = $this->normalizeProviderKey((string) $settings['active_provider']);
        $activeProviderDefinition = self::PROVIDERS[$activeProvider];
        $activeProviderEnabled = $this->providerEnabled($activeProvider, $settings);
        $activeProviderSupportsText = (bool) ($activeProviderDefinition['capabilities']['supports_text'] ?? false);
        $activeProviderCredentialsReady = $this->providerCredentialsReady($activeProvider, $settings, $webhookConfigured);
        $activeProviderRealReady = $this->providerRealReady($activeProvider, $settings, $webhookConfigured);
        $realAllowedTypes = collect($settings['message_types'] ?? [])
            ->filter(fn (array $type): bool => (bool) ($type['enabled'] ?? false) && (bool) ($type['real_send_allowed'] ?? false))
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
            $disabledReasons[] = 'Gerçek gönderime açık mesaj tipi yok.';
        }
        if ((bool) ($settings['queue_paused'] ?? true)) {
            $disabledReasons[] = 'Provider kuyruğu duraklatıldı.';
        } elseif (! $manualE2eContext->isActive()) {
            $disabledReasons[] = 'Provider kuyruğu açık ancak aktif Manual E2E run context yok.';
        } elseif ($manualE2eContext->workerCommand() === null) {
            $disabledReasons[] = 'Manual E2E güvenli worker komutu hazır değil.';
        }

        $canSendTest = (bool) $settings['messaging_enabled']
            && (bool) $settings['test_mode_enabled']
            && $testPhoneConfigured
            && $testAllowedTypes !== []
            && $activeProviderEnabled
            && $activeProviderSupportsText;
        $canSendReal = (bool) $settings['messaging_enabled']
            && (bool) $settings['real_send_enabled']
            && ! (bool) $settings['test_mode_enabled']
            && $testPhoneConfigured
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

        if ((bool) $settings['test_mode_enabled']) {
            return $testPhoneConfigured ? 'test_redirect' : 'blocked_missing_test_phone';
        }

        if ($activeProvider === 'evo_whatsapp' && ! $this->evoWhatsappReadiness($settings)['ready']) {
            return 'blocked_provider_missing';
        }

        if (! (bool) $settings['real_send_enabled']) {
            return 'blocked_real_send_disabled';
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
    private function isScopedLocalUatSettings(array $settings): bool
    {
        return data_get($settings, 'manual_e2e_run_snapshot.scoped_local_uat_profile_id')
            === ExternalEffectCapabilityRegistry::LOCAL_ALLOWLISTED_UAT_PROFILE;
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
        if (is_array($current['normal_outbound_active_claim'] ?? null)) {
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
            ->exists();
        if ($otherUnresolvedAttempt) {
            throw ValidationException::withMessages([
                'manual_e2e' => 'Başka bir external provider attempt sonucu belirsizken normal outbound başlatılamaz.',
            ]);
        }
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

        if ($mode !== self::OUTBOUND_EXECUTION_MODE_LIVE && ! $scopedManualE2E) {
            return $this->executionBlock('outbound_execution_mode_local', 'Global sistem çalışma modu Lokal; dış provider çağrısı kapalı.');
        }
        if (! in_array((string) $dispatch->provider_key, ['evo_whatsapp', 'nac_sms'], true)
            || ! in_array((string) $dispatch->channel, ['whatsapp', 'sms'], true)
            || ($dispatch->provider_key === 'evo_whatsapp' && $dispatch->channel !== 'whatsapp')
            || ($dispatch->provider_key === 'nac_sms' && $dispatch->channel !== 'sms')) {
            return $this->executionBlock('outbound_provider_channel_mismatch', 'Dispatch provider/channel tuple çalışma modu için geçersiz.');
        }
        $globalAuthorization = $this->outboundSnapshotAuthorization(
            (string) $dispatch->provider_key,
            $metadata,
        );
        if (! $globalAuthorization['allowed']) {
            return $globalAuthorization;
        }
        if (! $this->evoProviderReadyForLive($settings, $environment === 'production')
            || ! $this->nacProviderReadyForLive($settings, $environment === 'production')) {
            return $this->executionBlock('outbound_provider_set_not_ready', 'Evo ve NAC provider readiness birlikte geçerli değil; outbound fail-closed tutuldu.');
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

        if ($environment !== 'production') {
            return $this->executionBlock('non_production_normal_outbound_blocked', 'Non-production ortamda normal operasyon outbound kapalı; yalnız exact Manual E2E permit kullanılabilir.');
        }
        if ($context->enabled()
            || $context->activeRunId() !== null
            || $context->phase() !== self::MANUAL_E2E_PHASE_FROZEN
            || ! (bool) ($settings['real_send_enabled'] ?? false)
            || (bool) ($settings['queue_paused'] ?? true)) {
            return $this->executionBlock('production_live_gate_closed', 'Production live provider gate veya queue state hazır değil.');
        }
        if (! (bool) data_get($settings, 'message_types.'.$dispatch->message_type.'.real_send_allowed', false)) {
            return $this->executionBlock('message_type_real_send_disabled', 'Dispatch mesaj tipi gerçek gönderime açık değil.');
        }
        $worker = $this->outboundWorkerLeaseStatus();
        $rawWorker = $this->outboundWorkerLease();
        $releaseSha = $this->runtimeReleaseSha();
        if (($worker['state'] ?? null) !== 'active'
            || $releaseSha === null
            || ! hash_equals((string) ($worker['release_sha'] ?? ''), $releaseSha)) {
            return $this->executionBlock('outbound_worker_not_healthy', 'Normal outbound worker heartbeat current release ile eşleşmiyor.');
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
        if (is_array($settings['scoped_local_uat_active_effect_claim'] ?? null)) {
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
        if ($this->runtimeEnvironment() !== 'production' || trim($lockOwner) === '' || $releaseSha === null) {
            throw new ConflictHttpException('Normal outbound worker yalnız exact release SHA ile production ortamında kaydedilebilir.');
        }

        $now = CarbonImmutable::now();
        $lease = [
            'lock_owner' => $lockOwner,
            'process_id' => getmypid() ?: null,
            'release_sha' => $releaseSha,
            'mode' => 'normal_live_worker',
            'started_at' => $startedAt->toIso8601String(),
            'heartbeat_at' => $now->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
        Cache::put(self::OUTBOUND_WORKER_LEASE_KEY, $lease, max(60, $now->diffInSeconds($expiresAt) + 60));

        return $this->outboundWorkerLeasePublicPayload($lease, $now);
    }

    public function heartbeatOutboundWorkerLease(string $lockOwner): bool
    {
        $lease = $this->outboundWorkerLease();
        if ($lease === null
            || ($lease['lock_owner'] ?? null) !== $lockOwner
            || ($lease['release_sha'] ?? null) !== $this->runtimeReleaseSha()) {
            return false;
        }

        $lease['heartbeat_at'] = CarbonImmutable::now()->toIso8601String();
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
        $lease = $this->outboundWorkerLease();
        $settings = $this->settings();
        $context = TechnicalServiceManualE2ERunContext::fromSettings($settings);

        return $lease !== null
            && ($lease['lock_owner'] ?? null) === $lockOwner
            && ($lease['release_sha'] ?? null) === $this->runtimeReleaseSha()
            && $this->runtimeEnvironment() === 'production'
            && $this->executionMode($settings) === self::OUTBOUND_EXECUTION_MODE_LIVE
            && (bool) ($settings['real_send_enabled'] ?? false)
            && ! (bool) ($settings['queue_paused'] ?? true)
            && $context->phase() === self::MANUAL_E2E_PHASE_FROZEN
            && ! $context->enabled()
            && $context->activeRunId() === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function outboundWorkerLeaseStatus(): array
    {
        return $this->outboundWorkerLeasePublicPayload($this->outboundWorkerLease(), CarbonImmutable::now());
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
                'release_sha' => null,
                'started_at' => null,
                'heartbeat_at' => null,
                'expires_at' => null,
            ];
        }

        $heartbeat = $this->parseWorkerLeaseDate($lease['heartbeat_at'] ?? null);
        $expiresAt = $this->parseWorkerLeaseDate($lease['expires_at'] ?? null);
        $stale = $heartbeat === null
            || $heartbeat->addSeconds(self::OUTBOUND_WORKER_HEARTBEAT_STALE_AFTER_SECONDS)->lte($now)
            || ($expiresAt !== null && $expiresAt->lte($now));

        return [
            'state' => $stale ? 'stale' : 'active',
            'release_sha' => is_scalar($lease['release_sha'] ?? null) ? (string) $lease['release_sha'] : null,
            'started_at' => $this->parseWorkerLeaseDate($lease['started_at'] ?? null)?->toIso8601String(),
            'heartbeat_at' => $heartbeat?->toIso8601String(),
            'expires_at' => $expiresAt?->toIso8601String(),
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

    private function validPhone(string $phone): bool
    {
        $normalized = $this->normalizePhone($phone);

        return preg_match('/^[1-9][0-9]{10,14}$/', $normalized) === 1;
    }

    /**
     * @param  array<string, mixed>  $type
     */
    private function messageTypeAllowsScopedChannel(array $type, string $channel, string $provider): bool
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
