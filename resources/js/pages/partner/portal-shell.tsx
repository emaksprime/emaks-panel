import { Head, Link } from '@inertiajs/react'
import { useMemo, useState } from 'react'
import type { FormEvent } from 'react'
import { apiRequest } from '@/lib/api'

type Capability = 'dealer' | 'locksmith' | 'manufacturer' | 'seller'
type ViewKey = 'dashboard' | 'settings' | 'orders' | 'stock' | 'service-jobs' | 'earnings'

type LinkedTechnician = {
  name: string | null
  phone: string | null
  city: string | null
  district: string | null
  relationship_type: string
  is_primary: boolean
}

type ChildAccount = {
  usage_type: string | null
  label: string | null
  invoice_usage_note: string | null
}

type PartnerSummary = {
  id: number
  display_name: string
  capabilities: Capability[]
  phone: string | null
  email: string | null
  city: string | null
  district: string | null
  address: string | null
  child_accounts: ChildAccount[]
  linked_technicians: LinkedTechnician[]
  users_count: number
  active_users_count: number
}

type Product = {
  catalog_id: string
  name: string
  model: string
  category: string
  stock_status: 'available' | 'limited' | 'out_of_stock' | 'unknown'
  stock_label: string
  order_note: string
}

type OrderItem = {
  product_name: string
  requested_quantity: number
  stock_status: string
  stock_label: string
  note: string | null
}

type PartnerOrder = {
  id: number
  order_no: string
  status: string
  status_label: string
  note: string | null
  submitted_at: string | null
  items_count: number
  total_quantity: number
  estimated_amount: number | null
  shipping_status: string
  delivery_check_status: string
  items: OrderItem[]
}

type ServiceJob = {
  id: number
  mrn: string
  customer_name: string | null
  customer_phone: string | null
  customer_city: string | null
  customer_district: string | null
  address_summary: string | null
  product_name: string | null
  product_model: string | null
  service_type: string | null
  scheduled_at: string | null
  scheduled_date: string | null
  status: string | null
  workflow_status: string | null
  next_action: string | null
  updated_at: string | null
}

type EarningRow = {
  id: number
  period: string | null
  job_count: number
  labor_total: number
  travel_fee_total: number
  grand_total: number
  status: string
  paid_at: string | null
  items: Array<{
    job_date: string | null
    mrn: string | null
    city: string | null
    district: string | null
    labor_amount: number
    travel_fee_amount: number
    line_total: number
    status: string
  }>
}

type PartnerPortalProps = {
  preview?: {
    read_only: boolean
    warning: string
    back_url: string
    portal_url?: string
  }
  partnerPortal: {
    view: ViewKey
    allowed: boolean
    deniedMessage: string | null
    preview: boolean
    partners: PartnerSummary[]
    selectedPartner: PartnerSummary
    navigation: Array<{ key: ViewKey, label: string, href: string }>
    stats: {
      linked_technicians_count: number
      users_count: number
      active_users_count: number
      open_service_jobs_count: number
      open_orders_count: number
      approval_waiting_orders_count: number
      submitted_orders_count: number
    }
    orders: PartnerOrder[]
    products: Product[]
    serviceJobs: ServiceJob[]
    earnings: {
      status: string
      rows: EarningRow[]
      summary: {
        job_count: number
        labor_total: number
        travel_fee_total: number
        grand_total: number
      }
    }
    settings: {
      contact_note: string
      users: Array<{ name: string | null, username: string | null, title: string | null, phone: string | null }>
    }
    messages: Record<string, string>
  }
}

const capabilityLabel = (capability: Capability) => ({
  dealer: 'Bayi',
  locksmith: 'Çilingir',
  manufacturer: 'Üretici',
  seller: 'Satıcı',
}[capability])

const viewTitle = (view: ViewKey) => ({
  dashboard: 'Ana Sayfa',
  settings: 'Ayarlar',
  orders: 'Siparişlerim',
  stock: 'Ürünler',
  'service-jobs': 'İşlerim',
  earnings: 'Hakedişlerim',
}[view])

