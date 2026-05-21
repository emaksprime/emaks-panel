import { Head, Link } from '@inertiajs/react'
import { useCallback, useEffect, useMemo, useState } from 'react'
import Heading from '@/components/heading'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { apiRequest } from '@/lib/api'

type Capability = 'dealer' | 'locksmith' | 'manufacturer' | 'seller'
type TabKey = 'general' | 'partners' | 'orders' | 'stock' | 'deliveries' | 'locksmiths' | 'earnings' | 'alerts'

type PartnerStatus = {
  id: number
  display_name: string
  partner_code: string
  capabilities: Capability[]
  mikro_cari_kodu: string | null
  phone: string | null
  email: string | null
  city: string | null
  district: string | null
  address_missing: boolean
  users_count: number
  active_users_count: number
  linked_technicians_count: number
  child_cari_count: number
  active: boolean
  last_activity: { action: string, created_at: string | null } | null
}

type DashboardSummary = {
  partner_counts: Record<string, number>
  missing_data_counts: Record<string, number>
  service_counts: Record<string, number>
  user_counts: Record<string, number>
  stock_order_placeholders: Record<string, { status: string, reason: string }>
  recent_activity: Array<{ id: number, partner_name: string | null, action: string, created_at: string | null }>
  partner_status: PartnerStatus[]
}

type PlaceholderResponse = {
  status: string
  reason: string | null
  message: string | null
  rows: unknown[]
  summary?: Record<string, number>
  snapshot_contract?: unknown[]
}

type LocksmithRow = {
  id: number
  name: string
  phone: string | null
  city: string | null
  district: string | null
  linked_partners: Array<{ partner_name: string | null, relationship_type: string, is_primary: boolean }>
  open_jobs: number
  today_jobs: number
  completed_jobs: number
  pending_earnings: number
}

type EarningsRow = {
  id: number
  technician_name: string
  partner_names: string[]
  period: string | null
  job_count: number
  labor_total: number
  travel_fee_total: number
  grand_total: number
  status: string
}

type Filters = {
  search: string
  capability: '' | Capability | 'multi_role'
  active: '' | '1' | '0'
  city: string
  mikro_cari_kodu: string
  user_state: '' | 'with_users' | 'without_users'
  technician_state: '' | 'with_technicians' | 'without_technicians'
  data_state: '' | 'missing_invoice' | 'complete_invoice'
  child_cari_state: '' | 'with_child_cari' | 'without_child_cari'
}

const initialFilters: Filters = {
  search: '',
  capability: '',
  active: '',
  city: '',
  mikro_cari_kodu: '',
  user_state: '',
  technician_state: '',
  data_state: '',
  child_cari_state: '',
}

const capabilityLabel = (capability: Capability | 'multi_role') => {
  const labels = {
    dealer: 'Bayi',
    locksmith: 'Çilingir',
    manufacturer: 'Üretici',
    seller: 'Satıcı',
    multi_role: 'Çok rollü',
  }

  return labels[capability]
}

const numberFormat = new Intl.NumberFormat('tr-TR')
const currencyFormat = new Intl.NumberFormat('tr-TR', { style: 'currency', currency: 'TRY', maximumFractionDigits: 0 })

function compactLocation(city: string | null, district: string | null) {
  return [city, district].filter(Boolean).join(' / ') || '-'
}

function buildQuery(filters: Filters) {
  const params = new URLSearchParams()

  Object.entries(filters).forEach(([key, value]) => {
    if (value !== '') {
      params.set(key, value)
    }
  })

  return params.toString()
}

function KpiCard({ title, value, tone = 'slate', hint }: { title: string, value: number | string, tone?: 'slate' | 'sky' | 'emerald' | 'amber' | 'rose', hint?: string }) {
  const toneClass = {
    slate: 'border-slate-200 bg-white text-slate-950',
    sky: 'border-sky-100 bg-sky-50 text-sky-950',
    emerald: 'border-emerald-100 bg-emerald-50 text-emerald-950',
    amber: 'border-amber-100 bg-amber-50 text-amber-950',
    rose: 'border-rose-100 bg-rose-50 text-rose-950',
  }[tone]

  return (
    <div className={`rounded-xl border p-4 shadow-sm ${toneClass}`}>
      <div className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{title}</div>
      <div className="mt-2 text-2xl font-semibold">{typeof value === 'number' ? numberFormat.format(value) : value}</div>
      {hint && <div className="mt-1 text-xs font-medium text-slate-500">{hint}</div>}
    </div>
  )
}

