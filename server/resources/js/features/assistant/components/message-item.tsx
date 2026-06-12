import {
    AlertTriangle,
    Check,
    Copy,
    Paperclip,
    Pencil,
    RotateCcw,
    Sparkles,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { useClipboard } from '@/hooks/use-clipboard';
import type { ChatMessage } from '../types';
import { AgentActivity } from './agent-activity';
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

export function MessageItem({
    message,
    isLast,
    streaming,
    sending,
    onStreamDone,
    onRegenerate,
    onEdit,
    onRetry,
}: {
    message: ChatMessage;
    isLast: boolean;
    streaming: boolean;
    sending: boolean;
    onStreamDone: (id: number | string) => void;
    onRegenerate: () => void;
    onEdit: (id: number | string, text: string) => void;
    onRetry: () => void;
}) {
    if (message.role === 'user') {
        return (
            <UserMessage message={message} disabled={sending} onEdit={onEdit} />
        );
    }

    if (message.pending) {
        return <Thinking />;
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

    if (editing) {
        return (
            <div className="flex flex-col items-end gap-1.5">
                <textarea
                    value={draft}
                    autoFocus
                    onChange={(e) => setDraft(e.target.value)}
                    rows={Math.min(6, draft.split('\n').length)}
                    className="w-[85%] resize-none rounded-2xl border border-input bg-background px-3.5 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                />
                <div className="flex gap-1.5">
                    <button
                        type="button"
                        onClick={() => {
                            setEditing(false);
                            setDraft(message.text);
                        }}
                        className="rounded-md px-2.5 py-1 text-xs text-muted-foreground hover:bg-muted"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        disabled={draft.trim() === '' || draft === message.text}
                        onClick={() => {
                            setEditing(false);
                            onEdit(message.id, draft.trim());
                        }}
                        className="rounded-md bg-[#0F2044] px-2.5 py-1 text-xs font-medium text-white hover:bg-[#0F2044]/90 disabled:opacity-50"
                    >
                        Send
                    </button>
                </div>
            </div>
        );
    }

    return (
        <div className="group flex flex-col items-end gap-1">
            <div className="max-w-[85%] animate-in rounded-2xl rounded-br-sm bg-[#0F2044] px-3.5 py-2 text-sm text-white duration-200 fade-in slide-in-from-bottom-1">
                {message.attachments && message.attachments.length > 0 && (
                    <span className="mb-1 flex flex-col gap-0.5 text-[11px] text-white/70">
                        {message.attachments.map((name, index) => (
                            <span
                                key={index}
                                className="flex items-center gap-1.5"
                            >
                                <Paperclip className="size-3 shrink-0" />
                                <span className="truncate">{name}</span>
                            </span>
                        ))}
                    </span>
                )}
                <p className="break-words whitespace-pre-wrap">
                    {message.text}
                </p>
            </div>
            <div className="flex items-center gap-2 pr-1 opacity-0 transition-opacity group-hover:opacity-100">
                <span className="text-[10px] text-muted-foreground">
                    {formatTime(message.createdAt)}
                </span>
                {canEdit && !disabled && (
                    <button
                        type="button"
                        onClick={() => {
                            setDraft(message.text);
                            setEditing(true);
                        }}
                        className="flex items-center gap-1 text-[10px] text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <Pencil className="size-3" />
                        Edit
                    </button>
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
}: {
    message: ChatMessage;
    isLast: boolean;
    streaming: boolean;
    sending: boolean;
    onStreamDone: (id: number | string) => void;
    onRegenerate: () => void;
    onRetry: () => void;
}) {
    const hasActivity =
        (message.steps?.length ?? 0) + (message.actions?.length ?? 0) > 0;
    const [activityDone, setActivityDone] = useState(!hasActivity);

    return (
        <div className="group flex gap-2.5">
            <span className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg bg-[#0F2044] text-[#0ABFBF] ring-1 ring-border">
                <Sparkles className="size-3.5" />
            </span>

            <div className="flex min-w-0 flex-1 flex-col gap-2">
                {hasActivity && (
                    <AgentActivity
                        steps={message.steps ?? []}
                        actions={message.actions ?? []}
                        onRevealed={() => setActivityDone(true)}
                    />
                )}

                {message.failed ? (
                    <FailedNotice
                        text={message.text}
                        disabled={sending}
                        onRetry={onRetry}
                    />
                ) : (
                    activityDone &&
                    message.text && (
                        <div className="max-w-full animate-in rounded-2xl rounded-bl-sm bg-muted px-3.5 py-2 fade-in slide-in-from-bottom-1">
                            <StreamingText
                                id={message.id}
                                text={message.text}
                                streaming={streaming}
                                onDone={onStreamDone}
                            />
                        </div>
                    )
                )}

                {!message.failed && activityDone && !streaming && (
                    <AssistantActions
                        text={message.text}
                        canRegenerate={isLast && !sending}
                        onRegenerate={onRegenerate}
                        time={formatTime(message.createdAt)}
                    />
                )}
            </div>
        </div>
    );
}

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
    const [shown, setShown] = useState(streaming ? 0 : text.length);

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

        const timer = setTimeout(
            () => setShown((s) => Math.min(text.length, s + 3)),
            16,
        );

        return () => clearTimeout(timer);
    }, [streaming, shown, text, id, onDone]);

    const visible = streaming ? text.slice(0, shown) : text;

    return (
        <>
            <Markdown text={visible} />
            {streaming && shown < text.length && (
                <span className="ml-0.5 inline-block h-3.5 w-1.5 animate-pulse rounded-sm bg-[#0ABFBF] align-middle" />
            )}
        </>
    );
}

function AssistantActions({
    text,
    canRegenerate,
    onRegenerate,
    time,
}: {
    text: string;
    canRegenerate: boolean;
    onRegenerate: () => void;
    time: string;
}) {
    const [copied, copy] = useClipboard();
    const isCopied = copied === text && text !== '';

    return (
        <div className="flex items-center gap-1 pl-1 opacity-0 transition-opacity group-hover:opacity-100">
            <IconButton
                label={isCopied ? 'Copied' : 'Copy'}
                onClick={() => void copy(text)}
            >
                {isCopied ? (
                    <Check className="size-3 text-emerald-500" />
                ) : (
                    <Copy className="size-3" />
                )}
            </IconButton>
            {canRegenerate && (
                <IconButton label="Regenerate" onClick={onRegenerate}>
                    <RotateCcw className="size-3" />
                </IconButton>
            )}
            {time && (
                <span className="ml-1 text-[10px] text-muted-foreground">
                    {time}
                </span>
            )}
        </div>
    );
}

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
        <div className="flex flex-col gap-2 rounded-2xl rounded-bl-sm border border-amber-500/30 bg-amber-500/10 px-3.5 py-2.5 text-sm">
            <span className="flex items-start gap-2 text-foreground">
                <AlertTriangle className="mt-0.5 size-3.5 shrink-0 text-amber-500" />
                <span>{text}</span>
            </span>
            <button
                type="button"
                disabled={disabled}
                onClick={onRetry}
                className="flex w-fit items-center gap-1.5 rounded-md bg-[#0F2044] px-2.5 py-1 text-xs font-medium text-white hover:bg-[#0F2044]/90 disabled:opacity-50"
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
        <button
            type="button"
            onClick={onClick}
            title={label}
            aria-label={label}
            className="flex size-6 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
        >
            {children}
        </button>
    );
}

function Thinking() {
    const labels = [
        'Thinking…',
        'Reading your request…',
        'Working on it…',
        'Almost there…',
    ];
    const [index, setIndex] = useState(0);

    useEffect(() => {
        const timer = setInterval(
            () => setIndex((value) => (value + 1) % labels.length),
            1400,
        );

        return () => clearInterval(timer);
    }, [labels.length]);

    return (
        <div className="flex gap-2.5">
            <span className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg bg-[#0F2044] text-[#0ABFBF] ring-1 ring-border">
                <Sparkles className="size-3.5 animate-pulse" />
            </span>
            <div className="flex items-center gap-2 self-start rounded-2xl rounded-bl-sm bg-muted px-3.5 py-2.5">
                <span className="flex gap-1">
                    {[0, 1, 2].map((dot) => (
                        <span
                            key={dot}
                            className="size-1.5 animate-bounce rounded-full bg-[#0ABFBF]"
                            style={{ animationDelay: `${dot * 0.15}s` }}
                        />
                    ))}
                </span>
                <span className="text-xs text-muted-foreground">
                    {labels[index]}
                </span>
            </div>
        </div>
    );
}

export { Thinking };
