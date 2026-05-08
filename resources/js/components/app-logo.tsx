export default function AppLogo() {
    return (
        <>
            <div className="flex h-11 w-32 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200 group-data-[collapsible=icon]/sidebar-wrapper:h-8 group-data-[collapsible=icon]/sidebar-wrapper:w-8">
                <img src="/assets/primecrm/emaks-prime.png" alt="Emaks Prime" className="h-full w-full object-contain p-1" />
            </div>
            <div className="ml-3 grid min-w-0 text-left text-sm leading-tight group-data-[collapsible=icon]/sidebar-wrapper:hidden">
                <span className="whitespace-nowrap text-[0.68rem] font-semibold uppercase tracking-[0.16em] text-slate-500">
                    Emaks Prime
                </span>
                <span className="mb-0.5 whitespace-nowrap text-[0.95rem] font-semibold text-slate-950 [font-family:var(--font-display)]">
                    Operasyon Paneli
                </span>
            </div>
        </>
    );
}
