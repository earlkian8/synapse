import { cn } from '@/lib/utils';
import { STATUS_LABELS, STATUS_STYLES } from '../constants';
import type { EnrollmentStatus } from '../types';

/** A small pill for an enrollment's status. */
export function EnrollmentStatusBadge({
    status,
    className,
}: {
    status: EnrollmentStatus;
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
