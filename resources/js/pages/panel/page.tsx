import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Database, FileText, Plus, ShieldCheck } from 'lucide-react';
import { createElement, useEffect, useState } from 'react';
import { CustomerDetailDrawer } from '@/components/primecrm/CustomerDetailDrawer.jsx';
import { DataTable } from '@/components/primecrm/DataTable.jsx';
import { DetailDrawer } from '@/components/primecrm/DetailDrawer.jsx';
import { FilterBar } from '@/components/primecrm/FilterBar.jsx';
import { KpiCard } from '@/components/primecrm/KpiCard.jsx';
import { ModuleShell } from '@/components/primecrm/ModuleShell.jsx';
import { ProformaCartDrawer } from '@/components/primecrm/ProformaCartDrawer.jsx';
import { EmptyState, ErrorBanner, LoadingOverlay } from '@/components/primecrm/StateBlocks.jsx';
import {
    detailTitle,
    filterRowsBySearch,
    friendlyEmptyMessage,
    moduleKindFromPage,
    pageCopy,
    preferredColumns,
    summaryCards,
    translateModuleTabs,
    valueFrom,
    visibleRows,
} from '@/components/primecrm/module-data.js';
import { apiRequest } from '@/lib/api';
import { panelIcon } from '@/lib/panel-icons';
import type {
    PanelButtonData,
    PanelDataSourceSummary,
    PanelExternalIntegration,
    PanelMetric,
    PanelPagePayload,
} from '@/types';

type PanelPageProps = {
    page: PanelPagePayload;
    metrics: PanelMetric[];
    dataSources: PanelDataSourceSummary[];
    permissions: {
        grantedResources: number;
        canExecuteButtons: number;
    };
    integration?: PanelExternalIntegration | null;
};

type ModuleDataColumn = {
    key: string;
    label: string;
};

type ModuleDataResponse = {
    rows: Record<string, unknown>[];
    columns: ModuleDataColumn[];
    queryMeta?: {
        dataSource?: string | null;
        notice?: string | null;
    };
};

type ModuleDataState = ModuleDataResponse & {
    signature: string;
};

type ModuleErrorState = {
    signature: string;
    message: string;
};

const defaultFilters = () => {
    const today = new Date().toISOString().slice(0, 10);

    return {
        date_from: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10),
        date_to: today,
        grain: 'month',
        detail_type: 'cari',
        scope_key: 'all',
        search: '',
        page: 1,
        bypass_cache: false,
        group: '',
        balance_type: '',
        warehouse: '',
        stock_state: '',
        order_status: '',
    };
};

function ActionButton({ button }: { button: PanelButtonData }) {
    if (button.actionType === 'navigate' && button.actionTarget) {
        return (
            <Link
                href={button.actionTarget}
                className="inline-flex items-center gap-2 rounded-md bg-slate-900 px-3 py-2 text-sm font-semibold text-white"
            >
                {button.label}
                <ArrowRight className="size-4" />
            </Link>
        );
    }

    return (
        <button
            type="button"
            onClick={() => window.location.reload()}
            disabled={!button.canExecute}
            className="inline-flex items-center gap-2 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm disabled:opacity-50"
        >
            {button.label}
        </button>
    );
}

function PanelPageIcon({ name }: { name?: string | null }) {
    return createElement(panelIcon(name), { className: 'size-5' });
}

function PrintBrandHeader({ visible }: { visible: boolean }) {
    if (!visible) {
        return null;
    }

    return (
        <section className="hidden items-center justify-between border-b border-slate-200 pb-5 print:flex">
            <img src="/assets/primecrm/emaks-prime-pdf-logo.jpg" alt="Emaks Prime" className="h-14 w-auto object-contain" />
            <div className="text-center">
                <p className="text-xs font-semibold tracking-[0.2em] text-slate-500 uppercase">Emaks Prime</p>
                <h2 className="text-xl font-bold text-slate-950">Müşteri / Proforma Çıktısı</h2>
            </div>
            <img src="/assets/primecrm/philips-logo.png" alt="Philips" className="h-10 w-auto object-contain" />
        </section>
    );
}

