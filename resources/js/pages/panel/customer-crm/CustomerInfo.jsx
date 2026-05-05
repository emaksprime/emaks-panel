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
    'Ekstre',
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

function moneyTone(value) {
    if (value > 0) {
        return 'border-emerald-100 bg-emerald-50 text-emerald-700';
    }

    if (value < 0) {
        return 'border-rose-100 bg-rose-50 text-rose-700';
    }

    return 'border-slate-200 bg-slate-50 text-slate-600';
}

function balanceText(value) {
    if (value > 0) {
        return 'Bakiye var';
    }

    if (value < 0) {
        return 'Borç bakiyesi';
    }

    return 'Bakiye yok';
}

function generalText(value) {
    if (value > 0) {
        return 'Alacaklıyız';
    }

    if (value < 0) {
        return 'Borçluyuz';
    }

    return 'Dengede';
}

function orderText(value, emptyText) {
    return value > 0 ? formatMoney(value) : emptyText;
}

function StatusBox({ label, value, tone = 'border-slate-200 bg-slate-50 text-slate-700' }) {
    return (
        <span className={`inline-flex w-full min-w-0 flex-col rounded-xl border px-3 py-2 text-left text-xs leading-5 ${tone}`}>
            <strong className="text-[11px] font-semibold uppercase tracking-[0.08em] opacity-70">{label}</strong>
            <span className="font-semibold">{value}</span>
        </span>
    );
}

function customerListValues(row) {
    const code = customerCode(row) || '-';
    const name = companyName(row) || '-';
    const secondName = readText(row, ['firma_unvani_2', 'FirmaUnvani2']);
    const group = readText(row, ['grup', 'Grup']) || '-';
    const rep = representative(row);
    const balanceValue = amount(row, ['bakiye_durumu', 'bakiye', 'BakiyeDurumu']);
    const approvedValue = amount(row, ['acik_siparis_tutar', 'onayli_acik_siparis_tutari', 'AcikSiparisTutar']);
    const totalValue = amount(row, ['genel_durum_tutar', 'genel_durum', 'GenelDurumTutar']);
    const pendingValue = amount(row, ['bekleyen_siparis_tutar', 'onay_bekleyen_siparis_tutari', 'BekleyenSiparisTutar']);

    return {
        code,
        name,
        secondName,
        group,
        rep,
        balanceValue,
        approvedValue,
        totalValue,
        pendingValue,
        href: detailHref(code),
    };
}

function balanceValues(row) {
    const code = customerCode(row) || '-';
    const rep = representative(row);
    const net = amount(row, ['net_bakiye', 'bakiye_durumu', 'bakiye', 'BakiyeDurumu']);
    const borc = amount(row, ['borc']) || (net < 0 ? Math.abs(net) : 0);
    const alacak = amount(row, ['alacak']) || (net > 0 ? net : 0);

    return {
        code,
        name: companyName(row) || '-',
        group: readText(row, ['grup', 'Grup']) || '-',
        rep,
        net,
        borc,
        alacak,
        href: detailHref(code),
    };
}

function CustomerMobileCard({ row }) {
    const item = customerListValues(row);

    return (
        <article className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h3 className="whitespace-normal break-words text-base font-semibold leading-6 text-slate-950" title={item.name}>{item.name}</h3>
                    {item.secondName ? <p className="mt-1 whitespace-normal break-words text-xs leading-5 text-slate-500">{item.secondName}</p> : null}
                    <p className="mt-2 font-mono text-xs font-semibold text-slate-500">{item.code}</p>
                </div>
                <Link href={item.href} className="shrink-0 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700">
                    Ekstre
                </Link>
            </div>
            <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-400">Grup</dt>
                    <dd className="mt-1 whitespace-normal break-words text-slate-700">{item.group}</dd>
                </div>
                <div>
                    <dt className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-400">Temsilci</dt>
                    <dd className="mt-1 whitespace-normal break-words text-slate-700">{item.rep.name || '-'}</dd>
                    {item.rep.code ? <dd className="font-mono text-xs text-slate-400">{item.rep.code}</dd> : null}
                </div>
                <StatusBox label={balanceText(item.balanceValue)} value={formatMoney(item.balanceValue)} tone={moneyTone(item.balanceValue)} />
                <StatusBox label={item.approvedValue > 0 ? 'Onaylı' : 'Açık sipariş yok'} value={orderText(item.approvedValue, 'Yok')} />
                <StatusBox label={generalText(item.totalValue)} value={formatMoney(item.totalValue)} tone={moneyTone(item.totalValue)} />
                <StatusBox label={item.pendingValue > 0 ? 'Onay bekleyen' : 'Bekleyen yok'} value={orderText(item.pendingValue, 'Yok')} />
            </dl>
        </article>
    );
}

