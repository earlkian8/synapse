import { router } from '@inertiajs/react';
import { Paperclip, Send, Sparkles, UserCog, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/hooks/use-permissions';
import { sendToAssistant } from '../api';
import type { AgentAction, ChatMessage } from '../types';
import { AgentActivity } from './agent-activity';

const SUGGESTIONS = [
    'Add a new employee',
    'Onboard the attached CV',
    'Find Maria Santos',
];

let messageSeq = 0;
const nextId = () => `m${Date.now()}-${messageSeq++}`;

/**
 * Floating, persistent agentic assistant for employee management. Mounted once
 * in the authenticated layout so its conversation survives page navigations.
 */
export function EmployeeAssistant() {
    const { can } = usePermissions();
    const [open, setOpen] = useState(false);
    const [messages, setMessages] = useState<ChatMessage[]>([]);
    const [input, setInput] = useState('');
    const [file, setFile] = useState<File | null>(null);
    const [sending, setSending] = useState(false);

    const fileInput = useRef<HTMLInputElement>(null);
    const scrollRef = useRef<HTMLDivElement>(null);
    const contentRef = useRef<HTMLDivElement>(null);

    // Keep the view pinned to the latest content, including as the agent
    // timeline reveals itself after a response lands.
    useEffect(() => {
        const scroller = scrollRef.current;
        const content = contentRef.current;

        if (!scroller || !content) {
            return;
        }

        const pin = () => {
            const distance =
                scroller.scrollHeight -
                scroller.scrollTop -
                scroller.clientHeight;

            if (distance < 160) {
                scroller.scrollTop = scroller.scrollHeight;
            }
        };

        const observer = new ResizeObserver(pin);
        observer.observe(content);

        return () => observer.disconnect();
    }, [open]);

    const scrollToBottom = () => {
        requestAnimationFrame(() => {
            const scroller = scrollRef.current;

            if (scroller) {
                scroller.scrollTop = scroller.scrollHeight;
            }
        });
    };

    if (!can('employees.view')) {
        return null;
    }

    const applyEffects = (actions: AgentAction[]) => {
        if (actions.length === 0) {
            return;
        }

        for (const action of actions) {
            const name = action.employee.full_name;
            const verb =
                action.type === 'created'
                    ? 'added to the directory'
                    : action.type === 'updated'
                      ? 'updated'
                      : 'archived';

            if (action.type === 'archived') {
                toast(`${name} ${verb}.`);
            } else {
                toast.success(`${name} ${verb}.`);
            }
        }

        // Reflect changes live if the user is on the employee directory.
        if (window.location.pathname.startsWith('/employees')) {
            router.reload({
                only: ['employees', 'stats'],
                onSuccess: () => {
                    const highlight = actions.find(
                        (a) => a.type !== 'archived',
                    );

                    if (highlight) {
                        window.dispatchEvent(
                            new CustomEvent('nexo:employee-mutated', {
                                detail: { id: highlight.employee.id },
                            }),
                        );
                    }
                },
            });
        }
    };

    const send = async () => {
        const text = input.trim();

        if ((text === '' && !file) || sending) {
            return;
        }

        const attached = file;
        const userMessage: ChatMessage = {
            id: nextId(),
            role: 'user',
            text: text || 'Please review the attached file.',
            fileName: attached?.name ?? null,
        };
        const pendingId = nextId();

        const history = messages
            .filter((m) => !m.pending)
            .map((m) => ({ role: m.role, text: m.text }));

        setMessages((current) => [
            ...current,
            userMessage,
            { id: pendingId, role: 'assistant', text: '', pending: true },
        ]);
        setInput('');
        setFile(null);
        setSending(true);
        scrollToBottom();

        try {
            const result = await sendToAssistant({
                message: text,
                history,
                file: attached,
            });

            setMessages((current) =>
                current.map((m) =>
                    m.id === pendingId
                        ? {
                              id: pendingId,
                              role: 'assistant',
                              text: result.reply,
                              steps: result.steps,
                              actions: result.actions,
                          }
                        : m,
                ),
            );

            applyEffects(result.actions ?? []);
        } catch (error) {
            const message =
                error instanceof Error
                    ? error.message
                    : 'Something went wrong. Please try again.';

            setMessages((current) =>
                current.map((m) =>
                    m.id === pendingId
                        ? { id: pendingId, role: 'assistant', text: message }
                        : m,
                ),
            );
        } finally {
            setSending(false);
            scrollToBottom();
        }
    };

    const onKeyDown = (event: React.KeyboardEvent<HTMLTextAreaElement>) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            void send();
        }
    };

    return (
        <>
            {/* Launcher */}
            {!open && (
                <button
                    type="button"
                    onClick={() => setOpen(true)}
                    aria-label="Open assistant"
                    className="group fixed right-5 bottom-5 z-50 flex size-14 items-center justify-center rounded-full bg-[#0F2044] text-white shadow-lg ring-1 shadow-black/20 ring-white/10 transition-transform hover:scale-105 active:scale-95"
                >
                    <span className="absolute inset-0 animate-ping rounded-full bg-[#0ABFBF]/30 [animation-duration:2.5s]" />
                    <Sparkles className="relative size-6 text-[#0ABFBF] transition-transform group-hover:rotate-12" />
                </button>
            )}

            {/* Panel */}
            {open && (
                <div className="fixed right-5 bottom-5 z-50 flex h-[min(640px,calc(100vh-2.5rem))] w-[min(400px,calc(100vw-2.5rem))] animate-in flex-col overflow-hidden rounded-2xl border border-border bg-card shadow-2xl shadow-black/25 duration-300 fade-in slide-in-from-bottom-4">
                    <Header onClose={() => setOpen(false)} />

                    <div
                        ref={scrollRef}
                        className="flex-1 overflow-y-auto px-3.5 py-4"
                    >
                        <div ref={contentRef} className="flex flex-col gap-3">
                            {messages.length === 0 ? (
                                <EmptyState
                                    onPick={(prompt) => setInput(prompt)}
                                />
                            ) : (
                                messages.map((message) => (
                                    <MessageBubble
                                        key={message.id}
                                        message={message}
                                    />
                                ))
                            )}
                        </div>
                    </div>

                    <Composer
                        input={input}
                        file={file}
                        sending={sending}
                        fileInput={fileInput}
                        onInput={setInput}
                        onKeyDown={onKeyDown}
                        onSend={() => void send()}
                        onPickFile={() => fileInput.current?.click()}
                        onFile={(f) => setFile(f)}
                        onClearFile={() => {
                            setFile(null);

                            if (fileInput.current) {
                                fileInput.current.value = '';
                            }
                        }}
                    />
                </div>
            )}
        </>
    );
}

