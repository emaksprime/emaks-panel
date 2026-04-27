export function KpiCard({ label, value, hint }) {
    return (
        <article className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{label}</p>
            <strong className="mt-3 block text-2xl font-semibold text-slate-950">{value}</strong>
            {hint && <p className="mt-2 text-sm text-slate-500">{hint}</p>}
        </article>
    );
}
