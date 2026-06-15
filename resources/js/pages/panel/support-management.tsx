import { Head } from '@inertiajs/react';
import {
    CheckCircle2,
    Copy,
    FileUp,
    Keyboard,
    Pencil,
    Plus,
    RefreshCw,
    Search,
    Upload,
    X,
    XCircle,
} from 'lucide-react';
import type { ReactNode } from 'react';
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

type Pagination = {
    current_page: number;
    per_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
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
    source: 'csv' | 'xlsx' | 'paste';
    filename: string | null;
    total_rows: number;
    created_count: number;
    updated_count: number;
    skipped_count: number;
    failed_count: number;
    rows: ImportRow[];
    errors: ImportIssue[];
    skipped_rows: ImportIssue[];
};

type ImportCommitResult = {
    created_count: number;
    updated_count: number;
    skipped_count: number;
    failed_count: number;
    errors: ImportIssue[];
};

type GuideStep = {
    id: number | string;
    product_id: number | string;
    source?: 'managed' | 'legacy';
    section_type: string;
    custom_title: string | null;
    entry_method: string | null;
    entry_format: string | null;
    title: string;
    content: string;
    is_active: boolean;
    sort_order: number;
};

type GuideProduct = {
    id: number | string;
    source?: 'managed' | 'legacy';
    product_name: string;
    search_keywords: string | null;
    is_active: boolean;
    sort_order: number;
    steps: GuideStep[];
};

type GuideProductForm = {
    product_name: string;
    search_keywords: string;
    is_active: boolean;
};

type GuideStepForm = {
    section_type: string;
    custom_title: string;
    entry_method: string;
    entry_format: string;
    content: string;
    is_active: boolean;
};

const emptyActivationForm: ActivationForm = {
    stock_code: '',
    stock_name: '',
    serial_number: '',
    serial_number_clean: '',
    search_code: '',
    activation_code: '',
};

const emptyProductForm: GuideProductForm = {
    product_name: '',
    search_keywords: '',
    is_active: true,
};

const emptyStepForm: GuideStepForm = {
    section_type: 'pairing',
    custom_title: '',
    entry_method: 'Tuş Takımı',
    entry_format: 'Cihaz Eşleme',
    content: '',
    is_active: true,
};

const guideMethodOptions = ['Tuş Takımı', 'TUYA SMART', 'TTLOCK', 'GATEWAY', 'Uygulama ile Eşleme'];

const sectionTypeOptions = [
    ['pairing', 'Cihaz Eşleme'],
    ['fingerprint', 'Parmak İzi Ekleme'],
    ['pin', 'Pin Ekleme'],
    ['card', 'Kart Ekleme'],
    ['remote', 'Kumanda Ekleme'],
    ['reset', 'Resetleme'],
    ['other', 'Diğer'],
];
const sectionTypeLabel = (sectionType: string) => sectionTypeOptions.find(([value]) => value === sectionType)?.[1] ?? 'Diğer';

const inputClassName = 'h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium text-slate-800 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-blue-400 focus:ring-4 focus:ring-blue-100';
const textareaClassName = 'min-h-32 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium leading-6 text-slate-800 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-blue-400 focus:ring-4 focus:ring-blue-100';

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
const nullableText = (value: string) => value.trim() || null;
const dateText = (value: string | null) => value
    ? new Intl.DateTimeFormat('tr-TR', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value))
    : '-';
const cleanSerialText = (item: ActivationCode) => {
    if (item.serial_number_clean) {
        return item.serial_number_clean;
    }

    if (item.serial_number?.includes('-')) {
        return item.serial_number.slice(0, item.serial_number.lastIndexOf('-'));
    }

    return item.serial_number;
};
const activationCodeText = (item: ActivationCode) => {
    if (item.activation_code) {
        return item.activation_code;
    }

    if (item.serial_number?.includes('-')) {
        return item.serial_number.slice(item.serial_number.lastIndexOf('-') + 1);
    }

    return null;
};

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

function TabButton({ active, children, onClick }: { active: boolean; children: ReactNode; onClick: () => void }) {
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

function CopyButton({ value, label = 'Kopyala' }: { value: string | null | undefined; label?: string }) {
    const [copied, setCopied] = useState(false);

    if (!value) {
        return null;
    }

    const copy = async () => {
        await navigator.clipboard?.writeText(value);
        setCopied(true);
        window.setTimeout(() => setCopied(false), 1200);
    };

    return (
        <button
            type="button"
            onClick={() => void copy()}
            title={copied ? 'Kopyalandı' : label}
            aria-label={copied ? 'Kopyalandı' : label}
            className={[
                'inline-flex size-9 shrink-0 items-center justify-center rounded-lg border bg-white text-slate-600 transition',
                copied ? 'border-emerald-300 text-emerald-700 ring-2 ring-emerald-100' : 'border-slate-200 hover:border-blue-200 hover:text-blue-700',
            ].join(' ')}
        >
            {copied ? <CheckCircle2 className="size-4" /> : <Copy className="size-4" />}
            <span className="sr-only">{copied ? 'Kopyalandı' : label}</span>
        </button>
    );
}

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <label className="grid min-w-0 gap-1 text-sm font-semibold text-slate-700">
            <span>{label}</span>
            {children}
        </label>
    );
}

