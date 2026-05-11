import { TECHNICAL_SERVICE_KANBAN_COLUMNS, getTechnicalServiceKanbanColumn } from './technicalServiceKanban'
import { TechnicalServiceKanbanCard } from './TechnicalServiceKanbanCard'
import { TechnicalServiceKanbanColumn } from './TechnicalServiceKanbanColumn'
import type { ServiceRequest } from './types'

type TechnicalServiceKanbanBoardProps = {
  requests: ServiceRequest[]
  loading: boolean
  error: string | null
  selectedRequestId?: string
  onSelectRequest: (request: ServiceRequest) => void
}

export function TechnicalServiceKanbanBoard({
  requests,
  loading,
  error,
  selectedRequestId,
  onSelectRequest,
}: TechnicalServiceKanbanBoardProps) {
  const groupedRequests = TECHNICAL_SERVICE_KANBAN_COLUMNS.map((column) => ({
    ...column,
    items: requests.filter((request) => getTechnicalServiceKanbanColumn(request) === column.id),
  }))

  if (error) {
    return (
      <div className="rounded-[28px] border border-rose-200 bg-rose-50 p-5 text-sm text-rose-700">
        Teknik servis talepleri yüklenemedi. {error}
      </div>
    )
  }

  return (
    <div className="space-y-4">
      <div className="overflow-x-auto pb-2">
        <div className="flex min-w-max gap-4">
          {(loading ? TECHNICAL_SERVICE_KANBAN_COLUMNS : groupedRequests).map((column) => (
            <TechnicalServiceKanbanColumn
              key={column.id}
              title={column.label}
              count={'items' in column ? column.items.length : 0}
            >
              {loading ? (
                Array.from({ length: 3 }, (_, index) => (
                  <div key={`${column.id}-${index}`} className="h-[212px] animate-pulse rounded-[22px] border border-slate-200 bg-slate-100" />
                ))
              ) : 'items' in column ? (
                column.items.map((request) => (
                  <TechnicalServiceKanbanCard
                    key={request.id}
                    request={request}
                    selected={selectedRequestId === request.id}
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
