<?php

namespace App\Services\Messaging;

use App\Models\IntegrationProviderCredential;
use App\Models\PageConfig;
use App\Models\TechnicalServiceMessageDispatch;
use App\Support\PartnerPortalPublicUrl;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class TechnicalServiceMessagingSettingsService
{
    public const PAGE_CODE = 'technical_service_admin';

    public const ROOT_KEY = 'technical_service.messaging';

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
            'description' => 'Mikro read/write entegrasyon sağlayıcısı; yazma işlemleri ayrı onay ve audit olmadan açılmaz.',
            'status_label' => 'ERP API hazırlığı',
            'default_enabled' => false,
            'contract_confirmed' => false,
            'current_practical' => false,
            'disabled_reason' => 'Mikro API lisans/sözleşme, operasyon kataloğu ve yazma onayı doğrulanmadı.',
            'capabilities' => [
                'supports_read' => true,
                'supports_write' => true,
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
                'Gerçek gönderim yalnız Manual E2E kontrol panelindeki güvenli açma/dondurma yaşam döngüsüyle yönetilir.',
                'Randevu mesajları usta seçildiğinde değil OPS randevu onayında gider.',
                'Test modu açıkken hedef numara test numarasına çevrilir.',
                'Provider dispatch’leri allowlist, run context, queue, rate limit ve idempotency kontrollerinden geçer.',
                'Voibot ses/mesaj sağlayıcısı API sözleşmesi doğrulanana kadar kapalıdır.',
                'Evo Direct API, NAC SMS ve Mikro API canlı aksiyonları credential/readiness/queue/onay tamamlanmadan çalışmaz.',
            ],
            'helper_texts' => [
                'secrets' => 'Evo, NAC, Voibot, Mikro veya n8n token/API key bu ekranda düz metin saklanmaz ve gösterilmez.',
                'queue' => 'Gerçek provider kuyruğu yalnız aktif Manual E2E run context ve üretilen güvenli worker komutuyla işlenir.',
                'test_phone' => 'Test modu açıkken müşteri/usta yerine ortak test telefonuna yönlenir.',
                'active_provider' => 'Öncelikli sağlayıcı manuel test/readiness için varsayılan bakılan sağlayıcıdır.',
                'default_provider' => 'Varsayılan test sağlayıcısı otomasyon değil, güvenli preview/test tercihidir.',
                'fallback_provider' => 'Otomatik provider fallback bu kontrollü akışta kapalıdır; kanal politikası açık provider seçimini kullanır.',
                'channel_policy' => 'Kanal politikası WhatsApp/SMS seçimini tanımlar; birlikte gönderim güvenli queue ve idempotency kontrolleriyle yürür.',
            ],
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
                $page = $this->lockedPageConfig();
                $current = $this->settingsFromLayout((array) $page->layout_json);
                $next = $this->buildGenericSettingsUpdate($current, $values);
                $this->persistSettingsToPage($page, $next);
            });
        } finally {
            $lock->release();
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
        $context = TechnicalServiceManualE2ERunContext::fromSettings($current);
        if (! $context->enabled() && $context->activeRunId() === null) {
            return;
        }

        $lockedFields = array_values(array_intersect(array_keys($values), self::ACTIVE_RUN_LOCKED_FIELDS));
        if ($lockedFields !== []) {
            throw new ConflictHttpException('Aktif Manual E2E oturumu varken gönderim güvenliği ayarları değiştirilemez. Önce gönderimleri dondurun.');
        }
    }

    private function assertNoActiveRunMutation(?array $settings = null): void
    {
        $context = TechnicalServiceManualE2ERunContext::fromSettings($settings ?? $this->settings());
        if ($context->enabled() || $context->activeRunId() !== null) {
            throw new ConflictHttpException('Aktif Manual E2E oturumu varken gönderim güvenliği ayarları değiştirilemez. Önce gönderimleri dondurun.');
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
    public function enableManualE2E(array $values = []): array
    {
        $lock = $this->acquireLifecycleLock();

        try {
            $this->reconcileStaleManualE2EWorkerLease();
            if (! $this->lockAvailable(TechnicalServiceManualE2ERunContext::WORKER_LOCK_KEY)) {
                throw new ConflictHttpException('Başka bir Manual E2E worker çalışıyor veya worker lock sahipliği güvenli biçimde doğrulanamadı.');
            }

            DB::transaction(function () use ($values): void {
                $page = $this->lockedPageConfig();
                $current = $this->settingsFromLayout((array) $page->layout_json);
                $context = TechnicalServiceManualE2ERunContext::fromSettings($current);
                if ($context->enabled() || $context->activeRunId() !== null) {
                    throw new ConflictHttpException('Aktif Manual E2E oturumu zaten var. Yeni run için önce gönderimleri dondurun.');
                }

                $allowlist = array_key_exists('manual_e2e_allowlisted_phones', $values)
                    ? array_values(array_unique(array_filter(array_map(
                        fn (mixed $phone): string => $this->normalizePhone((string) $phone),
                        (array) $values['manual_e2e_allowlisted_phones'],
                    ))))
                    : (array) ($current['manual_e2e_allowlisted_phones'] ?? []);
                $ttl = max(60, min(
                    TechnicalServiceManualE2ERunContext::DEFAULT_TTL_SECONDS,
                    (int) ($values['manual_e2e_ttl_seconds'] ?? $current['manual_e2e_ttl_seconds'] ?? TechnicalServiceManualE2ERunContext::DEFAULT_TTL_SECONDS),
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

                $next = [
                    ...$candidate,
                    'manual_e2e_enabled' => true,
                    'real_send_enabled' => true,
                    'test_mode_enabled' => false,
                    'queue_paused' => false,
                    'ops_whatsapp_enabled' => true,
                    'manual_e2e_active_run_id' => $runId,
                    'manual_e2e_started_at' => $startedAt->toIso8601String(),
                    'manual_e2e_created_after' => $startedAt->toIso8601String(),
                    'manual_e2e_expires_at' => $startedAt->addSeconds($ttl)->toIso8601String(),
                ];
                $this->validateSettings($next);
                $this->persistSettingsToPage($page, $next);
            });
        } finally {
            $lock->release();
        }

        return $this->payload();
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
                $page = $this->lockedPageConfig();
                $current = $this->settingsFromLayout((array) $page->layout_json);
                $context = TechnicalServiceManualE2ERunContext::fromSettings($current);
                $activeRunId = $context->activeRunId();
                $next = $this->deactivateManualE2EContext($current, $context);
                $next['test_mode_enabled'] = false;
                $this->validateSettings($next);
                $this->persistSettingsToPage($page, $next);
            });

            if ($activeRunId !== null) {
                $this->invalidateManualE2EWorkerLease($activeRunId);
            } else {
                $this->reconcileStaleManualE2EWorkerLease();
            }
        } finally {
            $lock->release();
        }

        return $this->payload();
    }

    public function manualE2EContext(): TechnicalServiceManualE2ERunContext
    {
        return TechnicalServiceManualE2ERunContext::fromSettings($this->settings());
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
        $context = $this->manualE2EContext();
        if (! $context->isActive() || $context->activeRunId() !== $runId || trim($lockOwner) === '') {
            throw new ConflictHttpException('Worker lease aktif Manual E2E run ile eşleşmiyor.');
        }

        $now = CarbonImmutable::now();
        $lease = [
            'run_id' => $runId,
            'lock_owner' => $lockOwner,
            'process_id' => getmypid() ?: null,
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
    }

    public function heartbeatManualE2EWorkerLease(string $runId, string $lockOwner): bool
    {
        $lease = $this->manualE2EWorkerLease();
        $context = $this->manualE2EContext();
        if ($lease === null
            || ($lease['run_id'] ?? null) !== $runId
            || ($lease['lock_owner'] ?? null) !== $lockOwner
            || filled($lease['invalidated_at'] ?? null)
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
    }

    public function clearManualE2EWorkerLease(string $runId, string $lockOwner): bool
    {
        $lease = $this->manualE2EWorkerLease();
        if ($lease === null
            || ($lease['run_id'] ?? null) !== $runId
            || ($lease['lock_owner'] ?? null) !== $lockOwner) {
            return false;
        }

        Cache::forget(TechnicalServiceManualE2ERunContext::WORKER_LEASE_KEY);

        return true;
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
                $page = $this->lockedPageConfig();
                $current = $this->settingsFromLayout((array) $page->layout_json);
                $this->assertNoActiveRunMutation($current);
                $defaults = $this->defaultSettings();
                foreach (self::GENERIC_LIFECYCLE_FIELDS as $field) {
                    if (array_key_exists($field, $current) && array_key_exists($field, $defaults)) {
                        $defaults[$field] = $current[$field];
                    }
                }

                $this->persistSettingsToPage($page, $defaults);
            });
        } finally {
            $lock->release();
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
        $apiKey = trim((string) ($values['api_key'] ?? ''));
        $token = trim((string) ($values['token'] ?? ''));

        if ($apiKey === '' && $token === '') {
            throw ValidationException::withMessages([
                'api_key' => 'Mikro API key veya token zorunlu.',
            ]);
        }

        $credential = IntegrationProviderCredential::query()->updateOrCreate(
            [
                'scope' => IntegrationProviderCredential::SCOPE_TECHNICAL_SERVICE,
                'provider' => 'mikro_api',
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
                'metadata' => ['auth' => $token === '' ? 'api_key' : 'token'],
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
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        return $this->settingsFromLayout($this->layout());
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
            'queue_paused' => false,
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
            'manual_e2e_active_run_id' => null,
            'manual_e2e_started_at' => null,
            'manual_e2e_created_after' => null,
            'manual_e2e_expires_at' => null,
            'manual_e2e_last_run_id' => null,
            'manual_e2e_last_stopped_at' => null,
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
                'timeout_seconds' => 15,
                'license_status' => 'unknown',
                'app_customer_license_status' => 'unknown',
                'read_sync_enabled' => false,
                'write_enabled' => false,
                'write_approval_required' => true,
                'operation_catalog_status' => 'missing',
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
        $blockers = [];
        $allowlist = array_values(array_unique(array_filter(array_map(
            fn (mixed $phone): string => $this->normalizePhone((string) $phone),
            (array) ($settings['manual_e2e_allowlisted_phones'] ?? []),
        ))));
        if ($allowlist === [] || collect($allowlist)->contains(fn (string $phone): bool => ! $this->validPhone($phone))) {
            $blockers[] = ['code' => 'manual_e2e_allowlist_invalid', 'message' => 'Manual E2E için en az bir geçerli allowlist telefonu zorunlu.'];
        }

        $opsPhone = $this->normalizePhone((string) ($settings['ops_whatsapp_phone'] ?? ''));
        if (! $this->validPhone($opsPhone) || ! in_array($opsPhone, $allowlist, true)) {
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
            ->whereIn('status', $pendingStatuses)
            ->whereIn('provider_key', $providers)
            ->count();
        if ($pending > 0) {
            $blockers[] = ['code' => 'pending_provider_dispatch', 'message' => 'Manual E2E açılmadan önce external provider kuyruğu boş olmalı.'];
        }

        $unsafe = TechnicalServiceMessageDispatch::query()
            ->whereIn('status', $pendingStatuses)
            ->whereIn('provider_key', $providers)
            ->whereNotIn('target_phone', $allowlist ?: ['__manual_e2e_allowlist_missing__'])
            ->count();
        if ($unsafe > 0) {
            $blockers[] = ['code' => 'unsafe_provider_dispatch', 'message' => 'Allowlist dışı pending provider dispatch bulundu.'];
        }

        $workerLease = $this->manualE2EWorkerLeaseStatus();
        $rawWorkerLockAvailable = $this->lockAvailable(TechnicalServiceManualE2ERunContext::WORKER_LOCK_KEY);
        $workerLockAvailable = $rawWorkerLockAvailable || (bool) ($workerLease['stale_recoverable'] ?? false);
        if (! $workerLockAvailable) {
            $blockers[] = ['code' => 'manual_e2e_worker_active', 'message' => 'Başka bir Manual E2E worker çalışıyor.'];
        }

        $lifecycleLockAvailable = ! $checkLifecycleLock || $this->lockAvailable(TechnicalServiceManualE2ERunContext::LIFECYCLE_LOCK_KEY);
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
        if ((bool) ($settings['ops_whatsapp_enabled'] ?? false)) {
            $blockers[] = ['code' => 'manual_e2e_ops_not_frozen', 'message' => 'OPS WhatsApp açık. Yeni run öncesinde gönderimler dondurulmalı.'];
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

        if ((bool) $settings['real_send_enabled'] && ! $this->readiness($settings)['can_send_real']) {
            throw ValidationException::withMessages([
                'real_send_enabled' => 'Gerçek gönderim için mesaj sistemi, aktif provider, provider sözleşmesi, test telefonu ve en az bir gerçek gönderime açık mesaj tipi hazır olmalı.',
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

        return (bool) ($settings['messaging_enabled'] ?? false)
            && (bool) ($settings['real_send_enabled'] ?? false)
            && ! (bool) ($settings['test_mode_enabled'] ?? false)
            && ! (bool) ($settings['queue_paused'] ?? true)
            && $context->isActive()
            && $context->workerCommand() !== null;
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
            return false;
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
            'license_status',
            'app_customer_license_status',
            'operation_catalog_status',
        ] as $field) {
            if (array_key_exists($field, $updates)) {
                $value = trim((string) $updates[$field]);
                $next[$field] = $value === '' ? null : $value;
            }
        }

        if (array_key_exists('timeout_seconds', $updates)) {
            $next['timeout_seconds'] = (int) $updates['timeout_seconds'];
        }

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
        $credentialsReady = $credential?->apiKeyReady() ?? false;
        $baseReady = filled($mikro['base_url'] ?? null);
        $writeApprovalRequired = (bool) $mikro['write_approval_required'];
        $writeReady = (bool) $mikro['write_enabled'] && $writeApprovalRequired && $credentialsReady && $baseReady;

        $blocking = [];
        if (! (bool) $mikro['enabled']) {
            $blocking[] = 'Mikro API kapalı.';
        }
        if (! $baseReady) {
            $blocking[] = 'Mikro API base URL eksik.';
        }
        if (! $credentialsReady) {
            $blocking[] = 'Mikro API key/token eksik.';
        }
        if (! $writeApprovalRequired) {
            $blocking[] = 'Mikro yazma onayı zorunlu olmalı.';
        }
        if ($mikro['operation_catalog_status'] !== 'active') {
            $blocking[] = 'Mikro operasyon kataloğu aktif değil.';
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
            'timeout_seconds' => (int) $mikro['timeout_seconds'],
            'license_status' => $mikro['license_status'],
            'app_customer_license_status' => $mikro['app_customer_license_status'],
            'read_sync_enabled' => (bool) $mikro['read_sync_enabled'],
            'write_enabled' => (bool) $mikro['write_enabled'],
            'write_approval_required' => $writeApprovalRequired,
            'operation_catalog_status' => $mikro['operation_catalog_status'],
            'credentials_ready' => $credentialsReady,
            'api_key_mask' => $credential?->api_key_mask,
            'token_mask' => $credential?->token_mask,
            'read_ready' => (bool) $mikro['enabled'] && $credentialsReady && $baseReady,
            'write_ready' => $writeReady,
            'last_health_check_status' => $mikro['last_health_check_status'],
            'last_error_redacted' => $mikro['last_error_redacted'],
            'blocking_reasons' => array_values(array_unique($blocking)),
        ];
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

    /**
     * @param  array<string, mixed>  $settings
     */
    private function persistSettingsToPage(PageConfig $page, array $settings): void
    {
        $layout = is_array($page->layout_json) ? $page->layout_json : [];
        Arr::set($layout, self::ROOT_KEY, $settings);
        $page->forceFill(['layout_json' => $layout])->save();
    }

    private function acquireLifecycleLock(): Lock
    {
        $lock = Cache::lock(TechnicalServiceManualE2ERunContext::LIFECYCLE_LOCK_KEY, 15);
        if (! $lock->get()) {
            throw new ConflictHttpException('Manual E2E yaşam döngüsü başka bir işlem tarafından güncelleniyor.');
        }

        return $lock;
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
            $lock->release();
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

    private function maskPhone(?string $phone): ?string
    {
        $normalized = $this->normalizePhone($phone);

        if ($normalized === '') {
            return null;
        }

        return substr($normalized, 0, 4).'****'.substr($normalized, -3);
    }
}
