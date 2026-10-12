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
    clearanceExport: (caseHashid: string) =>
        `/offboarding/${caseHashid}/export`,
    applyProgram: (caseHashid: string) =>
        `/offboarding/${caseHashid}/clearance/apply-program`,
    bulkClear: (caseHashid: string) =>
        `/offboarding/${caseHashid}/clearance/bulk-clear`,

    // Clearance items are addressed by numeric id (sub-resources).
    item: (id: number) => `/offboarding/clearance/${id}`,
    itemStatus: (id: number) => `/offboarding/clearance/${id}/status`,

    // An exit's papers as PDFs (ADR 0077), and its exit interview (ADR 0076).
    clearanceForm: (caseHashid: string) =>
        `/offboarding/${caseHashid}/documents/clearance-form`,
    certificate: (caseHashid: string) =>
        `/offboarding/${caseHashid}/documents/certificate`,
    sendInterview: (caseHashid: string) =>
        `/offboarding/${caseHashid}/interview`,
    waiveInterview: (caseHashid: string) =>
        `/offboarding/${caseHashid}/interview/waive`,

    // Leaving, for the person who is (ADR 0076).
    me: '/offboarding/me',
    resign: '/offboarding/me/resignation',
    withdraw: (hashid: string) =>
        `/offboarding/me/resignation/${hashid}/withdraw`,
    myInterview: '/offboarding/me/exit-interview',
    myCertificate: '/offboarding/me/certificate',

    // HR's sections: resignations to review, and what exit interviews say.
    resignations: '/offboarding/resignations',
    decide: (hashid: string) => `/offboarding/resignations/${hashid}`,
    interviews: '/offboarding/interviews',

    // Clearance templates (programs) live under Company Setup.
    programs: '/setup/offboarding',
    programsStore: '/setup/offboarding',
    program: (hashid: string) => `/setup/offboarding/${hashid}`,
} as const;
