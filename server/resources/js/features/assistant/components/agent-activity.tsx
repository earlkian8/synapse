import {
    ArchiveX,
    Check,
    Loader2,
    PencilLine,
    UserPlus,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { PersonAvatar } from '@/components/person-avatar';
import { cn } from '@/lib/utils';
import type { AgentAction, AgentActionType, AgentStep } from '../types';

const ACTION_META: Record<
    AgentActionType,
    { label: string; icon: typeof UserPlus; tone: string }
> = {
    created: {
        label: 'Added',
        icon: UserPlus,
        tone: 'bg-emerald-500/12 text-emerald-600 dark:text-emerald-400',
    },
    updated: {
        label: 'Updated',
        icon: PencilLine,
        tone: 'bg-sky-500/12 text-sky-600 dark:text-sky-400',
    },
    archived: {
        label: 'Archived',
        icon: ArchiveX,
        tone: 'bg-amber-500/12 text-amber-600 dark:text-amber-400',
    },
};

/**
 * Reveals the agent's steps and result cards one at a time so a finished
 * server response still *feels* like live, deliberate work.
 */
export function AgentActivity({
    steps,
    actions,
    onRevealed,
}: {
    steps: AgentStep[];
    actions: AgentAction[];
    onRevealed?: () => void;
}) {
    const total = steps.length + actions.length;
    const [revealed, setRevealed] = useState(0);

    useEffect(() => {
        if (revealed >= total) {
            onRevealed?.();

            return;
        }

        const delay = revealed === 0 ? 200 : 520;
        const timer = setTimeout(
            () => setRevealed((value) => value + 1),
            delay,
        );

        return () => clearTimeout(timer);
    }, [revealed, total, onRevealed]);

    if (total === 0) {
        return null;
    }

    const visibleSteps = Math.min(revealed, steps.length);
    const visibleActions = Math.max(0, revealed - steps.length);

    return (
        <div className="mt-1 flex flex-col gap-2">
            {steps.length > 0 && (
                <ol className="flex flex-col gap-1.5 rounded-lg border border-border/70 bg-muted/40 p-2.5">
                    {steps.slice(0, visibleSteps).map((step, index) => (
                        <li
                            key={index}
                            className="flex animate-in items-start gap-2 text-xs duration-300 fade-in slide-in-from-left-1"
                        >
                            <StepIcon
                                status={step.status}
                                isLast={
                                    index === visibleSteps - 1 &&
                                    revealed < total
                                }
                            />
                            <span className="min-w-0">
                                <span className="font-medium text-foreground">
                                    {step.label}
                                </span>
                                {step.detail && (
                                    <span className="text-muted-foreground">
                                        {' '}
                                        · {step.detail}
                                    </span>
                                )}
                            </span>
                        </li>
                    ))}
                </ol>
            )}

            {actions.slice(0, visibleActions).map((action) => (
                <ResultCard
                    key={action.employee.id + action.type}
                    action={action}
                />
            ))}
        </div>
    );
}

function StepIcon({ status, isLast }: { status: string; isLast: boolean }) {
    if (isLast && status === 'done') {
        return (
            <Loader2 className="mt-px size-3.5 shrink-0 animate-spin text-[#0ABFBF]" />
        );
    }

    if (status === 'error') {
        return (
            <span className="mt-px flex size-3.5 shrink-0 items-center justify-center rounded-full bg-destructive/15">
                <X className="size-2.5 text-destructive" />
            </span>
        );
    }

    return (
        <span className="mt-px flex size-3.5 shrink-0 items-center justify-center rounded-full bg-emerald-500/15">
            <Check className="size-2.5 text-emerald-600 dark:text-emerald-400" />
        </span>
    );
}

function ResultCard({ action }: { action: AgentAction }) {
    const meta = ACTION_META[action.type];
    const Icon = meta.icon;
    const employee = action.employee;
    const subtitle = [employee.position, employee.department]
        .filter(Boolean)
        .join(' · ');

    return (
        <div className="relative animate-in overflow-hidden rounded-xl border border-border bg-card p-3 shadow-sm duration-500 zoom-in-95 fade-in slide-in-from-bottom-1">
            {/* one-shot sheen sweeping across on reveal */}
            <span className="pointer-events-none absolute inset-0 -translate-x-full animate-[assistant-sheen_1.1s_ease-out_forwards] bg-gradient-to-r from-transparent via-[#0ABFBF]/10 to-transparent" />
            <div className="flex items-center gap-3">
                <span className="animate-[assistant-pop_0.5s_ease-out]">
                    <PersonAvatar
                        name={employee.full_name}
                        initials={employee.initials}
                        photo={employee.photo}
                        className="size-10 ring-2 ring-[#0ABFBF]/30"
                    />
                </span>
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-semibold">
                        {employee.full_name}
                    </p>
                    <p className="truncate text-xs text-muted-foreground">
                        <span className="font-mono">
                            {employee.employee_no}
                        </span>
                        {subtitle && <> · {subtitle}</>}
                    </p>
                </div>
                <span
                    className={cn(
                        'inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold',
                        meta.tone,
                    )}
                >
                    <Icon className="size-3" />
                    {meta.label}
                </span>
            </div>
        </div>
    );
}
