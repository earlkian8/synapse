import { CalendarClock, Database, Ruler } from 'lucide-react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import {
    completion,
    formatProgress,
    STATUS_BARS,
    STATUS_LABELS,
    STATUS_STYLES,
} from '../constants';
import type { Requirement } from '../types';

/**
 * A drill-down on one requirement: where it stands, why the threshold is the
 * number it is, and where the count comes from. The justification is the point —
 * a threshold nobody can defend is just a number that blocks a button.
 */
export function RequirementDialog({
    requirement,
    onOpenChange,
}: {
    requirement: Requirement | null;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={requirement !== null} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                {requirement && (
                    <>
                        <DialogHeader>
                            <DialogTitle className="pr-6 text-base">
                                {requirement.label}
                            </DialogTitle>
                            <DialogDescription>
                                {requirement.summary}
                            </DialogDescription>
                        </DialogHeader>

                        <Progress requirement={requirement} />

                        <Note
                            icon={Ruler}
                            label="Why this threshold"
                            body={requirement.basis}
                        />
                        <Note
                            icon={Database}
                            label="Where the count comes from"
                            body={requirement.source}
                        />
                        {requirement.outlook && (
                            <Note
                                icon={CalendarClock}
                                label="What would close the gap"
                                body={requirement.outlook}
                            />
                        )}
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}

function Progress({ requirement }: { requirement: Requirement }) {
    const percent = completion(requirement.current, requirement.required);
    const remaining = Math.max(0, requirement.required - requirement.current);

    return (
        <div className="rounded-xl border border-sidebar-border/70 bg-muted/40 p-4 dark:border-sidebar-border">
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <span className="text-2xl font-semibold tracking-tight tabular-nums">
                    {formatProgress(requirement.current, requirement.required)}
                </span>
                <span
                    className={cn(
                        'rounded-full border px-2 py-0.5 text-[11px] font-medium',
                        STATUS_STYLES[requirement.status],
                    )}
                >
                    {STATUS_LABELS[requirement.status]}
                </span>
            </div>

            <div className="mt-3 h-2 overflow-hidden rounded-full bg-muted">
                <div
                    className={cn(
                        'h-full rounded-full',
                        STATUS_BARS[requirement.status],
                    )}
                    style={{ width: `${Math.max(percent, 1.5)}%` }}
                />
            </div>

            <p className="mt-2.5 text-xs text-muted-foreground">
                {requirement.status === 'met'
                    ? 'Satisfied — this requirement no longer blocks retraining.'
                    : `${remaining.toLocaleString()} more ${requirement.unit} needed.`}
            </p>
        </div>
    );
}

function Note({
    icon: Icon,
    label,
    body,
}: {
    icon: typeof Ruler;
    label: string;
    body: string;
}) {
    return (
        <div className="flex gap-3">
            <span className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-[#0ABFBF]">
                <Icon className="size-3.5" />
            </span>
            <div className="min-w-0">
                <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {label}
                </p>
                <p className="mt-1 text-sm text-muted-foreground">{body}</p>
            </div>
        </div>
    );
}
