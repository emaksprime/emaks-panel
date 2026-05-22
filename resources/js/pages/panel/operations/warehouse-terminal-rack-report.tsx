import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, ChevronDown, ChevronRight, Download, Eye, RotateCcw, Search, X } from 'lucide-react';
import type { FormEvent } from 'react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { apiRequest } from '@/lib/api';

type ItemType = 'all' | 'serial' | 'stock';

type RackReportItem = {
    item_type: 'serial' | 'stock';
    warehouse_no: number | string;
    warehouse_name: string;
    rack_code: string | null;
    rack_name: string | null;
    stock_code: string | null;
    stock_name: string | null;
    category_name: string | null;
    serial_no: string | null;
    quantity: number | string;
    status: string | null;
    source: string | null;
    last_operation_no: string | null;
    last_seen_at: string | null;
    updated_at: string | null;
};

type RackReportResponse = {
    items?: RackReportItem[];
};

type WarehouseLookup = {
    warehouse_no: number | string;
    warehouse_name: string;
};

type WarehouseLookupResponse = {
    items?: WarehouseLookup[];
};

type SerialHistoryItem = {
    source: 'panel' | 'mikro';
    date: string | null;
    movement_type: string | null;
    operation_no: string | null;
    source_warehouse_no: number | string | null;
    source_warehouse_name: string | null;
    source_rack_code: string | null;
    source_rack_name: string | null;
    target_warehouse_no: number | string | null;
    target_warehouse_name: string | null;
    target_rack_code: string | null;
    target_rack_name: string | null;
    document_no: string | null;
    user: string | null;
    description: string | null;
};

type SerialHistoryResponse = {
    serial_no: string;
    stock_code: string | null;
    stock_name: string | null;
    category_name: string | null;
    current_location: {
        warehouse_no: number | string | null;
        warehouse_name: string | null;
        rack_code: string | null;
        rack_name: string | null;
        status: string | null;
        last_seen_at: string | null;
    } | null;
    items: SerialHistoryItem[];
    message: string | null;
};

type RackReportGroup = {
    key: string;
    rack_code: string;
    items: RackReportItem[];
    total_line_count: number;
    serial_count: number;
    stock_line_count: number;
    stock_quantity_sum: number;
    total_quantity: number;
};

const rackReportUrl = '/api/operations/warehouse-terminal/rack-report';
const rackReportExportUrl = '/api/operations/warehouse-terminal/rack-report/export';
const rackReportSerialHistoryUrl = '/api/operations/warehouse-terminal/rack-report/serial-history';
const lookupWarehousesUrl = '/api/operations/warehouse-terminal/lookups/warehouses';

function buildQuery(params: Record<string, string | number | boolean | null | undefined>): string {
    const query = new URLSearchParams();

    Object.entries(params).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== '') {
            query.set(key, String(value));
        }
    });

    return query.toString();
}

function formatValue(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '-';
    }

    return String(value);
}

function formatQuantity(value: string | number | null | undefined): string {
    const numberValue = Number(value ?? 0);

    return numberValue.toLocaleString('tr-TR', {
        minimumFractionDigits: Number.isInteger(numberValue) ? 0 : 1,
        maximumFractionDigits: 4,
    });
}

function itemTypeLabel(value: string): string {
    return value === 'serial' ? 'Serili' : 'Serisiz';
}

function serialLabel(item: RackReportItem): string {
    if (item.item_type === 'stock') {
        return 'Serisiz ürün';
    }

    return formatValue(item.serial_no);
}

function rackMovementDateLabel(item: RackReportItem): string {
    return formatValue(item.last_seen_at || item.updated_at);
}

function categoryLabel(value: string | null | undefined): string {
    return formatValue(value);
}

function compareText(left: string | number | null | undefined, right: string | number | null | undefined): number {
    return formatValue(left).localeCompare(formatValue(right), 'tr', {
        numeric: true,
        sensitivity: 'base',
    });
}

