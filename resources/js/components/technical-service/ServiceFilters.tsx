import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import type { ServiceFilters, ServiceStatusFilter } from './types'

const statusOptions: { value: ServiceStatusFilter | string, label: string }[] = [
  { value: '', label: 'Tümü' },
  { value: 'unassigned', label: 'Atanmamış İşler' },
  { value: 'today_installations', label: 'Bugünkü Montajlar' },
  { value: 'scheduled', label: 'Randevulu' },
  { value: 'Tamamlandı', label: 'Tamamlandı' },
  { value: 'İptal', label: 'İptal' },
]

export function ServiceFilters({
  filters,
  onChange,
  onReset,
}: {
  filters: ServiceFilters
  onChange: (next: ServiceFilters) => void
  onReset: () => void
}) {
  return (
    <section className="grid gap-4 rounded-[26px] border border-white/80 bg-white p-4 shadow-[0_18px_40px_rgba(15,23,42,0.08)] ring-1 ring-slate-200/70 lg:grid-cols-[minmax(0,1.8fr)_260px_auto] lg:items-end lg:p-5">
      <label className="grid gap-2 text-sm font-medium text-slate-700">
        Arama
        <Input
          value={filters.search}
          onChange={(event) => onChange({ ...filters, search: event.target.value })}
          placeholder="Talep no, müşteri, telefon, cihaz, model veya seri no ile ara"
          className="h-12 rounded-xl border-slate-200 bg-slate-50/90 px-4 text-sm shadow-none focus-visible:border-blue-300 focus-visible:ring-4 focus-visible:ring-blue-100"
        />
      </label>

      <label className="grid gap-2 text-sm font-medium text-slate-700">
        Durum
        <select
          value={filters.status}
          onChange={(event) => onChange({ ...filters, status: event.target.value as ServiceStatusFilter })}
          className="h-12 rounded-xl border border-slate-200 bg-slate-50/90 px-4 text-sm text-slate-900 outline-none transition focus-visible:border-blue-300 focus-visible:ring-4 focus-visible:ring-blue-100"
        >
          {statusOptions.map((option) => (
            <option key={option.value || 'all'} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      </label>

      <div className="flex flex-col items-stretch gap-2 sm:flex-row lg:justify-end">
        <Button
          className="h-12 rounded-xl border-slate-200 bg-white px-5 text-sm text-slate-700 hover:bg-slate-50"
          variant="outline"
          type="button"
          onClick={onReset}
        >
          Temizle
        </Button>
      </div>
    </section>
  )
}

