/**
 * Centralised endpoint map for the Attendance module.
 * Mirrors the named routes registered in routes/attendance.php.
 */
export const attendanceRoutes = {
    index: '/attendance',
    store: '/attendance',
    export: '/attendance/export',
    approveAll: '/attendance/approve-all',
    reapplyRange: '/attendance/reapply-schedule',

    // The roster — who is due to work what, and one-off overrides (ADR 0037).
    rosterEntry: '/attendance/roster/entries',
    rosterEntryDestroy: (hashid: string) =>
        `/attendance/roster/entries/${hashid}`,
    rosterAssign: '/attendance/roster/assign',

    me: '/attendance/me',
    mePunch: '/attendance/me/punch',

    // Records are addressed by their obfuscated hashid (App\Support\Hashid).
    show: (hashid: string) => `/attendance/records/${hashid}`,
    update: (hashid: string) => `/attendance/records/${hashid}`,
    approve: (hashid: string) => `/attendance/records/${hashid}/approve`,
    reapply: (hashid: string) =>
        `/attendance/records/${hashid}/reapply-schedule`,
    destroy: (hashid: string) => `/attendance/records/${hashid}`,
} as const;
