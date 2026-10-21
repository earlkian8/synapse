import { Check } from 'lucide-react';
import { cn } from '@/lib/utils';
import { STEPS } from '../constants';
import type { ProblemReport } from '../types';

/**
 * The way a report moves — Received, Being looked into, Resolved — with the
 * steps it has reached filled in. A closed report (the team won't change
 * anything) says so in place of the last step, rather than pretending to be
 * resolved.
 */
export function ReportProgress({
    report,
}: {
    report: Pick<ProblemReport, 'status'>;
}) {
    const closed = report.status === 'closed';
    const reached = closed
        ? 1
        : STEPS.findIndex((step) => step.status === report.status);

    return (
        <ol className="grid grid-cols-3" aria-label="Where this report stands">
            {STEPS.map((step, index) => {
                const done = index <= reached;
                // The step it is waiting at; a resolved or closed report waits at none.
                const current =
                    index === reached &&
                    report.status !== 'resolved' &&
                    !closed;
                const last = index === STEPS.length - 1;
                const label = last && closed ? 'Closed' : step.label;

                return (
                    <li
                        key={step.status}
                        className="relative flex flex-col items-center gap-1.5 text-center"
                        aria-current={current ? 'step' : undefined}
                    >
                        {index > 0 && (
                            <span
                                aria-hidden="true"
                                className={cn(
                                    'absolute top-3 right-1/2 h-0.5 w-full -translate-y-1/2',
                                    done
                                        ? 'bg-[#0ABFBF]'
                                        : 'bg-sidebar-border dark:bg-sidebar-border',
                                    last &&
                                        closed &&
                                        'bg-neutral-300 dark:bg-neutral-600',
                                )}
                            />
                        )}
                        <span
                            className={cn(
                                'relative z-10 flex size-6 items-center justify-center rounded-full border-2 text-[11px] font-semibold',
                                done
                                    ? 'border-[#0ABFBF] bg-[#0ABFBF] text-white'
                                    : 'border-sidebar-border bg-card text-muted-foreground dark:border-sidebar-border',
                                current && 'ring-4 ring-[#0ABFBF]/20',
                                last &&
                                    closed &&
                                    'border-neutral-400 bg-neutral-400 text-white',
                            )}
                        >
                            {(done && !current) || (last && closed) ? (
                                <Check className="size-3.5" strokeWidth={3} />
                            ) : (
                                index + 1
                            )}
                        </span>
                        <span
                            className={cn(
                                'text-xs',
                                done || (last && closed)
                                    ? 'font-medium text-foreground'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {label}
                        </span>
                    </li>
                );
            })}
        </ol>
    );
}
