export function DateRangeFilter({ config, filters, onChange, loading }) {
    const grains = config?.grains ?? [];
    const applyGrain = (grain) => {
        const today = new Date();
        const end = today.toISOString().slice(0, 10);
        let start = new Date(today);

        if (grain === 'week') {
            const day = start.getDay();
            const diff = day === 0 ? 6 : day - 1;
            start.setDate(start.getDate() - diff);
        } else if (grain === 'month') {
            start = new Date(today.getFullYear(), today.getMonth(), 1);
        } else if (grain === 'year') {
            start = new Date(today.getFullYear(), 0, 1);
        }

        onChange({ grain, date_from: start.toISOString().slice(0, 10), date_to: end });
    };

    return (
        <div className="grid gap-3 lg:grid-cols-[1fr_auto_auto] lg:items-end">
            <div className="flex flex-wrap gap-2">
                {grains.map((grain) => (
                    <button
                        key={grain.key}
                        type="button"
                        onClick={() => applyGrain(grain.key)}
                        disabled={loading}
                        className={[
                            'rounded-md border px-3 py-2 text-sm font-semibold transition',
                            filters.grain === grain.key
                                ? 'border-blue-700 bg-blue-700 text-white'
                                : 'border-slate-200 bg-white text-slate-600 hover:border-blue-200 hover:text-blue-700',
                        ].join(' ')}
                    >
                        {grain.label}
                    </button>
                ))}
            </div>

            <label className="grid gap-1 text-sm font-medium text-slate-600">
                Baslangic
                <input
                    type="date"
                    value={filters.dateFrom}
                    onChange={(event) => onChange({ date_from: event.target.value })}
                    className="h-10 rounded-md border border-slate-200 bg-white px-3 text-slate-900"
                />
            </label>

            <label className="grid gap-1 text-sm font-medium text-slate-600">
                Bitis
                <input
                    type="date"
                    value={filters.dateTo}
                    onChange={(event) => onChange({ date_to: event.target.value })}
                    className="h-10 rounded-md border border-slate-200 bg-white px-3 text-slate-900"
                />
            </label>
        </div>
    );
}
