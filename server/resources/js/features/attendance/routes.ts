/**
 * Centralised endpoint map for the Attendance module.
 * Mirrors the named routes registered in routes/attendance.php.
 */
export const attendanceRoutes = {
    index: '/attendance',
    store: '/attendance',
    export: '/attendance/export',
    approveAll: '/attendance/approve-all',

    me: '/attendance/me',
    mePunch: '/attendance/me/punch',

    // Records are addressed by their obfuscated hashid (App\Support\Hashid).
    show: (hashid: string) => `/attendance/records/${hashid}`,
    update: (hashid: string) => `/attendance/records/${hashid}`,
    approve: (hashid: string) => `/attendance/records/${hashid}/approve`,
    destroy: (hashid: string) => `/attendance/records/${hashid}`,
} as const;
