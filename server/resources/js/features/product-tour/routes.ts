/**
 * Endpoint map for the product tour.
 * Mirrors the named routes registered in routes/settings.php (tour.*).
 */
export const productTourRoutes = {
    finish: '/settings/tour',
    /** Each run, for the per-stop numbers (ADR 0080). */
    runs: '/settings/tour/runs',
    run: (id: number) => `/settings/tour/runs/${id}`,
} as const;
