/**
 * Endpoint map for the Promotion Readiness module.
 * Mirrors the named routes in routes/analytics.php. Runs are addressed by hashid.
 */
export const promotionReadinessRoutes = {
    index: '/analytics/promotion-readiness',
    store: '/analytics/promotion-readiness',
    show: (hashid: string) =>
        `/analytics/promotion-readiness?run=${encodeURIComponent(hashid)}`,
    destroy: (hashid: string) => `/analytics/promotion-readiness/${hashid}`,
} as const;
