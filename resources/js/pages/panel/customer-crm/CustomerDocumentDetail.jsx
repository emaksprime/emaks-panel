import { Head, Link } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { apiRequest } from '@/lib/api';
import { EmptyState, ErrorBanner, LoadingOverlay } from '@/components/primecrm/StateBlocks.jsx';
import { KpiCard } from '@/components/primecrm/KpiCard.jsx';
import { formatNumber, formatPercentOrNumber, readDate, readMoney, readNumberRaw, readText } from './customerCrmUtils.js';

function parseCurrentSearch() {
    const search = typeof window === 'undefined' ? '' : window.location.search;
    const params = new URLSearchParams(search);

    return {
        guid: params.get('guid') ?? '',
    };
}

function pickRows(payload, candidates) {
    if (!payload || typeof payload !== 'object') {
        return [];
    }

    for (const key of candidates) {
        const candidate = payload[key];

        if (Array.isArray(candidate)) {
            return candidate;
        }
    }

    if (Array.isArray(payload.rows)) {
        return payload.rows;
    }

    return [];
}

function rowsByKind(rows, kinds) {
    return rows.filter((row) => kinds.includes(String(row?.line_type ?? row?.row_type ?? '').toLowerCase()));
}

function documentNumber(row) {
    const explicit = readText(row, ['evrak_no', 'evrakNo', 'belge_no']);

    if (explicit) {
        return explicit;
    }

    return `${readText(row, ['evrak_seri', 'seri'])} ${readText(row, ['evrak_sira', 'sira'])}`.trim() || '-';
}

