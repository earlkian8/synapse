import {
    ArchiveX,
    ArrowRightCircle,
    BadgeCheck,
    Ban,
    BarChart3,
    BellRing,
    CalendarClock,
    Check,
    CheckCircle2,
    ChevronRight,
    Megaphone,
    PencilLine,
    PlayCircle,
    Search,
    ShieldCheck,
    Trophy,
    UserPlus,
    X,
    XCircle,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import type {
    AgentCard,
    AgentCardKind,
    AgentCardTone,
    AgentStep,
} from '../types';

/** Icon per card kind (with a sensible fallback). */
const KIND_ICON: Record<AgentCardKind, LucideIcon> = {
    add: UserPlus,
    edit: PencilLine,
    archive: ArchiveX,
    approve: CheckCircle2,
    reject: XCircle,
    cancel: Ban,
    schedule: CalendarClock,
    find: Search,
    start: PlayCircle,
    move: ArrowRightCircle,
    hire: BadgeCheck,
    post: Megaphone,
    remind: BellRing,
    award: Trophy,
    insight: BarChart3,
    confirm: ShieldCheck,
};

/** Status colour per tone: the receipt's status word and its dot. */
const TONE_CLASS: Record<AgentCardTone, { text: string; dot: string }> = {
    positive: {
        text: 'text-emerald-700 dark:text-emerald-400',
        dot: 'bg-emerald-500',
    },
    info: { text: 'text-sky-700 dark:text-sky-400', dot: 'bg-sky-500' },
    warning: {
        text: 'text-amber-700 dark:text-amber-400',
        dot: 'bg-amber-500',
    },
    danger: { text: 'text-rose-700 dark:text-rose-400', dot: 'bg-rose-500' },
    neutral: { text: 'text-muted-foreground', dot: 'bg-muted-foreground/60' },
};

/** The longest delay setTimeout accepts (2^31 − 1 ms). */
const MAX_TIMEOUT = 2_147_483_647;

/** Answer a held action; resolves to an error to show, or null. */
export type AnswerAction = (
    token: string,
    decision: 'confirm' | 'cancel',
) => Promise<string | null>;

// ── The work trace ───────────────────────────────────────────────────────────

/**
 * What a node on the trace stands for. Each is drawn differently, because the
 * difference matters: a read consulted the record, a change altered it, a held
 * step waits on the user, and an error did not happen.
 */
export type TraceNodeKind = 'read' | 'change' | 'held' | 'error' | 'working';

function nodeKind(step: AgentStep): TraceNodeKind {
    if (step.status === 'held') {
        return 'held';
    }

    if (step.status === 'error') {
        return 'error';
    }

    // Turns recorded before reads were told apart carry no kind; they were
    // the assistant's actions.
    return step.kind === 'read' ? 'read' : 'change';
}

const NODE_LABEL: Record<TraceNodeKind, string> = {
    read: 'Read',
    change: 'Changed',
    held: 'Waiting for your OK',
    error: 'Failed',
    working: 'Working',
};

/** A node of the trace: hollow for a read, filled for a change. */
export function TraceNode({ kind }: { kind: TraceNodeKind }) {
    return (
        <span
            className={cn(
                'block size-2.5 shrink-0 rounded-full',
                kind === 'read' &&
                    'border-[1.5px] border-assistant-signal-text bg-background',
                kind === 'change' && 'bg-assistant-signal',
                kind === 'held' &&
                    'border-[1.5px] border-amber-500 bg-amber-100 dark:bg-amber-500/25',
                kind === 'error' && 'bg-rose-500',
                kind === 'working' &&
                    'assistant-working-node bg-assistant-signal',
            )}
        />
    );
}

/** Traces longer than this start folded into their one-line summary. */
const FOLD_AFTER = 3;

/**
 * The assistant's work for one turn, as a line of nodes: what it read before
 * answering and what it changed, in order. It is the grounding behind the reply
 * — the reader can check an answer against what it was built from — so it is
 * kept, not hidden; a long one is folded into a sentence that says what is in it.
 */
export function WorkTrace({ steps }: { steps: AgentStep[] }) {
    const [expanded, setExpanded] = useState(steps.length <= FOLD_AFTER);

    if (steps.length === 0) {
        return null;
    }

    const foldable = steps.length > FOLD_AFTER;

    return (
        <div className="flex flex-col gap-1.5">
            {foldable && (
                <button
                    type="button"
                    onClick={() => setExpanded((value) => !value)}
                    aria-expanded={expanded}
                    className="-ml-1 flex w-fit items-center gap-1 rounded-md px-1 py-0.5 text-xs text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                >
                    <ChevronRight
                        className={cn(
                            'size-3.5 transition-transform',
                            expanded && 'rotate-90',
                        )}
                    />
                    {summarize(steps)}
                </button>
            )}

            {expanded && (
                <ol
                    aria-label="What the assistant read and did"
                    className={cn(foldable && 'pl-0.5')}
                >
                    {steps.map((step, index) => {
                        const kind = nodeKind(step);
                        const last = index === steps.length - 1;

                        return (
                            <li
                                key={index}
                                style={
                                    {
                                        '--trace-index': index,
                                    } as React.CSSProperties
                                }
                                className={cn(
                                    'assistant-trace-row relative flex gap-2.5',
                                    !last && 'pb-2',
                                )}
                            >
                                <span className="relative flex w-2.5 shrink-0 justify-center pt-[5px]">
                                    <TraceNode kind={kind} />
                                    {!last && (
                                        <span className="absolute top-[17px] -bottom-[3px] left-1/2 w-px -translate-x-1/2 bg-border" />
                                    )}
                                </span>
                                <div className="min-w-0 flex-1 text-[13px] leading-5">
                                    <span className="sr-only">
                                        {NODE_LABEL[kind]}:{' '}
                                    </span>
                                    <span
                                        className={cn(
                                            kind === 'error'
                                                ? 'text-rose-700 dark:text-rose-400'
                                                : 'text-foreground/85',
                                        )}
                                    >
                                        {step.label}
                                    </span>
                                    {step.detail && (
                                        <p className="text-xs break-words text-muted-foreground">
                                            {step.detail}
                                        </p>
                                    )}
                                </div>
                            </li>
                        );
                    })}
                </ol>
            )}
        </div>
    );
}

/** "Read 4 sources and made 1 change" — what a folded trace holds. */
function summarize(steps: AgentStep[]): string {
    const count = (kind: TraceNodeKind) =>
        steps.filter((step) => nodeKind(step) === kind).length;
    const plural = (n: number, one: string, many: string) =>
        `${n} ${n === 1 ? one : many}`;

    const reads = count('read');
    const changes = count('change');
    const held = count('held');
    const errors = count('error');

    const parts = [
        reads > 0 && `read ${plural(reads, 'source', 'sources')}`,
        changes > 0 && `made ${plural(changes, 'change', 'changes')}`,
        held > 0 && `held ${plural(held, 'action', 'actions')} for your OK`,
        errors > 0 && `${plural(errors, 'step', 'steps')} failed`,
    ].filter((part): part is string => part !== false);

    const sentence =
        parts.length <= 1
            ? (parts[0] ?? plural(steps.length, 'step', 'steps'))
            : `${parts.slice(0, -1).join(', ')} and ${parts[parts.length - 1]}`;

    return sentence.charAt(0).toUpperCase() + sentence.slice(1);
}

// ── Receipts ─────────────────────────────────────────────────────────────────

/**
 * What the turn produced — a person added, a request approved, a ranking read
 * out — as one ruled list. Each line says what it is about and, in its own
 * colour, what happened to it.
 */
export function Receipts({ cards }: { cards: AgentCard[] }) {
    if (cards.length === 0) {
        return null;
    }

    return (
        <ul className="divide-y divide-border overflow-hidden rounded-lg border border-border bg-card">
            {cards.map((card, index) => (
                <Receipt key={index} card={card} />
            ))}
        </ul>
    );
}

function Receipt({ card }: { card: AgentCard }) {
    const Icon = KIND_ICON[card.kind] ?? Check;
    const tone = TONE_CLASS[card.tone] ?? TONE_CLASS.neutral;
    const meta = card.meta.filter(Boolean);
    // Read-outs (a pipeline summary, a ranked candidate, an AI read) carry
    // several figures worth seeing at once, so their meta becomes a row of
    // figures instead of being collapsed into the single subtitle line.
    const figures = card.kind === 'insight' ? meta.slice(1) : [];

    return (
        <li className="flex gap-3 px-3 py-2.5">
            {card.avatar ? (
                <PersonAvatar
                    name={card.avatar.name}
                    initials={card.avatar.initials}
                    photo={card.avatar.photo}
                    className="size-8"
                />
            ) : (
                <span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-muted text-foreground/70">
                    <Icon className="size-4" />
                </span>
            )}

            <div className="min-w-0 flex-1">
                <div className="flex items-baseline gap-3">
                    <p className="min-w-0 flex-1 truncate text-sm font-medium">
                        {card.title}
                    </p>
                    <span
                        className={cn(
                            'flex shrink-0 items-center gap-1.5 text-xs font-medium',
                            tone.text,
                        )}
                    >
                        <span
                            className={cn('size-1.5 rounded-full', tone.dot)}
                        />
                        {card.badge}
                    </span>
                </div>

                {(meta.length > 0 || card.subtitle) && (
                    <p className="flex flex-wrap gap-x-2 text-xs text-muted-foreground tabular-nums">
                        {meta.length > 0 && <span>{meta[0]}</span>}
                        {card.subtitle && (
                            <span className="min-w-0 truncate">
                                {card.subtitle}
                            </span>
                        )}
                    </p>
                )}

                {figures.length > 0 && (
                    <ul className="mt-1.5 flex flex-wrap gap-1">
                        {figures.map((figure) => (
                            <li
                                key={figure}
                                className="rounded bg-muted px-1.5 py-0.5 text-[11px] text-foreground/75 tabular-nums"
                            >
                                {figure}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </li>
    );
}

// ── Held actions ─────────────────────────────────────────────────────────────

/**
 * An action the assistant proposed but may not take on its own (ADR 0049) — or
 * a plan of several, in order (ADR 0068): it says exactly what would run — the
 * tools and the arguments they would run with — and waits for Confirm or
 * Cancel. Nothing has changed until Confirm is pressed,
 * and the server runs precisely what is shown here. Once answered it shrinks to
 * a line that says how it was answered.
 */
export function ConfirmCard({
    card,
    onAnswer,
}: {
    card: AgentCard;
    onAnswer?: AnswerAction;
}) {
    const confirmation = card.confirmation!;
    const [working, setWorking] = useState<'confirm' | 'cancel' | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [lapsed, setLapsed] = useState(false);

    // A held action expires on the server; stop offering it when it does.
    useEffect(() => {
        if (confirmation.state !== 'pending' || !confirmation.expires_at) {
            return;
        }

        const remaining =
            new Date(confirmation.expires_at).getTime() - Date.now();

        // A delay past setTimeout's limit (~24.8 days) would fire at once.
        if (!(remaining <= MAX_TIMEOUT)) {
            return;
        }

        const timer = setTimeout(() => setLapsed(true), Math.max(0, remaining));

        return () => clearTimeout(timer);
    }, [confirmation.state, confirmation.expires_at]);

    const state =
        confirmation.state === 'pending' && lapsed
            ? 'expired'
            : confirmation.state;
    const open = state === 'pending' && confirmation.token !== null;

    const answer = async (decision: 'confirm' | 'cancel') => {
        if (!confirmation.token || !onAnswer) {
            return;
        }

        setWorking(decision);
        setError(null);
        const problem = await onAnswer(confirmation.token, decision);
        setWorking(null);
        setError(problem);
    };

    if (!open) {
        const [label, Icon, className] =
            state === 'confirmed'
                ? ['Confirmed', Check, 'text-emerald-700 dark:text-emerald-400']
                : state === 'cancelled'
                  ? ['Cancelled', X, 'text-muted-foreground']
                  : ['Expired', X, 'text-muted-foreground'];

        return (
            <div className="flex flex-col gap-1">
                <p className="flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm">
                    <ShieldCheck className="size-4 shrink-0 text-muted-foreground" />
                    <span className="min-w-0 flex-1 truncate text-foreground/85">
                        {card.title}
                    </span>
                    <span
                        className={cn(
                            'flex shrink-0 items-center gap-1 text-xs font-medium',
                            className,
                        )}
                    >
                        <Icon className="size-3.5" />
                        {label}
                    </span>
                </p>
                {error && <AnswerError error={error} />}
            </div>
        );
    }

    const plan = card.plan ?? [];
    const steps = plan.length > 1 ? plan : [];
    // A plan's notes: what each step would reach, then why it waits.
    const notes = card.meta.slice(0, steps.length > 0 ? 4 : 1);

    return (
        <section
            aria-label={`Waiting for your OK: ${card.title}`}
            className="overflow-hidden rounded-lg border border-amber-500/45 bg-amber-50/70 dark:bg-amber-500/[0.06]"
        >
            <div className="flex gap-3 px-3 pt-3 pb-2.5">
                <ShieldCheck className="mt-0.5 size-4.5 shrink-0 text-amber-600 dark:text-amber-400" />
                <div className="min-w-0 flex-1">
                    <p className="text-xs font-medium text-amber-800 dark:text-amber-300">
                        Waiting for your OK
                    </p>
                    <p className="mt-0.5 text-sm font-semibold">{card.title}</p>
                    {steps.length > 0 ? (
                        <ol
                            aria-label="Steps, in the order they will run"
                            className="mt-1.5 flex flex-col gap-1.5"
                        >
                            {steps.map((step, index) => (
                                <li key={index} className="flex gap-2 text-xs">
                                    <span className="flex size-4 shrink-0 items-center justify-center rounded-full bg-amber-500/15 text-[10px] font-semibold text-amber-800 tabular-nums dark:text-amber-300">
                                        {index + 1}
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="font-medium text-foreground/90">
                                            {step.title}
                                        </span>
                                        {step.detail && (
                                            <span className="block break-words text-foreground/70">
                                                {step.detail}
                                            </span>
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ol>
                    ) : (
                        card.subtitle && (
                            <p className="mt-1 text-xs break-words text-foreground/75">
                                {card.subtitle}
                            </p>
                        )
                    )}
                    {notes.map((note, index) => (
                        <p
                            key={index}
                            className="mt-1.5 text-xs text-muted-foreground"
                        >
                            {note}
                        </p>
                    ))}
                    {error && <AnswerError error={error} />}
                </div>
            </div>

            <div className="flex items-center justify-end gap-2 border-t border-amber-500/25 px-3 py-2">
                <p className="mr-auto min-w-0 flex-1 text-[11px] text-muted-foreground">
                    {steps.length > 0
                        ? 'Nothing changes until you confirm. Steps run in order and stop at the first that fails.'
                        : 'Nothing changes until you confirm.'}
                </p>
                <Button
                    size="sm"
                    variant="ghost"
                    disabled={working !== null || !onAnswer}
                    onClick={() => void answer('cancel')}
                >
                    {working === 'cancel' && <Spinner />}
                    Cancel
                </Button>
                <Button
                    size="sm"
                    disabled={working !== null || !onAnswer}
                    onClick={() => void answer('confirm')}
                    className="bg-assistant-ink text-white hover:bg-assistant-ink/90"
                >
                    {working === 'confirm' ? <Spinner /> : <Check />}
                    Confirm
                </Button>
            </div>
        </section>
    );
}

function AnswerError({ error }: { error: string }) {
    return (
        <p
            role="alert"
            className="mt-1.5 text-xs text-rose-700 dark:text-rose-400"
        >
            {error}
        </p>
    );
}