function BalanceMobileCard({ row }) {
    const item = balanceValues(row);

    return (
        <article className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h3 className="whitespace-normal break-words text-base font-semibold leading-6 text-slate-950" title={item.name}>{item.name}</h3>
                    <p className="mt-2 font-mono text-xs font-semibold text-slate-500">{item.code}</p>
                </div>
                <Link href={item.href} className="shrink-0 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700">
                    Ekstre
                </Link>
            </div>
            <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-400">Grup</dt>
                    <dd className="mt-1 whitespace-normal break-words text-slate-700">{item.group}</dd>
                </div>
                <div>
                    <dt className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-400">Temsilci</dt>
                    <dd className="mt-1 whitespace-normal break-words text-slate-700">{item.rep.name || '-'}</dd>
                    {item.rep.code ? <dd className="font-mono text-xs text-slate-400">{item.rep.code}</dd> : null}
                </div>
                <StatusBox label="Borç" value={formatMoney(item.borc)} />
                <StatusBox label="Alacak" value={formatMoney(item.alacak)} />
                <StatusBox label="Net Bakiye" value={formatMoney(item.net)} tone={moneyTone(item.net)} />
            </dl>
        </article>
    );
}

function CustomerSearchForm({ searchDraft, setSearchDraft, onSubmit, loading }) {
    return (
        <form onSubmit={onSubmit} className="mt-5 grid gap-3 rounded-2xl border border-slate-100 bg-slate-50 p-3 md:grid-cols-[minmax(0,1fr)_auto]">
            <label className="grid gap-1 text-sm font-semibold text-slate-700">
                Cari kodu, firma adı, grup veya temsilci ara
                <input
                    value={searchDraft}
                    onChange={(event) => setSearchDraft(event.target.value)}
                    placeholder="Cari kodu, firma adı, grup veya temsilci ara"
                    className="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm"
                    autoFocus
                />
            </label>
            <button
                type="submit"
                disabled={loading}
                className="self-end rounded-xl bg-blue-700 px-6 py-3 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-60"
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
        <section className="grid gap-3 md:grid-cols-2 2xl:grid-cols-5">
            {cards.map((item) => (
                <KpiCard key={item.label} label={item.label} value={item.value} hint={item.hint} />
            ))}
        </section>
    );
}

