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
    revert: (model: ModelKey) => `${SURFACE_PATHS[model]}/graduation`,
} as const;
