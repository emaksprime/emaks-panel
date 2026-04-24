import { DataTable } from './data-table/DataTable.jsx';

export function SalesBreakdown({ breakdown, table }) {
    return (
        <section className="grid gap-3">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                        Kirilim
                    </p>
                    <h2 className="mt-1 text-lg font-semibold text-slate-950">{breakdown?.title}</h2>
                </div>
                <span className="rounded-md border border-slate-200 bg-white px-3 py-1 text-sm font-medium text-slate-600">
                    {breakdown?.mode === 'urun' ? 'Urun detay' : 'Cari detay'}
                </span>
            </div>
            <DataTable table={table} />
        </section>
    );
}
