import { StatusPill } from '@/components/status-pill';
import type { Tone } from '@/components/status-pill';
import { cn } from '@/lib/utils';
import type { ReportImpact, ReportStatus } from '../api';

const STATUS_TONES: Record<ReportStatus, Tone> = {
    new: 'sky',
    investigating: 'amber',
    resolved: 'emerald',
    closed: 'neutral',
};

export function ReportStatusPill({
    status,
    label,
}: {
    status: ReportStatus;
    label: string;
}) {
    return <StatusPill tone={STATUS_TONES[status]}>{label}</StatusPill>;
}

/**
 * How much the report gets in its sender's way, as a bar of three: the more
 * filled, the more in the way. Blocked is the one drawn in red.
 */
export function ImpactMeter({
    impact,
    label,
    className,
}: {
    impact: ReportImpact;
    label: string;
    className?: string;
}) {
    const level = impact === 'blocked' ? 3 : impact === 'workaround' ? 2 : 1;
    const fill =
        impact === 'blocked'
            ? 'bg-rose-500'
            : impact === 'workaround'
              ? 'bg-amber-500'
              : 'bg-neutral-400';

    return (
        <span
            className={cn(
                'inline-flex items-center gap-2 text-sm whitespace-nowrap',
                impact === 'blocked'
                    ? 'font-medium text-rose-700 dark:text-rose-400'
                    : 'text-muted-foreground',
                className,
            )}
        >
            <span className="flex items-end gap-0.5" aria-hidden="true">
                {[1, 2, 3].map((bar) => (
                    <span
                        key={bar}
                        className={cn(
                            'w-1 rounded-full',
                            bar === 1 ? 'h-1.5' : bar === 2 ? 'h-2.5' : 'h-3.5',
                            bar <= level ? fill : 'bg-muted-foreground/20',
                        )}
                    />
                ))}
            </span>
            {label}
        </span>
    );
}
