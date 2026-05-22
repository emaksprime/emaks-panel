import { Head, Link } from '@inertiajs/react'
import { useMemo, useState } from 'react'
import type { FormEvent, ReactNode } from 'react'
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
  status_label: string | null
  service_stage_label: string | null
  customer_name: string | null
  customer_phone: string | null
  city: string | null
  district: string | null
  address_summary: string | null
  product_name: string | null
  product_model: string | null
  model: string | null
  serial_no: string | null
  service_type: string | null
  scheduled_at: string | null
  scheduled_date: string | null
  appointment_at: string | null
  appointment_label: string | null
  priority: string | null
  status: string | null
  workflow_status: string | null
  next_action: string | null
  route_distance_summary: string | null
  payment_status_summary: string | null
  maps_link: string | null
  customer_tel_link: string | null
  checklist_status: string | null
  checklist_payload: Record<string, boolean>
  photo_counts: { before: number, after: number, general: number }
  photos: Array<{ id: number, label: string | null, category: string | null, field_code: string | null, preview_url?: string | null, review_status?: string | null, review_note?: string | null }>
  latest_partner_action: { action: string, status: string, note: string | null, payload?: Record<string, unknown>, created_at: string | null } | null
  portal_actions: Array<{ id?: number, action: string, status: string, note: string | null, payload?: Record<string, unknown>, created_at: string | null }>
  appointment_proposal: { id: number, status: string, note: string | null, payload: Record<string, unknown>, created_at: string | null } | null
  rejection: { id: number, status: string, note: string | null, payload: Record<string, unknown>, created_at: string | null } | null
  support_request: { id: number, status: string, note: string | null, payload: Record<string, unknown>, created_at: string | null } | null
  price_revision_request?: { id: number, status: string, note: string | null, payload: Record<string, unknown>, created_at: string | null } | null
  customer_otp_request: { id: number, status: string, note: string | null, payload: Record<string, unknown>, created_at: string | null } | null
  customer_confirmation?: { id: number, status: string, approved_at: string | null, rejected_at?: string | null, customer_note: string | null, approval_url: string | null } | null
  completion_submission: { id: number, status: string, note: string | null, payload: Record<string, unknown>, created_at: string | null } | null
  assignment_offer: {
    id: number
    labor_amount: number
    route_fee_amount: number
    total_amount: number
    currency: string
    status: string
    note: string | null
    sent_at: string | null
    message_payload?: Record<string, unknown> | null
  } | null
  earning_summary: {
    labor_amount: number
    route_fee_amount: number
    total_amount: number
    status: string | null
  }
  completion_requirements: {
    door_photos_required: number
    door_photos_uploaded: number
    photos_ready: boolean
    customer_confirmation_ready: boolean
    checklist_required: boolean
    ops_final_check_required: boolean
    required_photo_labels?: string[]
    missing_photo_labels?: string[]
    photo_statuses?: Array<{ field: string, label: string, uploaded: boolean }>
  }
  badges: string[]
  card_priority: number
  card_tone: 'blue' | 'green' | 'amber' | 'slate' | 'violet' | 'rose'
  kanban_column: 'new_jobs' | 'appointment_confirmed' | 'revisit' | 'final_check' | 'completed'
  action_state?: string
  can_accept: boolean
  can_propose_appointment?: boolean
  can_request_revisit: boolean
  can_request_support?: boolean
  can_request_price_revision?: boolean
  can_request_customer_otp?: boolean
  can_upload_photos?: boolean
  can_submit_completion: boolean
  can_complete_directly: boolean
  can_reject: boolean
  updated_at: string | null
}

