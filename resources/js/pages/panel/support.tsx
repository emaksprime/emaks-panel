import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    BadgeCheck,
    Copy,
    ExternalLink,
    Info,
    Keyboard,
    LifeBuoy,
    Search,
    SlidersHorizontal,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { apiRequest } from '@/lib/api';

type ActiveTab = 'guide' | 'activation';

type Filters = {
    device: string;
    method: string;
    format: string;
    search: string;
};

type EntryContentItem = {
    type: 'section' | 'step';
    text: string;
    number?: number;
};

type SupportGuideSection = {
    title: string | null;
    steps: string[];
};

type SupportGuideEntry = {
    id: number | string;
    code: string;
    sourceRow: number | null;
    devices: string[];
    deviceAliases: string[];
    method: string | null;
    guideType: string;
    sections: SupportGuideSection[];
    warnings: string[];
    extraNotes: string[];
    searchText?: string | null;
};

type SupportGuideData = {
    sourceSheet: string;
    total: number;
    entries: SupportGuideEntry[];
};

type SupportPermissions = {
    support: boolean;
    keypadGuide: boolean;
    activationQuery: boolean;
};

type SupportActivationSummary = {
    total: number;
};

type SupportActivationItem = {
    id: number;
    code: string;
    stock_code: string | null;
    stock_name: string | null;
    serial_number: string | null;
    serial_number_clean: string | null;
    search_code: string | null;
    activation_code: string | null;
    activation_link: string | null;
    matching_guide?: SupportGuideEntry | null;
    metadata: Record<string, unknown>;
};

type SupportActivationSearchResponse = {
    query: string;
    normalized_query: string;
    count: number;
    total: number;
    items: SupportActivationItem[];
};

type SupportPageProps = {
    supportActiveTab?: ActiveTab | null;
    supportGuideData?: SupportGuideData;
    supportPermissions?: SupportPermissions;
    supportActivationSummary?: SupportActivationSummary;
};

async function copyTextToClipboard(value: string): Promise<boolean> {
    if (navigator.clipboard?.writeText) {
        try {
            await navigator.clipboard.writeText(value);

            return true;
        } catch {
            // Fall through to the textarea fallback for restricted browser contexts.
        }
    }

    if (typeof document === 'undefined' || !document.body) {
        return false;
    }

    const textarea = document.createElement('textarea');
    textarea.value = value;
    textarea.setAttribute('readonly', 'true');
    textarea.style.position = 'fixed';
    textarea.style.left = '-9999px';
    textarea.style.top = '-9999px';
    textarea.style.opacity = '0';

    document.body.appendChild(textarea);
    textarea.focus();
    textarea.select();
    textarea.setSelectionRange(0, textarea.value.length);

    try {
        return document.execCommand('copy');
    } catch {
        return false;
    } finally {
        textarea.remove();
    }
}

const emptyFilters: Filters = {
    device: '',
    method: '',
    format: '',
    search: '',
};

const popularDevices = ['E35', 'E35 PRO', 'G20 Pro', 'G10', 'Galaxy 10', 'Galaxy 20', 'DDL720'];
const resultPageSize = 30;
const emptySupportGuideEntries: SupportGuideEntry[] = [];
const ignoredOptionValues = new Set(['', '-', 'belirtilmemi', 'belirtilmemis', 'undefined', 'null']);
const preferredMethodOptions = ['Tuş Takımı', 'TUYA SMART', 'TTLOCK', 'GATEWAY', 'Uygulama ile Eşleme'];
const deviceAliasMatchers: Record<string, (device: string) => boolean> = {
    [normalizeDeviceText('DDL720')]: (device) => normalizeDeviceText(device).includes('ddl720'),
    [normalizeDeviceText('Galaxy 20')]: (device) => normalizeDeviceText(device).includes('galaxy 20'),
    [normalizeDeviceText('Galaxy 10')]: (device) => normalizeDeviceText(device).includes('galaxy 10'),
    [normalizeDeviceText('E35 PRO')]: (device) => normalizeDeviceText(device) === 'e35 pro',
    [normalizeDeviceText('E35')]: (device) => normalizeDeviceText(device) === 'e35',
    [normalizeDeviceText('G20 Pro')]: (device) => normalizeDeviceText(device) === 'g20 pro',
    [normalizeDeviceText('G10')]: (device) => normalizeDeviceText(device) === 'g10',
};

