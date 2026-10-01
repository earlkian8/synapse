import { cn } from '@/lib/utils';
import { HOLIDAY_TYPE_LABELS, HOLIDAY_TYPE_STYLES } from '../constants';
import type { HolidayType } from '../types';

export function HolidayTypeBadge({ type }: { type: HolidayType }) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium',
                HOLIDAY_TYPE_STYLES[type] ?? HOLIDAY_TYPE_STYLES.regular,
            )}
        >
            {HOLIDAY_TYPE_LABELS[type] ?? type}
        </span>
    );
}
