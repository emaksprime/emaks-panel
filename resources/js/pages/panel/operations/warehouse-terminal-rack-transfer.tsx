import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, History, RotateCcw, Search, ShieldCheck, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { apiRequest } from '@/lib/api';

type Message = {
    type: 'error' | 'success' | 'info';
    text: string;
};

type WarehouseLookup = {
    warehouse_no: number;
    warehouse_name: string;
};

type RackLookup = {
    rack_code: string;
    rack_name: string;
};

type ItemLookup = {
    match_type: 'serial' | 'barcode' | 'stock_code' | 'stock_name' | string;
    stock_code: string | null;
    stock_name: string | null;
    barcode: string | null;
    serial_no: string | null;
    is_serial_tracked: boolean;
    display_label: string;
};

type LookupResponse<T> = {
    items?: T[];
    message?: string | null;
};

type TransferSummary = {
    operation_no?: string | null;
    warehouse_no?: number | string | null;
    warehouse_name?: string | null;
    source_rack_code?: string | null;
    target_rack_code?: string | null;
    item_type?: string | null;
    serial_numbers?: string[];
    serial_count?: number;
    stock_code?: string | null;
    stock_name?: string | null;
    quantity?: number | string | null;
    operation_status?: string | null;
};

type TransferResponse = {
    ok?: boolean;
    message?: string;
    operation_no?: string;
    summary?: TransferSummary;
};

type HistoryDetail = {
    line_no: number;
    item_type: string;
    serial_no: string | null;
    stock_code: string;
    stock_name: string | null;
    barcode: string | null;
    quantity: number | string;
    status: string;
};

type HistoryRow = {
    date: string | null;
    operation_no: string;
    warehouse_no: number | string | null;
    warehouse_name: string | null;
    source_rack_code: string | null;
    target_rack_code: string | null;
    stock_code: string | null;
    stock_name: string | null;
    serial_count: number;
    total_quantity: number | string;
    user: string | null;
    status: string;
    items: HistoryDetail[];
};

const lookupWarehousesUrl = '/api/operations/warehouse-terminal/lookups/warehouses';
const lookupRacksUrl = '/api/operations/warehouse-terminal/lookups/racks';
const lookupItemsUrl = '/api/operations/warehouse-terminal/lookups/items';
const rackTransferUrl = '/api/operations/warehouse-terminal/rack-transfer/transfer';
const rackTransferHistoryUrl = '/api/operations/warehouse-terminal/rack-transfer/history';

const safeNotes = [
    'Aynı depo içi raf transferleri Mikro’ya yazılmaz.',
    'Raf lokasyonu Panel/PostgreSQL tarafında tutulur.',
    'Mikro resmi depo/stok miktarı için kaynak olmaya devam eder.',
    'Seri takipli ürünlerde seri no okutulmadan transfer yapılmaz.',
    'Silme işlemi yapılmayacak; ileride iptal/ters işlem mantığı kurulacak.',
];

function todayIso(): string {
    return new Date().toISOString().slice(0, 10);
}

function daysAgoIso(days: number): string {
    const date = new Date();
    date.setDate(date.getDate() - days);

    return date.toISOString().slice(0, 10);
}

function normalizeQuantityInput(value: string): string {
    return value.trim().replace(',', '.') || '1';
}

function formatValue(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '-';
    }

    return String(value);
}

function itemTypeLabel(value: string | null | undefined): string {
    if (value === 'serial') {
        return 'Serili';
    }

    if (value === 'stock') {
        return 'Stok / adetli';
    }

    return '-';
}

function buildQuery(params: Record<string, string | number | null | undefined>): string {
    const query = new URLSearchParams();

    Object.entries(params).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== '') {
            query.set(key, String(value));
        }
    });

    return query.toString();
}

