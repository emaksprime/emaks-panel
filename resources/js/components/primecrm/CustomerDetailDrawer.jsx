import { Printer, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { apiRequest } from '@/lib/api';
import { formatCell, formatMoney, numericValue } from './format';
import { valueFrom } from './module-data';

const tabs = [
    { key: 'general', label: 'Genel Bilgi' },
    { key: 'balance', label: 'Bakiye' },
    { key: 'statement', label: 'Ekstre' },
];

const statementColumns = [
    { key: 'tarih', label: 'Tarih', aliases: ['tarih', 'Tarih', 'evrak_tarihi', 'Evrak Tarihi', 'son_hareket_tarihi'] },
    { key: 'aciklama', label: 'Açıklama', aliases: ['aciklama', 'açıklama', 'Aciklama', 'Açıklama', 'evrak_tip', 'evrak_tipi', 'islem_tipi'] },
    { key: 'borc', label: 'Borç', aliases: ['borc', 'borç', 'Borc', 'Borç', 'debit'] },
    { key: 'alacak', label: 'Alacak', aliases: ['alacak', 'Alacak', 'credit'] },
    { key: 'bakiye', label: 'Bakiye', aliases: ['bakiye', 'Bakiye', 'net_bakiye', 'balance'] },
];

function firstValue(row, keys) {
    if (!row) {
        return null;
    }

    const found = keys.find((key) => Object.prototype.hasOwnProperty.call(row, key));

    return found ? row[found] : null;
}

function Field({ label, value, columnKey = label }) {
    const display = formatCell(value, { key: columnKey, label });

    return (
        <div className="grid gap-1 rounded-xl border border-slate-100 bg-slate-50 p-3">
            <span className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">{label}</span>
            <strong className="break-words text-sm font-semibold text-slate-800">{display}</strong>
        </div>
    );
}

function StatusMessage({ loading, error, empty }) {
    if (loading) {
        return <p className="rounded-xl border border-blue-100 bg-blue-50 p-4 text-sm text-blue-700">Yükleniyor...</p>;
    }

    if (error) {
        return <p className="rounded-xl border border-amber-100 bg-amber-50 p-4 text-sm text-amber-700">{error}</p>;
    }

    if (empty) {
        return <p className="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">{empty}</p>;
    }

    return null;
}

function BalanceCards({ source }) {
    const balance = numericValue(valueFrom(source, 'bakiye'));
    const debt = numericValue(valueFrom(source, 'borc'));
    const credit = numericValue(valueFrom(source, 'alacak'));

    return (
        <section className="grid gap-3 sm:grid-cols-3">
            <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Borç</p>
                <strong className="mt-2 block text-lg text-slate-950">{formatMoney(debt)}</strong>
            </div>
            <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Alacak</p>
                <strong className="mt-2 block text-lg text-slate-950">{formatMoney(credit)}</strong>
            </div>
            <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Net Bakiye</p>
                <strong className={['mt-2 block text-lg', balance < 0 ? 'text-red-700' : 'text-emerald-700'].join(' ')}>
                    {formatMoney(balance)}
                </strong>
            </div>
        </section>
    );
}

function StatementRows({ rows }) {
    if (rows.length === 0) {
        return <StatusMessage empty="Ekstre kaydı bulunamadı." />;
    }

    return (
        <div className="overflow-x-auto rounded-2xl border border-slate-200">
            <table className="min-w-[720px] w-full divide-y divide-slate-200 text-sm">
                <thead className="bg-slate-50">
                    <tr>
                        {statementColumns.map((column) => (
                            <th key={column.key} className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                {column.label}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 bg-white">
                    {rows.slice(0, 50).map((row, index) => (
                        <tr key={`${firstValue(row, ['evrak_no', 'belge_no', 'id']) ?? 'statement'}-${index}`}>
                            {statementColumns.map((column) => (
                                <td
                                    key={column.key}
                                    className={[
                                        'px-4 py-3 align-top text-slate-700',
                                        ['borc', 'alacak', 'bakiye'].includes(column.key) ? 'text-right font-semibold tabular-nums' : '',
                                    ].join(' ')}
                                >
                                    {formatCell(firstValue(row, column.aliases), { key: column.key, label: column.label })}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export function CustomerDetailDrawer({ item, onClose }) {
    const [activeTab, setActiveTab] = useState('general');
    const [detailRows, setDetailRows] = useState([]);
    const [statementRows, setStatementRows] = useState([]);
    const [detailError, setDetailError] = useState(null);
    const [statementError, setStatementError] = useState(null);
    const [loadingDetail, setLoadingDetail] = useState(false);
    const [loadingStatement, setLoadingStatement] = useState(false);

    const customerCode = useMemo(() => item ? valueFrom(item, 'cariKodu') : null, [item]);

    useEffect(() => {
        if (!item) {
            return;
        }

        setActiveTab('general');
        setDetailRows([]);
        setStatementRows([]);
        setDetailError(null);
        setStatementError(null);

        if (!customerCode) {
            setDetailError('Müşteri kodu bulunamadı.');
            return;
        }

        let isCurrent = true;
        const payload = {
            customer_code: String(customerCode),
            limit: 50,
            bypass_cache: false,
        };

        setLoadingDetail(true);
        setLoadingStatement(true);

        void apiRequest('/api/data/customer_detail', {
            method: 'POST',
            body: JSON.stringify(payload),
        })
            .then((response) => {
                if (isCurrent) {
                    setDetailRows(Array.isArray(response.rows) ? response.rows : []);
                }
            })
            .catch(() => {
                if (isCurrent) {
                    setDetailError('Müşteri detay veri kaynağı çalıştırılamadı.');
                }
            })
            .finally(() => {
                if (isCurrent) {
                    setLoadingDetail(false);
                }
            });

        void apiRequest('/api/data/customer_statement', {
            method: 'POST',
            body: JSON.stringify(payload),
        })
            .then((response) => {
                if (isCurrent) {
                    setStatementRows(Array.isArray(response.rows) ? response.rows : []);
                }
            })
            .catch(() => {
                if (isCurrent) {
                    setStatementError('Ekstre veri kaynağı çalıştırılamadı.');
                }
            })
            .finally(() => {
                if (isCurrent) {
                    setLoadingStatement(false);
                }
            });

        return () => {
            isCurrent = false;
        };
    }, [item, customerCode]);

    if (!item) {
        return null;
    }

    const detail = detailRows[0] ?? item;
    const title = valueFrom(detail, 'cariAdi') ?? valueFrom(item, 'cariAdi') ?? customerCode ?? 'Müşteri detayı';

    return (
        <aside className="fixed inset-0 z-50 overflow-y-auto border-l border-slate-200 bg-white p-4 shadow-2xl sm:inset-y-0 sm:left-auto sm:w-full sm:max-w-3xl sm:p-6">
            <div className="flex items-start justify-between gap-4">
                <div className="min-w-0">
                    <p className="text-xs font-semibold uppercase tracking-[0.14em] text-blue-700">Müşteri Detayı</p>
                    <h2 className="mt-1 truncate text-2xl font-semibold text-slate-950" title={String(title)}>{title}</h2>
                    <p className="mt-1 text-sm text-slate-500">{formatCell(customerCode, { key: 'cari_kodu', label: 'Müşteri Kodu' })}</p>
                </div>
                <button
                    type="button"
                    onClick={onClose}
                    aria-label="Müşteri detay panelini kapat"
                    className="rounded-full border border-slate-200 bg-white p-2 text-slate-500 shadow-sm transition hover:bg-slate-50"
                >
                    <X className="size-4" />
                </button>
            </div>

            <nav className="mt-5 flex flex-wrap gap-2 border-b border-slate-200 pb-3" aria-label="Müşteri detay sekmeleri">
                {tabs.map((tab) => (
                    <button
                        key={tab.key}
                        type="button"
                        onClick={() => setActiveTab(tab.key)}
                        className={[
                            'rounded-xl px-4 py-2 text-sm font-semibold transition',
                            activeTab === tab.key
                                ? 'bg-blue-700 text-white shadow-sm'
                                : 'bg-slate-100 text-slate-600 hover:bg-slate-200',
                        ].join(' ')}
                    >
                        {tab.label}
                    </button>
                ))}
            </nav>

            <div className="mt-5 grid gap-5">
                {activeTab === 'general' && (
                    <>
                        <StatusMessage loading={loadingDetail} error={detailError} />
                        <section className="grid gap-3 sm:grid-cols-2">
                            <Field label="Telefon" value={valueFrom(detail, 'telefon') ?? valueFrom(item, 'telefon')} />
                            <Field label="E-posta" value={valueFrom(detail, 'email') ?? valueFrom(item, 'email')} />
                            <Field label="Vergi No" value={firstValue(detail, ['vergi_no', 'vergiNo', 'Vergi No']) ?? firstValue(item, ['vergi_no', 'vergiNo', 'Vergi No'])} />
                            <Field label="Vergi Dairesi" value={firstValue(detail, ['vergi_dairesi', 'vergiDairesi', 'Vergi Dairesi']) ?? firstValue(item, ['vergi_dairesi', 'vergiDairesi', 'Vergi Dairesi'])} />
                            <Field label="Grup" value={valueFrom(detail, 'cariGrup') ?? valueFrom(item, 'cariGrup')} />
                            <Field label="Temsilci" value={valueFrom(detail, 'temsilci') ?? valueFrom(item, 'temsilci')} />
                            <Field label="İl / İlçe" value={[valueFrom(detail, 'il') ?? valueFrom(item, 'il'), valueFrom(detail, 'ilce') ?? valueFrom(item, 'ilce')].filter(Boolean).join(' / ')} />
                            <Field label="Son Hareket" value={valueFrom(detail, 'sonHareket') ?? valueFrom(item, 'sonHareket')} columnKey="son_hareket_tarihi" />
                            <div className="sm:col-span-2">
                                <Field label="Adres" value={firstValue(detail, ['adres', 'address', 'Adres']) ?? firstValue(item, ['adres', 'address', 'Adres'])} />
                            </div>
                        </section>
                    </>
                )}

                {activeTab === 'balance' && (
                    <>
                        <StatusMessage loading={loadingDetail} error={detailError} />
                        <BalanceCards source={detail} />
                    </>
                )}

                {activeTab === 'statement' && (
                    <>
                        <StatusMessage loading={loadingStatement} error={statementError} />
                        {!loadingStatement && !statementError && <StatementRows rows={statementRows} />}
                    </>
                )}
            </div>

            <div className="mt-6 flex justify-end">
                <button type="button" onClick={() => window.print()} className="inline-flex items-center gap-2 rounded-xl bg-blue-700 px-4 py-2 text-sm font-semibold text-white">
                    <Printer className="size-4" />
                    Yazdır
                </button>
            </div>
        </aside>
    );
}
