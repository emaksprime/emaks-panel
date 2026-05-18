import { Link, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, ChevronsUpDown } from 'lucide-react';
import { useRef } from 'react';
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
    {
        label: 'Muhasebe / Finans',
        candidates: ['/accounting-finance/resmi-stok-kontrol'],
        match: ['/accounting-finance/resmi-stok-kontrol'],
        tone: 'teal',
    },
    {
        label: 'Destek',
        candidates: ['/support', '/support/keypad-guide', '/support/activation'],
        match: ['/support', '/support/keypad-guide', '/support/activation'],
        tone: 'rose',
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
    teal: {
        active: 'border-slate-950 bg-slate-950 text-white shadow-slate-900/20',
        idle: 'border-teal-100 bg-teal-50 text-teal-800 hover:border-teal-200 hover:bg-teal-100',
    },
    rose: {
        active: 'border-slate-950 bg-slate-950 text-white shadow-slate-900/20',
        idle: 'border-rose-100 bg-rose-50 text-rose-800 hover:border-rose-200 hover:bg-rose-100',
    },
};

export default function ModuleLayout({ children }: { children: React.ReactNode }) {
    const { auth, panelNavigation, page } = usePage<SharedPageProps & { page?: { routePath?: string } }>().props;
    const routePath = page?.routePath ?? (typeof window !== 'undefined' ? window.location.pathname : '/dashboard');
    const moduleNavRef = useRef<HTMLElement | null>(null);
    const visibleHrefs = new Set(
        panelNavigation.groups.flatMap((group) => group.items.map((item) => item.href)),
    );
    const scrollModules = (direction: -1 | 1) => {
        moduleNavRef.current?.scrollBy({ left: direction * 240, behavior: 'smooth' });
    };

    return (
        <div className="min-h-screen bg-slate-100">
            <header className="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
                <div className="mx-auto grid max-w-7xl gap-3 px-4 py-2.5 lg:grid-cols-[auto_minmax(0,1fr)_auto] lg:items-center xl:px-6">
                    <div className="flex min-w-0 items-center justify-center lg:justify-start">
                        <Link href="/dashboard" className="flex min-w-[160px] shrink-0 items-center justify-center">
                            <AppLogo />
                        </Link>
                    </div>

                    <div className="relative w-full min-w-0 max-w-full justify-self-stretch">
                        <span
                            aria-hidden="true"
                            className="pointer-events-none absolute inset-y-1 left-0 z-10 w-10 rounded-l-full bg-gradient-to-r from-white/95 via-white/80 to-transparent"
                        />
                        <button
                            type="button"
                            onClick={() => scrollModules(-1)}
                            aria-label="Modülleri sola kaydır"
                            className="absolute left-1 top-1/2 z-20 flex size-8 -translate-y-1/2 items-center justify-center rounded-full border border-slate-200 bg-white/95 text-slate-500 shadow-sm transition hover:border-slate-300 hover:text-slate-900"
                        >
                            <ChevronLeft className="size-4" />
                        </button>
                        <nav
                            ref={moduleNavRef}
                            className="flex w-max max-w-full min-w-0 gap-2 overflow-x-auto scroll-smooth rounded-full border border-slate-200/80 bg-white/80 py-1 pl-10 pr-10 shadow-inner shadow-slate-100 [scrollbar-width:none] lg:justify-start [&::-webkit-scrollbar]:hidden"
                        >
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
                        <span
                            aria-hidden="true"
                            className="pointer-events-none absolute inset-y-1 right-0 z-10 w-10 rounded-r-full bg-gradient-to-l from-white/95 via-white/80 to-transparent"
                        />
                        <button
                            type="button"
                            onClick={() => scrollModules(1)}
                            aria-label="Modülleri sağa kaydır"
                            className="absolute right-1 top-1/2 z-20 flex size-8 -translate-y-1/2 items-center justify-center rounded-full border border-slate-200 bg-white/95 text-slate-500 shadow-sm transition hover:border-slate-300 hover:text-slate-900"
                        >
                            <ChevronRight className="size-4" />
                        </button>
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
