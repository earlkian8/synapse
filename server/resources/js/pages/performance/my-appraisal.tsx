import { Head, usePage } from '@inertiajs/react';
import {
    BadgeCheck,
    CalendarRange,
    CheckCircle2,
    Layers,
    Scale,
    UserPen,
} from 'lucide-react';
import { Fragment, useMemo, useState } from 'react';
import {
    DataTable,
    PageBody,
    PageHeader,
    TableCard,
} from '@/components/data-table';
import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { acknowledgeOwnAppraisal } from '@/features/performance/api';
import { ResultSummary } from '@/features/performance/components/result-summary';
import { EvaluationStatusBadge } from '@/features/performance/components/status-badge';
import {
    formatDate,
    formatLineScore,
    formatPercent,
    formatTimestamp,
    TEXTAREA,
} from '@/features/performance/constants';
import { performanceRoutes } from '@/features/performance/routes';
import type { MyAppraisalPageProps } from '@/features/performance/types';
import { cn } from '@/lib/utils';

/**
 * One of the employee's own appraisals, shared with them (ADR 0072): the result
 * on its rating ladder, every criterion as the evaluator rated it with the
 * evidence given — and, beside it, what the employee said in their
 * self-review. Then the acknowledgement: it says they have read it, not that
 * they agree, and the comment is where they can say so.
 */
