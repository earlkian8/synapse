/**
 * Endpoint map for the Attrition Risk module.
 * Mirrors the named routes in routes/analytics.php. Runs are addressed by hashid.
 */
export const attritionRiskRoutes = {
    index: '/analytics/attrition',
    store: '/analytics/attrition',
    show: (hashid: string) =>
        `/analytics/attrition?run=${encodeURIComponent(hashid)}`,
    destroy: (hashid: string) => `/analytics/attrition/${hashid}`,
} as const;
