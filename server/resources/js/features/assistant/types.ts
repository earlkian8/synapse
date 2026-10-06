/**
 * Types for the Synapse assistant — a persistent, multi-conversation agentic chat
 * that acts across the HR modules (employees, leave, attendance, onboarding,
 * recruitment, performance, and the dashboard).
 */

export type AssistantRole = 'user' | 'assistant';

/**
 * `held` is an action the assistant proposed but did not take: it waits for the
 * user to confirm it (ADR 0049).
 */
export type AgentStepStatus = 'done' | 'error' | 'held';

/**
 * What kind of work a step was. `read` is the assistant consulting the record
 * before answering — the grounding behind a generated answer, which is worth
 * telling apart from something it went and changed.
 */
export type AgentStepKind = 'read' | 'action';

/** A single thing the agent did while handling a turn (drives the timeline). */
export type AgentStep = {
    label: string;
    status: AgentStepStatus;
    detail: string | null;
    /** Absent on turns recorded before reads were distinguished. */
    kind?: AgentStepKind;
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
    /** Someone was chased about outstanding work. */
    | 'remind'
    /** Someone was recognised with an award. */
    | 'award'
    /** A read-out rather than a change: a summary, a ranking, an AI read. */
    | 'insight'
    /** An action held until the user confirms or cancels it. */
    | 'confirm';

/** Colour intent of a result card. */
export type AgentCardTone =
    'positive' | 'info' | 'warning' | 'danger' | 'neutral';

export type AgentCardAvatar = {
    name: string;
    initials: string;
    photo: string | null;
};

/** Where a held action stands. */
export type ConfirmationState =
    'pending' | 'confirmed' | 'cancelled' | 'expired';

/**
 * The answer a held action is waiting for. The token is a single-use capability
 * the server issued for this user; it is null once the action is answered.
 */
export type AgentConfirmation = {
    token: string | null;
    state: ConfirmationState;
    expires_at: string | null;
};

/** One step of a held plan, as the confirm card lists it (ADR 0068). */
export type PlanStep = {
    title: string;
    detail: string | null;
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
    /** Present on a `confirm` card only. */
    confirmation?: AgentConfirmation;
    /** The steps a `confirm` card would run, in order (absent on cards from before plans). */
    plan?: PlanStep[];
};

/**
 * Which changes wait for the person's OK (ADR 0068). `balanced` is the default:
 * consequential changes, changes on a question and changes on a turn with an
 * attached document wait; `manual` waits on every change; `auto` waits only on
 * consequential changes and changes on a question.
 */
export type AssistantMode = 'manual' | 'balanced' | 'auto';

/** What a turn cost: model requests and tokens, summed. */
export type TurnUsage = {
    requests: number;
    prompt_tokens: number;
    output_tokens: number;
    cached_tokens: number;
    thinking_tokens: number;
};

/** A turn in the chat (client view). `id` is the server id, or a temp string. */
export type ChatMessage = {
    id: number | string;
    role: AssistantRole;
    text: string;
    steps?: AgentStep[];
    actions?: AgentCard[];
    attachments?: string[];
    /** What the turn cost (assistant turns since ADR 0068). */
    usage?: TurnUsage | null;
    /** The assistant turn failed (rate limit / error) and can be retried. */
    failed?: boolean;
    /** The assistant turn is still in flight. */
    pending?: boolean;
    /** The assistant reply should reveal progressively (simulated streaming). */
    streaming?: boolean;
    /** Loaded from history: its actions ran in an earlier session. */
    replayed?: boolean;
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
    usage?: TurnUsage | null;
    failed: boolean;
    created_at: string | null;
};

export type TurnResponse = {
    conversation_id: number;
    title: string;
    user_message_id: number;
    message: ServerMessage;
};

export type ActionAnswer = {
    state: 'confirmed' | 'cancelled';
    /** What confirming did, as a new assistant turn (confirm only). */
    message?: ServerMessage;
};

export type ConversationDetail = {
    id: number;
    title: string;
    pinned: boolean;
    messages: ServerMessage[];
};