export default function MyAppraisal() {
    const { evaluation, result, selfReview } =
        usePage<MyAppraisalPageProps>().props;

    const [comment, setComment] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);

    const calibrated =
        evaluation.scored_band !== null
            ? (evaluation.bands.find(
                  (band) => band.key === evaluation.result_band,
              ) ?? null)
            : null;

    const sections = useMemo(() => {
        const lines = evaluation.scores ?? [];

        return result.sections.map((section) => ({
            section,
            lines: lines.filter(
                (line) => (line.section_key || 'overall') === section.key,
            ),
        }));
    }, [evaluation.scores, result.sections]);

    const acknowledge = () =>
        acknowledgeOwnAppraisal(evaluation.hashid, comment.trim() || null, {
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onError: (errors) => setError(errors.comment),
        });

    const period = evaluation.period;

    return (
        <>
            <Head title={`My appraisal — ${period?.name ?? 'Performance'}`} />

            <PageBody>
                <PageHeader
                    back={{
                        href: performanceRoutes.me,
                        label: 'Back to my appraisals',
                    }}
                    title={`${period?.name ?? 'Your'} appraisal`}
                    badges={
                        <EvaluationStatusBadge
                            status={evaluation.status}
                            label={
                                evaluation.status === 'acknowledged'
                                    ? 'Acknowledged'
                                    : 'To acknowledge'
                            }
                        />
                    }
                    description={
                        <span className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs">
                            {period && (
                                <span className="inline-flex items-center gap-1.5 tabular-nums">
                                    <CalendarRange className="size-3.5" />
                                    {formatDate(period.start_date)} –{' '}
                                    {formatDate(period.end_date)}
                                </span>
                            )}
                            <span className="inline-flex items-center gap-1.5">
                                <Layers className="size-3.5" />
                                {evaluation.template_name ?? 'Standard'}
                            </span>
                            <span className="inline-flex items-center gap-1.5">
                                <UserPen className="size-3.5" />
                                Rated by{' '}
                                {evaluation.evaluator?.name ?? 'your evaluator'}
                            </span>
                        </span>
                    }
                />

                <ResultSummary
                    result={result}
                    bands={evaluation.bands}
                    display={evaluation.result_display}
                    calibrated={calibrated}
                />

                {calibrated && (
                    <p className="flex items-start gap-2.5 rounded-xl border border-dashed border-border bg-muted/30 px-4 py-3 text-sm text-muted-foreground">
                        <Scale className="mt-0.5 size-4 shrink-0" />
                        Calibration moved your rating from “
                        {evaluation.scored_label}” to “{calibrated.label}”, so
                        ratings are read the same way across the company. Your
                        attainment of{' '}
                        {formatPercent(evaluation.overall_percent)} is as it was
                        scored.
                    </p>
                )}

                <TableCard
                    title="Scorecard"
                    description={
                        selfReview
                            ? 'Each criterion as your evaluator rated it, beside your self-review.'
                            : 'Each criterion as your evaluator rated it.'
                    }
                >
                    <DataTable className="[&_tbody_tr]:hover:bg-transparent">
                        <TableHeader>
                            <TableRow>
                                <TableHead>Criterion</TableHead>
                                <TableHead className="w-32 text-right">
                                    Rating
                                </TableHead>
                                {selfReview && (
                                    <TableHead className="w-32 text-right">
                                        You said
                                    </TableHead>
                                )}
                                <TableHead className="w-72">Evidence</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {sections.map(({ section, lines }) => (
                                <Fragment key={section.key}>
                                    <TableRow className="bg-muted/30">
                                        <TableCell
                                            colSpan={selfReview ? 4 : 3}
                                            className="whitespace-normal"
                                        >
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <p className="text-sm font-semibold">
                                                    {section.name ??
                                                        'Performance criteria'}
                                                    <span className="ml-2 text-xs font-normal text-muted-foreground">
                                                        {section.weight}% of the
                                                        result
                                                    </span>
                                                </p>
                                                <span className="text-sm font-semibold tabular-nums">
                                                    {formatPercent(
                                                        section.percent,
                                                    )}
                                                </span>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                    {lines.map((line) => {
                                        const mine = selfReview?.lines[line.id];

                                        return (
                                            <TableRow
                                                key={line.id}
                                                className="align-top"
                                            >
                                                <TableCell className="whitespace-normal">
                                                    <p className="min-w-40 text-sm font-medium">
                                                        {line.label}
                                                    </p>
                                                    {line.description && (
                                                        <p className="mt-0.5 text-xs text-muted-foreground">
                                                            {line.description}
                                                        </p>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right text-sm font-semibold">
                                                    {formatLineScore(line)}
                                                </TableCell>
                                                {selfReview && (
                                                    <TableCell className="text-right text-sm text-muted-foreground">
                                                        {mine?.formatted ?? '—'}
                                                    </TableCell>
                                                )}
                                                <TableCell className="whitespace-normal">
                                                    <p className="w-72 text-sm text-muted-foreground">
                                                        {line.remarks || '—'}
                                                    </p>
                                                    {mine?.remarks && (
                                                        <p className="mt-1 w-72 text-xs text-muted-foreground/80">
                                                            You: “{mine.remarks}
                                                            ”
                                                        </p>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </Fragment>
                            ))}
                        </TableBody>
                    </DataTable>
                </TableCard>

                <div
                    className={cn('grid gap-4', selfReview && 'lg:grid-cols-2')}
                >
                    <div className="rounded-xl border border-sidebar-border/70 bg-card px-4 py-3 dark:border-sidebar-border">
                        <p className="text-sm font-semibold">
                            Your evaluator’s remarks
                        </p>
                        <p className="mt-1.5 text-sm whitespace-pre-line text-muted-foreground">
                            {evaluation.remarks || 'No remarks recorded.'}
                        </p>
                    </div>
                    {selfReview && (
                        <div className="rounded-xl border border-sidebar-border/70 bg-card px-4 py-3 dark:border-sidebar-border">
                            <p className="text-sm font-semibold">
                                Your self-review
                            </p>
                            <dl className="mt-1.5 space-y-2 text-sm">
                                <div>
                                    <dt className="text-xs font-medium">
                                        What went well
                                    </dt>
                                    <dd className="whitespace-pre-line text-muted-foreground">
                                        {selfReview.strengths || '—'}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs font-medium">
                                        What you want to develop
                                    </dt>
                                    <dd className="whitespace-pre-line text-muted-foreground">
                                        {selfReview.improvements || '—'}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    )}
                </div>

                {evaluation.status === 'acknowledged' ? (
                    <div className="rounded-xl border border-emerald-500/30 bg-emerald-500/5 px-4 py-3">
                        <p className="flex items-center gap-2 text-sm font-medium text-emerald-700 dark:text-emerald-400">
                            <CheckCircle2 className="size-4" />
                            {evaluation.acknowledged_by?.is_employee
                                ? `You acknowledged it ${formatTimestamp(evaluation.acknowledged_at)}.`
                                : `HR recorded your sign-off ${formatTimestamp(evaluation.acknowledged_at)}.`}
                        </p>
                        {evaluation.employee_comment && (
                            <blockquote className="mt-2 border-l-2 border-emerald-500/40 pl-3 text-sm whitespace-pre-line text-muted-foreground">
                                “{evaluation.employee_comment}”
                            </blockquote>
                        )}
                    </div>
                ) : (
                    <section
                        aria-labelledby="acknowledge-heading"
                        className="rounded-xl border border-[#0ABFBF]/40 bg-card px-4 py-4"
                    >
                        <h2
                            id="acknowledge-heading"
                            className="text-sm font-semibold"
                        >
                            Acknowledge your appraisal
                        </h2>
                        <p className="mt-1 max-w-prose text-sm text-muted-foreground">
                            Acknowledging says you’ve read it, not that you
                            agree with every rating. If you see something
                            differently, say so below. Your evaluator reads it,
                            and it stays with the appraisal.
                        </p>
                        <FormField
                            label="Your comment"
                            className="mt-3"
                            error={error}
                        >
                            <textarea
                                value={comment}
                                onChange={(e) => setComment(e.target.value)}
                                rows={3}
                                maxLength={2000}
                                placeholder="Optional"
                                className={TEXTAREA}
                            />
                        </FormField>
                        <div className="mt-3 flex justify-end">
                            <Button onClick={acknowledge} disabled={processing}>
                                {processing ? (
                                    <Spinner />
                                ) : (
                                    <BadgeCheck className="size-4" />
                                )}
                                Acknowledge
                            </Button>
                        </div>
                    </section>
                )}
            </PageBody>
        </>
    );
}

MyAppraisal.layout = (props: MyAppraisalPageProps) => ({
    breadcrumbs: [
        { title: 'Performance Management', href: '/performance' },
        { title: 'My appraisals', href: '/performance/me' },
        {
            title: props.evaluation.period?.name ?? 'Appraisal',
            href: performanceRoutes.myAppraisal(props.evaluation.hashid),
        },
    ],
});
