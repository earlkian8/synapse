/**
 * Endpoint map for Company Setup → Payroll Configuration.
 * Mirrors the named routes in routes/setup.php (payroll.*). Types are addressed
 * by hashid; restore / force-delete take the hashid as a plain string.
 */
export const payrollConfigRoutes = {
    index: '/setup/payroll',
    allowance: {
        store: '/setup/payroll/allowance-types',
        update: (hashid: string) => `/setup/payroll/allowance-types/${hashid}`,
        destroy: (hashid: string) => `/setup/payroll/allowance-types/${hashid}`,
        restore: (hashid: string) =>
            `/setup/payroll/allowance-types/${hashid}/restore`,
        forceDelete: (hashid: string) =>
            `/setup/payroll/allowance-types/${hashid}/force`,
    },
    deduction: {
        store: '/setup/payroll/deduction-types',
        update: (hashid: string) => `/setup/payroll/deduction-types/${hashid}`,
        destroy: (hashid: string) => `/setup/payroll/deduction-types/${hashid}`,
        restore: (hashid: string) =>
            `/setup/payroll/deduction-types/${hashid}/restore`,
        forceDelete: (hashid: string) =>
            `/setup/payroll/deduction-types/${hashid}/force`,
    },
} as const;
