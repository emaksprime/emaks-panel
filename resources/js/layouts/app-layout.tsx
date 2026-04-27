import { usePage } from '@inertiajs/react';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import ModuleLayoutTemplate from '@/layouts/module-layout';
import type { BreadcrumbItem } from '@/types';

export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
}) {
    const { page } = usePage<{ page?: { layoutType?: string; routePath?: string } }>().props;
    const routePath = page?.routePath ?? '';
    const layoutType = page?.layoutType ?? (routePath.startsWith('/admin') || routePath === '/dashboard' ? 'admin' : 'module');

    if (layoutType === 'module') {
        return <ModuleLayoutTemplate>{children}</ModuleLayoutTemplate>;
    }

    return (
        <AppLayoutTemplate breadcrumbs={breadcrumbs}>
            {children}
        </AppLayoutTemplate>
    );
}