export default function CustomerDocumentDetailPage() {
    const { guid } = useMemo(parseCurrentSearch, []);
    const [documentRows, setDocumentRows] = useState([]);
    const [movementRows, setMovementRows] = useState([]);
    const [stockRows, setStockRows] = useState([]);
    const [headerRows, setHeaderRows] = useState([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [queryMeta, setQueryMeta] = useState(null);

    useEffect(() => {
        if (!guid) {
            setHeaderRows([]);
            setDocumentRows([]);
            setMovementRows([]);
            setStockRows([]);
            setQueryMeta(null);
            setError(null);
            setLoading(false);
            return;
        }

        let cancelled = false;
        setLoading(true);
        setError(null);

        void apiRequest('/api/data/customer_documents', {
            method: 'POST',
            body: JSON.stringify({
                guid,
                hareket_guid: guid,
                document_guid: guid,
                evrak_guid: guid,
                bypass_cache: true,
            }),
        })
            .then((response) => {
                if (cancelled) {
                    return;
                }

                setQueryMeta(response?.queryMeta ?? null);

                const rows = Array.isArray(response?.rows) ? response.rows : [];
                const headerFromRows = rowsByKind(rows, ['header']);
                const movementFromRows = rowsByKind(rows, ['cari', 'movement']);
                const stockFromRows = rowsByKind(rows, ['stock', 'stok']);

                setDocumentRows(rows);
                setHeaderRows(Array.isArray(response?.header) ? response.header : headerFromRows);
                setMovementRows(
                    movementFromRows.length
                        ? movementFromRows
                        : pickRows(response, ['cari_hareket_satirlari', 'hareket_satirlari', 'movement_rows', 'rows_movement']) || [],
                );
                setStockRows(
                    stockFromRows.length
                        ? stockFromRows
                        : pickRows(response, ['stok_hareket_satirlari', 'stok_satirlari', 'line_items', 'rows_items']) || [],
                );
                setError(null);
            })
            .catch(() => {
                if (cancelled) {
                    return;
                }

                setError('Evrak detayı alınamadı veya veri kaynağı çalıştırılamadı.');
                setDocumentRows([]);
                setHeaderRows([]);
                setMovementRows([]);
                setStockRows([]);
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
    }, [guid]);

    const header = headerRows[0] ?? {};
    const returnCode = readText(header, ['musteri_kodu', 'cari_kodu', 'customer_code']) || '';
    const returnName = readText(header, ['musteri_adi', 'firma_unvani']) || '';
    const backHref = returnCode ? `/cari/detail?code=${encodeURIComponent(returnCode)}` : '/cari';
    const isDatasourceMissing = /tanimli degil|tanımlı değil|not found|undefined/i.test(String(queryMeta?.notice ?? '').toLowerCase());
    const hasMovementRows = Array.isArray(movementRows) && movementRows.length > 0;
    const hasStockRows = Array.isArray(stockRows) && stockRows.length > 0;
    const hasDocument = Array.isArray(documentRows) && documentRows.length > 0;
    const amount = readMoney(header, ['tutar', 'toplam_tutar', 'genel_tutar']);

    return (
        <main className="mx-auto grid w-full max-w-[1600px] gap-5 bg-[#f3f7fb] p-4 md:p-6">
            <Head title="Evrak Detayı" />

            <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="max-w-4xl">
                        <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Müşteri Yönetimi</p>
                        <h1 className="mt-1 text-2xl font-semibold text-slate-950 [font-family:var(--font-display)]">Evrak Detayı</h1>
                        <p className="mt-2 whitespace-normal break-words text-sm text-slate-600">
                            {guid ? `${readText(header, ['evrak_tipi', 'evrakTipi']) || '-'} - ${documentNumber(header)}` : 'Evrak listesinde Detay Gör ile geçiş yapınız.'}
                        </p>
                    </div>
                    <Link
                        href={backHref}
                        className="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700"
                    >
                        ← Ekstreye Dön
                    </Link>
                </div>
            </section>

            {!guid ? (
                <EmptyState title="Evrak listesinde Detay Gör ile geçiş yapınız." description="Cari ekstre ekranındaki Detay Gör bağlantısı ile devam edin." />
            ) : (
                <>
                    <section className="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                        <KpiCard
                            label="Cari"
                            value={readText(header, ['musteri_kodu', 'cari_kodu']) || '-'}
                            hint={returnName || '-'}
                        />
                        <KpiCard
                            label="Tarih"
                            value={readDate(header, ['tarih', 'date'])}
                            hint={readText(header, ['durum']) || '-'}
                        />
                        <KpiCard
                            label="Evrak Tipi"
                            value={readText(header, ['evrak_tipi', 'evrakTipi']) || '-'}
                            hint="Belge tipi"
                        />
                        <KpiCard
                            label="Evrak No"
                            value={documentNumber(header)}
                            hint={readText(header, ['evrak_seri', 'seri']) || '-'}
                        />
                        <KpiCard label="Tutar" value={amount} hint="Genel toplam" />
                    </section>

                    <section className="w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div className="border-b border-slate-100 px-5 py-4">
                            <h2 className="text-base font-semibold text-slate-900">Cari Hareket Satırları</h2>
                        </div>
                        <div className="w-full overflow-x-auto">
                            <table className="w-full min-w-[1040px] divide-y divide-slate-200 text-sm">
                                <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                                    <tr>
                                        <th className="px-4 py-3 text-left">Tarih</th>
                                        <th className="px-4 py-3 text-left">Cari Kodu</th>
                                        <th className="min-w-[280px] px-4 py-3 text-left">Cari Ünvan</th>
                                        <th className="min-w-[280px] px-4 py-3 text-left">Açıklama</th>
                                        <th className="px-4 py-3 text-right">Borç</th>
                                        <th className="px-4 py-3 text-right">Alacak</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 text-slate-700">
                                    {hasMovementRows &&
                                        movementRows.map((row, index) => (
                                            <tr key={readText(row, ['hareket_id', 'id', 'hareket_guid']) || index} className="align-top hover:bg-slate-50">
                                                <td className="px-4 py-3">{readDate(row, ['tarih', 'date'])}</td>
                                                <td className="px-4 py-3 font-mono text-xs text-slate-600">{readText(row, ['cari_kodu', 'cariKodu']) || '-'}</td>
                                                <td className="px-4 py-3 whitespace-normal break-words leading-5">{readText(row, ['cari_unvani', 'firma_unvani', 'musteri_adi']) || '-'}</td>
                                                <td className="px-4 py-3 whitespace-normal break-words leading-5" title={readText(row, ['aciklama', 'aciklamaMetni']) || '-'}>
                                                    {readText(row, ['aciklama', 'aciklamaMetni']) || '-'}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums">{readMoney(row, ['borc', 'borc_tl'])}</td>
                                                <td className="px-4 py-3 text-right tabular-nums">{readMoney(row, ['alacak', 'alacak_tl'])}</td>
                                            </tr>
                                        ))}
                                </tbody>
                            </table>
                        </div>
                        {(!hasMovementRows || !hasDocument) && (
                            <EmptyState title="Cari hareket satırı bulunamadı." description="Bu evraka ait hareket satırları boş." />
                        )}
                    </section>

                    <section className="w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div className="border-b border-slate-100 px-5 py-4">
                            <h2 className="text-base font-semibold text-slate-900">Stok / Hizmet Satırları</h2>
                        </div>
                        <div className="w-full overflow-x-auto">
                            <table className="w-full min-w-[1240px] divide-y divide-slate-200 text-sm">
                                <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                                    <tr>
                                        <th className="px-4 py-3 text-left">Stok Kodu</th>
                                        <th className="min-w-[320px] px-4 py-3 text-left">Ürün / Hizmet</th>
                                        <th className="px-4 py-3 text-right">Miktar</th>
                                        <th className="px-4 py-3 text-right">Net Birim Fiyat</th>
                                        <th className="px-4 py-3 text-right">Tutar</th>
                                        <th className="px-4 py-3 text-right">İsk. 1</th>
                                        <th className="px-4 py-3 text-right">İsk. 2</th>
                                        <th className="px-4 py-3 text-right">İsk. 3</th>
                                        <th className="px-4 py-3 text-right">Net Tutar</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 text-slate-700">
                                    {hasStockRows &&
                                        stockRows.map((row, index) => (
                                            <tr key={readText(row, ['satir_id', 'id', 'hareket_guid']) || index} className="align-top hover:bg-slate-50">
                                                <td className="px-4 py-3 font-mono text-xs text-slate-600">{readText(row, ['stok_kodu', 'stokKodu']) || '-'}</td>
                                                <td className="px-4 py-3">
                                                    <div className="whitespace-normal break-words font-semibold leading-5 text-slate-900" title={readText(row, ['urun_adi', 'hizmet_adi']) || '-'}>
                                                        {readText(row, ['urun_adi', 'hizmet_adi']) || '-'}
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums">{formatNumber(readNumberRaw(row, ['miktar', 'quantity']))}</td>
                                                <td className="px-4 py-3 text-right tabular-nums">{readMoney(row, ['birim_fiyat', 'net_birim_fiyat'])}</td>
                                                <td className="px-4 py-3 text-right tabular-nums">{readMoney(row, ['tutar', 'toplam_tutar'])}</td>
                                                <td className="px-4 py-3 text-right tabular-nums">{formatPercentOrNumber(readNumberRaw(row, ['iskonto_1', 'iskonto1']))}</td>
                                                <td className="px-4 py-3 text-right tabular-nums">{formatPercentOrNumber(readNumberRaw(row, ['iskonto_2', 'iskonto2']))}</td>
                                                <td className="px-4 py-3 text-right tabular-nums">{formatPercentOrNumber(readNumberRaw(row, ['iskonto_3', 'iskonto3']))}</td>
                                                <td className="px-4 py-3 text-right tabular-nums">{readMoney(row, ['net_tutar', 'net_tutar_tl'])}</td>
                                            </tr>
                                        ))}
                                </tbody>
                            </table>
                        </div>
                        {(!hasStockRows || !hasDocument) && (
                            <EmptyState title="Stok veya hizmet satırı bulunamadı." description="Bu evraka ait ürün/hizmet satırları boş." />
                        )}
                    </section>

                    {isDatasourceMissing && (
                        <EmptyState
                            title="Bu ekran için veri kaynağı henüz tanımlı değil."
                            description={queryMeta?.notice}
                        />
                    )}
                </>
            )}

            <ErrorBanner message={error} />
            <LoadingOverlay show={loading} />
        </main>
    );
}
