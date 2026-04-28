import { formatCell } from './format';

function isNumericColumn(column) {
    const value = `${column.key} ${column.label}`.toLocaleLowerCase('tr-TR');

    return ['miktar', 'adet', 'tutar', 'fiyat', 'toplam', 'bakiye', 'ciro', 'iskonto', 'kdv'].some((token) => value.includes(token));
}

export function DataTable({ columns, rows, onRowClick, rowActions }) {
    return (
        <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div className="overflow-x-auto">
                <table className="min-w-[920px] divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50">
                        <tr>
                            {columns.map((column, index) => (
                                <th
                                    key={column.key}
                                    className={[
                                        'whitespace-nowrap px-4 py-3 font-semibold text-slate-600',
                                        isNumericColumn(column) ? 'text-right' : 'text-left',
                                        index === 1 ? 'min-w-[260px]' : '',
                                    ].join(' ')}
                                >
                                    {column.label}
                                </th>
                            ))}
                            {rowActions && <th className="px-4 py-3 text-right font-semibold text-slate-600">Aksiyon</th>}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.map((row, index) => (
                            <tr key={`${row.cari_kodu ?? row.stok_kodu ?? row.id ?? index}`} className="transition hover:bg-slate-50">
                                {columns.map((column, columnIndex) => {
                                    const value = formatCell(row[column.key]);
                                    const numeric = isNumericColumn(column);

                                    return (
                                        <td
                                            key={`${column.key}-${index}`}
                                            onClick={() => onRowClick?.(row)}
                                            className={[
                                                'max-w-[320px] whitespace-nowrap px-4 py-3 text-slate-700',
                                                numeric ? 'text-right tabular-nums' : 'text-left',
                                                columnIndex <= 1 ? 'font-medium text-slate-900' : '',
                                            ].join(' ')}
                                        >
                                            <span className="block truncate" title={value}>{value}</span>
                                        </td>
                                    );
                                })}
                                {rowActions && <td className="px-4 py-3 text-right">{rowActions(row)}</td>}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
