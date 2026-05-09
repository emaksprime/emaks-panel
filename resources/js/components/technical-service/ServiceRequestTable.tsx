import { Badge } from '@/components/ui/badge'
import type { ServiceRequest } from './types'
import { formatTechnicalServiceDateTime, formatTechnicalServiceMrn } from './utils'

function normalizeStatus(status: ServiceRequest['status'] | string): string {
  switch (status) {
    case 'AtandÃ„Â±':
    case 'Atandı':
      return 'Atandı'
    case 'TamamlandÃ„Â±':
    case 'Tamamlandı':
      return 'Tamamlandı'
    case 'Ã„Â°ptal':
    case 'İptal':
      return 'İptal'
    default:
      return status
  }
}

function normalizePriority(priority: ServiceRequest['priority'] | string): string {
  switch (priority) {
    case 'YÃƒÂ¼ksek':
    case 'YÃ¼ksek':
      return 'Yüksek'
    case 'DÃƒÂ¼Ã…Å¸ÃƒÂ¼k':
    case 'DÃ¼ÅŸÃ¼k':
      return 'Düşük'
    default:
      return priority
  }
}

function normalizeTechnicianName(name: string | null | undefined): string {
  if (!name) {
    return ''
  }

  return name === 'AtanmadÃ„Â±' || name === 'AtanmadÄ±' ? 'Atanmadı' : name
}

const statusClassName = (status: string) => {
  switch (status) {
    case 'Yeni':
      return 'border-slate-200 bg-slate-100 text-slate-700'
    case 'Atandı':
      return 'border-blue-200 bg-blue-50 text-blue-700'
    case 'Randevulu':
      return 'border-sky-200 bg-sky-50 text-sky-700'
    case 'Devam Ediyor':
      return 'border-amber-200 bg-amber-50 text-amber-700'
    case 'Tamamlandı':
      return 'border-emerald-200 bg-emerald-50 text-emerald-700'
    case 'İptal':
      return 'border-rose-200 bg-rose-50 text-rose-700'
    default:
      return 'border-slate-200 bg-slate-100 text-slate-700'
  }
}

