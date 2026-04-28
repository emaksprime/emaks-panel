import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { RefreshCw } from 'lucide-react';
import { apiRequest } from '@/lib/api';
import { DateRangeFilter } from '@/components/sales-main/DateRangeFilter.jsx';
import { ManagementScopeFilter } from '@/components/sales-main/ManagementScopeFilter.jsx';
import { KpiCards } from '@/components/sales-main/KpiCards.jsx';
import { SalesPieChart } from '@/components/sales-main/SalesPieChart.jsx';
import { SalesBreakdown } from '@/components/sales-main/SalesBreakdown.jsx';

export default function SalesMainDashboard({ salesMainConfig, salesMainData }) {
    const [config] = useState(salesMainConfig);
    const today = new Date().toISOString().slice(0, 10);
    const monthStart = new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10);
    const [data, setData] = useState(salesMainData);
    const [filters, setFilters] = useState(() => ({
        date_from: salesMainData?.filters?.dateFrom ?? monthStart,
        date_to: salesMainData?.filters?.dateTo ?? today,
        grain: salesMainData?.filters?.grain ?? config?.defaults?.grain ?? 'week',
        detail_type: salesMainData?.filters?.detailType ?? config?.defaults?.detailType ?? 'cari',
        scope_key: salesMainData?.filters?.scopeKey ?? config?.defaults?.scopeKey ?? 'all',
        bypass_cache: false,
    }));
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(false);
    const pageTitle = 'Satış Yönetimi';
    const pageDescription = 'Satış performansı ve müşteri/ürün özeti';

    useEffect(() => {
        let active = true;

        async function load() {
            try {
                setLoading(true);
                setError(null);
                const nextData = await apiRequest('/api/data/sales-main', {
                    method: 'POST',
                    body: JSON.stringify(filters),
                });

                if (active) {
                    setData(nextData);
                }
            } catch (caught) {
                if (active) {
                    setError(caught instanceof Error ? caught.message : 'Veri alınamadı.');
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
    }, [filters]);

    const updateFilters = (patch) => {
        setFilters((current) => ({ ...current, ...patch }));
    };

    return (
        <>
            <Head title="Satış Yönetimi" />
            <main className="grid gap-5 bg-[#f3f7fb] p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-blue-700">
                            Emaks Prime
                        </p>
                        <h1 className="mt-2 text-3xl font-semibold text-slate-950 [font-family:var(--font-display)]">
                            {pageTitle}
                        </h1>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                            {pageDescription}
                        </p>
                        {data?.queryMeta?.notice && (
                            <div className="mt-3 inline-flex rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700">
                                {data.queryMeta.notice}
                            </div>
                        )}
                    </div>
                    <button
                        type="button"
                        onClick={() => updateFilters({ bypass_cache: !filters.bypass_cache })}
                        className="inline-flex items-center gap-2 rounded-xl border border-blue-100 bg-white px-4 py-3 text-sm font-semibold text-blue-700 shadow-sm transition hover:border-blue-200 hover:bg-blue-50"
                    >
                        <RefreshCw className={`size-4 ${loading ? 'animate-spin' : ''}`} />
                        Yenile
                    </button>
                </div>

                <section className="grid gap-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm md:p-5">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="grid gap-2">
                            <span className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">
                                Kapsam
                            </span>
                            <ManagementScopeFilter
                                scopes={config?.managementScopes ?? []}
                                activeKey={filters.scope_key}
                                onChange={updateFilters}
                                loading={loading}
                            />
                        </div>
                        <div className="grid gap-2">
                            <span className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">
                                Detay Tipi
                            </span>
                            <div className="flex rounded-xl border border-slate-200 bg-slate-50 p-1">
                                {(config?.detailModes ?? []).map((mode) => (
                                    <button
                                        key={mode.key}
                                        type="button"
                                        onClick={() => updateFilters({ detail_type: mode.key })}
                                        className={[
                                            'rounded-lg px-3 py-2 text-sm font-semibold transition',
                                            filters.detail_type === mode.key
                                                ? 'bg-white text-blue-700 shadow-sm'
                                                : 'text-slate-500 hover:text-slate-900',
                                        ].join(' ')}
                                    >
                                        {mode.label}
                                    </button>
                                ))}
                            </div>
                        </div>
                    </div>
                    <DateRangeFilter
                        config={config}
                        filters={{
                            dateFrom: filters.date_from,
                            dateTo: filters.date_to,
                            grain: filters.grain,
                        }}
                        onChange={updateFilters}
                        loading={loading}
                    />
                </section>

                {error && (
                    <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-700">
                        {error}
                    </div>
                )}

                <KpiCards items={data?.kpis ?? []} />

                <div className="grid gap-5">
                    <SalesPieChart chart={data?.chart} />
                    <SalesBreakdown breakdown={data?.breakdown} table={data?.table} />
                </div>
            </main>
        </>
    );
}
