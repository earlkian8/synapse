import { cn } from '@/lib/utils';
import { STATUS_LABELS, STATUS_STYLES } from '../constants';
import type { EmployeeStatus } from '../types';

export function EmployeeStatusBadge({ status }: { status: EmployeeStatus }) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium',
                STATUS_STYLES[status] ?? STATUS_STYLES.active,
            )}
        >
            {STATUS_LABELS[status] ?? status}
        </span>
    );
}
