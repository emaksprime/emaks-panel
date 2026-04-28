import { Badge } from '@/components/ui/badge'
import type { ServiceRequest } from './types'

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

export function ServiceRequestTable({
  requests,
  selectedId,
  onSelect,
}: {
  requests: ServiceRequest[]
  selectedId: string
  onSelect: (request: ServiceRequest) => void
}) {
  return (
    <section className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
      <div className="overflow-x-auto">
        <table className="min-w-full divide-y divide-slate-200 text-left text-sm">
          <thead className="bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-semibold">MRN</th>
              <th className="px-4 py-3 font-semibold">Durum</th>
              <th className="px-4 py-3 font-semibold">Öncelik</th>
              <th className="px-4 py-3 font-semibold">Servis Tipi</th>
              <th className="px-4 py-3 font-semibold">Müşteri</th>
              <th className="px-4 py-3 font-semibold">Telefon</th>
              <th className="px-4 py-3 font-semibold">İl / İlçe</th>
              <th className="px-4 py-3 font-semibold">Ürün</th>
              <th className="px-4 py-3 font-semibold">Seri No</th>
              <th className="px-4 py-3 font-semibold">Usta / Çilingir</th>
              <th className="px-4 py-3 font-semibold">Randevu</th>
              <th className="px-4 py-3 font-semibold">SLA</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100 bg-white">
            {requests.map((request) => {
              const active = request.id === selectedId
              return (
                <tr
                  key={request.id}
                  className={
                    'cursor-pointer transition hover:bg-slate-50 ' +
                    (active ? 'bg-slate-100' : '')
                  }
                  onClick={() => onSelect(request)}
                >
                  <td className="px-4 py-3 font-mono text-xs font-semibold text-slate-700">{request.mrn}</td>
                  <td className="px-4 py-3">
                    <Badge variant={statusVariant(request.status)}>{request.status}</Badge>
                  </td>
                  <td className="px-4 py-3">
                    <Badge variant={priorityVariant(request.priority)}>{request.priority}</Badge>
                  </td>
                  <td className="px-4 py-3 text-slate-700">{request.serviceType}</td>
                  <td className="px-4 py-3 text-slate-900">{request.customer}</td>
                  <td className="px-4 py-3 text-slate-700">{request.phone}</td>
                  <td className="px-4 py-3 text-slate-700">{request.city} / {request.district}</td>
                  <td className="px-4 py-3 text-slate-700">{request.product}</td>
                  <td className="px-4 py-3 text-slate-700">{request.serialNumber}</td>
                  <td className="px-4 py-3 text-slate-700">{request.technician}</td>
                  <td className="px-4 py-3 text-slate-700">{request.appointment}</td>
                  <td className="px-4 py-3">
                    <div className="flex flex-col gap-1 text-slate-700">
                      <span>{request.sla}</span>
                      <Badge variant={riskVariant(request.riskLevel)}>{request.riskLevel}</Badge>
                    </div>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>

      <div className="border-t border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-500">
        Seçili talep detayını sağ panelde görüntüleyin.
      </div>
    </section>
  )
}
