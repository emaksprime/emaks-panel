import { Link } from '@inertiajs/react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { panelIcon } from '@/lib/panel-icons';
import type { PanelNavigationGroup } from '@/types';

export function NavMain({ groups = [] }: { groups: PanelNavigationGroup[] }) {
    const { isCurrentUrl } = useCurrentUrl();

    return (
        <>
            {groups.map((group) => (
                <SidebarGroup key={group.id} className="px-2 py-0">
                    <SidebarGroupLabel className="text-[0.68rem] font-semibold uppercase tracking-[0.16em] text-slate-500">
                        {group.title}
                    </SidebarGroupLabel>
                    <SidebarMenu>
                        {group.items.map((item) => {
                            const Icon = panelIcon(item.icon);

                            return (
                                <SidebarMenuItem key={`${group.slug}-${item.id}`}>
                                    <SidebarMenuButton
                                        asChild
                                        isActive={isCurrentUrl(item.href)}
                                        tooltip={{ children: item.title }}
                                        className="data-[active=true]:bg-slate-900 data-[active=true]:text-white"
                                    >
                                        <Link href={item.href} prefetch>
                                            <Icon />
                                            <span>{item.title}</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                            );
                        })}
                    </SidebarMenu>
                </SidebarGroup>
            ))}
        </>
    );
}
