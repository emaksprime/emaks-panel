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
  paymentProviderSettings,
  mailTransportSettings,
}: {
  qrPublicFlowSettings: QrPublicFlowSettings
  paymentProviderSettings: PaymentProviderSettings
  mailTransportSettings: MailTransportSettings
}) {
  const [preFormPaymentEnabled, setPreFormPaymentEnabled] = useState(
    qrPublicFlowSettings.pre_form_payment_for_mount_excluded_enabled,
  )
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
  const [message, setMessage] = useState('')
  const [paymentMessage, setPaymentMessage] = useState('')
  const [mailMessage, setMailMessage] = useState('')
  const [credentialInputs, setCredentialInputs] = useState({ api_key: '', secret_key: '' })
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
