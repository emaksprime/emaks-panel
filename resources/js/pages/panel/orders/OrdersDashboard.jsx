import { Head, Link } from '@inertiajs/react';
import { Check, PieChart, RefreshCw, Search, X } from 'lucide-react';
import { useDeferredValue, useEffect, useMemo, useRef, useState } from 'react';
import { apiRequest } from '@/lib/api';
import {
    brandComparisonForOrderRows,
    brandLabelForKey,
    csvValues,
    deliveryWeekOptionsForRows,
    estimatedWeekLabel,
    filterRowsForOrderDashboard,
    groupGivenOrders,
    numberValue,
    pieItemsForOrderRows,
    productOptionsForRows,
    textValue,
} from './ordersUtils.js';

const PAGE_COPY = {
    alinan: {
        title: 'Alınan Siparişler',
        description: 'Müşteri siparişlerinden açık kalan ürünler',
    },
    verilen: {
        title: 'Verilen Siparişler',
        description: 'Tedarik/gelecek ürün siparişleri',
    },
};

const BRAND_OPTIONS = [
    { value: 'all', label: 'Tümü' },
    { value: 'philips', label: 'PHILIPS' },
    { value: 'emaks_prime', label: 'EMAKS PRIME' },
];

const PIE_COLORS = ['#1d4ed8', '#0891b2', '#059669', '#d97706', '#7c3aed', '#db2777', '#475569', '#0f766e'];

function today() {
    return new Date().toISOString().slice(0, 10);
}

function money(value) {
    return new Intl.NumberFormat('tr-TR', {
        style: 'currency',
        currency: 'TRY',
        maximumFractionDigits: 2,
    }).format(numberValue(value));
}

function quantity(value) {
    return new Intl.NumberFormat('tr-TR', {
        maximumFractionDigits: 2,
    }).format(numberValue(value));
}

function count(value) {
    return new Intl.NumberFormat('tr-TR', {
        maximumFractionDigits: 0,
    }).format(numberValue(value));
}

function deliveryWeekLabel(value) {
    return value === 'TESLÄ°M TARÄ°HÄ° BELÄ°RSÄ°Z' ? 'TESLİM TARİHİ BELİRSİZ' : value;
}

function defaultFilters() {
    return {
        date_from: '2025-01-01',
        date_to: today(),
        search: '',
        status: 'all',
        brand_filter: 'all',
        product_filter: '',
        delivery_week: 'all',
        delivery_date: '',
        limit: 500,
        page: 1,
        bypass_cache: false,
    };
}

function allowedTabs(page) {
    const tabs = (page?.moduleTabs ?? []).filter((tab) => ['/orders/alinan', '/orders/verilen'].includes(tab.href));

    if (tabs.length > 0) {
        return tabs;
    }

    return [{
        label: page?.routePath === '/orders/verilen' ? 'Verilen Siparişler' : 'Alınan Siparişler',
        href: page?.routePath === '/orders/verilen' ? '/orders/verilen' : '/orders/alinan',
    }];
}

function KpiCard({ label, value, hint }) {
    return (
        <div className="rounded-2xl border border-white/80 bg-white/95 p-4 shadow-[0_18px_45px_rgba(15,23,42,0.07)] ring-1 ring-slate-100/80">
            <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{label}</p>
            <p className="mt-2 text-2xl font-bold text-slate-950">{value}</p>
            {hint && <p className="mt-1 text-xs text-slate-500">{hint}</p>}
        </div>
    );
}

function OrderTabs({ page }) {
    const routePath = page?.routePath ?? '';

    return (
        <div className="flex gap-2 overflow-x-auto">
            {allowedTabs(page).map((tab) => {
                const active = tab.href === routePath;

                return (
                    <Link
                        key={tab.href}
                        href={tab.href}
                        className={[
                            'shrink-0 rounded-full border px-4 py-2 text-sm font-semibold transition',
                            active
                                ? 'border-slate-950 bg-slate-950 text-white'
                                : 'border-slate-200 bg-white text-slate-600 hover:border-slate-400 hover:text-slate-950',
                        ].join(' ')}
                    >
                        {tab.label}
                    </Link>
                );
            })}
        </div>
    );
}