function EmptyContract({ title, message }: { title: string, message: string }) {
  return (
    <div className="rounded-xl border border-dashed border-slate-200 bg-slate-50 p-5">
      <div className="text-sm font-semibold text-slate-900">{title}</div>
      <p className="mt-1 text-sm leading-6 text-slate-600">{message}</p>
    </div>
  )
}

export default function B2BDashboard() {
  const [summary, setSummary] = useState<DashboardSummary | null>(null)
  const [orders, setOrders] = useState<PlaceholderResponse | null>(null)
  const [stock, setStock] = useState<PlaceholderResponse | null>(null)
  const [locksmiths, setLocksmiths] = useState<LocksmithRow[]>([])
  const [earnings, setEarnings] = useState<{ status: string, message: string | null, rows: EarningsRow[] } | null>(null)
  const [filters, setFilters] = useState<Filters>(initialFilters)
  const [activeTab, setActiveTab] = useState<TabKey>('general')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const loadDashboard = useCallback(async (nextFilters: Filters = initialFilters) => {
    setLoading(true)
    setError(null)

    try {
      const query = buildQuery(nextFilters)
      const [summaryPayload, ordersPayload, stockPayload, locksmithPayload, earningsPayload] = await Promise.all([
        apiRequest(`/api/b2b/dashboard/summary${query ? `?${query}` : ''}`),
        apiRequest('/api/b2b/dashboard/orders'),
        apiRequest('/api/b2b/dashboard/stock'),
        apiRequest('/api/b2b/dashboard/locksmiths'),
        apiRequest('/api/b2b/dashboard/earnings'),
      ])

      setSummary(summaryPayload)
      setOrders(ordersPayload)
      setStock(stockPayload)
      setLocksmiths(locksmithPayload.items ?? [])
      setEarnings(earningsPayload)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'B2B kokpit verisi alınamadı.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      void loadDashboard(initialFilters)
    }, 0)

    return () => window.clearTimeout(timer)
  }, [loadDashboard])

  const kpis = useMemo(() => {
    const partner = summary?.partner_counts ?? {}
    const missing = summary?.missing_data_counts ?? {}
    const service = summary?.service_counts ?? {}

    return [
      ['Toplam partner', partner.total ?? 0, 'slate', 'Tüm kayıtlar'],
      ['Aktif bayi', partner.active_dealers ?? 0, 'sky', 'Bayi rolü'],
      ['Aktif çilingir', partner.active_locksmiths ?? 0, 'emerald', 'Servis rolü'],
      ['Bayi + çilingir', partner.active_dealer_locksmith ?? 0, 'emerald', 'Çok rollü'],
      ['Üretici', partner.active_manufacturers ?? 0, 'slate', 'Kanal rolü'],
      ['Satıcı', partner.active_sellers ?? 0, 'slate', 'Kanal rolü'],
      ['Kullanıcısız', missing.partners_without_users ?? 0, 'amber', 'Aksiyon gerekli'],
      ['Ustasız çilingir', missing.locksmiths_without_technicians ?? 0, 'rose', 'Bağlantı eksik'],
      ['Cari bilgisi eksik', missing.partners_missing_cari_info ?? 0, 'amber', 'Kod/adres kontrolü'],
      ['Açık servis işi', service.open_service_jobs ?? 0, 'sky', 'Teknik servis'],
      ['Bekleyen hakediş', locksmiths.reduce((total, row) => total + row.pending_earnings, 0), 'amber', 'Usta bazlı'],
      ['Konsinye/teşhir uyarısı', missing.partners_with_child_cari ?? 0, 'slate', 'Snapshot sözleşmesi'],
    ] as Array<[string, number, 'slate' | 'sky' | 'emerald' | 'amber' | 'rose', string]>
  }, [summary, locksmiths])

  const applyFilters = () => {
    void loadDashboard(filters)
  }

  const clearFilters = () => {
    setFilters(initialFilters)
    void loadDashboard(initialFilters)
  }

  const tabs: Array<[TabKey, string]> = [
    ['general', 'Genel'],
    ['partners', 'Partnerler'],
    ['orders', 'Siparişler'],
    ['stock', 'Stok/Konsinye'],
    ['deliveries', 'Teslimatlar'],
    ['locksmiths', 'Çilingirler'],
    ['earnings', 'Hakediş'],
    ['alerts', 'Uyarılar'],
  ]

  return (
    <div className="space-y-6 overflow-x-hidden p-6">
      <Head title="Bayi & Çilingir Kokpiti" />
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <Heading title="Bayi & Çilingir Kokpiti" description="Operasyon ve yönetim için merkezi B2B partner izleme ekranı" />
          <p className="mt-2 text-sm text-slate-500">Bu ekran şirket içi operasyon içindir; partner kullanıcıları kendi /partner portalını görür.</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Link href="/panel/b2b/partners" className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm">
            Partner Yönetimi
          </Link>
          <Link href="/panel/b2b/users" className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm">
            Kullanıcılar
          </Link>
        </div>
      </div>

      {error && <div className="rounded-xl border border-rose-100 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800">{error}</div>}

      <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {kpis.map(([title, value, tone, hint]) => (
          <KpiCard key={title} title={title} value={loading ? '-' : value} tone={tone} hint={hint} />
        ))}
      </section>

      <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div className="grid gap-3 lg:grid-cols-[minmax(0,1.5fr)_repeat(4,minmax(0,1fr))]">
          <Input value={filters.search} onChange={(event) => setFilters({ ...filters, search: event.target.value })} placeholder="Kod, ad, cari, telefon veya şehir ara" />
          <select className="rounded-md border border-slate-200 bg-white px-3 text-sm" value={filters.capability} onChange={(event) => setFilters({ ...filters, capability: event.target.value as Filters['capability'] })}>
            <option value="">Tüm roller</option>
            <option value="dealer">Bayi</option>
            <option value="locksmith">Çilingir</option>
            <option value="manufacturer">Üretici</option>
            <option value="seller">Satıcı</option>
            <option value="multi_role">Çok rollü</option>
          </select>
          <select className="rounded-md border border-slate-200 bg-white px-3 text-sm" value={filters.active} onChange={(event) => setFilters({ ...filters, active: event.target.value as Filters['active'] })}>
            <option value="">Aktif/Pasif</option>
            <option value="1">Aktif</option>
            <option value="0">Pasif</option>
          </select>
          <Input value={filters.city} onChange={(event) => setFilters({ ...filters, city: event.target.value })} placeholder="Şehir" />
          <Input value={filters.mikro_cari_kodu} onChange={(event) => setFilters({ ...filters, mikro_cari_kodu: event.target.value })} placeholder="Mikro cari kodu" />
        </div>
        <div className="mt-3 grid gap-3 md:grid-cols-4">
          <select className="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm" value={filters.user_state} onChange={(event) => setFilters({ ...filters, user_state: event.target.value as Filters['user_state'] })}>
            <option value="">Kullanıcı durumu</option>
            <option value="with_users">Kullanıcısı var</option>
            <option value="without_users">Kullanıcısı yok</option>
          </select>
          <select className="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm" value={filters.technician_state} onChange={(event) => setFilters({ ...filters, technician_state: event.target.value as Filters['technician_state'] })}>
            <option value="">Usta bağlantısı</option>
            <option value="with_technicians">Ustası var</option>
            <option value="without_technicians">Ustası yok</option>
          </select>
          <select className="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm" value={filters.data_state} onChange={(event) => setFilters({ ...filters, data_state: event.target.value as Filters['data_state'] })}>
            <option value="">Cari/fatura durumu</option>
            <option value="missing_invoice">Eksik</option>
            <option value="complete_invoice">Tamam</option>
          </select>
          <select className="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm" value={filters.child_cari_state} onChange={(event) => setFilters({ ...filters, child_cari_state: event.target.value as Filters['child_cari_state'] })}>
            <option value="">Alt cari</option>
            <option value="with_child_cari">Alt cari var</option>
            <option value="without_child_cari">Alt cari yok</option>
          </select>
        </div>
        <div className="mt-3 flex flex-wrap gap-2">
          <Button type="button" onClick={applyFilters} disabled={loading}>{loading ? 'Yükleniyor...' : 'Filtrele'}</Button>
          <Button type="button" variant="outline" onClick={clearFilters}>Temizle</Button>
        </div>
      </section>

      <nav className="flex flex-wrap gap-2">
        {tabs.map(([key, label]) => (
          <button
            key={key}
            type="button"
            onClick={() => setActiveTab(key)}
            className={`rounded-lg px-3 py-2 text-sm font-semibold ${activeTab === key ? 'bg-slate-950 text-white' : 'border border-slate-200 bg-white text-slate-700'}`}
          >
            {label}
          </button>
        ))}
      </nav>

      {activeTab === 'general' && (
        <section className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_380px]">
          <PartnerList partners={summary?.partner_status ?? []} compact />
          <RecentActivity rows={summary?.recent_activity ?? []} />
        </section>
      )}
      {activeTab === 'partners' && <PartnerList partners={summary?.partner_status ?? []} />}
      {activeTab === 'orders' && <OrdersSection orders={orders} />}
      {activeTab === 'stock' && <StockSection stock={stock} />}
      {activeTab === 'deliveries' && (
        <EmptyContract
          title="Partner Teslimatları"
          message="Bayi irsaliye ve teslimat doğrulama akışı için API/UI sözleşmesi hazırlandı. Gerçek irsaliye datasource bağlanmadan local teslimat tablosu açmak şu an gereksiz migration olur."
        />
      )}
      {activeTab === 'locksmiths' && <LocksmithSection rows={locksmiths} />}
      {activeTab === 'earnings' && <EarningsSection data={earnings} />}
      {activeTab === 'alerts' && <AlertsSection summary={summary} />}
    </div>
  )
}