function buildRackGroups(items: RackReportItem[]): RackReportGroup[] {
    const groups = new Map<string, RackReportItem[]>();

    items.forEach((item) => {
        const key = formatValue(item.rack_code);
        groups.set(key, [...(groups.get(key) ?? []), item]);
    });

    return Array.from(groups.entries())
        .map(([key, groupItems]) => {
            const sortedItems = [...groupItems].sort((left, right) => {
                const stockNameComparison = compareText(left.stock_name, right.stock_name);

                if (stockNameComparison !== 0) {
                    return stockNameComparison;
                }

                return compareText(left.serial_no, right.serial_no);
            });
            const serialCount = sortedItems.filter((item) => item.item_type === 'serial').length;
            const stockItems = sortedItems.filter((item) => item.item_type === 'stock');
            const stockQuantitySum = stockItems.reduce((sum, item) => sum + Number(item.quantity ?? 0), 0);
            const totalQuantity = sortedItems.reduce((sum, item) => sum + Number(item.quantity ?? 0), 0);

            return {
                key,
                rack_code: key,
                items: sortedItems,
                total_line_count: sortedItems.length,
                serial_count: serialCount,
                stock_line_count: stockItems.length,
                stock_quantity_sum: stockQuantitySum,
                total_quantity: totalQuantity,
            };
        })
        .sort((left, right) => compareText(left.rack_code, right.rack_code));
}

function exportFilename(contentDisposition: string | null): string {
    const match = contentDisposition?.match(/filename="?([^"]+)"?/i);

    return match?.[1] ?? 'raf-raporu.csv';
}

