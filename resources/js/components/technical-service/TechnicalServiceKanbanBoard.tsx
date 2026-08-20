import { useState } from 'react'
import { TECHNICAL_SERVICE_KANBAN_COLUMNS, getTechnicalServiceKanbanColumn } from './technicalServiceKanban'
import type { TechnicalServiceKanbanColumnId } from './technicalServiceKanban'
import { TechnicalServiceKanbanCard } from './TechnicalServiceKanbanCard'
import { TechnicalServiceKanbanColumn } from './TechnicalServiceKanbanColumn'
import type { ServiceRequest } from './types'

const emptyReadRequestIds = new Set<string>()

const toSortTime = (value: string | null | undefined): number => {
  const trimmed = value?.trim()

  if (!trimmed) {
    return 0
  }

  if (/^\d{4}-\d{2}-\d{2}$/.test(trimmed)) {
    const [year, month, day] = trimmed.split('-').map(Number)

    return new Date(year, (month ?? 1) - 1, day ?? 1).getTime()
  }

  const parsed = Date.parse(trimmed)

  return Number.isFinite(parsed) ? parsed : 0
}

const getNewestRequestTime = (request: ServiceRequest): number => Math.max(
  toSortTime(request.completedAt),
  toSortTime(request.fieldCompletedAt),
  toSortTime(request.fieldArrivedAt),
  toSortTime(request.fieldStartedAt),
  toSortTime(request.customerClosureApprovedAt),
  toSortTime(request.technicianApprovedAt),
  toSortTime(request.technicianRevisionRequestedAt),
  toSortTime(request.customerContactedAt),
  toSortTime(request.customerConfirmedAt),
  toSortTime(request.customerCallbackAt),
  toSortTime(request.scheduledAt),
  toSortTime(request.scheduledDate),
  toSortTime(request.customerPreferredDate),
  toSortTime(request.createdAt),
)

const requiresOpsAction = (request: ServiceRequest): boolean =>
  Boolean(
    request.actionOwner === 'ops'
    || request.actionFilterKeys?.includes('ops_action')
    || request.operationalState?.requires_ops_action
  )

type ActionFilterKey = 'all' | 'ops_action' | 'technician_action' | 'customer_waiting' | 'part_or_repeat' | 'scheduled' | 'completed'

const ACTION_FILTERS: Array<{ key: ActionFilterKey, label: string, description: string }> = [
  { key: 'all', label: 'Tümü', description: 'Tüm açık ve kapalı işler' },
  { key: 'ops_action', label: 'OPS aksiyonu', description: 'Operasyon karar bekleyen işler' },
  { key: 'technician_action', label: 'Usta bekleniyor', description: 'Aksiyon ustada' },
  { key: 'customer_waiting', label: 'Müşteri bekleniyor', description: 'Müşteri onayı veya dönüşü bekleniyor' },
  { key: 'part_or_repeat', label: 'Parça / tekrar servis', description: 'Parça veya tekrar ziyaret gündemi' },
  { key: 'scheduled', label: 'Planlı / randevulu', description: 'Randevu veya saha planı olan işler' },
  { key: 'completed', label: 'Tamamlanan', description: 'Kapalı işler' },
]

const requestActionFilterKeys = (request: ServiceRequest): string[] => (
  request.actionFilterKeys
  ?? request.operationalState?.action_filter_keys
  ?? []
)

const requestMatchesActionFilter = (request: ServiceRequest, filter: ActionFilterKey): boolean => {
  if (filter === 'all') {
    return true
  }

  const keys = requestActionFilterKeys(request)

  if (keys.includes(filter)) {
    return true
  }

  if (filter === 'ops_action') {
    return requiresOpsAction(request)
  }

  if (filter === 'technician_action') {
    return request.actionOwner === 'technician' || request.operationalState?.action_owner === 'technician'
  }

  if (filter === 'customer_waiting') {
    return request.actionOwner === 'customer' || request.operationalState?.action_owner === 'customer'
  }

  if (filter === 'part_or_repeat') {
    return Boolean(request.activePartRequest || request.requiresSecondVisit)
  }

  if (filter === 'scheduled') {
    return Boolean(request.scheduledAt || request.scheduledDate || request.operationalState?.is_appointment_confirmed)
  }

  if (filter === 'completed') {
    return request.actionOwner === 'completed' || getTechnicalServiceKanbanColumn(request) === 'completed'
  }

  return false
}

