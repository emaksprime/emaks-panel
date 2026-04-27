import { formatCell } from './format';

export function DataTable({ columns, rows, onRowClick, rowActions }) {
    return (
        <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50">
                        <tr>
                            {columns.map((column) => (
                                <th key={column.key} className="whitespace-nowrap px-4 py-3 text-left font-semibold text-slate-600">
                                    {column.label}
                                </th>
                            ))}
                            {rowActions && <th className="px-4 py-3 text-right font-semibold text-slate-600">Aksiyon</th>}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.map((row, index) => (
                            <tr key={`${row.cari_kodu ?? row.stok_kodu ?? row.id ?? index}`} className="transition hover:bg-slate-50">
                                {columns.map((column) => (
                                    <td
                                        key={`${column.key}-${index}`}
                                        onClick={() => onRowClick?.(row)}
                                        className="whitespace-nowrap px-4 py-3 text-slate-700"
                                    >
                                        {formatCell(row[column.key])}
                                    </td>
                                ))}
                                {rowActions && <td className="px-4 py-3 text-right">{rowActions(row)}</td>}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