function PartnerList({ partners, compact = false }: { partners: PartnerStatus[], compact?: boolean }) {
  if (partners.length === 0) {
    return <EmptyContract title="Partner durumları" message="Filtrelere uygun partner bulunamadı." />
  }

  return (
    <section className="grid gap-3">
      {partners.slice(0, compact ? 8 : 100).map((partner) => (
        <article key={partner.id} className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2">
                <h3 className="text-base font-semibold text-slate-950">{partner.display_name}</h3>
                <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${partner.active ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'}`}>
                  {partner.active ? 'Aktif' : 'Pasif'}
                </span>
              </div>
              <div className="mt-2 flex flex-wrap gap-2">
                {partner.capabilities.map((capability) => (
                  <span key={capability} className="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">{capabilityLabel(capability)}</span>
                ))}
              </div>
            </div>
            <div className="flex flex-wrap gap-2">
              <Link href="/panel/b2b/partners" className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700">Detay</Link>
              <Link href="/panel/b2b/users" className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700">Kullanıcılar</Link>
              <button type="button" disabled className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-400">Portal Önizle</button>
            </div>
          </div>
          <div className="mt-3 grid gap-2 text-sm text-slate-600 md:grid-cols-4">
            <div><span className="font-semibold text-slate-800">Cari:</span> {partner.mikro_cari_kodu ?? '-'}</div>
            <div><span className="font-semibold text-slate-800">Telefon:</span> {partner.phone ?? '-'}</div>
            <div><span className="font-semibold text-slate-800">E-posta:</span> {partner.email ?? '-'}</div>
            <div><span className="font-semibold text-slate-800">Konum:</span> {compactLocation(partner.city, partner.district)}</div>
            <div><span className="font-semibold text-slate-800">Adres:</span> {partner.address_missing ? 'Eksik' : 'Tamam'}</div>
            <div><span className="font-semibold text-slate-800">Kullanıcı:</span> {partner.active_users_count}/{partner.users_count}</div>
            <div><span className="font-semibold text-slate-800">Usta:</span> {partner.linked_technicians_count}</div>
            <div><span className="font-semibold text-slate-800">Alt cari:</span> {partner.child_cari_count}</div>
          </div>
        </article>
      ))}
    </section>
  )
}

function RecentActivity({ rows }: { rows: DashboardSummary['recent_activity'] }) {
  return (
    <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
      <h2 className="text-sm font-semibold text-slate-950">Son aktiviteler</h2>
      <div className="mt-3 grid gap-2">
        {rows.length === 0 && <p className="text-sm text-slate-500">Aktivite yok.</p>}
        {rows.map((row) => (
          <div key={row.id} className="rounded-lg bg-slate-50 px-3 py-2 text-sm">
            <div className="font-semibold text-slate-800">{row.partner_name ?? 'Partner'}</div>
            <div className="text-slate-500">{row.action}</div>
          </div>
        ))}
      </div>
    </section>
  )
}

function OrdersSection({ orders }: { orders: PlaceholderResponse | null }) {
  const summary = orders?.summary ?? {}

  return (
    <section className="grid gap-4">
      <div className="grid gap-3 md:grid-cols-3 xl:grid-cols-6">
        {[
          ['Bekleyen siparişler', summary.pending ?? 0],
          ['Onay bekleyenler', summary.approval_pending ?? 0],
          ['Sevk hazırlığında', summary.preparing ?? 0],
          ['Yolda', summary.in_transit ?? 0],
          ['Teslim edildi', summary.delivered ?? 0],
          ['Eksik/fark bildirimi', summary.discrepancy ?? 0],
        ].map(([title, value]) => <KpiCard key={title} title={String(title)} value={Number(value)} />)}
      </div>
      <EmptyContract title="Partner Siparişleri" message={orders?.message ?? 'Sipariş datasource sonraki fazda bağlanacak.'} />
    </section>
  )
}

function StockSection({ stock }: { stock: PlaceholderResponse | null }) {
  return (
    <section className="grid gap-4">
      <EmptyContract title="Partner Stok / Konsinye / Teşhir" message={stock?.message ?? 'Konsinye/teşhir stok datasource sonraki fazda bağlanacak.'} />
      {(stock?.snapshot_contract?.length ?? 0) > 0 && (
        <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
          <h2 className="text-sm font-semibold text-slate-950">Alt cari snapshot kayıtları</h2>
          <p className="mt-1 text-sm text-slate-500">Konsinye/teşhir/proje alt cari mappingleri partner metadata üzerinden izleniyor.</p>
        </div>
      )}
    </section>
  )
}

function LocksmithSection({ rows }: { rows: LocksmithRow[] }) {
  return (
    <section className="grid gap-3">
      {rows.length === 0 && <EmptyContract title="Çilingir / Usta Durumu" message="Aktif usta bulunamadı." />}
      {rows.map((row) => (
        <article key={row.id} className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h3 className="font-semibold text-slate-950">{row.name}</h3>
              <p className="mt-1 text-sm text-slate-500">{[row.phone, compactLocation(row.city, row.district)].filter(Boolean).join(' · ')}</p>
            </div>
            <div className="grid grid-cols-4 gap-2 text-center text-xs font-semibold text-slate-600">
              <span>Açık {row.open_jobs}</span>
              <span>Bugün {row.today_jobs}</span>
              <span>Tamam {row.completed_jobs}</span>
              <span>Hakediş {row.pending_earnings}</span>
            </div>
          </div>
          <div className="mt-3 flex flex-wrap gap-2">
            {row.linked_partners.map((partner, index) => (
              <span key={`${row.id}-${index}`} className="rounded-full bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700">
                {partner.partner_name ?? 'Partner'} · {partner.relationship_type}
              </span>
            ))}
          </div>
        </article>
      ))}
    </section>
  )
}

function EarningsSection({ data }: { data: { status: string, message: string | null, rows: EarningsRow[] } | null }) {
  if (!data || data.rows.length === 0) {
    return <EmptyContract title="Çilingir Hakedişleri" message={data?.message ?? 'Hakediş verisi oluştuğunda burada listelenecek.'} />
  }

  return (
    <section className="grid gap-3">
      {data.rows.map((row) => (
        <article key={row.id} className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h3 className="font-semibold text-slate-950">{row.technician_name}</h3>
              <p className="mt-1 text-sm text-slate-500">{row.partner_names.join(', ') || 'Partner bağlantısı yok'} · {row.period ?? '-'}</p>
            </div>
            <div className="text-right">
              <div className="font-semibold text-slate-950">{currencyFormat.format(row.grand_total)}</div>
              <div className="text-xs text-slate-500">{row.status}</div>
            </div>
          </div>
        </article>
      ))}
    </section>
  )
}

function AlertsSection({ summary }: { summary: DashboardSummary | null }) {
  const missing = summary?.missing_data_counts ?? {}

  return (
    <section className="grid gap-3 md:grid-cols-3">
      <KpiCard title="Kullanıcısı olmayan partner" value={missing.partners_without_users ?? 0} tone="amber" />
      <KpiCard title="Usta bağlantısı olmayan çilingir" value={missing.locksmiths_without_technicians ?? 0} tone="rose" />
      <KpiCard title="Cari/fatura bilgisi eksik" value={missing.partners_missing_cari_info ?? 0} tone="amber" />
    </section>
  )
}