function normalizeText(value: string): string {
    return value
        .toLocaleLowerCase('tr-TR')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/ı/g, 'i')
        .replace(/\s+/g, ' ')
        .trim();
}

function normalizeDeviceText(value: string): string {
    return normalizeText(value)
        .replace(/[^a-z0-9]+/gi, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

function uniqueSorted(values: string[]): string[] {
    return [...new Set(values.filter(Boolean))].sort((first, second) => first.localeCompare(second, 'tr-TR'));
}

function isMeaningfulOption(value: string): boolean {
    const normalized = normalizeText(value).replace(/[?!:.;,\s]+$/g, '').trim();

    return Boolean(normalized) && !ignoredOptionValues.has(normalized) && /[a-z0-9]/i.test(normalized);
}

const preferredMethodLookup = new Map(preferredMethodOptions.map((method) => [normalizeText(method), method]));

function normalizeMethod(value: string): string | null {
    if (!isMeaningfulOption(value)) {
        return null;
    }

    return preferredMethodLookup.get(normalizeText(value)) ?? null;
}

function entryMatchesDevice(entry: SupportGuideEntry, deviceFilter: string): boolean {
    if (!deviceFilter) {
        return true;
    }

    const normalizedFilter = normalizeDeviceText(deviceFilter);
    const aliasMatcher = deviceAliasMatchers[normalizedFilter];

    if (aliasMatcher) {
        return [...entry.devices, ...entry.deviceAliases].some(aliasMatcher);
    }

    return [...entry.devices, ...entry.deviceAliases].some((device) => normalizeDeviceText(device) === normalizedFilter);
}

function entryMatchesMethod(entry: SupportGuideEntry, methodFilter: string): boolean {
    return !methodFilter || normalizeMethod(entry.method ?? '') === methodFilter;
}

function entryMatchesFormat(entry: SupportGuideEntry, formatFilter: string): boolean {
    return !formatFilter || entry.guideType === formatFilter;
}

function methodOptionsForEntries(entries: SupportGuideEntry[]): string[] {
    return preferredMethodOptions.filter((method) => (
        entries.some((entry) => normalizeMethod(entry.method ?? '') === method)
    ));
}

function formatOptionsForEntries(entries: SupportGuideEntry[], method: string): string[] {
    return uniqueSorted(
        entries
            .filter((entry) => entryMatchesMethod(entry, method))
            .map((entry) => entry.guideType)
            .filter(isMeaningfulOption),
    );
}

function applyFilterCascade(filters: Filters, entries: SupportGuideEntry[]): Filters {
    const entriesMatchingDevice = entries.filter((entry) => entryMatchesDevice(entry, filters.device));
    const availableMethods = methodOptionsForEntries(entriesMatchingDevice);
    const method = filters.method && !availableMethods.includes(filters.method) ? '' : filters.method;
    const availableFormats = formatOptionsForEntries(entriesMatchingDevice, method);
    const format = filters.format && !availableFormats.includes(filters.format) ? '' : filters.format;

    return { ...filters, method, format };
}

function entrySearchText(entry: SupportGuideEntry): string {
    return normalizeText([
        entry.devices.join(' '),
        entry.deviceAliases.join(' '),
        entry.method,
        entry.guideType,
        entry.sections.map((section) => [section.title, ...section.steps].filter(Boolean).join(' ')).join(' '),
        entry.warnings.join(' '),
        entry.extraNotes.join(' '),
    ].join(' '));
}

function visibleDevices(devices: string[]): { shown: string[]; hiddenCount: number } {
    const shown = devices.slice(0, 3);

    return {
        shown,
        hiddenCount: Math.max(devices.length - shown.length, 0),
    };
}

function extractWarningStep(step: string): string | null {
    const trimmed = step.trim();

    if (!normalizeText(trimmed).startsWith('uyari')) {
        return null;
    }

    return trimmed.replace(/^uyar[ıi]\s*:?\s*/i, '').trim() || trimmed;
}

function buildEntryContent(entry: SupportGuideEntry): {
    items: EntryContentItem[];
    warnings: string[];
    extraNotes: string[];
} {
    const items: EntryContentItem[] = [];
    const inlineWarnings: string[] = [];
    let sectionStepNumber = 0;

    entry.sections.forEach((section) => {
        if (section.title) {
            sectionStepNumber = 0;
            items.push({ type: 'section', text: section.title });
        }

        section.steps.forEach((step) => {
            const warning = extractWarningStep(step);

            if (warning) {
                inlineWarnings.push(warning);

                return;
            }

            if (step.trim()) {
                sectionStepNumber += 1;
                items.push({ type: 'step', text: step, number: sectionStepNumber });
            }
        });
    });

    return {
        items,
        warnings: [...inlineWarnings, ...entry.warnings].filter(Boolean),
        extraNotes: entry.extraNotes.filter(Boolean),
    };
}

function entryHasGuideContent(entry: SupportGuideEntry): boolean {
    return entry.sections.some((section) => section.steps.some((step) => step.trim() !== ''));
}

function FilterSelect({
    label,
    value,
    options,
    onChange,
    allLabel,
}: {
    label: string;
    value: string;
    options: string[];
    onChange: (value: string) => void;
    allLabel: string;
}) {
    return (
        <label className="grid min-w-0 gap-1 overflow-hidden text-sm font-semibold text-slate-700">
            <span>{label}</span>
            <select
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="h-10 w-full max-w-full min-w-0 truncate rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium text-slate-800 shadow-sm outline-none transition focus:border-blue-400 focus:ring-4 focus:ring-blue-100"
            >
                <option value="">{allLabel}</option>
                {options.map((option) => (
                    <option key={option} value={option}>
                        {option}
                    </option>
                ))}
            </select>
        </label>
    );
}

function DeviceChip({
    device,
    onClick,
    selected = false,
}: {
    device: string;
    onClick: (device: string) => void;
    selected?: boolean;
}) {
    return (
        <button
            type="button"
            onClick={() => onClick(device)}
            className={[
                'rounded-full border px-2.5 py-1 text-xs font-semibold transition',
                selected
                    ? 'border-blue-700 bg-blue-700 text-white'
                    : 'border-slate-200 bg-white text-slate-700 hover:border-blue-300 hover:text-blue-700',
            ].join(' ')}
        >
            {device}
        </button>
    );
}

function StartPanel({ onSelectDevice }: { onSelectDevice: (device: string) => void }) {
    return (
        <section className="rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-sm">
            <div className="mx-auto flex size-12 items-center justify-center rounded-2xl bg-slate-900 text-white">
                <Keyboard className="size-6" />
            </div>
            <h2 className="mt-4 text-xl font-semibold text-slate-950">Cihaz seçerek başlayın</h2>
            <p className="mx-auto mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                Model adı, giriş yöntemi veya işlem adımı ile arayın. En hızlı yol cihazı seçip yalnızca ilgili kurulum adımlarını görmek.
            </p>
            <div className="mt-5 flex flex-wrap justify-center gap-2">
                {popularDevices.map((device) => (
                    <DeviceChip key={device} device={device} onClick={onSelectDevice} />
                ))}
            </div>
        </section>
    );
}

function GuideCard({
    entry,
    expanded,
    onToggle,
}: {
    entry: SupportGuideEntry;
    expanded: boolean;
    onToggle: (code: string) => void;
}) {
    const devices = visibleDevices(entry.devices);
    const content = buildEntryContent(entry);
    const title = isMeaningfulOption(entry.guideType) ? entry.guideType : 'Uygulama ile Eşleme Devam Adımları';
    const method = normalizeMethod(entry.method ?? '');
    const methodIsMissing = !isMeaningfulOption(entry.method ?? '');

    return (
        <article className="min-w-0 rounded-2xl border border-slate-200 bg-white p-3.5 shadow-sm md:p-4">
            <div className="flex min-w-0 flex-col gap-3 md:flex-row md:items-start md:justify-between">
                <div className="min-w-0">
                    <h2 className="min-w-0 text-base font-semibold text-slate-950">{title}</h2>
                    <div className="mt-2 flex flex-wrap items-center gap-2 text-xs font-semibold text-slate-600">
                        {method ? (
                            <span className="rounded-full border border-blue-100 bg-blue-50 px-2.5 py-1 text-blue-700">
                                {method}
                            </span>
                        ) : methodIsMissing ? (
                            <span className="text-xs font-medium text-slate-400">
                                Yöntem belirtilmedi
                            </span>
                        ) : null}
                        {devices.shown.map((device) => (
                            <span key={device} className="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-slate-700">
                                {device}
                            </span>
                        ))}
                        {devices.hiddenCount > 0 && (
                            <span className="rounded-full border border-slate-200 bg-white px-2.5 py-1 text-slate-500">
                                +{devices.hiddenCount} cihaz
                            </span>
                        )}
                    </div>
                </div>
                <button
                    type="button"
                    onClick={() => onToggle(entry.code)}
                    className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-blue-200 hover:text-blue-700 md:w-auto"
                >
                    {expanded ? 'Gizle' : 'Adımları göster'}
                </button>
            </div>

            {expanded && (
                <div className="mt-3 grid gap-3 border-t border-slate-100 pt-3">
                    {content.items.length > 0 && (
                        <div className="grid gap-2 rounded-xl border border-slate-100 bg-slate-50/70 p-3">
                            {content.items.map((item, index) => {
                                if (item.type === 'section') {
                                    return (
                                        <div
                                            key={`${entry.code}-section-${index}`}
                                            className={['flex min-w-0 items-center gap-2 text-sm font-bold text-slate-950', index > 0 ? 'pt-2' : ''].join(' ')}
                                        >
                                            <span className="size-2 shrink-0 rounded-full bg-blue-700" />
                                            <span className="min-w-0 break-words">{item.text}</span>
                                        </div>
                                    );
                                }

                                return (
                                    <div
                                        key={`${entry.code}-step-${index}`}
                                        className="grid min-w-0 grid-cols-[1.75rem_minmax(0,1fr)] gap-3"
                                    >
                                        <span className="mt-0.5 flex size-6 items-center justify-center rounded-full bg-slate-900 text-[0.68rem] font-bold leading-none text-white shadow-sm">
                                            {item.number}
                                        </span>
                                        <span className="min-w-0 whitespace-pre-line break-words rounded-xl border border-slate-100 bg-white px-3 py-2 text-sm leading-6 text-slate-700 shadow-sm">
                                            {item.text}
                                        </span>
                                    </div>
                                );
                            })}
                        </div>
                    )}

                    {(content.warnings.length > 0 || content.extraNotes.length > 0) && (
                        <div className="grid gap-2">
                            {content.warnings.map((warning) => (
                                <div
                                    key={`${entry.code}-warning-${warning}`}
                                    className="grid gap-1 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-900"
                                >
                                    <strong className="flex items-center gap-2 text-xs font-bold uppercase tracking-[0.12em] text-amber-800">
                                        <AlertTriangle className="size-4" />
                                        Uyarı
                                    </strong>
                                    <span className="whitespace-pre-line">{warning}</span>
                                </div>
                            ))}
                            {content.extraNotes.map((note) => (
                                <div
                                    key={`${entry.code}-note-${note}`}
                                    className="grid gap-1 rounded-xl border border-blue-100 bg-blue-50 px-4 py-3 text-sm leading-6 text-blue-900"
                                >
                                    <strong className="flex items-center gap-2 text-xs font-bold uppercase tracking-[0.12em] text-blue-800">
                                        <Info className="size-4" />
                                        Ek bilgi
                                    </strong>
                                    <span className="whitespace-pre-line">{note}</span>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            )}
        </article>
    );
}

function ActivationSearch({ totalRecords }: { totalRecords: number }) {
    const [query, setQuery] = useState('');
    const [result, setResult] = useState<SupportActivationSearchResponse | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [hasSearched, setHasSearched] = useState(false);
    const [copiedId, setCopiedId] = useState<number | null>(null);
    const [copyMessage, setCopyMessage] = useState<string | null>(null);

    const runSearch = async () => {
        const trimmedQuery = query.trim();

        if (trimmedQuery.length < 2) {
            setError('En az 2 karakter girin.');
            setResult(null);
            setHasSearched(false);

            return;
        }

        setLoading(true);
        setError(null);
        setCopyMessage(null);
        setHasSearched(true);
        setCopiedId(null);

        try {
            const params = new URLSearchParams({ query: trimmedQuery });
            const response = await apiRequest(`/api/support/activation/search?${params.toString()}`);

            setResult(response as SupportActivationSearchResponse);
        } catch (caught) {
            setError(caught instanceof Error ? caught.message : 'Aktivasyon sorgusu yapılamadı.');
            setResult(null);
        } finally {
            setLoading(false);
        }
    };

    const copyActivationCode = async (item: SupportActivationItem) => {
        const activationCode = item.activation_code?.trim();

        if (!activationCode) {
            return;
        }

        if (await copyTextToClipboard(activationCode)) {
            setError(null);
            setCopyMessage('Aktivasyon kodu kopyalandı.');
            setCopiedId(item.id);
            window.setTimeout(() => {
                setCopiedId((current) => (current === item.id ? null : current));
                setCopyMessage(null);
            }, 1600);

            return;
        }

        setCopyMessage(null);
        setError('Aktivasyon kodu kopyalanamadı. Kodu manuel seçip kopyalayın.');
    };

    return (
        <section className="grid gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm md:p-5">
            <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                <div className="min-w-0">
                    <div className="flex size-11 items-center justify-center rounded-2xl bg-slate-900 text-white">
                        <BadgeCheck className="size-5" />
                    </div>
                    <h2 className="mt-4 text-xl font-semibold text-slate-950">Aktivasyon Sorgu</h2>
                    <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                        Seri no, temiz seri no, arama kodu, aktivasyon kodu veya stok adı ile kayıt arayın.
                    </p>
                </div>
                <span className="w-fit rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-600">
                    {totalRecords} kayıt
                </span>
            </div>

            <div className="grid gap-3 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
                <label className="grid min-w-0 gap-1 text-sm font-semibold text-slate-700">
                    <span>Arama</span>
                    <span className="relative min-w-0">
                        <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                        <input
                            type="search"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter') {
                                    void runSearch();
                                }
                            }}
                            placeholder="Seri no, temiz seri no, arama kodu, aktivasyon kodu veya stok adı ara"
                            className="h-11 w-full rounded-xl border border-slate-200 bg-white py-2 pl-9 pr-3 text-sm font-medium text-slate-800 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-blue-400 focus:ring-4 focus:ring-blue-100"
                        />
                    </span>
                </label>
                <button
                    type="button"
                    onClick={() => void runSearch()}
                    disabled={loading}
                    className="h-11 rounded-xl bg-slate-900 px-5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    {loading ? 'Aranıyor...' : 'Ara'}
                </button>
            </div>

            {error && (
                <div className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                    {error}
                </div>
            )}

            {copyMessage && (
                <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
                    {copyMessage}
                </div>
            )}

            {totalRecords === 0 && !hasSearched && (
                <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-900">
                    Henüz aktarılmış aktivasyon kaydı yok. Veri geldiğinde bu ekran aynı arama akışıyla sonuç gösterecek.
                </div>
            )}

            {hasSearched && !loading && !error && (result?.items.length ?? 0) === 0 && (
                <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-6 text-center text-sm text-slate-600">
                    Kayıt bulunamadı. Seri no, arama kodu, aktivasyon kodu veya stok adını farklı yazımla tekrar deneyin.
                </div>
            )}

            {(result?.items.length ?? 0) > 0 && (
                <div className="grid gap-3">
                    {result?.items.map((item) => {
                        const activationCode = item.activation_code?.trim() || null;
                        const activationLink = item.activation_link?.trim() || null;

                        return (
                            <article key={item.id} className="grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                    <div className="min-w-0">
                                        <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Aktivasyon kaydı</p>
                                        <h3 className="mt-1 break-words text-base font-semibold text-slate-950">
                                            {item.stock_name || item.serial_number || 'Stok adı belirtilmedi'}
                                        </h3>
                                        <p className="mt-1 break-words text-sm text-slate-600">{item.stock_code || 'Stok kodu yok'}</p>
                                    </div>

                                    {activationCode && (
                                        <button
                                            type="button"
                                            onClick={() => void copyActivationCode(item)}
                                            className="inline-flex h-10 items-center justify-center rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:border-blue-200 hover:text-blue-700"
                                        >
                                            <Copy className="mr-2 size-4" />
                                            {copiedId === item.id ? 'Kopyalandı' : 'Kodu kopyala'}
                                        </button>
                                    )}
                                </div>

                                <div className="grid gap-2 md:grid-cols-2 xl:grid-cols-4">
                                    {[
                                        ['Seri No', item.serial_number || '-'],
                                        ['Seri No Temiz', item.serial_number_clean || '-'],
                                        ['Arama Kodu', item.search_code || '-'],
                                        ['Aktivasyon Kodu', activationCode || 'Aktivasyon kodu alanı boş'],
                                    ].map(([label, value]) => (
                                        <div key={label} className="rounded-xl border border-slate-200 bg-white px-3 py-2">
                                            <p className="text-[11px] font-bold uppercase tracking-[0.12em] text-slate-500">{label}</p>
                                            <p className="mt-1 break-words text-sm font-medium text-slate-900">{value}</p>
                                        </div>
                                    ))}
                                </div>

                                {item.matching_guide && entryHasGuideContent(item.matching_guide) && (
                                    <div className="grid gap-2 rounded-xl border border-blue-100 bg-blue-50 px-3 py-3 text-sm text-blue-950">
                                        <p className="font-semibold">İlgili tuşlama rehberi: {item.matching_guide.guideType}</p>
                                        <div className="grid gap-1">
                                            {item.matching_guide.sections.slice(0, 3).map((section) => (
                                                <div key={`${item.id}-${section.title}`} className="min-w-0">
                                                    {section.title && <p className="font-medium">{section.title}</p>}
                                                    {section.steps.slice(0, 2).map((step) => (
                                                        <p key={step} className="break-words text-blue-900/80">{step}</p>
                                                    ))}
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                )}

                                {!activationCode && (
                                    <div className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                                        Aktivasyon kodu alanı boş; arama kodu bu alanın yerine gösterilmedi.
                                    </div>
                                )}

                                {activationLink && (
                                    <a
                                        href={activationLink}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="inline-flex w-fit items-center gap-2 rounded-xl border border-blue-100 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-700 transition hover:border-blue-200 hover:bg-blue-100"
                                    >
                                        Aktivasyon linkini aç
                                        <ExternalLink className="size-4" />
                                    </a>
                                )}
                            </article>
                        );
                    })}
                </div>
            )}
        </section>
    );
}

function SupportAccessEmpty() {
    return (
        <section className="rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-sm">
            <div className="mx-auto flex size-11 items-center justify-center rounded-2xl bg-slate-900 text-white">
                <LifeBuoy className="size-5" />
            </div>
            <h2 className="mt-4 text-lg font-semibold text-slate-950">Destek alt kaynakları kapalı</h2>
            <p className="mx-auto mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                Bu kullanıcı için Tuşlama ve Kurulum Rehberi veya Aktivasyon Sorgu erişimi ayrıca açılmalı.
            </p>
        </section>
    );
}

export default function SupportPage({
    supportActiveTab = null,
    supportGuideData,
    supportPermissions,
    supportActivationSummary,
}: SupportPageProps) {
    const permissions = supportPermissions ?? { support: false, keypadGuide: false, activationQuery: false };
    const activationSummary = supportActivationSummary ?? { total: 0 };
    const supportGuideEntries = supportGuideData?.entries ?? emptySupportGuideEntries;
    const activeTab = supportActiveTab && (
        (supportActiveTab === 'guide' && permissions.keypadGuide)
        || (supportActiveTab === 'activation' && permissions.activationQuery)
    )
        ? supportActiveTab
        : (permissions.keypadGuide ? 'guide' : (permissions.activationQuery ? 'activation' : null));
    const [filters, setFilters] = useState<Filters>(emptyFilters);
    const [expandedIds, setExpandedIds] = useState<Set<string>>(() => new Set());
    const [visibleCount, setVisibleCount] = useState(resultPageSize);

    const deviceOptions = useMemo(
        () => uniqueSorted([...popularDevices, ...supportGuideEntries.flatMap((entry) => [...entry.devices, ...entry.deviceAliases])]),
        [supportGuideEntries],
    );
    const entriesMatchingDevice = useMemo(
        () => supportGuideEntries.filter((entry) => entryMatchesDevice(entry, filters.device)),
        [filters.device, supportGuideEntries],
    );
    const methodOptions = useMemo(
        () => methodOptionsForEntries(entriesMatchingDevice),
        [entriesMatchingDevice],
    );
    const formatOptions = useMemo(
        () => formatOptionsForEntries(entriesMatchingDevice, filters.method),
        [entriesMatchingDevice, filters.method],
    );

    const hasQuery = Boolean(filters.device || filters.method || filters.format || filters.search.trim());
    const searchNeedle = normalizeText(filters.search);

    const deviceSuggestions = useMemo(() => {
        if (!searchNeedle) {
            return [];
        }

        return deviceOptions
            .filter((device) => normalizeText(device).includes(searchNeedle))
            .slice(0, 8);
    }, [deviceOptions, searchNeedle]);

    const filteredEntries = useMemo(() => {
        if (!hasQuery) {
            return [];
        }

        return supportGuideEntries.filter((entry) => {
            const matchesDevice = entryMatchesDevice(entry, filters.device);
            const matchesMethod = entryMatchesMethod(entry, filters.method);
            const matchesFormat = entryMatchesFormat(entry, filters.format);
            const matchesSearch = !searchNeedle || entrySearchText(entry).includes(searchNeedle);

            return entryHasGuideContent(entry) && matchesDevice && matchesMethod && matchesFormat && matchesSearch;
        });
    }, [filters.device, filters.format, filters.method, hasQuery, searchNeedle, supportGuideEntries]);

    const visibleEntries = filteredEntries.slice(0, visibleCount);

    const updateFilter = (key: keyof Filters, value: string) => {
        setFilters((current) => applyFilterCascade({ ...current, [key]: value }, supportGuideEntries));
        setExpandedIds(new Set());
        setVisibleCount(resultPageSize);
    };

    const selectDevice = (device: string) => {
        setFilters((current) => applyFilterCascade({ ...current, device, search: '' }, supportGuideEntries));
        setExpandedIds(new Set());
        setVisibleCount(resultPageSize);
    };

    const resetFilters = () => {
        setFilters(emptyFilters);
        setExpandedIds(new Set());
        setVisibleCount(resultPageSize);
    };

    const toggleEntry = (id: string) => {
        setExpandedIds((current) => {
            const next = new Set(current);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });
    };

    return (
        <>
            <Head title="Destek Merkezi" />

            <main className="grid min-w-0 gap-4 bg-[#f3f7fb] p-4 md:p-6">
                <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div className="border-b border-slate-100 bg-white p-4 md:p-5">
                        <span className="inline-flex items-center gap-2 rounded-full border border-blue-100 bg-blue-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.14em] text-blue-700">
                            <LifeBuoy className="size-3.5" />
                            Destek
                        </span>
                        <h1 className="mt-3 text-2xl font-semibold text-slate-950 [font-family:var(--font-display)] md:text-3xl">
                            Destek Merkezi
                        </h1>
                        <p className="mt-1 max-w-3xl text-sm leading-6 text-slate-600">
                            Cihaz kurulum, tuşlama ve aktivasyon adımlarına hızlıca ulaşın.
                        </p>
                    </div>

                    <nav className="flex flex-wrap gap-2 bg-slate-50 p-3">
                        {[
                            ['guide', 'Tuşlama ve Kurulum Rehberi', '/support/keypad-guide', permissions.keypadGuide],
                            ['activation', 'Aktivasyon Sorgu', '/support/activation', permissions.activationQuery],
                        ].filter((tab) => tab[3]).map(([tab, label, href]) => (
                            <Link
                                key={tab as string}
                                href={href as string}
                                className={[
                                    'rounded-full border px-4 py-2 text-sm font-semibold transition',
                                    activeTab === tab
                                        ? 'border-blue-700 bg-blue-700 text-white'
                                        : 'border-slate-200 bg-white text-slate-600 hover:border-blue-300 hover:text-blue-700',
                                ].join(' ')}
                            >
                                {label}
                            </Link>
                        ))}
                    </nav>
                </section>

                {activeTab === null ? (
                    <SupportAccessEmpty />
                ) : activeTab === 'guide' ? (
                    <>
                        <section className="grid min-w-0 gap-3 overflow-hidden rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div className="flex items-center gap-2">
                                    <SlidersHorizontal className="size-5 text-slate-500" />
                                    <h2 className="text-base font-semibold text-slate-950">Rehber filtreleri</h2>
                                    {hasQuery && (
                                        <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">
                                            {filteredEntries.length} sonuç
                                        </span>
                                    )}
                                </div>
                                <button
                                    type="button"
                                    onClick={resetFilters}
                                    className="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-600 transition hover:border-blue-200 hover:text-blue-700"
                                >
                                    Temizle
                                </button>
                            </div>

                            <label className="grid min-w-0 gap-1 text-sm font-semibold text-slate-700">
                                <span>Serbest arama</span>
                                <span className="relative min-w-0">
                                    <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                                    <input
                                        type="search"
                                        value={filters.search}
                                        onChange={(event) => updateFilter('search', event.target.value)}
                                        placeholder="Cihaz, model veya işlem ara: E35, Galaxy 20, Pin Ekleme..."
                                        className="h-11 w-full rounded-xl border border-slate-200 bg-white py-2 pl-9 pr-3 text-sm font-medium text-slate-800 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-blue-400 focus:ring-4 focus:ring-blue-100"
                                    />
                                </span>
                            </label>

                            {(filters.device || deviceSuggestions.length > 0) && (
                                <div className="flex flex-wrap items-center gap-2">
                                    {filters.device && (
                                        <span className="inline-flex items-center gap-1.5 rounded-full border border-blue-200 bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700">
                                            Seçili cihaz: {filters.device}
                                            <button
                                                type="button"
                                                onClick={() => updateFilter('device', '')}
                                                aria-label="Cihaz filtresini temizle"
                                                className="rounded-full text-blue-700 transition hover:bg-blue-100"
                                            >
                                                <X className="size-3.5" />
                                            </button>
                                        </span>
                                    )}
                                    {!filters.device && deviceSuggestions.map((device) => (
                                        <DeviceChip key={device} device={device} onClick={selectDevice} />
                                    ))}
                                </div>
                            )}

                            <div className="grid min-w-0 grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3 [&>*]:min-w-0">
                                <FilterSelect
                                    label="Cihaz"
                                    value={filters.device}
                                    options={deviceOptions}
                                    allLabel="Tüm cihazlar"
                                    onChange={(value) => updateFilter('device', value)}
                                />
                                <FilterSelect
                                    label="Giriş yöntemi"
                                    value={filters.method}
                                    options={methodOptions}
                                    allLabel="Tüm yöntemler"
                                    onChange={(value) => updateFilter('method', value)}
                                />
                                <FilterSelect
                                    label="Giriş biçimi"
                                    value={filters.format}
                                    options={formatOptions}
                                    allLabel="Tüm biçimler"
                                    onChange={(value) => updateFilter('format', value)}
                                />
                            </div>
                        </section>

                        {!hasQuery ? (
                            <StartPanel onSelectDevice={selectDevice} />
                        ) : (
                            <section className="grid gap-3">
                                {visibleEntries.length > 0 ? (
                                    <>
                                        <div className="grid gap-3">
                                            {visibleEntries.map((entry) => (
                                                <GuideCard
                                                    key={entry.code}
                                                    entry={entry}
                                                    expanded={expandedIds.has(entry.code)}
                                                    onToggle={toggleEntry}
                                                />
                                            ))}
                                        </div>

                                        {visibleEntries.length < filteredEntries.length && (
                                            <div className="flex justify-center">
                                                <button
                                                    type="button"
                                                    onClick={() => setVisibleCount((current) => current + resultPageSize)}
                                                    className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-blue-200 hover:text-blue-700"
                                                >
                                                    Daha fazla göster
                                                </button>
                                            </div>
                                        )}
                                    </>
                                ) : (
                                    <div className="rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm">
                                        <h3 className="text-lg font-semibold text-slate-950">Sonuç bulunamadı</h3>
                                        <p className="mt-2 text-sm text-slate-600">
                                            Filtreleri azaltarak veya farklı bir model adıyla tekrar ara.
                                        </p>
                                    </div>
                                )}
                            </section>
                        )}
                    </>
                ) : (
                    <ActivationSearch totalRecords={activationSummary.total} />
                )}
            </main>
        </>
    );
}
