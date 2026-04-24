import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-9 items-center justify-center rounded-lg bg-sidebar-primary text-sidebar-primary-foreground shadow-sm">
                <AppLogoIcon className="size-5 fill-current text-white" />
            </div>
            <div className="ml-2 grid flex-1 text-left text-sm">
                <span className="truncate text-[0.7rem] font-semibold uppercase tracking-[0.18em] text-slate-500">
                    Emaks Prime
                </span>
                <span className="mb-0.5 truncate leading-tight font-semibold text-slate-950 [font-family:var(--font-display)]">
                    Control Panel
                </span>
            </div>
        </>
    );
}
