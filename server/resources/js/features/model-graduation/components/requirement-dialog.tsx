import { ArrowRight, Database, Hourglass, Info, Ruler } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
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
    formatShortfall,
    STATUS_BARS,
    STATUS_LABELS,
    STATUS_STYLES,
    STATUS_TRACKS,
} from '../constants';
import type { Requirement } from '../types';

/**
 * One requirement in full: where it stands, what to do, why the threshold is that
 * number, and exactly what is counted. The justification is the point — a
 * threshold nobody can defend is just a number that blocks a button.
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
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
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

                        <Standing requirement={requirement} />

                        {requirement.status !== 'met' && (
                            <Note
                                icon={ArrowRight}
                                label={
                                    requirement.derived
                                        ? 'This fills on its own'
                                        : 'What you can do'
                                }
                                body={requirement.action}
                            />
                        )}
                        <Note
                            icon={Ruler}
                            label="Why this number"
                            body={requirement.basis}
                        />
                        <Note
                            icon={Database}
                            label="What is counted"
                            body={requirement.source}
                        />
                        {requirement.note && (
                            <Note
                                icon={Info}
                                label="Not counted yet"
                                body={requirement.note}
                            />
                        )}
                        {requirement.outlook && (
                            <Note
                                icon={Hourglass}
                                label="When it might be met"
                                body={requirement.outlook}
                            />
                        )}
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}

function Standing({ requirement }: { requirement: Requirement }) {
    const percent = completion(requirement.current, requirement.required);
    const shortfall = formatShortfall(requirement);

    return (
        <div className="rounded-xl border border-sidebar-border/70 bg-muted/40 p-4 dark:border-sidebar-border">
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <span className="text-xl font-semibold tracking-tight">
                    {formatProgress(requirement)}
                </span>
                <span
                    className={cn(
                        'text-xs font-medium',
                        STATUS_STYLES[requirement.status],
                    )}
                >
                    {STATUS_LABELS[requirement.status]}
                </span>
            </div>

            {requirement.format !== 'check' && (
                <div
                    className={cn(
                        'mt-3 h-2 overflow-hidden rounded-full',
                        STATUS_TRACKS[requirement.status],
                    )}
                >
                    <div
                        className={cn(
                            'h-full rounded-full',
                            STATUS_BARS[requirement.status],
                        )}
                        style={{ width: `${Math.max(percent, 1.5)}%` }}
                    />
                </div>
            )}

            <p className="mt-2.5 text-xs text-muted-foreground">
                {shortfall
                    ? `Still needed: ${shortfall}.`
                    : 'Met — this no longer holds training back.'}
            </p>
        </div>
    );
}

function Note({
    icon: Icon,
    label,
    body,
}: {
    icon: LucideIcon;
    label: string;
    body: string;
}) {
    return (
        <div className="flex gap-3">
            <span className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-teal-700 dark:text-[#0ABFBF]">
                <Icon className="size-3.5" />
            </span>
            <div className="min-w-0">
                <p className="text-sm font-medium">{label}</p>
                <p className="mt-0.5 text-sm leading-relaxed text-muted-foreground">
                    {body}
                </p>
            </div>
        </div>
    );
}
