import type { LucideIcon } from 'lucide-react';
import {
    Boxes,
    Calculator,
    ChartColumnIncreasing,
    Database,
    FolderKanban,
    LayoutGrid,
    LifeBuoy,
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
    calculator: Calculator,
    'chart-column': ChartColumnIncreasing,
    database: Database,
    'folder-kanban': FolderKanban,
    'layout-grid': LayoutGrid,
    'life-buoy': LifeBuoy,
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