const actionOwnerSortPriority = (request: ServiceRequest): number => {
  const dashboardPriority = request.actionPriority ?? request.operationalState?.action_priority_score

  if (typeof dashboardPriority === 'number' && Number.isFinite(dashboardPriority)) {
    return dashboardPriority
  }

  if (requiresOpsAction(request)) {
    return ({
      critical: 10,
      high: 20,
      normal: 40,
      low: 70,
    } as Record<string, number>)[String(request.operationalState?.action_priority ?? 'normal')] ?? 40
  }

  if (request.actionOwner === 'completed' || request.operationalState?.action_owner === 'none') {
    return 900
  }

  return 500
}

const compareRequestsNewestFirst = (a: ServiceRequest, b: ServiceRequest): number => {
  const actionOwnerDifference = actionOwnerSortPriority(a) - actionOwnerSortPriority(b)

  if (actionOwnerDifference !== 0) {
    return actionOwnerDifference
  }

  const priorityDifference = (a.attention?.sort_priority ?? 50) - (b.attention?.sort_priority ?? 50)

  if (priorityDifference !== 0) {
    return priorityDifference
  }

  const actionTimeDifference = toSortTime(b.attention?.last_action_at ?? null) - toSortTime(a.attention?.last_action_at ?? null)

  if (actionTimeDifference !== 0) {
    return actionTimeDifference
  }

  const timeDifference = getNewestRequestTime(b) - getNewestRequestTime(a)

  if (timeDifference !== 0) {
    return timeDifference
  }

  return String(b.id).localeCompare(String(a.id), 'tr', { numeric: true })
}

type TechnicalServiceKanbanBoardProps = {
  requests: ServiceRequest[]
  loading: boolean
  error: string | null
  selectedRequestId?: string
  readRequestIds?: Set<string>
  onSelectRequest: (request: ServiceRequest) => void
}

const primaryColumnIds: TechnicalServiceKanbanColumnId[] = ['new', 'assignment_pending', 'assigned', 'final_check', 'completed']

