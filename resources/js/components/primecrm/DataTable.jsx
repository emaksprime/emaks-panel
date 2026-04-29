import { formatCell, isMoneyColumn, isNumberColumn, numericValue } from './format';

function statusTone(value) {
    const normalized = String(value ?? '').toLocaleLowerCase('tr-TR');

    if (['kapalı', 'kapandi', 'tamam', 'tamamlandı', 'iptal'].some((token) => normalized.includes(token))) {
        return 'border-slate-200 bg-slate-100 text-slate-600';
    }

    if (['bekleyen', 'açık', 'acik', 'kalan'].some((token) => normalized.includes(token))) {
        return 'border-amber-200 bg-amber-50 text-amber-700';
    }

    if (['onay', 'aktif', 'hazır'].some((token) => normalized.includes(token))) {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700';
    }

    return 'border-blue-100 bg-blue-50 text-blue-700';
}

function isStatusColumn(column) {
    const value = `${column.key} ${column.label}`.toLocaleLowerCase('tr-TR');

    return ['durum', 'status'].some((token) => value.includes(token));
}

function isBalanceColumn(column) {
    const value = `${column.key} ${column.label}`.toLocaleLowerCase('tr-TR');

    return ['bakiye', 'borç', 'borc', 'alacak', 'net'].some((token) => value.includes(token));
}

function cellTone(column, rawValue) {
    if (!isBalanceColumn(column) && !isMoneyColumn(column)) {
        return '';
    }

    const value = numericValue(rawValue);

    if (value < 0) {
        return 'text-red-700';
    }

    if (value > 0 && isBalanceColumn(column)) {
        return 'text-emerald-700';
    }

    return '';
}

function stockCategory(row) {
    return row.kategori ?? row.kategori_adi ?? row.stok_kategori_adi ?? row.sto_kategori_kodu ?? row.kategori_kodu ?? null;
}

export function DataTable({ columns, rows, onRowClick, rowActions }) {
    const tableWidth = columns.length <= 3 ? 'min-w-full' : 'min-w-[980px]';

    return (
        <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div className="overflow-x-auto">
                <table className={`${tableWidth} divide-y divide-slate-200 text-sm`}>
                    <thead className="bg-slate-50">
                        <tr>
                            {columns.map((column, index) => (
                                <th
                                    key={column.key}
                                    className={[
                                        'sticky top-0 z-10 whitespace-nowrap bg-slate-50 px-4 py-3 font-semibold text-slate-600',
                                        isNumberColumn(column) ? 'text-right' : 'text-left',
                                        index === 0 ? 'min-w-[280px]' : '',
                                    ].join(' ')}
                                >
                                    {column.label}
                                </th>
                            ))}
                            {rowActions && <th className="sticky top-0 z-10 bg-slate-50 px-4 py-3 text-right font-semibold text-slate-600">İşlem</th>}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.map((row, index) => (
                            <tr key={`${row.cari_kodu ?? row.stok_kodu ?? row.id ?? index}`} className="transition hover:bg-blue-50/40">
                                {columns.map((column, columnIndex) => {
                                    const rawValue = row[column.key];
                                    const value = formatCell(rawValue, column);
                                    const numeric = isNumberColumn(column);
                                    const status = isStatusColumn(column);
                                    const category = column.label === 'ÃœrÃ¼n / Model' || column.label === 'Ürün / Model'
                                        ? stockCategory(row)
                                        : null;

                                    return (
                                        <td
                                            key={`${column.key}-${index}`}
                                            onClick={() => onRowClick?.(row)}
                                            className={[
                                                'max-w-[340px] whitespace-nowrap px-4 py-3 text-slate-700',
                                                onRowClick ? 'cursor-pointer' : '',
                                                numeric ? 'text-right tabular-nums' : 'text-left',
                                                columnIndex <= 1 ? 'font-medium text-slate-900' : '',
                                                cellTone(column, rawValue),
                                            ].join(' ')}
                                        >
                                            {status ? (
                                                <span className={['inline-flex max-w-full rounded-full border px-2.5 py-1 text-xs font-semibold', statusTone(value)].join(' ')}>
                                                    <span className="block truncate" title={value}>{value}</span>
                                                </span>
                                            ) : (
                                                <>
                                                    <span className="block truncate" title={value}>{value}</span>
                                                    {category && (
                                                        <span className="mt-1 inline-flex max-w-full rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-500">
                                                            <span className="truncate" title={String(category)}>{String(category)}</span>
                                                        </span>
                                                    )}
                                                </>
                                            )}
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
