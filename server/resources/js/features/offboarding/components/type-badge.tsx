import { cn } from '@/lib/utils';
import { TYPE_LABELS, TYPE_STYLES } from '../constants';
import type { OffboardingType } from '../types';

export function TypeBadge({ type }: { type: OffboardingType }) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium',
                TYPE_STYLES[type] ?? TYPE_STYLES.resignation,
            )}
        >
            {TYPE_LABELS[type] ?? type}
        </span>
    );
}
