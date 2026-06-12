/**
 * Types for the Employee assistant — the floating agentic chat that can find,
 * create, update and archive employees on the user's behalf.
 */

export type AssistantRole = 'user' | 'assistant';

export type AgentStepStatus = 'done' | 'error';

/** A single thing the agent did while handling a turn (drives the timeline). */
export type AgentStep = {
    label: string;
    status: AgentStepStatus;
    detail: string | null;
};

export type AgentActionType = 'created' | 'updated' | 'archived';

/** A compact employee payload returned for an executed mutation. */
export type AgentEmployee = {
    id: number;
    employee_no: string;
    full_name: string;
    initials: string;
    photo: string | null;
    position: string | null;
    department: string | null;
    employment_status: string;
    employment_type: string;
    action: AgentActionType;
};

export type AgentAction = {
    type: AgentActionType;
    employee: AgentEmployee;
};

/** The JSON shape returned by POST /employees/assistant. */
export type AssistantResponse = {
    reply: string;
    steps: AgentStep[];
    actions: AgentAction[];
    error?: string | null;
};

export type ChatMessage = {
    id: string;
    role: AssistantRole;
    text: string;
    steps?: AgentStep[];
    actions?: AgentAction[];
    fileNames?: string[];
    /** True while the assistant turn is still in flight. */
    pending?: boolean;
};
