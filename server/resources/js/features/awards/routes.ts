/**
 * Endpoint map for the Awards & Recognition module.
 * Mirrors the named routes in routes/awards.php. Awards are addressed by numeric id.
 */
export const awardsRoutes = {
    index: '/awards',
    store: '/awards',
    export: '/awards/export',
    nominations: '/awards/nominations',
    shortlist: '/awards/shortlist',
    citation: '/awards/citation',
    award: (id: number) => `/awards/${id}`,

    // Nominations colleagues made (ADR 0071).
    approve: (id: number) => `/awards/nominations/${id}/approve`,
    reject: (id: number) => `/awards/nominations/${id}/reject`,

    // The rewards desk (ADR 0071). Rewards by hashid.
    rewards: '/awards/rewards',
    rewardStore: '/awards/rewards',
    rewardUpdate: (hashid: string) => `/awards/rewards/${hashid}`,
    rewardDestroy: (hashid: string) => `/awards/rewards/${hashid}`,
    rewardRestore: (hashid: string) => `/awards/rewards/${hashid}/restore`,
    fulfil: (id: number) => `/awards/redemptions/${id}/fulfil`,
    decline: (id: number) => `/awards/redemptions/${id}/decline`,
    adjust: '/awards/points/adjust',
    settings: '/awards/settings',
} as const;
