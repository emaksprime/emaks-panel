import { Head } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { RefreshCw } from 'lucide-react';
import { apiRequest } from '@/lib/api';
import { DateRangeFilter } from '@/components/sales-main/DateRangeFilter.jsx';
import { ManagementScopeFilter } from '@/components/sales-main/ManagementScopeFilter.jsx';
import { KpiCards } from '@/components/sales-main/KpiCards.jsx';
import { SalesPieChart } from '@/components/sales-main/SalesPieChart.jsx';
import { SalesBreakdown } from '@/components/sales-main/SalesBreakdown.jsx';
import { CustomerFilterPicker } from '@/components/sales-main/CustomerFilterPicker.jsx';
import { ProductFilter } from '@/components/sales-main/ProductFilter.jsx';
import { BrandComparisonStrip } from '@/components/sales-main/BrandComparisonStrip.jsx';

function queryParam(name) {
    if (typeof window === 'undefined') {
        return null;
    }

    return new URLSearchParams(window.location.search).get(name);
}

function formatDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

function resolvePeriodRange(grain) {
    const today = new Date();
    const end = new Date(today.getFullYear(), today.getMonth(), today.getDate());
    const start = new Date(today.getFullYear(), today.getMonth(), today.getDate());

    if (grain === 'week') {
        const day = start.getDay();
        const diff = day === 0 ? 6 : day - 1;
        start.setDate(start.getDate() - diff);
    } else if (grain === 'month') {
        start.setDate(1);
    } else if (grain === 'year') {
        start.setMonth(0, 1);
    } else if (grain === 'day') {
        // keep today for both start and end
    }

    return {
        dateFrom: formatDate(start),
        dateTo: formatDate(end),
    };
}

const RESPONSE_SIGNATURE_KEYS = [
    'detail_type',
    'scope_key',
    'date_from',
    'date_to',
    'brand_filter',
    'category_filter',
    'product_filter',
    'customer_filter',
];

function normalizeSignatureValue(value, fallback = '') {
    return String(value ?? fallback).trim();
}

function normalizeChoiceSignatureValue(value) {
    const normalized = normalizeSignatureValue(value, 'all').toLowerCase();

    return normalized === '' ? 'all' : normalized;
}

function responseFilterValue(payload, filterKey, paramKey, fallback = '') {
    const responseFilters = payload?.filters ?? {};
    const gatewayRequest = payload?.queryMeta?.gatewayRequest ?? {};
    const gatewayParams = gatewayRequest?.params ?? {};

    return responseFilters[filterKey] ?? gatewayParams[paramKey] ?? gatewayRequest[paramKey] ?? fallback;
}

function requestSignature(filters) {
    return {
        detail_type: normalizeSignatureValue(filters.detail_type, 'cari'),
        scope_key: normalizeSignatureValue(filters.scope_key, 'all'),
        date_from: normalizeSignatureValue(filters.date_from),
        date_to: normalizeSignatureValue(filters.date_to),
        brand_filter: normalizeChoiceSignatureValue(filters.brand_filter),
        category_filter: normalizeChoiceSignatureValue(filters.category_filter),
        product_filter: normalizeSignatureValue(filters.product_filter),
        customer_filter: normalizeSignatureValue(filters.customer_filter || filters.cari_filter),
    };
}

function responseSignature(payload) {
    return {
        detail_type: normalizeSignatureValue(responseFilterValue(payload, 'detailType', 'detail_type', 'cari')),
        scope_key: normalizeSignatureValue(responseFilterValue(payload, 'scopeKey', 'scope_key', 'all')),
        date_from: normalizeSignatureValue(responseFilterValue(payload, 'dateFrom', 'date_from')),
        date_to: normalizeSignatureValue(responseFilterValue(payload, 'dateTo', 'date_to')),
        brand_filter: normalizeChoiceSignatureValue(responseFilterValue(payload, 'brandFilter', 'brand_filter', 'all')),
        category_filter: normalizeChoiceSignatureValue(responseFilterValue(payload, 'categoryFilter', 'category_filter', 'all')),
        product_filter: normalizeSignatureValue(responseFilterValue(payload, 'productFilter', 'product_filter')),
        customer_filter: normalizeSignatureValue(
            responseFilterValue(payload, 'customerFilter', 'customer_filter')
                || responseFilterValue(payload, 'customerFilter', 'cari_filter'),
        ),
    };
}

function signaturesMatch(expected, actual) {
    return RESPONSE_SIGNATURE_KEYS.every((key) => expected[key] === actual[key]);
}

