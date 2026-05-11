import {
  AlertTriangle,
  CalendarClock,
  CheckCircle2,
  CircleDot,
  Clock3,
  FileClock,
  Filter,
  MapPin,
  Package2,
  Phone,
  ShieldAlert,
  UserRound,
  Wrench,
} from 'lucide-react'
import { Input } from '@/components/ui/input'
import type { ServiceRequest } from './types'

export type OperationQuickFilterKey =
  | 'all_open'
  | 'unassigned'
  | 'appointment_pending'
  | 'overdue'
  | 'in_service'
  | 'completed'

export type OperationQuickFilterItem = {
  key: OperationQuickFilterKey
  label: string
  count: number
}

export type WeeklyScheduleDay = {
  key: string
  label: string
  shortDate: string
  fullDate: string
  count: number
  densityLabel: 'Yok' | 'Dusuk' | 'Normal' | 'Orta' | 'Yogun'
  isToday: boolean
  isSelected: boolean
}

export type OperationMetric = {
  label: string
  value: number
  tone: 'blue' | 'green' | 'red' | 'purple'
}

export type TechnicianLoadItem = {
  name: string
  count: number
}

export type WorkflowFilterKey =
  | 'customer_call'
  | 'customer_unreachable'
  | 'customer_callback'
  | 'schedule_planning'
  | 'technician_approval'
  | 'unassigned'
  | 'customer_confirmation'
  | 'sla_overdue'
  | 'travel_pending'
  | 'on_site_active'
  | 'checklist_missing'
  | 'photo_missing'
  | 'closure_pending_field'
  | 'incomplete'
  | 'parts_pending'
  | 'second_visit'

export type WorkflowQueueItem = {
  key: WorkflowFilterKey
  label: string
  description?: string
  count: number
}

type OperationCenterDashboardProps = {
  quickFilters: OperationQuickFilterItem[]
  activeQuickFilter: OperationQuickFilterKey
  onQuickFilterChange: (key: OperationQuickFilterKey) => void
  weekDays: WeeklyScheduleDay[]
  onSelectDay: (key: string) => void
  tableTitle: string
  tableSubtitle: string
  tableSearch: string
  onTableSearchChange: (value: string) => void
  appointments: ServiceRequest[]
  emptyMessage?: string
  selectedRequestId: string
  onSelectRequest: (request: ServiceRequest) => void
  summaryMetrics: OperationMetric[]
  summaryDescription: string
  workflowQueues: WorkflowQueueItem[]
  activeWorkflowFilter: WorkflowFilterKey | null
  onWorkflowFilterChange: (key: WorkflowFilterKey | null) => void
  technicianSummary: TechnicianLoadItem[]
  weeklyLegend: Array<WeeklyScheduleDay['densityLabel']>
  loading: boolean
  error: string | null
}

const densityStyles: Record<WeeklyScheduleDay['densityLabel'], { bar: string, pill: string }> = {
  Yogun: { bar: 'bg-rose-500', pill: 'bg-rose-50 text-rose-700' },
  Orta: { bar: 'bg-orange-400', pill: 'bg-orange-50 text-orange-700' },
  Normal: { bar: 'bg-emerald-500', pill: 'bg-emerald-50 text-emerald-700' },
  Dusuk: { bar: 'bg-lime-400', pill: 'bg-lime-50 text-lime-700' },
  Yok: { bar: 'bg-slate-300', pill: 'bg-slate-100 text-slate-600' },
}

const metricStyles: Record<OperationMetric['tone'], string> = {
  blue: 'bg-blue-50 text-blue-700',
  green: 'bg-emerald-50 text-emerald-700',
  red: 'bg-rose-50 text-rose-700',
  purple: 'bg-violet-50 text-violet-700',
}

