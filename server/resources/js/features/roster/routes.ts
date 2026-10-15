/**
 * Centralised endpoint map for the shift roster (Attendance, ADR 0090).
 * Mirrors the named routes registered in routes/attendance.php.
 */
export const rosterRoutes = {
    index: '/attendance/roster',

    // One-off overrides, addressed by hashid, and putting people on a schedule.
    entry: '/attendance/roster/entries',
    entryDestroy: (hashid: string) => `/attendance/roster/entries/${hashid}`,
    assign: '/attendance/roster/assign',
} as const;
