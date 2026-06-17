/**
 * Centralised endpoint map for the Payroll module.
 * Mirrors the named routes registered in routes/payroll.php.
 */
export const payrollRoutes = {
    index: '/payroll',
    store: '/payroll',

    // Periods are addressed by their obfuscated hashid (App\Support\Hashid).
    show: (hashid: string) => `/payroll/${hashid}`,
    process: (hashid: string) => `/payroll/${hashid}/process`,
    finalize: (hashid: string) => `/payroll/${hashid}/finalize`,
    paid: (hashid: string) => `/payroll/${hashid}/paid`,
    destroy: (hashid: string) => `/payroll/${hashid}`,

    // Manual payslip adjustment (payslips addressed by hashid).
    payslipUpdate: (hashid: string) => `/payroll/payslips/${hashid}`,
    payslipReset: (hashid: string) => `/payroll/payslips/${hashid}/reset`,
} as const;
