/**
 * Endpoint map for Check-ins (ADR 0102), mirroring routes/check-ins.php.
 */
export const checkInRoutes = {
    index: '/check-ins',
    me: '/check-ins/me',
    store: '/check-ins/me',
    results: (period?: string) =>
        period ? `/check-ins/results?period=${period}` : '/check-ins/results',
    settings: '/check-ins/settings',
} as const;
