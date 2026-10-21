/**
 * Endpoint map for problem reports (ADR 0096).
 * Mirrors the named routes in routes/help.php (help.reports.*).
 */
export const problemReportRoutes = {
    index: '/help/reports',
    store: '/help/reports',
    /** Your reports, with one of them open. */
    show: (reference: string) =>
        `/help/reports?report=${encodeURIComponent(reference)}`,
} as const;
