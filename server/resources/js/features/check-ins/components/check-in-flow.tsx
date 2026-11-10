import { router } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Check, LockKeyhole } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { MOOD_WORDS, answerLabel, engagementOf } from '../constants';
import { checkInRoutes } from '../routes';
import type { AnswerKey, Answers, Question } from '../types';
import { MoodFace } from './mood-face';

/** The most a private note may hold (CheckInQuestions::NOTE_MAX). */
const NOTE_MAX = 500;

/**
 * This month's check-in, one question at a time (ADR 0102). The face beside the
 * questions wears the mood answer; nothing else moves it. Choosing an answer moves on
 * by itself, a number key chooses one, and the last step reads every answer back,
 * with a note only the person can read, before anything is saved.
 */
export function CheckInFlow({
    questions,
    periodLabel,
    initial,
    onDone,
    onCancel,
}: {
    questions: Question[];
    periodLabel: string;
    /** This month's answers when changing them. */
    initial?: { answers: Answers; note: string | null } | null;
    onDone: () => void;
    onCancel?: () => void;
}) {
    const [answers, setAnswers] = useState<Partial<Answers>>(
        initial?.answers ?? {},
    );
    const [note, setNote] = useState(initial?.note ?? '');
    const [step, setStep] = useState(initial ? questions.length : 0);
    const [saving, setSaving] = useState(false);
    // Changing one answer from the read-back returns to it once chosen.
    const [returning, setReturning] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const reviewing = step === questions.length;
    const question = reviewing ? null : questions[step];
    const answered = question ? answers[question.key] !== undefined : true;

    const choose = (key: AnswerKey, value: number) => {
        setAnswers((current) => ({ ...current, [key]: value }));
        // Move on once the choice has been seen: to the next question, or back to
        // the read-back when only this answer was being changed.
        const next = returning ? questions.length : step + 1;
        setReturning(false);
        window.setTimeout(() => setStep((s) => (s === step ? next : s)), 260);
    };

    // A number key answers: 1–5 on five-step scales, 0–6 on the frequency ones.
    useEffect(() => {
        if (!question) {
            return;
        }

        const onKey = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement | null;

            if (
                target?.closest('textarea, input') ||
                event.metaKey ||
                event.ctrlKey ||
                event.altKey
            ) {
                return;
            }

            const n = Number(event.key);

            if (
                event.key.length === 1 &&
                !Number.isNaN(n) &&
                n >= question.min &&
                n <= question.max
            ) {
                event.preventDefault();
                choose(question.key, n);
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    });

    const save = () => {
        setSaving(true);
        setError(null);
        router.post(
            checkInRoutes.store,
            { ...answers, note: note.trim() || null },
            {
                preserveScroll: true,
                onSuccess: () => onDone(),
                onError: (errors) =>
                    setError(
                        Object.values(errors)[0] ??
                            'The check-in could not be saved.',
                    ),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <section
            aria-label={`${periodLabel} check-in`}
            className="grid overflow-hidden rounded-2xl border border-sidebar-border/70 bg-card md:grid-cols-[minmax(0,15rem)_minmax(0,1fr)] dark:border-sidebar-border"
        >
            {/* The face, and the mood in words. */}
            <div className="flex flex-row items-center gap-4 border-b border-sidebar-border/70 bg-muted/40 px-6 py-5 md:flex-col md:justify-center md:gap-3 md:border-r md:border-b-0 md:py-10 dark:border-sidebar-border">
                <MoodFace
                    mood={answers.mood ?? null}
                    size={132}
                    className="size-20 md:size-[132px]"
                />
                <div className="flex min-w-0 flex-col md:items-center">
                    <p
                        className="text-3xl font-semibold tracking-tight md:text-4xl"
                        aria-live="polite"
                    >
                        {answers.mood ? MOOD_WORDS[answers.mood - 1] : '…'}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {periodLabel}
                    </p>
                </div>
            </div>

            <div className="flex min-w-0 flex-col gap-6 p-5 sm:p-7">
                <Progress
                    total={questions.length}
                    step={step}
                    answers={answers}
                    questions={questions}
                    onStep={setStep}
                />

                {question ? (
                    <div key={question.key} className="flex flex-col gap-5">
                        <div className="flex flex-col gap-1.5">
                            {question.group === 'engagement' && (
                                <p className="text-sm text-muted-foreground">
                                    This month, how often did you feel this way?
                                </p>
                            )}
                            <h2 className="text-xl font-semibold tracking-tight text-balance sm:text-2xl">
                                {question.prompt}
                            </h2>
                        </div>
                        {question.key === 'mood' ? (
                            <MoodChoices
                                value={answers.mood}
                                onChoose={(v) => choose('mood', v)}
                            />
                        ) : (
                            <ScaleChoices
                                question={question}
                                value={answers[question.key]}
                                onChoose={(v) => choose(question.key, v)}
                            />
                        )}
                    </div>
                ) : (
                    <Review
                        questions={questions}
                        answers={answers as Answers}
                        note={note}
                        onNote={setNote}
                        onStep={(i) => {
                            setReturning(true);
                            setStep(i);
                        }}
                    />
                )}

                {error && (
                    <p className="text-sm text-destructive" role="alert">
                        {error}
                    </p>
                )}

                <div className="mt-auto flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4">
                    <p className="flex max-w-md items-start gap-2 text-xs text-muted-foreground">
                        <LockKeyhole
                            className="mt-0.5 size-3.5 shrink-0"
                            aria-hidden
                        />
                        Confidential. No one at your company sees your answers,
                        only results for groups of five or more.
                    </p>
                    <div className="flex items-center gap-2">
                        {step > 0 ? (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => setStep(step - 1)}
                            >
                                <ArrowLeft className="size-4" />
                                Back
                            </Button>
                        ) : (
                            onCancel && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={onCancel}
                                >
                                    Cancel
                                </Button>
                            )
                        )}
                        {reviewing ? (
                            <Button
                                type="button"
                                onClick={save}
                                disabled={saving}
                            >
                                <Check className="size-4" />
                                {saving ? 'Saving…' : 'Save check-in'}
                            </Button>
                        ) : (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setStep(step + 1)}
                                disabled={!answered}
                            >
                                Next
                                <ArrowRight className="size-4" />
                            </Button>
                        )}
                    </div>
                </div>
            </div>
        </section>
    );
}

