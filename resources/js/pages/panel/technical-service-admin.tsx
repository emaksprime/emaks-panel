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
  selected_provider_mode_label: string
  effective_mode: string
  effective_mode_label: string
  fake_active: boolean
  gateway: {
    url_configured: boolean
    token_configured: boolean
    health_verified: boolean
    http_enabled: boolean
    provider_send_enabled: boolean
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
  can_enable_real_provider: boolean
  disabled_reason: string | null
  health_status: {
    status: string
    label: string
    message: string
  }
  secret_source: string
  warning: string
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
}: {
  qrPublicFlowSettings: QrPublicFlowSettings
  paymentProviderSettings: PaymentProviderSettings
}) {
  const [preFormPaymentEnabled, setPreFormPaymentEnabled] = useState(
    qrPublicFlowSettings.pre_form_payment_for_mount_excluded_enabled,
  )
  const [paymentSettings, setPaymentSettings] = useState(paymentProviderSettings)
  const [opsDetailVisibility, setOpsDetailVisibility] = useState({
    show_mount_excluded_approval_block: Boolean(qrPublicFlowSettings.ops_detail_visibility?.show_mount_excluded_approval_block),
    show_payment_mount_control_block: Boolean(qrPublicFlowSettings.ops_detail_visibility?.show_payment_mount_control_block),
    show_address_control_block: Boolean(qrPublicFlowSettings.ops_detail_visibility?.show_address_control_block),
  })
  const [saving, setSaving] = useState(false)
  const [paymentSaving, setPaymentSaving] = useState(false)
  const [credentialSaving, setCredentialSaving] = useState(false)
  const [healthChecking, setHealthChecking] = useState(false)
  const [message, setMessage] = useState('')
  const [paymentMessage, setPaymentMessage] = useState('')
  const [credentialInputs, setCredentialInputs] = useState({ api_key: '', secret_key: '' })

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
      label: 'Gateway URL',
      value: paymentSettings.gateway.url_configured ? 'Hazır' : 'Eksik',
      ok: paymentSettings.gateway.url_configured,
    },
    {
      label: 'Gateway token',
      value: paymentSettings.gateway.token_configured ? 'Hazır' : 'Eksik',
      ok: paymentSettings.gateway.token_configured,
    },
    {
      label: 'API bilgileri',
      value: paymentSettings.credentials.ready ? 'API bilgileri tanımlı' : 'API bilgileri tanımlı değil',
      ok: paymentSettings.credentials.ready,
    },
    {
      label: 'Son sağlık kontrolü',
      value: paymentSettings.health_status.label,
      ok: paymentSettings.health_status.status === 'ready',
    },
    {
      label: 'Gerçek gönderim',
      value: paymentSettings.gateway.provider_send_enabled ? 'Aktif' : 'Kapalı',
      ok: paymentSettings.gateway.provider_send_enabled,
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
                  : 'Gerçek ödeme aktif. Fake ödeme kullanılmaz.'}
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
              <p className="mt-2 text-xs font-semibold text-slate-500">
                Webhook: {paymentSettings.gateway.webhook_path}
              </p>
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
            </div>
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
