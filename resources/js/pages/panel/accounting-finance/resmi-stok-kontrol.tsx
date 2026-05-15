import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    BadgeCheck,
    CalendarDays,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    ChevronUp,
    FileCheck2,
    Info,
    Layers3,
    LoaderCircle,
    ReceiptText,
    RotateCcw,
    Search,
    Sigma,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { apiRequest } from '@/lib/api';

type CategoryName = 'Mekanik Kapı Kolu' | 'Güvenlik Kasası' | 'Akıllı Kilit';
type SummaryRowKind = CategoryName | 'Toplam' | string;
type ActionKind = 'invoice' | 'correction' | 'balanced' | 'virtual';

type ApiRow = Record<string, unknown>;

type ApiDataResponse = {
    rows?: ApiRow[];
    queryMeta?: {
        notice?: string | null;
    };
};

type StockSummaryRow = {
    category: SummaryRowKind;
    grossOfficialStock: number;
    smartOffset: number;
    netOfficialStock: number;
    mikroActualStock: number;
    invoiceQuantity: number;
    correctionQuantity: number;
    netStockImpact: number;
    action: ActionKind;
    isTotal: boolean;
};

type ModelDetailRow = {
    category: SummaryRowKind;
    modelName: string;
    modelNameSource: string;
    isSmart: boolean;
    isSmartPL: boolean;
    officialNetQuantity: number;
    actualQuantity: number;
    difference: number;
    action: ActionKind;
};

type CategoryFilterValue = 'all' | CategoryName;
type ActionFilterValue = 'all' | ActionKind;

type DecisionCardProps = {
    title: string;
    value: string;
    description?: string;
    icon: LucideIcon;
    tone: 'red' | 'amber' | 'slate' | 'blue';
    breakdown?: Array<{
        label: SummaryRowKind;
        value: number;
    }>;
};

const dataSourceCode = 'accounting_finance_resmi_stok_kontrol';

const categoryOrder: CategoryName[] = ['Mekanik Kapı Kolu', 'Güvenlik Kasası', 'Akıllı Kilit'];

const categoryFilterOptions: Array<{ value: CategoryFilterValue; label: string }> = [
    { value: 'all', label: 'Tümü' },
    ...categoryOrder.map((category) => ({ value: category, label: category })),
];

const actionFilterOptions: Array<{ value: ActionFilterValue; label: string }> = [
    { value: 'all', label: 'Tümü' },
    { value: 'invoice', label: 'Resmileştirilecek Adet' },
    { value: 'correction', label: 'Stoksuz satış (Smart gibi)' },
    { value: 'virtual', label: 'Resmileşmiş adet' },
    { value: 'balanced', label: 'Resmi ve Gerçek stok tutuyor.' },
];

const mikroModelNameFields = [
    'MikroModelAdi',
    'MikroModelAdı',
    'sto_isim',
    'StokAdi',
    'StokAdı',
    'StokIsmi',
    'Stokİsmi',
    'MikroStokAdi',
    'MikroStokAdı',
    'UrunAdi',
    'UrunAdı',
    'ÜrünAdi',
    'ÜrünAdı',
    'UrunIsmi',
    'Urunİsmi',
    'ÜrünIsmi',
    'Ürünİsmi',
];

const categoryDisplayNames: Record<string, SummaryRowKind> = {
    'AKILLI KİLİT': 'Akıllı Kilit',
    'GÜVENLİK KASASI': 'Güvenlik Kasası',
    'MEKANİK KAPI KOLU': 'Mekanik Kapı Kolu',
    TOPLAM: 'Toplam',
};

const decisionToneClasses: Record<DecisionCardProps['tone'], string> = {
    amber: 'border-amber-200 bg-white text-slate-950',
    blue: 'border-blue-200 bg-white text-slate-950',
    red: 'border-rose-200 bg-white text-slate-950',
    slate: 'border-slate-200 bg-white text-slate-950',
};

const decisionAccentClasses: Record<DecisionCardProps['tone'], string> = {
    amber: 'bg-amber-500',
    blue: 'bg-blue-600',
    red: 'bg-rose-600',
    slate: 'bg-slate-700',
};

const decisionIconClasses: Record<DecisionCardProps['tone'], string> = {
    amber: 'bg-amber-50 text-amber-700 ring-amber-100',
    blue: 'bg-blue-50 text-blue-700 ring-blue-100',
    red: 'bg-rose-50 text-rose-700 ring-rose-100',
    slate: 'bg-slate-100 text-slate-700 ring-slate-200',
};