function Header({ onClose }: { onClose: () => void }) {
    return (
        <div className="flex items-center gap-2.5 border-b border-border bg-gradient-to-r from-[#0F2044] to-[#16305f] px-4 py-3 text-white">
            <span className="flex size-8 items-center justify-center rounded-lg bg-white/10 ring-1 ring-white/15">
                <Sparkles className="size-4 text-[#0ABFBF]" />
            </span>
            <div className="flex-1">
                <p className="text-sm leading-tight font-semibold">
                    Nexo Assistant
                </p>
                <p className="flex items-center gap-1 text-[11px] text-white/60">
                    <UserCog className="size-3" />
                    Employee management
                </p>
            </div>
            <button
                type="button"
                onClick={onClose}
                aria-label="Close assistant"
                className="flex size-7 items-center justify-center rounded-md text-white/70 transition-colors hover:bg-white/10 hover:text-white"
            >
                <X className="size-4" />
            </button>
        </div>
    );
}

function EmptyState({ onPick }: { onPick: (prompt: string) => void }) {
    return (
        <div className="flex flex-col items-center gap-3 px-4 py-8 text-center">
            <span className="flex size-12 items-center justify-center rounded-2xl bg-[#0F2044] text-[#0ABFBF] ring-1 ring-border">
                <Sparkles className="size-6" />
            </span>
            <div>
                <p className="text-sm font-semibold">How can I help?</p>
                <p className="mx-auto mt-1 max-w-[260px] text-xs text-muted-foreground">
                    I can add, update, find or archive employees for you.
                    Describe what you need, or drop in a CV and I'll take it
                    from there.
                </p>
            </div>
            <div className="mt-1 flex flex-wrap justify-center gap-1.5">
                {SUGGESTIONS.map((prompt) => (
                    <button
                        key={prompt}
                        type="button"
                        onClick={() => onPick(prompt)}
                        className="rounded-full border border-border bg-muted/50 px-2.5 py-1 text-xs text-muted-foreground transition-colors hover:border-[#0ABFBF]/40 hover:text-foreground"
                    >
                        {prompt}
                    </button>
                ))}
            </div>
        </div>
    );
}

