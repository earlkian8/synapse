/**
 * Centralised endpoint map for the Leave Management module.
 * Mirrors the named routes registered in routes/leave.php.
 */
export const leaveRoutes = {
    index: '/leave',
    store: '/leave',

    balances: '/leave/balances',
    balancesStore: '/leave/balances',

    // Requests are addressed by their obfuscated hashid (App\Support\Hashid).
    show: (hashid: string) => `/leave/${hashid}`,
    update: (hashid: string) => `/leave/${hashid}`,
    review: (hashid: string) => `/leave/${hashid}/review`,
    cancel: (hashid: string) => `/leave/${hashid}/cancel`,
    destroy: (hashid: string) => `/leave/${hashid}`,
} as const;
