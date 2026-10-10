import { Head, usePage } from '@inertiajs/react';
import { CalendarRange, Lock, Send, ShieldCheck, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { PageBody, PageHeader } from '@/components/data-table';
import { FormField } from '@/components/form-field';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import {
    declineReview,
    saveReview,
    submitReview,
} from '@/features/performance/api';
import type { ReviewPayload } from '@/features/performance/api';
import { RatingControl } from '@/features/performance/components/rating-control';
import {
    formatDate,
    formatLineScore,
    PILL,
    RELATIONSHIP_LABELS,
    REVIEW_STATUS,
    TEXTAREA,
} from '@/features/performance/constants';
import { performanceRoutes } from '@/features/performance/routes';
import type {
    PerformanceScore,
    ReviewFormPageProps,
} from '@/features/performance/types';
import { cn } from '@/lib/utils';

/**
 * Writing a review (ADR 0072): the appraisal's own criteria, each on its own
 * scale, with room for evidence, then two written answers. A self-review rates
 * everything; anyone else may leave a criterion they can't judge. The page says
 * plainly who will read the answers, and how — pooled reviews are averaged and
 * unnamed.
 *
 * The evaluator's ratings are never shown here, so the review is the
 * reviewer's own view.
 */
export default function ReviewForm() {
    const { review, criteria, sections, answers } =
        usePage<ReviewFormPageProps>().props;

    const self = review.relationship === 'self';
    const pooled =
        review.relationship === 'peer' ||
        review.relationship === 'direct_report';
    const editable = review.status === 'pending' && review.open === true;
    const subject = review.subject?.full_name ?? 'this colleague';
    const firstName = subject.split(' ')[0];

    const [lines, setLines] = useState<PerformanceScore[]>(() =>
        criteria.map((line) => ({
            ...line,
            score: answers[line.id]?.score ?? null,
            remarks: answers[line.id]?.remarks ?? null,
        })),
    );
    const [strengths, setStrengths] = useState(review.strengths ?? '');
    const [improvements, setImprovements] = useState(review.improvements ?? '');
    const [processing, setProcessing] = useState(false);
    const [declining, setDeclining] = useState(false);
    const [reason, setReason] = useState('');

    const grouped = useMemo(() => {
        const order = sections.map((s) => s.key);
        const keys = [
            ...new Set([
                ...order,
                ...lines.map((l) => l.section_key || 'overall'),
            ]),
        ];

        return keys
            .map((key) => ({
                key,
                name:
                    sections.find((s) => s.key === key)?.name ??
                    lines.find((l) => (l.section_key || 'overall') === key)
                        ?.section_name ??
                    'Criteria',
                description:
                    sections.find((s) => s.key === key)?.description ?? null,
                lines: lines.filter(
                    (l) => (l.section_key || 'overall') === key,
                ),
            }))
            .filter((group) => group.lines.length > 0);
    }, [sections, lines]);

    const rated = lines.filter((l) => l.score !== null).length;

    const update = (id: number, patch: Partial<PerformanceScore>) =>
        setLines((prev) =>
            prev.map((line) => (line.id === id ? { ...line, ...patch } : line)),
        );

    const payload = (): ReviewPayload => ({
        scores: lines.map((line) => ({
            id: line.id,
            score: line.score,
            remarks: line.remarks?.trim() || null,
        })),
        strengths: strengths.trim() || null,
        improvements: improvements.trim() || null,
    });

    const handlers = {
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
    };

    const ready = self
        ? rated === lines.length
        : rated > 0 || strengths.trim() !== '' || improvements.trim() !== '';

    const who = self
        ? 'Your evaluator reads it beside their own ratings. It doesn’t set your result.'
        : pooled
          ? `Your ratings are averaged with other ${review.relationship === 'peer' ? 'peers' : 'reports'}’ and shown without your name, once at least two have answered. Your written answers are shown without your name too.`
          : `Your name is shown with your answers. The evaluator reads them beside their own ratings.`;

    return (
        <>
            <Head title={self ? 'Your self-review' : `Review of ${subject}`} />

            <PageBody>
                <PageHeader
                    back={{
                        href: performanceRoutes.reviews,
                        label: 'Back to reviews',
                    }}
                    leading={
                        <PersonAvatar
                            name={subject}
                            initials={review.subject?.initials ?? '?'}
                            photo={review.subject?.photo}
                            className="size-11"
                        />
                    }
                    title={self ? 'Your self-review' : `Review of ${subject}`}
                    badges={
                        <>
                            <span
                                className={cn(
                                    PILL,
                                    'border-border text-muted-foreground',
                                )}
                            >
                                {
                                    RELATIONSHIP_LABELS[review.relationship]
                                        .review
                                }
                            </span>
                            {!editable && (
                                <span
                                    className={cn(
                                        PILL,
                                        REVIEW_STATUS[review.status].className,
                                    )}
                                >
                                    {REVIEW_STATUS[review.status].label}
                                </span>
                            )}
                        </>
                    }
                    description={
                        <span className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs">
                            <span className="inline-flex items-center gap-1.5">
                                <CalendarRange className="size-3.5" />
                                {review.period?.name ?? 'Review cycle'}
                            </span>
                            {!self && review.subject?.position && (
                                <span>{review.subject.position}</span>
                            )}
                            {editable && review.due_on && (
                                <span
                                    className={cn(
                                        review.overdue &&
                                            'font-medium text-amber-700 dark:text-amber-400',
                                    )}
                                >
                                    {review.overdue
                                        ? 'Overdue — was due'
                                        : 'Due'}{' '}
                                    {formatDate(review.due_on)}
                                </span>
                            )}
                        </span>
                    }
                />

                <p className="flex items-start gap-2.5 rounded-xl border border-dashed border-border bg-muted/30 px-4 py-3 text-sm text-muted-foreground">
                    {editable ? (
                        <ShieldCheck className="mt-0.5 size-4 shrink-0" />
                    ) : (
                        <Lock className="mt-0.5 size-4 shrink-0" />
                    )}
                    <span>
                        {editable
                            ? self
                                ? `Rate yourself on each criterion and say why. ${who}`
                                : `Rate ${firstName} on what you’ve seen this cycle. Leave a criterion blank if you can’t judge it. ${who}`
                            : review.status === 'declined'
                              ? `You declined this review${review.decline_reason ? `: “${review.decline_reason}”` : '.'}`
                              : review.status === 'submitted'
                                ? 'Handed in. This is the review as you gave it.'
                                : 'The appraisal was submitted, so this review is closed.'}
                    </span>
                </p>

                {grouped.map((group) => (
                    <section
                        key={group.key}
                        aria-labelledby={`section-${group.key}`}
                        className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border"
                    >
                        <div className="border-b border-border bg-muted/30 px-4 py-2.5">
                            <h2
                                id={`section-${group.key}`}
                                className="text-sm font-semibold"
                            >
                                {group.name}
                            </h2>
                            {group.description && (
                                <p className="text-xs text-muted-foreground">
                                    {group.description}
                                </p>
                            )}
                        </div>
                        <ul className="divide-y divide-border">
                            {group.lines.map((line) => (
                                <li
                                    key={line.id}
                                    className="grid gap-3 px-4 py-3.5 md:grid-cols-[minmax(0,1fr)_minmax(0,22rem)] md:gap-6"
                                >
                                    <div className="min-w-0">
                                        <p className="text-sm font-medium">
                                            {line.label}
                                        </p>
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
                                    </div>
                                    <div className="flex min-w-0 flex-col gap-2">
                                        {editable ? (
                                            <>
                                                <RatingControl
                                                    line={line}
                                                    onChange={(value) =>
                                                        update(line.id, {
                                                            score: value,
                                                        })
                                                    }
                                                />
                                                {!self &&
                                                    line.score !== null && (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                update(
                                                                    line.id,
                                                                    {
                                                                        score: null,
                                                                    },
                                                                )
                                                            }
                                                            className="self-start text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                                                        >
                                                            I can’t judge this
                                                        </button>
                                                    )}
                                                <Input
                                                    value={line.remarks ?? ''}
                                                    onChange={(e) =>
                                                        update(line.id, {
                                                            remarks:
                                                                e.target.value,
                                                        })
                                                    }
                                                    maxLength={1000}
                                                    placeholder="An example (optional)"
                                                    aria-label={`Example for ${line.label}`}
                                                    className="h-8"
                                                />
                                            </>
                                        ) : (
                                            <>
                                                <p className="text-sm font-semibold">
                                                    {line.score === null
                                                        ? 'Not rated'
                                                        : formatLineScore(line)}
                                                </p>
                                                {line.remarks && (
                                                    <p className="text-sm text-muted-foreground">
                                                        {line.remarks}
                                                    </p>
                                                )}
                                            </>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </section>
                ))}

                <section className="grid gap-4 rounded-xl border border-sidebar-border/70 bg-card px-4 py-4 md:grid-cols-2 dark:border-sidebar-border">
                    {(
                        [
                            [
                                'strengths',
                                self
                                    ? 'What went well'
                                    : `What should ${firstName} keep doing?`,
                                strengths,
                                setStrengths,
                            ],
                            [
                                'improvements',
                                self
                                    ? 'What you’d like to develop'
                                    : `What could ${firstName} do differently?`,
                                improvements,
                                setImprovements,
                            ],
                        ] as const
                    ).map(([key, label, value, onChange]) => (
                        <FormField key={key} label={label}>
                            {editable ? (
                                <textarea
                                    value={value}
                                    onChange={(e) => onChange(e.target.value)}
                                    rows={4}
                                    maxLength={3000}
                                    className={TEXTAREA}
                                />
                            ) : (
                                <p className="text-sm whitespace-pre-line text-muted-foreground">
                                    {value || '—'}
                                </p>
                            )}
                        </FormField>
                    ))}
                </section>

                {editable && (
                    <div className="sticky bottom-0 -mx-4 flex flex-wrap items-center justify-between gap-2 border-t border-border bg-background/85 px-4 py-3 backdrop-blur md:-mx-6 md:px-6">
                        <div className="flex items-center gap-3">
                            {!self && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="text-muted-foreground hover:text-destructive"
                                    onClick={() => setDeclining(true)}
                                    disabled={processing}
                                >
                                    <X className="size-4" />
                                    Decline
                                </Button>
                            )}
                            <span
                                className={cn(
                                    'text-xs tabular-nums',
                                    self && rated < lines.length
                                        ? 'text-amber-700 dark:text-amber-400'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {rated} of {lines.length} rated
                            </span>
                        </div>
                        <div className="flex items-center gap-2">
                            <Button
                                variant="outline"
                                onClick={() =>
                                    saveReview(
                                        review.hashid,
                                        payload(),
                                        handlers,
                                    )
                                }
                                disabled={processing}
                            >
                                Save
                            </Button>
                            <Button
                                onClick={() =>
                                    submitReview(
                                        review.hashid,
                                        payload(),
                                        handlers,
                                    )
                                }
                                disabled={processing || !ready}
                            >
                                {processing ? (
                                    <Spinner />
                                ) : (
                                    <Send className="size-4" />
                                )}
                                Hand in
                            </Button>
                        </div>
                    </div>
                )}
                {editable && !ready && (
                    <p className="-mt-2 text-right text-xs text-muted-foreground">
                        {self
                            ? 'Rate every criterion to hand in your self-review.'
                            : 'Rate at least one criterion or write an answer to hand it in.'}
                    </p>
                )}
            </PageBody>

            <ConfirmDialog
                open={declining}
                onOpenChange={setDeclining}
                title={`Decline the review of ${subject}?`}
                description={
                    <div className="space-y-2">
                        <p>
                            The evaluator is told, with your reason if you give
                            one.
                        </p>
                        <textarea
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            rows={2}
                            maxLength={500}
                            placeholder="e.g. We haven’t worked together this cycle"
                            aria-label="Reason"
                            className={TEXTAREA}
                        />
                    </div>
                }
                confirmLabel="Decline"
                destructive
                processing={processing}
                onConfirm={() =>
                    declineReview(review.hashid, reason.trim() || null, {
                        ...handlers,
                        onSuccess: () => setDeclining(false),
                    })
                }
            />
        </>
    );
}

ReviewForm.layout = (props: ReviewFormPageProps) => ({
    breadcrumbs: [
        { title: 'Performance Management', href: '/performance' },
        { title: 'Reviews', href: '/performance/reviews' },
        {
            title:
                props.review.relationship === 'self'
                    ? 'Your self-review'
                    : (props.review.subject?.full_name ?? 'Review'),
            href: performanceRoutes.review(props.review.hashid),
        },
    ],
});
