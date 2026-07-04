/**
 * Endpoint map for the Training & Development module.
 * Mirrors the named routes in routes/training.php. Programs are addressed by
 * hashid; enrollments by numeric id. Restore / force-delete take the hashid as a
 * plain string.
 */
export const trainingRoutes = {
    index: '/training',
    store: '/training',
    export: '/training/export',
    show: (hashid: string) => `/training/${hashid}`,
    update: (hashid: string) => `/training/${hashid}`,
    destroy: (hashid: string) => `/training/${hashid}`,
    restore: (hashid: string) => `/training/${hashid}/restore`,
    forceDelete: (hashid: string) => `/training/${hashid}/force`,
    rosterExport: (hashid: string) => `/training/${hashid}/export`,
    insights: (hashid: string) => `/training/${hashid}/insights`,
    enroll: (hashid: string) => `/training/${hashid}/enrollments`,
    enrollment: (id: number) => `/training/enrollments/${id}`,
    bulkEnrollments: '/training/enrollments/bulk',
} as const;
