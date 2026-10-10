import { BellRing, MessageSquareText, UserPlus, X } from 'lucide-react';
import { useState } from 'react';
import { DataTable, TableCard } from '@/components/data-table';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { cancelReview, remindReview } from '../api';
import {
    formatDate,
    formatLineScore,
    PILL,
    RELATIONSHIP_LABELS,
    REVIEW_STATUS,
    scaleFraction,
} from '../constants';
import type {
    FeedbackSummary,
    PerformanceScore,
    ReviewCandidate,
    ReviewRequest,
} from '../types';
import { RequestReviewsModal } from './request-reviews-modal';

type Props = {
    hashid: string;
    subject: string;
    feedback: FeedbackSummary;
    /** The evaluator's own lines, so the comparison sits beside them. */
    lines: PerformanceScore[];
    candidates: ReviewCandidate[];
    /** HR may ask, remind and withdraw (a draft that isn't their own). */
    canAsk: boolean;
    defaultDue: string | null;
};

/** Colour a 0–1 position on a scale, matching the scorecard's ordering. */
function fractionText(fraction: number | null): string {
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
 * The reviews of one appraisal (ADR 0072), as the people running it read them:
 * who was asked and where each request stands, then every criterion with the
 * evaluator's rating beside the self, manager, peer and direct-report views.
 *
 * Peers and direct reports are pooled — averaged and unnamed — and a pool only
 * opens once two in it have answered, so no single answer can be singled out.
 * Reviews are input: they never set the result.
 */
export function FeedbackPanel({
    hashid,
    subject,
    feedback,
    lines,
    candidates,
    canAsk,
    defaultDue,
}: Props) {
    const [asking, setAsking] = useState(false);
    const { requests, counts, columns, comments } = feedback;
    const byLine = new Map(feedback.lines.map((line) => [line.id, line]));
    const shown = columns.filter((column) => column.shown);

    return (
        <>
            <TableCard
                title="Reviews"
                description={`What the people around ${subject} think. The evaluator reads them; they never set the result.`}
                actions={
                    <>
                        {counts.asked > 0 && (
                            <span className="text-xs text-muted-foreground tabular-nums">
                                {counts.submitted} of {counts.asked} answered
                            </span>
                        )}
                        {canAsk && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setAsking(true)}
                            >
                                <UserPlus className="size-4" />
                                Ask for reviews
                            </Button>
                        )}
                    </>
                }
            >
                {requests.length === 0 ? (
                    <div className="flex flex-col items-center gap-1.5 px-4 py-8 text-center">
                        <span className="flex size-10 items-center justify-center rounded-full bg-muted">
                            <MessageSquareText className="size-5 text-muted-foreground" />
                        </span>
                        <p className="text-sm font-medium">
                            No reviews asked for
                        </p>
                        <p className="max-w-md text-sm text-muted-foreground">
                            {canAsk
                                ? `Ask ${subject} for a self-review, and their manager, peers or reports for their view.`
                                : 'Nobody was asked to review this appraisal.'}
                        </p>
                    </div>
                ) : (
                    <>
                        <ul className="divide-y divide-border">
                            {requests.map((request) => (
                                <RequestRow
                                    key={request.id}
                                    request={request}
                                    canManage={canAsk}
                                />
                            ))}
                        </ul>

                        {columns.length > 0 && (
                            <div className="border-t border-border">
                                <DataTable className="[&_tbody_tr]:hover:bg-transparent">
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Criterion</TableHead>
                                            <TableHead className="w-28 text-right">
                                                Evaluator
                                            </TableHead>
                                            {columns.map((column) => (
                                                <TableHead
                                                    key={column.key}
                                                    className="w-32 text-right"
                                                >
                                                    <span className="block">
                                                        {column.label}
                                                    </span>
                                                    <span className="block text-[10px] font-normal tracking-normal normal-case">
                                                        {column.shown
                                                            ? `${column.answered} of ${column.asked}`
                                                            : column.answered ===
                                                                0
                                                              ? 'no answers yet'
                                                              : `${column.answered} of ${column.asked} · opens at 2`}
                                                    </span>
                                                </TableHead>
                                            ))}
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {lines.map((line) => {
                                            const feedbackLine = byLine.get(
                                                line.id,
                                            );

                                            return (
                                                <TableRow
                                                    key={line.id}
                                                    className="align-top"
                                                >
                                                    <TableCell className="whitespace-normal">
                                                        <p className="min-w-40 text-sm font-medium">
                                                            {line.label}
                                                        </p>
                                                        {feedbackLine?.remarks.map(
                                                            (remark, i) => (
                                                                <p
                                                                    key={i}
                                                                    className="mt-1 text-xs text-muted-foreground"
                                                                >
                                                                    “
                                                                    {
                                                                        remark.text
                                                                    }
                                                                    ”{' '}
                                                                    <span className="text-muted-foreground/70">
                                                                        —{' '}
                                                                        {remark.by ??
                                                                            RELATIONSHIP_LABELS[
                                                                                remark
                                                                                    .relationship
                                                                            ]
                                                                                .label}
                                                                    </span>
                                                                </p>
                                                            ),
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        <span
                                                            className={cn(
                                                                'text-sm font-semibold',
                                                                fractionText(
                                                                    scaleFraction(
                                                                        line,
                                                                    ),
                                                                ),
                                                            )}
                                                        >
                                                            {formatLineScore(
                                                                line,
                                                            )}
                                                        </span>
                                                    </TableCell>
                                                    {columns.map((column) => {
                                                        const value =
                                                            column.shown
                                                                ? feedbackLine
                                                                      ?.values[
                                                                      column.key
                                                                  ]
                                                                : null;

                                                        return (
                                                            <TableCell
                                                                key={column.key}
                                                                className="text-right"
                                                            >
                                                                {value ? (
                                                                    <span
                                                                        className={cn(
                                                                            'text-sm font-semibold',
                                                                            fractionText(
                                                                                value.fraction,
                                                                            ),
                                                                        )}
                                                                    >
                                                                        {
                                                                            value.formatted
                                                                        }
                                                                    </span>
                                                                ) : (
                                                                    <span className="text-sm text-muted-foreground">
                                                                        —
                                                                    </span>
                                                                )}
                                                            </TableCell>
                                                        );
                                                    })}
                                                </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </DataTable>
                            </div>
                        )}

                        {comments.length > 0 && (
                            <div className="grid gap-3 border-t border-border p-4 md:grid-cols-2">
                                {comments.map((comment, i) => (
                                    <div
                                        key={i}
                                        className="rounded-lg border border-border bg-muted/20 px-3.5 py-3"
                                    >
                                        <p className="text-xs font-medium text-muted-foreground">
                                            {
                                                RELATIONSHIP_LABELS[
                                                    comment.relationship
                                                ].review
                                            }
                                            {comment.by && ` · ${comment.by}`}
                                        </p>
                                        {comment.strengths && (
                                            <div className="mt-2">
                                                <p className="text-xs font-semibold">
                                                    Keep doing
                                                </p>
                                                <p className="mt-0.5 text-sm whitespace-pre-line text-muted-foreground">
                                                    {comment.strengths}
                                                </p>
                                            </div>
                                        )}
                                        {comment.improvements && (
                                            <div className="mt-2">
                                                <p className="text-xs font-semibold">
                                                    Do differently
                                                </p>
                                                <p className="mt-0.5 text-sm whitespace-pre-line text-muted-foreground">
                                                    {comment.improvements}
                                                </p>
                                            </div>
                                        )}
                                    </div>
                                ))}
                            </div>
                        )}

                        {shown.length < columns.length && (
                            <p className="border-t border-border px-4 py-2.5 text-xs text-muted-foreground">
                                Peer and direct-report answers are pooled and
                                unnamed. A pool shows once two in it have
                                answered, so no single answer can be singled
                                out.
                            </p>
                        )}
                    </>
                )}
            </TableCard>

            {canAsk && (
                <RequestReviewsModal
                    open={asking}
                    onOpenChange={setAsking}
                    hashid={hashid}
                    subject={subject}
                    candidates={candidates}
                    defaultDue={defaultDue}
                />
            )}
        </>
    );
}

/** One person asked, and where their review stands. */
function RequestRow({
    request,
    canManage,
}: {
    request: ReviewRequest;
    canManage: boolean;
}) {
    const [busy, setBusy] = useState(false);
    const handlers = {
        onStart: () => setBusy(true),
        onFinish: () => setBusy(false),
    };
    const status = REVIEW_STATUS[request.status];
    const pending = request.status === 'pending';

    return (
        <li className="flex flex-col gap-2 px-4 py-2.5 sm:flex-row sm:items-center">
            <div className="flex min-w-0 flex-1 items-center gap-3">
                <PersonAvatar
                    name={request.reviewer?.name ?? '?'}
                    initials={request.reviewer?.initials ?? '?'}
                    photo={request.reviewer?.photo}
                    className="size-8"
                />
                <div className="min-w-0">
                    <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm font-medium">
                        <span className="truncate">
                            {request.reviewer?.name ?? 'A former colleague'}
                        </span>
                        <span
                            className={cn(
                                PILL,
                                'border-border text-muted-foreground',
                            )}
                        >
                            {RELATIONSHIP_LABELS[request.relationship].label}
                        </span>
                        <span className={cn(PILL, status.className)}>
                            {status.label}
                        </span>
                    </p>
                    <p
                        className={cn(
                            'mt-0.5 text-xs text-muted-foreground',
                            request.overdue &&
                                'text-amber-700 dark:text-amber-400',
                        )}
                    >
                        {request.status === 'declined'
                            ? request.decline_reason
                                ? `Declined: “${request.decline_reason}”`
                                : 'Declined without a reason'
                            : request.status === 'submitted'
                              ? `Answered ${formatDate(request.submitted_at?.slice(0, 10) ?? null)}`
                              : request.status === 'cancelled'
                                ? 'Closed when the appraisal was submitted or withdrawn'
                                : request.overdue
                                  ? `Overdue — was due ${formatDate(request.due_on)}`
                                  : `Due ${formatDate(request.due_on)}`}
                    </p>
                </div>
            </div>

            {canManage && pending && (
                <div className="flex shrink-0 items-center gap-1 pl-11 sm:pl-0">
                    <Button
                        variant="ghost"
                        size="sm"
                        disabled={busy || !request.can_remind}
                        onClick={() => remindReview(request.hashid, handlers)}
                    >
                        <BellRing className="size-4" />
                        {request.can_remind ? 'Remind' : 'Reminded today'}
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        className="text-muted-foreground hover:text-destructive"
                        disabled={busy}
                        onClick={() => cancelReview(request.hashid, handlers)}
                    >
                        <X className="size-4" />
                        Withdraw
                    </Button>
                </div>
            )}
        </li>
    );
}
