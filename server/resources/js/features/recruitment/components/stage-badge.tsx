import { cn } from '@/lib/utils';
import { STAGE_LABELS, STAGE_STYLES } from '../constants';
import type { Stage } from '../types';

export function StageBadge({ stage }: { stage: Stage }) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium',
                STAGE_STYLES[stage] ?? STAGE_STYLES.applied,
            )}
        >
            {STAGE_LABELS[stage] ?? stage}
        </span>
    );
}
