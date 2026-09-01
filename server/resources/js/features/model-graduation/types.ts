/**
 * Model graduation — the lifecycle each predictive surface moves through as an
 * organisation accumulates enough of its own history to train on.
 *
 * Promotion Readiness and Performance Forecast are served by models trained on a
 * general workforce dataset rather than on the deploying organisation's records;
 * Attrition Risk has no trained model at all. Each surface embeds its own
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
    /** Plural noun for the count, e.g. "promotions". */
    unit: string;
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
};
