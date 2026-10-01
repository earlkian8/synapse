/**
 * Centralised endpoint map for the shift roster (Company Setup).
 * Mirrors the named routes registered in routes/setup.php.
 */
export const rosterRoutes = {
    index: '/setup/roster',

    // One-off overrides, addressed by hashid, and putting people on a schedule.
    entry: '/setup/roster/entries',
    entryDestroy: (hashid: string) => `/setup/roster/entries/${hashid}`,
    assign: '/setup/roster/assign',
} as const;
