/**
 * Types for the Nexo assistant — the floating agentic chat that can act across
 * the HR modules (employees, leave, onboarding, recruitment) on the user's
 * behalf, one tool call at a time.
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
    | 'post';

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

/** The JSON shape returned by POST /assistant. */
export type AssistantResponse = {
    reply: string;
    steps: AgentStep[];
    actions: AgentCard[];
    error?: string | null;
};

export type ChatMessage = {
    id: string;
    role: AssistantRole;
    text: string;
    steps?: AgentStep[];
    actions?: AgentCard[];
    fileNames?: string[];
    /** True while the assistant turn is still in flight. */
    pending?: boolean;
};
