import { Head, Link } from '@inertiajs/react';

const tabs = [
    ['Özet', '/admin'],
    ['Kullanıcılar', '/admin/users'],
    ['Sayfalar', '/admin/pages'],
    ['Veri Kaynakları', '/admin/datasources'],
    ['Loglar', '/admin/logs'],
];

export function AdminFrame({ title, children }) {
    return (
        <>
            <Head title={title} />
            <main className="grid gap-5 p-4 md:p-6">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Yönetim</p>
                    <h1 className="mt-1 text-2xl font-semibold text-slate-950 [font-family:var(--font-display)]">
                        {title}
                    </h1>
                </div>
                <nav className="flex flex-wrap gap-2">
                    {tabs.map(([label, href]) => (
                        <Link
                            key={href}
                            href={href}
                            className="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-600 shadow-sm hover:text-slate-950"
                        >
                            {label}
                        </Link>
                    ))}
                </nav>
                {children}
            </main>
        </>
    );
}