/** Where the person is: a segment per question, filled once answered. */
function Progress({
    total,
    step,
    answers,
    questions,
    onStep,
}: {
    total: number;
    step: number;
    answers: Partial<Answers>;
    questions: Question[];
    onStep: (step: number) => void;
}) {
    return (
        <div className="flex flex-col gap-2">
            <p className="text-xs font-medium text-muted-foreground">
                {step < total
                    ? `Question ${step + 1} of ${total}`
                    : 'Read it back, then save'}
            </p>
            <ol className="flex gap-1.5" aria-label="Questions">
                {questions.map((q, i) => {
                    const done = answers[q.key] !== undefined;

                    return (
                        <li key={q.key} className="flex-1">
                            <button
                                type="button"
                                onClick={() => done && onStep(i)}
                                disabled={!done}
                                aria-label={`Question ${i + 1}${done ? ', answered' : ''}`}
                                aria-current={i === step ? 'step' : undefined}
                                className={cn(
                                    'block h-1.5 w-full rounded-full transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                    i === step
                                        ? 'bg-foreground'
                                        : done
                                          ? 'bg-foreground/35 hover:bg-foreground/60'
                                          : 'bg-muted',
                                )}
                            />
                        </li>
                    );
                })}
            </ol>
        </div>
    );
}

