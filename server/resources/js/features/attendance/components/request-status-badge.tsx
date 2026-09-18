import { cn } from '@/lib/utils';
import { REQUEST_STATUS_LABELS, REQUEST_STATUS_STYLES } from '../constants';
import type { AttendanceRequestStatus } from '../types';

export function RequestStatusBadge({
    status,
    className,
}: {
    status: AttendanceRequestStatus;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium whitespace-nowrap',
                REQUEST_STATUS_STYLES[status],
                className,
            )}
        >
            {REQUEST_STATUS_LABELS[status]}
        </span>
    );
}
