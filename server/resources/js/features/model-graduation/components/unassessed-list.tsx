import { ChevronDown, UserX } from 'lucide-react';
import { useState } from 'react';
import { PersonAvatar } from '@/components/person-avatar';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { cn } from '@/lib/utils';

export type UnassessedReason =
    'no_appraisal' | 'appraisal_in_progress' | 'none_before_period';

export type UnassessedEmployee = {
    reason: UnassessedReason;
    employee: {
        id: number;
        full_name: string;
        initials: string;
        employee_no: string;
        photo: string | null;
        position: string | null;
        department: string | null;
    };
};

/** Why a record was not scored, and what would include it next time. */
const REASONS: Record<UnassessedReason, { label: string; action: string }> = {
    no_appraisal: {
        label: 'No appraisal on record',
        action: 'Complete an appraisal for them',
    },
    appraisal_in_progress: {
        label: 'Appraisal still in draft',
        action: 'Submit their appraisal',
    },
    none_before_period: {
        label: 'Only appraised in the period being forecast',
        action: 'Included once a later period is forecast',
    },
};

/**
 * The people a model declined to score. A model that guessed for them would put
 * an invented number on the page; this says who is missing, why, and what would
 * include them — so the roster above is complete in what it claims.
 */
export function UnassessedList({
    unassessed,
    noun,
}: {
    unassessed: UnassessedEmployee[];
    /** What was not done to them, e.g. "assessed" or "forecast". */
    noun: string;
}) {
    const [open, setOpen] = useState(false);

    if (unassessed.length === 0) {
        return null;
    }

    const counts = unassessed.reduce<Partial<Record<UnassessedReason, number>>>(
        (acc, entry) => ({
            ...acc,
            [entry.reason]: (acc[entry.reason] ?? 0) + 1,
        }),
        {},
    );

    return (
        <Collapsible
            open={open}
            onOpenChange={setOpen}
            className="rounded-xl border border-sidebar-border/70 bg-card/60 dark:border-sidebar-border"
        >
            <CollapsibleTrigger className="flex w-full items-center gap-3 px-4 py-3 text-left">
                <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-slate-500/10 text-slate-600 dark:text-slate-300">
                    <UserX className="size-4" />
                </span>
                <span className="flex min-w-0 flex-1 flex-col">
                    <span className="text-sm font-medium">
                        {unassessed.length} not {noun}
                    </span>
                    <span className="truncate text-xs text-muted-foreground">
                        {Object.entries(counts)
                            .map(
                                ([reason, n]) =>
                                    `${n} · ${REASONS[reason as UnassessedReason].label.toLowerCase()}`,
                            )
                            .join('  ·  ')}
                        {' — nothing is guessed for them'}
                    </span>
                </span>
                <ChevronDown
                    className={cn(
                        'size-4 shrink-0 text-muted-foreground transition-transform',
                        open && 'rotate-180',
                    )}
                />
            </CollapsibleTrigger>
            <CollapsibleContent>
                <ul className="divide-y divide-border border-t border-border">
                    {unassessed.map(({ employee, reason }) => (
                        <li
                            key={employee.id}
                            className="flex items-center gap-3 px-4 py-2.5"
                        >
                            <PersonAvatar
                                name={employee.full_name}
                                initials={employee.initials}
                                photo={employee.photo}
                                className="size-8"
                            />
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm">
                                    {employee.full_name}
                                </p>
                                <p className="truncate text-xs text-muted-foreground">
                                    {employee.position ?? employee.employee_no}
                                    {employee.department
                                        ? ` · ${employee.department}`
                                        : ''}
                                </p>
                            </div>
                            <div className="flex shrink-0 flex-col items-end text-right">
                                <span className="text-xs font-medium">
                                    {REASONS[reason].label}
                                </span>
                                <span className="text-[11px] text-muted-foreground">
                                    {REASONS[reason].action}
                                </span>
                            </div>
                        </li>
                    ))}
                </ul>
            </CollapsibleContent>
        </Collapsible>
    );
}