/** Five faces, the mood each stands for under it. */
function MoodChoices({
    value,
    onChoose,
}: {
    value: number | undefined;
    onChoose: (value: number) => void;
}) {
    return (
        <div
            role="radiogroup"
            aria-label="Mood"
            className="grid grid-cols-5 gap-1.5 sm:gap-3"
        >
            {MOOD_WORDS.map((word, i) => {
                const mood = i + 1;
                const selected = value === mood;

                return (
                    <button
                        key={word}
                        type="button"
                        role="radio"
                        aria-checked={selected}
                        onClick={() => onChoose(mood)}
                        className={cn(
                            'group flex flex-col items-center gap-1.5 rounded-xl border px-1 py-3 transition-[border-color,background-color] focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:py-4',
                            selected
                                ? 'border-foreground bg-muted/60'
                                : 'border-transparent hover:border-border hover:bg-muted/40',
                        )}
                    >
                        <MoodFace
                            mood={mood}
                            size={52}
                            className={cn(
                                'size-10 transition-transform duration-200 motion-safe:group-hover:-translate-y-0.5 sm:size-[52px]',
                                value !== undefined &&
                                    !selected &&
                                    'opacity-55',
                            )}
                        />
                        <span
                            className={cn(
                                'text-xs sm:text-sm',
                                selected
                                    ? 'font-semibold'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {word}
                        </span>
                        <span className="sr-only">Key {mood}</span>
                    </button>
                );
            })}
        </div>
    );
}

/**
 * A scale as a row of steps (five for agreement, seven for how often), drawn in ink
 * rather than mood colours: an answer here is a fact, not good or bad news.
 */
function ScaleChoices({
    question,
    value,
    onChoose,
}: {
    question: Question;
    value: number | undefined;
    onChoose: (value: number) => void;
}) {
    const picked = question.options.find((o) => o.value === value);
    const frequency = question.options.length === 7;

    return (
        <div className="flex flex-col gap-3">
            <div
                role="radiogroup"
                aria-label={question.prompt}
                className={cn(
                    'grid gap-1.5',
                    frequency ? 'grid-cols-7' : 'grid-cols-5',
                )}
            >
                {question.options.map((option) => {
                    const selected = option.value === value;
                    const below = value !== undefined && option.value < value;

                    return (
                        <button
                            key={option.value}
                            type="button"
                            role="radio"
                            aria-checked={selected}
                            aria-label={
                                option.hint
                                    ? `${option.label}: ${option.hint}`
                                    : option.label
                            }
                            onClick={() => onChoose(option.value)}
                            className={cn(
                                'group flex flex-col items-center gap-2 rounded-lg px-0.5 py-2 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            )}
                        >
                            <span
                                className={cn(
                                    'h-9 w-full rounded-md border transition-colors',
                                    selected
                                        ? 'border-foreground bg-foreground'
                                        : below
                                          ? 'border-foreground/25 bg-foreground/15'
                                          : 'border-border bg-muted/40 group-hover:border-foreground/40',
                                )}
                            />
                            {!frequency && (
                                <span
                                    className={cn(
                                        'text-center text-[11px] leading-tight sm:text-xs',
                                        selected
                                            ? 'font-semibold'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {option.label}
                                </span>
                            )}
                        </button>
                    );
                })}
            </div>
            {frequency && (
                <div className="flex items-baseline justify-between gap-3 text-xs text-muted-foreground">
                    <span>{question.options[0].label}</span>
                    <span
                        className="text-center text-sm text-foreground"
                        aria-live="polite"
                    >
                        {picked ? (
                            <>
                                <span className="font-semibold">
                                    {picked.label}
                                </span>
                                {picked.hint && (
                                    <span className="text-muted-foreground">
                                        {' '}
                                        ({picked.hint.toLowerCase()})
                                    </span>
                                )}
                            </>
                        ) : (
                            'Choose how often'
                        )}
                    </span>
                    <span>{question.options.at(-1)?.label}</span>
                </div>
            )}
        </div>
    );
}

/** Every answer read back, each one a step to change, and the private note. */
function Review({
    questions,
    answers,
    note,
    onNote,
    onStep,
}: {
    questions: Question[];
    answers: Answers;
    note: string;
    onNote: (note: string) => void;
    onStep: (step: number) => void;
}) {
    const engagement = engagementOf(answers);

    return (
        <div className="flex flex-col gap-5">
            <div className="flex flex-col gap-1">
                <h2 className="text-xl font-semibold tracking-tight sm:text-2xl">
                    Here is your month
                </h2>
                {engagement !== null && (
                    <p className="text-sm text-muted-foreground">
                        Engagement {engagement.toFixed(1)} of 6, from your three
                        answers about energy, enthusiasm and focus.
                    </p>
                )}
            </div>
            <dl className="divide-y divide-border rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                {questions.map((q, i) => (
                    <div
                        key={q.key}
                        className="flex items-center justify-between gap-4 px-4 py-2.5"
                    >
                        <dt className="min-w-0 text-sm text-muted-foreground">
                            {q.prompt}
                        </dt>
                        <dd className="flex shrink-0 items-center gap-3 text-sm font-medium">
                            {answerLabel(questions, q.key, answers[q.key])}
                            <button
                                type="button"
                                onClick={() => onStep(i)}
                                className="text-xs font-normal text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                            >
                                Change
                            </button>
                        </dd>
                    </div>
                ))}
            </dl>
            <label className="flex flex-col gap-1.5">
                <span className="flex items-center justify-between text-sm font-medium">
                    A note for yourself
                    <span className="flex items-center gap-1 text-xs font-normal text-muted-foreground">
                        <LockKeyhole className="size-3" aria-hidden />
                        Only you can read it
                    </span>
                </span>
                <textarea
                    value={note}
                    onChange={(e) => onNote(e.target.value.slice(0, NOTE_MAX))}
                    rows={3}
                    maxLength={NOTE_MAX}
                    placeholder="What made the month what it was? (optional)"
                    className="min-h-20 rounded-lg border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
                <span className="self-end text-[11px] text-muted-foreground tabular-nums">
                    {note.length}/{NOTE_MAX}
                </span>
            </label>
        </div>
    );
}
