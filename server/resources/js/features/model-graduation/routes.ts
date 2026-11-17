import type { ModelKey } from './types';

/**
 * Endpoint map for model graduation. Mirrors the `*.graduation.*` named routes in
 * routes/analytics.php — registered once per surface, under its own prefix.
 */
const SURFACE_PATHS: Record<ModelKey, string> = {
    promotion: '/analytics/promotion-readiness',
    performance: '/analytics/performance-forecast',
    attrition: '/analytics/attrition',
};

export const graduationRoutes = {
    train: (model: ModelKey) => `${SURFACE_PATHS[model]}/graduation`,
    activate: (model: ModelKey, hashid: string) =>
        `${SURFACE_PATHS[model]}/graduation/${hashid}/activate`,
    /** Keep the model in use and set the new one aside. */
    dismiss: (model: ModelKey, hashid: string) =>
        `${SURFACE_PATHS[model]}/graduation/${hashid}/dismiss`,
    /** A plain-language reading of the check (ADR 0106). */
    interpretation: (model: ModelKey, hashid: string) =>
        `${SURFACE_PATHS[model]}/graduation/${hashid}/interpretation`,
    revert: (model: ModelKey) => `${SURFACE_PATHS[model]}/graduation`,
} as const;
