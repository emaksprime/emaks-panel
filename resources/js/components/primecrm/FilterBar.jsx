import { RefreshCw, Search } from 'lucide-react';

export function FilterBar({ filters, setFilters, onRefresh, loading, mode }) {
    return (
        <section className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm lg:grid-cols-[1fr_auto_auto_auto] lg:items-end">
            <label className="grid gap-1 text-sm font-semibold text-slate-700">
                Arama
                <span className="relative">
                    <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                    <input
                        value={filters.search}
                        onChange={(event) => setFilters((current) => ({ ...current, search: event.target.value, page: 1 }))}
                        placeholder={mode === 'stock' ? 'Stok kodu, ürün, model ara' : 'Cari kodu, ünvan, evrak veya ürün ara'}
                        className="h-11 w-full rounded-xl border border-slate-200 pl-9 pr-3 font-normal"
                    />
                </span>
            </label>
            <label className="grid gap-1 text-sm font-semibold text-slate-700">
                Başlangıç
                <input
                    type="date"
                    value={filters.date_from}
                    onChange={(event) => setFilters((current) => ({ ...current, date_from: event.target.value, page: 1 }))}
                    className="h-11 rounded-xl border border-slate-200 px-3 font-normal"
                />
            </label>
            <label className="grid gap-1 text-sm font-semibold text-slate-700">
                Bitiş
                <input
                    type="date"
                    value={filters.date_to}
                    onChange={(event) => setFilters((current) => ({ ...current, date_to: event.target.value, page: 1 }))}
                    className="h-11 rounded-xl border border-slate-200 px-3 font-normal"
                />
            </label>
            <button
                type="button"
                onClick={onRefresh}
                disabled={loading}
                className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white disabled:opacity-60"
            >
                <RefreshCw className={['size-4', loading ? 'animate-spin' : ''].join(' ')} />
                Yenile
            </button>
        </section>
    );
}