function ProformaDraftPanel({ slug }: { slug: string }) {
    if (!['proforma_create', 'proforma_edit'].includes(slug)) {
        return null;
    }

    return (
        <section className="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm lg:grid-cols-[1.2fr_0.8fr]">
            <div>
                <p className="text-xs font-semibold tracking-[0.18em] text-blue-700 uppercase">Proforma Taslağı</p>
                <h3 className="mt-2 text-lg font-bold text-slate-950">
                    Müşteri seçimi ve ürün satırları
                </h3>
                <p className="mt-2 text-sm leading-6 text-slate-600">
                    Stok ekranından eklenen ürünler proforma satırlarına dönüştürülür. Müşteri seçimi, fiyat, iskonto ve not alanları yazdırma öncesi kontrol edilir.
                </p>
                <div className="mt-4 grid gap-3 md:grid-cols-3">
                    {['Müşteri Seç', 'Ürün Ekle', 'PDF Önizle'].map((label) => (
                        <button key={label} type="button" className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700">
                            {label}
                        </button>
                    ))}
                </div>
            </div>
            <div className="rounded-2xl border border-blue-100 bg-blue-50 p-5">
                <p className="text-xs font-semibold tracking-[0.18em] text-blue-700 uppercase">Toplamlar</p>
                <dl className="mt-4 grid gap-3 text-sm">
                    <div className="flex justify-between"><dt>Ara Toplam</dt><dd>0,00 TL</dd></div>
                    <div className="flex justify-between"><dt>İskonto</dt><dd>0,00 TL</dd></div>
                    <div className="flex justify-between"><dt>KDV</dt><dd>0,00 TL</dd></div>
                    <div className="flex justify-between border-t border-blue-200 pt-3 text-base font-bold"><dt>Genel Toplam</dt><dd>0,00 TL</dd></div>
                </dl>
            </div>
        </section>
    );
}

function ModuleDataPanel({ page }: { page: PanelPagePayload }) {
    const kind = moduleKindFromPage(page);
    const copy = pageCopy(page, kind);
    const displayPage = {
        ...page,
        title: copy.title,
        description: copy.description,
        heroEyebrow: copy.eyebrow,
        moduleTabs: translateModuleTabs(page.moduleTabs ?? []),
    };
    const [filters, setFilters] = useState(defaultFilters);
    const [debouncedFilters, setDebouncedFilters] = useState(filters);
    const [data, setData] = useState<ModuleDataState | null>(null);
    const [error, setError] = useState<ModuleErrorState | null>(null);
    const [selected, setSelected] = useState<Record<string, unknown> | null>(null);
    const [cartOpen, setCartOpen] = useState(false);
    const [cartItems, setCartItems] = useState<Array<Record<string, unknown>>>([]);

    useEffect(() => {
        const timer = window.setTimeout(() => setDebouncedFilters(filters), 350);

        return () => window.clearTimeout(timer);
    }, [filters]);

    const signature = `${page.slug}:${JSON.stringify(debouncedFilters)}`;

    useEffect(() => {
        let isCurrent = true;

        void apiRequest(`/api/data/${page.slug}`, {
            method: 'POST',
            body: JSON.stringify(debouncedFilters),
        })
            .then((response: ModuleDataResponse) => {
                if (isCurrent) {
                    setData({ ...response, signature });
                    setError(null);
                }
            })
            .catch((caught: unknown) => {
                if (isCurrent) {
                    setError({
                        signature,
                        message: caught instanceof Error ? caught.message : 'Veri alınamadı.',
                    });
                }
            });

        return () => {
            isCurrent = false;
        };
    }, [page.slug, debouncedFilters, signature]);

    const activeData = data?.signature === signature ? data : null;
    const activeError = error?.signature === signature ? error.message : null;
    const rawRows = visibleRows(activeData?.rows ?? []);
    const rows = filterRowsBySearch(kind, rawRows, filters.search);
    const columns = preferredColumns(kind, page, activeData?.columns ?? []);
    const hasRows = rows.length > 0 && columns.length > 0;
    const loading = !activeData && !activeError;
    const cards = summaryCards(kind, page, rows, cartItems);

    const addToCart = (row: Record<string, unknown>) => {
        setCartItems((current) => [
            ...current,
            {
                stok_kodu: valueFrom(row, 'stokKodu'),
                urun_adi: valueFrom(row, 'urunAdi'),
                model: row.model ?? row.model_adi,
                quantity: 1,
                unit_price: Number(row.birim_fiyat ?? row.fiyat ?? 0),
                discount: 0,
            },
        ]);
        setCartOpen(true);
    };

    const actions = (
        <>
            {kind === 'stock' && (
                <button type="button" onClick={() => setCartOpen(true)} className="inline-flex items-center gap-2 rounded-xl border border-blue-100 bg-blue-50 px-4 py-2 text-sm font-semibold text-blue-700 transition hover:border-blue-200 hover:bg-blue-100">
                    <Plus className="size-4" />
                    Proforma Sepeti ({cartItems.length})
                </button>
            )}
            {kind === 'proforma' && (
                <button type="button" onClick={() => window.print()} className="inline-flex items-center gap-2 rounded-xl border border-blue-100 bg-blue-50 px-4 py-2 text-sm font-semibold text-blue-700 transition hover:border-blue-200 hover:bg-blue-100">
                    <FileText className="size-4" />
                    PDF / Yazdır
                </button>
            )}
        </>
    );

    return (
        <ModuleShell page={displayPage} badge={hasRows ? 'Canlı veri' : null} actions={actions}>
            <FilterBar
                filters={filters}
                setFilters={setFilters}
                mode={kind}
                loading={loading}
                onRefresh={() => setFilters((current) => ({ ...current, bypass_cache: !current.bypass_cache }))}
            />

            <PrintBrandHeader visible={kind === 'cari' || kind === 'proforma'} />
            <ProformaDraftPanel slug={page.slug} />

            <section className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                {cards.map((card) => (
                    <KpiCard key={card.label} label={card.label} value={card.value} hint={card.hint} />
                ))}
            </section>

            <ErrorBanner message={activeError} />
            <LoadingOverlay show={loading} />
            {!loading && !activeError && !hasRows && <EmptyState title="Canlı veri bulunamadı" description={friendlyEmptyMessage(kind)} />}
            {hasRows && (
                <DataTable
                    columns={columns}
                    rows={rows}
                    onRowClick={setSelected}
                    rowActions={kind === 'stock' ? (row) => (
                        <button type="button" onClick={() => addToCart(row)} className="rounded-lg bg-blue-700 px-3 py-2 text-xs font-semibold text-white transition hover:bg-blue-800">
                            Ekle
                        </button>
                    ) : undefined}
                />
            )}

            {kind === 'cari' ? (
                <CustomerDetailDrawer item={selected} onClose={() => setSelected(null)} />
            ) : (
                <DetailDrawer
                    title={detailTitle(kind, selected)}
                    item={selected}
                    onClose={() => setSelected(null)}
                    actions={kind === 'proforma' ? (
                        <button type="button" onClick={() => window.print()} className="rounded-xl bg-blue-700 px-4 py-3 text-sm font-semibold text-white">
                            PDF / Yazdır
                        </button>
                    ) : null}
                />
            )}
            <ProformaCartDrawer open={cartOpen} items={cartItems} setItems={setCartItems} onClose={() => setCartOpen(false)} />
        </ModuleShell>
    );
}

