import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import type { ServiceFilters, ServiceStatus, ServiceType } from './types'

const serviceTypes: (ServiceType | '')[] = ['', 'Montaj', 'Arıza', 'Kontrol']
const statuses: (ServiceStatus | '')[] = ['', 'Yeni', 'Atandı', 'Randevulu', 'Devam Ediyor', 'Tamamlandı', 'İptal']

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
      <div className="flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
        <div className="grid flex-1 gap-4 sm:grid-cols-2 xl:grid-cols-[1.5fr_1fr_1fr]">
          <label className="grid gap-2 text-sm font-medium text-slate-700">
            Arama
            <Input
              value={filters.search}
              onChange={(event) => onChange({ ...filters, search: event.target.value })}
              placeholder="MRN, müşteri, telefon, seri no"
            />
          </label>

          <label className="grid gap-2 text-sm font-medium text-slate-700">
            Servis tipi
            <select
              value={filters.serviceType}
              onChange={(event) => onChange({ ...filters, serviceType: event.target.value as ServiceType | '' })}
              className="border-input h-9 rounded-md border bg-transparent px-3 text-sm outline-none transition focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
            >
              {serviceTypes.map((type) => (
                <option key={type || 'all'} value={type}>
                  {type || 'Tümü'}
                </option>
              ))}
            </select>
          </label>

          <label className="grid gap-2 text-sm font-medium text-slate-700">
            Durum
            <select
              value={filters.status}
              onChange={(event) => onChange({ ...filters, status: event.target.value as ServiceStatus | '' })}
              className="border-input h-9 rounded-md border bg-transparent px-3 text-sm outline-none transition focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
            >
              {statuses.map((status) => (
                <option key={status || 'all'} value={status}>
                  {status || 'Tümü'}
                </option>
              ))}
            </select>
          </label>
        </div>

        <div className="flex flex-col gap-3 sm:flex-row">
          <Button variant="outline" type="button" onClick={onReset}>
            Temizle
          </Button>
        </div>
      </div>
    </section>
  )
}
