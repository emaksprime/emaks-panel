import { Head, Link } from '@inertiajs/react';
import { RefreshCw, Search } from 'lucide-react';
import { useDeferredValue, useEffect, useMemo, useState } from 'react';
import { apiRequest } from '@/lib/api';
import { groupGivenOrders, numberValue, textValue } from './ordersUtils.js';

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

function defaultFilters() {
    return {
        date_from: '2025-01-01',
        date_to: today(),
        search: '',
        status: 'all',
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
        <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
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

function SearchPanel({ filters, setFilters, loading, mode }) {
    const placeholder = mode === 'verilen'
        ? 'Stok adı, stok kodu veya kategori ara'
        : 'Cari adı, ürün, açıklama2 veya sorumluluk kodu ara';

    return (
        <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div className="grid gap-3 md:grid-cols-[minmax(0,1fr)_auto] md:items-center">
                <label className="relative">
                    <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                    <input
                        type="search"
                        value={filters.search}
                        disabled={loading}
                        onChange={(event) => setFilters((current) => ({ ...current, search: event.target.value, page: 1 }))}
                        placeholder={placeholder}
                        className="h-11 w-full rounded-xl border border-slate-200 bg-white pl-10 pr-3 text-sm font-medium text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-blue-300 focus:ring-4 focus:ring-blue-50"
                    />
                </label>
                <button
                    type="button"
                    disabled={loading}
                    onClick={() => setFilters((current) => ({ ...current, bypass_cache: !current.bypass_cache }))}
                    className="inline-flex items-center justify-center gap-2 rounded-xl border border-blue-100 bg-blue-50 px-4 py-3 text-sm font-semibold text-blue-700 transition hover:bg-blue-100 disabled:opacity-60"
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

function summaryForReceived(rows) {
    const approved = rows.filter((row) => approvedGroup(row) === 'approved');
    const pending = rows.filter((row) => approvedGroup(row) === 'pending');
    const sum = (items, key) => items.reduce((total, row) => total + numberValue(row[key]), 0);

    return [
        { label: 'Onaylı Sipariş Miktarı', value: quantity(sum(approved, 'kalan_miktar')) },
        { label: 'Onaylı Sipariş Tutarı', value: money(sum(approved, 'kalan_tutar')) },
        { label: 'Onay Bekleyen Sipariş Miktarı', value: quantity(sum(pending, 'kalan_miktar')) },
        { label: 'Onay Bekleyen Sipariş Tutarı', value: money(sum(pending, 'kalan_tutar')) },
    ];
}

function summaryForGiven(rows, groups) {
    const nearest = groups.find((group) => group.label !== 'TESLİM TARİHİ BELİRSİZ')?.label ?? 'Belirsiz';

    return [
        { label: 'Toplam Sipariş Miktarı', value: quantity(rows.reduce((total, row) => total + numberValue(row.siparis_miktari), 0)) },
        { label: 'Teslim Haftası Sayısı', value: String(groups.length) },
        { label: 'En Yakın Teslim Haftası', value: nearest },
    ];
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
                        <h2 className="mt-1 text-xl font-bold text-slate-950">{group.label}</h2>
                    </div>
                    <div className="hidden md:block">
                        <table className="w-full table-fixed">
                            <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                <tr>
                                    <th className="px-4 py-3">Stok Adı</th>
                                    <th className="w-48 px-4 py-3">Stok Kategori Adı</th>
                                    <th className="w-36 px-4 py-3 text-right">Sipariş Miktarı</th>
                                    <th className="w-32 px-4 py-3">Teslim Tarihi</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 text-sm">
                                {group.rows.map((row, index) => (
                                    <tr key={`${textValue(row, ['stok_kodu'])}-${index}`} className="align-top">
                                        <td className="break-words px-4 py-3 font-semibold text-slate-950">{textValue(row, ['stok_adi'], '-')}</td>
                                        <td className="break-words px-4 py-3 text-slate-600">{textValue(row, ['stok_kategori_adi'], '-')}</td>
                                        <td className="px-4 py-3 text-right font-bold text-slate-950">{quantity(row.siparis_miktari)}</td>
                                        <td className="px-4 py-3 text-slate-600">{textValue(row, ['teslim_tarihi_gosterim', 'teslim_tarihi'], '-')}</td>
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
                                <div className="mt-3 flex items-center justify-between gap-3 text-sm">
                                    <span className="text-slate-500">Sipariş Miktarı</span>
                                    <strong className="text-slate-950">{quantity(row.siparis_miktari)}</strong>
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
    const copy = PAGE_COPY[mode] ?? PAGE_COPY.alinan;
    const slug = mode === 'verilen' ? 'orders_verilen' : 'orders_alinan';

    const signature = `${slug}:${JSON.stringify(deferredFilters)}`;

    useEffect(() => {
        let active = true;

        void apiRequest(`/api/data/${slug}`, {
            method: 'POST',
            body: JSON.stringify({ ...deferredFilters, bypass_cache: true }),
        })
            .then((response) => {
                if (active) {
                    setData({ ...response, signature });
                }
            })
            .catch((caught) => {
                if (active) {
                    setError({
                        signature,
                        message: caught instanceof Error ? caught.message : 'Sipariş verisi alınamadı.',
                    });
                }
            });

        return () => {
            active = false;
        };
    }, [deferredFilters, signature, slug]);

    const activeData = data?.signature === signature ? data : null;
    const activeError = error?.signature === signature ? error.message : null;
    const rows = activeData?.rows ?? [];
    const loading = !activeData && !activeError;
    const givenGroups = useMemo(() => groupGivenOrders(rows), [rows]);
    const cards = mode === 'verilen' ? summaryForGiven(rows, givenGroups) : summaryForReceived(rows);
    const approvedRows = rows.filter((row) => approvedGroup(row) === 'approved');
    const pendingRows = rows.filter((row) => approvedGroup(row) === 'pending');

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

                <SearchPanel filters={filters} setFilters={setFilters} loading={loading} mode={mode} />

                <section className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    {cards.map((card) => <KpiCard key={card.label} {...card} />)}
                </section>

                {activeError && <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-700">{activeError}</div>}
                {loading && <div className="rounded-xl border border-blue-100 bg-blue-50 p-4 text-sm font-semibold text-blue-700">Sipariş verisi yükleniyor...</div>}
                {!loading && !activeError && rows.length === 0 && (
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
