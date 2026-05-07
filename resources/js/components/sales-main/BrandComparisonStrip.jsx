export function BrandComparisonStrip({ comparison }) {
    const items = comparison?.items ?? [];

    if (items.length === 0) {
        return null;
    }

    return (
        <section className="grid gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-[0.14em] text-blue-700">
                        Marka Kırılımı
                    </p>
                    <h2 className="mt-1 text-lg font-semibold text-slate-950">
                        {comparison?.title ?? 'Marka Karşılaştırması'}
                    </h2>
                </div>
                <p className="max-w-2xl text-sm font-medium leading-6 text-slate-500">
                    Ürün satışlarının marka bazlı toplamı. Pasta grafik aşağıda ürün/model dağılımını gösterir.
                </p>
            </div>

            <div className="grid gap-3 md:grid-cols-3">
                {items.map((item) => (
                    <article
                        key={item.label}
                        className="grid min-w-0 gap-3 rounded-2xl border border-slate-100 bg-slate-50/70 p-4"
                    >
                        <div className="flex items-center justify-between gap-3">
                            <div className="flex min-w-0 items-center gap-3">
                                <span className="size-3 rounded-full" style={{ backgroundColor: item.color }} />
                                <h3 className="truncate text-sm font-bold tracking-wide text-slate-950">
                                    {item.label}
                                </h3>
                            </div>
                            <span className="rounded-full bg-white px-2.5 py-1 text-xs font-bold text-slate-600 shadow-sm">
                                %{item.percentage}
                            </span>
                        </div>

                        <div className="grid gap-1">
                            <p className="text-xl font-bold text-slate-950 sm:text-2xl">
                                {item.amountLabel}
                            </p>
                            <p className="text-sm font-semibold text-slate-500">
                                {item.quantityLabel} adet
                            </p>
                        </div>
                    </article>
                ))}
            </div>
        </section>
    );
}
