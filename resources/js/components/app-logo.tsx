export default function AppLogo() {
    return (
        <div className="flex w-36 shrink-0 flex-col items-center gap-1.5 text-center">
            <div className="flex h-10 w-32 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
                <img src="/assets/primecrm/emaks-prime.png" alt="Emaks Prime logo" className="h-full w-full object-contain p-1" />
            </div>
            <div className="grid min-w-0 text-sm leading-tight">
                <span className="mb-0.5 whitespace-nowrap text-[0.95rem] font-semibold text-slate-950 [font-family:var(--font-display)]">
                    Operasyon Paneli
                </span>
            </div>
        </div>
    );
}
