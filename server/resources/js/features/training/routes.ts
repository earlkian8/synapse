/**
 * Endpoint map for the Training & Development module.
 * Mirrors the named routes in routes/training.php. Programs are addressed by
 * hashid; enrollments, sessions, costs and certificates by numeric id. Restore /
 * force-delete take the hashid as a plain string.
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
    approve: (id: number) => `/training/enrollments/${id}/approve`,
    decline: (id: number) => `/training/enrollments/${id}/decline`,

    // Sessions and attendance (ADR 0074); costs (ADR 0075).
    sessions: (hashid: string) => `/training/${hashid}/sessions`,
    session: (id: number) => `/training/sessions/${id}`,
    attendance: (id: number) => `/training/sessions/${id}/attendance`,
    costs: (hashid: string) => `/training/${hashid}/costs`,
    cost: (id: number) => `/training/costs/${id}`,

    // The module's sections.
    me: '/training/me',
    meTab: (tab: string) => `/training/me?tab=${tab}`,
    sessionIcs: (id: number) => `/training/me/sessions/${id}/ics`,
    withdraw: (id: number) => `/training/me/enrollments/${id}`,
    catalog: '/training/catalog',
    join: (hashid: string) => `/training/catalog/${hashid}/join`,
    schedule: (month?: string) =>
        month ? `/training/schedule?month=${month}` : '/training/schedule',
    certificates: '/training/certificates',
    certificatesExport: '/training/certificates/export',
    certificate: (id: number) => `/training/certificates/${id}`,
    budget: (year?: number) =>
        year ? `/training/budget?year=${year}` : '/training/budget',
    budgetStore: '/training/budget',
} as const;
