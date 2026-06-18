/**
 * Endpoint map for the Performance Management module.
 * Mirrors the named routes in routes/performance.php. Evaluations are addressed
 * by hashid.
 */
export const performanceRoutes = {
    index: '/performance',
    store: '/performance',
    show: (hashid: string) => `/performance/${hashid}`,
    update: (hashid: string) => `/performance/${hashid}`,
    submit: (hashid: string) => `/performance/${hashid}/submit`,
    acknowledge: (hashid: string) => `/performance/${hashid}/acknowledge`,
    destroy: (hashid: string) => `/performance/${hashid}`,
} as const;
