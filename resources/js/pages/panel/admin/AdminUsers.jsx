import { useEffect, useState } from 'react';
import { apiRequest } from '@/lib/api';
import { AdminFrame } from './AdminFrame.jsx';

const blank = {
    id: null,
    username: '',
    full_name: '',
    password: '',
    role_code: 'sales',
    temsilci_kodu: '',
    aktif: true,
    access: [],
};

export default function AdminUsers() {
    const [data, setData] = useState({ users: [], roles: [], resources: [] });
    const [form, setForm] = useState(blank);

    const load = () => apiRequest('/api/admin/users').then(setData);

    useEffect(() => {
        load();
    }, []);

    const save = async (event) => {
        event.preventDefault();
        const next = await apiRequest('/api/admin/users', {
            method: 'POST',
            body: JSON.stringify(form),
        });
        setData(next);
        setForm(blank);
    };

    const toggleAccess = (code) => {
        setForm((current) => ({
            ...current,
            access: current.access.includes(code)
                ? current.access.filter((item) => item !== code)
                : [...current.access, code],
        }));
    };

    return (
        <AdminFrame title="Kullanici Yonetimi">
            <section className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_420px]">
                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                    <table className="w-full min-w-[760px] text-sm">
                        <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                            <tr>
                                <th className="px-4 py-3">Kullanici</th>
                                <th className="px-4 py-3">Rol</th>
                                <th className="px-4 py-3">Temsilci</th>
                                <th className="px-4 py-3">Durum</th>
                                <th className="px-4 py-3" />
                            </tr>
                        </thead>
                        <tbody>
                            {data.users.map((user) => (
                                <tr key={user.id} className="border-t border-slate-100">
                                    <td className="px-4 py-3">
                                        <p className="font-semibold text-slate-950">{user.full_name}</p>
                                        <p className="text-slate-500">{user.username}</p>
                                    </td>
                                    <td className="px-4 py-3">{user.role_code}</td>
                                    <td className="px-4 py-3">{user.temsilci_kodu || '-'}</td>
                                    <td className="px-4 py-3">{user.aktif ? 'Aktif' : 'Pasif'}</td>
                                    <td className="px-4 py-3 text-right">
                                        <button
                                            type="button"
                                            onClick={() => setForm({ ...blank, ...user, password: '' })}
                                            className="rounded-md border border-slate-200 px-3 py-2 font-semibold text-slate-700"
                                        >
                                            Duzenle
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <form onSubmit={save} className="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <input className="rounded-md border border-slate-200 px-3 py-2" placeholder="Kullanici adi" value={form.username} onChange={(event) => setForm({ ...form, username: event.target.value })} />
                    <input className="rounded-md border border-slate-200 px-3 py-2" placeholder="Ad soyad" value={form.full_name} onChange={(event) => setForm({ ...form, full_name: event.target.value })} />
                    <input className="rounded-md border border-slate-200 px-3 py-2" type="password" placeholder={form.id ? 'Yeni sifre opsiyonel' : 'Sifre'} value={form.password} onChange={(event) => setForm({ ...form, password: event.target.value })} />
                    <select className="rounded-md border border-slate-200 px-3 py-2" value={form.role_code} onChange={(event) => setForm({ ...form, role_code: event.target.value })}>
                        {data.roles.map((role) => <option key={role.code} value={role.code}>{role.name}</option>)}
                    </select>
                    <input className="rounded-md border border-slate-200 px-3 py-2" placeholder="Temsilci kodu" value={form.temsilci_kodu ?? ''} onChange={(event) => setForm({ ...form, temsilci_kodu: event.target.value })} />
                    <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                        <input type="checkbox" checked={form.aktif} onChange={(event) => setForm({ ...form, aktif: event.target.checked })} />
                        Aktif
                    </label>
                    <div className="max-h-56 overflow-auto rounded-md border border-slate-200 p-2">
                        {data.resources.map((resource) => (
                            <label key={resource.code} className="flex items-center gap-2 py-1 text-sm">
                                <input type="checkbox" checked={form.access.includes(resource.code)} onChange={() => toggleAccess(resource.code)} />
                                {resource.name}
                            </label>
                        ))}
                    </div>
                    <button className="rounded-md bg-slate-900 px-3 py-2 font-semibold text-white">Kaydet</button>
                </form>
            </section>
        </AdminFrame>
    );
}