const money = new Intl.NumberFormat('tr-TR', { style: 'currency', currency: 'TRY', maximumFractionDigits: 0 })

const portalHref = (path: string, partnerId: number) => `${path}?partner_id=${partnerId}`

const locationLabel = (partner: PartnerSummary) => [partner.city, partner.district].filter(Boolean).join(' / ') || '-'

function CapabilityChips({ capabilities }: { capabilities: Capability[] }) {
  return (
    <div className="flex flex-wrap gap-2">
      {capabilities.map((capability) => (
        <span key={capability} className="rounded-full border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700">
          {capabilityLabel(capability)}
        </span>
      ))}
    </div>
  )
}

function PortalShell({ partnerPortal, preview }: PartnerPortalProps) {
  const { selectedPartner, stats, view } = partnerPortal
  const isPreview = Boolean(preview?.read_only || partnerPortal.preview)

  return (
    <div className="min-h-screen overflow-x-hidden bg-slate-100 text-slate-950">
      <Head title={`${viewTitle(view)} - Partner Portal`} />
      <header className="border-b border-slate-200 bg-white">
        <div className="mx-auto flex max-w-7xl flex-col gap-4 px-4 py-4 lg:flex-row lg:items-center lg:justify-between">
          <div className="min-w-0">
            <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Emaks Prime Partner Portal</p>
            <h1 className="mt-1 break-words text-2xl font-semibold text-slate-950">{selectedPartner.display_name}</h1>
            <div className="mt-2 flex flex-wrap items-center gap-2 text-sm text-slate-500">
              <span>{locationLabel(selectedPartner)}</span>
              {selectedPartner.phone && <span>{selectedPartner.phone}</span>}
            </div>
          </div>
          <div className="flex flex-col gap-3 lg:items-end">
            <CapabilityChips capabilities={selectedPartner.capabilities} />
            <div className="flex flex-wrap gap-2">
              {partnerPortal.partners.length > 1 && !isPreview && (
                <select
                  className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700"
                  defaultValue={selectedPartner.id}
                  onChange={(event) => {
                    window.location.href = `${window.location.pathname}?partner_id=${Number(event.target.value)}`
                  }}
                >
                  {partnerPortal.partners.map((partner) => (
                    <option key={partner.id} value={partner.id}>{partner.display_name}</option>
                  ))}
                </select>
              )}
              {preview ? (
                <Link href={preview.back_url} className="rounded-xl bg-slate-950 px-3 py-2 text-sm font-semibold text-white">
                  Partner yönetimine dön
                </Link>
              ) : (
                <>
                  <Link href={portalHref('/partner/settings', selectedPartner.id)} className="rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700">
                    Ayarlar
                  </Link>
                  <Link href="/logout" method="post" as="button" className="rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700">
                    Çıkış
                  </Link>
                </>
              )}
            </div>
          </div>
        </div>
        <nav className="mx-auto flex max-w-7xl flex-wrap gap-2 px-4 pb-4">
          {partnerPortal.navigation.map((item) => {
            const active = item.key === view
            const href = isPreview && preview?.portal_url
              ? `${preview.portal_url}?view=${item.key}`
              : portalHref(item.href, selectedPartner.id)

            return (
              <Link
                key={item.key}
                href={href}
                className={`rounded-xl px-3 py-2 text-sm font-semibold ${active ? 'bg-slate-950 text-white' : 'bg-white text-slate-700 hover:bg-slate-50'}`}
                onClick={(event) => {
                  if (isPreview && !preview?.portal_url) {
                    event.preventDefault()
                  }
                }}
              >
                {item.label}
              </Link>
            )
          })}
        </nav>
      </header>

      <main className="mx-auto max-w-7xl px-4 py-6">
        {preview && (
          <section className="mb-5 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm font-semibold text-amber-900">
            {preview.warning}
          </section>
        )}

        {!partnerPortal.allowed && (
          <section className="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-amber-900">
            <h2 className="text-lg font-semibold">Bu ekrana erişiminiz yok.</h2>
            <p className="mt-1 text-sm">{partnerPortal.deniedMessage ?? 'Partner yetkiniz bu ekran için yeterli değil.'}</p>
          </section>
        )}

        {partnerPortal.allowed && view === 'dashboard' && (
          <DashboardView partner={selectedPartner} stats={stats} messages={partnerPortal.messages} />
        )}
        {partnerPortal.allowed && view === 'orders' && (
          <OrdersView
            partnerId={selectedPartner.id}
            initialOrders={partnerPortal.orders}
            products={partnerPortal.products}
            message={partnerPortal.messages.orders}
            readOnly={isPreview}
          />
        )}
        {partnerPortal.allowed && view === 'stock' && (
          <ProductsView products={partnerPortal.products} readOnly={isPreview} />
        )}
        {partnerPortal.allowed && view === 'service-jobs' && (
          <ServiceJobsView jobs={partnerPortal.serviceJobs} />
        )}
        {partnerPortal.allowed && view === 'earnings' && (
          <EarningsView earnings={partnerPortal.earnings} />
        )}
        {partnerPortal.allowed && view === 'settings' && (
          <SettingsView partner={selectedPartner} settings={partnerPortal.settings} message={partnerPortal.messages.settings} />
        )}
      </main>
    </div>
  )
}

