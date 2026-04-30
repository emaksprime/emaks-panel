import { Head, Link } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { apiRequest } from '@/lib/api';
import { EmptyState, ErrorBanner, LoadingOverlay } from '@/components/primecrm/StateBlocks.jsx';
import { KpiCard } from '@/components/primecrm/KpiCard.jsx';
import { formatMoney, readNumberRaw, readText } from './customerCrmUtils.js';

const LIST_COLUMNS = [
    'Cari Kodu',
    'Firma Ünvanı',
    'Grup',
    'Temsilci',
    'Bakiye Durumu',
    'Onaylı Açık Sipariş',
    'Genel Durum',
    'Onay Bekleyen Sipariş',
];

const BALANCE_COLUMNS = [
    'Cari Kodu',
    'Firma Ünvanı',
    'Grup',
    'Temsilci',
    'Borç',
    'Alacak',
    'Net Bakiye',
    'Detay',
];

function amount(row, keys) {
    return readNumberRaw(row, keys) ?? 0;
}

function customerCode(row) {
    return readText(row, ['musteri_kodu', 'cari_kodu', 'CariKodu', 'cariKodu']);
}

function companyName(row) {
    return readText(row, ['firma_unvani', 'musteri_adi', 'cari_unvani', 'FirmaUnvani', 'cariUnvani']);
}

function representative(row) {
    const name = readText(row, ['temsilci', 'temsilci_adi', 'TemsilciAdi', 'temsilciAdi']);
    const code = readText(row, ['temsilci_kodu', 'TemsilciKodu', 'temsilciKodu']);

    return { name, code };
}

function detailHref(code) {
    return `/cari/detail?code=${encodeURIComponent(code)}`;
}

function summaryFromRows(rows) {
    const first = rows[0] ?? {};
    const summaryKeys = [
        'toplam_alacak_bakiyesi',
        'toplam_borc_bakiyesi',
        'toplam_onayli_acik_siparis',
        'toplam_onay_bekleyen_siparis',
        'genel_sonuc',
    ];
    const hasSummary = summaryKeys.some((key) => first[key] !== undefined && first[key] !== null);

    if (hasSummary) {
        return {
            toplamAlacak: amount(first, ['toplam_alacak_bakiyesi']),
            toplamBorc: amount(first, ['toplam_borc_bakiyesi']),
            onayliAcik: amount(first, ['toplam_onayli_acik_siparis']),
            genelSonuc: amount(first, ['genel_sonuc']),
            onayBekleyen: amount(first, ['toplam_onay_bekleyen_siparis']),
        };
    }

    return rows.reduce(
        (carry, row) => {
            const balance = amount(row, ['bakiye_durumu', 'bakiye', 'net_bakiye', 'BakiyeDurumu']);
            const approved = amount(row, ['acik_siparis_tutar', 'onayli_acik_siparis_tutari', 'AcikSiparisTutar']);
            const pending = amount(row, ['bekleyen_siparis_tutar', 'onay_bekleyen_siparis_tutari', 'BekleyenSiparisTutar']);

            if (balance > 0) {
                carry.toplamAlacak += balance;
            } else if (balance < 0) {
                carry.toplamBorc += Math.abs(balance);
            }

            carry.onayliAcik += approved;
            carry.onayBekleyen += pending;
            carry.genelSonuc = carry.toplamAlacak - carry.toplamBorc + carry.onayliAcik;

            return carry;
        },
        {
            toplamAlacak: 0,
            toplamBorc: 0,
            onayliAcik: 0,
            genelSonuc: 0,
            onayBekleyen: 0,
        },
    );
}

function CustomerSearchForm({ searchDraft, setSearchDraft, onSubmit, loading }) {
    return (
        <form onSubmit={onSubmit} className="mt-4 grid gap-3 rounded-xl border border-slate-100 bg-slate-50 p-3 md:grid-cols-[minmax(0,1fr)_auto]">
            <label className="grid gap-1 text-sm font-semibold text-slate-700">
                Cari kodu, firma adı, grup veya temsilci ara
                <input
                    value={searchDraft}
                    onChange={(event) => setSearchDraft(event.target.value)}
                    placeholder="Cari kodu, firma adı, grup veya temsilci ara"
                    className="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm"
                    autoFocus
                />
            </label>
            <button
                type="submit"
                disabled={loading}
                className="self-end rounded-xl bg-blue-700 px-5 py-3 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-60"
            >
                Ara
            </button>
        </form>
    );
}