const getQuickFilterIcon = (key: OperationQuickFilterKey) => {
  switch (key) {
    case 'all_open':
      return CircleDot
    case 'unassigned':
      return UserRound
    case 'appointment_pending':
      return CalendarClock
    case 'overdue':
      return AlertTriangle
    case 'in_service':
      return Wrench
    case 'completed':
      return CheckCircle2
    default:
      return CircleDot
  }
}

const getMetricIcon = (tone: OperationMetric['tone']) => {
  switch (tone) {
    case 'blue':
      return CalendarClock
    case 'green':
      return CheckCircle2
    case 'red':
      return AlertTriangle
    case 'purple':
      return UserRound
    default:
      return CircleDot
  }
}

const getWorkflowIcon = (key: WorkflowFilterKey) => {
  switch (key) {
    case 'customer_call':
      return Phone
    case 'customer_unreachable':
      return AlertTriangle
    case 'customer_callback':
      return Clock3
    case 'schedule_planning':
      return CalendarClock
    case 'technician_approval':
      return CheckCircle2
    case 'unassigned':
      return UserRound
    case 'customer_confirmation':
      return Phone
    case 'sla_overdue':
      return AlertTriangle
    case 'travel_pending':
      return CalendarClock
    case 'on_site_active':
      return Wrench
    case 'checklist_missing':
      return ShieldAlert
    case 'photo_missing':
      return FileClock
    case 'closure_pending_field':
      return CheckCircle2
    case 'incomplete':
      return AlertTriangle
    case 'parts_pending':
      return Package2
    case 'second_visit':
      return CalendarClock
    default:
      return ShieldAlert
  }
}

const getStatusClassName = (status: string) => {
  switch (status) {
    case 'Atandi':
    case 'Atandı':
    case 'Randevulu':
    case 'Montaj Yeri Açılacak':
      return 'border-blue-200 bg-blue-50 text-blue-700'
    case 'Servis':
    case 'Devam Ediyor':
    case 'Tamamlandi':
    case 'Tamamlandı':
      return 'border-emerald-200 bg-emerald-50 text-emerald-700'
    case 'Geciken':
      return 'border-rose-200 bg-rose-50 text-rose-700'
    case 'Iptal':
    case 'İptal':
      return 'border-slate-200 bg-slate-100 text-slate-600'
    default:
      return 'border-slate-200 bg-slate-100 text-slate-700'
  }
}

