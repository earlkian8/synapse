/** The answer keys, in the order the check-in asks them (CheckInQuestions). */
export type AnswerKey =
    | 'mood'
    | 'satisfaction'
    | 'energy'
    | 'enthusiasm'
    | 'immersion'
    | 'leave_thoughts';

export type Answers = Record<AnswerKey, number>;

export type QuestionOption = {
    value: number;
    label: string;
    /** How often a frequency means, e.g. "A few times a month". */
    hint: string | null;
};

export type Question = {
    key: AnswerKey;
    prompt: string;
    group: 'mood' | 'satisfaction' | 'engagement' | 'leaving';
    min: number;
    max: number;
    options: QuestionOption[];
};

/** One of the person's own months — only ever served to them. */
export type MyCheckIn = {
    id: number;
    /** The month's first day, YYYY-MM-DD. */
    period: string;
    answers: Answers;
    /** UWES-3: the mean of energy, enthusiasm and immersion, 0–6. */
    engagement: number;
    /** For the person alone. */
    note: string | null;
    submitted_at: string;
};

export type CheckInOverview = {
    enabled: boolean;
    has_employee: boolean;
    period: string;
    period_label: string;
    days_left: number;
    current: MyCheckIn | null;
    /** Newest first, the last twelve months. */
    history: MyCheckIn[];
    /** The twelve months, oldest first, ending with this one. */
    months: string[];
    questions: Question[];
};

export type MyCheckInPageProps = { overview: CheckInOverview };

/** A group's figures; only `answers` when it is too small to show (< 5). */
export type GroupResult = {
    answers: number;
    shown: boolean;
    mood?: number;
    satisfaction?: number;
    engagement?: number;
    /** The share who often or always think about leaving, 0–1. */
    leave_share?: number;
    /** How many answered each mood, Rough → Great. */
    moods?: [number, number, number, number, number];
};

export type DepartmentResult = GroupResult & {
    id: number | null;
    name: string;
    /** Active people in the department. */
    people: number;
};

export type PeriodResults = {
    period: string;
    period_label: string;
    company: GroupResult & { people: number };
    departments: DepartmentResult[];
    /** Departments too small to show alone, pooled (or null when none). */
    others: (GroupResult & { teams: number }) | null;
};

export type TrendPoint = GroupResult & { period: string; period_label: string };

export type ResultsPageProps = {
    results: PeriodResults;
    trend: TrendPoint[];
    periods: { value: string; label: string }[];
    questions: Question[];
    min_group: number;
    enabled: boolean;
    can: { manage: boolean };
};
