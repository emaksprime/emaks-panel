import { ExpandableRows } from './ExpandableRows.jsx';

export function DataTable({ table }) {
    const columns = (table?.columns ?? [])
        .filter((column) => ['label', 'quantity', 'amount'].includes(column.key))
        .map((column) => ({
            ...column,
            label: {
                label: 'Başlık',
                quantity: 'Adet',
                amount: 'Ciro',
            }[column.key] ?? column.label,
        }));

    return (
        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <div className="overflow-x-auto">
                <table className="w-full min-w-[560px] text-sm">
                    <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                        <tr>
                            {columns.map((column, index) => (
                                <th key={column.key} className={`whitespace-nowrap px-4 py-3 ${index === 0 ? 'text-left' : 'text-right'}`}>
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
