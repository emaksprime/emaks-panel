import { ExpandableRows } from './ExpandableRows.jsx';

const toLabelKey = (value) => (value ?? '').toString().trim().toLowerCase();

function getColumnClass(column, index) {
    if (index === 0) {
        return 'whitespace-nowrap px-4 py-3 text-left min-w-0';
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

    return (
        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <div className="overflow-x-auto">
                <table className="w-full min-w-[760px] text-sm">
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
                        <ExpandableRows rows={table?.rows ?? []} />
                    </tbody>
                </table>
            </div>
        </div>
    );
}
