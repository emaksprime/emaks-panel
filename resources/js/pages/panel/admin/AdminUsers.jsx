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
    allowed_cari_groups: '',
    denied_cari_groups: '',
    strict_access: false,
};

const cloneBlank = {
    username: '',
    full_name: '',
    password: '',
    temsilci_kodu: '',
    aktif: true,
    force_password_change: true,
    strict_access: true,
};

const groupOrder = [
    'Satış Yönetimi',
    'Stok Yönetimi',
    'Sipariş Yönetimi',
    'Teknik Servis',
    'Müşteri Yönetimi',
    'Proforma',
    'Sistem Yönetimi',
    'Veri Kaynakları',
];

function groupResources(resources) {
    const uniqueResources = Array.from(
        new Map(resources.map((resource) => [resource.code, resource])).values(),
    );

    return uniqueResources.reduce((groups, resource) => {
        const key = resource.group || 'Sistem Yönetimi';
        return {
            ...groups,
            [key]: [...(groups[key] ?? []), resource],
        };
    }, {});
}

const typeLabels = {
    page: 'Ekranlar',
    action: 'Butonlar/Aksiyonlar',
    data_source: 'Veri kaynakları',
    scope: 'Kapsamlar/Scope’lar',
};

const typeOrder = ['page', 'action', 'data_source', 'scope', 'other'];

const salesScopeResourceCodes = new Set([
    'sales_main_all',
    'sales_online',
    'sales_bayi',
    'sales_rep_salih_cakir',
    'sales_rep_umit_yildiz',
    'sales_rep_bulent_saglam',
]);

function resourceTypeLabel(type) {
    return typeLabels[type] ?? 'Diğer izinler';
}

function groupResourcesByType(resources) {
    return resources.reduce((groups, resource) => {
        const key = salesScopeResourceCodes.has(resource.code)
            ? 'scope'
            : typeLabels[resource.type]
                ? resource.type
                : 'other';
        return {
            ...groups,
            [key]: [...(groups[key] ?? []), resource],
        };
    }, {});
}

