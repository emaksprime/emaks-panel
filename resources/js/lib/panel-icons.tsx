import type { LucideIcon } from 'lucide-react';
import {
    Boxes,
    ChartColumnIncreasing,
    Database,
    FolderKanban,
    LayoutGrid,
    PanelLeft,
    ScrollText,
    Shield,
    ShoppingCart,
    Signal,
    Store,
    Users,
    Wallet,
} from 'lucide-react';

const iconMap: Record<string, LucideIcon> = {
    boxes: Boxes,
    'chart-column': ChartColumnIncreasing,
    database: Database,
    'folder-kanban': FolderKanban,
    'layout-grid': LayoutGrid,
    'panel-left': PanelLeft,
    'scroll-text': ScrollText,
    shield: Shield,
    'shopping-cart': ShoppingCart,
    signal: Signal,
    store: Store,
    users: Users,
    wallet: Wallet,
};

export function panelIcon(name?: string | null): LucideIcon {
    if (!name) {
        return FolderKanban;
    }

    return iconMap[name] ?? FolderKanban;
}