const priorityClassName = (priority: string) => {
  switch (priority) {
    case 'Kritik':
      return 'border-rose-200 bg-rose-50 text-rose-700'
    case 'Yüksek':
      return 'border-amber-200 bg-amber-50 text-amber-700'
    case 'Orta':
      return 'border-blue-200 bg-blue-50 text-blue-700'
    default:
      return 'border-slate-200 bg-slate-100 text-slate-600'
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
  const lastActionLabel = (request: ServiceRequest) =>
    formatTechnicalServiceDateTime(request.completedAt ?? request.scheduledAt ?? request.createdAt, '-')

  return (
    <section className="space-y-4">
      <div className="hidden overflow-hidden rounded-[28px] border border-white/80 bg-white shadow-[0_20px_45px_rgba(15,23,42,0.08)] ring-1 ring-slate-200/70 md:block">
        <div className="overflow-x-auto">
          <table className="w-full table-auto divide-y divide-slate-200 text-left text-sm">
            <thead className="bg-slate-950/[0.03] text-slate-500">
              <tr>
                <th className="px-5 py-4 text-[11px] font-semibold uppercase tracking-[0.16em]">Öncelik</th>
                <th className="px-5 py-4 text-[11px] font-semibold uppercase tracking-[0.16em]">Durum</th>
                <th className="px-5 py-4 text-[11px] font-semibold uppercase tracking-[0.16em]">Müşteri</th>
                <th className="px-5 py-4 text-[11px] font-semibold uppercase tracking-[0.16em]">Telefon</th>
                <th className="px-5 py-4 text-[11px] font-semibold uppercase tracking-[0.16em]">İl / İlçe</th>
                <th className="min-w-[220px] px-5 py-4 text-[11px] font-semibold uppercase tracking-[0.16em]">Ürün / Seri No</th>
                <th className="px-5 py-4 text-[11px] font-semibold uppercase tracking-[0.16em]">İş Tipi</th>
                <th className="px-5 py-4 text-[11px] font-semibold uppercase tracking-[0.16em]">Servis Personeli</th>
                <th className="px-5 py-4 text-[11px] font-semibold uppercase tracking-[0.16em]">Randevu Tarihi</th>
                <th className="px-5 py-4 text-[11px] font-semibold uppercase tracking-[0.16em]">Son İşlem</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 bg-white">
              {requests.map((request) => {
                const displayStatus = normalizeStatus(request.status)
                const displayPriority = normalizePriority(request.priority)
                const technicianName = normalizeTechnicianName(request.technician)
                const isInactive = displayStatus === 'Tamamlandı' || displayStatus === 'İptal'
                const isUnassigned = !technicianName.trim() || technicianName === 'Atanmadı'
                const active = request.id === selectedId
                const displayMrn = formatTechnicalServiceMrn(request)

                return (
                  <tr
                    key={request.id}
                    className={[
                      'cursor-pointer border-l-2 border-transparent transition-all duration-150',
                      active ? 'border-l-blue-600 bg-blue-50/80' : 'hover:bg-slate-50/80',
                      isInactive ? 'opacity-70' : '',
                    ].join(' ')}
                    onClick={() => onSelect(request)}
                  >
                    <td className="px-5 py-4 whitespace-nowrap">
                      <div className="space-y-2">
                        <Badge variant="outline" className={priorityClassName(displayPriority)}>
                          {displayPriority}
                        </Badge>
                        <p className="font-mono text-[0.7rem] font-semibold text-slate-500">{displayMrn}</p>
                      </div>
                    </td>
                    <td className="px-5 py-4 whitespace-nowrap">
                      <Badge variant="outline" className={statusClassName(displayStatus)}>
                        {displayStatus}
                      </Badge>
                    </td>
                    <td className="px-5 py-4 text-slate-900">
                      <div className="min-w-[180px]">
                        <p className="truncate font-medium">{request.customer}</p>
                        <p className="mt-1 truncate text-xs text-slate-500">{request.phone || '-'}</p>
                      </div>
                    </td>
                    <td className="px-5 py-4 whitespace-nowrap text-slate-700">{request.phone || '-'}</td>
                    <td className="px-5 py-4 text-slate-700">
                      <div className="min-w-[120px]">
                        <p>{request.city || '-'}</p>
                        <p className="mt-1 text-xs text-slate-500">{request.district || '-'}</p>
                      </div>
                    </td>
                    <td className="px-5 py-4 text-slate-700">
                      <div className="min-w-[220px]">
                        <p className="truncate">{request.product || '-'}</p>
                        <p className="mt-1 truncate text-xs text-slate-500">{request.serialNumber || request.model || '-'}</p>
                      </div>
                    </td>
                    <td className="px-5 py-4 whitespace-nowrap text-slate-700">{request.serviceType || '-'}</td>
                    <td className="px-5 py-4 whitespace-nowrap">
                      {isUnassigned ? (
                        <span className="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-[0.65rem] font-semibold uppercase tracking-[0.16em] text-slate-600">
                          Atanmadı
                        </span>
                      ) : (
                        <span className="block max-w-[140px] truncate text-slate-900">{technicianName}</span>
                      )}
                    </td>
                    <td className="px-5 py-4 whitespace-nowrap text-slate-700">{request.appointment}</td>
                    <td className="px-5 py-4 whitespace-nowrap text-slate-700">{lastActionLabel(request)}</td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>

        <div className="border-t border-slate-200 bg-slate-950/[0.03] px-5 py-3 text-xs text-slate-500">
          Satıra tıklayarak talep detayını açabilirsiniz.
        </div>
      </div>

      <div className="grid gap-4 md:hidden">
        {requests.map((request) => {
          const displayStatus = normalizeStatus(request.status)
          const displayPriority = normalizePriority(request.priority)
          const technicianName = normalizeTechnicianName(request.technician)
          const isInactive = displayStatus === 'Tamamlandı' || displayStatus === 'İptal'
          const isUnassigned = !technicianName.trim() || technicianName === 'Atanmadı'
          const active = request.id === selectedId
          const displayMrn = formatTechnicalServiceMrn(request)

          return (
            <button
              key={request.id}
              type="button"
              onClick={() => onSelect(request)}
              className={[
                'w-full rounded-[26px] border border-white/80 bg-white p-4 text-left shadow-[0_18px_40px_rgba(15,23,42,0.08)] ring-1 ring-slate-200/70 transition-all',
                active ? 'border-blue-200 bg-blue-50/50' : 'hover:border-slate-300',
                isInactive ? 'opacity-70' : '',
              ].join(' ')}
            >
              <div className="flex flex-col gap-4">
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                    <p className="text-[0.68rem] uppercase tracking-[0.16em] text-slate-500">Talep / Öncelik</p>
                    <p className="mt-2 break-words whitespace-normal text-sm font-semibold text-slate-900">{displayMrn}</p>
                  </div>
                  <div className="flex flex-wrap items-center gap-2">
                    <Badge variant="outline" className={priorityClassName(displayPriority)}>
                      {displayPriority}
                    </Badge>
                    <Badge variant="outline" className={statusClassName(displayStatus)}>
                      {displayStatus}
                    </Badge>
                  </div>
                </div>

                <div className="grid gap-2 text-sm text-slate-700">
                  <div className="flex items-center justify-between gap-2">
                    <span className="font-semibold">Ürün / Seri No</span>
                    <span className="truncate">{request.product || '-'} / {request.serialNumber || request.model || '-'}</span>
                  </div>
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
                    <span className="truncate">{request.phone || '-'}</span>
                  </div>
                  <div className="flex items-center justify-between gap-2">
                    <span className="font-semibold">Şehir</span>
                    <span className="truncate">{request.city} / {request.district}</span>
                  </div>
                  <div className="flex items-center justify-between gap-2">
                    <span className="font-semibold">Teknisyen</span>
                    {isUnassigned ? (
                      <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[0.65rem] font-semibold uppercase tracking-[0.16em] text-slate-600">
                        Atanmadı
                      </span>
                    ) : (
                      <span className="truncate text-slate-900">{technicianName}</span>
                    )}
                  </div>
                  <div className="flex items-center justify-between gap-2">
                    <span className="font-semibold">Randevu</span>
                    <span className="truncate">{request.appointment}</span>
                  </div>
                  <div className="flex items-center justify-between gap-2">
                    <span className="font-semibold">Son İşlem</span>
                    <span className="truncate">{lastActionLabel(request)}</span>
                  </div>
                  <div className="text-xs text-slate-500">Detayı görüntülemek için dokunun.</div>
                </div>
              </div>
            </button>
          )
        })}
      </div>
    </section>
  )
}

