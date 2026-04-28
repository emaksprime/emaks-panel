import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import type { ServiceRequest } from './types'

const timelineItems = [
  { step: 'Talep alındı', time: '09:15', note: 'Teknik servis ekibi talebi aldı.' },
  { step: 'Usta atandı', time: '10:00', note: 'Talep için görev atandı.' },
  { step: 'Randevu planlandı', time: '10:20', note: 'Müşteriye randevu bildirimi gönderildi.' },
]

export function ServiceRequestDetails({ request }: { request: ServiceRequest }) {
  return (
    <Card className="rounded-3xl border-slate-200 bg-white shadow-sm">
      <CardHeader className="space-y-3 px-6 py-6">
        <div>
          <p className="text-xs font-semibold uppercase tracking-[0.15em] text-slate-500">Seçili MRN</p>
          <CardTitle className="mt-2 text-xl text-slate-950">{request.mrn}</CardTitle>
        </div>
        <p className="text-sm text-slate-600">{request.customer} için teknik servis ve montaj bilgisi.</p>
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
          <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Cihaz Özeti</p>
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
            <div className="flex justify-between">
              <span className="font-semibold">Kanal</span>
              <span>{request.channel}</span>
            </div>
            <div className="flex justify-between">
              <span className="font-semibold">Servis Tipi</span>
              <span>{request.serviceType}</span>
            </div>
          </div>
        </section>

        <section className="grid gap-3 rounded-2xl border border-slate-200 p-4">
          <div className="flex items-center justify-between gap-4">
            <div>
              <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Usta / Çilingir</p>
              <p className="mt-2 text-sm font-semibold text-slate-900">{request.technician}</p>
            </div>
            <div className="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-700">
              {request.status}
            </div>
          </div>
          <div className="grid gap-2 sm:grid-cols-2">
            <div className="rounded-2xl border border-slate-200 bg-white p-3">
              <p className="text-xs uppercase tracking-[0.14em] text-slate-500">Randevu</p>
              <p className="mt-2 text-sm font-semibold text-slate-900">{request.appointment}</p>
            </div>
            <div className="rounded-2xl border border-slate-200 bg-white p-3">
              <p className="text-xs uppercase tracking-[0.14em] text-slate-500">SLA</p>
              <p className="mt-2 text-sm font-semibold text-slate-900">{request.sla}</p>
              <p className="mt-1 text-xs text-slate-500">Risk: {request.riskLevel}</p>
            </div>
          </div>
        </section>

        <section className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
          <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Durum Timeline</p>
          <div className="mt-4 space-y-4">
            {timelineItems.map((item) => (
              <div key={item.step} className="flex gap-3 text-sm">
                <div className="mt-1 h-2.5 w-2.5 rounded-full bg-slate-400" />
                <div>
                  <p className="font-semibold text-slate-900">{item.step}</p>
                  <p className="text-xs text-slate-500">{item.time} · {item.note}</p>
                </div>
              </div>
            ))}
          </div>
        </section>
      </CardContent>
    </Card>
  )
}
