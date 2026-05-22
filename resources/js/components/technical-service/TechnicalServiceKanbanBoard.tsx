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

const compareRequestsNewestFirst = (a: ServiceRequest, b: ServiceRequest): number => {
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
  const groupedRequests = TECHNICAL_SERVICE_KANBAN_COLUMNS.map((column) => ({
    ...column,
    items: requests
      .filter((request) => getTechnicalServiceKanbanColumn(request) === column.id)
      .sort(compareRequestsNewestFirst),
  }))
  const primaryColumns = groupedRequests.filter((column) => primaryColumnIds.includes(column.id))
  const otherColumns = groupedRequests.filter((column) => !primaryColumnIds.includes(column.id))
  const otherCount = otherColumns.reduce((total, column) => total + column.items.length, 0)
  const visibleColumns = showOtherColumns ? [...primaryColumns, ...otherColumns] : primaryColumns
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
      {!loading && otherColumns.length > 0 && (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-[24px] border border-slate-200 bg-white px-4 py-3 text-sm shadow-sm">
          <div>
            <p className="font-semibold text-slate-900">Diğer durumlar</p>
            <p className="mt-1 text-xs text-slate-500">İnceleniyor ve İptal kolonları küçük ekranda alanı boğmasın diye kapalı gelir.</p>
          </div>
          <button
            type="button"
            onClick={() => setShowOtherColumns((current) => !current)}
            className="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100"
          >
            {showOtherColumns ? 'Diğer durumları gizle' : `Diğer durumları göster (${otherCount})`}
          </button>
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
