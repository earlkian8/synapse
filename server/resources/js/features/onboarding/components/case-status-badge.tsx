import { cn } from '@/lib/utils';
import { CASE_STATUS_LABELS, CASE_STATUS_STYLES } from '../constants';
import type { CaseStatus } from '../types';

export function CaseStatusBadge({ status }: { status: CaseStatus }) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium',
                CASE_STATUS_STYLES[status] ?? CASE_STATUS_STYLES.pending,
            )}
        >
            {CASE_STATUS_LABELS[status] ?? status}
        </span>
    );
}
