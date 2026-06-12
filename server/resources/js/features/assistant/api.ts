import type {
    Conversation,
    ConversationDetail,
    ServerMessage,
    TurnResponse,
} from './types';

/** Read Laravel's XSRF cookie so plain fetches pass CSRF verification. */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function headers(json = true): Record<string, string> {
    const base: Record<string, string> = {
        'X-XSRF-TOKEN': xsrfToken(),
        Accept: 'application/json',
    };

    if (json) {
        base['Content-Type'] = 'application/json';
    }

    return base;
}

async function parse<T>(response: Response): Promise<T> {
    const data = (await response.json().catch(() => null)) as T | null;

    if (!response.ok || !data) {
        throw new Error(
            response.status === 419
                ? 'Your session expired. Please refresh the page and try again.'
                : `The assistant request failed (${response.status}).`,
        );
    }

    return data;
}

type RawConversation = {
    id: number;
    title: string;
    pinned: boolean;
    last_activity_at: string | null;
    preview?: string | null;
};

function toConversation(raw: RawConversation): Conversation {
    return {
        id: raw.id,
        title: raw.title,
        pinned: raw.pinned,
        lastActivityAt: raw.last_activity_at,
        preview: raw.preview ?? null,
    };
}

// ── Conversations ────────────────────────────────────────────────────────────

export async function listConversations(): Promise<Conversation[]> {
    const response = await fetch('/assistant/conversations', {
        headers: headers(false),
        credentials: 'same-origin',
    });
    const data = await parse<{ conversations: RawConversation[] }>(response);

    return data.conversations.map(toConversation);
}

export async function getConversation(id: number): Promise<ConversationDetail> {
    const response = await fetch(`/assistant/conversations/${id}`, {
        headers: headers(false),
        credentials: 'same-origin',
    });
    const data = await parse<{ conversation: ConversationDetail }>(response);

    return data.conversation;
}

export async function renameConversation(
    id: number,
    title: string,
): Promise<Conversation> {
    const response = await fetch(`/assistant/conversations/${id}`, {
        method: 'PATCH',
        headers: headers(),
        credentials: 'same-origin',
        body: JSON.stringify({ title }),
    });
    const data = await parse<{ conversation: RawConversation }>(response);

    return toConversation(data.conversation);
}

export async function pinConversation(
    id: number,
    pinned: boolean,
): Promise<Conversation> {
    const response = await fetch(`/assistant/conversations/${id}`, {
        method: 'PATCH',
        headers: headers(),
        credentials: 'same-origin',
        body: JSON.stringify({ pinned }),
    });
    const data = await parse<{ conversation: RawConversation }>(response);

    return toConversation(data.conversation);
}

export async function deleteConversation(id: number): Promise<void> {
    await fetch(`/assistant/conversations/${id}`, {
        method: 'DELETE',
        headers: headers(false),
        credentials: 'same-origin',
    });
}

export async function clearConversations(): Promise<void> {
    await fetch('/assistant/conversations', {
        method: 'DELETE',
        headers: headers(false),
        credentials: 'same-origin',
    });
}

// ── Turns ────────────────────────────────────────────────────────────────────

export async function sendTurn(opts: {
    message: string;
    conversationId?: number | null;
    replaceMessageId?: number | null;
    files: File[];
    signal?: AbortSignal;
}): Promise<TurnResponse> {
    const body = new FormData();
    body.append('message', opts.message);

    if (opts.conversationId) {
        body.append('conversation_id', String(opts.conversationId));
    }

    if (opts.replaceMessageId) {
        body.append('replace_message_id', String(opts.replaceMessageId));
    }

    for (const file of opts.files) {
        body.append('files[]', file);
    }

    const response = await fetch('/assistant', {
        method: 'POST',
        headers: { 'X-XSRF-TOKEN': xsrfToken(), Accept: 'application/json' },
        credentials: 'same-origin',
        body,
        signal: opts.signal,
    });

    return parse<TurnResponse>(response);
}

export async function regenerateTurn(
    conversationId: number,
    signal?: AbortSignal,
): Promise<TurnResponse> {
    const response = await fetch(
        `/assistant/conversations/${conversationId}/regenerate`,
        {
            method: 'POST',
            headers: headers(),
            credentials: 'same-origin',
            signal,
        },
    );

    return parse<TurnResponse>(response);
}

// ── Mapping ──────────────────────────────────────────────────────────────────

export function serverMessageToChat(m: ServerMessage) {
    return {
        id: m.id ?? `tmp-${Date.now()}-${Math.random().toString(36).slice(2)}`,
        role: m.role,
        text: m.body,
        steps: m.steps,
        actions: m.actions,
        attachments: m.attachments,
        failed: m.failed,
        createdAt: m.created_at,
    };
}