export function TechnicalServiceKanbanBoard({
  requests,
  loading,
  error,
  selectedRequestId,
  readRequestIds = emptyReadRequestIds,
  onSelectRequest,
}: TechnicalServiceKanbanBoardProps) {
  const [showOtherColumns, setShowOtherColumns] = useState(false)
  const [activeActionFilter, setActiveActionFilter] = useState<ActionFilterKey>('all')
  const opsActionCount = requests.filter(requiresOpsAction).length
  const filterCounts = ACTION_FILTERS.reduce<Record<ActionFilterKey, number>>((counts, filter) => {
    counts[filter.key] = requests.filter((request) => requestMatchesActionFilter(request, filter.key)).length

    return counts
  }, {} as Record<ActionFilterKey, number>)
  const filteredRequests = requests.filter((request) => requestMatchesActionFilter(request, activeActionFilter))
  const groupedRequests = TECHNICAL_SERVICE_KANBAN_COLUMNS.map((column) => ({
    ...column,
    items: filteredRequests
      .filter((request) => getTechnicalServiceKanbanColumn(request) === column.id)
      .sort(compareRequestsNewestFirst),
  }))
  const primaryColumns = groupedRequests.filter((column) => primaryColumnIds.includes(column.id))
  const otherColumns = groupedRequests.filter((column) => !primaryColumnIds.includes(column.id))
  const otherCount = otherColumns.reduce((total, column) => total + column.items.length, 0)
  const filteredColumns = groupedRequests.filter((column) => column.items.length > 0)
  const isActionFiltered = activeActionFilter !== 'all'
  const activeFilter = ACTION_FILTERS.find((filter) => filter.key === activeActionFilter) ?? ACTION_FILTERS[0]
  const visibleColumns = isActionFiltered
    ? (filteredColumns.length > 0 ? filteredColumns : primaryColumns)
    : showOtherColumns
      ? [...primaryColumns, ...otherColumns]
      : primaryColumns
  const columnsToRender = loading ? primaryColumns : visibleColumns

  if (error) {
    return (
      <div className="rounded-[28px] border border-rose-200 bg-rose-50 p-5 text-sm text-rose-700">
        Teknik servis talepleri yüklenemedi. {error}
      </div>
    )
  }

  return (
    <div className="space-y-4">
      {!loading && (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-[24px] border border-slate-200 bg-white px-4 py-3 text-sm shadow-sm">
          <div>
            <p className="font-semibold text-slate-900">
              {isActionFiltered ? activeFilter.label : 'Aksiyon filtreleri'}
            </p>
            <p className="mt-1 text-xs text-slate-500">
              {isActionFiltered
                ? activeFilter.description
                : 'İnceleniyor ve İptal kolonları küçük ekranda alanı boğmasın diye kapalı gelir.'}
            </p>
            {activeActionFilter === 'ops_action' && opsActionCount === 0 ? (
              <p className="mt-1 text-xs font-semibold text-emerald-700">OPS aksiyonu bekleyen iş yok.</p>
            ) : null}
          </div>
          <div className="flex flex-wrap items-center gap-2">
            {ACTION_FILTERS.map((filter) => (
              <button
                key={filter.key}
                type="button"
                onClick={() => setActiveActionFilter(filter.key)}
                className={[
                  'rounded-2xl border px-3 py-2 text-sm font-semibold transition',
                  activeActionFilter === filter.key
                    ? filter.key === 'ops_action'
                      ? 'border-amber-300 bg-amber-100 text-amber-950 shadow-sm'
                      : 'border-[#06143A] bg-[#06143A] text-white shadow-sm'
                    : filter.key === 'ops_action'
                      ? 'border-amber-200 bg-amber-50 text-amber-800 hover:bg-amber-100'
                      : 'border-slate-200 bg-slate-50 text-slate-700 hover:bg-slate-100',
                ].join(' ')}
              >
                {filter.label} ({filterCounts[filter.key] ?? 0})
              </button>
            ))}
            {!isActionFiltered && otherColumns.length > 0 ? (
              <button
                type="button"
                onClick={() => setShowOtherColumns((current) => !current)}
                className="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100"
              >
                {showOtherColumns ? 'Diğer durumları gizle' : `Diğer durumları göster (${otherCount})`}
              </button>
            ) : null}
          </div>
        </div>
      )}
      <div className="w-full min-w-0 overflow-hidden pb-3">
        <div
          className="grid w-full min-w-0 gap-2 2xl:gap-3"
          style={{ gridTemplateColumns: `repeat(${columnsToRender.length}, minmax(0, 1fr))` }}
        >
          {columnsToRender.map((column) => (
            <TechnicalServiceKanbanColumn
              key={column.id}
              columnId={column.id}
              title={column.label}
              count={'items' in column ? column.items.length : 0}
            >
              {loading ? (
                Array.from({ length: 3 }, (_, index) => (
                  <div key={`${column.id}-${index}`} className="h-[224px] min-w-0 animate-pulse rounded-[24px] border border-slate-200 bg-slate-100" />
                ))
              ) : 'items' in column ? (
                column.items.map((request) => (
                  <TechnicalServiceKanbanCard
                    key={request.id}
                    request={request}
                    selected={selectedRequestId === request.id}
                    isUnread={getTechnicalServiceKanbanColumn(request) === 'new' && !readRequestIds.has(request.id)}
                    onClick={() => onSelectRequest(request)}
                  />
                ))
              ) : null}
            </TechnicalServiceKanbanColumn>
          ))}
        </div>
      </div>
    </div>
  )
}
