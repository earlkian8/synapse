/**
 * Endpoint map for Company Setup → Benefits Configuration.
 * Mirrors the named routes in routes/setup.php (benefits.*). Plans are addressed
 * by hashid; restore / force-delete take the hashid as a plain string.
 */
export const benefitsConfigRoutes = {
    index: '/setup/benefits',
    store: '/setup/benefits',
    update: (hashid: string) => `/setup/benefits/${hashid}`,
    destroy: (hashid: string) => `/setup/benefits/${hashid}`,
    restore: (hashid: string) => `/setup/benefits/${hashid}/restore`,
    forceDelete: (hashid: string) => `/setup/benefits/${hashid}/force`,
} as const;
