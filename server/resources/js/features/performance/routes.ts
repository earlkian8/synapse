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

    // Reviews (ADR 0072): asking on a scorecard, and the reviewer's own side.
    requestReviews: (hashid: string) => `/performance/${hashid}/reviews`,
    reviews: '/performance/reviews',
    review: (hashid: string) => `/performance/reviews/${hashid}`,
    reviewSubmit: (hashid: string) => `/performance/reviews/${hashid}/submit`,
    reviewDecline: (hashid: string) => `/performance/reviews/${hashid}/decline`,
    reviewCancel: (hashid: string) => `/performance/reviews/${hashid}/cancel`,
    reviewRemind: (hashid: string) => `/performance/reviews/${hashid}/remind`,

    // My appraisals and my goals.
    me: '/performance/me',
    myAppraisal: (hashid: string) => `/performance/me/${hashid}`,
    myAcknowledge: (hashid: string) => `/performance/me/${hashid}/acknowledge`,
    myGoals: (periodId?: number | null) =>
        periodId
            ? `/performance/me/goals?period=${periodId}`
            : '/performance/me/goals',
    myGoalsStore: '/performance/me/goals',
    myGoalCheckIn: (hashid: string) =>
        `/performance/me/goals/${hashid}/check-ins`,
    myGoalDestroy: (hashid: string) => `/performance/me/goals/${hashid}`,

    // Goals (ADR 0073).
    goals: (periodId?: number | null) =>
        periodId
            ? `/performance/goals?period=${periodId}`
            : '/performance/goals',
    goalsStore: '/performance/goals',
    goal: (hashid: string) => `/performance/goals/${hashid}`,
    goalCheckIn: (hashid: string) => `/performance/goals/${hashid}/check-ins`,
    goalStatus: (hashid: string) => `/performance/goals/${hashid}/status`,

    // Calibration sessions (ADR 0073).
    calibration: (periodId?: number | null) =>
        periodId
            ? `/performance/calibration?period=${periodId}`
            : '/performance/calibration',
    calibrationStore: '/performance/calibration',
    session: (hashid: string) => `/performance/calibration/${hashid}`,
    sessionAdjust: (hashid: string) =>
        `/performance/calibration/${hashid}/adjustments`,
    sessionComplete: (hashid: string) =>
        `/performance/calibration/${hashid}/complete`,
    sessionCancel: (hashid: string) =>
        `/performance/calibration/${hashid}/cancel`,
} as const;
