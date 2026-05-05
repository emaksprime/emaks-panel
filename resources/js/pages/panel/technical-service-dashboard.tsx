import { Head, Link } from '@inertiajs/react'
import { useCallback, useEffect, useMemo, useState } from 'react'
import Heading from '@/components/heading'
import { formatTechnicalServiceDateTime } from '@/components/technical-service/utils'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { apiRequest } from '@/lib/api'

type OperationRequest = {
  id: number | string
  mrn: string
  customer_name: string
  customer_city?: string | null
  customer_district?: string | null
  product_name?: string | null
  product_model?: string | null
  serial_number?: string | null
  service_type?: string | null
  technician_name?: string | null
  scheduled_at?: string | null
  scheduled_time?: string | null
  status: string
  installation_completed_at?: string | null
  warranty_started_at?: string | null
  overdue_label?: string | null
}

type DashboardResponse = {
  summary: Record<string, number>
  today_appointments: OperationRequest[]
  overdue_requests: OperationRequest[]
  warranty_started_requests: OperationRequest[]
  past_scheduled_not_completed: OperationRequest[]
  technician_summary: Array<{
    technician_name: string
    today_jobs: number
    open_jobs: number
    completed_jobs: number
    overdue_jobs: number
  }>
  city_summary: Array<{
    city: string
    open_requests: number
    today_appointments: number
    overdue_requests: number
  }>
}

const initialFilters = {
  date_from: '',
  date_to: '',
  status: '',
  service_type: '',
  city: '',
  technician_name: '',
  warranty_started: '',
  overdue: '',
}

const summaryCards = [
  ['today_appointments', 'Bugünkü Randevular', 'blue'],
  ['pending', 'Bekleyen Talepler', 'slate'],
  ['assigned', 'Atanmış Talepler', 'slate'],
  ['scheduled', 'Randevulu Talepler', 'blue'],
  ['in_progress', 'Devam Eden İşler', 'amber'],
  ['completed', 'Tamamlanan İşler', 'green'],
  ['cancelled', 'İptal Edilen İşler', 'slate'],
  ['overdue', 'Geciken İşler', 'red'],
  ['warranty_started', 'Garanti Başlayan İşler', 'green'],
  ['past_scheduled_not_completed', 'Fiili Tarihi Geçmiş Açık İşler', 'amber'],
] as const

const cardClassName = (tone: string): string => {
  switch (tone) {
    case 'blue':
      return 'border-blue-200 bg-blue-50 text-blue-800'
    case 'green':
      return 'border-emerald-200 bg-emerald-50 text-emerald-800'
    case 'amber':
      return 'border-amber-200 bg-amber-50 text-amber-800'
    case 'red':
      return 'border-rose-200 bg-rose-50 text-rose-800'
    default:
      return 'border-slate-200 bg-white text-slate-800'
  }
}

const formatDateTime = (value: string | null | undefined): string => {
  return formatTechnicalServiceDateTime(value)
}

