import { CalendarDays, Check, ChevronDown, ChevronLeft, ChevronRight } from 'lucide-react'
import type { RefObject } from 'react'
import { Button } from '@/components/ui/button'

type CalendarDay = {
  key: string
  date: Date
  label: string
  inCurrentMonth: boolean
  isToday: boolean
  isSelected: boolean
}

type WeekDayItem = {
  key: string
  label: string
  shortDate: string
  fullDate: string
  count: number
  overdueCount: number
  isToday: boolean
  isSelected: boolean
}

type SummaryItem = {
  label: string
  value: number
  tone?: 'slate' | 'blue' | 'amber' | 'rose' | 'emerald'
}

type TechnicalServiceWeekNavigatorProps = {
  weekLabel: string
  selectedDateButtonLabel: string
  selectedDayLabel: string
  selectedDayCount: number
  selectedDayOverdueCount: number
  hasActiveDayFilter: boolean
  weekDays: WeekDayItem[]
  summaryItems: SummaryItem[]
  isDatePickerOpen: boolean
  calendarMonthLabel: string
  calendarWeekdays: string[]
  calendarDays: CalendarDay[]
  datePickerRef: RefObject<HTMLDivElement | null>
  onPreviousWeek: () => void
  onToday: () => void
  onToggleDatePicker: () => void
  onPreviousMonth: () => void
  onNextMonth: () => void
  onSelectCalendarDay: (day: Date) => void
  onSelectDay: (key: string) => void
  onCloseDatePicker: () => void
  onNextWeek: () => void
}

const toneClassMap: Record<NonNullable<SummaryItem['tone']>, string> = {
  slate: 'bg-slate-50 text-slate-700',
  blue: 'bg-blue-50 text-blue-700',
  amber: 'bg-amber-50 text-amber-700',
  rose: 'bg-rose-50 text-rose-700',
  emerald: 'bg-emerald-50 text-emerald-700',
}

