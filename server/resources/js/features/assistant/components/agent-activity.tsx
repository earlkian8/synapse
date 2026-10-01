import {
    ArchiveX,
    ArrowRightCircle,
    BadgeCheck,
    Ban,
    BellRing,
    BookOpen,
    CalendarClock,
    Check,
    CheckCircle2,
    Hourglass,
    Loader2,
    Megaphone,
    PencilLine,
    PlayCircle,
    Search,
    ShieldCheck,
    Sparkles,
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
    insight: Sparkles,
    confirm: ShieldCheck,
};

/** Badge colour per tone. */
const TONE_CLASS: Record<AgentCardTone, string> = {
    positive: 'bg-emerald-500/12 text-emerald-600 dark:text-emerald-400',
    info: 'bg-sky-500/12 text-sky-600 dark:text-sky-400',
    warning: 'bg-amber-500/12 text-amber-600 dark:text-amber-400',
    danger: 'bg-rose-500/12 text-rose-600 dark:text-rose-400',
    neutral: 'bg-muted text-muted-foreground',
};

/**
 * Reveals the agent's steps and result cards one at a time so a finished
 * server response still *feels* like live, deliberate work.
 */
/** Answer a held action; resolves to an error to show, or null. */
export type AnswerAction = (
    token: string,
    decision: 'confirm' | 'cancel',
) => Promise<string | null>;

export function AgentActivity({
    steps,
    actions,
    onRevealed,
    onAnswer,
}: {
    steps: AgentStep[];
    actions: AgentCard[];
    onRevealed?: () => void;
    onAnswer?: AnswerAction;
}) {
    const total = steps.length + actions.length;
    const [revealed, setRevealed] = useState(0);

    useEffect(() => {
        if (revealed >= total) {
            onRevealed?.();

            return;
        }

        const delay = revealed === 0 ? 200 : 520;
        const timer = setTimeout(
            () => setRevealed((value) => value + 1),
            delay,
        );

        return () => clearTimeout(timer);
    }, [revealed, total, onRevealed]);

    if (total === 0) {
        return null;
    }

    const visibleSteps = Math.min(revealed, steps.length);
    const visibleActions = Math.max(0, revealed - steps.length);

    return (
        <div className="mt-1 flex flex-col gap-2">
            {steps.length > 0 && (
                <ol className="flex flex-col gap-1.5 rounded-lg border border-border/70 bg-muted/40 p-2.5">
                    {steps.slice(0, visibleSteps).map((step, index) => (
                        <li
                            key={index}
                            className="flex animate-in items-start gap-2 text-xs duration-300 fade-in slide-in-from-left-1"
                        >
                            <StepIcon
                                status={step.status}
                                kind={step.kind}
                                isLast={
                                    index === visibleSteps - 1 &&
                                    revealed < total
                                }
                            />
                            <span className="min-w-0">
                                <span className="font-medium text-foreground">
                                    {step.label}
                                </span>
                                {step.detail && (
                                    <span className="text-muted-foreground">
                                        {' '}
                                        · {step.detail}
                                    </span>
                                )}
                            </span>
                        </li>
                    ))}
                </ol>
            )}

            {actions
                .slice(0, visibleActions)
                .map((card, index) =>
                    card.kind === 'confirm' && card.confirmation ? (
                        <ConfirmCard
                            key={index}
                            card={card}
                            onAnswer={onAnswer}
                        />
                    ) : (
                        <ResultCard key={index} card={card} />
                    ),
                )}
        </div>
    );
}

function StepIcon({
    status,
    kind,
    isLast,
}: {
    status: string;
    kind?: AgentStep['kind'];
    isLast: boolean;
}) {
    if (isLast && status === 'done') {
        return (
            <Loader2 className="mt-px size-3.5 shrink-0 animate-spin text-[#0ABFBF]" />
        );
    }

    // Proposed, not done: waiting on the user's answer.
    if (status === 'held') {
        return (
            <span className="mt-px flex size-3.5 shrink-0 items-center justify-center rounded-full bg-amber-500/15">
                <Hourglass className="size-2.5 text-amber-600 dark:text-amber-400" />
            </span>
        );
    }

    if (status === 'error') {
        return (
            <span className="mt-px flex size-3.5 shrink-0 items-center justify-center rounded-full bg-destructive/15">
                <X className="size-2.5 text-destructive" />
            </span>
        );
    }

    // A read is the record being consulted, not a change to it — the difference
    // between "here's what I found" and "here's what I did".
    if (kind === 'read') {
        return (
            <span className="mt-px flex size-3.5 shrink-0 items-center justify-center rounded-full bg-[#0ABFBF]/15">
                <BookOpen className="size-2.5 text-[#0a8b91] dark:text-[#0ABFBF]" />
            </span>
        );
    }

    return (
        <span className="mt-px flex size-3.5 shrink-0 items-center justify-center rounded-full bg-emerald-500/15">
            <Check className="size-2.5 text-emerald-600 dark:text-emerald-400" />
        </span>
    );
}

