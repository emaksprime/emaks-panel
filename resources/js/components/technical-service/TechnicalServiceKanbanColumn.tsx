import {
  CheckCircle2,
  ClipboardCheck,
  ClipboardList,
  Clock3,
  SearchCheck,
  UserRoundPlus,
  XCircle,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import type { ReactNode } from 'react'
import type { TechnicalServiceKanbanColumnId } from './technicalServiceKanban'

const columnMeta: Record<TechnicalServiceKanbanColumnId, { icon: LucideIcon, tint: string, emptyIcon: string }> = {
  new: {
    icon: ClipboardList,
    tint: 'bg-blue-50 text-blue-700 ring-blue-100',
    emptyIcon: 'text-blue-300',
  },
  assignment_pending: {
    icon: UserRoundPlus,
    tint: 'bg-amber-50 text-amber-700 ring-amber-100',
    emptyIcon: 'text-amber-300',
  },
  assigned: {
    icon: Clock3,
    tint: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    emptyIcon: 'text-emerald-300',
  },
  final_check: {
    icon: ClipboardCheck,
    tint: 'bg-indigo-50 text-indigo-700 ring-indigo-100',
    emptyIcon: 'text-indigo-300',
  },
  completed: {
    icon: CheckCircle2,
    tint: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    emptyIcon: 'text-emerald-300',
  },
  review: {
    icon: SearchCheck,
    tint: 'bg-orange-50 text-orange-700 ring-orange-100',
    emptyIcon: 'text-orange-300',
  },
  cancelled: {
    icon: XCircle,
    tint: 'bg-rose-50 text-rose-700 ring-rose-100',
    emptyIcon: 'text-rose-300',
  },
}

export function TechnicalServiceKanbanColumn({
  columnId,
  title,
  count,
  children,
  empty = 'Bu aşamada talep yok',
}: {
  columnId: TechnicalServiceKanbanColumnId
  title: string
  count: number
  children: ReactNode
  empty?: string
}) {
  const content = Array.isArray(children) ? children : [children]
  const hasContent = content.some(Boolean)
  const meta = columnMeta[columnId]
  const Icon = meta.icon

  return (
    <section className="flex min-w-0 w-full flex-col rounded-[22px] border border-white bg-white p-2 shadow-[0_10px_28px_rgba(15,23,42,0.06)] ring-1 ring-slate-200/70 xl:p-3">
      <div className="mb-2 flex items-center justify-between gap-2 border-b border-slate-100 pb-2 xl:mb-3">
        <div className="flex min-w-0 items-center gap-2">
          <span className={['inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-[12px] ring-1', meta.tint].join(' ')}>
            <Icon className="h-3.5 w-3.5" />
          </span>
          <h2 className="truncate text-xs font-semibold text-slate-950 xl:text-sm">{title}</h2>
        </div>
        <span className="inline-flex min-w-7 items-center justify-center rounded-full bg-[#06143A] px-2 py-0.5 text-[11px] font-semibold text-white">
          {count}
        </span>
      </div>

      <div className="flex min-h-[220px] flex-1 flex-col gap-2 xl:gap-3">
        {hasContent ? children : (
          <div className="flex min-h-[220px] flex-col items-center justify-center rounded-[18px] border border-dashed border-slate-300 bg-[#F8FAFD] px-3 text-center text-xs font-medium text-slate-500 xl:text-sm">
            <Icon className={['mb-3 h-7 w-7', meta.emptyIcon].join(' ')} />
            <span>{empty}</span>
          </div>
        )}
      </div>
    </section>
  )
}
