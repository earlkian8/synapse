/**
 * Model Graduation — the lifecycle a predictive model moves through as an
 * organisation accumulates enough of its own history to train on.
 *
 * The shipped Promotion Readiness and Performance Forecast models are trained on
 * a general public dataset. This surface tracks the conditions under which that
 * model could honestly be replaced by one trained on THIS organisation's records,
 * and refuses to retrain until every condition is met.
 */

/** Where the model currently sits in its lifecycle. */
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
    /** Plural noun for the count, e.g. "promotions". */
    unit: string;
    status: RequirementStatus;
    /** One line on what this requirement protects against. */
    summary: string;
    /** Why the threshold is this number — the statistical justification. */
    basis: string;
    /** Where the count comes from in the system. */
    source: string;
};

/**
 * One readiness check: a dated snapshot of every requirement, plus the verdict
 * derived from them.
 */
export type GraduationCheck = {
    id: number;
    hashid: string;
    checked_at: string;
    stage: Stage;
    /** The dataset the active model was trained on. */
    dataset_origin: string;
    /** Active employees whose records feed the check. */
    employees_tracked: number;
    requirements: Requirement[];
    met_count: number;
    total_count: number;
    /** The requirement furthest from being satisfied — what actually blocks. */
    binding_key: string;
    /** Promotions per year observed on record, used for the projection. */
    observed_rate: number;
    /** Calendar year graduation becomes possible at the observed rate. */
    projected_year: number | null;
};

/** A lightweight check for the history selector. */
export type CheckSummary = {
    hashid: string;
    checked_at: string;
    met_count: number;
    total_count: number;
    stage: Stage;
};
