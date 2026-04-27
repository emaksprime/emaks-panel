import { Link } from '@inertiajs/react';

export function ModuleShell({ page, badge, actions, children }) {
    return (
        <main className="grid gap-5 p-4 md:p-6">
            <section className="overflow-hidden rounded-[1.75rem] border border-slate-200 bg-white shadow-sm">
                <div className="grid gap-4 bg-gradient-to-br from-slate-950 via-slate-900 to-blue-950 p-6 text-white lg:grid-cols-[1fr_auto] lg:items-center">
                    <div>
                        <div className="flex flex-wrap items-center gap-3">
                            <span className="rounded-full border border-white/15 bg-white/10 px-3 py-1 text-xs font-semibold uppercase tracking-[0.14em]">
                                {page.heroEyebrow ?? 'Operasyon'}
                            </span>
                            {badge && (
                                <span className="rounded-full border border-sky-200/30 bg-sky-200/15 px-3 py-1 text-xs font-semibold text-sky-100">
                                    {badge}
                                </span>
                            )}
                        </div>
                        <h1 className="mt-4 text-3xl font-semibold [font-family:var(--font-display)]">{page.title}</h1>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-200">{page.description}</p>
                    </div>
                    <div className="flex flex-wrap gap-2">{actions}</div>
                </div>

                {page.moduleTabs && page.moduleTabs.length > 0 && (
                    <nav className="flex flex-wrap gap-2 border-b border-slate-200 bg-slate-50 p-3">
                        {page.moduleTabs.map((tab) => {
                            const active = tab.href === page.routePath;

                            return (
                                <Link
                                    key={`${tab.label}-${tab.href}`}
                                    href={tab.href}
                                    className={[
                                        'rounded-full border px-4 py-2 text-sm font-semibold transition',
                                        active
                                            ? 'border-slate-950 bg-slate-950 text-white'
                                            : 'border-slate-200 bg-white text-slate-600 hover:border-blue-300 hover:text-blue-700',
                                    ].join(' ')}
                                >
                                    {tab.label}
                                </Link>
                            );
                        })}
                    </nav>
                )}
            </section>

            {children}
        </main>
    );
}
