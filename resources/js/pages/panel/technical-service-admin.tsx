import { Head } from '@inertiajs/react'
import { useState } from 'react'
import Heading from '@/components/heading'
import { TechnicalServicePageLinks } from '@/components/technical-service/TechnicalServicePageLinks'

const adminItems = [
  ['Ekran yetkileri', 'Teknik servis ekranları ayrı panel resource kodlarıyla Admin > Kullanıcı Yönetimi üzerinden atanır.'],
  ['Aksiyon yetkileri', 'Talep yönetimi, hakediş ödeme ve teknisyen yönetimi ayrı API resource kontrolleriyle korunur.'],
  ['Mikro sorguları', 'Seri no ve garanti sorguları technical_service_serial_query yetkisine bağlıdır.'],
]

type QrPublicFlowSettings = {
  pre_form_payment_for_mount_excluded_enabled: boolean
  key: string
  label: string
  ops_detail_visibility: {
    show_mount_excluded_approval_block: boolean
    show_payment_mount_control_block: boolean
    show_address_control_block: boolean
    keys?: Record<string, string>
  }
}

type PaymentProviderSettings = {
  real_provider_enabled: boolean
  provider: 'fake' | 'iyzico' | string
  configured_provider: string
  provider_mode: 'sandbox' | 'live'
  provider_transport: string
  provider_transport_label: string
  live_send_approved: boolean
  selected_provider_mode_label: string
  effective_mode: string
  effective_mode_label: string
  fake_active: boolean
  iyzico_urls: {
    sandbox_base_url: string
    live_base_url: string
    authorization_scheme: string
    endpoints: Record<string, string>
  }
  ip_whitelist: {
    source: string
    source_label: string
    status: string
    label: string
    outbound_ip_value: string | null
    ready: boolean
    manual_check_command: string
    message: string
  }
  back_url: {
    status: string
    label: string
    public_base_url: string | null
    public_https_ready: boolean
    payment_return_route_exists: boolean
    payment_return_url: string | null
    callback_url: string | null
    global_back_url: string | null
    callback_route_exists: boolean
    callback_route_name: string | null
    identification_rule: string
    ready: boolean
    message: string
  }
  gateway: {
    url_configured: boolean
    token_configured: boolean
    health_verified: boolean
    http_enabled: boolean
    provider_send_enabled: boolean
    provider_send_ready: boolean
    ready: boolean
    mode: 'sandbox' | 'live' | string
    webhook_path: string
  }
  credentials: {
    ready: boolean
    source: string
    source_label: string
    api_key_status: string
    secret_key_status: string
    masked_api_key: string | null
    masked_secret_key: string | null
    entry_supported: boolean
    entry_status: string
    entry_message: string
    last_updated_at: string | null
    last_verified_at: string | null
    last_verification_status: string | null
    last_verification_message: string | null
  }
  credential_bridge: {
    source: string
    source_label: string
    laravel_encrypted_credentials_saved: boolean
    n8n_env_credentials_ready: boolean
    credentials_ready_for_selected_source: boolean
    safe_for_provider_send: boolean
    status: string
    message: string
    normal_item_json_secret_allowed: boolean
  }
  legacy_n8n_adapter: {
    active: boolean
    status: string
    message: string
  }
  readiness: {
    effective_mode: string
    selected_provider: string
    selected_mode: 'sandbox' | 'live' | string
    real_provider_enabled: boolean
    provider_transport: string
    credential_source: string
    credentials_saved: boolean
    credentials_ready_for_selected_source: boolean
    gateway_url_configured: boolean
    gateway_token_configured: boolean
    gateway_ready: boolean
    provider_send_enabled: boolean
    provider_send_ready: boolean
    live_send_approved: boolean
    sandbox_base_url: string
    live_base_url: string
    ip_whitelist_confirmed: boolean
    ip_whitelist_source: string
    back_url_ready: boolean
    callback_route_ready: boolean
    live_readiness_ready: boolean
    can_enable_real_provider: boolean
    disabled_reason: string | null
    next_required_action: string
  }
  automatic_reconcile: {
    sandbox: {
      ready: boolean
      label: string
      message: string
    }
    live: {
      ready: boolean
      label: string
      message: string
    }
    back_url_status: string
    callback_verified: boolean
    accepted_fallback: string
    live_release_requirement: string
  }
  sandbox_activation_checklist: Array<{
    key: string
    label: string
    ready: boolean
  }>
  can_enable_real_provider: boolean
  disabled_reason: string | null
  health_status: {
    status: string
    label: string
    message: string
  }
  payment_notification: {
    enabled: boolean
    recipients: string[]
    recipients_text: string
    smtp_ready: boolean
    ready: boolean
    status_label: string
    helper_text: string
  }
  secret_source: string
  warning: string
}

type MessagingMessageType = {
  key: string
  label: string
  recipient_role: string
  description: string
  future: boolean
  enabled: boolean
  real_send_allowed: boolean
  test_send_allowed: boolean
  template_key: string | null
  notes: string | null
}

type MessagingProvider = {
  key: string
  label: string
  channel: string
  description: string
  status_label: string
  enabled: boolean
  real_send_allowed: boolean
  test_send_allowed: boolean
  contract_confirmed: boolean
  current_practical: boolean
  active: boolean
  default: boolean
  fallback: boolean
  real_ready: boolean
  ready_reason: string | null
  capabilities: Record<string, boolean>
  notes: string | null
}

type MessagingNacSmsSettings = {
  enabled: boolean
  scheme: 'http' | 'https'
  host: string | null
  port: number
  base_url: string
  sender: string | null
  title: string | null
  gateway_uuid: string | null
  encoding: number
  commercial: boolean
  skip_ahs_query: boolean
  recipient_type: number
  validity: number
  report_push_url: string | null
  use_shared_test_phone: boolean
  test_phone: string | null
  test_phone_masked: string | null
  real_send_allowed: boolean
  credentials_ready: boolean
  username_mask: string | null
  password_mask: string | null
  test_ready: boolean
  live_ready: boolean
  queue_ready: boolean
  blocking_reasons: string[]
}

type MessagingMikroApiSettings = {
  enabled: boolean
  base_url: string | null
  api_version: string | null
  application_code: string | null
  application_name: string | null
  company_code: string | null
  branch_code: string | null
  workstation_code: string | null
  fiscal_year: string | null
  timeout_seconds: number
  license_status: string | null
  app_customer_license_status: string | null
  read_sync_enabled: boolean
  write_enabled: boolean
  write_approval_required: boolean
  operation_catalog_status: string | null
  credentials_ready: boolean
  api_key_mask: string | null
  token_mask: string | null
  read_ready: boolean
  write_ready: boolean
  blocking_reasons: string[]
}

type MessagingAdminSection = {
  key: string
  label: string
  ready: boolean
  summary: string
}

type MessagingSettings = {
  global: {
    messaging_enabled: boolean
    real_send_enabled: boolean
    test_mode_enabled: boolean
    test_phone: string | null
    test_phone_masked: string | null
    queue_paused: boolean
    provider_key: string
    active_provider: string
    default_provider: string
    fallback_provider: string
    provider_priority: string[]
    send_delay_seconds: number
    duplicate_cooldown_minutes: number
    hourly_limit: number
    daily_limit: number
    max_auto_retries: number
    allow_browser_smoke_send: boolean
    allow_test_fixture_send: boolean
  }
  readiness: {
    messaging_enabled: boolean
    real_send_enabled: boolean
    test_mode_enabled: boolean
    test_phone_configured: boolean
    provider_webhook_configured: boolean
    provider_secret_configured: boolean
    active_provider: string
    active_provider_label: string
    default_provider: string
    fallback_provider: string
    provider_priority: string[]
    active_provider_enabled: boolean
    active_provider_supports_text: boolean
    active_provider_contract_confirmed: boolean
    active_provider_credentials_ready: boolean
    active_provider_real_ready: boolean
    queue_ready: boolean
    can_send_test: boolean
    can_send_real: boolean
    effective_mode: string
    disabled_reasons: string[]
    real_allowed_message_types: string[]
    test_allowed_message_types: string[]
  }
  provider: {
    active_provider: string
    default_provider: string
    fallback_provider: string
    provider_priority: string[]
    webhook_url_configured: boolean
    provider_secret_configured: boolean
    webhook_url_value: string | null
    secret_value: null
    webhook_path: string
    router: string
  }
  providers: MessagingProvider[]
  capability_map: Record<string, Record<string, boolean>>
  nac_sms: MessagingNacSmsSettings
  mikro_api: MessagingMikroApiSettings
  admin_sections: MessagingAdminSection[]
  message_types: MessagingMessageType[]
  warnings: string[]
  helper_texts: {
    secrets: string
    queue: string
    test_phone: string
  }
}

type MessagingTypeInputs = Record<
  string,
  {
    enabled: boolean
    real_send_allowed: boolean
    test_send_allowed: boolean
    template_key: string
    notes: string
  }
>

type MailTransportSettings = {
  outgoing: {
    enabled: boolean
    mailer: string
    host: string | null
    port: number | null
    encryption: 'tls' | 'ssl' | 'none'
    username_mask: string | null
    password_mask: string | null
    from_address: string | null
    from_name: string | null
    ready: boolean
    status_label: string
    readiness_message: string
    last_tested_at: string | null
    last_test_status: string | null
    last_test_message: string | null
  }
  incoming: {
    enabled: boolean
    protocol: 'imap' | 'pop3'
    host: string | null
    port: number | null
    encryption: 'tls' | 'ssl' | 'none'
    username_mask: string | null
    password_mask: string | null
    mailbox: string | null
    ready: boolean
    status_label: string
    readiness_message: string
    last_tested_at: string | null
    last_test_status: string | null
    last_test_message: string | null
  }
  payment_notification_ready: boolean
  helper_texts: {
    outgoing: string
    incoming: string
    secrets: string
  }
}

function messageTypeInputsFromSettings(settings: MessagingSettings): MessagingTypeInputs {
  return Object.fromEntries(
    settings.message_types.map((item) => [
      item.key,
      {
        enabled: item.enabled,
        real_send_allowed: item.real_send_allowed,
        test_send_allowed: item.test_send_allowed,
        template_key: item.template_key ?? '',
        notes: item.notes ?? '',
      },
    ]),
  )
}

function nacSmsInputsFromSettings(settings: MessagingSettings) {
  return {
    enabled: settings.nac_sms.enabled,
    scheme: settings.nac_sms.scheme,
    host: settings.nac_sms.host ?? '',
    port: String(settings.nac_sms.port),
    sender: settings.nac_sms.sender ?? '',
    title: settings.nac_sms.title ?? '',
    gateway_uuid: settings.nac_sms.gateway_uuid ?? '',
    encoding: String(settings.nac_sms.encoding),
    commercial: settings.nac_sms.commercial,
    skip_ahs_query: settings.nac_sms.skip_ahs_query,
    recipient_type: String(settings.nac_sms.recipient_type),
    validity: String(settings.nac_sms.validity),
    report_push_url: settings.nac_sms.report_push_url ?? '',
    use_shared_test_phone: settings.nac_sms.use_shared_test_phone,
    test_phone: settings.nac_sms.test_phone ?? '',
    real_send_allowed: settings.nac_sms.real_send_allowed,
  }
}

function mikroApiInputsFromSettings(settings: MessagingSettings) {
  return {
    enabled: settings.mikro_api.enabled,
    base_url: settings.mikro_api.base_url ?? '',
    api_version: settings.mikro_api.api_version ?? 'V17',
    application_code: settings.mikro_api.application_code ?? '',
    application_name: settings.mikro_api.application_name ?? '',
    company_code: settings.mikro_api.company_code ?? '',
    branch_code: settings.mikro_api.branch_code ?? '',
    workstation_code: settings.mikro_api.workstation_code ?? '',
    fiscal_year: settings.mikro_api.fiscal_year ?? '',
    timeout_seconds: String(settings.mikro_api.timeout_seconds),
    license_status: settings.mikro_api.license_status ?? 'unknown',
    app_customer_license_status: settings.mikro_api.app_customer_license_status ?? 'unknown',
    read_sync_enabled: settings.mikro_api.read_sync_enabled,
    write_enabled: settings.mikro_api.write_enabled,
    write_approval_required: settings.mikro_api.write_approval_required,
    operation_catalog_status: settings.mikro_api.operation_catalog_status ?? 'missing',
  }
}

