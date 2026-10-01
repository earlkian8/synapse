/**
 * Endpoint map for the Performance Management module.
 * Mirrors the named routes in routes/performance.php. Appraisals are addressed
 * by hashid; the overview and the export are scoped to one review cycle.
 */
export const performanceRoutes = {
    index: '/performance',
    forPeriod: (periodId: number | null) =>
        periodId === null ? '/performance' : `/performance?period=${periodId}`,
    store: '/performance',
    launchCycle: '/performance/cycles',
    export: (periodId: number | null) =>
        periodId === null
            ? '/performance/export'
            : `/performance/export?period=${periodId}`,
    show: (hashid: string) => `/performance/${hashid}`,
    insights: (hashid: string) => `/performance/${hashid}/insights`,
    update: (hashid: string) => `/performance/${hashid}`,
    submit: (hashid: string) => `/performance/${hashid}/submit`,
    acknowledge: (hashid: string) => `/performance/${hashid}/acknowledge`,
    destroy: (hashid: string) => `/performance/${hashid}`,
} as const;
