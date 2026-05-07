import { useEffect, useState } from 'react';
import { Check, Search, X } from 'lucide-react';

const BRAND_OPTIONS = [
    { value: 'all', label: 'TÜMÜ' },
    { value: 'philips', label: 'PHILIPS' },
    { value: 'emaks_prime', label: 'EMAKS PRIME' },
];

const CATEGORY_OPTIONS = [
    { value: 'all', label: 'Tüm Kategoriler' },
    { value: 'A1', label: 'AKILLI KİLİT' },
    { value: 'AS1', label: 'AKILLI SİLİNDİR' },
    { value: 'D1', label: 'AKILLI DÜRBÜN' },
    { value: 'G1', label: 'GÜVENLİK KASASI' },
    { value: 'K1', label: 'KABİN KİLİDİ' },
    { value: 'KA1', label: 'KOLLU AKILLI KİLİT' },
    { value: 'M1', label: 'MEKANİK KAPI KOLU' },
    { value: 'O1', label: 'OTEL TİPİ' },
    { value: 'OT1', label: 'OTEL TİPİ AKSESUARLARI' },
    { value: 'YM1', label: 'YÜZEY MONTAJLI KİLİT CAM VS.' },
];

export function ProductFilter({
    brandFilter = 'all',
    categoryFilter = 'all',
    productFilter = '',
    productOptions = [],
    onChange,
}) {
    const normalizedProductFilter = productFilter ?? '';
    const optionValues = productOptions.map((option) => option.value);
    const optionValuesKey = optionValues.join('|');
    const parsedFilterValues = splitProductFilter(normalizedProductFilter);
    const selectedValues = parsedFilterValues.filter((value) => optionValues.includes(value));
    const selectedValueSet = new Set(selectedValues);
    const hasStructuredSelection = selectedValues.length > 0;
    const [localProductFilter, setLocalProductFilter] = useState(normalizedProductFilter);
    const [suggestionsOpen, setSuggestionsOpen] = useState(false);
    const update = (patch) => onChange({ ...patch, bypass_cache: true });
    const queryNeedle = normalizeOptionText(localProductFilter);
    const selectedProducts = selectedValues.map((value) => (
        productOptions.find((option) => option.value === value) ?? { value, label: value }
    ));
    const visibleOptions = productOptions
        .filter((option) => {
            if (queryNeedle === '') {
                return productOptions.length <= 8;
            }

            return normalizeOptionText([
                option.label,
                option.value,
                option.brand,
                option.brandName,
            ].filter(Boolean).join(' ')).includes(queryNeedle);
        })
        .slice(0, 12);

    useEffect(() => {
        const nextValues = splitProductFilter(normalizedProductFilter);
        const availableValues = new Set(optionValuesKey.split('|').filter(Boolean));
        const hasSelectedOptions = nextValues.some((value) => availableValues.has(value));

        setLocalProductFilter(hasSelectedOptions ? '' : normalizedProductFilter);
    }, [normalizedProductFilter, optionValuesKey]);

    useEffect(() => {
        if (hasStructuredSelection) {
            return undefined;
        }

        const timeoutId = window.setTimeout(() => {
            if (localProductFilter !== normalizedProductFilter) {
                onChange({ product_filter: localProductFilter, bypass_cache: true });
            }
        }, 350);

        return () => window.clearTimeout(timeoutId);
    }, [hasStructuredSelection, localProductFilter, normalizedProductFilter, onChange]);

    const emitSelectedProducts = (values) => {
        update({ product_filter: values.join(', ') });
        setLocalProductFilter('');
        setSuggestionsOpen(false);
    };

    const toggleProduct = (option) => {
        const nextValues = selectedValueSet.has(option.value)
            ? selectedValues.filter((value) => value !== option.value)
            : [...selectedValues, option.value];

        emitSelectedProducts(nextValues);
    };

    const removeProduct = (value) => {
        emitSelectedProducts(selectedValues.filter((selectedValue) => selectedValue !== value));
    };

    const clearProducts = () => emitSelectedProducts([]);

    return (
        <div className="grid gap-3">
            <label className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">
                Ürün Filtresi
            </label>
            <div className="grid gap-3 lg:grid-cols-[minmax(0,1fr)_220px_280px]">
                <div
                    className="relative"
                    onBlur={(event) => {
                        if (!event.currentTarget.contains(event.relatedTarget)) {
                            setSuggestionsOpen(false);
                        }
                    }}
                >
                    <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                    <input
                        type="search"
                        value={localProductFilter}
                        onChange={(event) => {
                            setLocalProductFilter(event.target.value);
                            setSuggestionsOpen(true);
                        }}
                        onFocus={() => setSuggestionsOpen(true)}
                        placeholder="Ürün, model veya stok kodu ara"
                        className="h-11 w-full rounded-xl border border-slate-200 bg-white pl-10 pr-3 text-sm font-medium text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-blue-300 focus:ring-4 focus:ring-blue-50"
                    />
                    {suggestionsOpen && (localProductFilter.trim() !== '' || visibleOptions.length > 0) && (
                        <div className="absolute left-0 right-0 top-full z-30 mt-2 max-h-80 overflow-y-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-xl">
                            {visibleOptions.length > 0 ? (
                                <div className="grid gap-1">
                                    {visibleOptions.map((option) => (
                                        <button
                                            key={option.value}
                                            type="button"
                                            onClick={() => toggleProduct(option)}
                                            className="grid w-full grid-cols-[auto_minmax(0,1fr)] gap-3 rounded-xl px-3 py-2 text-left transition hover:bg-blue-50"
                                        >
                                            <span className="mt-1 grid size-5 place-items-center rounded-md border border-blue-200 bg-white text-blue-700">
                                                <input
                                                    type="checkbox"
                                                    checked={selectedValueSet.has(option.value)}
                                                    readOnly
                                                    className="sr-only"
                                                    tabIndex={-1}
                                                />
                                                {selectedValueSet.has(option.value) && <Check className="size-3" />}
                                            </span>
                                            <span className="min-w-0">
                                                <span className="block break-words text-sm font-bold text-slate-950">
                                                    {option.label}
                                                </span>
                                                <span className="mt-1 flex flex-wrap gap-2 text-xs font-semibold text-slate-500">
                                                    {option.brand && <span>{option.brand}</span>}
                                                    <span>{option.quantityLabel ?? option.quantity ?? 0} adet</span>
                                                    <span>{option.amountLabel ?? '0,00 TL'}</span>
                                                </span>
                                            </span>
                                        </button>
                                    ))}
                                </div>
                            ) : (
                                <p className="px-3 py-4 text-sm font-semibold text-slate-500">
                                    Ürün bulunamadı.
                                </p>
                            )}
                        </div>
                    )}
                </div>
                <select
                    value={brandFilter || 'all'}
                    onChange={(event) => update({ brand_filter: event.target.value })}
                    className="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 outline-none transition focus:border-blue-300 focus:ring-4 focus:ring-blue-50"
                    aria-label="Marka filtresi"
                >
                    {BRAND_OPTIONS.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </select>
                <select
                    value={categoryFilter || 'all'}
                    onChange={(event) => update({ category_filter: event.target.value })}
                    className="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 outline-none transition focus:border-blue-300 focus:ring-4 focus:ring-blue-50"
                    aria-label="Kategori filtresi"
                >
                    {CATEGORY_OPTIONS.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </select>
            </div>
            {selectedProducts.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {selectedProducts.map((product) => (
                        <span
                            key={product.value}
                            className="inline-flex max-w-full items-center gap-2 rounded-full border border-blue-100 bg-blue-50 px-3 py-1.5 text-sm font-semibold text-blue-800"
                        >
                            <span className="min-w-0 break-words">{product.label}</span>
                            <button
                                type="button"
                                onClick={() => removeProduct(product.value)}
                                className="rounded-full text-blue-500 transition hover:text-blue-900"
                                aria-label={`${product.label} seçimini kaldır`}
                            >
                                <X className="size-3.5" />
                            </button>
                        </span>
                    ))}
                    <button
                        type="button"
                        onClick={clearProducts}
                        className="rounded-full px-3 py-1.5 text-sm font-bold text-slate-500 transition hover:bg-slate-100 hover:text-slate-900"
                    >
                        Tümünü temizle
                    </button>
                </div>
            )}
        </div>
    );
}

function splitProductFilter(value) {
    return String(value ?? '')
        .split(',')
        .map((item) => item.trim())
        .filter(Boolean);
}

function normalizeOptionText(value) {
    return String(value ?? '')
        .toLocaleUpperCase('tr-TR')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^A-Z0-9]+/g, ' ')
        .trim();
}
