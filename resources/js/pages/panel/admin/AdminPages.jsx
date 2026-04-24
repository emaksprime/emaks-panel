import { useEffect, useState } from 'react';
import { apiRequest } from '@/lib/api';
import { AdminFrame } from './AdminFrame.jsx';

const blank = { id: null, code: '', name: '', route: '', icon: '', resource_code: '', component: 'panel/page', description: '', page_order: 0, active: true };

export default function AdminPages() {
    const [data, setData] = useState({ pages: [] });
    const [form, setForm] = useState(blank);

    useEffect(() => {
        apiRequest('/api/admin/pages').then(setData);
    }, []);

    const save = async (event) => {
        event.preventDefault();
        setData(await apiRequest('/api/admin/pages', { method: 'POST', body: JSON.stringify(form) }));
        setForm(blank);
    };

    return (
        <AdminFrame title="Sayfa ve Menu Yonetimi">
            <section className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_420px]">
                <div className="rounded-lg border border-slate-200 bg-white shadow-sm">
                    {data.pages.map((page) => (
                        <button key={page.id} type="button" onClick={() => setForm(page)} className="grid w-full grid-cols-[1fr_auto] gap-3 border-b border-slate-100 px-4 py-3 text-left">
                            <span>
                                <strong className="block text-slate-950">{page.name}</strong>
                                <span className="text-sm text-slate-500">{page.route}</span>
                            </span>
                            <span className="text-sm font-semibold text-slate-500">{page.active ? 'Aktif' : 'Pasif'}</span>
                        </button>
                    ))}
                </div>
                <form onSubmit={save} className="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    {['code', 'name', 'route', 'icon', 'resource_code', 'component'].map((field) => (
                        <input key={field} className="rounded-md border border-slate-200 px-3 py-2" placeholder={field} value={form[field] ?? ''} onChange={(event) => setForm({ ...form, [field]: event.target.value })} />
                    ))}
                    <textarea className="min-h-24 rounded-md border border-slate-200 px-3 py-2" placeholder="description" value={form.description ?? ''} onChange={(event) => setForm({ ...form, description: event.target.value })} />
                    <input type="number" className="rounded-md border border-slate-200 px-3 py-2" value={form.page_order ?? 0} onChange={(event) => setForm({ ...form, page_order: Number(event.target.value) })} />
                    <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                        <input type="checkbox" checked={form.active} onChange={(event) => setForm({ ...form, active: event.target.checked })} />
                        Aktif
                    </label>
                    <button className="rounded-md bg-slate-900 px-3 py-2 font-semibold text-white">Kaydet</button>
                </form>
            </section>
        </AdminFrame>
    );
}
