import { router, usePage } from '@inertiajs/react';
import { ChevronDown, Maximize2, Minimize2, SquarePen, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { findTourTarget } from '@/features/product-tour/targets';
import { cn } from '@/lib/utils';
import { assistantLauncher, useAssistantOpen } from '../launcher';
import type { AgentCard } from '../types';
import { useAssistant } from '../use-assistant';
import { ASSISTANT_PANEL_ID } from './assistant-button';
import { Composer, MAX_FILES } from './composer';
import { ConversationList } from './conversation-list';
import { MessageList } from './message-list';

const draftKey = (id: number | null) =>
    `synapse.assistant.draft.${id ?? 'new'}`;

const WIDE_KEY = 'synapse.assistant.wide';

/** The panel's two widths; the wide one is for long replies and tables. */
const WIDTH = { narrow: '420px', wide: '640px' } as const;

/** Focus the composer with the cursor after anything already typed. */
function focusComposer(textarea: HTMLTextAreaElement | null): void {
    if (!textarea) {
        return;
    }

    textarea.focus();
    textarea.setSelectionRange(textarea.value.length, textarea.value.length);
}

function readWide(): boolean {
    try {
        return localStorage.getItem(WIDE_KEY) === '1';
    } catch {
        return false;
    }
}

/**
 * The persistent Synapse assistant: multi-conversation history, markdown replies,
 * copy/edit/regenerate, attachments, and live HR actions across the modules the
 * user can access. Mounted once in the authenticated layout so it survives
 * navigation; opened from its button in the top bar (`assistant-button.tsx`).
 *
 * It is a panel at the right edge, never something floating over the page. On a
 * wide screen (xl) it docks beside the page as a second inset card and the page
 * narrows to make room, so nothing — the footer's links included — is covered.
 * Narrower, it slides over the page's right side; on a phone it takes the screen.
 */
export function Assistant() {
    // Who is offered the assistant is decided on the server (AssistantAccess):
    // anyone with a module it can read for them. Self-service alone does not
    // open it — every turn spends model quota.
    const offered = usePage().props.auth.assistant;
    const assistant = useAssistant();
    const open = useAssistantOpen();

    const [wide, setWide] = useState(readWide);
    const [showHistory, setShowHistory] = useState(false);
    const [input, setInput] = useState('');
    const [files, setFiles] = useState<File[]>([]);
    const [dragging, setDragging] = useState(false);

    const processed = useRef<Set<number | string>>(new Set());
    const composerRef = useRef<HTMLTextAreaElement>(null);

    const { activeId, messages, conversations } = assistant;

    // Opened from elsewhere in the app (the Help Center's "Ask the assistant"),
    // perhaps with a question to finish. It is typed, never sent.
    useEffect(
        () =>
            assistantLauncher.subscribe(({ prompt }) => {
                setShowHistory(false);

                if (prompt !== '') {
                    setInput(prompt);
                }

                // Once the panel has rendered (it may have been closed), put
                // the cursor after the question, ready to finish it.
                requestAnimationFrame(() => focusComposer(composerRef.current));
            }),
        [],
    );

    // Opening puts the cursor in the composer, ready to type.
    useEffect(() => {
        if (open) {
            focusComposer(composerRef.current);
        }
    }, [open]);

    // Restore the saved draft when the active conversation changes. This is an
    // intentional external (localStorage) → state sync keyed on the thread.
    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        setInput(localStorage.getItem(draftKey(activeId)) ?? '');
    }, [activeId]);

    // Persist the draft as it is typed.
    useEffect(() => {
        const key = draftKey(activeId);

        if (input.trim() === '') {
            localStorage.removeItem(key);
        } else {
            localStorage.setItem(key, input);
        }
    }, [input, activeId]);

    // Apply side effects (live refresh, and a toast if the panel was closed
    // meanwhile) for newly executed actions — never for a conversation opened
    // from history, whose actions ran before.
    useEffect(() => {
        for (const message of messages) {
            if (
                message.role !== 'assistant' ||
                message.pending ||
                message.failed ||
                message.replayed ||
                !message.actions?.length ||
                processed.current.has(message.id)
            ) {
                continue;
            }

            processed.current.add(message.id);
            applyEffects(message.actions, !open);
        }
    }, [messages, open]);

    if (!offered || !open) {
        return null;
    }

    const activeTitle = activeId
        ? (conversations.find((c) => c.id === activeId)?.title ??
          'Conversation')
        : 'New conversation';

    const send = () => {
        if (input.trim() === '' && files.length === 0) {
            return;
        }

        localStorage.removeItem(draftKey(activeId));
        void assistant.sendMessage(input, files);
        setInput('');
        setFiles([]);
    };

    const addFiles = (picked: File[]) =>
        setFiles((current) => [...current, ...picked].slice(0, MAX_FILES));

    const openConversation = (id: number) => {
        void assistant.openConversation(id);
        setShowHistory(false);
    };

    const newChat = () => {
        assistant.newChat();
        setShowHistory(false);
        setInput('');
        setFiles([]);
        composerRef.current?.focus();
    };

    const close = () => {
        assistantLauncher.close();
        // Hand focus back to the button that opened the panel.
        findTourTarget('assistant')?.focus();
    };

    const toggleWide = () => {
        const next = !wide;
        setWide(next);

        try {
            localStorage.setItem(WIDE_KEY, next ? '1' : '0');
        } catch {
            // A remembered width is a nicety; the panel works without it.
        }
    };

    const pickSuggestion = (prompt: string) => {
        setInput(prompt);
        requestAnimationFrame(() => focusComposer(composerRef.current));
    };

    const onKeyDown = (event: React.KeyboardEvent) => {
        // Inner editors (renaming, editing a message) claim Escape for
        // themselves by preventing its default.
        if (event.key !== 'Escape' || event.defaultPrevented) {
            return;
        }

        event.preventDefault();

        if (showHistory) {
            setShowHistory(false);
        } else {
            close();
        }
    };

    const width = wide ? WIDTH.wide : WIDTH.narrow;

    return (
        <aside
            id={ASSISTANT_PANEL_ID}
            aria-label="Assistant"
            onKeyDown={onKeyDown}
            onDragEnter={(event) => {
                if (event.dataTransfer.types.includes('Files')) {
                    setDragging(true);
                }
            }}
            onDragOver={(event) => event.preventDefault()}
            onDragLeave={(event) => {
                if (
                    !event.currentTarget.contains(event.relatedTarget as Node)
                ) {
                    setDragging(false);
                }
            }}
            onDrop={(event) => {
                event.preventDefault();
                setDragging(false);
                addFiles(Array.from(event.dataTransfer.files ?? []));
            }}
            style={{ '--assistant-width': width } as React.CSSProperties}
            className={cn(
                'fixed inset-0 z-50 flex flex-col overflow-hidden bg-background',
                'animate-in duration-200 fade-in slide-in-from-right-6 motion-reduce:animate-none',
                // A sheet over the page's right side.
                'sm:left-auto sm:w-[min(var(--assistant-width),100vw)] sm:border-l sm:border-border sm:shadow-2xl sm:shadow-black/15',
                // Docked beside the page, as the inset sidebar layout's second card.
                'xl:sticky xl:inset-auto xl:top-2 xl:z-20 xl:my-2 xl:mr-2 xl:h-[calc(100svh-1rem)] xl:w-(--assistant-width) xl:shrink-0 xl:rounded-xl xl:border xl:shadow-sm',
            )}
        >
            <ToastClearance width={width} />

            <header className="flex h-14 shrink-0 items-center gap-1 border-b border-border px-2 transition-[height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:xl:h-12">
                <button
                    type="button"
                    onClick={() => setShowHistory((value) => !value)}
                    aria-expanded={showHistory}
                    className="flex min-w-0 items-center gap-1 rounded-md px-2 py-1.5 text-left transition-colors outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring/50"
                >
                    <span className="truncate text-sm font-semibold">
                        {activeTitle}
                    </span>
                    <ChevronDown
                        className={cn(
                            'size-4 shrink-0 text-muted-foreground transition-transform',
                            showHistory && 'rotate-180',
                        )}
                    />
                    <span className="sr-only">
                        {showHistory
                            ? ', hide conversations'
                            : ', show conversations'}
                    </span>
                </button>

                <div className="ml-auto flex shrink-0 items-center">
                    <HeaderButton label="New conversation" onClick={newChat}>
                        <SquarePen />
                    </HeaderButton>
                    <span className="hidden sm:contents">
                        <HeaderButton
                            label={wide ? 'Narrower panel' : 'Wider panel'}
                            onClick={toggleWide}
                        >
                            {wide ? <Minimize2 /> : <Maximize2 />}
                        </HeaderButton>
                    </span>
                    <HeaderButton label="Close (Esc)" onClick={close}>
                        <X />
                    </HeaderButton>
                </div>
            </header>

            <div className="relative flex min-h-0 flex-1 flex-col">
                <MessageList
                    messages={messages}
                    streamingId={assistant.streamingId}
                    sending={assistant.sending}
                    loading={assistant.loadingThread}
                    onStreamDone={assistant.endStreaming}
                    onRegenerate={() => void assistant.regenerate()}
                    onEdit={(id, text) => void assistant.editMessage(id, text)}
                    onRetry={() => void assistant.regenerate()}
                    onAnswer={assistant.answerAction}
                    onPickSuggestion={pickSuggestion}
                />

                {showHistory && (
                    <ConversationList
                        conversations={conversations}
                        activeId={activeId}
                        onOpen={openConversation}
                        onNew={newChat}
                        onRename={assistant.rename}
                        onTogglePin={assistant.togglePin}
                        onDelete={assistant.remove}
                        onClearAll={assistant.clearAll}
                    />
                )}

                {dragging && (
                    <div className="pointer-events-none absolute inset-3 z-20 flex flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed border-assistant-signal-text/50 bg-background/90 text-center">
                        <p className="text-sm font-medium">Drop to attach</p>
                        <p className="text-xs text-muted-foreground">
                            PDF, image or text, up to {MAX_FILES} files
                        </p>
                    </div>
                )}
            </div>

            <Composer
                ref={composerRef}
                input={input}
                files={files}
                busy={assistant.sending || assistant.streamingId !== null}
                mode={assistant.mode}
                onModeChange={assistant.setMode}
                onInput={setInput}
                onAddFiles={addFiles}
                onRemoveFile={(index) =>
                    setFiles((current) => current.filter((_, i) => i !== index))
                }
                onSend={send}
                onStop={assistant.stopStreaming}
            />
        </aside>
    );
}

