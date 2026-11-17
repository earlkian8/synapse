/**
 * What the breakdown dialogs fetch beside one person's prediction (ADR 0099) —
 * App\Support\Ml\PredictionContext. Every record block is null when the viewer may
 * not open the module it comes from.
 */

/** The three predictive surfaces, as the server names them. */
export type Surface = 'attrition' | 'promotion' | 'performance';

/** The person's result in one run (a tier or, for a forecast, a band). */
export type HistoryPoint = {
    run: string;
    at: string | null;
    score: number;
    tier: string;
    /** Forecast only: the likely range, the period, and what it actually gave. */
    low?: number | null;
    high?: number | null;
    period?: string | null;
    actual?: number | null;
};

/** Their latest result on another surface. */
export type Signal = {
    run: string;
    at: string | null;
    score: number;
    tier: string;
    low?: number | null;
    high?: number | null;
};

export type AttendanceMonth = {
    month: string;
    label: string;
    /** Days with an attendance record at all. */
    tracked: number;
    worked: number;
    absences: number;
    lates: number;
    late_minutes: number;
    overtime_hours: number;
};

export type LeaveSummary = {
    days_12m: number;
    by_type: { type: string; days: number; requests: number }[];
    pending: number;
    upcoming: { type: string; start: string; end: string; days: number }[];
};

export type AppraisalRecord = {
    period: string | null;
    ends: string | null;
    percent: number;
    result: string | null;
    sections: { name: string; percent: number; weight: number }[];
};

export type PredictionContext = {
    employment: {
        hired_on: string | null;
        tenure_years: number | null;
        employment_type: string | null;
        position: string | null;
        department: string | null;
        manager: string | null;
    };
    history: HistoryPoint[];
    signals: Partial<Record<Surface, Signal | null>>;
    offboarding: {
        type: string;
        status: string;
        notice_date: string | null;
        last_working_day: string | null;
    } | null;
    attendance: { months: AttendanceMonth[] } | null;
    leave: LeaveSummary | null;
    appraisals: AppraisalRecord[] | null;
    promotions:
        | {
              effective_date: string | null;
              from: string | null;
              to: string | null;
          }[]
        | null;
    training: {
        completed_12m: number;
        recent: { program: string; completed_at: string | null }[];
        current: { program: string; status: string }[];
    } | null;
    awards: { type: string; awarded_on: string | null }[] | null;
};

/** One suggested next step, and the reason on record behind it. */
export type NextStep = {
    key: string;
    title: string;
    because: string;
    /** Questions worth asking, for a conversation step. */
    prompts?: string[];
    link?: { href: string; label: string };
};

/** How urgent a person's read is, worded for the surface. */
export type Verdict = {
    tone: 'urgent' | 'watch' | 'calm' | 'positive' | 'closed';
    label: string;
    headline: string;
    notes: string[];
};

/**
 * One reason in a plain-language reading (ADR 0106). The label, effect, points and
 * whether a check was met come from the stored result; only `text` was written by
 * the AI.
 */
export type InterpretationReason = {
    key: string;
    label: string;
    /** Which way it moves the score; null when it has no direction (a forecast's facts, a check). */
    effect: 'raises' | 'lowers' | null;
    points: number | null;
    text: string;
    /** For a model check: whether this part of it was met (null: no bar to meet). */
    met?: boolean | null;
};

/** What an LLM wrote about one prediction or one model check, for HR. */
export type Interpretation = {
    available: true;
    headline: string;
    summary: string;
    reasons: InterpretationReason[];
    /** How the rest of the record reads beside it / what switching would mean. */
    context: string;
    /**
     * What HR should confirm before acting (deciding, for a model check). Absent on
     * readings written before this replaced the AI's notes on its own limits.
     */
    checks?: string[];
    actions: string[];
    generated_at: string;
};

/** Why no reading could be written (not configured, busy, unreadable answer). */
export type InterpretationUnavailable = {
    available: false;
    reason: string;
    retryable: boolean;
};

export type InterpretationResult = Interpretation | InterpretationUnavailable;
