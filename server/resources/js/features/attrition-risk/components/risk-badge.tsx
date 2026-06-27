import { cn } from '@/lib/utils';
import { TIER_LABELS, TIER_STYLES } from '../constants';
import type { RiskTier } from '../types';

const BASE =
    'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-medium';

/** A pill for an employee's attrition-risk tier. */
export function RiskBadge({
    tier,
    className,
}: {
    tier: RiskTier;
    className?: string;
}) {
    return (
        <span className={cn(BASE, TIER_STYLES[tier], className)}>
            <span className="size-1.5 rounded-full bg-current opacity-80" />
            {TIER_LABELS[tier]}
        </span>
    );
}
