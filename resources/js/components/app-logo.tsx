export default function AppLogo() {
    return (
        <>
            <div className="flex h-10 w-28 items-center justify-center overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
                <img src="/assets/primecrm/emaks-prime.png" alt="Emaks Prime" className="h-full w-full object-contain p-1" />
            </div>
            <div className="ml-2 grid flex-1 text-left text-sm">
                <span className="truncate text-[0.7rem] font-semibold uppercase tracking-[0.18em] text-slate-500">
                    Emaks Prime
                </span>
                <span className="mb-0.5 truncate leading-tight font-semibold text-slate-950 [font-family:var(--font-display)]">
                    Operasyon Paneli
                </span>
            </div>
        </>
    );
}
