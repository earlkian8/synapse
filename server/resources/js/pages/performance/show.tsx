import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    BadgeCheck,
    CheckCircle2,
    Send,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
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
import { ScoreRow } from '@/features/performance/components/score-row';
import { EvaluationStatusBadge } from '@/features/performance/components/status-badge';
import {
    computeOverall,
    formatDate,
    formatScore,
    ratingLabelForScore,
    scoreBarTone,
    scoreTone,
} from '@/features/performance/constants';
import { performanceRoutes } from '@/features/performance/routes';
import type {
    PerformanceScore,
    PerformanceShowPageProps,
} from '@/features/performance/types';
import { cn } from '@/lib/utils';

export default function PerformanceShow() {
    const { evaluation, can } = usePage<PerformanceShowPageProps>().props;
    const employee = evaluation.employee;
    const editable = can.manage && evaluation.status === 'draft';

    // Editable working copy of the scorecard (kept in sync with our own saves).
    const [lines, setLines] = useState<PerformanceScore[]>(
        () => evaluation.scores ?? [],
    );
    const [remarks, setRemarks] = useState(evaluation.remarks ?? '');
    const [processing, setProcessing] = useState(false);
    const [confirm, setConfirm] = useState<'submit' | 'delete' | null>(null);

    const liveOverall = editable
        ? computeOverall(lines)
        : evaluation.overall_score;
    const allScored = lines.length > 0 && lines.every((l) => l.score !== null);

    const setScore = (id: number, score: number) =>
        setLines((prev) =>
            prev.map((l) => (l.id === id ? { ...l, score } : l)),
        );

    const setRemark = (id: number, value: string) =>
        setLines((prev) =>
            prev.map((l) => (l.id === id ? { ...l, remarks: value } : l)),
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
                scores: lines.map((l) => ({
                    id: l.id,
                    score: l.score,
                    remarks: l.remarks,
                })),
            },
            handlers,
        );

    return (
        <>
            <Head
                title={`Performance — ${employee?.full_name ?? 'Evaluation'}`}
            />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <Link
                    href={performanceRoutes.index}
                    className="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    All evaluations
                </Link>

                {/* Header */}
                <div className="flex flex-col gap-5 rounded-xl border border-sidebar-border/70 bg-card p-5 shadow-sm dark:border-sidebar-border">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div className="flex min-w-0 items-start gap-3">
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
                                <p className="mt-1 text-sm text-muted-foreground">
                                    <span className="font-medium text-foreground">
                                        {evaluation.period?.name ?? '—'}
                                    </span>
                                    {evaluation.period && (
                                        <span className="tabular-nums">
                                            {' · '}
                                            {formatDate(
                                                evaluation.period.start_date,
                                            )}{' '}
                                            –{' '}
                                            {formatDate(
                                                evaluation.period.end_date,
                                            )}
                                        </span>
                                    )}
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {evaluation.evaluator
                                        ? `Evaluator: ${evaluation.evaluator.name}`
                                        : 'No evaluator on record'}
                                    {evaluation.submitted_at &&
                                        ` · Submitted ${formatTimestamp(evaluation.submitted_at)}`}
                                    {evaluation.acknowledged_at &&
                                        ` · Acknowledged ${formatTimestamp(evaluation.acknowledged_at)}`}
                                </p>
                            </div>
                        </div>

                        {/* Overall score */}
                        <div className="shrink-0 sm:text-right">
                            <span className="text-[11px] tracking-wide text-muted-foreground uppercase">
                                Overall score
                            </span>
                            <div className="flex items-baseline gap-1 sm:justify-end">
                                <span
                                    className={cn(
                                        'text-3xl font-semibold tracking-tight tabular-nums',
                                        scoreTone(liveOverall),
                                    )}
                                >
                                    {formatScore(liveOverall)}
                                </span>
                                <span className="text-sm text-muted-foreground">
                                    / 5.00
                                </span>
                            </div>
                            <span className="text-xs text-muted-foreground">
                                {ratingLabelForScore(liveOverall) ??
                                    'Not yet scored'}
                            </span>
                            <div className="mt-2 h-1.5 w-40 overflow-hidden rounded-full bg-muted sm:ml-auto">
                                <div
                                    className={cn(
                                        'h-full rounded-full transition-all',
                                        scoreBarTone(liveOverall),
                                    )}
                                    style={{
                                        width: `${((liveOverall ?? 0) / 5) * 100}%`,
                                    }}
                                />
                            </div>
                        </div>
                    </div>
                </div>

                {/* Scorecard */}
                <div className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border">
                    <div className="flex items-center justify-between border-b border-border px-4 py-3">
                        <p className="text-sm font-semibold">KPI scorecard</p>
                        <span className="text-xs text-muted-foreground">
                            {editable
                                ? 'Rate each criterion from 1 to 5'
                                : `${lines.length} criteria`}
                        </span>
                    </div>
                    <div className="divide-y divide-border">
                        {lines.map((line) => (
                            <ScoreRow
                                key={line.id}
                                label={line.label}
                                weight={line.weight}
                                score={line.score}
                                remarks={line.remarks}
                                criterionActive={line.criterion_active}
                                editable={editable}
                                onScoreChange={(v) => setScore(line.id, v)}
                                onRemarksChange={(v) => setRemark(line.id, v)}
                            />
                        ))}
                    </div>
                </div>

                {/* Overall remarks */}
                <div className="rounded-xl border border-sidebar-border/70 bg-card p-5 shadow-sm dark:border-sidebar-border">
                    <p className="mb-2 text-sm font-semibold">
                        Overall remarks
                    </p>
                    {editable ? (
                        <textarea
                            value={remarks}
                            onChange={(e) => setRemarks(e.target.value)}
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

                {/* Actions */}
                {can.manage &&
                    (evaluation.status === 'draft' ||
                        evaluation.status === 'submitted') && (
                        <div className="flex flex-wrap items-center justify-between gap-2">
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
                                            disabled={processing || !allScored}
                                            title={
                                                allScored
                                                    ? undefined
                                                    : 'Score every criterion first'
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
                                        Acknowledge
                                    </Button>
                                </div>
                            )}
                        </div>
                    )}

                {evaluation.status === 'acknowledged' && (
                    <div className="flex items-center justify-center gap-2 rounded-xl border border-dashed border-emerald-500/30 bg-emerald-500/5 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-400">
                        <CheckCircle2 className="size-4" />
                        This evaluation has been acknowledged and is final.
                    </div>
                )}
            </div>

            <ConfirmDialog
                open={confirm === 'submit'}
                onOpenChange={(open) => !open && setConfirm(null)}
                title="Submit this evaluation?"
                description="Submitting locks the scorecard and finalises the overall score. It can no longer be edited."
                confirmLabel="Submit"
                processing={processing}
                onConfirm={() => submitEvaluation(evaluation.hashid, handlers)}
            />

            <ConfirmDialog
                open={confirm === 'delete'}
                onOpenChange={(open) => !open && setConfirm(null)}
                title="Delete this draft?"
                description={`The draft evaluation for ${employee?.full_name ?? 'this employee'} will be permanently removed.`}
                confirmLabel="Delete"
                destructive
                processing={processing}
                onConfirm={() => deleteEvaluation(evaluation.hashid, handlers)}
            />
        </>
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