export default function SalesMainDashboard({ salesMainConfig, salesMainData }) {
    const config = salesMainConfig;
    const initialGrain = queryParam('grain') ?? salesMainData?.filters?.grain ?? config?.defaults?.grain ?? 'week';
    const initialPeriod = resolvePeriodRange(initialGrain);
    const [data, setData] = useState(salesMainData);
    const [filters, setFilters] = useState(() => ({
        date_from: queryParam('date_from') ?? salesMainData?.filters?.dateFrom ?? initialPeriod.dateFrom,
        date_to: queryParam('date_to') ?? salesMainData?.filters?.dateTo ?? initialPeriod.dateTo,
        grain: queryParam('grain') ?? salesMainData?.filters?.grain ?? config?.defaults?.grain ?? 'week',
        detail_type: queryParam('detail_type') ?? salesMainData?.filters?.detailType ?? config?.defaults?.detailType ?? 'cari',
        scope_key: queryParam('scope_key') ?? salesMainData?.filters?.scopeKey ?? config?.defaults?.scopeKey ?? 'all',
        customer_filter: salesMainData?.filters?.customerFilter ?? '',
        cari_filter: salesMainData?.filters?.customerFilter ?? '',
        brand_filter: queryParam('brand_filter') ?? salesMainData?.filters?.brandFilter ?? 'all',
        category_filter: queryParam('category_filter') ?? salesMainData?.filters?.categoryFilter ?? 'all',
        product_filter: queryParam('product_filter') ?? salesMainData?.filters?.productFilter ?? '',
        bypass_cache: false,
    }));
    const [selectedCustomers, setSelectedCustomers] = useState([]);
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(false);
    const requestIdRef = useRef(0);
    const pageTitle = 'Satış Yönetimi';
    const pageDescription = 'Satış performansı ve müşteri/ürün özeti';

    useEffect(() => {
        setData(salesMainData);
    }, [salesMainData]);

    useEffect(() => {
        let active = true;
        const requestId = requestIdRef.current + 1;
        requestIdRef.current = requestId;
        const expectedSignature = requestSignature(filters);

        async function load() {
            try {
                setLoading(true);
                setError(null);
                const nextData = await apiRequest('/api/data/sales-main', {
                    method: 'POST',
                    body: JSON.stringify(filters),
                });

                if (active && requestId === requestIdRef.current && signaturesMatch(expectedSignature, responseSignature(nextData))) {
                    setData(nextData);
                }
            } catch (caught) {
                if (active && requestId === requestIdRef.current) {
                    setError(caught instanceof Error ? caught.message : 'Veri alınamadı.');
                }
            } finally {
                if (active && requestId === requestIdRef.current) {
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

    const handleScopeChange = (patch) => {
        setSelectedCustomers([]);
        updateFilters({
            ...patch,
            customer_filter: '',
            cari_filter: '',
            bypass_cache: true,
        });
    };

    const updateCustomers = (customers) => {
        setSelectedCustomers(customers);
        const csv = customers.map((customer) => customer.code).filter(Boolean).join(',');

        updateFilters({
            customer_filter: csv,
            cari_filter: csv,
            bypass_cache: true,
        });
    };

    const handleDetailTypeChange = (detailType) => {
        if (detailType === 'urun') {
            setSelectedCustomers([]);
            updateFilters({
                detail_type: detailType,
                customer_filter: '',
                cari_filter: '',
                bypass_cache: true,
            });

            return;
        }

        updateFilters({
            detail_type: detailType,
            brand_filter: 'all',
            category_filter: 'all',
            product_filter: '',
            bypass_cache: true,
        });
    };

    return (
        <>
            <Head title="Satış Yönetimi" />
            <main className="grid gap-4 bg-[#f3f7fb] p-3 sm:gap-5 sm:p-4 md:p-6">
                <div className="grid gap-4 sm:flex sm:flex-wrap sm:items-start sm:justify-between">
                    <div className="min-w-0">
                        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-blue-700">
                            Emaks Prime
                        </p>
                        <h1 className="mt-2 text-2xl font-semibold text-slate-950 [font-family:var(--font-display)] sm:text-3xl">
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
                        className="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-blue-100 bg-white px-4 py-3 text-sm font-semibold text-blue-700 shadow-sm transition hover:border-blue-200 hover:bg-blue-50 sm:w-auto"
                    >
                        <RefreshCw className={`size-4 ${loading ? 'animate-spin' : ''}`} />
                        Yenile
                    </button>
                </div>

                <section className="grid gap-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm md:p-5">
                    <div className="grid gap-4 xl:grid-cols-[minmax(0,1.35fr)_auto] xl:items-start xl:justify-between">
                        <div className="grid gap-2">
                            <span className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">
                                Kapsam
                            </span>
                            <ManagementScopeFilter
                                scopes={config?.managementScopes ?? []}
                                activeKey={filters.scope_key}
                                onChange={handleScopeChange}
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
                                        onClick={() => handleDetailTypeChange(mode.key)}
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
                    {filters.detail_type === 'urun' ? (
                        <ProductFilter
                            brandFilter={filters.brand_filter}
                            categoryFilter={filters.category_filter}
                            productFilter={filters.product_filter}
                            onChange={updateFilters}
                            loading={loading}
                        />
                    ) : (
                        <CustomerFilterPicker
                            selected={selectedCustomers}
                            onChange={updateCustomers}
                            loading={loading}
                            scopeKey={filters.scope_key}
                            dateFrom={filters.date_from}
                            dateTo={filters.date_to}
                            grain={filters.grain}
                            detailType={filters.detail_type}
                        />
                    )}
                </section>

                {error && (
                    <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-700">
                        {error}
                    </div>
                )}

                <KpiCards items={data?.kpis ?? []} />

                <div className="grid gap-5">
                    {filters.detail_type === 'urun' && (
                        <BrandComparisonStrip comparison={data?.brandComparison} />
                    )}
                    <SalesPieChart chart={data?.chart} />
                    <SalesBreakdown breakdown={data?.breakdown} table={data?.table} />
                </div>
            </main>
        </>
    );
}
