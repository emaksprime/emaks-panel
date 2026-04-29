import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import type { ServiceRequest, ServiceRequestEvent } from './types'

const statusVariant = (status: ServiceRequest['status']) => {
  switch (status) {
    case 'Yeni':
      return 'secondary'
    case 'Atandı':
      return 'default'
    case 'Randevulu':
      return 'warning'
    case 'Devam Ediyor':
      return 'accent'
    case 'Tamamlandı':
      return 'positive'
    case 'İptal':
      return 'destructive'
    default:
      return 'default'
  }
}

const riskVariant = (risk: ServiceRequest['riskLevel']) => {
  switch (risk) {
    case 'Kritik':
      return 'destructive'
    case 'Yüksek':
      return 'warning'
    case 'Orta':
      return 'default'
    default:
      return 'secondary'
  }
}

const priorityVariant = (priority: ServiceRequest['priority']) => {
  switch (priority) {
    case 'Kritik':
      return 'destructive'
    case 'Yüksek':
      return 'warning'
    case 'Orta':
      return 'default'
    default:
      return 'secondary'
  }
}

type ServiceRequestDetailsProps = {
  request: ServiceRequest
  events: ServiceRequestEvent[]
  loading: boolean
  error?: string | null
  onAssign?: () => void
  onSchedule?: () => void
  onComplete?: () => void
}

const eventTime = (timestamp: string): string => {
  const date = new Date(timestamp)

  if (Number.isNaN(date.getTime())) {
    return 'Bilinmiyor'
  }

  return date.toLocaleString('tr-TR', {
    dateStyle: 'short',
    timeStyle: 'short',
  })
}

export function ServiceRequestDetails({
  request,
  events,
  loading,
  error,
  onAssign,
  onSchedule,
  onComplete,
}: ServiceRequestDetailsProps) {
  const isActionDisabled = request.status === 'Tamamlandı' || request.status === 'İptal'
  const disabledTitle = 'Tamamlanan veya iptal edilen taleplerde işlem yapılamaz'

  return (
    <Card className="rounded-3xl border-slate-200 bg-white shadow-sm">
      <CardHeader className="space-y-3 px-6 py-6">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <p className="text-xs font-semibold uppercase tracking-[0.15em] text-slate-500">Seçili MRN</p>
            <CardTitle className="mt-2 text-xl text-slate-950">{request.mrn}</CardTitle>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <Badge variant={statusVariant(request.status)}>{request.status}</Badge>
            <Badge variant={priorityVariant(request.priority)}>{request.priority}</Badge>
          </div>
        </div>
        <p className="text-sm text-slate-600">{request.customer} için servis, randevu ve SLA detaylarını takip edin.</p>
      </CardHeader>

      <CardContent className="space-y-6 px-6 pb-6">
        <section className="grid gap-4 sm:grid-cols-2">
          <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Müşteri</p>
            <p className="mt-3 text-sm font-semibold text-slate-900">{request.customer}</p>
            <p className="mt-1 text-sm text-slate-600">{request.phone}</p>
          </div>
          <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Adres</p>
            <p className="mt-3 text-sm text-slate-900">{request.address}</p>
            <p className="mt-2 text-sm text-slate-600">{request.city} / {request.district}</p>
          </div>
        </section>

        <section className="grid gap-4 rounded-2xl border border-slate-200 bg-slate-50 p-4">
          <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Cihaz / Seri No</p>
          <div className="grid gap-2 text-sm text-slate-700">
            <div className="flex justify-between">
              <span className="font-semibold">Ürün</span>
              <span>{request.product}</span>
            </div>
            <div className="flex justify-between">
              <span className="font-semibold">Model</span>
              <span>{request.model}</span>
            </div>
            <div className="flex justify-between">
              <span className="font-semibold">Seri No</span>
              <span>{request.serialNumber}</span>
            </div>
          </div>
        </section>

        <section className="grid gap-4 rounded-2xl border border-slate-200 p-4">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Usta / Çilingir</p>
              <p className="mt-2 text-sm font-semibold text-slate-900">{request.technician}</p>
            </div>
            <div className="flex items-center gap-2">
              <p className="text-xs uppercase tracking-[0.14em] text-slate-500">Servis Tipi</p>
              <Badge variant="secondary">{request.serviceType}</Badge>
            </div>
          </div>
          <div className="grid gap-3 sm:grid-cols-2">
            <div className="rounded-2xl border border-slate-200 bg-white p-3">
              <p className="text-xs uppercase tracking-[0.14em] text-slate-500">Randevu</p>
              <p className="mt-2 text-sm font-semibold text-slate-900">{request.appointment}</p>
            </div>
            <div className="rounded-2xl border border-slate-200 bg-white p-3">
              <p className="text-xs uppercase tracking-[0.14em] text-slate-500">SLA</p>
              <p className="mt-2 text-sm font-semibold text-slate-900">{request.sla}</p>
              <div className="mt-2 flex items-center gap-2">
                <span className="text-xs text-slate-500">Risk</span>
                <Badge variant={riskVariant(request.riskLevel)}>{request.riskLevel}</Badge>
              </div>
            </div>
          </div>
        </section>

        <section className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
          <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Notlar</p>
          <p className="mt-2 text-sm leading-6 text-slate-700">{request.notes}</p>
        </section>

        <section className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
          <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Durum Timeline</p>
          <div className="mt-4 space-y-4">
            {loading ? (
              <div className="rounded-2xl border border-slate-200 bg-white p-4 text-sm text-slate-500">
                Detay yükleniyor...
              </div>
            ) : error ? (
              <div className="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                {error}
              </div>
            ) : events.length === 0 ? (
              <div className="rounded-2xl border border-slate-200 bg-white p-4 text-sm text-slate-500">
                Henüz işlem geçmişi yok.
              </div>
            ) : (
              events.map((event) => (
                <div key={String(event.id)} className="flex gap-3 text-sm">
                  <div className="mt-1 h-2.5 w-2.5 rounded-full bg-slate-400" />
                  <div>
                    <p className="font-semibold text-slate-900">{event.title}</p>
                    <p className="text-xs text-slate-500">
                      {eventTime(event.created_at)}
                      {event.note ? ` · ${event.note}` : ''}
                    </p>
                  </div>
                </div>
              ))
            )}
          </div>
        </section>

        <section className="grid gap-3 sm:grid-cols-2">
          <Button
            type="button"
            onClick={() => onAssign?.()}
            disabled={isActionDisabled}
            title={isActionDisabled ? disabledTitle : undefined}
          >
            Usta Ata
          </Button>
          <Button
            variant="outline"
            type="button"
            onClick={() => onSchedule?.()}
            disabled={isActionDisabled}
            title={isActionDisabled ? disabledTitle : undefined}
          >
            Randevu Planla
          </Button>
          <Button variant="secondary" type="button">WhatsApp Link Gönder</Button>
          <Button
            variant="destructive"
            type="button"
            onClick={() => !isActionDisabled && onComplete?.()}
            disabled={isActionDisabled}
            title={isActionDisabled ? disabledTitle : undefined}
          >
            Talebi Kapat
          </Button>
        </section>
      </CardContent>
    </Card>
  )
}
