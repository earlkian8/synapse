import { useMemo } from 'react';
import { usePermissions } from '@/hooks/use-permissions';
import { APP_NAVIGATION } from '@/lib/app-navigation';
import type { AppNavGroup, AppNavItem } from '@/lib/app-navigation';

/**
 * The sidebar sections the signed-in person can see: each item gated by its
 * permission, and a section with nothing left in it dropped whole rather than
 * shown as a bare heading. The sidebar renders exactly this, and the first-run
 * tour describes exactly this.
 */
export function useAppNavigation(): AppNavGroup[] {
    const { can, canAny } = usePermissions();

    return useMemo(() => {
        const isVisible = (item: AppNavItem): boolean =>
            (!item.permission || can(item.permission)) &&
            (!item.permissionAny || canAny(...item.permissionAny));

        return APP_NAVIGATION.map((group) => ({
            ...group,
            items: group.items.filter(isVisible),
        })).filter((group) => group.items.length > 0);
    }, [can, canAny]);
}