function DashboardView({ partner, stats, messages }: { partner: PartnerSummary, stats: PartnerPortalProps['partnerPortal']['stats'], messages: Record<string, string> }) {
  const isDealerLike = partner.capabilities.some((capability) => ['dealer', 'manufacturer', 'seller'].includes(capability))
  const isLocksmith = partner.capabilities.includes('locksmith')

  return (
    <div className="grid gap-5">
      <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <p className="text-sm font-semibold text-slate-500">Hoş geldiniz</p>
        <h2 className="mt-1 text-2xl font-semibold text-slate-950">{partner.display_name}</h2>
        <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
          Bu portal sadece size atanmış partner kapsamını gösterir. İç operasyon ekranları ve kaynak kodları burada yer almaz.
        </p>
      </section>

      {isDealerLike && (
        <section className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
          <StatCard title="Açık siparişlerim" value={stats.open_orders_count} hint="Operasyon incelemesinde" />
          <StatCard title="Onay bekleyen" value={stats.approval_waiting_orders_count} hint="Merkez onayı" />
          <StatCard title="Ürün/stok durumu" value="Hazır" hint="Güvenli ürün kataloğu" />
          <StatCard title="Teslimat kontrolü" value="Hazırlanıyor" hint="Sonraki faz" />
        </section>
      )}

      {isLocksmith && (
        <section className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
          <StatCard title="Bugünkü işlerim" value="-" hint="Randevu takibi" />
          <StatCard title="Açık işlerim" value={stats.open_service_jobs_count} hint="Atanmış işler" />
          <StatCard title="Bağlı ustalar" value={stats.linked_technicians_count} hint="Partner kapsamı" />
          <StatCard title="Hakediş" value="Hazır" hint="Teknik Servis kaynağı" />
        </section>
      )}

      <section className="grid gap-4 lg:grid-cols-2">
        {isDealerLike && <ActionPanel title="Bayi işlemleri" message={messages.orders} actions={[['Yeni sipariş oluştur', '/partner/orders'], ['Ürünleri incele', '/partner/stock']]} partnerId={partner.id} />}
        {isLocksmith && <ActionPanel title="Çilingir işlemleri" message={messages.service} actions={[['İşlerime git', '/partner/service-jobs'], ['Hakedişlerime git', '/partner/earnings']]} partnerId={partner.id} />}
      </section>
    </div>
  )
}

