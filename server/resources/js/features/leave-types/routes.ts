/**
 * Endpoint map for Company Setup → Leave Types.
 * Mirrors the named routes in routes/setup.php (leave-types.*). Types are
 * addressed by hashid; restore / force-delete take the hashid as a plain string.
 */
export const leaveTypeRoutes = {
    index: '/setup/leave-types',
    store: '/setup/leave-types',
    type: (hashid: string) => `/setup/leave-types/${hashid}`,
    restore: (hashid: string) => `/setup/leave-types/${hashid}/restore`,
    forceDelete: (hashid: string) => `/setup/leave-types/${hashid}/force`,
} as const;