function BrandFilter({ value, onChange }) {
    return (
        <label className="grid gap-1 text-sm font-semibold text-slate-700">
            Marka
            <select
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-900 outline-none transition focus:border-blue-300 focus:ring-4 focus:ring-blue-50"
            >
                {BRAND_OPTIONS.map((option) => (
                    <option key={option.value} value={option.value}>{option.label}</option>
                ))}
            </select>
        </label>
    );
}

function ProductPicker({ value, options, onChange }) {
    const [query, setQuery] = useState('');
    const [isOpen, setIsOpen] = useState(false);
    const pickerRef = useRef(null);
    const selectedValues = useMemo(() => csvValues(value), [value]);
    const selectedLookup = useMemo(() => new Set(selectedValues), [selectedValues]);
    const normalizedQuery = query.trim().toLocaleUpperCase('tr');

    const visibleOptions = useMemo(() => options
        .filter((option) => !selectedLookup.has(option.value))
        .filter((option) => normalizedQuery === ''
            || [option.label, option.stockCode, option.brandLabel].join(' ').toLocaleUpperCase('tr').includes(normalizedQuery))
        .slice(0, 12), [normalizedQuery, options, selectedLookup]);

    const optionByValue = useMemo(() => new Map(options.map((option) => [option.value, option])), [options]);

    useEffect(() => {
        const closeOnOutsideClick = (event) => {
            if (pickerRef.current && !pickerRef.current.contains(event.target)) {
                setIsOpen(false);
            }
        };
        const closeOnEscape = (event) => {
            if (event.key === 'Escape') {
                setIsOpen(false);
            }
        };

        document.addEventListener('pointerdown', closeOnOutsideClick);
        document.addEventListener('keydown', closeOnEscape);

        return () => {
            document.removeEventListener('pointerdown', closeOnOutsideClick);
            document.removeEventListener('keydown', closeOnEscape);
        };
    }, []);

    const commit = (nextValues) => {
        onChange(nextValues.join(', '));
    };

    return (
        <div ref={pickerRef} className="relative grid gap-2">
            <label className="grid gap-1 text-sm font-semibold text-slate-700">
                Ürün / Model
                <div className="relative">
                    <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                    <input
                        type="search"
                        value={query}
                        aria-expanded={isOpen}
                        onFocus={() => setIsOpen(true)}
                        onClick={() => setIsOpen(true)}
                        onChange={(event) => {
                            setQuery(event.target.value);
                            setIsOpen(true);
                        }}
                        placeholder="Ürün, model veya stok kodu ara"
                        className="h-10 w-full rounded-xl border border-slate-200 bg-white pl-10 pr-3 text-sm font-medium text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-blue-300 focus:ring-4 focus:ring-blue-50"
                    />
                </div>
            </label>

            {selectedValues.length > 0 && (
                <div className="flex max-h-16 flex-wrap gap-2 overflow-y-auto pr-1">
                    {selectedValues.map((selected) => {
                        const option = optionByValue.get(selected);

                        return (
                            <button
                                key={selected}
                                type="button"
                                onClick={() => commit(selectedValues.filter((item) => item !== selected))}
                                className="inline-flex max-w-[260px] items-center gap-2 rounded-full border border-blue-100 bg-blue-50 px-3 py-1.5 text-xs font-semibold text-blue-800 shadow-sm"
                            >
                                <span className="truncate">{option?.label ?? selected}</span>
                                <X className="size-3.5" />
                            </button>
                        );
                    })}
                </div>
            )}

            {isOpen && (
                <div className="absolute left-0 right-0 top-full z-40 mt-2 max-h-72 overflow-y-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-2xl shadow-slate-900/12 ring-1 ring-slate-100">
                    {visibleOptions.length === 0 ? (
                        <p className="px-2 py-3 text-sm text-slate-500">Seçilebilir ürün bulunamadı.</p>
                    ) : visibleOptions.map((option) => (
                        <button
                            key={option.value}
                            type="button"
                            onClick={() => {
                                commit([...selectedValues, option.value]);
                                setQuery('');
                                setIsOpen(true);
                            }}
                            className="flex w-full items-center justify-between gap-3 rounded-xl px-3 py-2.5 text-left transition hover:bg-blue-50"
                        >
                            <span className="min-w-0">
                                <span className="block truncate text-sm font-bold text-slate-900">{option.label}</span>
                                <span className="mt-0.5 block text-xs text-slate-500">
                                    {brandLabelForKey(option.brandKey)} · {quantity(option.quantity)} adet · {money(option.amount)}
                                </span>
                            </span>
                            <span className="grid size-6 shrink-0 place-items-center rounded-lg border border-blue-200 bg-white text-blue-600 shadow-sm">
                                <Check className="size-3.5" />
                            </span>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

function DeliveryWeekFilter({ filters, setFilters, weekOptions }) {
    if (weekOptions.length === 0) {
        return null;
    }

    return (
        <section className="grid gap-3 rounded-2xl border border-blue-100 bg-white/95 p-3 shadow-[0_18px_45px_rgba(15,23,42,0.06)] ring-1 ring-slate-100/80">
            <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-[0.16em] text-blue-700">Teslim Haftası</p>
                    <p className="mt-1 text-xs text-slate-500">Hafta havuzu sabit kalır; tarih yalnızca aktif haftayı seçer.</p>
                </div>
                <label className="grid gap-1 text-xs font-bold uppercase tracking-[0.12em] text-slate-500 lg:w-56">
                    Tarihten hafta seç
                    <input
                        type="date"
                        value={filters.delivery_date}
                        onChange={(event) => {
                            const value = event.target.value;

                            setFilters((current) => ({
                                ...current,
                                delivery_date: value,
                                delivery_week: value ? estimatedWeekLabel(value) : 'all',
                                page: 1,
                                bypass_cache: true,
                            }));
                        }}
                        className="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-900 outline-none transition focus:border-blue-300 focus:ring-4 focus:ring-blue-50"
                    />
                </label>
            </div>
            <div className="flex gap-2 overflow-x-auto pb-1 [scrollbar-width:thin]">
                <button
                    type="button"
                    onClick={() => setFilters((current) => ({ ...current, delivery_week: 'all', delivery_date: '', page: 1, bypass_cache: true }))}
                    className={[
                        'shrink-0 rounded-full border px-4 py-2 text-sm font-bold transition',
                        filters.delivery_week === 'all'
                            ? 'border-slate-950 bg-slate-950 text-white shadow-lg shadow-slate-900/15'
                            : 'border-slate-200 bg-white text-slate-600 hover:border-slate-400',
                    ].join(' ')}
                >
                    Tümü
                </button>
                {weekOptions.map((option) => (
                    <button
                        key={option.value}
                        type="button"
                        onClick={() => setFilters((current) => ({ ...current, delivery_week: option.value, delivery_date: '', page: 1, bypass_cache: true }))}
                        className={[
                            'shrink-0 rounded-full border px-4 py-2 text-sm font-bold transition',
                            filters.delivery_week === option.value
                                ? 'border-blue-700 bg-blue-700 text-white shadow-lg shadow-blue-700/20'
                                : 'border-blue-100 bg-blue-50 text-blue-700 hover:bg-blue-100',
                        ].join(' ')}
                    >
                        {deliveryWeekLabel(option.label)}
                    </button>
                ))}
            </div>
        </section>
    );
}

function SearchPanel({ filters, setFilters, loading, mode, productOptions }) {
    const placeholder = mode === 'verilen'
        ? 'Stok adı, stok kodu veya kategori ara'
        : 'Cari adı, ürün, açıklama2 veya sorumluluk kodu ara';

    return (
        <section className="relative z-20 rounded-2xl border border-white/80 bg-white/95 p-3 shadow-[0_18px_45px_rgba(15,23,42,0.06)] ring-1 ring-slate-100/80 md:p-4">
            <div className="grid gap-3 xl:grid-cols-[minmax(280px,1fr)_180px_minmax(320px,0.95fr)_auto] xl:items-start">
                <label className="relative grid gap-1 text-sm font-semibold text-slate-700">
                    Genel Arama
                    <span className="relative">
                        <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                        <input
                            type="search"
                            value={filters.search}
                            onChange={(event) => setFilters((current) => ({ ...current, search: event.target.value, page: 1, bypass_cache: true }))}
                            placeholder={placeholder}
                            className="h-10 w-full rounded-xl border border-slate-200 bg-white pl-10 pr-3 text-sm font-medium text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-blue-300 focus:ring-4 focus:ring-blue-50"
                        />
                    </span>
                </label>

                <BrandFilter
                    value={filters.brand_filter}
                    onChange={(value) => setFilters((current) => ({ ...current, brand_filter: value, product_filter: '', page: 1, bypass_cache: true }))}
                />

                <ProductPicker
                    value={filters.product_filter}
                    options={productOptions}
                    onChange={(value) => setFilters((current) => ({ ...current, product_filter: value, page: 1, bypass_cache: true }))}
                />

                <button
                    type="button"
                    onClick={() => setFilters((current) => ({ ...current, bypass_cache: !current.bypass_cache }))}
                    className="inline-flex h-10 items-center justify-center gap-2 self-end rounded-xl border border-blue-100 bg-blue-50 px-4 text-sm font-bold text-blue-700 transition hover:bg-blue-100 xl:min-w-28"
                >
                    <RefreshCw className={`size-4 ${loading ? 'animate-spin' : ''}`} />
                    Yenile
                </button>
            </div>
        </section>
    );
}

function approvedGroup(row) {
    const group = textValue(row, ['siparis_grubu']);
    const serial = textValue(row, ['sip_evrakno_seri']);

    return serial === 'B' || group.toLocaleLowerCase('tr').includes('bekleyen') ? 'pending' : 'approved';
}

function summaryForReceived(approvedRows, pendingRows) {
    const sum = (items, key) => items.reduce((total, row) => total + numberValue(row[key]), 0);

    return [
        { label: 'Onaylı Açık Sipariş Satırı', value: count(approvedRows.length) },
        { label: 'Onaylı Sipariş Tutarı', value: money(sum(approvedRows, 'kalan_tutar')) },
        { label: 'Onay Bekleyen Açık Sipariş Satırı', value: count(pendingRows.length) },
        { label: 'Onay Bekleyen Sipariş Tutarı', value: money(sum(pendingRows, 'kalan_tutar')) },
    ];
}

function summaryForGiven(rows, groups) {
    const nearest = deliveryWeekLabel(groups.find((group) => group.label !== 'TESLÄ°M TARÄ°HÄ° BELÄ°RSÄ°Z')?.label ?? 'Belirsiz');

    return [
        { label: 'Toplam Sipariş Miktarı', value: quantity(rows.reduce((total, row) => total + numberValue(row.siparis_miktari), 0)) },
        { label: 'Teslim Haftası Sayısı', value: String(groups.length) },
        { label: 'En Yakın Teslim Haftası', value: nearest },
    ];
}

function BrandComparisonStrip({ items }) {
    if (items.length === 0) {
        return null;
    }

    return (
        <section className="grid gap-4 rounded-3xl border border-white/80 bg-white/95 p-4 shadow-[0_24px_70px_rgba(15,23,42,0.08)] ring-1 ring-slate-100/80">
            <div className="flex flex-col gap-1 md:flex-row md:items-end md:justify-between">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-[0.16em] text-blue-700">Marka Karşılaştırması</p>
                    <p className="mt-1 text-sm text-slate-500">Filtreli siparişlerde marka bazlı adet ve tutar dağılımı.</p>
                </div>
                <p className="text-xs font-semibold text-slate-400">Adet, tutar ve pay</p>
            </div>
            <div className="grid gap-3 md:grid-cols-3">
                {items.map((item, index) => (
                    <article key={item.key} className="overflow-hidden rounded-2xl border border-slate-100 bg-gradient-to-br from-slate-50 via-white to-blue-50/60 p-4 shadow-sm">
                        <div className="flex items-center justify-between gap-3">
                            <h3 className="text-sm font-black text-slate-950">{item.label}</h3>
                            <span className="rounded-full bg-white px-2.5 py-1 text-xs font-black text-blue-700 shadow-sm">%{item.percentage.toFixed(1)}</span>
                        </div>
                        <div className="mt-4 grid gap-2">
                            <p className="text-2xl font-black tracking-tight text-slate-950">{money(item.amount)}</p>
                            <p className="inline-flex w-fit rounded-full bg-white px-2.5 py-1 text-sm font-bold text-slate-600 shadow-sm">{quantity(item.quantity)} adet</p>
                        </div>
                        <div className="mt-4 h-2.5 overflow-hidden rounded-full bg-white shadow-inner">
                            <div
                                className="h-full rounded-full"
                                style={{
                                    width: `${Math.min(100, Math.max(0, item.percentage))}%`,
                                    backgroundColor: PIE_COLORS[index % PIE_COLORS.length],
                                }}
                            />
                        </div>
                    </article>
                ))}
            </div>
        </section>
    );
}

function OrdersPieChart({ title, items }) {
    const total = items.reduce((sum, item) => sum + item.quantity, 0);
    let cursor = 0;
    const gradient = items.length === 0
        ? '#e2e8f0 0 100%'
        : items.map((item, index) => {
            const start = cursor;
            const end = cursor + item.percentage;
            cursor = end;

            return `${PIE_COLORS[index % PIE_COLORS.length]} ${start}% ${end}%`;
        }).join(', ');

    return (
        <section className="rounded-3xl border border-white/80 bg-white/95 p-5 shadow-[0_24px_70px_rgba(15,23,42,0.08)] ring-1 ring-slate-100/80">
            <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div className="flex items-center gap-3">
                    <span className="grid size-10 place-items-center rounded-2xl bg-blue-50 text-blue-700">
                        <PieChart className="size-5" />
                    </span>
                    <div>
                        <h2 className="text-lg font-bold text-slate-950">{title}</h2>
                        <p className="text-sm text-slate-500">Ürün/model bazlı açık sipariş dağılımı</p>
                    </div>
                </div>
                {items.length > 0 && <span className="w-fit rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">{items.length} model</span>}
            </div>

            {items.length === 0 ? (
                <div className="mt-4 rounded-xl border border-dashed border-slate-200 bg-slate-50 p-5 text-sm text-slate-500">Grafik için uygun sipariş satırı yok.</div>
            ) : (
                <div className="mt-5 grid gap-6 lg:grid-cols-[240px_minmax(0,1fr)] lg:items-center">
                    <div className="mx-auto grid size-52 place-items-center rounded-full p-3 shadow-[0_20px_55px_rgba(29,78,216,0.16)]" style={{ background: `conic-gradient(${gradient})` }}>
                        <div className="grid size-32 place-items-center rounded-full bg-white text-center shadow-inner">
                            <span>
                                <span className="block text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Toplam</span>
                                <span className="block text-xl font-bold text-slate-950">{quantity(total)}</span>
                            </span>
                        </div>
                    </div>
                    <div className="grid gap-2">
                        {items.map((item, index) => (
                            <div key={item.label} className="grid gap-2 rounded-2xl border border-slate-100 bg-slate-50/80 p-3 transition hover:bg-white hover:shadow-sm md:grid-cols-[minmax(0,1fr)_auto] md:items-center">
                                <div className="flex min-w-0 items-center gap-2">
                                    <span className="size-3 shrink-0 rounded-full" style={{ backgroundColor: PIE_COLORS[index % PIE_COLORS.length] }} />
                                    <span className="truncate text-sm font-bold text-slate-900">{item.label}</span>
                                </div>
                                <div className="flex flex-wrap justify-start gap-2 text-xs font-bold text-slate-700 md:justify-end">
                                    <span className="rounded-full bg-white px-2 py-1 shadow-sm">{quantity(item.quantity)} adet</span>
                                    <span className="rounded-full bg-white px-2 py-1 shadow-sm">{money(item.amount)}</span>
                                    <span className="rounded-full bg-blue-50 px-2 py-1 text-blue-700">%{item.percentage.toFixed(1)}</span>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </section>
    );
}

function ReceivedTable({ title, rows }) {
    return (
        <section className="rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div className="border-b border-slate-100 p-4">
                <h2 className="text-lg font-bold text-slate-950">{title}</h2>
                <p className="mt-1 text-sm text-slate-500">{rows.length} açık sipariş satırı</p>
            </div>

            <div className="hidden md:block">
                <table className="w-full table-fixed">
                    <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                        <tr>
                            <th className="w-28 px-4 py-3">Sipariş Tarihi</th>
                            <th className="px-4 py-3">Cari Adı</th>
                            <th className="px-4 py-3">Ürün</th>
                            <th className="w-32 px-4 py-3">Marka</th>
                            <th className="px-4 py-3">Açıklama2</th>
                            <th className="w-24 px-4 py-3 text-right">Kalan</th>
                            <th className="w-20 px-4 py-3">Birim</th>
                            <th className="w-32 px-4 py-3 text-right">Tutar</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 text-sm">
                        {rows.map((row, index) => (
                            <tr key={`${textValue(row, ['sip_evrakno_seri'])}-${textValue(row, ['sip_evrakno_sira'])}-${index}`} className="align-top">
                                <td className="px-4 py-3 font-semibold text-slate-700">{textValue(row, ['siparis_tarihi'], '-')}</td>
                                <td className="break-words px-4 py-3 font-semibold text-slate-950">{textValue(row, ['cari_adi'], '-')}</td>
                                <td className="break-words px-4 py-3 text-slate-700">{textValue(row, ['urun_adi'], '-')}</td>
                                <td className="px-4 py-3 text-slate-600">{textValue(row, ['marka', 'brand_code'], '-')}</td>
                                <td className="break-words px-4 py-3 text-slate-600">{textValue(row, ['sip_aciklama2'], '-')}</td>
                                <td className="px-4 py-3 text-right font-semibold text-slate-900">{quantity(row.kalan_miktar)}</td>
                                <td className="px-4 py-3 text-slate-600">{textValue(row, ['birim'], 'Adet')}</td>
                                <td className="px-4 py-3 text-right font-bold text-slate-950">{money(row.kalan_tutar)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="grid gap-3 p-4 md:hidden">
                {rows.map((row, index) => (
                    <article key={`${textValue(row, ['sip_evrakno_seri'])}-${textValue(row, ['sip_evrakno_sira'])}-${index}`} className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <p className="text-xs font-semibold text-slate-500">{textValue(row, ['siparis_tarihi'], '-')}</p>
                                <h3 className="mt-1 break-words text-base font-bold text-slate-950">{textValue(row, ['cari_adi'], '-')}</h3>
                                <p className="mt-2 break-words text-sm text-slate-700">{textValue(row, ['urun_adi'], '-')}</p>
                                <p className="mt-1 text-xs font-semibold text-blue-700">{textValue(row, ['marka', 'brand_code'], '-')}</p>
                            </div>
                            <div className="text-right">
                                <p className="text-xs text-slate-500">Kalan</p>
                                <p className="font-bold text-slate-950">{quantity(row.kalan_miktar)}</p>
                            </div>
                        </div>
                        <div className="mt-3 grid gap-2 text-sm text-slate-600">
                            <p><strong>Açıklama2:</strong> {textValue(row, ['sip_aciklama2'], '-')}</p>
                            <p><strong>Tutar:</strong> {money(row.kalan_tutar)}</p>
                        </div>
                    </article>
                ))}
            </div>
        </section>
    );
}

function GivenGroups({ groups }) {
    return (
        <div className="grid gap-4">
            {groups.map((group) => (
                <section key={group.label} className="rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div className="border-b border-slate-100 p-4">
                        <p className="text-xs font-semibold uppercase tracking-[0.16em] text-blue-700">Tahmini Teslim Haftası</p>
                        <h2 className="mt-1 text-xl font-bold text-slate-950">{deliveryWeekLabel(group.label)}</h2>
                    </div>
                    <div className="hidden md:block">
                        <table className="w-full table-fixed">
                            <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                <tr>
                                    <th className="px-4 py-3">Stok Adı</th>
                                    <th className="w-48 px-4 py-3">Stok Kategori Adı</th>
                                    <th className="w-32 px-4 py-3">Marka</th>
                                    <th className="w-36 px-4 py-3 text-right">Sipariş Miktarı</th>
                                    <th className="w-40 px-4 py-3">Tahmini Teslim Haftası</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 text-sm">
                                {group.rows.map((row, index) => (
                                    <tr key={`${textValue(row, ['stok_kodu'])}-${index}`} className="align-top">
                                        <td className="break-words px-4 py-3 font-semibold text-slate-950">{textValue(row, ['stok_adi'], '-')}</td>
                                        <td className="break-words px-4 py-3 text-slate-600">{textValue(row, ['stok_kategori_adi'], '-')}</td>
                                        <td className="px-4 py-3 text-slate-600">{textValue(row, ['marka', 'brand_code'], '-')}</td>
                                        <td className="px-4 py-3 text-right font-bold text-slate-950">{quantity(row.siparis_miktari)}</td>
                                        <td className="px-4 py-3 text-slate-600">
                                            <span className="font-semibold text-slate-900">{deliveryWeekLabel(group.label)}</span>
                                            <span className="mt-1 block text-xs text-slate-400">{textValue(row, ['teslim_tarihi_gosterim', 'teslim_tarihi'], '')}</span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <div className="grid gap-3 p-4 md:hidden">
                        {group.rows.map((row, index) => (
                            <article key={`${textValue(row, ['stok_kodu'])}-${index}`} className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                <h3 className="break-words text-base font-bold text-slate-950">{textValue(row, ['stok_adi'], '-')}</h3>
                                <p className="mt-2 text-sm text-slate-600">{textValue(row, ['stok_kategori_adi'], '-')}</p>
                                <p className="mt-1 text-xs font-semibold text-blue-700">{textValue(row, ['marka', 'brand_code'], '-')}</p>
                                <div className="mt-3 flex items-center justify-between gap-3 text-sm">
                                    <span className="text-slate-500">Sipariş Miktarı</span>
                                    <strong className="text-slate-950">{quantity(row.siparis_miktari)}</strong>
                                </div>
                                <div className="mt-2 text-sm">
                                    <span className="text-slate-500">Tahmini teslim:</span>
                                    <strong className="ml-2 text-slate-950">{deliveryWeekLabel(group.label)}</strong>
                                </div>
                            </article>
                        ))}
                    </div>
                </section>
            ))}
        </div>
    );
}

export default function OrdersDashboard({ page, mode }) {
    const [filters, setFilters] = useState(defaultFilters);
    const deferredFilters = useDeferredValue(filters);
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(true);
    const [productOptionsCache, setProductOptionsCache] = useState({ key: '', options: [] });
    const requestIdRef = useRef(0);
    const copy = PAGE_COPY[mode] ?? PAGE_COPY.alinan;
    const slug = mode === 'verilen' ? 'orders_verilen' : 'orders_alinan';

    const signature = useMemo(() => `${slug}:${JSON.stringify(deferredFilters)}`, [deferredFilters, slug]);

    useEffect(() => {
        const requestId = requestIdRef.current + 1;
        requestIdRef.current = requestId;
        setLoading(true);
        const requestFilters = mode === 'verilen'
            ? { ...deferredFilters, delivery_week: 'all', delivery_date: '' }
            : deferredFilters;

        void apiRequest(`/api/data/${slug}`, {
            method: 'POST',
            body: JSON.stringify({ ...requestFilters, bypass_cache: true }),
        })
            .then((response) => {
                if (requestId === requestIdRef.current) {
                    setData({ ...response, signature });
                    setError(null);
                }
            })
            .catch((caught) => {
                if (requestId === requestIdRef.current) {
                    setError({
                        signature,
                        message: caught instanceof Error ? caught.message : 'Sipariş verisi alınamadı.',
                    });
                }
            })
            .finally(() => {
                if (requestId === requestIdRef.current) {
                    setLoading(false);
                }
            });
    }, [deferredFilters, mode, signature, slug]);

    const rows = data?.rows ?? [];
    const activeError = error?.message ?? null;
    const optionCacheKey = [
        slug,
        filters.brand_filter,
        filters.date_from,
        filters.date_to,
        filters.search,
    ].join('|');
    const liveProductOptions = useMemo(() => productOptionsForRows(rows, filters.brand_filter), [filters.brand_filter, rows]);

    useEffect(() => {
        if (productOptionsCache.key !== optionCacheKey) {
            setProductOptionsCache({ key: optionCacheKey, options: [] });

            return;
        }

        if (filters.product_filter === '' && liveProductOptions.length > 0) {
            setProductOptionsCache({ key: optionCacheKey, options: liveProductOptions });
        }
    }, [filters.product_filter, liveProductOptions, optionCacheKey, productOptionsCache.key]);

    const productOptions = productOptionsCache.key === optionCacheKey && productOptionsCache.options.length > 0
        ? productOptionsCache.options
        : liveProductOptions;
    const visibleRows = useMemo(() => filterRowsForOrderDashboard(rows, filters, mode), [filters, mode, rows]);
    const deliveryWeekOptions = useMemo(() => deliveryWeekOptionsForRows(filterRowsForOrderDashboard(rows, { ...filters, delivery_week: 'all', delivery_date: '' }, 'verilen')), [filters, rows]);
    const givenGroups = useMemo(() => groupGivenOrders(visibleRows), [visibleRows]);
    const approvedRows = visibleRows.filter((row) => approvedGroup(row) === 'approved');
    const pendingRows = visibleRows.filter((row) => approvedGroup(row) === 'pending');
    const cards = mode === 'verilen' ? summaryForGiven(visibleRows, givenGroups) : summaryForReceived(approvedRows, pendingRows);
    const chartItems = useMemo(
        () => (mode === 'verilen'
            ? pieItemsForOrderRows(visibleRows, 'siparis_miktari', 'siparis_tutari')
            : pieItemsForOrderRows(approvedRows, 'kalan_miktar', 'kalan_tutar')),
        [approvedRows, mode, visibleRows],
    );
    const brandComparisonItems = useMemo(
        () => (mode === 'verilen'
            ? brandComparisonForOrderRows(visibleRows, 'siparis_miktari', 'siparis_tutari')
            : brandComparisonForOrderRows(approvedRows, 'kalan_miktar', 'kalan_tutar')),
        [approvedRows, mode, visibleRows],
    );

    return (
        <>
            <Head title={copy.title} />
            <main className="grid gap-5 bg-[#f3f7fb] p-4 md:p-6">
                <section className="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-start">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-[0.18em] text-blue-700">Emaks Prime Operasyon Paneli</p>
                            <h1 className="mt-2 text-3xl font-bold text-slate-950">{copy.title}</h1>
                            <p className="mt-2 text-sm leading-6 text-slate-600">{copy.description}</p>
                        </div>
                        <OrderTabs page={page} />
                    </div>
                </section>

                <SearchPanel filters={filters} setFilters={setFilters} loading={loading} mode={mode} productOptions={productOptions} />

                {mode === 'verilen' && (
                    <DeliveryWeekFilter filters={filters} setFilters={setFilters} weekOptions={deliveryWeekOptions} />
                )}

                <section className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    {cards.map((card) => <KpiCard key={card.label} {...card} />)}
                </section>

                <BrandComparisonStrip items={brandComparisonItems} />

                <OrdersPieChart
                    title={mode === 'verilen' ? 'Verilen Sipariş Ürün Dağılımı' : 'Onaylı Alınan Sipariş Ürün Dağılımı'}
                    items={chartItems}
                />

                {activeError && <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-700">{activeError}</div>}
                {loading && <div className="rounded-xl border border-blue-100 bg-blue-50 p-4 text-sm font-semibold text-blue-700">Sipariş verisi yenileniyor...</div>}
                {!loading && !activeError && visibleRows.length === 0 && (
                    <div className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-600">Seçili filtrelerde açık sipariş bulunamadı.</div>
                )}

                {mode === 'verilen' ? (
                    <GivenGroups groups={givenGroups} />
                ) : (
                    <div className="grid gap-5">
                        <ReceivedTable title="Onaylı Siparişler" rows={approvedRows} />
                        <ReceivedTable title="Onay Bekleyen Siparişler" rows={pendingRows} />
                    </div>
                )}
            </main>
        </>
    );
}
