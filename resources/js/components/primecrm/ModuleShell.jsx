import { Link } from '@inertiajs/react';

export function ModuleShell({ page, badge, actions, children }) {
    return (
        <main className="grid gap-5 bg-[#f3f7fb] p-4 md:p-6">
            <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div className="grid gap-4 border-b border-slate-100 bg-white p-5 lg:grid-cols-[1fr_auto] lg:items-center">
                    <div>
                        <div className="flex flex-wrap items-center gap-3">
                            <span className="rounded-full border border-blue-100 bg-blue-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.14em] text-blue-700">
                                {page.heroEyebrow ?? 'Operasyon'}
                            </span>
                            {badge && (
                                <span className="rounded-full border border-emerald-100 bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">
                                    {badge}
                                </span>
                            )}
                        </div>
                        <h1 className="mt-4 text-3xl font-semibold text-slate-950 [font-family:var(--font-display)]">{page.title}</h1>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{page.description}</p>
                    </div>
                    <div className="flex flex-wrap gap-2">{actions}</div>
                </div>

                {page.moduleTabs && page.moduleTabs.length > 0 && (
                    <nav className="flex flex-wrap gap-2 bg-slate-50 p-3">
                        {page.moduleTabs.map((tab) => {
                            const active = tab.href === page.routePath;

                            return (
                                <Link
                                    key={`${tab.label}-${tab.href}`}
                                    href={tab.href}
                                    className={[
                                        'rounded-full border px-4 py-2 text-sm font-semibold transition',
                                        active
                                            ? 'border-blue-700 bg-blue-700 text-white'
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
