/**
 * Endpoint map for Company Setup → Award Types.
 * Mirrors the named routes in routes/setup.php (award-types.*). Types are addressed
 * by hashid; restore / force-delete take the hashid as a plain string.
 */
export const awardTypesConfigRoutes = {
    index: '/setup/award-types',
    store: '/setup/award-types',
    update: (hashid: string) => `/setup/award-types/${hashid}`,
    destroy: (hashid: string) => `/setup/award-types/${hashid}`,
    restore: (hashid: string) => `/setup/award-types/${hashid}/restore`,
    forceDelete: (hashid: string) => `/setup/award-types/${hashid}/force`,
} as const;
