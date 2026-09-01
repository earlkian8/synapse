import { ArrowRight } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { GraduationCheck } from '../types';

/**
 * What the model is today against what it becomes on graduation, side by side.
 * The contrast is the argument for the whole surface: the difference is not
 * accuracy, it is whose workforce the predictions actually describe.
 */
export function ModelSummary({ check }: { check: GraduationCheck }) {
    const graduated = check.stage === 'graduated';

    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <Panel
                eyebrow="Scoring today"
                title="Provisional model"
                active={!graduated}
                rows={[
                    ['Trained on', check.dataset_origin],
                    ['Powers', 'Promotion Readiness and Performance Forecast'],
                    [
                        'Employees tracked',
                        `${check.employees_tracked.toLocaleString()} active`,
                    ],
                    ['Shown to users as', 'Provisional'],
                ]}
            />

            <Panel
                eyebrow="After graduation"
                title="Local model"
                active={graduated}
                rows={[
                    ['Trained on', 'This organisation’s own records'],
                    ['Powers', 'The same two surfaces, retrained in place'],
                    [
                        'Employees tracked',
                        'Every employee with a complete history',
                    ],
                    ['Shown to users as', 'Trained on your records'],
                ]}
            />
        </div>
    );
}

function Panel({
    eyebrow,
    title,
    active,
    rows,
}: {
    eyebrow: string;
    title: string;
    active: boolean;
    rows: [string, string][];
}) {
    return (
        <div
            className={cn(
                'flex flex-col rounded-xl border bg-card p-4 shadow-sm sm:p-5',
                active
                    ? 'border-[#0ABFBF]/40'
                    : 'border-sidebar-border/70 dark:border-sidebar-border',
            )}
        >
            <div className="flex items-center gap-2">
                <span className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {eyebrow}
                </span>
                {active && (
                    <span className="rounded-full border border-[#0ABFBF]/30 bg-[#0ABFBF]/10 px-2 py-0.5 text-[10px] font-medium tracking-wide text-[#0ABFBF] uppercase">
                        Active
                    </span>
                )}
            </div>

            <p
                className={cn(
                    'mt-1 flex items-center gap-1.5 text-sm font-medium',
                    !active && 'text-muted-foreground',
                )}
            >
                {title}
                {!active && <ArrowRight className="size-3.5" />}
            </p>

            <dl className="mt-4 flex flex-col gap-2.5">
                {rows.map(([label, value]) => (
                    <div
                        key={label}
                        className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5 text-xs"
                    >
                        <dt className="text-muted-foreground">{label}</dt>
                        <dd
                            className={cn(
                                'text-right font-medium',
                                !active && 'text-muted-foreground',
                            )}
                        >
                            {value}
                        </dd>
                    </div>
                ))}
            </dl>
        </div>
    );
}
