import { Head, Link } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { apiRequest } from '@/lib/api';
import { EmptyState, ErrorBanner, LoadingOverlay } from '@/components/primecrm/StateBlocks.jsx';
import { KpiCard } from '@/components/primecrm/KpiCard.jsx';
import { formatMoney, readNumberRaw, readText } from './customerCrmUtils.js';

function toAmount(value) {
    return value === null ? 0 : value;
}

export default function CustomerInfoPage() {
    const [filters, setFilters] = useState({
        cariKodu: '',
        firmaAdi: '',
        grup: '',
        temsilci: '',
    });
    const [scopeKey, setScopeKey] = useState('all');
    const [debouncedFilters, setDebouncedFilters] = useState(filters);
    const [rows, setRows] = useState([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [queryMeta, setQueryMeta] = useState(null);
    const [refreshTick, setRefreshTick] = useState(0);

    useEffect(() => {
        const timer = window.setTimeout(() => {
            setDebouncedFilters(filters);
        }, 250);

        return () => window.clearTimeout(timer);
    }, [filters]);

    const searchText = useMemo(() => {
        return [debouncedFilters.cariKodu, debouncedFilters.firmaAdi, debouncedFilters.grup, debouncedFilters.temsilci]
            .map((item) => String(item ?? '').trim())
            .filter(Boolean)
            .join(' ');
    }, [debouncedFilters]);

    useEffect(() => {
        let cancelled = false;

        setLoading(true);
        setError(null);

        void apiRequest('/api/data/cari', {
            method: 'POST',
            body: JSON.stringify({
                search: searchText,
                scope_key: scopeKey,
                page: 1,
                limit: 200,
                ...debouncedFilters,
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
            .catch((caught) => {
                if (cancelled) {
                    return;
                }

                setRows([]);
                setError(caught instanceof Error ? caught.message : 'Veri alınamadı.');
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
    }, [searchText, scopeKey, refreshTick]);

    const summaryRows = useMemo(() => {
        let toplamAlacak = 0;
        let toplamBorc = 0;
        let onayliAcik = 0;
        let onayBekleyen = 0;

        rows.forEach((row) => {
            const balance = toAmount(readNumberRaw(row, ['bakiye', 'net_bakiye', 'bakiye_durumu']));
            const approved = toAmount(readNumberRaw(row, ['onayli_acik_siparis_tutari', 'approved_open_order']));
            const pending = toAmount(readNumberRaw(row, ['onay_bekleyen_siparis_tutari', 'pending_order']));

            if (balance > 0) {
                toplamAlacak += balance;
            } else if (balance < 0) {
                toplamBorc += Math.abs(balance);
            }

            onayliAcik += approved;
            onayBekleyen += pending;
        });

        return [
            {
                label: 'Toplam Alacak Bakiyesi',
                value: formatMoney(toplamAlacak),
                hint: 'Cari bakiyenin alacak tarafı',
            },
            {
                label: 'Toplam Borç Bakiyesi',
                value: formatMoney(toplamBorc),
                hint: 'Cari bakiyenin borç tarafı',
            },
            {
                label: 'Onaylı Açık Sipariş',
                value: formatMoney(onayliAcik),
                hint: 'Onaylı açık sipariş toplamı',
            },
            {
                label: 'Genel Sonuç',
                value: formatMoney(toplamAlacak - toplamBorc),
                hint: 'Alacak - Borç',
            },
            {
                label: 'Onay Bekleyen Sipariş',
                value: formatMoney(onayBekleyen),
                hint: 'Onay bekleyen sipariş toplamı',
            },
        ];
    }, [rows]);

    const isDatasourceMissing = /tanimli degil|not found|undefined/i.test(String(queryMeta?.notice ?? '').toLowerCase());

    return (
        <main className="grid gap-5 bg-[#f3f7fb] p-4 md:p-6">
            <Head title="Müşteri Bilgi" />

            <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Müşteri Yönetimi</p>
                        <h1 className="mt-1 text-2xl font-semibold text-slate-950 [font-family:var(--font-display)]">Müşteri Bilgi</h1>
                        <p className="mt-2 text-sm text-slate-600">Temsilci Kodu / Kullanıcı Kapsamı</p>
                    </div>
                    <button
                        type="button"
                        onClick={() => setRefreshTick((current) => current + 1)}
                        className="inline-flex items-center gap-2 rounded-xl bg-blue-700 px-3 py-2 text-sm font-semibold text-white"
                    >
                        Yenile
                    </button>
                </div>

                <div className="mt-4 grid gap-3 rounded-xl border border-slate-100 bg-slate-50 p-3 md:grid-cols-2 xl:grid-cols-5">
                    <label className="grid gap-1 text-sm font-semibold text-slate-700">
                        Cari Kodu
                        <input
                            value={filters.cariKodu}
                            onChange={(event) => setFilters((current) => ({ ...current, cariKodu: event.target.value }))}
                            placeholder="Cari kodu ile ara"
                            className="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm"
                        />
                    </label>
                    <label className="grid gap-1 text-sm font-semibold text-slate-700">
                        Firma Ünvanı
                        <input
                            value={filters.firmaAdi}
                            onChange={(event) => setFilters((current) => ({ ...current, firmaAdi: event.target.value }))}
                            placeholder="Firma unvanı ara"
                            className="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm"
                        />
                    </label>
                    <label className="grid gap-1 text-sm font-semibold text-slate-700">
                        Grup
                        <input
                            value={filters.grup}
                            onChange={(event) => setFilters((current) => ({ ...current, grup: event.target.value }))}
                            placeholder="Grup ara"
                            className="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm"
                        />
                    </label>
                    <label className="grid gap-1 text-sm font-semibold text-slate-700">
                        Temsilci
                        <input
                            value={filters.temsilci}
                            onChange={(event) => setFilters((current) => ({ ...current, temsilci: event.target.value }))}
                            placeholder="Temsilci ara"
                            className="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm"
                        />
                    </label>
                    <label className="grid gap-1 text-sm font-semibold text-slate-700">
                        Kapsam
                        <select
                            value={scopeKey}
                            onChange={(event) => setScopeKey(event.target.value)}
                            className="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm"
                        >
                            <option value="all">Tümü</option>
                            <option value="payable">Borçlular</option>
                            <option value="receivable">Alacaklılar</option>
                        </select>
                    </label>
                </div>
            </section>

            <section className="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                {summaryRows.map((item) => (
                    <KpiCard key={item.label} label={item.label} value={item.value} hint={item.hint} />
                ))}
            </section>

            <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                            <tr>
                                <th className="px-4 py-3 text-left">Cari Kodu</th>
                                <th className="px-4 py-3 text-left">Firma Ünvanı</th>
                                <th className="px-4 py-3 text-left">Grup</th>
                                <th className="px-4 py-3 text-left">Temsilci</th>
                                <th className="px-4 py-3 text-right">Bakiye Durumu</th>
                                <th className="px-4 py-3 text-right">Onaylı Açık Sipariş</th>
                                <th className="px-4 py-3 text-right">Genel Durum</th>
                                <th className="px-4 py-3 text-right">Onay Bekleyen Sipariş</th>
                                <th className="px-4 py-3 text-right">Detay</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 text-slate-700">
                            {rows.map((row, index) => {
                                const code = readText(row, ['musteri_kodu', 'musteriKodu', 'cari_kodu', 'cariKodu']) || '-';
                                const name = readText(row, ['musteri_adi', 'firma_unvani', 'cari_unvani', 'cariUnvani']) || '-';
                                const group = readText(row, ['grup', 'cariGrup']) || '-';
                                const rep = readText(row, ['temsilci', 'temsilci_adi', 'temsilciAdi']) || '-';
                                const linkCode = encodeURIComponent(code);
                                const balance = formatMoney(toAmount(readNumberRaw(row, ['bakiye', 'balance'])));
                                const approved = formatMoney(toAmount(readNumberRaw(row, ['onayli_acik_siparis_tutari', 'approved_open_order'])));
                                const total = formatMoney(toAmount(readNumberRaw(row, ['genel_durum', 'genelSonuc', 'genel_durum_tl'])));
                                const pending = formatMoney(toAmount(readNumberRaw(row, ['onay_bekleyen_siparis_tutari', 'pending_order'])));

                                return (
                                    <tr key={`${code}-${index}`} className="hover:bg-slate-50">
                                        <td className="px-4 py-3 font-mono text-xs text-slate-600">{code}</td>
                                        <td className="px-4 py-3">
                                            <p className="font-semibold text-slate-900">{name}</p>
                                        </td>
                                        <td className="px-4 py-3">{group}</td>
                                        <td className="px-4 py-3">{rep}</td>
                                        <td className="px-4 py-3 text-right">{balance}</td>
                                        <td className="px-4 py-3 text-right">{approved}</td>
                                        <td className="px-4 py-3 text-right">{total}</td>
                                        <td className="px-4 py-3 text-right">{pending}</td>
                                        <td className="px-4 py-3 text-right">
                                            <Link
                                                href={`/cari/detail?code=${linkCode}`}
                                                className="inline-flex rounded-md border border-blue-200 bg-blue-50 px-2.5 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-100"
                                            >
                                                Detay
                                            </Link>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                {rows.length === 0 && !loading && !error && (
                    <EmptyState
                        title={isDatasourceMissing ? 'Bu ekran için veri kaynağı henüz tanımlı değil.' : 'Seçili filtrelerde kayıt bulunamadı.'}
                        description={isDatasourceMissing
                            ? queryMeta?.notice
                            : 'Seçiminizi değiştirip tekrar deneyin.'}
                    />
                )}
            </section>

            <ErrorBanner message={error} />
            <LoadingOverlay show={loading} />
        </main>
    );
}
