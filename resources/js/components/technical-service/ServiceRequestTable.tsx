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
        <table className="table-auto min-w-[1040px] divide-y divide-slate-200 text-left text-sm">
          <thead className="bg-slate-50 text-slate-500">
            <tr>
              <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-[0.08em]">MRN</th>
              <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-[0.08em]">Durum</th>
              <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-[0.08em]">Öncelik</th>
              <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-[0.08em]">Servis Tipi</th>
              <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-[0.08em]">Müşteri</th>
              <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-[0.08em]">Telefon</th>
              <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-[0.08em]">İl / İlçe</th>
              <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-[0.08em]">Ürün</th>
              <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-[0.08em]">Seri No</th>
              <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-[0.08em]">Usta / Çilingir</th>
              <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-[0.08em]">Randevu</th>
              <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-[0.08em]">SLA</th>
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
                  <td className="min-w-[100px] px-3 py-3 font-mono text-xs font-semibold text-slate-700 whitespace-nowrap">{request.mrn}</td>
                  <td className="min-w-[80px] px-3 py-3 whitespace-nowrap">
                    <Badge variant={statusVariant(request.status)}>{request.status}</Badge>
                  </td>
                  <td className="min-w-[80px] px-3 py-3 whitespace-nowrap">
                    <Badge variant={priorityVariant(request.priority)}>{request.priority}</Badge>
                  </td>
                  <td className="min-w-[100px] px-3 py-3 text-slate-700 whitespace-nowrap">{request.serviceType}</td>
                  <td className="min-w-[170px] px-3 py-3 text-slate-900 whitespace-nowrap truncate max-w-[170px]">{request.customer}</td>
                  <td className="min-w-[120px] px-3 py-3 text-slate-700 whitespace-nowrap">{request.phone}</td>
                  <td className="min-w-[120px] px-3 py-3 text-slate-700 whitespace-nowrap">{request.city} / {request.district}</td>
                  <td className="min-w-[140px] px-3 py-3 text-slate-700 whitespace-nowrap truncate max-w-[140px]">{request.product}</td>
                  <td className="min-w-[110px] px-3 py-3 text-slate-700 whitespace-nowrap">{request.serialNumber}</td>
                  <td className="min-w-[130px] px-3 py-3 text-slate-700 whitespace-nowrap">{request.technician}</td>
                  <td className="min-w-[140px] px-3 py-3 text-slate-700 whitespace-nowrap">{request.appointment}</td>
                  <td className="min-w-[120px] px-3 py-3">
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