function MessageBubble({ message }: { message: ChatMessage }) {
    if (message.role === 'user') {
        return (
            <div className="flex justify-end">
                <div className="max-w-[85%] animate-in rounded-2xl rounded-br-sm bg-[#0F2044] px-3.5 py-2 text-sm text-white duration-200 fade-in slide-in-from-bottom-1">
                    {message.fileName && (
                        <span className="mb-1 flex items-center gap-1.5 text-[11px] text-white/70">
                            <Paperclip className="size-3" />
                            {message.fileName}
                        </span>
                    )}
                    <p className="break-words whitespace-pre-wrap">
                        {message.text}
                    </p>
                </div>
            </div>
        );
    }

    if (message.pending) {
        return <Thinking />;
    }

    return <AssistantMessage message={message} />;
}

function AssistantMessage({ message }: { message: ChatMessage }) {
    const hasActivity =
        (message.steps?.length ?? 0) + (message.actions?.length ?? 0) > 0;
    const [showText, setShowText] = useState(!hasActivity);

    return (
        <div className="flex flex-col gap-2">
            {hasActivity && (
                <AgentActivity
                    steps={message.steps ?? []}
                    actions={message.actions ?? []}
                    onRevealed={() => setShowText(true)}
                />
            )}

            {showText && message.text && (
                <div className="max-w-[88%] animate-in self-start rounded-2xl rounded-bl-sm bg-muted px-3.5 py-2 text-sm fade-in slide-in-from-bottom-1">
                    <p className="break-words whitespace-pre-wrap">
                        {message.text}
                    </p>
                </div>
            )}
        </div>
    );
}

function Thinking() {
    const labels = [
        'Thinking…',
        'Reading your request…',
        'Checking the directory…',
        'Working on it…',
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
    );
}

function Composer({
    input,
    file,
    sending,
    fileInput,
    onInput,
    onKeyDown,
    onSend,
    onPickFile,
    onFile,
    onClearFile,
}: {
    input: string;
    file: File | null;
    sending: boolean;
    fileInput: React.RefObject<HTMLInputElement | null>;
    onInput: (value: string) => void;
    onKeyDown: (event: React.KeyboardEvent<HTMLTextAreaElement>) => void;
    onSend: () => void;
    onPickFile: () => void;
    onFile: (file: File | null) => void;
    onClearFile: () => void;
}) {
    return (
        <div className="border-t border-border bg-card px-3 py-2.5">
            {file && (
                <div className="mb-2 flex items-center gap-1.5 rounded-md border border-border bg-muted/50 px-2 py-1 text-xs">
                    <Paperclip className="size-3 shrink-0 text-muted-foreground" />
                    <span className="min-w-0 flex-1 truncate">{file.name}</span>
                    <button
                        type="button"
                        onClick={onClearFile}
                        aria-label="Remove file"
                        className="text-muted-foreground hover:text-destructive"
                    >
                        <X className="size-3.5" />
                    </button>
                </div>
            )}

            <div className="flex items-end gap-1.5">
                <button
                    type="button"
                    onClick={onPickFile}
                    aria-label="Attach a file"
                    className="flex size-9 shrink-0 items-center justify-center rounded-lg border border-border text-muted-foreground transition-colors hover:border-[#0ABFBF]/40 hover:text-foreground"
                >
                    <Paperclip className="size-4" />
                </button>
                <input
                    ref={fileInput}
                    type="file"
                    accept=".pdf,.png,.jpg,.jpeg,.webp,.txt,application/pdf,image/png,image/jpeg,image/webp,text/plain"
                    className="hidden"
                    onChange={(event) =>
                        onFile(event.target.files?.[0] ?? null)
                    }
                />

                <textarea
                    value={input}
                    onChange={(event) => onInput(event.target.value)}
                    onKeyDown={onKeyDown}
                    rows={1}
                    placeholder="Message the assistant…"
                    className="max-h-28 min-h-9 flex-1 resize-none rounded-lg border border-input bg-transparent px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                />

                <Button
                    type="button"
                    size="icon"
                    onClick={onSend}
                    disabled={sending || (input.trim() === '' && !file)}
                    className="size-9 shrink-0 bg-[#0F2044] hover:bg-[#0F2044]/90"
                    aria-label="Send"
                >
                    <Send className="size-4" />
                </Button>
            </div>
        </div>
    );
}
