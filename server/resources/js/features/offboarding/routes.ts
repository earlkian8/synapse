/**
 * Centralised endpoint map for the Offboarding module.
 * Mirrors the named routes registered in routes/offboarding.php.
 */
export const offboardingRoutes = {
    index: '/offboarding',
    store: '/offboarding',
    // Cases are addressed by their obfuscated hashid (App\Support\Hashid).
    show: (hashid: string) => `/offboarding/${hashid}`,
    update: (hashid: string) => `/offboarding/${hashid}`,
    status: (hashid: string) => `/offboarding/${hashid}/status`,
    destroy: (hashid: string) => `/offboarding/${hashid}`,
    clearance: (caseHashid: string) => `/offboarding/${caseHashid}/clearance`,

    // Clearance items are addressed by numeric id (sub-resources).
    item: (id: number) => `/offboarding/clearance/${id}`,
    itemStatus: (id: number) => `/offboarding/clearance/${id}/status`,
} as const;
