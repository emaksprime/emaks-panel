import { useEffect, useState } from 'react';
import { apiRequest } from '@/lib/api';
import { AdminFrame } from './AdminFrame.jsx';

const blank = { id: null, code: '', name: '', db_type: 'mssql', query_template: '', allowed_params: [], connection_meta: {}, active: true, description: '', sort_order: 0 };

export default function AdminDataSources() {
    const [data, setData] = useState({ dataSources: [] });
    const [form, setForm] = useState(blank);
    const [allowedParamsText, setAllowedParamsText] = useState('');

    useEffect(() => {
        apiRequest('/api/admin/datasources').then(setData);
    }, []);

    const edit = (source) => {
        setForm(source);
        setAllowedParamsText((source.allowed_params ?? []).join(', '));
    };

    const save = async (event) => {
        event.preventDefault();
        const payload = {
            ...form,
            allowed_params: allowedParamsText.split(',').map((item) => item.trim()).filter(Boolean),
        };
        setData(await apiRequest('/api/admin/datasources', { method: 'POST', body: JSON.stringify(payload) }));
        setForm(blank);
        setAllowedParamsText('');
    };

    return (
        <AdminFrame title="Veri Kaynakları Yönetimi">
            <section className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_520px]">
                <div className="rounded-lg border border-slate-200 bg-white shadow-sm">
                    {data.dataSources.map((source) => (
                        <button key={source.id} type="button" onClick={() => edit(source)} className="grid w-full gap-1 border-b border-slate-100 px-4 py-3 text-left">
                            <strong className="text-slate-950">{source.name}</strong>
                            <span className="text-sm text-slate-500">{source.code} - {source.db_type} - {source.active ? 'Etkin' : 'Pasif'}</span>
                        </button>
                    ))}
                </div>
                <form onSubmit={save} className="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <input className="rounded-md border border-slate-200 px-3 py-2" placeholder="Kod" value={form.code} onChange={(event) => setForm({ ...form, code: event.target.value })} />
                    <input className="rounded-md border border-slate-200 px-3 py-2" placeholder="Ad" value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} />
                    <select className="rounded-md border border-slate-200 px-3 py-2" value={form.db_type} onChange={(event) => setForm({ ...form, db_type: event.target.value })}>
                        <option value="mssql">MSSQL</option>
                        <option value="postgres">PostgreSQL</option>
                    </select>
                    <input
                        className="rounded-md border border-slate-200 px-3 py-2"
                        placeholder="İzin verilen parametreler (date_from, date_to)"
                        value={allowedParamsText}
                        onChange={(event) => setAllowedParamsText(event.target.value)}
                    />
                    <textarea
                        className="min-h-56 rounded-md border border-slate-200 px-3 py-2 font-mono text-xs"
                        placeholder="Sorgu şablonu"
                        value={form.query_template}
                        onChange={(event) => setForm({ ...form, query_template: event.target.value })}
                    />
                    <textarea
                        className="min-h-20 rounded-md border border-slate-200 px-3 py-2"
                        placeholder="Açıklama"
                        value={form.description ?? ''}
                        onChange={(event) => setForm({ ...form, description: event.target.value })}
                    />
                    <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                        <input type="checkbox" checked={form.active} onChange={(event) => setForm({ ...form, active: event.target.checked })} />
                        Etkin
                    </label>
                    <button className="rounded-md bg-slate-900 px-3 py-2 font-semibold text-white">Veri kaynağını kaydet</button>
                </form>
            </section>
        </AdminFrame>
    );
}
