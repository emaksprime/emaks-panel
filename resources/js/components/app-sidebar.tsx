import { Link, usePage } from '@inertiajs/react';
import { LayoutDashboard } from 'lucide-react';
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
    useSidebar,
} from '@/components/ui/sidebar';
import type { SharedPageProps } from '@/types';

export function AppSidebar() {
    const { panelNavigation } = usePage<SharedPageProps>().props;
    const { state } = useSidebar();

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            size="lg"
                            asChild
                            className={state === 'collapsed' ? '' : 'h-auto justify-center py-3 hover:bg-transparent'}
                        >
                            <Link href="/dashboard" prefetch className={state === 'collapsed' ? 'justify-center' : ''}>
                                {state === 'collapsed' ? (
                                    <>
                                        <span className="grid size-8 place-items-center rounded-xl bg-slate-950 text-white shadow-sm">
                                            <LayoutDashboard className="size-4" />
                                        </span>
                                        <span className="sr-only">Ana Giriş</span>
                                    </>
                                ) : (
                                    <AppLogo />
                                )}
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain groups={panelNavigation.groups} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
