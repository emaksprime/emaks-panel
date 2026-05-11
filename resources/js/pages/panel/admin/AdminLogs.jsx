import { useEffect, useMemo, useState } from 'react';
import { apiRequest } from '@/lib/api';
import { AdminFrame } from './AdminFrame.jsx';

const defaultFilters = {
    q: '',
    action: '',
    page: '',
    date_from: '',
    date_to: '',
    limit: '200',
};

function queryString(filters) {
    const params = new URLSearchParams();

    Object.entries(filters).forEach(([key, value]) => {
        if (value !== '') {
            params.set(key, value);
        }
    });

    return params.toString();
}

function displayUser(log) {
    return log.user_name || log.full_name || log.username || (log.user_id ? `Kullanıcı #${log.user_id}` : 'Sistem');
}

function prettyPayload(payload) {
    return JSON.stringify(payload ?? {}, null, 2);
}

export default function AdminLogs() {
    const [data, setData] = useState({ logs: [], summary: {}, options: { actions: [], pages: [] } });
    const [filters, setFilters] = useState(defaultFilters);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [openLogId, setOpenLogId] = useState(null);

    const loadLogs = (nextFilters = filters) => {
        setLoading(true);
        setError('');

        apiRequest(`/api/admin/logs?${queryString(nextFilters)}`)
            .then((payload) => setData({
                logs: payload.logs ?? [],
                summary: payload.summary ?? {},
                options: payload.options ?? { actions: [], pages: [] },
            }))
            .catch((exception) => setError(exception.message || 'Log kayıtları alınamadı.'))
            .finally(() => setLoading(false));
    };

    const handleSubmit = (event) => {
        event.preventDefault();
        loadLogs(filters);
    };

    const resetFilters = () => {
        setFilters(defaultFilters);
        setOpenLogId(null);
        loadLogs(defaultFilters);
    };

    useEffect(() => {
        loadLogs();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const summaryCards = useMemo(() => [
        ['Bugünkü log', data.summary?.today_count ?? 0],
        ['Bugünkü kullanıcı', data.summary?.unique_users_today ?? 0],
        ['Son kayıt saati', data.summary?.last_log_at ?? '-'],
        ['Arşiv durumu', data.summary?.archived_available ? 'Hazır' : 'Kurulum bekliyor'],
    ], [data.summary]);

    return (
        <AdminFrame title="Sistem Kayıtları">
            <div className="grid gap-4">
                <section className="grid gap-3 md:grid-cols-4">
                    {summaryCards.map(([label, value]) => (
                        <div key={label} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                            <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{label}</p>
                            <p className="mt-2 text-xl font-semibold text-slate-950">{value}</p>
                        </div>
                    ))}
                </section>

                <form onSubmit={handleSubmit} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div className="grid gap-3 lg:grid-cols-[1.4fr_1fr_1fr_0.9fr_0.9fr_0.7fr_auto]">
                        <label className="grid gap-1 text-sm font-semibold text-slate-700">
                            Kullanıcı / arama
                            <input
                                value={filters.q}
                                onChange={(event) => setFilters((current) => ({ ...current, q: event.target.value }))}
                                placeholder="Kullanıcı, sayfa, IP veya arama..."
                                className="rounded-xl border border-slate-200 px-3 py-2 text-sm font-normal outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100"
                            />
                        </label>
                        <label className="grid gap-1 text-sm font-semibold text-slate-700">
                            İşlem tipi
                            <select
                                value={filters.action}
                                onChange={(event) => setFilters((current) => ({ ...current, action: event.target.value }))}
                                className="rounded-xl border border-slate-200 px-3 py-2 text-sm font-normal outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100"
                            >
                                <option value="">Tümü</option>
                                {(data.options?.actions ?? []).map((action) => (
                                    <option key={action.value} value={action.value}>{action.label}</option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm font-semibold text-slate-700">
                            Sayfa
                            <select
                                value={filters.page}
                                onChange={(event) => setFilters((current) => ({ ...current, page: event.target.value }))}
                                className="rounded-xl border border-slate-200 px-3 py-2 text-sm font-normal outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100"
                            >
                                <option value="">Tümü</option>
                                {(data.options?.pages ?? []).map((page) => (
                                    <option key={page.value} value={page.value}>{page.label}</option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm font-semibold text-slate-700">
                            Başlangıç
                            <input
                                type="date"
                                value={filters.date_from}
                                onChange={(event) => setFilters((current) => ({ ...current, date_from: event.target.value }))}
                                className="rounded-xl border border-slate-200 px-3 py-2 text-sm font-normal outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100"
                            />
                        </label>
                        <label className="grid gap-1 text-sm font-semibold text-slate-700">
                            Bitiş
                            <input
                                type="date"
                                value={filters.date_to}
                                onChange={(event) => setFilters((current) => ({ ...current, date_to: event.target.value }))}
                                className="rounded-xl border border-slate-200 px-3 py-2 text-sm font-normal outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100"
                            />
                        </label>
                        <label className="grid gap-1 text-sm font-semibold text-slate-700">
                            Limit
                            <select
                                value={filters.limit}
                                onChange={(event) => setFilters((current) => ({ ...current, limit: event.target.value }))}
                                className="rounded-xl border border-slate-200 px-3 py-2 text-sm font-normal outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100"
                            >
                                <option value="100">100</option>
                                <option value="200">200</option>
                                <option value="500">500</option>
                                <option value="1000">1000</option>
                            </select>
                        </label>
                        <div className="flex items-end gap-2">
                            <button
                                type="submit"
                                className="rounded-xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800"
                            >
                                {loading ? 'Yükleniyor...' : 'Yenile'}
                            </button>
                            <button
                                type="button"
                                onClick={resetFilters}
                                className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 hover:text-slate-950"
                            >
                                Temizle
                            </button>
                        </div>
                    </div>
                    {error && <p className="mt-3 rounded-xl bg-red-50 px-3 py-2 text-sm font-semibold text-red-700">{error}</p>}
                </form>

                <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[1120px] text-sm">
                            <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                <tr>
                                    <th className="px-4 py-3">Tarih/Saat</th>
                                    <th className="px-4 py-3">Kullanıcı</th>
                                    <th className="px-4 py-3">İşlem</th>
                                    <th className="px-4 py-3">Sayfa/Yol</th>
                                    <th className="px-4 py-3">IP</th>
                                    <th className="px-4 py-3">Cihaz/Tarayıcı</th>
                                    <th className="px-4 py-3">Arama/Filtre</th>
                                    <th className="px-4 py-3">Detay</th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.logs.map((log) => (
                                    <tr key={log.id} className="border-t border-slate-100 align-top">
                                        <td className="whitespace-nowrap px-4 py-3 font-medium text-slate-700">{log.created_at_human}</td>
                                        <td className="px-4 py-3">
                                            <p className="font-semibold text-slate-900">{displayUser(log)}</p>
                                            {log.username && log.username !== displayUser(log) && (
                                                <p className="text-xs text-slate-500">{log.username}</p>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <p className="font-semibold text-slate-950">{log.action_label || log.action}</p>
                                            <p className="font-mono text-xs text-slate-400">{log.action}</p>
                                        </td>
                                        <td className="px-4 py-3">
                                            <p className="font-semibold text-slate-800">{log.page_label || '-'}</p>
                                            <p className="max-w-[220px] truncate text-xs text-slate-500">{log.path || '-'}</p>
                                        </td>
                                        <td className="whitespace-nowrap px-4 py-3 text-slate-600">{log.ip_address || '-'}</td>
                                        <td className="px-4 py-3">
                                            <p className="text-slate-700">{log.device_label || '-'}</p>
                                            <p className="text-xs text-slate-500">{log.browser_label || '-'}</p>
                                        </td>
                                        <td className="px-4 py-3">
                                            <p className="max-w-[240px] break-words font-medium text-slate-800">{log.search_term || '-'}</p>
                                            <p className="max-w-[260px] break-words text-xs text-slate-500">{log.filters_summary || log.payload_summary || '-'}</p>
                                        </td>
                                        <td className="px-4 py-3">
                                            <button
                                                type="button"
                                                onClick={() => setOpenLogId((current) => (current === log.id ? null : log.id))}
                                                className="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:border-blue-200 hover:text-blue-700"
                                            >
                                                {openLogId === log.id ? 'Detay gizle' : 'Detay göster'}
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {data.logs.length === 0 && (
                        <div className="px-4 py-10 text-center text-sm font-semibold text-slate-500">Filtrelerle eşleşen log kaydı bulunamadı.</div>
                    )}
                </section>

                {openLogId && (
                    <section className="rounded-2xl border border-slate-200 bg-slate-950 p-4 text-slate-100 shadow-sm">
                        <div className="mb-3 flex items-center justify-between gap-3">
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">Ham detay</p>
                                <p className="text-sm font-semibold">Log #{openLogId}</p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setOpenLogId(null)}
                                className="rounded-lg border border-white/10 px-3 py-1.5 text-xs font-semibold text-slate-200 hover:bg-white/10"
                            >
                                Kapat
                            </button>
                        </div>
                        <pre className="max-h-[420px] overflow-auto rounded-xl bg-black/35 p-4 text-xs leading-5 text-slate-100">
                            {prettyPayload(data.logs.find((log) => log.id === openLogId)?.raw_payload)}
                        </pre>
                    </section>
                )}
            </div>
        </AdminFrame>
    );
}
