import { Head, Link } from '@inertiajs/react'
import { ChevronDown } from 'lucide-react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
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

type ServiceJobPhoto = {
  id: number
  label: string | null
  category: string | null
  field_code: string | null
  preview_url?: string | null
  review_status?: string | null
  review_note?: string | null
  created_at?: string | null
}

type ServiceJob = {
  id: number
  mrn: string
  parent_request_id?: number | null
  root_mrn?: string | null
  service_code?: string | null
  view_context?: 'active_current' | 'child_active' | 'completed_history' | 'completed_parent'
  is_current_active_assignment?: boolean
  is_completed_history_view?: boolean
  should_show_completion_requirements?: boolean
  should_show_current_actions?: boolean
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
  brand?: string | null
  stock_code?: string | null
  activation_code?: string | null
  serial_context?: {
    serial_number?: string | null
    activation_code?: string | null
    product_name?: string | null
    product_model?: string | null
    brand?: string | null
    stock_code?: string | null
  } | null
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
  field_action_hint?: string | null
  route_distance_summary: string | null
  payment_status_summary: string | null
  maps_link: string | null
  customer_tel_link: string | null
  checklist_status: string | null
  checklist_payload: Record<string, boolean>
  photo_counts: { before: number, after: number, general: number }
  photos: ServiceJobPhoto[]
  current_field_documents?: Record<string, ServiceJobPhoto | null>
  previous_photos?: ServiceJobPhoto[]
  latest_partner_action: { action: string, action_label?: string | null, status: string, status_label?: string | null, note: string | null, payload?: Record<string, unknown>, created_at: string | null } | null
  portal_actions: Array<{ id?: number, action: string, action_label?: string | null, status: string, status_label?: string | null, note: string | null, payload?: Record<string, unknown>, created_at: string | null }>
  appointment_proposal: { id: number, status: string, status_label?: string | null, note: string | null, payload: Record<string, unknown>, created_at: string | null } | null
  rejection: { id: number, status: string, status_label?: string | null, note: string | null, payload: Record<string, unknown>, created_at: string | null } | null
  support_request: { id: number, status: string, status_label?: string | null, note: string | null, payload: Record<string, unknown>, created_at: string | null } | null
  part_requests?: Array<{
    id: number
    status: string
    status_label: string
    part_name: string
    quantity: number
    technician_note?: string | null
    partner_message?: string | null
    shipment_provider?: string | null
    tracking_no?: string | null
    sent_at?: string | null
    received_at?: string | null
  }>
  active_part_request?: {
    id: number
    status: string
    status_label: string
    part_name: string
    quantity: number
    technician_note?: string | null
    partner_message?: string | null
    shipment_provider?: string | null
    tracking_no?: string | null
    sent_at?: string | null
    received_at?: string | null
  } | null
  can_receive_part?: boolean
  price_revision_request?: { id: number, status: string, status_label?: string | null, note: string | null, payload: Record<string, unknown>, created_at: string | null } | null
  customer_otp_request: { id: number, status: string, status_label?: string | null, note: string | null, payload: Record<string, unknown>, created_at: string | null } | null
  customer_confirmation?: { id: number, status: string, approved_at: string | null, rejected_at?: string | null, customer_note: string | null, approval_url: string | null } | null
  completion_submission: { id: number, status: string, status_label?: string | null, note: string | null, payload: Record<string, unknown>, created_at: string | null } | null
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
  earning_breakdown?: {
    current_visit?: {
      id: number
      mrn: string
      display_mrn?: string | null
      kind_label?: string | null
      technician_id?: number | string | null
      technician_name?: string | null
      technician_source?: string | null
      labor_amount: number
      route_fee_amount: number
      total_amount: number
      status_label?: string | null
    } | null
    rows?: Array<{
      id: number
      mrn: string
      display_mrn?: string | null
      kind_label?: string | null
      is_current?: boolean
      technician_id?: number | string | null
      technician_name?: string | null
      technician_source?: string | null
      labor_amount: number
      route_fee_amount: number
      total_amount: number
      status_label?: string | null
    }>
    root_total?: {
      labor_amount: number
      route_fee_amount: number
      total_amount: number
      job_count?: number
      technician_count?: number
      technician_names?: string[]
      is_multi_technician?: boolean
    }
  } | null
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
  kanban_column: 'new_jobs' | 'appointment_confirmed' | 'ops_review' | 'revisit' | 'final_check' | 'completed'
  service_visit_context?: {
    parent_request_id?: number | null
    root_mrn?: string | null
    parent_mrn?: string | null
    service_code?: string | null
    reason_label?: string | null
    summary?: string | null
    sibling_service_visits?: Array<{ id: number, mrn: string, service_code?: string | null, status_label?: string | null }>
  } | null
  action_state?: string
  can_accept: boolean
  can_propose_appointment?: boolean
  can_request_appointment_change?: boolean
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

const dateTimeOrEmpty = (value: string | null | undefined, fallback: string): string => {
  if (!value) {
    return fallback
  }

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) {
    return value
  }

  return date.toLocaleString('tr-TR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

const numericAmount = (amount: number | string | null | undefined): number => {
  const parsed = Number(amount ?? 0)

  return Number.isFinite(parsed) ? parsed : 0
}

const currentVisitEarning = (job: ServiceJob): NonNullable<ServiceJob['earning_breakdown']>['current_visit'] => {
  return job.earning_breakdown?.current_visit ?? null
}

const jobEarningTotal = (job: ServiceJob): number => {
  return numericAmount(currentVisitEarning(job)?.total_amount ?? job.assignment_offer?.total_amount ?? job.earning_summary.total_amount)
}

const jobEarningLabor = (job: ServiceJob): number => {
  return numericAmount(currentVisitEarning(job)?.labor_amount ?? job.assignment_offer?.labor_amount ?? job.earning_summary.labor_amount)
}

const jobEarningRoute = (job: ServiceJob): number => {
  return numericAmount(currentVisitEarning(job)?.route_fee_amount ?? job.assignment_offer?.route_fee_amount ?? job.earning_summary.route_fee_amount)
}

const hasRawCodeShape = (value: string): boolean => /^[a-z0-9_-]+$/i.test(value)

const cleanDisplayText = (value: string | null | undefined): string => {
  if (value === null || value === undefined) {
    return ''
  }

  return String(value)
    .replaceAll('M??teri', 'Müşteri')
    .replaceAll('Planl?', 'Planlı')
    .replaceAll('Tamamland?', 'Tamamlandı')
    .replaceAll('Atamas?', 'Ataması')
    .replaceAll('Onay?', 'Onayı')
    .replaceAll('onaylad?', 'onayladı')
    .replaceAll('onayland?', 'onaylandı')
    .replaceAll('Ã‡', 'Ç')
    .replaceAll('Ã–', 'Ö')
    .replaceAll('Ãœ', 'Ü')
    .replaceAll('Ã§', 'ç')
    .replaceAll('Ã¶', 'ö')
    .replaceAll('Ã¼', 'ü')
    .replaceAll('Ä°', 'İ')
    .replaceAll('Ä±', 'ı')
    .replaceAll('ÄŸ', 'ğ')
    .replaceAll('ÅŸ', 'ş')
    .replaceAll('Åž', 'Ş')
    .replaceAll('Â', '')
    .replaceAll('ï¿½', '')
}

const serviceJobProductLabel = (job: ServiceJob): string => {
  const values = [
    job.product_name,
    job.product_model ?? job.model,
    job.brand ?? job.serial_context?.brand,
  ].map((value) => cleanDisplayText(value).trim()).filter(Boolean)

  return values.join(' / ') || '-'
}

const serviceJobSerialLabel = (job: ServiceJob): string => {
  const activationCode = cleanDisplayText(job.activation_code ?? job.serial_context?.activation_code).trim()
  const serialNumber = cleanDisplayText(job.serial_no ?? job.serial_context?.serial_number).trim()
  const values = [
    activationCode ? `Aktivasyon: ${activationCode}` : null,
    serialNumber ? `Seri: ${serialNumber}` : null,
  ].filter((value): value is string => Boolean(value))

  return values.join(' / ') || '-'
}

const statusLabel = (status: string | null | undefined, provided?: string | null): string => {
  const normalizedProvided = cleanDisplayText(provided).trim()

  if (normalizedProvided !== '' && !hasRawCodeShape(normalizedProvided)) {
    return normalizedProvided
  }

  const normalized = cleanDisplayText(status).trim()

  return ({
  ops_review: 'Operasyon incelemesinde',
  applied: 'Uygulandı',
  submitted: 'Gönderildi',
  sent: 'Gönderildi',
  pending: 'Bekliyor',
  revised: 'Revize edildi',
  accepted: 'Kabul edildi',
  approved: 'Onaylandı',
  rejected: 'Reddedildi',
  revision_requested: 'Revize istendi',
  completed: 'Tamamlandı',
  final_check: 'Son kontrol',
  final_check_waiting: 'Son kontrol bekliyor',
  draft: 'Taslak',
  cancelled: 'İptal edildi',
  superseded: 'Yenilendi',
}[normalized] ?? (normalized && !hasRawCodeShape(normalized) ? normalized : '-'))
}

const jobEarningStatus = (job: ServiceJob): string => {
  const currentVisit = currentVisitEarning(job)

  if (currentVisit?.status_label) {
    return cleanDisplayText(currentVisit.status_label)
  }

  if (job.assignment_offer?.status) {
    return statusLabel(job.assignment_offer.status)
  }

  return jobEarningTotal(job) > 0 ? 'Tahmini' : 'Gönderilmedi'
}

const jobReadyForCompletionSubmit = (job: ServiceJob): boolean => (
  Boolean(job.can_submit_completion)
  && job.kanban_column === 'appointment_confirmed'
  && job.completion_requirements.photos_ready
  && job.completion_requirements.customer_confirmation_ready
  && job.action_state !== 'final_check_waiting'
)

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
          <ServiceJobsView partnerId={selectedPartner.id} board={partnerPortal.serviceJobBoard} readOnly={isPreview} />
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
  { key: 'ops_review', label: 'Operasyon incelemede', tone: 'violet' },
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

const actionLabel = (action: string, status?: string | null, provided?: string | null) => {
  const normalizedProvided = cleanDisplayText(provided).trim()
  const labels: Record<string, string> = {
    accepted: 'Randevu onaylandı',
    appointment_accepted_by_technician: 'Randevu onaylandı',
    appointment_proposed: 'Randevu önerildi',
    partner_portal_appointment_proposed: 'Randevu önerildi',
    appointment_change_requested: 'Randevu değişikliği istendi',
    appointment_approved: 'Randevu onaylandı',
    appointment_updated: 'Randevu güncellendi',
    schedule_updated: 'Randevu güncellendi',
    assignment_created: 'Usta atandı',
    assignment_updated: 'Servis ataması güncellendi',
    assignment_reassigned: 'Servis ataması güncellendi',
    assignment_offer_sent: 'Hakediş bilgisi hazırlandı',
    job_rejected: 'İş reddedildi',
    revisit_requested: 'Tekrar ziyaret istendi',
    completion_submitted: 'Tamamlama gönderildi',
    customer_otp_requested: 'Müşteri onayı istendi',
    customer_approval_request: 'Müşteri onayı istendi',
    customer_approval_request_resent: 'Müşteri onayı istendi',
    customer_approval_confirmed: 'Müşteri onayı alındı',
    customer_approval_rejected: 'Müşteri onayı reddedildi',
    support_requested: 'Ek talep',
    partner_portal_support_requested: 'Ek talep',
    photos_uploaded: 'Fotoğraf yüklendi',
    price_revision_requested: 'Hakediş revize talebi',
    part_requested: 'Parça talebi oluşturuldu',
    part_request_created: 'Parça talebi oluşturuldu',
    part_approved: 'Parça talebi onaylandı',
    part_ordered: 'Parça tedarik ediliyor',
    part_sent: 'Parça gönderildi',
    part_received: 'Parça teslim alındı',
    srv_created: 'Servis kaydı oluşturuldu',
    service_visit_created: 'Servis kaydı oluşturuldu',
    note_added: 'Not eklendi',
    submitted: 'Gönderildi',
    applied: 'Uygulandı',
    revised: 'Revize edildi',
    ops_review: 'Operasyon incelemesinde',
  }

  if (labels[normalizedProvided]) {
    return labels[normalizedProvided]
  }

  if (normalizedProvided !== '' && !hasRawCodeShape(normalizedProvided)) {
    return normalizedProvided
  }

  if (action === 'appointment_proposed' && status === 'applied') {
    return 'Randevu onaylandı'
  }

  const cleanAction = cleanDisplayText(action)

  return labels[action] ?? (hasRawCodeShape(cleanAction) ? 'İşlem kaydı' : cleanAction)
}

const todayDateValue = () => new Date().toISOString().slice(0, 10)

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

const customerApprovalDefaultMessage = (job: ServiceJob, approvalUrl?: string | null, lastMessage?: string | null) => {
  if (lastMessage && lastMessage.trim() !== '') {
    return lastMessage
  }

  const product = [job.product_name, job.model].filter(Boolean).join(' / ')
  const linkLine = approvalUrl && approvalUrl.trim() !== ''
    ? approvalUrl
    : 'Onay linki gönderim sırasında otomatik eklenecek.'

  return [
    'Emaks Prime Teknik Servis',
    '',
    `Sayın ${job.customer_name || 'müşterimiz'},`,
    product
      ? `${product} montaj işleminiz için onayınız gerekmektedir.`
      : 'Montaj işleminiz için onayınız gerekmektedir.',
    '',
    `Talep No: ${job.mrn}`,
    '',
    'Montajın tamamlandığını ve üründe görünür hasar/kusur olmadığını kontrol ettiyseniz aşağıdaki bağlantıdan onay verebilirsiniz:',
    '',
    linkLine,
    '',
    'Bu işlemi siz yapmadıysanız operasyon ekibimizle iletişime geçiniz.',
  ].join('\n')
}

const portalPhotoFields = [
  ['before_photo', 'Öncesi'],
  ['after_photo', 'Sonrası'],
  ['warranty_document_photo', 'Garanti Belgesi'],
] as const

const portalPhotoAccept = 'image/*,.heic,.heif'

const portalPhotoExtension = (file: File) => file.name.split('.').pop()?.toLowerCase() ?? ''

const canPreviewPortalPhoto = (file: File) => {
  const extension = portalPhotoExtension(file)

  return ['jpg', 'jpeg', 'png', 'webp', 'gif'].includes(extension)
    && /^image\/(jpeg|png|webp|gif)$/i.test(file.type)
}

type PartnerDetailSectionKey = 'earnings' | 'appointment' | 'photos' | 'history'

const getPartnerDefaultOpenSections = (job: ServiceJob, completionReady: boolean): Set<PartnerDetailSectionKey> => {
  if (job.kanban_column === 'completed') {
    return new Set(['earnings'])
  }

  if (job.kanban_column === 'final_check' || job.action_state === 'final_check_waiting') {
    return new Set()
  }

  if (job.action_state === 'appointment_proposed_waiting' || job.action_state === 'appointment_change_requested') {
    return new Set(['appointment'])
  }

  if (job.kanban_column === 'appointment_confirmed' && job.can_upload_photos) {
    return new Set(['photos'])
  }

  if (completionReady) {
    return new Set()
  }

  return new Set()
}

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

function ServiceJobsView({ partnerId, board, readOnly }: { partnerId: number, board: PartnerPortalProps['partnerPortal']['serviceJobBoard'], readOnly: boolean }) {
  const initialJobs = useMemo(() => board.columns.flatMap((column) => column.jobs), [board.columns])
  const [jobs, setJobs] = useState<ServiceJob[]>(initialJobs)
  const [selectedJobId, setSelectedJobId] = useState<number | null>(null)
  const [detailOpen, setDetailOpen] = useState(false)
  const [message, setMessage] = useState<string | null>(null)
  const [refreshing, setRefreshing] = useState(false)
  const [detailActionOpen, setDetailActionOpen] = useState(false)
  const requestedJobAppliedRef = useRef(false)
  const selectedJob = selectedJobId === null ? null : jobs.find((job) => job.id === selectedJobId) ?? null
  const refreshJobs = useCallback(async (silent = true, force = false) => {
    if (readOnly) {
      return
    }

    if (silent && detailActionOpen && !force) {
      return
    }

    if (!silent) {
      setRefreshing(true)
      setMessage(null)
    }

    try {
      const response = await apiRequest(`/api/partner/service-jobs?partner_id=${partnerId}`) as { jobs?: ServiceJob[] }

      if (Array.isArray(response.jobs)) {
        setJobs(response.jobs)
      }
    } catch (error) {
      if (!silent) {
        setMessage(error instanceof Error ? error.message : 'İşler yenilenemedi.')
      }
    } finally {
      if (!silent) {
        setRefreshing(false)
      }
    }
  }, [detailActionOpen, partnerId, readOnly])
  const columns = serviceJobColumns.map((column) => {
    const columnJobs = jobs
      .filter((job) => job.kanban_column === column.key)
      .sort((a, b) => (a.card_priority - b.card_priority) || String(b.updated_at ?? '').localeCompare(String(a.updated_at ?? '')))

    return { ...column, count: columnJobs.length, jobs: columnJobs }
  })

  const updateJob = (job: ServiceJob) => {
    const parentRequestId = job.parent_request_id ?? job.service_visit_context?.parent_request_id ?? null
    setJobs((current) => {
      const withoutReplaced = current.filter((item) => item.id !== job.id && (parentRequestId === null || item.id !== parentRequestId))

      return [...withoutReplaced, job]
    })
    setSelectedJobId(job.id)
  }

  const openJob = (job: ServiceJob) => {
    setSelectedJobId(job.id)
    setDetailOpen(true)
    setDetailActionOpen(false)
  }

  const closeJobDetail = () => {
    setDetailOpen(false)
    setDetailActionOpen(false)
  }

  useEffect(() => {
    if (requestedJobAppliedRef.current || typeof window === 'undefined') {
      return
    }

    const jobId = Number(new URLSearchParams(window.location.search).get('job_id'))

    if (!Number.isFinite(jobId) || jobId <= 0) {
      requestedJobAppliedRef.current = true

      return
    }

    if (jobs.some((job) => job.id === jobId)) {
      requestedJobAppliedRef.current = true

      const timeoutId = window.setTimeout(() => {
        setSelectedJobId(jobId)
        setDetailOpen(true)
        setDetailActionOpen(false)
      }, 0)

      return () => window.clearTimeout(timeoutId)
    }
  }, [jobs])

  useEffect(() => {
    if (readOnly) {
      return undefined
    }

    const interval = window.setInterval(() => {
      if (document.visibilityState === 'visible') {
        void refreshJobs(true)
      }
    }, 15000)

    return () => window.clearInterval(interval)
  }, [readOnly, refreshJobs])

  useEffect(() => {
    if (readOnly) {
      return undefined
    }

    const refreshVisibleJobs = () => {
      if (document.visibilityState === 'visible') {
        void refreshJobs(true, true)
      }
    }

    window.addEventListener('focus', refreshVisibleJobs)
    document.addEventListener('visibilitychange', refreshVisibleJobs)

    return () => {
      window.removeEventListener('focus', refreshVisibleJobs)
      document.removeEventListener('visibilitychange', refreshVisibleJobs)
    }
  }, [readOnly, refreshJobs])

  return (
    <div className="grid gap-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm font-semibold text-slate-600">Son durum</p>
        <button
          type="button"
          disabled={readOnly || refreshing}
          onClick={() => void refreshJobs(false)}
          className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 disabled:cursor-not-allowed disabled:opacity-50"
        >
          {refreshing ? 'Yenileniyor...' : 'Yenile'}
        </button>
      </div>
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
              ) : column.jobs.map((job) => {
                const completionReady = jobReadyForCompletionSubmit(job)

                return (
                <button
                  key={job.id}
                  type="button"
                  onClick={() => openJob(job)}
                  className={`w-full rounded-xl border p-3 text-left shadow-sm transition hover:border-slate-300 ${cardToneClass(job.card_tone)} ${completionReady ? 'ring-2 ring-emerald-500' : ''} ${selectedJob?.id === job.id ? 'ring-2 ring-slate-900' : ''}`}
                >
                  <div className="rounded-xl border border-blue-100 bg-white/85 px-3 py-2">
                    <p className="text-[11px] font-semibold uppercase tracking-wide text-blue-500">Randevu</p>
                    <p className="mt-1 text-base font-semibold text-slate-950">{job.appointment_label ?? job.appointment_at ?? 'Randevu bekleniyor'}</p>
                  </div>
                  <div className="mt-3 flex items-start justify-between gap-2">
                    <span className="font-semibold text-slate-950">{cleanDisplayText(job.customer_name) || 'Müşteri'}</span>
                    <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600">{cleanDisplayText(job.service_stage_label ?? job.status_label) || '-'}</span>
                  </div>
                  {job.customer_phone ? <p className="mt-1 text-xs font-semibold text-blue-700">{job.customer_phone}</p> : null}
                  <p className="mt-1 text-xs text-slate-500">{[job.city, job.district].map((value) => cleanDisplayText(value)).filter(Boolean).join(' / ') || '-'}</p>
                  <p className="mt-1 font-mono text-[11px] text-slate-500">{job.mrn}</p>
                  <div className="mt-2 rounded-lg border border-emerald-100 bg-white/80 px-2.5 py-2 text-xs text-emerald-900">
                    <div className="flex items-center justify-between gap-2">
                      <span className="font-semibold">Bu ziyaret hakedişi</span>
                      <strong>{jobEarningTotal(job) > 0 ? money.format(jobEarningTotal(job)) : 'Gönderilmedi'}</strong>
                    </div>
                    <div className="mt-1 flex items-center justify-between gap-2 text-[11px] text-emerald-700">
                      <span>İşçilik {money.format(jobEarningLabor(job))}</span>
                      <span>Yol {money.format(jobEarningRoute(job))}</span>
                    </div>
                    <p className="mt-1 text-[11px] font-semibold text-emerald-700">Durum: {jobEarningStatus(job)}</p>
                  </div>
                  <p className="mt-2 line-clamp-2 text-xs text-slate-500">{job.next_action ?? 'Aksiyon bekleniyor'}</p>
                  {job.kanban_column === 'appointment_confirmed' ? (
                    <p className="mt-2 rounded-lg border border-blue-100 bg-white/80 px-2.5 py-2 text-xs font-semibold leading-5 text-blue-900">
                      {job.field_action_hint ?? 'İş sonrası 3 fotoğrafı yükleyin, ardından müşteri onayı alın.'}
                    </p>
                  ) : null}
                  {completionReady && (
                    <div className="mt-3 rounded-xl border border-emerald-200 bg-emerald-600 px-3 py-2 text-white shadow-sm">
                      <p className="text-[11px] font-semibold uppercase tracking-wide text-emerald-100">Ana aksiyon</p>
                      <p className="mt-0.5 text-sm font-semibold">Tamamlamaya gönder</p>
                      <p className="mt-1 text-xs font-medium text-emerald-50">Saha belgeleri ve müşteri onayı tamam.</p>
                    </div>
                  )}
                  {job.badges.length > 0 && (
                    <div className="mt-2 flex flex-wrap gap-1">
                      {job.badges.slice(0, 3).map((badge) => (
                        <span key={badge} className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${badge === 'Tamamlamaya gönderilebilir' ? 'bg-emerald-100 text-emerald-800' : 'bg-white/80 text-slate-700'}`}>{badge}</span>
                      ))}
                    </div>
                  )}
                </button>
                )
              })}
            </div>
          </div>
        ))}
      </section>
      {selectedJob && detailOpen && (
        <div className="fixed inset-0 z-50 overflow-x-clip overflow-y-auto bg-slate-950/45 p-0 sm:p-4 lg:p-6">
          <div className="absolute inset-0" onClick={closeJobDetail} />
          <div className="relative z-10 mx-auto flex min-h-screen w-full max-w-6xl min-w-0 flex-col overflow-hidden bg-white shadow-2xl sm:min-h-0 sm:max-h-[calc(100vh-2rem)] lg:max-h-[calc(100vh-3rem)] sm:rounded-3xl">
            <div className="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-slate-200 bg-white px-4 py-4">
              <div className="min-w-0">
                <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">İş detayı</p>
                <h2 className="mt-1 text-xl font-semibold text-slate-950">{selectedJob.mrn}</h2>
              </div>
              <button
                type="button"
                onClick={closeJobDetail}
                className="inline-flex h-10 w-10 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100"
                aria-label="İş detayını kapat"
              >
                ×
              </button>
            </div>
            <div className="min-h-0 min-w-0 flex-1 overflow-x-clip overflow-y-auto px-4 py-4 pb-36 sm:pb-4">
              <ServiceJobDetail
                job={selectedJob}
                readOnly={readOnly}
                onJobUpdated={updateJob}
                onJobsRefresh={() => refreshJobs(true, true)}
                onMessage={setMessage}
                onActionDialogOpenChange={setDetailActionOpen}
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
  onJobsRefresh,
  onMessage,
  onActionDialogOpenChange,
}: {
  job: ServiceJob
  readOnly: boolean
  onJobUpdated: (job: ServiceJob) => void
  onJobsRefresh?: () => Promise<void>
  onMessage: (message: string | null) => void
  onActionDialogOpenChange: (open: boolean) => void
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
  const [photoPreviewUrls, setPhotoPreviewUrls] = useState<Record<string, string>>({})
  const [photoPreviewErrors, setPhotoPreviewErrors] = useState<Record<string, boolean>>({})
  const [photoPickerField, setPhotoPickerField] = useState<string | null>(null)
  const [otpNote, setOtpNote] = useState('')
  const [supportType, setSupportType] = useState('spare_part')
  const [supportDescription, setSupportDescription] = useState('')
  const [supportProduct, setSupportProduct] = useState('')
  const [supportQuantity, setSupportQuantity] = useState('')
  const [priceLaborAmount, setPriceLaborAmount] = useState(job.assignment_offer?.labor_amount ? String(job.assignment_offer.labor_amount) : '')
  const [priceRouteAmount, setPriceRouteAmount] = useState(job.assignment_offer?.route_fee_amount ? String(job.assignment_offer.route_fee_amount) : '')
  const [priceRevisionNote, setPriceRevisionNote] = useState('')
  const [activeActionDialog, setActiveActionDialog] = useState<'appointment' | 'reject' | 'revisit' | 'otp' | 'support' | 'price' | 'completion' | 'note' | null>(null)
  useEffect(() => {
    onActionDialogOpenChange(activeActionDialog !== null)

    return () => onActionDialogOpenChange(false)
  }, [activeActionDialog, onActionDialogOpenChange])
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
  const defaultOtpMessageText = customerApprovalDefaultMessage(job, approvalUrl, confirmationMessageText)
  const [otpMessageText, setOtpMessageText] = useState(defaultOtpMessageText)
  const dispatchStatus = nestedStringValue(messagePayload, 'dispatch_status')
  const dispatchTargetPhone = nestedStringValue(messagePayload, 'target_phone')
  const dispatchErrorMessage = nestedStringValue(messagePayload, 'error_message')
  const publicUrlWarning = nestedStringValue(messagePayload, 'public_url_warning') ?? nestedStringValue(otpPayload, 'public_url_warning')
  const dispatchTestMode = messagePayload?.test_mode === true
  const otpDispatchTitle = dispatchStatus === 'sent'
    ? 'WhatsApp onay mesajı gönderildi.'
    : dispatchStatus === 'suppressed_duplicate'
      ? 'Bu mesaj daha önce gönderildi.'
      : dispatchStatus && dispatchStatus.startsWith('suppressed_')
        ? 'Test mesajı gerçek WhatsApp’a gönderilmedi.'
        : dispatchStatus === 'failed' || dispatchStatus === 'not_configured'
          ? 'WhatsApp mesajı gönderilemedi.'
          : 'Müşteriden montaj onayı alınmalı.'
  useEffect(() => () => {
    Object.values(photoPreviewUrls).forEach((url) => {
      if (url) {
        URL.revokeObjectURL(url)
      }
    })
  }, [photoPreviewUrls])
  const missingPhotoLabels = job.completion_requirements.missing_photo_labels ?? []
  const completionMissingReasons = [
    ...missingPhotoLabels.map((label) => `${label} eksik`),
    ...(confirmationReady ? [] : ['Müşteri onayı bekleniyor']),
  ]
  const completionBlocked = completionMissingReasons.length > 0
  const completionReady = jobReadyForCompletionSubmit(job) && !completionBlocked
  const defaultOpenPartnerSections = getPartnerDefaultOpenSections(job, completionReady)
  const showCompletionRequirements = job.should_show_completion_requirements ?? !job.is_completed_history_view
  const readOnlyHistoryPhotos = showCompletionRequirements
    ? []
    : [...job.photos, ...(job.previous_photos ?? [])].filter((photo, index, photos) => (
      photos.findIndex((candidate) => candidate.id === photo.id) === index
    ))
  const showPhotoSection = (showCompletionRequirements && Boolean(job.can_upload_photos || job.kanban_column === 'final_check'))
    || Boolean(job.is_completed_history_view && readOnlyHistoryPhotos.length > 0)
  const photoStatuses = job.completion_requirements.photo_statuses ?? portalPhotoFields.map(([field, label]) => ({
    field,
    label,
    uploaded: (field === 'before_photo' && job.photo_counts.before > 0)
      || (field === 'after_photo' && job.photo_counts.after > 0)
      || (field === 'warranty_document_photo' && job.photo_counts.general > 0),
  }))
  const hasSelectedPhoto = Object.values(photoFiles).some((file) => file instanceof File)
  const photoByField = (field: string) => job.current_field_documents?.[field]
    ?? job.photos.find((photo) => photo.field_code === field)
    ?? readOnlyHistoryPhotos.find((photo) => photo.field_code === field)
  const photoFieldsToRender = showCompletionRequirements
    ? portalPhotoFields
    : portalPhotoFields.filter(([field]) => Boolean(photoByField(field)))
  const appointmentSlotError = slotValidationMessage(appointmentSlots)
  const canAcceptAppointment = Boolean(job.can_accept)
  const canProposeAppointment = Boolean(job.can_propose_appointment ?? true)
  const canRequestAppointmentChange = Boolean(job.can_request_appointment_change)
  const canUseAppointmentProposal = canProposeAppointment || canRequestAppointmentChange
  const canRequestCustomerApproval = Boolean(job.can_request_customer_otp && photosReady)
  const customerApprovalPhotoGateMessage = 'Müşteri onayı için önce 3 fotoğrafı yükleyin.'
  const canOnlyAddNote = job.kanban_column === 'ops_review'
    || ['final_check_waiting', 'rejected_ops_review', 'completed', 'appointment_change_requested', 'support_requested', 'revisit_requested'].includes(job.action_state ?? '')
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
      return 'Operasyon önerdiğiniz saatlerden birini onayladığında müşteriye ve size bilgilendirme gönderilecek.'
    }

    if (job.action_state === 'appointment_change_requested') {
      return 'Randevu değişikliği operasyona iletildi. Operasyon onayını bekleyin.'
    }

    if (job.kanban_column === 'ops_review') {
      return 'Talebiniz operasyona iletildi. Bu aşamada sadece not ekleyebilirsiniz.'
    }

    if (job.kanban_column === 'final_check' || job.action_state === 'final_check_waiting') {
      return 'Tamamlama gönderimi operasyonda son kontrol bekliyor. Bu aşamada sadece not ekleyebilirsiniz.'
    }

    if (completionReady) {
      return 'Saha belgeleri ve müşteri onayı tamam. İşi operasyon son kontrolüne gönderebilirsiniz.'
    }

    if (job.action_state === 'completion_ready') {
      return 'Tamamlama için eksik kontrol var. Fotoğraf ve müşteri onayı durumunu kontrol edin.'
    }

    if (job.kanban_column === 'appointment_confirmed') {
      return job.field_action_hint ?? 'İş sonrası 3 fotoğrafı yükleyin, ardından müşteri onayı alın.'
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
      }) as { job?: ServiceJob, message?: string }

      if (response.job) {
        onJobUpdated(response.job)

        if (action.startsWith('part-requests/') && action.endsWith('/received') && response.job.can_propose_appointment) {
          setActiveActionDialog('appointment')
        }
      }

      await onJobsRefresh?.()

      onMessage(response.message ?? successMessage)
    } catch (error) {
      onMessage(error instanceof Error ? error.message : 'İşlem tamamlanamadı.')
    } finally {
      setActionLoading(null)
    }
  }

  const openCustomerApprovalDialog = () => {
    if (!photosReady) {
      onMessage(customerApprovalPhotoGateMessage)

      return
    }

    setOtpMessageText(customerApprovalDefaultMessage(job, approvalUrl, confirmationMessageText))
    setActiveActionDialog('otp')
  }

  const copyApprovalLink = async () => {
    if (!approvalUrl) {
      onMessage('Henüz onay linki oluşmadı.')

      return
    }

    try {
      await navigator.clipboard.writeText(approvalUrl)
      onMessage('Onay linki kopyalandı.')
    } catch {
      onMessage('Onay linki kopyalanamadı.')
    }
  }

  const submitCustomerOtpRequest = async () => {
    if (!photosReady) {
      onMessage(customerApprovalPhotoGateMessage)

      return
    }

    const messageText = otpMessageText.trim()

    if (messageText.length < 3) {
      onMessage('WhatsApp mesaj metni boş gönderilemez.')

      return
    }

    await submitAction('customer-otp-request', {
      note: otpNote || null,
      message_text: messageText,
    }, 'WhatsApp onay mesajı gönderildi.')
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

  const uploadPhotoEntries = async (entries: Array<[string, File]>) => {
    if (readOnly) {
      onMessage('Önizleme modunda işlem yapılamaz.')

      return
    }

    if (entries.length === 0) {
      onMessage('Yüklenecek fotoğraf seçin.')

      return
    }

    const formData = new FormData()
    entries.forEach(([field, file]) => {
      formData.append(field, file)
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
          'X-Requested-With': 'XMLHttpRequest',
          ...(token ? { 'X-CSRF-TOKEN': token } : {}),
        },
        body: formData,
      })

      if (!response.ok) {
        let message = 'Fotoğraf yüklenemedi.'

        try {
          const payload = await response.json() as { message?: string, errors?: Record<string, string[]> }
          const firstError = payload.errors ? Object.values(payload.errors).flat()[0] : null
          message = firstError || payload.message || message
        } catch {
          // Keep non-JSON error responses out of the portal UI.
        }

        throw new Error(message)
      }

      const payload = await response.json() as { job?: ServiceJob }

      if (payload.job) {
        onJobUpdated(payload.job)
      }

      await onJobsRefresh?.()

      setPhotoFiles({})
      setPhotoPreviewUrls({})
      setPhotoPickerField(null)
      onMessage(entries.length === 1 ? 'Fotoğraf yüklendi.' : 'Fotoğraflar yüklendi.')
    } catch (error) {
      onMessage(error instanceof Error ? error.message : 'Fotoğraf yüklenemedi.')
    } finally {
      setActionLoading(null)
    }
  }

  const updatePhotoFile = (field: string, file: File | null) => {
    setPhotoFiles((current) => ({ ...current, [field]: file }))
    setPhotoPreviewUrls((current) => ({
      ...current,
      [field]: file && canPreviewPortalPhoto(file) ? URL.createObjectURL(file) : '',
    }))
    setPhotoPreviewErrors((current) => ({ ...current, [field]: false }))
    setPhotoPickerField(field)

    if (file) {
      void uploadPhotoEntries([[field, file]])
    }
  }

  const submitPhotoUpload = async () => {
    const entries = Object.entries(photoFiles).filter((entry): entry is [string, File] => entry[1] instanceof File)

    await uploadPhotoEntries(entries)
  }

  return (
    <section className="grid w-full min-w-0 max-w-full gap-5 overflow-hidden rounded-2xl border border-slate-200 bg-white p-4 pb-36 shadow-sm lg:grid-cols-[minmax(0,1fr)_400px] lg:p-5">
      <div className="flex min-w-0 max-w-full flex-col">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">İş detayı</p>
            <h2 className="mt-1 text-2xl font-semibold text-slate-950">{job.mrn}</h2>
            {job.badges.length > 0 && (
              <div className="mt-2 flex flex-wrap gap-1">
                {job.badges.map((badge) => (
                  <span key={badge} className={`rounded-full px-2.5 py-1 text-xs font-semibold ${badge === 'Tamamlamaya gönderilebilir' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700'}`}>{badge}</span>
                ))}
              </div>
            )}
          </div>
          {job.latest_partner_action && (
            <span className="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">
              {actionLabel(job.latest_partner_action.action, job.latest_partner_action.status, job.latest_partner_action.action_label)}
            </span>
          )}
        </div>
        <div className="mt-5 rounded-2xl border border-blue-100 bg-blue-50 p-4">
          <p className="text-xs font-semibold uppercase tracking-wide text-blue-600">Randevu</p>
          <p className="mt-1 text-2xl font-semibold text-slate-950">{job.appointment_label ?? job.appointment_at ?? 'Randevu bekleniyor'}</p>
          <p className="mt-1 text-sm text-blue-800">{job.kanban_column === 'appointment_confirmed' ? 'Randevu onaylandı' : statusPlan}</p>
        </div>
        {job.service_visit_context ? (
          <div className="mt-5 rounded-2xl border border-violet-100 bg-violet-50 p-4 text-violet-950">
            <p className="text-xs font-semibold uppercase tracking-wide text-violet-600">Servis geçmişi</p>
            <div className="mt-2 grid gap-2 sm:grid-cols-2">
              <div className="rounded-xl bg-white px-3 py-2">
                <p className="text-xs font-semibold text-violet-700">Ana talep</p>
                <p className="mt-1 font-semibold text-slate-950">{job.service_visit_context.root_mrn ?? job.service_visit_context.parent_mrn ?? '-'}</p>
              </div>
              <div className="rounded-xl bg-white px-3 py-2">
                <p className="text-xs font-semibold text-violet-700">Servis ziyareti</p>
                <p className="mt-1 font-semibold text-slate-950">{job.service_visit_context.service_code ?? 'Ana talep'}</p>
              </div>
            </div>
            <p className="mt-2 text-sm text-violet-800">
              {job.service_visit_context.reason_label ?? job.service_visit_context.summary ?? 'Ek servis ziyareti'}
            </p>
            {(job.service_visit_context.sibling_service_visits?.length ?? 0) > 0 ? (
              <details className="mt-3 rounded-xl bg-white px-3 py-2 text-sm">
                <summary className="cursor-pointer font-semibold text-violet-800">Aynı ana talepteki diğer servisler</summary>
                <div className="mt-2 grid gap-2">
                  {job.service_visit_context.sibling_service_visits?.map((visit) => (
                    <div key={visit.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-violet-50 px-2 py-1">
                      <span className="font-semibold text-slate-800">{visit.mrn}</span>
                      <span className="text-xs text-violet-700">{[visit.service_code, visit.status_label].filter(Boolean).join(' · ')}</span>
                    </div>
                  ))}
                </div>
              </details>
            ) : null}
          </div>
        ) : null}
        {job.active_part_request ? (
          <div className="mt-5 rounded-2xl border border-violet-100 bg-violet-50 p-4 text-violet-950">
            <div className="flex flex-wrap items-start justify-between gap-2">
              <div>
                <p className="text-xs font-semibold uppercase tracking-wide text-violet-600">Parça talebi</p>
                <p className="mt-1 text-lg font-semibold text-slate-950">{job.active_part_request.part_name} {job.active_part_request.quantity > 1 ? `x${job.active_part_request.quantity}` : ''}</p>
              </div>
              <span className="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-violet-800">{job.active_part_request.status_label}</span>
            </div>
            {job.active_part_request.partner_message ? (
              <p className="mt-3 rounded-xl border border-violet-100 bg-white px-3 py-2 text-sm text-violet-900">{job.active_part_request.partner_message}</p>
            ) : null}
            {job.active_part_request.tracking_no ? (
              <p className="mt-3 text-sm text-violet-800">Kargo: {[job.active_part_request.shipment_provider, job.active_part_request.tracking_no].filter(Boolean).join(' / ')}</p>
            ) : null}
            {job.can_receive_part ? (
              <button
                type="button"
                disabled={readOnly || actionLoading === `part-requests/${job.active_part_request.id}/received`}
                onClick={() => void submitAction(`part-requests/${job.active_part_request?.id}/received`, {}, 'Parça teslim alındı olarak işaretlendi.')}
                className="mt-3 rounded-xl border border-violet-200 bg-white px-3 py-2 text-sm font-semibold text-violet-800 disabled:cursor-not-allowed disabled:opacity-50"
              >
                Parçayı teslim aldım
              </button>
            ) : null}
          </div>
        ) : null}
        <div className="order-[40] mt-5 grid gap-3 md:grid-cols-2">
          <InfoTile label="Müşteri" value={<span className="text-base font-semibold text-slate-950">{job.customer_name ?? '-'}</span>} />
          <InfoTile label="Telefon" value={job.customer_tel_link ? <a className="text-base font-semibold text-blue-700 hover:underline" href={job.customer_tel_link}>{job.customer_phone ?? 'Ara'}</a> : job.customer_phone ?? '-'} />
          <InfoTile label="Adres" value={job.address_summary ?? '-'} />
          <InfoTile label="Harita" value={job.maps_link ? <a className="font-semibold text-blue-700 hover:underline" href={job.maps_link} target="_blank" rel="noreferrer">Google Maps aç</a> : '-'} />
          <InfoTile label="Ürün" value={serviceJobProductLabel(job)} />
          <InfoTile label="Aktivasyon / seri" value={serviceJobSerialLabel(job)} />
          <InfoTile label="Km / yol bilgisi" value={job.route_distance_summary ?? '-'} />
          <InfoTile label="Konum" value={[job.city, job.district].filter(Boolean).join(' / ') || '-'} />
        </div>
        <div className={`mt-5 rounded-2xl border p-4 ${completionReady ? 'border-emerald-200 bg-emerald-50' : 'border-slate-200 bg-slate-50'}`}>
          <p className={`text-xs font-semibold uppercase tracking-wide ${completionReady ? 'text-emerald-700' : 'text-slate-400'}`}>{completionReady ? 'Ana aksiyon' : 'Bu aşamadaki aksiyon'}</p>
          {completionReady && <p className="mt-2 text-lg font-semibold text-emerald-950">Tamamlamaya gönder</p>}
          <p className={`mt-2 text-sm leading-6 ${completionReady ? 'text-emerald-900' : 'text-slate-700'}`}>{statusPlan}</p>
          <div className="mt-3 flex flex-wrap gap-2">
            {(job.badges.length === 0 ? ['Normal akış'] : job.badges).map((badge) => (
              <span key={badge} className={`rounded-full bg-white px-2.5 py-1 text-xs font-semibold ${badge === 'Tamamlamaya gönderilebilir' ? 'text-emerald-800' : 'text-slate-700'}`}>{badge}</span>
            ))}
          </div>
        </div>
        <div className="mt-5 grid gap-3 md:grid-cols-2">
          <PartnerDetailPanel key={`${job.id}-earnings`} title="Hakediş özeti" summary="İşçilik, usta yol hakedişi ve toplam" tone="emerald" defaultOpen={defaultOpenPartnerSections.has('earnings')} panelKey={`${job.id}-earnings`}>
            {job.assignment_offer || jobEarningTotal(job) > 0 ? (
              <div className="mt-3 grid gap-2">
                  <div className="min-w-0 max-w-full overflow-hidden rounded-xl border border-emerald-100 bg-white/80 p-3">
                    <p className="text-xs font-semibold uppercase tracking-wide text-emerald-700">Bu ziyaret</p>
                    <p className="mt-1 truncate text-xs font-semibold text-slate-500">Usta: {cleanDisplayText(job.earning_breakdown?.current_visit?.technician_name ?? job.technician_name ?? 'Usta bilgisi yok')}</p>
                    <div className="mt-2 grid grid-cols-[minmax(0,1fr)_auto] gap-3"><span className="min-w-0 break-words">{job.earning_breakdown?.current_visit?.kind_label ?? 'İş'} işçilik</span><strong className="shrink-0 whitespace-nowrap">{money.format(job.earning_breakdown?.current_visit?.labor_amount ?? jobEarningLabor(job))}</strong></div>
                    <div className="grid grid-cols-[minmax(0,1fr)_auto] gap-3"><span className="min-w-0 break-words">Yol hakedişi</span><strong className="shrink-0 whitespace-nowrap">{money.format(job.earning_breakdown?.current_visit?.route_fee_amount ?? jobEarningRoute(job))}</strong></div>
                    <div className="grid grid-cols-[minmax(0,1fr)_auto] gap-3 border-t border-emerald-100 pt-2"><span className="min-w-0 break-words">Bu ziyaret toplamı</span><strong className="shrink-0 whitespace-nowrap">{money.format(job.earning_breakdown?.current_visit?.total_amount ?? jobEarningTotal(job))}</strong></div>
                    <p className="mt-2 text-xs text-emerald-700">Durum: {jobEarningStatus(job)}</p>
                  </div>
                {job.earning_breakdown?.root_total && (job.earning_breakdown.root_total.job_count ?? 0) > 1 ? (
                  <div className="min-w-0 max-w-full overflow-hidden rounded-xl border border-emerald-200 bg-emerald-50 p-3">
                    <div className="grid grid-cols-[minmax(0,1fr)_auto] gap-3"><span className="min-w-0 break-words">Ana MRN toplam hakedişi{job.earning_breakdown.root_total.is_multi_technician ? ` (${job.earning_breakdown.root_total.technician_count ?? job.earning_breakdown.root_total.technician_names?.length ?? 0} usta)` : ''}</span><strong className="shrink-0 whitespace-nowrap">{money.format(job.earning_breakdown.root_total.total_amount)}</strong></div>
                    <p className="mt-1 text-xs text-emerald-700">{job.earning_breakdown.root_total.job_count} bağlı iş toplamı. İptal edilen işler toplamdan düşer.</p>
                    {job.earning_breakdown.root_total.is_multi_technician ? (
                      <p className="mt-1 text-xs font-semibold text-emerald-800">Ustalar: {(job.earning_breakdown.root_total.technician_names ?? []).map((name) => cleanDisplayText(name)).join(', ')}</p>
                    ) : null}
                  </div>
                ) : null}
                {(job.earning_breakdown?.rows ?? []).length > 1 ? (
                  <div className="grid min-w-0 max-w-full gap-1 overflow-hidden rounded-xl border border-slate-200 bg-white/80 p-2 text-xs text-slate-700">
                    {(job.earning_breakdown?.rows ?? []).map((row) => (
                      <div key={`${row.id}-${row.mrn}`} className="grid min-w-0 grid-cols-[minmax(0,1fr)_auto] gap-2">
                        <span className="min-w-0 break-words leading-5">{row.kind_label ?? 'İş'} - {row.display_mrn ?? row.mrn}{row.is_current ? ' (bu ziyaret)' : ''}<br /><span className="font-semibold text-slate-500">Usta: {cleanDisplayText(row.technician_name ?? 'Usta bilgisi yok')}</span></span>
                        <strong className="shrink-0 whitespace-nowrap">{money.format(row.total_amount)}</strong>
                      </div>
                    ))}
                  </div>
                ) : null}
                {job.assignment_offer?.note && <p className="text-xs text-emerald-800">{job.assignment_offer.note}</p>}
                {job.price_revision_request?.status === 'ops_review' && (
                  <p className="rounded-lg bg-rose-100 px-2 py-1 text-xs font-semibold text-rose-800">Hakediş revize talebi operasyon incelemesinde.</p>
                )}
              </div>
            ) : (
              <p className="mt-2 text-sm text-emerald-800">Bu iş için hakediş bilgisi henüz gönderilmedi.</p>
            )}
          </PartnerDetailPanel>
          <PartnerDetailPanel key={`${job.id}-appointment`} title="Randevu / bildirim" summary="Önerilen saatler ve operasyon bildirimi" tone="blue" defaultOpen={defaultOpenPartnerSections.has('appointment')} panelKey={`${job.id}-appointment`}>
            {job.appointment_proposal ? (
              <div className="mt-3 rounded-xl bg-white/80 p-3 text-xs text-blue-900">
                <p className="font-semibold">{job.appointment_proposal.status === 'applied' ? 'Randevu onaylandı' : `Randevu: ${statusLabel(job.appointment_proposal.status, job.appointment_proposal.status_label)}`}</p>
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
                <p className="font-semibold">Hakediş bilgisi operasyondan geldi.</p>
                <p className="mt-1">Detayları hakediş kartında görebilirsiniz.</p>
              </div>
            ) : null}
          </PartnerDetailPanel>
        </div>
        {showPhotoSection && (
          <PartnerDetailPanel key={`${job.id}-photos`} title="Fotoğraf / belge" summary={showCompletionRequirements ? 'Öncesi, sonrası ve garanti belgesi' : 'Tamamlanan işe ait yüklenen görseller'} tone="slate" defaultOpen={defaultOpenPartnerSections.has('photos')} panelKey={`${job.id}-photos`} className="order-[35] mt-5">
            {showCompletionRequirements ? (
              <div className="mt-3 min-w-0 max-w-full overflow-hidden rounded-xl border border-slate-200 bg-white p-3 text-xs text-slate-700">
                <p className="font-semibold">Tamamlama şartı</p>
                <p className="mt-1">{job.completion_requirements.door_photos_uploaded}/{job.completion_requirements.door_photos_required} fotoğraf/belge yüklendi.</p>
                <p className="mt-1">{job.completion_requirements.customer_confirmation_ready ? 'Müşteri onayı hazır.' : 'Müşteri onayı bekliyor.'}</p>
                <div className="mt-2 grid min-w-0 gap-1">
                  {photoStatuses.map((photo) => (
                    <p key={photo.field} className={`${photo.uploaded ? 'font-semibold text-emerald-700' : 'font-semibold text-rose-700'} min-w-0 [overflow-wrap:anywhere]`}>
                      {photo.label}: {photo.uploaded ? 'yüklendi' : 'eksik'}
                    </p>
                  ))}
                </div>
              </div>
            ) : (
              <div className="mt-3 rounded-xl border border-slate-200 bg-white p-3 text-xs text-slate-600">
                Bu tamamlanan işte yüklenen görseller read-only görüntülenir. Aktif SRV eksik fotoğraf şartları bu karta yansımaz.
              </div>
            )}
            <div className="mt-3 grid min-w-0 max-w-full gap-3 sm:grid-cols-3">
              {photoFieldsToRender.map(([field, label]) => {
                const uploadedPhoto = photoByField(field)
                const selectedFile = photoFiles[field]
                const selectedPreviewUrl = photoPreviewUrls[field]
                const showPicker = !uploadedPhoto || photoPickerField === field

                return (
                  <div key={field} className="grid min-w-0 max-w-full gap-3 overflow-hidden rounded-2xl border border-slate-200 bg-white p-3">
                    <div className="flex min-w-0 items-center justify-between gap-2">
                      <p className="min-w-0 truncate text-sm font-semibold text-slate-950">{label}</p>
                      <span className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold ${uploadedPhoto ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'}`}>
                        {uploadedPhoto ? 'Yüklendi' : 'Eksik'}
                      </span>
                    </div>
                    {uploadedPhoto?.preview_url ? (
                      <img src={uploadedPhoto.preview_url} alt={uploadedPhoto.label ?? label} className="h-40 w-full max-w-full rounded-xl object-cover" />
                    ) : uploadedPhoto ? (
                      <div className="min-w-0 max-w-full overflow-hidden rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-500">Belge yüklendi.</div>
                    ) : null}
                    {selectedPreviewUrl && !photoPreviewErrors[field] ? (
                      <div className="min-w-0 max-w-full overflow-hidden rounded-xl border border-blue-100 bg-blue-50 p-2">
                        <img src={selectedPreviewUrl} alt={`${label} yeni seçim`} onError={() => setPhotoPreviewErrors((current) => ({ ...current, [field]: true }))} className="h-36 w-full max-w-full rounded-lg object-cover" />
                        <p className="mt-2 min-w-0 max-w-full truncate text-xs font-semibold text-blue-800" title={selectedFile?.name}>{selectedFile?.name}</p>
                      </div>
                    ) : selectedFile ? (
                      <div className="min-w-0 max-w-full overflow-hidden rounded-xl border border-blue-100 bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-800">
                        <p className="min-w-0 max-w-full truncate" title={selectedFile.name}>{selectedFile.name}</p>
                        <p className="mt-1 min-w-0 font-normal text-blue-700 [overflow-wrap:anywhere]">Bu dosya yüklenebilir; tarayıcı önizlemesi desteklemeyebilir.</p>
                      </div>
                    ) : null}
                    {uploadedPhoto ? (
                      <div className="grid min-w-0 max-w-full gap-2 sm:grid-cols-2">
                        {uploadedPhoto.preview_url ? (
                          <a href={uploadedPhoto.preview_url} target="_blank" rel="noreferrer" className="min-w-0 truncate rounded-xl border border-slate-200 bg-white px-3 py-2 text-center text-xs font-semibold text-blue-700">
                            Belgeyi aç
                          </a>
                        ) : null}
                        {job.can_upload_photos ? (
                          <button type="button" onClick={() => setPhotoPickerField(field)} className="min-w-0 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700">
                            Değiştir
                          </button>
                        ) : null}
                      </div>
                    ) : null}
                    {showPicker && job.can_upload_photos ? (
                      <div className="grid min-w-0 max-w-full gap-2 text-xs font-semibold text-slate-600">
                        <span className="min-w-0 truncate">{uploadedPhoto ? 'Yeni belge seç' : 'Belge ekle'}</span>
                        <p className="text-[11px] font-normal text-slate-500">Dosya seçildiğinde otomatik yüklenir.</p>
                        <div className="grid min-w-0 max-w-full grid-cols-2 gap-2">
                          <label className="inline-flex min-w-0 max-w-full cursor-pointer items-center justify-center rounded-xl border border-blue-100 bg-blue-50 px-2 py-2 text-center text-blue-800">
                            Fotoğraf çek
                            <input
                              type="file"
                              accept={portalPhotoAccept}
                              capture="environment"
                              disabled={readOnly || !job.can_upload_photos}
                              onChange={(event) => updatePhotoFile(field, event.target.files?.[0] ?? null)}
                              className="sr-only"
                            />
                          </label>
                          <label className="inline-flex min-w-0 max-w-full cursor-pointer items-center justify-center rounded-xl border border-slate-200 bg-slate-50 px-2 py-2 text-center text-slate-700">
                            Dosya seç
                            <input
                              type="file"
                              accept={portalPhotoAccept}
                              disabled={readOnly || !job.can_upload_photos}
                              onChange={(event) => updatePhotoFile(field, event.target.files?.[0] ?? null)}
                              className="sr-only"
                            />
                          </label>
                        </div>
                      </div>
                    ) : null}
                    {uploadedPhoto?.review_status ? <p className="min-w-0 text-xs text-slate-500 [overflow-wrap:anywhere]">Uygunluk: {statusLabel(uploadedPhoto.review_status)}</p> : null}
                    {uploadedPhoto?.review_note ? <p className="min-w-0 text-xs text-slate-500 [overflow-wrap:anywhere]">{uploadedPhoto.review_note}</p> : null}
                  </div>
                )
              })}
              {job.can_upload_photos && hasSelectedPhoto ? (
                <button
                  type="button"
                  disabled={readOnly || actionLoading === 'photos'}
                  onClick={() => void submitPhotoUpload()}
                  className="w-full min-w-0 max-w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 disabled:cursor-not-allowed disabled:opacity-50 sm:col-span-3"
                >
                {actionLoading === 'photos' ? 'Fotoğraflar yükleniyor...' : 'Fotoğrafları yükle'}
              </button>
            ) : null}
            </div>
            {showCompletionRequirements && (job.previous_photos?.length ?? 0) > 0 ? (
              <details className="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-700">
                <summary className="cursor-pointer font-semibold text-slate-800">Önceki ziyaret görselleri</summary>
                <div className="mt-3 grid min-w-0 max-w-full gap-3 sm:grid-cols-3">
                  {job.previous_photos?.map((photo) => (
                    <div key={photo.id} className="min-w-0 overflow-hidden rounded-xl border border-slate-200 bg-white p-2">
                      <div className="flex min-w-0 items-center justify-between gap-2">
                        <p className="min-w-0 truncate font-semibold text-slate-800">{photo.label ?? 'Belge'}</p>
                        {photo.created_at ? <span className="shrink-0 text-[11px] text-slate-400">{dateTimeOrEmpty(photo.created_at, '-')}</span> : null}
                      </div>
                      {photo.preview_url ? (
                        <a href={photo.preview_url} target="_blank" rel="noreferrer" className="mt-2 block overflow-hidden rounded-lg border border-slate-200 bg-white">
                          <img src={photo.preview_url} alt={photo.label ?? 'Önceki ziyaret belgesi'} className="h-28 w-full object-cover" />
                        </a>
                      ) : null}
                    </div>
                  ))}
                </div>
              </details>
            ) : null}
          </PartnerDetailPanel>
        )}
        {job.portal_actions.length > 0 && (
          <details className="mt-5 rounded-2xl bg-slate-50 p-4">
            <summary className="cursor-pointer text-xs font-semibold uppercase tracking-wide text-slate-500">İşlem geçmişi</summary>
            <div className="mt-3 grid gap-2">
              {job.portal_actions.map((action, index) => (
                <div key={`${action.action}-${action.created_at ?? index}`} className="rounded-xl bg-white px-3 py-2 text-sm">
                  <div className="font-semibold text-slate-900">{actionLabel(action.action, action.status, action.action_label)} · {statusLabel(action.status, action.status_label)}</div>
                  {action.note && <div className="mt-1 text-slate-500">{action.note}</div>}
                </div>
              ))}
            </div>
          </details>
        )}
      </div>
      <aside className="hidden min-w-0 rounded-2xl border border-slate-200 bg-slate-50 p-4 lg:sticky lg:top-4 lg:block lg:self-start">
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
          {canUseAppointmentProposal && (
            <ActionBox title={canRequestAppointmentChange ? 'Randevu değişikliği iste' : (job.action_state === 'appointment_proposed_waiting' ? 'Randevu önerisini revize et' : 'Randevu saatleri öner')}>
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
                    <input type="date" className="w-full rounded-xl border border-slate-200 p-3 text-sm" value={slot.date} min={todayDateValue()} onChange={(event) => updateAppointmentSlot(index, { date: event.target.value })} disabled={readOnly} />
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
              }, canRequestAppointmentChange ? 'Randevu değişikliği operasyona gönderildi.' : 'Randevu önerisi operasyona gönderildi.')}
              className="rounded-xl border border-blue-200 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-800 disabled:cursor-not-allowed disabled:opacity-50"
            >
              {canRequestAppointmentChange ? 'Randevu değişikliği iste' : 'Randevu öner'}
            </button>
          </ActionBox>
          )}
          {job.can_reject && (
          <ActionBox title="İşi reddet" className="hidden lg:grid">
            <button type="button" disabled={readOnly || !job.can_reject} onClick={() => setActiveActionDialog('reject')} className="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-800 disabled:cursor-not-allowed disabled:opacity-50">
              İşi reddet
            </button>
          </ActionBox>
          )}
          {job.can_request_revisit && (
          <ActionBox title="Tekrar ziyaret iste" className="hidden lg:grid">
            <button type="button" disabled={readOnly || !job.can_request_revisit} onClick={() => setActiveActionDialog('revisit')} className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800 disabled:cursor-not-allowed disabled:opacity-50">
              Tekrar ziyaret iste
            </button>
          </ActionBox>
          )}
          {job.can_request_customer_otp && (
          <ActionBox title="Müşteri OTP / onay" className="hidden lg:grid">
            {!photosReady ? (
              <p className="rounded-xl border border-amber-100 bg-amber-50 p-3 text-xs font-semibold text-amber-900">
                {customerApprovalPhotoGateMessage}
              </p>
            ) : null}
            {approvalUrl || whatsappUrl ? (
              <div className="rounded-xl border border-violet-100 bg-white p-3 text-xs text-slate-600">
                <p className={dispatchStatus === 'failed' ? 'font-semibold text-rose-800' : 'font-semibold text-violet-900'}>{otpDispatchTitle}</p>
                {dispatchTestMode && dispatchTargetPhone ? (
                  <p className="mt-1 font-semibold text-slate-500">Test mod: {dispatchTargetPhone}</p>
                ) : null}
                {dispatchStatus === 'failed' && dispatchErrorMessage ? (
                  <p className="mt-1 text-rose-700">{dispatchErrorMessage}</p>
                ) : null}
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
            <button type="button" disabled={readOnly || !canRequestCustomerApproval} onClick={openCustomerApprovalDialog} className="rounded-xl border border-violet-200 bg-violet-50 px-3 py-2 text-sm font-semibold text-violet-800 disabled:cursor-not-allowed disabled:opacity-50">
              Müşteri onayı iste
            </button>
          </ActionBox>
          )}
          {job.can_request_support && (
          <ActionBox title="Yedek parça / ek talep" className="hidden lg:grid">
            <button type="button" disabled={readOnly || !job.can_request_support} onClick={() => setActiveActionDialog('support')} className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800 disabled:cursor-not-allowed disabled:opacity-50">
              Ek talep oluştur
            </button>
          </ActionBox>
          )}
          {job.can_request_price_revision && (
          <ActionBox title="Hakediş revize talebi" className="hidden lg:grid">
            <button type="button" disabled={readOnly || !job.can_request_price_revision} onClick={() => setActiveActionDialog('price')} className="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-800 disabled:cursor-not-allowed disabled:opacity-50">
              Hakediş revize talep et
            </button>
          </ActionBox>
          )}
          {completionReady && (
          <ActionBox title="Tamamlamaya gönder" className="hidden lg:grid">
            <select className="w-full rounded-xl border border-slate-200 p-3 text-sm" value={completionResult} onChange={(event) => setCompletionResult(event.target.value)} disabled={readOnly || !job.can_submit_completion}>
              <option value="completed">Tamamlandı</option>
              <option value="revisit_required">Tekrar ziyaret gerekli</option>
              <option value="customer_not_available">Müşteri yok</option>
              <option value="missing_info_or_photo">Eksik bilgi/fotoğraf</option>
              <option value="parts_pending">Parça/ürün bekleniyor</option>
            </select>
            <div className={`rounded-xl border p-3 text-xs ${completionReady ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-slate-200 bg-white text-slate-600'}`}>
              {completionReady ? (
                <p className="text-sm font-semibold">Saha belgeleri ve müşteri onayı tamam. İşi operasyon son kontrolüne gönderebilirsiniz.</p>
              ) : (
                <>
                  <p>{photosReady ? '3 fotoğraf hazır.' : '3 ayrı fotoğraf türü yüklenmeden tamamlamaya gönderilemez.'}</p>
                  <p>{confirmationReady ? 'Müşteri onayı hazır.' : 'Müşteri onayı olmadan tamamlamaya gönderilemez.'}</p>
                </>
              )}
              {completionMissingReasons.length > 0 && (
                <ul className="mt-2 grid gap-1">
                  {completionMissingReasons.map((reason) => (
                    <li key={reason}>- {reason}</li>
                  ))}
                </ul>
              )}
            </div>
            <textarea className="min-h-24 w-full rounded-xl border border-slate-200 p-3 text-sm" value={completionNote} onChange={(event) => setCompletionNote(event.target.value)} placeholder="İsterseniz yapılan işlemle ilgili kısa not ekleyin." disabled={readOnly || !job.can_submit_completion} />
            <button type="button" disabled={readOnly || !job.can_submit_completion || completionBlocked || actionLoading === 'submit-completion'} onClick={() => void submitAction('submit-completion', { result: completionResult, note: completionNote.trim() || null }, 'Tamamlama gönderimi son kontrol için operasyona düştü.')} className={`rounded-xl bg-emerald-600 font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-300 ${completionReady ? 'px-4 py-3 text-base shadow-sm shadow-emerald-200' : 'px-3 py-2 text-sm'}`}>
              Tamamlamaya gönder
            </button>
          </ActionBox>
          )}
          {canOnlyAddNote && (
            <div className="rounded-xl border border-slate-200 bg-white p-3 text-sm text-slate-600">
              Bu aşamada işlem kapalı. Operasyona not bırakabilirsiniz.
            </div>
          )}
          <ActionBox title="Operasyona not" className="hidden lg:grid">
            <textarea className="min-h-20 w-full rounded-xl border border-slate-200 p-3 text-sm" value={note} onChange={(event) => setNote(event.target.value)} placeholder="Not yaz" disabled={readOnly} />
            <button type="button" disabled={readOnly || note.trim().length < 3 || actionLoading === 'note'} onClick={() => void submitAction('note', { note, visibility: 'ops' }, 'Not eklendi.')} className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 disabled:cursor-not-allowed disabled:opacity-50">
              Not ekle
            </button>
          </ActionBox>
        </div>
      </aside>
      {!readOnly && !activeActionDialog && (
        <div className="fixed inset-x-0 bottom-0 z-[80] grid max-h-[36vh] max-w-[100dvw] grid-cols-2 gap-1.5 overflow-x-clip overflow-y-auto border-t border-slate-200 bg-white/95 p-2 pb-[calc(env(safe-area-inset-bottom)+0.5rem)] shadow-[0_-8px_24px_rgba(15,23,42,0.08)] backdrop-blur lg:hidden">
          {completionReady && (
            <button type="button" onClick={() => setActiveActionDialog('completion')} className={`min-w-0 truncate font-semibold leading-tight ${completionReady ? 'col-span-2 min-h-12 rounded-xl border border-emerald-700 bg-emerald-600 px-3 py-2.5 text-sm text-white shadow-lg shadow-emerald-200' : 'min-h-10 rounded-md border border-emerald-200 bg-emerald-50 px-2 py-1.5 text-xs text-emerald-800'}`}>
              Tamamlamaya gönder
            </button>
          )}
          {canAcceptAppointment && (
            <button type="button" disabled={actionLoading === 'accept-appointment'} onClick={() => void submitAction('accept-appointment', { note: acceptNote || null }, 'Randevu onayı gönderildi.')} className="min-h-10 min-w-0 truncate rounded-md border border-slate-800 bg-slate-950 px-2 py-1.5 text-xs font-semibold leading-tight text-white disabled:cursor-not-allowed disabled:opacity-60">
              Randevu onayla
            </button>
          )}
          {canUseAppointmentProposal && (
            <button type="button" onClick={() => setActiveActionDialog('appointment')} className="min-h-10 min-w-0 truncate rounded-md border border-blue-200 bg-blue-50 px-2 py-1.5 text-xs font-semibold leading-tight text-blue-800">
              {canRequestAppointmentChange ? 'Randevu güncelle' : 'Randevu öner'}
            </button>
          )}
          {job.can_reject && (
            <button type="button" onClick={() => setActiveActionDialog('reject')} className="min-h-10 min-w-0 truncate rounded-md border border-rose-200 bg-rose-50 px-2 py-1.5 text-xs font-semibold leading-tight text-rose-800">
              İşi reddet
            </button>
          )}
          {job.can_request_revisit && (
            <button type="button" onClick={() => setActiveActionDialog('revisit')} className="min-h-10 min-w-0 truncate rounded-md border border-amber-200 bg-amber-50 px-2 py-1.5 text-xs font-semibold leading-tight text-amber-800">
              Tekrar ziyaret
            </button>
          )}
          {job.can_request_support && (
            <button type="button" onClick={() => setActiveActionDialog('support')} className="min-h-10 min-w-0 truncate rounded-md border border-amber-200 bg-amber-50 px-2 py-1.5 text-xs font-semibold leading-tight text-amber-800">
              Ek talep
            </button>
          )}
          {job.can_request_customer_otp && (
            <button type="button" disabled={!canRequestCustomerApproval} onClick={openCustomerApprovalDialog} className="min-h-10 min-w-0 truncate rounded-md border border-violet-200 bg-violet-50 px-2 py-1.5 text-xs font-semibold leading-tight text-violet-800 disabled:cursor-not-allowed disabled:opacity-50">
              Müşteri onayı
            </button>
          )}
          {job.can_request_price_revision && (
            <button type="button" onClick={() => setActiveActionDialog('price')} className="min-h-10 min-w-0 truncate rounded-md border border-rose-200 bg-rose-50 px-2 py-1.5 text-xs font-semibold leading-tight text-rose-800">
              Hakediş revize
            </button>
          )}
          <button type="button" onClick={() => setActiveActionDialog('note')} className="min-h-10 min-w-0 truncate rounded-md border border-slate-200 bg-white px-2 py-1.5 text-xs font-semibold leading-tight text-slate-700">
            Not ekle
          </button>
        </div>
      )}
      <ActionDialog title={canRequestAppointmentChange ? 'Randevu değişikliği iste' : 'Randevu saatleri öner'} open={activeActionDialog === 'appointment'} onClose={() => setActiveActionDialog(null)}>
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
                <input type="date" className="w-full rounded-xl border border-slate-200 p-3 text-sm" value={slot.date} min={todayDateValue()} onChange={(event) => updateAppointmentSlot(index, { date: event.target.value })} disabled={readOnly} />
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
          }, canRequestAppointmentChange ? 'Randevu değişikliği operasyona gönderildi.' : 'Randevu önerisi operasyona gönderildi.').then(() => setActiveActionDialog(null))}
          className="rounded-xl border border-blue-200 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-800 disabled:cursor-not-allowed disabled:opacity-50"
        >
          {canRequestAppointmentChange ? 'Randevu değişikliği iste' : 'Randevu öner'}
        </button>
      </ActionDialog>
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
        <div className="min-w-0 rounded-xl border border-violet-100 bg-violet-50 p-3 text-sm text-violet-950">
          <p className="font-semibold">{otpDispatchTitle}</p>
          <div className="mt-2 grid gap-1 text-xs font-semibold text-violet-800">
            {dispatchTestMode && dispatchTargetPhone ? <p>Test mod: {dispatchTargetPhone}</p> : null}
            {job.customer_otp_request?.created_at ? <p>Son gönderim: {dateTimeOrEmpty(job.customer_otp_request.created_at, '-')}</p> : null}
          </div>
          {dispatchStatus === 'failed' && dispatchErrorMessage ? <p className="mt-2 rounded-lg bg-rose-100 px-2 py-1 text-xs font-semibold text-rose-800">{dispatchErrorMessage}</p> : null}
          {publicUrlWarning ? <p className="mt-2 rounded-lg bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-900">{publicUrlWarning}</p> : null}
        </div>
        <div className="min-w-0 rounded-xl border border-violet-100 bg-white p-3 text-sm text-slate-700">
          <p className="font-semibold text-slate-950">Onay linki</p>
          {approvalUrl ? (
            <div className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
              <button type="button" onClick={copyApprovalLink} className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700">
                Linki kopyala
              </button>
              <a href={approvalUrl} target="_blank" rel="noreferrer" className="rounded-lg border border-violet-200 bg-violet-50 px-3 py-2 text-center text-sm font-semibold text-violet-800">
                Onay linkini aç
              </a>
            </div>
          ) : (
            <p className="mt-2 text-xs text-slate-500">Gönderim sırasında yeni onay linki oluşturulacak.</p>
          )}
          {whatsappUrl ? (
            <a href={whatsappUrl} target="_blank" rel="noreferrer" className="mt-2 block rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-center text-sm font-semibold text-emerald-800">
              Son WhatsApp bağlantısını aç
            </a>
          ) : null}
        </div>
        <label className="grid min-w-0 gap-2 text-sm font-semibold text-slate-700">
          WhatsApp mesaj metni
          <textarea
            className="min-h-56 w-full min-w-0 resize-y rounded-xl border border-slate-200 p-3 text-sm leading-6 text-slate-800 [overflow-wrap:anywhere] disabled:bg-slate-50"
            value={otpMessageText}
            onChange={(event) => setOtpMessageText(event.target.value)}
            placeholder="Müşteriye gidecek WhatsApp mesajı"
            disabled={readOnly || actionLoading === 'customer-otp-request'}
          />
          <span className="text-xs font-normal text-slate-500">Onay linki metinde yoksa gönderim sırasında ayrı satır olarak eklenecek.</span>
        </label>
        <textarea className="min-h-20 w-full min-w-0 rounded-xl border border-slate-200 p-3 text-sm" value={otpNote} onChange={(event) => setOtpNote(event.target.value)} placeholder="Operasyona not" disabled={readOnly} />
        <button type="button" disabled={readOnly || !canRequestCustomerApproval || otpMessageText.trim().length < 3 || actionLoading === 'customer-otp-request'} onClick={() => void submitCustomerOtpRequest()} className="sticky bottom-0 rounded-xl bg-violet-700 px-3 py-2.5 text-sm font-semibold text-white shadow-sm disabled:cursor-not-allowed disabled:bg-slate-300">
          {actionLoading === 'customer-otp-request' ? 'Gönderiliyor...' : 'WhatsApp onay mesajı gönder'}
        </button>
      </ActionDialog>
      <ActionDialog title="Yedek parça / ek talep" open={activeActionDialog === 'support'} onClose={() => setActiveActionDialog(null)}>
        <select className="w-full rounded-xl border border-slate-200 p-3 text-sm" value={supportType} onChange={(event) => setSupportType(event.target.value)} disabled={readOnly}>
          <option value="spare_part">Yedek parça</option>
          <option value="technical_support">Teknik destek</option>
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
      <ActionDialog title="Tamamlamaya gönder" open={activeActionDialog === 'completion'} onClose={() => setActiveActionDialog(null)}>
        <select className="w-full rounded-xl border border-slate-200 p-3 text-sm" value={completionResult} onChange={(event) => setCompletionResult(event.target.value)} disabled={readOnly || !job.can_submit_completion}>
          <option value="completed">Tamamlandı</option>
          <option value="revisit_required">Tekrar ziyaret gerekli</option>
          <option value="customer_not_available">Müşteri yok</option>
          <option value="missing_info_or_photo">Eksik bilgi/fotoğraf</option>
          <option value="parts_pending">Parça/ürün bekleniyor</option>
        </select>
        <div className={`rounded-xl border p-3 text-xs ${completionReady ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-slate-200 bg-white text-slate-600'}`}>
          {completionReady ? (
            <p className="text-sm font-semibold">Saha belgeleri ve müşteri onayı tamam. İşi operasyon son kontrolüne gönderebilirsiniz.</p>
          ) : (
            <>
              <p>{photosReady ? '3 fotoğraf hazır.' : '3 ayrı fotoğraf türü yüklenmeden tamamlamaya gönderilemez.'}</p>
              <p>{confirmationReady ? 'Müşteri onayı hazır.' : 'Müşteri onayı olmadan tamamlamaya gönderilemez.'}</p>
            </>
          )}
          {completionMissingReasons.length > 0 && (
            <ul className="mt-2 grid gap-1">
              {completionMissingReasons.map((reason) => (
                <li key={reason}>- {reason}</li>
              ))}
            </ul>
          )}
        </div>
        <textarea className="min-h-24 w-full rounded-xl border border-slate-200 p-3 text-sm" value={completionNote} onChange={(event) => setCompletionNote(event.target.value)} placeholder="İsterseniz yapılan işlemle ilgili kısa not ekleyin." disabled={readOnly || !job.can_submit_completion} />
        <button type="button" disabled={readOnly || !job.can_submit_completion || completionBlocked || actionLoading === 'submit-completion'} onClick={() => void submitAction('submit-completion', { result: completionResult, note: completionNote.trim() || null }, 'Tamamlama gönderimi son kontrol için operasyona düştü.').then(() => setActiveActionDialog(null))} className={`rounded-xl bg-emerald-600 font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-300 ${completionReady ? 'px-4 py-3 text-base shadow-sm shadow-emerald-200' : 'px-3 py-2 text-sm'}`}>
          Tamamlamaya gönder
        </button>
      </ActionDialog>
      <ActionDialog title="Operasyona not" open={activeActionDialog === 'note'} onClose={() => setActiveActionDialog(null)}>
        <textarea className="min-h-24 w-full rounded-xl border border-slate-200 p-3 text-sm" value={note} onChange={(event) => setNote(event.target.value)} placeholder="Not yaz" disabled={readOnly} />
        <button type="button" disabled={readOnly || note.trim().length < 3 || actionLoading === 'note'} onClick={() => void submitAction('note', { note, visibility: 'ops' }, 'Not eklendi.').then(() => setActiveActionDialog(null))} className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 disabled:cursor-not-allowed disabled:opacity-50">
          Not ekle
        </button>
      </ActionDialog>
    </section>
  )
}

function ActionBox({ title, children, className = 'grid' }: { title: string, children: ReactNode, className?: string }) {
  return (
    <div className={`${className} gap-2`}>
      <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-400">{title}</h3>
      {children}
    </div>
  )
}

function PartnerDetailPanel({
  title,
  summary,
  tone,
  defaultOpen,
  panelKey,
  className = '',
  children,
}: {
  title: string
  summary: string
  tone: 'emerald' | 'blue' | 'slate'
  defaultOpen: boolean
  panelKey: string
  className?: string
  children: ReactNode
}) {
  const toneClass = {
    emerald: 'border-emerald-100 bg-emerald-50 text-emerald-950',
    blue: 'border-blue-100 bg-blue-50 text-blue-950',
    slate: 'border-slate-200 bg-slate-50 text-slate-950',
  }[tone]
  const [panelState, setPanelState] = useState({ panelKey, defaultOpen, open: defaultOpen })
  const panelOpen = panelState.panelKey === panelKey && panelState.defaultOpen === defaultOpen
    ? panelState.open
    : defaultOpen

  return (
    <details
      key={panelKey}
      open={panelOpen}
      onToggle={(event) => setPanelState({ panelKey, defaultOpen, open: event.currentTarget.open })}
      className={`group min-w-0 max-w-full overflow-hidden rounded-2xl border p-4 text-sm shadow-sm ${toneClass} ${className}`}
    >
      <summary className="flex cursor-pointer list-none items-start justify-between gap-3">
        <span className="min-w-0">
          <span className="block text-xs font-semibold uppercase tracking-wide opacity-80">{title}</span>
          <span className="mt-1 block text-xs leading-5 opacity-70">{summary}</span>
        </span>
        <ChevronDown className="h-4 w-4 shrink-0 text-slate-500 transition-transform group-open:rotate-180" />
      </summary>
      <div className="mt-3 min-w-0 max-w-full">
        {children}
      </div>
    </details>
  )
}

function ActionDialog({ title, open, onClose, children }: { title: string, open: boolean, onClose: () => void, children: ReactNode }) {
  if (!open) {
    return null
  }

  return (
    <div className="fixed inset-0 z-[70] grid max-w-[100dvw] place-items-end overflow-x-hidden bg-slate-950/45 p-0 sm:place-items-center sm:p-4">
      <button type="button" className="absolute inset-0 cursor-default" aria-label="Popup kapat" onClick={onClose} />
      <div className="relative z-10 grid max-h-[92dvh] w-[min(100%,calc(100dvw-1rem))] max-w-[calc(100dvw-1rem)] min-w-0 gap-4 overflow-y-auto overflow-x-hidden rounded-t-3xl bg-white p-4 shadow-2xl sm:max-w-lg sm:rounded-3xl sm:p-5">
        <div className="flex items-center justify-between gap-3">
          <h3 className="text-lg font-semibold text-slate-950">{title}</h3>
          <button type="button" onClick={onClose} className="inline-flex h-9 w-9 items-center justify-center rounded-full text-slate-500 hover:bg-slate-100" aria-label="Popup kapat">
            ×
          </button>
        </div>
        <div className="grid min-w-0 gap-3">
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
