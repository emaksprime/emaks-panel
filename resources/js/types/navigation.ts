import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import type { Auth } from '@/types/auth';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
};

export type PanelNavigationItem = {
    id: number;
    title: string;
    href: string;
    icon?: string | null;
};

export type PanelNavigationGroup = {
    id: number;
    title: string;
    slug: string;
    icon?: string | null;
    items: PanelNavigationItem[];
};

export type PanelButtonData = {
    id: number;
    label: string;
    slug: string;
    variant: string;
    actionType: string;
    actionTarget: string | null;
    position?: string | null;
    confirmationRequired?: boolean;
    confirmationText?: string | null;
    canExecute: boolean;
    icon?: string | null;
};

export type PanelPagePayload = {
    id: number;
    title: string;
    slug: string;
    routePath: string;
    component: string;
    layoutType?: 'admin' | 'module' | string;
    description?: string | null;
    icon?: string | null;
    heroEyebrow?: string | null;
    previewNotice?: string | null;
    moduleTabs?: Array<{
        label: string;
        href: string;
    }>;
    buttons: PanelButtonData[];
};

export type PanelMetric = {
    label: string;
    value: string;
    hint: string;
    tone?: 'default' | 'accent' | 'warning';
};

export type PanelDataSourceSummary = {
    id: number;
    name: string;
    slug: string;
    driver: string;
    target?: string | null;
    status: string;
    description?: string | null;
    database?: string | null;
    host?: string | null;
};

export type PanelNavigationPayload = {
    groups: PanelNavigationGroup[];
    currentPage: PanelPagePayload | null;
    role: {
        name: string;
        slug: string;
        isSuperAdmin: boolean;
    } | null;
    meta: {
        brand: string;
        environment: string;
        host: string | null;
        generatedAt: string;
    };
};

export type SharedPageProps = {
    name: string;
    auth: Auth;
    sidebarOpen: boolean;
    panelNavigation: PanelNavigationPayload;
    panelContext: {
        brand: string;
        host: string | null;
        environment: string;
    };
    [key: string]: unknown;
};
