import { Head, useForm, usePage } from '@inertiajs/react';
import { CircleCheck, Lock } from 'lucide-react';
import type { FormEvent } from 'react';
import { PageBody, PageHeader } from '@/components/data-table';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { OffboardingNav } from '@/features/offboarding/components/offboarding-nav';
import { formatDay } from '@/features/offboarding/constants';
import { offboardingRoutes } from '@/features/offboarding/routes';
import type {
    ExitInterviewPageProps,
    InterviewAnswers,
    InterviewQuestion,
} from '@/features/offboarding/types';
import { cn } from '@/lib/utils';

/**
 * The exit interview (ADR 0076): why the person is leaving, seven parts of
 * their time here rated 1 to 5 — a matrix, one row each — whether they would
 * recommend the company or come back, and three open questions. Once sent, the
 * page shows what they answered. Only those who may see exits
 * (`offboarding.view`) read it.
 */
export default function ExitInterviewPage() {
    const { interview, nav } = usePage<ExitInterviewPageProps>().props;
    const readOnly = !interview.can_answer;

    const initial: InterviewAnswers = interview.answers ?? {};
    const { data, setData, post, processing, errors } = useForm<{
        answers: InterviewAnswers;
    }>({ answers: initial });

    const fieldErrors = errors as Record<string, string | undefined>;
    const answer = (key: string) => data.answers[key];
    const set = (key: string, value: string | number) =>
        setData('answers', { ...data.answers, [key]: value });

    const questions = interview.questions;
    const choices = questions.filter((q) => q.type === 'choice');
    const ratings = questions.filter((q) => q.type === 'rating');
    const yesNo = questions.filter((q) => q.type === 'yes_no');
    const open = questions.filter((q) => q.type === 'text');
    const scale = Object.entries(interview.scale);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(offboardingRoutes.myInterview, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Exit interview" />

            <PageBody>
                <PageHeader
                    title="Exit interview"
                    description={
                        readOnly
                            ? interview.status === 'submitted'
                                ? `You answered on ${formatDay(interview.submitted_on)}. Thank you.`
                                : 'HR no longer needs this survey.'
                            : 'About five minutes. Tell us what worked and what we should change.'
                    }
                    actions={<OffboardingNav current="me" counts={nav} />}
                />

                <p className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Lock className="size-4 shrink-0" />
                    Only the people who handle exits in your company can read
                    your answers. Your team cannot.
                </p>

                <form
                    onSubmit={submit}
                    className="flex max-w-3xl flex-col gap-6"
                >
                    {choices.map((question) => (
                        <Fieldset
                            key={question.key}
                            question={question}
                            error={fieldErrors[`answers.${question.key}`]}
                        >
                            <div
                                role="radiogroup"
                                aria-label={question.label}
                                className="grid gap-2 sm:grid-cols-2"
                            >
                                {Object.entries(question.options ?? {}).map(
                                    ([value, label]) => {
                                        const checked =
                                            answer(question.key) === value;

                                        return (
                                            <button
                                                key={value}
                                                type="button"
                                                role="radio"
                                                aria-checked={checked}
                                                disabled={readOnly}
                                                onClick={() =>
                                                    set(question.key, value)
                                                }
                                                className={cn(
                                                    'rounded-lg border px-3 py-2 text-left text-sm transition-colors disabled:cursor-default',
                                                    checked
                                                        ? 'border-[#0ABFBF] bg-[#0ABFBF]/10 font-medium text-foreground'
                                                        : 'border-border text-muted-foreground hover:border-foreground/30 hover:text-foreground disabled:hover:border-border disabled:hover:text-muted-foreground',
                                                )}
                                            >
                                                {label}
                                            </button>
                                        );
                                    },
                                )}
                            </div>
                        </Fieldset>
                    ))}

                    {ratings.length > 0 && (
                        <section className="flex flex-col gap-2">
                            <h2 className="text-sm font-semibold">
                                {ratings[0].group ?? 'How would you rate…'}
                                {/* The scale's words do not fit a phone's columns. */}
                                <span className="ml-1 font-normal text-muted-foreground sm:hidden">
                                    1 very poor, 5 very good
                                </span>
                            </h2>
                            <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
                                <table className="w-full text-sm">
                                    <thead className="bg-muted/40 text-xs text-muted-foreground">
                                        <tr>
                                            <th className="px-3 py-2 text-left font-medium sm:px-4">
                                                <span className="sr-only">
                                                    Topic
                                                </span>
                                            </th>
                                            {scale.map(([score, label]) => (
                                                <th
                                                    key={score}
                                                    className="w-9 px-0.5 py-2 text-center font-medium sm:w-16 sm:px-1"
                                                >
                                                    <span className="block tabular-nums">
                                                        {score}
                                                    </span>
                                                    <span className="hidden text-[10px] font-normal sm:block">
                                                        {label}
                                                    </span>
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-border">
                                        {ratings.map((question) => {
                                            const error =
                                                fieldErrors[
                                                    `answers.${question.key}`
                                                ];

                                            return (
                                                <tr
                                                    key={question.key}
                                                    role="radiogroup"
                                                    aria-label={question.label}
                                                    className={cn(
                                                        error &&
                                                            'bg-rose-500/[0.05]',
                                                    )}
                                                >
                                                    <th
                                                        scope="row"
                                                        className="px-3 py-2.5 text-left font-normal sm:px-4"
                                                    >
                                                        {question.label}
                                                        {error && (
                                                            <span className="block text-xs text-rose-600 dark:text-rose-400">
                                                                Pick a rating.
                                                            </span>
                                                        )}
                                                    </th>
                                                    {scale.map(
                                                        ([score, label]) => {
                                                            const checked =
                                                                Number(
                                                                    answer(
                                                                        question.key,
                                                                    ),
                                                                ) ===
                                                                Number(score);

                                                            return (
                                                                <td
                                                                    key={score}
                                                                    className="px-0.5 py-2 text-center sm:px-1"
                                                                >
                                                                    <button
                                                                        type="button"
                                                                        role="radio"
                                                                        aria-checked={
                                                                            checked
                                                                        }
                                                                        aria-label={`${score} — ${label}`}
                                                                        disabled={
                                                                            readOnly
                                                                        }
                                                                        onClick={() =>
                                                                            set(
                                                                                question.key,
                                                                                Number(
                                                                                    score,
                                                                                ),
                                                                            )
                                                                        }
                                                                        className={cn(
                                                                            'mx-auto flex size-7 items-center justify-center rounded-full border text-xs font-medium tabular-nums transition-colors disabled:cursor-default',
                                                                            checked
                                                                                ? 'border-[#0ABFBF] bg-[#0ABFBF] text-white'
                                                                                : 'border-border text-muted-foreground hover:border-[#0ABFBF] disabled:hover:border-border',
                                                                        )}
                                                                    >
                                                                        {score}
                                                                    </button>
                                                                </td>
                                                            );
                                                        },
                                                    )}
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    )}

                    <div className="grid gap-6 sm:grid-cols-2">
                        {yesNo.map((question) => (
                            <Fieldset
                                key={question.key}
                                question={question}
                                error={fieldErrors[`answers.${question.key}`]}
                            >
                                <div
                                    role="radiogroup"
                                    aria-label={question.label}
                                    className="grid grid-cols-3 gap-1 rounded-lg bg-muted p-1"
                                >
                                    {Object.entries(question.options ?? {}).map(
                                        ([value, label]) => (
                                            <button
                                                key={value}
                                                type="button"
                                                role="radio"
                                                aria-checked={
                                                    answer(question.key) ===
                                                    value
                                                }
                                                disabled={readOnly}
                                                onClick={() =>
                                                    set(question.key, value)
                                                }
                                                className={cn(
                                                    'rounded-md px-2 py-1.5 text-sm font-medium transition-colors disabled:cursor-default',
                                                    answer(question.key) ===
                                                        value
                                                        ? 'bg-background text-foreground shadow-xs'
                                                        : 'text-muted-foreground hover:text-foreground',
                                                )}
                                            >
                                                {label}
                                            </button>
                                        ),
                                    )}
                                </div>
                            </Fieldset>
                        ))}
                    </div>

                    {open.map((question) => (
                        <Fieldset
                            key={question.key}
                            question={question}
                            error={fieldErrors[`answers.${question.key}`]}
                        >
                            {readOnly ? (
                                <p className="text-sm whitespace-pre-line text-muted-foreground">
                                    {String(answer(question.key) ?? '—')}
                                </p>
                            ) : (
                                <textarea
                                    aria-label={question.label}
                                    value={String(answer(question.key) ?? '')}
                                    onChange={(event) =>
                                        set(question.key, event.target.value)
                                    }
                                    rows={3}
                                    maxLength={2000}
                                    className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-colors placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                                />
                            )}
                        </Fieldset>
                    ))}

                    {readOnly ? (
                        interview.status === 'submitted' && (
                            <p className="flex items-center gap-2 text-sm text-emerald-600 dark:text-emerald-400">
                                <CircleCheck className="size-4" />
                                Sent to HR on{' '}
                                {formatDay(interview.submitted_on)}.
                            </p>
                        )
                    ) : (
                        <div className="flex items-center justify-end gap-3 border-t border-border pt-4">
                            <Button type="submit" disabled={processing}>
                                {processing && <Spinner />}
                                Send my answers
                            </Button>
                        </div>
                    )}
                </form>
            </PageBody>
        </>
    );
}

function Fieldset({
    question,
    error,
    children,
}: {
    question: InterviewQuestion;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <fieldset className="flex flex-col gap-2">
            <legend className="mb-2 text-sm font-semibold">
                {question.label}
                {!question.required && (
                    <span className="ml-1.5 font-normal text-muted-foreground">
                        Optional
                    </span>
                )}
            </legend>
            {children}
            <InputError message={error} />
        </fieldset>
    );
}

ExitInterviewPage.layout = {
    breadcrumbs: [
        { title: 'Offboarding', href: offboardingRoutes.index },
        { title: 'My resignation', href: offboardingRoutes.me },
        { title: 'Exit interview', href: offboardingRoutes.myInterview },
    ],
};
