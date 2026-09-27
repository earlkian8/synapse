import {
    ArrowLeft,
    ArrowRight,
    Database,
    Hourglass,
    Info,
    Ruler,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import {
    completion,
    formatProgress,
    formatShortfall,
    GROUP_COPY,
    STATUS_BARS,
    STATUS_LABELS,
    STATUS_STYLES,
    STATUS_TRACKS,
} from '../constants';
import type { Requirement } from '../types';
import { StatusIcon } from './requirements-table';

/**
 * One requirement in full, opened in place of the requirements table: where it
 * stands, what to do, why the threshold is that number, and exactly what is
 * counted. The justification is the point — a threshold nobody can defend is
 * just a number that blocks a button.
 */
export function RequirementDetail({
    requirement,
    onBack,
}: {
    requirement: Requirement;
    onBack: () => void;
}) {
    return (
        <div className="flex flex-col gap-4">
            <div>
                <Button
                    variant="ghost"
                    size="sm"
                    className="-ml-2 h-8 text-muted-foreground"
                    onClick={onBack}
                >
                    <ArrowLeft className="size-4" />
                    All requirements
                </Button>
            </div>

            <div className="flex items-start gap-2.5">
                <StatusIcon status={requirement.status} />
                <div className="min-w-0">
                    <p className="text-xs text-muted-foreground">
                        {GROUP_COPY[requirement.group].label}
                    </p>
                    <h3 className="text-base font-semibold tracking-tight">
                        {requirement.label}
                    </h3>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        {requirement.summary}
                    </p>
                </div>
            </div>

            <Standing requirement={requirement} />

            <div className="grid gap-4 md:grid-cols-2">
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
                {requirement.outlook && (
                    <Note
                        icon={Hourglass}
                        label="When it might be met"
                        body={requirement.outlook}
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
            </div>
        </div>
    );
}

function Standing({ requirement }: { requirement: Requirement }) {
    const percent = completion(requirement.current, requirement.required);
    const shortfall = formatShortfall(requirement);

    return (
        <div className="rounded-xl border border-sidebar-border/70 bg-muted/40 px-4 py-3 dark:border-sidebar-border">
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <span className="text-lg font-semibold tracking-tight tabular-nums">
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
                        'mt-2 h-2 overflow-hidden rounded-full',
                        STATUS_TRACKS[requirement.status],
                    )}
                >
                    <div
                        className={cn(
                            'h-full rounded-full',
                            STATUS_BARS[requirement.status],
                        )}
                        style={{ width: `${Math.max(percent, 2)}%` }}
                    />
                </div>
            )}

            <p className="mt-2 text-xs text-muted-foreground">
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