function CustomerSummaryCards({ rows }) {
    const summary = useMemo(() => summaryFromRows(rows), [rows]);
    const cards = [
        { label: 'Toplam Alacak Bakiyesi', value: formatMoney(summary.toplamAlacak), hint: 'Pozitif cari bakiyeler' },
        { label: 'Toplam Borç Bakiyesi', value: formatMoney(summary.toplamBorc), hint: 'Negatif cari bakiyeler' },
        { label: 'Onaylı Açık Sipariş', value: formatMoney(summary.onayliAcik), hint: 'Genel duruma dahil' },
        { label: 'Genel Sonuç', value: formatMoney(summary.genelSonuc), hint: 'Bakiye + onaylı açık sipariş' },
        { label: 'Onay Bekleyen Sipariş', value: formatMoney(summary.onayBekleyen), hint: 'Bilgi amaçlı' },
    ];

    return (
        <section className="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
            {cards.map((item) => (
                <KpiCard key={item.label} label={item.label} value={item.value} hint={item.hint} />
            ))}
        </section>
    );
}

function CustomerListTable({ rows }) {
    return (
        <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div className="border-b border-slate-100 px-4 py-3">
                <h2 className="text-sm font-semibold text-slate-900">Sonuçlar</h2>
                <p className="mt-1 text-xs text-slate-500">Cari satırına tıklayarak hesap ekstresini görüntüleyebilirsiniz.</p>
            </div>
            <div className="overflow-x-auto">
                <table className="min-w-[1120px] divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                        <tr>
                            {LIST_COLUMNS.map((column) => (
                                <th key={column} className={`px-4 py-3 ${column.includes('Sipariş') || column.includes('Durum') ? 'text-right' : 'text-left'}`}>
                                    {column}
                                </th>
                            ))}
                            <th className="px-4 py-3 text-right">Detay</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 text-slate-700">
                        {rows.map((row, index) => {
                            const code = customerCode(row) || '-';
                            const name = companyName(row) || '-';
                            const secondName = readText(row, ['firma_unvani_2', 'FirmaUnvani2']);
                            const group = readText(row, ['grup', 'Grup']) || '-';
                            const rep = representative(row);
                            const balance = formatMoney(amount(row, ['bakiye_durumu', 'bakiye', 'BakiyeDurumu']));
                            const approved = formatMoney(amount(row, ['acik_siparis_tutar', 'onayli_acik_siparis_tutari', 'AcikSiparisTutar']));
                            const total = formatMoney(amount(row, ['genel_durum_tutar', 'genel_durum', 'GenelDurumTutar']));
                            const pending = formatMoney(amount(row, ['bekleyen_siparis_tutar', 'onay_bekleyen_siparis_tutari', 'BekleyenSiparisTutar']));
                            const href = detailHref(code);

                            return (
                                <tr
                                    key={`${code}-${index}`}
                                    className="cursor-pointer hover:bg-slate-50"
                                    onClick={() => {
                                        window.location.href = href;
                                    }}
                                >
                                    <td className="px-4 py-3 font-mono text-xs text-slate-600">{code}</td>
                                    <td className="px-4 py-3">
                                        <p className="font-semibold text-slate-900">{name}</p>
                                        {secondName ? <p className="mt-0.5 text-xs text-slate-500">{secondName}</p> : null}
                                    </td>
                                    <td className="px-4 py-3">{group}</td>
                                    <td className="px-4 py-3">
                                        <p>{rep.name || '-'}</p>
                                        {rep.code ? <p className="mt-0.5 font-mono text-xs text-slate-400">{rep.code}</p> : null}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums">{balance}</td>
                                    <td className="px-4 py-3 text-right tabular-nums">{approved}</td>
                                    <td className="px-4 py-3 text-right tabular-nums">{total}</td>
                                    <td className="px-4 py-3 text-right tabular-nums">{pending}</td>
                                    <td className="px-4 py-3 text-right">
                                        <Link
                                            href={href}
                                            onClick={(event) => event.stopPropagation()}
                                            className="inline-flex rounded-md border border-blue-200 bg-blue-50 px-2.5 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-100"
                                        >
                                            Ekstre
                                        </Link>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

function CustomerBalanceTable({ rows }) {
    return (
        <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div className="border-b border-slate-100 px-4 py-3">
                <h2 className="text-sm font-semibold text-slate-900">Müşteri Bakiyesi</h2>
                <p className="mt-1 text-xs text-slate-500">Müşteri/cari bazlı bakiye listesi.</p>
            </div>
            <div className="overflow-x-auto">
                <table className="min-w-[980px] divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                        <tr>
                            {BALANCE_COLUMNS.map((column) => (
                                <th key={column} className={`px-4 py-3 ${['Borç', 'Alacak', 'Net Bakiye', 'Detay'].includes(column) ? 'text-right' : 'text-left'}`}>
                                    {column}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 text-slate-700">
                        {rows.map((row, index) => {
                            const code = customerCode(row) || '-';
                            const rep = representative(row);
                            const net = amount(row, ['net_bakiye', 'bakiye_durumu', 'bakiye', 'BakiyeDurumu']);
                            const borc = amount(row, ['borc']) || (net < 0 ? Math.abs(net) : 0);
                            const alacak = amount(row, ['alacak']) || (net > 0 ? net : 0);

                            return (
                                <tr key={`${code}-${index}`} className="hover:bg-slate-50">
                                    <td className="px-4 py-3 font-mono text-xs text-slate-600">{code}</td>
                                    <td className="px-4 py-3 font-semibold text-slate-900">{companyName(row) || '-'}</td>
                                    <td className="px-4 py-3">{readText(row, ['grup', 'Grup']) || '-'}</td>
                                    <td className="px-4 py-3">
                                        <p>{rep.name || '-'}</p>
                                        {rep.code ? <p className="mt-0.5 font-mono text-xs text-slate-400">{rep.code}</p> : null}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums">{formatMoney(borc)}</td>
                                    <td className="px-4 py-3 text-right tabular-nums">{formatMoney(alacak)}</td>
                                    <td className="px-4 py-3 text-right tabular-nums">{formatMoney(net)}</td>
                                    <td className="px-4 py-3 text-right">
                                        <Link href={detailHref(code)} className="inline-flex rounded-md border border-blue-200 bg-blue-50 px-2.5 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-100">
                                            Detay
                                        </Link>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

function CustomerInfoBase({ mode = 'list' }) {
    const isBalance = mode === 'balance';
    const [searchDraft, setSearchDraft] = useState('');
    const [search, setSearch] = useState('');
    const [rows, setRows] = useState([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [queryMeta, setQueryMeta] = useState(null);
    const [refreshTick, setRefreshTick] = useState(0);
    const sourcePath = isBalance ? '/api/data/cari_balance' : '/api/data/cari';

    useEffect(() => {
        let cancelled = false;

        setLoading(true);
        setError(null);

        void apiRequest(sourcePath, {
            method: 'POST',
            body: JSON.stringify({
                search,
                scope_key: 'all',
                page: 1,
                limit: 200,
                bypass_cache: refreshTick > 0,
            }),
        })
            .then((payload) => {
                if (cancelled) {
                    return;
                }

                setRows(Array.isArray(payload?.rows) ? payload.rows : []);
                setQueryMeta(payload?.queryMeta ?? null);
                setError(null);
            })
            .catch(() => {
                if (cancelled) {
                    return;
                }

                setRows([]);
                setError('Müşteri kaydı bulunamadı veya veri kaynağı çalıştırılamadı.');
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
    }, [sourcePath, search, refreshTick]);

    const isDatasourceMissing = /tanimli degil|tanımlı değil|not found|undefined/i.test(String(queryMeta?.notice ?? '').toLowerCase());
    const emptyTitle = isDatasourceMissing ? 'Müşteri veri kaynağı henüz tanımlı değil.' : 'Müşteri kaydı bulunamadı.';
    const emptyDescription = isDatasourceMissing ? queryMeta?.notice : 'Arama kriterini değiştirip tekrar deneyin.';

    const onSubmit = (event) => {
        event.preventDefault();
        setSearch(searchDraft.trim());
    };

    return (
        <main className="grid gap-5 bg-[#f3f7fb] p-4 md:p-6">
            <Head title={isBalance ? 'Müşteri Bakiyesi' : 'Müşteri Bilgi'} />

            <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Müşteri Yönetimi</p>
                        <h1 className="mt-1 text-2xl font-semibold text-slate-950 [font-family:var(--font-display)]">
                            {isBalance ? 'Müşteri Bakiyesi' : 'Müşteri Bilgi'}
                        </h1>
                        <p className="mt-2 text-sm text-slate-600">
                            {isBalance ? 'Müşteri/cari bazlı borç, alacak ve net bakiye görünümü.' : 'Müşteri, bayi ve hesap bilgileri.'}
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={() => setRefreshTick((current) => current + 1)}
                        className="inline-flex items-center gap-2 rounded-xl bg-blue-700 px-3 py-2 text-sm font-semibold text-white"
                    >
                        Yenile
                    </button>
                </div>

                <CustomerSearchForm searchDraft={searchDraft} setSearchDraft={setSearchDraft} onSubmit={onSubmit} loading={loading} />
            </section>

            {!isBalance && <CustomerSummaryCards rows={rows} />}

            {isBalance ? <CustomerBalanceTable rows={rows} /> : <CustomerListTable rows={rows} />}

            {rows.length === 0 && !loading && !error && (
                <EmptyState title={emptyTitle} description={emptyDescription} />
            )}

            <ErrorBanner message={error} />
            <LoadingOverlay show={loading} />
        </main>
    );
}

export function CustomerBalancePage() {
    return <CustomerInfoBase mode="balance" />;
}

export default function CustomerInfoPage() {
    return <CustomerInfoBase mode="list" />;
}
