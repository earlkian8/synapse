import { CircleSlash, Database, Minus } from 'lucide-react';
import { Fragment } from 'react';
import { DataTable, TableCard } from '@/components/data-table';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
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
 * it, as one table grouped by whether the field reaches the score at all: a
 * field the system does not record cannot be fixed by data entry, and a field
 * that is recorded but left out always says why.
 */
export function FieldCoverageTable({
    fields,
    employees,
}: {
    fields: FieldCoverage[];
    employees: number;
}) {
    return (
        <TableCard
            title="What the scores are based on"
            count={fields.length}
            description={`How many of your ${employees.toLocaleString()} active employees’ records carry each field.`}
        >
            <DataTable>
                <TableHeader>
                    <TableRow>
                        <TableHead>Field</TableHead>
                        <TableHead className="w-48">Records</TableHead>
                        <TableHead>What it means</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {FIELD_STATE_ORDER.map((state) => {
                        const rows = fields.filter(
                            (field) => field.state === state,
                        );

                        if (rows.length === 0) {
                            return null;
                        }

                        return (
                            <Fragment key={state}>
                                <TableRow className="bg-muted/30 hover:bg-muted/30">
                                    <TableCell
                                        colSpan={3}
                                        className="py-1.5 whitespace-normal"
                                    >
                                        <span
                                            className={cn(
                                                'rounded-full border px-2 py-0.5 text-[11px] font-medium',
                                                FIELD_STATE_STYLES[state],
                                            )}
                                        >
                                            {FIELD_STATE_LABELS[state]} (
                                            {rows.length})
                                        </span>
                                        <span className="ml-2 text-[11px] text-muted-foreground">
                                            {FIELD_STATE_HINTS[state]}
                                        </span>
                                    </TableCell>
                                </TableRow>
                                {rows.map((field) => (
                                    <FieldRow key={field.key} field={field} />
                                ))}
                            </Fragment>
                        );
                    })}
                </TableBody>
            </DataTable>
        </TableCard>
    );
}

function FieldRow({ field }: { field: FieldCoverage }) {
    const percent = completion(field.covered, field.total);

    return (
        <TableRow>
            <TableCell className="whitespace-normal">
                <p className="text-sm font-medium">{field.label}</p>
                <p className="text-[11px] text-muted-foreground">
                    {field.source}
                </p>
            </TableCell>
            <TableCell>
                {field.total === 0 ? (
                    <span className="text-sm text-muted-foreground">
                        None yet
                    </span>
                ) : (
                    <>
                        <p className="text-sm tabular-nums">
                            <span
                                className={cn(
                                    'font-medium',
                                    field.state === 'missing' &&
                                        'text-muted-foreground',
                                )}
                            >
                                {field.covered.toLocaleString()}
                            </span>
                            <span className="text-muted-foreground">
                                {' '}
                                of {field.total.toLocaleString()} · {percent}%
                            </span>
                        </p>
                        <div
                            className={cn(
                                'mt-1.5 h-1.5 overflow-hidden rounded-full',
                                FIELD_STATE_TRACKS[field.state],
                            )}
                        >
                            <div
                                className={cn(
                                    'h-full rounded-full',
                                    FIELD_STATE_BARS[field.state],
                                )}
                                style={{ width: `${percent}%` }}
                            />
                        </div>
                    </>
                )}
            </TableCell>
            <TableCell className="min-w-56 whitespace-normal">
                <p className="flex items-start gap-1.5 text-xs leading-relaxed text-muted-foreground">
                    <StateIcon state={field.state} />
                    <span>{field.note}</span>
                </p>
            </TableCell>
        </TableRow>
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
