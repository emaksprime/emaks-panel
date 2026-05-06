import { Search } from 'lucide-react';

const BRAND_OPTIONS = [
    { value: 'all', label: 'TÜMÜ' },
    { value: 'philips', label: 'PHILIPS' },
    { value: 'emaks_prime', label: 'EMAKS PRIME' },
];

const CATEGORY_OPTIONS = [
    { value: 'all', label: 'Tüm Kategoriler' },
    { value: 'A1', label: 'A1 - AKILLI KİLİT' },
    { value: 'AS1', label: 'AS1 - AKILLI SİLİNDİR' },
    { value: 'D1', label: 'D1 - AKILLI DÜRBÜN' },
    { value: 'G1', label: 'G1 - GÜVENLİK KASASI' },
    { value: 'K1', label: 'K1 - KABİN KİLİDİ' },
    { value: 'KA1', label: 'KA1 - KOLLU AKILLI KİLİT' },
    { value: 'M1', label: 'M1 - MEKANİK KAPI KOLU' },
    { value: 'O1', label: 'O1 - OTEL TİPİ' },
    { value: 'OT1', label: 'OT1 - OTEL TİPİ AKSESUARLARI' },
    { value: 'YM1', label: 'YM1 - YÜZEY MONTAJLI KİLİT CAM VS.' },
];

export function ProductFilter({
    brandFilter = 'all',
    categoryFilter = 'all',
    productFilter = '',
    onChange,
    loading,
}) {
    const update = (patch) => onChange({ ...patch, bypass_cache: true });

    return (
        <div className="grid gap-3">
            <label className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">
                Ürün Filtresi
            </label>
            <div className="grid gap-3 lg:grid-cols-[minmax(0,1fr)_220px_280px]">
                <div className="relative">
                    <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                    <input
                        type="search"
                        value={productFilter}
                        disabled={loading}
                        onChange={(event) => update({ product_filter: event.target.value })}
                        placeholder="Ürün, model veya stok kodu ara"
                        className="h-11 w-full rounded-xl border border-slate-200 bg-white pl-10 pr-3 text-sm font-medium text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-blue-300 focus:ring-4 focus:ring-blue-50"
                    />
                </div>
                <select
                    value={brandFilter || 'all'}
                    disabled={loading}
                    onChange={(event) => update({ brand_filter: event.target.value })}
                    className="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 outline-none transition focus:border-blue-300 focus:ring-4 focus:ring-blue-50"
                    aria-label="Marka filtresi"
                >
                    {BRAND_OPTIONS.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </select>
                <select
                    value={categoryFilter || 'all'}
                    disabled={loading}
                    onChange={(event) => update({ category_filter: event.target.value })}
                    className="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 outline-none transition focus:border-blue-300 focus:ring-4 focus:ring-blue-50"
                    aria-label="Kategori filtresi"
                >
                    {CATEGORY_OPTIONS.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </select>
            </div>
        </div>
    );
}
