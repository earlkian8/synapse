/**
 * Types for the Synapse assistant — a persistent, multi-conversation agentic chat
 * that acts across the HR modules (employees, leave, onboarding, recruitment).
 */

export type AssistantRole = 'user' | 'assistant';

export type AgentStepStatus = 'done' | 'error';

/** A single thing the agent did while handling a turn (drives the timeline). */
export type AgentStep = {
    label: string;
    status: AgentStepStatus;
    detail: string | null;
};

/** Visual kind of a result card — selects its icon on the frontend. */
export type AgentCardKind =
    | 'add'
    | 'edit'
    | 'archive'
    | 'approve'
    | 'reject'
    | 'cancel'
    | 'schedule'
    | 'find'
    | 'start'
    | 'move'
    | 'hire'
    | 'post'
    /** A read-out rather than a change: a summary, a ranking, an AI read. */
    | 'insight';

/** Colour intent of a result card. */
export type AgentCardTone =
    | 'positive'
    | 'info'
    | 'warning'
    | 'danger'
    | 'neutral';

export type AgentCardAvatar = {
    name: string;
    initials: string;
    photo: string | null;
};

/** A rich, module-agnostic result the chat animates in after an action. */
export type AgentCard = {
    module: string;
    kind: AgentCardKind;
    tone: AgentCardTone;
    badge: string;
    title: string;
    subtitle: string | null;
    meta: string[];
    avatar: AgentCardAvatar | null;
    id: number | string | null;
};

/** A turn in the chat (client view). `id` is the server id, or a temp string. */
export type ChatMessage = {
    id: number | string;
    role: AssistantRole;
    text: string;
    steps?: AgentStep[];
    actions?: AgentCard[];
    attachments?: string[];
    /** The assistant turn failed (rate limit / error) and can be retried. */
    failed?: boolean;
    /** The assistant turn is still in flight. */
    pending?: boolean;
    /** The assistant reply should reveal progressively (simulated streaming). */
    streaming?: boolean;
    createdAt?: string | null;
};

/** A conversation thread in the history list. */
export type Conversation = {
    id: number;
    title: string;
    pinned: boolean;
    lastActivityAt: string | null;
    preview?: string | null;
};

// ── API payloads ─────────────────────────────────────────────────────────────

export type ServerMessage = {
    id: number | null;
    role: AssistantRole;
    body: string;
    steps: AgentStep[];
    actions: AgentCard[];
    attachments: string[];
    failed: boolean;
    created_at: string | null;
};

export type TurnResponse = {
    conversation_id: number;
    title: string;
    user_message_id: number;
    message: ServerMessage;
};

export type ConversationDetail = {
    id: number;
    title: string;
    pinned: boolean;
    messages: ServerMessage[];
};
