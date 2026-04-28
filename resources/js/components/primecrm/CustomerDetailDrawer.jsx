import { Printer, X } from 'lucide-react';
import { formatCell, formatMoney, numericValue } from './format';
import { valueFrom } from './module-data';

function Field({ label, value, columnKey = label }) {
    const display = formatCell(value, { key: columnKey, label });

    return (
        <div className="grid gap-1 rounded-xl border border-slate-100 bg-slate-50 p-3">
            <span className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">{label}</span>
            <strong className="break-words text-sm font-semibold text-slate-800">{display}</strong>
        </div>
    );
}

function DisabledAction({ children }) {
    return (
        <button
            type="button"
            disabled
            className="rounded-xl border border-slate-200 bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-400"
            title="Hazırlanıyor"
        >
            {children} (Hazırlanıyor)
        </button>
    );
}

export function CustomerDetailDrawer({ item, onClose }) {
    if (!item) {
        return null;
    }

    const title = valueFrom(item, 'cariAdi') ?? valueFrom(item, 'cariKodu') ?? 'Cari detayı';
    const balance = numericValue(valueFrom(item, 'bakiye'));
    const debt = numericValue(valueFrom(item, 'borc'));
    const credit = numericValue(valueFrom(item, 'alacak'));

    return (
        <aside className="fixed inset-0 z-50 overflow-y-auto border-l border-slate-200 bg-white p-4 shadow-2xl sm:inset-y-0 sm:left-auto sm:w-full sm:max-w-2xl sm:p-6">
            <div className="flex items-start justify-between gap-4">
                <div className="min-w-0">
                    <p className="text-xs font-semibold uppercase tracking-[0.14em] text-blue-700">Cari Detay</p>
                    <h2 className="mt-1 truncate text-2xl font-semibold text-slate-950" title={String(title)}>{title}</h2>
                    <p className="mt-1 text-sm text-slate-500">{formatCell(valueFrom(item, 'cariKodu'), { key: 'cari_kodu', label: 'Cari Kodu' })}</p>
                </div>
                <button
                    type="button"
                    onClick={onClose}
                    aria-label="Cari detay panelini kapat"
                    className="rounded-full border border-slate-200 bg-white p-2 text-slate-500 shadow-sm transition hover:bg-slate-50"
                >
                    <X className="size-4" />
                </button>
            </div>

            <section className="mt-5 grid gap-3 sm:grid-cols-3">
                <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Borç</p>
                    <strong className="mt-2 block text-lg text-slate-950">{formatMoney(debt)}</strong>
                </div>
                <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Alacak</p>
                    <strong className="mt-2 block text-lg text-slate-950">{formatMoney(credit)}</strong>
                </div>
                <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Net Bakiye</p>
                    <strong className={['mt-2 block text-lg', balance < 0 ? 'text-red-700' : 'text-emerald-700'].join(' ')}>{formatMoney(balance)}</strong>
                </div>
            </section>

            <section className="mt-5 grid gap-3 sm:grid-cols-2">
                <Field label="Telefon" value={valueFrom(item, 'telefon')} />
                <Field label="E-posta" value={valueFrom(item, 'email')} />
                <Field label="Vergi No" value={item.vergi_no ?? item.vergiNo ?? item['Vergi No']} />
                <Field label="Vergi Dairesi" value={item.vergi_dairesi ?? item.vergiDairesi ?? item['Vergi Dairesi']} />
                <Field label="Grup" value={valueFrom(item, 'cariGrup')} />
                <Field label="Temsilci" value={valueFrom(item, 'temsilci')} />
                <Field label="İl / İlçe" value={[valueFrom(item, 'il'), valueFrom(item, 'ilce')].filter(Boolean).join(' / ')} />
                <Field label="Son Hareket" value={valueFrom(item, 'sonHareket')} columnKey="son_hareket_tarihi" />
                <div className="sm:col-span-2">
                    <Field label="Adres" value={item.adres ?? item.address ?? item['Adres']} />
                </div>
            </section>

            <section className="mt-5 grid gap-3 rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-4">
                <div>
                    <h3 className="font-semibold text-slate-950">Son hareketler ve evraklar</h3>
                    <p className="mt-1 text-sm leading-6 text-slate-600">
                        Detay veri kaynağı henüz tanımlı değil. Satırdaki temel cari bilgileri görüntüleniyor.
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <DisabledAction>Detay</DisabledAction>
                    <DisabledAction>Ekstre</DisabledAction>
                    <DisabledAction>Evraklar</DisabledAction>
                    <button type="button" onClick={() => window.print()} className="inline-flex items-center gap-2 rounded-xl bg-blue-700 px-4 py-2 text-sm font-semibold text-white">
                        <Printer className="size-4" />
                        Yazdır / PDF
                    </button>
                </div>
            </section>
        </aside>
    );
}
