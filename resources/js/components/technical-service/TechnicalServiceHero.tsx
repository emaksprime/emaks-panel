import type { ReactNode } from 'react'

export function TechnicalServiceHero({
  label,
  title,
  description,
  action,
}: {
  label: string
  title: string
  description: string
  action?: ReactNode
}) {
  return (
    <section className="relative overflow-hidden rounded-[28px] border border-white/80 bg-white/92 px-5 py-5 shadow-[0_18px_45px_rgba(15,23,42,0.08)] ring-1 ring-slate-200/70 backdrop-blur sm:px-6 sm:py-6">
      <div className="absolute inset-x-0 top-0 h-1.5 bg-slate-950" />
      <div className="relative flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
        <div className="flex max-w-4xl items-start gap-4">
          <div className="inline-flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-slate-950 text-white shadow-[0_14px_30px_rgba(15,23,42,0.18)]">
            <div className="grid grid-cols-2 gap-1.5">
              <span className="h-2 w-2 rounded-[3px] bg-white" />
              <span className="h-2 w-2 rounded-[3px] bg-white/80" />
              <span className="h-2 w-2 rounded-[3px] bg-white/80" />
              <span className="h-2 w-2 rounded-[3px] bg-white" />
            </div>
          </div>
          <div className="max-w-3xl">
            <p className="mb-3 text-[11px] font-semibold uppercase tracking-[0.26em] text-slate-500">{label}</p>
            <h1 className="text-3xl font-semibold tracking-tight text-slate-950">{title}</h1>
            <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-600">{description}</p>
          </div>
        </div>

        {action ? (
          <div className="flex shrink-0 flex-col items-stretch gap-3 lg:items-end">
            <p className="text-right text-xs font-medium uppercase tracking-[0.18em] text-slate-500">Canlı operasyon takibi</p>
            {action}
          </div>
        ) : null}
      </div>
    </section>
  )
}
