/**
 * Endpoint map for the Performance Forecast module.
 * Mirrors the named routes in routes/analytics.php. Runs are addressed by hashid.
 */
export const performanceForecastRoutes = {
    index: '/analytics/performance-forecast',
    store: '/analytics/performance-forecast',
    show: (hashid: string) =>
        `/analytics/performance-forecast?run=${encodeURIComponent(hashid)}`,
    destroy: (hashid: string) => `/analytics/performance-forecast/${hashid}`,
} as const;