function OrdersView({ partnerId, initialOrders, products, message, readOnly }: { partnerId: number, initialOrders: PartnerOrder[], products: Product[], message: string, readOnly: boolean }) {
  const [orders, setOrders] = useState(initialOrders)
  const [quantities, setQuantities] = useState<Record<string, number>>({})
  const [note, setNote] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [result, setResult] = useState<string | null>(null)
  const selectedItems = useMemo(() => products.filter((product) => (quantities[product.catalog_id] ?? 0) > 0), [products, quantities])

  const submitOrder = async (event: FormEvent) => {
    event.preventDefault()

    if (readOnly || selectedItems.length === 0) {
      return
    }

    setSubmitting(true)
    setResult(null)

    try {
      const payload = await apiRequest('/api/partner/orders', {
        method: 'POST',
        body: JSON.stringify({
          partner_id: partnerId,
          note,
          items: selectedItems.map((product) => ({
            catalog_id: product.catalog_id,
            requested_quantity: quantities[product.catalog_id],
          })),
        }),
      }) as { message?: string, order: PartnerOrder }
      setOrders([payload.order, ...orders])
      setQuantities({})
      setNote('')
      setResult(payload.message ?? 'Sipariş talebi oluşturuldu.')
    } catch (error) {
      setResult(error instanceof Error ? error.message : 'Sipariş talebi oluşturulamadı.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_420px]">
      <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 className="text-lg font-semibold text-slate-950">Siparişlerim</h2>
            <p className="mt-1 text-sm text-slate-500">{message}</p>
          </div>
        </div>
        <div className="mt-4 grid gap-3">
          {orders.length === 0 && <EmptyState title="Sipariş talebi yok" message="Yeni sipariş oluştur sekmesinden operasyon incelemesine talep gönderebilirsiniz." />}
          {orders.map((order) => (
            <article key={order.id} className="rounded-xl border border-slate-200 bg-slate-50 p-4">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <h3 className="font-semibold text-slate-950">{order.order_no}</h3>
                  <p className="mt-1 text-sm text-slate-500">{order.items_count} ürün · {order.total_quantity} adet</p>
                </div>
                <span className="rounded-full bg-white px-3 py-1 text-xs font-semibold text-slate-700">{order.status_label}</span>
              </div>
              <div className="mt-3 grid gap-2 text-sm text-slate-600">
                <div>Sevk durumu: {order.shipping_status}</div>
                <div>Teslimat kontrolü: {order.delivery_check_status}</div>
                {order.note && <div>Not: {order.note}</div>}
              </div>
            </article>
          ))}
        </div>
      </section>

      <form onSubmit={submitOrder} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm xl:sticky xl:top-4 xl:self-start">
        <h2 className="text-lg font-semibold text-slate-950">Yeni sipariş oluştur</h2>
        <p className="mt-1 text-sm text-slate-500">Talep operasyon onayına düşer; iç stok veya fiyat detayı gösterilmez.</p>
        <div className="mt-4 grid gap-3">
          {products.map((product) => (
            <div key={product.catalog_id} className="rounded-xl border border-slate-200 bg-slate-50 p-3">
              <div className="flex items-start justify-between gap-3">
                <div>
                  <div className="font-semibold text-slate-950">{product.name}</div>
                  <div className="text-sm text-slate-500">{product.model} · {product.category}</div>
                  <div className="mt-2 text-xs font-semibold text-slate-600">{product.stock_label}</div>
                </div>
                <input
                  type="number"
                  min={0}
                  max={999}
                  value={quantities[product.catalog_id] ?? 0}
                  disabled={readOnly}
                  onChange={(event) => setQuantities({ ...quantities, [product.catalog_id]: Number(event.target.value) })}
                  className="h-10 w-20 rounded-lg border border-slate-200 bg-white px-2 text-right text-sm font-semibold"
                />
              </div>
            </div>
          ))}
        </div>
        <textarea
          value={note}
          disabled={readOnly}
          onChange={(event) => setNote(event.target.value)}
          placeholder="Sipariş notu"
          className="mt-4 min-h-24 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400"
        />
        {result && <div className="mt-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-700">{result}</div>}
        <button
          type="submit"
          disabled={readOnly || submitting || selectedItems.length === 0}
          className="mt-4 w-full rounded-xl bg-slate-950 px-4 py-3 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-300"
        >
          {readOnly ? 'Önizleme modunda kapalı' : submitting ? 'Gönderiliyor...' : 'Talebi gönder'}
        </button>
      </form>
    </div>
  )
}

function ProductsView({ products, readOnly }: { products: Product[], readOnly: boolean }) {
  return (
    <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
      {products.map((product) => (
        <article key={product.catalog_id} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{product.category}</p>
          <h2 className="mt-2 text-lg font-semibold text-slate-950">{product.name}</h2>
          <p className="mt-1 text-sm text-slate-500">{product.model}</p>
          <span className="mt-4 inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">{product.stock_label}</span>
          <p className="mt-3 text-sm leading-6 text-slate-600">{product.order_note}</p>
          <Link
            href={readOnly ? '#' : '/partner/orders'}
            onClick={(event) => {
              if (readOnly) {
                event.preventDefault()
              }
            }}
            className="mt-4 inline-flex rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700"
          >
            Siparişe ekle
          </Link>
        </article>
      ))}
    </section>
  )
}

function ServiceJobsView({ jobs }: { jobs: ServiceJob[] }) {
  return (
    <section className="grid gap-3">
      {jobs.length === 0 && <EmptyState title="Atanmış iş yok" message="Bu partner kapsamındaki aktif usta işlerinde kayıt bulunamadı." />}
      {jobs.map((job) => (
        <article key={job.id} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h2 className="text-lg font-semibold text-slate-950">{job.mrn}</h2>
              <p className="mt-1 text-sm text-slate-500">{job.service_type ?? 'Servis'} · {[job.customer_city, job.customer_district].filter(Boolean).join(' / ') || '-'}</p>
            </div>
            <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">{job.workflow_status ?? job.status ?? '-'}</span>
          </div>
          <div className="mt-4 grid gap-3 md:grid-cols-2">
            <InfoTile label="Müşteri" value={job.customer_name ?? '-'} />
            <InfoTile label="İletişim" value={job.customer_phone ?? '-'} />
            <InfoTile label="Adres" value={job.address_summary ?? '-'} />
            <InfoTile label="Ürün" value={[job.product_name, job.product_model].filter(Boolean).join(' / ') || '-'} />
            <InfoTile label="Randevu" value={job.scheduled_at ?? job.scheduled_date ?? '-'} />
            <InfoTile label="Sonraki aksiyon" value={job.next_action ?? '-'} />
          </div>
        </article>
      ))}
    </section>
  )
}

function EarningsView({ earnings }: { earnings: PartnerPortalProps['partnerPortal']['earnings'] }) {
  return (
    <div className="grid gap-5">
      <section className="grid gap-3 md:grid-cols-4">
        <StatCard title="Tamamlanan iş" value={earnings.summary.job_count} hint="Dönem toplamı" />
        <StatCard title="İşçilik" value={money.format(earnings.summary.labor_total)} hint="Hesaplanan" />
        <StatCard title="Yol" value={money.format(earnings.summary.travel_fee_total)} hint="Hesaplanan" />
        <StatCard title="Toplam" value={money.format(earnings.summary.grand_total)} hint="Hakediş" />
      </section>
      <section className="grid gap-3">
        {earnings.rows.length === 0 && <EmptyState title="Hakediş kaydı yok" message="Teknik Servis hakediş dönemi oluştuğunda burada görünecek." />}
        {earnings.rows.map((row) => (
          <article key={row.id} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div>
                <h2 className="font-semibold text-slate-950">{row.period ?? 'Dönem yok'}</h2>
                <p className="text-sm text-slate-500">{row.job_count} iş · {row.status}</p>
              </div>
              <div className="text-right font-semibold text-slate-950">{money.format(row.grand_total)}</div>
            </div>
            <div className="mt-4 grid gap-2">
              {row.items.map((item, index) => (
                <div key={`${row.id}-${item.mrn ?? index}`} className="rounded-xl bg-slate-50 px-3 py-2 text-sm">
                  <div className="flex flex-wrap justify-between gap-2">
                    <span className="font-semibold text-slate-900">{item.mrn ?? '-'}</span>
                    <span>{money.format(item.line_total)}</span>
                  </div>
                  <div className="mt-1 text-slate-500">{[item.job_date, item.city, item.district].filter(Boolean).join(' · ') || '-'}</div>
                </div>
              ))}
            </div>
          </article>
        ))}
      </section>
    </div>
  )
}

function SettingsView({ partner, settings, message }: { partner: PartnerSummary, settings: PartnerPortalProps['partnerPortal']['settings'], message: string }) {
  return (
    <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_380px]">
      <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 className="text-lg font-semibold text-slate-950">Firma bilgileri</h2>
        <p className="mt-1 text-sm text-slate-500">{message}</p>
        <dl className="mt-4 grid gap-3 sm:grid-cols-2">
          <InfoTile label="Firma adı" value={partner.display_name} />
          <InfoTile label="Telefon" value={partner.phone ?? '-'} />
          <InfoTile label="E-posta" value={partner.email ?? '-'} />
          <InfoTile label="İl / ilçe" value={locationLabel(partner)} />
          <InfoTile label="Açık adres" value={partner.address ?? '-'} />
        </dl>
      </section>
      <aside className="grid gap-5 lg:sticky lg:top-4 lg:self-start">
        <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <h3 className="text-sm font-semibold text-slate-950">Yetkili kullanıcılar</h3>
          <div className="mt-3 grid gap-2">
            {settings.users.length === 0 && <p className="text-sm text-slate-500">Aktif kullanıcı yok.</p>}
            {settings.users.map((user, index) => (
              <div key={`${user.username ?? index}`} className="rounded-xl bg-slate-50 px-3 py-2 text-sm">
                <div className="font-semibold text-slate-900">{user.name ?? user.username ?? '-'}</div>
                <div className="text-slate-500">{[user.title, user.phone].filter(Boolean).join(' · ') || '-'}</div>
              </div>
            ))}
          </div>
        </section>
        {partner.child_accounts.length > 0 && (
          <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 className="text-sm font-semibold text-slate-950">Operasyon alt hesapları</h3>
            <div className="mt-3 grid gap-2">
              {partner.child_accounts.map((child, index) => (
                <div key={`${child.usage_type ?? index}`} className="rounded-xl bg-slate-50 px-3 py-2 text-sm">
                  <div className="font-semibold text-slate-900">{child.label ?? 'Alt hesap'}</div>
                  <div className="text-slate-500">{child.invoice_usage_note}</div>
                </div>
              ))}
            </div>
          </section>
        )}
      </aside>
    </div>
  )
}

function StatCard({ title, value, hint }: { title: string, value: number | string, hint: string }) {
  return (
    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{title}</p>
      <p className="mt-2 text-2xl font-semibold text-slate-950">{value}</p>
      <p className="mt-1 text-sm text-slate-500">{hint}</p>
    </div>
  )
}

function ActionPanel({ title, message, actions, partnerId }: { title: string, message: string, actions: Array<[string, string]>, partnerId: number }) {
  return (
    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <h2 className="text-lg font-semibold text-slate-950">{title}</h2>
      <p className="mt-2 text-sm leading-6 text-slate-600">{message}</p>
      <div className="mt-4 flex flex-wrap gap-2">
        {actions.map(([label, href]) => (
          <Link key={href} href={portalHref(href, partnerId)} className="rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700">
            {label}
          </Link>
        ))}
      </div>
    </section>
  )
}

function InfoTile({ label, value }: { label: string, value: string | number | null }) {
  return (
    <div className="rounded-xl bg-slate-50 px-3 py-2">
      <dt className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{label}</dt>
      <dd className="mt-1 break-words text-sm font-semibold text-slate-900">{value || '-'}</dd>
    </div>
  )
}

function EmptyState({ title, message }: { title: string, message: string }) {
  return (
    <div className="rounded-2xl border border-dashed border-slate-200 bg-white p-6 text-center">
      <h2 className="text-base font-semibold text-slate-950">{title}</h2>
      <p className="mt-1 text-sm text-slate-500">{message}</p>
    </div>
  )
}

export default PortalShell