const actionStyles: Record<ActionKind, {
    label: string;
    className: string;
    Icon: LucideIcon;
}> = {
    balanced: {
        label: 'Resmi ve Gerçek stok tutuyor.',
        className: 'border-emerald-200 bg-emerald-50 text-emerald-800',
        Icon: BadgeCheck,
    },
    correction: {
        label: 'Stoksuz satış (Smart gibi)',
        className: 'border-amber-200 bg-amber-50 text-amber-900',
        Icon: RotateCcw,
    },
    invoice: {
        label: 'Resmileştirilecek Adet',
        className: 'border-rose-200 bg-rose-50 text-rose-800',
        Icon: AlertTriangle,
    },
    virtual: {
        label: 'Resmileşmiş adet',
        className: 'border-blue-200 bg-blue-50 text-blue-800',
        Icon: Layers3,
    },
};

function toIsoDate(date: Date): string {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

function getTodayReportDate(): string {
    return toIsoDate(new Date());
}

function formatQuantity(value: number): string {
    return new Intl.NumberFormat('tr-TR', { maximumFractionDigits: 2 }).format(value);
}

function formatReportDate(value: string): string {
    const [year, month, day] = value.split('-');

    return year && month && day ? `${day}.${month}.${year}` : value;
}

const turkishMonthNames = [
    'Ocak',
    'Şubat',
    'Mart',
    'Nisan',
    'Mayıs',
    'Haziran',
    'Temmuz',
    'Ağustos',
    'Eylül',
    'Ekim',
    'Kasım',
    'Aralık',
];

const turkishWeekDays = ['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz'];

function parseIsoDate(value: string): Date {
    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, month - 1, day);
}

function addMonths(date: Date, amount: number): Date {
    return new Date(date.getFullYear(), date.getMonth() + amount, 1);
}

function isSameIsoDate(first: string, second: string): boolean {
    return first === second;
}

function buildCalendarDays(monthDate: Date): Array<{ isoDate: string; day: number; isCurrentMonth: boolean }> {
    const firstDayOfMonth = new Date(monthDate.getFullYear(), monthDate.getMonth(), 1);
    const mondayBasedOffset = (firstDayOfMonth.getDay() + 6) % 7;
    const firstCalendarDay = new Date(firstDayOfMonth);

    firstCalendarDay.setDate(firstDayOfMonth.getDate() - mondayBasedOffset);

    return Array.from({ length: 42 }, (_, index) => {
        const dayDate = new Date(firstCalendarDay);

        dayDate.setDate(firstCalendarDay.getDate() + index);

        return {
            day: dayDate.getDate(),
            isCurrentMonth: dayDate.getMonth() === monthDate.getMonth(),
            isoDate: toIsoDate(dayDate),
        };
    });
}

function formatCategoryName(value: string): SummaryRowKind {
    const key = value.trim().toLocaleUpperCase('tr-TR');

    return categoryDisplayNames[key] ?? value;
}

function stringValue(row: ApiRow, key: string, fallback = ''): string {
    const value = row[key];

    return value === null || value === undefined ? fallback : String(value);
}

function firstAvailableStringValue(row: ApiRow, keys: string[]): { value: string; source: string } | null {
    for (const key of keys) {
        const value = stringValue(row, key).trim();

        if (value && value !== '-') {
            return { source: key, value };
        }
    }

    return null;
}

function resolveModelName(row: ApiRow): { value: string; source: string } {
    return firstAvailableStringValue(row, mikroModelNameFields) ?? {
        source: 'RaporModelAdi',
        value: stringValue(row, 'RaporModelAdi', '-'),
    };
}

function normalizeFilterText(value: string): string {
    return value
        .trim()
        .toLocaleLowerCase('tr-TR')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/ı/g, 'i');
}

function numberValue(row: ApiRow, key: string): number {
    const value = row[key];

    if (typeof value === 'number') {
        return Number.isFinite(value) ? value : 0;
    }

    const parsed = Number(String(value ?? '').replace(/\./g, '').replace(',', '.'));

    return Number.isFinite(parsed) ? parsed : 0;
}

function booleanValue(row: ApiRow, key: string): boolean {
    const value = row[key];

    if (typeof value === 'boolean') {
        return value;
    }

    if (typeof value === 'number') {
        return value === 1;
    }

    return ['1', 'true', 'evet', 'yes'].includes(String(value ?? '').trim().toLowerCase());
}

