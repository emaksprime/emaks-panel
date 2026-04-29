import { useState } from 'react';
import { ChevronDown, ChevronRight } from 'lucide-react';

function Row({ row, depth = 0 }) {
    const [open, setOpen] = useState(depth < 1);
    const hasChildren = Array.isArray(row.children) && row.children.length > 0;

    return (
        <>
            <tr className="border-b border-slate-100 transition hover:bg-slate-50">
                <td className="min-w-[320px] py-3 pr-3 pl-4">
                    <div className="flex items-center gap-2" style={{ paddingLeft: depth * 18 }}>
                        {hasChildren ? (
                            <button
                                type="button"
                                onClick={() => setOpen((value) => !value)}
                                className="grid size-6 place-items-center rounded-md border border-slate-200 text-slate-600"
                            >
                                {open ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
                            </button>
                        ) : (
                            <span className="size-6" />
                        )}
                        <span className="max-w-[420px] truncate font-medium text-slate-900" title={row.label}>{row.label}</span>
                    </div>
                </td>
                <td className="w-24 min-w-[72px] px-3 py-3 text-center whitespace-nowrap text-slate-600">{row.quantityLabel}</td>
                <td className="w-40 min-w-[132px] px-3 py-3 pr-6 whitespace-nowrap text-right font-semibold text-slate-950">{row.amountLabel}</td>
            </tr>
            {hasChildren && open && row.children.map((child) => (
                <Row key={child.id ?? `${row.id}-${child.label}`} row={child} depth={depth + 1} />
            ))}
        </>
    );
}

export function ExpandableRows({ rows = [] }) {
    return rows.map((row) => <Row key={row.id ?? row.label} row={row} />);
}
