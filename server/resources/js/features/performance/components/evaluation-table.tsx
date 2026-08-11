import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { PersonAvatar } from '@/components/person-avatar';
import { bandFor, formatPercent } from '../constants';
import { performanceRoutes } from '../routes';
import type { PerformanceEvaluation } from '../types';
import { BandChip } from './band-chip';
import { RatingLadder } from './rating-ladder';
import { EvaluationStatusBadge } from './status-badge';

type Props = { evaluations: PerformanceEvaluation[] };

/**
 * The appraisals of a cycle. Each row carries the rating in the company's own
 * words and a miniature of the ladder it sits on, so a reader can compare two
 * people reviewed under two different frameworks without doing arithmetic in
 * their head.
 */
export function EvaluationTable({ evaluations }: Props) {
    return (
        <div className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border">
            <ul className="divide-y divide-border">
                {evaluations.map((evaluation) => {
                    const band =
                        bandFor(evaluation.overall_percent, evaluation.bands) ??
                        null;

                    return (
                        <li key={evaluation.id}>
                            <Link
                                href={performanceRoutes.show(evaluation.hashid)}
                                className="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-3 px-4 py-3 transition-colors hover:bg-muted/50 sm:grid-cols-[auto_minmax(0,1.4fr)_minmax(0,1fr)_auto_auto]"
                            >
                                <PersonAvatar
                                    name={
                                        evaluation.employee?.full_name ??
                                        'Unknown'
                                    }
                                    initials={
                                        evaluation.employee?.initials ?? '?'
                                    }
                                    photo={evaluation.employee?.photo}
                                    className="size-9"
                                />

                                <div className="min-w-0">
                                    <p className="truncate text-sm font-medium">
                                        {evaluation.employee?.full_name ??
                                            'Unknown employee'}
                                    </p>
                                    <p className="truncate text-xs text-muted-foreground">
                                        {evaluation.employee?.position ??
                                            evaluation.employee?.employee_no ??
                                            '—'}
                                        {evaluation.template_name &&
                                            ` · ${evaluation.template_name}`}
                                    </p>
                                </div>

                                <div className="hidden min-w-0 sm:block">
                                    <div className="flex items-center justify-between gap-2">
                                        <BandChip
                                            label={evaluation.result_label}
                                            tone={band?.tone}
                                        />
                                        <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                                            {formatPercent(
                                                evaluation.overall_percent,
                                            )}
                                        </span>
                                    </div>
                                    <RatingLadder
                                        bands={evaluation.bands}
                                        percent={evaluation.overall_percent}
                                        variant="rail"
                                        className="mt-2"
                                    />
                                </div>

                                <EvaluationStatusBadge
                                    status={evaluation.status}
                                />
                                <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}
