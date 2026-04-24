export function KpiCards({ items = [] }) {
    return (
        <section className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            {items.map((item) => (
                <article key={item.label} className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                        {item.label}
                    </p>
                    <strong className="mt-3 block text-2xl font-semibold text-slate-950">
                        {item.value}
                    </strong>
                </article>
            ))}
        </section>
    );
}