export default function WarehouseTerminalRackTransfer() {
    const warehouseSelectRef = useRef<HTMLSelectElement | null>(null);
    const [activeTab, setActiveTab] = useState<'transfer' | 'history'>('transfer');
    const [warehouses, setWarehouses] = useState<WarehouseLookup[]>([]);
    const [sourceRacks, setSourceRacks] = useState<RackLookup[]>([]);
    const [targetRacks, setTargetRacks] = useState<RackLookup[]>([]);
    const [warehouseNo, setWarehouseNo] = useState('');
    const [sourceRack, setSourceRack] = useState('');
    const [targetRack, setTargetRack] = useState('');
    const [itemQuery, setItemQuery] = useState('');
    const [itemResults, setItemResults] = useState<ItemLookup[]>([]);
    const [selectedItem, setSelectedItem] = useState<ItemLookup | null>(null);
    const [serialInput, setSerialInput] = useState('');
    const [serialNumbers, setSerialNumbers] = useState<string[]>([]);
    const [quantity, setQuantity] = useState('1');
    const [summary, setSummary] = useState<TransferSummary | null>(null);
    const [status, setStatus] = useState('Bekliyor');
    const [message, setMessage] = useState<Message | null>(null);
    const [lookupMessage, setLookupMessage] = useState<string | null>(null);
    const [loadingWarehouses, setLoadingWarehouses] = useState(false);
    const [loadingRacks, setLoadingRacks] = useState(false);
    const [searchingItems, setSearchingItems] = useState(false);
    const [transferring, setTransferring] = useState(false);
    const [historyFrom, setHistoryFrom] = useState(daysAgoIso(7));
    const [historyTo, setHistoryTo] = useState(todayIso());
    const [historyWarehouseNo, setHistoryWarehouseNo] = useState('');
    const [historyRows, setHistoryRows] = useState<HistoryRow[]>([]);
    const [historyMessage, setHistoryMessage] = useState<Message | null>(null);
    const [historyLoading, setHistoryLoading] = useState(false);
    const [expandedOperation, setExpandedOperation] = useState<string | null>(null);

    const selectedWarehouse = useMemo(
        () => warehouses.find((warehouse) => String(warehouse.warehouse_no) === warehouseNo) ?? null,
        [warehouseNo, warehouses],
    );
    const isSerialTracked = Boolean(selectedItem?.is_serial_tracked || selectedItem?.serial_no);
    const effectiveQuantity = isSerialTracked ? String(serialNumbers.length) : quantity;
    const isBusy = loadingWarehouses || loadingRacks || searchingItems || transferring;
    const transferCompleted = status === 'Tamamlandı' && Boolean(summary?.operation_no);
    const transferButtonLabel = transferCompleted ? 'Transfer tamamlandı' : (transferring ? 'Transfer ediliyor' : 'Transfer Et');

    const resetTransferState = (nextStatus = 'Bekliyor') => {
        setSummary(null);
        setStatus(nextStatus);
        setMessage(null);
    };

    const resetItemState = () => {
        setItemQuery('');
        setItemResults([]);
        setSelectedItem(null);
        setSerialInput('');
        setSerialNumbers([]);
        setQuantity('1');
        resetTransferState();
    };

    const loadWarehouses = useCallback(async () => {
        setLoadingWarehouses(true);
        setLookupMessage(null);

        try {
            const response = await apiRequest(lookupWarehousesUrl) as LookupResponse<WarehouseLookup>;
            setWarehouses(response.items ?? []);
            setLookupMessage(response.message ?? null);
        } catch (caught) {
            setLookupMessage(caught instanceof Error ? caught.message : 'Depo listesi alınamadı.');
        } finally {
            setLoadingWarehouses(false);
        }
    }, []);

    const loadRacks = useCallback(async (nextWarehouseNo: string) => {
        setLoadingRacks(true);
        setLookupMessage(null);

        try {
            const [sourceResponse, targetResponse] = await Promise.all([
                apiRequest(`${lookupRacksUrl}?${buildQuery({ warehouse_no: nextWarehouseNo, type: 'source' })}`) as Promise<LookupResponse<RackLookup>>,
                apiRequest(`${lookupRacksUrl}?${buildQuery({ warehouse_no: nextWarehouseNo, type: 'target' })}`) as Promise<LookupResponse<RackLookup>>,
            ]);

            setSourceRacks(sourceResponse.items ?? []);
            setTargetRacks(targetResponse.items ?? []);
            setLookupMessage(sourceResponse.message ?? targetResponse.message ?? null);
        } catch (caught) {
            setLookupMessage(caught instanceof Error ? caught.message : 'Raf listesi alınamadı.');
        } finally {
            setLoadingRacks(false);
        }
    }, []);

    useEffect(() => {
        warehouseSelectRef.current?.focus();
        const timer = window.setTimeout(() => {
            void loadWarehouses();
        }, 0);

        return () => window.clearTimeout(timer);
    }, [loadWarehouses]);

    const searchItems = async () => {
        if (transferCompleted) {
            return;
        }

        if (!warehouseNo) {
            setMessage({ type: 'error', text: 'Önce depo seçilmelidir.' });

            return;
        }

        const query = itemQuery.trim();

        if (query.length < 2) {
            setMessage({ type: 'error', text: 'Ürün araması için en az 2 karakter girilmelidir.' });

            return;
        }

        setSearchingItems(true);
        setSelectedItem(null);
        setItemResults([]);
        resetTransferState('Ürün aranıyor');

        try {
            const response = await apiRequest(`${lookupItemsUrl}?${buildQuery({ warehouse_no: warehouseNo, q: query })}`) as LookupResponse<ItemLookup>;
            setItemResults(response.items ?? []);
            setMessage(response.message ? { type: 'info', text: response.message } : null);
            setStatus('Ürün seçimi bekleniyor');
        } catch (caught) {
            setStatus('Uyarı');
            setMessage({
                type: 'error',
                text: caught instanceof Error ? caught.message : 'Ürün araması yapılamadı.',
            });
        } finally {
            setSearchingItems(false);
        }
    };

    const selectItem = (item: ItemLookup) => {
        if (transferCompleted) {
            return;
        }

        setSelectedItem(item);
        setItemQuery(item.display_label || item.serial_no || item.stock_code || item.barcode || '');
        setItemResults([]);
        setQuantity('1');
        setSerialNumbers(item.is_serial_tracked && item.serial_no ? [item.serial_no] : []);
        setSerialInput('');
        resetTransferState('Transfer bekliyor');
    };

    const addSerial = () => {
        if (transferCompleted) {
            return;
        }

        const serial = serialInput.trim();

        if (!serial) {
            return;
        }

        if (serialNumbers.some((value) => value.toLocaleUpperCase('tr-TR') === serial.toLocaleUpperCase('tr-TR'))) {
            setMessage({ type: 'error', text: 'Aynı seri ikinci kez eklenemez.' });

            return;
        }

        setSerialNumbers((current) => [...current, serial]);
        setSerialInput('');
        resetTransferState('Transfer bekliyor');
    };

    const removeSerial = (serial: string) => {
        if (transferCompleted) {
            return;
        }

        setSerialNumbers((current) => current.filter((value) => value !== serial));
        resetTransferState('Transfer bekliyor');
    };

    const clearTransfer = () => {
        setWarehouseNo('');
        setSourceRacks([]);
        setTargetRacks([]);
        setSourceRack('');
        setTargetRack('');
        resetItemState();
        setLookupMessage(null);
        warehouseSelectRef.current?.focus();
    };

    const transferPayload = () => ({
        warehouse_no: Number(warehouseNo),
        warehouse_name: selectedWarehouse?.warehouse_name ?? null,
        source_rack_code: sourceRack,
        target_rack_code: targetRack,
        stock_code: selectedItem?.stock_code ?? '',
        item_code: selectedItem?.serial_no ?? selectedItem?.stock_code ?? itemQuery.trim(),
        quantity: isSerialTracked ? serialNumbers.length : normalizeQuantityInput(quantity),
        serial_numbers: serialNumbers,
        is_serial_tracked: isSerialTracked,
        barcode: selectedItem?.barcode ?? null,
        selection_type: selectedItem?.match_type ?? null,
    });

    const handleTransfer = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (transferCompleted) {
            return;
        }

        if (!warehouseNo) {
            setMessage({ type: 'error', text: 'Depo seçimi zorunludur.' });

            return;
        }

        if (!sourceRack || !targetRack) {
            setMessage({ type: 'error', text: 'Kaynak ve hedef raf seçilmelidir.' });

            return;
        }

        if (sourceRack.toLocaleUpperCase('tr-TR') === targetRack.toLocaleUpperCase('tr-TR')) {
            setMessage({ type: 'error', text: 'Kaynak raf ile hedef raf aynı olamaz.' });

            return;
        }

        if (!selectedItem && !itemQuery.trim()) {
            setMessage({ type: 'error', text: 'Ürün seçimi zorunludur.' });

            return;
        }

        if (isSerialTracked && serialNumbers.length === 0) {
            setMessage({ type: 'error', text: 'Seri takipli ürünlerde seri no zorunludur.' });

            return;
        }

        if (!isSerialTracked && Number(normalizeQuantityInput(quantity)) <= 0) {
            setMessage({ type: 'error', text: 'Miktar sıfırdan büyük olmalıdır.' });

            return;
        }

        setTransferring(true);
        setStatus('Transfer ediliyor');
        setMessage(null);

        try {
            const response = await apiRequest(rackTransferUrl, {
                method: 'POST',
                body: JSON.stringify(transferPayload()),
            }) as TransferResponse;

            if (response.ok === false) {
                throw new Error(response.message || 'Raf transferi tamamlanamadı.');
            }

            setSummary(response.summary ?? null);
            setStatus('Tamamlandı');
            setMessage({
                type: 'success',
                text: response.message || 'Raf transferi tamamlandı. Mikro’ya yazma yapılmadı.',
            });
        } catch (caught) {
            setStatus('Hata');
            setMessage({
                type: 'error',
                text: caught instanceof Error ? caught.message : 'Raf transferi tamamlanamadı.',
            });
        } finally {
            setTransferring(false);
        }
    };

    const loadHistory = async () => {
        setHistoryLoading(true);
        setHistoryMessage(null);
        setExpandedOperation(null);

        try {
            const response = await apiRequest(`${rackTransferHistoryUrl}?${buildQuery({
                date_from: historyFrom,
                date_to: historyTo,
                warehouse_no: historyWarehouseNo,
            })}`) as LookupResponse<HistoryRow>;

            setHistoryRows(response.items ?? []);
            setHistoryMessage((response.items ?? []).length === 0 ? { type: 'info', text: 'Seçilen aralıkta transfer bulunamadı.' } : null);
        } catch (caught) {
            setHistoryMessage({
                type: 'error',
                text: caught instanceof Error ? caught.message : 'Transfer geçmişi alınamadı.',
            });
        } finally {
            setHistoryLoading(false);
        }
    };

    const messageClassName = (messageValue: Message) => {
        if (messageValue.type === 'success') {
            return 'border-emerald-200 bg-emerald-50 text-emerald-800';
        }

        if (messageValue.type === 'info') {
            return 'border-amber-200 bg-amber-50 text-amber-900';
        }

        return 'border-amber-200 bg-amber-50 text-amber-900';
    };

    return (
        <>
            <Head title="Raf Transferi" />

            <main className="min-h-[calc(100vh-4.5rem)] bg-slate-100 px-3 py-4 sm:px-4 md:px-6 md:py-6">
                <div className="mx-auto grid w-full max-w-[900px] gap-4">
                    <section className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm md:p-6">
                        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div className="min-w-0">
                                <p className="text-xs font-bold uppercase text-blue-700">
                                    Depo Terminal
                                </p>
                                <h1 className="mt-2 text-2xl font-semibold text-slate-950 [font-family:var(--font-display)] md:text-3xl">
                                    Raf Transferi
                                </h1>
                                <p className="mt-2 text-sm leading-6 text-slate-600 md:text-base">
                                    Kaynak raf, ürün/seri ve hedef raf seçilerek aynı depo içi raf transferi Panel/PostgreSQL tarafında tamamlanır.
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

                    <div className="grid grid-cols-2 gap-2 rounded-lg border border-slate-200 bg-white p-2 shadow-sm">
                        <button
                            type="button"
                            onClick={() => setActiveTab('transfer')}
                            className={`inline-flex h-14 items-center justify-center gap-2 rounded-lg text-base font-bold transition ${
                                activeTab === 'transfer' ? 'bg-slate-950 text-white' : 'bg-white text-slate-700 hover:bg-slate-50'
                            }`}
                        >
                            <CheckCircle2 className="size-5" />
                            Transfer Et
                        </button>
                        <button
                            type="button"
                            onClick={() => setActiveTab('history')}
                            className={`inline-flex h-14 items-center justify-center gap-2 rounded-lg text-base font-bold transition ${
                                activeTab === 'history' ? 'bg-slate-950 text-white' : 'bg-white text-slate-700 hover:bg-slate-50'
                            }`}
                        >
                            <History className="size-5" />
                            Transfer Geçmişi
                        </button>
                    </div>

                    {activeTab === 'transfer' ? (
                        <>
                            <form onSubmit={handleTransfer} className="grid gap-4 rounded-lg border border-slate-200 bg-white p-4 shadow-sm md:p-6">
                                {lookupMessage ? (
                                    <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">
                                        {lookupMessage}
                                    </div>
                                ) : null}

                                <label className="grid gap-2 text-sm font-bold text-slate-800">
                                    Depo
                                    <select
                                        ref={warehouseSelectRef}
                                        value={warehouseNo}
                                        onChange={(event) => {
                                            const nextWarehouseNo = event.target.value;

                                            setWarehouseNo(nextWarehouseNo);
                                            setSourceRack('');
                                            setTargetRack('');
                                            resetItemState();

                                            if (!nextWarehouseNo) {
                                                setSourceRacks([]);
                                                setTargetRacks([]);

                                                return;
                                            }

                                            void loadRacks(nextWarehouseNo);
                                        }}
                                        disabled={loadingWarehouses || transferCompleted}
                                        className="h-14 rounded-lg border border-slate-300 bg-white px-4 text-lg font-semibold text-slate-950 outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100 disabled:opacity-60"
                                    >
                                        <option value="">{loadingWarehouses ? 'Depolar yükleniyor' : 'Depo seçin'}</option>
                                        {warehouses.map((warehouse) => (
                                            <option key={warehouse.warehouse_no} value={warehouse.warehouse_no}>
                                                {warehouse.warehouse_name}
                                            </option>
                                        ))}
                                    </select>
                                </label>

                                <div className="grid gap-4 md:grid-cols-2">
                                    <label className="grid gap-2 text-sm font-bold text-slate-800">
                                        Kaynak Raf
                                        <select
                                            value={sourceRack}
                                            onChange={(event) => {
                                                setSourceRack(event.target.value);
                                                resetTransferState('Transfer bekliyor');
                                            }}
                                            disabled={!warehouseNo || loadingRacks || transferCompleted}
                                            className="h-14 rounded-lg border border-slate-300 bg-white px-4 text-lg font-semibold text-slate-950 outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100 disabled:opacity-60"
                                        >
                                            <option value="">{loadingRacks ? 'Raflar yükleniyor' : 'Kaynak raf seçin'}</option>
                                            {sourceRacks.map((rack) => (
                                                <option key={rack.rack_code} value={rack.rack_code}>
                                                    {rack.rack_name}
                                                </option>
                                            ))}
                                        </select>
                                    </label>

                                    <label className="grid gap-2 text-sm font-bold text-slate-800">
                                        Hedef Raf
                                        <select
                                            value={targetRack}
                                            onChange={(event) => {
                                                setTargetRack(event.target.value);
                                                resetTransferState('Transfer bekliyor');
                                            }}
                                            disabled={!warehouseNo || loadingRacks || transferCompleted}
                                            className="h-14 rounded-lg border border-slate-300 bg-white px-4 text-lg font-semibold text-slate-950 outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100 disabled:opacity-60"
                                        >
                                            <option value="">{loadingRacks ? 'Raflar yükleniyor' : 'Hedef raf seçin'}</option>
                                            {targetRacks.map((rack) => (
                                                <option key={rack.rack_code} value={rack.rack_code}>
                                                    {rack.rack_name}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                </div>

                                <div className="grid gap-3">
                                    <label className="grid gap-2 text-sm font-bold text-slate-800">
                                        Ürün / Seri / Barkod
                                        <div className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto]">
                                            <input
                                                value={itemQuery}
                                                onChange={(event) => {
                                                    setItemQuery(event.target.value);
                                                    setSelectedItem(null);
                                                    setItemResults([]);
                                                    setSerialNumbers([]);
                                                    resetTransferState('Ürün seçimi bekleniyor');
                                                }}
                                                placeholder="Barkod, stok kodu, stok adı veya seri no okutun"
                                                autoComplete="off"
                                                disabled={transferCompleted}
                                                className="h-14 rounded-lg border border-slate-300 bg-white px-4 text-lg font-semibold text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100 disabled:opacity-60"
                                            />
                                            <button
                                                type="button"
                                                onClick={searchItems}
                                                disabled={searchingItems || !warehouseNo || transferCompleted}
                                                className="inline-flex h-14 items-center justify-center gap-2 rounded-lg bg-slate-900 px-5 text-base font-bold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                <Search className="size-5" />
                                                {searchingItems ? 'Araniyor' : 'Ara'}
                                            </button>
                                        </div>
                                    </label>

                                    {itemResults.length > 0 ? (
                                        <div className="grid gap-2 rounded-lg border border-slate-200 bg-slate-50 p-2">
                                            {itemResults.map((item) => (
                                                <button
                                                    type="button"
                                                    key={`${item.match_type}-${item.stock_code}-${item.serial_no}-${item.barcode}`}
                                                    onClick={() => selectItem(item)}
                                                    disabled={transferCompleted}
                                                    className="grid gap-1 rounded-lg border border-slate-200 bg-white p-3 text-left transition hover:border-blue-300 hover:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-60"
                                                >
                                                    <span className="text-base font-bold text-slate-950">{item.display_label}</span>
                                                    <span className="text-xs font-semibold text-slate-500">
                                                        {item.is_serial_tracked ? 'Seri takipli' : 'Adetli'} | {item.match_type}
                                                    </span>
                                                </button>
                                            ))}
                                        </div>
                                    ) : null}

                                    {selectedItem ? (
                                        <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm font-semibold text-emerald-900">
                                            Seçili ürün: {selectedItem.display_label}
                                        </div>
                                    ) : null}
                                </div>

                                {isSerialTracked ? (
                                    <div className="grid gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
                                        <label className="grid gap-2 text-sm font-bold text-slate-800">
                                            Okutulan Seriler
                                            <div className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto]">
                                                <input
                                                    value={serialInput}
                                                    onChange={(event) => setSerialInput(event.target.value)}
                                                    onKeyDown={(event) => {
                                                        if (event.key === 'Enter') {
                                                            event.preventDefault();
                                                            addSerial();
                                                        }
                                                    }}
                                                    placeholder="Seri no okutun"
                                                    autoComplete="off"
                                                    disabled={transferCompleted}
                                                    className="h-14 rounded-lg border border-slate-300 bg-white px-4 text-lg font-semibold text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100 disabled:opacity-60"
                                                />
                                                <button
                                                    type="button"
                                                    onClick={addSerial}
                                                    disabled={transferCompleted}
                                                    className="inline-flex h-14 items-center justify-center rounded-lg bg-blue-700 px-5 text-base font-bold text-white transition hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-60"
                                                >
                                                    Ekle
                                                </button>
                                            </div>
                                        </label>
                                        <div className="grid gap-2">
                                            {serialNumbers.length === 0 ? (
                                                <p className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-500">
                                                    Henüz seri okutulmadı.
                                                </p>
                                            ) : serialNumbers.map((serial) => (
                                                <div key={serial} className="flex items-center justify-between gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2">
                                                    <span className="min-w-0 break-all text-base font-bold text-slate-950">{serial}</span>
                                                    <button
                                                        type="button"
                                                        onClick={() => removeSerial(serial)}
                                                        disabled={transferCompleted}
                                                        className="grid size-11 shrink-0 place-items-center rounded-lg border border-slate-200 text-slate-600 transition hover:border-red-200 hover:bg-red-50 hover:text-red-700 disabled:cursor-not-allowed disabled:opacity-60"
                                                        aria-label={`${serial} serisini kaldır`}
                                                    >
                                                        <Trash2 className="size-5" />
                                                    </button>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                ) : null}

                                <label className="grid gap-2 text-sm font-bold text-slate-800">
                                    Miktar
                                    <input
                                        value={effectiveQuantity}
                                        onChange={(event) => {
                                            setQuantity(event.target.value);
                                            resetTransferState('Transfer bekliyor');
                                        }}
                                        placeholder="Miktar"
                                        inputMode="decimal"
                                        readOnly={isSerialTracked || transferCompleted}
                                        className="h-14 rounded-lg border border-slate-300 bg-white px-4 text-lg font-semibold text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100 read-only:bg-slate-100"
                                    />
                                </label>

                                {message ? (
                                    <div className={`rounded-lg border px-4 py-3 text-sm font-semibold ${messageClassName(message)}`}>
                                        {message.text}
                                    </div>
                                ) : null}

                                <div className="grid gap-3 sm:grid-cols-3">
                                    <button
                                        type="submit"
                                        disabled={isBusy || transferCompleted}
                                        className="inline-flex h-14 items-center justify-center gap-2 rounded-lg bg-slate-950 px-5 text-base font-bold text-white shadow-sm transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        <CheckCircle2 className="size-5" />
                                        {transferButtonLabel}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={clearTransfer}
                                        disabled={isBusy && !transferCompleted}
                                        className="inline-flex h-14 items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-5 text-base font-bold text-slate-700 shadow-sm transition hover:border-slate-300 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        <RotateCcw className="size-5" />
                                        {transferCompleted ? 'Yeni Transfer' : 'Temizle'}
                                    </button>
                                    <Link
                                        href="/operations/warehouse-terminal"
                                        className="inline-flex h-14 items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-5 text-base font-bold text-slate-700 shadow-sm transition hover:border-slate-300 hover:bg-slate-50"
                                    >
                                        <ArrowLeft className="size-5" />
                                        Geri Dön
                                    </Link>
                                </div>
                            </form>

                            <section className="grid gap-4 md:grid-cols-[minmax(0,1fr)_minmax(280px,0.75fr)]">
                                <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm md:p-5">
                                    <h2 className="text-base font-bold text-slate-950">İşlem özeti</h2>
                                    <dl className="mt-4 grid gap-3 text-sm">
                                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                            <dt className="font-semibold text-slate-500">İşlem No</dt>
                                            <dd className="mt-1 break-words text-lg font-bold text-slate-950">{formatValue(summary?.operation_no)}</dd>
                                        </div>
                                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                            <dt className="font-semibold text-slate-500">Depo</dt>
                                            <dd className="mt-1 break-words text-lg font-bold text-slate-950">{formatValue(summary?.warehouse_name ?? selectedWarehouse?.warehouse_name)}</dd>
                                        </div>
                                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                            <dt className="font-semibold text-slate-500">Kaynak Raf</dt>
                                            <dd className="mt-1 break-words text-lg font-bold text-slate-950">{formatValue(summary?.source_rack_code ?? sourceRack)}</dd>
                                        </div>
                                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                            <dt className="font-semibold text-slate-500">Hedef Raf</dt>
                                            <dd className="mt-1 break-words text-lg font-bold text-slate-950">{formatValue(summary?.target_rack_code ?? targetRack)}</dd>
                                        </div>
                                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                            <dt className="font-semibold text-slate-500">Ürün / Stok</dt>
                                            <dd className="mt-1 break-words text-lg font-bold text-slate-950">{formatValue(summary?.stock_name ?? selectedItem?.stock_name ?? selectedItem?.display_label)}</dd>
                                        </div>
                                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                            <dt className="font-semibold text-slate-500">Tip</dt>
                                            <dd className="mt-1 break-words text-lg font-bold text-slate-950">{itemTypeLabel(summary?.item_type ?? (isSerialTracked ? 'serial' : 'stock'))}</dd>
                                        </div>
                                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                            <dt className="font-semibold text-slate-500">Miktar</dt>
                                            <dd className="mt-1 break-words text-lg font-bold text-slate-950">{formatValue(summary?.quantity ?? effectiveQuantity)}</dd>
                                        </div>
                                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                            <dt className="font-semibold text-slate-500">İşlem Durumu</dt>
                                            <dd className="mt-1 text-lg font-bold text-slate-950">{status}</dd>
                                        </div>
                                    </dl>
                                </div>

                                <aside className="rounded-lg border border-blue-100 bg-blue-50 p-4 shadow-sm md:p-5">
                                    <div className="flex items-center gap-3">
                                        <span className="grid size-10 place-items-center rounded-lg bg-white text-blue-700">
                                            <ShieldCheck className="size-5" />
                                        </span>
                                        <h2 className="text-base font-bold text-slate-950">Güvenlik notu</h2>
                                    </div>
                                    <ul className="mt-4 grid gap-2 text-sm leading-6 text-blue-950">
                                        {safeNotes.map((note) => (
                                            <li key={note} className="rounded-lg border border-blue-100 bg-white/70 px-3 py-2">
                                                {note}
                                            </li>
                                        ))}
                                    </ul>
                                </aside>
                            </section>
                        </>
                    ) : (
                        <section className="grid gap-4 rounded-lg border border-slate-200 bg-white p-4 shadow-sm md:p-6">
                            <div className="grid gap-3 md:grid-cols-[1fr_1fr_1fr_auto]">
                                <label className="grid gap-2 text-sm font-bold text-slate-800">
                                    Tarih Başlangıç
                                    <input
                                        type="date"
                                        value={historyFrom}
                                        onChange={(event) => setHistoryFrom(event.target.value)}
                                        className="h-14 rounded-lg border border-slate-300 bg-white px-4 text-base font-semibold text-slate-950 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100"
                                    />
                                </label>
                                <label className="grid gap-2 text-sm font-bold text-slate-800">
                                    Tarih Bitiş
                                    <input
                                        type="date"
                                        value={historyTo}
                                        onChange={(event) => setHistoryTo(event.target.value)}
                                        className="h-14 rounded-lg border border-slate-300 bg-white px-4 text-base font-semibold text-slate-950 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100"
                                    />
                                </label>
                                <label className="grid gap-2 text-sm font-bold text-slate-800">
                                    Depo
                                    <select
                                        value={historyWarehouseNo}
                                        onChange={(event) => setHistoryWarehouseNo(event.target.value)}
                                        className="h-14 rounded-lg border border-slate-300 bg-white px-4 text-base font-semibold text-slate-950 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100"
                                    >
                                        <option value="">Tüm depolar</option>
                                        {warehouses.map((warehouse) => (
                                            <option key={warehouse.warehouse_no} value={warehouse.warehouse_no}>
                                                {warehouse.warehouse_name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <button
                                    type="button"
                                    onClick={loadHistory}
                                    disabled={historyLoading}
                                    className="inline-flex h-14 items-center justify-center self-end rounded-lg bg-slate-950 px-5 text-base font-bold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {historyLoading ? 'Listeleniyor' : 'Listele'}
                                </button>
                            </div>

                            {historyMessage ? (
                                <div className={`rounded-lg border px-4 py-3 text-sm font-semibold ${messageClassName(historyMessage)}`}>
                                    {historyMessage.text}
                                </div>
                            ) : null}

                            <div className="grid gap-3">
                                {historyRows.map((row) => (
                                    <article key={row.operation_no} className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                        <button
                                            type="button"
                                            onClick={() => setExpandedOperation(expandedOperation === row.operation_no ? null : row.operation_no)}
                                            className="grid w-full gap-2 text-left md:grid-cols-[1fr_1fr_1fr_1fr]"
                                        >
                                            <span>
                                                <strong className="block text-xs text-slate-500">Tarih</strong>
                                                <span className="text-sm font-bold text-slate-950">{formatValue(row.date)}</span>
                                            </span>
                                            <span>
                                                <strong className="block text-xs text-slate-500">İşlem No</strong>
                                                <span className="text-sm font-bold text-slate-950">{row.operation_no}</span>
                                            </span>
                                            <span>
                                                <strong className="block text-xs text-slate-500">Raf</strong>
                                                <span className="text-sm font-bold text-slate-950">{formatValue(row.source_rack_code)} → {formatValue(row.target_rack_code)}</span>
                                            </span>
                                            <span>
                                                <strong className="block text-xs text-slate-500">Miktar</strong>
                                                <span className="text-sm font-bold text-slate-950">{formatValue(row.total_quantity)} / Seri: {row.serial_count}</span>
                                            </span>
                                        </button>
                                        <div className="mt-3 grid gap-1 text-sm text-slate-700 md:grid-cols-3">
                                            <span>Depo: <strong>{formatValue(row.warehouse_name ?? row.warehouse_no)}</strong></span>
                                            <span>Ürün: <strong>{formatValue(row.stock_name ?? row.stock_code)}</strong></span>
                                            <span>Kullanıcı: <strong>{formatValue(row.user)}</strong></span>
                                        </div>
                                        {expandedOperation === row.operation_no ? (
                                            <div className="mt-3 grid gap-2">
                                                {row.items.map((item) => (
                                                    <div key={`${row.operation_no}-${item.line_no}`} className="grid gap-1 rounded-lg border border-slate-200 bg-white p-3 text-sm md:grid-cols-[60px_1fr_1fr_90px]">
                                                        <span className="font-bold text-slate-500">#{item.line_no}</span>
                                                        <span className="font-semibold text-slate-950">{formatValue(item.serial_no ?? item.stock_code)}</span>
                                                        <span className="text-slate-700">{formatValue(item.stock_name)}</span>
                                                        <span className="font-bold text-slate-950">{formatValue(item.quantity)}</span>
                                                    </div>
                                                ))}
                                            </div>
                                        ) : null}
                                    </article>
                                ))}
                            </div>
                        </section>
                    )}
                </div>
            </main>
        </>
    );
}