type ServiceJobColumn = {
  key: ServiceJob['kanban_column']
  label: string
  tone: 'blue' | 'green' | 'amber' | 'slate' | 'violet' | 'rose'
  count: number
  jobs: ServiceJob[]
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

type PendingEarningRow = {
  id: number
  mrn: string | null
  scheduled_at: string | null
  labor_amount: number
  travel_fee_amount: number
  line_total: number
  status: string
  offer_status: string | null
  city: string | null
  district: string | null
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
    serviceJobBoard: {
      columns: ServiceJobColumn[]
      total: number
    }
    earnings: {
      status: string
      rows: EarningRow[]
      pending?: {
        rows: PendingEarningRow[]
        summary: {
          job_count: number
          labor_total: number
          travel_fee_total: number
          grand_total: number
        }
        note?: string
      }
      completed?: {
        rows: EarningRow[]
        summary: {
          job_count: number
          labor_total: number
          travel_fee_total: number
          grand_total: number
        }
        note?: string
      }
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

const numericAmount = (amount: number | string | null | undefined): number => {
  const parsed = Number(amount ?? 0)

  return Number.isFinite(parsed) ? parsed : 0
}

const jobEarningTotal = (job: ServiceJob): number => {
  return numericAmount(job.assignment_offer?.total_amount ?? job.earning_summary.total_amount)
}

const jobEarningLabor = (job: ServiceJob): number => {
  return numericAmount(job.assignment_offer?.labor_amount ?? job.earning_summary.labor_amount)
}

const jobEarningRoute = (job: ServiceJob): number => {
  return numericAmount(job.assignment_offer?.route_fee_amount ?? job.earning_summary.route_fee_amount)
}

const jobEarningStatus = (job: ServiceJob): string => {
  if (job.assignment_offer?.status) {
    return job.assignment_offer.status
  }

  return jobEarningTotal(job) > 0 ? 'tahmini' : 'gönderilmedi'
}

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
          <ServiceJobsView board={partnerPortal.serviceJobBoard} readOnly={isPreview} />
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

const serviceJobColumns: Array<Omit<ServiceJobColumn, 'count' | 'jobs'>> = [
  { key: 'new_jobs', label: 'Yeni işler', tone: 'blue' },
  { key: 'appointment_confirmed', label: 'Randevu onaylandı', tone: 'green' },
  { key: 'revisit', label: 'Tekrar ziyaret', tone: 'amber' },
  { key: 'final_check', label: 'Son kontrol bekliyor', tone: 'violet' },
  { key: 'completed', label: 'Tamamlanan işler', tone: 'slate' },
]

const columnToneClass = (tone: ServiceJobColumn['tone']) => ({
  blue: 'border-blue-100 bg-blue-50 text-blue-800',
  green: 'border-emerald-100 bg-emerald-50 text-emerald-800',
  amber: 'border-amber-100 bg-amber-50 text-amber-800',
  violet: 'border-violet-100 bg-violet-50 text-violet-800',
  rose: 'border-rose-100 bg-rose-50 text-rose-800',
  slate: 'border-slate-200 bg-slate-50 text-slate-700',
}[tone])

const cardToneClass = (tone: ServiceJob['card_tone']) => ({
  blue: 'border-blue-200 bg-blue-50/80',
  green: 'border-emerald-200 bg-emerald-50/80',
  amber: 'border-amber-200 bg-amber-50/80',
  violet: 'border-violet-200 bg-violet-50/80',
  rose: 'border-rose-200 bg-rose-50/80',
  slate: 'border-slate-200 bg-white',
}[tone])

const actionLabel = (action: string) => ({
  accepted: 'Randevu onaylandı',
  appointment_accepted_by_technician: 'Randevu onaylandı',
  appointment_proposed: 'Randevu önerildi',
  job_rejected: 'İş reddedildi',
  revisit_requested: 'Tekrar ziyaret istendi',
  completion_submitted: 'Tamamlama gönderildi',
  customer_otp_requested: 'Müşteri onayı istendi',
  support_requested: 'Ek talep',
  note_added: 'Not eklendi',
}[action] ?? action)

const tomorrowDateValue = () => {
  const date = new Date()
  date.setDate(date.getDate() + 1)

  return date.toISOString().slice(0, 10)
}

const appointmentSlotLabels = (payload: Record<string, unknown> | undefined) => {
  const slots = Array.isArray(payload?.slots) ? payload.slots : []

  return slots
    .filter((slot): slot is Record<string, unknown> => typeof slot === 'object' && slot !== null)
    .map((slot) => String(slot.label ?? [slot.date, `${slot.start_time ?? ''}-${slot.end_time ?? ''}`].filter(Boolean).join(' ')))
    .filter(Boolean)
}

const nestedStringValue = (source: Record<string, unknown> | undefined, key: string): string | null => {
  const value = source?.[key]

  return typeof value === 'string' && value.trim() !== '' ? value : null
}

const portalPhotoFields = [
  ['before_photo', 'Öncesi'],
  ['after_photo', 'Sonrası'],
  ['warranty_document_photo', 'Garanti Belgesi'],
] as const

const appointmentSlotOptions = [
  '10:00-11:00',
  '11:00-12:00',
  '12:00-13:00',
  '13:00-14:00',
  '14:00-15:00',
  '15:00-16:00',
  '16:00-17:00',
] as const

type AppointmentSlotValue = (typeof appointmentSlotOptions)[number]
type AppointmentSlotDraft = { date: string, slot: AppointmentSlotValue }

const slotTimeRange = (slot: AppointmentSlotValue) => {
  const [start_time, end_time] = slot.split('-')

  return { start_time, end_time }
}

const slotValidationMessage = (slots: AppointmentSlotDraft[]) => {
  if (slots.length < 1) {
    return 'En az bir randevu saati önerin.'
  }

  if (slots.length > 3) {
    return 'En fazla 3 randevu saati önerilebilir.'
  }

  const today = new Date()
  today.setHours(0, 0, 0, 0)
  const sorted = [...slots].sort((a, b) => `${a.date} ${a.slot}`.localeCompare(`${b.date} ${b.slot}`))

  for (let index = 0; index < sorted.length; index += 1) {
    const slot = sorted[index]
    const range = slotTimeRange(slot.slot)

    if (!slot.date || !slot.slot) {
      return 'Tarih ve randevu saati zorunlu.'
    }

    if (new Date(`${slot.date}T00:00:00`).getTime() < today.getTime()) {
      return 'Geçmiş tarih için randevu önerilemez.'
    }

    const previous = sorted[index - 1]

    if (previous && previous.date === slot.date && range.start_time < slotTimeRange(previous.slot).end_time) {
      return 'Randevu saatleri aynı gün içinde çakışamaz.'
    }
  }

  return null
}

function ServiceJobsView({ board, readOnly }: { board: PartnerPortalProps['partnerPortal']['serviceJobBoard'], readOnly: boolean }) {
  const initialJobs = useMemo(() => board.columns.flatMap((column) => column.jobs), [board.columns])
  const [jobs, setJobs] = useState<ServiceJob[]>(initialJobs)
  const [selectedJobId, setSelectedJobId] = useState<number | null>(null)
  const [detailOpen, setDetailOpen] = useState(false)
  const [message, setMessage] = useState<string | null>(null)
  const selectedJob = selectedJobId === null ? null : jobs.find((job) => job.id === selectedJobId) ?? null
  const columns = serviceJobColumns.map((column) => {
    const columnJobs = jobs
      .filter((job) => job.kanban_column === column.key)
      .sort((a, b) => (a.card_priority - b.card_priority) || String(b.updated_at ?? '').localeCompare(String(a.updated_at ?? '')))

    return { ...column, count: columnJobs.length, jobs: columnJobs }
  })

  const updateJob = (job: ServiceJob) => {
    setJobs((current) => current.map((item) => (item.id === job.id ? job : item)))
    setSelectedJobId(job.id)
  }

  const openJob = (job: ServiceJob) => {
    setSelectedJobId(job.id)
    setDetailOpen(true)
  }

  return (
    <div className="grid gap-5">
      {message && <div className="rounded-2xl border border-emerald-100 bg-emerald-50 p-4 text-sm font-semibold text-emerald-800">{message}</div>}
      {jobs.length === 0 && <EmptyState title="Atanmış iş yok" message="Bu partner kapsamındaki aktif usta işlerinde kayıt bulunamadı." />}
      <section className="grid gap-4 lg:grid-cols-4">
        {columns.map((column) => (
          <div key={column.key} className={`min-w-0 rounded-2xl border p-3 ${columnToneClass(column.tone)}`}>
            <div className="flex items-center justify-between gap-3">
              <h2 className="text-sm font-semibold">{column.label}</h2>
              <span className="rounded-full bg-white/80 px-2 py-0.5 text-xs font-semibold">{column.count}</span>
            </div>
            <div className="mt-3 grid gap-3">
              {column.jobs.length === 0 ? (
                <p className="rounded-xl bg-white/70 p-3 text-xs text-slate-500">Bu kolonda iş yok.</p>
              ) : column.jobs.map((job) => (
                <button
                  key={job.id}
                  type="button"
                  onClick={() => openJob(job)}
                  className={`w-full rounded-xl border p-3 text-left shadow-sm transition hover:border-slate-300 ${cardToneClass(job.card_tone)} ${selectedJob?.id === job.id ? 'ring-2 ring-slate-900' : ''}`}
                >
                  <div className="flex items-start justify-between gap-2">
                    <span className="font-semibold text-slate-950">{job.mrn}</span>
                    <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600">{job.service_stage_label ?? job.status_label ?? '-'}</span>
                  </div>
                  <p className="mt-2 text-sm font-medium text-slate-800">{job.customer_name ?? 'Müşteri'}</p>
                  <p className="mt-1 text-xs text-slate-500">{[job.city, job.district].filter(Boolean).join(' / ') || '-'}</p>
                  <p className="mt-1 text-xs font-semibold text-slate-600">Randevu: {job.appointment_label ?? job.appointment_at ?? '-'}</p>
                  <div className="mt-2 rounded-lg border border-emerald-100 bg-white/80 px-2.5 py-2 text-xs text-emerald-900">
                    <div className="flex items-center justify-between gap-2">
                      <span className="font-semibold">Hakediş</span>
                      <strong>{jobEarningTotal(job) > 0 ? money.format(jobEarningTotal(job)) : 'Gönderilmedi'}</strong>
                    </div>
                    <div className="mt-1 flex items-center justify-between gap-2 text-[11px] text-emerald-700">
                      <span>İşçilik {money.format(jobEarningLabor(job))}</span>
                      <span>Yol {money.format(jobEarningRoute(job))}</span>
                    </div>
                    <p className="mt-1 text-[11px] font-semibold text-emerald-700">Durum: {jobEarningStatus(job)}</p>
                  </div>
                  <p className="mt-2 line-clamp-2 text-xs text-slate-500">{job.next_action ?? 'Aksiyon bekleniyor'}</p>
                  {job.badges.length > 0 && (
                    <div className="mt-2 flex flex-wrap gap-1">
                      {job.badges.map((badge) => (
                        <span key={badge} className="rounded-full bg-white/80 px-2 py-0.5 text-[11px] font-semibold text-slate-700">{badge}</span>
                      ))}
                    </div>
                  )}
                </button>
              ))}
            </div>
          </div>
        ))}
      </section>
      {selectedJob && detailOpen && (
        <div className="fixed inset-0 z-50 overflow-y-auto bg-slate-950/45 p-0 sm:p-4 lg:p-6">
          <div className="absolute inset-0" onClick={() => setDetailOpen(false)} />
          <div className="relative z-10 mx-auto flex min-h-screen w-full max-w-6xl flex-col overflow-hidden bg-white shadow-2xl sm:min-h-0 sm:max-h-[calc(100vh-2rem)] lg:max-h-[calc(100vh-3rem)] sm:rounded-3xl">
            <div className="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-slate-200 bg-white px-4 py-4">
              <div className="min-w-0">
                <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">İş detayı</p>
                <h2 className="mt-1 text-xl font-semibold text-slate-950">{selectedJob.mrn}</h2>
              </div>
              <button
                type="button"
                onClick={() => setDetailOpen(false)}
                className="inline-flex h-10 w-10 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100"
                aria-label="İş detayını kapat"
              >
                ×
              </button>
            </div>
            <div className="min-h-0 flex-1 overflow-y-auto px-4 py-4 pb-24 sm:pb-4">
              <ServiceJobDetail
                job={selectedJob}
                readOnly={readOnly}
                onJobUpdated={updateJob}
                onMessage={setMessage}
              />
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

function ServiceJobDetail({
  job,
  readOnly,
  onJobUpdated,
  onMessage,
}: {
  job: ServiceJob
  readOnly: boolean
  onJobUpdated: (job: ServiceJob) => void
  onMessage: (message: string | null) => void
}) {
  const [actionLoading, setActionLoading] = useState<string | null>(null)
  const [acceptNote, setAcceptNote] = useState('')
  const [appointmentSlots, setAppointmentSlots] = useState<AppointmentSlotDraft[]>([{ date: tomorrowDateValue(), slot: '10:00-11:00' }])
  const [proposalNote, setProposalNote] = useState('')
  const [revisitReason, setRevisitReason] = useState('')
  const [rejectReason, setRejectReason] = useState('not_available')
  const [rejectNote, setRejectNote] = useState('')
  const [completionNote, setCompletionNote] = useState('')
  const [completionResult, setCompletionResult] = useState('completed')
  const [note, setNote] = useState('')
  const [photoFiles, setPhotoFiles] = useState<Record<string, File | null>>({})
  const [otpNote, setOtpNote] = useState('')
  const [supportType, setSupportType] = useState('spare_part')
  const [supportDescription, setSupportDescription] = useState('')
  const [supportProduct, setSupportProduct] = useState('')
  const [supportQuantity, setSupportQuantity] = useState('')
  const [priceLaborAmount, setPriceLaborAmount] = useState(job.assignment_offer?.labor_amount ? String(job.assignment_offer.labor_amount) : '')
  const [priceRouteAmount, setPriceRouteAmount] = useState(job.assignment_offer?.route_fee_amount ? String(job.assignment_offer.route_fee_amount) : '')
  const [priceRevisionNote, setPriceRevisionNote] = useState('')
  const [activeActionDialog, setActiveActionDialog] = useState<'reject' | 'revisit' | 'otp' | 'support' | 'price' | null>(null)
  const photosReady = job.completion_requirements.photos_ready
  const confirmationReady = job.completion_requirements.customer_confirmation_ready
  const otpPayload = job.customer_otp_request?.payload
  const messagePayload = typeof otpPayload?.message_payload === 'object' && otpPayload.message_payload !== null
    ? otpPayload.message_payload as Record<string, unknown>
    : undefined
  const approvalUrl = job.customer_confirmation?.approval_url
    ?? nestedStringValue(otpPayload, 'approval_url')
    ?? nestedStringValue(messagePayload, 'approval_url')
  const whatsappUrl = nestedStringValue(messagePayload, 'whatsapp_url')
  const confirmationMessageText = nestedStringValue(messagePayload, 'message_text')
  const missingPhotoLabels = job.completion_requirements.missing_photo_labels ?? []
  const completionMissingReasons = [
    ...missingPhotoLabels.map((label) => `${label} eksik`),
    ...(confirmationReady ? [] : ['Müşteri onayı bekleniyor']),
    ...(completionNote.trim().length >= 3 ? [] : ['İşlem notu gerekli']),
  ]
  const completionBlocked = completionMissingReasons.length > 0
  const showPhotoSection = Boolean(job.can_upload_photos || ['final_check', 'completed'].includes(job.kanban_column))
  const photoStatuses = job.completion_requirements.photo_statuses ?? portalPhotoFields.map(([field, label]) => ({
    field,
    label,
    uploaded: (field === 'before_photo' && job.photo_counts.before > 0)
      || (field === 'after_photo' && job.photo_counts.after > 0)
      || (field === 'warranty_document_photo' && job.photo_counts.general > 0),
  }))
  const appointmentSlotError = slotValidationMessage(appointmentSlots)
  const canAcceptAppointment = Boolean(job.can_accept)
  const canProposeAppointment = Boolean(job.can_propose_appointment ?? true)
  const canOnlyAddNote = ['final_check_waiting', 'rejected_ops_review', 'completed'].includes(job.action_state ?? '')
  const statusPlan = (() => {
    if (job.action_state === 'rejected_ops_review') {
      return 'İş reddi operasyona iletildi. Bu aşamada sadece not ekleyebilirsiniz.'
    }

    if (job.action_state === 'final_check_waiting') {
      return 'İşlem operasyonda son kontrolde. Onay gelmeden tamamlanan/hakediş kesinleşmiş sayılmaz.'
    }

    if (job.action_state === 'appointment_waiting_technician_accept') {
      return 'Operasyon randevu verdi. Bu aşamada randevuyu onaylayabilir, revize için yeni saat önerebilir veya işi reddedebilirsiniz.'
    }

    if (job.action_state === 'appointment_proposed_waiting') {
      return 'Randevu önerisi operasyon onayı bekliyor. Gerekirse yeni saat önerisi gönderebilirsiniz.'
    }

    if (job.kanban_column === 'appointment_confirmed') {
      return 'Randevu onaylandı. Fotoğrafları, müşteri onayını ve ara talepleri bu ekrandan yönetin.'
    }

    if (job.kanban_column === 'revisit') {
      return 'Tekrar ziyaret veya ara işlem bekliyor. Yeni saat önerisi, ek talep ve not gönderebilirsiniz.'
    }

    if (job.kanban_column === 'completed') {
      return 'İş tamamlandı. Aksiyon kapalı; hakediş durumunu görüntüleyebilirsiniz.'
    }

    return 'Randevu tarihi ve saat aralığı önerin. Operasyon onaylayınca iş randevu onaylandı aşamasına geçer.'
  })()

  const submitAction = async (action: string, payload: Record<string, unknown>, successMessage: string) => {
    if (readOnly) {
      onMessage('Önizleme modunda işlem yapılamaz.')

      return
    }

    setActionLoading(action)
    onMessage(null)

    try {
      const response = await apiRequest(`/api/partner/service-jobs/${job.id}/${action}`, {
        method: 'POST',
        body: JSON.stringify(payload),
      }) as { job?: ServiceJob }

      if (response.job) {
        onJobUpdated(response.job)
      }

      onMessage(successMessage)
    } catch (error) {
      onMessage(error instanceof Error ? error.message : 'İşlem tamamlanamadı.')
    } finally {
      setActionLoading(null)
    }
  }

  const updateAppointmentSlot = (index: number, patch: Partial<AppointmentSlotDraft>) => {
    setAppointmentSlots((current) => current.map((slot, slotIndex) => (slotIndex === index ? { ...slot, ...patch } : slot)))
  }

  const addAppointmentSlot = () => {
    setAppointmentSlots((current) => (
      current.length >= 3 ? current : [...current, { date: tomorrowDateValue(), slot: '10:00-11:00' }]
    ))
  }

  const removeAppointmentSlot = (index: number) => {
    setAppointmentSlots((current) => current.length <= 1 ? current : current.filter((_, slotIndex) => slotIndex !== index))
  }

  const submitPhotoUpload = async () => {
    if (readOnly) {
      onMessage('Önizleme modunda işlem yapılamaz.')

      return
    }

    const entries = Object.entries(photoFiles).filter(([, file]) => file instanceof File)

    if (entries.length === 0) {
      onMessage('Yüklenecek fotoğraf seçin.')

      return
    }

    const formData = new FormData()
    entries.forEach(([field, file]) => {
      if (file) {
        formData.append(field, file)
      }
    })

    setActionLoading('photos')
    onMessage(null)

    try {
      const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
      const response = await fetch(`/api/partner/service-jobs/${job.id}/photos`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          ...(token ? { 'X-CSRF-TOKEN': token } : {}),
        },
        body: formData,
      })

      if (!response.ok) {
        throw new Error('Fotoğraf yüklenemedi.')
      }

      const payload = await response.json() as { job?: ServiceJob }

      if (payload.job) {
        onJobUpdated(payload.job)
      }

      setPhotoFiles({})
      onMessage('Fotoğraflar yüklendi.')
    } catch (error) {
      onMessage(error instanceof Error ? error.message : 'Fotoğraf yüklenemedi.')
    } finally {
      setActionLoading(null)
    }
  }

  return (
    <section className="grid gap-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm lg:grid-cols-[minmax(0,1fr)_400px] lg:p-5">
      <div className="min-w-0">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">İş detayı</p>
            <h2 className="mt-1 text-2xl font-semibold text-slate-950">{job.mrn}</h2>
            <p className="mt-1 text-sm text-slate-500">{job.service_stage_label ?? job.status_label ?? '-'}</p>
            {job.badges.length > 0 && (
              <div className="mt-2 flex flex-wrap gap-1">
                {job.badges.map((badge) => (
                  <span key={badge} className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">{badge}</span>
                ))}
              </div>
            )}
          </div>
          {job.latest_partner_action && (
            <span className="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">
              {actionLabel(job.latest_partner_action.action)}
            </span>
          )}
        </div>
        <div className="mt-5 grid gap-3 md:grid-cols-2">
          <InfoTile label="Müşteri" value={job.customer_name ?? '-'} />
          <InfoTile label="Telefon" value={job.customer_tel_link ? <a className="font-semibold text-blue-700 hover:underline" href={job.customer_tel_link}>{job.customer_phone ?? 'Ara'}</a> : job.customer_phone ?? '-'} />
          <InfoTile label="Adres" value={job.address_summary ?? '-'} />
          <InfoTile label="Harita" value={job.maps_link ? <a className="font-semibold text-blue-700 hover:underline" href={job.maps_link} target="_blank" rel="noreferrer">Google Maps aç</a> : '-'} />
          <InfoTile label="Konum" value={[job.city, job.district].filter(Boolean).join(' / ') || '-'} />
          <InfoTile label="Ürün" value={[job.product_name, job.model].filter(Boolean).join(' / ') || '-'} />
          <InfoTile label="Aktivasyon / seri" value={job.serial_no ?? '-'} />
          <InfoTile label="Yol" value={job.route_distance_summary ?? '-'} />
        </div>
        <div className="mt-5 grid gap-3 md:grid-cols-2">
          <div className="rounded-2xl bg-emerald-50 p-4 text-sm text-emerald-950">
            <p className="text-xs font-semibold uppercase tracking-wide text-emerald-700">Hakediş</p>
            {job.assignment_offer || jobEarningTotal(job) > 0 ? (
              <div className="mt-3 grid gap-2">
                <div className="flex justify-between gap-3"><span>İşçilik / montaj</span><strong>{money.format(jobEarningLabor(job))}</strong></div>
                <div className="flex justify-between gap-3"><span>Usta yol hakedişi</span><strong>{money.format(jobEarningRoute(job))}</strong></div>
                <div className="flex justify-between gap-3 border-t border-emerald-200 pt-2"><span>Toplam</span><strong>{money.format(jobEarningTotal(job))}</strong></div>
                <p className="text-xs text-emerald-700">Durum: {jobEarningStatus(job)}</p>
                {job.assignment_offer?.note && <p className="text-xs text-emerald-800">{job.assignment_offer.note}</p>}
                {job.price_revision_request?.status === 'ops_review' && (
                  <p className="rounded-lg bg-rose-100 px-2 py-1 text-xs font-semibold text-rose-800">Hakediş revize talebi operasyon incelemesinde.</p>
                )}
              </div>
            ) : (
              <p className="mt-2 text-sm text-emerald-800">Bu iş için hakediş bilgisi henüz gönderilmedi.</p>
            )}
          </div>
          <div className="rounded-2xl bg-blue-50 p-4 text-sm text-blue-950">
            <p className="text-xs font-semibold uppercase tracking-wide text-blue-700">Randevu / bildirim</p>
            {job.appointment_proposal ? (
              <div className="mt-3 rounded-xl bg-white/80 p-3 text-xs text-blue-900">
                <p className="font-semibold">Randevu önerisi: {job.appointment_proposal.status}</p>
                <div className="mt-1 grid gap-1">
                  {appointmentSlotLabels(job.appointment_proposal.payload).length === 0 ? (
                    <p>-</p>
                  ) : appointmentSlotLabels(job.appointment_proposal.payload).map((slot) => (
                    <p key={slot}>{slot}</p>
                  ))}
                </div>
              </div>
            ) : (
              <p className="mt-2 text-sm text-blue-800">Henüz portal randevu önerisi yok.</p>
            )}
            {job.rejection && (
              <div className="mt-3 rounded-xl bg-rose-50 p-3 text-xs font-semibold text-rose-800">
                İş reddi operasyona iletildi: {String(job.rejection.payload?.reason_label ?? job.rejection.note ?? '-')}
              </div>
            )}
            {job.assignment_offer?.message_payload ? (
              <div className="mt-3 rounded-xl bg-white/80 p-3 text-xs text-blue-900">
                <p className="font-semibold">Son hakediş mesaj payload'ı hazır.</p>
                <p className="mt-1">Canlı mesaj gönderimi yapılmadı; operasyon onayıyla kullanılır.</p>
              </div>
            ) : null}
          </div>
        </div>
        <div className="mt-5 grid gap-3 md:grid-cols-2">
          <div className="rounded-2xl bg-slate-50 p-4">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Aksiyon planı</p>
            <p className="mt-2 text-sm leading-6 text-slate-700">{statusPlan}</p>
            <div className="mt-3 flex flex-wrap gap-2">
              {job.badges.length === 0 ? (
                <span className="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-slate-600">Normal akış</span>
              ) : job.badges.map((badge) => (
                <span key={badge} className="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-slate-700">{badge}</span>
              ))}
            </div>
          </div>
          {showPhotoSection && (
          <div className="rounded-2xl bg-slate-50 p-4">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Fotoğraf / belge</p>
            <p className="mt-2 text-sm text-slate-700">Öncesi / Sonrası / Garanti Belgesi</p>
            <div className="mt-3 rounded-xl border border-slate-200 bg-white p-3 text-xs text-slate-700">
              <p className="font-semibold">Tamamlama şartı</p>
              <p className="mt-1">{job.completion_requirements.door_photos_uploaded}/{job.completion_requirements.door_photos_required} fotoğraf/belge yüklendi.</p>
              <p className="mt-1">{job.completion_requirements.customer_confirmation_ready ? 'Müşteri onayı hazır.' : 'Müşteri OTP/onay bekliyor.'}</p>
              <div className="mt-2 grid gap-1">
                {photoStatuses.map((photo) => (
                  <p key={photo.field} className={photo.uploaded ? 'font-semibold text-emerald-700' : 'font-semibold text-rose-700'}>
                    {photo.label}: {photo.uploaded ? 'yüklendi' : 'eksik'}
                  </p>
                ))}
              </div>
            </div>
            {job.photos.length === 0 ? (
              <p className="mt-3 text-sm text-slate-500">Mevcut fotoğraf kaydı yok.</p>
            ) : (
              <div className="mt-3 grid gap-2">
                {job.photos.map((photo) => (
                  <div key={photo.id} className="overflow-hidden rounded-xl bg-white text-sm text-slate-600">
                    {photo.preview_url ? (
                      <img src={photo.preview_url} alt={photo.label ?? `Fotoğraf #${photo.id}`} className="h-32 w-full object-cover" />
                    ) : null}
                    <div className="px-3 py-2">
                      <p className="font-semibold text-slate-900">{photo.label ?? `Fotoğraf #${photo.id}`}</p>
                      {photo.review_status ? <p className="text-xs text-slate-500">Ops uygunluk: {photo.review_status}</p> : null}
                      {photo.review_note ? <p className="text-xs text-slate-500">{photo.review_note}</p> : null}
                    </div>
                  </div>
                ))}
              </div>
            )}
            <div className="mt-3 grid gap-2">
              {portalPhotoFields.map(([field, label]) => (
                <label key={field} className="grid gap-1 text-xs font-semibold text-slate-600">
                  {label}
                  <input
                    type="file"
                    accept="image/*"
                    disabled={readOnly || !job.can_upload_photos}
                    onChange={(event) => setPhotoFiles({ ...photoFiles, [field]: event.target.files?.[0] ?? null })}
                    className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"
                  />
                </label>
              ))}
              <button
                type="button"
                disabled={readOnly || !job.can_upload_photos || actionLoading === 'photos' || Object.values(photoFiles).every((file) => !file)}
                onClick={() => void submitPhotoUpload()}
                className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 disabled:cursor-not-allowed disabled:opacity-50"
              >
                Fotoğrafları yükle
              </button>
            </div>
          </div>
          )}
        </div>
        {job.portal_actions.length > 0 && (
          <div className="mt-5 rounded-2xl bg-slate-50 p-4">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Portal aksiyonları</p>
            <div className="mt-3 grid gap-2">
              {job.portal_actions.map((action, index) => (
                <div key={`${action.action}-${action.created_at ?? index}`} className="rounded-xl bg-white px-3 py-2 text-sm">
                  <div className="font-semibold text-slate-900">{actionLabel(action.action)} · {action.status}</div>
                  {action.note && <div className="mt-1 text-slate-500">{action.note}</div>}
                </div>
              ))}
            </div>
          </div>
        )}
      </div>
      <aside className="min-w-0 rounded-2xl border border-slate-200 bg-slate-50 p-4 lg:sticky lg:top-4 lg:self-start">
        <p className="text-sm font-semibold text-slate-950">Bu aşamadaki aksiyonlar</p>
        <p className="mt-1 text-xs leading-5 text-slate-500">{statusPlan}</p>
        {readOnly && <p className="mt-3 rounded-xl bg-amber-50 p-3 text-sm font-semibold text-amber-800">Önizleme modu: işlem yapılamaz.</p>}
        <div className="mt-4 grid gap-4">
          {canAcceptAppointment && (
            <ActionBox title="Randevuyu onayla">
              <p className="rounded-xl bg-white p-3 text-xs font-semibold text-slate-700">Ops randevusu: {job.appointment_label ?? job.appointment_at ?? '-'}</p>
              <textarea className="min-h-20 w-full rounded-xl border border-slate-200 p-3 text-sm" value={acceptNote} onChange={(event) => setAcceptNote(event.target.value)} placeholder="İsteğe bağlı not" disabled={readOnly} />
              <button type="button" disabled={readOnly || actionLoading === 'accept-appointment'} onClick={() => void submitAction('accept-appointment', { note: acceptNote }, 'Randevu onayı gönderildi.')} className="rounded-xl bg-slate-950 px-3 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-300">
                Randevu onayla
              </button>
            </ActionBox>
          )}
          {canProposeAppointment && (
            <ActionBox title={job.action_state === 'appointment_proposed_waiting' ? 'Randevu önerisini revize et' : 'Randevu saatleri öner'}>
            <div className="grid gap-2">
              {appointmentSlots.map((slot, index) => (
                <div key={index} className="rounded-xl border border-slate-200 bg-white p-3">
                  <div className="flex items-center justify-between gap-2">
                    <span className="text-xs font-semibold text-slate-500">Öneri {index + 1}</span>
                    {appointmentSlots.length > 1 && (
                      <button type="button" onClick={() => removeAppointmentSlot(index)} className="text-xs font-semibold text-rose-700" disabled={readOnly}>Kaldır</button>
                    )}
                  </div>
                  <div className="mt-2 grid gap-2 sm:grid-cols-[minmax(0,1fr)_160px]">
                    <input type="date" className="w-full rounded-xl border border-slate-200 p-3 text-sm" value={slot.date} onChange={(event) => updateAppointmentSlot(index, { date: event.target.value })} disabled={readOnly} />
                    <select
                      className="w-full rounded-xl border border-slate-200 p-3 text-sm"
                      value={slot.slot}
                      onChange={(event) => updateAppointmentSlot(index, { slot: event.target.value as AppointmentSlotValue })}
                      disabled={readOnly}
                    >
                      {appointmentSlotOptions.map((option) => (
                        <option key={option} value={option}>{option}</option>
                      ))}
                    </select>
                  </div>
                </div>
              ))}
            </div>
            {appointmentSlotError && <p className="rounded-xl bg-rose-50 p-2 text-xs font-semibold text-rose-700">{appointmentSlotError}</p>}
            <button type="button" disabled={readOnly || appointmentSlots.length >= 3} onClick={addAppointmentSlot} className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 disabled:cursor-not-allowed disabled:opacity-50">
              Randevu saati ekle
            </button>
            <textarea className="min-h-20 w-full rounded-xl border border-slate-200 p-3 text-sm" value={proposalNote} onChange={(event) => setProposalNote(event.target.value)} placeholder="Operasyona randevu notu" disabled={readOnly} />
            <button
              type="button"
              disabled={readOnly || appointmentSlotError !== null || actionLoading === 'appointment-proposal'}
              onClick={() => void submitAction('appointment-proposal', {
                slots: appointmentSlots.map((slot) => ({ ...slot, ...slotTimeRange(slot.slot) })),
                note: proposalNote || null,
              }, 'Randevu önerisi operasyona gönderildi.')}
              className="rounded-xl border border-blue-200 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-800 disabled:cursor-not-allowed disabled:opacity-50"
            >
              Randevu öner
            </button>
          </ActionBox>
          )}
          {job.can_reject && (
          <ActionBox title="İşi reddet">
            <button type="button" disabled={readOnly || !job.can_reject} onClick={() => setActiveActionDialog('reject')} className="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-800 disabled:cursor-not-allowed disabled:opacity-50">
              Reddetme formunu aç
            </button>
          </ActionBox>
          )}
          {job.can_request_revisit && (
          <ActionBox title="Tekrar ziyaret iste">
            <button type="button" disabled={readOnly || !job.can_request_revisit} onClick={() => setActiveActionDialog('revisit')} className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800 disabled:cursor-not-allowed disabled:opacity-50">
              Tekrar ziyaret formunu aç
            </button>
          </ActionBox>
          )}
          {job.can_request_customer_otp && (
          <ActionBox title="Müşteri OTP / onay">
            {approvalUrl || whatsappUrl ? (
              <div className="rounded-xl border border-violet-100 bg-white p-3 text-xs text-slate-600">
                <p className="font-semibold text-violet-900">Müşteri onay mesajı hazırlandı.</p>
                {approvalUrl ? (
                  <a href={approvalUrl} target="_blank" rel="noreferrer" className="mt-2 inline-flex font-semibold text-violet-700 hover:text-violet-900">
                    Onay linkini aç
                  </a>
                ) : null}
                {whatsappUrl ? (
                  <a href={whatsappUrl} target="_blank" rel="noreferrer" className="mt-2 block font-semibold text-emerald-700 hover:text-emerald-900">
                    WhatsApp mesajını aç
                  </a>
                ) : null}
              </div>
            ) : null}
            <button type="button" disabled={readOnly} onClick={() => setActiveActionDialog('otp')} className="rounded-xl border border-violet-200 bg-violet-50 px-3 py-2 text-sm font-semibold text-violet-800 disabled:cursor-not-allowed disabled:opacity-50">
              OTP/onay popup aç
            </button>
          </ActionBox>
          )}
          {job.can_request_support && (
          <ActionBox title="Yedek parça / ek talep">
            <button type="button" disabled={readOnly || !job.can_request_support} onClick={() => setActiveActionDialog('support')} className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800 disabled:cursor-not-allowed disabled:opacity-50">
              Ek talep popup aç
            </button>
          </ActionBox>
          )}
          {job.can_request_price_revision && (
          <ActionBox title="Hakediş revize talebi">
            <button type="button" disabled={readOnly || !job.can_request_price_revision} onClick={() => setActiveActionDialog('price')} className="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-800 disabled:cursor-not-allowed disabled:opacity-50">
              Hakediş revize talep et
            </button>
          </ActionBox>
          )}
          {job.can_submit_completion && (
          <ActionBox title="Tamamlamaya gönder">
            <select className="w-full rounded-xl border border-slate-200 p-3 text-sm" value={completionResult} onChange={(event) => setCompletionResult(event.target.value)} disabled={readOnly || !job.can_submit_completion}>
              <option value="completed">Tamamlandı</option>
              <option value="revisit_required">Tekrar ziyaret gerekli</option>
              <option value="customer_not_available">Müşteri yok</option>
              <option value="missing_info_or_photo">Eksik bilgi/fotoğraf</option>
              <option value="parts_pending">Parça/ürün bekleniyor</option>
            </select>
            <div className="rounded-xl border border-slate-200 bg-white p-3 text-xs text-slate-600">
              <p>{photosReady ? '3 fotoğraf hazır.' : '3 ayrı fotoğraf türü yüklenmeden tamamlamaya gönderilemez.'}</p>
              <p>{confirmationReady ? 'Müşteri onayı hazır.' : 'Müşteri onayı olmadan tamamlamaya gönderilemez.'}</p>
              {completionMissingReasons.length > 0 && (
                <ul className="mt-2 grid gap-1">
                  {completionMissingReasons.map((reason) => (
                    <li key={reason}>- {reason}</li>
                  ))}
                </ul>
              )}
            </div>
            <textarea className="min-h-24 w-full rounded-xl border border-slate-200 p-3 text-sm" value={completionNote} onChange={(event) => setCompletionNote(event.target.value)} placeholder="İşlem notu" disabled={readOnly || !job.can_submit_completion} />
            <button type="button" disabled={readOnly || !job.can_submit_completion || completionBlocked || actionLoading === 'submit-completion'} onClick={() => void submitAction('submit-completion', { result: completionResult, note: completionNote }, 'Tamamlama gönderimi son kontrol için operasyona düştü.')} className="rounded-xl bg-emerald-600 px-3 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-300">
              Tamamlamaya gönder
            </button>
          </ActionBox>
          )}
          {canOnlyAddNote && (
            <div className="rounded-xl border border-slate-200 bg-white p-3 text-sm text-slate-600">
              Bu aşamada işlem kapalı. Operasyona not bırakabilirsiniz.
            </div>
          )}
          <ActionBox title="Operasyona not">
            <textarea className="min-h-20 w-full rounded-xl border border-slate-200 p-3 text-sm" value={note} onChange={(event) => setNote(event.target.value)} placeholder="Not yaz" disabled={readOnly} />
            <button type="button" disabled={readOnly || note.trim().length < 3 || actionLoading === 'note'} onClick={() => void submitAction('note', { note, visibility: 'ops' }, 'Not eklendi.')} className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 disabled:cursor-not-allowed disabled:opacity-50">
              Not ekle
            </button>
          </ActionBox>
        </div>
      </aside>
      <ActionDialog title="İşi reddet" open={activeActionDialog === 'reject'} onClose={() => setActiveActionDialog(null)}>
        <select className="w-full rounded-xl border border-slate-200 p-3 text-sm" value={rejectReason} onChange={(event) => setRejectReason(event.target.value)} disabled={readOnly || !job.can_reject}>
          <option value="not_available">Uygun değilim</option>
          <option value="region_not_suitable">Bölge uygun değil</option>
          <option value="time_not_suitable">Zaman uygun değil</option>
          <option value="customer_unreachable">Müşteriyle iletişim kurulamadı</option>
          <option value="customer_disagreement">Müşteriyle anlaşamadım</option>
          <option value="other">Diğer</option>
        </select>
        <textarea className="min-h-24 w-full rounded-xl border border-slate-200 p-3 text-sm" value={rejectNote} onChange={(event) => setRejectNote(event.target.value)} placeholder="Açıklama zorunlu" disabled={readOnly || !job.can_reject} />
        <button type="button" disabled={readOnly || !job.can_reject || rejectNote.trim().length < 3 || actionLoading === 'reject'} onClick={() => void submitAction('reject', { reason: rejectReason, note: rejectNote }, 'İş reddi operasyona gönderildi.').then(() => setActiveActionDialog(null))} className="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-800 disabled:cursor-not-allowed disabled:opacity-50">
          İşi reddet
        </button>
      </ActionDialog>
      <ActionDialog title="Tekrar ziyaret iste" open={activeActionDialog === 'revisit'} onClose={() => setActiveActionDialog(null)}>
        <textarea className="min-h-24 w-full rounded-xl border border-slate-200 p-3 text-sm" value={revisitReason} onChange={(event) => setRevisitReason(event.target.value)} placeholder="Tekrar ziyaret nedeni" disabled={readOnly || !job.can_request_revisit} />
        <button type="button" disabled={readOnly || !job.can_request_revisit || revisitReason.trim().length < 3 || actionLoading === 'request-revisit'} onClick={() => void submitAction('request-revisit', { reason: revisitReason }, 'Tekrar ziyaret talebi gönderildi.').then(() => setActiveActionDialog(null))} className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800 disabled:cursor-not-allowed disabled:opacity-50">
          Tekrar ziyaret iste
        </button>
      </ActionDialog>
      <ActionDialog title="Müşteri OTP / onay" open={activeActionDialog === 'otp'} onClose={() => setActiveActionDialog(null)}>
        <div className="rounded-xl border border-violet-100 bg-violet-50 p-3 text-sm text-violet-950">
          <p className="font-semibold">Müşteriye onay mesajı hazırlanır.</p>
          <p className="mt-1">Mesajda montajı onaylama bağlantısı olur; canlı SMS/WhatsApp gönderimi yapılmaz.</p>
        </div>
        {confirmationMessageText ? (
          <div className="rounded-xl border border-slate-200 bg-white p-3 text-sm text-slate-700">
            {confirmationMessageText}
          </div>
        ) : null}
        <textarea className="min-h-20 w-full rounded-xl border border-slate-200 p-3 text-sm" value={otpNote} onChange={(event) => setOtpNote(event.target.value)} placeholder="Operasyona not" disabled={readOnly} />
        <button type="button" disabled={readOnly || actionLoading === 'customer-otp-request'} onClick={() => void submitAction('customer-otp-request', { note: otpNote || null }, 'Müşteri onay mesajı ve bağlantısı oluşturuldu.').then(() => setActiveActionDialog(null))} className="rounded-xl border border-violet-200 bg-violet-50 px-3 py-2 text-sm font-semibold text-violet-800 disabled:cursor-not-allowed disabled:opacity-50">
          Onay mesajı oluştur
        </button>
      </ActionDialog>
      <ActionDialog title="Yedek parça / ek talep" open={activeActionDialog === 'support'} onClose={() => setActiveActionDialog(null)}>
        <select className="w-full rounded-xl border border-slate-200 p-3 text-sm" value={supportType} onChange={(event) => setSupportType(event.target.value)} disabled={readOnly}>
          <option value="spare_part">Yedek parça</option>
          <option value="extra_product">Ek ürün</option>
          <option value="missing_info">Eksik bilgi</option>
          <option value="customer_call">Müşteri aransın</option>
          <option value="other">Diğer</option>
        </select>
        <input className="w-full rounded-xl border border-slate-200 p-3 text-sm" value={supportProduct} onChange={(event) => setSupportProduct(event.target.value)} placeholder="Ürün/parça adı" disabled={readOnly} />
        <input type="number" min={1} className="w-full rounded-xl border border-slate-200 p-3 text-sm" value={supportQuantity} onChange={(event) => setSupportQuantity(event.target.value)} placeholder="Adet" disabled={readOnly} />
        <textarea className="min-h-24 w-full rounded-xl border border-slate-200 p-3 text-sm" value={supportDescription} onChange={(event) => setSupportDescription(event.target.value)} placeholder="Açıklama zorunlu" disabled={readOnly} />
        <button
          type="button"
          disabled={readOnly || supportDescription.trim().length < 3 || actionLoading === 'support-request'}
          onClick={() => void submitAction('support-request', {
            type: supportType,
            description: supportDescription,
            product_name: supportProduct || null,
            quantity: supportQuantity ? Number(supportQuantity) : null,
          }, 'Ek talep operasyona gönderildi.').then(() => setActiveActionDialog(null))}
          className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800 disabled:cursor-not-allowed disabled:opacity-50"
        >
          Ek talep gönder
        </button>
      </ActionDialog>
      <ActionDialog title="Hakediş revize talebi" open={activeActionDialog === 'price'} onClose={() => setActiveActionDialog(null)}>
        <input
          type="number"
          min={0}
          step="0.01"
          className="w-full rounded-xl border border-slate-200 p-3 text-sm"
          value={priceLaborAmount}
          onChange={(event) => setPriceLaborAmount(event.target.value)}
          placeholder="Talep edilen işçilik / montaj"
          disabled={readOnly || !job.can_request_price_revision}
        />
        <input
          type="number"
          min={0}
          step="0.01"
          className="w-full rounded-xl border border-slate-200 p-3 text-sm"
          value={priceRouteAmount}
          onChange={(event) => setPriceRouteAmount(event.target.value)}
          placeholder="Talep edilen usta yol hakedişi"
          disabled={readOnly || !job.can_request_price_revision}
        />
        <textarea className="min-h-24 w-full rounded-xl border border-slate-200 p-3 text-sm" value={priceRevisionNote} onChange={(event) => setPriceRevisionNote(event.target.value)} placeholder="Açıklama zorunlu" disabled={readOnly || !job.can_request_price_revision} />
        <button
          type="button"
          disabled={readOnly || !job.can_request_price_revision || priceRevisionNote.trim().length < 3 || actionLoading === 'price-revision-request'}
          onClick={() => void submitAction('price-revision-request', {
            labor_amount: priceLaborAmount ? Number(priceLaborAmount) : null,
            route_fee_amount: priceRouteAmount ? Number(priceRouteAmount) : null,
            note: priceRevisionNote,
          }, 'Hakediş revize talebi operasyona gönderildi.').then(() => setActiveActionDialog(null))}
          className="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-800 disabled:cursor-not-allowed disabled:opacity-50"
        >
          Revize talebi gönder
        </button>
      </ActionDialog>
    </section>
  )
}

function ActionBox({ title, children }: { title: string, children: ReactNode }) {
  return (
    <div className="grid gap-2">
      <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-400">{title}</h3>
      {children}
    </div>
  )
}

function ActionDialog({ title, open, onClose, children }: { title: string, open: boolean, onClose: () => void, children: ReactNode }) {
  if (!open) {
    return null
  }

  return (
    <div className="fixed inset-0 z-[70] grid place-items-end bg-slate-950/45 p-0 sm:place-items-center sm:p-4">
      <button type="button" className="absolute inset-0 cursor-default" aria-label="Popup kapat" onClick={onClose} />
      <div className="relative z-10 grid max-h-[92vh] w-full gap-4 overflow-y-auto rounded-t-3xl bg-white p-4 shadow-2xl sm:max-w-lg sm:rounded-3xl sm:p-5">
        <div className="flex items-center justify-between gap-3">
          <h3 className="text-lg font-semibold text-slate-950">{title}</h3>
          <button type="button" onClick={onClose} className="inline-flex h-9 w-9 items-center justify-center rounded-full text-slate-500 hover:bg-slate-100" aria-label="Popup kapat">
            ×
          </button>
        </div>
        <div className="grid gap-3">
          {children}
        </div>
      </div>
    </div>
  )
}

function EarningsView({ earnings }: { earnings: PartnerPortalProps['partnerPortal']['earnings'] }) {
  const pending = earnings.pending ?? { rows: [], summary: { job_count: 0, labor_total: 0, travel_fee_total: 0, grand_total: 0 } }
  const completed = earnings.completed ?? { rows: earnings.rows, summary: earnings.summary }

  return (
    <div className="grid gap-5">
      <section className="grid gap-3 md:grid-cols-4">
        <StatCard title="Bekleyen iş" value={pending.summary.job_count} hint="Tahmini hakediş" />
        <StatCard title="Bekleyen toplam" value={money.format(pending.summary.grand_total)} hint="Actual hakediş değildir" />
        <StatCard title="Tamamlanan iş" value={completed.summary.job_count} hint="Teknik Servis hakedişi" />
        <StatCard title="Tamamlanan toplam" value={money.format(completed.summary.grand_total)} hint="Hesaplanan" />
      </section>
      <section className="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-amber-950 shadow-sm">
        <h2 className="text-lg font-semibold">Bekleyen tahmini hakedişler</h2>
        <p className="mt-1 text-sm">Bekleyen hakedişler tahmini tutardır. Operasyon son kontrolünden sonra kesin hakedişe dönüşür.</p>
        <div className="mt-4 grid gap-2">
          {pending.rows.length === 0 && <EmptyState title="Bekleyen hakediş yok" message="Planlı veya son kontrol bekleyen teklif bulunmadı." />}
          {pending.rows.map((row) => (
            <div key={row.id} className="rounded-xl bg-white px-3 py-3 text-sm">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="font-semibold text-slate-950">{row.mrn ?? '-'}</span>
                <span className="font-semibold text-slate-950">{money.format(row.line_total)}</span>
              </div>
              <div className="mt-1 text-slate-500">{[row.scheduled_at, row.city, row.district].filter(Boolean).join(' · ') || '-'}</div>
              <div className="mt-2 grid gap-1 text-xs text-slate-600 sm:grid-cols-3">
                <span>İşçilik: {money.format(row.labor_amount)}</span>
                <span>Yol: {money.format(row.travel_fee_amount)}</span>
                <span>Durum: {row.status}</span>
              </div>
            </div>
          ))}
        </div>
      </section>
      <section className="grid gap-3">
        <h2 className="text-lg font-semibold text-slate-950">Tamamlanan hakedişler</h2>
        {completed.rows.length === 0 && <EmptyState title="Tamamlanan hakediş kaydı yok" message="Teknik Servis hakediş dönemi oluştuğunda burada görünecek." />}
        {completed.rows.map((row) => (
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

function InfoTile({ label, value }: { label: string, value: ReactNode }) {
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
