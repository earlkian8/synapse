import { Head, usePage } from '@inertiajs/react';
import {
    CalendarCheck2,
    NotebookPen,
    PencilLine,
    PowerOff,
    UserX,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { PageBody, PageHeader } from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { CheckInFlow } from '@/features/check-ins/components/check-in-flow';
import { CheckInsNav } from '@/features/check-ins/components/check-ins-nav';
import { MoodFace } from '@/features/check-ins/components/mood-face';
import { MoodYear } from '@/features/check-ins/components/mood-year';
import { WhoSeesThis } from '@/features/check-ins/components/who-sees-this';
import {
    MOOD_WORDS,
    answerLabel,
    monthName,
} from '@/features/check-ins/constants';
import type {
    MyCheckIn,
    MyCheckInPageProps,
    Question,
} from '@/features/check-ins/types';

/**
 * My check-in (ADR 0102): this month's six questions when they wait — or, once
 * answered, the month as it was told, with a way to change it until the month ends —
 * and the last twelve months as a row of faces to look back through.
 */
export default function MyCheckIns() {
    const { overview } = usePage<MyCheckInPageProps>().props;
    const { current, history, questions } = overview;
    const [editing, setEditing] = useState(false);
    const [picked, setPicked] = useState<string | null>(null);

    const shownPeriod = picked ?? history[0]?.period ?? null;
    const shown = history.find((c) => c.period === shownPeriod) ?? null;
    const asking =
        overview.enabled &&
        overview.has_employee &&
        (current === null || editing);

    return (
        <>
            <Head title="My check-in" />
            <PageBody>
                <PageHeader
                    title="My check-in"
                    description={
                        !overview.enabled
                            ? 'Check-ins are turned off in this workspace.'
                            : current
                              ? `You checked in for ${overview.period_label}. You can change it for ${overview.days_left} more ${overview.days_left === 1 ? 'day' : 'days'}.`
                              : `Six quick questions about how ${monthName(overview.period)} is going. ${overview.days_left} ${overview.days_left === 1 ? 'day' : 'days'} left to answer.`
                    }
                    actions={<CheckInsNav current="me" />}
                />

                {!overview.has_employee ? (
                    <Notice
                        icon={<UserX className="size-8" />}
                        title="Your account isn’t linked to an employee"
                        body="Check-ins belong to employees. Ask HR to link your account to your employee record."
                    />
                ) : !overview.enabled && history.length === 0 ? (
                    <Notice
                        icon={<PowerOff className="size-8" />}
                        title="Check-ins are off"
                        body="Your company hasn’t turned on monthly check-ins. When it does, you’ll be told the month’s is open."
                    />
                ) : (
                    <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_20rem]">
                        <div className="flex min-w-0 flex-col gap-6">
                            {asking ? (
                                <CheckInFlow
                                    questions={questions}
                                    periodLabel={overview.period_label}
                                    initial={
                                        editing && current
                                            ? {
                                                  answers: current.answers,
                                                  note: current.note,
                                              }
                                            : null
                                    }
                                    onDone={() => {
                                        setEditing(false);
                                        setPicked(overview.period);
                                    }}
                                    onCancel={
                                        editing
                                            ? () => setEditing(false)
                                            : undefined
                                    }
                                />
                            ) : (
                                shown && (
                                    <MonthCard
                                        checkIn={shown}
                                        questions={questions}
                                        isCurrent={
                                            shown.period === overview.period
                                        }
                                        canChange={
                                            overview.enabled &&
                                            shown.period === overview.period
                                        }
                                        onChange={() => setEditing(true)}
                                    />
                                )
                            )}

                            {history.length > 0 && (
                                <section className="flex flex-col gap-3">
                                    <div className="flex items-baseline justify-between gap-3">
                                        <h2 className="text-sm font-semibold">
                                            Your year
                                        </h2>
                                        <p className="text-xs text-muted-foreground">
                                            {history.length} of 12 months
                                            checked in
                                        </p>
                                    </div>
                                    <MoodYear
                                        months={overview.months}
                                        history={history}
                                        selected={asking ? null : shownPeriod}
                                        current={overview.period}
                                        onSelect={(period) => {
                                            setEditing(false);
                                            setPicked(period);
                                        }}
                                    />
                                </section>
                            )}
                        </div>

                        <aside className="flex flex-col gap-4">
                            <WhoSeesThis />
                        </aside>
                    </div>
                )}
            </PageBody>
        </>
    );
}

