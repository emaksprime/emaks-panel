import { Link, router, usePage } from '@inertiajs/react';
import { LogOut, Settings, Shield } from 'lucide-react';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { SharedPageProps, User } from '@/types';

type Props = {
    user: User;
};

export function UserMenuContent({ user }: Props) {
    const { panelNavigation } = usePage<SharedPageProps>().props;
    const cleanup = useMobileNavigation();
    const canAccessAdmin = panelNavigation.groups.some((group) => (
        group.items.some((item) => item.href === '/admin')
    ));

    const handleLogout = () => {
        cleanup();
        router.flushAll();
    };

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                    <UserInfo user={user} showEmail={true} />
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                {canAccessAdmin && (
                    <DropdownMenuItem asChild>
                        <Link
                            className="block w-full cursor-pointer"
                            href="/admin"
                            prefetch
                            onClick={cleanup}
                        >
                            <Shield className="mr-2" />
                            Yönetim Paneli
                        </Link>
                    </DropdownMenuItem>
                )}
                <DropdownMenuItem asChild>
                    <Link
                        className="block w-full cursor-pointer"
                        href={edit()}
                        prefetch
                        onClick={cleanup}
                    >
                        <Settings className="mr-2" />
                        Profil / Ayarlar
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuItem asChild>
                <Link
                    className="block w-full cursor-pointer"
                    href={logout()}
                    as="button"
                    onClick={handleLogout}
                    data-test="logout-button"
                >
                    <LogOut className="mr-2" />
                    Çıkış Yap
                </Link>
            </DropdownMenuItem>
        </>
    );
}
