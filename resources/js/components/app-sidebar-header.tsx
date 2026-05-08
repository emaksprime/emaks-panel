import { usePage } from '@inertiajs/react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { Badge } from '@/components/ui/badge';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type {
    BreadcrumbItem as BreadcrumbItemType,
    SharedPageProps,
} from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { panelContext, panelNavigation } = usePage<SharedPageProps>().props;
    const isDashboard = panelNavigation.currentPage?.routePath === '/dashboard';
    const title = isDashboard
        ? 'Ana Giriş'
        : panelNavigation.currentPage?.title ?? breadcrumbs[breadcrumbs.length - 1]?.title ?? panelContext.brand;
    const description = isDashboard
        ? 'Operasyon Paneli'
        : panelNavigation.currentPage?.description ?? 'Authenticated administration workspace';
    const showContextBadges = !isDashboard;

    return (
        <header className={[
            'flex shrink-0 flex-col border-b border-slate-200 bg-white px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-auto md:px-5',
            isDashboard ? 'gap-2 py-3' : 'gap-4 py-4',
        ].join(' ')}>
            <div className="flex items-start justify-between gap-4">
                <div className="flex items-start gap-3">
                    <SidebarTrigger className="-ml-1 mt-1" />
                    <div className="space-y-2">
                        {showContextBadges && (
                            <div className="flex flex-wrap items-center gap-2">
                                <Badge variant="outline" className="rounded-md border-slate-200 bg-slate-50 text-slate-700">
                                    {panelContext.host ?? 'dashboard.emaksprime.com.tr'}
                                </Badge>
                                <Badge variant="outline" className="rounded-md border-slate-200 bg-white text-slate-600">
                                    {panelContext.environment}
                                </Badge>
                                {panelNavigation.role && (
                                    <Badge variant="outline" className="rounded-md border-slate-200 bg-white text-slate-600">
                                        {panelNavigation.role.name}
                                    </Badge>
                                )}
                            </div>
                        )}
                        <div>
                            <h1 className={[
                                'font-semibold text-slate-950 [font-family:var(--font-display)]',
                                isDashboard ? 'text-base' : 'text-2xl',
                            ].join(' ')}>
                                {title}
                            </h1>
                            <p className={[
                                'mt-1 max-w-2xl text-slate-500',
                                isDashboard ? 'text-xs' : 'text-sm',
                            ].join(' ')}>
                                {description}
                            </p>
                        </div>
                    </div>
                </div>

                <div className="hidden md:block">
                    <Breadcrumbs breadcrumbs={breadcrumbs} />
                </div>
            </div>

            <div className="md:hidden">
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
        </header>
    );
}
