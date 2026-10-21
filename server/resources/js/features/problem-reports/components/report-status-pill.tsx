import { cn } from '@/lib/utils';
import { STATUS_TONES } from '../constants';
import type { ProblemReport } from '../types';

/** Where a report stands, in the reporter's words. */
export function ReportStatusPill({
    report,
    className,
}: {
    report: Pick<ProblemReport, 'status' | 'status_label'>;
    className?: string;
}) {
    const tone = STATUS_TONES[report.status];

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[11px] font-medium whitespace-nowrap',
                tone.pill,
                className,
            )}
        >
            <span className={cn('size-1.5 rounded-full', tone.dot)} />
            {report.status_label}
        </span>
    );
}
