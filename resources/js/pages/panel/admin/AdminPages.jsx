import { useEffect, useState } from 'react';
import { apiRequest } from '@/lib/api';
import { AdminFrame } from './AdminFrame.jsx';

const blankPage = {
    id: null,
    code: '',
    name: '',
    route: '',
    icon: '',
    resource_code: '',
    component: 'panel/page',
    layout_type: 'module',
    description: '',
    page_order: 0,
    active: true,
    menu_group_id: '',
    menu_label: '',
    menu_visible: true,
    menu_sort_order: 0,
};

const blankButton = {
    id: null,
    page_id: '',
    code: '',
    label: '',
    resource_code: '',
    variant: 'secondary',
    action_type: 'navigate',
    action_target: '',
    position: 'page_top',
    config_json: {},
    confirmation_required: false,
    confirmation_text: '',
    sort_order: 0,
    is_visible: true,
};

function stringifyJson(value) {
    return JSON.stringify(value ?? {}, null, 2);
}

function parseJson(text, fallback = {}) {
    if (!text.trim()) {
        return fallback;
    }

    return JSON.parse(text);
}

export default function AdminPages() {
    const [data, setData] = useState({ pages: [], menuGroups: [], buttons: [] });
    const [pageForm, setPageForm] = useState(blankPage);
    const [buttonForm, setButtonForm] = useState(blankButton);
    const [buttonConfigText, setButtonConfigText] = useState('{}');
    const [status, setStatus] = useState('');

    const load = () => apiRequest('/api/admin/pages').then(setData);

    useEffect(() => {
        load();
    }, []);

    const savePage = async (event) => {
        event.preventDefault();
        const next = await apiRequest('/api/admin/pages', {
            method: 'POST',
            body: JSON.stringify({
                ...pageForm,
                menu_group_id: pageForm.menu_group_id || null,
                menu_sort_order: Number(pageForm.menu_sort_order ?? pageForm.page_order ?? 0),
                page_order: Number(pageForm.page_order ?? 0),
            }),
        });
        setData(next);
        setPageForm(blankPage);
        setStatus('Sayfa metadata kaydedildi.');
    };

    const saveButton = async (event) => {
        event.preventDefault();
        const next = await apiRequest('/api/admin/buttons', {
            method: 'POST',
            body: JSON.stringify({
                ...buttonForm,
                page_id: Number(buttonForm.page_id),
                sort_order: Number(buttonForm.sort_order ?? 0),
                config_json: parseJson(buttonConfigText, {}),
            }),
        });
        setData(next);
        setButtonForm(blankButton);
        setButtonConfigText('{}');
        setStatus('Buton metadata kaydedildi.');
    };

    const editPage = (page) => {
        setPageForm({
            ...blankPage,
            ...page,
            menu_group_id: page.menu_group_id ?? '',
            menu_label: page.menu_label ?? '',
        });
    };

    const editButton = (button) => {
        setButtonForm({
            ...blankButton,
            ...button,
            resource_code: button.resource_code ?? '',
            action_target: button.action_target ?? '',
            confirmation_text: button.confirmation_text ?? '',
        });
        setButtonConfigText(stringifyJson(button.config_json));
    };

    return (
        <AdminFrame title="Sayfa ve Menü Yönetimi">
            {status && (
                <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">
                    {status}
                </div>
            )}

            <section className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_460px]">
                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    {data.pages.map((page) => (
                        <button
                            key={page.id}
                            type="button"
                            onClick={() => editPage(page)}
                            className="grid w-full grid-cols-[1fr_auto] gap-3 border-b border-slate-100 px-5 py-4 text-left transition hover:bg-slate-50"
                        >
                            <span>
                                <strong className="block text-slate-950">{page.name}</strong>
                                <span className="text-sm text-slate-500">{page.route} - {page.layout_type}</span>
                            </span>
                            <span className="text-sm font-semibold text-slate-500">{page.active ? 'Aktif' : 'Pasif'}</span>
                        </button>
                    ))}
                </div>

                <form onSubmit={savePage} className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 className="text-lg font-semibold text-slate-950">Sayfa Ayarları</h2>
                    {[
                        ['code', 'Kod'],
                        ['name', 'Sayfa Adı'],
                        ['route', 'Rota'],
                        ['icon', 'İkon'],
                        ['resource_code', 'Kaynak Kodu'],
                        ['component', 'React Bileşeni'],
                    ].map(([field, label]) => (
                        <label key={field} className="grid gap-1 text-sm font-semibold text-slate-700">
                            {label}
                            <input className="rounded-xl border border-slate-200 px-3 py-2 font-normal" value={pageForm[field] ?? ''} onChange={(event) => setPageForm({ ...pageForm, [field]: event.target.value })} />
                        </label>
                    ))}
                    <label className="grid gap-1 text-sm font-semibold text-slate-700">
                        Layout tipi
                        <select className="rounded-xl border border-slate-200 px-3 py-2 font-normal" value={pageForm.layout_type} onChange={(event) => setPageForm({ ...pageForm, layout_type: event.target.value })}>
                            <option value="admin">admin</option>
                            <option value="module">module</option>
                        </select>
                    </label>
                    <label className="grid gap-1 text-sm font-semibold text-slate-700">
                        Açıklama
                        <textarea className="min-h-24 rounded-xl border border-slate-200 px-3 py-2 font-normal" value={pageForm.description ?? ''} onChange={(event) => setPageForm({ ...pageForm, description: event.target.value })} />
                    </label>
                    <label className="grid gap-1 text-sm font-semibold text-slate-700">
                        Sıra
                        <input type="number" className="rounded-xl border border-slate-200 px-3 py-2 font-normal" value={pageForm.page_order ?? 0} onChange={(event) => setPageForm({ ...pageForm, page_order: Number(event.target.value) })} />
                    </label>
                    <label className="grid gap-1 text-sm font-semibold text-slate-700">
                        Menü grubu
                        <select className="rounded-xl border border-slate-200 px-3 py-2 font-normal" value={pageForm.menu_group_id ?? ''} onChange={(event) => setPageForm({ ...pageForm, menu_group_id: event.target.value })}>
                            <option value="">Menü grubu seç</option>
                            {data.menuGroups.map((group) => (
                                <option key={group.id} value={group.id}>{group.name}</option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-1 text-sm font-semibold text-slate-700">
                        Menü etiketi
                        <input className="rounded-xl border border-slate-200 px-3 py-2 font-normal" value={pageForm.menu_label ?? ''} onChange={(event) => setPageForm({ ...pageForm, menu_label: event.target.value })} />
                    </label>
                    <div className="grid gap-2 rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <label className="flex items-center gap-2 text-sm font-semibold text-slate-700">
                            <input type="checkbox" checked={pageForm.active} onChange={(event) => setPageForm({ ...pageForm, active: event.target.checked })} />
                            Sayfa aktif
                        </label>
                        <label className="flex items-center gap-2 text-sm font-semibold text-slate-700">
                            <input type="checkbox" checked={pageForm.menu_visible} onChange={(event) => setPageForm({ ...pageForm, menu_visible: event.target.checked })} />
                            Menüde görünür
                        </label>
                    </div>
                    <button className="rounded-xl bg-slate-950 px-3 py-3 font-semibold text-white">Sayfayı Kaydet</button>
                </form>
            </section>

            <section className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_460px]">
                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div className="border-b border-slate-100 px-5 py-4">
                        <h2 className="text-lg font-semibold text-slate-950">Sayfa Butonları</h2>
                        <p className="mt-1 text-sm text-slate-500">Buton metadata kaydı; aksiyonların çalıştırılması sonraki aşamada bağlanacak.</p>
                    </div>
                    {data.buttons.map((button) => (
                        <button
                            key={button.id}
                            type="button"
                            onClick={() => editButton(button)}
                            className="grid w-full grid-cols-[1fr_auto] gap-3 border-b border-slate-100 px-5 py-4 text-left transition hover:bg-slate-50"
                        >
                            <span>
                                <strong className="block text-slate-950">{button.label}</strong>
                                <span className="text-sm text-slate-500">{button.page_code} - {button.position} - {button.action_type}</span>
                            </span>
                            <span className="text-sm font-semibold text-slate-500">{button.is_visible ? 'Görünür' : 'Gizli'}</span>
                        </button>
                    ))}
                </div>

                <form onSubmit={saveButton} className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 className="text-lg font-semibold text-slate-950">Buton Ayarları</h2>
                    <label className="grid gap-1 text-sm font-semibold text-slate-700">
                        Sayfa
                        <select required className="rounded-xl border border-slate-200 px-3 py-2 font-normal" value={buttonForm.page_id} onChange={(event) => setButtonForm({ ...buttonForm, page_id: event.target.value })}>
                            <option value="">Sayfa seç</option>
                            {data.pages.map((page) => (
                                <option key={page.id} value={page.id}>{page.name}</option>
                            ))}
                        </select>
                    </label>
                    {[
                        ['code', 'Kod'],
                        ['label', 'Etiket'],
                        ['resource_code', 'Kaynak Kodu'],
                        ['action_target', 'Eylem Hedefi'],
                    ].map(([field, label]) => (
                        <label key={field} className="grid gap-1 text-sm font-semibold text-slate-700">
                            {label}
                            <input className="rounded-xl border border-slate-200 px-3 py-2 font-normal" value={buttonForm[field] ?? ''} onChange={(event) => setButtonForm({ ...buttonForm, [field]: event.target.value })} />
                        </label>
                    ))}
                    <div className="grid gap-3 sm:grid-cols-2">
                        <label className="grid gap-1 text-sm font-semibold text-slate-700">
                            Variant
                            <select className="rounded-xl border border-slate-200 px-3 py-2 font-normal" value={buttonForm.variant} onChange={(event) => setButtonForm({ ...buttonForm, variant: event.target.value })}>
                                {['primary', 'secondary', 'danger', 'ghost'].map((item) => <option key={item} value={item}>{item}</option>)}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm font-semibold text-slate-700">
                            Eylem Türü
                            <select className="rounded-xl border border-slate-200 px-3 py-2 font-normal" value={buttonForm.action_type} onChange={(event) => setButtonForm({ ...buttonForm, action_type: event.target.value })}>
                                {['navigate', 'webhook', 'modal', 'refresh', 'custom'].map((item) => <option key={item} value={item}>{item}</option>)}
                            </select>
                        </label>
                    </div>
                    <label className="grid gap-1 text-sm font-semibold text-slate-700">
                        Konum
                        <select className="rounded-xl border border-slate-200 px-3 py-2 font-normal" value={buttonForm.position} onChange={(event) => setButtonForm({ ...buttonForm, position: event.target.value })}>
                            {['header_right', 'filter_bar', 'table_row', 'table_bulk', 'card_footer', 'page_top'].map((item) => <option key={item} value={item}>{item}</option>)}
                        </select>
                    </label>
                    <label className="grid gap-1 text-sm font-semibold text-slate-700">
                        Ayar JSON
                        <textarea className="min-h-28 rounded-xl border border-slate-200 px-3 py-2 font-mono text-xs" value={buttonConfigText} onChange={(event) => setButtonConfigText(event.target.value)} />
                    </label>
                    <label className="grid gap-1 text-sm font-semibold text-slate-700">
                        Onay metni
                        <input className="rounded-xl border border-slate-200 px-3 py-2 font-normal" value={buttonForm.confirmation_text ?? ''} onChange={(event) => setButtonForm({ ...buttonForm, confirmation_text: event.target.value })} />
                    </label>
                    <div className="grid gap-2 rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <label className="flex items-center gap-2 text-sm font-semibold text-slate-700">
                            <input type="checkbox" checked={buttonForm.is_visible} onChange={(event) => setButtonForm({ ...buttonForm, is_visible: event.target.checked })} />
                            Görünür
                        </label>
                        <label className="flex items-center gap-2 text-sm font-semibold text-slate-700">
                            <input type="checkbox" checked={buttonForm.confirmation_required} onChange={(event) => setButtonForm({ ...buttonForm, confirmation_required: event.target.checked })} />
                            Onay gerekli
                        </label>
                    </div>
                    <button className="rounded-xl bg-slate-950 px-3 py-3 font-semibold text-white">Butonu Kaydet</button>
                </form>
            </section>
        </AdminFrame>
    );
}
