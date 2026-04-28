function polarSegment(item, index, offset) {
    const radius = 78;
    const circumference = 2 * Math.PI * radius;
    const visiblePercentage = Math.max(Number(item.percentage || 0), item.percentage > 0 ? 1.5 : 0);
    const dash = (visiblePercentage / 100) * circumference;
    const gap = circumference - dash;

    return {
        dashArray: `${dash} ${gap}`,
        dashOffset: -((offset / 100) * circumference),
        gradientId: `sales-segment-${index}`,
    };
}

export function SalesPieChart({ chart }) {
    const items = chart?.items ?? [];
    let offset = 0;
    const segments = items
        .filter((item) => item.percentage > 0)
        .map((item, index) => {
            const segment = polarSegment(item, index, offset);
            offset += item.percentage;

            return { ...item, ...segment };
        });

    return (
        <section className="grid gap-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm lg:grid-cols-[260px_minmax(0,1fr)]">
            <div>
                <p className="text-xs font-semibold uppercase tracking-[0.14em] text-blue-700">
                    {chart?.title}
                </p>
                <h2 className="mt-2 text-lg font-semibold text-slate-950">{chart?.subtitle}</h2>
                <div className="relative mx-auto mt-5 grid aspect-square w-full max-w-[230px] place-items-center rounded-full bg-gradient-to-br from-white to-slate-100 shadow-[0_18px_45px_rgba(15,23,42,0.16)]">
                    <svg viewBox="0 0 220 220" className="size-full -rotate-90 drop-shadow-sm" role="img" aria-label={chart?.title ?? 'Satış dağılımı'}>
                        <defs>
                            {segments.map((item, index) => (
                                <linearGradient id={item.gradientId} key={item.gradientId} x1="0%" y1="0%" x2="100%" y2="100%">
                                    <stop offset="0%" stopColor={item.color} stopOpacity="0.82" />
                                    <stop offset="100%" stopColor={item.color} stopOpacity="1" />
                                </linearGradient>
                            ))}
                        </defs>
                        <circle cx="110" cy="110" r="78" fill="none" stroke="#e2e8f0" strokeWidth="28" />
                        {segments.map((item) => (
                            <circle
                                key={item.label}
                                cx="110"
                                cy="110"
                                r="78"
                                fill="none"
                                stroke={`url(#${item.gradientId})`}
                                strokeWidth="28"
                                strokeLinecap="round"
                                strokeDasharray={item.dashArray}
                                strokeDashoffset={item.dashOffset}
                                className="transition duration-300 hover:opacity-80"
                            />
                        ))}
                    </svg>
                    <div className="absolute grid size-[46%] place-items-center rounded-full border border-slate-100 bg-white/95 shadow-inner">
                        <span className="text-center text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">
                            Dağılım
                        </span>
                    </div>
                </div>
            </div>

            <div className="grid min-w-0 content-start gap-3">
                {items.map((item) => (
                    <div key={item.label} className="grid min-w-0 grid-cols-[12px_minmax(0,1fr)_auto] items-center gap-3 rounded-xl border border-slate-100 px-3 py-2 transition hover:border-blue-100 hover:bg-blue-50/40">
                        <span className="size-3 rounded-sm" style={{ backgroundColor: item.color }} />
                        <div className="min-w-0">
                            <p className="truncate font-medium text-slate-900" title={item.label}>{item.label}</p>
                            <p className="text-sm text-slate-500">{item.quantityLabel} adet</p>
                        </div>
                        <div className="min-w-[118px] text-right">
                            <p className="whitespace-nowrap font-semibold text-slate-950">{item.amountLabel}</p>
                            <p className="text-sm text-slate-500">%{item.percentage}</p>
                        </div>
                    </div>
                ))}
            </div>
        </section>
    );
}
