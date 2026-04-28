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
    force_password_change: false,
    access: [],
    denied_access: [],
};

const resourceLabels = {
    page: 'Sayfalar',
    scope: 'Yonetim kapsamleri',
    data_source: 'Veri kaynagi yetkileri',
};

function groupResources(resources) {
    return resources.reduce((groups, resource) => {
        const key = resource.type || 'other';
        return {
            ...groups,
            [key]: [...(groups[key] ?? []), resource],
        };
    }, {});
}

export default function AdminUsers() {
    const [data, setData] = useState({ users: [], roles: [], resources: [] });
    const [form, setForm] = useState(blank);
    const [query, setQuery] = useState('');
    const [status, setStatus] = useState({ type: 'idle', message: '' });
    const [isLoading, setIsLoading] = useState(true);
    const [isSaving, setIsSaving] = useState(false);

    const load = async () => {
        setIsLoading(true);
        try {
            const next = await apiRequest('/api/admin/users');
            setData(next);
        } catch (error) {
            setStatus({ type: 'error', message: error.message });
        } finally {
            setIsLoading(false);
        }
    };

    useEffect(() => {
        load();
    }, []);

    const filteredUsers = data.users.filter((user) => {
        const haystack = [
            user.full_name,
            user.username,
            user.role_code,
            user.temsilci_kodu,
        ].join(' ').toLowerCase();

        return haystack.includes(query.toLowerCase());
    });

    const groupedResources = groupResources(data.resources);
    const selectedRole = data.roles.find((role) => role.code === form.role_code);

    const save = async (event) => {
        event.preventDefault();
        setIsSaving(true);
        setStatus({ type: 'idle', message: '' });

        try {
            const next = await apiRequest('/api/admin/users', {
                method: 'POST',
                body: JSON.stringify(form),
            });
            setData(next);
            setForm(blank);
            setStatus({
                type: 'success',
                message: 'Kullanici kaydedildi ve yetkileri guncellendi.',
            });
        } catch (error) {
            setStatus({ type: 'error', message: error.message });
        } finally {
            setIsSaving(false);
        }
    };

    const editUser = (user) => {
        setForm({
            ...blank,
            ...user,
            password: '',
            access: user.access ?? [],
            denied_access: user.denied_access ?? [],
        });
        setStatus({ type: 'idle', message: '' });
    };

    const setAccessState = (code, state) => {
        setForm((current) => ({
            ...current,
            access:
                state === 'allow'
                    ? [...new Set([...current.access, code])]
                    : current.access.filter((item) => item !== code),
            denied_access:
                state === 'deny'
                    ? [...new Set([...(current.denied_access ?? []), code])]
                    : (current.denied_access ?? []).filter((item) => item !== code),
        }));
    };

    const accessState = (code) => {
        if (form.access.includes(code)) {
            return 'allow';
        }

        if ((form.denied_access ?? []).includes(code)) {
            return 'deny';
        }

        return 'inherit';
    };

    const selectAll = () => {
        setForm((current) => ({
            ...current,
            access: data.resources.map((resource) => resource.code),
            denied_access: [],
        }));
    };

    const clearAccess = () => {
        setForm((current) => ({ ...current, access: [], denied_access: [] }));
    };

    return (
        <AdminFrame title="Kullanici Yonetimi">
            <section className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_460px]">
                <div className="space-y-4">
                    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                    Panel kullanicilari
                                </p>
                                <h2 className="mt-1 text-xl font-semibold text-slate-950">
                                    {data.users.length} kayit
                                </h2>
                            </div>
                            <input
                                className="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-slate-400 md:w-72"
                                placeholder="Kullanici, rol veya temsilci ara"
                                value={query}
                                onChange={(event) => setQuery(event.target.value)}
                            />
                        </div>
                    </div>

                    <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[780px] text-sm">
                                <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                    <tr>
                                        <th className="px-5 py-4">Kullanici</th>
                                        <th className="px-5 py-4">Rol</th>
                                        <th className="px-5 py-4">Temsilci</th>
                                        <th className="px-5 py-4">Yetki</th>
                                        <th className="px-5 py-4">Durum</th>
                                        <th className="px-5 py-4" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {isLoading && (
                                        <tr>
                                            <td className="px-5 py-8 text-center text-slate-500" colSpan={6}>
                                                Kullanicilar yukleniyor...
                                            </td>
                                        </tr>
                                    )}

                                    {!isLoading && filteredUsers.length === 0 && (
                                        <tr>
                                            <td className="px-5 py-8 text-center text-slate-500" colSpan={6}>
                                                Bu filtreyle kullanici bulunamadi.
                                            </td>
                                        </tr>
                                    )}

                                    {filteredUsers.map((user) => (
                                        <tr key={user.id} className="border-t border-slate-100">
                                            <td className="px-5 py-4">
                                                <p className="font-semibold text-slate-950">{user.full_name}</p>
                                                <p className="text-slate-500">{user.username}</p>
                                            </td>
                                            <td className="px-5 py-4">
                                                <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold uppercase tracking-[0.12em] text-slate-700">
                                                    {user.role_code}
                                                </span>
                                            </td>
                                            <td className="px-5 py-4 text-slate-600">{user.temsilci_kodu || '-'}</td>
                                            <td className="px-5 py-4 text-slate-600">
                                                {(user.access?.length ?? 0)} izin / {(user.denied_access?.length ?? 0)} engel
                                            </td>
                                            <td className="px-5 py-4">
                                                <span className={`rounded-full px-3 py-1 text-xs font-semibold ${user.aktif ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'}`}>
                                                    {user.aktif ? 'Aktif' : 'Pasif'}
                                                </span>
                                            </td>
                                            <td className="px-5 py-4 text-right">
                                                <button
                                                    type="button"
                                                    onClick={() => editUser(user)}
                                                    className="rounded-xl border border-slate-200 px-4 py-2 font-semibold text-slate-700 transition hover:border-slate-400 hover:text-slate-950"
                                                >
                                                    Duzenle
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <form onSubmit={save} className="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                {form.id ? 'Kullanici duzenle' : 'Yeni kullanici'}
                            </p>
                            <h2 className="mt-1 text-xl font-semibold text-slate-950">
                                {form.full_name || 'Kullanici bilgileri'}
                            </h2>
                        </div>
                        {form.id && (
                            <button
                                type="button"
                                onClick={() => setForm(blank)}
                                className="rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-600"
                            >
                                Yeni
                            </button>
                        )}
                    </div>

                    {status.message && (
                        <div className={`rounded-xl px-4 py-3 text-sm font-medium ${status.type === 'error' ? 'bg-rose-50 text-rose-700' : 'bg-emerald-50 text-emerald-700'}`}>
                            {status.message}
                        </div>
                    )}

                    <label className="grid gap-2 text-sm font-semibold text-slate-700">
                        Kullanici adi
                        <input
                            required
                            className="rounded-xl border border-slate-200 px-4 py-3 font-normal outline-none transition focus:border-slate-400"
                            placeholder="ornek.kullanici"
                            value={form.username}
                            onChange={(event) => setForm({ ...form, username: event.target.value })}
                        />
                    </label>

                    <label className="grid gap-2 text-sm font-semibold text-slate-700">
                        Ad soyad
                        <input
                            required
                            className="rounded-xl border border-slate-200 px-4 py-3 font-normal outline-none transition focus:border-slate-400"
                            placeholder="Ad Soyad"
                            value={form.full_name}
                            onChange={(event) => setForm({ ...form, full_name: event.target.value })}
                        />
                    </label>

                    <label className="grid gap-2 text-sm font-semibold text-slate-700">
                        Sifre
                        <input
                            className="rounded-xl border border-slate-200 px-4 py-3 font-normal outline-none transition focus:border-slate-400"
                            type="password"
                            placeholder={form.id ? 'Degistirmek icin yeni sifre girin' : 'Ilk sifre'}
                            value={form.password}
                            onChange={(event) => setForm({ ...form, password: event.target.value })}
                            required={!form.id}
                        />
                    </label>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <label className="grid gap-2 text-sm font-semibold text-slate-700">
                            Rol
                            <select
                                className="rounded-xl border border-slate-200 px-4 py-3 font-normal outline-none transition focus:border-slate-400"
                                value={form.role_code}
                                onChange={(event) => setForm({ ...form, role_code: event.target.value })}
                            >
                                {data.roles.map((role) => (
                                    <option key={role.code} value={role.code}>
                                        {role.name}
                                    </option>
                                ))}
                            </select>
                        </label>

                        <label className="grid gap-2 text-sm font-semibold text-slate-700">
                            Temsilci kodu
                            <input
                                className="rounded-xl border border-slate-200 px-4 py-3 font-normal outline-none transition focus:border-slate-400"
                                placeholder="0003"
                                value={form.temsilci_kodu ?? ''}
                                onChange={(event) => setForm({ ...form, temsilci_kodu: event.target.value })}
                            />
                        </label>
                    </div>

                    <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <label className="flex items-center justify-between gap-3 text-sm font-semibold text-slate-700">
                            <span>
                                Aktif kullanici
                                <small className="block font-normal text-slate-500">
                                    Pasif kullanicilar panele giris yapamaz.
                                </small>
                            </span>
                            <input
                                type="checkbox"
                                checked={form.aktif}
                                onChange={(event) => setForm({ ...form, aktif: event.target.checked })}
                            />
                        </label>
                    </div>

                    <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <label className="flex items-center justify-between gap-3 text-sm font-semibold text-slate-700">
                            <span>
                                Ilk giriste sifre degistirsin
                                <small className="block font-normal text-slate-500">
                                    Kullanici sonraki giriste yeni sifre belirlemeye yonlendirilebilir.
                                </small>
                            </span>
                            <input
                                type="checkbox"
                                checked={form.force_password_change}
                                onChange={(event) => setForm({ ...form, force_password_change: event.target.checked })}
                            />
                        </label>
                    </div>

                    <div className="grid gap-3">
                        <div className="flex items-center justify-between gap-2">
                            <div>
                                <p className="text-sm font-semibold text-slate-800">Kaynak yetkileri</p>
                                <p className="text-xs text-slate-500">
                                    {selectedRole?.name ?? form.role_code} rolu temel alinir; kullanici bazli izin veya engel ustune uygulanir.
                                </p>
                            </div>
                            <div className="flex gap-2">
                                <button type="button" onClick={selectAll} className="text-xs font-semibold text-slate-700">
                                    Tumune izin ver
                                </button>
                                <button type="button" onClick={clearAccess} className="text-xs font-semibold text-slate-500">
                                    Role birak
                                </button>
                            </div>
                        </div>

                        <div className="max-h-72 overflow-auto rounded-xl border border-slate-200">
                            {Object.entries(groupedResources).map(([type, resources]) => (
                                <div key={type} className="border-b border-slate-100 last:border-b-0">
                                    <div className="bg-slate-50 px-4 py-2 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                        {resourceLabels[type] ?? type}
                                    </div>
                                    <div className="grid gap-1 p-3">
                                        {resources.map((resource) => (
                                            <div key={resource.code} className="grid gap-2 rounded-lg px-2 py-2 text-sm hover:bg-slate-50 sm:grid-cols-[minmax(0,1fr)_140px] sm:items-center">
                                                <span className="min-w-0">
                                                    <span className="font-medium text-slate-800">{resource.name}</span>
                                                    <span className="ml-2 text-xs text-slate-400">{resource.code}</span>
                                                </span>
                                                <select
                                                    className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 outline-none transition focus:border-slate-400"
                                                    value={accessState(resource.code)}
                                                    onChange={(event) => setAccessState(resource.code, event.target.value)}
                                                >
                                                    <option value="inherit">Rol kararini kullan</option>
                                                    <option value="allow">Izin ver</option>
                                                    <option value="deny">Engelle</option>
                                                </select>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>

                    <button
                        className="rounded-xl bg-slate-950 px-4 py-3 font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                        disabled={isSaving}
                    >
                        {isSaving ? 'Kaydediliyor...' : 'Kullaniciyi kaydet'}
                    </button>
                </form>
            </section>
        </AdminFrame>
    );
}
