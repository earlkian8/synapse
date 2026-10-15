/**
 * Endpoint map for Company Setup → Performance framework.
 * Mirrors the named routes in routes/setup.php (kpi.*). Everything is addressed
 * by hashid; restore / force-delete take the hashid as a plain string.
 */
export const kpiConfigRoutes = {
    index: '/setup/kpi',
    frameworks: {
        store: '/setup/kpi/frameworks',
        update: (hashid: string) => `/setup/kpi/frameworks/${hashid}`,
        destroy: (hashid: string) => `/setup/kpi/frameworks/${hashid}`,
        restore: (hashid: string) => `/setup/kpi/frameworks/${hashid}/restore`,
        forceDelete: (hashid: string) =>
            `/setup/kpi/frameworks/${hashid}/force`,
    },
    scales: {
        store: '/setup/kpi/scales',
        update: (hashid: string) => `/setup/kpi/scales/${hashid}`,
        destroy: (hashid: string) => `/setup/kpi/scales/${hashid}`,
        restore: (hashid: string) => `/setup/kpi/scales/${hashid}/restore`,
        forceDelete: (hashid: string) => `/setup/kpi/scales/${hashid}/force`,
    },
    criteria: {
        store: '/setup/kpi/criteria',
        update: (hashid: string) => `/setup/kpi/criteria/${hashid}`,
        destroy: (hashid: string) => `/setup/kpi/criteria/${hashid}`,
        restore: (hashid: string) => `/setup/kpi/criteria/${hashid}/restore`,
        forceDelete: (hashid: string) => `/setup/kpi/criteria/${hashid}/force`,
    },
    periods: {
        store: '/setup/kpi/periods',
        update: (hashid: string) => `/setup/kpi/periods/${hashid}`,
        destroy: (hashid: string) => `/setup/kpi/periods/${hashid}`,
        restore: (hashid: string) => `/setup/kpi/periods/${hashid}/restore`,
        forceDelete: (hashid: string) => `/setup/kpi/periods/${hashid}/force`,
    },
} as const;
