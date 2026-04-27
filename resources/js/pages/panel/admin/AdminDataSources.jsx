import { useEffect, useState } from 'react';
import { apiRequest } from '@/lib/api';
import { AdminFrame } from './AdminFrame.jsx';

const blank = {
    id: null,
    code: '',
    name: '',
    db_type: 'n8n_json',
    query_template: '',
    allowed_params: [],
    connection_meta: {},
    preview_payload: {},
    active: true,
    description: '',
    sort_order: 0,
};

const dbTypeLabels = {
    mssql: 'Microsoft SQL Server (mssql)',
    postgres: 'PostgreSQL (postgres)',
    n8n_json: 'n8n JSON Webhook (n8n_json)',
    static_preview: 'Statik Önizleme (static_preview)',
};

function pretty(value) {
    return JSON.stringify(value ?? {}, null, 2);
}

function parseJson(text, fallback) {
    if (!text.trim()) {
        return fallback;
    }

    return JSON.parse(text);
}

export default function AdminDataSources() {
    const [data, setData] = useState({ dataSources: [] });
    const [form, setForm] = useState(blank);
    const [allowedParamsText, setAllowedParamsText] = useState('');
    const [connectionMetaText, setConnectionMetaText] = useState('{}');
    const [previewPayloadText, setPreviewPayloadText] = useState('{}');
    const [status, setStatus] = useState('');
    const [error, setError] = useState('');
    const [testing, setTesting] = useState(false);
    const [testResult, setTestResult] = useState(null);

    useEffect(() => {
        void apiRequest('/api/admin/datasources').then(setData);
    }, []);

    const edit = (source) => {
        setForm({ ...blank, ...source });
        setAllowedParamsText((source.allowed_params ?? []).join(', '));
        setConnectionMetaText(pretty(source.connection_meta));
        setPreviewPayloadText(pretty(source.preview_payload));
        setStatus('');
        setError('');
        setTestResult(null);
    };

    const save = async (event) => {
        event.preventDefault();
        setError('');
        setStatus('');

        try {
            const payload = {
                ...form,
                sort_order: Number(form.sort_order ?? 0),
                allowed_params: allowedParamsText.split(',').map((item) => item.trim()).filter(Boolean),
                connection_meta: parseJson(connectionMetaText, {}),
                preview_payload: parseJson(previewPayloadText, {}),
            };
            const next = await apiRequest('/api/admin/datasources', {
                method: 'POST',
                body: JSON.stringify(payload),
            });
            setData(next);
            setForm(blank);
            setAllowedParamsText('');
            setConnectionMetaText('{}');
            setPreviewPayloadText('{}');
            setStatus('Veri kaynağı metadata kaydedildi.');
        } catch (caught) {
            setError(caught instanceof SyntaxError ? `JSON parse hatası: ${caught.message}` : (caught.message ?? 'Veri kaynağı kaydedilemedi.'));
        }
    };

    const testSource = async () => {
        if (!form.code) {
            setError('Test için önce bir veri kaynağı seçin veya kod girin.');
            return;
        }

        setTesting(true);
        setError('');
        setTestResult(null);

        try {
            const result = await apiRequest('/api/admin/datasources/test', {
                method: 'POST',
                body: JSON.stringify({
                    code: form.code,
                    grain: 'week',
                    detail_type: 'cari',
                    scope_key: 'all',
                }),
            });
            setTestResult(result);
        } catch (caught) {
            setError(caught.message ?? 'Veri kaynağı test edilemedi.');
        } finally {
            setTesting(false);
        }
    };

    return (
        <AdminFrame title="Veri Kaynakları">
            {status && (
                <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">
                    {status}
                </div>
            )}

            {error && (
                <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
                    {error}
                </div>
            )}

            <section className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_560px]">
                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    {data.dataSources.map((source) => (
                        <button
                            key={source.id}
                            type="button"
                            onClick={() => edit(source)}
                            className="grid w-full gap-1 border-b border-slate-100 px-5 py-4 text-left transition hover:bg-slate-50"
                        >
                            <div className="flex items-center justify-between gap-3">
                                <strong className="text-slate-950">{source.name}</strong>
                                <span className={`rounded-full px-3 py-1 text-xs font-semibold ${source.active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}>
                                    {source.active ? 'Aktif' : 'Pasif'}
                                </span>
                            </div>
                            <span className="text-sm text-slate-500">{source.code} - {source.db_type}</span>
                            {source.description && <span className="text-sm text-slate-600">{source.description}</span>}
                        </button>
                    ))}
                </div>

                <form onSubmit={save} className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div>
                        <h2 className="text-lg font-semibold text-slate-950">Veri Kaynağı Düzenle</h2>
                        <p className="mt-1 text-sm text-slate-500">
                            Kaydet, Test Et ve Önizleme akışı panel.data_sources metadata kaydı üzerinden çalışır.
                        </p>
                    </div>

                    <label className="grid gap-1 text-sm font-semibold text-slate-700">
                        Kod
                        <input className="rounded-xl border border-slate-200 px-3 py-2 font-normal" value={form.code} onChange={(event) => setForm({ ...form, code: event.target.value })} />
                    </label>
                    <label className="grid gap-1 text-sm font-semibold text-slate-700">
                        Ad
                        <input className="rounded-xl border border-slate-200 px-3 py-2 font-normal" value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} />
                    </label>
                    <label className="grid gap-1 text-sm font-semibold text-slate-700">
                        Açıklama
                        <textarea className="min-h-20 rounded-xl border border-slate-200 px-3 py-2 font-normal" value={form.description ?? ''} onChange={(event) => setForm({ ...form, description: event.target.value })} />
                    </label>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <label className="grid gap-1 text-sm font-semibold text-slate-700">
                            Veri tipi
                            <select className="rounded-xl border border-slate-200 px-3 py-2 font-normal" value={form.db_type} onChange={(event) => setForm({ ...form, db_type: event.target.value })}>
                                {['mssql', 'postgres', 'n8n_json', 'static_preview'].map((type) => <option key={type} value={type}>{dbTypeLabels[type]}</option>)}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm font-semibold text-slate-700">
                            Sıralama
                            <input type="number" className="rounded-xl border border-slate-200 px-3 py-2 font-normal" value={form.sort_order ?? 0} onChange={(event) => setForm({ ...form, sort_order: Number(event.target.value) })} />
                        </label>
                    </div>
                    <label className="grid gap-1 text-sm font-semibold text-slate-700">
                        İzinli Parametreler (allowed_params)
                        <input className="rounded-xl border border-slate-200 px-3 py-2 font-normal" placeholder="date_from, date_to, rep_code, search" value={allowedParamsText} onChange={(event) => setAllowedParamsText(event.target.value)} />
                    </label>
                    <label className="grid gap-1 text-sm font-semibold text-slate-700">
                        SQL / Sorgu Metni
                        <textarea className="min-h-64 rounded-xl border border-slate-200 bg-slate-950 px-3 py-3 font-mono text-xs text-slate-100" value={form.query_template} onChange={(event) => setForm({ ...form, query_template: event.target.value })} />
                    </label>
                    <label className="grid gap-1 text-sm font-semibold text-slate-700">
                        Bağlantı Ayarları JSON
                        <textarea className="min-h-44 rounded-xl border border-slate-200 px-3 py-3 font-mono text-xs" value={connectionMetaText} onChange={(event) => setConnectionMetaText(event.target.value)} />
                    </label>
                    <label className="grid gap-1 text-sm font-semibold text-slate-700">
                        Önizleme Verisi JSON
                        <textarea className="min-h-44 rounded-xl border border-slate-200 px-3 py-3 font-mono text-xs" value={previewPayloadText} onChange={(event) => setPreviewPayloadText(event.target.value)} />
                    </label>
                    <label className="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm font-semibold text-slate-700">
                        <input type="checkbox" checked={form.active} onChange={(event) => setForm({ ...form, active: event.target.checked })} />
                        Aktif
                    </label>
                    <div className="grid gap-2 sm:grid-cols-2">
                        <button className="rounded-xl bg-slate-950 px-3 py-3 font-semibold text-white">Veri Kaynağını Kaydet</button>
                        <button type="button" onClick={testSource} disabled={testing} className="rounded-xl border border-slate-200 bg-white px-3 py-3 font-semibold text-slate-700 disabled:opacity-60">
                            {testing ? 'Test ediliyor...' : 'Test Et'}
                        </button>
                    </div>
                    {testResult && (
                        <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <strong className="text-sm text-slate-950">Önizleme</strong>
                                <span className="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">
                                    {testResult.rows_count ?? 0} satır
                                </span>
                            </div>
                            <pre className="mt-3 max-h-80 overflow-auto rounded-lg bg-slate-950 p-3 text-xs text-slate-100">
                                {JSON.stringify(testResult.first_5_rows ?? [], null, 2)}
                            </pre>
                        </div>
                    )}
                </form>
            </section>
        </AdminFrame>
    );
}
