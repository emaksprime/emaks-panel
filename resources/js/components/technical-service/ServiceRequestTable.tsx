import { Badge } from '@/components/ui/badge'
import { formatTechnicalServiceMrn } from './utils'
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
    <section className="space-y-4">
      <div className="hidden md:block overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
        <div className="overflow-x-auto">
          <table className="table-auto w-full divide-y divide-slate-200 text-left text-sm">
            <thead className="bg-slate-50 text-slate-500">
                <tr>
                <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-[0.08em] min-w-[170px]">MRN</th>
                <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-[0.08em]">Durum</th>
                <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-[0.08em]">Öncelik</th>
                <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-[0.08em]">Servis Tipi</th>
                <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-[0.08em]">Müşteri</th>
                <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-[0.08em]">Telefon</th>
                <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-[0.08em]">İl / İlçe</th>
                <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-[0.08em]">Usta</th>
                <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-[0.08em]">Randevu</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 bg-white">
              {requests.map((request) => {
                const active = request.id === selectedId
                const isInactive = request.status === 'Tamamlandı' || request.status === 'İptal'
                const technicianName = request.technician?.trim() && request.technician !== 'Atanmadı' ? request.technician : ''
                const displayMrn = formatTechnicalServiceMrn(request)

                return (
                  <tr
                    key={request.id}
                    className={
                      'cursor-pointer transition hover:bg-slate-50 ' +
                      (active ? 'bg-slate-100 ' : '') +
                      (isInactive ? 'opacity-70' : '')
                    }
                    onClick={() => onSelect(request)}
                  >
                    <td className="px-3 py-3 font-mono text-xs font-semibold text-slate-700 min-w-[170px] whitespace-nowrap">{displayMrn}</td>
                    <td className="px-3 py-3 whitespace-nowrap">
                      <Badge variant={statusVariant(request.status)}>{request.status}</Badge>
                    </td>
                    <td className="px-3 py-3 whitespace-nowrap">
                      <Badge variant={priorityVariant(request.priority)}>{request.priority}</Badge>
                    </td>
                    <td className="px-3 py-3 text-slate-700 whitespace-nowrap truncate max-w-[110px]">{request.serviceType || '-'}</td>
                    <td className="px-3 py-3 text-slate-900 whitespace-nowrap truncate max-w-[180px]">{request.customer}</td>
                    <td className="px-3 py-3 text-slate-700 whitespace-nowrap truncate max-w-[130px]">{request.phone}</td>
                    <td className="px-3 py-3 text-slate-700 whitespace-nowrap truncate max-w-[150px]">{request.city} / {request.district}</td>
                    <td className="px-3 py-3 whitespace-nowrap">
                      {technicianName ? (
                        <span className="truncate max-w-[140px] block text-slate-900">{technicianName}</span>
                      ) : (
                        <span className="inline-flex rounded-full bg-amber-50 px-2 py-1 text-[0.65rem] font-semibold uppercase tracking-[0.16em] text-amber-700">
                          Atanmadı
                        </span>
                      )}
                    </td>
                    <td className="px-3 py-3 text-slate-700 whitespace-nowrap">{request.appointment}</td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>

        <div className="border-t border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-500">
          Satıra tıklayarak pop-up içinde talep detayını görüntüleyin.
        </div>
      </div>

      <div className="grid gap-4 md:hidden">
        {requests.map((request) => {
          const isInactive = request.status === 'Tamamlandı' || request.status === 'İptal'
          const technicianName = request.technician?.trim() && request.technician !== 'Atanmadı' ? request.technician : ''
          const displayMrn = formatTechnicalServiceMrn(request)

          return (
            <button
              key={request.id}
              type="button"
              onClick={() => onSelect(request)}
              className={
                'w-full rounded-2xl border border-slate-200 bg-white p-4 text-left shadow-sm transition hover:border-slate-300 ' +
                (isInactive ? 'opacity-70 ' : '')
              }
            >
              <div className="flex flex-col gap-4">
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="text-[0.68rem] uppercase tracking-[0.16em] text-slate-500">MRN</p>
                    <p className="mt-2 text-sm font-semibold text-slate-900 whitespace-normal break-words">{displayMrn}</p>
                  </div>
                  <div className="flex flex-wrap items-center gap-2">
                    <Badge variant={statusVariant(request.status)}>{request.status}</Badge>
                    <Badge variant={priorityVariant(request.priority)}>{request.priority}</Badge>
                  </div>
                </div>

                <div className="grid gap-2 text-sm text-slate-700">
                  <div className="flex items-center justify-between gap-2">
                    <span className="font-semibold">Servis Tipi</span>
                    <span className="truncate">{request.serviceType || '-'}</span>
                  </div>
                  <div className="flex items-center justify-between gap-2">
                    <span className="font-semibold">Müşteri</span>
                    <span className="truncate">{request.customer}</span>
                  </div>
                  <div className="flex items-center justify-between gap-2">
                    <span className="font-semibold">Telefon</span>
                    <span className="truncate">{request.phone}</span>
                  </div>
                  <div className="flex items-center justify-between gap-2">
                    <span className="font-semibold">İl / İlçe</span>
                    <span className="truncate">{request.city} / {request.district}</span>
                  </div>
                  <div className="flex items-center justify-between gap-2">
                    <span className="font-semibold">Usta</span>
                    {technicianName ? (
                      <span className="truncate text-slate-900">{technicianName}</span>
                    ) : (
                      <span className="rounded-full bg-amber-50 px-2 py-0.5 text-[0.65rem] font-semibold uppercase tracking-[0.16em] text-amber-700">
                        Atanmadı
                      </span>
                    )}
                  </div>
                  <div className="flex items-center justify-between gap-2">
                    <span className="font-semibold">Randevu</span>
                    <span className="truncate">{request.appointment}</span>
                  </div>
                  <div className="text-xs text-slate-500">Detayı görüntülemek için tıklayın</div>
                </div>
              </div>
            </button>
          )
        })}
      </div>
    </section>
  )
}