function ResultCard({ card }: { card: AgentCard }) {
    const Icon = KIND_ICON[card.kind] ?? Check;
    const tone = TONE_CLASS[card.tone] ?? TONE_CLASS.neutral;
    const meta = card.meta.filter(Boolean);
    // Read-outs (a pipeline summary, a ranked candidate, an AI read) carry
    // several figures worth seeing at once, so their meta becomes a chip row
    // instead of being collapsed into the single subtitle line.
    const chips = card.kind === 'insight' ? meta.slice(1) : [];

    return (
        <div className="relative animate-in overflow-hidden rounded-xl border border-border bg-card p-3 shadow-sm duration-500 zoom-in-95 fade-in slide-in-from-bottom-1">
            {/* one-shot sheen sweeping across on reveal */}
            <span className="pointer-events-none absolute inset-0 -translate-x-full animate-[assistant-sheen_1.1s_ease-out_forwards] bg-gradient-to-r from-transparent via-[#0ABFBF]/10 to-transparent" />
            <div className="flex items-center gap-3">
                <span className="animate-[assistant-pop_0.5s_ease-out]">
                    {card.avatar ? (
                        <PersonAvatar
                            name={card.avatar.name}
                            initials={card.avatar.initials}
                            photo={card.avatar.photo}
                            className="size-10 ring-2 ring-[#0ABFBF]/30"
                        />
                    ) : (
                        <span className="flex size-10 items-center justify-center rounded-full bg-[#0F2044] text-[#0ABFBF] ring-2 ring-[#0ABFBF]/30">
                            <Icon className="size-5" />
                        </span>
                    )}
                </span>
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-semibold">
                        {card.title}
                    </p>
                    <p className="truncate text-xs text-muted-foreground">
                        {meta.length > 0 && (
                            <span className="font-mono">{meta[0]}</span>
                        )}
                        {card.subtitle && (
                            <>
                                {meta.length > 0 && <> · </>}
                                {card.subtitle}
                            </>
                        )}
                    </p>
                </div>
                <span
                    className={cn(
                        'inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold',
                        tone,
                    )}
                >
                    <Icon className="size-3" />
                    {card.badge}
                </span>
            </div>

            {chips.length > 0 && (
                <div className="mt-2 flex flex-wrap gap-1 pl-13">
                    {chips.map((chip) => (
                        <span
                            key={chip}
                            className="rounded-md bg-muted px-1.5 py-0.5 text-[11px] text-muted-foreground"
                        >
                            {chip}
                        </span>
                    ))}
                </div>
            )}
        </div>
    );
}

/**
 * An action the assistant proposed but may not take on its own (ADR 0049): it
 * says exactly what would run — the tool and the arguments it would run with —
 * and waits for Confirm or Cancel. Nothing has changed until Confirm is pressed,
 * and the server runs precisely what is shown here.
 */
function ConfirmCard({
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

    const reason = card.meta[0];

    return (
        <section
            aria-label={`Waiting for your OK: ${card.title}`}
            className={cn(
                'animate-in rounded-xl border p-3 shadow-sm duration-500 fade-in slide-in-from-bottom-1',
                open
                    ? 'border-amber-500/40 bg-amber-500/5'
                    : 'border-border bg-card',
            )}
        >
            <div className="flex items-start gap-3">
                <span
                    className={cn(
                        'flex size-9 shrink-0 items-center justify-center rounded-full',
                        open
                            ? 'bg-amber-500/15 text-amber-600 dark:text-amber-400'
                            : 'bg-muted text-muted-foreground',
                    )}
                >
                    <ShieldCheck className="size-4.5" />
                </span>
                <div className="min-w-0 flex-1">
                    <p className="text-sm font-semibold">{card.title}</p>
                    {card.subtitle && (
                        <p className="mt-0.5 text-xs break-words text-muted-foreground">
                            {card.subtitle}
                        </p>
                    )}
                    {open && reason && (
                        <p className="mt-1.5 text-xs text-amber-700 dark:text-amber-300">
                            {reason}
                        </p>
                    )}
                </div>
                <StateBadge state={state} />
            </div>

            {open && (
                <div className="mt-3 flex items-center justify-end gap-2">
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={working !== null || !onAnswer}
                        onClick={() => void answer('cancel')}
                    >
                        {working === 'cancel' ? <Spinner /> : <X />}
                        Cancel
                    </Button>
                    <Button
                        size="sm"
                        disabled={working !== null || !onAnswer}
                        onClick={() => void answer('confirm')}
                    >
                        {working === 'confirm' ? <Spinner /> : <Check />}
                        Confirm
                    </Button>
                </div>
            )}

            {error && (
                <p
                    role="alert"
                    className="mt-2 text-xs text-rose-600 dark:text-rose-400"
                >
                    {error}
                </p>
            )}
        </section>
    );
}

function StateBadge({ state }: { state: string }) {
    const [label, className] =
        state === 'confirmed'
            ? ['Confirmed', TONE_CLASS.positive]
            : state === 'cancelled'
              ? ['Cancelled', TONE_CLASS.neutral]
              : state === 'expired'
                ? ['Expired', TONE_CLASS.neutral]
                : ['Needs your OK', TONE_CLASS.warning];

    return (
        <span
            className={cn(
                'inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-[11px] font-semibold',
                className,
            )}
        >
            {label}
        </span>
    );
}
