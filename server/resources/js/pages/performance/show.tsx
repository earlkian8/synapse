import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    BadgeCheck,
    CheckCircle2,
    Layers,
    Send,
    Trash2,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    acknowledgeEvaluation,
    deleteEvaluation,
    saveEvaluation,
    submitEvaluation,
} from '@/features/performance/api';
import { DecisionSupport } from '@/features/performance/components/decision-support';
import { PerformanceInsights } from '@/features/performance/components/performance-insights';
import { ResultSummary } from '@/features/performance/components/result-summary';
import { SectionCard } from '@/features/performance/components/section-card';
import { EvaluationStatusBadge } from '@/features/performance/components/status-badge';
import { computeResult, formatDate } from '@/features/performance/constants';
import { performanceRoutes } from '@/features/performance/routes';
import type {
    PerformanceScore,
    PerformanceShowPageProps,
} from '@/features/performance/types';

export default function PerformanceShow() {
    const {
        evaluation,
        result: savedResult,
        support,
        can,
    } = usePage<PerformanceShowPageProps>().props;

    const employee = evaluation.employee;
    const editable = can.manage && evaluation.status === 'draft';

    // Editable working copy of the scorecard (kept in sync with our own saves).
    const [lines, setLines] = useState<PerformanceScore[]>(
        () => evaluation.scores ?? [],
    );
    const [remarks, setRemarks] = useState(evaluation.remarks ?? '');
    const [processing, setProcessing] = useState(false);
    const [confirm, setConfirm] = useState<'submit' | 'delete' | null>(null);

    // While a draft is being filled in the result is derived here, on the same
    // rules the server applies on save — so the ladder moves as HR types.
    const result = useMemo(
        () => (editable ? computeResult(lines, evaluation.bands) : savedResult),
        [editable, lines, evaluation.bands, savedResult],
    );

    // The scorecard, grouped the way its framework was written.
    const sections = useMemo(() => {
        const described = new Map(
            evaluation.template_sections.map((section) => [
                section.key,
                section.description,
            ]),
        );

        return result.sections.map((section) => ({
            section,
            description: described.get(section.key) ?? null,
            lines: lines.filter(
                (line) => (line.section_key || 'overall') === section.key,
            ),
        }));
    }, [result.sections, evaluation.template_sections, lines]);

    const setScore = (id: number, score: number) =>
        setLines((prev) =>
            prev.map((line) => (line.id === id ? { ...line, score } : line)),
        );

    const setRemark = (id: number, value: string) =>
        setLines((prev) =>
            prev.map((line) =>
                line.id === id ? { ...line, remarks: value } : line,
            ),
        );

    const handlers = {
        onStart: () => setProcessing(true),
        onFinish: () => {
            setProcessing(false);
            setConfirm(null);
        },
    };

    const save = () =>
        saveEvaluation(
            evaluation.hashid,
            {
                remarks: remarks || null,
                scores: lines.map((line) => ({
                    id: line.id,
                    score: line.score,
                    remarks: line.remarks,
                })),
            },
            handlers,
        );

    const complete = result.total > 0 && result.scored === result.total;

    return (
        <>
            <Head
                title={`Appraisal — ${employee?.full_name ?? 'Performance'}`}
            />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <Link
                    href={performanceRoutes.forPeriod(
                        evaluation.period?.id ?? null,
                    )}
                    className="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    Back to the cycle
                </Link>

                {/* Who, which cycle, under which framework — then the result. */}
                <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,26rem)]">
                    <div className="rounded-xl border border-sidebar-border/70 bg-card p-5 shadow-sm dark:border-sidebar-border">
                        <div className="flex min-w-0 items-start gap-3.5">
                            <PersonAvatar
                                name={employee?.full_name ?? 'Unknown'}
                                initials={employee?.initials ?? '?'}
                                photo={employee?.photo}
                                className="size-12"
                            />
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h1 className="text-xl font-semibold tracking-tight">
                                        {employee?.full_name ??
                                            'Unknown employee'}
                                    </h1>
                                    <EvaluationStatusBadge
                                        status={evaluation.status}
                                    />
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    {employee?.position ?? '—'}
                                    {employee?.department
                                        ? ` · ${employee.department}`
                                        : ''}
                                </p>
                            </div>
                        </div>

                        <dl className="mt-4 grid gap-3 border-t border-border pt-4 sm:grid-cols-3">
                            <Fact label="Review cycle">
                                {evaluation.period?.name ?? '—'}
                                {evaluation.period && (
                                    <span className="block text-xs text-muted-foreground tabular-nums">
                                        {formatDate(
                                            evaluation.period.start_date,
                                        )}{' '}
                                        –{' '}
                                        {formatDate(evaluation.period.end_date)}
                                    </span>
                                )}
                            </Fact>
                            <Fact label="Framework">
                                <span className="inline-flex items-center gap-1.5">
                                    <Layers className="size-3.5 shrink-0 text-muted-foreground" />
                                    {evaluation.template_name ?? 'Standard'}
                                </span>
                                <span className="block text-xs text-muted-foreground">
                                    {evaluation.bands.length} rating bands
                                </span>
                            </Fact>
                            <Fact label="Evaluator">
                                {evaluation.evaluator?.name ?? 'Not recorded'}
                                {evaluation.submitted_at && (
                                    <span className="block text-xs text-muted-foreground">
                                        Submitted{' '}
                                        {formatTimestamp(
                                            evaluation.submitted_at,
                                        )}
                                    </span>
                                )}
                                {evaluation.acknowledged_at && (
                                    <span className="block text-xs text-muted-foreground">
                                        Signed off{' '}
                                        {formatTimestamp(
                                            evaluation.acknowledged_at,
                                        )}
                                    </span>
                                )}
                            </Fact>
                        </dl>
                    </div>

                    <ResultSummary
                        result={result}
                        bands={evaluation.bands}
                        display={evaluation.result_display}
                        live={editable}
                    />
                </div>

                {/* Decision support: ML forecast, trajectory, strengths & gaps */}
                <DecisionSupport support={support} scores={lines} />

                {/* The scorecard, section by weighted section. */}
                {sections.map(
                    ({ section, description, lines: sectionLines }) => (
                        <SectionCard
                            key={section.key}
                            section={section}
                            description={description}
                            lines={sectionLines}
                            editable={editable}
                            onScoreChange={setScore}
                            onRemarksChange={setRemark}
                        />
                    ),
                )}

                {/* Overall remarks */}
                <div className="rounded-xl border border-sidebar-border/70 bg-card p-5 shadow-sm dark:border-sidebar-border">
                    <label
                        htmlFor="overall-remarks"
                        className="mb-2 block text-sm font-semibold"
                    >
                        Overall remarks
                    </label>
                    {editable ? (
                        <textarea
                            id="overall-remarks"
                            value={remarks}
                            onChange={(event) => setRemarks(event.target.value)}
                            rows={3}
                            placeholder="Summary, strengths and areas to develop (optional)"
                            className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-colors placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                        />
                    ) : (
                        <p className="text-sm whitespace-pre-line text-muted-foreground">
                            {evaluation.remarks || 'No remarks recorded.'}
                        </p>
                    )}
                </div>

                {/* AI coaching insights (LLM) */}
                {support.ai_available && (
                    <PerformanceInsights
                        key={evaluation.hashid}
                        hashid={evaluation.hashid}
                        saved={evaluation.ai_insights}
                    />
                )}

                {/* Actions */}
                {can.manage &&
                    (evaluation.status === 'draft' ||
                        evaluation.status === 'submitted') && (
                        <div className="sticky bottom-0 -mx-4 flex flex-wrap items-center justify-between gap-2 border-t border-border bg-background/85 px-4 py-3 backdrop-blur md:-mx-6 md:px-6">
                            {evaluation.status === 'draft' ? (
                                <>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="text-muted-foreground hover:text-destructive"
                                        onClick={() => setConfirm('delete')}
                                        disabled={processing}
                                    >
                                        <Trash2 className="size-4" />
                                        Delete draft
                                    </Button>
                                    <div className="flex items-center gap-2">
                                        <Button
                                            variant="outline"
                                            onClick={save}
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            Save
                                        </Button>
                                        <Button
                                            onClick={() => setConfirm('submit')}
                                            disabled={processing || !complete}
                                            title={
                                                complete
                                                    ? undefined
                                                    : `Rate all ${result.total} criteria first`
                                            }
                                        >
                                            <Send className="size-4" />
                                            Submit
                                        </Button>
                                    </div>
                                </>
                            ) : (
                                <div className="ml-auto">
                                    <Button
                                        onClick={() =>
                                            acknowledgeEvaluation(
                                                evaluation.hashid,
                                                handlers,
                                            )
                                        }
                                        disabled={processing}
                                    >
                                        <BadgeCheck className="size-4" />
                                        Record sign-off
                                    </Button>
                                </div>
                            )}
                        </div>
                    )}

                {evaluation.status === 'acknowledged' && (
                    <div className="flex items-center justify-center gap-2 rounded-xl border border-dashed border-emerald-500/30 bg-emerald-500/5 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-400">
                        <CheckCircle2 className="size-4" />
                        Signed off. This appraisal is final.
                    </div>
                )}
            </div>

            <ConfirmDialog
                open={confirm === 'submit'}
                onOpenChange={(open) => !open && setConfirm(null)}
                title="Submit this appraisal?"
                description={
                    result.band
                        ? `It will be recorded as “${result.band.label}” and locked. Ratings can no longer be changed.`
                        : 'Submitting locks the scorecard and finalises the result. It can no longer be edited.'
                }
                confirmLabel="Submit"
                processing={processing}
                onConfirm={() => submitEvaluation(evaluation.hashid, handlers)}
            />

            <ConfirmDialog
                open={confirm === 'delete'}
                onOpenChange={(open) => !open && setConfirm(null)}
                title="Delete this draft?"
                description={`The draft appraisal for ${employee?.full_name ?? 'this employee'} will be permanently removed.`}
                confirmLabel="Delete"
                destructive
                processing={processing}
                onConfirm={() => deleteEvaluation(evaluation.hashid, handlers)}
            />
        </>
    );
}

function Fact({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="min-w-0">
            <dt className="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </dt>
            <dd className="mt-0.5 text-sm font-medium">{children}</dd>
        </div>
    );
}

/** Format an ISO timestamp as "Jun 16, 2026". */
function formatTimestamp(iso: string): string {
    return new Date(iso).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

PerformanceShow.layout = {
    breadcrumbs: [{ title: 'Performance', href: '/performance' }],
};
