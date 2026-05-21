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
  photos: Array<{ id: number, label: string | null, category: string | null, field_code: string | null }>
  latest_partner_action: { action: string, status: string, note: string | null, payload?: Record<string, unknown>, created_at: string | null } | null
  portal_actions: Array<{ id?: number, action: string, status: string, note: string | null, payload?: Record<string, unknown>, created_at: string | null }>
  appointment_proposal: { id: number, status: string, note: string | null, payload: Record<string, unknown>, created_at: string | null } | null
  rejection: { id: number, status: string, note: string | null, payload: Record<string, unknown>, created_at: string | null } | null
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
  kanban_column: 'new_jobs' | 'appointment_confirmed' | 'revisit' | 'completed'
  can_accept: boolean
  can_request_revisit: boolean
  can_submit_completion: boolean
  can_complete_directly: boolean
  can_reject: boolean
  updated_at: string | null
}

type ServiceJobColumn = {
  key: ServiceJob['kanban_column']
  label: string
  tone: 'blue' | 'green' | 'amber' | 'slate'
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
  { key: 'completed', label: 'Tamamlanan işler', tone: 'slate' },
]

const columnToneClass = (tone: ServiceJobColumn['tone']) => ({
  blue: 'border-blue-100 bg-blue-50 text-blue-800',
  green: 'border-emerald-100 bg-emerald-50 text-emerald-800',
  amber: 'border-amber-100 bg-amber-50 text-amber-800',
  slate: 'border-slate-200 bg-slate-50 text-slate-700',
}[tone])

const actionLabel = (action: string) => ({
  accepted: 'Randevu onaylandı',
  appointment_proposed: 'Randevu önerildi',
  job_rejected: 'İş reddedildi',
  revisit_requested: 'Tekrar ziyaret istendi',
  completion_submitted: 'Tamamlama gönderildi',
  note_added: 'Not eklendi',
}[action] ?? action)

const requiredChecklist = [
  ['customer_contacted', 'Müşteri ile iletişim kuruldu'],
  ['address_confirmed', 'Adres doğrulandı'],
  ['appointment_confirmed', 'Randevu teyit edildi'],
  ['door_product_checked', 'Kapı/ürün kontrol edildi'],
  ['job_completed', 'İşlem tamamlandı'],
  ['customer_informed', 'Müşteri bilgilendirildi'],
] as const

