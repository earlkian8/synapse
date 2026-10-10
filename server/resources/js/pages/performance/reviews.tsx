import { Head, Link, usePage } from '@inertiajs/react';
import { MessageSquareText } from 'lucide-react';
import { useState } from 'react';
import { PageBody, PageHeader } from '@/components/data-table';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import { PerformanceNav } from '@/features/performance/components/performance-nav';
import {
    formatDate,
    formatTimestamp,
    PILL,
    RELATIONSHIP_LABELS,
    REVIEW_STATUS,
} from '@/features/performance/constants';
import { performanceRoutes } from '@/features/performance/routes';
import type { MyReview, ReviewsPageProps } from '@/features/performance/types';
import { cn } from '@/lib/utils';

type View = 'waiting' | 'done';

/**
 * Reviews (ADR 0072): what the signed-in person is asked to write — their own
 * self-review, and reviews of colleagues as a manager, peer or report — with
 * what is due first, then what they have answered or declined.
 */
export default function Reviews() {
    const { reviews, has_employee, nav } = usePage<ReviewsPageProps>().props;

    const waiting = reviews.filter((r) => r.status === 'pending' && r.open);
    const done = reviews.filter((r) => !(r.status === 'pending' && r.open));
    const [view, setView] = useState<View>(
        waiting.length > 0 || done.length === 0 ? 'waiting' : 'done',
    );
    const shown = view === 'waiting' ? waiting : done;

    return (
        <>
            <Head title="Reviews" />

            <PageBody>
                <PageHeader
                    title="Reviews"
                    description={
                        waiting.length > 0
                            ? `${waiting.length} ${waiting.length === 1 ? 'review is' : 'reviews are'} waiting for you.`
                            : 'Reviews you’re asked to write, about yourself and about colleagues.'
                    }
                    actions={<PerformanceNav current="reviews" counts={nav} />}
                />

                {!has_employee ? (
                    <Empty
                        title="Your account isn’t linked to an employee"
                        body="Reviews are asked of employees. Ask HR to link your account to your employee record."
                    />
                ) : (
                    <>
                        <div
                            role="tablist"
                            aria-label="Which reviews"
                            className="flex items-center gap-1 overflow-x-auto border-b border-border"
                        >
                            {(
                                [
                                    ['waiting', 'To write', waiting.length],
                                    ['done', 'Done', done.length],
                                ] as const
                            ).map(([key, label, count]) => (
                                <button
                                    key={key}
                                    type="button"
                                    role="tab"
                                    aria-selected={view === key}
                                    onClick={() => setView(key)}
                                    className={cn(
                                        'border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors',
                                        view === key
                                            ? 'border-[#0ABFBF] text-foreground'
                                            : 'border-transparent text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {label}
                                    <span className="ml-1.5 text-muted-foreground tabular-nums">
                                        {count}
                                    </span>
                                </button>
                            ))}
                        </div>

                        {shown.length === 0 ? (
                            view === 'waiting' ? (
                                <Empty
                                    title="Nothing to write"
                                    body="When HR asks for your view of a colleague, or opens your self-review, it lands here and in your notifications."
                                />
                            ) : (
                                <Empty
                                    title="Nothing answered yet"
                                    body="Reviews you hand in or decline are kept here."
                                />
                            )
                        ) : (
                            <ul className="flex flex-col gap-3">
                                {shown.map((review) => (
                                    <ReviewCard
                                        key={review.id}
                                        review={review}
                                    />
                                ))}
                            </ul>
                        )}
                    </>
                )}
            </PageBody>
        </>
    );
}

function ReviewCard({ review }: { review: MyReview }) {
    const self = review.relationship === 'self';
    const open = review.status === 'pending' && review.open;
    const status = REVIEW_STATUS[review.status];

    return (
        <li className="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 sm:flex-row sm:items-center dark:border-sidebar-border">
            <PersonAvatar
                name={review.subject?.full_name ?? '?'}
                initials={review.subject?.initials ?? '?'}
                photo={review.subject?.photo}
                className="size-10"
            />
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <p className="flex flex-wrap items-center gap-2 font-medium">
                    {self
                        ? 'Your self-review'
                        : (review.subject?.full_name ?? 'A colleague')}
                    {!self && (
                        <span
                            className={cn(
                                PILL,
                                'border-border text-muted-foreground',
                            )}
                        >
                            as their{' '}
                            {RELATIONSHIP_LABELS[
                                review.relationship
                            ].label.toLowerCase()}
                        </span>
                    )}
                    {!open && (
                        <span className={cn(PILL, status.className)}>
                            {status.label}
                        </span>
                    )}
                </p>
                <p className="text-sm text-muted-foreground">
                    {[
                        review.period?.name,
                        !self && review.subject?.position,
                        review.requested_by &&
                            `asked by ${review.requested_by}`,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </p>
                <p
                    className={cn(
                        'text-xs text-muted-foreground',
                        open &&
                            review.overdue &&
                            'text-amber-700 dark:text-amber-400',
                    )}
                >
                    {open
                        ? review.overdue
                            ? `Overdue — was due ${formatDate(review.due_on)}`
                            : `Due ${formatDate(review.due_on)}`
                        : review.status === 'submitted'
                          ? `Handed in ${formatTimestamp(review.submitted_at)}`
                          : review.status === 'declined'
                            ? `Declined ${formatTimestamp(review.declined_at)}`
                            : 'Closed — the appraisal was submitted before it was answered'}
                </p>
            </div>
            <Button
                size="sm"
                variant={open ? 'default' : 'outline'}
                className="self-start sm:self-center"
                asChild
            >
                <Link href={performanceRoutes.review(review.hashid)}>
                    {open ? (self ? 'Write it' : 'Write review') : 'Open'}
                </Link>
            </Button>
        </li>
    );
}

function Empty({ title, body }: { title: string; body: string }) {
    return (
        <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border px-6 py-14 text-center">
            <MessageSquareText
                className="size-8 text-muted-foreground"
                aria-hidden
            />
            <p className="font-medium">{title}</p>
            <p className="max-w-sm text-sm text-muted-foreground">{body}</p>
        </div>
    );
}

Reviews.layout = {
    breadcrumbs: [
        { title: 'Performance Management', href: '/performance' },
        { title: 'Reviews', href: '/performance/reviews' },
    ],
};
