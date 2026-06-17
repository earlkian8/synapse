import { cn } from '@/lib/utils';
import {
    PAYSLIP_STATUS_LABELS,
    PAYSLIP_STATUS_STYLES,
    PERIOD_STATUS_LABELS,
    PERIOD_STATUS_STYLES,
} from '../constants';
import type { PayrollStatus, PayslipStatus } from '../types';

/** A run's lifecycle status, as a pill. */
export function PeriodStatusBadge({
    status,
    className,
}: {
    status: PayrollStatus;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium whitespace-nowrap',
                PERIOD_STATUS_STYLES[status],
                className,
            )}
        >
            {PERIOD_STATUS_LABELS[status]}
        </span>
    );
}

/** A payslip's release status, as a pill. */
export function PayslipStatusBadge({
    status,
    className,
}: {
    status: PayslipStatus;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium whitespace-nowrap',
                PAYSLIP_STATUS_STYLES[status],
                className,
            )}
        >
            {PAYSLIP_STATUS_LABELS[status]}
        </span>
    );
}
