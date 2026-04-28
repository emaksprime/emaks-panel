import { Link, usePage } from '@inertiajs/react';
import { ChevronsUpDown } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { UserMenuContent } from '@/components/user-menu-content';
import type { SharedPageProps } from '@/types';

const moduleItems = [
    { label: 'Satış Yönetimi', href: '/sales/main', match: ['/sales/main', '/sales/online', '/sales/bayi'] },
    { label: 'Stok Yönetimi', href: '/stock', match: ['/stock', '/stock/critical'] },
    { label: 'Sipariş Yönetimi', href: '/orders', match: ['/orders', '/orders/alinan', '/orders/verilen'] },
    { label: 'Müşteri Yönetimi', href: '/cari', match: ['/cari', '/cari/balance', '/cari/detail'] },
    { label: 'Proforma', href: '/proforma', match: ['/proforma', '/proforma/create', '/proforma/detail', '/proforma/edit'] },
];

export default function ModuleLayout({ children }: { children: React.ReactNode }) {
    const { auth, panelNavigation, page } = usePage<SharedPageProps & { page?: { routePath?: string } }>().props;
    const routePath = page?.routePath ?? (typeof window !== 'undefined' ? window.location.pathname : '/dashboard');
    const visibleHrefs = new Set(
        panelNavigation.groups.flatMap((group) => group.items.map((item) => item.href)),
    );

    return (
        <div className="min-h-screen bg-slate-100">
            <header className="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
                <div className="mx-auto grid max-w-7xl gap-3 px-4 py-3 lg:grid-cols-[minmax(230px,auto)_minmax(0,1fr)_auto] lg:items-center">
                    <div className="flex min-w-0 items-center">
                        <Link href="/dashboard" className="flex min-w-[230px] shrink-0 items-center gap-2">
                            <AppLogo />
                        </Link>
                    </div>

                    <nav className="flex min-w-0 gap-2 overflow-x-auto pb-1 lg:justify-center lg:pb-0">
                        {moduleItems
                            .filter((item) => visibleHrefs.has(item.href) || item.match.some((href) => visibleHrefs.has(href)))
                            .map((item) => {
                                const active = item.match.includes(routePath);

                                return (
                                    <Link
                                        key={item.href}
                                        href={item.href}
                                        className={[
                                            'shrink-0 rounded-full border px-3 py-2 text-sm font-semibold transition xl:px-4',
                                            active
                                                ? 'border-slate-950 bg-slate-950 text-white'
                                                : 'border-slate-200 bg-white text-slate-600 hover:border-slate-400 hover:text-slate-950',
                                        ].join(' ')}
                                    >
                                        {item.label}
                                    </Link>
                                );
                            })}
                    </nav>

                    {auth.user && (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <button className="inline-flex min-w-48 items-center gap-2 justify-self-start rounded-xl border border-slate-200 bg-white px-3 py-2 text-left text-sm font-semibold text-slate-700 shadow-sm lg:justify-self-end">
                                    <UserInfo user={auth.user} />
                                    <ChevronsUpDown className="ml-auto size-4" />
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent className="w-64 rounded-lg" align="end">
                                <UserMenuContent user={auth.user} />
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}
                </div>
            </header>

            <main className="mx-auto w-full max-w-7xl">
                {children}
            </main>
        </div>
    );
}
