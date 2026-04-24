import { Link } from '@inertiajs/react';

export function TopNav({ items = [], activeHref = '/sales/main' }) {
    return (
        <nav className="flex flex-wrap items-center gap-2">
            {items.map((item) => {
                const active = item.href === activeHref;

                return (
                    <Link
                        key={item.key}
                        href={item.href}
                        className={[
                            'rounded-md border px-3 py-2 text-sm font-semibold transition',
                            active
                                ? 'border-slate-900 bg-slate-900 text-white'
                                : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:text-slate-950',
                        ].join(' ')}
                    >
                        {item.label}
                    </Link>
                );
            })}
        </nav>
    );
}
