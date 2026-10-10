import { Head, Link, usePage } from '@inertiajs/react';
import {
    ClipboardCheck,
    Hourglass,
    MessageSquareText,
    PenLine,
} from 'lucide-react';
import { PageBody, PageHeader } from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { BandChip } from '@/features/performance/components/band-chip';
import { PerformanceNav } from '@/features/performance/components/performance-nav';
import {
    formatDate,
    formatPercent,
    formatTimestamp,
} from '@/features/performance/constants';
import { performanceRoutes } from '@/features/performance/routes';
import type {
    MyAppraisal,
    MyAppraisalsPageProps,
} from '@/features/performance/types';
import { cn } from '@/lib/utils';

/**
 * My appraisals (ADR 0072): every appraisal of the signed-in employee, newest
 * first. One still being rated says so (and offers the self-review when one was
 * asked for); one being calibrated says it will be shared once that is done; a
 * shared one opens to be read and acknowledged.
 */
export default function MyAppraisals() {
    const { appraisals, has_employee, nav } =
        usePage<MyAppraisalsPageProps>().props;

    const waiting = appraisals.filter(
        (a) => a.shared && a.status === 'submitted',
    ).length;

    return (
        <>
            <Head title="My appraisals" />

            <PageBody>
                <PageHeader
                    title="My appraisals"
                    description={
                        waiting > 0
                            ? `${waiting} ${waiting === 1 ? 'appraisal is' : 'appraisals are'} ready for you to read and acknowledge.`
                            : 'Your appraisals, cycle by cycle. You’re told when one is ready to read.'
                    }
                    actions={<PerformanceNav current="me" counts={nav} />}
                />

                {!has_employee ? (
                    <Empty
                        title="Your account isn’t linked to an employee"
                        body="Appraisals belong to employees. Ask HR to link your account to your employee record."
                    />
                ) : appraisals.length === 0 ? (
                    <Empty
                        title="No appraisals yet"
                        body="When a review cycle opens an appraisal for you, it shows here, and you’re told when it’s ready to read."
                    />
                ) : (
                    <ul className="flex flex-col gap-3">
                        {appraisals.map((appraisal) => (
                            <AppraisalCard
                                key={appraisal.hashid}
                                appraisal={appraisal}
                            />
                        ))}
                    </ul>
                )}
            </PageBody>
        </>
    );
}

function AppraisalCard({ appraisal }: { appraisal: MyAppraisal }) {
    const ready = appraisal.shared && appraisal.status === 'submitted';
    const self = appraisal.self_review;

    return (
        <li
            className={cn(
                'flex flex-col gap-3 rounded-xl border bg-card p-4 sm:flex-row sm:items-center',
                ready
                    ? 'border-[#0ABFBF]/40'
                    : 'border-sidebar-border/70 dark:border-sidebar-border',
            )}
        >
            <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-[#0ABFBF]">
                <ClipboardCheck className="size-5" />
            </span>

            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <p className="font-medium">
                    {appraisal.period?.name ?? 'Review cycle'}
                    {appraisal.period && (
                        <span className="ml-2 text-xs font-normal text-muted-foreground tabular-nums">
                            {formatDate(appraisal.period.start_date)} –{' '}
                            {formatDate(appraisal.period.end_date)}
                        </span>
                    )}
                </p>
                <p className="text-sm text-muted-foreground">
                    {[
                        appraisal.template_name,
                        appraisal.evaluator &&
                            `rated by ${appraisal.evaluator}`,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </p>
                <Status appraisal={appraisal} />
            </div>

            <div className="flex shrink-0 flex-wrap items-center gap-2 sm:justify-end">
                {self?.open && (
                    <Button size="sm" variant="outline" asChild>
                        <Link href={performanceRoutes.review(self.hashid)}>
                            <PenLine className="size-4" />
                            Write your self-review
                        </Link>
                    </Button>
                )}
                {appraisal.shared && (
                    <Button
                        size="sm"
                        variant={ready ? 'default' : 'outline'}
                        asChild
                    >
                        <Link
                            href={performanceRoutes.myAppraisal(
                                appraisal.hashid,
                            )}
                        >
                            {ready ? 'Read and acknowledge' : 'Open'}
                        </Link>
                    </Button>
                )}
            </div>
        </li>
    );
}

/** Where the appraisal stands, in a line. */
function Status({ appraisal }: { appraisal: MyAppraisal }) {
    if (appraisal.shared) {
        return (
            <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                <BandChip
                    label={appraisal.result_label}
                    tone={appraisal.result_tone ?? undefined}
                />
                <span className="tabular-nums">
                    {formatPercent(appraisal.overall_percent)} attainment
                </span>
                <span>
                    {appraisal.acknowledged_at
                        ? `· Acknowledged ${formatTimestamp(appraisal.acknowledged_at)}`
                        : `· Shared ${formatTimestamp(appraisal.shared_at)}`}
                </span>
            </div>
        );
    }

    if (appraisal.calibrating) {
        return (
            <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                <Hourglass className="size-3.5" />
                Being calibrated. You’re told when it’s ready to read.
            </p>
        );
    }

    const self = appraisal.self_review;

    return (
        <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
            <MessageSquareText className="size-3.5" />
            In progress
            {self?.status === 'submitted'
                ? ' · your self-review is in'
                : self?.open && self.due_on
                  ? ` · self-review due ${formatDate(self.due_on)}`
                  : ''}
        </p>
    );
}

function Empty({ title, body }: { title: string; body: string }) {
    return (
        <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border px-6 py-14 text-center">
            <ClipboardCheck
                className="size-8 text-muted-foreground"
                aria-hidden
            />
            <p className="font-medium">{title}</p>
            <p className="max-w-sm text-sm text-muted-foreground">{body}</p>
        </div>
    );
}

MyAppraisals.layout = {
    breadcrumbs: [
        { title: 'Performance Management', href: '/performance' },
        { title: 'My appraisals', href: '/performance/me' },
    ],
};