function normalizeAction(value: unknown, fallback?: StockSummaryRow): ActionKind {
    if (fallback) {
        if (fallback.netStockImpact > 0 || fallback.invoiceQuantity > fallback.correctionQuantity) {
            return 'invoice';
        }

        if (fallback.correctionQuantity > 0) {
            return 'correction';
        }

        if (fallback.smartOffset > 0) {
            return 'virtual';
        }
    }

    const normalized = String(value ?? '').trim().toLocaleLowerCase('tr-TR');

    if (normalized.includes('sanal') || normalized.includes('mahsup') || normalized.includes('resmileşmiş')) {
        return 'virtual';
    }

    if (
        normalized.includes('stoksuz')
        || normalized.includes('alış')
        || normalized.includes('alis')
        || normalized.includes('giriş')
        || normalized.includes('giris')
        || normalized.includes('düzelt')
        || normalized.includes('duzelt')
        || normalized.includes('iade')
    ) {
        return 'correction';
    }

    if (
        normalized.includes('resmileştirilecek')
        || normalized.includes('satış')
        || normalized.includes('satis')
        || normalized.includes('çıkış')
        || normalized.includes('cikis')
        || normalized.includes('fatura')
    ) {
        return 'invoice';
    }

    if (normalized.includes('uyum') || normalized.includes('tutuyor') || normalized.includes('balanced')) {
        return 'balanced';
    }

    return 'balanced';
}

function mapSummaryRow(row: ApiRow): StockSummaryRow {
    const rawCategory = stringValue(row, 'Kategori', '-');
    const summaryRow = {
        category: formatCategoryName(rawCategory),
        grossOfficialStock: numberValue(row, 'BrutResmiStok'),
        smartOffset: numberValue(row, 'SmartMahsup'),
        netOfficialStock: numberValue(row, 'NetResmiStok'),
        mikroActualStock: numberValue(row, 'MikroFiiliStokDepo6Haric'),
        invoiceQuantity: numberValue(row, 'SatisFaturasiKesilecekAdet'),
        correctionQuantity: numberValue(row, 'AlisGirisDuzeltmeAdedi'),
        netStockImpact: numberValue(row, 'NetStokEtkisi'),
        action: 'balanced' as ActionKind,
        isTotal: rawCategory.toLocaleLowerCase('tr-TR') === 'toplam',
    };

    return {
        ...summaryRow,
        action: normalizeAction(row.NetAksiyon, summaryRow),
    };
}

function mapDetailRow(row: ApiRow): ModelDetailRow {
    const modelName = resolveModelName(row);

    return {
        category: formatCategoryName(stringValue(row, 'Kategori', '-')),
        modelName: modelName.value,
        modelNameSource: modelName.source,
        isSmart: booleanValue(row, 'IsSmart'),
        isSmartPL: booleanValue(row, 'IsSmartPL'),
        officialNetQuantity: numberValue(row, 'ResmiNetAdet'),
        actualQuantity: numberValue(row, 'FiiliAdet'),
        difference: numberValue(row, 'Fark'),
        action: normalizeAction(row.Aksiyon),
    };
}

function splitRows(rows: ApiRow[]): { summaryRows: StockSummaryRow[]; detailRows: ModelDetailRow[] } {
    return rows.reduce(
        (result, row) => {
            const rowType = stringValue(row, 'row_type').trim().toLowerCase();

            if (rowType === 'summary') {
                result.summaryRows.push(mapSummaryRow(row));
            }

            if (rowType === 'detail') {
                result.detailRows.push(mapDetailRow(row));
            }

            return result;
        },
        { summaryRows: [] as StockSummaryRow[], detailRows: [] as ModelDetailRow[] },
    );
}

function buildCategoryBreakdown(
    rows: StockSummaryRow[],
    valueSelector: (row: StockSummaryRow) => number,
): Array<{ label: SummaryRowKind; value: number }> {
    return categoryOrder.map((category) => {
        const row = rows.find((candidate) => candidate.category === category);

        return {
            label: category,
            value: row ? valueSelector(row) : 0,
        };
    });
}

function ActionBadge({ action }: { action: ActionKind }) {
    const style = actionStyles[action];
    const Icon = style.Icon;

    return (
        <span className={`inline-flex w-max max-w-full items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold ${style.className}`}>
            <Icon className="size-3.5 shrink-0" />
            <span className="whitespace-normal">{style.label}</span>
        </span>
    );
}

