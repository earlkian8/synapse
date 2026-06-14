import { router } from '@inertiajs/react';
import {
    History,
    Maximize2,
    Minimize2,
    PenSquare,
    Sparkles,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { usePermissions } from '@/hooks/use-permissions';
import { cn } from '@/lib/utils';
import type { AgentCard } from '../types';
import { useAssistant } from '../use-assistant';
import { Composer } from './composer';
import { ConversationList } from './conversation-list';
import { MessageList } from './message-list';

/** Permissions that make at least part of the assistant useful. */
const ASSISTANT_PERMISSIONS = [
    'employees.view',
    'leave.view',
    'onboarding.view',
    'recruitment.view',
] as const;

const draftKey = (id: number | null) =>
    `synapse.assistant.draft.${id ?? 'new'}`;

/**
 * The floating, persistent Synapse assistant. Mounted once in the authenticated
 * layout so it survives navigation. A premium chat surface: multi-conversation
 * history, markdown replies with streaming, copy/edit/regenerate, drag-and-drop
 * attachments, and live HR actions across the modules the user can access.
 */
export function Assistant() {
    const { can } = usePermissions();
    const assistant = useAssistant();

    const [open, setOpen] = useState(false);
    const [expanded, setExpanded] = useState(false);
    const [showHistory, setShowHistory] = useState(false);
    const [input, setInput] = useState('');
    const [files, setFiles] = useState<File[]>([]);

    const processed = useRef<Set<number | string>>(new Set());

    const { activeId, messages, conversations } = assistant;

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

    // Apply side effects (toasts + live refresh) for newly executed actions.
    useEffect(() => {
        for (const message of messages) {
            if (
                message.role !== 'assistant' ||
                message.pending ||
                message.failed ||
                !message.actions?.length ||
                processed.current.has(message.id)
            ) {
                continue;
            }

            processed.current.add(message.id);
            applyEffects(message.actions);
        }
    }, [messages]);

    if (!ASSISTANT_PERMISSIONS.some((permission) => can(permission))) {
        return null;
    }

    const activeTitle =
        conversations.find((c) => c.id === activeId)?.title ??
        'New conversation';

    const send = () => {
        if (input.trim() === '' && files.length === 0) {
            return;
        }

        localStorage.removeItem(draftKey(activeId));
        void assistant.sendMessage(input, files);
        setInput('');
        setFiles([]);
    };

    const openConversation = (id: number) => {
        void assistant.openConversation(id);
        setShowHistory(false);
    };

    const newChat = () => {
        assistant.newChat();
        setShowHistory(false);
        setInput('');
        setFiles([]);
    };

    return (
        <>
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

            {open && (
                <div
                    className={cn(
                        'fixed right-5 bottom-5 z-50 flex animate-in flex-col overflow-hidden rounded-2xl border border-border bg-card shadow-2xl shadow-black/25 duration-300 fade-in slide-in-from-bottom-4',
                        expanded
                            ? 'h-[min(760px,calc(100vh-2.5rem))] w-[min(560px,calc(100vw-2.5rem))]'
                            : 'h-[min(640px,calc(100vh-2.5rem))] w-[min(400px,calc(100vw-2.5rem))]',
                    )}
                >
                    <Header
                        title={activeId ? activeTitle : 'Synapse Assistant'}
                        subtitle={activeId ? 'HR copilot' : 'Your HR copilot'}
                        expanded={expanded}
                        onHistory={() => setShowHistory(true)}
                        onNew={newChat}
                        onToggleExpand={() => setExpanded((v) => !v)}
                        onClose={() => setOpen(false)}
                    />

                    <div className="relative flex min-h-0 flex-1 flex-col">
                        <MessageList
                            messages={messages}
                            streamingId={assistant.streamingId}
                            sending={assistant.sending}
                            loading={assistant.loadingThread}
                            onStreamDone={assistant.endStreaming}
                            onRegenerate={() => void assistant.regenerate()}
                            onEdit={(id, text) =>
                                void assistant.editMessage(id, text)
                            }
                            onRetry={() => void assistant.regenerate()}
                            onPickSuggestion={setInput}
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
                                onClose={() => setShowHistory(false)}
                            />
                        )}
                    </div>

                    <Composer
                        input={input}
                        files={files}
                        busy={
                            assistant.sending || assistant.streamingId !== null
                        }
                        onInput={setInput}
                        onAddFiles={(picked) =>
                            setFiles((current) =>
                                [...current, ...picked].slice(0, 8),
                            )
                        }
                        onRemoveFile={(index) =>
                            setFiles((current) =>
                                current.filter((_, i) => i !== index),
                            )
                        }
                        onSend={send}
                        onStop={assistant.stopStreaming}
                    />
                </div>
            )}
        </>
    );
}

function Header({
    title,
    subtitle,
    expanded,
    onHistory,
    onNew,
    onToggleExpand,
    onClose,
}: {
    title: string;
    subtitle: string;
    expanded: boolean;
    onHistory: () => void;
    onNew: () => void;
    onToggleExpand: () => void;
    onClose: () => void;
}) {
    return (
        <div className="flex items-center gap-2 border-b border-border bg-gradient-to-r from-[#0F2044] to-[#16305f] px-3 py-2.5 text-white">
            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-white/10 ring-1 ring-white/15">
                <Sparkles className="size-4 text-[#0ABFBF]" />
            </span>
            <div className="min-w-0 flex-1">
                <p className="truncate text-sm leading-tight font-semibold">
                    {title}
                </p>
                <p className="truncate text-[11px] text-white/60">{subtitle}</p>
            </div>
            <HeaderButton label="Conversations" onClick={onHistory}>
                <History className="size-4" />
            </HeaderButton>
            <HeaderButton label="New conversation" onClick={onNew}>
                <PenSquare className="size-4" />
            </HeaderButton>
            <HeaderButton
                label={expanded ? 'Shrink' : 'Expand'}
                onClick={onToggleExpand}
            >
                {expanded ? (
                    <Minimize2 className="size-4" />
                ) : (
                    <Maximize2 className="size-4" />
                )}
            </HeaderButton>
            <HeaderButton label="Close" onClick={onClose}>
                <X className="size-4" />
            </HeaderButton>
        </div>
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
        <button
            type="button"
            onClick={onClick}
            title={label}
            aria-label={label}
            className="flex size-7 shrink-0 items-center justify-center rounded-md text-white/70 transition-colors hover:bg-white/10 hover:text-white"
        >
            {children}
        </button>
    );
}

/** Toast each executed action and refresh the current page so it shows live. */
function applyEffects(cards: AgentCard[]) {
    const mutated = cards.filter((card) => card.kind !== 'find');

    for (const card of mutated) {
        const message = `${card.badge} · ${card.title}`;

        if (card.tone === 'positive') {
            toast.success(message);
        } else {
            toast(message);
        }
    }

    if (mutated.length === 0) {
        return;
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
