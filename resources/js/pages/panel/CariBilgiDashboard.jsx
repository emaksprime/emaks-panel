import { Head } from '@inertiajs/react';
import { RefreshCw, Search } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { apiRequest } from '@/lib/api';

const toneClasses = {
    positive: 'border-emerald-200 bg-emerald-50 text-emerald-700',
    negative: 'border-rose-200 bg-rose-50 text-rose-700',
    neutral: 'border-slate-200 bg-slate-50 text-slate-700',
};

export default function CariBilgiDashboard({ cariBilgiConfig, cariBilgiData }) {
    const [config] = useState(cariBilgiConfig);
    const [data, setData] = useState(cariBilgiData);
    const [filters, setFilters] = useState(() => ({
        search: cariBilgiData?.filters?.search ?? config?.defaults?.search ?? '',
        scope_key: cariBilgiData?.filters?.scopeKey ?? config?.defaults?.scopeKey ?? 'own',
        limit: cariBilgiData?.filters?.limit ?? config?.defaults?.limit ?? 20,
    }));
    const [draftSearch, setDraftSearch] = useState(filters.search);
    const [refreshKey, setRefreshKey] = useState(0);
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        const timer = window.setTimeout(() => {
            setFilters((current) => ({ ...current, search: draftSearch.trim() }));
        }, 350);

        return () => window.clearTimeout(timer);
    }, [draftSearch]);

    useEffect(() => {
        let active = true;

        async function load() {
            try {
                setLoading(true);
                setError(null);
                const nextData = await apiRequest('/api/data/cari-bilgi', {
                    method: 'POST',
                    body: JSON.stringify(filters),
                });

                if (active) {
                    setData(nextData);
                }
            } catch (caught) {
                if (active) {
                    setError(caught instanceof Error ? caught.message : 'Veri alinamadi');
                }
            } finally {
                if (active) {
                    setLoading(false);
                }
            }
        }

        load();

        return () => {
            active = false;
        };
    }, [filters, refreshKey]);

    const scopeOptions = useMemo(() => config?.scopes ?? [], [config]);
    const rows = data?.rows ?? [];

    return (
        <>
            <Head title="Musteri Bilgi" />
            <main className="grid gap-5 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                            {config?.page?.routePath}
                        </p>
                        <h1 className="mt-1 text-2xl font-semibold text-slate-950 [font-family:var(--font-display)]">
                            Müşteri Bilgi
                        </h1>
                        <p className="mt-1 max-w-3xl text-sm text-slate-600">
                            Cari bakiye, açık sipariş ve genel durum takibi
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={() => setRefreshKey((current) => current + 1)}
                        className="inline-flex items-center gap-2 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm"
                    >
                        <RefreshCw className={`size-4 ${loading ? 'animate-spin' : ''}`} />
                        Yenile
                    </button>
                </div>

                <section className="grid gap-4 rounded-lg border border-slate-200 bg-white p-4 shadow-sm lg:grid-cols-[minmax(260px,1fr)_auto_auto] lg:items-end">
                    <label className="grid gap-2 text-sm font-semibold text-slate-700">
                        Cari kodu veya unvan
                        <span className="relative">
                            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                            <input
                                value={draftSearch}
                                onChange={(event) => setDraftSearch(event.target.value)}
                                placeholder="Ara"
                                className="h-10 w-full rounded-md border border-slate-200 bg-white pl-9 pr-3 text-sm outline-none transition focus:border-slate-400"
                            />
                        </span>
                    </label>
                    <label className="grid gap-2 text-sm font-semibold text-slate-700">
                        Kapsam
                        <select
                            value={filters.scope_key}
                            onChange={(event) => setFilters((current) => ({ ...current, scope_key: event.target.value }))}
                            className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-slate-400"
                        >
                            {scopeOptions.map((scope) => (
                                <option key={scope.key} value={scope.key}>
                                    {scope.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-2 text-sm font-semibold text-slate-700">
                        Limit
                        <select
                            value={filters.limit}
                            onChange={(event) => setFilters((current) => ({ ...current, limit: Number(event.target.value) }))}
                            className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-slate-400"
                        >
                            {(config?.limits ?? [20, 50, 100]).map((limit) => (
                                <option key={limit} value={limit}>
                                    {limit}
                                </option>
                            ))}
                        </select>
                    </label>
                </section>

                {error && (
                    <div className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm font-medium text-red-700">
                        {error}
                    </div>
                )}

                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    {(data?.summary ?? []).map((item) => (
                        <div key={item.key} className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{item.label}</p>
                            <p className="mt-3 text-xl font-semibold text-slate-950">{item.value}</p>
                        </div>
                    ))}
                </section>

                <section className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                    <div className="hidden overflow-x-auto md:block">
                        <table className="min-w-full divide-y divide-slate-200 text-sm">
                            <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                                <tr>
                                    <th className="px-4 py-3">Cari Kodu</th>
                                    <th className="px-4 py-3">Cari Ünvanı</th>
                                    <th className="px-4 py-3 text-right">Bakiye Durumu</th>
                                    <th className="px-4 py-3 text-right">Onaylı Açık Sipariş</th>
                                    <th className="px-4 py-3 text-right">Onay Bekleyen Sipariş</th>
                                    <th className="px-4 py-3 text-right">Genel Durum</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {rows.map((row) => (
                                    <tr key={row.cari_kodu} className="hover:bg-slate-50">
                                        <td className="px-4 py-3 font-mono text-xs font-semibold text-slate-600">{row.cari_kodu}</td>
                                        <td className="px-4 py-3 font-semibold text-slate-950">{row.cari_unvani}</td>
                                        <td className="px-4 py-3 text-right">
                                            <span className="block font-semibold text-slate-900">{row.formatted.bakiye}</span>
                                            <span className="text-xs text-slate-500">{row.bakiye_durumu}</span>
                                        </td>
                                        <td className="px-4 py-3 text-right font-semibold text-slate-700">{row.formatted.onayli_acik_siparis_tutari}</td>
                                        <td className="px-4 py-3 text-right font-semibold text-slate-700">{row.formatted.onay_bekleyen_siparis_tutari}</td>
                                        <td className="px-4 py-3 text-right">
                                            <span className={`inline-flex rounded-md border px-2 py-1 font-semibold ${toneClasses[row.tone] ?? toneClasses.neutral}`}>
                                                {row.formatted.genel_durum}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="grid gap-3 p-3 md:hidden">
                        {rows.map((row) => (
                            <article key={row.cari_kodu} className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <h2 className="text-base font-semibold leading-6 text-slate-950">{row.cari_unvani}</h2>
                                        <span className="mt-2 inline-flex rounded border border-slate-200 bg-slate-50 px-2 py-1 font-mono text-xs font-semibold text-slate-600">
                                            {row.cari_kodu}
                                        </span>
                                    </div>
                                    <span className={`shrink-0 rounded-md border px-2 py-1 text-xs font-semibold ${toneClasses[row.tone] ?? toneClasses.neutral}`}>
                                        {row.formatted.genel_durum}
                                    </span>
                                </div>
                                <dl className="mt-4 grid gap-3 text-sm">
                                    <div className="flex justify-between gap-3">
                                        <dt className="text-slate-500">Bakiye</dt>
                                        <dd className="text-right font-semibold text-slate-900">{row.formatted.bakiye} · {row.bakiye_durumu}</dd>
                                    </div>
                                    <div className="flex justify-between gap-3">
                                        <dt className="text-slate-500">Onaylı açık sipariş</dt>
                                        <dd className="text-right font-semibold text-slate-900">{row.formatted.onayli_acik_siparis_tutari}</dd>
                                    </div>
                                    <div className="flex justify-between gap-3">
                                        <dt className="text-slate-500">Onay bekleyen sipariş</dt>
                                        <dd className="text-right font-semibold text-slate-900">{row.formatted.onay_bekleyen_siparis_tutari}</dd>
                                    </div>
                                </dl>
                            </article>
                        ))}
                    </div>

                    {rows.length === 0 && !loading && (
                        <div className="p-6 text-sm font-medium text-slate-500">Kayit bulunamadi.</div>
                    )}
                </section>
            </main>
        </>
    );
}
