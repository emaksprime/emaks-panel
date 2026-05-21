import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, RotateCcw, ShieldCheck } from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import { apiRequest } from '@/lib/api';

type ValidationMessage = {
    type: 'error' | 'success';
    text: string;
};

type RackTransferSummary = {
    operation_no?: string | null;
    warehouse_no?: number | string | null;
    source_rack_code?: string | null;
    target_rack_code?: string | null;
    item_code?: string | null;
    item_type?: 'serial' | 'stock' | string | null;
    serial_no?: string | null;
    stock_code?: string | null;
    stock_name?: string | null;
    quantity?: number | string | null;
    operation_status?: string | null;
};

type RackTransferResponse = {
    ok?: boolean;
    operation_no?: string;
    item_type?: 'serial' | 'stock';
    message?: string;
    summary?: RackTransferSummary;
};

const safeNotes = [
    'Aynı depo içi raf transferleri Mikro’ya yazılmaz.',
    'Raf lokasyonu Panel/PostgreSQL tarafında tutulur.',
    'Mikro resmi depo/stok miktarı için kaynak olmaya devam eder.',
    'Bu ekran bu aşamada Mikro’ya veri yazmaz.',
    'Silme işlemi yapılmayacak; ileride iptal/ters işlem mantığı kurulacak.',
];

const rackTransferValidateUrl = '/api/operations/warehouse-terminal/rack-transfer/validate';
const rackTransferCompleteUrl = '/api/operations/warehouse-terminal/rack-transfer/complete';

function normalizeQuantityInput(value: string): string {
    return value.trim().replace(',', '.') || '1';
}

function formatSummaryValue(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '-';
    }

    return String(value);
}

function itemTypeLabel(value: RackTransferSummary['item_type']): string {
    if (value === 'serial') {
        return 'Serili';
    }

    if (value === 'stock') {
        return 'Stok / adetli';
    }

    return '-';
}

