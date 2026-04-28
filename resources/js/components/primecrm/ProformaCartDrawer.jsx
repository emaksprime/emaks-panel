import { Link } from '@inertiajs/react';
import { Trash2, X } from 'lucide-react';
import { formatMoney } from './format';

export function ProformaCartDrawer({ open, items, setItems, onClose }) {
    if (!open) {
        return null;
    }

    const total = items.reduce((sum, item) => sum + Number(item.quantity || 1) * Number(item.unit_price || 0) * (1 - Number(item.discount || 0) / 100), 0);

    const update = (index, patch) => {
        setItems((current) => current.map((item, itemIndex) => (itemIndex === index ? { ...item, ...patch } : item)));
    };

    const remove = (index) => {
        setItems((current) => current.filter((_, itemIndex) => itemIndex !== index));
    };

    return (
        <aside className="fixed inset-y-0 right-0 z-50 w-full max-w-lg overflow-y-auto border-l border-slate-200 bg-white p-6 shadow-2xl">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-[0.14em] text-blue-700">Proforma Sepeti</p>
                    <h2 className="mt-1 text-xl font-semibold text-slate-950">{items.length} ürün seçildi</h2>
                    <p className="mt-1 text-sm text-slate-500">Stoktan eklenen ürünler proforma taslağına aktarılır.</p>
                </div>
                <button type="button" onClick={onClose} className="rounded-full border border-slate-200 p-2 text-slate-500 transition hover:bg-slate-50">
                    <X className="size-4" />
                </button>
            </div>

            <div className="mt-5 grid gap-3">
                {items.map((item, index) => (
                    <article key={`${item.stok_kodu}-${index}`} className="rounded-2xl border border-slate-200 p-4">
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <strong className="block truncate text-slate-950" title={String(item.urun_adi || item.stok_adi || item.model || item.stok_kodu)}>
                                    {item.urun_adi || item.stok_adi || item.model || item.stok_kodu}
                                </strong>
                                <p className="mt-1 truncate text-xs text-slate-500" title={String(item.stok_kodu)}>{item.stok_kodu}</p>
                            </div>
                            <button type="button" onClick={() => remove(index)} className="rounded-full border border-red-100 p-2 text-red-600 transition hover:bg-red-50">
                                <Trash2 className="size-4" />
                            </button>
                        </div>
                        <div className="mt-3 grid grid-cols-3 gap-2">
                            <label className="grid gap-1 text-xs font-semibold text-slate-500">
                                Miktar
                                <input type="number" min="1" value={item.quantity} onChange={(event) => update(index, { quantity: Number(event.target.value) })} className="rounded-xl border border-slate-200 px-3 py-2 text-sm font-normal text-slate-900" />
                            </label>
                            <label className="grid gap-1 text-xs font-semibold text-slate-500">
                                Fiyat
                                <input type="number" min="0" value={item.unit_price} onChange={(event) => update(index, { unit_price: Number(event.target.value) })} className="rounded-xl border border-slate-200 px-3 py-2 text-sm font-normal text-slate-900" />
                            </label>
                            <label className="grid gap-1 text-xs font-semibold text-slate-500">
                                İskonto
                                <input type="number" min="0" max="100" value={item.discount} onChange={(event) => update(index, { discount: Number(event.target.value) })} className="rounded-xl border border-slate-200 px-3 py-2 text-sm font-normal text-slate-900" />
                            </label>
                        </div>
                    </article>
                ))}
            </div>

            <div className="sticky bottom-0 mt-6 grid gap-3 border-t border-slate-200 bg-white pt-4">
                <div className="flex items-center justify-between">
                    <span className="text-sm font-semibold text-slate-500">Ara toplam</span>
                    <strong className="text-xl text-slate-950">{formatMoney(total)}</strong>
                </div>
                <Link href="/proforma/create" className="rounded-xl bg-blue-700 px-4 py-3 text-center text-sm font-semibold text-white transition hover:bg-blue-800">
                    Proforma Oluştur
                </Link>
            </div>
        </aside>
    );
}