function csrfToken(): string {
  if (typeof document === 'undefined') {
    return ''
  }

  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
}

async function errorMessageFromResponse(response: Response, fallback: string): Promise<string> {
  try {
    const payload = await response.json()
    const firstError = payload?.errors && typeof payload.errors === 'object'
      ? Object.values(payload.errors).flat().find(Boolean)
      : null

    return String(firstError || payload?.message || payload?.error || fallback)
  } catch {
    return fallback
  }
}

export default function TechnicalServiceAdmin({
  qrPublicFlowSettings,
  messagingSettings,
  paymentProviderSettings,
  mailTransportSettings,
}: {
  qrPublicFlowSettings: QrPublicFlowSettings
  messagingSettings: MessagingSettings
  paymentProviderSettings: PaymentProviderSettings
  mailTransportSettings: MailTransportSettings
}) {
  const [preFormPaymentEnabled, setPreFormPaymentEnabled] = useState(
    qrPublicFlowSettings.pre_form_payment_for_mount_excluded_enabled,
  )
  const [messaging, setMessaging] = useState(messagingSettings)
  const [paymentSettings, setPaymentSettings] = useState(paymentProviderSettings)
  const [mailSettings, setMailSettings] = useState(mailTransportSettings)
  const [opsDetailVisibility, setOpsDetailVisibility] = useState({
    show_mount_excluded_approval_block: Boolean(qrPublicFlowSettings.ops_detail_visibility?.show_mount_excluded_approval_block),
    show_payment_mount_control_block: Boolean(qrPublicFlowSettings.ops_detail_visibility?.show_payment_mount_control_block),
    show_address_control_block: Boolean(qrPublicFlowSettings.ops_detail_visibility?.show_address_control_block),
  })
  const [saving, setSaving] = useState(false)
  const [paymentSaving, setPaymentSaving] = useState(false)
  const [credentialSaving, setCredentialSaving] = useState(false)
  const [healthChecking, setHealthChecking] = useState(false)
  const [mailSaving, setMailSaving] = useState(false)
  const [mailTesting, setMailTesting] = useState(false)
  const [messagingSaving, setMessagingSaving] = useState(false)
  const [messagingPhoneChecking, setMessagingPhoneChecking] = useState(false)
  const [integrationCredentialSaving, setIntegrationCredentialSaving] = useState(false)
  const [message, setMessage] = useState('')
  const [paymentMessage, setPaymentMessage] = useState('')
  const [mailMessage, setMailMessage] = useState('')
  const [messagingMessage, setMessagingMessage] = useState('')
  const [credentialInputs, setCredentialInputs] = useState({ api_key: '', secret_key: '' })
  const [messagingInputs, setMessagingInputs] = useState({
    messaging_enabled: messagingSettings.global.messaging_enabled,
    real_send_enabled: messagingSettings.global.real_send_enabled,
    test_mode_enabled: messagingSettings.global.test_mode_enabled,
    test_phone: messagingSettings.global.test_phone ?? '',
    queue_paused: messagingSettings.global.queue_paused,
    active_provider: messagingSettings.global.active_provider,
    default_provider: messagingSettings.global.default_provider,
    fallback_provider: messagingSettings.global.fallback_provider,
    send_delay_seconds: String(messagingSettings.global.send_delay_seconds),
    duplicate_cooldown_minutes: String(messagingSettings.global.duplicate_cooldown_minutes),
    hourly_limit: String(messagingSettings.global.hourly_limit),
    daily_limit: String(messagingSettings.global.daily_limit),
    max_auto_retries: String(messagingSettings.global.max_auto_retries),
    allow_browser_smoke_send: messagingSettings.global.allow_browser_smoke_send,
    allow_test_fixture_send: messagingSettings.global.allow_test_fixture_send,
  })
  const [nacSmsInputs, setNacSmsInputs] = useState(() => nacSmsInputsFromSettings(messagingSettings))
  const [nacSmsCredentialInputs, setNacSmsCredentialInputs] = useState({ username: '', password: '' })
  const [mikroApiInputs, setMikroApiInputs] = useState(() => mikroApiInputsFromSettings(messagingSettings))
  const [mikroApiCredentialInputs, setMikroApiCredentialInputs] = useState({ api_key: '', token: '' })
  const [messageTypeInputs, setMessageTypeInputs] = useState(() => messageTypeInputsFromSettings(messagingSettings))
  const [notificationInputs, setNotificationInputs] = useState({
    enabled: paymentProviderSettings.payment_notification.enabled,
    recipients: paymentProviderSettings.payment_notification.recipients_text ?? '',
  })
  const [outgoingMailInputs, setOutgoingMailInputs] = useState({
    enabled: mailTransportSettings.outgoing.enabled,
    host: mailTransportSettings.outgoing.host ?? '',
    port: mailTransportSettings.outgoing.port ? String(mailTransportSettings.outgoing.port) : '',
    encryption: mailTransportSettings.outgoing.encryption,
    username: '',
    password: '',
    from_address: mailTransportSettings.outgoing.from_address ?? '',
    from_name: mailTransportSettings.outgoing.from_name ?? '',
    test_recipient: '',
  })
  const [incomingMailInputs, setIncomingMailInputs] = useState({
    enabled: mailTransportSettings.incoming.enabled,
    protocol: mailTransportSettings.incoming.protocol,
    host: mailTransportSettings.incoming.host ?? '',
    port: mailTransportSettings.incoming.port ? String(mailTransportSettings.incoming.port) : '',
    encryption: mailTransportSettings.incoming.encryption,
    username: '',
    password: '',
    mailbox: mailTransportSettings.incoming.mailbox ?? 'INBOX',
  })

  const applyMessagingSettings = (nextSettings: MessagingSettings) => {
    setMessaging(nextSettings)
    setMessagingInputs({
      messaging_enabled: nextSettings.global.messaging_enabled,
      real_send_enabled: nextSettings.global.real_send_enabled,
      test_mode_enabled: nextSettings.global.test_mode_enabled,
      test_phone: nextSettings.global.test_phone ?? '',
      queue_paused: nextSettings.global.queue_paused,
      active_provider: nextSettings.global.active_provider,
      default_provider: nextSettings.global.default_provider,
      fallback_provider: nextSettings.global.fallback_provider,
      send_delay_seconds: String(nextSettings.global.send_delay_seconds),
      duplicate_cooldown_minutes: String(nextSettings.global.duplicate_cooldown_minutes),
      hourly_limit: String(nextSettings.global.hourly_limit),
      daily_limit: String(nextSettings.global.daily_limit),
      max_auto_retries: String(nextSettings.global.max_auto_retries),
      allow_browser_smoke_send: nextSettings.global.allow_browser_smoke_send,
      allow_test_fixture_send: nextSettings.global.allow_test_fixture_send,
    })
    setNacSmsInputs(nacSmsInputsFromSettings(nextSettings))
    setMikroApiInputs(mikroApiInputsFromSettings(nextSettings))
    setMessageTypeInputs(messageTypeInputsFromSettings(nextSettings))
  }

  const saveMessagingSettings = async () => {
    setMessagingSaving(true)
    setMessagingMessage('')

    try {
      const response = await fetch('/api/technical-service/messaging-settings', {
        method: 'PATCH',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify({
          messaging_enabled: messagingInputs.messaging_enabled,
          real_send_enabled: messagingInputs.real_send_enabled,
          test_mode_enabled: messagingInputs.test_mode_enabled,
          test_phone: messagingInputs.test_phone,
          queue_paused: messagingInputs.queue_paused,
          provider_key: messagingInputs.active_provider,
          active_provider: messagingInputs.active_provider,
          default_provider: messagingInputs.default_provider,
          fallback_provider: messagingInputs.fallback_provider,
          send_delay_seconds: Number(messagingInputs.send_delay_seconds),
          duplicate_cooldown_minutes: Number(messagingInputs.duplicate_cooldown_minutes),
          hourly_limit: Number(messagingInputs.hourly_limit),
          daily_limit: Number(messagingInputs.daily_limit),
          max_auto_retries: Number(messagingInputs.max_auto_retries),
          allow_browser_smoke_send: messagingInputs.allow_browser_smoke_send,
          allow_test_fixture_send: messagingInputs.allow_test_fixture_send,
          shared_test_phone: messagingInputs.test_phone,
          nac_sms: {
            enabled: nacSmsInputs.enabled,
            scheme: nacSmsInputs.scheme,
            host: nacSmsInputs.host,
            port: Number(nacSmsInputs.port),
            sender: nacSmsInputs.sender,
            title: nacSmsInputs.title,
            gateway_uuid: nacSmsInputs.gateway_uuid,
            encoding: Number(nacSmsInputs.encoding),
            commercial: nacSmsInputs.commercial,
            skip_ahs_query: nacSmsInputs.skip_ahs_query,
            recipient_type: Number(nacSmsInputs.recipient_type),
            validity: Number(nacSmsInputs.validity),
            report_push_url: nacSmsInputs.report_push_url,
            use_shared_test_phone: nacSmsInputs.use_shared_test_phone,
            test_phone: nacSmsInputs.test_phone,
            real_send_allowed: nacSmsInputs.real_send_allowed,
          },
          mikro_api: {
            enabled: mikroApiInputs.enabled,
            base_url: mikroApiInputs.base_url,
            api_version: mikroApiInputs.api_version,
            application_code: mikroApiInputs.application_code,
            application_name: mikroApiInputs.application_name,
            company_code: mikroApiInputs.company_code,
            branch_code: mikroApiInputs.branch_code,
            workstation_code: mikroApiInputs.workstation_code,
            fiscal_year: mikroApiInputs.fiscal_year,
            timeout_seconds: Number(mikroApiInputs.timeout_seconds),
            license_status: mikroApiInputs.license_status,
            app_customer_license_status: mikroApiInputs.app_customer_license_status,
            read_sync_enabled: mikroApiInputs.read_sync_enabled,
            write_enabled: mikroApiInputs.write_enabled,
            write_approval_required: mikroApiInputs.write_approval_required,
            operation_catalog_status: mikroApiInputs.operation_catalog_status,
          },
          message_types: messageTypeInputs,
        }),
      })

      if (!response.ok) {
        setMessagingMessage(await errorMessageFromResponse(response, 'Mesajlaşma sağlayıcı ayarları kaydedilemedi.'))

        return
      }

      const responsePayload = await response.json()
      applyMessagingSettings(responsePayload.messaging_settings)
      setMessagingMessage('Mesajlaşma sağlayıcı ayarları kaydedildi. Gerçek mesaj gönderilmedi.')
    } catch {
      setMessagingMessage('Mesajlaşma sağlayıcı ayarları kaydedilemedi. Bağlantı durumunu kontrol edin.')
    } finally {
      setMessagingSaving(false)
    }
  }

  const resetMessagingSettings = async () => {
    if (typeof window !== 'undefined' && !window.confirm('Mesajlaşma sağlayıcı ayarları güvenli varsayılanlara dönsün mü?')) {
      return
    }

    setMessagingSaving(true)
    setMessagingMessage('')

    try {
      const response = await fetch('/api/technical-service/messaging-settings/reset', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
      })

      if (!response.ok) {
        setMessagingMessage(await errorMessageFromResponse(response, 'Mesajlaşma sağlayıcı ayarları sıfırlanamadı.'))

        return
      }

      const responsePayload = await response.json()
      applyMessagingSettings(responsePayload.messaging_settings)
      setMessagingMessage(responsePayload.message ?? 'Mesajlaşma sağlayıcı ayarları güvenli varsayılanlara döndürüldü.')
    } catch {
      setMessagingMessage('Mesajlaşma sağlayıcı ayarları sıfırlanamadı.')
    } finally {
      setMessagingSaving(false)
    }
  }

  const validateMessagingPhone = async () => {
    setMessagingPhoneChecking(true)
    setMessagingMessage('')

    try {
      const response = await fetch('/api/technical-service/messaging-settings/validate-phone', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify({ test_phone: messagingInputs.test_phone }),
      })

      if (!response.ok) {
        setMessagingMessage(await errorMessageFromResponse(response, 'Test telefonu geçerli değil.'))

        return
      }

      const responsePayload = await response.json()
      setMessagingInputs((current) => ({
        ...current,
        test_phone: responsePayload.phone?.normalized ?? current.test_phone,
      }))
      setMessagingMessage(responsePayload.message ?? 'Test telefon numarası geçerli.')
    } catch {
      setMessagingMessage('Test telefonu doğrulanamadı.')
    } finally {
      setMessagingPhoneChecking(false)
    }
  }

  const saveNacSmsCredentials = async () => {
    setIntegrationCredentialSaving(true)
    setMessagingMessage('')

    try {
      const response = await fetch('/api/technical-service/messaging-settings/nac-sms/credentials', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify(nacSmsCredentialInputs),
      })

      if (!response.ok) {
        setMessagingMessage(await errorMessageFromResponse(response, 'NAC SMS bilgileri kaydedilemedi.'))

        return
      }

      const responsePayload = await response.json()
      applyMessagingSettings(responsePayload.messaging_settings)
      setNacSmsCredentialInputs({ username: '', password: '' })
      setMessagingMessage(responsePayload.message ?? 'NAC SMS bilgileri encrypted olarak kaydedildi.')
    } catch {
      setMessagingMessage('NAC SMS bilgileri kaydedilemedi. Bağlantı durumunu kontrol edin.')
    } finally {
      setIntegrationCredentialSaving(false)
    }
  }

  const clearNacSmsCredentials = async () => {
    if (typeof window !== 'undefined' && !window.confirm('NAC SMS credential bilgileri temizlensin mi?')) {
      return
    }

    setIntegrationCredentialSaving(true)
    setMessagingMessage('')

    try {
      const response = await fetch('/api/technical-service/messaging-settings/nac-sms/credentials/clear', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
      })

      if (!response.ok) {
        setMessagingMessage(await errorMessageFromResponse(response, 'NAC SMS bilgileri temizlenemedi.'))

        return
      }

      const responsePayload = await response.json()
      applyMessagingSettings(responsePayload.messaging_settings)
      setNacSmsCredentialInputs({ username: '', password: '' })
      setMessagingMessage(responsePayload.message ?? 'NAC SMS credential bilgileri temizlendi.')
    } catch {
      setMessagingMessage('NAC SMS bilgileri temizlenemedi.')
    } finally {
      setIntegrationCredentialSaving(false)
    }
  }

  const saveMikroApiCredentials = async () => {
    setIntegrationCredentialSaving(true)
    setMessagingMessage('')

    try {
      const response = await fetch('/api/technical-service/messaging-settings/mikro-api/credentials', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify(mikroApiCredentialInputs),
      })

      if (!response.ok) {
        setMessagingMessage(await errorMessageFromResponse(response, 'Mikro API bilgileri kaydedilemedi.'))

        return
      }

      const responsePayload = await response.json()
      applyMessagingSettings(responsePayload.messaging_settings)
      setMikroApiCredentialInputs({ api_key: '', token: '' })
      setMessagingMessage(responsePayload.message ?? 'Mikro API bilgileri encrypted olarak kaydedildi.')
    } catch {
      setMessagingMessage('Mikro API bilgileri kaydedilemedi. Bağlantı durumunu kontrol edin.')
    } finally {
      setIntegrationCredentialSaving(false)
    }
  }

  const clearMikroApiCredentials = async () => {
    if (typeof window !== 'undefined' && !window.confirm('Mikro API credential bilgileri temizlensin mi?')) {
      return
    }

    setIntegrationCredentialSaving(true)
    setMessagingMessage('')

    try {
      const response = await fetch('/api/technical-service/messaging-settings/mikro-api/credentials/clear', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
      })

      if (!response.ok) {
        setMessagingMessage(await errorMessageFromResponse(response, 'Mikro API bilgileri temizlenemedi.'))

        return
      }

      const responsePayload = await response.json()
      applyMessagingSettings(responsePayload.messaging_settings)
      setMikroApiCredentialInputs({ api_key: '', token: '' })
      setMessagingMessage(responsePayload.message ?? 'Mikro API credential bilgileri temizlendi.')
    } catch {
      setMessagingMessage('Mikro API bilgileri temizlenemedi.')
    } finally {
      setIntegrationCredentialSaving(false)
    }
  }

  const updateSettings = async (payload: {
    pre_form_payment_for_mount_excluded_enabled?: boolean
    ops_detail_visibility?: Partial<typeof opsDetailVisibility>
  }, rollback: () => void, successMessage: string) => {
    setSaving(true)
    setMessage('')

    try {
      const response = await fetch('/api/technical-service/qr-flow-settings', {
        method: 'PATCH',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
      })

      if (!response.ok) {
        setMessage(await errorMessageFromResponse(response, 'Ayar kaydedilemedi. Yetki veya oturum durumunu kontrol edin.'))
        rollback()

        return
      }

      const responsePayload = await response.json()
      setPreFormPaymentEnabled(Boolean(responsePayload.settings?.pre_form_payment_for_mount_excluded_enabled))
      setOpsDetailVisibility({
        show_mount_excluded_approval_block: Boolean(responsePayload.settings?.ops_detail_visibility?.show_mount_excluded_approval_block),
        show_payment_mount_control_block: Boolean(responsePayload.settings?.ops_detail_visibility?.show_payment_mount_control_block),
        show_address_control_block: Boolean(responsePayload.settings?.ops_detail_visibility?.show_address_control_block),
      })
      setMessage(successMessage)
    } catch {
      setMessage('Ayar kaydedilemedi. Bağlantı durumunu kontrol edin.')
      rollback()
    } finally {
      setSaving(false)
    }
  }

  const updatePaymentSettings = async (
    payload: {
      real_provider_enabled?: boolean
      provider_mode?: 'sandbox' | 'live'
      payment_notification_enabled?: boolean
      payment_notification_recipients?: string
    },
    rollback: () => void,
    successMessage: string,
  ) => {
    setPaymentSaving(true)
    setPaymentMessage('')

    try {
      const response = await fetch('/api/technical-service/payment-provider-settings', {
        method: 'PATCH',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
      })

      if (!response.ok) {
        setPaymentMessage(await errorMessageFromResponse(response, 'Ödeme sağlayıcı ayarı kaydedilemedi.'))
        rollback()

        return
      }

      const responsePayload = await response.json()
      setPaymentSettings(responsePayload.settings)
      setPaymentMessage(successMessage)
    } catch {
      setPaymentMessage('Ödeme sağlayıcı ayarı kaydedilemedi. Bağlantı durumunu kontrol edin.')
      rollback()
    } finally {
      setPaymentSaving(false)
    }
  }

  const updateRealPaymentToggle = async (enabled: boolean) => {
    const previous = paymentSettings

    setPaymentSettings({ ...paymentSettings, real_provider_enabled: enabled })
    await updatePaymentSettings(
      { real_provider_enabled: enabled },
      () => setPaymentSettings(previous),
      enabled ? 'Gerçek ödeme ayarı etkinleştirildi.' : 'Gerçek ödeme kapatıldı; fake/local ödeme aktif.',
    )
  }

  const updateProviderMode = async (mode: 'sandbox' | 'live') => {
    const previous = paymentSettings

    setPaymentSettings({
      ...paymentSettings,
      provider_mode: mode,
      selected_provider_mode_label: mode === 'live' ? 'Iyzico Live' : 'Iyzico Sandbox',
    })
    await updatePaymentSettings(
      { provider_mode: mode },
      () => setPaymentSettings(previous),
      mode === 'live' ? 'Iyzico live modu kaydedildi.' : 'Iyzico sandbox modu kaydedildi.',
    )
  }

  const resetToSafePaymentMode = async () => {
    const previous = paymentSettings

    setPaymentSettings({
      ...paymentSettings,
      real_provider_enabled: false,
      provider: 'fake',
      configured_provider: 'fake',
      provider_mode: 'sandbox',
      selected_provider_mode_label: 'Iyzico Sandbox',
      effective_mode: 'fake',
      effective_mode_label: 'Fake / Yerel',
      fake_active: true,
    })
    await updatePaymentSettings(
      { real_provider_enabled: false, provider_mode: 'sandbox' },
      () => setPaymentSettings(previous),
      'Fake/Yerel moda dönüldü. Iyzico hazırlık modu sandbox olarak kaydedildi.',
    )
  }

  const saveCredentials = async () => {
    setCredentialSaving(true)
    setPaymentMessage('')

    try {
      const response = await fetch('/api/technical-service/payment-provider-settings/credentials', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify({
          mode: paymentSettings.provider_mode,
          api_key: credentialInputs.api_key,
          secret_key: credentialInputs.secret_key,
        }),
      })

      if (!response.ok) {
        setPaymentMessage(await errorMessageFromResponse(response, 'API bilgileri kaydedilemedi.'))

        return
      }

      const responsePayload = await response.json()
      setPaymentSettings(responsePayload.settings)
      setCredentialInputs({ api_key: '', secret_key: '' })
      setPaymentMessage('API bilgileri encrypted olarak kaydedildi. Tam değerler tekrar gösterilmez.')
    } catch {
      setPaymentMessage('API bilgileri kaydedilemedi. Bağlantı durumunu kontrol edin.')
    } finally {
      setCredentialSaving(false)
    }
  }

  const clearCredentials = async () => {
    if (typeof window !== 'undefined' && !window.confirm('Seçili modun API bilgileri silinsin mi?')) {
      return
    }

    setCredentialSaving(true)
    setPaymentMessage('')

    try {
      const response = await fetch('/api/technical-service/payment-provider-settings/credentials/clear', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify({
          mode: paymentSettings.provider_mode,
        }),
      })

      if (!response.ok) {
        setPaymentMessage(await errorMessageFromResponse(response, 'API bilgileri temizlenemedi.'))

        return
      }

      const responsePayload = await response.json()
      setPaymentSettings(responsePayload.settings)
      setCredentialInputs({ api_key: '', secret_key: '' })
      setPaymentMessage('API bilgileri temizlendi.')
    } catch {
      setPaymentMessage('API bilgileri temizlenemedi. Bağlantı durumunu kontrol edin.')
    } finally {
      setCredentialSaving(false)
    }
  }

  const runPaymentHealthCheck = async () => {
    setHealthChecking(true)
    setPaymentMessage('')

    try {
      const response = await fetch('/api/technical-service/payment-provider-settings/health-check', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
      })

      if (!response.ok) {
        setPaymentMessage(await errorMessageFromResponse(response, 'Bağlantı durumu okunamadı.'))

        return
      }

      const responsePayload = await response.json()
      setPaymentSettings(responsePayload.settings)
      setPaymentMessage(responsePayload.health_check?.message ?? 'Bağlantı durumu okundu.')
    } catch {
      setPaymentMessage('Bağlantı durumu okunamadı.')
    } finally {
      setHealthChecking(false)
    }
  }

  const savePaymentNotificationSettings = async () => {
    const previous = paymentSettings

    await updatePaymentSettings(
      {
        payment_notification_enabled: notificationInputs.enabled,
        payment_notification_recipients: notificationInputs.recipients,
      },
      () => setPaymentSettings(previous),
      'Ödeme bildirimi mail ayarı kaydedildi.',
    )
  }

  const applyMailResponse = (payload: unknown) => {
    const nextSettings = (payload as { mail_transport_settings?: MailTransportSettings }).mail_transport_settings

    if (!nextSettings) {
      return
    }

    setMailSettings(nextSettings)
    setPaymentSettings((current) => ({
      ...current,
      payment_notification: {
        ...current.payment_notification,
        smtp_ready: nextSettings.payment_notification_ready,
        ready: current.payment_notification.enabled
          && current.payment_notification.recipients.length > 0
          && nextSettings.payment_notification_ready,
        status_label: !current.payment_notification.enabled
          ? 'Kapalı'
          : (!nextSettings.payment_notification_ready
              ? 'SMTP eksik'
              : (current.payment_notification.recipients.length > 0 ? 'Aktif' : 'Alıcı bekliyor')),
      },
    }))
    setOutgoingMailInputs((current) => ({
      ...current,
      enabled: nextSettings.outgoing.enabled,
      host: nextSettings.outgoing.host ?? '',
      port: nextSettings.outgoing.port ? String(nextSettings.outgoing.port) : '',
      encryption: nextSettings.outgoing.encryption,
      username: '',
      password: '',
      from_address: nextSettings.outgoing.from_address ?? '',
      from_name: nextSettings.outgoing.from_name ?? '',
    }))
    setIncomingMailInputs((current) => ({
      ...current,
      enabled: nextSettings.incoming.enabled,
      protocol: nextSettings.incoming.protocol,
      host: nextSettings.incoming.host ?? '',
      port: nextSettings.incoming.port ? String(nextSettings.incoming.port) : '',
      encryption: nextSettings.incoming.encryption,
      username: '',
      password: '',
      mailbox: nextSettings.incoming.mailbox ?? 'INBOX',
    }))
  }

  const postMailSettings = async (path: string, payload: Record<string, unknown>, successMessage: string) => {
    setMailSaving(true)
    setMailMessage('')

    try {
      const response = await fetch(path, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
      })

      if (!response.ok) {
        setMailMessage(await errorMessageFromResponse(response, 'Mail ayarı kaydedilemedi.'))

        return
      }

      const responsePayload = await response.json()
      applyMailResponse(responsePayload)
      setMailMessage(successMessage)
    } catch {
      setMailMessage('Mail ayarı kaydedilemedi. Bağlantı durumunu kontrol edin.')
    } finally {
      setMailSaving(false)
    }
  }

  const saveOutgoingMailSettings = async () => postMailSettings('/api/technical-service/mail-transport-settings/outgoing', {
    enabled: outgoingMailInputs.enabled,
    host: outgoingMailInputs.host,
    port: outgoingMailInputs.port,
    encryption: outgoingMailInputs.encryption,
    username: outgoingMailInputs.username,
    password: outgoingMailInputs.password,
    from_address: outgoingMailInputs.from_address,
    from_name: outgoingMailInputs.from_name,
  }, 'SMTP ayarları encrypted olarak kaydedildi. Tam şifre tekrar gösterilmez.')

  const clearOutgoingMailSettings = async () => {
    if (typeof window !== 'undefined' && !window.confirm('SMTP ayarları temizlensin mi?')) {
      return
    }

    await postMailSettings('/api/technical-service/mail-transport-settings/outgoing/clear', {}, 'SMTP ayarları temizlendi.')
  }

  const sendOutgoingTestMail = async () => {
    setMailTesting(true)
    setMailMessage('')

    try {
      const response = await fetch('/api/technical-service/mail-transport-settings/outgoing/test', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify({ recipient: outgoingMailInputs.test_recipient }),
      })
      const responsePayload = await response.json().catch(() => ({}))

      applyMailResponse(responsePayload)

      if (!response.ok) {
        setMailMessage(String(responsePayload.message || 'Test mail gönderilemedi.'))

        return
      }

      setMailMessage(responsePayload.message ?? 'Test mail gönderildi.')
    } catch {
      setMailMessage('Test mail gönderilemedi. Bağlantı durumunu kontrol edin.')
    } finally {
      setMailTesting(false)
    }
  }

  const saveIncomingMailSettings = async () => postMailSettings('/api/technical-service/mail-transport-settings/incoming', {
    enabled: incomingMailInputs.enabled,
    protocol: incomingMailInputs.protocol,
    host: incomingMailInputs.host,
    port: incomingMailInputs.port,
    encryption: incomingMailInputs.encryption,
    username: incomingMailInputs.username,
    password: incomingMailInputs.password,
    mailbox: incomingMailInputs.mailbox,
  }, 'IMAP/POP3 ayarları encrypted olarak kaydedildi. Tam şifre tekrar gösterilmez.')

  const clearIncomingMailSettings = async () => {
    if (typeof window !== 'undefined' && !window.confirm('IMAP/POP3 ayarları temizlensin mi?')) {
      return
    }

    await postMailSettings('/api/technical-service/mail-transport-settings/incoming/clear', {}, 'IMAP/POP3 ayarları temizlendi.')
  }

  const testIncomingMailSettings = async () => {
    setMailTesting(true)
    setMailMessage('')

    try {
      const response = await fetch('/api/technical-service/mail-transport-settings/incoming/test', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
      })
      const responsePayload = await response.json().catch(() => ({}))

      applyMailResponse(responsePayload)
      setMailMessage(responsePayload.message ?? (response.ok ? 'Gelen kutu bağlantı testi tamamlandı.' : 'Gelen kutu bağlantı testi başarısız.'))
    } catch {
      setMailMessage('Gelen kutu bağlantı testi başarısız.')
    } finally {
      setMailTesting(false)
    }
  }

  const updatePreFormPayment = async (enabled: boolean) => updateSettings(
    { pre_form_payment_for_mount_excluded_enabled: enabled },
    () => setPreFormPaymentEnabled(!enabled),
    'QR ödeme yönlendirme ayarı kaydedildi.',
  )

  const updateOpsDetailVisibility = async (key: keyof typeof opsDetailVisibility, enabled: boolean) => {
    const previous = { ...opsDetailVisibility }
    const next = { ...opsDetailVisibility, [key]: enabled }

    setOpsDetailVisibility(next)
    await updateSettings(
      { ops_detail_visibility: next },
      () => setOpsDetailVisibility(previous),
      'OPS detay görünürlük ayarı kaydedildi.',
    )
  }

  const opsDetailToggles: Array<{
    key: keyof typeof opsDetailVisibility
    label: string
    description: string
  }> = [
    {
      key: 'show_mount_excluded_approval_block',
      label: 'Montaj hariç / çoklu ürün onayı bloğunu göster',
      description: 'Kapalıyken eski onay bloğu OPS detayında görünmez; gerekli ödeme bilgisi atama alanında sade kart olarak kalır.',
    },
    {
      key: 'show_payment_mount_control_block',
      label: 'Ödeme / montaj kontrol bloğunu göster',
      description: 'Kapalıyken büyük ödeme kontrol bloğu gizlenir; ödeme linki aksiyonu sade modal üzerinden yürür.',
    },
    {
      key: 'show_address_control_block',
      label: 'Adres kontrol bloğunu göster',
      description: 'Kapalıyken büyük adres kontrol bloğu gizlenir. Müşteri adresi normal müşteri bilgi alanında kalır.',
    },
  ]

  const providerStatusCards = [
    {
      label: 'Adaptör',
      value: paymentSettings.provider_transport_label,
      ok: paymentSettings.provider_transport === 'direct_laravel',
    },
    {
      label: 'Seçili mod',
      value: paymentSettings.selected_provider_mode_label,
      ok: paymentSettings.provider_mode === 'sandbox' || paymentSettings.live_send_approved,
    },
    {
      label: 'API bilgileri',
      value: paymentSettings.credentials.ready ? 'API bilgileri tanımlı' : 'API bilgileri tanımlı değil',
      ok: paymentSettings.credentials.ready,
    },
    {
      label: 'Fake ödeme',
      value: paymentSettings.fake_active ? 'Aktif' : 'Kapalı',
      ok: paymentSettings.fake_active,
    },
    {
      label: 'Hazırlık',
      value: paymentSettings.health_status.label,
      ok: paymentSettings.health_status.status === 'ready',
    },
    {
      label: 'Gerçek gönderim',
      value: paymentSettings.gateway.provider_send_ready ? 'Hazır' : (paymentSettings.gateway.provider_send_enabled ? 'İzin var, hazır değil' : 'Kapalı'),
      ok: paymentSettings.gateway.provider_send_ready,
    },
    {
      label: 'IP whitelist',
      value: paymentSettings.ip_whitelist.label,
      ok: paymentSettings.provider_mode !== 'live' || paymentSettings.ip_whitelist.ready,
    },
    {
      label: 'Back URL',
      value: paymentSettings.back_url.label,
      ok: paymentSettings.provider_mode !== 'live' || paymentSettings.back_url.ready,
    },
  ]
  const providerModeDisabled = paymentSaving

  return (
    <>
      <Head title="Teknik Servis Admin" />

      <div className="mx-auto w-full max-w-[1600px] space-y-6 px-4 py-6 md:px-6 lg:px-12">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
          <Heading
            title="Teknik Servis Admin"
            description="Teknik servis modül yetkileri, yönetim ekranları ve güvenli aksiyon ayrımları."
          />
          <div className="flex flex-wrap gap-2">
            <TechnicalServicePageLinks />
          </div>
        </div>

        <section className="grid gap-4 md:grid-cols-3">
          {adminItems.map(([title, description]) => (
            <article key={title} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
              <p className="text-sm font-semibold text-slate-950">{title}</p>
              <p className="mt-2 text-sm leading-6 text-slate-600">{description}</p>
            </article>
          ))}
        </section>

        <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
            QR müşteri akışı
          </p>
          <div className="mt-3 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div className="max-w-3xl">
              <h2 className="text-lg font-bold text-slate-950">
                {qrPublicFlowSettings.label}
              </h2>
              <p className="mt-2 text-sm leading-6 text-slate-600">
                Kapalıyken müşteri formu montaj dahil / hariç sonucundan bağımsız açılır; ödeme gerekiyorsa OPS daha sonra link oluşturur.
                Açıkken montaj hariç, stok/depo veya ödeme gerektiren QR akışları formdan önce public ödeme sayfasına yönlendirilir.
              </p>
              <p className="mt-2 text-xs font-semibold text-slate-500">
                Ayar anahtarı: {qrPublicFlowSettings.key}
              </p>
              {message ? (
                <p className="mt-3 rounded-lg border border-blue-100 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-800">
                  {message}
                </p>
              ) : null}
            </div>
            <label className="inline-flex shrink-0 cursor-pointer items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-800">
              <input
                type="checkbox"
                checked={preFormPaymentEnabled}
                disabled={saving}
                onChange={(event) => {
                  const enabled = event.target.checked

                  setPreFormPaymentEnabled(enabled)
                  void updatePreFormPayment(enabled)
                }}
                className="h-5 w-5 rounded border-slate-300 text-slate-950 focus:ring-slate-400"
              />
              {preFormPaymentEnabled ? 'Açık' : 'Kapalı'}
            </label>
          </div>
        </section>

        <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div className="max-w-3xl">
              <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                Ödeme sağlayıcı
              </p>
              <h2 className="mt-2 text-lg font-bold text-slate-950">
                {paymentSettings.effective_mode_label}
              </h2>
              <p className="mt-2 text-sm leading-6 text-slate-600">
                {paymentSettings.fake_active
                  ? 'Fake/local ödeme aktif. Iyzico devre dışı.'
                  : 'Iyzico Laravel Direct aktif. Fake ödeme kullanılmaz.'}
              </p>
              <p className="mt-2 text-sm leading-6 text-slate-600">
                Ödeme adaptörü: <span className="font-semibold text-slate-900">{paymentSettings.provider_transport_label}</span>
              </p>
              <p className="mt-2 text-sm leading-6 text-slate-600">
                Planlanan Iyzico modu: <span className="font-semibold text-slate-900">{paymentSettings.selected_provider_mode_label}</span>
                {paymentSettings.fake_active ? ' — Seçilen Iyzico modu sadece hazırlık ayarıdır; gerçek ödeme kapalı.' : null}
              </p>
              <p className="mt-2 text-sm font-semibold text-amber-700">
                {paymentSettings.warning}
              </p>
              <p className="mt-1 text-sm text-slate-600">
                {paymentSettings.credentials.entry_message}
              </p>
              {paymentMessage ? (
                <p className="mt-3 rounded-lg border border-blue-100 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-800">
                  {paymentMessage}
                </p>
              ) : null}
            </div>
            <div className="grid gap-3 sm:grid-cols-2 lg:min-w-[360px]">
              <label className="inline-flex cursor-pointer items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-800">
                <span>Gerçek ödeme alınsın</span>
                <input
                  type="checkbox"
                  checked={paymentSettings.real_provider_enabled}
                  disabled={paymentSaving}
                  onChange={(event) => {
                    void updateRealPaymentToggle(event.target.checked)
                  }}
                  className="h-5 w-5 rounded border-slate-300 text-slate-950 focus:ring-slate-400"
                />
              </label>
              <label className="grid gap-2 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-800">
                <span>Provider modu</span>
                <select
                  value={paymentSettings.provider_mode}
                  disabled={providerModeDisabled}
                  onChange={(event) => {
                    void updateProviderMode(event.target.value === 'live' ? 'live' : 'sandbox')
                  }}
                  className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                >
                  <option value="sandbox">Iyzico Sandbox</option>
                  <option value="live">Iyzico Live</option>
                </select>
              </label>
              <button
                type="button"
                disabled={paymentSaving}
                onClick={() => {
                  void resetToSafePaymentMode()
                }}
                className="rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60 sm:col-span-2"
              >
                Fake/Yerel moda dön
              </button>
            </div>
          </div>

          <div className="mt-5 grid gap-3 md:grid-cols-5">
            {providerStatusCards.map((item) => (
              <div
                key={item.label}
                className={[
                  'rounded-xl border px-4 py-3',
                  item.ok ? 'border-emerald-100 bg-emerald-50 text-emerald-900' : 'border-amber-100 bg-amber-50 text-amber-900',
                ].join(' ')}
              >
                <p className="text-xs font-semibold uppercase tracking-[0.12em] opacity-75">{item.label}</p>
                <p className="mt-1 text-sm font-bold">{item.value}</p>
              </div>
            ))}
          </div>

          <div className="mt-5 grid gap-4 lg:grid-cols-[1.2fr_0.8fr]">
            <div className="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
              <p className="font-semibold text-slate-950">Hazırlık durumu</p>
              <p className="mt-2 leading-6">{paymentSettings.health_status.message}</p>
              {paymentSettings.disabled_reason ? (
                <p className="mt-2 font-semibold text-rose-700">{paymentSettings.disabled_reason}</p>
              ) : null}
              <p className="mt-3 font-semibold text-slate-950">Credential kaynağı</p>
              <p className="mt-1 leading-6">n8n ödeme adaptörü aktif ödeme yolundan çıkarıldı. Iyzico imzası ve HTTP çağrısı Laravel Direct içinde yapılır.</p>
              <p className="mt-1 leading-6">{paymentSettings.credential_bridge.message}</p>
              <p className="mt-1 text-xs font-semibold text-slate-500">
                Kaynak: {paymentSettings.credential_bridge.source_label}
              </p>
              <p className="mt-2 text-sm font-semibold text-amber-700">
                Sonraki aksiyon: {paymentSettings.readiness.next_required_action}
              </p>
              <div className="mt-4 grid gap-3 rounded-lg border border-slate-200 bg-white p-3">
                <div>
                  <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Iyzico API URL</p>
                  <p className="mt-1 break-all text-sm font-semibold text-slate-900">Sandbox: {paymentSettings.iyzico_urls.sandbox_base_url}</p>
                  <p className="mt-1 break-all text-sm font-semibold text-slate-900">Live: {paymentSettings.iyzico_urls.live_base_url}</p>
                  <p className="mt-1 text-xs font-semibold text-slate-500">Authorization: {paymentSettings.iyzico_urls.authorization_scheme}</p>
                </div>
                <div>
                  <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">IP whitelist</p>
                  <p className="mt-1 text-sm font-semibold text-slate-900">{paymentSettings.ip_whitelist.label}</p>
                  <p className="mt-1 leading-6">{paymentSettings.ip_whitelist.message}</p>
                  <p className="mt-1 text-xs font-semibold text-slate-500">Kaynak: {paymentSettings.ip_whitelist.source_label}</p>
                  <p className="mt-1 break-all text-xs font-semibold text-slate-500">Manuel kontrol: {paymentSettings.ip_whitelist.manual_check_command}</p>
                  {paymentSettings.ip_whitelist.outbound_ip_value ? (
                    <p className="mt-1 text-sm font-semibold text-emerald-800">Public IP: {paymentSettings.ip_whitelist.outbound_ip_value}</p>
                  ) : null}
                </div>
                <div>
                  <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Back URL / callback</p>
                  <p className="mt-1 text-sm font-semibold text-slate-900">{paymentSettings.back_url.label}</p>
                  <p className="mt-1 leading-6">{paymentSettings.back_url.message}</p>
                  {paymentSettings.back_url.global_back_url ? (
                    <p className="mt-1 break-all text-xs font-semibold text-slate-500">Iyzico Back URL: {paymentSettings.back_url.global_back_url}</p>
                  ) : null}
                  {paymentSettings.back_url.payment_return_url ? (
                    <p className="mt-1 break-all text-xs font-semibold text-slate-500">Müşteri ödeme URL şablonu: {paymentSettings.back_url.payment_return_url}</p>
                  ) : null}
                  <p className="mt-1 text-xs font-semibold text-slate-500">{paymentSettings.back_url.identification_rule}</p>
                  <p className="mt-1 text-xs font-semibold text-slate-500">
                    Callback route: {paymentSettings.back_url.callback_route_exists ? paymentSettings.back_url.callback_route_name : 'Eksik'}
                  </p>
                </div>
                <div>
                  <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Otomatik ödeme kontrolü</p>
                  <p className="mt-1 text-sm font-semibold text-slate-900">
                    Sandbox otomatik kontrol: {paymentSettings.automatic_reconcile.sandbox.label}
                  </p>
                  <p className="mt-1 text-xs leading-5 text-slate-600">{paymentSettings.automatic_reconcile.sandbox.message}</p>
                  <p className="mt-2 text-sm font-semibold text-slate-900">
                    Live otomatik kontrol: {paymentSettings.automatic_reconcile.live.label}
                  </p>
                  <p className="mt-1 text-xs leading-5 text-slate-600">{paymentSettings.automatic_reconcile.live.message}</p>
                  <p className="mt-2 text-xs font-semibold text-slate-500">
                    Callback verified: {paymentSettings.automatic_reconcile.callback_verified ? 'Evet' : 'Hayır'}
                  </p>
                  <p className="mt-1 text-xs leading-5 text-slate-600">{paymentSettings.automatic_reconcile.accepted_fallback}</p>
                  <p className="mt-1 text-xs font-semibold text-amber-700">{paymentSettings.automatic_reconcile.live_release_requirement}</p>
                </div>
              </div>
              <p className="mt-2 text-xs font-semibold text-slate-500">
                Eski n8n adaptörü: {paymentSettings.legacy_n8n_adapter.message}
              </p>
              <p className="mt-2 text-sm font-semibold text-rose-700">
                {paymentSettings.provider_mode === 'live' && !paymentSettings.live_send_approved
                  ? 'Canlı mod gerçek para hareketi oluşturur. Canlı onay gerektirir.'
                  : 'Sandbox test ortamıdır; gerçek para hareketi oluşturmaz.'}
              </p>
              <div className="mt-4 grid gap-2">
                {paymentSettings.sandbox_activation_checklist.map((item) => (
                  <div
                    key={item.key}
                    className={[
                      'rounded-lg border px-3 py-2 text-xs font-semibold',
                      item.ready ? 'border-emerald-100 bg-emerald-50 text-emerald-900' : 'border-amber-100 bg-amber-50 text-amber-900',
                    ].join(' ')}
                  >
                    {item.ready ? 'Hazır' : 'Bekliyor'} — {item.label}
                  </div>
                ))}
              </div>
            </div>
            <div className="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
              <p className="font-semibold text-slate-950">API bilgileri</p>
              <p className="mt-2">Kaynak: {paymentSettings.credentials.source_label}</p>
              <p className="mt-1">{paymentSettings.credentials.api_key_status}</p>
              <p className="mt-1">{paymentSettings.credentials.secret_key_status}</p>
              <p className="mt-1 font-semibold text-amber-700">{paymentSettings.credentials.entry_status}</p>
              <p className="mt-2 leading-6">{paymentSettings.credentials.entry_message}</p>
              {paymentSettings.credentials.masked_api_key ? (
                <p className="mt-3 rounded-lg border border-emerald-100 bg-emerald-50 px-3 py-2 font-semibold text-emerald-900">
                  API Key: {paymentSettings.credentials.masked_api_key}
                </p>
              ) : null}
              {paymentSettings.credentials.masked_secret_key ? (
                <p className="mt-2 rounded-lg border border-emerald-100 bg-emerald-50 px-3 py-2 font-semibold text-emerald-900">
                  Secret Key: {paymentSettings.credentials.masked_secret_key}
                </p>
              ) : null}
              <div className="mt-4 grid gap-3">
                <label className="grid gap-1 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                  <span>{paymentSettings.selected_provider_mode_label} API Key</span>
                  <input
                    type="password"
                    value={credentialInputs.api_key}
                    onChange={(event) => setCredentialInputs({ ...credentialInputs, api_key: event.target.value })}
                    placeholder="Değiştirmek için yeni değer girin"
                    autoComplete="off"
                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold normal-case tracking-normal text-slate-900 focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                  />
                </label>
                <label className="grid gap-1 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                  <span>{paymentSettings.selected_provider_mode_label} Secret Key</span>
                  <input
                    type="password"
                    value={credentialInputs.secret_key}
                    onChange={(event) => setCredentialInputs({ ...credentialInputs, secret_key: event.target.value })}
                    placeholder="Değiştirmek için yeni değer girin"
                    autoComplete="off"
                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold normal-case tracking-normal text-slate-900 focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                  />
                </label>
                <div className="flex flex-wrap gap-2">
                  <button
                    type="button"
                    disabled={credentialSaving || credentialInputs.api_key.trim() === '' || credentialInputs.secret_key.trim() === ''}
                    onClick={() => {
                      void saveCredentials()
                    }}
                    className="rounded-lg border border-slate-900 bg-slate-950 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                  >
                    {credentialSaving ? 'Kaydediliyor' : 'API bilgilerini kaydet'}
                  </button>
                  <button
                    type="button"
                    disabled={credentialSaving || !paymentSettings.credentials.ready}
                    onClick={() => {
                      void clearCredentials()
                    }}
                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                  >
                    API bilgilerini temizle
                  </button>
                </div>
              </div>
              <button
                type="button"
                disabled={healthChecking}
                onClick={() => {
                  void runPaymentHealthCheck()
                }}
                className="mt-3 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
              >
                {healthChecking ? 'Kontrol ediliyor' : 'Bağlantıyı doğrula'}
              </button>
              <div className="mt-4 rounded-lg border border-slate-200 bg-white p-3">
                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Ödeme bildirimi maili</p>
                <label className="mt-3 flex items-center gap-3 text-sm font-semibold text-slate-800">
                  <input
                    type="checkbox"
                    checked={notificationInputs.enabled}
                    onChange={(event) => setNotificationInputs({ ...notificationInputs, enabled: event.target.checked })}
                    className="h-5 w-5 rounded border-slate-300 text-slate-950 focus:ring-slate-400"
                  />
                  Ödeme bildirimi maili gönderilsin
                </label>
                <label className="mt-3 grid gap-1 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                  <span>Alıcı e-posta adresleri</span>
                  <input
                    type="text"
                    value={notificationInputs.recipients}
                    onChange={(event) => setNotificationInputs({ ...notificationInputs, recipients: event.target.value })}
                    placeholder="payment-audit@example.com"
                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold normal-case tracking-normal text-slate-900 focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                  />
                </label>
                <p className="mt-2 text-xs leading-5 text-slate-600">{paymentSettings.payment_notification.helper_text}</p>
                <p className="mt-1 text-xs font-semibold text-slate-500">Durum: {paymentSettings.payment_notification.status_label}</p>
                <p className="mt-1 text-xs font-semibold text-slate-500">
                  SMTP: {paymentSettings.payment_notification.smtp_ready ? 'Hazır' : 'Eksik'}
                </p>
                <button
                  type="button"
                  disabled={paymentSaving}
                  onClick={() => {
                    void savePaymentNotificationSettings()
                  }}
                  className="mt-3 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                >
                  Mail ayarını kaydet
                </button>
              </div>
            </div>
          </div>
        </section>

        <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <div className="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
            <div className="max-w-4xl">
              <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                Mesajlaşma Sağlayıcı Ayarları
              </p>
              <h2 className="mt-2 text-lg font-bold text-slate-950">
                Gerçek gönderim kapalı, test modu kontrollü.
              </h2>
              <p className="mt-2 text-sm leading-6 text-slate-600">
                Evo WhatsApp mevcut pratik sağlayıcıdır. Voibot ses/mesaj sağlayıcısı sözleşme kesinleşince aynı
                provider altyapısına bağlanacak. Randevu mesajı usta seçildiğinde değil OPS randevu onayında bağlanacak.
              </p>
              {messagingMessage ? (
                <p className="mt-3 rounded-lg border border-blue-100 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-800">
                  {messagingMessage}
                </p>
              ) : null}
            </div>
            <div className="flex flex-wrap gap-2">
              <button
                type="button"
                disabled={messagingSaving}
                onClick={() => {
                  void saveMessagingSettings()
                }}
                className="rounded-lg border border-slate-900 bg-slate-950 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
              >
                {messagingSaving ? 'Kaydediliyor' : 'Kaydet'}
              </button>
              <button
                type="button"
                disabled={messagingSaving}
                onClick={() => {
                  void resetMessagingSettings()
                }}
                className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
              >
                Ayarları varsayılana döndür
              </button>
            </div>
          </div>

          <div className="mt-5 grid gap-3 md:grid-cols-5">
            {[
              { label: 'Etkin mod', value: messaging.readiness.effective_mode, ok: messaging.readiness.can_send_test || messaging.readiness.can_send_real },
              { label: 'Test telefonu', value: messaging.global.test_phone_masked ?? 'Eksik', ok: messaging.readiness.test_phone_configured },
              { label: 'Aktif sağlayıcı', value: messaging.readiness.active_provider_label, ok: messaging.readiness.active_provider_enabled && messaging.readiness.active_provider_supports_text },
              { label: 'Evo webhook', value: messaging.provider.webhook_url_configured ? 'Hazır' : 'Eksik', ok: messaging.provider.webhook_url_configured },
              { label: 'Queue sender', value: messaging.readiness.queue_ready ? 'Hazır' : 'REL-4D bekliyor', ok: messaging.readiness.queue_ready },
            ].map((item) => (
              <div
                key={item.label}
                className={[
                  'rounded-xl border px-4 py-3',
                  item.ok ? 'border-emerald-100 bg-emerald-50 text-emerald-900' : 'border-amber-100 bg-amber-50 text-amber-900',
                ].join(' ')}
              >
                <p className="text-xs font-semibold uppercase tracking-[0.12em] opacity-75">{item.label}</p>
                <p className="mt-1 text-sm font-bold">{item.value}</p>
              </div>
            ))}
          </div>

          <div className="mt-5 rounded-xl border border-slate-200 bg-slate-50 p-4">
            <div className="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
              <div>
                <p className="text-sm font-bold text-slate-950">Provider readiness</p>
                <p className="mt-1 text-sm leading-6 text-slate-600">
                  Message type ayarları provider bağımsız kalır; gönderim REL-4D provider router üzerinden sıraya alınacak.
                </p>
              </div>
              <p className="rounded-lg border border-amber-100 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-900">
                Voibot sözleşme bekliyor
              </p>
            </div>

            <div className="mt-4 grid gap-3 lg:grid-cols-3">
              {[
                ['active_provider', 'Aktif provider'],
                ['default_provider', 'Varsayılan provider'],
                ['fallback_provider', 'Fallback provider'],
              ].map(([key, label]) => (
                <label key={key} className="grid gap-1 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                  <span>{label}</span>
                  <select
                    value={String(messagingInputs[key as keyof typeof messagingInputs])}
                    onChange={(event) => setMessagingInputs({ ...messagingInputs, [key]: event.target.value })}
                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold normal-case tracking-normal text-slate-900 focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                  >
                    {messaging.providers.map((provider) => (
                      <option key={provider.key} value={provider.key}>
                        {provider.label}
                      </option>
                    ))}
                  </select>
                </label>
              ))}
            </div>

            <div className="mt-4 grid gap-3 lg:grid-cols-5">
              {messaging.providers.map((provider) => (
                <article
                  key={provider.key}
                  className={[
                    'rounded-xl border bg-white p-3 text-sm',
                    provider.active ? 'border-slate-900' : 'border-slate-200',
                  ].join(' ')}
                >
                  <div className="flex items-start justify-between gap-2">
                    <div>
                      <p className="font-bold text-slate-950">{provider.label}</p>
                      <p className="mt-1 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{provider.channel}</p>
                    </div>
                    <span className={[
                      'rounded-lg border px-2 py-1 text-xs font-bold',
                      provider.contract_confirmed && provider.enabled ? 'border-emerald-100 bg-emerald-50 text-emerald-900' : 'border-amber-100 bg-amber-50 text-amber-900',
                    ].join(' ')}
                    >
                      {provider.status_label}
                    </span>
                  </div>
                  <p className="mt-2 text-xs leading-5 text-slate-600">{provider.description}</p>
                  {provider.ready_reason ? (
                    <p className="mt-2 text-xs font-semibold text-amber-700">{provider.ready_reason}</p>
                  ) : null}
                  <div className="mt-3 flex flex-wrap gap-1">
                    {provider.active ? <span className="rounded bg-slate-900 px-2 py-1 text-xs font-bold text-white">Aktif</span> : null}
                    {provider.default ? <span className="rounded bg-slate-100 px-2 py-1 text-xs font-bold text-slate-700">Default</span> : null}
                    {provider.fallback ? <span className="rounded bg-slate-100 px-2 py-1 text-xs font-bold text-slate-700">Fallback</span> : null}
                  </div>
                </article>
              ))}
            </div>

            <p className="mt-3 text-xs font-semibold text-slate-500">
              Provider önceliği: {messaging.provider.provider_priority.join(' > ')}
            </p>
          </div>

          <div className="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            {messaging.admin_sections.map((section) => (
              <article
                key={section.key}
                className={[
                  'rounded-xl border p-3 text-sm',
                  section.ready ? 'border-emerald-100 bg-emerald-50 text-emerald-900' : 'border-slate-200 bg-slate-50 text-slate-700',
                ].join(' ')}
              >
                <p className="font-bold">{section.label}</p>
                <p className="mt-1 text-xs leading-5 opacity-80">{section.summary}</p>
              </article>
            ))}
          </div>

          <div className="mt-5 grid gap-4 xl:grid-cols-2">
            <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
              <div className="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                <div>
                  <p className="text-sm font-bold text-slate-950">SMS API / NAC</p>
                  <p className="mt-1 text-sm leading-6 text-slate-600">
                    Basic Auth encrypted saklanır. Bu fazda kredi/sender okuma veya SMS gönderimi yapılmaz.
                  </p>
                </div>
                <span className={[
                  'rounded-lg border px-3 py-2 text-xs font-bold',
                  messaging.nac_sms.test_ready ? 'border-emerald-100 bg-emerald-50 text-emerald-900' : 'border-amber-100 bg-amber-50 text-amber-900',
                ].join(' ')}
                >
                  {messaging.nac_sms.test_ready ? 'Test hazır' : 'Hazırlık eksik'}
                </span>
              </div>

              <div className="mt-4 grid gap-3 md:grid-cols-2">
                {([
                  ['enabled', 'NAC aktif'],
                  ['use_shared_test_phone', 'Ortak test telefonu'],
                  ['commercial', 'Ticari gönderim'],
                  ['real_send_allowed', 'Gerçek gönderim izni'],
                ] as const).map(([key, label]) => (
                  <label key={key} className="flex items-center gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-800">
                    <input
                      type="checkbox"
                      checked={Boolean(nacSmsInputs[key])}
                      onChange={(event) => setNacSmsInputs({ ...nacSmsInputs, [key]: event.target.checked })}
                      className="h-5 w-5 rounded border-slate-300 text-slate-950 focus:ring-slate-400"
                    />
                    {label}
                  </label>
                ))}
                {([
                  ['host', 'Host'],
                  ['port', 'Port'],
                  ['sender', 'Sender'],
                  ['title', 'Title'],
                  ['gateway_uuid', 'Gateway UUID'],
                  ['validity', 'Validity'],
                  ['test_phone', 'NAC test telefonu'],
                  ['report_push_url', 'Report push URL'],
                ] as const).map(([key, label]) => (
                  <label key={key} className="grid gap-1 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                    <span>{label}</span>
                    <input
                      type={['port', 'validity'].includes(key) ? 'number' : 'text'}
                      value={String(nacSmsInputs[key])}
                      disabled={key === 'test_phone' && nacSmsInputs.use_shared_test_phone}
                      onChange={(event) => setNacSmsInputs({ ...nacSmsInputs, [key]: event.target.value })}
                      className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold normal-case tracking-normal text-slate-900 focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200 disabled:bg-slate-100"
                    />
                  </label>
                ))}
              </div>

              <div className="mt-4 rounded-lg border border-slate-200 bg-white p-3">
                <p className="text-sm font-bold text-slate-950">NAC credential</p>
                <p className="mt-1 text-xs leading-5 text-slate-600">
                  Kullanıcı: {messaging.nac_sms.username_mask ?? 'Eksik'} / Şifre: {messaging.nac_sms.password_mask ?? 'Eksik'}
                </p>
                <div className="mt-3 grid gap-3 md:grid-cols-2">
                  <input
                    type="text"
                    value={nacSmsCredentialInputs.username}
                    onChange={(event) => setNacSmsCredentialInputs({ ...nacSmsCredentialInputs, username: event.target.value })}
                    placeholder="NAC kullanıcı adı"
                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                  />
                  <input
                    type="password"
                    value={nacSmsCredentialInputs.password}
                    onChange={(event) => setNacSmsCredentialInputs({ ...nacSmsCredentialInputs, password: event.target.value })}
                    placeholder="NAC şifre"
                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                  />
                </div>
                <div className="mt-3 flex flex-wrap gap-2">
                  <button
                    type="button"
                    disabled={integrationCredentialSaving || nacSmsCredentialInputs.username.trim() === '' || nacSmsCredentialInputs.password === ''}
                    onClick={() => {
                      void saveNacSmsCredentials()
                    }}
                    className="rounded-lg border border-slate-900 bg-slate-950 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                  >
                    NAC bilgilerini kaydet
                  </button>
                  <button
                    type="button"
                    disabled={integrationCredentialSaving || !messaging.nac_sms.credentials_ready}
                    onClick={() => {
                      void clearNacSmsCredentials()
                    }}
                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                  >
                    NAC bilgilerini temizle
                  </button>
                </div>
              </div>
            </div>

            <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
              <div className="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                <div>
                  <p className="text-sm font-bold text-slate-950">Mikro API</p>
                  <p className="mt-1 text-sm leading-6 text-slate-600">
                    Mikro read/write hazırlığı panelden yönetilir. Yazma işlemleri onay/audit olmadan hazır sayılmaz.
                  </p>
                </div>
                <span className={[
                  'rounded-lg border px-3 py-2 text-xs font-bold',
                  messaging.mikro_api.read_ready ? 'border-emerald-100 bg-emerald-50 text-emerald-900' : 'border-amber-100 bg-amber-50 text-amber-900',
                ].join(' ')}
                >
                  {messaging.mikro_api.read_ready ? 'Read hazır' : 'Hazırlık eksik'}
                </span>
              </div>

              <div className="mt-4 grid gap-3 md:grid-cols-2">
                {([
                  ['enabled', 'Mikro aktif'],
                  ['read_sync_enabled', 'Read sync açık'],
                  ['write_enabled', 'Write açık'],
                  ['write_approval_required', 'Write onayı zorunlu'],
                ] as const).map(([key, label]) => (
                  <label key={key} className="flex items-center gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-800">
                    <input
                      type="checkbox"
                      checked={Boolean(mikroApiInputs[key])}
                      onChange={(event) => setMikroApiInputs({ ...mikroApiInputs, [key]: event.target.checked })}
                      className="h-5 w-5 rounded border-slate-300 text-slate-950 focus:ring-slate-400"
                    />
                    {label}
                  </label>
                ))}
                {([
                  ['base_url', 'Base URL'],
                  ['api_version', 'API version'],
                  ['application_code', 'Uygulama kodu'],
                  ['application_name', 'Uygulama adı'],
                  ['company_code', 'Firma kodu'],
                  ['branch_code', 'Şube kodu'],
                  ['workstation_code', 'Terminal kodu'],
                  ['fiscal_year', 'Mali yıl'],
                  ['timeout_seconds', 'Timeout saniye'],
                  ['operation_catalog_status', 'Operasyon kataloğu'],
                ] as const).map(([key, label]) => (
                  <label key={key} className="grid gap-1 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                    <span>{label}</span>
                    <input
                      type={key === 'timeout_seconds' ? 'number' : 'text'}
                      value={String(mikroApiInputs[key])}
                      onChange={(event) => setMikroApiInputs({ ...mikroApiInputs, [key]: event.target.value })}
                      className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold normal-case tracking-normal text-slate-900 focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                    />
                  </label>
                ))}
              </div>

              <div className="mt-4 rounded-lg border border-slate-200 bg-white p-3">
                <p className="text-sm font-bold text-slate-950">Mikro credential</p>
                <p className="mt-1 text-xs leading-5 text-slate-600">
                  API key: {messaging.mikro_api.api_key_mask ?? 'Eksik'} / Token: {messaging.mikro_api.token_mask ?? 'Eksik'}
                </p>
                <div className="mt-3 grid gap-3 md:grid-cols-2">
                  <input
                    type="password"
                    value={mikroApiCredentialInputs.api_key}
                    onChange={(event) => setMikroApiCredentialInputs({ ...mikroApiCredentialInputs, api_key: event.target.value })}
                    placeholder="Mikro API key"
                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                  />
                  <input
                    type="password"
                    value={mikroApiCredentialInputs.token}
                    onChange={(event) => setMikroApiCredentialInputs({ ...mikroApiCredentialInputs, token: event.target.value })}
                    placeholder="Mikro token"
                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                  />
                </div>
                <div className="mt-3 flex flex-wrap gap-2">
                  <button
                    type="button"
                    disabled={integrationCredentialSaving || (mikroApiCredentialInputs.api_key.trim() === '' && mikroApiCredentialInputs.token.trim() === '')}
                    onClick={() => {
                      void saveMikroApiCredentials()
                    }}
                    className="rounded-lg border border-slate-900 bg-slate-950 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                  >
                    Mikro bilgilerini kaydet
                  </button>
                  <button
                    type="button"
                    disabled={integrationCredentialSaving || !messaging.mikro_api.credentials_ready}
                    onClick={() => {
                      void clearMikroApiCredentials()
                    }}
                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                  >
                    Mikro bilgilerini temizle
                  </button>
                </div>
              </div>
            </div>
          </div>

          <div className="mt-5 grid gap-4 xl:grid-cols-[0.9fr_1.1fr]">
            <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
              <p className="text-sm font-bold text-slate-950">Genel ayarlar</p>
              <p className="mt-1 text-sm leading-6 text-slate-600">
                {messaging.helper_texts.secrets} {messaging.helper_texts.test_phone} {messaging.helper_texts.queue}
              </p>

              <div className="mt-4 grid gap-3 md:grid-cols-2">
                {[
                  ['messaging_enabled', 'Mesaj sistemi aktif'],
                  ['test_mode_enabled', 'Test modu'],
                  ['real_send_enabled', 'Gerçek gönderim aktif'],
                  ['queue_paused', 'Kuyruk duraklatıldı'],
                  ['allow_browser_smoke_send', 'Browser smoke gönderimine izin'],
                  ['allow_test_fixture_send', 'Test fixture gönderimine izin'],
                ].map(([key, label]) => (
                  <label key={key} className="flex items-center gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-800">
                    <input
                      type="checkbox"
                      checked={Boolean(messagingInputs[key as keyof typeof messagingInputs])}
                      onChange={(event) => setMessagingInputs({ ...messagingInputs, [key]: event.target.checked })}
                      className="h-5 w-5 rounded border-slate-300 text-slate-950 focus:ring-slate-400"
                    />
                    {label}
                  </label>
                ))}
              </div>

              <div className="mt-4 grid gap-3 md:grid-cols-2">
                <label className="grid gap-1 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500 md:col-span-2">
                  <span>Test telefon numarası</span>
                  <div className="flex flex-col gap-2 sm:flex-row">
                    <input
                      type="text"
                      value={messagingInputs.test_phone}
                      onChange={(event) => setMessagingInputs({ ...messagingInputs, test_phone: event.target.value })}
                      placeholder="905467647428"
                      className="min-w-0 flex-1 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold normal-case tracking-normal text-slate-900 focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                    />
                    <button
                      type="button"
                      disabled={messagingPhoneChecking || messagingInputs.test_phone.trim() === ''}
                      onClick={() => {
                        void validateMessagingPhone()
                      }}
                      className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold normal-case tracking-normal text-slate-800 shadow-sm hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                      {messagingPhoneChecking ? 'Kontrol ediliyor' : 'Test telefonu doğrula'}
                    </button>
                  </div>
                </label>
                {[
                  ['send_delay_seconds', 'Gönderim aralığı saniye', 30],
                  ['duplicate_cooldown_minutes', 'Duplicate cooldown dakika', 1],
                  ['hourly_limit', 'Saatlik limit', 1],
                  ['daily_limit', 'Günlük limit', 1],
                  ['max_auto_retries', 'Maksimum otomatik retry', 0],
                ].map(([key, label, min]) => (
                  <label key={key} className="grid gap-1 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                    <span>{label}</span>
                    <input
                      type="number"
                      min={Number(min)}
                      value={String(messagingInputs[key as keyof typeof messagingInputs])}
                      onChange={(event) => setMessagingInputs({ ...messagingInputs, [key]: event.target.value })}
                      className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold normal-case tracking-normal text-slate-900 focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                    />
                  </label>
                ))}
              </div>

              <div className="mt-4 rounded-lg border border-slate-200 bg-white p-3 text-sm text-slate-700">
                <p className="font-semibold text-slate-950">Readiness nedenleri</p>
                <ul className="mt-2 space-y-1">
                  {messaging.readiness.disabled_reasons.map((reason) => (
                    <li key={reason}>- {reason}</li>
                  ))}
                </ul>
              </div>
            </div>

            <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
              <p className="text-sm font-bold text-slate-950">Mesaj tipi ayarları</p>
              <p className="mt-1 text-sm leading-6 text-slate-600">
                Mesaj tipi ayarları provider bağımsızdır. Gerçek gönderim varsayılan kapalıdır; test modu açıkken hedef
                numara test telefonuna çevrilir.
              </p>
              <div className="mt-4 overflow-x-auto">
                <table className="min-w-[900px] text-left text-sm">
                  <thead className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                    <tr>
                      <th className="px-3 py-2">Mesaj tipi</th>
                      <th className="px-3 py-2">Aktif</th>
                      <th className="px-3 py-2">Test</th>
                      <th className="px-3 py-2">Gerçek</th>
                      <th className="px-3 py-2">Template key</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-200">
                    {messaging.message_types.map((type) => {
                      const input = messageTypeInputs[type.key] ?? {
                        enabled: false,
                        test_send_allowed: false,
                        real_send_allowed: false,
                        template_key: '',
                        notes: '',
                      }

                      return (
                        <tr key={type.key} className="align-top">
                          <td className="px-3 py-3">
                            <p className="font-semibold text-slate-950">{type.label}</p>
                            <p className="mt-1 text-xs leading-5 text-slate-600">{type.description}</p>
                            <p className="mt-1 text-xs font-semibold text-slate-500">{type.recipient_role}{type.future ? ' / future' : ''}</p>
                          </td>
                          {(['enabled', 'test_send_allowed', 'real_send_allowed'] as const).map((key) => (
                            <td key={key} className="px-3 py-3">
                              <input
                                type="checkbox"
                                checked={Boolean(input[key])}
                                onChange={(event) => setMessageTypeInputs({
                                  ...messageTypeInputs,
                                  [type.key]: {
                                    ...input,
                                    enabled: Boolean(input.enabled),
                                    test_send_allowed: Boolean(input.test_send_allowed),
                                    real_send_allowed: Boolean(input.real_send_allowed),
                                    template_key: input.template_key ?? '',
                                    notes: input.notes ?? '',
                                    [key]: event.target.checked,
                                  },
                                })}
                                className="h-5 w-5 rounded border-slate-300 text-slate-950 focus:ring-slate-400"
                              />
                            </td>
                          ))}
                          <td className="px-3 py-3">
                            <input
                              type="text"
                              value={input.template_key ?? ''}
                              onChange={(event) => setMessageTypeInputs({
                                ...messageTypeInputs,
                                [type.key]: {
                                  ...input,
                                  enabled: Boolean(input.enabled),
                                  test_send_allowed: Boolean(input.test_send_allowed),
                                  real_send_allowed: Boolean(input.real_send_allowed),
                                  template_key: event.target.value,
                                  notes: input.notes ?? '',
                                },
                              })}
                              placeholder="REL-4C"
                              className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                            />
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div className="mt-4 grid gap-2 md:grid-cols-2">
            {messaging.warnings.map((warning) => (
              <p key={warning} className="rounded-lg border border-amber-100 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900">
                {warning}
              </p>
            ))}
          </div>
        </section>

        <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
            <div>
              <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                Mail ayarları
              </p>
              <h2 className="mt-2 text-lg font-bold text-slate-950">
                SMTP gönderim, IMAP/POP3 gelen kutu bağlantı ayarları
              </h2>
              <p className="mt-2 max-w-4xl text-sm leading-6 text-slate-600">
                {mailSettings.helper_texts.outgoing} {mailSettings.helper_texts.incoming} {mailSettings.helper_texts.secrets}
              </p>
              {mailMessage ? (
                <p className="mt-3 rounded-lg border border-blue-100 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-800">
                  {mailMessage}
                </p>
              ) : null}
            </div>
            <div className={[
              'rounded-xl border px-4 py-3 text-sm font-semibold',
              mailSettings.payment_notification_ready ? 'border-emerald-100 bg-emerald-50 text-emerald-900' : 'border-amber-100 bg-amber-50 text-amber-900',
            ].join(' ')}
            >
              Ödeme maili: {mailSettings.payment_notification_ready ? 'SMTP hazır' : 'SMTP eksik'}
            </div>
          </div>

          <div className="mt-5 grid gap-4 xl:grid-cols-2">
            <article className="rounded-xl border border-slate-200 bg-slate-50 p-4">
              <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                  <p className="text-sm font-bold text-slate-950">Giden Mail / SMTP</p>
                  <p className="mt-1 text-sm leading-6 text-slate-600">SMTP ödeme bildirimi ve test maili göndermek için kullanılır.</p>
                </div>
                <span className={[
                  'rounded-lg border px-3 py-2 text-xs font-bold',
                  mailSettings.outgoing.ready ? 'border-emerald-100 bg-emerald-50 text-emerald-900' : 'border-amber-100 bg-amber-50 text-amber-900',
                ].join(' ')}
                >
                  {mailSettings.outgoing.status_label}
                </span>
              </div>

              <div className="mt-4 grid gap-3 md:grid-cols-2">
                <label className="flex items-center gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-800 md:col-span-2">
                  <input
                    type="checkbox"
                    checked={outgoingMailInputs.enabled}
                    onChange={(event) => setOutgoingMailInputs({ ...outgoingMailInputs, enabled: event.target.checked })}
                    className="h-5 w-5 rounded border-slate-300 text-slate-950 focus:ring-slate-400"
                  />
                  SMTP gönderimi aktif
                </label>
                <label className="grid gap-1 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                  <span>SMTP host</span>
                  <input
                    type="text"
                    value={outgoingMailInputs.host}
                    onChange={(event) => setOutgoingMailInputs({ ...outgoingMailInputs, host: event.target.value })}
                    placeholder="smtp.example.com"
                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold normal-case tracking-normal text-slate-900 focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                  />
                </label>
                <label className="grid gap-1 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                  <span>Port</span>
                  <input
                    type="number"
                    min="1"
                    max="65535"
                    value={outgoingMailInputs.port}
                    onChange={(event) => setOutgoingMailInputs({ ...outgoingMailInputs, port: event.target.value })}
                    placeholder="587"
                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold normal-case tracking-normal text-slate-900 focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                  />
                </label>
                <label className="grid gap-1 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                  <span>Şifreleme</span>
                  <select
                    value={outgoingMailInputs.encryption}
                    onChange={(event) => setOutgoingMailInputs({ ...outgoingMailInputs, encryption: event.target.value as 'tls' | 'ssl' | 'none' })}
                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold normal-case tracking-normal text-slate-900 focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                  >
                    <option value="tls">TLS</option>
                    <option value="ssl">SSL</option>
                    <option value="none">None</option>
                  </select>
                </label>
                <label className="grid gap-1 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                  <span>From adresi</span>
                  <input
                    type="email"
                    value={outgoingMailInputs.from_address}
                    onChange={(event) => setOutgoingMailInputs({ ...outgoingMailInputs, from_address: event.target.value })}
                    placeholder="no-reply@example.com"
                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold normal-case tracking-normal text-slate-900 focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                  />
                </label>
                <label className="grid gap-1 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                  <span>Kullanıcı adı</span>
                  <input
                    type="text"
                    value={outgoingMailInputs.username}
                    onChange={(event) => setOutgoingMailInputs({ ...outgoingMailInputs, username: event.target.value })}
                    placeholder={mailSettings.outgoing.username_mask ?? 'Değiştirmek için girin'}
                    autoComplete="off"
                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold normal-case tracking-normal text-slate-900 focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                  />
                </label>
                <label className="grid gap-1 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                  <span>Şifre / token</span>
                  <input
                    type="password"
                    value={outgoingMailInputs.password}
                    onChange={(event) => setOutgoingMailInputs({ ...outgoingMailInputs, password: event.target.value })}
                    placeholder={mailSettings.outgoing.password_mask ?? 'Değiştirmek için girin'}
                    autoComplete="new-password"
                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold normal-case tracking-normal text-slate-900 focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                  />
                </label>
                <label className="grid gap-1 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                  <span>From adı</span>
                  <input
                    type="text"
                    value={outgoingMailInputs.from_name}
                    onChange={(event) => setOutgoingMailInputs({ ...outgoingMailInputs, from_name: event.target.value })}
                    placeholder="EMAKS Teknik Servis"
                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold normal-case tracking-normal text-slate-900 focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                  />
                </label>
                <label className="grid gap-1 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                  <span>Test alıcısı</span>
                  <input
                    type="email"
                    value={outgoingMailInputs.test_recipient}
                    onChange={(event) => setOutgoingMailInputs({ ...outgoingMailInputs, test_recipient: event.target.value })}
                    placeholder="payment-audit@example.com"
                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold normal-case tracking-normal text-slate-900 focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                  />
                </label>
              </div>

              <div className="mt-4 flex flex-wrap gap-2">
                <button
                  type="button"
                  disabled={mailSaving}
                  onClick={() => {
                    void saveOutgoingMailSettings()
                  }}
                  className="rounded-lg border border-slate-900 bg-slate-950 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                >
                  {mailSaving ? 'Kaydediliyor' : 'SMTP ayarlarını kaydet'}
                </button>
                <button
                  type="button"
                  disabled={mailTesting || !outgoingMailInputs.test_recipient.trim()}
                  onClick={() => {
                    void sendOutgoingTestMail()
                  }}
                  className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                >
                  {mailTesting ? 'Test ediliyor' : 'Test mail gönder'}
                </button>
                <button
                  type="button"
                  disabled={mailSaving}
                  onClick={() => {
                    void clearOutgoingMailSettings()
                  }}
                  className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                >
                  SMTP bilgilerini temizle
                </button>
              </div>
              <p className="mt-3 text-xs leading-5 text-slate-600">{mailSettings.outgoing.readiness_message}</p>
              {mailSettings.outgoing.last_test_status ? (
                <p className="mt-1 text-xs font-semibold text-slate-500">
                  Son test: {mailSettings.outgoing.last_test_status} — {mailSettings.outgoing.last_test_message}
                </p>
              ) : null}
            </article>

            <article className="rounded-xl border border-slate-200 bg-slate-50 p-4">
              <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                  <p className="text-sm font-bold text-slate-950">Gelen Mail / IMAP-POP3</p>
                  <p className="mt-1 text-sm leading-6 text-slate-600">Bu ayarlar sadece gelen kutu bağlantı testi içindir; ödeme bildirimi SMTP ile gönderilir.</p>
                </div>
                <span className={[
                  'rounded-lg border px-3 py-2 text-xs font-bold',
                  mailSettings.incoming.ready ? 'border-emerald-100 bg-emerald-50 text-emerald-900' : 'border-amber-100 bg-amber-50 text-amber-900',
                ].join(' ')}
                >
                  {mailSettings.incoming.status_label}
                </span>
              </div>

              <div className="mt-4 grid gap-3 md:grid-cols-2">
                <label className="flex items-center gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-800 md:col-span-2">
                  <input
                    type="checkbox"
                    checked={incomingMailInputs.enabled}
                    onChange={(event) => setIncomingMailInputs({ ...incomingMailInputs, enabled: event.target.checked })}
                    className="h-5 w-5 rounded border-slate-300 text-slate-950 focus:ring-slate-400"
                  />
                  Gelen kutu bağlantısı aktif
                </label>
                <label className="grid gap-1 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                  <span>Protokol</span>
                  <select
                    value={incomingMailInputs.protocol}
                    onChange={(event) => setIncomingMailInputs({ ...incomingMailInputs, protocol: event.target.value as 'imap' | 'pop3' })}
                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold normal-case tracking-normal text-slate-900 focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                  >
                    <option value="imap">IMAP</option>
                    <option value="pop3">POP3</option>
                  </select>
                </label>
                <label className="grid gap-1 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                  <span>Host</span>
                  <input
                    type="text"
                    value={incomingMailInputs.host}
                    onChange={(event) => setIncomingMailInputs({ ...incomingMailInputs, host: event.target.value })}
                    placeholder={incomingMailInputs.protocol === 'imap' ? 'imap.example.com' : 'pop3.example.com'}
                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold normal-case tracking-normal text-slate-900 focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                  />
                </label>
                <label className="grid gap-1 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                  <span>Port</span>
                  <input
                    type="number"
                    min="1"
                    max="65535"
                    value={incomingMailInputs.port}
                    onChange={(event) => setIncomingMailInputs({ ...incomingMailInputs, port: event.target.value })}
                    placeholder={incomingMailInputs.protocol === 'imap' ? '993' : '995'}
                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold normal-case tracking-normal text-slate-900 focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                  />
                </label>
                <label className="grid gap-1 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                  <span>Şifreleme</span>
                  <select
                    value={incomingMailInputs.encryption}
                    onChange={(event) => setIncomingMailInputs({ ...incomingMailInputs, encryption: event.target.value as 'tls' | 'ssl' | 'none' })}
                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold normal-case tracking-normal text-slate-900 focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                  >
                    <option value="tls">TLS</option>
                    <option value="ssl">SSL</option>
                    <option value="none">None</option>
                  </select>
                </label>
                <label className="grid gap-1 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                  <span>Kullanıcı adı</span>
                  <input
                    type="text"
                    value={incomingMailInputs.username}
                    onChange={(event) => setIncomingMailInputs({ ...incomingMailInputs, username: event.target.value })}
                    placeholder={mailSettings.incoming.username_mask ?? 'Değiştirmek için girin'}
                    autoComplete="off"
                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold normal-case tracking-normal text-slate-900 focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                  />
                </label>
                <label className="grid gap-1 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                  <span>Şifre / token</span>
                  <input
                    type="password"
                    value={incomingMailInputs.password}
                    onChange={(event) => setIncomingMailInputs({ ...incomingMailInputs, password: event.target.value })}
                    placeholder={mailSettings.incoming.password_mask ?? 'Değiştirmek için girin'}
                    autoComplete="new-password"
                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold normal-case tracking-normal text-slate-900 focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                  />
                </label>
                <label className="grid gap-1 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500 md:col-span-2">
                  <span>Mailbox / klasör</span>
                  <input
                    type="text"
                    value={incomingMailInputs.mailbox}
                    onChange={(event) => setIncomingMailInputs({ ...incomingMailInputs, mailbox: event.target.value })}
                    placeholder="INBOX"
                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold normal-case tracking-normal text-slate-900 focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200"
                  />
                </label>
              </div>

              <div className="mt-4 flex flex-wrap gap-2">
                <button
                  type="button"
                  disabled={mailSaving}
                  onClick={() => {
                    void saveIncomingMailSettings()
                  }}
                  className="rounded-lg border border-slate-900 bg-slate-950 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                >
                  {mailSaving ? 'Kaydediliyor' : 'IMAP/POP3 ayarlarını kaydet'}
                </button>
                <button
                  type="button"
                  disabled={mailTesting}
                  onClick={() => {
                    void testIncomingMailSettings()
                  }}
                  className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                >
                  {mailTesting ? 'Test ediliyor' : 'Bağlantıyı test et'}
                </button>
                <button
                  type="button"
                  disabled={mailSaving}
                  onClick={() => {
                    void clearIncomingMailSettings()
                  }}
                  className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                >
                  Gelen mail bilgilerini temizle
                </button>
              </div>
              <p className="mt-3 text-xs leading-5 text-slate-600">{mailSettings.incoming.readiness_message}</p>
              {mailSettings.incoming.last_test_status ? (
                <p className="mt-1 text-xs font-semibold text-slate-500">
                  Son test: {mailSettings.incoming.last_test_status} — {mailSettings.incoming.last_test_message}
                </p>
              ) : null}
            </article>
          </div>
        </section>

        <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
            OPS detay görünürlüğü
          </p>
          <h2 className="mt-2 text-lg font-bold text-slate-950">
            Gelişmiş kontrol blokları varsayılan olarak gizli.
          </h2>
          <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
            Bu ayarlar sadece operasyon detay ekranındaki eski büyük kontrol bloklarının görünürlüğünü belirler.
            İş kuralı ve backend doğrulaması bu görünürlükten bağımsız kalır.
          </p>
          <div className="mt-4 grid gap-3 lg:grid-cols-3">
            {opsDetailToggles.map((item) => (
              <label key={item.key} className="grid cursor-pointer gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-800">
                <span className="flex items-start gap-3">
                  <input
                    type="checkbox"
                    checked={opsDetailVisibility[item.key]}
                    disabled={saving}
                    onChange={(event) => {
                      void updateOpsDetailVisibility(item.key, event.target.checked)
                    }}
                    className="mt-0.5 h-5 w-5 rounded border-slate-300 text-slate-950 focus:ring-slate-400"
                  />
                  <span>
                    <span className="block font-semibold">{item.label}</span>
                    <span className="mt-1 block leading-5 text-slate-600">{item.description}</span>
                  </span>
                </span>
                <span className="text-xs font-semibold text-slate-500">
                  {opsDetailVisibility[item.key] ? 'Açık' : 'Kapalı'}
                </span>
              </label>
            ))}
          </div>
        </section>

        <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
            Yetki merkezi
          </p>
          <h2 className="mt-2 text-lg font-bold text-slate-950">
            Teknik servis erişimleri kullanıcı bazında atanır.
          </h2>
          <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
            Bu ekran sadece technical_service_admin yetkisi olan kullanıcılar için görünür. Kullanıcı ekran erişimleri,
            hakediş ödeme aksiyonu ve teknisyen yönetimi {'Admin > Kullanıcı Yönetimi'} içinde ayrı kaynaklar olarak
            seçilebilir.
          </p>
        </section>
      </div>
    </>
  )
}
