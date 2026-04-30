import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import type { ServiceFilters, ServiceStatusFilter } from './types'

const statusOptions: { value: ServiceStatusFilter; label: string }[] = [
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
    <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
      <div className="grid gap-4">
        <label className="grid gap-2 text-sm font-medium text-slate-700">
          Arama
          <Input
            value={filters.search}
            onChange={(event) => onChange({ ...filters, search: event.target.value })}
            placeholder="MRN, müşteri, telefon, adres, ürün, model, seri no..."
          />
        </label>

        <label className="grid gap-2 text-sm font-medium text-slate-700">
          Durum
          <select
            value={filters.status}
            onChange={(event) => onChange({ ...filters, status: event.target.value as ServiceStatusFilter })}
            className="border-input h-9 rounded-md border bg-transparent px-3 text-sm outline-none transition focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
          >
            {statusOptions.map((option) => (
              <option key={option.value || 'all'} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </label>

        <div className="flex flex-col items-stretch gap-2 sm:flex-row sm:justify-end sm:items-center">
          <Button className="w-full sm:w-auto" variant="outline" type="button" onClick={onReset}>
            Temizle
          </Button>
        </div>
      </div>
    </section>
  )
}
