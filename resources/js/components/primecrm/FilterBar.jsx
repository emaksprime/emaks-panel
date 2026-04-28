import { RefreshCw, Search } from 'lucide-react';

const modeSearchPlaceholder = {
    stock: 'Stok kodu, ürün veya model ara',
    cari: 'Cari kodu, cari adı, firma ünvanı, telefon veya vergi no ara',
    orders: 'Cari, ürün, evrak no veya durum ara',
    proforma: 'Proforma no, cari veya durum ara',
};

export function FilterBar({ filters, setFilters, onRefresh, loading, mode }) {
    return (
        <section className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-[minmax(240px,1fr)_auto_auto_auto] md:items-end">
            <label className="grid gap-1 text-sm font-semibold text-slate-700">
                Arama
                <span className="relative">
                    <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                    <input
                        value={filters.search}
                        onChange={(event) => setFilters((current) => ({ ...current, search: event.target.value, page: 1 }))}
                        placeholder={modeSearchPlaceholder[mode] ?? 'Cari kodu, ünvan, evrak veya ürün ara'}
                        className="h-11 w-full rounded-xl border border-slate-200 bg-white pl-9 pr-3 font-normal text-slate-900 outline-none transition focus:border-blue-400 focus:ring-4 focus:ring-blue-50"
                    />
                </span>
            </label>
            <label className="grid gap-1 text-sm font-semibold text-slate-700">
                Başlangıç
                <input
                    type="date"
                    value={filters.date_from}
                    onChange={(event) => setFilters((current) => ({ ...current, date_from: event.target.value, page: 1 }))}
                    className="h-11 rounded-xl border border-slate-200 bg-white px-3 font-normal outline-none transition focus:border-blue-400 focus:ring-4 focus:ring-blue-50"
                />
            </label>
            <label className="grid gap-1 text-sm font-semibold text-slate-700">
                Bitiş
                <input
                    type="date"
                    value={filters.date_to}
                    onChange={(event) => setFilters((current) => ({ ...current, date_to: event.target.value, page: 1 }))}
                    className="h-11 rounded-xl border border-slate-200 bg-white px-3 font-normal outline-none transition focus:border-blue-400 focus:ring-4 focus:ring-blue-50"
                />
            </label>
            <button
                type="button"
                onClick={onRefresh}
                disabled={loading}
                className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-blue-700 px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-800 disabled:opacity-60"
            >
                <RefreshCw className={['size-4', loading ? 'animate-spin' : ''].join(' ')} />
                Yenile
            </button>
        </section>
    );
}