function CustomerListTable({ rows }) {
    return (
        <section className="w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
                <div>
                    <h2 className="text-base font-semibold text-slate-900">Sonuçlar</h2>
                    <p className="mt-1 text-sm text-slate-500">Cari satırına tıklayarak hesap ekstresini görüntüleyebilirsiniz.</p>
                </div>
                <span className="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-600">
                    {rows.length} kayıt
                </span>
            </div>
            <div className="grid gap-3 bg-slate-50 p-3 md:hidden">
                {rows.map((row, index) => (
                    <CustomerMobileCard key={`${customerCode(row) || 'customer'}-${index}`} row={row} />
                ))}
            </div>
            <div className="hidden w-full md:block">
                <table className="w-full table-fixed divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                        <tr>
                            <th className="w-[140px] px-3 py-3 text-left">{LIST_COLUMNS[0]}</th>
                            <th className="px-3 py-3 text-left">{LIST_COLUMNS[1]}</th>
                            <th className="w-[110px] px-3 py-3 text-left">{LIST_COLUMNS[2]}</th>
                            <th className="w-[140px] px-3 py-3 text-left">{LIST_COLUMNS[3]}</th>
                            <th className="w-[136px] px-3 py-3 text-right">{LIST_COLUMNS[4]}</th>
                            <th className="w-[136px] px-3 py-3 text-right">{LIST_COLUMNS[5]}</th>
                            <th className="w-[136px] px-3 py-3 text-right">{LIST_COLUMNS[6]}</th>
                            <th className="w-[136px] px-3 py-3 text-right">{LIST_COLUMNS[7]}</th>
                            <th className="w-[90px] px-3 py-3 text-right">Ekstre</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 text-slate-700">
                        {rows.map((row, index) => {
                            const item = customerListValues(row);

                            return (
                                <tr
                                    key={`${item.code}-${index}`}
                                    className="cursor-pointer align-top hover:bg-slate-50"
                                    onClick={() => {
                                        window.location.href = item.href;
                                    }}
                                >
                                    <td className="px-3 py-4 font-mono text-xs font-semibold text-slate-600">{item.code}</td>
                                    <td className="px-3 py-4">
                                        <p className="whitespace-normal break-words text-sm font-semibold leading-5 text-slate-900" title={item.name}>{item.name}</p>
                                        {item.secondName ? <p className="mt-1 whitespace-normal break-words text-xs leading-5 text-slate-500">{item.secondName}</p> : null}
                                    </td>
                                    <td className="px-3 py-4 whitespace-normal break-words">{item.group}</td>
                                    <td className="px-3 py-4">
                                        <p className="whitespace-normal break-words">{item.rep.name || '-'}</p>
                                        {item.rep.code ? <p className="mt-0.5 font-mono text-xs text-slate-400">{item.rep.code}</p> : null}
                                    </td>
                                    <td className="px-3 py-4 text-right">
                                        <StatusBox label={balanceText(item.balanceValue)} value={formatMoney(item.balanceValue)} tone={moneyTone(item.balanceValue)} />
                                    </td>
                                    <td className="px-3 py-4 text-right">
                                        <StatusBox label={item.approvedValue > 0 ? 'Onaylı' : 'Açık sipariş yok'} value={orderText(item.approvedValue, 'Yok')} />
                                    </td>
                                    <td className="px-3 py-4 text-right">
                                        <StatusBox label={generalText(item.totalValue)} value={formatMoney(item.totalValue)} tone={moneyTone(item.totalValue)} />
                                    </td>
                                    <td className="px-3 py-4 text-right">
                                        <StatusBox label={item.pendingValue > 0 ? 'Onay bekleyen' : 'Bekleyen yok'} value={orderText(item.pendingValue, 'Yok')} />
                                    </td>
                                    <td className="px-3 py-4 text-right">
                                        <Link
                                            href={item.href}
                                            onClick={(event) => event.stopPropagation()}
                                            className="inline-flex rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700 hover:bg-blue-100"
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
        <section className="w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
                <div>
                    <h2 className="text-base font-semibold text-slate-900">Müşteri Bakiyesi</h2>
                    <p className="mt-1 text-sm text-slate-500">Müşteri/cari bazlı bakiye listesi.</p>
                </div>
                <span className="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-600">
                    {rows.length} kayıt
                </span>
            </div>
            <div className="grid gap-3 bg-slate-50 p-3 md:hidden">
                {rows.map((row, index) => (
                    <BalanceMobileCard key={`${customerCode(row) || 'balance'}-${index}`} row={row} />
                ))}
            </div>
            <div className="hidden w-full md:block">
                <table className="w-full table-fixed divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                        <tr>
                            {BALANCE_COLUMNS.map((column) => (
                                <th key={column} className={`px-3 py-3 ${['Borç', 'Alacak', 'Net Bakiye', 'Ekstre'].includes(column) ? 'text-right' : 'text-left'}`}>
                                    {column}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 text-slate-700">
                        {rows.map((row, index) => {
                            const item = balanceValues(row);

                            return (
                                <tr key={`${item.code}-${index}`} className="align-top hover:bg-slate-50">
                                    <td className="w-[140px] px-3 py-4 font-mono text-xs font-semibold text-slate-600">{item.code}</td>
                                    <td className="px-3 py-4">
                                        <p className="whitespace-normal break-words font-semibold leading-5 text-slate-900" title={item.name}>{item.name}</p>
                                    </td>
                                    <td className="w-[110px] px-3 py-4 whitespace-normal break-words">{item.group}</td>
                                    <td className="w-[140px] px-3 py-4">
                                        <p className="whitespace-normal break-words">{item.rep.name || '-'}</p>
                                        {item.rep.code ? <p className="mt-0.5 font-mono text-xs text-slate-400">{item.rep.code}</p> : null}
                                    </td>
                                    <td className="w-32 px-3 py-4 text-right tabular-nums">{formatMoney(item.borc)}</td>
                                    <td className="w-32 px-3 py-4 text-right tabular-nums">{formatMoney(item.alacak)}</td>
                                    <td className="w-32 px-3 py-4 text-right tabular-nums">{formatMoney(item.net)}</td>
                                    <td className="w-[90px] px-3 py-4 text-right">
                                        <Link href={item.href} className="inline-flex rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700 hover:bg-blue-100">
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

function representativeScopeCode(queryMeta) {
    const request = queryMeta?.gatewayRequest ?? {};
    const params = request?.params ?? {};

    return String(request?.rep_code ?? params?.rep_code ?? '').trim();
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
    const activeRepCode = representativeScopeCode(queryMeta);
    const listSubtitle = activeRepCode
        ? `Sadece temsilci kodunuzdaki müşteriler listelenir. Temsilci kodu: ${activeRepCode}`
        : 'Tüm müşteri kartlarında cari bilgi, bakiye ve sipariş durumu.';

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
        <main className="mx-auto grid w-full max-w-[1600px] gap-5 bg-[#f3f7fb] p-4 md:p-6">
            <Head title={isBalance ? 'Müşteri Bakiyesi' : 'Müşteri Bilgi'} />

            <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="max-w-4xl">
                        <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Müşteri Yönetimi</p>
                        <h1 className="mt-1 text-2xl font-semibold text-slate-950 [font-family:var(--font-display)]">
                            {isBalance ? 'Müşteri Bakiyesi' : 'Müşteri Bilgi'}
                        </h1>
                        <p className="mt-2 text-sm text-slate-600">
                            {isBalance ? 'Müşteri/cari bazlı borç, alacak ve net bakiye görünümü.' : listSubtitle}
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
