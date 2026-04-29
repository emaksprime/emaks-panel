import { DataTable } from './data-table/DataTable.jsx';

export function SalesBreakdown({ breakdown, table }) {
    const modeLabel = breakdown?.mode === 'urun' ? 'Ürün Satış Detayı' : 'Müşteri Satış Detayı';

    return (
        <section className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-[0.14em] text-blue-700">
                        Satış Detayı
                    </p>
                    <h2 className="mt-1 text-lg font-semibold text-slate-950">Satış Detayı</h2>
                </div>
                <span className="rounded-full border border-blue-100 bg-blue-50 px-3 py-1 text-sm font-semibold text-blue-700">
                    {modeLabel}
                </span>
            </div>
            <DataTable table={table} />
        </section>
    );
}
