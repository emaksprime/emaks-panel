export function KpiCard({ label, value, hint }) {
    return (
        <article className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{label}</p>
            <strong className="mt-3 block break-words text-xl font-semibold leading-tight text-slate-950 sm:text-2xl" title={String(value)}>{value}</strong>
            {hint && <p className="mt-2 truncate text-sm text-slate-500" title={String(hint)}>{hint}</p>}
        </article>
    );
}
