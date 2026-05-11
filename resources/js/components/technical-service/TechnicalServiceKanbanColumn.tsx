import type { ReactNode } from 'react'

export function TechnicalServiceKanbanColumn({
  title,
  count,
  children,
  empty = 'Bu aşamada talep yok',
}: {
  title: string
  count: number
  children: ReactNode
  empty?: string
}) {
  const content = Array.isArray(children) ? children : [children]
  const hasContent = content.some(Boolean)

  return (
    <section className="flex min-w-[260px] flex-1 flex-col rounded-[28px] border border-slate-200 bg-white/80 p-4 shadow-sm backdrop-blur xl:min-w-[272px] 2xl:min-w-[288px]">
      <div className="mb-4 flex items-center justify-between gap-3 border-b border-slate-200 pb-3">
        <h2 className="text-sm font-semibold tracking-[0.01em] text-slate-950">{title}</h2>
        <span className="inline-flex min-w-8 items-center justify-center rounded-full bg-slate-950 px-2.5 py-1 text-xs font-semibold text-white">
          {count}
        </span>
      </div>

      <div className="flex min-h-[220px] flex-1 flex-col gap-3">
        {hasContent ? children : (
          <div className="flex min-h-[220px] items-center justify-center rounded-[22px] border border-dashed border-slate-200 bg-slate-50 px-4 text-center text-sm text-slate-500">
            {empty}
          </div>
        )}
      </div>
    </section>
  )
}
