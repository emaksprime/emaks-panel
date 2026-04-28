import { X } from 'lucide-react';
import { formatCell } from './format';

function labelFor(key) {
    return String(key)
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toLocaleUpperCase('tr-TR'));
}

export function DetailDrawer({ title, item, onClose, actions }) {
    if (!item) {
        return null;
    }

    return (
        <aside className="fixed inset-y-0 right-0 z-50 w-full max-w-xl overflow-y-auto border-l border-slate-200 bg-white p-6 shadow-2xl">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Detay</p>
                    <h2 className="mt-1 text-xl font-semibold text-slate-950">{title}</h2>
                </div>
                <button type="button" onClick={onClose} className="rounded-full border border-slate-200 p-2 text-slate-500">
                    <X className="size-4" />
                </button>
            </div>
            <div className="mt-5 grid gap-2">
                {Object.entries(item).slice(0, 24).map(([key, value]) => (
                    <div key={key} className="grid gap-1 rounded-xl border border-slate-100 bg-slate-50 p-3">
                        <span className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">{labelFor(key)}</span>
                        <strong className="break-words text-sm font-semibold text-slate-800">{formatCell(value, { key, label: labelFor(key) })}</strong>
                    </div>
                ))}
            </div>
            {actions && <div className="sticky bottom-0 mt-5 grid gap-2 bg-white pt-4">{actions}</div>}
        </aside>
    );
}
