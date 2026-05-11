import { Head } from '@inertiajs/react'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { Clock3, MapPin, MessageCircle, Phone, Wrench } from 'lucide-react'
import { TechnicalServicePageLinks } from '@/components/technical-service/TechnicalServicePageLinks'
import { Button } from '@/components/ui/button'
import { apiRequest } from '@/lib/api'

type FieldApiRequest = {
  id: number | string
  mrn: string
  customer_name: string
  customer_phone?: string | null
  customer_city?: string | null
  customer_district?: string | null
  service_address?: string | null
  product_name?: string | null
  product_model?: string | null
  serial_number?: string | null
  technician_name?: string | null
  workflow_status?: string | null
  field_status?: string | null
  next_action?: string | null
  sla_status?: string | null
  scheduled_at?: string | null
  scheduled_date?: string | null
  scheduled_time?: string | null
}

type FieldRequest = {
  id: string
  mrn: string
  customer: string
  phone: string
  city: string
  district: string
  address: string
  product: string
  model: string
  serialNumber: string
  technician: string
  workflowStatus: string
  fieldStatus: string
  nextAction: string
  slaStatus: string
  scheduledAt: string | null
  scheduledDate: string | null
  scheduledTime: string | null
}

const statusTone = (status: string) => {
  switch (status) {
    case 'Sahada':
      return 'border-emerald-200 bg-emerald-50 text-emerald-700'
    case 'Yolda':
      return 'border-blue-200 bg-blue-50 text-blue-700'
    case 'Parça Bekleniyor':
    case 'Beklemede':
      return 'border-amber-200 bg-amber-50 text-amber-800'
    case 'Belge / Fotoğraf Bekleyen':
    case 'Müşteri Kapanış Onayı Bekleyen':
      return 'border-violet-200 bg-violet-50 text-violet-700'
    case 'Tamamlandı':
      return 'border-emerald-200 bg-emerald-50 text-emerald-700'
    default:
      return 'border-slate-200 bg-slate-100 text-slate-700'
  }
}

const slaTone = (status: string) => {
  switch (status) {
    case 'geciken':
      return 'border-rose-200 bg-rose-50 text-rose-700'
    case 'yaklaşan':
      return 'border-amber-200 bg-amber-50 text-amber-800'
    default:
      return 'border-slate-200 bg-slate-100 text-slate-700'
  }
}

const mapRequest = (item: FieldApiRequest): FieldRequest => ({
  id: String(item.id),
  mrn: item.mrn,
  customer: item.customer_name,
  phone: item.customer_phone ?? '',
  city: item.customer_city ?? '',
  district: item.customer_district ?? '',
  address: item.service_address ?? '',
  product: item.product_name ?? '',
  model: item.product_model ?? '',
  serialNumber: item.serial_number ?? '',
  technician: item.technician_name ?? 'Atanmadı',
  workflowStatus: item.workflow_status ?? '-',
  fieldStatus: item.field_status ?? '-',
  nextAction: item.next_action ?? '-',
  slaStatus: item.sla_status ?? 'normal',
  scheduledAt: item.scheduled_at ?? null,
  scheduledDate: item.scheduled_date ?? null,
  scheduledTime: item.scheduled_time ?? null,
})

const formatTime = (request: FieldRequest) => {
  if (request.scheduledTime?.trim()) {
    return request.scheduledTime
  }

  if (!request.scheduledAt) {
    return '-'
  }

  const parsed = new Date(request.scheduledAt)
  if (Number.isNaN(parsed.getTime())) {
    return '-'
  }

  return `${String(parsed.getHours()).padStart(2, '0')}:${String(parsed.getMinutes()).padStart(2, '0')}`
}

const isToday = (value: string | null) => {
  if (!value) {
    return false
  }

  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) {
    return false
  }

  const today = new Date()
  return parsed.getFullYear() === today.getFullYear()
    && parsed.getMonth() === today.getMonth()
    && parsed.getDate() === today.getDate()
}

