import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Boxes, Filter, Layers3, PackageCheck, RotateCcw, Search } from 'lucide-react';
import type { FormEvent } from 'react';
import { useCallback, useEffect, useState } from 'react';
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
    serial_no: string | null;
    quantity: number | string;
    status: string | null;
    source: string | null;
    last_operation_no: string | null;
    last_seen_at: string | null;
    updated_at: string | null;
};

type RackReportSummary = {
    total_serial_count: number;
    total_stock_rows: number;
    total_stock_quantity: number;
    rack_count: number;
};

type RackReportResponse = {
    items?: RackReportItem[];
    summary?: RackReportSummary;
};

const rackReportUrl = '/api/operations/warehouse-terminal/rack-report';
const emptySummary: RackReportSummary = {
    total_serial_count: 0,
    total_stock_rows: 0,
    total_stock_quantity: 0,
    rack_count: 0,
};

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
    return value === 'serial' ? 'Serili' : 'Serisiz / Adetli';
}

function statusLabel(value: string | null): string {
    if (value === 'in_stock') {
        return 'Stokta';
    }

    if (value === 'empty') {
        return 'Boş';
    }

    return formatValue(value);
}

function summaryCards(summary: RackReportSummary) {
    return [
        {
            label: 'Toplam Seri Adedi',
            value: formatQuantity(summary.total_serial_count),
            Icon: PackageCheck,
        },
        {
            label: 'Toplam Serisiz Stok Satırı',
            value: formatQuantity(summary.total_stock_rows),
            Icon: Boxes,
        },
        {
            label: 'Toplam Serisiz Miktar',
            value: formatQuantity(summary.total_stock_quantity),
            Icon: Layers3,
        },
        {
            label: 'Raf Sayısı',
            value: formatQuantity(summary.rack_count),
            Icon: Filter,
        },
    ];
}

