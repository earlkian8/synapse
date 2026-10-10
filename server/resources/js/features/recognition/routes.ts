/**
 * Endpoint map for taking part in recognition (ADR 0071) — the wall, kudos,
 * nominations and points. Part of the Awards & Recognition module, so it
 * mirrors routes/awards.php. Nominations, kudos and requests are addressed by
 * id; rewards by hashid.
 */
export const recognitionRoutes = {
    wall: '/awards/wall',
    nominations: '/awards/my-nominations',
    rewards: '/awards/points',
    kudos: '/awards/kudos',
    kudosDestroy: (id: number) => `/awards/kudos/${id}`,
    nominate: '/awards/my-nominations',
    withdraw: (id: number) => `/awards/my-nominations/${id}`,
    redeem: (hashid: string) => `/awards/rewards/${hashid}/redeem`,
    cancel: (id: number) => `/awards/redemptions/${id}/cancel`,
} as const;
