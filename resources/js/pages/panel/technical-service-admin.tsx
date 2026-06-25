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

function csrfToken(): string {
  if (typeof document === 'undefined') {
    return ''
  }

  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
}

export default function TechnicalServiceAdmin({
  qrPublicFlowSettings,
}: {
  qrPublicFlowSettings: QrPublicFlowSettings
}) {
  const [preFormPaymentEnabled, setPreFormPaymentEnabled] = useState(
    qrPublicFlowSettings.pre_form_payment_for_mount_excluded_enabled,
  )
  const [opsDetailVisibility, setOpsDetailVisibility] = useState({
    show_mount_excluded_approval_block: Boolean(qrPublicFlowSettings.ops_detail_visibility?.show_mount_excluded_approval_block),
    show_payment_mount_control_block: Boolean(qrPublicFlowSettings.ops_detail_visibility?.show_payment_mount_control_block),
    show_address_control_block: Boolean(qrPublicFlowSettings.ops_detail_visibility?.show_address_control_block),
  })
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')

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
        setMessage('Ayar kaydedilemedi. Yetki veya oturum durumunu kontrol edin.')
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
