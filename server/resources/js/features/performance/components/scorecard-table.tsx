import { Fragment } from 'react';
import { DataTable, TableCard } from '@/components/data-table';
import { Input } from '@/components/ui/input';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { formatLineScore, formatPercent, scaleFraction } from '../constants';
import type { PerformanceScore, SectionResult } from '../types';
import { RatingControl } from './rating-control';

export type ScorecardSection = {
    section: SectionResult;
    /** What the framework said this section is for (snapshot). */
    description: string | null;
    lines: PerformanceScore[];
};

type Props = {
    sections: ScorecardSection[];
    editable: boolean;
    /** Rated criteria across the whole scorecard, for the header count. */
    scored: number;
    total: number;
    onScoreChange: (id: number, value: number) => void;
    onRemarksChange: (id: number, value: string) => void;
};

/** Colour a normalised (0–1) line result, matching the band palette's ordering. */
function fractionTone(fraction: number | null): string {
    if (fraction === null) {
        return 'text-muted-foreground';
    }

    if (fraction >= 0.75) {
        return 'text-emerald-600 dark:text-emerald-400';
    }

    if (fraction >= 0.5) {
        return 'text-[#0a8b91] dark:text-[#0ABFBF]';
    }

    if (fraction >= 0.25) {
        return 'text-amber-600 dark:text-amber-400';
    }

    return 'text-rose-600 dark:text-rose-400';
}

/**
 * The appraisal scorecard as one table, grouped the way its framework was
 * written: each weighted section is a header row — its weight, its running
 * attainment and how much of it is rated — above the criteria it holds.
 *
 * A criterion's weight is shown as its **share of the section**, not as a bare
 * number — "24% of Capability" is a sentence an evaluator can act on; "35" is
 * not.
 */
export function ScorecardTable({
    sections,
    editable,
    scored,
    total,
    onScoreChange,
    onRemarksChange,
}: Props) {
    return (
        <TableCard
            title="Scorecard"
            description={
                editable
                    ? 'Rate every criterion, then submit. The result updates as you go.'
                    : undefined
            }
            actions={
                <span
                    className={cn(
                        'text-xs tabular-nums',
                        scored < total
                            ? 'text-amber-600 dark:text-amber-400'
                            : 'text-muted-foreground',
                    )}
                >
                    {scored} of {total} rated
                </span>
            }
        >
            <DataTable className="[&_tbody_tr]:hover:bg-transparent">
                <TableHeader>
                    <TableRow>
                        <TableHead>Criterion</TableHead>
                        <TableHead className="w-24 text-right">
                            Weight
                        </TableHead>
                        <TableHead className="w-80">Rating</TableHead>
                        <TableHead className="w-72">Evidence</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {sections.map(({ section, description, lines }) => {
                        const lineWeight = lines.reduce(
                            (sum, line) => sum + (line.weight || 0),
                            0,
                        );
                        const complete =
                            section.total > 0 &&
                            section.scored === section.total;

                        return (
                            <Fragment key={section.key}>
                                <TableRow className="bg-muted/30">
                                    <TableCell
                                        colSpan={4}
                                        className="whitespace-normal"
                                    >
                                        <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                                            <div className="min-w-0">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <h2 className="text-sm font-semibold">
                                                        {section.name ??
                                                            'Performance criteria'}
                                                    </h2>
                                                    <span className="rounded-full border border-[#0ABFBF]/25 bg-[#0ABFBF]/10 px-2 py-0.5 text-[11px] font-semibold text-[#0a7d82] tabular-nums dark:text-[#3fd6d6]">
                                                        {section.weight}% of the
                                                        result
                                                    </span>
                                                </div>
                                                {description && (
                                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                                        {description}
                                                    </p>
                                                )}
                                            </div>
                                            <div className="flex shrink-0 items-center gap-3">
                                                <span
                                                    className={cn(
                                                        'text-[11px] tabular-nums',
                                                        complete
                                                            ? 'text-muted-foreground'
                                                            : 'text-amber-600 dark:text-amber-400',
                                                    )}
                                                >
                                                    {section.scored} of{' '}
                                                    {section.total} rated
                                                </span>
                                                <div className="h-1.5 w-20 overflow-hidden rounded-full bg-muted">
                                                    <div
                                                        className="h-full rounded-full bg-[#0ABFBF] transition-all"
                                                        style={{
                                                            width: `${section.percent ?? 0}%`,
                                                        }}
                                                    />
                                                </div>
                                                <span className="w-12 text-right text-sm font-semibold tabular-nums">
                                                    {formatPercent(
                                                        section.percent,
                                                    )}
                                                </span>
                                            </div>
                                        </div>
                                    </TableCell>
                                </TableRow>

                                {lines.map((line) => {
                                    const share =
                                        lineWeight > 0
                                            ? ((line.weight || 0) /
                                                  lineWeight) *
                                              100
                                            : null;
                                    const fraction = scaleFraction(line);

                                    return (
                                        <TableRow
                                            key={line.id}
                                            className="align-top"
                                        >
                                            <TableCell className="whitespace-normal">
                                                <div className="flex min-w-48 flex-wrap items-center gap-x-2 gap-y-1">
                                                    <p className="text-sm font-medium">
                                                        {line.label}
                                                    </p>
                                                    {line.criterion_active ===
                                                        false && (
                                                        <span className="rounded-full border border-border bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
                                                            Archived criterion
                                                        </span>
                                                    )}
                                                </div>
                                                {line.description && (
                                                    <p className="mt-0.5 text-xs leading-relaxed text-muted-foreground">
                                                        {line.description}
                                                    </p>
                                                )}
                                                <p className="mt-0.5 text-[11px] text-muted-foreground/80">
                                                    Rated on{' '}
                                                    <span className="font-medium text-muted-foreground">
                                                        {line.scale_name ??
                                                            line.scale_descriptor}
                                                    </span>
                                                </p>
                                            </TableCell>
                                            <TableCell className="text-right text-sm text-muted-foreground tabular-nums">
                                                {share === null
                                                    ? '—'
                                                    : `${share.toFixed(0)}%`}
                                            </TableCell>
                                            <TableCell className="whitespace-normal">
                                                {editable ? (
                                                    <div className="w-80">
                                                        <RatingControl
                                                            line={line}
                                                            onChange={(value) =>
                                                                onScoreChange(
                                                                    line.id,
                                                                    value,
                                                                )
                                                            }
                                                        />
                                                    </div>
                                                ) : (
                                                    <div className="flex items-baseline gap-2">
                                                        <span
                                                            className={cn(
                                                                'text-sm font-semibold',
                                                                fractionTone(
                                                                    fraction,
                                                                ),
                                                            )}
                                                        >
                                                            {formatLineScore(
                                                                line,
                                                            )}
                                                        </span>
                                                        {line.score ===
                                                            null && (
                                                            <span className="text-xs text-muted-foreground">
                                                                not rated
                                                            </span>
                                                        )}
                                                    </div>
                                                )}
                                            </TableCell>
                                            <TableCell className="whitespace-normal">
                                                {editable ? (
                                                    <Input
                                                        value={
                                                            line.remarks ?? ''
                                                        }
                                                        onChange={(event) =>
                                                            onRemarksChange(
                                                                line.id,
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                        placeholder="Evidence (optional)"
                                                        aria-label={`Comment on ${line.label}`}
                                                        className="h-8 w-72"
                                                    />
                                                ) : (
                                                    <p className="w-72 text-sm text-muted-foreground">
                                                        {line.remarks || '—'}
                                                    </p>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </Fragment>
                        );
                    })}
                </TableBody>
            </DataTable>
        </TableCard>
    );
}
