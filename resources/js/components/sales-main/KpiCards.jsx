export function KpiCards({ items = [] }) {
    return (
        <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            {items.map((item, index) => (
                <article key={item.label} className="relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <span className="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-blue-700 via-sky-500 to-cyan-400" style={{ opacity: index === 0 ? 1 : 0.55 }} />
                    <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                        {item.label}
                    </p>
                    <strong className="mt-3 block break-words text-xl font-semibold leading-tight text-slate-950 sm:text-2xl" title={String(item.value)}>
                        {item.value}
                    </strong>
                </article>
            ))}
        </section>
    );
}
