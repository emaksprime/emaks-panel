import { Link, usePage } from '@inertiajs/react';
import { ChevronRight, ChevronsUpDown } from 'lucide-react';
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
    {
        label: 'Satış Yönetimi',
        candidates: ['/sales/main', '/sales/online', '/sales/bayi'],
        match: ['/sales/main', '/sales/online', '/sales/bayi'],
        tone: 'blue',
    },
    {
        label: 'Stok Yönetimi',
        candidates: ['/stock', '/stock/critical'],
        match: ['/stock', '/stock/critical'],
        tone: 'slate',
    },
    {
        label: 'Sipariş Yönetimi',
        candidates: ['/orders/alinan', '/orders/verilen', '/orders'],
        match: ['/orders', '/orders/alinan', '/orders/verilen'],
        tone: 'cyan',
    },
    {
        label: 'Teknik Servis',
        candidates: [
            '/technical-service',
            '/technical-service/dashboard',
            '/technical-service/serial-query',
            '/technical-service/technicians',
            '/technical-service/earnings',
            '/technical-service/admin',
        ],
        match: [
            '/technical-service',
            '/technical-service/dashboard',
            '/technical-service/serial-query',
            '/technical-service/technicians',
            '/technical-service/earnings',
            '/technical-service/admin',
        ],
        tone: 'emerald',
    },
    {
        label: 'Müşteri Yönetimi',
        candidates: ['/cari', '/cari/balance'],
        match: ['/cari', '/cari/balance', '/cari/detail', '/cari/document-detail'],
        tone: 'indigo',
    },
    {
        label: 'Proforma',
        candidates: ['/proforma', '/proforma/create', '/proforma/detail', '/proforma/edit'],
        match: ['/proforma', '/proforma/create', '/proforma/detail', '/proforma/edit'],
        tone: 'amber',
    },
];

function selectModuleHref(candidates: string[], visibleHrefs: Set<string>) {
    return candidates.find((href) => visibleHrefs.has(href)) ?? null;
}

const moduleToneClasses = {
    blue: {
        active: 'border-slate-950 bg-slate-950 text-white shadow-slate-900/20',
        idle: 'border-blue-100 bg-blue-50 text-blue-800 hover:border-blue-200 hover:bg-blue-100',
    },
    slate: {
        active: 'border-slate-950 bg-slate-950 text-white shadow-slate-900/20',
        idle: 'border-slate-200 bg-slate-50 text-slate-700 hover:border-slate-300 hover:bg-slate-100',
    },
    cyan: {
        active: 'border-slate-950 bg-slate-950 text-white shadow-slate-900/20',
        idle: 'border-cyan-100 bg-cyan-50 text-cyan-800 hover:border-cyan-200 hover:bg-cyan-100',
    },
    emerald: {
        active: 'border-slate-950 bg-slate-950 text-white shadow-slate-900/20',
        idle: 'border-emerald-100 bg-emerald-50 text-emerald-800 hover:border-emerald-200 hover:bg-emerald-100',
    },
    indigo: {
        active: 'border-slate-950 bg-slate-950 text-white shadow-slate-900/20',
        idle: 'border-indigo-100 bg-indigo-50 text-indigo-800 hover:border-indigo-200 hover:bg-indigo-100',
    },
    amber: {
        active: 'border-slate-950 bg-slate-950 text-white shadow-slate-900/20',
        idle: 'border-amber-100 bg-amber-50 text-amber-900 hover:border-amber-200 hover:bg-amber-100',
    },
};

export default function ModuleLayout({ children }: { children: React.ReactNode }) {
    const { auth, panelNavigation, page } = usePage<SharedPageProps & { page?: { routePath?: string } }>().props;
    const routePath = page?.routePath ?? (typeof window !== 'undefined' ? window.location.pathname : '/dashboard');
    const visibleHrefs = new Set(
        panelNavigation.groups.flatMap((group) => group.items.map((item) => item.href)),
    );

    return (
        <div className="min-h-screen bg-slate-100">
            <header className="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
                <div className="mx-auto grid max-w-[1600px] gap-3 px-4 py-2.5 lg:grid-cols-[minmax(170px,190px)_minmax(0,1fr)_auto] lg:items-center xl:px-6">
                    <div className="flex min-w-0 items-center justify-center lg:justify-start">
                        <Link href="/dashboard" className="flex min-w-[160px] shrink-0 items-center justify-center">
                            <AppLogo />
                        </Link>
                    </div>

                    <div className="relative min-w-0">
                        <nav className="flex min-w-0 gap-2 overflow-x-auto rounded-full border border-slate-200/80 bg-white/80 p-1 pr-10 shadow-inner shadow-slate-100 [scrollbar-width:none] lg:justify-start [&::-webkit-scrollbar]:hidden">
                            {moduleItems
                                .map((item) => ({
                                    ...item,
                                    visibleHref: selectModuleHref(item.candidates, visibleHrefs),
                                }))
                                .filter((item) => item.visibleHref !== null)
                                .map((item) => {
                                    const active = item.match.includes(routePath);
                                    const tone = moduleToneClasses[item.tone as keyof typeof moduleToneClasses];

                                    return (
                                        <Link
                                            key={item.label}
                                            href={item.visibleHref ?? '#'}
                                            className={[
                                                'shrink-0 rounded-full border px-3 py-2 text-[0.82rem] font-semibold shadow-sm transition hover:-translate-y-0.5 xl:px-4 xl:text-sm',
                                                active ? tone.active : tone.idle,
                                            ].join(' ')}
                                        >
                                            {item.label}
                                        </Link>
                                    );
                                })}
                        </nav>
                        <div className="pointer-events-none absolute inset-y-0 right-0 flex items-center rounded-r-full bg-gradient-to-l from-white via-white/95 to-transparent pl-8 pr-2 text-slate-400">
                            <ChevronRight className="size-4" />
                        </div>
                    </div>

                    {auth.user && (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <button className="inline-flex min-w-48 items-center gap-2 justify-self-start rounded-2xl border border-slate-200 bg-white px-3 py-2 text-left text-sm font-semibold text-slate-700 shadow-sm transition hover:border-slate-300 lg:justify-self-end">
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
