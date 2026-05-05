import { Head, Link } from '@inertiajs/react'
import { useCallback, useEffect, useMemo, useState } from 'react'
import Heading from '@/components/heading'
import { formatTechnicalServiceDateTime } from '@/components/technical-service/utils'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { apiRequest } from '@/lib/api'

type Technician = {
  id: number | string
  name: string
  city?: string | null
}

type Earning = {
  id: number
  technical_service_technician_id?: number | null
  technician_name_snapshot: string
  city_snapshot?: string | null
  job_count: number
  installation_count: number
  service_count: number
  labor_total: string | number
  travel_fee_total: string | number
  travel_round_trip_km_total: string | number
  travel_billable_km_total: string | number
  grand_total: string | number
  status: string
  internal_note?: string | null
  dispute_note?: string | null
  paid_at?: string | null
}

type EarningItem = {
  id: number
  mrn: string
  job_date: string
  customer_city?: string | null
  customer_district?: string | null
  service_type?: string | null
  product_name?: string | null
  serial_number?: string | null
  labor_amount: string | number
  travel_round_trip_km: string | number
  travel_billable_km: string | number
  travel_fee_amount: string | number
  line_total: string | number
  note?: string | null
}

type EarningDetail = Earning & {
  items: EarningItem[]
}

type EarningsResponse = {
  period: null | {
    id: number
    year: number
    month: number
    status: string
    calculated_at?: string | null
  }
  items: Earning[]
  summary: {
    technician_count: number
    job_count: number
    labor_total: number
    travel_fee_total: number
    grand_total: number
    payable_count: number
    paid_count: number
    disputed_count: number
  }
}

const statusOptions = ['', 'Kontrol Bekliyor', 'Ödenecek', 'Ödendi', 'İtirazlı']
const months = [
  ['1', 'Ocak'],
  ['2', 'Şubat'],
  ['3', 'Mart'],
  ['4', 'Nisan'],
  ['5', 'Mayıs'],
  ['6', 'Haziran'],
  ['7', 'Temmuz'],
  ['8', 'Ağustos'],
  ['9', 'Eylül'],
  ['10', 'Ekim'],
  ['11', 'Kasım'],
  ['12', 'Aralık'],
]

const money = (value: string | number | null | undefined): string => `${Number(value ?? 0).toLocaleString('tr-TR', {
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
})} TL`

const numberText = (value: string | number | null | undefined): string => Number(value ?? 0).toLocaleString('tr-TR', {
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
})

const formatDate = (value: string | null | undefined): string => {
  return formatTechnicalServiceDateTime(value)
}

const badgeClass = (status: string): string => {
  switch (status) {
    case 'Ödenecek':
      return 'border-emerald-200 bg-emerald-50 text-emerald-700'
    case 'Ödendi':
      return 'border-blue-200 bg-blue-50 text-blue-700'
    case 'İtirazlı':
      return 'border-rose-200 bg-rose-50 text-rose-700'
    default:
      return 'border-amber-200 bg-amber-50 text-amber-800'
  }
}

