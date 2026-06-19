/**
 * Endpoint map for Company Setup → Work Schedule & Holidays.
 * Mirrors the named routes in routes/setup.php (schedule.*). Schedules and
 * holidays are addressed by hashid; restore / force-delete take it as a string.
 */
export const scheduleConfigRoutes = {
    index: '/setup/schedule',
    workSchedules: {
        store: '/setup/schedule/work-schedules',
        update: (hashid: string) => `/setup/schedule/work-schedules/${hashid}`,
        destroy: (hashid: string) => `/setup/schedule/work-schedules/${hashid}`,
        restore: (hashid: string) =>
            `/setup/schedule/work-schedules/${hashid}/restore`,
        forceDelete: (hashid: string) =>
            `/setup/schedule/work-schedules/${hashid}/force`,
    },
    holidays: {
        store: '/setup/schedule/holidays',
        update: (hashid: string) => `/setup/schedule/holidays/${hashid}`,
        destroy: (hashid: string) => `/setup/schedule/holidays/${hashid}`,
        restore: (hashid: string) =>
            `/setup/schedule/holidays/${hashid}/restore`,
        forceDelete: (hashid: string) =>
            `/setup/schedule/holidays/${hashid}/force`,
    },
} as const;
