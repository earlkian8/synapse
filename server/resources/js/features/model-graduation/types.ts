/**
 * Model graduation (ADR 0046) — how each predictive surface moves from a general
 * model, learned from other workplaces' data, to one trained on the organisation's
 * own records. Every figure here is counted server-side from those records
 * (`App\Support\Ml\Graduation`); nothing is simulated.
 */

/** The three predictive surfaces, each graduating on its own terms. */
export type ModelKey = 'promotion' | 'performance' | 'attrition';

/**
 * Where a surface sits: scored by the general model with nothing of its own
 * recorded yet, still the general model while its own history builds up, or scored
 * by its own model.
 */
export type Stage = 'provisional' | 'collecting' | 'graduated';

/** How close one requirement is to being met. */
export type RequirementStatus = 'met' | 'progressing' | 'waiting';

/** Enough history, history that means the same thing, and the system around it. */
export type RequirementGroup = 'volume' | 'quality' | 'system';

export type Requirement = {
    key: string;
    /** What is counted, in the reader's words. */
    label: string;
    group: RequirementGroup;
    /** A count, a percentage of something, or a yes/no (1 / 0). */
    format: 'count' | 'percent' | 'check';
    current: number;
    required: number;
    /** What is counted, plural and singular, for "86 more promotions". */
    unit: string;
    unit_one: string;
    status: RequirementStatus;
    /** One line on what this is. */
    summary: string;
    /** What the reader can do to move it. */
    action: string;
    /** Why the threshold is this number. */
    basis: string;
    /** Where the count comes from. */
    source: string;
    /**
     * Follows from other records rather than being collected on its own — never
     * named as the one furthest from ready.
     */
    derived: boolean;
    /** When it might be met, at the organisation's recent pace. */
    outlook: string | null;
    /** Records that exist but can't count yet, and why. */
    note: string | null;
};

/** What a model trained on the organisation's records was judged on. */
export type Comparison = {
    /** Promotion: prediction error; performance: average miss; attrition: ranking. */
    metric: 'brier' | 'mae' | 'roc_auc';
    better: 'lower' | 'higher';
    /** The organisation's model, the general one, and knowing nothing about the person. */
    local?: number;
    reference?: number;
    baseline?: number;
    /** Share of re-checks (resamples of the organisation's people) it came out ahead in. */
    wins_over_reference?: number;
    wins_over_baseline?: number;
    required_share: number;
    examples: number;
    people: number;
    /** Performance: how many of the ratings that followed its ranges held. */
    coverage?: number;
    promised_coverage?: number;
};

/** One attempt at training on the organisation's records. */
export type LocalModelSummary = {
    hashid: string;
    status: 'failed' | 'ready' | 'active' | 'retired';
    examples: number;
    counts: Record<string, number>;
    comparison: Comparison | null;
    /** Plain-language sentences, one per check. */
    findings: string[];
    trained_at: string | null;
    trained_by: string | null;
    activated_at: string | null;
    activated_by: string | null;
};

/**
 * Whether a field reaches the score today.
 *
 * `supplied` — read from your records and fed into every score.
 * `available` — the system records it, but it is not fed in.
 * `missing`   — nothing in the system produces it.
 */
export type FieldState = 'supplied' | 'available' | 'missing';

/** How completely one input field is filled in. */
export type FieldCoverage = {
    key: string;
    label: string;
    /** Which part of the system the value comes from. */
    source: string;
    state: FieldState;
    covered: number;
    total: number;
    /** What the gap means, or why the field is not used. */
    note: string;
};

/** A surface's graduation, as its page receives it. */
export type Graduation = {
    model: ModelKey;
    stage: Stage;
    /** Every requirement is met: a model can be trained. */
    gate_open: boolean;
    requirements: Requirement[];
    met_count: number;
    total_count: number;
    /** The actionable requirement furthest from being met. */
    binding_key: string | null;
    /** Labelled examples the organisation's records hold today. */
    examples: number;
    /** The organisation's own model scoring this surface, if any. */
    active: LocalModelSummary | null;
    /** The newest attempt not in use: a model ready to switch to, or a check that failed. */
    latest: LocalModelSummary | null;
    fields: FieldCoverage[];
    /** Active employees, for the field coverage. */
    employees: number;
    checked_at: string;
};
