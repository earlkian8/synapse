import { useCallback, useEffect, useRef, useState } from 'react';
import * as api from './api';
import { serverMessageToChat } from './api';
import type { ChatMessage, Conversation, TurnResponse } from './types';

let seq = 0;
const tempId = () => `tmp-${Date.now()}-${seq++}`;

/**
 * The brain of the assistant: server-persisted conversations, the active
 * thread's messages, sending with optimistic UI, simulated streaming, and the
 * full set of conversation/message controls.
 */
export function useAssistant() {
    const [conversations, setConversations] = useState<Conversation[]>([]);
    const [activeId, setActiveId] = useState<number | null>(null);
    const [messages, setMessages] = useState<ChatMessage[]>([]);
    const [sending, setSending] = useState(false);
    const [streamingId, setStreamingId] = useState<number | string | null>(
        null,
    );
    const [ready, setReady] = useState(false);
    const [loadingThread, setLoadingThread] = useState(false);

    const abortRef = useRef<AbortController | null>(null);

    const refreshList = useCallback(async () => {
        try {
            setConversations(await api.listConversations());
        } catch {
            // A failed list refresh is non-fatal; the thread still works.
        }
    }, []);

    // Initial load of the history list.
    useEffect(() => {
        let cancelled = false;

        void (async () => {
            try {
                const list = await api.listConversations();

                if (!cancelled) {
                    setConversations(list);
                }
            } catch {
                // ignore — the assistant still works without history.
            } finally {
                if (!cancelled) {
                    setReady(true);
                }
            }
        })();

        return () => {
            cancelled = true;
        };
    }, []);

    const newChat = useCallback(() => {
        abortRef.current?.abort();
        setActiveId(null);
        setMessages([]);
        setStreamingId(null);
    }, []);

    const openConversation = useCallback(
        async (id: number) => {
            if (id === activeId) {
                return;
            }

            abortRef.current?.abort();
            setActiveId(id);
            setStreamingId(null);
            setMessages([]);
            setLoadingThread(true);

            try {
                const detail = await api.getConversation(id);
                setMessages(detail.messages.map(serverMessageToChat));
            } catch {
                setMessages([]);
            } finally {
                setLoadingThread(false);
            }
        },
        [activeId],
    );

    // Apply a successful turn response to the local message list.
    const applyTurn = useCallback(
        (
            resp: TurnResponse,
            userTempId: number | string,
            pendingId: string,
        ) => {
            setActiveId(resp.conversation_id);

            setMessages((current) =>
                current
                    .map((m) =>
                        m.id === userTempId
                            ? { ...m, id: resp.user_message_id }
                            : m,
                    )
                    .map((m) =>
                        m.id === pendingId
                            ? {
                                  ...serverMessageToChat(resp.message),
                                  streaming: !resp.message.failed,
                              }
                            : m,
                    ),
            );

            if (!resp.message.failed && resp.message.id !== null) {
                setStreamingId(resp.message.id);
            }

            void refreshList();
        },
        [refreshList],
    );

    const runRequest = useCallback(
        async (
            pendingId: string,
            userTempId: number | string,
            request: (signal: AbortSignal) => Promise<TurnResponse>,
        ) => {
            setSending(true);
            const controller = new AbortController();
            abortRef.current = controller;

            try {
                const resp = await request(controller.signal);
                applyTurn(resp, userTempId, pendingId);
            } catch (error) {
                if (controller.signal.aborted) {
                    setMessages((current) =>
                        current.filter((m) => m.id !== pendingId),
                    );
                } else {
                    const text =
                        error instanceof Error
                            ? error.message
                            : 'Something went wrong. Please try again.';
                    setMessages((current) =>
                        current.map((m) =>
                            m.id === pendingId
                                ? { ...m, pending: false, failed: true, text }
                                : m,
                        ),
                    );
                }
            } finally {
                setSending(false);
                abortRef.current = null;
            }
        },
        [applyTurn],
    );

    const sendMessage = useCallback(
        async (text: string, files: File[], replaceMessageId?: number) => {
            const trimmed = text.trim();

            if ((trimmed === '' && files.length === 0) || sending) {
                return;
            }

            const userTempId = tempId();
            const pendingId = tempId();

            const userMessage: ChatMessage = {
                id: userTempId,
                role: 'user',
                text:
                    trimmed ||
                    (files.length > 1
                        ? `[${files.length} files attached]`
                        : '[1 file attached]'),
                attachments: files.map((f) => f.name),
            };

            setMessages((current) => [
                ...current,
                userMessage,
                { id: pendingId, role: 'assistant', text: '', pending: true },
            ]);

            await runRequest(pendingId, userTempId, (signal) =>
                api.sendTurn({
                    message: trimmed,
                    conversationId: activeId,
                    replaceMessageId,
                    files,
                    signal,
                }),
            );
        },
        [activeId, runRequest, sending],
    );

    // Edit a prior user message: truncate from it and re-send the new text.
    const editMessage = useCallback(
        async (messageId: number | string, newText: string) => {
            if (typeof messageId !== 'number') {
                return;
            }

            const index = messages.findIndex((m) => m.id === messageId);

            if (index !== -1) {
                setMessages((current) => current.slice(0, index));
            }

            await sendMessage(newText, [], messageId);
        },
        [messages, sendMessage],
    );

    const regenerate = useCallback(async () => {
        if (!activeId || sending) {
            return;
        }

        const pendingId = tempId();

        setMessages((current) => {
            let end = current.length;

            while (end > 0 && current[end - 1].role === 'assistant') {
                end -= 1;
            }

            return [
                ...current.slice(0, end),
                { id: pendingId, role: 'assistant', text: '', pending: true },
            ];
        });

        await runRequest(pendingId, -1, (signal) =>
            api.regenerateTurn(activeId, signal),
        );
    }, [activeId, runRequest, sending]);

    const stopStreaming = useCallback(() => {
        abortRef.current?.abort();
        setStreamingId(null);
    }, []);

    const endStreaming = useCallback((id: number | string) => {
        setStreamingId((current) => (current === id ? null : current));
    }, []);

    // ── Conversation management ──────────────────────────────────────────────

    const rename = useCallback(async (id: number, title: string) => {
        const clean = title.trim();

        if (clean === '') {
            return;
        }

        setConversations((current) =>
            current.map((c) => (c.id === id ? { ...c, title: clean } : c)),
        );

        try {
            await api.renameConversation(id, clean);
        } catch {
            // optimistic update stands; a refresh will reconcile later.
        }
    }, []);

    const togglePin = useCallback(
        async (id: number, pinned: boolean) => {
            setConversations((current) =>
                current.map((c) => (c.id === id ? { ...c, pinned } : c)),
            );

            try {
                await api.pinConversation(id, pinned);
            } finally {
                await refreshList();
            }
        },
        [refreshList],
    );

    const remove = useCallback(
        async (id: number) => {
            setConversations((current) => current.filter((c) => c.id !== id));

            if (activeId === id) {
                newChat();
            }

            try {
                await api.deleteConversation(id);
            } catch {
                // ignore — the row is already gone locally.
            }
        },
        [activeId, newChat],
    );

    const clearAll = useCallback(async () => {
        setConversations([]);
        newChat();

        try {
            await api.clearConversations();
        } catch {
            // ignore — the list is already cleared locally.
        }
    }, [newChat]);

    return {
        conversations,
        activeId,
        messages,
        sending,
        streamingId,
        ready,
        loadingThread,
        newChat,
        openConversation,
        sendMessage,
        editMessage,
        regenerate,
        stopStreaming,
        endStreaming,
        rename,
        togglePin,
        remove,
        clearAll,
    };
}
