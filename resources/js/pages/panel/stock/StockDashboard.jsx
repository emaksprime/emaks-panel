import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, Eye, EyeOff, Package, RefreshCw, Search, Trash2, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { EmptyState, ErrorBanner, LoadingOverlay } from '@/components/primecrm/StateBlocks.jsx';
import { formatQuantity } from '@/components/primecrm/format.js';
import { apiRequest } from '@/lib/api';
import {
    ALL_CATEGORIES,
    DEFAULT_STOCK_CATEGORY,
    categoryOptions,
    decorateStockRows,
    filterStockRows,
    sortStockRows,
    stockCategory,
    stockCategoryCode,
    stockCode,
    stockModel,
    stockName,
    stockQuantity,
} from './stockUtils.js';

const emptyForm = {
    threshold_quantity: '',
    active: true,
    note: '',
};

function CriticalBadge({ row }) {
    if (!row.isCritical) {
        return null;
    }

    return (
        <span className="inline-flex items-center gap-1 rounded-full border border-red-200 bg-red-50 px-2 py-1 text-xs font-bold text-red-700">
            <AlertTriangle className="size-3.5" />
            Kritik eşik: {formatQuantity(row.criticalThreshold)}
        </span>
    );
}

function StockCodeToggle({ row, visibleCodes, onToggle }) {
    const code = stockCode(row) || '-';
    const visible = visibleCodes.has(code);

    return (
        <div className="flex flex-col items-end gap-2">
            <button
                type="button"
                onClick={(event) => onToggle(event, code)}
                aria-label={visible ? 'Stok kodunu gizle' : 'Stok kodunu göster'}
                title={visible ? 'Stok kodunu gizle' : 'Stok kodunu göster'}
                className="inline-flex size-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 transition hover:border-blue-200 hover:text-blue-700"
            >
                {visible ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
            </button>
            {visible && (
                <span className="max-w-48 break-words rounded-md bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600" title={code}>
                    Stok Kodu: {code}
                </span>
            )}
        </div>
    );
}

function StockDetailDrawer({
    row,
    form,
    canManageCritical,
    saving,
    message,
    onClose,
    onFormChange,
    onSave,
    onRemove,
}) {
    if (!row) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-50 flex justify-end bg-slate-950/40 p-3 backdrop-blur-sm">
            <aside className="flex h-full w-full max-w-xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
                <div className="flex items-start justify-between gap-4 border-b border-slate-200 p-5">
                    <div>
                        <p className="text-xs font-semibold tracking-[0.16em] text-blue-700 uppercase">Stok Detayı</p>
                        <h2 className="mt-1 text-xl font-bold text-slate-950">{stockName(row)}</h2>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Detayı kapat"
                        className="inline-flex size-9 items-center justify-center rounded-lg border border-slate-200 text-slate-600 transition hover:bg-slate-50"
                    >
                        <X className="size-4" />
                    </button>
                </div>

                <div className="grid flex-1 content-start gap-5 overflow-y-auto p-5">
                    <dl className="grid gap-3 text-sm sm:grid-cols-2">
                        {[
                            ['Stok kodu', stockCode(row) || '-'],
                            ['Stok adı', stockName(row)],
                            ['Kategori kodu', stockCategoryCode(row) || '-'],
                            ['Kategori', stockCategory(row) || 'Kategori bilgisi yok'],
                            ['Model adı', stockModel(row) || '-'],
                            ['Toplam miktar', formatQuantity(stockQuantity(row))],
                        ].map(([label, value]) => (
                            <div key={label} className="rounded-xl border border-slate-200 bg-slate-50 p-3">
                                <dt className="text-xs font-semibold text-slate-500">{label}</dt>
                                <dd className="mt-1 break-words font-bold text-slate-950">{value}</dd>
                            </div>
                        ))}
                    </dl>

                    <CriticalBadge row={row} />

                    {canManageCritical && (
                        <form onSubmit={onSave} className="grid gap-4 rounded-2xl border border-blue-100 bg-blue-50 p-4">
                            <div>
                                <p className="text-xs font-semibold tracking-[0.14em] text-blue-700 uppercase">
                                    Kritik stok belirle
                                </p>
                                <p className="mt-1 text-sm text-slate-600">
                                    Bu eşik sadece panel ayarıdır; MSSQL stok sorgusu değiştirilmez.
                                </p>
                            </div>

                            <label className="grid gap-1 text-sm font-semibold text-slate-700">
                                Eşik miktar
                                <input
                                    type="number"
                                    min="0.01"
                                    step="0.01"
                                    value={form.threshold_quantity}
                                    onChange={(event) => onFormChange({ ...form, threshold_quantity: event.target.value })}
                                    className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-slate-900 outline-none focus:border-blue-400"
                                    required
                                />
                            </label>

                            <label className="flex items-center gap-2 text-sm font-semibold text-slate-700">
                                <input
                                    type="checkbox"
                                    checked={form.active}
                                    onChange={(event) => onFormChange({ ...form, active: event.target.checked })}
                                    className="size-4 rounded border-slate-300 text-blue-700"
                                />
                                Kritik takibi aktif
                            </label>

                            <label className="grid gap-1 text-sm font-semibold text-slate-700">
                                Not
                                <textarea
                                    value={form.note}
                                    onChange={(event) => onFormChange({ ...form, note: event.target.value })}
                                    className="min-h-20 rounded-xl border border-slate-200 bg-white px-3 py-2 text-slate-900 outline-none focus:border-blue-400"
                                    placeholder="İsteğe bağlı açıklama"
                                />
                            </label>

                            {message && (
                                <p className="rounded-xl border border-blue-200 bg-white px-3 py-2 text-sm font-semibold text-blue-700">
                                    {message}
                                </p>
                            )}

                            <div className="flex flex-wrap gap-2">
                                <button
                                    type="submit"
                                    disabled={saving}
                                    className="rounded-xl bg-slate-950 px-4 py-2 text-sm font-bold text-white transition hover:bg-slate-800 disabled:opacity-50"
                                >
                                    Kaydet
                                </button>
                                <button
                                    type="button"
                                    onClick={onRemove}
                                    disabled={saving || !row.criticalSetting}
                                    className="inline-flex items-center gap-2 rounded-xl border border-red-200 bg-white px-4 py-2 text-sm font-bold text-red-700 transition hover:bg-red-50 disabled:opacity-50"
                                >
                                    <Trash2 className="size-4" />
                                    Kritik takibi kaldır
                                </button>
                            </div>
                        </form>
                    )}
                </div>
            </aside>
        </div>
    );
}