function ActivationValueTile({ label, value, className = '' }: { label: string; value: string | null | undefined; className?: string }) {
    return (
        <div className={`min-w-0 rounded-xl border border-slate-200 bg-white px-4 py-3.5 ${className}`}>
            <div className="flex min-w-0 items-start justify-between gap-4">
                <div className="min-w-0">
                    <p className="whitespace-nowrap text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">{label}</p>
                    <p className="mt-2 min-w-0 break-all font-mono text-[15px] font-semibold leading-6 text-slate-950">{value || '-'}</p>
                </div>
                <CopyButton value={value} />
            </div>
        </div>
    );
}

export default function SupportManagement() {
    const [tab, setTab] = useState<ManagementTab>('activation');
    const [activationSearch, setActivationSearch] = useState('');
    const [activationItems, setActivationItems] = useState<ActivationCode[]>([]);
    const [activationTotal, setActivationTotal] = useState(0);
    const [activationFilteredTotal, setActivationFilteredTotal] = useState(0);
    const [activationPage, setActivationPage] = useState(1);
    const [activationPerPage, setActivationPerPage] = useState(25);
    const [activationPagination, setActivationPagination] = useState<Pagination>({ current_page: 1, per_page: 25, last_page: 1, from: null, to: null });
    const [lastImport, setLastImport] = useState<{ id: number; created_at: string | null; created_count: number; updated_count: number } | null>(null);
    const [loadingActivations, setLoadingActivations] = useState(false);
    const [activationError, setActivationError] = useState<string | null>(null);
    const [activationForm, setActivationForm] = useState<ActivationForm>(emptyActivationForm);
    const [editingActivation, setEditingActivation] = useState<ActivationCode | null>(null);
    const [savingActivation, setSavingActivation] = useState(false);
    const [file, setFile] = useState<File | null>(null);
    const [pasteOpen, setPasteOpen] = useState(false);
    const [pasteText, setPasteText] = useState('');
    const [preview, setPreview] = useState<ImportPreview | null>(null);
    const [commitResult, setCommitResult] = useState<ImportCommitResult | null>(null);
    const [importing, setImporting] = useState(false);
    const [importError, setImportError] = useState<string | null>(null);
    const [guideSearch, setGuideSearch] = useState('');
    const [guideProducts, setGuideProducts] = useState<GuideProduct[]>([]);
    const [selectedProductId, setSelectedProductId] = useState<number | string | null>(null);
    const [productForm, setProductForm] = useState<GuideProductForm>(emptyProductForm);
    const [editingProductId, setEditingProductId] = useState<number | string | null>(null);
    const [stepForm, setStepForm] = useState<GuideStepForm>(emptyStepForm);
    const [editingStepId, setEditingStepId] = useState<number | string | null>(null);
    const [guideError, setGuideError] = useState<string | null>(null);
    const [guideNotice, setGuideNotice] = useState<string | null>(null);
    const [loadingGuides, setLoadingGuides] = useState(false);
    const [savingGuide, setSavingGuide] = useState(false);
    const [showInactiveSteps, setShowInactiveSteps] = useState(false);

    const selectedProduct = useMemo(
        () => guideProducts.find((product) => product.id === selectedProductId) ?? null,
        [guideProducts, selectedProductId],
    );
    const visiblePreviewRows = useMemo(() => preview?.rows.slice(0, 30) ?? [], [preview]);
    const previewCanCommit = Boolean(preview && preview.rows.length > 0 && !importing);
    const visibleSteps = useMemo(
        () => selectedProduct?.steps.filter((step) => showInactiveSteps || step.is_active) ?? [],
        [selectedProduct, showInactiveSteps],
    );

    const loadActivations = useCallback(async () => {
        setLoadingActivations(true);
        setActivationError(null);

        try {
            const query = new URLSearchParams({
                page: String(activationPage),
                per_page: String(activationPerPage),
            });

            if (activationSearch.trim()) {
                query.set('search', activationSearch.trim());
            }

            const response = await apiRequest(`/api/support/management/activation-codes?${query.toString()}`);
            setActivationItems(Array.isArray(response.items) ? response.items : []);
            setActivationTotal(Number(response.total ?? 0));
            setActivationFilteredTotal(Number(response.filtered_total ?? 0));
            setActivationPagination(response.pagination ?? { current_page: 1, per_page: activationPerPage, last_page: 1, from: null, to: null });
            setLastImport(response.last_import ?? null);
        } catch (caught) {
            setActivationError(caught instanceof Error ? caught.message : 'Aktivasyon kayıtları alınamadı.');
        } finally {
            setLoadingActivations(false);
        }
    }, [activationPage, activationPerPage, activationSearch]);

    const loadGuideProducts = useCallback(async () => {
        setLoadingGuides(true);
        setGuideError(null);

        try {
            const query = new URLSearchParams();

            if (guideSearch.trim()) {
                query.set('search', guideSearch.trim());
            }

            const response = await apiRequest(`/api/support/management/guides${query.toString() ? `?${query.toString()}` : ''}`);
            const items = Array.isArray(response.items) ? response.items : [];
            setGuideProducts(items);

            if (items.length > 0 && (!selectedProductId || !items.some((item: GuideProduct) => item.id === selectedProductId))) {
                setSelectedProductId(items[0].id);
            }
        } catch (caught) {
            setGuideError(caught instanceof Error ? caught.message : 'Rehber ürünleri alınamadı.');
        } finally {
            setLoadingGuides(false);
        }
    }, [guideSearch, selectedProductId]);

    useEffect(() => {
        void Promise.resolve().then(loadActivations);
    }, [loadActivations]);

    useEffect(() => {
        void Promise.resolve().then(loadGuideProducts);
    }, [loadGuideProducts]);

    const resetActivationForm = () => {
        setEditingActivation(null);
        setActivationForm(emptyActivationForm);
    };

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

    const previewImport = async (mode: 'file' | 'paste') => {
        setImporting(true);
        setImportError(null);
        setPreview(null);
        setCommitResult(null);

        try {
            if (mode === 'file') {
                if (!file) {
                    setImportError('CSV veya XLSX dosyası seçin.');

                    return;
                }

                const source = file.name.toLowerCase().endsWith('.xlsx') ? 'xlsx' : 'csv';
                const formData = new FormData();
                formData.append('file', file);
                formData.append('source', source);

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
                    setImportError(result.message ?? 'Dosya preview alınamadı.');

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
            setPasteOpen(false);
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

    const resetProductForm = () => {
        setEditingProductId(null);
        setProductForm(emptyProductForm);
    };

    const editProduct = (product: GuideProduct) => {
        setSelectedProductId(product.id);
        setEditingProductId(product.id);
        setProductForm({
            product_name: product.product_name,
            search_keywords: product.search_keywords ?? '',
            is_active: product.is_active,
        });
        setStepForm(emptyStepForm);
        setEditingStepId(null);
    };

    const saveProduct = async () => {
        if (!productForm.product_name.trim()) {
            setGuideError('Ürün adı / model adı zorunlu.');

            return;
        }

        setSavingGuide(true);
        setGuideError(null);
        setGuideNotice(null);

        try {
            const payload = {
                product_name: productForm.product_name.trim(),
                search_keywords: nullableText(productForm.search_keywords),
                is_active: productForm.is_active,
            };
            const result = await apiRequest(
                editingProductId ? `/api/support/management/guides/${editingProductId}` : '/api/support/management/guides',
                {
                    method: editingProductId ? 'PATCH' : 'POST',
                    body: JSON.stringify(payload),
                },
            );

            setSelectedProductId(result.item.id);
            resetProductForm();
            await loadGuideProducts();
            setGuideNotice('Ürün rehberi kaydedildi.');
        } catch (caught) {
            setGuideError(caught instanceof Error ? caught.message : 'Ürün rehberi saklanamadı.');
        } finally {
            setSavingGuide(false);
        }
    };

    const resetStepForm = () => {
        setEditingStepId(null);
        setStepForm(emptyStepForm);
    };

    const duplicateProduct = async () => {
        if (!selectedProduct) {
            setGuideError('Önce ürün seçin.');

            return;
        }

        setSavingGuide(true);
        setGuideError(null);
        setGuideNotice(null);

        try {
            const result = await apiRequest(`/api/support/management/guides/${selectedProduct.id}/duplicate`, {
                method: 'POST',
            });
            const item = result.item as GuideProduct;

            setGuideProducts((current) => [item, ...current.filter((product) => product.id !== item.id)]);
            editProduct(item);
            setShowInactiveSteps(true);
            await loadGuideProducts();
            setSelectedProductId(item.id);
            setEditingProductId(item.id);
            setProductForm({
                product_name: item.product_name,
                search_keywords: item.search_keywords ?? '',
                is_active: item.is_active,
            });
            setGuideNotice('Ürün kopyalandı.');
        } catch (caught) {
            setGuideError(caught instanceof Error ? caught.message : 'Ürün kopyalanamadı.');
        } finally {
            setSavingGuide(false);
        }
    };

    const deactivateStep = async (step: GuideStep) => {
        if (!selectedProduct) {
            setGuideError('Önce ürün seçin.');

            return;
        }

        setSavingGuide(true);
        setGuideError(null);
        setGuideNotice(null);

        try {
            await apiRequest(`/api/support/management/guides/${selectedProduct.id}/steps/${step.id}`, {
                method: 'PATCH',
                body: JSON.stringify({
                    section_type: step.section_type,
                    custom_title: step.custom_title,
                    entry_method: step.entry_method,
                    entry_format: step.entry_format || step.title,
                    content: step.content,
                    is_active: false,
                }),
            });
            resetStepForm();
            setShowInactiveSteps(true);
            await loadGuideProducts();
            setGuideNotice('Alt başlık pasifleştirildi.');
        } catch (caught) {
            setGuideError(caught instanceof Error ? caught.message : 'Alt başlık pasifleştirilemedi.');
        } finally {
            setSavingGuide(false);
        }
    };

    const editStep = (step: GuideStep) => {
        setEditingStepId(step.id);
        setStepForm({
            section_type: step.section_type,
            custom_title: step.custom_title ?? '',
            entry_method: step.entry_method ?? 'Tuş Takımı',
            entry_format: step.entry_format ?? step.title,
            content: step.content,
            is_active: step.is_active,
        });
    };

    const saveStep = async () => {
        if (!selectedProduct) {
            setGuideError('Önce ürün seçin.');

            return;
        }

        if (stepForm.section_type === 'other' && !stepForm.custom_title.trim()) {
            setGuideError('Diğer başlık için özel başlık zorunlu.');

            return;
        }

        if (!stepForm.content.trim()) {
            setGuideError('Alt başlık içeriği zorunlu.');

            return;
        }

        if (!stepForm.entry_format.trim()) {
            setGuideError('Giriş biçimi zorunlu.');

            return;
        }

        setSavingGuide(true);
        setGuideError(null);
        setGuideNotice(null);

        try {
            const payload = {
                section_type: stepForm.section_type,
                custom_title: nullableText(stepForm.custom_title),
                entry_method: nullableText(stepForm.entry_method),
                entry_format: stepForm.entry_format.trim(),
                content: stepForm.content.trim(),
                is_active: stepForm.is_active,
            };
            const endpoint = editingStepId
                ? `/api/support/management/guides/${selectedProduct.id}/steps/${editingStepId}`
                : `/api/support/management/guides/${selectedProduct.id}/steps`;

            await apiRequest(endpoint, {
                method: editingStepId ? 'PATCH' : 'POST',
                body: JSON.stringify(payload),
            });
            resetStepForm();
            await loadGuideProducts();
            setGuideNotice('Alt başlık kaydedildi.');
        } catch (caught) {
            setGuideError(caught instanceof Error ? caught.message : 'Alt başlık saklanamadı.');
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
                            Aktivasyon kodlarını, importları ve ürün bazlı tuşlama rehberlerini yönetin.
                        </p>
                    </div>
                    <nav className="flex flex-wrap gap-2 bg-slate-50 p-3">
                        <TabButton active={tab === 'activation'} onClick={() => setTab('activation')}>Aktivasyon Kodları</TabButton>
                        <TabButton active={tab === 'import'} onClick={() => setTab('import')}>İçe Aktar</TabButton>
                        <TabButton active={tab === 'guides'} onClick={() => setTab('guides')}>Tuşlama Rehberi</TabButton>
                    </nav>
                </section>

                {tab === 'activation' ? (
                    <section className="grid min-w-0 gap-4 lg:grid-cols-[minmax(0,1fr)_24rem]">
                        <div className="grid min-w-0 gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                            <div className="grid gap-3 md:grid-cols-[minmax(0,1fr)_auto_auto] md:items-end">
                                <Field label="Aktivasyon kaydı ara">
                                    <span className="relative min-w-0">
                                        <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                                        <input
                                            value={activationSearch}
                                            onChange={(event) => {
                                                setActivationSearch(event.target.value);
                                                setActivationPage(1);
                                            }}
                                            placeholder="Seri no, temiz seri, aktivasyon kodu, arama kodu"
                                            className={`${inputClassName} pl-9`}
                                        />
                                    </span>
                                </Field>
                                <Field label="Sayfa boyutu">
                                    <select
                                        value={activationPerPage}
                                        onChange={(event) => {
                                            setActivationPerPage(Number(event.target.value));
                                            setActivationPage(1);
                                        }}
                                        className={inputClassName}
                                    >
                                        <option value={25}>25</option>
                                        <option value={50}>50</option>
                                    </select>
                                </Field>
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
                                <StatPill label="Filtrelenen" value={activationFilteredTotal} tone="blue" />
                                <StatPill label="Sayfa" value={`${activationPagination.current_page}/${activationPagination.last_page}`} />
                                {lastImport ? <StatPill label={`Son import #${lastImport.id}`} value={dateText(lastImport.created_at)} tone="emerald" /> : null}
                            </div>

                            {activationError ? <div className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{activationError}</div> : null}

                            <div className="grid min-w-0 gap-3">
                                {activationItems.map((item) => (
                                    <article key={item.id} className="grid min-w-0 gap-4 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                        <div className="grid min-w-0 gap-3 md:grid-cols-[minmax(0,1fr)_auto] md:items-start">
                                            <div className="min-w-0">
                                                <h2 className="break-words text-base font-semibold text-slate-950">{item.stock_name || 'Stok adı yok'}</h2>
                                                <p className="mt-1 break-words text-sm text-slate-600">{item.stock_code || '-'}</p>
                                            </div>
                                            <button
                                                type="button"
                                                onClick={() => editActivation(item)}
                                                className="inline-flex h-9 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:border-blue-200 hover:text-blue-700"
                                            >
                                                <Pencil className="size-4" />
                                                Düzenle
                                            </button>
                                        </div>
                                        <div className="grid min-w-0 gap-3 lg:grid-cols-[minmax(0,1.5fr)_minmax(10rem,0.75fr)_minmax(10rem,0.75fr)]">
                                            <ActivationValueTile label="Seri No" value={cleanSerialText(item)} />
                                            <ActivationValueTile label="Aktivasyon Kodu" value={activationCodeText(item)} />
                                            <ActivationValueTile label="Arama Kodu" value={item.search_code} />
                                        </div>
                                        {item.source || item.imported_at ? (
                                            <p className="text-xs text-slate-500">Kaynak: {item.source || '-'} / {dateText(item.imported_at)}</p>
                                        ) : null}
                                    </article>
                                ))}

                                {!loadingActivations && activationItems.length === 0 ? (
                                    <div className="rounded-2xl border border-slate-200 bg-slate-50 p-8 text-center text-sm text-slate-500">
                                        Kayıt bulunamadı.
                                    </div>
                                ) : null}
                            </div>

                            <div className="flex flex-col gap-2 border-t border-slate-100 pt-3 text-sm text-slate-600 md:flex-row md:items-center md:justify-between">
                                <span>
                                    {activationPagination.from ?? 0}-{activationPagination.to ?? 0} / {activationFilteredTotal}
                                </span>
                                <div className="flex gap-2">
                                    <button
                                        type="button"
                                        disabled={activationPagination.current_page <= 1}
                                        onClick={() => setActivationPage((current) => Math.max(1, current - 1))}
                                        className="rounded-xl border border-slate-200 bg-white px-3 py-2 font-semibold text-slate-700 disabled:opacity-50"
                                    >
                                        Önceki
                                    </button>
                                    <button
                                        type="button"
                                        disabled={activationPagination.current_page >= activationPagination.last_page}
                                        onClick={() => setActivationPage((current) => current + 1)}
                                        className="rounded-xl border border-slate-200 bg-white px-3 py-2 font-semibold text-slate-700 disabled:opacity-50"
                                    >
                                        Sonraki
                                    </button>
                                </div>
                            </div>
                        </div>

                        <aside className="grid h-fit gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm lg:sticky lg:top-24">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <h2 className="text-base font-semibold text-slate-950">{editingActivation ? 'Aktivasyon düzenle' : 'Manuel aktivasyon ekle'}</h2>
                                    <p className="mt-1 text-sm text-slate-500">Temiz seri boşsa seri no tireden ayrıştırılır.</p>
                                </div>
                                {editingActivation ? (
                                    <button type="button" onClick={resetActivationForm} className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600">
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
                                    <Field key={field} label={label}>
                                        <input value={activationForm[field]} onChange={(event) => setActivationForm((current) => ({ ...current, [field]: event.target.value }))} className={inputClassName} />
                                    </Field>
                                ))}
                            </div>
                            <button
                                type="button"
                                onClick={() => void saveActivation()}
                                disabled={savingActivation}
                                className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:opacity-60"
                            >
                                <Plus className="size-4" />
                                {savingActivation ? 'Kaydediliyor...' : 'Kaydet'}
                            </button>
                        </aside>
                    </section>
                ) : null}

                {tab === 'import' ? (
                    <section className="grid min-w-0 gap-4 lg:grid-cols-[minmax(0,1fr)_24rem]">
                        <div className="grid min-w-0 gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                            <div>
                                <h2 className="text-base font-semibold text-slate-950">CSV / XLSX / paste import</h2>
                                <p className="mt-1 text-sm leading-6 text-slate-600">Beklenen kolonlar: STOK_KODU, STOK_ADI, SERI_NO, SERI_NO_TEMIZ, ARAMA_KODU.</p>
                            </div>
                            <div className="grid min-w-0 gap-4 md:grid-cols-2">
                                <div className="grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <div className="flex items-center gap-2 text-sm font-semibold text-slate-900">
                                        <FileUp className="size-4 text-blue-700" />
                                        CSV / XLSX dosyası
                                    </div>
                                    <input
                                        type="file"
                                        accept=".csv,.txt,.xlsx,text/csv,text/plain,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                        onChange={(event) => setFile(event.target.files?.[0] ?? null)}
                                        className="block w-full min-w-0 text-sm text-slate-700 file:mr-3 file:rounded-xl file:border-0 file:bg-slate-950 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-white"
                                    />
                                    <button type="button" onClick={() => void previewImport('file')} disabled={importing} className="inline-flex h-10 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:border-blue-200 hover:text-blue-700 disabled:opacity-60">
                                        <Search className="size-4" />
                                        Preview
                                    </button>
                                </div>
                                <div className="grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <div className="flex items-center gap-2 text-sm font-semibold text-slate-900">
                                        <Upload className="size-4 text-blue-700" />
                                        Paste import
                                    </div>
                                    <p className="text-sm leading-6 text-slate-600">CSV satırlarını popup içine yapıştırın. Header varsa algılanır.</p>
                                    <button type="button" onClick={() => setPasteOpen(true)} className="inline-flex h-10 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:border-blue-200 hover:text-blue-700">
                                        Paste popup aç
                                    </button>
                                </div>
                            </div>

                            {importError ? <div className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{importError}</div> : null}

                            {preview ? (
                                <div className="grid min-w-0 gap-4 rounded-2xl border border-slate-200 bg-white p-4">
                                    <div className="flex flex-wrap gap-2">
                                        <StatPill label="Toplam" value={preview.total_rows} />
                                        <StatPill label="Yeni" value={preview.created_count} tone="emerald" />
                                        <StatPill label="Güncellenecek" value={preview.updated_count} tone="blue" />
                                        <StatPill label="Atlanan" value={preview.skipped_count} tone="amber" />
                                        <StatPill label="Hatalı" value={preview.failed_count} tone="rose" />
                                    </div>
                                    <div className="grid min-w-0 gap-2">
                                        {visiblePreviewRows.map((row) => (
                                            <article key={`${row.row}-${row.seri_no_temiz}`} className="grid min-w-0 gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="font-semibold text-slate-900">Satır {row.row}</span>
                                                    <span className={row.action === 'create' ? 'font-semibold text-emerald-700' : 'font-semibold text-blue-700'}>
                                                        {row.action === 'create' ? 'Yeni kayıt' : 'Güncellenecek'}
                                                    </span>
                                                </div>
                                                <p className="break-words text-slate-900">{row.stok_adi || '-'}</p>
                                                <p className="break-all text-slate-600">{row.seri_no || '-'} / {row.seri_no_temiz || '-'}</p>
                                                <p className="break-words text-slate-600">Aktivasyon: {row.aktivasyon_kodu || '-'} / Arama: {row.arama_kodu || '-'}</p>
                                            </article>
                                        ))}
                                    </div>
                                    {preview.errors.length > 0 ? (
                                        <div className="grid gap-2 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">
                                            <div className="flex items-center gap-2 font-semibold">
                                                <XCircle className="size-4" />
                                                İlk 20 hata
                                            </div>
                                            {preview.errors.map((error, index) => <p key={`${error.row}-${index}`}>Satır {error.row}: {error.reason}</p>)}
                                        </div>
                                    ) : null}
                                </div>
                            ) : null}
                        </div>

                        <aside className="grid h-fit gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm lg:sticky lg:top-24">
                            <h2 className="text-base font-semibold text-slate-950">Commit</h2>
                            <p className="text-sm leading-6 text-slate-600">Preview onaylanmadan kayıt yazılmaz. Commit transaction içinde temiz seri bazlı insert/update yapar.</p>
                            <button type="button" onClick={() => void commitImport()} disabled={!previewCanCommit} className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:opacity-60">
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
                    <section className="grid w-full max-w-full min-w-0 gap-4 overflow-hidden xl:grid-cols-[24rem_minmax(0,1fr)]">
                        <div className="grid h-fit w-full max-w-full min-w-0 gap-4 overflow-hidden rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                            <div className="grid gap-3">
                                <Field label="Ürün ara">
                                    <span className="relative min-w-0">
                                        <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                                        <input value={guideSearch} onChange={(event) => setGuideSearch(event.target.value)} placeholder="Ürün/model adı veya arama kelimesi" className={`${inputClassName} pl-9`} />
                                    </span>
                                </Field>
                                <button type="button" onClick={() => void loadGuideProducts()} className="inline-flex h-10 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700">
                                    <RefreshCw className="size-4" />
                                    Yenile
                                </button>
                            </div>

                            <div className="grid gap-2">
                                {guideProducts.map((product) => (
                                    <button
                                        key={product.id}
                                        type="button"
                                        onClick={() => {
                                            setSelectedProductId(product.id);
                                            editProduct(product);
                                        }}
                                        className={[
                                            'grid w-full max-w-full min-w-0 gap-1 overflow-hidden rounded-xl border px-3 py-3 text-left transition',
                                            selectedProductId === product.id ? 'border-blue-300 bg-blue-50' : 'border-slate-200 bg-slate-50 hover:border-blue-200',
                                        ].join(' ')}
                                    >
                                        <span className="break-all text-sm font-semibold text-slate-950">{product.product_name}</span>
                                        <span className="break-all text-xs text-slate-600">{product.search_keywords || 'Alternatif arama yok'}</span>
                                        <span className={product.is_active ? 'text-xs font-semibold text-emerald-700' : 'text-xs font-semibold text-slate-500'}>
                                            {product.is_active ? 'Aktif' : 'Pasif'} / {product.steps.length} alt başlık
                                        </span>
                                    </button>
                                ))}
                                {!loadingGuides && guideProducts.length === 0 ? <div className="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">Ürün rehberi bulunamadı.</div> : null}
                            </div>

                            <div className="grid gap-3 border-t border-slate-100 pt-4">
                                <h2 className="text-base font-semibold text-slate-950">{editingProductId ? 'Ürün düzenle' : 'Ürün ekle'}</h2>
                                <Field label="Ürün adı / model adı">
                                    <input value={productForm.product_name} onChange={(event) => setProductForm((current) => ({ ...current, product_name: event.target.value }))} className={inputClassName} />
                                </Field>
                                <Field label="Alternatif arama kelimeleri">
                                    <textarea value={productForm.search_keywords} onChange={(event) => setProductForm((current) => ({ ...current, search_keywords: event.target.value }))} placeholder="Virgül veya satırla ayırın" className={textareaClassName} />
                                </Field>
                                <label className="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                                    <input type="checkbox" checked={productForm.is_active} onChange={(event) => setProductForm((current) => ({ ...current, is_active: event.target.checked }))} className="size-4 rounded border-slate-300 text-blue-700" />
                                    Aktif
                                </label>
                                <div className="flex gap-2">
                                    <button type="button" onClick={() => void saveProduct()} disabled={savingGuide} className="inline-flex h-10 flex-1 items-center justify-center gap-2 rounded-xl bg-slate-950 px-3 text-sm font-semibold text-white disabled:opacity-60">
                                        <Plus className="size-4" />
                                        Kaydet
                                    </button>
                                    {editingProductId ? <button type="button" onClick={resetProductForm} className="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700">Yeni</button> : null}
                                </div>
                            </div>
                        </div>

                        <div className="grid h-fit w-full max-w-full min-w-0 gap-4 overflow-hidden rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                            {guideError ? <div className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{guideError}</div> : null}
                            {guideNotice ? <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{guideNotice}</div> : null}
                            {selectedProduct ? (
                                <>
                                    <div>
                                        <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Seçili ürün</p>
                                        <h2 className="mt-1 break-all text-xl font-semibold text-slate-950">{selectedProduct.product_name}</h2>
                                        <p className="mt-1 break-all text-sm text-slate-600">{selectedProduct.search_keywords || 'Alternatif arama kelimesi yok'}</p>
                                        <div className="mt-3 flex flex-wrap gap-2">
                                            <button type="button" onClick={() => void duplicateProduct()} disabled={savingGuide} className="inline-flex h-10 items-center justify-center gap-2 rounded-xl border border-blue-200 bg-blue-50 px-3 text-sm font-semibold text-blue-700 disabled:opacity-60">
                                                <Copy className="size-4" />
                                                Ürünü kopyala
                                            </button>
                                            <label className="inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700">
                                                <input type="checkbox" checked={showInactiveSteps} onChange={(event) => setShowInactiveSteps(event.target.checked)} className="size-4 rounded border-slate-300 text-blue-700" />
                                                Pasifleri göster
                                            </label>
                                        </div>
                                    </div>

                                    <div className="grid gap-3">
                                        {visibleSteps.map((step) => (
                                            <article key={step.id} className="grid w-full max-w-full min-w-0 gap-2 overflow-hidden rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                                <div className="flex min-w-0 flex-col gap-2 md:flex-row md:items-start md:justify-between">
                                                    <div className="min-w-0">
                                                        <h3 className="break-all text-base font-semibold text-slate-950">{step.title}</h3>
                                                        <div className="mt-1 flex flex-wrap gap-2 text-xs font-semibold">
                                                            <span className={step.is_active ? 'text-emerald-700' : 'text-slate-500'}>{step.is_active ? 'Aktif' : 'Pasif'}</span>
                                                            {step.entry_method ? <span className="max-w-full break-all rounded-full border border-blue-100 bg-blue-50 px-2 py-0.5 text-blue-700">{step.entry_method}</span> : null}
                                                            {step.entry_format ? <span className="max-w-full break-all rounded-full border border-slate-200 bg-white px-2 py-0.5 text-slate-700">{step.entry_format}</span> : null}
                                                        </div>
                                                    </div>
                                                    <div className="flex flex-wrap gap-2">
                                                        <button type="button" onClick={() => editStep(step)} className="inline-flex h-9 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700">
                                                            <Pencil className="size-4" />
                                                            Düzenle
                                                        </button>
                                                        {step.is_active ? (
                                                            <button type="button" onClick={() => void deactivateStep(step)} disabled={savingGuide} className="inline-flex h-9 items-center justify-center gap-2 rounded-xl border border-rose-200 bg-white px-3 text-sm font-semibold text-rose-700 disabled:opacity-60">
                                                                <XCircle className="size-4" />
                                                                Pasifleştir
                                                            </button>
                                                        ) : null}
                                                    </div>
                                                </div>
                                                <p className="whitespace-pre-line break-all text-sm leading-6 text-slate-700">{step.content}</p>
                                            </article>
                                        ))}
                                        {visibleSteps.length === 0 ? <div className="rounded-xl border border-slate-200 bg-slate-50 p-5 text-sm text-slate-500">Bu ürün için görüntülenecek alt başlık yok.</div> : null}
                                    </div>

                                    <div className="grid gap-3 border-t border-slate-100 pt-4">
                                        <h2 className="text-base font-semibold text-slate-950">{editingStepId ? 'Alt başlık düzenle' : 'Alt başlık ekle'}</h2>
                                        <div className="grid gap-3 md:grid-cols-2">
                                            <Field label="Giriş yöntemi">
                                                <select value={stepForm.entry_method} onChange={(event) => setStepForm((current) => ({ ...current, entry_method: event.target.value }))} className={inputClassName}>
                                                    <option value="">Yöntem belirtilmedi</option>
                                                    {guideMethodOptions.map((method) => <option key={method} value={method}>{method}</option>)}
                                                </select>
                                            </Field>
                                            <Field label="Giriş biçimi">
                                                <input value={stepForm.entry_format} onChange={(event) => setStepForm((current) => ({ ...current, entry_format: event.target.value }))} placeholder="Örn. Pin Ekleme, Cihaz Eşleme" className={inputClassName} />
                                            </Field>
                                        </div>
                                        <Field label="Başlık türü">
                                            <select
                                                value={stepForm.section_type}
                                                onChange={(event) => {
                                                    const sectionType = event.target.value;

                                                    setStepForm((current) => ({
                                                        ...current,
                                                        section_type: sectionType,
                                                        entry_format: current.entry_format.trim() ? current.entry_format : sectionTypeLabel(sectionType),
                                                    }));
                                                }}
                                                className={inputClassName}
                                            >
                                                {sectionTypeOptions.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                            </select>
                                        </Field>
                                        {stepForm.section_type === 'other' ? (
                                            <Field label="Özel başlık">
                                                <input value={stepForm.custom_title} onChange={(event) => setStepForm((current) => ({ ...current, custom_title: event.target.value }))} className={inputClassName} />
                                            </Field>
                                        ) : null}
                                        <Field label="İçerik">
                                            <textarea value={stepForm.content} onChange={(event) => setStepForm((current) => ({ ...current, content: event.target.value }))} className={textareaClassName} />
                                        </Field>
                                        <label className="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                                            <input type="checkbox" checked={stepForm.is_active} onChange={(event) => setStepForm((current) => ({ ...current, is_active: event.target.checked }))} className="size-4 rounded border-slate-300 text-blue-700" />
                                            Aktif
                                        </label>
                                        <div className="flex gap-2">
                                            <button type="button" onClick={() => void saveStep()} disabled={savingGuide} className="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white disabled:opacity-60">
                                                <Plus className="size-4" />
                                                Kaydet
                                            </button>
                                            {editingStepId ? <button type="button" onClick={resetStepForm} className="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700">Yeni alt başlık</button> : null}
                                        </div>
                                    </div>
                                </>
                            ) : (
                                <div className="rounded-2xl border border-slate-200 bg-slate-50 p-8 text-center text-sm text-slate-500">
                                    Ürün seçin veya yeni ürün ekleyin.
                                </div>
                            )}
                        </div>
                    </section>
                ) : null}

                {pasteOpen ? (
                    <div className="fixed inset-0 z-50 grid place-items-center bg-slate-950/40 p-4">
                        <div className="grid max-h-[90vh] w-full max-w-3xl gap-4 overflow-y-auto rounded-2xl bg-white p-4 shadow-xl">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <h2 className="text-base font-semibold text-slate-950">Paste import</h2>
                                    <p className="mt-1 text-sm text-slate-600">CSV satırlarını yapıştırın; header varsa algılanır.</p>
                                </div>
                                <button type="button" onClick={() => setPasteOpen(false)} className="rounded-xl border border-slate-200 bg-white p-2 text-slate-600">
                                    <X className="size-4" />
                                </button>
                            </div>
                            <textarea value={pasteText} onChange={(event) => setPasteText(event.target.value)} placeholder="STOK_KODU,STOK_ADI,SERI_NO,SERI_NO_TEMIZ,ARAMA_KODU" className="min-h-72 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium leading-6 text-slate-800 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-blue-400 focus:ring-4 focus:ring-blue-100" />
                            <div className="flex flex-col gap-2 sm:flex-row sm:justify-end">
                                <button type="button" onClick={() => setPasteOpen(false)} className="h-10 rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700">Vazgeç</button>
                                <button type="button" onClick={() => void previewImport('paste')} disabled={importing} className="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white disabled:opacity-60">
                                    <Search className="size-4" />
                                    Preview
                                </button>
                            </div>
                        </div>
                    </div>
                ) : null}
            </main>
        </>
    );
}
