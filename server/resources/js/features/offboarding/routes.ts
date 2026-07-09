/**
 * Centralised endpoint map for the Offboarding module.
 * Mirrors the named routes registered in routes/offboarding.php.
 */
export const offboardingRoutes = {
    index: '/offboarding',
    store: '/offboarding',
    export: '/offboarding/export',
    // Cases are addressed by their obfuscated hashid (App\Support\Hashid).
    show: (hashid: string) => `/offboarding/${hashid}`,
    update: (hashid: string) => `/offboarding/${hashid}`,
    status: (hashid: string) => `/offboarding/${hashid}/status`,
    destroy: (hashid: string) => `/offboarding/${hashid}`,
    clearance: (caseHashid: string) => `/offboarding/${caseHashid}/clearance`,
    clearanceExport: (caseHashid: string) => `/offboarding/${caseHashid}/export`,
    applyProgram: (caseHashid: string) =>
        `/offboarding/${caseHashid}/clearance/apply-program`,
    bulkClear: (caseHashid: string) =>
        `/offboarding/${caseHashid}/clearance/bulk-clear`,

    // Clearance items are addressed by numeric id (sub-resources).
    item: (id: number) => `/offboarding/clearance/${id}`,
    itemStatus: (id: number) => `/offboarding/clearance/${id}/status`,

    // Clearance templates (programs) live under Company Setup.
    programs: '/setup/offboarding',
    programsStore: '/setup/offboarding',
    program: (hashid: string) => `/setup/offboarding/${hashid}`,
} as const;
