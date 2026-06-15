import { cn } from '@/lib/utils';
import { STATUS_LABELS, STATUS_STYLES } from '../constants';
import type { AttendanceStatus } from '../types';

export function AttendanceStatusBadge({
    status,
    className,
}: {
    status: AttendanceStatus;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium whitespace-nowrap',
                STATUS_STYLES[status],
                className,
            )}
        >
            {STATUS_LABELS[status]}
        </span>
    );
}
