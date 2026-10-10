import {
    AlertTriangle,
    Check,
    Copy,
    FileText,
    Pencil,
    RotateCcw,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useClipboard } from '@/hooks/use-clipboard';
import { cn } from '@/lib/utils';
import type { ChatMessage, TurnUsage } from '../types';
import { ConfirmCard, Receipts, TraceNode, WorkTrace } from './agent-activity';
import type { AnswerAction } from './agent-activity';
import { Markdown } from './markdown';

function formatTime(iso?: string | null): string {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);

    return Number.isNaN(date.getTime())
        ? ''
        : date.toLocaleTimeString(undefined, {
              hour: 'numeric',
              minute: '2-digit',
          });
}

const prefersReducedMotion = () =>
    typeof window !== 'undefined' &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

export function MessageItem({
    message,
    isLast,
    streaming,
    sending,
    onStreamDone,
    onRegenerate,
    onEdit,
    onRetry,
    onAnswer,
}: {
    message: ChatMessage;
    isLast: boolean;
    streaming: boolean;
    sending: boolean;
    onStreamDone: (id: number | string) => void;
    onRegenerate: () => void;
    onEdit: (id: number | string, text: string) => void;
    onRetry: () => void;
    onAnswer: AnswerAction;
}) {
    if (message.role === 'user') {
        return (
            <UserMessage message={message} disabled={sending} onEdit={onEdit} />
        );
    }

    if (message.pending) {
        return <Working />;
    }

    return (
        <AssistantMessage
            message={message}
            isLast={isLast}
            streaming={streaming}
            sending={sending}
            onStreamDone={onStreamDone}
            onRegenerate={onRegenerate}
            onRetry={onRetry}
            onAnswer={onAnswer}
        />
    );
}

// ── User ─────────────────────────────────────────────────────────────────────

function UserMessage({
    message,
    disabled,
    onEdit,
}: {
    message: ChatMessage;
    disabled: boolean;
    onEdit: (id: number | string, text: string) => void;
}) {
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(message.text);
    const canEdit = typeof message.id === 'number';

    const cancel = () => {
        setEditing(false);
        setDraft(message.text);
    };

    const submit = () => {
        if (draft.trim() === '' || draft === message.text) {
            return;
        }

        setEditing(false);
        onEdit(message.id, draft.trim());
    };

    if (editing) {
        return (
            <div className="ml-8 flex flex-col gap-2 rounded-xl border border-input bg-background p-2 focus-within:border-assistant-signal-text/60 focus-within:ring-3 focus-within:ring-assistant-signal/15">
                <label className="sr-only" htmlFor={`edit-${message.id}`}>
                    Edit your message
                </label>
                <textarea
                    id={`edit-${message.id}`}
                    value={draft}
                    autoFocus
                    onChange={(e) => setDraft(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Escape') {
                            e.preventDefault();
                            cancel();
                        } else if (
                            e.key === 'Enter' &&
                            !e.shiftKey &&
                            !e.nativeEvent.isComposing
                        ) {
                            e.preventDefault();
                            submit();
                        }
                    }}
                    rows={Math.min(6, Math.max(2, draft.split('\n').length))}
                    className="w-full resize-none bg-transparent px-1.5 py-1 text-sm leading-6 outline-none"
                />
                <div className="flex items-center gap-1.5">
                    <p className="mr-auto px-1.5 text-[11px] text-muted-foreground">
                        Sending this replaces the replies after it.
                    </p>
                    <button
                        type="button"
                        onClick={cancel}
                        className="rounded-md px-2.5 py-1 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        disabled={draft.trim() === '' || draft === message.text}
                        onClick={submit}
                        className="rounded-md bg-assistant-ink px-2.5 py-1 text-xs font-medium text-white transition-opacity hover:opacity-90 disabled:opacity-40"
                    >
                        Send
                    </button>
                </div>
            </div>
        );
    }

    const time = formatTime(message.createdAt);

    return (
        <div className="group/user relative flex flex-col items-end gap-1 pl-8">
            {message.attachments && message.attachments.length > 0 && (
                <ul
                    aria-label="Attached files"
                    className="flex max-w-full flex-wrap justify-end gap-1"
                >
                    {message.attachments.map((name, index) => (
                        <li
                            key={index}
                            className="flex max-w-[220px] items-center gap-1.5 rounded-md border border-border bg-background px-2 py-1 text-xs text-foreground/80"
                        >
                            <FileText className="size-3.5 shrink-0 text-muted-foreground" />
                            <span className="truncate">{name}</span>
                        </li>
                    ))}
                </ul>
            )}
            <div className="max-w-full rounded-2xl rounded-tr-md bg-assistant-ink px-3.5 py-2 text-sm leading-6 text-white">
                <p className="break-words whitespace-pre-wrap">
                    {message.text}
                </p>
            </div>
            {/* Laid in the gap below the message, so it adds no height. */}
            <div className="absolute top-full right-0 flex h-6 items-center gap-1 opacity-0 transition-opacity group-focus-within/user:opacity-100 group-hover/user:opacity-100">
                {time && (
                    <span className="px-1 text-[11px] text-muted-foreground tabular-nums">
                        {time}
                    </span>
                )}
                {canEdit && !disabled && (
                    <IconButton
                        label="Edit message"
                        onClick={() => {
                            setDraft(message.text);
                            setEditing(true);
                        }}
                    >
                        <Pencil />
                    </IconButton>
                )}
            </div>
        </div>
    );
}

