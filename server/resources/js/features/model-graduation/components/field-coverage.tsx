import { ChevronDown, CircleSlash, Database, Minus } from 'lucide-react';
import { useState } from 'react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { cn } from '@/lib/utils';
import {
    completion,
    FIELD_STATE_BARS,
    FIELD_STATE_HINTS,
    FIELD_STATE_LABELS,
    FIELD_STATE_ORDER,
    FIELD_STATE_STYLES,
    FIELD_STATE_TRACKS,
} from '../constants';
import type { FieldCoverage, FieldState } from '../types';

/**
 * Every field a score draws on — or could — with how many of your records carry
 * it. Grouped by whether it reaches the score at all: a field the system does not
 * record cannot be fixed by data entry, and a field that is recorded but left out
 * always says why.
 *
 * Secondary to the checklist, so it starts closed.
 */
export function FieldCoverageTable({
    fields,
    employees,
}: {
    fields: FieldCoverage[];
    employees: number;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Collapsible open={open} onOpenChange={setOpen}>
            <CollapsibleTrigger className="flex w-full items-center justify-between gap-4 rounded-lg px-1 py-1 text-left">
                <span>
                    <span className="block text-sm font-semibold">
                        What the scores are based on
                    </span>
                    <span className="block text-xs text-muted-foreground">
                        {fields.length} fields, and how many of your{' '}
                        {employees.toLocaleString()} active employees’ records
                        carry each.
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
                <div className="mt-3 flex flex-col gap-4">
                    {FIELD_STATE_ORDER.map((state) => {
                        const rows = fields.filter(
                            (field) => field.state === state,
                        );

                        if (rows.length === 0) {
                            return null;
                        }

                        return (
                            <section key={state}>
                                <header className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 px-1">
                                    <span
                                        className={cn(
                                            'rounded-full border px-2 py-0.5 text-[11px] font-medium',
                                            FIELD_STATE_STYLES[state],
                                        )}
                                    >
                                        {FIELD_STATE_LABELS[state]}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {FIELD_STATE_HINTS[state]}
                                    </span>
                                </header>

                                <ul className="mt-2 divide-y divide-sidebar-border/70 overflow-hidden rounded-xl border border-sidebar-border/70 dark:divide-sidebar-border dark:border-sidebar-border">
                                    {rows.map((field) => (
                                        <FieldRow
                                            key={field.key}
                                            field={field}
                                        />
                                    ))}
                                </ul>
                            </section>
                        );
                    })}
                </div>
            </CollapsibleContent>
        </Collapsible>
    );
}

function FieldRow({ field }: { field: FieldCoverage }) {
    const percent = completion(field.covered, field.total);
    const missing = field.state === 'missing';

    return (
        <li className="px-3.5 py-3">
            <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                <p className="text-sm font-medium">
                    {field.label}
                    <span className="ml-2 text-[11px] font-normal text-muted-foreground">
                        {field.source}
                    </span>
                </p>
                <p
                    className={cn(
                        'shrink-0 text-sm font-semibold tabular-nums',
                        missing && 'text-muted-foreground',
                    )}
                >
                    {field.total === 0 ? (
                        <span className="font-normal text-muted-foreground">
                            none yet
                        </span>
                    ) : (
                        <>
                            {field.covered.toLocaleString()}
                            <span className="font-normal text-muted-foreground">
                                {' '}
                                of {field.total.toLocaleString()} · {percent}%
                            </span>
                        </>
                    )}
                </p>
            </div>

            <div
                className={cn(
                    'mt-2 h-1.5 overflow-hidden rounded-full',
                    FIELD_STATE_TRACKS[field.state],
                )}
            >
                <div
                    className={cn(
                        'h-full rounded-full',
                        FIELD_STATE_BARS[field.state],
                    )}
                    style={{ width: `${field.total === 0 ? 0 : percent}%` }}
                />
            </div>

            <p className="mt-2 flex items-start gap-1.5 text-xs text-muted-foreground">
                <StateIcon state={field.state} />
                <span>{field.note}</span>
            </p>
        </li>
    );
}

function StateIcon({ state }: { state: FieldState }) {
    const Icon =
        state === 'supplied'
            ? Database
            : state === 'available'
              ? Minus
              : CircleSlash;

    return <Icon className="mt-0.5 size-3 shrink-0" aria-hidden="true" />;
}
