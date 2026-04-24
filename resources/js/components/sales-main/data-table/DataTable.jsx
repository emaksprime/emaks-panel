import { ExpandableRows } from './ExpandableRows.jsx';

export function DataTable({ table }) {
    const columns = table?.columns ?? [];

    return (
        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <table className="w-full min-w-[680px] text-sm">
                <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                    <tr>
                        {columns.map((column, index) => (
                            <th key={column.key} className={`px-4 py-3 ${index === 0 ? 'text-left' : 'text-right'}`}>
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
    );
}