export function TechnicalServiceWeekNavigator({
  weekLabel,
  selectedDateButtonLabel,
  selectedDayLabel,
  selectedDayCount,
  selectedDayOverdueCount,
  hasActiveDayFilter,
  weekDays,
  summaryItems,
  isDatePickerOpen,
  calendarMonthLabel,
  calendarWeekdays,
  calendarDays,
  datePickerRef,
  onPreviousWeek,
  onToday,
  onToggleDatePicker,
  onPreviousMonth,
  onNextMonth,
  onSelectCalendarDay,
  onSelectDay,
  onCloseDatePicker,
  onNextWeek,
}: TechnicalServiceWeekNavigatorProps) {
  return (
    <section className="rounded-[24px] border border-slate-200 bg-white px-5 py-5 shadow-sm sm:px-6 sm:py-6">
      <div className="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
        <div>
          <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Haftalik Plan</p>
          <h2 className="mt-2 text-xl font-semibold tracking-tight text-slate-950">{weekLabel}</h2>
          <p className="mt-2 text-sm leading-6 text-slate-600">Planli randevularin haftalik yogunlugunu ozetler.</p>
        </div>

        <div className="flex flex-wrap items-center gap-2 xl:justify-end">
          <Button type="button" variant="outline" className="h-11 rounded-2xl border-slate-200 bg-white px-4 text-slate-700 hover:bg-slate-50" onClick={onPreviousWeek}>
            <ChevronLeft className="mr-2 h-4 w-4" />
            Onceki Hafta
          </Button>
          <Button type="button" variant="outline" className="h-11 rounded-2xl border-slate-200 bg-white px-4 text-slate-700 hover:bg-slate-50" onClick={onToday}>
            <CalendarDays className="mr-2 h-4 w-4" />
            Bugun
          </Button>
          <div ref={datePickerRef} className="relative">
            <Button
              type="button"
              variant="outline"
              className="h-11 rounded-2xl border-slate-200 bg-white px-4 text-slate-700 hover:bg-slate-50"
              onClick={onToggleDatePicker}
              aria-expanded={isDatePickerOpen}
            >
              <span>{selectedDateButtonLabel}</span>
              <ChevronDown className={['ml-2 h-4 w-4 text-slate-400 transition-transform', isDatePickerOpen ? 'rotate-180' : 'rotate-0'].join(' ')} />
            </Button>

            {isDatePickerOpen ? (
              <div className="absolute right-0 top-[calc(100%+10px)] z-30 w-[320px] rounded-[24px] border border-slate-200 bg-white p-4 shadow-[0_18px_40px_rgba(15,23,42,0.12)]">
                <div className="mb-4 flex items-center justify-between gap-2">
                  <button type="button" onClick={onPreviousMonth} className="inline-flex h-9 w-9 items-center justify-center rounded-full border border-slate-200 text-slate-600 transition hover:bg-slate-50" aria-label="Onceki ay">
                    <ChevronLeft className="h-4 w-4" />
                  </button>
                  <p className="text-sm font-semibold text-slate-950">{calendarMonthLabel}</p>
                  <button type="button" onClick={onNextMonth} className="inline-flex h-9 w-9 items-center justify-center rounded-full border border-slate-200 text-slate-600 transition hover:bg-slate-50" aria-label="Sonraki ay">
                    <ChevronRight className="h-4 w-4" />
                  </button>
                </div>

                <div className="grid grid-cols-7 gap-2 text-center text-[11px] font-semibold text-slate-500">
                  {calendarWeekdays.map((weekday) => (
                    <span key={weekday} className="py-1">{weekday}</span>
                  ))}
                </div>

                <div className="mt-2 grid grid-cols-7 gap-2">
                  {calendarDays.map((day) => (
                    <button
                      key={day.key}
                      type="button"
                      onClick={() => onSelectCalendarDay(day.date)}
                      className={[
                        'flex h-10 items-center justify-center rounded-2xl text-sm font-medium transition',
                        day.isSelected
                          ? 'bg-[#06143A] text-white shadow-[0_10px_20px_rgba(6,20,58,0.18)]'
                          : day.isToday
                            ? 'border border-blue-200 bg-blue-50 text-blue-700'
                            : day.inCurrentMonth
                              ? 'text-slate-700 hover:bg-slate-50'
                              : 'text-slate-300 hover:bg-slate-50',
                      ].join(' ')}
                    >
                      {day.label}
                    </button>
                  ))}
                </div>

                <div className="mt-4 flex items-center justify-between gap-2">
                  <button type="button" onClick={onToday} className="text-sm font-medium text-[#06143A] transition hover:text-slate-900">
                    Bugun
                  </button>
                  <button type="button" onClick={onCloseDatePicker} className="text-sm font-medium text-slate-500 transition hover:text-slate-900">
                    Kapat
                  </button>
                </div>
              </div>
            ) : null}
          </div>
          <Button type="button" variant="outline" className="h-11 rounded-2xl border-slate-200 bg-white px-4 text-slate-700 hover:bg-slate-50" onClick={onNextWeek}>
            Sonraki Hafta
            <ChevronRight className="ml-2 h-4 w-4" />
          </Button>
        </div>
      </div>

      <div className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        {summaryItems.map((item) => (
          <div key={item.label} className="rounded-[20px] border border-slate-200 bg-slate-50/70 px-4 py-4">
            <span className={['inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold', toneClassMap[item.tone ?? 'slate']].join(' ')}>
              {item.label}
            </span>
            <p className="mt-3 text-3xl font-semibold tracking-[-0.03em] text-slate-950">{item.value}</p>
          </div>
        ))}
      </div>

      <div className="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-[20px] border border-slate-200 bg-slate-50/70 px-4 py-3">
        <div>
          <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Secili Gun</p>
          <p className="mt-1 text-sm font-semibold text-slate-950">{selectedDayLabel}</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {hasActiveDayFilter ? (
            <span className="inline-flex rounded-full bg-[#06143A] px-3 py-1.5 text-sm font-medium text-white">
              Filtre aktif
            </span>
          ) : null}
          <span className="inline-flex rounded-full bg-white px-3 py-1.5 text-sm font-medium text-slate-700">
            {selectedDayCount} talep
          </span>
          {selectedDayOverdueCount > 0 ? (
            <span className="inline-flex rounded-full bg-rose-50 px-3 py-1.5 text-sm font-medium text-rose-700">
              {selectedDayOverdueCount} geciken
            </span>
          ) : null}
        </div>
      </div>

      <div className="mt-5 overflow-x-auto pb-1">
        <div className="grid min-w-[980px] grid-cols-[repeat(7,minmax(132px,1fr))] gap-3 xl:min-w-0 xl:grid-cols-[repeat(7,minmax(0,1fr))]">
          {weekDays.map((day) => (
            <button
              key={day.key}
              type="button"
              onClick={() => onSelectDay(day.key)}
              className={[
                'rounded-[20px] border px-4 py-4 text-left transition',
                day.isSelected
                  ? 'border-[#06143A] bg-[#06143A] text-white shadow-[0_12px_24px_rgba(6,20,58,0.16)]'
                  : day.isToday
                    ? 'border-blue-200 bg-blue-50/60 text-slate-950'
                    : 'border-slate-200 bg-slate-50 text-slate-900 hover:border-slate-300 hover:bg-white',
              ].join(' ')}
            >
              <div className="flex items-start justify-between gap-3">
                <div>
                  <p className={['text-xs font-semibold uppercase tracking-[0.14em]', day.isSelected ? 'text-slate-200' : 'text-slate-500'].join(' ')}>{day.label}</p>
                  <p className={['mt-1 text-sm font-semibold', day.isSelected ? 'text-white' : 'text-slate-950'].join(' ')}>{day.shortDate}</p>
                </div>
                {day.isSelected ? (
                  <span className="inline-flex rounded-full bg-white/15 px-2 py-1 text-[11px] font-semibold text-white">
                    <Check className="mr-1 h-3.5 w-3.5" />
                    Secili
                  </span>
                ) : day.overdueCount > 0 ? (
                  <span className={['inline-flex rounded-full px-2 py-1 text-[11px] font-semibold', day.isSelected ? 'bg-white/15 text-white' : 'bg-rose-50 text-rose-700'].join(' ')}>
                    {day.overdueCount} geciken
                  </span>
                ) : null}
              </div>
              <p className={['mt-4 text-3xl font-semibold tracking-[-0.03em]', day.isSelected ? 'text-white' : 'text-slate-950'].join(' ')}>{day.count}</p>
              <p className={['mt-1 text-xs', day.isSelected ? 'text-slate-200' : 'text-slate-500'].join(' ')}>{day.fullDate}</p>
            </button>
          ))}
        </div>
      </div>
    </section>
  )
}