// ── Assistant ────────────────────────────────────────────────────────────────

function AssistantMessage({
    message,
    isLast,
    streaming,
    sending,
    onStreamDone,
    onRegenerate,
    onRetry,
    onAnswer,
}: {
    message: ChatMessage;
    isLast: boolean;
    streaming: boolean;
    sending: boolean;
    onStreamDone: (id: number | string) => void;
    onRegenerate: () => void;
    onRetry: () => void;
    onAnswer: AnswerAction;
}) {
    const actions = message.actions ?? [];
    const held = actions.filter(
        (card) => card.kind === 'confirm' && card.confirmation,
    );
    const receipts = actions.filter(
        (card) => !(card.kind === 'confirm' && card.confirmation),
    );

    return (
        <article
            aria-label="Assistant reply"
            className="group/reply flex flex-col gap-3"
        >
            <WorkTrace steps={message.steps ?? []} />

            {message.failed ? (
                <FailedNotice
                    text={message.text}
                    disabled={sending}
                    onRetry={onRetry}
                />
            ) : (
                message.text && (
                    <StreamingText
                        id={message.id}
                        text={message.text}
                        streaming={streaming}
                        onDone={onStreamDone}
                    />
                )
            )}

            {!streaming && <Receipts cards={receipts} />}

            {!streaming &&
                held.map((card, index) => (
                    <ConfirmCard key={index} card={card} onAnswer={onAnswer} />
                ))}

            {!message.failed && !streaming && (
                <ReplyActions
                    text={message.text}
                    alwaysShown={isLast}
                    canRegenerate={isLast && !sending}
                    onRegenerate={onRegenerate}
                    time={formatTime(message.createdAt)}
                    usage={message.usage ?? null}
                />
            )}
        </article>
    );
}

/**
 * The reply, written out over about a second. The whole reply has already
 * arrived, so the reveal is short — it only shows where the new text is — and
 * it is skipped for anyone who asks for reduced motion. Stop shows it all.
 */
function StreamingText({
    id,
    text,
    streaming,
    onDone,
}: {
    id: number | string;
    text: string;
    streaming: boolean;
    onDone: (id: number | string) => void;
}) {
    const [shown, setShown] = useState(() =>
        streaming && !prefersReducedMotion() ? 0 : text.length,
    );

    useEffect(() => {
        // Only animate while streaming; when stopped, `visible` shows the full
        // text without touching state.
        if (!streaming) {
            return;
        }

        if (shown >= text.length) {
            onDone(id);

            return;
        }

        // ~70 frames whatever the length, so a long reply is not a long wait.
        const step = Math.max(4, Math.ceil(text.length / 70));
        const timer = setTimeout(
            () => setShown((s) => Math.min(text.length, s + step)),
            16,
        );

        return () => clearTimeout(timer);
    }, [streaming, shown, text, id, onDone]);

    const visible = streaming ? text.slice(0, shown) : text;

    return <Markdown text={visible} />;
}

