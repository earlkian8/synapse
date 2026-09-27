import {
    ArrowRight,
    CircleCheck,
    CircleDashed,
    CircleDot,
    Hourglass,
    Info,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import {
    completion,
    formatProgress,
    formatShortfall,
    GROUP_COPY,
    GROUP_ORDER,
    STATUS_BARS,
    STATUS_LABELS,
    STATUS_STYLES,
    STATUS_TRACKS,
} from '../constants';
import type { Requirement, RequirementStatus } from '../types';

/**
 * Every requirement, as a checklist a reader can act on: what is counted, how far
 * along it is, what is still missing in plain words, and what to do about it. The
 * statistical reason for each threshold is one click away, not in the way.
 *
 * Groups are categories, not steps — nothing here happens in sequence.
 */
export function RequirementChecklist({
    requirements,
    bindingKey,
    onExplain,
}: {
    requirements: Requirement[];
    bindingKey: string | null;
    onExplain: (requirement: Requirement) => void;
}) {
    return (
        <div className="flex flex-col gap-5">
            {GROUP_ORDER.map((group) => {
                const rows = requirements.filter((r) => r.group === group);

                if (rows.length === 0) {
                    return null;
                }

                const met = rows.filter((r) => r.status === 'met').length;

                return (
                    <section key={group} aria-label={GROUP_COPY[group].label}>
                        <header className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5 px-1">
                            <h4 className="text-sm font-medium">
                                {GROUP_COPY[group].label}
                            </h4>
                            <span className="text-xs text-muted-foreground">
                                {met} of {rows.length} done
                            </span>
                            <p className="w-full text-xs text-muted-foreground">
                                {GROUP_COPY[group].hint}
                            </p>
                        </header>

                        <ul className="mt-2 divide-y divide-sidebar-border/70 overflow-hidden rounded-xl border border-sidebar-border/70 dark:divide-sidebar-border dark:border-sidebar-border">
                            {rows.map((requirement) => (
                                <RequirementRow
                                    key={requirement.key}
                                    requirement={requirement}
                                    isBinding={requirement.key === bindingKey}
                                    onExplain={() => onExplain(requirement)}
                                />
                            ))}
                        </ul>
                    </section>
                );
            })}
        </div>
    );
}

function RequirementRow({
    requirement,
    isBinding,
    onExplain,
}: {
    requirement: Requirement;
    isBinding: boolean;
    onExplain: () => void;
}) {
    const met = requirement.status === 'met';
    const percent = completion(requirement.current, requirement.required);
    const shortfall = formatShortfall(requirement);

    return (
        <li
            className={cn(
                'relative px-4 py-3.5',
                isBinding && 'bg-[#0ABFBF]/[0.04]',
            )}
        >
            {/* Ties this row to "furthest to go" at a glance. */}
            {isBinding && (
                <span
                    className="absolute inset-y-0 left-0 w-0.5 bg-[#0ABFBF]"
                    aria-hidden="true"
                />
            )}

            <div className="flex items-start gap-3">
                <StatusIcon status={requirement.status} />

                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                        <p className="text-sm font-medium">
                            {requirement.label}
                            {isBinding && (
                                <span className="ml-2 rounded-full bg-[#0ABFBF]/15 px-2 py-0.5 text-[11px] font-medium whitespace-nowrap text-teal-700 dark:text-[#0ABFBF]">
                                    Furthest to go
                                </span>
                            )}
                        </p>
                        <p className="shrink-0 text-sm font-semibold tabular-nums">
                            {formatProgress(requirement)}
                        </p>
                    </div>

                    <p className="mt-0.5 text-xs leading-relaxed text-muted-foreground">
                        {requirement.summary}
                    </p>

                    {requirement.format !== 'check' && (
                        <div className="mt-2.5 flex items-center gap-3">
                            <div
                                className={cn(
                                    'h-1.5 flex-1 overflow-hidden rounded-full',
                                    STATUS_TRACKS[requirement.status],
                                )}
                                role="meter"
                                aria-label={requirement.label}
                                aria-valuemin={0}
                                aria-valuemax={100}
                                aria-valuenow={percent}
                            >
                                <div
                                    className={cn(
                                        'h-full rounded-full',
                                        STATUS_BARS[requirement.status],
                                    )}
                                    style={{
                                        width: `${met ? 100 : Math.max(percent, 1.5)}%`,
                                    }}
                                />
                            </div>
                            <span
                                className={cn(
                                    'w-28 shrink-0 text-right text-xs font-medium',
                                    STATUS_STYLES[requirement.status],
                                )}
                            >
                                {met ? STATUS_LABELS.met : `${percent}%`}
                            </span>
                        </div>
                    )}

                    {!met && (
                        <div className="mt-2.5 flex flex-col gap-1.5 text-xs">
                            {shortfall && (
                                <p className="font-medium text-foreground">
                                    Still needed: {shortfall}
                                </p>
                            )}
                            <p className="flex items-start gap-1.5 text-muted-foreground">
                                <ArrowRight className="mt-0.5 size-3 shrink-0 text-teal-700 dark:text-[#0ABFBF]" />
                                <span>
                                    <span className="font-medium text-foreground">
                                        {requirement.derived
                                            ? 'This fills on its own: '
                                            : 'What you can do: '}
                                    </span>
                                    {requirement.action}
                                </span>
                            </p>
                            {isBinding && requirement.outlook && (
                                <p className="flex items-start gap-1.5 text-muted-foreground">
                                    <Hourglass className="mt-0.5 size-3 shrink-0" />
                                    <span>{requirement.outlook}</span>
                                </p>
                            )}
                        </div>
                    )}

                    {requirement.note && (
                        <p className="mt-2 flex items-start gap-1.5 text-xs text-muted-foreground">
                            <Info className="mt-0.5 size-3 shrink-0" />
                            <span>{requirement.note}</span>
                        </p>
                    )}

                    <button
                        type="button"
                        onClick={onExplain}
                        className="mt-2 text-xs font-medium text-teal-700 underline-offset-4 hover:underline dark:text-[#0ABFBF]"
                    >
                        Why this number?
                    </button>
                </div>
            </div>
        </li>
    );
}

function StatusIcon({ status }: { status: RequirementStatus }) {
    const Icon =
        status === 'met'
            ? CircleCheck
            : status === 'progressing'
              ? CircleDot
              : CircleDashed;

    return (
        <span className={cn('mt-0.5 shrink-0', STATUS_STYLES[status])}>
            <Icon className="size-4" aria-hidden="true" />
            <span className="sr-only">{STATUS_LABELS[status]}</span>
        </span>
    );
}
