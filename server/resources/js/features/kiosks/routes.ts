/**
 * Attendance → Kiosks (routes/attendance.php). Kiosks by hashid; a PIN by id.
 */
export const kioskAdminRoutes = {
    index: '/attendance/kiosks',
    store: '/attendance/kiosks',
    update: (hashid: string) => `/attendance/kiosks/${hashid}`,
    pairingCode: (hashid: string) =>
        `/attendance/kiosks/${hashid}/pairing-code`,
    unpair: (hashid: string) => `/attendance/kiosks/${hashid}/device`,
    destroy: (hashid: string) => `/attendance/kiosks/${hashid}`,
    issuePins: '/attendance/kiosks/pins',
    withdrawPin: (id: number) => `/attendance/kiosks/pins/${id}`,
} as const;