export default function WarehouseTerminalRackReport() {
    const [warehouses, setWarehouses] = useState<WarehouseLookup[]>([]);
    const [warehouseNo, setWarehouseNo] = useState('');
    const [rackCode, setRackCode] = useState('');
    const [itemType, setItemType] = useState<ItemType>('all');
    const [search, setSearch] = useState('');
    const [items, setItems] = useState<RackReportItem[]>([]);
    const [expandedRacks, setExpandedRacks] = useState<Set<string>>(new Set());
    const [loading, setLoading] = useState(false);
    const [exporting, setExporting] = useState(false);
    const [historyLoadingSerial, setHistoryLoadingSerial] = useState<string | null>(null);
    const [serialHistory, setSerialHistory] = useState<SerialHistoryResponse | null>(null);
    const [serialHistoryError, setSerialHistoryError] = useState<string | null>(null);
    const [message, setMessage] = useState<string | null>(null);
    const rackGroups = useMemo(() => buildRackGroups(items), [items]);

    const loadWarehouses = useCallback(async () => {
        try {
            const response = await apiRequest(lookupWarehousesUrl) as WarehouseLookupResponse;
            setWarehouses(response.items ?? []);
        } catch {
            setWarehouses([]);
        }
    }, []);

    const loadReport = useCallback(async () => {
        setLoading(true);
        setMessage(null);

        try {
            const response = await apiRequest(`${rackReportUrl}?${buildQuery({
                warehouse_no: warehouseNo,
                rack_code: rackCode.trim(),
                item_type: itemType,
                search: search.trim(),
                only_in_stock: 1,
                per_page: 100,
            })}`) as RackReportResponse;

            setItems(response.items ?? []);
            setExpandedRacks(new Set());
            setMessage((response.items ?? []).length === 0 ? 'Filtrelere uygun raf lokasyon kaydı bulunamadı.' : null);
        } catch (caught) {
            setMessage(caught instanceof Error ? caught.message : 'Raf raporu alınamadı.');
        } finally {
            setLoading(false);
        }
    }, [itemType, rackCode, search, warehouseNo]);

    useEffect(() => {
        const timer = window.setTimeout(() => {
            void loadWarehouses();
        }, 0);

        return () => window.clearTimeout(timer);
    }, [loadWarehouses]);

    useEffect(() => {
        const timer = window.setTimeout(() => {
            void loadReport();
        }, 0);

        return () => window.clearTimeout(timer);
    }, [loadReport]);

    const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        void loadReport();
    };

    const clearFilters = () => {
        setWarehouseNo('');
        setRackCode('');
        setItemType('all');
        setSearch('');
    };

    const toggleRack = (rackKey: string) => {
        setExpandedRacks((current) => {
            const next = new Set(current);

            if (next.has(rackKey)) {
                next.delete(rackKey);
            } else {
                next.add(rackKey);
            }

            return next;
        });
    };

    const expandAll = () => {
        setExpandedRacks(new Set(rackGroups.map((group) => group.key)));
    };

    const collapseAll = () => {
        setExpandedRacks(new Set());
    };

    const exportReport = useCallback(async () => {
        setExporting(true);
        setMessage(null);

        try {
            const response = await fetch(`${rackReportExportUrl}?${buildQuery({
                warehouse_no: warehouseNo,
                rack_code: rackCode.trim(),
                item_type: itemType,
                search: search.trim(),
                only_in_stock: 1,
            })}`, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'text/csv',
                },
            });

            if (!response.ok) {
                throw new Error('Excel raporu indirilemedi.');
            }

            const blob = await response.blob();
            const url = window.URL.createObjectURL(blob);
            const anchor = document.createElement('a');
            anchor.href = url;
            anchor.download = exportFilename(response.headers.get('Content-Disposition'));
            document.body.appendChild(anchor);
            anchor.click();
            anchor.remove();
            window.URL.revokeObjectURL(url);
        } catch {
            setMessage('Excel raporu indirilemedi.');
        } finally {
            setExporting(false);
        }
    }, [itemType, rackCode, search, warehouseNo]);

    const openSerialHistory = useCallback(async (serialNo: string) => {
        setHistoryLoadingSerial(serialNo);
        setSerialHistoryError(null);

        try {
            const response = await apiRequest(`${rackReportSerialHistoryUrl}?${buildQuery({ serial_no: serialNo })}`) as SerialHistoryResponse;
            setSerialHistory(response);
        } catch {
            setSerialHistoryError('Seri hareket geçmişi alınamadı.');
            setSerialHistory({
                serial_no: serialNo,
                stock_code: null,
                stock_name: null,
                category_name: null,
                current_location: null,
                items: [],
                message: null,
            });
        } finally {
            setHistoryLoadingSerial(null);
        }
    }, []);

    const closeSerialHistory = () => {
        setSerialHistory(null);
        setSerialHistoryError(null);
        setHistoryLoadingSerial(null);
    };

    return (
        <>
            <Head title="Raf Raporu" />

            <main className="min-h-[calc(100vh-4.5rem)] bg-slate-100 px-3 py-4 sm:px-4 md:px-6 md:py-6">
                <div className="mx-auto grid w-full max-w-[1100px] gap-4">
                    <section className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm md:p-6">
                        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div className="min-w-0">
                                <p className="text-xs font-bold uppercase text-blue-700">Depo Terminal</p>
                                <h1 className="mt-2 text-2xl font-semibold text-slate-950 [font-family:var(--font-display)] md:text-3xl">
                                    Raf Raporu
                                </h1>
                                <p className="mt-2 text-sm leading-6 text-slate-600 md:text-base">
                                    Panel raf lokasyon kayıtlarına göre güncel depo/raf durumunu gösterir.
                                </p>
                            </div>
                            <Link
                                href="/operations/warehouse-terminal"
                                className="inline-flex h-12 items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-4 text-sm font-bold text-slate-700 shadow-sm transition hover:border-slate-300 hover:bg-slate-50"
                            >
                                <ArrowLeft className="size-5" />
                                Geri Dön
                            </Link>
                        </div>
                    </section>

                    <form onSubmit={handleSubmit} className="grid gap-3 rounded-lg border border-slate-200 bg-white p-3 shadow-sm md:gap-4 md:p-5">
                        <div className="grid gap-3 md:grid-cols-[0.8fr_1fr_1fr_1.4fr]">
                            <label className="grid gap-1.5 text-sm font-bold text-slate-800 md:gap-2">
                                Depo
                                <select
                                    value={warehouseNo}
                                    onChange={(event) => setWarehouseNo(event.target.value)}
                                    className="h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100 md:h-12 md:text-base"
                                >
                                    <option value="">Tüm depolar</option>
                                    {warehouses.map((warehouse) => (
                                        <option key={warehouse.warehouse_no} value={warehouse.warehouse_no}>
                                            {warehouse.warehouse_name}
                                        </option>
                                    ))}
                                </select>
                            </label>

                            <label className="grid gap-1.5 text-sm font-bold text-slate-800 md:gap-2">
                                Raf
                                <input
                                    value={rackCode}
                                    onChange={(event) => setRackCode(event.target.value)}
                                    placeholder="Raf kodu"
                                    className="h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100 md:h-12 md:text-base"
                                />
                            </label>

                            <label className="grid gap-1.5 text-sm font-bold text-slate-800 md:gap-2">
                                Arama
                                <input
                                    value={search}
                                    onChange={(event) => setSearch(event.target.value)}
                                    placeholder="Stok kodu, stok adı, seri no veya raf kodu"
                                    className="h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100 md:h-12 md:text-base"
                                />
                            </label>

                            <label className="grid gap-1.5 text-sm font-bold text-slate-800 md:gap-2">
                                Tip
                                <select
                                    value={itemType}
                                    onChange={(event) => setItemType(event.target.value as ItemType)}
                                    className="h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-950 outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100 md:h-12 md:text-base"
                                >
                                    <option value="all">Tümü</option>
                                    <option value="serial">Serili</option>
                                    <option value="stock">Serisiz / Adetli</option>
                                </select>
                            </label>
                        </div>

                        <div className="grid gap-2 sm:grid-cols-3 md:ml-auto">
                            <button
                                type="submit"
                                disabled={loading || exporting}
                                className="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-slate-950 px-4 text-sm font-bold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60 md:h-12 md:px-5 md:text-base"
                            >
                                <Search className="size-5" />
                                {loading ? 'Listeleniyor' : 'Listele'}
                            </button>
                            <button
                                type="button"
                                onClick={clearFilters}
                                disabled={loading || exporting}
                                className="inline-flex h-10 items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-4 text-sm font-bold text-slate-700 transition hover:border-slate-300 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60 md:h-12 md:px-5 md:text-base"
                            >
                                <RotateCcw className="size-5" />
                                Temizle
                            </button>
                            <button
                                type="button"
                                onClick={exportReport}
                                disabled={loading || exporting}
                                className="inline-flex h-10 items-center justify-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-4 text-sm font-bold text-blue-800 transition hover:border-blue-300 hover:bg-blue-100 disabled:cursor-not-allowed disabled:opacity-60 md:h-12 md:px-5 md:text-base"
                            >
                                <Download className="size-5" />
                                {exporting ? 'Hazırlanıyor' : 'Excel’e Aktar'}
                            </button>
                        </div>
                    </form>

                    {message ? (
                        <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">
                            {message}
                        </div>
                    ) : null}

                    <section className="grid gap-2">
                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <p className="text-sm font-semibold text-slate-600">
                                {formatQuantity(rackGroups.length)} raf / {formatQuantity(items.length)} ürün
                            </p>
                            <div className="grid grid-cols-2 gap-2 sm:w-auto">
                                <button
                                    type="button"
                                    onClick={expandAll}
                                    disabled={rackGroups.length === 0}
                                    className="inline-flex h-9 items-center justify-center rounded-lg border border-slate-200 bg-white px-3 text-sm font-bold text-slate-700 transition hover:border-slate-300 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    Tümünü Aç
                                </button>
                                <button
                                    type="button"
                                    onClick={collapseAll}
                                    disabled={rackGroups.length === 0}
                                    className="inline-flex h-9 items-center justify-center rounded-lg border border-slate-200 bg-white px-3 text-sm font-bold text-slate-700 transition hover:border-slate-300 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    Tümünü Kapat
                                </button>
                            </div>
                        </div>

                        <div className="grid gap-2">
                            {rackGroups.map((group) => {
                                const isExpanded = expandedRacks.has(group.key);
                                const ChevronIcon = isExpanded ? ChevronDown : ChevronRight;

                                return (
                                    <article key={group.key} className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                                        <button
                                            type="button"
                                            onClick={() => toggleRack(group.key)}
                                            className="flex w-full items-start justify-between gap-3 px-3 py-2.5 text-left transition hover:bg-slate-50 md:px-4"
                                        >
                                            <div className="flex min-w-0 items-start gap-2">
                                                <ChevronIcon className="mt-1 size-4 shrink-0 text-slate-500" />
                                                <h2 className="min-w-0 break-words text-lg font-bold leading-6 text-slate-950">
                                                    <span className="mr-1 text-xs font-bold uppercase text-slate-500">RAF:</span>
                                                    {group.rack_code}
                                                </h2>
                                            </div>
                                            <div
                                                className="grid shrink-0 gap-0.5 text-right text-xs font-bold leading-4 text-slate-600 sm:flex sm:items-center sm:gap-2"
                                                title={`${formatQuantity(group.stock_line_count)} serisiz satır / ${formatQuantity(group.total_quantity)} toplam miktar`}
                                            >
                                                <span>{formatQuantity(group.total_line_count)} ürün</span>
                                                <span>{formatQuantity(group.serial_count)} seri</span>
                                                <span>{formatQuantity(group.stock_quantity_sum)} serisiz</span>
                                            </div>
                                        </button>

                                        {isExpanded ? (
                                            <div className="divide-y divide-slate-100 border-t border-slate-100">
                                                <div className="hidden bg-slate-50 px-4 py-2 text-xs font-bold uppercase text-slate-500 md:grid md:grid-cols-[minmax(180px,1.2fr)_minmax(140px,0.8fr)_minmax(170px,1fr)_80px_160px_auto] md:items-center md:gap-3">
                                                    <span>Stok Adı</span>
                                                    <span>Kategori Adı</span>
                                                    <span>Seri No</span>
                                                    <span>Miktar</span>
                                                    <span>Son Raf Hareketi</span>
                                                    <span>Tip</span>
                                                </div>
                                                {group.items.map((item) => (
                                                    <div
                                                        key={`${item.item_type}-${item.warehouse_no}-${item.rack_code}-${item.stock_code}-${item.serial_no ?? 'stock'}`}
                                                        className="grid gap-2 px-3 py-2.5 text-sm md:grid-cols-[minmax(180px,1.2fr)_minmax(140px,0.8fr)_minmax(170px,1fr)_80px_160px_auto] md:items-center md:gap-3 md:px-4"
                                                    >
                                                        <div className="grid gap-1">
                                                            <p className="break-words font-semibold leading-5 text-slate-950">{formatValue(item.stock_name)}</p>
                                                            <p className="break-words text-xs font-semibold leading-4 text-slate-500 md:hidden">
                                                                Kategori: {categoryLabel(item.category_name)}
                                                            </p>
                                                        </div>
                                                        <p className="hidden break-words font-semibold leading-5 text-slate-700 md:block">{categoryLabel(item.category_name)}</p>
                                                        <div className="flex min-w-0 items-center gap-1.5 leading-5 text-slate-700">
                                                            <span className="shrink-0 font-semibold text-slate-500">Seri: </span>
                                                            <span className="min-w-0 break-words font-semibold text-slate-800">{serialLabel(item)}</span>
                                                            {item.item_type === 'serial' && item.serial_no ? (
                                                                <button
                                                                    type="button"
                                                                    onClick={() => void openSerialHistory(item.serial_no as string)}
                                                                    disabled={historyLoadingSerial === item.serial_no}
                                                                    className="inline-grid size-8 shrink-0 place-items-center rounded-lg border border-slate-200 bg-white text-slate-600 transition hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700 disabled:cursor-wait disabled:opacity-60"
                                                                    aria-label={`${item.serial_no} seri hareket geçmişini aç`}
                                                                    title="Seri hareket geçmişi"
                                                                >
                                                                    {historyLoadingSerial === item.serial_no ? (
                                                                        <span className="text-xs font-bold">...</span>
                                                                    ) : (
                                                                        <Eye className="size-4" />
                                                                    )}
                                                                </button>
                                                            ) : null}
                                                        </div>
                                                        <p className="font-bold text-slate-950">
                                                            <span className="font-semibold text-slate-500 md:hidden">Miktar: </span>
                                                            {formatQuantity(item.quantity)}
                                                        </p>
                                                        <p className="break-words leading-5 text-slate-700">
                                                            <span className="font-semibold text-slate-500 md:hidden">Son Raf Hareketi: </span>
                                                            {rackMovementDateLabel(item)}
                                                        </p>
                                                        <span className="w-fit rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-bold text-slate-700">
                                                            {itemTypeLabel(item.item_type)}
                                                        </span>
                                                    </div>
                                                ))}
                                            </div>
                                        ) : null}
                                    </article>
                                );
                            })}
                        </div>
                    </section>
                </div>

                {serialHistory ? (
                    <div className="fixed inset-0 z-50 bg-slate-950/40 px-0 py-0 md:px-6 md:py-6">
                        <div className="ml-auto flex h-full w-full flex-col overflow-hidden bg-white shadow-2xl md:max-w-[720px] md:rounded-lg">
                            <header className="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-4">
                                <div className="min-w-0">
                                    <p className="text-xs font-bold uppercase text-blue-700">Seri Hareket Geçmişi</p>
                                    <h2 className="mt-1 break-words text-xl font-bold text-slate-950">{formatValue(serialHistory.serial_no)}</h2>
                                </div>
                                <button
                                    type="button"
                                    onClick={closeSerialHistory}
                                    className="grid size-10 shrink-0 place-items-center rounded-lg border border-slate-200 bg-white text-slate-700 transition hover:bg-slate-50"
                                    aria-label="Kapat"
                                >
                                    <X className="size-5" />
                                </button>
                            </header>

                            <div className="grid flex-1 gap-4 overflow-y-auto px-4 py-4">
                                {serialHistoryError ? (
                                    <div className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-800">
                                        {serialHistoryError}
                                    </div>
                                ) : null}

                                {serialHistory.message ? (
                                    <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900">
                                        {serialHistory.message}
                                    </div>
                                ) : null}

                                <section className="grid gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm sm:grid-cols-2">
                                    <div>
                                        <p className="text-xs font-bold uppercase text-slate-500">Stok Adı</p>
                                        <p className="mt-1 break-words font-semibold text-slate-950">{formatValue(serialHistory.stock_name)}</p>
                                    </div>
                                    <div>
                                        <p className="text-xs font-bold uppercase text-slate-500">Kategori Adı</p>
                                        <p className="mt-1 break-words font-semibold text-slate-950">{categoryLabel(serialHistory.category_name)}</p>
                                    </div>
                                    <div>
                                        <p className="text-xs font-bold uppercase text-slate-500">Güncel Depo</p>
                                        <p className="mt-1 font-semibold text-slate-950">{formatValue(serialHistory.current_location?.warehouse_name)}</p>
                                    </div>
                                    <div>
                                        <p className="text-xs font-bold uppercase text-slate-500">Güncel Raf</p>
                                        <p className="mt-1 font-semibold text-slate-950">{formatValue(serialHistory.current_location?.rack_name ?? serialHistory.current_location?.rack_code)}</p>
                                    </div>
                                    <div>
                                        <p className="text-xs font-bold uppercase text-slate-500">Durum</p>
                                        <p className="mt-1 font-semibold text-slate-950">{formatValue(serialHistory.current_location?.status)}</p>
                                    </div>
                                    <div>
                                        <p className="text-xs font-bold uppercase text-slate-500">Son Raf Hareketi</p>
                                        <p className="mt-1 font-semibold text-slate-950">{formatValue(serialHistory.current_location?.last_seen_at)}</p>
                                    </div>
                                </section>

                                <section className="grid gap-2">
                                    {serialHistory.items.length === 0 ? (
                                        <div className="rounded-lg border border-slate-200 bg-white px-3 py-4 text-sm font-semibold text-slate-600">
                                            Bu seri için hareket geçmişi bulunamadı.
                                        </div>
                                    ) : (
                                        serialHistory.items.map((historyItem, index) => (
                                            <article key={`${historyItem.source}-${historyItem.date ?? index}-${historyItem.operation_no ?? historyItem.document_no ?? index}`} className="rounded-lg border border-slate-200 bg-white p-3">
                                                <div className="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                                                    <div>
                                                        <p className="text-sm font-bold text-slate-950">{formatValue(historyItem.movement_type)}</p>
                                                        <p className="text-xs font-semibold uppercase text-slate-500">{historyItem.source === 'panel' ? 'Panel' : 'Mikro'} / {formatValue(historyItem.date)}</p>
                                                    </div>
                                                    <span className="w-fit rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-bold text-slate-700">
                                                        {historyItem.source === 'panel' ? 'Raf' : 'Mikro'}
                                                    </span>
                                                </div>

                                                <dl className="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                                                    <div>
                                                        <dt className="font-semibold text-slate-500">Kaynak Depo / Raf</dt>
                                                        <dd className="break-words font-semibold text-slate-800">
                                                            {formatValue(historyItem.source_warehouse_name)} / {formatValue(historyItem.source_rack_name ?? historyItem.source_rack_code)}
                                                        </dd>
                                                    </div>
                                                    <div>
                                                        <dt className="font-semibold text-slate-500">Hedef Depo / Raf</dt>
                                                        <dd className="break-words font-semibold text-slate-800">
                                                            {formatValue(historyItem.target_warehouse_name)} / {formatValue(historyItem.target_rack_name ?? historyItem.target_rack_code)}
                                                        </dd>
                                                    </div>
                                                    <div>
                                                        <dt className="font-semibold text-slate-500">Evrak / İşlem No</dt>
                                                        <dd className="break-words font-semibold text-slate-800">{formatValue(historyItem.document_no ?? historyItem.operation_no)}</dd>
                                                    </div>
                                                    <div>
                                                        <dt className="font-semibold text-slate-500">Kullanıcı</dt>
                                                        <dd className="break-words font-semibold text-slate-800">{formatValue(historyItem.user)}</dd>
                                                    </div>
                                                    <div className="sm:col-span-2">
                                                        <dt className="font-semibold text-slate-500">Açıklama</dt>
                                                        <dd className="break-words font-semibold text-slate-800">{formatValue(historyItem.description)}</dd>
                                                    </div>
                                                </dl>
                                            </article>
                                        ))
                                    )}
                                </section>
                            </div>
                        </div>
                    </div>
                ) : null}
            </main>
        </>
    );
}
