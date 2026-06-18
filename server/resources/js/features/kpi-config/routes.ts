/**
 * Endpoint map for Company Setup → KPI & Evaluation Criteria.
 * Mirrors the named routes in routes/setup.php (kpi.*). Criteria and periods are
 * addressed by hashid; restore / force-delete take the hashid as a plain string.
 */
export const kpiConfigRoutes = {
    index: '/setup/kpi',
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
