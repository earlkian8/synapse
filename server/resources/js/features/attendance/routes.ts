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

    // Requests (ADR 0039) — filed by the employee, decided by a reviewer.
    requestStore: '/attendance/requests',
    requestBulkReview: '/attendance/requests/review',
    requestShow: (hashid: string) => `/attendance/requests/${hashid}`,
    requestReview: (hashid: string) => `/attendance/requests/${hashid}/review`,
    requestCancel: (hashid: string) => `/attendance/requests/${hashid}/cancel`,

    // Periods and the lock (ADR 0039).
    periodGenerate: '/attendance/periods/generate',
    periodSettings: '/attendance/periods/settings',
    periodLock: (hashid: string) => `/attendance/periods/${hashid}/lock`,
    periodUnlock: (hashid: string) => `/attendance/periods/${hashid}/unlock`,
    periodExport: (hashid: string) => `/attendance/periods/${hashid}/export`,

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
