/**
 * Attendance → QR stations (routes/attendance.php, ADR 0103). By hashid.
 */
export const qrStationRoutes = {
    index: '/attendance/qr',
    store: '/attendance/qr',
    update: (hashid: string) => `/attendance/qr/${hashid}`,
    toggle: (hashid: string) => `/attendance/qr/${hashid}/toggle`,
    reset: (hashid: string) => `/attendance/qr/${hashid}/reset`,
    destroy: (hashid: string) => `/attendance/qr/${hashid}`,
    display: (hashid: string) => `/attendance/qr/${hashid}/display`,
    code: (hashid: string) => `/attendance/qr/${hashid}/code`,
} as const;
