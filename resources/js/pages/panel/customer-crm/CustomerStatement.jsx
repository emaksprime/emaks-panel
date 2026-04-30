import { Head, Link } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { apiRequest } from '@/lib/api';
import { EmptyState, ErrorBanner, LoadingOverlay } from '@/components/primecrm/StateBlocks.jsx';
import { KpiCard } from '@/components/primecrm/KpiCard.jsx';
import { makeCsvLine, readDate, readMoney, readText } from './customerCrmUtils.js';

function parseCurrentSearch() {
    const search = typeof window === 'undefined' ? '' : window.location.search;
    const params = new URLSearchParams(search);

    return {
        code: params.get('code') ?? '',
        from: params.get('from') ?? '',
        to: params.get('to') ?? '',
    };
}

function yearStartDate() {
    const today = new Date();
    return `${today.getFullYear()}-01-01`;
}

function formatIsoDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

export default function CustomerStatementPage() {
    const queryParams = useMemo(parseCurrentSearch, []);
    const [query, setQuery] = useState(() => ({
        code: queryParams.code,
        from: queryParams.from || yearStartDate(),
        to: queryParams.to || formatIsoDate(new Date()),
    }));

    const [summaryRows, setSummaryRows] = useState([]);
    const [detailRows, setDetailRows] = useState([]);
    const [queryMeta, setQueryMeta] = useState(null);
    const [summaryMeta, setSummaryMeta] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

    const canLoad = query.code.trim() !== '';
    const isDatasourceMissing = /tanimli degil|not found|undefined/i.test(
        String((queryMeta?.notice ?? summaryMeta?.notice ?? '').toLowerCase()),
    );

    useEffect(() => {
        if (!canLoad) {
            setSummaryRows([]);
            setDetailRows([]);
            return;
        }

        let cancelled = false;
        setLoading(true);
        setError(null);

        Promise.all([
            apiRequest('/api/data/customer_detail', {
                method: 'POST',
                body: JSON.stringify({
                    customer_code: query.code,
                    rep_code: '',
                    bypass_cache: true,
                }),
            }),
            apiRequest('/api/data/customer_statement', {
                method: 'POST',
                body: JSON.stringify({
                    customer_code: query.code,
                    date_from: query.from,
                    date_to: query.to,
                    bypass_cache: true,
                }),
            }),
        ])
            .then(([summaryResponse, detailResponse]) => {
                if (cancelled) {
                    return;
                }

                setSummaryRows(Array.isArray(summaryResponse?.rows) ? summaryResponse.rows : []);
                setDetailRows(Array.isArray(detailResponse?.rows) ? detailResponse.rows : []);
                setSummaryMeta(summaryResponse?.queryMeta ?? null);
                setQueryMeta(detailResponse?.queryMeta ?? null);
                setError(null);
            })
            .catch((caught) => {
                if (cancelled) {
                    return;
                }

                setError(caught instanceof Error ? caught.message : 'Veri alınamadı.');
                setSummaryRows([]);
                setDetailRows([]);
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [canLoad, query.code, query.from, query.to]);

    const summary = summaryRows[0] ?? {};

    const exportRows = useMemo(() => {
        return detailRows.map((row) => ({
            tarih: readDate(row, ['tarih', 'date']),
            evrakTipi: readText(row, ['evrak_tipi', 'evrakTipi', 'type']),
            evrakNo: `${readText(row, ['evrak_seri', 'seri', 'evrakSeri'])} ${readText(row, ['evrak_sira', 'sira', 'evrakSira'])}`.trim() || '-',
            aciklama: readText(row, ['aciklama', 'aciklamaMetni']),
            borc: readMoney(row, ['borc', 'borc_tl']),
            alacak: readMoney(row, ['alacak', 'alacak_tl']),
            bakiye: readMoney(row, ['bakiye', 'balance']),
        }));
    }, [detailRows]);

    const onExportExcel = () => {
        if (!detailRows.length || !query.code) {
            return;
        }

        const headers = ['Tarih', 'Evrak Tipi', 'Evrak No', 'Açıklama', 'Borç', 'Alacak', 'Bakiye'];
        const lines = [headers.join(',')];

        exportRows.forEach((row) => {
            lines.push(
                makeCsvLine([
                    row.tarih,
                    row.evrakTipi,
                    row.evrakNo,
                    row.aciklama,
                    row.borc,
                    row.alacak,
                    row.bakiye,
                ]),
            );
        });

        const blob = new Blob([`\ufeff${lines.join('\n')}`], {
            type: 'text/csv;charset=utf-8;',
        });
        const link = document.createElement('a');
        const fileName = `cari_ekstre_${query.code}_${query.from}_${query.to}.csv`;

        link.href = URL.createObjectURL(blob);
        link.setAttribute('download', fileName);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(link.href);
    };

    const onExportPdf = () => {
        if (!canLoad || !detailRows.length) {
            return;
        }

        window.print();
    };

    return (
        <main className="grid gap-5 bg-[#f3f7fb] p-4 md:p-6">
            <Head title="Cari Hesap Ekstresi" />

            <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Müşteri Yönetimi</p>
                        <h1 className="mt-1 text-2xl font-semibold text-slate-950 [font-family:var(--font-display)]">Cari Hesap Ekstresi</h1>
                        <p className="mt-2 text-sm text-slate-600">
                            {query.code ? `${query.code} - ${summary?.musteri_adi ? `${summary.musteri_adi}` : readText(summary, ['firma_unvani'])}` : 'Cari kodu seçilmeli.'}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={onExportPdf}
                            disabled={!query.code || !detailRows.length}
                            className="inline-flex items-center gap-2 rounded-xl bg-blue-700 px-3 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            PDF Dök
                        </button>
                        <button
                            type="button"
                            onClick={onExportExcel}
                            disabled={!query.code || !detailRows.length}
                            className="inline-flex items-center gap-2 rounded-xl border border-blue-200 bg-white px-3 py-2 text-sm font-semibold text-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Excel Dök
                        </button>
                        <Link
                            href="/cari"
                            className="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700"
                        >
                            ← Müşteri Bilgi
                        </Link>
                    </div>
                </div>

                <div className="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                    <KpiCard label="Cari Kodu" value={readText(summary, ['musteri_kodu', 'cari_kodu']) || '-'} hint={readText(summary, ['temsilci_kodu', 'temsilciKodu']) || '-'} />
                    <KpiCard
                        label="Firma Ünvanı"
                        value={readText(summary, ['musteri_adi', 'firma_unvani']) || '-'}
                        hint={readText(summary, ['firma_unvani_2', 'firmaUnvan2']) || '-'}
                    />
                    <KpiCard label="Grup" value={readText(summary, ['grup']) || '-'} hint="Müşteri grubu" />
                    <KpiCard
                        label="Temsilci"
                        value={readText(summary, ['temsilci', 'temsilciAdi', 'temsilci_adi']) || '-'}
                        hint={readText(summary, ['temsilci_kodu', 'temsilciKodu']) || '-'}
                    />
                    <KpiCard
                        label="Bakiye Durumu"
                        value={readMoney(summary, ['bakiye', 'balance', 'bakiye_durumu'])}
                        hint={summary?.belge_no || summary?.evrak_no ? `Ekstre No: ${summary.belge_no || summary.evrak_no}` : '-'}
                    />
                </div>
            </section>

            <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <label className="grid gap-1 text-sm font-semibold text-slate-700">
                    Tarih Aralığı
                    <div className="grid gap-2 md:grid-cols-2">
                        <input
                            type="date"
                            value={query.from}
                            onChange={(event) => setQuery((current) => ({ ...current, from: event.target.value }))}
                            className="h-11 rounded-xl border border-slate-200 bg-white px-3"
                        />
                        <input
                            type="date"
                            value={query.to}
                            onChange={(event) => setQuery((current) => ({ ...current, to: event.target.value }))}
                            className="h-11 rounded-xl border border-slate-200 bg-white px-3"
                        />
                    </div>
                </label>
            </section>

            <section className="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50">
                            <tr>
                                <th className="px-4 py-3 text-left">Tarih</th>
                                <th className="px-4 py-3 text-left">Evrak Tipi</th>
                                <th className="px-4 py-3 text-left">Evrak No</th>
                                <th className="px-4 py-3 text-left">Açıklama</th>
                                <th className="px-4 py-3 text-right">Borç</th>
                                <th className="px-4 py-3 text-right">Alacak</th>
                                <th className="px-4 py-3 text-right">Bakiye</th>
                                <th className="px-4 py-3 text-right">Detay</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 text-slate-700">
                            {detailRows.map((row, index) => {
                                const guid = readText(row, ['hareket_guid', 'hareketGuid', 'guid']);
                                const evrakSeri = readText(row, ['evrak_seri', 'seri', 'evrakSeri']);
                                const evrakSira = readText(row, ['evrak_sira', 'sira', 'evrakSira']);
                                const tarih = readText(row, ['tarih', 'date']);
                                const detailUrl = guid ? `/cari/document-detail?guid=${encodeURIComponent(guid)}` : null;
                                const rowIndex = `${evrakSeri}-${evrakSira}-${tarih}-${index}`;

                                return (
                                    <tr key={rowIndex} className="hover:bg-slate-50">
                                        <td className="px-4 py-3">{readDate(row, ['tarih', 'date'])}</td>
                                        <td className="px-4 py-3">{readText(row, ['evrak_tipi', 'evrakTipi'])}</td>
                                        <td className="px-4 py-3 font-mono text-xs">
                                            {`${readText(row, ['evrak_seri', 'seri', 'evrakSeri'])} ${readText(row, ['evrak_sira', 'sira', 'evrakSira'])}`.trim() || '-'}
                                        </td>
                                        <td className="max-w-[320px] px-4 py-3 break-words">{readText(row, ['aciklama', 'aciklamaMetni']) || '-'}</td>
                                        <td className="px-4 py-3 text-right tabular-nums">{readMoney(row, ['borc', 'borc_tl'])}</td>
                                        <td className="px-4 py-3 text-right tabular-nums">{readMoney(row, ['alacak', 'alacak_tl'])}</td>
                                        <td className="px-4 py-3 text-right tabular-nums">{readMoney(row, ['bakiye', 'balance'])}</td>
                                        <td className="px-4 py-3 text-right">
                                            {detailUrl ? (
                                                <Link
                                                    href={detailUrl}
                                                    className="inline-flex rounded-md border border-blue-200 bg-blue-50 px-2.5 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-100"
                                                >
                                                    Detay Gör
                                                </Link>
                                            ) : (
                                                <span className="text-slate-400">-</span>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                {detailRows.length === 0 && !loading && !error && (
                    <EmptyState
                        title={isDatasourceMissing ? 'Bu ekran için veri kaynağı henüz tanımlı değil.' : 'Seçili filtrelerde kayıt bulunamadı.'}
                        description={isDatasourceMissing
                            ? queryMeta?.notice
                            : canLoad
                                ? 'Tarihi değiştirip tekrar deneyin.'
                                : 'Cari kodu seçerek ekstreyi yükleyin.'}
                    />
                )}
            </section>

            <ErrorBanner message={error} />
            <LoadingOverlay show={loading} />
        </main>
    );
}