const formatAppointmentTime = (request: ServiceRequest): string => {
  if (request.scheduledTime?.trim()) {
    return request.scheduledTime.trim()
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

const productLabel = (request: ServiceRequest): string => {
  const pieces = [request.product, request.model].filter((part) => String(part ?? '').trim() !== '')
  return pieces.length > 0 ? pieces.join(' / ') : '-'
}

function QuickFilterSidebar({
  quickFilters,
  activeQuickFilter,
  onQuickFilterChange,
}: Pick<OperationCenterDashboardProps, 'quickFilters' | 'activeQuickFilter' | 'onQuickFilterChange'>) {
  return (
    <section className="rounded-[22px] border border-slate-200 bg-white p-4 shadow-sm">
      <div className="mb-4">
        <h2 className="text-sm font-semibold text-slate-900">İş Filtreleri</h2>
      </div>
      <div className="flex gap-2 overflow-x-auto pb-1 xl:grid xl:gap-2 xl:overflow-visible">
        {quickFilters.map((item) => {
          const Icon = getQuickFilterIcon(item.key)
          const active = item.key === activeQuickFilter

          return (
            <button
              key={item.key}
              type="button"
              onClick={() => onQuickFilterChange(item.key)}
              className={[
                'flex min-w-[172px] items-start gap-3 rounded-[18px] border px-3 py-3 text-left transition xl:min-w-0',
                active
                  ? 'border-[#06143A] bg-[#06143A] text-white shadow-[0_12px_22px_rgba(6,20,58,0.18)]'
                  : 'border-slate-200 bg-slate-50 text-slate-700 hover:border-slate-300 hover:bg-white',
              ].join(' ')}
            >
              <span className={['inline-flex h-9 w-9 items-center justify-center rounded-2xl', active ? 'bg-white/12' : 'bg-white text-slate-600'].join(' ')}>
                <Icon className="h-4 w-4" />
              </span>
              <span className="flex-1 text-sm font-medium leading-5 break-words text-inherit">{item.label}</span>
              <span className={['mt-0.5 shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold', active ? 'bg-white/12 text-white' : 'bg-white text-slate-600'].join(' ')}>
                {item.count}
              </span>
            </button>
          )
        })}
      </div>
    </section>
  )
}

function WeeklyScheduleStrip({
  weekDays,
  weeklyLegend,
  onSelectDay,
}: Pick<OperationCenterDashboardProps, 'weekDays' | 'weeklyLegend' | 'onSelectDay'>) {
  const maxCount = weekDays.reduce((max, day) => Math.max(max, day.count), 0)

  return (
    <section className="rounded-[22px] border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
      <div className="mb-4">
        <h2 className="text-lg font-semibold text-slate-950">Haftalık Görünüm</h2>
      </div>

      <div className="grid gap-3 overflow-x-auto pb-2 md:grid-cols-7">
        {weekDays.map((day) => {
          const density = densityStyles[day.densityLabel]
          const width = maxCount === 0 ? 8 : Math.max(12, Math.round((day.count / maxCount) * 100))

          return (
            <button
              key={day.key}
              type="button"
              onClick={() => onSelectDay(day.key)}
              className={[
                'min-h-[188px] min-w-[138px] rounded-[20px] border px-4 py-4 text-left transition md:min-w-0',
                day.isSelected
                  ? 'border-[#06143A] bg-[#06143A] text-white shadow-[0_16px_28px_rgba(6,20,58,0.18)]'
                  : day.isToday
                    ? 'border-blue-200 bg-slate-50 text-slate-900'
                    : 'border-slate-200 bg-slate-50 text-slate-900 hover:border-slate-300 hover:bg-white',
              ].join(' ')}
            >
              <div className="flex h-full flex-col">
                <div className="min-w-0">
                  <p className={['text-sm font-semibold', day.isSelected ? 'text-white' : 'text-slate-800'].join(' ')}>{day.label}</p>
                  <p className={['mt-1 text-xs', day.isSelected ? 'text-slate-200' : 'text-slate-500'].join(' ')}>{day.shortDate}</p>
                </div>
              <div className="mt-2 flex h-6 items-center justify-center">
                <span
                  className={[
                    'mx-auto inline-flex items-center justify-center rounded-full px-2 py-0.5 text-[11px] font-semibold',
                    day.isToday
                      ? day.isSelected
                        ? 'border border-white/20 bg-white/15 text-white'
                        : 'border border-blue-100 bg-blue-50 text-blue-700'
                      : 'invisible',
                  ].join(' ')}
                  aria-hidden={!day.isToday}
                >
                  Bugün
                  </span>
              </div>
              <div className="mt-4">
                <p className={['text-3xl font-semibold tracking-[-0.03em]', day.isSelected ? 'text-white' : 'text-slate-950'].join(' ')}>{day.count}</p>
                <p className={['mt-1 text-xs font-medium', day.isSelected ? 'text-slate-200' : 'text-slate-500'].join(' ')}>Randevu</p>
              </div>
              <div className="mt-auto w-full pt-4">
                <div className={['h-2 rounded-full', day.isSelected ? 'bg-white/12' : 'bg-slate-200'].join(' ')}>
                  <div className={['h-2 rounded-full', day.isSelected ? 'bg-white' : density.bar].join(' ')} style={{ width: `${width}%` }} />
                </div>
              </div>
              </div>
            </button>
          )
        })}
      </div>

      <div className="mt-4 flex flex-wrap items-center gap-2">
        {weeklyLegend.map((legend) => (
          <span key={legend} className={['inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-medium', densityStyles[legend].pill].join(' ')}>
            <span className={['h-1.5 w-1.5 rounded-full', densityStyles[legend].bar].join(' ')} />
            {legend === 'Dusuk' ? 'Düşük' : legend === 'Yogun' ? 'Yoğun' : legend}
          </span>
        ))}
      </div>
    </section>
  )
}

function SelectedDayAppointmentsTable({
  tableTitle,
  tableSubtitle,
  tableSearch,
  onTableSearchChange,
  appointments,
  emptyMessage,
  selectedRequestId,
  onSelectRequest,
  loading,
  error,
}: Pick<
  OperationCenterDashboardProps,
  'tableTitle'
  | 'tableSubtitle'
  | 'tableSearch'
  | 'onTableSearchChange'
  | 'appointments'
  | 'emptyMessage'
  | 'selectedRequestId'
  | 'onSelectRequest'
  | 'loading'
  | 'error'
>) {
  return (
    <section className="rounded-[22px] border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
      <div className="flex flex-col gap-4 border-b border-slate-200 pb-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <div className="flex flex-wrap items-center gap-2">
            <h2 className="text-lg font-semibold text-slate-950">{tableTitle}</h2>
            <span className="rounded-full bg-[#06143A] px-2.5 py-1 text-xs font-semibold text-white">{appointments.length}</span>
          </div>
          <p className="mt-1 text-sm text-slate-500">{tableSubtitle}</p>
        </div>
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
          <div className="relative min-w-0 flex-1 sm:w-[320px]">
            <Input
              value={tableSearch}
              onChange={(event) => onTableSearchChange(event.target.value)}
              placeholder="Randevu, müşteri veya telefon ara..."
              className="h-11 rounded-2xl border-slate-200 bg-slate-50 pr-4 pl-10 shadow-none focus-visible:border-blue-300 focus-visible:ring-4 focus-visible:ring-blue-100"
            />
            <Filter className="pointer-events-none absolute top-1/2 left-3.5 h-4 w-4 -translate-y-1/2 text-slate-400" />
          </div>
        </div>
      </div>

      {error ? (
        <div className="mt-4 rounded-[20px] border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">{error}</div>
      ) : loading ? (
        <div className="mt-4 rounded-[20px] border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">Randevular yükleniyor...</div>
      ) : appointments.length === 0 ? (
        <div className="mt-4 rounded-[20px] border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">{emptyMessage ?? 'Seçili gün için randevu bulunamadı.'}</div>
      ) : (
        <>
          <div className="mt-4 hidden overflow-hidden rounded-[20px] border border-slate-200 lg:block">
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-slate-200 text-left text-sm">
                <thead className="bg-slate-50 text-slate-500">
                  <tr>
                    <th className="px-4 py-3 font-medium">Saat</th>
                    <th className="px-4 py-3 font-medium">Müşteri</th>
                    <th className="px-4 py-3 font-medium">Telefon</th>
                    <th className="px-4 py-3 font-medium">Şehir</th>
                    <th className="px-4 py-3 font-medium">Ürün</th>
                    <th className="px-4 py-3 font-medium">Teknisyen</th>
                    <th className="px-4 py-3 font-medium">Durum</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 bg-white">
                  {appointments.map((request) => {
                    const active = selectedRequestId === request.id
                    const technicianName = request.technician?.trim() ? request.technician : 'Atanmadı'

                    return (
                      <tr
                        key={request.id}
                        onClick={() => onSelectRequest(request)}
                        className={['cursor-pointer transition', active ? 'bg-blue-50/80' : 'hover:bg-slate-50'].join(' ')}
                      >
                        <td className="px-4 py-4 align-top">
                          <div className="flex items-center gap-2 font-medium text-slate-900">
                            <Clock3 className="h-4 w-4 text-slate-400" />
                            {formatAppointmentTime(request)}
                          </div>
                        </td>
                        <td className="px-4 py-4 align-top font-medium text-slate-900">{request.customer || '-'}</td>
                        <td className="px-4 py-4 align-top text-slate-600">{request.phone || '-'}</td>
                        <td className="px-4 py-4 align-top text-slate-600">{request.city || '-'}</td>
                        <td className="px-4 py-4 align-top text-slate-600">{productLabel(request)}</td>
                        <td className="px-4 py-4 align-top text-slate-600">{technicianName}</td>
                        <td className="px-4 py-4 align-top">
                          <span className={['inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold', getStatusClassName(request.status)].join(' ')}>
                            {request.status}
                          </span>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          </div>

          <div className="mt-4 grid gap-3 lg:hidden">
            {appointments.map((request) => {
              const active = selectedRequestId === request.id
              const technicianName = request.technician?.trim() ? request.technician : 'Atanmadı'

              return (
                <button
                  key={request.id}
                  type="button"
                  onClick={() => onSelectRequest(request)}
                  className={[
                    'rounded-[20px] border p-4 text-left transition',
                    active ? 'border-blue-200 bg-blue-50/70' : 'border-slate-200 bg-slate-50 hover:border-slate-300 hover:bg-white',
                  ].join(' ')}
                >
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <p className="text-sm font-semibold text-slate-950">{request.customer || '-'}</p>
                      <p className="mt-1 text-xs text-slate-500">{formatAppointmentTime(request)}</p>
                    </div>
                    <span className={['inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold', getStatusClassName(request.status)].join(' ')}>
                      {request.status}
                    </span>
                  </div>
                  <div className="mt-4 grid gap-2 text-sm text-slate-600">
                    <div className="flex items-center gap-2">
                      <Phone className="h-4 w-4 text-slate-400" />
                      <span>{request.phone || '-'}</span>
                    </div>
                    <div className="flex items-center gap-2">
                      <MapPin className="h-4 w-4 text-slate-400" />
                      <span>{request.city || '-'}</span>
                    </div>
                    <div className="flex items-center gap-2">
                      <Package2 className="h-4 w-4 text-slate-400" />
                      <span>{productLabel(request)}</span>
                    </div>
                    <div className="flex items-center gap-2">
                      <Wrench className="h-4 w-4 text-slate-400" />
                      <span>{technicianName}</span>
                    </div>
                  </div>
                </button>
              )
            })}
          </div>
        </>
      )}
    </section>
  )
}

function SelectedDaySummaryPanel({
  summaryMetrics,
  summaryDescription,
}: Pick<OperationCenterDashboardProps, 'summaryMetrics' | 'summaryDescription'>) {
  return (
    <section className="rounded-[22px] border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
      <div className="mb-4">
        <h2 className="text-lg font-semibold text-slate-950">Seçili Gün Özeti</h2>
        <p className="mt-1 text-sm text-slate-500">{summaryDescription}</p>
      </div>
      <div className="grid grid-cols-2 gap-2.5">
        {summaryMetrics.map((metric) => {
          const Icon = getMetricIcon(metric.tone)

          return (
            <div key={metric.label} className="rounded-[18px] border border-slate-200 bg-slate-50 p-3">
              <div className="flex items-start justify-between gap-2">
                <div>
                  <p className="text-xs leading-4 font-medium text-slate-500">{metric.label}</p>
                  <p className="mt-1.5 text-2xl font-semibold tracking-[-0.03em] text-slate-950">{metric.value}</p>
                </div>
                <span className={['mt-auto inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-2xl', metricStyles[metric.tone]].join(' ')}>
                  <Icon className="h-4 w-4" />
                </span>
              </div>
            </div>
          )
        })}
      </div>
    </section>
  )
}

function WorkflowQueuePanel({
  workflowQueues,
  activeWorkflowFilter,
  onWorkflowFilterChange,
}: Pick<OperationCenterDashboardProps, 'workflowQueues' | 'activeWorkflowFilter' | 'onWorkflowFilterChange'>) {
  return (
    <section className="rounded-[22px] border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
      <div className="mb-4">
        <h2 className="text-lg font-semibold text-slate-950">Operasyon İş Akışı</h2>
        <p className="mt-1 text-sm text-slate-500">Aksiyon bekleyen servis kayıtları</p>
      </div>

      <div className="space-y-2.5">
        {workflowQueues.map((item) => {
          const Icon = getWorkflowIcon(item.key)
          const active = activeWorkflowFilter === item.key

          return (
            <button
              key={item.key}
              type="button"
              onClick={() => onWorkflowFilterChange(active ? null : item.key)}
              className={[
                'flex w-full items-start gap-3 rounded-[18px] border px-3 py-3 text-left transition',
                active
                  ? 'border-[#06143A] bg-[#06143A] text-white shadow-[0_12px_22px_rgba(6,20,58,0.18)]'
                  : 'border-slate-200 bg-slate-50 text-slate-800 hover:border-slate-300 hover:bg-white',
              ].join(' ')}
            >
              <span className={['inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-2xl', active ? 'bg-white/12' : 'bg-white text-slate-600'].join(' ')}>
                <Icon className="h-4 w-4" />
              </span>
              <span className="flex-1">
                <span className="block text-sm leading-5 font-medium break-words text-inherit">{item.label}</span>
                {item.description ? (
                  <span className={['mt-1 block text-xs leading-5', active ? 'text-slate-200' : 'text-slate-500'].join(' ')}>
                    {item.description}
                  </span>
                ) : null}
              </span>
              <span className={['mt-0.5 shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold', active ? 'bg-white/12 text-white' : 'bg-white text-slate-700'].join(' ')}>
                {item.count}
              </span>
            </button>
          )
        })}
      </div>
    </section>
  )
}

function TechnicianDensityPanel({ technicianSummary }: Pick<OperationCenterDashboardProps, 'technicianSummary'>) {
  const maxCount = technicianSummary.reduce((max, item) => Math.max(max, item.count), 0)

  return (
    <section className="rounded-[22px] border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
      <div className="mb-4">
        <h2 className="text-lg font-semibold text-slate-950">Teknisyen Yoğunluğu</h2>
      </div>

      {technicianSummary.length === 0 ? (
        <div className="rounded-[18px] border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">
          Seçili gün için teknisyen yoğunluğu yok.
        </div>
      ) : (
        <div className="space-y-3">
          {technicianSummary.map((item) => {
            const width = maxCount === 0 ? 0 : Math.max(10, Math.round((item.count / maxCount) * 100))

            return (
              <div key={item.name} className="rounded-[18px] border border-slate-200 bg-slate-50 p-3">
                <div className="mb-2 flex items-start justify-between gap-3">
                  <p className="flex-1 text-sm leading-5 font-medium break-words text-slate-900">{item.name}</p>
                  <span className="text-sm font-semibold text-slate-900">{item.count} iş</span>
                </div>
                <div className="h-2 rounded-full bg-white">
                  <div className="h-2 rounded-full bg-[#06143A]" style={{ width: `${width}%` }} />
                </div>
              </div>
            )
          })}
        </div>
      )}
    </section>
  )
}

function WeeklyDensityPanel({
  weekDays,
  weeklyLegend,
  onSelectDay,
}: Pick<OperationCenterDashboardProps, 'weekDays' | 'weeklyLegend' | 'onSelectDay'>) {
  const maxCount = weekDays.reduce((max, day) => Math.max(max, day.count), 0)

  return (
    <section className="rounded-[22px] border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
      <div className="mb-4">
        <h2 className="text-lg font-semibold text-slate-950">Haftalık Yoğunluk</h2>
      </div>
      <div className="space-y-3">
        {weekDays.map((day) => {
          const density = densityStyles[day.densityLabel]
          const width = maxCount === 0 ? 8 : Math.max(12, Math.round((day.count / maxCount) * 100))

          return (
            <button
              key={day.key}
              type="button"
              onClick={() => onSelectDay(day.key)}
              className={[
                'w-full rounded-[18px] border px-3 py-3 text-left transition',
                day.isSelected
                  ? 'border-[#06143A] bg-[#06143A] text-white shadow-[0_12px_22px_rgba(6,20,58,0.18)]'
                  : day.isToday
                    ? 'border-blue-200 bg-blue-50/70'
                    : 'border-slate-200 bg-slate-50 hover:border-slate-300 hover:bg-white',
              ].join(' ')}
            >
              <div className="mb-2 flex items-center justify-between gap-3">
                <div>
                  <p className={['text-sm font-medium', day.isSelected ? 'text-white' : 'text-slate-900'].join(' ')}>{day.label}</p>
                  <p className={['text-xs', day.isSelected ? 'text-slate-200' : 'text-slate-500'].join(' ')}>{day.shortDate}</p>
                </div>
                <span className={['text-sm font-semibold', day.isSelected ? 'text-white' : 'text-slate-900'].join(' ')}>{day.count}</span>
              </div>
              <div className={['h-2 rounded-full', day.isSelected ? 'bg-white/12' : 'bg-white'].join(' ')}>
                <div className={['h-2 rounded-full', day.isSelected ? 'bg-white' : density.bar].join(' ')} style={{ width: `${width}%` }} />
              </div>
            </button>
          )
        })}
      </div>
      <div className="mt-4 flex flex-wrap gap-2">
        {weeklyLegend.map((legend) => (
          <span key={legend} className={['rounded-full px-2.5 py-1 text-[11px] font-medium', densityStyles[legend].pill].join(' ')}>
            {legend === 'Dusuk' ? 'Düşük' : legend === 'Yogun' ? 'Yoğun' : legend}
          </span>
        ))}
      </div>
    </section>
  )
}

export function TechnicalServiceOperationsDashboard(props: OperationCenterDashboardProps) {
  const {
    quickFilters,
    activeQuickFilter,
    onQuickFilterChange,
    weekDays,
    onSelectDay,
    tableTitle,
    tableSubtitle,
    tableSearch,
    onTableSearchChange,
    appointments,
    emptyMessage,
    selectedRequestId,
    onSelectRequest,
    summaryMetrics,
    summaryDescription,
    workflowQueues,
    activeWorkflowFilter,
    onWorkflowFilterChange,
    technicianSummary,
    weeklyLegend,
    loading,
    error,
  } = props

  return (
    <div className="grid gap-5 xl:grid-cols-[240px_minmax(0,1fr)_300px]">
      <aside className="order-1 xl:sticky xl:top-6 xl:self-start">
        <QuickFilterSidebar quickFilters={quickFilters} activeQuickFilter={activeQuickFilter} onQuickFilterChange={onQuickFilterChange} />
      </aside>

      <div className="order-2 space-y-5">
        <WeeklyScheduleStrip weekDays={weekDays} weeklyLegend={weeklyLegend} onSelectDay={onSelectDay} />
        <SelectedDayAppointmentsTable
          tableTitle={tableTitle}
          tableSubtitle={tableSubtitle}
          tableSearch={tableSearch}
          onTableSearchChange={onTableSearchChange}
          appointments={appointments}
          emptyMessage={emptyMessage}
          selectedRequestId={selectedRequestId}
          onSelectRequest={onSelectRequest}
          loading={loading}
          error={error}
        />
      </div>

      <aside className="order-3 space-y-5">
        <SelectedDaySummaryPanel summaryMetrics={summaryMetrics} summaryDescription={summaryDescription} />
        <WorkflowQueuePanel
          workflowQueues={workflowQueues}
          activeWorkflowFilter={activeWorkflowFilter}
          onWorkflowFilterChange={onWorkflowFilterChange}
        />
        <TechnicianDensityPanel technicianSummary={technicianSummary} />
      </aside>
    </div>
  )
}