export default function TechnicalServiceEarnings() {
  const now = new Date()
  const [year, setYear] = useState(String(now.getFullYear()))
  const [month, setMonth] = useState(String(now.getMonth() + 1))
  const [technicianId, setTechnicianId] = useState('')
  const [status, setStatus] = useState('')
  const [technicians, setTechnicians] = useState<Technician[]>([])
  const [data, setData] = useState<EarningsResponse | null>(null)
  const [loading, setLoading] = useState(false)
  const [calculating, setCalculating] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [detail, setDetail] = useState<EarningDetail | null>(null)
  const [detailOpen, setDetailOpen] = useState(false)
  const [whatsappText, setWhatsappText] = useState('')
  const [whatsappOpen, setWhatsappOpen] = useState(false)

  const query = useMemo(() => {
    const params = new URLSearchParams({ year, month })

    if (technicianId) {
      params.set('technician_id', technicianId)
    }

    if (status) {
      params.set('status', status)
    }

    return params.toString()
  }, [month, status, technicianId, year])

  const loadTechnicians = useCallback(async () => {
    const response = await apiRequest('/api/technical-service/technicians?active=1')
    setTechnicians(Array.isArray(response.items) ? response.items : [])
  }, [])

  const loadEarnings = useCallback(async () => {
    setLoading(true)
    setError(null)

    try {
      const response = await apiRequest(`/api/technical-service/earnings?${query}`)
      setData(response as EarningsResponse)
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Hakediş verisi alınamadı.')
    } finally {
      setLoading(false)
    }
  }, [query])

  const calculatePeriod = async () => {
    setCalculating(true)
    setError(null)

    try {
      await apiRequest('/api/technical-service/earnings/periods/calculate', {
        method: 'POST',
        body: JSON.stringify({ year: Number(year), month: Number(month) }),
      })
      await loadEarnings()
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Hakediş dönemi hesaplanamadı.')
    } finally {
      setCalculating(false)
    }
  }

  const openDetail = async (earning: Earning) => {
    const response = await apiRequest(`/api/technical-service/earnings/${earning.id}`)
    setDetail(response.earning as EarningDetail)
    setDetailOpen(true)
  }

  const markPaid = async (earning: Earning) => {
    await apiRequest(`/api/technical-service/earnings/${earning.id}/mark-paid`, { method: 'POST' })
    await loadEarnings()
  }

  const markDisputed = async (earning: Earning) => {
    await apiRequest(`/api/technical-service/earnings/${earning.id}`, {
      method: 'PATCH',
      body: JSON.stringify({ status: 'İtirazlı', dispute_note: 'Operasyon kontrolü için itirazlı işaretlendi.' }),
    })
    await loadEarnings()
  }

  const openWhatsapp = async (earning: Earning) => {
    const response = await apiRequest(`/api/technical-service/earnings/${earning.id}/whatsapp-text`)
    setWhatsappText(String(response.text ?? ''))
    setWhatsappOpen(true)
  }

  useEffect(() => {
    void Promise.resolve().then(loadTechnicians)
  }, [loadTechnicians])

  useEffect(() => {
    void Promise.resolve().then(loadEarnings)
  }, [loadEarnings])

  const summary = data?.summary ?? {
    technician_count: 0,
    job_count: 0,
    labor_total: 0,
    travel_fee_total: 0,
    grand_total: 0,
    payable_count: 0,
    paid_count: 0,
    disputed_count: 0,
  }

  return (
    <>
      <Head title="Servis Hakedişleri" />
      <div className="mx-auto w-full max-w-[1800px] space-y-6 px-4 py-6 md:px-6 lg:px-10">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
          <Heading
            title="Servis Hakedişleri"
            description="Servis ve çilingir bazlı aylık hakediş kontrolü."
          />
          <div className="flex flex-wrap gap-2">
            <Button asChild variant="secondary"><Link href="/technical-service">Talepler</Link></Button>
            <Button asChild variant="secondary"><Link href="/technical-service/dashboard">Operasyon Dashboard</Link></Button>
            <Button type="button" onClick={() => void loadEarnings()} disabled={loading}>{loading ? 'Yükleniyor...' : 'Yenile'}</Button>
          </div>
        </div>

        <section className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 md:grid-cols-5">
          <label className="grid gap-1 text-xs font-semibold text-slate-600">
            Ay
            <select className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm" value={month} onChange={(event) => setMonth(event.target.value)}>
              {months.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </select>
          </label>
          <label className="grid gap-1 text-xs font-semibold text-slate-600">
            Yıl
            <Input value={year} onChange={(event) => setYear(event.target.value)} />
          </label>
          <label className="grid gap-1 text-xs font-semibold text-slate-600">
            Servis / Usta
            <select className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm" value={technicianId} onChange={(event) => setTechnicianId(event.target.value)}>
              <option value="">Tümü</option>
              {technicians.map((technician) => (
                <option key={technician.id} value={String(technician.id)}>{technician.name}</option>
              ))}
            </select>
          </label>
          <label className="grid gap-1 text-xs font-semibold text-slate-600">
            Durum
            <select className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm" value={status} onChange={(event) => setStatus(event.target.value)}>
              {statusOptions.map((option) => <option key={option || 'all'} value={option}>{option || 'Tümü'}</option>)}
            </select>
          </label>
          <div className="flex items-end">
            <Button className="w-full" type="button" onClick={() => void calculatePeriod()} disabled={calculating}>
              {calculating ? 'Hesaplanıyor...' : 'Hesapla / Yeniden Hesapla'}
            </Button>
          </div>
        </section>

        {error ? (
          <div className="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">{error}</div>
        ) : null}

        <section className="grid gap-3 md:grid-cols-3 xl:grid-cols-6">
          {[
            ['Servis Sayısı', summary.technician_count],
            ['İş Sayısı', summary.job_count],
            ['Hizmet Bedeli', money(summary.labor_total)],
            ['Yol Ücreti', money(summary.travel_fee_total)],
            ['Genel Hakediş', money(summary.grand_total)],
            ['Ödenecek / Ödendi / İtirazlı', `${summary.payable_count} / ${summary.paid_count} / ${summary.disputed_count}`],
          ].map(([label, value]) => (
            <div key={label} className="rounded-2xl border border-slate-200 bg-white p-4">
              <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{label}</p>
              <p className="mt-2 text-lg font-semibold text-slate-950">{value}</p>
            </div>
          ))}
        </section>

        <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
          <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-4">
            <div>
              <h2 className="text-sm font-semibold text-slate-950">Servis Bazlı Hakedişler</h2>
              <p className="mt-1 text-xs text-slate-500">Period: {data?.period ? `${data.period.month}/${data.period.year} - ${data.period.status}` : 'Henüz hesaplanmadı'}</p>
            </div>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[1200px] text-left text-sm">
              <thead className="bg-slate-50 text-xs uppercase tracking-[0.08em] text-slate-500">
                <tr>
                  <th className="px-4 py-3">Servis</th>
                  <th className="px-4 py-3">Şehir</th>
                  <th className="px-4 py-3">İş</th>
                  <th className="px-4 py-3">Montaj</th>
                  <th className="px-4 py-3">Servis/Arıza</th>
                  <th className="px-4 py-3">Hizmet</th>
                  <th className="px-4 py-3">Yol</th>
                  <th className="px-4 py-3">Toplam</th>
                  <th className="px-4 py-3">Durum</th>
                  <th className="px-4 py-3">Aksiyon</th>
                </tr>
              </thead>
              <tbody>
                {data?.items.length ? data.items.map((earning) => (
                  <tr key={earning.id} className="border-t border-slate-100 align-top">
                    <td className="px-4 py-3 font-semibold text-slate-950">{earning.technician_name_snapshot}</td>
                    <td className="px-4 py-3 text-slate-700">{earning.city_snapshot || '-'}</td>
                    <td className="px-4 py-3 text-slate-700">{earning.job_count}</td>
                    <td className="px-4 py-3 text-slate-700">{earning.installation_count}</td>
                    <td className="px-4 py-3 text-slate-700">{earning.service_count}</td>
                    <td className="px-4 py-3 text-slate-700">{money(earning.labor_total)}</td>
                    <td className="px-4 py-3 text-slate-700">{money(earning.travel_fee_total)}</td>
                    <td className="px-4 py-3 font-semibold text-slate-950">{money(earning.grand_total)}</td>
                    <td className="px-4 py-3"><Badge variant="outline" className={badgeClass(earning.status)}>{earning.status}</Badge></td>
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap gap-2">
                        <Button size="sm" variant="secondary" onClick={() => void openDetail(earning)}>Detay</Button>
                        <Button size="sm" variant="secondary" onClick={() => void openWhatsapp(earning)}>WhatsApp</Button>
                        <Button size="sm" variant="outline" onClick={() => void markPaid(earning)} disabled={earning.status === 'Ödendi'}>Ödendi</Button>
                        <Button size="sm" variant="destructive" onClick={() => void markDisputed(earning)}>İtirazlı</Button>
                      </div>
                    </td>
                  </tr>
                )) : (
                  <tr>
                    <td colSpan={10} className="px-4 py-6 text-slate-500">Bu dönem için hakediş kaydı yok. Hesapla / Yeniden Hesapla ile oluşturabilirsiniz.</td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </section>
      </div>

      <Dialog open={detailOpen} onOpenChange={setDetailOpen}>
        <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-[1100px]">
          <DialogHeader>
            <DialogTitle>{detail?.technician_name_snapshot ?? 'Hakediş Detayı'}</DialogTitle>
            <DialogDescription>Servis detay ekstresi</DialogDescription>
          </DialogHeader>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[1000px] text-left text-sm">
              <thead className="bg-slate-50 text-xs uppercase tracking-[0.08em] text-slate-500">
                <tr>
                  <th className="px-3 py-2">MRN</th>
                  <th className="px-3 py-2">İş Tarihi</th>
                  <th className="px-3 py-2">İl/İlçe</th>
                  <th className="px-3 py-2">Tip</th>
                  <th className="px-3 py-2">Ürün</th>
                  <th className="px-3 py-2">Seri No</th>
                  <th className="px-3 py-2">Hizmet</th>
                  <th className="px-3 py-2">Km</th>
                  <th className="px-3 py-2">Ücretli Km</th>
                  <th className="px-3 py-2">Yol</th>
                  <th className="px-3 py-2">Toplam</th>
                  <th className="px-3 py-2">Not</th>
                </tr>
              </thead>
              <tbody>
                {detail?.items.map((item) => (
                  <tr key={item.id} className="border-t border-slate-100 align-top">
                    <td className="px-3 py-2 font-semibold">{item.mrn}</td>
                    <td className="px-3 py-2">{formatDate(item.job_date)}</td>
                    <td className="px-3 py-2">{[item.customer_city, item.customer_district].filter(Boolean).join(' / ') || '-'}</td>
                    <td className="px-3 py-2">{item.service_type || '-'}</td>
                    <td className="px-3 py-2">{item.product_name || '-'}</td>
                    <td className="px-3 py-2">{item.serial_number || '-'}</td>
                    <td className="px-3 py-2">{money(item.labor_amount)}</td>
                    <td className="px-3 py-2">{numberText(item.travel_round_trip_km)}</td>
                    <td className="px-3 py-2">{numberText(item.travel_billable_km)}</td>
                    <td className="px-3 py-2">{money(item.travel_fee_amount)}</td>
                    <td className="px-3 py-2 font-semibold">{money(item.line_total)}</td>
                    <td className="px-3 py-2 text-amber-700">{item.note || '-'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </DialogContent>
      </Dialog>

      <Dialog open={whatsappOpen} onOpenChange={setWhatsappOpen}>
        <DialogContent className="sm:max-w-[720px]">
          <DialogHeader>
            <DialogTitle>WhatsApp Hakediş Metni</DialogTitle>
            <DialogDescription>Kopyalanabilir servis ekstresi</DialogDescription>
          </DialogHeader>
          <textarea
            className="min-h-[420px] w-full rounded-md border border-slate-200 p-3 text-sm"
            readOnly
            value={whatsappText}
          />
          <Button type="button" onClick={() => void navigator.clipboard?.writeText(whatsappText)}>Kopyala</Button>
        </DialogContent>
      </Dialog>
    </>
  )
}