function ReportDatePicker({
    isoValue,
    displayValue,
    onChange,
}: {
    isoValue: string;
    displayValue: string;
    onChange: (isoValue: string, displayValue: string) => void;
}) {
    const pickerRef = useRef<HTMLDivElement>(null);
    const triggerRef = useRef<HTMLButtonElement>(null);
    const [open, setOpen] = useState(false);
    const [visibleMonth, setVisibleMonth] = useState(() => parseIsoDate(isoValue));
    const [popupPosition, setPopupPosition] = useState({ left: 16, top: 16, width: 336 });
    const todayIsoDate = getTodayReportDate();
    const calendarDays = useMemo(() => buildCalendarDays(visibleMonth), [visibleMonth]);

    const updatePopupPosition = useCallback(() => {
        const trigger = triggerRef.current;

        if (!trigger) {
            return;
        }

        const viewportGap = 16;
        const triggerRect = trigger.getBoundingClientRect();
        const popupWidth = Math.min(336, window.innerWidth - (viewportGap * 2));
        const popupHeight = 382;
        const topBelow = triggerRect.bottom + 8;
        const top = topBelow + popupHeight > window.innerHeight - viewportGap
            ? Math.max(viewportGap, triggerRect.top - popupHeight - 8)
            : topBelow;
        const left = Math.min(
            Math.max(viewportGap, triggerRect.right - popupWidth),
            window.innerWidth - popupWidth - viewportGap,
        );

        setPopupPosition({
            left,
            top,
            width: popupWidth,
        });
    }, []);

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        updatePopupPosition();

        const closeOnOutsideClick = (event: PointerEvent) => {
            if (!pickerRef.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };

        const closeOnEscape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };

        document.addEventListener('pointerdown', closeOnOutsideClick);
        document.addEventListener('keydown', closeOnEscape);
        window.addEventListener('resize', updatePopupPosition);
        window.addEventListener('scroll', updatePopupPosition, true);

        return () => {
            document.removeEventListener('pointerdown', closeOnOutsideClick);
            document.removeEventListener('keydown', closeOnEscape);
            window.removeEventListener('resize', updatePopupPosition);
            window.removeEventListener('scroll', updatePopupPosition, true);
        };
    }, [open, updatePopupPosition]);

    const openPicker = () => {
        setVisibleMonth(parseIsoDate(isoValue));
        updatePopupPosition();
        setOpen((current) => !current);
    };

    const selectDate = (isoDate: string) => {
        onChange(isoDate, formatReportDate(isoDate));
        setVisibleMonth(parseIsoDate(isoDate));
        setOpen(false);
    };

    return (
        <div ref={pickerRef} className="relative grid w-full gap-2 text-sm font-semibold text-slate-700 sm:max-w-[260px]">
            <span>Rapor tarihi</span>
            <div className="relative">
                <button
                    ref={triggerRef}
                    type="button"
                    onClick={openPicker}
                    className="flex h-12 w-full items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 text-left text-base font-semibold tabular-nums text-slate-950 shadow-sm outline-none transition hover:border-slate-300 focus:border-blue-300 focus:ring-4 focus:ring-blue-100"
                    aria-label="Takvimi aç"
                    aria-expanded={open}
                    aria-haspopup="dialog"
                >
                    <span>{displayValue}</span>
                    <CalendarDays className="size-5 shrink-0 text-blue-700" />
                </button>
            </div>

            {open ? (
                <div
                    className="fixed z-[999] max-h-[calc(100vh-2rem)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-3 shadow-[0_22px_55px_rgba(15,23,42,0.18)]"
                    role="dialog"
                    aria-label="Rapor tarihi takvimi"
                    style={{
                        left: popupPosition.left,
                        top: popupPosition.top,
                        width: popupPosition.width,
                    }}
                >
                    <div className="mb-3 flex items-center justify-between gap-2">
                        <button
                            type="button"
                            onClick={() => setVisibleMonth((current) => addMonths(current, -1))}
                            className="grid size-9 place-items-center rounded-xl border border-slate-200 text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-blue-200"
                            aria-label="Önceki ay"
                        >
                            <ChevronLeft className="size-4" />
                        </button>
                        <div className="text-center">
                            <p className="text-base font-semibold text-slate-950">
                                {turkishMonthNames[visibleMonth.getMonth()]} {visibleMonth.getFullYear()}
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={() => setVisibleMonth((current) => addMonths(current, 1))}
                            className="grid size-9 place-items-center rounded-xl border border-slate-200 text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-blue-200"
                            aria-label="Sonraki ay"
                        >
                            <ChevronRight className="size-4" />
                        </button>
                    </div>

                    <div className="grid grid-cols-7 gap-1 text-center text-xs font-semibold text-slate-500">
                        {turkishWeekDays.map((day) => (
                            <span key={day} className="py-2">{day}</span>
                        ))}
                    </div>

                    <div className="mt-1 grid grid-cols-7 gap-1">
                        {calendarDays.map((day) => {
                            const selected = isSameIsoDate(day.isoDate, isoValue);
                            const today = isSameIsoDate(day.isoDate, todayIsoDate);

                            return (
                                <button
                                    key={day.isoDate}
                                    type="button"
                                    onClick={() => selectDate(day.isoDate)}
                                    className={[
                                        'grid h-9 place-items-center rounded-xl text-sm font-semibold tabular-nums transition focus:outline-none focus:ring-2 focus:ring-blue-200',
                                        selected ? 'bg-blue-700 text-white shadow-sm hover:bg-blue-800' : '',
                                        !selected && today ? 'border border-blue-200 bg-blue-50 text-blue-800' : '',
                                        !selected && !today && day.isCurrentMonth ? 'text-slate-800 hover:bg-slate-100' : '',
                                        !selected && !today && !day.isCurrentMonth ? 'text-slate-300 hover:bg-slate-50' : '',
                                    ].join(' ')}
                                >
                                    {day.day}
                                </button>
                            );
                        })}
                    </div>
                </div>
            ) : null}
        </div>
    );
}

