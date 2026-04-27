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
    const [data, setData] = useState(salesMainData);
    const [filters, setFilters] = useState(() => ({
        date_from: salesMainData?.filters?.dateFrom,
        date_to: salesMainData?.filters?.dateTo,
        grain: salesMainData?.filters?.grain ?? config?.defaults?.grain ?? 'week',
        detail_type: salesMainData?.filters?.detailType ?? config?.defaults?.detailType ?? 'cari',
        scope_key: salesMainData?.filters?.scopeKey ?? config?.defaults?.scopeKey ?? 'all',
    }));
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(false);

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
    }, [filters]);

    const updateFilters = (patch) => {
        setFilters((current) => ({ ...current, ...patch }));
    };

    return (
        <>
            <Head title="Satış Yönetimi" />
            <main className="grid gap-5 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                            {config?.page?.routePath}
                        </p>
                        <h1 className="mt-1 text-2xl font-semibold text-slate-950 [font-family:var(--font-display)]">
                            {config?.page?.title}
                        </h1>
                        <p className="mt-1 max-w-3xl text-sm text-slate-600">
                            {config?.page?.description}
                        </p>
                        <div className="mt-3 inline-flex rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700">
                            {data?.queryMeta?.notice ?? 'Önizleme verisi - canlı veri bağlı değil'}
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={() => updateFilters({ ...filters })}
                        className="inline-flex items-center gap-2 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm"
                    >
                        <RefreshCw className={`size-4 ${loading ? 'animate-spin' : ''}`} />
                        Yenile
                    </button>
                </div>

                <section className="grid gap-4 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <ManagementScopeFilter
                            scopes={config?.managementScopes ?? []}
                            activeKey={filters.scope_key}
                            onChange={updateFilters}
                            loading={loading}
                        />
                        <div className="flex rounded-md border border-slate-200 bg-slate-50 p-1">
                            {(config?.detailModes ?? []).map((mode) => (
                                <button
                                    key={mode.key}
                                    type="button"
                                    onClick={() => updateFilters({ detail_type: mode.key })}
                                    className={[
                                        'rounded px-3 py-2 text-sm font-semibold transition',
                                        filters.detail_type === mode.key
                                            ? 'bg-white text-slate-950 shadow-sm'
                                            : 'text-slate-500 hover:text-slate-900',
                                    ].join(' ')}
                                >
                                    {mode.label}
                                </button>
                            ))}
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
                    <div className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm font-medium text-red-700">
                        {error}
                    </div>
                )}

                <KpiCards items={data?.kpis ?? []} />

                <div className="grid gap-5 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
                    <SalesPieChart chart={data?.chart} />
                    <SalesBreakdown breakdown={data?.breakdown} table={data?.table} />
                </div>
            </main>
        </>
    );
}
