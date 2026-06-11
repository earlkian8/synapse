import { cn } from '@/lib/utils';
import { STATUS_LABELS, STATUS_STYLES } from '../constants';
import type { LeaveStatus } from '../types';

export function RequestStatusBadge({
    status,
    className,
}: {
    status: LeaveStatus;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium',
                STATUS_STYLES[status],
                className,
            )}
        >
            {STATUS_LABELS[status]}
        </span>
    );
}
