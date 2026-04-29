import { HighlightedAccountLabel } from './HighlightedAccountLabel.jsx';

function polarSegment(item, index, offset) {
    const radius = 82;
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
    const totalNetRaw = (chart?.totalNetLabel ?? '').toString().trim();
    const amountText = totalNetRaw ? totalNetRaw.replace(/\s*TL$/i, '').trim() : '-';
    const currencyText = totalNetRaw ? '₺' : '';

    let offset = 0;
    const segments = items
        .filter((item) => item.percentage > 0)
        .map((item, index) => {
            const segment = polarSegment(item, index, offset);
            offset += item.percentage;

            return { ...item, ...segment };
        });

    return (
        <section className="grid gap-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5 lg:grid-cols-[300px_minmax(0,1fr)]">
            <div>
                <p className="text-xs font-semibold uppercase tracking-[0.14em] text-blue-700">
                    SATIŞ DAĞILIMI
                </p>
                <h2 className="mt-2 text-lg font-semibold text-slate-950">
                    {chart?.title || 'Satış Dağılımı'}
                </h2>
                <div className="relative mx-auto mt-5 grid aspect-square w-full max-w-[280px] place-items-center rounded-full bg-gradient-to-br from-white to-slate-100 shadow-[0_18px_45px_rgba(15,23,42,0.16)]">
                    <svg viewBox="0 0 220 220" className="size-full -rotate-90 drop-shadow-sm" role="img" aria-label={chart?.title ?? 'Satış Dağılımı'}>
                        <title>{chart?.title ?? 'Satış Dağılımı'}</title>
                        <defs>
                            {segments.map((item, index) => (
                                <linearGradient id={item.gradientId} key={item.gradientId} x1="0%" y1="0%" x2="100%" y2="100%">
                                    <stop offset="0%" stopColor={item.color} stopOpacity="0.78" />
                                    <stop offset="100%" stopColor={item.color} stopOpacity="1" />
                                </linearGradient>
                            ))}
                        </defs>
                        <circle cx="110" cy="110" r="82" fill="none" stroke="#e2e8f0" strokeWidth="28" />
                        {segments.map((item) => (
                            <circle
                                key={item.customerCode ?? item.label}
                                cx="110"
                                cy="110"
                                r="82"
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
                    <div className="absolute left-1/2 top-1/2 grid size-[58%] -translate-x-1/2 -translate-y-1/2 place-items-center overflow-hidden rounded-full border border-slate-100 bg-white/95 text-center shadow-inner">
                        <div className="grid min-w-0 place-items-center gap-1 px-2 leading-tight">
                            <p className="text-[9px] font-semibold uppercase tracking-[0.05em] text-slate-400 sm:text-[10px]">
                                Toplam Ciro
                            </p>
                            <p
                                className="mt-0.5 w-full break-words text-center text-sm font-bold leading-tight text-slate-950 sm:text-base"
                                title={totalNetRaw || '-'}
                            >
                                {amountText}
                            </p>
                            {currencyText ? <p className="text-xs font-semibold text-slate-500">{currencyText}</p> : null}
                        </div>
                    </div>
                </div>
            </div>

            <div className="grid min-w-0 content-start gap-3 lg:grid-cols-2">
                {items.map((item) => (
                    <div key={item.customerCode ?? item.label} className="grid min-w-0 grid-cols-[12px_minmax(0,1fr)_auto] items-start gap-3 rounded-xl border border-slate-100 px-3 py-2 transition hover:border-blue-100 hover:bg-blue-50/40">
                        <span className="size-3 rounded-sm" style={{ backgroundColor: item.color }} />
                        <div className="min-w-0">
                            <p className="whitespace-normal break-words font-medium leading-5 text-slate-900" title={item.label}>
                                <HighlightedAccountLabel value={item.label} />
                            </p>
                            {(item.customerCode || item.groupLabel) && (
                                <p className="mt-1 whitespace-normal break-words text-xs font-medium text-slate-500">
                                    {[item.customerCode, item.groupLabel].filter(Boolean).join(' · ')}
                                </p>
                            )}
                            <p className="text-sm text-slate-500">{item.quantityLabel} adet</p>
                        </div>
                        <div className="min-w-[118px] text-right">
                            <p className="whitespace-nowrap font-semibold text-slate-950">{item.amountLabel}</p>
                            <p className="text-sm text-slate-500">%{item.percentage}</p>
                            {item.excludedFromTotal && (
                                <p className="text-xs font-semibold text-amber-600">Toplam dışı</p>
                            )}
                        </div>
                    </div>
                ))}
            </div>
        </section>
    );
}