function DecisionCard({ title, value, description, icon: Icon, tone, breakdown }: DecisionCardProps) {
    return (
        <section className={`relative overflow-hidden rounded-xl border p-5 shadow-[0_12px_30px_rgba(15,23,42,0.06)] ${decisionToneClasses[tone]}`}>
            <div className={`absolute inset-x-0 top-0 h-1 ${decisionAccentClasses[tone]}`} />
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-xs font-semibold uppercase text-slate-500">{title}</p>
                    <p className="mt-3 text-4xl font-semibold leading-none tabular-nums text-slate-950">{value}</p>
                </div>
                <span className={`grid size-11 shrink-0 place-items-center rounded-xl ring-1 ${decisionIconClasses[tone]}`}>
                    <Icon className="size-5" />
                </span>
            </div>

            {description ? (
                <p className="mt-4 text-sm leading-6 text-slate-600">{description}</p>
            ) : null}

            {breakdown ? (
                <dl className="mt-4 grid gap-2 text-sm">
                    {breakdown.map((item) => (
                        <div key={item.label} className="flex items-center justify-between gap-3 rounded-lg border border-slate-100 bg-slate-50 px-3 py-2">
                            <dt className="min-w-0 truncate font-medium text-slate-600">{item.label}</dt>
                            <dd className="shrink-0 rounded-full border border-slate-200 bg-white px-2.5 py-0.5 font-semibold tabular-nums text-slate-900">
                                {formatQuantity(item.value)}
                            </dd>
                        </div>
                    ))}
                </dl>
            ) : null}
        </section>
    );
}

function StateNotice({ title, description, tone }: { title: string; description: string; tone: 'loading' | 'error' | 'empty' }) {
    const toneClasses = {
        empty: 'border-slate-200 bg-white text-slate-700',
        error: 'border-rose-200 bg-rose-50 text-rose-900',
        loading: 'border-blue-200 bg-blue-50 text-blue-900',
    };

    return (
        <section className={`rounded-xl border p-5 shadow-[0_10px_26px_rgba(15,23,42,0.05)] ${toneClasses[tone]}`}>
            <div className="flex items-start gap-3">
                {tone === 'loading' ? <LoaderCircle className="mt-0.5 size-5 shrink-0 animate-spin" /> : <Info className="mt-0.5 size-5 shrink-0" />}
                <div>
                    <h2 className="text-base font-semibold">{title}</h2>
                    <p className="mt-1 text-sm leading-6 opacity-80">{description}</p>
                </div>
            </div>
        </section>
    );
}