function RequestList({ title, items, tone = 'slate', showOverdue = false, showWarranty = false }: {
  title: string
  items: OperationRequest[]
  tone?: string
  showOverdue?: boolean
  showWarranty?: boolean
}) {
  return (
    <section className="rounded-2xl border border-slate-200 bg-white">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-4">
        <h2 className="text-sm font-semibold text-slate-950">{title}</h2>
        <Badge variant="outline" className={cardClassName(tone)}>{items.length}</Badge>
      </div>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[980px] text-left text-sm">
          <thead className="bg-slate-50 text-xs uppercase tracking-[0.08em] text-slate-500">
            <tr>
              <th className="px-4 py-3">MRN</th>
              <th className="px-4 py-3">Müşteri</th>
              <th className="px-4 py-3">İl / İlçe</th>
              <th className="px-4 py-3">Ürün / Model</th>
              <th className="px-4 py-3">Seri No</th>
              <th className="px-4 py-3">Servis</th>
              <th className="px-4 py-3">Usta</th>
              <th className="px-4 py-3">Randevu</th>
              <th className="px-4 py-3">Durum</th>
              {showOverdue ? <th className="px-4 py-3">Gecikme</th> : null}
              {showWarranty ? <th className="px-4 py-3">Garanti Başlangıcı</th> : null}
            </tr>
          </thead>
          <tbody>
            {items.length === 0 ? (
              <tr>
                <td colSpan={showOverdue || showWarranty ? 10 : 9} className="px-4 py-5 text-slate-500">Kayıt yok.</td>
              </tr>
            ) : items.map((item) => (
              <tr key={`${item.id}-${item.mrn}`} className="border-t border-slate-100 align-top">
                <td className="px-4 py-3 font-semibold text-slate-950">
                  <Link href={`/technical-service?search=${encodeURIComponent(item.mrn)}`} className="hover:underline">
                    {item.mrn}
                  </Link>
                </td>
                <td className="px-4 py-3 text-slate-700">{item.customer_name}</td>
                <td className="px-4 py-3 text-slate-700">{[item.customer_city, item.customer_district].filter(Boolean).join(' / ') || '-'}</td>
                <td className="px-4 py-3 text-slate-700">{[item.product_name, item.product_model].filter(Boolean).join(' / ') || '-'}</td>
                <td className="px-4 py-3 text-slate-700">{item.serial_number || '-'}</td>
                <td className="px-4 py-3 text-slate-700">{item.service_type || '-'}</td>
                <td className="px-4 py-3 text-slate-700">{item.technician_name || 'Atanmadı'}</td>
                <td className="px-4 py-3 text-slate-700">{formatDateTime(item.scheduled_at)}</td>
                <td className="px-4 py-3"><Badge variant="secondary">{item.status}</Badge></td>
                {showOverdue ? <td className="px-4 py-3 text-rose-700">{item.overdue_label || '-'}</td> : null}
                {showWarranty ? <td className="px-4 py-3 text-emerald-700">{formatDateTime(item.installation_completed_at)}</td> : null}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  )
}

export default function TechnicalServiceDashboard() {
  const [filters, setFilters] = useState(initialFilters)
  const [data, setData] = useState<DashboardResponse | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const query = useMemo(() => {
    const params = new URLSearchParams()
    Object.entries(filters).forEach(([key, value]) => {
      if (value !== '') {
        params.set(key, value)
      }
    })

    return params.toString()
  }, [filters])

  const loadDashboard = useCallback(async () => {
    setLoading(true)
    setError(null)

    try {
      const response = await apiRequest(`/api/technical-service/operations-dashboard?${query}`)
      setData(response as DashboardResponse)
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Dashboard verisi alınamadı.')
    } finally {
      setLoading(false)
    }
  }, [query])

  useEffect(() => {
    void Promise.resolve().then(loadDashboard)
  }, [loadDashboard])

  return (
    <>
      <Head title="Teknik Servis İç Operasyon Pilot Dashboard" />
      <div className="mx-auto w-full max-w-[1800px] space-y-6 px-4 py-6 md:px-6 lg:px-10">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
          <Heading
            title="Teknik Servis İç Operasyon Pilot Dashboard"
            description="İç ekip için günlük randevu, gecikme, garanti ve operasyon takip ekranı."
          />
          <div className="flex flex-wrap gap-2">
            <Button asChild variant="secondary"><Link href="/technical-service">Talepler</Link></Button>
            <Button asChild variant="secondary"><Link href="/technical-service/earnings">Hakedişler</Link></Button>
            <Button asChild variant="secondary"><Link href="/technical-service/serial-query">Seri No Sorgu</Link></Button>
            <Button type="button" onClick={() => void loadDashboard()} disabled={loading}>
              {loading ? 'Yükleniyor...' : 'Yenile'}
            </Button>
          </div>
        </div>

        <section className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 md:grid-cols-4 xl:grid-cols-8">
          <label className="grid gap-1 text-xs font-semibold text-slate-600">
            Başlangıç
            <Input type="date" value={filters.date_from} onChange={(event) => setFilters((current) => ({ ...current, date_from: event.target.value }))} />
          </label>
          <label className="grid gap-1 text-xs font-semibold text-slate-600">
            Bitiş
            <Input type="date" value={filters.date_to} onChange={(event) => setFilters((current) => ({ ...current, date_to: event.target.value }))} />
          </label>
          <label className="grid gap-1 text-xs font-semibold text-slate-600">
            Durum
            <select className="h-10 rounded-md border border-slate-200 px-3 text-sm" value={filters.status} onChange={(event) => setFilters((current) => ({ ...current, status: event.target.value }))}>
              <option value="">Tümü</option>
              {['Yeni', 'Atandı', 'Randevulu', 'Devam Ediyor', 'Tamamlandı', 'İptal'].map((status) => <option key={status} value={status}>{status}</option>)}
            </select>
          </label>
          <label className="grid gap-1 text-xs font-semibold text-slate-600">
            Servis Tipi
            <select className="h-10 rounded-md border border-slate-200 px-3 text-sm" value={filters.service_type} onChange={(event) => setFilters((current) => ({ ...current, service_type: event.target.value }))}>
              <option value="">Tümü</option>
              {['Montaj', 'Arıza', 'Kontrol'].map((type) => <option key={type} value={type}>{type}</option>)}
            </select>
          </label>
          <label className="grid gap-1 text-xs font-semibold text-slate-600">
            İl
            <Input value={filters.city} onChange={(event) => setFilters((current) => ({ ...current, city: event.target.value }))} placeholder="İl" />
          </label>
          <label className="grid gap-1 text-xs font-semibold text-slate-600">
            Usta
            <Input value={filters.technician_name} onChange={(event) => setFilters((current) => ({ ...current, technician_name: event.target.value }))} placeholder="Usta" />
          </label>
          <label className="grid gap-1 text-xs font-semibold text-slate-600">
            Garanti
            <select className="h-10 rounded-md border border-slate-200 px-3 text-sm" value={filters.warranty_started} onChange={(event) => setFilters((current) => ({ ...current, warranty_started: event.target.value }))}>
              <option value="">Tümü</option>
              <option value="1">Başladı</option>
              <option value="0">Başlamadı</option>
            </select>
          </label>
          <label className="grid gap-1 text-xs font-semibold text-slate-600">
            Gecikme
            <select className="h-10 rounded-md border border-slate-200 px-3 text-sm" value={filters.overdue} onChange={(event) => setFilters((current) => ({ ...current, overdue: event.target.value }))}>
              <option value="">Tümü</option>
              <option value="1">Gecikenler</option>
            </select>
          </label>
        </section>

        {error ? <div className="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">{error}</div> : null}

        <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
          {summaryCards.map(([key, label, tone]) => (
            <div key={key} className={['rounded-2xl border p-4', cardClassName(tone)].join(' ')}>
              <p className="text-xs font-semibold uppercase tracking-[0.08em] opacity-75">{label}</p>
              <p className="mt-3 text-3xl font-semibold">{data?.summary?.[key] ?? 0}</p>
            </div>
          ))}
        </section>

        <div className="grid gap-6 xl:grid-cols-2">
          <RequestList title="Bugünkü Randevular" items={data?.today_appointments ?? []} tone="blue" />
          <RequestList title="Geciken İşler" items={data?.overdue_requests ?? []} tone="red" showOverdue />
          <RequestList title="Garanti Başlayan İşler" items={data?.warranty_started_requests ?? []} tone="green" showWarranty />
          <RequestList title="Tamamlanmamış Ama Randevusu Geçmiş İşler" items={data?.past_scheduled_not_completed ?? []} tone="amber" showOverdue />
        </div>

        <div className="grid gap-6 xl:grid-cols-2">
          <section className="rounded-2xl border border-slate-200 bg-white p-4">
            <h2 className="text-sm font-semibold text-slate-950">Usta Bazlı Özet</h2>
            <div className="mt-4 grid gap-2">
              {(data?.technician_summary ?? []).map((item) => (
                <div key={item.technician_name} className="grid gap-2 rounded-xl border border-slate-200 p-3 text-sm md:grid-cols-5">
                  <span className="font-semibold text-slate-950">{item.technician_name}</span>
                  <span>Bugün: {item.today_jobs}</span>
                  <span>Açık: {item.open_jobs}</span>
                  <span>Tamamlanan: {item.completed_jobs}</span>
                  <span className="text-rose-700">Geciken: {item.overdue_jobs}</span>
                </div>
              ))}
            </div>
          </section>
          <section className="rounded-2xl border border-slate-200 bg-white p-4">
            <h2 className="text-sm font-semibold text-slate-950">Şehir Bazlı İş Yoğunluğu</h2>
            <div className="mt-4 grid gap-2">
              {(data?.city_summary ?? []).map((item) => (
                <div key={item.city} className="grid gap-2 rounded-xl border border-slate-200 p-3 text-sm md:grid-cols-4">
                  <span className="font-semibold text-slate-950">{item.city}</span>
                  <span>Açık: {item.open_requests}</span>
                  <span>Bugün: {item.today_appointments}</span>
                  <span className="text-rose-700">Geciken: {item.overdue_requests}</span>
                </div>
              ))}
            </div>
          </section>
        </div>
      </div>
    </>
  )
}
