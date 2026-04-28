export function ErrorBanner({ message }) {
    if (!message) {
        return null;
    }

    return (
        <div className="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-700">
            Veri alınamadı. Lütfen filtreleri kontrol edip tekrar deneyin.
        </div>
    );
}

export function EmptyState({ title = 'Kayıt bulunamadı', description = 'Filtreleri değiştirip tekrar deneyebilirsiniz.' }) {
    return (
        <div className="rounded-2xl border border-amber-200 bg-amber-50 p-6 text-amber-800">
            <h3 className="font-semibold">{title}</h3>
            <p className="mt-2 text-sm leading-6">{description}</p>
        </div>
    );
}

export function LoadingOverlay({ show }) {
    if (!show) {
        return null;
    }

    return (
        <div className="rounded-2xl border border-slate-200 bg-white p-6 text-sm font-semibold text-slate-500 shadow-sm">
            Veri kaynağı okunuyor...
        </div>
    );
}
