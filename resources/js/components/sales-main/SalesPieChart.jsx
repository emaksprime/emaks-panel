export function SalesPieChart({ chart }) {
    const items = chart?.items ?? [];
    let offset = 0;
    const segments = items
        .filter((item) => item.percentage > 0)
        .map((item) => {
            const segment = `${item.color} ${offset}% ${offset + item.percentage}%`;
            offset += item.percentage;
            return segment;
        })
        .join(', ');

    return (
        <section className="grid gap-5 rounded-lg border border-slate-200 bg-white p-5 shadow-sm lg:grid-cols-[260px_1fr]">
            <div>
                <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                    {chart?.title}
                </p>
                <h2 className="mt-2 text-lg font-semibold text-slate-950">{chart?.subtitle}</h2>
                <div
                    className="mx-auto mt-5 grid aspect-square w-full max-w-[220px] place-items-center rounded-full"
                    style={{ background: `conic-gradient(${segments || '#e2e8f0 0% 100%'})` }}
                >
                    <div className="grid size-[58%] place-items-center rounded-full bg-white text-center shadow-inner">
                        <span className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                            Net
                        </span>
                    </div>
                </div>
            </div>

            <div className="grid content-start gap-3">
                {items.map((item) => (
                    <div key={item.label} className="grid grid-cols-[12px_1fr_auto] items-center gap-3">
                        <span className="size-3 rounded-sm" style={{ backgroundColor: item.color }} />
                        <div>
                            <p className="font-medium text-slate-900">{item.label}</p>
                            <p className="text-sm text-slate-500">{item.quantityLabel} adet</p>
                        </div>
                        <div className="text-right">
                            <p className="font-semibold text-slate-950">{item.amountLabel}</p>
                            <p className="text-sm text-slate-500">%{item.percentage}</p>
                        </div>
                    </div>
                ))}
            </div>
        </section>
    );
}