function HeaderButton({
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
                    className="flex size-8 items-center justify-center rounded-md text-muted-foreground transition-colors outline-none hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/50 [&_svg]:size-4"
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
 * Keeps the app's toasts off the panel while it is open: beside it on a wide
 * screen, above the composer on a phone, where the panel fills the screen. The
 * toaster reads these variables (`components/ui/sonner.tsx`); the style goes
 * when the panel closes.
 */
function ToastClearance({ width }: { width: string }) {
    return (
        <style>{`
            @media (min-width: 640px) {
                :root {
                    --app-toast-offset-right: calc(min(${width}, 100vw) + 24px);
                }
            }
            @media (min-width: 1280px) {
                :root {
                    --app-toast-offset-right: calc(${width} + 32px);
                }
            }
            @media (max-width: 639px) {
                :root {
                    --app-toast-offset-bottom: 8rem;
                    --app-toast-offset-bottom-mobile: 8rem;
                }
            }
        `}</style>
    );
}

/**
 * Refresh the current page so what the assistant changed shows live. Each change
 * is already in the chat as a receipt, so it is toasted only when the panel was
 * closed before the reply came back.
 */
function applyEffects(cards: AgentCard[], announce: boolean) {
    // Only what actually changed: a lookup, a read-out or an action still
    // waiting for the user's OK changed nothing, so it neither toasts nor
    // reloads the page.
    const mutated = cards.filter(
        (card) => !['find', 'insight', 'confirm'].includes(card.kind),
    );

    if (mutated.length === 0) {
        return;
    }

    if (announce) {
        for (const card of mutated) {
            const message = `${card.badge}: ${card.title}`;

            if (card.tone === 'positive') {
                toast.success(message);
            } else {
                toast(message);
            }
        }
    }

    router.reload({
        onSuccess: () => {
            if (!window.location.pathname.startsWith('/employees')) {
                return;
            }

            const highlight = mutated.find(
                (card) =>
                    card.module === 'employees' &&
                    typeof card.id === 'number' &&
                    (card.kind === 'add' || card.kind === 'edit'),
            );

            if (highlight) {
                window.dispatchEvent(
                    new CustomEvent('synapse:employee-mutated', {
                        detail: { id: highlight.id },
                    }),
                );
            }
        },
    });
}