export default function AdminUsers() {
    const [data, setData] = useState({ users: [], roles: [], resources: [], rolePermissions: {} });
    const [form, setForm] = useState(blank);
    const [query, setQuery] = useState('');
    const [status, setStatus] = useState({ type: 'idle', message: '' });
    const [isLoading, setIsLoading] = useState(true);
    const [isSaving, setIsSaving] = useState(false);
    const [cloneSource, setCloneSource] = useState(null);
    const [cloneForm, setCloneForm] = useState(cloneBlank);
    const [isCloning, setIsCloning] = useState(false);

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
    const groupedResourceEntries = Object.entries(groupedResources).sort(
        ([left], [right]) =>
            (groupOrder.indexOf(left) === -1 ? 999 : groupOrder.indexOf(left)) -
            (groupOrder.indexOf(right) === -1 ? 999 : groupOrder.indexOf(right)),
    );
    const selectedRole = data.roles.find((role) => role.code === form.role_code);
    const selectedRoleIsSuperAdmin = Boolean(selectedRole?.is_super_admin ?? selectedRole?.isSuperAdmin);
    const activeResourceCodes = data.resources.map((resource) => resource.code);
    const roleAllowedResources = new Set(data.rolePermissions?.[form.role_code] ?? []);

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
                message: 'Kullanıcı kaydedildi ve yetkileri güncellendi.',
            });
        } catch (error) {
            setStatus({ type: 'error', message: error.message });
        } finally {
            setIsSaving(false);
        }
    };

    const openClone = (user) => {
        setCloneSource(user);
        setCloneForm(cloneBlank);
        setStatus({ type: 'idle', message: '' });
    };

    const closeClone = () => {
        setCloneSource(null);
        setCloneForm(cloneBlank);
        setIsCloning(false);
    };

    const cloneUser = async (event) => {
        event.preventDefault();

        if (!cloneSource) {
            return;
        }

        setIsCloning(true);
        setStatus({ type: 'idle', message: '' });

        try {
            const next = await apiRequest(`/api/admin/users/${cloneSource.id}/clone`, {
                method: 'POST',
                body: JSON.stringify(cloneForm),
            });
            setData(next);
            closeClone();
            setStatus({
                type: 'success',
                message: 'Kullanıcı kopyalandı. Rol ve izinler kaynak kullanıcıdan alındı.',
            });
        } catch (error) {
            setStatus({ type: 'error', message: error.message });
            setIsCloning(false);
        }
    };

    const editUser = (user) => {
        setForm({
            ...blank,
            ...user,
            password: '',
            access: user.access ?? [],
            denied_access: user.denied_access ?? [],
            allowed_cari_groups: (user.allowed_cari_groups ?? []).join(','),
            denied_cari_groups: (user.denied_cari_groups ?? []).join(','),
            strict_access: false,
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

    const effectiveAccess = (code) => {
        const state = accessState(code);

        if (state === 'allow') {
            return true;
        }

        if (state === 'deny') {
            return false;
        }

        return roleAllowedResources.has(code);
    };

    const moduleAccessState = (resources) => {
        const enabledCount = resources.filter((resource) => effectiveAccess(resource.code)).length;

        if (enabledCount === 0) {
            return 'none';
        }

        return enabledCount === resources.length ? 'all' : 'partial';
    };

    const setModuleAccess = (groupName, resources, enabled) => {
        const codes = resources
            .filter((resource) => enabled === false || groupName !== 'Satış Yönetimi' || !salesScopeResourceCodes.has(resource.code))
            .map((resource) => resource.code);

        setForm((current) => ({
            ...current,
            access: enabled
                ? [...new Set([...(current.access ?? []), ...codes])]
                : (current.access ?? []).filter((code) => !codes.includes(code)),
            denied_access: enabled
                ? (current.denied_access ?? []).filter((code) => !codes.includes(code))
                : [...new Set([...(current.denied_access ?? []), ...codes])],
        }));
    };

    const selectAll = () => {
        setForm((current) => ({
            ...current,
            access: data.resources.map((resource) => resource.code),
            denied_access: [],
            strict_access: false,
        }));
    };

    const clearAccess = () => {
        setForm((current) => ({
            ...current,
            access: [],
            denied_access: [],
            strict_access: false,
        }));
    };

    const applyStrictAccess = () => {
        if (selectedRoleIsSuperAdmin) {
            setStatus({
                type: 'error',
                message: 'Super admin rolü sadece seçilen kaynaklarla sınırlandırılamaz.',
            });

            return;
        }

        setForm((current) => {
            const allowed = [...new Set([...(current.access ?? []), 'dashboard'])];

            return {
                ...current,
                access: allowed,
                denied_access: activeResourceCodes.filter((code) => !allowed.includes(code)),
                strict_access: true,
            };
        });
        setStatus({
            type: 'success',
            message: 'Sadece seçilen kaynaklar izinli, diğer aktif kaynaklar engelli olarak işaretlendi.',
        });
    };

    return (
        <AdminFrame title="Kullanıcı Yönetimi">
            <section className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_460px]">
                <div className="space-y-4">
                    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                    Panel kullanıcıları
                                </p>
                                <h2 className="mt-1 text-xl font-semibold text-slate-950">
                                    {data.users.length} kayıt
                                </h2>
                            </div>
                            <input
                                className="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-slate-400 md:w-72"
                                placeholder="Kullanıcı, rol veya temsilci ara"
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
                                        <th className="px-5 py-4">Kullanıcı</th>
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
                                                Kullanıcılar yükleniyor...
                                            </td>
                                        </tr>
                                    )}

                                    {!isLoading && filteredUsers.length === 0 && (
                                        <tr>
                                            <td className="px-5 py-8 text-center text-slate-500" colSpan={6}>
                                                Bu filtreyle kullanıcı bulunamadı.
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
                                            <td className="px-5 py-4">
                                                <div className="flex justify-end gap-2">
                                                    <button
                                                        type="button"
                                                        onClick={() => openClone(user)}
                                                        className="rounded-xl border border-slate-200 px-4 py-2 font-semibold text-slate-600 transition hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700"
                                                    >
                                                        Kopyala
                                                    </button>
                                                <button
                                                    type="button"
                                                    onClick={() => editUser(user)}
                                                    className="rounded-xl border border-slate-200 px-4 py-2 font-semibold text-slate-700 transition hover:border-slate-400 hover:text-slate-950"
                                                >
                                                    Düzenle
                                                </button>
                                                </div>
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
                                {form.id ? 'Kullanıcı düzenle' : 'Yeni kullanıcı'}
                            </p>
                            <h2 className="mt-1 text-xl font-semibold text-slate-950">
                                {form.full_name || 'Kullanıcı bilgileri'}
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
                        Kullanıcı adı
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
                        Şifre
                        <input
                            className="rounded-xl border border-slate-200 px-4 py-3 font-normal outline-none transition focus:border-slate-400"
                            type="password"
                            placeholder={form.id ? 'Değiştirmek için yeni şifre girin' : 'İlk şifre'}
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
                                Aktif kullanıcı
                                <small className="block font-normal text-slate-500">
                                    Pasif kullanıcılar panele giriş yapamaz.
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
                                İlk girişte şifre değiştirsin
                                <small className="block font-normal text-slate-500">
                                    Kullanıcı sonraki girişte yeni şifre belirlemeye yönlendirilebilir.
                                </small>
                            </span>
                            <input
                                type="checkbox"
                                checked={form.force_password_change}
                                onChange={(event) => setForm({ ...form, force_password_change: event.target.checked })}
                            />
                        </label>
                    </div>

                    <div className="grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <div>
                            <p className="text-sm font-semibold text-slate-800">Cari Grup Kodu Yetkileri</p>
                            <p className="text-xs text-slate-500">
                                Virgülle ayırarak girin. Deny listesi allow listesinden öncelikli uygulanır.
                            </p>
                        </div>
                        <label className="grid gap-2 text-sm font-semibold text-slate-700">
                            Allow listesi
                            <input
                                className="rounded-xl border border-slate-200 px-4 py-3 font-normal outline-none transition focus:border-slate-400"
                                placeholder="120.11,120.12"
                                value={form.allowed_cari_groups ?? ''}
                                onChange={(event) => setForm({ ...form, allowed_cari_groups: event.target.value })}
                            />
                        </label>
                        <label className="grid gap-2 text-sm font-semibold text-slate-700">
                            Deny listesi
                            <input
                                className="rounded-xl border border-slate-200 px-4 py-3 font-normal outline-none transition focus:border-slate-400"
                                placeholder="120.11"
                                value={form.denied_cari_groups ?? ''}
                                onChange={(event) => setForm({ ...form, denied_cari_groups: event.target.value })}
                            />
                        </label>
                    </div>

                    <div className="grid gap-3">
                        <div className="flex items-center justify-between gap-2">
                            <div>
                                <p className="text-sm font-semibold text-slate-800">Kaynak yetkileri</p>
                                <p className="text-xs text-slate-500">
                                    {selectedRole?.name ?? form.role_code} rolü temel alınır; kullanıcı bazlı izin veya engel bu listeye uygulanır.
                                </p>
                            </div>
                            <div className="flex gap-2">
                                <button type="button" onClick={selectAll} className="text-xs font-semibold text-slate-700">
                                    Tümüne izin ver
                                </button>
                                <button
                                    type="button"
                                    onClick={applyStrictAccess}
                                    disabled={selectedRoleIsSuperAdmin}
                                    className="text-xs font-semibold text-emerald-700 disabled:cursor-not-allowed disabled:text-slate-300"
                                >
                                    Sadece seçilenlere izin ver
                                </button>
                                <button type="button" onClick={clearAccess} className="text-xs font-semibold text-slate-500">
                                    Role bırak
                                </button>
                            </div>
                        </div>
                        <p className="rounded-xl border border-amber-100 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800">
                            Sadece seçilenlere izin ver aksiyonu, seçili kaynakları izin listesine alır ve diğer tüm aktif kaynakları kullanıcı bazlı engel listesine yazar.
                        </p>

                        <div className="max-h-[34rem] overflow-auto rounded-xl border border-slate-200">
                            {groupedResourceEntries.map(([groupName, resources]) => {
                                const access = moduleAccessState(resources);
                                const resourcesByType = groupResourcesByType(resources);

                                return (
                                    <div key={groupName} className="border-b border-slate-100 last:border-b-0">
                                        <div className="flex items-start justify-between gap-3 bg-slate-50 px-4 py-3">
                                            <div>
                                                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                    {groupName}
                                                </p>
                                                <p className="mt-1 text-[11px] font-medium text-slate-500">
                                                    Durum: {access === 'all' ? 'Tüm modül açık' : access === 'partial' ? 'Kısmi erişim' : 'Modül kapalı'}
                                                </p>
                                            </div>
                                            <label className="flex shrink-0 items-center gap-2 text-xs font-semibold text-slate-700">
                                                Bu modüle erişim
                                                <input
                                                    type="checkbox"
                                                    checked={access !== 'none'}
                                                    onChange={(event) => setModuleAccess(groupName, resources, event.target.checked)}
                                                />
                                            </label>
                                        </div>

                                        <div className="grid gap-3 p-3">
                                            {typeOrder
                                                .filter((type) => resourcesByType[type]?.length)
                                                .map((type) => (
                                                    <div key={`${groupName}-${type}`} className="rounded-xl border border-slate-100">
                                                        <div className="border-b border-slate-100 bg-white px-3 py-2 text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                            {resourceTypeLabel(type)}
                                                        </div>
                                                        <div className="grid gap-1 p-2">
                                                            {resourcesByType[type].map((resource) => (
                                                                <div key={resource.code} className="grid gap-2 rounded-lg px-2 py-2 text-sm hover:bg-slate-50 sm:grid-cols-[minmax(0,1fr)_140px] sm:items-center">
                                                                    <span className="min-w-0">
                                                                        <span className="font-medium text-slate-800">{resource.name}</span>
                                                                        <span className="ml-2 text-xs text-slate-400">{resource.code}</span>
                                                                        <span className="mt-1 block text-xs text-slate-500">
                                                                            Rol kararı: {roleAllowedResources.has(resource.code) ? 'izinli' : 'kapalı'} · Etkin durum: {effectiveAccess(resource.code) ? 'açık' : 'kapalı'}
                                                                        </span>
                                                                    </span>
                                                                    <select
                                                                        className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 outline-none transition focus:border-slate-400"
                                                                        value={accessState(resource.code)}
                                                                        onChange={(event) => setAccessState(resource.code, event.target.value)}
                                                                    >
                                                                        <option value="inherit">Rol kararını kullan</option>
                                                                        <option value="allow">İzin ver</option>
                                                                        <option value="deny">Engelle</option>
                                                                    </select>
                                                                </div>
                                                            ))}
                                                        </div>
                                                    </div>
                                                ))}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>

                    <button
                        className="rounded-xl bg-slate-950 px-4 py-3 font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                        disabled={isSaving}
                    >
                        {isSaving ? 'Kaydediliyor...' : 'Kullanıcıyı kaydet'}
                    </button>
                </form>
            </section>

            {cloneSource && (
                <div className="fixed inset-0 z-50 grid place-items-center bg-slate-950/50 px-4 py-6">
                    <form onSubmit={cloneUser} className="w-full max-w-xl rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                    Kullanıcı kopyala
                                </p>
                                <h3 className="mt-1 text-xl font-semibold text-slate-950">
                                    {cloneSource.full_name || cloneSource.username}
                                </h3>
                                <p className="mt-2 text-sm text-slate-500">
                                    Rol ve izinler kaynak kullanıcıdan kopyalanır. Temsilci kodu yeni kullanıcı için ayrı girilir.
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={closeClone}
                                className="rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-600 transition hover:border-slate-400"
                            >
                                Kapat
                            </button>
                        </div>

                        <div className="mt-5 grid gap-4">
                            <label className="grid gap-2 text-sm font-semibold text-slate-700">
                                Yeni kullanıcı adı
                                <input
                                    required
                                    className="rounded-xl border border-slate-200 px-4 py-3 font-normal outline-none transition focus:border-slate-400"
                                    placeholder="yeni.kullanici"
                                    value={cloneForm.username}
                                    onChange={(event) => setCloneForm({ ...cloneForm, username: event.target.value })}
                                />
                            </label>

                            <label className="grid gap-2 text-sm font-semibold text-slate-700">
                                Yeni tam ad
                                <input
                                    required
                                    className="rounded-xl border border-slate-200 px-4 py-3 font-normal outline-none transition focus:border-slate-400"
                                    placeholder="Ad Soyad"
                                    value={cloneForm.full_name}
                                    onChange={(event) => setCloneForm({ ...cloneForm, full_name: event.target.value })}
                                />
                            </label>

                            <label className="grid gap-2 text-sm font-semibold text-slate-700">
                                Yeni parola
                                <input
                                    required
                                    type="password"
                                    className="rounded-xl border border-slate-200 px-4 py-3 font-normal outline-none transition focus:border-slate-400"
                                    placeholder="İlk parola"
                                    value={cloneForm.password}
                                    onChange={(event) => setCloneForm({ ...cloneForm, password: event.target.value })}
                                />
                            </label>

                            <label className="grid gap-2 text-sm font-semibold text-slate-700">
                                Temsilci kodu
                                <input
                                    className="rounded-xl border border-slate-200 px-4 py-3 font-normal outline-none transition focus:border-slate-400"
                                    placeholder="0035"
                                    value={cloneForm.temsilci_kodu ?? ''}
                                    onChange={(event) => setCloneForm({ ...cloneForm, temsilci_kodu: event.target.value })}
                                />
                            </label>

                            <div className="grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
                                <label className="flex items-center justify-between gap-3 text-sm font-semibold text-slate-700">
                                    Aktif
                                    <input
                                        type="checkbox"
                                        checked={cloneForm.aktif}
                                        onChange={(event) => setCloneForm({ ...cloneForm, aktif: event.target.checked })}
                                    />
                                </label>
                                <label className="flex items-center justify-between gap-3 text-sm font-semibold text-slate-700">
                                    İlk girişte şifre değiştir
                                    <input
                                        type="checkbox"
                                        checked={cloneForm.force_password_change}
                                        onChange={(event) => setCloneForm({ ...cloneForm, force_password_change: event.target.checked })}
                                    />
                                </label>
                                <label className="flex items-center justify-between gap-3 text-sm font-semibold text-slate-700">
                                    <span>
                                        Dar yetkiyi sabitle
                                        <small className="block font-normal text-slate-500">
                                            Kaynak kullanıcının etkin izinleri yeni kullanıcıya explicit allow/deny olarak yazılır; rol fallback fazladan alan açamaz.
                                        </small>
                                    </span>
                                    <input
                                        type="checkbox"
                                        checked={cloneForm.strict_access}
                                        onChange={(event) => setCloneForm({ ...cloneForm, strict_access: event.target.checked })}
                                    />
                                </label>
                            </div>
                        </div>

                        <div className="mt-6 flex justify-end gap-3">
                            <button
                                type="button"
                                onClick={closeClone}
                                className="rounded-xl border border-slate-200 px-4 py-3 font-semibold text-slate-600 transition hover:border-slate-400"
                            >
                                Vazgeç
                            </button>
                            <button
                                className="rounded-xl bg-slate-950 px-4 py-3 font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                                disabled={isCloning}
                            >
                                {isCloning ? 'Kopyalanıyor...' : 'Kullanıcıyı kopyala'}
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </AdminFrame>
    );
}
