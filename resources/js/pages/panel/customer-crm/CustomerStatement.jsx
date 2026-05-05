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

function statementNumber(row) {
    const evrakNo = readText(row, ['evrak_no', 'evrakNo', 'belge_no']);

    if (evrakNo) {
        return evrakNo;
    }

    return `${readText(row, ['evrak_seri', 'seri', 'evrakSeri'])} ${readText(row, ['evrak_sira', 'sira', 'evrakSira'])}`.trim() || '-';
}

export default function CustomerStatementPage() {
    const queryParams = useMemo(parseCurrentSearch, []);
    const [query, setQuery] = useState(() => ({
        code: queryParams.code,
        from: queryParams.from || yearStartDate(),
        to: queryParams.to || formatIsoDate(new Date()),
    }));
    const [dateDraft, setDateDraft] = useState(() => ({
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
    const isDatasourceMissing = /tanimli degil|tanımlı değil|not found|undefined/i.test(
        String((queryMeta?.notice ?? summaryMeta?.notice ?? '').toLowerCase()),
    );

    useEffect(() => {
        if (!canLoad) {
            setSummaryRows([]);
            setDetailRows([]);
            setQueryMeta(null);
            setSummaryMeta(null);
            setError(null);
            setLoading(false);
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
            .catch(() => {
                if (cancelled) {
                    return;
                }

                setError('Müşteri ekstresi alınamadı veya veri kaynağı çalıştırılamadı.');
                setSummaryRows([]);
                setDetailRows([]);
                setSummaryMeta(null);
                setQueryMeta(null);
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
    const customerName = readText(summary, ['musteri_adi', 'firma_unvani']);
    const customerCode = readText(summary, ['musteri_kodu', 'cari_kodu']) || query.code;

    const exportRows = useMemo(() => {
        return detailRows.map((row) => ({
            tarih: readDate(row, ['tarih', 'date']),
            evrakTipi: readText(row, ['evrak_tipi', 'evrakTipi', 'type']),
            evrakNo: statementNumber(row),
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

    const onFilter = (event) => {
        event.preventDefault();
        setQuery((current) => ({
            ...current,
            from: dateDraft.from || yearStartDate(),
            to: dateDraft.to || formatIsoDate(new Date()),
        }));
    };

    return (
        <main className="mx-auto grid w-full max-w-[1600px] gap-5 bg-[#f3f7fb] p-4 md:p-6">
            <Head title="Cari Hesap Ekstresi" />

            <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="max-w-4xl">
                        <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Müşteri Yönetimi</p>
                        <h1 className="mt-1 text-2xl font-semibold text-slate-950 [font-family:var(--font-display)]">Cari Hesap Ekstresi</h1>
                        <p className="mt-2 whitespace-normal break-words text-sm text-slate-600">
                            {canLoad ? `${customerCode} - ${customerName || '-'}` : 'Önce Müşteri Listesi’nden bir cari seçin.'}
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

                {canLoad ? (
                    <div className="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                        <KpiCard label="Cari Kodu" value={customerCode || '-'} hint={readText(summary, ['temsilci_kodu', 'temsilciKodu']) || '-'} />
                        <KpiCard
                            label="Firma Ünvanı"
                            value={customerName || '-'}
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
                ) : null}
            </section>

            {!canLoad ? (
                <EmptyState title="Önce Müşteri Listesi’nden bir cari seçin." description="Müşteri Bilgi ekranındaki Ekstre bağlantısı ile devam edin." />
            ) : (
                <>
                    <form onSubmit={onFilter} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 className="text-sm font-semibold text-slate-900">Tarih Filtresi</h2>
                        <div className="mt-3 grid gap-3 md:grid-cols-[1fr_1fr_auto]">
                            <label className="grid gap-1 text-sm font-semibold text-slate-700">
                                Başlangıç
                                <input
                                    type="date"
                                    value={dateDraft.from}
                                    onChange={(event) => setDateDraft((current) => ({ ...current, from: event.target.value }))}
                                    className="h-11 rounded-xl border border-slate-200 bg-white px-3"
                                />
                            </label>
                            <label className="grid gap-1 text-sm font-semibold text-slate-700">
                                Bitiş
                                <input
                                    type="date"
                                    value={dateDraft.to}
                                    onChange={(event) => setDateDraft((current) => ({ ...current, to: event.target.value }))}
                                    className="h-11 rounded-xl border border-slate-200 bg-white px-3"
                                />
                            </label>
                            <button type="submit" className="self-end rounded-xl bg-blue-700 px-5 py-3 text-sm font-semibold text-white">
                                Filtrele
                            </button>
                        </div>
                    </form>

                    <section className="w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div className="w-full overflow-x-auto">
                            <table className="w-full min-w-[1180px] divide-y divide-slate-200 text-sm">
                                <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                                    <tr>
                                        <th className="px-4 py-3 text-left">Tarih</th>
                                        <th className="px-4 py-3 text-left">Evrak Tipi</th>
                                        <th className="px-4 py-3 text-left">Evrak No</th>
                                        <th className="min-w-[320px] px-4 py-3 text-left">Açıklama</th>
                                        <th className="px-4 py-3 text-right">Borç</th>
                                        <th className="px-4 py-3 text-right">Alacak</th>
                                        <th className="px-4 py-3 text-right">Bakiye</th>
                                        <th className="px-4 py-3 text-right">Detay</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 text-slate-700">
                                    {detailRows.map((row, index) => {
                                        const guid = readText(row, ['hareket_guid', 'hareketGuid', 'guid']);
                                        const tarih = readText(row, ['tarih', 'date']);
                                        const detailUrl = guid ? `/cari/document-detail?guid=${encodeURIComponent(guid)}` : null;
                                        const rowIndex = `${statementNumber(row)}-${tarih}-${index}`;

                                        return (
                                            <tr key={rowIndex} className="align-top hover:bg-slate-50">
                                                <td className="px-4 py-3">{readDate(row, ['tarih', 'date'])}</td>
                                                <td className="px-4 py-3 whitespace-normal break-words">{readText(row, ['evrak_tipi', 'evrakTipi'])}</td>
                                                <td className="px-4 py-3 font-mono text-xs">{statementNumber(row)}</td>
                                                <td className="px-4 py-3 whitespace-normal break-words leading-5">{readText(row, ['aciklama', 'aciklamaMetni']) || '-'}</td>
                                                <td className="px-4 py-3 text-right tabular-nums">{readMoney(row, ['borc', 'borc_tl'])}</td>
                                                <td className="px-4 py-3 text-right tabular-nums">{readMoney(row, ['alacak', 'alacak_tl'])}</td>
                                                <td className="px-4 py-3 text-right tabular-nums">{readMoney(row, ['bakiye', 'balance'])}</td>
                                                <td className="px-4 py-3 text-right">
                                                    {detailUrl ? (
                                                        <Link
                                                            href={detailUrl}
                                                            className="inline-flex rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700 hover:bg-blue-100"
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
                                description={isDatasourceMissing ? queryMeta?.notice : 'Tarihi değiştirip tekrar deneyin.'}
                            />
                        )}
                    </section>
                </>
            )}

            <ErrorBanner message={error} />
            <LoadingOverlay show={loading} />
        </main>
    );
}
