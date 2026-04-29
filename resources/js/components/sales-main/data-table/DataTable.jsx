import { useState } from 'react';
import { ExpandableRows } from './ExpandableRows.jsx';

const toLabelKey = (value) => (value ?? '').toString().trim().toLowerCase();

function getColumnClass(column, index) {
    if (index === 0) {
        return 'w-auto min-w-[560px] px-4 py-3 text-left whitespace-normal';
    }

    const key = toLabelKey(column?.key);
    const label = toLabelKey(column?.label);

    if (key === 'quantity' || key === 'adet' || label === 'adet') {
        return 'w-24 min-w-[72px] px-3 py-3 text-center font-normal';
    }

    if (key === 'amount' || key === 'ciro' || key === 'net' || label === 'ciro' || label === 'net satış') {
        return 'w-40 min-w-[132px] px-3 py-3 pr-6 text-right';
    }

    return 'w-40 min-w-[120px] px-3 py-3 text-right';
}

export function DataTable({ table }) {
    const columns = table?.columns ?? [];
    const rows = table?.rows ?? [];

    return (
        <div className="rounded-xl border border-slate-200 bg-white">
            <div className="grid gap-3 p-3 md:hidden">
                {rows.map((row) => (
                    <MobileRow key={row.id ?? row.label} row={row} />
                ))}
            </div>
            <div className="hidden overflow-x-auto md:block">
                <table className="w-full min-w-[1100px] text-sm">
                    <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                        <tr>
                            {columns.map((column, index) => (
                                <th key={column.key} className={getColumnClass(column, index)}>
                                    {column.label}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        <ExpandableRows rows={rows} />
                    </tbody>
                </table>
            </div>
        </div>
    );
}

function MobileRow({ row, depth = 0 }) {
    const [open, setOpen] = useState(depth < 1);
    const hasChildren = Array.isArray(row.children) && row.children.length > 0;

    return (
        <article className="rounded-2xl border border-slate-100 bg-white p-3 shadow-sm" style={{ marginLeft: depth * 10 }}>
            <button
                type="button"
                onClick={() => hasChildren && setOpen((value) => !value)}
                className="grid w-full gap-3 text-left"
                disabled={!hasChildren}
            >
                <div className="flex items-start justify-between gap-3">
                    <h3 className="min-w-0 whitespace-normal break-words text-sm font-semibold leading-5 text-slate-950" title={row.label}>
                        {row.label}
                    </h3>
                    {hasChildren && (
                        <span className="shrink-0 rounded-full border border-slate-200 px-2 py-1 text-xs font-semibold text-slate-500">
                            {open ? 'Kapat' : 'Aç'}
                        </span>
                    )}
                </div>
                <div className="grid grid-cols-2 gap-2">
                    <span className="rounded-xl bg-slate-50 p-2">
                        <span className="block text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-400">Adet</span>
                        <span className="mt-1 block text-sm font-semibold text-slate-800">{row.quantityLabel}</span>
                    </span>
                    <span className="rounded-xl bg-blue-50 p-2 text-right">
                        <span className="block text-[10px] font-semibold uppercase tracking-[0.12em] text-blue-400">Ciro</span>
                        <span className="mt-1 block break-words text-sm font-semibold text-blue-800">{row.amountLabel}</span>
                    </span>
                </div>
            </button>
            {hasChildren && open && (
                <div className="mt-3 grid gap-2">
                    {row.children.map((child) => (
                        <MobileRow key={child.id ?? `${row.id}-${child.label}`} row={child} depth={depth + 1} />
                    ))}
                </div>
            )}
        </article>
    );
}