export default function StockDashboard({ page, mode = 'list' }) {
    const [filters, setFilters] = useState({
        search: '',
        category: DEFAULT_STOCK_CATEGORY,
        bypass_cache: false,
    });
    const [stockData, setStockData] = useState({ rows: [], columns: [], queryMeta: null });
    const [settingsData, setSettingsData] = useState({ rows: [], can_manage: false });
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(true);
    const [refreshKey, setRefreshKey] = useState(0);
    const [visibleCodes, setVisibleCodes] = useState(new Set());
    const [selectedRow, setSelectedRow] = useState(null);
    const [form, setForm] = useState(emptyForm);
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState('');

    useEffect(() => {
        let cancelled = false;

        void Promise.all([
            apiRequest('/api/data/stock', {
                method: 'POST',
                body: JSON.stringify({
                    bypass_cache: filters.bypass_cache,
                }),
            }),
            apiRequest('/api/stock/critical-settings'),
        ])
            .then(([stockResult, settingsResult]) => {
                if (cancelled) {
                    return;
                }

                setStockData({
                    rows: Array.isArray(stockResult.rows) ? stockResult.rows : [],
                    columns: Array.isArray(stockResult.columns) ? stockResult.columns : [],
                    queryMeta: stockResult.queryMeta ?? null,
                });
                setSettingsData({
                    rows: Array.isArray(settingsResult.rows) ? settingsResult.rows : [],
                    can_manage: Boolean(settingsResult.can_manage),
                });
                setError(null);
            })
            .catch((requestError) => {
                if (cancelled) {
                    return;
                }

                setError(requestError?.message ?? 'Stok verisi alınamadı.');
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [refreshKey, filters.bypass_cache]);

    const decoratedRows = decorateStockRows(stockData.rows, settingsData.rows);
    const filteredRows = sortStockRows(filterStockRows(decoratedRows, {
        ...filters,
        mode,
    }));
    const options = categoryOptions(decoratedRows);
    const criticalCount = decoratedRows.filter((row) => row.isCritical).length;
    const totalQuantity = decoratedRows.reduce((sum, row) => sum + stockQuantity(row), 0);
    const canManageCritical = settingsData.can_manage;

    const toggleStockCode = (event, code) => {
        event.stopPropagation();
        setVisibleCodes((current) => {
            const next = new Set(current);

            if (next.has(code)) {
                next.delete(code);
            } else {
                next.add(code);
            }

            return next;
        });
    };

    const openDetails = (row) => {
        const setting = row.criticalSetting;

        setSelectedRow(row);
        setMessage('');
        setForm({
            threshold_quantity: setting ? String(setting.threshold_quantity ?? '') : '',
            active: Boolean(setting?.active ?? true),
            note: String(setting?.note ?? ''),
        });
    };

    const refresh = () => {
        setLoading(true);
        setFilters((current) => ({ ...current, bypass_cache: true }));
        setRefreshKey((current) => current + 1);
    };

    const saveCriticalSetting = async (event) => {
        event.preventDefault();

        if (!selectedRow) {
            return;
        }

        setSaving(true);
        setMessage('');

        try {
            await apiRequest('/api/stock/critical-settings', {
                method: 'POST',
                body: JSON.stringify({
                    stock_code: stockCode(selectedRow),
                    product_name: stockName(selectedRow),
                    category: stockCategory(selectedRow),
                    threshold_quantity: form.threshold_quantity,
                    active: form.active,
                    note: form.note,
                }),
            });
            setMessage('Kritik stok ayarı kaydedildi.');
            setRefreshKey((current) => current + 1);
        } catch (requestError) {
            setMessage(requestError?.message ?? 'Kritik stok ayarı kaydedilemedi.');
        } finally {
            setSaving(false);
        }
    };

    const removeCriticalSetting = async () => {
        if (!selectedRow) {
            return;
        }

        setSaving(true);
        setMessage('');

        try {
            await apiRequest(`/api/stock/critical-settings/${encodeURIComponent(stockCode(selectedRow))}`, {
                method: 'DELETE',
            });
            setMessage('Kritik stok takibi kaldırıldı.');
            setRefreshKey((current) => current + 1);
        } catch (requestError) {
            setMessage(requestError?.message ?? 'Kritik stok takibi kaldırılamadı.');
        } finally {
            setSaving(false);
        }
    };

    const tabClass = (active) => active
        ? 'rounded-xl bg-slate-950 px-4 py-2 text-sm font-bold text-white'
        : 'rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 transition hover:border-blue-200 hover:text-blue-700';

    return (
        <>
            <Head title={page?.title ?? 'Stok Yönetimi'} />
            <main className="grid gap-5 p-4 md:p-6">
                <section className="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm lg:grid-cols-[1fr_auto]">
                    <div className="flex items-start gap-3">
                        <span className="grid size-12 place-items-center rounded-2xl bg-slate-950 text-white">
                            <Package className="size-6" />
                        </span>
                        <div>
                            <p className="text-xs font-semibold tracking-[0.18em] text-blue-700 uppercase">Stok Yönetimi</p>
                            <h1 className="mt-1 text-2xl font-bold text-slate-950">Stok Yönetimi</h1>
                            <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                                Ürün stok listesi. Kritik stok takibi sadece admin tarafından panel ayarıyla belirlenen ürünlerde çalışır.
                            </p>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Link href="/stock" className={tabClass(mode !== 'critical')}>Stok Listesi</Link>
                        <Link href="/stock/critical" className={tabClass(mode === 'critical')}>Kritik Stoklar</Link>
                    </div>
                </section>

                <section className="grid gap-3 md:grid-cols-3">
                    {[
                        ['Kayıt', filteredRows.length],
                        ['Toplam Stok', formatQuantity(totalQuantity)],
                        ['Kritik Stok', criticalCount],
                    ].map(([label, value]) => (
                        <div key={label} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                            <p className="text-xs font-semibold tracking-[0.14em] text-slate-500 uppercase">{label}</p>
                            <p className="mt-2 text-2xl font-bold text-slate-950">{value}</p>
                        </div>
                    ))}
                </section>

                <section className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm lg:grid-cols-[minmax(0,1fr)_260px_auto]">
                    <label className="relative">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                        <input
                            type="search"
                            value={filters.search}
                            onChange={(event) => setFilters((current) => ({ ...current, search: event.target.value }))}
                            placeholder="Stok kodu, ürün, model veya kategori ara"
                            className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pr-3 pl-10 text-sm outline-none transition focus:border-blue-400 focus:bg-white"
                        />
                    </label>

                    <select
                        value={filters.category}
                        onChange={(event) => setFilters((current) => ({ ...current, category: event.target.value }))}
                        className="h-11 rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm font-semibold text-slate-700 outline-none transition focus:border-blue-400 focus:bg-white"
                    >
                        {options.map((option) => (
                            <option key={option} value={option}>{option}</option>
                        ))}
                        {!options.includes(DEFAULT_STOCK_CATEGORY) && (
                            <option value={DEFAULT_STOCK_CATEGORY}>{DEFAULT_STOCK_CATEGORY}</option>
                        )}
                    </select>

                    <button
                        type="button"
                        onClick={refresh}
                        className="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-bold text-slate-700 transition hover:border-blue-200 hover:text-blue-700"
                    >
                        <RefreshCw className="size-4" />
                        Yenile
                    </button>
                </section>

                {error && <ErrorBanner message={error} />}

                <section className="relative rounded-2xl border border-slate-200 bg-white shadow-sm">
                    {loading && <LoadingOverlay />}

                    {filteredRows.length === 0 && !loading ? (
                        <EmptyState title="Canlı veri bulunamadı" message={mode === 'critical' ? 'Eşik altına düşen admin tanımlı kritik stok yok.' : 'Seçili filtrelerde stok satırı yok.'} />
                    ) : (
                        <>
                            <div className="hidden overflow-x-auto md:block">
                                <table className="w-full table-fixed text-left text-sm">
                                    <thead className="bg-slate-50 text-xs font-bold tracking-[0.12em] text-slate-500 uppercase">
                                        <tr>
                                            <th className="w-[42%] px-4 py-3">Ürün / Model</th>
                                            <th className="w-[22%] px-4 py-3">Kategori</th>
                                            <th className="w-[14%] px-4 py-3 text-right">Miktar</th>
                                            <th className="w-[22%] px-4 py-3 text-right">İşlem</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {filteredRows.map((row) => (
                                            <tr
                                                key={stockCode(row) || stockName(row)}
                                                onClick={() => openDetails(row)}
                                                className={row.isCritical ? 'cursor-pointer bg-red-50/70 ring-1 ring-red-100' : 'cursor-pointer bg-white transition hover:bg-slate-50'}
                                            >
                                                <td className="px-4 py-4 align-top">
                                                    <div className="grid gap-1">
                                                        <strong className="break-words text-slate-950">{stockName(row)}</strong>
                                                        {stockModel(row) && <span className="text-xs font-semibold text-slate-500">{stockModel(row)}</span>}
                                                        <CriticalBadge row={row} />
                                                    </div>
                                                </td>
                                                <td className="px-4 py-4 align-top">
                                                    <span className="inline-flex max-w-full rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold break-words text-slate-600">
                                                        {stockCategory(row) || 'Kategori bilgisi yok'}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-4 text-right align-top font-bold text-slate-950">{formatQuantity(stockQuantity(row))}</td>
                                                <td className="px-4 py-4 align-top">
                                                    <div className="flex items-start justify-end gap-2">
                                                        <StockCodeToggle row={row} visibleCodes={visibleCodes} onToggle={toggleStockCode} />
                                                        <button
                                                            type="button"
                                                            onClick={(event) => {
                                                                event.stopPropagation();
                                                                openDetails(row);
                                                            }}
                                                            className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:border-blue-200 hover:text-blue-700"
                                                        >
                                                            Detay
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            <div className="grid gap-3 p-3 md:hidden">
                                {filteredRows.map((row) => (
                                    <article
                                        key={stockCode(row) || stockName(row)}
                                        className={row.isCritical ? 'rounded-2xl border border-red-200 bg-red-50 p-4' : 'rounded-2xl border border-slate-200 bg-white p-4'}
                                    >
                                        <button type="button" onClick={() => openDetails(row)} className="grid w-full gap-3 text-left">
                                            <div>
                                                <h3 className="break-words text-base font-bold text-slate-950">{stockName(row)}</h3>
                                                {stockModel(row) && <p className="mt-1 text-xs font-semibold text-slate-500">{stockModel(row)}</p>}
                                            </div>
                                            <div className="grid gap-2 text-sm text-slate-600">
                                                <span><strong>Kategori:</strong> {stockCategory(row) || 'Kategori bilgisi yok'}</span>
                                                <span><strong>Miktar:</strong> {formatQuantity(stockQuantity(row))}</span>
                                            </div>
                                            <CriticalBadge row={row} />
                                        </button>
                                        <div className="mt-3 flex items-start justify-between gap-2">
                                            <StockCodeToggle row={row} visibleCodes={visibleCodes} onToggle={toggleStockCode} />
                                            <button
                                                type="button"
                                                onClick={() => openDetails(row)}
                                                className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-700"
                                            >
                                                Detay
                                            </button>
                                        </div>
                                    </article>
                                ))}
                            </div>
                        </>
                    )}
                </section>
            </main>

            <StockDetailDrawer
                row={selectedRow}
                form={form}
                canManageCritical={canManageCritical}
                saving={saving}
                message={message}
                onClose={() => setSelectedRow(null)}
                onFormChange={setForm}
                onSave={saveCriticalSetting}
                onRemove={removeCriticalSetting}
            />
        </>
    );
}
