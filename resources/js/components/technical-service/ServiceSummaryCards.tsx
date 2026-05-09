import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import type { SummaryItem } from './types'

const toneStyles: Record<SummaryItem['tone'], { dot: string, ring: string, bg: string }> = {
  default: {
    dot: 'bg-slate-400',
    ring: 'ring-slate-200/80',
    bg: 'bg-white/92',
  },
  accent: {
    dot: 'bg-blue-600',
    ring: 'ring-blue-100',
    bg: 'bg-white/92',
  },
  warning: {
    dot: 'bg-amber-500',
    ring: 'ring-amber-100',
    bg: 'bg-white/92',
  },
  positive: {
    dot: 'bg-emerald-500',
    ring: 'ring-emerald-100',
    bg: 'bg-white/92',
  },
}

export function ServiceSummaryCards({ items }: { items: SummaryItem[] }) {
  return (
    <div className="-mx-1 overflow-x-auto pb-1">
      <div className="grid min-w-[720px] gap-3 px-1 md:min-w-0 md:grid-cols-3 xl:grid-cols-6">
      {items.map((item) => {
        const tone = toneStyles[item.tone]

        return (
          <Card
            key={item.label}
            className={[
              'rounded-[22px] border border-white/80 shadow-[0_14px_30px_rgba(15,23,42,0.06)] backdrop-blur',
              tone.bg,
              tone.ring,
            ].join(' ')}
          >
            <CardHeader className="space-y-3 px-4 py-4">
              <div className="flex items-center justify-between gap-3">
                <CardDescription className="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">
                  {item.label}
                </CardDescription>
                <span className={['mt-1 h-2.5 w-2.5 rounded-full', tone.dot].join(' ')} />
              </div>
              <CardTitle className="text-2xl font-semibold tracking-[-0.04em] text-slate-950">
                {item.value}
              </CardTitle>
            </CardHeader>
          </Card>
        )
      })}
      </div>
    </div>
  )
}
