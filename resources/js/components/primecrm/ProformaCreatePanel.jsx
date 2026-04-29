import { Printer, Save, Search } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { apiRequest } from '@/lib/api';
import { formatMoney, numericValue } from './format';

const customerAliases = {
    musteri_kodu: ['musteri_kodu', 'cari_kodu', 'cari_kod', 'code'],
    musteri_adi: ['musteri_adi', 'cari_adi', 'cari_unvan1', 'unvan', 'title'],
    grup: ['grup', 'cari_grup', 'cari_grup_adi', 'cari_grup_kodu'],
    telefon: ['telefon', 'tel', 'cari_CepTel', 'phone'],
    email: ['email', 'cari_EMail', 'mail'],
    il: ['il', 'cari_il', 'city'],
    ilce: ['ilce', 'cari_ilce', 'district'],
    vergi_no: ['vergi_no', 'cari_VergiKimlikNo', 'tax_no'],
    vergi_dairesi: ['vergi_dairesi', 'cari_vdaire_adi', 'tax_office'],
    bakiye: ['bakiye', 'net_bakiye', 'balance'],
};

const stockAliases = {
    stok_kodu: ['stok_kodu', 'sto_kod'],
    urun_adi: ['urun_adi', 'stok_adi', 'sto_isim', 'model_adi'],
    kategori: ['kategori', 'kategori_adi', 'stok_kategori_adi', 'sto_kategori_kodu'],
    miktar: ['miktar', 'toplam_miktar', 'quantity'],
    fiyat: ['fiyat', 'birim_fiyat', 'satis_fiyati', 'price', 'unit_price'],
};

function pick(row, aliases) {
    const found = aliases.find((key) => Object.prototype.hasOwnProperty.call(row ?? {}, key));

    return found ? row[found] : '';
}

function normalizeCustomer(row) {
    return Object.fromEntries(Object.entries(customerAliases).map(([key, aliases]) => [key, pick(row, aliases)]));
}

function normalizeLine(row) {
    return {
        stok_kodu: pick(row, stockAliases.stok_kodu),
        urun_adi: pick(row, stockAliases.urun_adi),
        kategori: pick(row, stockAliases.kategori),
        quantity: numericValue(pick(row, stockAliases.miktar)) || 1,
        unit_price: numericValue(pick(row, stockAliases.fiyat)),
        vat_rate: numericValue(row.vat_rate ?? row.kdv_orani ?? row.kdv),
        discounts: Array.isArray(row.discounts) ? row.discounts : [0, 0, 0],
    };
}

function applyDiscounts(gross, discounts) {
    return discounts.reduce((net, discount) => {
        const rate = Math.max(0, Math.min(100, numericValue(discount)));

        return net - (net * rate) / 100;
    }, gross);
}

function lineTotals(line) {
    const gross = numericValue(line.quantity) * numericValue(line.unit_price);
    const net = applyDiscounts(gross, line.discounts ?? []);
    const vat = net * (numericValue(line.vat_rate) / 100);

    return {
        gross,
        discount: gross - net,
        vat,
        total: net + vat,
    };
}

