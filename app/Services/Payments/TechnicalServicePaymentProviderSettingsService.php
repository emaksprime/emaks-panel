<?php

namespace App\Services\Payments;

use App\Models\PageConfig;
use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use App\Support\PartnerPortalPublicUrl;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

class TechnicalServicePaymentProviderSettingsService
{
    public const PAGE_CODE = 'technical_service_admin';

    public const REAL_PROVIDER_ENABLED_KEY = 'technical_service.payment.real_provider_enabled';

    public const PROVIDER_KEY = 'technical_service.payment.provider';

    public const PROVIDER_MODE_KEY = 'technical_service.payment.provider_mode';

    public const PAYMENT_NOTIFICATION_ENABLED_KEY = 'technical_service.payment.notification.enabled';

    public const PAYMENT_NOTIFICATION_RECIPIENTS_KEY = 'technical_service.payment.notification.recipients';

    public const COMPANY_RECIPIENT_KEY = 'technical_service.payment.company_recipient';

    public const COMPANY_RECIPIENT_ADDRESS_MISSING_MESSAGE = 'Firma tahsilat adresi eksik. Ödeme linki oluşturmak için Teknik Servis Admin > Ödeme/Firma bilgileri alanından firma adresini girin.';

    private const COMPANY_RECIPIENT_FIELDS = [
        'company_title' => 'Ünvan',
        'tax_office' => 'Vergi dairesi',
        'tax_number' => 'VKN',
        'trade_registry_no' => 'Ticaret sicil no',
        'company_address' => 'Firma tahsilat adresi',
        'company_phone' => 'Firma telefonu',
        'company_email' => 'Firma e-posta',
        'iban_try' => 'IBAN TRY',
        'iban_usd' => 'IBAN USD',
    ];

    public const CREDENTIAL_SOURCE_DISABLED = 'disabled';

    public const CREDENTIAL_SOURCE_LARAVEL_ENCRYPTED = 'laravel_encrypted';

    public const CREDENTIAL_SOURCE_N8N_ENV = 'n8n_env';

    public const CREDENTIAL_SOURCE_LARAVEL_ENCRYPTED_PENDING_BRIDGE = 'laravel_encrypted_pending_bridge';

    public const LIVE_SEND_APPROVAL_MESSAGE = 'Canlı Iyzico gönderimi için canlı onayı gerekir.';

    public function __construct(
        private readonly TechnicalServicePaymentProviderCredentialService $credentialService,
        private readonly TechnicalServicePaymentProviderTransportResolver $transportResolver,
        private readonly TechnicalServiceMailTransportSettingsService $mailTransportSettings,
    ) {}

