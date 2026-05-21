import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, RotateCcw, ShieldCheck } from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useRef, useState } from 'react';

type ValidationMessage = {
    type: 'error' | 'success';
    text: string;
};

const safeNotes = [
    'Bu ekran bu aşamada Mikro’ya veri yazmaz.',
    'Çıkış rafı HGRP 2, giriş rafı HGRP 3 mantığı gerçek işlem aşamasında uygulanacak.',
    'Silme işlemi yapılmayacak; ileride iptal/ters işlem mantığı kurulacak.',
];

export default function WarehouseTerminalRackTransfer() {
    const sourceRackInputRef = useRef<HTMLInputElement | null>(null);
    const [sourceRack, setSourceRack] = useState('');
    const [itemCode, setItemCode] = useState('');
    const [targetRack, setTargetRack] = useState('');
    const [status, setStatus] = useState('Bekliyor');
    const [message, setMessage] = useState<ValidationMessage | null>(null);

    useEffect(() => {
        sourceRackInputRef.current?.focus();
    }, []);

    const handleClear = () => {
        setSourceRack('');
        setItemCode('');
        setTargetRack('');
        setStatus('Bekliyor');
        setMessage(null);
        sourceRackInputRef.current?.focus();
    };

    const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        const normalizedSourceRack = sourceRack.trim();
        const normalizedItemCode = itemCode.trim();
        const normalizedTargetRack = targetRack.trim();

        if (!normalizedSourceRack) {
            setStatus('Uyarı');
            setMessage({ type: 'error', text: 'Kaynak raf okutulmalı veya yazılmalı.' });
            sourceRackInputRef.current?.focus();

            return;
        }

        if (!normalizedItemCode) {
            setStatus('Uyarı');
            setMessage({ type: 'error', text: 'Ürün / seri no okutulmalı veya yazılmalı.' });

            return;
        }

        if (!normalizedTargetRack) {
            setStatus('Uyarı');
            setMessage({ type: 'error', text: 'Hedef raf okutulmalı veya yazılmalı.' });

            return;
        }

        if (normalizedSourceRack.toLocaleUpperCase('tr-TR') === normalizedTargetRack.toLocaleUpperCase('tr-TR')) {
            setStatus('Uyarı');
            setMessage({ type: 'error', text: 'Kaynak raf ile hedef raf aynı olamaz.' });

            return;
        }

        setStatus('Ön kontrol başarılı');
        setMessage({ type: 'success', text: 'Ön kontrol başarılı. Mikro’ya yazma işlemi bu aşamada kapalıdır.' });
    };

    const messageClassName = message?.type === 'success'
        ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
        : 'border-amber-200 bg-amber-50 text-amber-900';

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
                                    Kaynak raf, ürün/seri ve hedef raf okutularak transfer hazırlığı yapılır.
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

                    <form onSubmit={handleSubmit} className="grid gap-4 rounded-lg border border-slate-200 bg-white p-4 shadow-sm md:p-6">
                        <label className="grid gap-2 text-sm font-bold text-slate-800">
                            Kaynak Raf
                            <input
                                ref={sourceRackInputRef}
                                value={sourceRack}
                                onChange={(event) => setSourceRack(event.target.value)}
                                placeholder="Kaynak raf okutun veya yazın"
                                autoComplete="off"
                                className="h-14 rounded-lg border border-slate-300 bg-white px-4 text-lg font-semibold text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100"
                            />
                        </label>

                        <label className="grid gap-2 text-sm font-bold text-slate-800">
                            Ürün / Seri No
                            <input
                                value={itemCode}
                                onChange={(event) => setItemCode(event.target.value)}
                                placeholder="Barkod, stok kodu veya seri no okutun"
                                autoComplete="off"
                                className="h-14 rounded-lg border border-slate-300 bg-white px-4 text-lg font-semibold text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100"
                            />
                        </label>

                        <label className="grid gap-2 text-sm font-bold text-slate-800">
                            Hedef Raf
                            <input
                                value={targetRack}
                                onChange={(event) => setTargetRack(event.target.value)}
                                placeholder="Hedef raf okutun veya yazın"
                                autoComplete="off"
                                className="h-14 rounded-lg border border-slate-300 bg-white px-4 text-lg font-semibold text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100"
                            />
                        </label>

                        {message ? (
                            <div className={`rounded-lg border px-4 py-3 text-sm font-semibold ${messageClassName}`}>
                                {message.text}
                            </div>
                        ) : null}

                        <div className="grid gap-3 sm:grid-cols-3">
                            <button
                                type="submit"
                                className="inline-flex h-14 items-center justify-center gap-2 rounded-lg bg-slate-950 px-5 text-base font-bold text-white shadow-sm transition hover:bg-blue-700"
                            >
                                <CheckCircle2 className="size-5" />
                                Kontrol Et
                            </button>
                            <button
                                type="button"
                                onClick={handleClear}
                                className="inline-flex h-14 items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-5 text-base font-bold text-slate-700 shadow-sm transition hover:border-slate-300 hover:bg-slate-50"
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
                                    <dt className="font-semibold text-slate-500">Kaynak Raf</dt>
                                    <dd className="mt-1 break-words text-lg font-bold text-slate-950">{sourceRack.trim() || '-'}</dd>
                                </div>
                                <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                    <dt className="font-semibold text-slate-500">Ürün / Seri</dt>
                                    <dd className="mt-1 break-words text-lg font-bold text-slate-950">{itemCode.trim() || '-'}</dd>
                                </div>
                                <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                    <dt className="font-semibold text-slate-500">Hedef Raf</dt>
                                    <dd className="mt-1 break-words text-lg font-bold text-slate-950">{targetRack.trim() || '-'}</dd>
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