function ServiceJobsView({ board, readOnly }: { board: PartnerPortalProps['partnerPortal']['serviceJobBoard'], readOnly: boolean }) {
  const initialJobs = useMemo(() => board.columns.flatMap((column) => column.jobs), [board.columns])
  const [jobs, setJobs] = useState<ServiceJob[]>(initialJobs)
  const [selectedJobId, setSelectedJobId] = useState<number | null>(null)
  const [detailOpen, setDetailOpen] = useState(false)
  const [message, setMessage] = useState<string | null>(null)
  const selectedJob = selectedJobId === null ? null : jobs.find((job) => job.id === selectedJobId) ?? null
  const columns = serviceJobColumns.map((column) => {
    const columnJobs = jobs.filter((job) => job.kanban_column === column.key)

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
                  className={`w-full rounded-xl border bg-white p-3 text-left shadow-sm transition hover:border-slate-300 ${selectedJob?.id === job.id ? 'border-slate-900' : 'border-white'}`}
                >
                  <div className="flex items-start justify-between gap-2">
                    <span className="font-semibold text-slate-950">{job.mrn}</span>
                    <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600">{job.service_stage_label ?? job.status_label ?? '-'}</span>
                  </div>
                  <p className="mt-2 text-sm font-medium text-slate-800">{job.customer_name ?? 'Müşteri'}</p>
                  <p className="mt-1 text-xs text-slate-500">{[job.city, job.district].filter(Boolean).join(' / ') || '-'}</p>
                  <p className="mt-2 line-clamp-2 text-xs text-slate-500">{job.next_action ?? 'Aksiyon bekleniyor'}</p>
                </button>
              ))}
            </div>
          </div>
        ))}
      </section>
      {selectedJob && detailOpen && (
        <div className="fixed inset-0 z-50 overflow-hidden bg-slate-950/40">
          <div className="absolute inset-0" onClick={() => setDetailOpen(false)} />
          <div className="absolute inset-x-0 bottom-0 top-8 flex min-h-0 flex-col overflow-hidden rounded-t-3xl bg-white shadow-2xl md:inset-y-0 md:left-auto md:right-0 md:top-0 md:w-[760px] md:rounded-none">
            <div className="flex items-start justify-between gap-4 border-b border-slate-200 px-4 py-4">
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
            <div className="min-h-0 flex-1 overflow-y-auto px-4 py-4">
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
  const [proposalDate, setProposalDate] = useState('')
  const [proposalSlot, setProposalSlot] = useState('morning')
  const [proposalStart, setProposalStart] = useState('')
  const [proposalEnd, setProposalEnd] = useState('')
  const [proposalNote, setProposalNote] = useState('')
  const [revisitReason, setRevisitReason] = useState('')
  const [rejectReason, setRejectReason] = useState('not_available')
  const [rejectNote, setRejectNote] = useState('')
  const [completionNote, setCompletionNote] = useState('')
  const [completionResult, setCompletionResult] = useState('completed')
  const [note, setNote] = useState('')
  const checklist = Object.fromEntries(requiredChecklist.map(([key]) => [key, true]))

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

  return (
    <section className="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm lg:grid-cols-[minmax(0,1fr)_360px]">
      <div className="min-w-0">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">İş detayı</p>
            <h2 className="mt-1 text-2xl font-semibold text-slate-950">{job.mrn}</h2>
            <p className="mt-1 text-sm text-slate-500">{job.service_stage_label ?? job.status_label ?? '-'}</p>
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
          <InfoTile label="Seri" value={job.serial_no ?? '-'} />
          <InfoTile label="Randevu" value={job.appointment_at ?? '-'} />
          <InfoTile label="Sonraki aksiyon" value={job.next_action ?? '-'} />
          <InfoTile label="Yol" value={job.route_distance_summary ?? '-'} />
          <InfoTile label="Ödeme" value={job.payment_status_summary ?? '-'} />
        </div>
        <div className="mt-5 grid gap-3 md:grid-cols-2">
          <div className="rounded-2xl bg-emerald-50 p-4 text-sm text-emerald-950">
            <p className="text-xs font-semibold uppercase tracking-wide text-emerald-700">Hakediş</p>
            {job.assignment_offer ? (
              <div className="mt-3 grid gap-2">
                <div className="flex justify-between gap-3"><span>İşçilik / montaj</span><strong>{money.format(job.assignment_offer.labor_amount)}</strong></div>
                <div className="flex justify-between gap-3"><span>Yol</span><strong>{money.format(job.assignment_offer.route_fee_amount)}</strong></div>
                <div className="flex justify-between gap-3 border-t border-emerald-200 pt-2"><span>Toplam</span><strong>{money.format(job.assignment_offer.total_amount)}</strong></div>
                <p className="text-xs text-emerald-700">Durum: {job.assignment_offer.status}</p>
                {job.assignment_offer.note && <p className="text-xs text-emerald-800">{job.assignment_offer.note}</p>}
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
                <p className="mt-1">{String((job.appointment_proposal.payload?.proposal as Record<string, unknown> | undefined)?.slot_label ?? '-')}</p>
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
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Checklist</p>
            <div className="mt-3 grid gap-2">
              {requiredChecklist.map(([key, label]) => (
                <label key={key} className="flex items-center gap-2 text-sm text-slate-700">
                  <input type="checkbox" checked readOnly className="h-4 w-4 rounded border-slate-300" />
                  {label}
                </label>
              ))}
            </div>
            <p className="mt-3 text-xs text-slate-500">Teknik Servis checklist durumu: {job.checklist_status ?? '-'}</p>
          </div>
          <div className="rounded-2xl bg-slate-50 p-4">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Fotoğraf / belge</p>
            <p className="mt-2 text-sm text-slate-700">Önce: {job.photo_counts.before} · Sonra: {job.photo_counts.after} · Genel: {job.photo_counts.general}</p>
            {job.photos.length === 0 ? (
              <p className="mt-3 text-sm text-slate-500">Mevcut fotoğraf kaydı yok. Fotoğraf yükleme sonraki portal fazında güçlendirilecek.</p>
            ) : (
              <div className="mt-3 grid gap-2">
                {job.photos.map((photo) => (
                  <div key={photo.id} className="rounded-xl bg-white px-3 py-2 text-sm text-slate-600">{photo.label ?? `Fotoğraf #${photo.id}`}</div>
                ))}
              </div>
            )}
          </div>
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
      <aside className="min-w-0 rounded-2xl border border-slate-200 bg-slate-50 p-4">
        <p className="text-sm font-semibold text-slate-950">Aksiyonlar</p>
        {readOnly && <p className="mt-2 rounded-xl bg-amber-50 p-3 text-sm font-semibold text-amber-800">Önizleme modu: işlem yapılamaz.</p>}
        <div className="mt-4 grid gap-4">
          <ActionBox title="Randevuyu onayla">
            <textarea className="min-h-20 w-full rounded-xl border border-slate-200 p-3 text-sm" value={acceptNote} onChange={(event) => setAcceptNote(event.target.value)} placeholder="İsteğe bağlı not" disabled={readOnly || !job.can_accept} />
            <button type="button" disabled={readOnly || !job.can_accept || actionLoading === 'accept'} onClick={() => void submitAction('accept', { note: acceptNote }, 'Randevu onayı gönderildi.')} className="rounded-xl bg-slate-950 px-3 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-300">
              {job.can_accept ? 'Randevu onayla' : 'Bu iş onay beklemiyor'}
            </button>
          </ActionBox>
          <ActionBox title="Randevu öner">
            <input type="date" className="w-full rounded-xl border border-slate-200 p-3 text-sm" value={proposalDate} onChange={(event) => setProposalDate(event.target.value)} disabled={readOnly} />
            <select className="w-full rounded-xl border border-slate-200 p-3 text-sm" value={proposalSlot} onChange={(event) => setProposalSlot(event.target.value)} disabled={readOnly}>
              <option value="morning">Öğleden önce</option>
              <option value="afternoon">Öğleden sonra</option>
              <option value="full_day">Tam gün / operasyon belirlesin</option>
              <option value="custom">Özel saat aralığı</option>
            </select>
            {proposalSlot === 'custom' && (
              <div className="grid gap-2 sm:grid-cols-2">
                <input type="time" className="w-full rounded-xl border border-slate-200 p-3 text-sm" value={proposalStart} onChange={(event) => setProposalStart(event.target.value)} disabled={readOnly} />
                <input type="time" className="w-full rounded-xl border border-slate-200 p-3 text-sm" value={proposalEnd} onChange={(event) => setProposalEnd(event.target.value)} disabled={readOnly} />
              </div>
            )}
            <textarea className="min-h-20 w-full rounded-xl border border-slate-200 p-3 text-sm" value={proposalNote} onChange={(event) => setProposalNote(event.target.value)} placeholder="Operasyona randevu notu" disabled={readOnly} />
            <button
              type="button"
              disabled={readOnly || !proposalDate || (proposalSlot === 'custom' && (!proposalStart || !proposalEnd)) || actionLoading === 'appointment-proposal'}
              onClick={() => void submitAction('appointment-proposal', {
                proposed_date: proposalDate,
                proposed_slot: proposalSlot,
                proposed_time_start: proposalSlot === 'custom' ? proposalStart : null,
                proposed_time_end: proposalSlot === 'custom' ? proposalEnd : null,
                note: proposalNote || null,
              }, 'Randevu önerisi operasyona gönderildi.')}
              className="rounded-xl border border-blue-200 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-800 disabled:cursor-not-allowed disabled:opacity-50"
            >
              Randevu öner
            </button>
          </ActionBox>
          <ActionBox title="İşi reddet">
            <select className="w-full rounded-xl border border-slate-200 p-3 text-sm" value={rejectReason} onChange={(event) => setRejectReason(event.target.value)} disabled={readOnly || !job.can_reject}>
              <option value="not_available">Uygun değilim</option>
              <option value="region_not_suitable">Bölge uygun değil</option>
              <option value="time_not_suitable">Zaman uygun değil</option>
              <option value="customer_disagreement">Müşteriyle anlaşamadım</option>
              <option value="other">Diğer</option>
            </select>
            <textarea className="min-h-20 w-full rounded-xl border border-slate-200 p-3 text-sm" value={rejectNote} onChange={(event) => setRejectNote(event.target.value)} placeholder={rejectReason === 'other' ? 'Reddetme notu zorunlu' : 'İsteğe bağlı not'} disabled={readOnly || !job.can_reject} />
            <button
              type="button"
              disabled={readOnly || !job.can_reject || (rejectReason === 'other' && rejectNote.trim().length < 3) || actionLoading === 'reject'}
              onClick={() => void submitAction('reject', { reason: rejectReason, note: rejectNote || null }, 'İş reddi operasyona gönderildi.')}
              className="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-800 disabled:cursor-not-allowed disabled:opacity-50"
            >
              İşi reddet
            </button>
          </ActionBox>
          <ActionBox title="Tekrar ziyaret iste">
            <textarea className="min-h-20 w-full rounded-xl border border-slate-200 p-3 text-sm" value={revisitReason} onChange={(event) => setRevisitReason(event.target.value)} placeholder="Tekrar ziyaret nedeni" disabled={readOnly || !job.can_request_revisit} />
            <button type="button" disabled={readOnly || !job.can_request_revisit || revisitReason.trim().length < 3 || actionLoading === 'request-revisit'} onClick={() => void submitAction('request-revisit', { reason: revisitReason }, 'Tekrar ziyaret talebi gönderildi.')} className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800 disabled:cursor-not-allowed disabled:opacity-50">
              Tekrar ziyaret iste
            </button>
          </ActionBox>
          <ActionBox title="Tamamlamaya gönder">
            <select className="w-full rounded-xl border border-slate-200 p-3 text-sm" value={completionResult} onChange={(event) => setCompletionResult(event.target.value)} disabled={readOnly || !job.can_submit_completion}>
              <option value="completed">Tamamlandı</option>
              <option value="revisit_required">Tekrar ziyaret gerekli</option>
              <option value="customer_not_available">Müşteri yok</option>
              <option value="missing_info_or_photo">Eksik bilgi/fotoğraf</option>
              <option value="parts_pending">Parça/ürün bekleniyor</option>
            </select>
            <textarea className="min-h-24 w-full rounded-xl border border-slate-200 p-3 text-sm" value={completionNote} onChange={(event) => setCompletionNote(event.target.value)} placeholder="İşlem notu" disabled={readOnly || !job.can_submit_completion} />
            <button type="button" disabled={readOnly || !job.can_submit_completion || completionNote.trim().length < 3 || actionLoading === 'submit-completion'} onClick={() => void submitAction('submit-completion', { result: completionResult, checklist, note: completionNote }, job.can_complete_directly ? 'İş tamamlandı.' : 'Tamamlama gönderimi operasyon onayına düştü.')} className="rounded-xl bg-emerald-600 px-3 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-300">
              {job.can_complete_directly ? 'Tamamla' : 'Tamamlamaya gönder'}
            </button>
          </ActionBox>
          <ActionBox title="Operasyona not">
            <textarea className="min-h-20 w-full rounded-xl border border-slate-200 p-3 text-sm" value={note} onChange={(event) => setNote(event.target.value)} placeholder="Not yaz" disabled={readOnly} />
            <button type="button" disabled={readOnly || note.trim().length < 3 || actionLoading === 'note'} onClick={() => void submitAction('note', { note, visibility: 'ops' }, 'Not eklendi.')} className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 disabled:cursor-not-allowed disabled:opacity-50">
              Not ekle
            </button>
          </ActionBox>
        </div>
      </aside>
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