export default function WarehouseTerminalRackReport() {
    const [warehouseNo, setWarehouseNo] = useState('');
    const [rackCode, setRackCode] = useState('');
    const [itemType, setItemType] = useState<ItemType>('all');
    const [search, setSearch] = useState('');
    const [onlyInStock, setOnlyInStock] = useState(false);
    const [items, setItems] = useState<RackReportItem[]>([]);
    const [summary, setSummary] = useState<RackReportSummary>(emptySummary);
    const [loading, setLoading] = useState(false);
    const [message, setMessage] = useState<string | null>(null);

    const loadReport = useCallback(async () => {
        setLoading(true);
        setMessage(null);

        try {
            const response = await apiRequest(`${rackReportUrl}?${buildQuery({
                warehouse_no: warehouseNo,
                rack_code: rackCode.trim(),
                item_type: itemType,
                search: search.trim(),
                only_in_stock: onlyInStock ? 1 : 0,
                per_page: 100,
            })}`) as RackReportResponse;

            setItems(response.items ?? []);
            setSummary(response.summary ?? emptySummary);
            setMessage((response.items ?? []).length === 0 ? 'Filtrelere uygun raf lokasyon kaydı bulunamadı.' : null);
        } catch (caught) {
            setMessage(caught instanceof Error ? caught.message : 'Raf raporu alınamadı.');
        } finally {
            setLoading(false);
        }
    }, [itemType, onlyInStock, rackCode, search, warehouseNo]);

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
        setOnlyInStock(false);
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

                    <form onSubmit={handleSubmit} className="grid gap-4 rounded-lg border border-slate-200 bg-white p-4 shadow-sm md:p-5">
                        <div className="grid gap-3 md:grid-cols-[0.8fr_1fr_1fr_1.4fr]">
                            <label className="grid gap-2 text-sm font-bold text-slate-800">
                                Depo
                                <input
                                    value={warehouseNo}
                                    onChange={(event) => setWarehouseNo(event.target.value)}
                                    placeholder="Depo no"
                                    inputMode="numeric"
                                    className="h-12 rounded-lg border border-slate-300 bg-white px-3 text-base font-semibold text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100"
                                />
                            </label>

                            <label className="grid gap-2 text-sm font-bold text-slate-800">
                                Raf
                                <input
                                    value={rackCode}
                                    onChange={(event) => setRackCode(event.target.value)}
                                    placeholder="Raf kodu"
                                    className="h-12 rounded-lg border border-slate-300 bg-white px-3 text-base font-semibold text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100"
                                />
                            </label>

                            <label className="grid gap-2 text-sm font-bold text-slate-800">
                                Tip
                                <select
                                    value={itemType}
                                    onChange={(event) => setItemType(event.target.value as ItemType)}
                                    className="h-12 rounded-lg border border-slate-300 bg-white px-3 text-base font-semibold text-slate-950 outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100"
                                >
                                    <option value="all">Tümü</option>
                                    <option value="serial">Serili</option>
                                    <option value="stock">Serisiz / Adetli</option>
                                </select>
                            </label>

                            <label className="grid gap-2 text-sm font-bold text-slate-800">
                                Arama
                                <input
                                    value={search}
                                    onChange={(event) => setSearch(event.target.value)}
                                    placeholder="Stok kodu, stok adı, seri no veya raf kodu"
                                    className="h-12 rounded-lg border border-slate-300 bg-white px-3 text-base font-semibold text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100"
                                />
                            </label>
                        </div>

                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <label className="inline-flex min-h-12 items-center gap-3 text-sm font-bold text-slate-800">
                                <input
                                    type="checkbox"
                                    checked={onlyInStock}
                                    onChange={(event) => setOnlyInStock(event.target.checked)}
                                    className="size-5 rounded border-slate-300 text-blue-700 focus:ring-blue-500"
                                />
                                Sadece stokta olanlar
                            </label>

                            <div className="grid gap-2 sm:grid-cols-2">
                                <button
                                    type="submit"
                                    disabled={loading}
                                    className="inline-flex h-12 items-center justify-center gap-2 rounded-lg bg-slate-950 px-5 text-base font-bold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <Search className="size-5" />
                                    {loading ? 'Listeleniyor' : 'Listele'}
                                </button>
                                <button
                                    type="button"
                                    onClick={clearFilters}
                                    disabled={loading}
                                    className="inline-flex h-12 items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-5 text-base font-bold text-slate-700 transition hover:border-slate-300 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <RotateCcw className="size-5" />
                                    Temizle
                                </button>
                            </div>
                        </div>
                    </form>

                    <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        {summaryCards(summary).map(({ label, value, Icon }) => (
                            <article key={label} className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                                <div className="flex items-start justify-between gap-3">
                                    <span className="grid size-10 place-items-center rounded-lg bg-blue-50 text-blue-700">
                                        <Icon className="size-5" />
                                    </span>
                                    <span className="text-2xl font-bold text-slate-950">{value}</span>
                                </div>
                                <p className="mt-3 text-sm font-semibold text-slate-500">{label}</p>
                            </article>
                        ))}
                    </section>

                    {message ? (
                        <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">
                            {message}
                        </div>
                    ) : null}

                    <section className="grid gap-3 md:hidden">
                        {items.map((item) => (
                            <article key={`${item.item_type}-${item.warehouse_no}-${item.rack_code}-${item.stock_code}-${item.serial_no ?? 'stock'}`} className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="text-xs font-bold uppercase text-slate-500">Raf</p>
                                        <h2 className="mt-1 break-words text-xl font-bold text-slate-950">{formatValue(item.rack_name ?? item.rack_code)}</h2>
                                    </div>
                                    <span className="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-bold text-slate-700">
                                        {itemTypeLabel(item.item_type)}
                                    </span>
                                </div>
                                <dl className="mt-4 grid gap-2 text-sm">
                                    <div>
                                        <dt className="font-semibold text-slate-500">Stok Kodu</dt>
                                        <dd className="break-words font-bold text-slate-950">{formatValue(item.stock_code)}</dd>
                                    </div>
                                    <div>
                                        <dt className="font-semibold text-slate-500">Stok Adı</dt>
                                        <dd className="break-words font-bold text-slate-950">{formatValue(item.stock_name)}</dd>
                                    </div>
                                    <div className="grid grid-cols-2 gap-3">
                                        <div>
                                            <dt className="font-semibold text-slate-500">Seri No</dt>
                                            <dd className="break-words font-bold text-slate-950">{formatValue(item.serial_no)}</dd>
                                        </div>
                                        <div>
                                            <dt className="font-semibold text-slate-500">Miktar</dt>
                                            <dd className="font-bold text-slate-950">{formatQuantity(item.quantity)}</dd>
                                        </div>
                                    </div>
                                    <div className="grid grid-cols-2 gap-3">
                                        <div>
                                            <dt className="font-semibold text-slate-500">Son İşlem</dt>
                                            <dd className="break-words font-bold text-slate-950">{formatValue(item.last_operation_no)}</dd>
                                        </div>
                                        <div>
                                            <dt className="font-semibold text-slate-500">Son Güncelleme</dt>
                                            <dd className="break-words font-bold text-slate-950">{formatValue(item.updated_at)}</dd>
                                        </div>
                                    </div>
                                </dl>
                            </article>
                        ))}
                    </section>

                    <section className="hidden rounded-lg border border-slate-200 bg-white shadow-sm md:block">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200 text-left text-sm">
                                <thead className="bg-slate-50 text-xs font-bold uppercase text-slate-500">
                                    <tr>
                                        <th className="px-4 py-3">Raf</th>
                                        <th className="px-4 py-3">Stok Kodu</th>
                                        <th className="px-4 py-3">Stok Adı</th>
                                        <th className="px-4 py-3">Seri No</th>
                                        <th className="px-4 py-3">Miktar</th>
                                        <th className="px-4 py-3">Tip</th>
                                        <th className="px-4 py-3">Son İşlem</th>
                                        <th className="px-4 py-3">Son Güncelleme</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {items.map((item) => (
                                        <tr key={`${item.item_type}-${item.warehouse_no}-${item.rack_code}-${item.stock_code}-${item.serial_no ?? 'stock'}`} className="align-top">
                                            <td className="px-4 py-3 font-bold text-slate-950">
                                                <span className="block">{formatValue(item.rack_name ?? item.rack_code)}</span>
                                                <span className="text-xs font-semibold text-slate-500">{item.warehouse_name}</span>
                                            </td>
                                            <td className="px-4 py-3 font-semibold text-slate-800">{formatValue(item.stock_code)}</td>
                                            <td className="max-w-[260px] px-4 py-3 text-slate-700">{formatValue(item.stock_name)}</td>
                                            <td className="px-4 py-3 font-semibold text-slate-800">{formatValue(item.serial_no)}</td>
                                            <td className="px-4 py-3 font-bold text-slate-950">{formatQuantity(item.quantity)}</td>
                                            <td className="px-4 py-3">
                                                <span className="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-bold text-slate-700">
                                                    {itemTypeLabel(item.item_type)}
                                                </span>
                                                <span className="mt-1 block text-xs font-semibold text-slate-500">{statusLabel(item.status)}</span>
                                            </td>
                                            <td className="px-4 py-3 text-slate-700">{formatValue(item.last_operation_no)}</td>
                                            <td className="px-4 py-3 text-slate-700">{formatValue(item.updated_at)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </main>
        </>
    );
}
