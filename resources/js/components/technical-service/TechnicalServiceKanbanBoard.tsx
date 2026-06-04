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
  Boolean(request.operationalState?.requires_ops_action)

const actionOwnerSortPriority = (request: ServiceRequest): number => {
  if (request.operationalState?.requires_ops_action) {
    return ({
      critical: 0,
      high: 1,
      normal: 2,
      low: 3,
    } as Record<string, number>)[String(request.operationalState.action_priority ?? 'normal')] ?? 2
  }

  if (request.operationalState?.action_owner === 'none') {
    return 20
  }

  return 10
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
  const [showOpsActionsOnly, setShowOpsActionsOnly] = useState(false)
  const opsActionCount = requests.filter(requiresOpsAction).length
  const filteredRequests = showOpsActionsOnly
    ? requests.filter(requiresOpsAction)
    : requests
  const groupedRequests = TECHNICAL_SERVICE_KANBAN_COLUMNS.map((column) => ({
    ...column,
    items: filteredRequests
      .filter((request) => getTechnicalServiceKanbanColumn(request) === column.id)
      .sort(compareRequestsNewestFirst),
  }))
  const primaryColumns = groupedRequests.filter((column) => primaryColumnIds.includes(column.id))
  const otherColumns = groupedRequests.filter((column) => !primaryColumnIds.includes(column.id))
  const otherCount = otherColumns.reduce((total, column) => total + column.items.length, 0)
  const opsFilteredColumns = groupedRequests.filter((column) => column.items.length > 0)
  const visibleColumns = showOpsActionsOnly
    ? (opsFilteredColumns.length > 0 ? opsFilteredColumns : primaryColumns)
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
      {!loading && (otherColumns.length > 0 || opsActionCount > 0 || showOpsActionsOnly) && (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-[24px] border border-slate-200 bg-white px-4 py-3 text-sm shadow-sm">
          <div>
            <p className="font-semibold text-slate-900">
              {showOpsActionsOnly ? 'OPS aksiyonu bekleyenler' : 'Kanban filtreleri'}
            </p>
            <p className="mt-1 text-xs text-slate-500">
              {showOpsActionsOnly
                ? 'Sadece operasyonun karar vermesi gereken işler gösteriliyor.'
                : 'İnceleniyor ve İptal kolonları küçük ekranda alanı boğmasın diye kapalı gelir.'}
            </p>
            {showOpsActionsOnly && opsActionCount === 0 ? (
              <p className="mt-1 text-xs font-semibold text-emerald-700">OPS aksiyonu bekleyen iş yok.</p>
            ) : null}
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <button
              type="button"
              onClick={() => setShowOpsActionsOnly((current) => !current)}
              className={[
                'rounded-2xl border px-3 py-2 text-sm font-semibold transition',
                showOpsActionsOnly
                  ? 'border-amber-300 bg-amber-100 text-amber-950 shadow-sm'
                  : 'border-amber-200 bg-amber-50 text-amber-800 hover:bg-amber-100',
              ].join(' ')}
            >
              OPS aksiyonu bekleyenler ({opsActionCount})
            </button>
            {!showOpsActionsOnly && otherColumns.length > 0 ? (
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
