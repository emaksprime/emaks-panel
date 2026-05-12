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
  id: string
  label: string
  value: number | string
  tone?: 'slate' | 'blue' | 'amber' | 'rose' | 'emerald'
  isActive?: boolean
}

type TechnicalServiceWeekNavigatorProps = {
  weekLabel: string
  selectedDateButtonLabel: string
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
  onSelectSummaryItem: (id: string) => void
  onSelectDay: (key: string) => void
  onCloseDatePicker: () => void
  onNextWeek: () => void
}

const toneClassMap: Record<NonNullable<SummaryItem['tone']>, string> = {
  slate: 'text-slate-600',
  blue: 'text-blue-700',
  amber: 'text-amber-700',
  rose: 'text-rose-700',
  emerald: 'text-emerald-700',
}

export function TechnicalServiceWeekNavigator({
  weekLabel,
  selectedDateButtonLabel,
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
  onSelectSummaryItem,
  onSelectDay,
  onCloseDatePicker,
  onNextWeek,
}: TechnicalServiceWeekNavigatorProps) {
  return (
    <section className="rounded-[32px] border border-white bg-white px-5 py-5 shadow-[0_16px_44px_rgba(15,23,42,0.07)] ring-1 ring-slate-200/70 sm:px-6 sm:py-6">
      <div className="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
        <div>
          <p className="inline-flex rounded-full bg-slate-100 px-3 py-1 text-[11px] font-semibold uppercase text-slate-500">Haftalık Plan</p>
          <h2 className="mt-3 text-2xl font-semibold text-slate-950">{weekLabel}</h2>
          <p className="mt-2 text-sm leading-6 text-slate-600">Planlı randevuların haftalık yoğunluğunu özetler.</p>
        </div>

        <div className="flex flex-wrap items-center gap-2 xl:justify-end">
          <Button type="button" variant="outline" className="h-10 rounded-[16px] border-slate-200 bg-white px-3.5 font-semibold text-slate-700 shadow-sm hover:bg-slate-50" onClick={onPreviousWeek}>
            <ChevronLeft className="mr-2 h-4 w-4" />
            Önceki Hafta
          </Button>
          <Button type="button" variant="outline" className="h-10 rounded-[16px] border-slate-200 bg-white px-3.5 font-semibold text-slate-700 shadow-sm hover:bg-slate-50" onClick={onToday}>
            <CalendarDays className="mr-2 h-4 w-4" />
            Bugün
          </Button>
          <div ref={datePickerRef} className="relative">
            <Button
              type="button"
              variant="outline"
              className="h-10 rounded-[16px] border-slate-200 bg-white px-3.5 font-semibold text-slate-700 shadow-sm hover:bg-slate-50"
              onClick={onToggleDatePicker}
              aria-expanded={isDatePickerOpen}
            >
              <span>{selectedDateButtonLabel}</span>
              <ChevronDown className={['ml-2 h-4 w-4 text-slate-400 transition-transform', isDatePickerOpen ? 'rotate-180' : 'rotate-0'].join(' ')} />
            </Button>

            {isDatePickerOpen ? (
              <div className="absolute right-0 top-[calc(100%+10px)] z-30 w-[320px] rounded-[24px] border border-slate-200 bg-white p-4 shadow-[0_18px_40px_rgba(15,23,42,0.12)]">
                <div className="mb-4 flex items-center justify-between gap-2">
                  <button type="button" onClick={onPreviousMonth} className="inline-flex h-9 w-9 items-center justify-center rounded-full border border-slate-200 text-slate-600 transition hover:bg-slate-50" aria-label="Önceki ay">
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
                    Bugün
                  </button>
                  <button type="button" onClick={onCloseDatePicker} className="text-sm font-medium text-slate-500 transition hover:text-slate-900">
                    Kapat
                  </button>
                </div>
              </div>
            ) : null}
          </div>
          <Button type="button" variant="outline" className="h-10 rounded-[16px] border-slate-200 bg-white px-3.5 font-semibold text-slate-700 shadow-sm hover:bg-slate-50" onClick={onNextWeek}>
            Sonraki Hafta
            <ChevronRight className="ml-2 h-4 w-4" />
          </Button>
        </div>
      </div>

      <div className="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        {summaryItems.map((item) => (
          <button
            key={item.id}
            type="button"
            aria-pressed={item.isActive ? 'true' : 'false'}
            onClick={() => onSelectSummaryItem(item.id)}
            className={[
              'flex min-h-[76px] flex-col items-start justify-between gap-3 rounded-[22px] border px-4 py-3 text-left transition',
              item.isActive
                ? 'border-[#06143A] bg-[#06143A]/5 shadow-[0_10px_24px_rgba(6,20,58,0.08)] ring-2 ring-[#06143A]/10'
                : 'border-slate-200 bg-[#F8FAFD] hover:border-slate-300 hover:bg-white',
            ].join(' ')}
          >
            <span className={['min-w-0 truncate text-xs font-semibold', toneClassMap[item.tone ?? 'slate']].join(' ')}>
              {item.label}
            </span>
            <span className="shrink-0 text-2xl font-semibold leading-none text-slate-950">{item.value}</span>
          </button>
        ))}
      </div>

      <div className="mt-4 overflow-x-auto pb-1">
        <div className="grid w-full grid-cols-7 gap-2">
          {weekDays.map((day) => (
            <button
              key={day.key}
              type="button"
              onClick={() => onSelectDay(day.key)}
              className={[
                'min-h-[84px] rounded-[18px] border bg-[#F8FAFD] px-3 py-2.5 text-left transition',
                day.isSelected
                  ? 'border-blue-300 bg-blue-50 text-slate-950 ring-1 ring-blue-100'
                  : day.isToday
                    ? 'border-blue-200 bg-blue-50/60 text-slate-950'
                    : 'border-slate-200 text-slate-900 hover:border-slate-300 hover:bg-white',
              ].join(' ')}
            >
              <div className="flex min-h-5 items-center justify-between gap-1.5">
                <p className={['text-[11px] font-semibold uppercase', day.isSelected ? 'text-blue-700' : 'text-slate-500'].join(' ')}>{day.label}</p>
                {day.isSelected ? (
                  <span className="inline-flex shrink-0 rounded-full bg-blue-100 px-1.5 py-0.5 text-[10px] font-semibold leading-none text-blue-700">
                    <Check className="mr-0.5 h-3 w-3" />
                    Seçili
                  </span>
                ) : day.overdueCount > 0 ? (
                  <span className={['inline-flex shrink-0 rounded-full px-1.5 py-0.5 text-[10px] font-semibold leading-none', day.isSelected ? 'bg-blue-100 text-blue-700' : 'bg-rose-50 text-rose-700'].join(' ')}>
                    {day.overdueCount} geciken
                  </span>
                ) : null}
              </div>
              <p className="mt-1 text-xs font-semibold text-slate-700">{day.shortDate}</p>
              <p className={['mt-1 text-2xl font-semibold leading-none', day.isSelected ? 'text-[#06143A]' : 'text-slate-950'].join(' ')}>{day.count}</p>
              <p className={['mt-1 text-[11px] leading-4', day.isSelected ? 'text-blue-700' : 'text-slate-500'].join(' ')}>{day.fullDate}</p>
            </button>
          ))}
        </div>
      </div>
    </section>
  )
}