export default function PanelPage({
    page,
    metrics,
    dataSources,
    permissions,
}: PanelPageProps) {
    const isModule = page.layoutType === 'module';

    return (
        <>
            <Head title={page.title} />

            {isModule ? (
                <ModuleDataPanel page={page} />
            ) : (
                <main className="grid gap-5 p-4 md:p-6">
                    <section className="grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm lg:grid-cols-[1fr_auto]">
                        <div>
                            <div className="flex items-center gap-3">
                                <span className="grid size-11 place-items-center rounded-lg bg-slate-900 text-white">
                                    <PanelPageIcon name={page.icon} />
                                </span>
                                <div>
                                    <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                        {page.heroEyebrow ?? page.routePath}
                                    </p>
                                    <h1 className="text-2xl font-semibold text-slate-950 [font-family:var(--font-display)]">
                                        {page.title}
                                    </h1>
                                </div>
                            </div>
                            <p className="mt-4 max-w-3xl text-sm leading-6 text-slate-600">
                                {page.description}
                            </p>
                        </div>
                        <div className="flex flex-wrap items-start gap-2">
                            {page.buttons.map((button) => (
                                <ActionButton key={button.id} button={button} />
                            ))}
                        </div>
                    </section>

                    <section className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        {metrics.map((metric) => (
                            <KpiCard key={metric.label} label={metric.label} value={metric.value} hint={metric.hint} />
                        ))}
                    </section>

                    <section className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
                        <div className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <div className="mb-4 flex items-center gap-2">
                                <Database className="size-5 text-slate-500" />
                                <h2 className="font-semibold text-slate-950">Veri Kaynakları</h2>
                            </div>
                            <div className="grid gap-2">
                                {dataSources.map((source) => (
                                    <div key={source.id} className="grid gap-1 rounded-md border border-slate-200 p-3">
                                        <strong className="text-slate-950">{source.name}</strong>
                                        <p className="text-sm text-slate-500">{source.driver} - {source.database ?? 'metadata'}</p>
                                    </div>
                                ))}
                            </div>
                        </div>
                        <div className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <div className="mb-4 flex items-center gap-2">
                                <ShieldCheck className="size-5 text-slate-500" />
                                <h2 className="font-semibold text-slate-950">Yetki Özeti</h2>
                            </div>
                            <KpiCard label="Tanımlı kaynaklar" value={String(permissions.grantedResources)} />
                            <div className="mt-3">
                                <KpiCard label="Çalıştırılabilir butonlar" value={String(permissions.canExecuteButtons)} />
                            </div>
                        </div>
                    </section>
                </main>
            )}
        </>
    );
}
