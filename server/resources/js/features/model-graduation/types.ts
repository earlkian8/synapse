/**
 * Model graduation — the lifecycle each predictive surface moves through as an
 * organisation accumulates enough of its own history to train on.
 *
 * Promotion Readiness and Performance Forecast are served by models trained on a
 * general workforce dataset, and Attrition Risk by one trained on a survey of
 * workers at other employers — none on the deploying organisation's own records.
 * Each surface embeds its own
 * readiness panel stating that plainly and tracking what would have to be true
 * before a locally trained model could replace it.
 */

/** The three predictive surfaces, each graduating on its own terms. */
export type ModelKey = 'promotion' | 'performance' | 'attrition';

/** Where a surface's model currently sits in its lifecycle. */
export type Stage = 'provisional' | 'collecting' | 'graduated';

/** How close a single requirement is to being satisfied. */
export type RequirementStatus = 'met' | 'progressing' | 'waiting';

/** Requirements are grouped by what kind of problem they guard against. */
export type RequirementGroup = 'volume' | 'quality' | 'system';

export type Requirement = {
    key: string;
    /** What is being counted, in the user's words. */
    label: string;
    group: RequirementGroup;
    current: number;
    required: number;
    /**
     * What the count is *of*, phrased specifically enough to stand alone in a
     * chip — "people held back for testing", not "people". Plural form.
     */
    unit: string;
    /** The singular form, used when exactly one is outstanding. */
    unitOne: string;
    status: RequirementStatus;
    /** One line on what this requirement protects against, in plain language. */
    summary: string;
    /** Why the threshold is this number — the statistical justification. */
    basis: string;
    /** Where the count comes from in the system. */
    source: string;
    /**
     * True when this count follows from another requirement rather than being
     * collected on its own — held-out rows rise as records do. Derived
     * requirements are never named as the blocker, because there is nothing to
     * act on them directly.
     */
    derived?: boolean;
    /** What it would take to close this shortfall, in plain language. */
    outlook?: string;
};

/**
 * Whether a field reaches the score today.
 *
 * `supplied` — read from your records and fed into every score.
 * `available` — the system already records it, but it is not fed in yet.
 * `missing`   — nothing in the system produces it, so it cannot be fed at all.
 */
export type FieldState = 'supplied' | 'available' | 'missing';

/** How completely one input field is filled in across the workforce. */
export type FieldCoverage = {
    key: string;
    /** The field in the user's words, not the model's column name. */
    label: string;
    /** Which part of the system the value comes from. */
    source: string;
    state: FieldState;
    /** Employees whose record carries a usable value. */
    covered: number;
    /** Employees in scope. */
    total: number;
    /** What the gap means, or why the field cannot be fed yet. */
    note: string;
};

/** One readiness check for one surface: every requirement, plus the verdict. */
export type ModelCheck = {
    model: ModelKey;
    hashid: string;
    checked_at: string;
    stage: Stage;
    requirements: Requirement[];
    met_count: number;
    total_count: number;
    /**
     * The requirement furthest from being satisfied, among those that can be
     * acted on directly — what actually blocks.
     */
    binding_key: string;
    /** Per-field record coverage across the workforce. */
    fields: FieldCoverage[];
    /** Employees in scope for the coverage figures. */
    employees: number;
};
