import { Link, usePage } from '@inertiajs/react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import type { SharedPageProps } from '@/types';

export function AppSidebar() {
    const { panelContext, panelNavigation } = usePage<SharedPageProps>().props;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <div className="px-2 pb-2">
                    <div className="rounded-lg border border-sidebar-border bg-white p-3 text-sm shadow-sm">
                        <p className="text-[0.68rem] font-semibold uppercase tracking-[0.18em] text-slate-500">
                            {panelContext.host ?? 'dashboard.emaksprime.com.tr'}
                        </p>
                        <p className="mt-2 text-sm leading-5 text-slate-600">
                            Yetkiler PostgreSQL panel metadata kayitlarindan okunur.
                        </p>
                    </div>
                </div>
                <NavMain groups={panelNavigation.groups} />
            </SidebarContent>

            <SidebarFooter>
                <div className="mx-2 rounded-lg border border-sidebar-border bg-white p-3 text-sm text-slate-600 shadow-sm">
                    <p className="text-[0.68rem] font-semibold uppercase tracking-[0.18em] text-slate-500">
                        Metadata
                    </p>
                    <p className="mt-2">
                        {panelNavigation.meta.environment} - {panelNavigation.groups.length} menü grubu
                    </p>
                </div>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
