import { Building2, CalendarRange, UserCog, Users } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { TrashTypeKey } from './types';

export const PER_PAGE_OPTIONS = [10, 15, 25, 50, 100] as const;

/**
 * A stable selection key for a trash item. Numeric ids collide across types
 * (user #5 vs employee #5), so selection is keyed by `type:id`.
 */
export const trashKey = (item: { type: TrashTypeKey; id: number }): string =>
    `${item.type}:${item.id}`;

/**
 * Per-type presentation: the icon shown in the table/tabs and the badge accent.
 */
export const TYPE_META: Record<
    TrashTypeKey,
    { icon: LucideIcon; badge: string }
> = {
    user: {
        icon: UserCog,
        badge: 'border-violet-500/30 bg-violet-500/10 text-violet-600 dark:text-violet-400',
    },
    employee: {
        icon: Users,
        badge: 'border-[#0ABFBF]/30 bg-[#0ABFBF]/10 text-[#0ABFBF]',
    },
    department: {
        icon: Building2,
        badge: 'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400',
    },
    leave_type: {
        icon: CalendarRange,
        badge: 'border-sky-500/30 bg-sky-500/10 text-sky-600 dark:text-sky-400',
    },
};