/** One month as it was told: the face, every answer, and the private note. */
function MonthCard({
    checkIn,
    questions,
    isCurrent,
    canChange,
    onChange,
}: {
    checkIn: MyCheckIn;
    questions: Question[];
    isCurrent: boolean;
    canChange: boolean;
    onChange: () => void;
}) {
    const engagement = checkIn.engagement;
    const rows = questions.filter(
        (q) => q.key !== 'mood' && q.group !== 'engagement',
    );

    return (
        <section className="grid overflow-hidden rounded-2xl border border-sidebar-border/70 bg-card md:grid-cols-[minmax(0,15rem)_minmax(0,1fr)] dark:border-sidebar-border">
            <div className="flex items-center gap-4 border-b border-sidebar-border/70 bg-muted/40 px-6 py-5 md:flex-col md:justify-center md:border-r md:border-b-0 md:py-10 dark:border-sidebar-border">
                <MoodFace
                    mood={checkIn.answers.mood}
                    size={132}
                    className="size-20 md:size-[132px]"
                />
                <div className="flex flex-col md:items-center">
                    <p className="text-3xl font-semibold tracking-tight md:text-4xl">
                        {MOOD_WORDS[checkIn.answers.mood - 1]}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {monthName(checkIn.period, true)}
                    </p>
                </div>
            </div>

            <div className="flex min-w-0 flex-col gap-5 p-5 sm:p-7">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex flex-col gap-1">
                        <h2 className="flex items-center gap-2 text-xl font-semibold tracking-tight">
                            {isCurrent && (
                                <CalendarCheck2
                                    className="size-5 text-muted-foreground"
                                    aria-hidden
                                />
                            )}
                            {isCurrent
                                ? 'Checked in for this month'
                                : `How ${monthName(checkIn.period)} went`}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Saved{' '}
                            {new Date(checkIn.submitted_at).toLocaleDateString(
                                undefined,
                                {
                                    month: 'long',
                                    day: 'numeric',
                                },
                            )}
                            .
                        </p>
                    </div>
                    {canChange && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={onChange}
                        >
                            <PencilLine className="size-4" />
                            Change my answers
                        </Button>
                    )}
                </div>

                <div className="flex flex-col gap-2">
                    <div className="flex items-baseline justify-between text-sm">
                        <span className="font-medium">Engagement</span>
                        <span className="text-muted-foreground tabular-nums">
                            <span className="font-semibold text-foreground">
                                {engagement.toFixed(1)}
                            </span>{' '}
                            of 6
                        </span>
                    </div>
                    <div
                        className="h-2 overflow-hidden rounded-full bg-muted"
                        role="img"
                        aria-label={`Engagement ${engagement.toFixed(1)} of 6`}
                    >
                        <div
                            className="h-full rounded-full bg-foreground/80"
                            style={{ width: `${(engagement / 6) * 100}%` }}
                        />
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Energy{' '}
                        {answerLabel(
                            questions,
                            'energy',
                            checkIn.answers.energy,
                        ).toLowerCase()}
                        , enthusiasm{' '}
                        {answerLabel(
                            questions,
                            'enthusiasm',
                            checkIn.answers.enthusiasm,
                        ).toLowerCase()}
                        , immersed{' '}
                        {answerLabel(
                            questions,
                            'immersion',
                            checkIn.answers.immersion,
                        ).toLowerCase()}
                        .
                    </p>
                </div>

                <dl className="grid gap-3 sm:grid-cols-2">
                    {rows.map((q) => (
                        <div
                            key={q.key}
                            className="flex flex-col gap-0.5 rounded-xl bg-muted/40 px-4 py-3"
                        >
                            <dt className="text-xs text-muted-foreground">
                                {q.prompt}
                            </dt>
                            <dd className="text-sm font-medium">
                                {answerLabel(
                                    questions,
                                    q.key,
                                    checkIn.answers[q.key],
                                )}
                            </dd>
                        </div>
                    ))}
                </dl>

                {checkIn.note && (
                    <figure className="flex gap-3 rounded-xl border border-dashed border-border px-4 py-3">
                        <NotebookPen
                            className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                            aria-hidden
                        />
                        <div className="flex flex-col gap-0.5">
                            <figcaption className="text-xs text-muted-foreground">
                                Your note — only you can read it
                            </figcaption>
                            <blockquote className="text-sm whitespace-pre-line">
                                {checkIn.note}
                            </blockquote>
                        </div>
                    </figure>
                )}
            </div>
        </section>
    );
}

function Notice({
    icon,
    title,
    body,
}: {
    icon: ReactNode;
    title: string;
    body: string;
}) {
    return (
        <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border px-6 py-14 text-center">
            <span className="text-muted-foreground" aria-hidden>
                {icon}
            </span>
            <p className="font-medium">{title}</p>
            <p className="max-w-sm text-sm text-muted-foreground">{body}</p>
        </div>
    );
}

MyCheckIns.layout = {
    breadcrumbs: [
        { title: 'Check-ins', href: '/check-ins' },
        { title: 'My check-in', href: '/check-ins/me' },
    ],
};