export default function WarehouseTerminalRackTransfer() {
    const warehouseNoInputRef = useRef<HTMLInputElement | null>(null);
    const [warehouseNo, setWarehouseNo] = useState('');
    const [sourceRack, setSourceRack] = useState('');
    const [itemCode, setItemCode] = useState('');
    const [targetRack, setTargetRack] = useState('');
    const [quantity, setQuantity] = useState('1');
    const [operationNo, setOperationNo] = useState<string | null>(null);
    const [apiSummary, setApiSummary] = useState<RackTransferSummary | null>(null);
    const [status, setStatus] = useState('Bekliyor');
    const [message, setMessage] = useState<ValidationMessage | null>(null);
    const [validating, setValidating] = useState(false);
    const [completing, setCompleting] = useState(false);

    useEffect(() => {
        warehouseNoInputRef.current?.focus();
    }, []);

    const resetValidatedState = (nextStatus = 'Bekliyor') => {
        setOperationNo(null);
        setApiSummary(null);
        setStatus(nextStatus);
        setMessage(null);
    };

    const handleClear = () => {
        setWarehouseNo('');
        setSourceRack('');
        setItemCode('');
        setTargetRack('');
        setQuantity('1');
        resetValidatedState();
        warehouseNoInputRef.current?.focus();
    };

    const transferPayload = () => ({
        warehouse_no: warehouseNo.trim(),
        source_rack_code: sourceRack.trim(),
        target_rack_code: targetRack.trim(),
        item_code: itemCode.trim(),
        quantity: normalizeQuantityInput(quantity),
    });

    const handleValidate = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setValidating(true);
        setOperationNo(null);
        setApiSummary(null);
        setStatus('Kontrol ediliyor');
        setMessage(null);

        try {
            const response = await apiRequest(rackTransferValidateUrl, {
                method: 'POST',
                body: JSON.stringify(transferPayload()),
            }) as RackTransferResponse;

            if (response.ok === false) {
                throw new Error(response.message || 'Raf transferi doğrulanamadı.');
            }

            setOperationNo(response.operation_no ?? null);
            setApiSummary(response.summary ?? null);
            setStatus('Ön kontrol başarılı');
            setMessage({
                type: 'success',
                text: response.message || 'Ön kontrol başarılı. Mikro’ya yazma yapılmadı.',
            });
        } catch (caught) {
            setStatus('Uyarı');
            setMessage({
                type: 'error',
                text: caught instanceof Error ? caught.message : 'Raf transferi doğrulanamadı.',
            });
        } finally {
            setValidating(false);
        }
    };

    const handleComplete = async () => {
        if (!operationNo) {
            setStatus('Uyarı');
            setMessage({ type: 'error', text: 'Önce kontrol işlemi başarılı olmalıdır.' });

            return;
        }

        setCompleting(true);
        setStatus('Tamamlanıyor');
        setMessage(null);

        try {
            const response = await apiRequest(rackTransferCompleteUrl, {
                method: 'POST',
                body: JSON.stringify({ operation_no: operationNo }),
            }) as RackTransferResponse;

            if (response.ok === false) {
                throw new Error(response.message || 'Raf transferi tamamlanamadı.');
            }

            setApiSummary(response.summary ?? apiSummary);
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
            setCompleting(false);
        }
    };

    const messageClassName = message?.type === 'success'
        ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
        : 'border-amber-200 bg-amber-50 text-amber-900';
    const summary = apiSummary ?? {};
    const isBusy = validating || completing;
    const canComplete = Boolean(operationNo) && !isBusy && status !== 'Tamamlandı';

    return (
        <>
            <Head title="Raf Transferi" />

            <main className="min-h-[calc(100vh-4.5rem)] bg-slate-100 px-3 py-4 sm:px-4 md:px-6 md:py-6">
                <div className="mx-auto grid w-full max-w-[900px] gap-4">
                    <section className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm md:p-6">
                        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div className="min-w-0">
                                <p className="text-xs font-bold uppercase tracking-[0.14em] text-blue-700">
                                    Depo Terminal
                                </p>
                                <h1 className="mt-2 text-2xl font-semibold text-slate-950 [font-family:var(--font-display)] md:text-3xl">
                                    Raf Transferi
                                </h1>
                                <p className="mt-2 text-sm leading-6 text-slate-600 md:text-base">
                                    Kaynak raf, ürün/seri ve hedef raf okutularak aynı depo içi raf transfer hazırlığı yapılır.
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

                    <form onSubmit={handleValidate} className="grid gap-4 rounded-lg border border-slate-200 bg-white p-4 shadow-sm md:p-6">
                        <label className="grid gap-2 text-sm font-bold text-slate-800">
                            Depo No
                            <input
                                ref={warehouseNoInputRef}
                                value={warehouseNo}
                                onChange={(event) => {
                                    setWarehouseNo(event.target.value);
                                    resetValidatedState();
                                }}
                                placeholder="Depo no yazın"
                                inputMode="numeric"
                                autoComplete="off"
                                className="h-14 rounded-lg border border-slate-300 bg-white px-4 text-lg font-semibold text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100"
                            />
                        </label>

                        <label className="grid gap-2 text-sm font-bold text-slate-800">
                            Kaynak Raf
                            <input
                                value={sourceRack}
                                onChange={(event) => {
                                    setSourceRack(event.target.value);
                                    resetValidatedState();
                                }}
                                placeholder="Kaynak raf okutun veya yazın"
                                autoComplete="off"
                                className="h-14 rounded-lg border border-slate-300 bg-white px-4 text-lg font-semibold text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100"
                            />
                        </label>

                        <label className="grid gap-2 text-sm font-bold text-slate-800">
                            Ürün / Seri No
                            <input
                                value={itemCode}
                                onChange={(event) => {
                                    setItemCode(event.target.value);
                                    resetValidatedState();
                                }}
                                placeholder="Barkod, stok kodu veya seri no okutun"
                                autoComplete="off"
                                className="h-14 rounded-lg border border-slate-300 bg-white px-4 text-lg font-semibold text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100"
                            />
                        </label>

                        <label className="grid gap-2 text-sm font-bold text-slate-800">
                            Hedef Raf
                            <input
                                value={targetRack}
                                onChange={(event) => {
                                    setTargetRack(event.target.value);
                                    resetValidatedState();
                                }}
                                placeholder="Hedef raf okutun veya yazın"
                                autoComplete="off"
                                className="h-14 rounded-lg border border-slate-300 bg-white px-4 text-lg font-semibold text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100"
                            />
                        </label>

                        <label className="grid gap-2 text-sm font-bold text-slate-800">
                            Miktar
                            <input
                                value={quantity}
                                onChange={(event) => {
                                    setQuantity(event.target.value);
                                    resetValidatedState();
                                }}
                                placeholder="Miktar"
                                inputMode="decimal"
                                autoComplete="off"
                                className="h-14 rounded-lg border border-slate-300 bg-white px-4 text-lg font-semibold text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100"
                            />
                        </label>

                        {message ? (
                            <div className={`rounded-lg border px-4 py-3 text-sm font-semibold ${messageClassName}`}>
                                {message.text}
                            </div>
                        ) : null}

                        <div className="grid gap-3 sm:grid-cols-4">
                            <button
                                type="submit"
                                disabled={isBusy}
                                className="inline-flex h-14 items-center justify-center gap-2 rounded-lg bg-slate-950 px-5 text-base font-bold text-white shadow-sm transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                <CheckCircle2 className="size-5" />
                                {validating ? 'Kontrol ediliyor' : 'Kontrol Et'}
                            </button>
                            <button
                                type="button"
                                onClick={handleComplete}
                                disabled={!canComplete}
                                className="inline-flex h-14 items-center justify-center gap-2 rounded-lg bg-blue-700 px-5 text-base font-bold text-white shadow-sm transition hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <CheckCircle2 className="size-5" />
                                {completing ? 'Tamamlanıyor' : 'Transferi Tamamla'}
                            </button>
                            <button
                                type="button"
                                onClick={handleClear}
                                disabled={isBusy}
                                className="inline-flex h-14 items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-5 text-base font-bold text-slate-700 shadow-sm transition hover:border-slate-300 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                <RotateCcw className="size-5" />
                                Temizle
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
                                    <dd className="mt-1 break-words text-lg font-bold text-slate-950">{formatSummaryValue(summary.operation_no ?? operationNo)}</dd>
                                </div>
                                <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                    <dt className="font-semibold text-slate-500">Depo No</dt>
                                    <dd className="mt-1 break-words text-lg font-bold text-slate-950">{formatSummaryValue(summary.warehouse_no ?? warehouseNo.trim())}</dd>
                                </div>
                                <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                    <dt className="font-semibold text-slate-500">Kaynak Raf</dt>
                                    <dd className="mt-1 break-words text-lg font-bold text-slate-950">{formatSummaryValue(summary.source_rack_code ?? sourceRack.trim())}</dd>
                                </div>
                                <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                    <dt className="font-semibold text-slate-500">Ürün / Seri</dt>
                                    <dd className="mt-1 break-words text-lg font-bold text-slate-950">{formatSummaryValue(summary.item_code ?? itemCode.trim())}</dd>
                                </div>
                                <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                    <dt className="font-semibold text-slate-500">Tip</dt>
                                    <dd className="mt-1 break-words text-lg font-bold text-slate-950">{itemTypeLabel(summary.item_type)}</dd>
                                </div>
                                <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                    <dt className="font-semibold text-slate-500">Miktar</dt>
                                    <dd className="mt-1 break-words text-lg font-bold text-slate-950">{formatSummaryValue(summary.quantity ?? quantity.trim())}</dd>
                                </div>
                                <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                    <dt className="font-semibold text-slate-500">Hedef Raf</dt>
                                    <dd className="mt-1 break-words text-lg font-bold text-slate-950">{formatSummaryValue(summary.target_rack_code ?? targetRack.trim())}</dd>
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
                </div>
            </main>
        </>
    );
}
