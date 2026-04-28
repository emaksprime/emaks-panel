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
        <section className="grid gap-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm lg:grid-cols-[250px_minmax(0,1fr)]">
            <div>
                <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                    {chart?.title}
                </p>
                <h2 className="mt-2 text-lg font-semibold text-slate-950">{chart?.subtitle}</h2>
                <div
                    className="mx-auto mt-5 grid aspect-square w-full max-w-[220px] place-items-center rounded-full"
                    style={{ background: `conic-gradient(${segments || '#e2e8f0 0% 100%'})` }}
                >
                    <div className="size-[58%] rounded-full bg-white shadow-inner" />
                </div>
            </div>

            <div className="grid min-w-0 content-start gap-3">
                {items.map((item) => (
                    <div key={item.label} className="grid min-w-0 grid-cols-[12px_minmax(0,1fr)_auto] items-center gap-3 rounded-xl border border-slate-100 px-3 py-2">
                        <span className="size-3 rounded-sm" style={{ backgroundColor: item.color }} />
                        <div className="min-w-0">
                            <p className="truncate font-medium text-slate-900" title={item.label}>{item.label}</p>
                            <p className="text-sm text-slate-500">{item.quantityLabel} adet</p>
                        </div>
                        <div className="min-w-[112px] text-right">
                            <p className="whitespace-nowrap font-semibold text-slate-950">{item.amountLabel}</p>
                            <p className="text-sm text-slate-500">%{item.percentage}</p>
                        </div>
                    </div>
                ))}
            </div>
        </section>
    );
}
