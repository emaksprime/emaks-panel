import { useEffect, useMemo, useRef, useState } from 'react';
import { Check, Search, X } from 'lucide-react';
import { apiRequest } from '@/lib/api';

function valueFrom(row, keys) {
    for (const key of keys) {
        const value = row?.[key];

        if (value !== undefined && value !== null && `${value}`.trim() !== '') {
            return `${value}`.trim();
        }
    }

    return '';
}

function normalizeCustomer(row) {
    const code = valueFrom(row, ['cari_kodu', 'CariKodu', 'cariKod', 'code']);
    const title = valueFrom(row, ['cari_unvani', 'cari_adi', 'CariUnvani', 'CariAdi', 'unvan', 'title']);
    const group = valueFrom(row, ['cari_grubu', 'cari_grup_adi', 'CariGrubu', 'grup']);
    const display = valueFrom(row, ['display_text', 'DisplayText']);

    return {
        code,
        title: title || code,
        group,
        display: display || [title || code, code, group].filter(Boolean).join(' | '),
    };
}

export function CustomerFilterPicker({ selected = [], onChange, loading }) {
    const [query, setQuery] = useState('');
    const [options, setOptions] = useState([]);
    const [searching, setSearching] = useState(false);
    const [open, setOpen] = useState(false);
    const [message, setMessage] = useState('');
    const wrapperRef = useRef(null);

    const selectedCodes = useMemo(() => new Set(selected.map((item) => item.code)), [selected]);

    useEffect(() => {
        const onPointerDown = (event) => {
            if (!wrapperRef.current?.contains(event.target)) {
                setOpen(false);
            }
        };

        document.addEventListener('pointerdown', onPointerDown);

        return () => document.removeEventListener('pointerdown', onPointerDown);
    }, []);

    useEffect(() => {
        const search = query.trim();

        if (search.length < 2) {
            setOptions([]);
            setMessage('');
            setSearching(false);
            return undefined;
        }

        let active = true;
        const timeout = window.setTimeout(async () => {
            try {
                setSearching(true);
                setMessage('');
                const response = await apiRequest('/api/data/sales_customer_search', {
                    method: 'POST',
                    body: JSON.stringify({ search, limit: 80, bypass_cache: true }),
                });

                if (!active) {
                    return;
                }

                const rows = Array.isArray(response?.rows) ? response.rows : [];
                const normalized = rows
                    .map(normalizeCustomer)
                    .filter((item) => item.code !== '')
                    .filter((item, index, all) => all.findIndex((candidate) => candidate.code === item.code) === index);

                setOptions(normalized);
                setOpen(true);
                setMessage(normalized.length === 0 ? 'Müşteri bulunamadı.' : '');
            } catch {
                if (active) {
                    setOptions([]);
                    setOpen(true);
                    setMessage('Müşteri araması yapılamadı.');
                }
            } finally {
                if (active) {
                    setSearching(false);
                }
            }
        }, 320);

        return () => {
            active = false;
            window.clearTimeout(timeout);
        };
    }, [query]);

    const toggleCustomer = (customer) => {
        const nextSelected = selectedCodes.has(customer.code)
            ? selected.filter((item) => item.code !== customer.code)
            : [...selected, customer];

        onChange(nextSelected);
    };

    return (
        <div ref={wrapperRef} className="relative grid gap-2">
            <label className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">
                Müşteri Filtresi
            </label>
            <div className="relative">
                <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                <input
                    type="search"
                    value={query}
                    disabled={loading}
                    onFocus={() => query.trim().length >= 2 && setOpen(true)}
                    onChange={(event) => setQuery(event.target.value)}
                    placeholder="Müşteri ara"
                    className="h-11 w-full rounded-xl border border-slate-200 bg-white pl-10 pr-3 text-sm font-medium text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-blue-300 focus:ring-4 focus:ring-blue-50"
                />
            </div>

            {selected.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {selected.map((customer) => (
                        <button
                            key={customer.code}
                            type="button"
                            onClick={() => toggleCustomer(customer)}
                            className="inline-flex max-w-full items-center gap-2 rounded-full border border-blue-100 bg-blue-50 px-3 py-1.5 text-xs font-semibold text-blue-700"
                            title={customer.display}
                        >
                            <span className="max-w-[210px] truncate">{customer.title}</span>
                            <X className="size-3" />
                        </button>
                    ))}
                    <button
                        type="button"
                        onClick={() => onChange([])}
                        className="rounded-full border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-500 hover:border-slate-300 hover:text-slate-800"
                    >
                        Temizle
                    </button>
                </div>
            )}

            {open && (options.length > 0 || message || searching) && (
                <div className="absolute left-0 right-0 top-[76px] z-30 max-h-80 overflow-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-xl">
                    {searching && <p className="px-3 py-2 text-sm font-medium text-slate-500">Aranıyor...</p>}
                    {!searching && message && <p className="px-3 py-2 text-sm font-medium text-slate-500">{message}</p>}
                    {!searching && options.map((customer) => {
                        const checked = selectedCodes.has(customer.code);

                        return (
                            <button
                                key={customer.code}
                                type="button"
                                onClick={() => toggleCustomer(customer)}
                                className="grid w-full grid-cols-[24px_minmax(0,1fr)] items-start gap-3 rounded-xl px-3 py-2 text-left transition hover:bg-blue-50"
                            >
                                <span className={[
                                    'mt-0.5 grid size-5 place-items-center rounded-md border text-white',
                                    checked ? 'border-blue-700 bg-blue-700' : 'border-slate-300 bg-white',
                                ].join(' ')}
                                >
                                    {checked && <Check className="size-3.5" />}
                                </span>
                                <span className="min-w-0">
                                    <span className="block truncate text-sm font-semibold text-slate-900" title={customer.title}>
                                        {customer.title}
                                    </span>
                                    <span className="mt-1 block truncate text-xs font-medium text-slate-500" title={[customer.code, customer.group].filter(Boolean).join(' | ')}>
                                        {[customer.code, customer.group].filter(Boolean).join(' | ')}
                                    </span>
                                </span>
                            </button>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