export default function TechnicalServiceFieldPage() {
  const [requests, setRequests] = useState<FieldRequest[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [activeId, setActiveId] = useState<string | null>(null)
  const [submittingAction, setSubmittingAction] = useState<string | null>(null)

  const loadRequests = useCallback(async () => {
    setLoading(true)
    setError(null)

    try {
      const response = await apiRequest('/api/technical-service/requests?limit=200')
      const items = Array.isArray(response.items) ? response.items : []
      setRequests(items.map((item: FieldApiRequest) => mapRequest(item)))
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Saha işleri alınamadı.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void Promise.resolve().then(loadRequests)
  }, [loadRequests])

  const openAssignedRequests = useMemo(
    () => requests.filter((request) => request.technician !== 'Atanmadı' && !['Tamamlandı', 'İptal'].includes(request.workflowStatus)),
    [requests],
  )

  const todayRequests = useMemo(
    () => openAssignedRequests.filter((request) => isToday(request.scheduledAt ?? request.scheduledDate)),
    [openAssignedRequests],
  )

  const plannedRequests = useMemo(
    () => openAssignedRequests.filter((request) => ['Planlı', 'Yolda', 'Sahada'].includes(request.workflowStatus)),
    [openAssignedRequests],
  )

  const overdueRequests = useMemo(
    () => openAssignedRequests.filter((request) => request.slaStatus === 'geciken'),
    [openAssignedRequests],
  )

  const submitFieldAction = useCallback(async (requestId: string, actionPath: 'start-travel' | 'arrive' | 'start-work') => {
    setSubmittingAction(`${requestId}:${actionPath}`)
    setError(null)

    try {
      await apiRequest(`/api/technical-service/requests/${requestId}/field/${actionPath}`, {
        method: 'PATCH',
        body: JSON.stringify({}),
      })

      await loadRequests()
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Saha aksiyonu kaydedilemedi.')
    } finally {
      setSubmittingAction(null)
    }
  }, [loadRequests])

  return (
    <>
      <Head title="Usta Saha İşleri" />

      <div className="min-h-screen bg-[#eaf1f8]">
        <div className="mx-auto w-full max-w-6xl space-y-6 px-4 py-6 md:px-6">
          <section className="overflow-hidden rounded-[28px] border border-white/80 bg-white/92 px-5 py-5 shadow-[0_18px_45px_rgba(15,23,42,0.08)] ring-1 ring-slate-200/70 backdrop-blur sm:px-6 sm:py-6">
            <div className="flex flex-col gap-4">
              <div>
                <p className="text-[11px] font-semibold uppercase tracking-[0.24em] text-slate-500">SAHA PWA</p>
                <h1 className="mt-3 text-3xl font-semibold tracking-tight text-slate-950">Usta Saha İşleri</h1>
                <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                  Atanmış işleri mobil odaklı akışla takip edin. Yola çıkış, sahaya varış ve iş başlangıcı bu ekrandan hızlıca işlenebilir.
                </p>
              </div>
              <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                {[
                  ['Bugünkü İşler', todayRequests.length],
                  ['Atanmış İşler', openAssignedRequests.length],
                  ['Planlı / Saha', plannedRequests.length],
                  ['Geciken İşler', overdueRequests.length],
                ].map(([label, value]) => (
                  <article key={String(label)} className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{label}</p>
                    <p className="mt-3 text-3xl font-semibold tracking-[-0.04em] text-slate-950">{value}</p>
                  </article>
                ))}
              </div>
            </div>
          </section>

          <TechnicalServicePageLinks />

          {error ? (
            <div className="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">{error}</div>
          ) : null}

          {loading ? (
            <div className="rounded-2xl border border-slate-200 bg-white p-5 text-sm text-slate-500">Saha işleri yükleniyor...</div>
          ) : openAssignedRequests.length === 0 ? (
            <div className="rounded-2xl border border-slate-200 bg-white p-5 text-sm text-slate-500">
              Atanmış açık saha işi bulunamadı.
            </div>
          ) : (
            <section className="grid gap-4">
              {openAssignedRequests.map((request) => {
                const active = activeId === request.id
                const phoneDigits = request.phone.replace(/[^\d+]/g, '')
                const phoneHref = phoneDigits ? `tel:${phoneDigits}` : '#'
                const whatsappHref = phoneDigits ? `https://wa.me/${phoneDigits.replace(/^\+/, '')}` : '#'

                return (
                  <article key={request.id} className="rounded-[24px] border border-slate-200 bg-white p-4 shadow-sm">
                    <div className="flex flex-col gap-4">
                      <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="space-y-2">
                          <div className="flex flex-wrap items-center gap-2">
                            <span className="rounded-full bg-slate-950 px-3 py-1 text-xs font-semibold text-white">{request.mrn}</span>
                            <span className={['rounded-full border px-3 py-1 text-xs font-semibold', statusTone(request.workflowStatus)].join(' ')}>
                              {request.workflowStatus}
                            </span>
                            <span className={['rounded-full border px-3 py-1 text-xs font-semibold', slaTone(request.slaStatus)].join(' ')}>
                              SLA: {request.slaStatus}
                            </span>
                          </div>
                          <h2 className="text-lg font-semibold text-slate-950">{request.customer}</h2>
                          <p className="text-sm text-slate-600">{request.technician}</p>
                        </div>
                        <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-right">
                          <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Saat</p>
                          <p className="mt-1 text-sm font-semibold text-slate-950">{formatTime(request)}</p>
                        </div>
                      </div>

                      <div className="grid gap-3 text-sm text-slate-700 md:grid-cols-2">
                        <div className="flex items-start gap-2">
                          <MapPin className="mt-0.5 h-4 w-4 text-slate-400" />
                          <span className="break-words">{request.address || '-'}{request.city || request.district ? ` · ${[request.city, request.district].filter(Boolean).join(' / ')}` : ''}</span>
                        </div>
                        <div className="flex items-start gap-2">
                          <Wrench className="mt-0.5 h-4 w-4 text-slate-400" />
                          <span className="break-words">{[request.product, request.model, request.serialNumber].filter(Boolean).join(' / ') || '-'}</span>
                        </div>
                        <div className="flex items-start gap-2">
                          <Clock3 className="mt-0.5 h-4 w-4 text-slate-400" />
                          <span>{request.fieldStatus !== '-' ? `Saha durumu: ${request.fieldStatus}` : request.workflowStatus}</span>
                        </div>
                        <div className="text-slate-700">
                          <span className="font-medium">Sıradaki aksiyon:</span> {request.nextAction || '-'}
                        </div>
                      </div>

                      {active ? (
                        <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                          <p><span className="font-medium">Telefon:</span> {request.phone || '-'}</p>
                          <p className="mt-2"><span className="font-medium">Planlanan tarih:</span> {request.scheduledDate || '-'}</p>
                          <p className="mt-2"><span className="font-medium">İş durumu:</span> {request.workflowStatus}</p>
                          <p className="mt-2"><span className="font-medium">Saha aksiyonu:</span> {request.nextAction || '-'}</p>
                        </div>
                      ) : null}

                      <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-6">
                        <Button asChild variant="outline" className="h-10">
                          <a href={phoneHref}><Phone className="mr-2 h-4 w-4" />Ara</a>
                        </Button>
                        <Button asChild variant="secondary" className="h-10">
                          <a href={whatsappHref} target="_blank" rel="noreferrer"><MessageCircle className="mr-2 h-4 w-4" />WhatsApp</a>
                        </Button>
                        <Button
                          type="button"
                          variant="outline"
                          className="h-10"
                          disabled={!['Planlı'].includes(request.workflowStatus) || submittingAction !== null}
                          onClick={() => void submitFieldAction(request.id, 'start-travel')}
                        >
                          {submittingAction === `${request.id}:start-travel` ? 'Kaydediliyor...' : 'Yola Çıktı'}
                        </Button>
                        <Button
                          type="button"
                          variant="outline"
                          className="h-10"
                          disabled={!['Planlı', 'Yolda'].includes(request.workflowStatus) || submittingAction !== null}
                          onClick={() => void submitFieldAction(request.id, 'arrive')}
                        >
                          {submittingAction === `${request.id}:arrive` ? 'Kaydediliyor...' : 'Sahaya Vardı'}
                        </Button>
                        <Button
                          type="button"
                          variant="outline"
                          className="h-10"
                          disabled={!['Yolda', 'Sahada'].includes(request.workflowStatus) || submittingAction !== null}
                          onClick={() => void submitFieldAction(request.id, 'start-work')}
                        >
                          {submittingAction === `${request.id}:start-work` ? 'Kaydediliyor...' : 'İşe Başladı'}
                        </Button>
                        <Button type="button" variant="outline" className="h-10" onClick={() => setActiveId(active ? null : request.id)}>
                          Detay Aç
                        </Button>
                      </div>
                    </div>
                  </article>
                )
              })}
            </section>
          )}
        </div>
      </div>
    </>
  )
}