function SummaryTable({ rows }: { rows: StockSummaryRow[] }) {
    return (
        <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-[0_14px_34px_rgba(15,23,42,0.06)]">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-white px-5 py-4">
                <div>
                    <h2 className="text-lg font-semibold text-slate-950">Kategori Özeti</h2>
                    <p className="mt-1 text-sm text-slate-500">Resmi net stok ile Mikro fiili stok farkı kategori bazında izlenir.</p>
                </div>
                <span className="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-600">
                    {formatQuantity(rows.length)} satır
                </span>
            </div>

            <div className="overflow-x-auto">
                <table className="w-full min-w-[1120px] text-left text-sm">
                    <thead className="bg-slate-100 text-xs font-semibold uppercase text-slate-600">
                        <tr>
                            <th className="px-4 py-3.5">Kategori</th>
                            <th className="px-4 py-3.5 text-right">Brüt Resmi Stok</th>
                            <th className="px-4 py-3.5 text-right">SMART / SMART PL Mahsup</th>
                            <th className="px-4 py-3.5 text-right">Net Resmi Stok</th>
                            <th className="px-4 py-3.5 text-right">Mikro Fiili Stok</th>
                            <th className="px-4 py-3.5 text-right">Resmileştirilecek Adet</th>
                            <th className="px-4 py-3.5 text-right">Stoksuz Ürünlerin Çıkış Adeti</th>
                            <th className="px-4 py-3.5 text-right">Resmileştirilecek Toplam Adet</th>
                            <th className="px-4 py-3.5">Net Aksiyon</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr
                                key={row.category}
                                className={[
                                    'border-t border-slate-100 align-middle',
                                    row.isTotal ? 'bg-slate-950 text-white' : 'text-slate-700',
                                ].join(' ')}
                            >
                                <td className="px-4 py-4 font-semibold">{row.category}</td>
                                <td className="px-4 py-4 text-right font-medium tabular-nums">{formatQuantity(row.grossOfficialStock)}</td>
                                <td className="px-4 py-4 text-right font-medium tabular-nums">{formatQuantity(row.smartOffset)}</td>
                                <td className="px-4 py-4 text-right font-medium tabular-nums">{formatQuantity(row.netOfficialStock)}</td>
                                <td className="px-4 py-4 text-right font-medium tabular-nums">{formatQuantity(row.mikroActualStock)}</td>
                                <td className={`px-4 py-4 text-right font-semibold tabular-nums ${row.isTotal ? 'text-rose-200' : 'text-rose-600'}`}>
                                    {formatQuantity(row.invoiceQuantity)}
                                </td>
                                <td className={`px-4 py-4 text-right font-semibold tabular-nums ${row.isTotal ? 'text-amber-200' : 'text-amber-600'}`}>
                                    {formatQuantity(row.correctionQuantity)}
                                </td>
                                <td className="px-4 py-4 text-right font-semibold tabular-nums">{formatQuantity(row.netStockImpact)}</td>
                                <td className="px-4 py-4">
                                    <ActionBadge action={row.action} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

function ModelDetailsSection({ rows }: { rows: ModelDetailRow[] }) {
    const [open, setOpen] = useState(false);
    const [categoryFilter, setCategoryFilter] = useState<CategoryFilterValue>('all');
    const [modelSearch, setModelSearch] = useState('');
    const [actionFilter, setActionFilter] = useState<ActionFilterValue>('all');
    const totals = useMemo(
        () => rows.reduce(
            (result, row) => ({
                actualQuantity: result.actualQuantity + row.actualQuantity,
                difference: result.difference + row.difference,
                officialNetQuantity: result.officialNetQuantity + row.officialNetQuantity,
            }),
            {
                actualQuantity: 0,
                difference: 0,
                officialNetQuantity: 0,
            },
        ),
        [rows],
    );
    const normalizedModelSearch = normalizeFilterText(modelSearch);
    const filteredRows = useMemo(
        () => rows.filter((row) => {
            const categoryMatches = categoryFilter === 'all' || row.category === categoryFilter;
            const actionMatches = actionFilter === 'all' || row.action === actionFilter;
            const modelMatches = !normalizedModelSearch || normalizeFilterText(row.modelName).includes(normalizedModelSearch);

            return categoryMatches && actionMatches && modelMatches;
        }),
        [actionFilter, categoryFilter, normalizedModelSearch, rows],
    );
    const hasMikroModelName = rows.some((row) => row.modelNameSource !== 'RaporModelAdi');
    const modelNameHeader = hasMikroModelName ? 'Mikro Model Adı' : 'Rapor Model Adı';
    const hasActiveFilters = categoryFilter !== 'all' || actionFilter !== 'all' || modelSearch.trim().length > 0;

    const clearFilters = () => {
        setCategoryFilter('all');
        setModelSearch('');
        setActionFilter('all');
    };

    return (
        <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-[0_14px_34px_rgba(15,23,42,0.06)]">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-white px-5 py-4">
                <div>
                    <h2 className="text-lg font-semibold text-slate-950">Model Detayları</h2>
                    <p className="mt-1 text-sm text-slate-500">
                        Kategori farklarını oluşturan model kırılımları. {filteredRows.length} / {rows.length} model gösteriliyor.
                    </p>
                </div>
                <button
                    type="button"
                    onClick={() => setOpen((current) => !current)}
                    className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-slate-300 hover:bg-slate-50"
                >
                    {open ? <ChevronUp className="size-4" /> : <ChevronDown className="size-4" />}
                    {open ? 'Kırılımı Gizle' : 'Kırılımı Göster'}
                </button>
            </div>

            {open ? (
                <>
                    <div className="grid gap-3 border-b border-slate-100 bg-slate-50/70 px-5 py-4 md:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)_minmax(0,1fr)_auto] md:items-end">
                        <label className="grid gap-1.5 text-sm font-semibold text-slate-700">
                            <span>Kategori</span>
                            <select
                                value={categoryFilter}
                                onChange={(event) => setCategoryFilter(event.target.value as CategoryFilterValue)}
                                className="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium text-slate-800 shadow-sm outline-none transition focus:border-blue-300 focus:ring-4 focus:ring-blue-100"
                            >
                                {categoryFilterOptions.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </label>

                        <label className="grid gap-1.5 text-sm font-semibold text-slate-700">
                            <span>Model ara</span>
                            <span className="relative">
                                <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                                <input
                                    type="search"
                                    value={modelSearch}
                                    onChange={(event) => setModelSearch(event.target.value)}
                                    placeholder="SBX, DDL720, PL40, SMART"
                                    className="h-11 w-full rounded-xl border border-slate-200 bg-white pl-9 pr-3 text-sm font-medium text-slate-800 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-blue-300 focus:ring-4 focus:ring-blue-100"
                                />
                            </span>
                        </label>

                        <label className="grid gap-1.5 text-sm font-semibold text-slate-700">
                            <span>Aksiyon</span>
                            <select
                                value={actionFilter}
                                onChange={(event) => setActionFilter(event.target.value as ActionFilterValue)}
                                className="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium text-slate-800 shadow-sm outline-none transition focus:border-blue-300 focus:ring-4 focus:ring-blue-100"
                            >
                                {actionFilterOptions.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </label>

                        <button
                            type="button"
                            onClick={clearFilters}
                            disabled={!hasActiveFilters}
                            className="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-3.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-slate-300 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <RotateCcw className="size-4" />
                            Filtreleri Temizle
                        </button>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[860px] text-left text-sm">
                            <thead className="bg-slate-100 text-xs font-semibold uppercase text-slate-600">
                                <tr>
                                    <th className="px-4 py-3.5">Kategori</th>
                                    <th className="px-4 py-3.5">{modelNameHeader}</th>
                                    <th className="px-4 py-3.5 text-right">Resmi Net Adet</th>
                                    <th className="px-4 py-3.5 text-right">Fiili Adet</th>
                                    <th className="px-4 py-3.5 text-right">Fark</th>
                                    <th className="px-4 py-3.5">Aksiyon</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr className="border-t border-slate-200 bg-slate-950 text-white">
                                    <td className="px-4 py-4 font-semibold">Toplam</td>
                                    <td className="px-4 py-4 font-medium text-slate-200">Genel Toplam</td>
                                    <td className="px-4 py-4 text-right font-semibold tabular-nums">{formatQuantity(totals.officialNetQuantity)}</td>
                                    <td className="px-4 py-4 text-right font-semibold tabular-nums">{formatQuantity(totals.actualQuantity)}</td>
                                    <td className="px-4 py-4 text-right font-semibold tabular-nums">{formatQuantity(totals.difference)}</td>
                                    <td className="px-4 py-4">
                                        <span className="inline-flex rounded-full border border-white/20 bg-white/10 px-2.5 py-1 text-xs font-semibold text-white">
                                            Genel Toplam
                                        </span>
                                    </td>
                                </tr>
                                {filteredRows.length === 0 ? (
                                    <tr className="border-t border-slate-100">
                                        <td colSpan={6} className="px-4 py-8 text-center text-sm font-medium text-slate-500">
                                            Filtreye uygun model bulunamadı.
                                        </td>
                                    </tr>
                                ) : null}
                                {filteredRows.map((row) => {
                                    const isSmartOffsetRow = row.isSmart || row.isSmartPL;

                                    return (
                                        <tr
                                            key={`${row.category}-${row.modelName}-${isSmartOffsetRow ? 'smart-offset' : 'regular'}`}
                                            className={[
                                                'border-t align-middle text-slate-700',
                                                isSmartOffsetRow
                                                    ? 'border-blue-100 bg-blue-50/70 shadow-[inset_3px_0_0_rgba(37,99,235,0.45)]'
                                                    : 'border-slate-100',
                                            ].join(' ')}
                                        >
                                            <td className="px-4 py-4 font-medium text-slate-950">{row.category}</td>
                                            <td className="px-4 py-4">{row.modelName}</td>
                                            <td className="px-4 py-4 text-right font-medium tabular-nums">{formatQuantity(row.officialNetQuantity)}</td>
                                            <td className="px-4 py-4 text-right font-medium tabular-nums">{formatQuantity(row.actualQuantity)}</td>
                                            <td className="px-4 py-4 text-right font-semibold tabular-nums">{formatQuantity(row.difference)}</td>
                                            <td className="px-4 py-4">
                                                <ActionBadge action={row.action} />
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </>
            ) : null}
        </section>
    );
}

export default function ResmiStokKontrol() {
    const [reportDateIso, setReportDateIso] = useState(() => getTodayReportDate());
    const [reportDateDisplay, setReportDateDisplay] = useState(() => formatReportDate(reportDateIso));
    const [rows, setRows] = useState<ApiRow[]>([]);
    const [notice, setNotice] = useState<string | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        let isCurrent = true;

        void apiRequest(`/api/data/${dataSourceCode}`, {
            method: 'POST',
            body: JSON.stringify({
                date_from: reportDateIso,
                date_to: reportDateIso,
            }),
        })
            .then((response: ApiDataResponse) => {
                if (!isCurrent) {
                    return;
                }

                setRows(Array.isArray(response.rows) ? response.rows : []);
                setNotice(response.queryMeta?.notice ?? null);
            })
            .catch((caught: unknown) => {
                if (!isCurrent) {
                    return;
                }

                setRows([]);
                setNotice(null);
                setError(caught instanceof Error ? caught.message : 'Veri alınamadı.');
            })
            .finally(() => {
                if (isCurrent) {
                    setLoading(false);
                }
            });

        return () => {
            isCurrent = false;
        };
    }, [reportDateIso]);

    const { summaryRows, detailRows } = useMemo(() => splitRows(rows), [rows]);
    const totalRow = summaryRows.find((row) => row.isTotal);
    const categoryRows = summaryRows.filter((row) => !row.isTotal);
    const hasRows = summaryRows.length > 0 || detailRows.length > 0;

    const updateReportDate = (isoValue: string, displayValue: string) => {
        const nextReportDateIso = isoValue || getTodayReportDate();
        const nextReportDateDisplay = displayValue || formatReportDate(nextReportDateIso);

        if (nextReportDateIso === reportDateIso) {
            return;
        }

        setRows([]);
        setNotice(null);
        setError(null);
        setLoading(true);
        setReportDateIso(nextReportDateIso);
        setReportDateDisplay(nextReportDateDisplay);
    };

    const totals = {
        correctionBreakdown: buildCategoryBreakdown(categoryRows, (row) => row.correctionQuantity),
        correctionQuantity: totalRow?.correctionQuantity ?? 0,
        invoiceBreakdown: buildCategoryBreakdown(categoryRows, (row) => row.invoiceQuantity),
        invoiceQuantity: totalRow?.invoiceQuantity ?? 0,
        netImpact: totalRow?.netStockImpact ?? 0,
        netImpactBreakdown: buildCategoryBreakdown(categoryRows, (row) => row.invoiceQuantity - row.correctionQuantity),
    };

    return (
        <>
            <Head title="Resmi Stok Kontrolü" />

            <main className="grid gap-6 bg-[#f4f7fb] p-4 md:p-6">
                <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-[0_18px_45px_rgba(15,23,42,0.07)]">
                    <div className="flex flex-col gap-5 border-b border-slate-100 bg-slate-50/70 px-5 py-5 lg:flex-row lg:items-start lg:justify-between md:px-6">
                        <div className="max-w-4xl">
                            <p className="text-xs font-semibold uppercase text-blue-700">Muhasebe / Finans</p>
                            <h1 className="mt-3 text-2xl font-semibold text-slate-950 md:text-3xl">Resmi Stok Kontrolü</h1>
                            <p className="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
                                Bu ekran, Q serili resmi dışı hareketleri resmi stok hesabına dahil etmeden; SMART ve SMART PL mahsuplarını düşerek, Mikro fiili stok ile resmi stok farkını 3 kategori bazında gösterir.
                            </p>
                        </div>
                        <ReportDatePicker isoValue={reportDateIso} displayValue={reportDateDisplay} onChange={updateReportDate} />
                    </div>
                </section>

                <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <DecisionCard
                        title="RESMİLEŞTİRİLECEK ADET"
                        value={formatQuantity(totals.invoiceQuantity)}
                        icon={ReceiptText}
                        tone="red"
                        breakdown={totals.invoiceBreakdown}
                    />
                    <DecisionCard
                        title="STOKSUZ ÜRÜNLERİN ÇIKIŞ ADETİ"
                        value={formatQuantity(totals.correctionQuantity)}
                        icon={FileCheck2}
                        tone="amber"
                        description="Stok kalmamış ürünlerin resmi çıkış adeti. Smart gibi çıkışı yapılmış oluyor."
                        breakdown={totals.correctionBreakdown}
                    />
                    <DecisionCard
                        title="RESMİLEŞTİRİLECEK TOPLAM ADET"
                        value={formatQuantity(totals.netImpact)}
                        icon={Sigma}
                        tone="slate"
                        description="Resmileştirilecek adet = resmi çıkış adedi - stoksuz ürünlerin çıkış adedi."
                        breakdown={totals.netImpactBreakdown}
                    />
                    <DecisionCard
                        title="RAPOR TARİHİ"
                        value={reportDateDisplay}
                        icon={CalendarDays}
                        tone="blue"
                        description={notice ?? 'Veriler seçilen rapor tarihine göre yenilenir.'}
                    />
                </section>

                {loading ? (
                    <StateNotice title="Veri yükleniyor" description="Resmi stok kontrol verileri alınıyor." tone="loading" />
                ) : null}

                {!loading && error ? (
                    <StateNotice title="Veri alınamadı" description={error} tone="error" />
                ) : null}

                {!loading && !error && !hasRows ? (
                    <StateNotice title="Kayıt bulunamadı" description={notice ?? 'Seçili rapor tarihinde gösterilecek satır bulunamadı.'} tone="empty" />
                ) : null}

                {!loading && !error && hasRows ? (
                    <>
                        <SummaryTable rows={summaryRows} />
                        <ModelDetailsSection rows={detailRows} />
                    </>
                ) : null}
            </main>
        </>
    );
}
