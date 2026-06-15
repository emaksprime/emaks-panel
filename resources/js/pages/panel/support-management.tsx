import { Head } from '@inertiajs/react';
import {
    CheckCircle2,
    FileUp,
    Keyboard,
    Pencil,
    Plus,
    RefreshCw,
    Search,
    Upload,
    XCircle,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { apiRequest } from '@/lib/api';

type ManagementTab = 'activation' | 'import' | 'guides';

type ActivationCode = {
    id: number;
    stock_code: string | null;
    stock_name: string | null;
    serial_number: string | null;
    serial_number_clean: string | null;
    search_code: string | null;
    activation_code: string | null;
    source: string | null;
    imported_at: string | null;
    is_active: boolean;
};

type ActivationForm = {
    stock_code: string;
    stock_name: string;
    serial_number: string;
    serial_number_clean: string;
    search_code: string;
    activation_code: string;
};

type ImportRow = {
    row: number;
    action: 'create' | 'update';
    stok_kodu: string | null;
    stok_adi: string | null;
    seri_no: string | null;
    seri_no_temiz: string | null;
    aktivasyon_kodu: string | null;
    arama_kodu: string | null;
};

type ImportIssue = {
    row: number | null;
    reason: string;
    data?: unknown;
    seri_no_temiz?: string;
};

type ImportPreview = {
    source: 'csv' | 'paste';
    filename: string | null;
    total_rows: number;
    new_count: number;
    created_count: number;
    updated_count: number;
    skipped_count: number;
    failed_count: number;
    rows: ImportRow[];
    errors: ImportIssue[];
    skipped_rows: ImportIssue[];
};

type ImportCommitResult = {
    batch?: {
        id: number;
        filename: string | null;
        source: string;
        created_at: string | null;
    };
    created_count: number;
    updated_count: number;
    skipped_count: number;
    failed_count: number;
    errors: ImportIssue[];
};

type GuideEntry = {
    id: number;
    title: string;
    stok_kodu: string | null;
    product_keyword: string | null;
    method: string | null;
    guide_content: string | null;
    is_active: boolean;
    sort_order: number;
    source_sheet: string | null;
};

type GuideForm = {
    title: string;
    stok_kodu: string;
    product_keyword: string;
    method: string;
    guide_content: string;
    is_active: boolean;
    sort_order: string;
};

const emptyActivationForm: ActivationForm = {
    stock_code: '',
    stock_name: '',
    serial_number: '',
    serial_number_clean: '',
    search_code: '',
    activation_code: '',
};

const emptyGuideForm: GuideForm = {
    title: '',
    stok_kodu: '',
    product_keyword: '',
    method: '',
    guide_content: '',
    is_active: true,
    sort_order: '100',
};

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
const nullableText = (value: string) => value.trim() || null;
const inputClassName = 'h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium text-slate-800 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-blue-400 focus:ring-4 focus:ring-blue-100';
const textareaClassName = 'min-h-32 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium leading-6 text-slate-800 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-blue-400 focus:ring-4 focus:ring-blue-100';

function statusText(action: ImportRow['action']) {
    return action === 'create' ? 'Yeni kayıt' : 'Güncellenecek';
}

function dateText(value: string | null) {
    if (!value) {
        return '-';
    }

    return new Intl.DateTimeFormat('tr-TR', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(value));
}

function StatPill({ label, value, tone = 'slate' }: { label: string; value: number | string; tone?: 'slate' | 'emerald' | 'blue' | 'amber' | 'rose' }) {
    const toneClass = {
        slate: 'border-slate-200 bg-slate-50 text-slate-700',
        emerald: 'border-emerald-200 bg-emerald-50 text-emerald-700',
        blue: 'border-blue-200 bg-blue-50 text-blue-700',
        amber: 'border-amber-200 bg-amber-50 text-amber-800',
        rose: 'border-rose-200 bg-rose-50 text-rose-700',
    }[tone];

    return (
        <span className={`inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold ${toneClass}`}>
            <span>{label}</span>
            <strong>{value}</strong>
        </span>
    );
}

function TabButton({
    active,
    children,
    onClick,
}: {
    active: boolean;
    children: React.ReactNode;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={[
                'inline-flex h-10 items-center justify-center rounded-full border px-4 text-sm font-semibold transition',
                active
                    ? 'border-slate-950 bg-slate-950 text-white'
                    : 'border-slate-200 bg-white text-slate-600 hover:border-blue-200 hover:text-blue-700',
            ].join(' ')}
        >
            {children}
        </button>
    );
}

export default function SupportManagement() {
    const [tab, setTab] = useState<ManagementTab>('activation');
    const [activationSearch, setActivationSearch] = useState('');
    const [activationItems, setActivationItems] = useState<ActivationCode[]>([]);
    const [activationTotal, setActivationTotal] = useState(0);
    const [lastImport, setLastImport] = useState<{ id: number; created_at: string | null; created_count: number; updated_count: number } | null>(null);
    const [loadingActivations, setLoadingActivations] = useState(false);
    const [activationError, setActivationError] = useState<string | null>(null);
    const [activationForm, setActivationForm] = useState<ActivationForm>(emptyActivationForm);
    const [editingActivation, setEditingActivation] = useState<ActivationCode | null>(null);
    const [savingActivation, setSavingActivation] = useState(false);
    const [file, setFile] = useState<File | null>(null);
    const [pasteText, setPasteText] = useState('');
    const [preview, setPreview] = useState<ImportPreview | null>(null);
    const [commitResult, setCommitResult] = useState<ImportCommitResult | null>(null);
    const [importing, setImporting] = useState(false);
    const [importError, setImportError] = useState<string | null>(null);
    const [guideSearch, setGuideSearch] = useState('');
    const [guides, setGuides] = useState<GuideEntry[]>([]);
    const [guideForm, setGuideForm] = useState<GuideForm>(emptyGuideForm);
    const [editingGuide, setEditingGuide] = useState<GuideEntry | null>(null);
    const [loadingGuides, setLoadingGuides] = useState(false);
    const [guideError, setGuideError] = useState<string | null>(null);
    const [savingGuide, setSavingGuide] = useState(false);

    const loadActivations = useCallback(async () => {
        setLoadingActivations(true);
        setActivationError(null);

        try {
            const query = new URLSearchParams();

            if (activationSearch.trim()) {
                query.set('search', activationSearch.trim());
            }

            const response = await apiRequest(`/api/support/management/activation-codes${query.toString() ? `?${query.toString()}` : ''}`);

            setActivationItems(Array.isArray(response.items) ? response.items : []);
            setActivationTotal(Number(response.total ?? 0));
            setLastImport(response.last_import ?? null);
        } catch (caught) {
            setActivationError(caught instanceof Error ? caught.message : 'Aktivasyon kayıtları alınamadı.');
        } finally {
            setLoadingActivations(false);
        }
    }, [activationSearch]);

    const loadGuides = useCallback(async () => {
        setLoadingGuides(true);
        setGuideError(null);

        try {
            const query = new URLSearchParams();

            if (guideSearch.trim()) {
                query.set('search', guideSearch.trim());
            }

            const response = await apiRequest(`/api/support/management/guides${query.toString() ? `?${query.toString()}` : ''}`);

            setGuides(Array.isArray(response.items) ? response.items : []);
        } catch (caught) {
            setGuideError(caught instanceof Error ? caught.message : 'Rehber kayıtları alınamadı.');
        } finally {
            setLoadingGuides(false);
        }
    }, [guideSearch]);

    useEffect(() => {
        void Promise.resolve().then(loadActivations);
    }, [loadActivations]);

    useEffect(() => {
        void Promise.resolve().then(loadGuides);
    }, [loadGuides]);

    const visiblePreviewRows = useMemo(() => preview?.rows.slice(0, 30) ?? [], [preview]);
    const previewCanCommit = Boolean(preview && preview.rows.length > 0 && !importing);

    const editActivation = (item: ActivationCode) => {
        setEditingActivation(item);
        setActivationForm({
            stock_code: item.stock_code ?? '',
            stock_name: item.stock_name ?? '',
            serial_number: item.serial_number ?? '',
            serial_number_clean: item.serial_number_clean ?? '',
            search_code: item.search_code ?? '',
            activation_code: item.activation_code ?? '',
        });
        setTab('activation');
    };

    const resetActivationForm = () => {
        setEditingActivation(null);
        setActivationForm(emptyActivationForm);
    };

    const saveActivation = async () => {
        if (!activationForm.serial_number.trim()) {
            setActivationError('Seri No zorunlu.');

            return;
        }

        setSavingActivation(true);
        setActivationError(null);

        try {
            const payload = {
                stock_code: nullableText(activationForm.stock_code),
                stock_name: nullableText(activationForm.stock_name),
                serial_number: activationForm.serial_number.trim(),
                serial_number_clean: nullableText(activationForm.serial_number_clean),
                search_code: nullableText(activationForm.search_code),
                activation_code: nullableText(activationForm.activation_code),
            };

            await apiRequest(
                editingActivation
                    ? `/api/support/management/activation-codes/${editingActivation.id}`
                    : '/api/support/management/activation-codes',
                {
                    method: editingActivation ? 'PATCH' : 'POST',
                    body: JSON.stringify(payload),
                },
            );
            resetActivationForm();
            await loadActivations();
        } catch (caught) {
            setActivationError(caught instanceof Error ? caught.message : 'Aktivasyon kaydı saklanamadı.');
        } finally {
            setSavingActivation(false);
        }
    };

    const previewImport = async (mode: 'csv' | 'paste') => {
        setImporting(true);
        setImportError(null);
        setPreview(null);
        setCommitResult(null);

        try {
            if (mode === 'csv') {
                if (!file) {
                    setImportError('CSV dosyası seçin.');

                    return;
                }

                const formData = new FormData();
                formData.append('file', file);
                formData.append('source', 'csv');

                const response = await fetch('/api/support/management/imports/preview', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        ...(csrfToken() ? { 'X-CSRF-TOKEN': csrfToken() } : {}),
                    },
                    body: formData,
                });
                const result = await response.json();

                if (!response.ok) {
                    setImportError(result.message ?? 'CSV preview alınamadı.');

                    return;
                }

                setPreview(result as ImportPreview);

                return;
            }

            if (!pasteText.trim()) {
                setImportError('Yapıştırılmış CSV verisi boş.');

                return;
            }

            const result = await apiRequest('/api/support/management/imports/preview', {
                method: 'POST',
                body: JSON.stringify({
                    paste_text: pasteText,
                    source: 'paste',
                }),
            });

            setPreview(result as ImportPreview);
        } catch (caught) {
            setImportError(caught instanceof Error ? caught.message : 'Import preview alınamadı.');
        } finally {
            setImporting(false);
        }
    };

    const commitImport = async () => {
        if (!preview) {
            return;
        }

        setImporting(true);
        setImportError(null);

        try {
            const result = await apiRequest('/api/support/management/imports/commit', {
                method: 'POST',
                body: JSON.stringify({
                    rows: preview.rows,
                    source: preview.source,
                    filename: preview.filename,
                }),
            });

            setCommitResult(result as ImportCommitResult);
            await loadActivations();
        } catch (caught) {
            setImportError(caught instanceof Error ? caught.message : 'Import commit yapılamadı.');
        } finally {
            setImporting(false);
        }
    };

    const editGuide = (entry: GuideEntry) => {
        setEditingGuide(entry);
        setGuideForm({
            title: entry.title ?? '',
            stok_kodu: entry.stok_kodu ?? '',
            product_keyword: entry.product_keyword ?? '',
            method: entry.method ?? '',
            guide_content: entry.guide_content ?? '',
            is_active: entry.is_active,
            sort_order: String(entry.sort_order ?? 100),
        });
        setTab('guides');
    };

    const resetGuideForm = () => {
        setEditingGuide(null);
        setGuideForm(emptyGuideForm);
    };

    const saveGuide = async () => {
        if (!guideForm.title.trim() || !guideForm.guide_content.trim()) {
            setGuideError('Başlık ve rehber içeriği zorunlu.');

            return;
        }

        setSavingGuide(true);
        setGuideError(null);

        try {
            const payload = {
                title: guideForm.title.trim(),
                stok_kodu: nullableText(guideForm.stok_kodu),
                product_keyword: nullableText(guideForm.product_keyword),
                method: nullableText(guideForm.method),
                guide_content: guideForm.guide_content.trim(),
                is_active: guideForm.is_active,
                sort_order: Number.parseInt(guideForm.sort_order, 10) || 100,
            };

            await apiRequest(
                editingGuide
                    ? `/api/support/management/guides/${editingGuide.id}`
                    : '/api/support/management/guides',
                {
                    method: editingGuide ? 'PATCH' : 'POST',
                    body: JSON.stringify(payload),
                },
            );
            resetGuideForm();
            await loadGuides();
        } catch (caught) {
            setGuideError(caught instanceof Error ? caught.message : 'Rehber kaydı saklanamadı.');
        } finally {
            setSavingGuide(false);
        }
    };

    return (
        <>
            <Head title="Destek Yönetimi" />

            <main className="grid min-w-0 gap-4 bg-[#f3f7fb] p-4 md:p-6">
                <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div className="border-b border-slate-100 bg-white p-4 md:p-5">
                        <span className="inline-flex items-center gap-2 rounded-full border border-blue-100 bg-blue-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.14em] text-blue-700">
                            <Keyboard className="size-3.5" />
                            Destek
                        </span>
                        <h1 className="mt-3 text-2xl font-semibold text-slate-950 [font-family:var(--font-display)] md:text-3xl">
                            Destek Yönetimi
                        </h1>
                        <p className="mt-1 max-w-3xl text-sm leading-6 text-slate-600">
                            Aktivasyon kodlarını ve tuşlama rehberi içeriklerini yönetin.
                        </p>
                    </div>
                    <nav className="flex flex-wrap gap-2 bg-slate-50 p-3">
                        <TabButton active={tab === 'activation'} onClick={() => setTab('activation')}>
                            Aktivasyon Kodları
                        </TabButton>
                        <TabButton active={tab === 'import'} onClick={() => setTab('import')}>
                            İçe Aktar
                        </TabButton>
                        <TabButton active={tab === 'guides'} onClick={() => setTab('guides')}>
                            Tuşlama Rehberi
                        </TabButton>
                    </nav>
                </section>

                {tab === 'activation' ? (
                    <section className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_24rem]">
                        <div className="grid min-w-0 gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                            <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                                <label className="grid min-w-0 flex-1 gap-1 text-sm font-semibold text-slate-700">
                                    <span>Aktivasyon kaydı ara</span>
                                    <span className="relative min-w-0">
                                        <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                                        <input
                                            value={activationSearch}
                                            onChange={(event) => setActivationSearch(event.target.value)}
                                            onKeyDown={(event) => {
                                                if (event.key === 'Enter') {
                                                    void loadActivations();
                                                }
                                            }}
                                            placeholder="Seri no, temiz seri, aktivasyon kodu, arama kodu"
                                            className={`${inputClassName} pl-9`}
                                        />
                                    </span>
                                </label>
                                <button
                                    type="button"
                                    onClick={() => void loadActivations()}
                                    className="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:border-blue-200 hover:text-blue-700"
                                >
                                    <RefreshCw className="size-4" />
                                    Yenile
                                </button>
                            </div>

                            <div className="flex flex-wrap gap-2">
                                <StatPill label="Toplam" value={activationTotal} />
                                <StatPill label="Listelenen" value={activationItems.length} tone="blue" />
                                {lastImport ? (
                                    <StatPill label={`Son import #${lastImport.id}`} value={dateText(lastImport.created_at)} tone="emerald" />
                                ) : null}
                            </div>

                            {activationError ? (
                                <div className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                                    {activationError}
                                </div>
                            ) : null}

                            <div className="overflow-x-auto rounded-2xl border border-slate-200">
                                <table className="w-full min-w-[980px] divide-y divide-slate-200 text-sm">
                                    <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                        <tr>
                                            <th className="px-4 py-3">Ürün</th>
                                            <th className="px-4 py-3">Seri</th>
                                            <th className="px-4 py-3">Kodlar</th>
                                            <th className="px-4 py-3">Kaynak</th>
                                            <th className="px-4 py-3 text-right">İşlem</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100 bg-white">
                                        {activationItems.map((item) => (
                                            <tr key={item.id}>
                                                <td className="px-4 py-3 align-top">
                                                    <p className="font-semibold text-slate-950">{item.stock_name || 'Stok adı yok'}</p>
                                                    <p className="mt-1 text-xs text-slate-500">{item.stock_code || '-'}</p>
                                                </td>
                                                <td className="px-4 py-3 align-top">
                                                    <p className="font-medium text-slate-900">{item.serial_number || '-'}</p>
                                                    <p className="mt-1 text-xs text-slate-500">{item.serial_number_clean || '-'}</p>
                                                </td>
                                                <td className="px-4 py-3 align-top">
                                                    <p className="font-semibold text-slate-900">{item.activation_code || '-'}</p>
                                                    <p className="mt-1 text-xs text-slate-500">Arama: {item.search_code || '-'}</p>
                                                </td>
                                                <td className="px-4 py-3 align-top text-slate-600">
                                                    <p>{item.source || '-'}</p>
                                                    <p className="mt-1 text-xs">{dateText(item.imported_at)}</p>
                                                </td>
                                                <td className="px-4 py-3 align-top">
                                                    <div className="flex justify-end">
                                                        <button
                                                            type="button"
                                                            onClick={() => editActivation(item)}
                                                            className="inline-flex h-9 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:border-blue-200 hover:text-blue-700"
                                                        >
                                                            <Pencil className="size-4" />
                                                            Düzenle
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                        {!loadingActivations && activationItems.length === 0 ? (
                                            <tr>
                                                <td className="px-4 py-8 text-center text-slate-500" colSpan={5}>
                                                    Kayıt bulunamadı.
                                                </td>
                                            </tr>
                                        ) : null}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <aside className="grid h-fit gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm lg:sticky lg:top-24">
                            <div className="flex items-center justify-between gap-3">
                                <div>
                                    <h2 className="text-base font-semibold text-slate-950">
                                        {editingActivation ? 'Aktivasyon düzenle' : 'Manuel aktivasyon ekle'}
                                    </h2>
                                    <p className="mt-1 text-sm text-slate-500">
                                        Temiz seri boşsa seri no içindeki tireden ayrıştırılır.
                                    </p>
                                </div>
                                {editingActivation ? (
                                    <button
                                        type="button"
                                        onClick={resetActivationForm}
                                        className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition hover:border-blue-200 hover:text-blue-700"
                                    >
                                        Yeni
                                    </button>
                                ) : null}
                            </div>
                            <div className="grid gap-3">
                                {([
                                    ['stock_code', 'Stok Kodu'],
                                    ['stock_name', 'Stok Adı'],
                                    ['serial_number', 'Seri No'],
                                    ['serial_number_clean', 'Seri No Temiz'],
                                    ['activation_code', 'Aktivasyon Kodu'],
                                    ['search_code', 'Arama Kodu'],
                                ] as const).map(([field, label]) => (
                                    <label key={field} className="grid gap-1 text-sm font-semibold text-slate-700">
                                        <span>{label}</span>
                                        <input
                                            value={activationForm[field]}
                                            onChange={(event) => setActivationForm((current) => ({ ...current, [field]: event.target.value }))}
                                            className={inputClassName}
                                        />
                                    </label>
                                ))}
                            </div>
                            <button
                                type="button"
                                onClick={() => void saveActivation()}
                                disabled={savingActivation}
                                className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                <Plus className="size-4" />
                                {savingActivation ? 'Kaydediliyor...' : 'Kaydet'}
                            </button>
                        </aside>
                    </section>
                ) : null}

                {tab === 'import' ? (
                    <section className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_24rem]">
                        <div className="grid gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                            <div>
                                <h2 className="text-base font-semibold text-slate-950">CSV / paste import</h2>
                                <p className="mt-1 text-sm leading-6 text-slate-600">
                                    Beklenen kolonlar: STOK_KODU, STOK_ADI, SERI_NO, SERI_NO_TEMIZ, ARAMA_KODU.
                                </p>
                            </div>
                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <div className="flex items-center gap-2 text-sm font-semibold text-slate-900">
                                        <FileUp className="size-4 text-blue-700" />
                                        CSV dosyası
                                    </div>
                                    <input
                                        type="file"
                                        accept=".csv,.txt,text/csv,text/plain"
                                        onChange={(event) => setFile(event.target.files?.[0] ?? null)}
                                        className="block w-full text-sm text-slate-700 file:mr-3 file:rounded-xl file:border-0 file:bg-slate-950 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-white"
                                    />
                                    <button
                                        type="button"
                                        onClick={() => void previewImport('csv')}
                                        disabled={importing}
                                        className="inline-flex h-10 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:border-blue-200 hover:text-blue-700 disabled:opacity-60"
                                    >
                                        <Search className="size-4" />
                                        Preview
                                    </button>
                                </div>
                                <div className="grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <div className="flex items-center gap-2 text-sm font-semibold text-slate-900">
                                        <Upload className="size-4 text-blue-700" />
                                        Paste import
                                    </div>
                                    <textarea
                                        value={pasteText}
                                        onChange={(event) => setPasteText(event.target.value)}
                                        placeholder="STOK_KODU,STOK_ADI,SERI_NO,SERI_NO_TEMIZ,ARAMA_KODU"
                                        className={textareaClassName}
                                    />
                                    <button
                                        type="button"
                                        onClick={() => void previewImport('paste')}
                                        disabled={importing}
                                        className="inline-flex h-10 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:border-blue-200 hover:text-blue-700 disabled:opacity-60"
                                    >
                                        <Search className="size-4" />
                                        Preview
                                    </button>
                                </div>
                            </div>

                            {importError ? (
                                <div className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                                    {importError}
                                </div>
                            ) : null}

                            {preview ? (
                                <div className="grid gap-4 rounded-2xl border border-slate-200 bg-white p-4">
                                    <div className="flex flex-wrap gap-2">
                                        <StatPill label="Toplam" value={preview.total_rows} />
                                        <StatPill label="Yeni" value={preview.created_count} tone="emerald" />
                                        <StatPill label="Güncelleme" value={preview.updated_count} tone="blue" />
                                        <StatPill label="Atlanan" value={preview.skipped_count} tone="amber" />
                                        <StatPill label="Hatalı" value={preview.failed_count} tone="rose" />
                                    </div>
                                    <div className="overflow-x-auto rounded-2xl border border-slate-200">
                                        <table className="w-full min-w-[860px] divide-y divide-slate-200 text-sm">
                                            <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                                <tr>
                                                    <th className="px-4 py-3">Satır</th>
                                                    <th className="px-4 py-3">Durum</th>
                                                    <th className="px-4 py-3">Stok</th>
                                                    <th className="px-4 py-3">Seri</th>
                                                    <th className="px-4 py-3">Aktivasyon</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-slate-100 bg-white">
                                                {visiblePreviewRows.map((row) => (
                                                    <tr key={`${row.row}-${row.seri_no_temiz}`}>
                                                        <td className="px-4 py-3">{row.row}</td>
                                                        <td className="px-4 py-3">
                                                            <span className={row.action === 'create' ? 'text-emerald-700' : 'text-blue-700'}>
                                                                {statusText(row.action)}
                                                            </span>
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            <p className="font-medium text-slate-900">{row.stok_adi || '-'}</p>
                                                            <p className="mt-1 text-xs text-slate-500">{row.stok_kodu || '-'}</p>
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            <p>{row.seri_no || '-'}</p>
                                                            <p className="mt-1 text-xs text-slate-500">{row.seri_no_temiz || '-'}</p>
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            <p className="font-semibold text-slate-900">{row.aktivasyon_kodu || '-'}</p>
                                                            <p className="mt-1 text-xs text-slate-500">Arama: {row.arama_kodu || '-'}</p>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                    {preview.errors.length > 0 ? (
                                        <div className="grid gap-2 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">
                                            <div className="flex items-center gap-2 font-semibold">
                                                <XCircle className="size-4" />
                                                İlk 20 hata
                                            </div>
                                            {preview.errors.map((error, index) => (
                                                <p key={`${error.row}-${index}`}>Satır {error.row}: {error.reason}</p>
                                            ))}
                                        </div>
                                    ) : null}
                                </div>
                            ) : null}
                        </div>

                        <aside className="grid h-fit gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm lg:sticky lg:top-24">
                            <h2 className="text-base font-semibold text-slate-950">Commit</h2>
                            <p className="text-sm leading-6 text-slate-600">
                                Preview onaylanmadan kayıt yazılmaz. Commit transaction içinde temiz seri bazlı insert/update yapar.
                            </p>
                            <button
                                type="button"
                                onClick={() => void commitImport()}
                                disabled={!previewCanCommit}
                                className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                <CheckCircle2 className="size-4" />
                                {importing ? 'İşleniyor...' : 'Import et'}
                            </button>
                            {commitResult ? (
                                <div className="grid gap-2 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">
                                    <p className="font-semibold">Import tamamlandı.</p>
                                    <p>Eklenen: {commitResult.created_count}</p>
                                    <p>Güncellenen: {commitResult.updated_count}</p>
                                    <p>Atlanan: {commitResult.skipped_count}</p>
                                    <p>Hatalı: {commitResult.failed_count}</p>
                                </div>
                            ) : null}
                        </aside>
                    </section>
                ) : null}

                {tab === 'guides' ? (
                    <section className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_24rem]">
                        <div className="grid gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                            <div className="grid gap-3 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
                                <label className="grid min-w-0 gap-1 text-sm font-semibold text-slate-700">
                                    <span>Rehber ara</span>
                                    <input
                                        value={guideSearch}
                                        onChange={(event) => setGuideSearch(event.target.value)}
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter') {
                                                void loadGuides();
                                            }
                                        }}
                                        placeholder="Başlık, stok kodu, ürün veya içerik"
                                        className={inputClassName}
                                    />
                                </label>
                                <button
                                    type="button"
                                    onClick={() => void loadGuides()}
                                    className="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:border-blue-200 hover:text-blue-700"
                                >
                                    <RefreshCw className="size-4" />
                                    Yenile
                                </button>
                            </div>
                            {guideError ? (
                                <div className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                                    {guideError}
                                </div>
                            ) : null}
                            <div className="grid gap-3">
                                {guides.map((guide) => (
                                    <article key={guide.id} className="grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                        <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                            <div className="min-w-0">
                                                <h2 className="text-base font-semibold text-slate-950">{guide.title}</h2>
                                                <p className="mt-1 text-sm text-slate-600">
                                                    {[guide.product_keyword, guide.stok_kodu, guide.method].filter(Boolean).join(' / ') || 'Eşleme yok'}
                                                </p>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <span className={guide.is_active ? 'text-sm font-semibold text-emerald-700' : 'text-sm font-semibold text-slate-500'}>
                                                    {guide.is_active ? 'Aktif' : 'Pasif'}
                                                </span>
                                                <button
                                                    type="button"
                                                    onClick={() => editGuide(guide)}
                                                    className="inline-flex h-9 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:border-blue-200 hover:text-blue-700"
                                                >
                                                    <Pencil className="size-4" />
                                                    Düzenle
                                                </button>
                                            </div>
                                        </div>
                                        <p className="line-clamp-3 whitespace-pre-line text-sm leading-6 text-slate-600">
                                            {guide.guide_content || 'İçerik yok'}
                                        </p>
                                    </article>
                                ))}
                                {!loadingGuides && guides.length === 0 ? (
                                    <div className="rounded-2xl border border-slate-200 bg-slate-50 p-8 text-center text-sm text-slate-500">
                                        Rehber kaydı bulunamadı.
                                    </div>
                                ) : null}
                            </div>
                        </div>

                        <aside className="grid h-fit gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm lg:sticky lg:top-24">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <h2 className="text-base font-semibold text-slate-950">
                                        {editingGuide ? 'Rehber düzenle' : 'Rehber ekle'}
                                    </h2>
                                    <p className="mt-1 text-sm text-slate-500">
                                        Ürün/stok keyword desteği arama sonucunu besler.
                                    </p>
                                </div>
                                {editingGuide ? (
                                    <button
                                        type="button"
                                        onClick={resetGuideForm}
                                        className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition hover:border-blue-200 hover:text-blue-700"
                                    >
                                        Yeni
                                    </button>
                                ) : null}
                            </div>
                            <div className="grid gap-3">
                                {([
                                    ['title', 'Başlık'],
                                    ['stok_kodu', 'Stok Kodu'],
                                    ['product_keyword', 'Ürün / model keyword'],
                                    ['method', 'Giriş yöntemi'],
                                    ['sort_order', 'Sıralama'],
                                ] as const).map(([field, label]) => (
                                    <label key={field} className="grid gap-1 text-sm font-semibold text-slate-700">
                                        <span>{label}</span>
                                        <input
                                            value={guideForm[field]}
                                            onChange={(event) => setGuideForm((current) => ({ ...current, [field]: event.target.value }))}
                                            className={inputClassName}
                                        />
                                    </label>
                                ))}
                                <label className="grid gap-1 text-sm font-semibold text-slate-700">
                                    <span>Rehber içeriği</span>
                                    <textarea
                                        value={guideForm.guide_content}
                                        onChange={(event) => setGuideForm((current) => ({ ...current, guide_content: event.target.value }))}
                                        className={textareaClassName}
                                    />
                                </label>
                                <label className="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                                    <input
                                        type="checkbox"
                                        checked={guideForm.is_active}
                                        onChange={(event) => setGuideForm((current) => ({ ...current, is_active: event.target.checked }))}
                                        className="size-4 rounded border-slate-300 text-blue-700"
                                    />
                                    Aktif
                                </label>
                            </div>
                            <button
                                type="button"
                                onClick={() => void saveGuide()}
                                disabled={savingGuide}
                                className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                <Plus className="size-4" />
                                {savingGuide ? 'Kaydediliyor...' : 'Kaydet'}
                            </button>
                        </aside>
                    </section>
                ) : null}
            </main>
        </>
    );
}
