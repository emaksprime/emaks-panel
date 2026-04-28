import { formatMoney } from '@/components/primecrm/format';

function polarSegment(item, index, offset) {
    const radius = 78;
    const circumference = 2 * Math.PI * radius;
    const percentage = Math.max(Number(item.percentage || 0), 0);
    const dash = (percentage / 100) * circumference;
    const gap = circumference - dash;

    return {
        dashArray: `${dash} ${gap}`,
        dashOffset: -((offset / 100) * circumference),
        gradientId: `sales-segment-${index}`,
    };
}

export function SalesPieChart({ chart }) {
    const items = chart?.items ?? [];
    const totalNet = formatMoney(chart?.totalNet ?? 0);
    let offset = 0;
    const segments = items
        .filter((item) => item.percentage > 0)
        .map((item, index) => {
            const segment = polarSegment(item, index, offset);
            offset += item.percentage;

            return { ...item, ...segment };
        });

    return (
        <section className="grid gap-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5 xl:grid-cols-[300px_minmax(0,1fr)]">
            <div className="min-w-0">
                <p className="text-xs font-semibold uppercase tracking-[0.14em] text-blue-700">
                    Satış Dağılımı
                </p>
                <h2 className="mt-2 text-lg font-semibold text-slate-950">
                    Satış gruplarının toplam ciro içindeki payları.
                </h2>
                <div className="relative mx-auto mt-5 grid aspect-square w-full max-w-[260px] place-items-center rounded-full bg-gradient-to-br from-white to-slate-100 shadow-[0_18px_45px_rgba(15,23,42,0.16)]">
                    <svg viewBox="0 0 220 220" className="size-full -rotate-90 drop-shadow-sm" role="img" aria-label="Satış dağılımı">
                        <title>Satış dağılımı</title>
                        <defs>
                            {segments.map((item) => (
                                <linearGradient id={item.gradientId} key={item.gradientId} x1="0%" y1="0%" x2="100%" y2="100%">
                                    <stop offset="0%" stopColor={item.color} stopOpacity="0.78" />
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
                            >
                                <title>{`${item.label}: ${item.amountLabel} - %${item.percentage}`}</title>
                            </circle>
                        ))}
                    </svg>
                    <div className="absolute grid size-[52%] place-items-center rounded-full border border-slate-100 bg-white/95 p-3 text-center shadow-inner">
                        <span className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-400">Toplam Ciro</span>
                        <strong className="mt-1 max-w-full text-sm font-bold leading-tight text-slate-950 sm:text-base">
                            {totalNet}
                        </strong>
                    </div>
                </div>
            </div>

            <div className="grid min-w-0 content-start gap-3 sm:grid-cols-2 2xl:grid-cols-3">
                {items.map((item) => (
                    <div key={item.label} className="grid min-w-0 grid-cols-[12px_minmax(0,1fr)] gap-3 rounded-xl border border-slate-100 px-3 py-2 transition hover:border-blue-100 hover:bg-blue-50/40">
                        <span className="mt-1 size-3 rounded-sm" style={{ backgroundColor: item.color }} />
                        <div className="grid min-w-0 gap-1">
                            <p className="truncate font-medium text-slate-900" title={item.label}>{item.label}</p>
                            <div className="grid grid-cols-[minmax(0,1fr)_auto] items-end gap-3 text-sm">
                                <span className="truncate text-slate-500" title={`${item.quantityLabel} adet`}>{item.quantityLabel} adet</span>
                                <span className="whitespace-nowrap text-right font-semibold text-slate-950">{item.amountLabel}</span>
                            </div>
                            <div className="h-1.5 overflow-hidden rounded-full bg-slate-100">
                                <div className="h-full rounded-full" style={{ width: `${Math.max(item.percentage, 2)}%`, backgroundColor: item.color }} />
                            </div>
                            <p className="text-right text-xs font-semibold text-slate-500">%{item.percentage}</p>
                        </div>
                    </div>
                ))}
            </div>
        </section>
    );
}
