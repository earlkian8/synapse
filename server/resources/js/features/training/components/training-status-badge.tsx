import { cn } from '@/lib/utils';
import {
    ENROLLMENT_STATUS_LABELS,
    ENROLLMENT_STATUS_STYLES,
    PROGRAM_STATUS_LABELS,
    PROGRAM_STATUS_STYLES,
} from '../constants';
import type { ProgramStatus, TrainingEnrollmentStatus } from '../types';

const BASE =
    'inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium';

/** A pill for a program's derived lifecycle status. */
export function ProgramStatusBadge({
    status,
    className,
}: {
    status: ProgramStatus;
    className?: string;
}) {
    return (
        <span className={cn(BASE, PROGRAM_STATUS_STYLES[status], className)}>
            {PROGRAM_STATUS_LABELS[status]}
        </span>
    );
}

/** A pill for an enrollment's status. */
export function EnrollmentStatusBadge({
    status,
    className,
}: {
    status: TrainingEnrollmentStatus;
    className?: string;
}) {
    return (
        <span className={cn(BASE, ENROLLMENT_STATUS_STYLES[status], className)}>
            {ENROLLMENT_STATUS_LABELS[status]}
        </span>
    );
}