/** "2 model calls · 9.8k tokens" — what a turn cost, where it was spent. */
function describeUsage(usage: TurnUsage): string {
    const tokens =
        usage.prompt_tokens + usage.output_tokens + usage.thinking_tokens;
    const amount =
        tokens >= 1000 ? `${(tokens / 1000).toFixed(1)}k` : String(tokens);

    return `${usage.requests} model ${usage.requests === 1 ? 'call' : 'calls'} · ${amount} tokens`;
}

function ReplyActions({
    text,
    alwaysShown,
    canRegenerate,
    onRegenerate,
    time,
    usage,
}: {
    text: string;
    alwaysShown: boolean;
    canRegenerate: boolean;
    onRegenerate: () => void;
    time: string;
    usage: TurnUsage | null;
}) {
    const [copied, copy] = useClipboard();
    const isCopied = copied === text && text !== '';

    return (
        <div
            className={cn(
                '-mt-1 -ml-1.5 flex h-6 items-center gap-0.5 transition-opacity',
                !alwaysShown &&
                    'opacity-0 group-focus-within/reply:opacity-100 group-hover/reply:opacity-100',
            )}
        >
            {text !== '' && (
                <IconButton
                    label={isCopied ? 'Copied' : 'Copy reply'}
                    onClick={() => void copy(text)}
                >
                    {isCopied ? (
                        <Check className="text-emerald-600 dark:text-emerald-400" />
                    ) : (
                        <Copy />
                    )}
                </IconButton>
            )}
            {canRegenerate && (
                <IconButton
                    label="Write the reply again"
                    onClick={onRegenerate}
                >
                    <RotateCcw />
                </IconButton>
            )}
            {time && (
                <span className="ml-1 text-[11px] text-muted-foreground tabular-nums">
                    {time}
                </span>
            )}
            {usage && usage.requests > 0 && (
                <span
                    title={`Prompt ${usage.prompt_tokens.toLocaleString()} (cached ${usage.cached_tokens.toLocaleString()}) · reply ${usage.output_tokens.toLocaleString()} · thinking ${usage.thinking_tokens.toLocaleString()}`}
                    className="ml-1 text-[11px] text-muted-foreground/80 tabular-nums"
                >
                    · {describeUsage(usage)}
                </span>
            )}
        </div>
    );
}

/**
 * A turn that did not come back — the model was busy, or the allowance for the
 * minute or the day is used up. The message says which; Retry asks again.
 */
function FailedNotice({
    text,
    disabled,
    onRetry,
}: {
    text: string;
    disabled: boolean;
    onRetry: () => void;
}) {
    return (
        <div
            role="alert"
            className="flex items-start gap-2.5 rounded-lg border border-amber-500/40 bg-amber-50/70 px-3 py-2.5 text-sm dark:bg-amber-500/[0.06]"
        >
            <AlertTriangle className="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400" />
            <p className="min-w-0 flex-1 leading-6">{text}</p>
            <button
                type="button"
                disabled={disabled}
                onClick={onRetry}
                className="flex shrink-0 items-center gap-1.5 rounded-md border border-input bg-background px-2.5 py-1 text-xs font-medium transition-colors hover:bg-muted disabled:opacity-50"
            >
                <RotateCcw className="size-3" />
                Retry
            </button>
        </div>
    );
}

function IconButton({
    label,
    onClick,
    children,
}: {
    label: string;
    onClick: () => void;
    children: React.ReactNode;
}) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    onClick={onClick}
                    aria-label={label}
                    className="flex size-6 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground [&_svg]:size-3.5"
                >
                    {children}
                </button>
            </TooltipTrigger>
            <TooltipContent side="bottom" className="text-xs">
                {label}
            </TooltipContent>
        </Tooltip>
    );
}

/**
 * A turn still in flight. The server does the whole turn in one request, so
 * there are no live steps to show — only that it is working, and for how long.
 */
function Working() {
    const [seconds, setSeconds] = useState(0);

    useEffect(() => {
        const timer = setInterval(() => setSeconds((s) => s + 1), 1000);

        return () => clearInterval(timer);
    }, []);

    return (
        <div
            role="status"
            className="flex items-center gap-2.5 text-[13px] text-muted-foreground"
        >
            <span className="flex w-2.5 justify-center">
                <TraceNode kind="working" />
            </span>
            <span>
                Working
                {seconds >= 3 && (
                    <span aria-hidden="true" className="tabular-nums">
                        , {seconds}s
                    </span>
                )}
            </span>
        </div>
    );
}