export function ProformaCreatePanel({ cartItems = [], setCartItems }) {
    const [search, setSearch] = useState('');
    const [customerResults, setCustomerResults] = useState([]);
    const [customer, setCustomer] = useState(null);
    const [lines, setLines] = useState([]);
    const [message, setMessage] = useState('');
    const [searching, setSearching] = useState(false);
    const [priceInfo, setPriceInfo] = useState(null);
    const [discountInfo, setDiscountInfo] = useState(null);
    const [note, setNote] = useState('');

    useEffect(() => {
        if (cartItems.length > 0) {
            setLines(cartItems.map(normalizeLine));
            return;
        }

        try {
            const stored = window.localStorage.getItem('emaks_proforma_cart');
            const parsed = stored ? JSON.parse(stored) : [];

            if (Array.isArray(parsed)) {
                setLines(parsed.map(normalizeLine));
            }
        } catch {
            setLines([]);
        }
    }, [cartItems]);

    useEffect(() => {
        const term = search.trim();

        if (term.length < 2) {
            setCustomerResults([]);
            return;
        }

        let isCurrent = true;
        const timer = window.setTimeout(() => {
            setSearching(true);
            void apiRequest('/api/data/proforma_customer_search', {
                method: 'POST',
                body: JSON.stringify({ search: term, bypass_cache: false }),
            })
                .then((response) => {
                    if (isCurrent) {
                        setCustomerResults(Array.isArray(response.rows) ? response.rows : []);
                    }
                })
                .catch(() => {
                    if (isCurrent) {
                        setCustomerResults([]);
                        setMessage('Müşteri arama veri kaynağı çalıştırılamadı.');
                    }
                })
                .finally(() => {
                    if (isCurrent) {
                        setSearching(false);
                    }
                });
        }, 300);

        return () => {
            isCurrent = false;
            window.clearTimeout(timer);
        };
    }, [search]);

    const totals = useMemo(() => lines.reduce((sum, line) => {
        const current = lineTotals(line);

        return {
            gross: sum.gross + current.gross,
            discount: sum.discount + current.discount,
            vat: sum.vat + current.vat,
            total: sum.total + current.total,
        };
    }, { gross: 0, discount: 0, vat: 0, total: 0 }), [lines]);

    const selectCustomer = (row) => {
        const normalized = normalizeCustomer(row);
        setCustomer(normalized);
        setSearch(String(normalized.musteri_adi || normalized.musteri_kodu || ''));
        setCustomerResults([]);
        setMessage('');

        if (!normalized.musteri_kodu) {
            return;
        }

        void apiRequest('/api/data/proforma_price_list', {
            method: 'POST',
            body: JSON.stringify({ customer_code: normalized.musteri_kodu, bypass_cache: false }),
        })
            .then((response) => setPriceInfo(Array.isArray(response.rows) ? response.rows[0] ?? null : null))
            .catch(() => setPriceInfo(null));

        void apiRequest('/api/data/proforma_discount_defs', {
            method: 'POST',
            body: JSON.stringify({ discount_code: normalized.musteri_kodu, bypass_cache: false }),
        })
            .then((response) => setDiscountInfo(Array.isArray(response.rows) ? response.rows[0] ?? null : null))
            .catch(() => setDiscountInfo(null));
    };

    const updateLine = (index, patch) => {
        setLines((current) => current.map((line, lineIndex) => (lineIndex === index ? { ...line, ...patch } : line)));
    };

    const updateDiscount = (lineIndex, discountIndex, value) => {
        setLines((current) => current.map((line, currentIndex) => {
            if (currentIndex !== lineIndex) {
                return line;
            }

            const discounts = [...(line.discounts ?? [])];
            discounts[discountIndex] = value;

            return { ...line, discounts };
        }));
    };

    const addDiscount = (lineIndex) => {
        setLines((current) => current.map((line, currentIndex) => (
            currentIndex === lineIndex ? { ...line, discounts: [...(line.discounts ?? []), 0] } : line
        )));
    };

    const saveDraft = () => {
        const draft = {
            customer,
            lines,
            note,
            totals,
            saved_at: new Date().toISOString(),
        };

        window.localStorage.setItem('emaks_proforma_draft', JSON.stringify(draft));
        window.localStorage.setItem('emaks_proforma_cart', JSON.stringify(lines));
        setCartItems?.(lines);
        setMessage('Taslak tarayıcıda kaydedildi.');
    };

    return (
        <section className="grid gap-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div className="grid gap-5 lg:grid-cols-[1fr_320px]">
                <div className="grid gap-4">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-blue-700">Proforma Oluştur</p>
                        <h3 className="mt-2 text-xl font-bold text-slate-950">Müşteri ve ürün satırları</h3>
                        <p className="mt-1 text-sm text-slate-600">Müşteri arayın, stoktan gelen satırları kontrol edin ve taslağı tarayıcıda kaydedin.</p>
                    </div>

                    <label className="grid gap-1 text-sm font-semibold text-slate-700">
                        Müşteri Arama
                        <span className="relative">
                            <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                            <input
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder="Müşteri kodu veya müşteri adı ara"
                                className="h-11 w-full rounded-xl border border-slate-200 bg-white pl-9 pr-3 font-normal text-slate-900 outline-none transition focus:border-blue-400 focus:ring-4 focus:ring-blue-50"
                            />
                        </span>
                    </label>

                    {searching && <p className="text-sm text-slate-500">Müşteri aranıyor...</p>}
                    {customerResults.length > 0 && (
                        <div className="grid gap-2 rounded-2xl border border-slate-200 bg-slate-50 p-3">
                            {customerResults.map((row, index) => {
                                const normalized = normalizeCustomer(row);
                                const title = normalized.musteri_adi || normalized.musteri_kodu || 'Bilgi yok';

                                return (
                                    <button
                                        key={`${normalized.musteri_kodu || title}-${index}`}
                                        type="button"
                                        onClick={() => selectCustomer(row)}
                                        className="rounded-xl bg-white p-3 text-left shadow-sm transition hover:bg-blue-50"
                                    >
                                        <strong className="block text-sm text-slate-950">{title}</strong>
                                        <span className="text-xs text-slate-500">{[normalized.musteri_kodu, normalized.grup].filter(Boolean).join(' - ') || 'Bilgi yok'}</span>
                                    </button>
                                );
                            })}
                        </div>
                    )}

                    {customer && (
                        <div className="grid gap-3 rounded-2xl border border-blue-100 bg-blue-50 p-4 sm:grid-cols-2">
                            {[
                                ['Müşteri Kodu', customer.musteri_kodu],
                                ['Müşteri Adı', customer.musteri_adi],
                                ['Grup', customer.grup],
                                ['Telefon', customer.telefon],
                                ['E-posta', customer.email],
                                ['İl / İlçe', [customer.il, customer.ilce].filter(Boolean).join(' / ')],
                                ['Vergi No', customer.vergi_no],
                                ['Vergi Dairesi', customer.vergi_dairesi],
                                ['Bakiye', customer.bakiye ? formatMoney(customer.bakiye) : 'Bilgi yok'],
                            ].map(([label, value]) => (
                                <div key={label}>
                                    <p className="text-xs font-semibold uppercase tracking-[0.12em] text-blue-700">{label}</p>
                                    <p className="mt-1 text-sm font-semibold text-slate-900">{value || 'Bilgi yok'}</p>
                                </div>
                            ))}
                        </div>
                    )}

                    {priceInfo && (
                        <p className="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600">
                            Fiyat listesi: {priceInfo.fiyat_liste_adi || priceInfo.fiyat_liste_no || 'Bilgi yok'}
                        </p>
                    )}
                    {discountInfo && (
                        <p className="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600">
                            İskonto tanımı alındı. İsterseniz satırlara manuel yüzde iskonto ekleyebilirsiniz.
                        </p>
                    )}
                </div>

                <div className="rounded-2xl border border-blue-100 bg-blue-50 p-5">
                    <p className="text-xs font-semibold uppercase tracking-[0.18em] text-blue-700">Toplamlar</p>
                    <dl className="mt-4 grid gap-3 text-sm">
                        <div className="flex justify-between"><dt>Ara Toplam</dt><dd>{formatMoney(totals.gross)}</dd></div>
                        <div className="flex justify-between"><dt>Toplam İskonto</dt><dd>{formatMoney(totals.discount)}</dd></div>
                        <div className="flex justify-between"><dt>KDV</dt><dd>{formatMoney(totals.vat)}</dd></div>
                        <div className="flex justify-between border-t border-blue-200 pt-3 text-base font-bold"><dt>Genel Toplam</dt><dd>{formatMoney(totals.total)}</dd></div>
                    </dl>
                    <div className="mt-5 grid gap-2">
                        <button type="button" onClick={saveDraft} className="inline-flex items-center justify-center gap-2 rounded-xl bg-blue-700 px-4 py-3 text-sm font-semibold text-white transition hover:bg-blue-800">
                            <Save className="size-4" />
                            Taslak Kaydet
                        </button>
                        <button type="button" onClick={() => window.print()} className="inline-flex items-center justify-center gap-2 rounded-xl border border-blue-200 bg-white px-4 py-3 text-sm font-semibold text-blue-700 transition hover:bg-blue-50">
                            <Printer className="size-4" />
                            Yazdır
                        </button>
                    </div>
                </div>
            </div>

            {message && <p className="rounded-xl border border-emerald-100 bg-emerald-50 p-3 text-sm font-semibold text-emerald-700">{message}</p>}

            <div className="grid gap-3">
                {lines.length === 0 ? (
                    <p className="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">Seçili ürün bulunamadı. Stok ekranından ürün ekleyebilirsiniz.</p>
                ) : lines.map((line, index) => {
                    const totalsForLine = lineTotals(line);

                    return (
                        <article key={`${line.stok_kodu || line.urun_adi}-${index}`} className="grid gap-3 rounded-2xl border border-slate-200 p-4">
                            <div className="grid gap-2 lg:grid-cols-[1fr_140px_160px_140px] lg:items-end">
                                <div className="min-w-0">
                                    <strong className="block truncate text-slate-950" title={String(line.urun_adi || line.stok_kodu)}>
                                        {line.urun_adi || line.stok_kodu || 'Ürün bilgisi yok'}
                                    </strong>
                                    <p className="mt-1 truncate text-xs text-slate-500" title={String(line.stok_kodu || '')}>{line.stok_kodu || 'Stok kodu yok'}</p>
                                    {line.kategori && <span className="mt-2 inline-flex rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-500">{line.kategori}</span>}
                                </div>
                                <label className="grid gap-1 text-xs font-semibold text-slate-500">
                                    Miktar
                                    <input type="number" min="0" step="0.01" value={line.quantity} onChange={(event) => updateLine(index, { quantity: Number(event.target.value) })} className="rounded-xl border border-slate-200 px-3 py-2 text-sm font-normal text-slate-900" />
                                </label>
                                <label className="grid gap-1 text-xs font-semibold text-slate-500">
                                    Birim Fiyat
                                    <input type="number" min="0" step="0.01" value={line.unit_price} onChange={(event) => updateLine(index, { unit_price: Number(event.target.value) })} className="rounded-xl border border-slate-200 px-3 py-2 text-sm font-normal text-slate-900" />
                                </label>
                                <div className="text-right">
                                    <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">Satır Toplamı</p>
                                    <strong className="text-sm text-slate-950">{formatMoney(totalsForLine.total)}</strong>
                                </div>
                            </div>

                            <div className="grid gap-2 md:grid-cols-4">
                                {(line.discounts ?? []).map((discount, discountIndex) => (
                                    <label key={discountIndex} className="grid gap-1 text-xs font-semibold text-slate-500">
                                        İskonto {discountIndex + 1} %
                                        <input type="number" min="0" max="100" step="0.01" value={discount} onChange={(event) => updateDiscount(index, discountIndex, Number(event.target.value))} className="rounded-xl border border-slate-200 px-3 py-2 text-sm font-normal text-slate-900" />
                                    </label>
                                ))}
                                <button type="button" onClick={() => addDiscount(index)} className="self-end rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">
                                    Ek İskonto Ekle
                                </button>
                            </div>
                        </article>
                    );
                })}
            </div>
        </section>
    );
}
