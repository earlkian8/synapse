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
        profile_url: string | null;
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