    public function realProviderEnabled(): bool
    {
        return filter_var(
            Arr::get($this->layout(), self::REAL_PROVIDER_ENABLED_KEY, config('payments.real_provider_enabled', false)),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    public function configuredProvider(): string
    {
        $provider = strtolower(trim((string) Arr::get(
            $this->layout(),
            self::PROVIDER_KEY,
            config('payments.provider_name', config('payments.provider', 'iyzico')),
        )));

        return str_starts_with($provider, 'iyzico') ? 'iyzico' : ($provider === 'fake' ? 'fake' : $provider);
    }

    public function effectiveProvider(): string
    {
        return $this->realProviderEnabled() ? 'iyzico' : 'fake';
    }

    public function providerMode(): string
    {
        $mode = strtolower(trim((string) Arr::get(
            $this->layout(),
            self::PROVIDER_MODE_KEY,
            config('payments.gateway.mode', config('payments.environment', 'sandbox')),
        )));

        return $mode === 'live' ? 'live' : 'sandbox';
    }

    public function providerTransport(): string
    {
        return $this->transportResolver->activeTransport();
    }

    public function gatewayUrlConfigured(): bool
    {
        return filled(config('payments.gateway.url'));
    }

    public function gatewayTokenConfigured(): bool
    {
        return filled(config('payments.gateway.token'));
    }

    public function gatewayConfigured(): bool
    {
        return false;
    }

    public function gatewayHealthVerified(): bool
    {
        return false;
    }

    public function gatewayHttpEnabled(): bool
    {
        return false;
    }

    public function gatewayReady(): bool
    {
        return false;
    }

    public function credentialsReady(): bool
    {
        return $this->laravelEncryptedCredentialsReady($this->providerMode());
    }

    public function providerSendReady(?string $mode = null): bool
    {
        $mode = $mode !== null ? $this->normalizeMode($mode) : $this->providerMode();

        return $this->transportResolver->directLaravel()
            && $this->laravelEncryptedCredentialsReady($mode)
            && $this->providerSendEnabled($mode)
            && ($mode === 'sandbox' || $this->liveOperationalReadinessReady());
    }

    public function canEnableRealProvider(): bool
    {
        return $this->canEnableRealProviderForMode($this->providerMode());
    }

    public function providerSendEnabled(?string $mode = null): bool
    {
        $mode = $mode !== null ? $this->normalizeMode($mode) : $this->providerMode();

        return $mode === 'sandbox' || $this->liveSendApproved();
    }

    public function providerReconcileReady(?string $mode = null): bool
    {
        $mode = $mode !== null ? $this->normalizeMode($mode) : $this->providerMode();

        return $this->realProviderEnabled()
            && $this->configuredProvider() === 'iyzico'
            && $this->providerMode() === $mode
            && $this->transportResolver->directLaravel()
            && $this->laravelEncryptedCredentialsReady($mode)
            && $this->providerSendEnabled($mode)
            && ($mode === 'sandbox' || $this->liveOperationalReadinessReady());
    }

    public function providerReconcileDisabledReason(?string $mode = null): string
    {
        $mode = $mode !== null ? $this->normalizeMode($mode) : $this->providerMode();

        if (! $this->realProviderEnabled() || $this->configuredProvider() !== 'iyzico') {
            return $mode === 'live'
                ? 'Live otomatik kontrol kapalı; canlı ödeme aktif edilince açılacak.'
                : 'Sandbox otomatik kontrol için Iyzico gerçek sağlayıcı aktif olmalı.';
        }

        if ($this->providerMode() !== $mode) {
            return $mode === 'live'
                ? 'Live otomatik kontrol kapalı; canlı ödeme modu seçilip onaylanınca açılacak.'
                : 'Sandbox otomatik kontrol yalnızca seçili mod sandbox iken çalışır.';
        }

        if (! $this->transportResolver->directLaravel()) {
            return 'Otomatik kontrol için Iyzico Laravel Direct adaptörü aktif olmalı.';
        }

        if (! $this->laravelEncryptedCredentialsReady($mode)) {
            return $mode === 'live'
                ? 'Live otomatik kontrol için live API bilgileri encrypted olarak kaydedilmeli.'
                : 'Sandbox otomatik kontrol için sandbox API bilgileri encrypted olarak kaydedilmeli.';
        }

        if ($mode === 'live' && ! $this->liveSendApproved()) {
            return 'Live otomatik kontrol kapalı; canlı ödeme onayı verilmeden live API çağrısı yapılmaz.';
        }

        if ($mode === 'live' && ! $this->liveOperationalReadinessReady()) {
            return 'Live otomatik kontrol kapalı; public IP whitelist ve public HTTPS Back URL doğrulanmalı.';
        }

        return 'Otomatik kontrol hazır.';
    }

    public function liveSendApproved(): bool
    {
        return (bool) config('payments.iyzico.live_send_approved', false);
    }

    public function paymentNotificationEnabled(): bool
    {
        return filter_var(
            Arr::get($this->layout(), self::PAYMENT_NOTIFICATION_ENABLED_KEY, false),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    /**
     * @return array<int, string>
     */
    public function paymentNotificationRecipients(): array
    {
        return $this->parseRecipients(Arr::get($this->layout(), self::PAYMENT_NOTIFICATION_RECIPIENTS_KEY, ''));
    }

    /**
     * @return array<string, mixed>
     */
    public function companyRecipientPayload(): array
    {
        $stored = Arr::get($this->layout(), self::COMPANY_RECIPIENT_KEY, []);
        $stored = is_array($stored) ? $stored : [];
        $values = [];

        foreach (self::COMPANY_RECIPIENT_FIELDS as $field => $label) {
            $values[$field] = $this->nullableTrimmedString($stored[$field] ?? null);
        }

        $missingFields = [];
        if ($values['company_address'] === null) {
            $missingFields[] = 'company_address';
        }

        return $values + [
            'ready' => $missingFields === [],
            'missing_fields' => $missingFields,
            'status_label' => $missingFields === [] ? 'Hazır' : 'Eksik bilgi',
            'message' => $missingFields === []
                ? 'Firma tahsilat adresi ödeme linki oluşturma akışı için hazır.'
                : self::COMPANY_RECIPIENT_ADDRESS_MISSING_MESSAGE,
            'helper_text' => 'Firma tahsilat adresi, ödeme alan/EMAKS Prime firma adresidir. Müşteri servis adresinden farklıdır.',
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public function companyRecipientForPayment(): array
    {
        $payload = $this->companyRecipientPayload();

        return collect(array_keys(self::COMPANY_RECIPIENT_FIELDS))
            ->mapWithKeys(fn (string $field): array => [$field => $payload[$field] ?? null])
            ->all();
    }

    public function companyRecipientAddressReady(): bool
    {
        return (bool) ($this->companyRecipientPayload()['ready'] ?? false);
    }

    public function assertCompanyRecipientAddressReady(): void
    {
        if (! $this->companyRecipientAddressReady()) {
            throw ValidationException::withMessages([
                'company_address' => self::COMPANY_RECIPIENT_ADDRESS_MISSING_MESSAGE,
            ]);
        }
    }

    public function credentialSource(?string $mode = null): string
    {
        return $this->laravelEncryptedCredentialsReady($mode ?? $this->providerMode())
            ? self::CREDENTIAL_SOURCE_LARAVEL_ENCRYPTED
            : self::CREDENTIAL_SOURCE_DISABLED;
    }

    public function laravelEncryptedCredentialsReady(?string $mode = null): bool
    {
        return $this->credentialService->credentialsReady($mode ?? $this->providerMode());
    }

    public function n8nEnvCredentialsReady(): bool
    {
        return false;
    }

    public function credentialsReadyForSelectedSource(?string $mode = null): bool
    {
        return $this->laravelEncryptedCredentialsReady($mode ?? $this->providerMode());
    }

    public function canEnableRealProviderForMode(?string $mode = null): bool
    {
        $mode = $mode !== null ? $this->normalizeMode($mode) : $this->providerMode();

        return $this->transportResolver->directLaravel()
            && $this->credentialsReadyForSelectedSource($mode)
            && $this->providerSendReady($mode);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $realEnabled = $this->realProviderEnabled();
        $providerMode = $this->providerMode();
        $canEnable = $this->canEnableRealProvider();
        $disabledReason = $canEnable ? null : $this->disabledReasonForMode($providerMode);
        $credentialPayload = $this->credentialService->credentialPayload($providerMode);
        $credentialBridge = $this->credentialBridgePayload($providerMode);
        $readiness = $this->readinessPayload($providerMode, $canEnable, $disabledReason);

        if (($credentialPayload['ready'] ?? false) === true && $providerMode === 'live' && ! $this->liveSendApproved()) {
            $credentialPayload['entry_status'] = 'API bilgileri tanımlı; canlı gönderim onayı bekliyor.';
            $credentialPayload['entry_message'] = 'API bilgileri encrypted saklanır; kayıttan sonra tam değer gösterilmez. Canlı Iyzico gönderimi ayrı onay olmadan açılmaz.';
        } elseif (($credentialPayload['ready'] ?? false) === true) {
            $credentialPayload['entry_status'] = 'API bilgileri tanımlı; Laravel Direct ile sandbox kullanıma hazır.';
            $credentialPayload['entry_message'] = 'API bilgileri encrypted saklanır; kayıttan sonra tam değer gösterilmez. Iyzico imzası Laravel içinde oluşturulur.';
        }

        return [
            'keys' => [
                'real_provider_enabled' => self::REAL_PROVIDER_ENABLED_KEY,
                'provider' => self::PROVIDER_KEY,
                'provider_mode' => self::PROVIDER_MODE_KEY,
                'company_recipient' => self::COMPANY_RECIPIENT_KEY,
            ],
            'real_provider_enabled' => $realEnabled,
            'provider' => $realEnabled ? 'iyzico' : 'fake',
            'configured_provider' => $this->configuredProvider(),
            'provider_mode' => $providerMode,
            'provider_transport' => $this->providerTransport(),
            'provider_transport_label' => $this->transportResolver->activeTransportLabel(),
            'live_send_approved' => $this->liveSendApproved(),
            'selected_provider_mode_label' => $this->selectedProviderModeLabel($providerMode),
            'effective_mode' => $this->effectiveModeKey($realEnabled, $providerMode),
            'effective_mode_label' => $this->effectiveModeLabel($realEnabled, $providerMode),
            'fake_active' => ! $realEnabled,
            'iyzico_urls' => $this->iyzicoUrlsPayload(),
            'ip_whitelist' => $this->ipWhitelistPayload(),
            'back_url' => $this->backUrlPayload(),
            'gateway' => $this->legacyGatewayPayload($providerMode),
            'legacy_n8n_adapter' => [
                'active' => false,
                'status' => 'archived',
                'message' => 'n8n ödeme adaptörü devre dışı; ödeme sağlayıcı artık Laravel Direct ile çalışır.',
            ],
            'credentials' => $credentialPayload,
            'credential_bridge' => $credentialBridge,
            'readiness' => $readiness,
            'automatic_reconcile' => $this->automaticReconcilePayload(),
            'sandbox_activation_checklist' => $this->sandboxActivationChecklist($credentialBridge, $readiness),
            'can_enable_real_provider' => $canEnable,
            'disabled_reason' => $disabledReason,
            'health_status' => $this->healthStatus(),
            'payment_notification' => $this->paymentNotificationPayload(),
            'company_recipient' => $this->companyRecipientPayload(),
            'secret_source' => $credentialBridge['source'],
            'warning' => 'Gerçek ödeme aktifken fake ödeme kullanılmaz.',
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function update(array $values): array
    {
        return app(TechnicalServiceMessagingSettingsService::class)
            ->withScopedLocalUatConfigurationMutationLock('payment', function () use ($values): array {
                app(TechnicalServiceMessagingSettingsService::class)
                    ->assertScopedLocalUatPaymentSettingsMutationAllowed($values);
                $layout = $this->layout();

                $nextRealProviderEnabled = array_key_exists('real_provider_enabled', $values)
                    ? (bool) $values['real_provider_enabled']
                    : $this->realProviderEnabled();
                $nextProvider = $nextRealProviderEnabled ? 'iyzico' : 'fake';
                $nextMode = array_key_exists('provider_mode', $values)
                    ? $this->normalizeMode($values['provider_mode'])
                    : $this->providerMode();
                $notificationRecipients = array_key_exists('payment_notification_recipients', $values)
                    ? $this->parseRecipients($values['payment_notification_recipients'])
                    : $this->paymentNotificationRecipients();

                if ($nextRealProviderEnabled && ! $this->canEnableRealProviderForMode($nextMode)) {
                    throw ValidationException::withMessages([
                        'real_provider_enabled' => $this->disabledReasonForMode($nextMode),
                    ]);
                }

                Arr::set($layout, self::REAL_PROVIDER_ENABLED_KEY, $nextRealProviderEnabled);
                Arr::set($layout, self::PROVIDER_KEY, $nextProvider);
                Arr::set($layout, self::PROVIDER_MODE_KEY, $nextMode);

                if (array_key_exists('payment_notification_enabled', $values)) {
                    Arr::set($layout, self::PAYMENT_NOTIFICATION_ENABLED_KEY, (bool) $values['payment_notification_enabled']);
                }

                if (array_key_exists('payment_notification_recipients', $values)) {
                    Arr::set($layout, self::PAYMENT_NOTIFICATION_RECIPIENTS_KEY, implode(',', $notificationRecipients));
                }

                if (array_key_exists('company_recipient', $values)) {
                    $companyRecipient = is_array($values['company_recipient']) ? $values['company_recipient'] : [];
                    $nextCompanyRecipient = [];

                    foreach (array_keys(self::COMPANY_RECIPIENT_FIELDS) as $field) {
                        $nextCompanyRecipient[$field] = $this->nullableTrimmedString($companyRecipient[$field] ?? null);
                    }

                    Arr::set($layout, self::COMPANY_RECIPIENT_KEY, $nextCompanyRecipient);
                }

                $config = PageConfig::query()->firstOrCreate(
                    ['page_code' => self::PAGE_CODE],
                    ['layout_json' => []],
                );
                $config->forceFill(['layout_json' => $layout])->save();

                return $this->payload();
            });
    }

    /**
     * @return array<string, mixed>
     */
    public function healthCheckPayload(): array
    {
        return [
            'settings' => $this->payload(),
            'health_check' => $this->healthStatus(),
        ];
    }

    /**
     * @return array{status:string,label:string,message:string}
     */
    private function healthStatus(): array
    {
        if (! $this->credentialsReady()) {
            return [
                'status' => 'missing_credentials',
                'label' => 'API bilgileri tanımsız',
                'message' => 'Seçili Iyzico modu için API bilgileri tanımlı değil.',
            ];
        }

        if (! $this->companyRecipientAddressReady()) {
            return [
                'status' => 'company_recipient_address_missing',
                'label' => 'Firma adresi eksik',
                'message' => self::COMPANY_RECIPIENT_ADDRESS_MISSING_MESSAGE,
            ];
        }

        if (! $this->ipWhitelistConfirmed()) {
            return [
                'status' => 'ip_whitelist_unverified',
                'label' => 'IP doğrulaması bekliyor',
                'message' => 'Iyzico IP whitelist doğrulaması tamamlanmadan provider hazırlığı Hazır sayılamaz.',
            ];
        }

        if (! $this->backUrlReadyForLive()) {
            return [
                'status' => 'back_url_unverified',
                'label' => 'Back URL eksik',
                'message' => 'Public HTTPS Back URL ve callback route doğrulanmadan provider hazırlığı Hazır sayılamaz.',
            ];
        }

        if (! $this->connectionVerificationReady($this->providerMode())) {
            return [
                'status' => 'connection_unverified',
                'label' => 'Bağlantı doğrulanmadı',
                'message' => 'Seçili Iyzico modu için başarılı bağlantı doğrulaması kaydı bulunmuyor.',
            ];
        }

        if ($this->providerMode() === 'live' && ! $this->liveSendApproved()) {
            return [
                'status' => 'live_send_approval_missing',
                'label' => 'Canlı onay bekliyor',
                'message' => self::LIVE_SEND_APPROVAL_MESSAGE,
            ];
        }

        if ($this->providerMode() === 'live' && ! $this->liveOperationalReadinessReady()) {
            return [
                'status' => 'live_readiness_missing',
                'label' => 'Canlı hazırlık eksik',
                'message' => $this->liveOperationalReadinessMessage(),
            ];
        }

        return [
            'status' => 'ready',
            'label' => 'Hazır',
            'message' => 'Laravel Direct ödeme adaptörü ve seçili mod API bilgileri hazır.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentNotificationPayload(): array
    {
        $recipients = $this->paymentNotificationRecipients();
        $mailSettings = $this->mailTransportSettings->payload();
        $smtpReady = (bool) ($mailSettings['payment_notification_ready'] ?? false);
        $enabled = $this->paymentNotificationEnabled();
        $recipientsReady = $recipients !== [];

        return [
            'enabled' => $enabled,
            'recipients' => $recipients,
            'recipients_text' => implode(',', $recipients),
            'smtp_ready' => $smtpReady,
            'ready' => $enabled && $recipientsReady && $smtpReady,
            'status_label' => $enabled
                ? (! $smtpReady ? 'SMTP eksik' : ($recipientsReady ? 'Aktif' : 'Alıcı bekliyor'))
                : 'Kapalı',
            'helper_text' => 'Dekont numarası sağlayıcı tarafından dönmüyorsa provider ödeme referansı gönderilir.',
        ];
    }

    private function disabledReasonForMode(string $mode): string
    {
        $mode = $this->normalizeMode($mode);

        if (! $this->transportResolver->directLaravel()) {
            return 'Iyzico ödeme adaptörü Laravel Direct olarak yapılandırılmalı.';
        }

        if (! $this->laravelEncryptedCredentialsReady($mode)) {
            return TechnicalServicePaymentProviderModeResolver::NOT_READY_MESSAGE;
        }

        if ($mode === 'live' && ! $this->liveSendApproved()) {
            return self::LIVE_SEND_APPROVAL_MESSAGE;
        }

        if ($mode === 'live' && ! $this->ipWhitelistConfirmed()) {
            return 'Canlı Iyzico için uygulama sunucusu public IP adresi Iyzico panelinde onaylanmalı.';
        }

        if ($mode === 'live' && ! $this->backUrlReadyForLive()) {
            return 'Canlı Back URL / callback doğrulanmadı; canlı açılıştan önce live reconcile readiness doğrulanmalı.';
        }

        return TechnicalServicePaymentProviderModeResolver::NOT_READY_MESSAGE;
    }

    private function effectiveModeKey(bool $realEnabled, string $providerMode): string
    {
        return $realEnabled ? 'iyzico_'.$providerMode : 'fake';
    }

    private function effectiveModeLabel(bool $realEnabled, string $providerMode): string
    {
        if (! $realEnabled) {
            return 'Fake / Yerel';
        }

        return $this->selectedProviderModeLabel($providerMode);
    }

    private function selectedProviderModeLabel(string $providerMode): string
    {
        return $providerMode === 'live' ? 'Iyzico Live' : 'Iyzico Sandbox';
    }

    private function normalizeMode(mixed $mode): string
    {
        return strtolower(trim((string) $mode)) === 'live' ? 'live' : 'sandbox';
    }

    /**
     * @return array<string, mixed>
     */
    private function credentialBridgePayload(string $providerMode): array
    {
        $laravelEncryptedReady = $this->laravelEncryptedCredentialsReady($providerMode);
        $source = $this->credentialSource($providerMode);

        return [
            'source' => $source,
            'source_label' => $this->credentialSourceLabel($source),
            'laravel_encrypted_credentials_saved' => $laravelEncryptedReady,
            'n8n_env_credentials_ready' => false,
            'credentials_ready_for_selected_source' => $laravelEncryptedReady,
            'safe_for_provider_send' => $laravelEncryptedReady && $this->providerSendReady($providerMode),
            'status' => $laravelEncryptedReady ? 'ready' : 'missing',
            'message' => $laravelEncryptedReady
                ? 'API bilgileri Laravel’de encrypted olarak kayıtlı; imza ve Iyzico çağrısı Laravel Direct içinde yapılır.'
                : 'Seçili Iyzico modu için encrypted API bilgisi tanımlanmalı.',
            'normal_item_json_secret_allowed' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readinessPayload(string $providerMode, bool $canEnable, ?string $disabledReason): array
    {
        $operationalBlockers = $this->operationalReadinessBlockers($providerMode);

        return [
            'effective_mode' => $this->effectiveModeKey($this->realProviderEnabled(), $providerMode),
            'selected_provider' => $this->configuredProvider(),
            'selected_mode' => $providerMode,
            'real_provider_enabled' => $this->realProviderEnabled(),
            'provider_transport' => $this->providerTransport(),
            'credential_source' => $this->credentialSource($providerMode),
            'credentials_saved' => $this->laravelEncryptedCredentialsReady($providerMode),
            'credentials_ready_for_selected_source' => $this->credentialsReadyForSelectedSource($providerMode),
            'gateway_url_configured' => false,
            'gateway_token_configured' => false,
            'gateway_ready' => false,
            'provider_send_enabled' => $this->providerSendEnabled($providerMode),
            'provider_send_ready' => $this->providerSendReady($providerMode),
            'company_recipient_address_ready' => $this->companyRecipientAddressReady(),
            'company_recipient_status' => $this->companyRecipientPayload()['status_label'],
            'live_send_approved' => $this->liveSendApproved(),
            'sandbox_base_url' => DirectIyzicoLinkProviderClient::SANDBOX_BASE_URL,
            'live_base_url' => DirectIyzicoLinkProviderClient::LIVE_BASE_URL,
            'ip_whitelist_confirmed' => $this->ipWhitelistConfirmed(),
            'ip_whitelist_source' => 'direct_laravel_app_server',
            'back_url_ready' => $this->backUrlReadyForLive(),
            'callback_route_ready' => $this->callbackRouteExists(),
            'live_readiness_ready' => $this->liveOperationalReadinessReady(),
            'connection_verification_ready' => $this->connectionVerificationReady($providerMode),
            'operational_readiness_ready' => $operationalBlockers === [],
            'operational_blockers' => $operationalBlockers,
            'can_enable_real_provider' => $canEnable,
            'disabled_reason' => $disabledReason,
            'next_required_action' => $canEnable ? 'Sandbox gerçek ödeme sağlayıcısı yerel kontrollü test için hazır.' : $this->nextRequiredAction($providerMode),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function operationalReadinessBlockers(string $providerMode): array
    {
        return array_values(array_filter([
            $this->companyRecipientAddressReady() ? null : self::COMPANY_RECIPIENT_ADDRESS_MISSING_MESSAGE,
            $this->ipWhitelistConfirmed() ? null : 'Iyzico IP whitelist doğrulaması bekliyor.',
            $this->backUrlReadyForLive() ? null : 'Public HTTPS Back URL / callback doğrulaması bekliyor.',
            $this->connectionVerificationReady($providerMode) ? null : 'Iyzico bağlantı doğrulaması bekliyor.',
        ]));
    }

    private function connectionVerificationReady(string $providerMode): bool
    {
        $credential = $this->credentialService->credentialPayload($this->normalizeMode($providerMode));
        $status = strtolower(trim((string) ($credential['last_verification_status'] ?? '')));

        return ($credential['last_verified_at'] ?? null) !== null
            && in_array($status, ['passed', 'success', 'verified'], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function automaticReconcilePayload(): array
    {
        $sandboxReady = $this->providerReconcileReady('sandbox');
        $liveReady = $this->providerReconcileReady('live');

        return [
            'sandbox' => [
                'ready' => $sandboxReady,
                'label' => $sandboxReady ? 'Aktif / hazır' : 'Kapalı / eksik',
                'message' => $sandboxReady
                    ? 'Sandbox bekleyen Iyzico ödemeleri otomatik veya manuel sağlayıcı senkronizasyonuyla kontrol edilir.'
                    : $this->providerReconcileDisabledReason('sandbox'),
            ],
            'live' => [
                'ready' => $liveReady,
                'label' => $liveReady ? 'Aktif / hazır' : 'Kapalı / canlı ödeme aktif edilince açılacak',
                'message' => $liveReady
                    ? 'Live bekleyen Iyzico ödemeleri row mode ve readiness doğrulamasıyla kontrol edilir.'
                    : $this->providerReconcileDisabledReason('live'),
            ],
            'back_url_status' => $this->backUrlReadyForLive() ? 'ready' : 'pending_or_unverified',
            'callback_verified' => false,
            'accepted_fallback' => 'Callback doğrulanmadı; güvenli yol admin/manual sync + scheduled reconcile.',
            'live_release_requirement' => 'Canlı ödeme açılmadan önce live reconcile readiness, live credentials, live approval, IP whitelist ve public HTTPS Back URL doğrulanmalı.',
        ];
    }

    /**
     * @param  array<string, mixed>  $credentialBridge
     * @param  array<string, mixed>  $readiness
     * @return array<int, array{key:string,label:string,ready:bool}>
     */
    private function sandboxActivationChecklist(array $credentialBridge, array $readiness): array
    {
        return [
            [
                'key' => 'transport',
                'label' => 'Ödeme adaptörü Laravel Direct',
                'ready' => $this->transportResolver->directLaravel(),
            ],
            [
                'key' => 'credentials',
                'label' => 'Seçili mod API bilgileri encrypted olarak kayıtlı',
                'ready' => (bool) ($credentialBridge['credentials_ready_for_selected_source'] ?? false),
            ],
            [
                'key' => 'provider_send',
                'label' => 'Sandbox gönderimi seçili mod için uygun',
                'ready' => (bool) ($readiness['provider_send_ready'] ?? false),
            ],
            [
                'key' => 'company_recipient_address',
                'label' => 'Firma tahsilat adresi admin ayarlarında tanımlı',
                'ready' => $this->companyRecipientAddressReady(),
            ],
            [
                'key' => 'live_guard',
                'label' => 'Canlı mod ayrı onay olmadan kapalı',
                'ready' => $this->providerMode() !== 'live' || $this->liveSendApproved(),
            ],
            [
                'key' => 'ip_whitelist',
                'label' => 'Canlı için uygulama sunucusu public IP adresi Iyzico panelinde doğrulanmalı',
                'ready' => $this->providerMode() !== 'live' || $this->ipWhitelistConfirmed(),
            ],
            [
                'key' => 'back_url',
                'label' => 'Canlı için public HTTPS Back URL / callback route hazır olmalı',
                'ready' => $this->providerMode() !== 'live' || $this->backUrlReadyForLive(),
            ],
        ];
    }

    private function credentialSourceLabel(string $source): string
    {
        return match ($source) {
            self::CREDENTIAL_SOURCE_LARAVEL_ENCRYPTED => 'Laravel encrypted credentials',
            self::CREDENTIAL_SOURCE_N8N_ENV => 'n8n/Coolify Secrets',
            self::CREDENTIAL_SOURCE_LARAVEL_ENCRYPTED_PENDING_BRIDGE => 'Laravel encrypted credentials - bridge bekliyor',
            default => 'Not configured',
        };
    }

    private function nextRequiredAction(string $mode): string
    {
        $mode = $this->normalizeMode($mode);

        if (! $this->transportResolver->directLaravel()) {
            return 'Provider transport Laravel Direct olmalı.';
        }

        if (! $this->laravelEncryptedCredentialsReady($mode)) {
            return 'Seçili Iyzico modu için API bilgileri admin panelinden encrypted olarak kaydedilmeli.';
        }

        if ($mode === 'live' && ! $this->liveSendApproved()) {
            return self::LIVE_SEND_APPROVAL_MESSAGE;
        }

        if ($mode === 'live' && ! $this->ipWhitelistConfirmed()) {
            return 'Canlı Iyzico için uygulama sunucusu public IP adresi Iyzico panelinde onaylanmalı.';
        }

        if ($mode === 'live' && ! $this->backUrlReadyForLive()) {
            return 'Canlı Back URL / callback doğrulanmadı; canlı açılıştan önce live reconcile readiness doğrulanmalı.';
        }

        return 'Sandbox gerçek gönderim testi için Burhan açık onayı gerekir.';
    }

    /**
     * @return array<string, mixed>
     */
    private function iyzicoUrlsPayload(): array
    {
        return [
            'sandbox_base_url' => DirectIyzicoLinkProviderClient::SANDBOX_BASE_URL,
            'live_base_url' => DirectIyzicoLinkProviderClient::LIVE_BASE_URL,
            'authorization_scheme' => 'IYZWSv2',
            'endpoints' => [
                'create_link' => 'POST /v2/iyzilink/products',
                'update_link' => 'PUT /v2/iyzilink/products/{token}',
                'get_link' => 'GET /v2/iyzilink/products/{token}',
                'list_links' => 'GET /v2/iyzilink/products',
                'cancel_link' => 'PATCH /v2/iyzilink/products/{token}/status/PASSIVE',
                'delete_link' => 'DELETE /v2/iyzilink/products/{token}',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ipWhitelistPayload(): array
    {
        $ip = trim((string) config('payments.iyzico.outbound_ip', ''));
        $confirmed = $this->ipWhitelistConfirmed();

        return [
            'source' => 'direct_laravel_app_server',
            'source_label' => 'Laravel uygulama sunucusu',
            'status' => $confirmed ? 'confirmed' : ($ip !== '' ? 'detected_manual_confirmation_required' : 'manual_required'),
            'label' => $confirmed ? 'Iyzico IP whitelist onaylı' : 'Manuel doğrulama gerekli',
            'outbound_ip_value' => $ip !== '' ? $ip : null,
            'ready' => $confirmed,
            'manual_check_command' => 'curl -4s https://api.ipify.org',
            'message' => 'Iyzico panelinde API çağrısını yapan Laravel uygulama sunucusunun public IP adresi tanımlı olmalı.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function backUrlPayload(): array
    {
        $baseUrl = $this->paymentPublicBaseUrl();
        $publicHttpsReady = $baseUrl !== null
            && str_starts_with($baseUrl, 'https://')
            && ! PartnerPortalPublicUrl::isLocalUrl($baseUrl);
        $paymentReturnRouteExists = Route::has('mount-payment.show');
        $callbackRouteExists = $this->callbackRouteExists();
        $paymentReturnUrl = $baseUrl !== null && $paymentReturnRouteExists
            ? rtrim($baseUrl, '/').'/mount-payment/{provider_reference}'
            : null;
        $callbackUrl = $baseUrl !== null && $callbackRouteExists
            ? rtrim($baseUrl, '/').'/mount-payment/iyzico/callback'
            : null;

        return [
            'status' => $this->backUrlReadyForLive() ? 'ready' : 'missing_callback_route',
            'label' => $this->backUrlReadyForLive() ? 'Back URL hazır' : 'Back URL / callback eksik',
            'public_base_url' => $baseUrl,
            'public_https_ready' => $publicHttpsReady,
            'payment_return_route_exists' => $paymentReturnRouteExists,
            'payment_return_url' => $paymentReturnUrl,
            'callback_url' => $callbackUrl,
            'global_back_url' => $callbackUrl,
            'callback_route_exists' => $callbackRouteExists,
            'callback_route_name' => $callbackRouteExists ? $this->callbackRouteName() : null,
            'identification_rule' => 'Global Back URL ödeme kaydını sadece provider token, provider_reference veya conversationId=payment:{id} dönerse eşleştirir.',
            'ready' => $this->backUrlReadyForLive(),
            'message' => $this->backUrlReadyForLive()
                ? 'Public HTTPS global Back URL ve callback route hazır. Ödeme paid sayılması yine provider sync ile doğrulanır.'
                : 'Back URL / callback doğrulanmadı; ödeme durumu admin/manual sync ve scheduled reconcile ile doğrulanır.',
        ];
    }

    private function ipWhitelistConfirmed(): bool
    {
        return (bool) config('payments.iyzico.ip_whitelist_confirmed', false);
    }

    private function paymentPublicBaseUrl(): ?string
    {
        $configuredBaseUrl = PartnerPortalPublicUrl::normalizeBaseUrl((string) (
            config('services.public_urls.payment_base_url')
            ?: config('services.public_urls.app_url')
        ));

        if ($configuredBaseUrl !== null) {
            return $configuredBaseUrl;
        }

        $requestBaseUrl = PartnerPortalPublicUrl::localRequestBaseUrl();

        if ($requestBaseUrl !== null) {
            return $requestBaseUrl;
        }

        return PartnerPortalPublicUrl::normalizeBaseUrl((string) config('app.url'));
    }

    private function backUrlReadyForLive(): bool
    {
        $baseUrl = $this->paymentPublicBaseUrl();

        return $baseUrl !== null
            && str_starts_with($baseUrl, 'https://')
            && ! PartnerPortalPublicUrl::isLocalUrl($baseUrl)
            && $this->callbackRouteExists();
    }

    private function callbackRouteExists(): bool
    {
        return $this->callbackRouteName() !== null;
    }

    private function callbackRouteName(): ?string
    {
        foreach ([
            'api.technical-service.payment-provider.callback',
            'api.technical-service.payments.callback',
            'mount-payment.provider.callback',
            'mount-payment.callback',
        ] as $routeName) {
            if (Route::has($routeName)) {
                return $routeName;
            }
        }

        return null;
    }

    private function liveOperationalReadinessReady(): bool
    {
        return $this->ipWhitelistConfirmed() && $this->backUrlReadyForLive();
    }

    private function liveOperationalReadinessMessage(): string
    {
        if (! $this->ipWhitelistConfirmed()) {
            return 'Canlı Iyzico için uygulama sunucusu public IP adresi Iyzico panelinde onaylanmalı.';
        }

        return 'Canlı Back URL / callback doğrulanmadı; canlı açılıştan önce live reconcile readiness doğrulanmalı.';
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyGatewayPayload(string $providerMode): array
    {
        return [
            'url_configured' => false,
            'token_configured' => false,
            'health_verified' => false,
            'http_enabled' => false,
            'provider_send_enabled' => $this->providerSendEnabled($providerMode),
            'provider_send_ready' => $this->providerSendReady($providerMode),
            'ready' => false,
            'mode' => $providerMode,
            'webhook_path' => 'n8n-payment-adapter-disabled',
        ];
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

    private function nullableTrimmedString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array<int, string>
     */
    private function parseRecipients(mixed $value): array
    {
        $items = is_array($value)
            ? $value
            : preg_split('/[\s,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);

        $recipients = collect($items ?: [])
            ->map(fn (mixed $item): string => strtolower(trim((string) $item)))
            ->filter()
            ->unique()
            ->values();

        $invalid = $recipients
            ->reject(fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->values();

        if ($invalid->isNotEmpty()) {
            throw ValidationException::withMessages([
                'payment_notification_recipients' => 'Geçerli ödeme bildirimi e-posta adresi girilmeli.',
            ]);
        }

        return $recipients->all();
    }
}
