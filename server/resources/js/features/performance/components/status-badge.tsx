import { cn } from '@/lib/utils';
import {
    EVALUATION_STATUS_LABELS,
    EVALUATION_STATUS_STYLES,
    PERIOD_STATUS_LABELS,
    PERIOD_STATUS_STYLES,
} from '../constants';
import type { EvaluationStatus, PeriodStatus } from '../types';

const BASE =
    'inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium';

/** A pill for an evaluation's lifecycle status. */
export function EvaluationStatusBadge({
    status,
    className,
}: {
    status: EvaluationStatus;
    className?: string;
}) {
    return (
        <span className={cn(BASE, EVALUATION_STATUS_STYLES[status], className)}>
            {EVALUATION_STATUS_LABELS[status]}
        </span>
    );
}

/** A pill for an evaluation period's status. */
export function PeriodStatusBadge({
    status,
    className,
}: {
    status: PeriodStatus;
    className?: string;
}) {
    return (
        <span className={cn(BASE, PERIOD_STATUS_STYLES[status], className)}>
            {PERIOD_STATUS_LABELS[status]}
        </span>
    );
}
